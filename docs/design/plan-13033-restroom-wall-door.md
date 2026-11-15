# card#13033 — design and build plan: the restroom door set into its side wall, in 2-D and 3-D

**What this file is.** The design and the build plan for one report on one card, in the shape
`plan-12987-cab-door-view.md` set: written for the coder who implements it and the reviewer who attacks it. It owns
the *derivation*, the *measurement*, the *sequence* and the *acceptance list as observables*; it owns no rule. Every
rule lives in `FLOOR.md`, which this commit amends in place: § 6.7 item 2 (the facing, the side door's geometry and
drawing, the flag sign, the 3-D opening), item 3 rules 1 and 2 and the re-measured lay, item 4 (the step through the
wall), § 12's *Restroom door*, *Restroom plates*, *Restroom threshold* and *Restroom lane* rows, and the two
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
primitive, `floor/map-tiles.js`'s `restroomDoorGeometry(cell, cells)`, answers `{ facing, wall, doorway, draw, plates }`
for a door cell from its own grid's cells; `sceneryInput(cell, seed, cells)` folds it into the theme's input; the scene
attaches it to each door; the painter, the world, the furniture and the room paint read it. The threshold, lane and
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
  readable. § 7 Q2 offers it to the operator.
- **The flag centred over the doorway**: on the shipped default its plate (y 470–502 at x 1,738–1,776) lies over the
  low snack table's picture (rect x 1,728–1,776, y 432–496; with the flag at 112–144 px over the floor line, the centred
  plate stands at y 480–512). Mounted over the NEAR jamb it stands at y 512–544 over floor. The near-jamb mount is a seat
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

### 3.1 The geometry primitive (`floor/map-tiles.js`)

`restroomDoorGeometry(cell, at, walls, T)` — `cell` a `mapTiles()` door cell, `at` its top-left on the FLOOR's grid
(`floor-walls.js`'s `cellOf(cell, g, T)`, the same placement every wall body has), `walls` the floor's `wallBodies()`
(every map on the floor, each `wall` cell placed by `cellOf()`, the top row and the cells under a slab already left
out), `T` the floor's cell (`floorCell()`: one cell SIZE per floor; rooms on different sizes are not defended, which is
what the `floor-walls.js` header says):

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
  door is TWO billboards and a cut. (1) **The elevation** — `scenery(sceneryInput(cell, seed, geometry, 'elevation'))`,
  a `cell.w × cell.h` (64 × 144) box: the frame as today's face-on picture (6 px jambs, the 10 px head whose top is
  22 px under the box's top, the 4 px sill, the recess between), no pediment, no flag — stood in the wall's plane,
  `x1 = x2 = X(x_w)`, `z1 = Z(y_c + 32)` (the near jamb), `z2 = Z(y_c − 32)`, `y1 = 0`, `y2 = cell.h × SCALE`, sorted at
  the doorway's rows. (2) **The flag** — `scenery(…, 'flag')`, a 36 × 32 box facing the eye (a plate at right angles to a
  side wall faces the band): `x1 = X(x_w − 2)`, `x2 = X(x_w − 38)` (`left`: `X(x_w + 2)`, `X(x_w + 38)`), `z1 = z2 =
  Z(y_c + 32)`, `y1 = 112 × SCALE`, `y2 = 144 × SCALE`, sorted at the near jamb's row. (3) **The cut** — the door view's
  `wallBodies()` loop (L189–199) raises `y1` to `cell.h × SCALE` for each wall body of the door's column whose row lies in
  the doorway's span (a lintel, as 3-D's), AND the FOOTPRINT's side-wall row strips behind them (L177–185, one strip per
  footprint edge and row, `y1: 0, y2: ROOM_H`) get the same `y1` for those rows — without that the cut would open onto a
  solid strip. Measured nowhere yet — the door view has no restroom fixture; `door-view-leg.mjs` gains one and its
  `hrefsOf()` passes the `view` (§ 6).
- `back`: `{ facing, wall: null, doorway: null, draw: null, plates: RESTROOM_PLATES.back }` — today's values, so every
  existing consumer is unchanged for a `back` door.
- `right` (`left` mirrors every `x`): `x_w = cell.x + cell.w`, `foot = cell.y + cell.h`, `y_c = foot − cell.w ÷ 2`;
  `wall: { x: x_w, t: T }`; `doorway: { x: x_w, y: foot − cell.w, w: T, h: cell.w }` (the strip over the span);
  `draw: { x: x_w − 40, y: foot − 146, w: 48, h: 150 }`; `plates: RESTROOM_PLATES.side` — in the draw box's px: plate
  `(2, 2, 36, 32)`, sign `(9, 20, 22, 12)`, doorway `(40, 82, 8, 64)`, leaf `(42, 88, 4, 52)`; for `left`, each `x` is
  `48 − x − w`. The flag's plate stands 112–144 px over the foot line — its top at the tile's top, flush under the 3-D
  lintel, its bottom at the head's underside — so every renderer's flag is one figure (the first draft's 122–154 stood
  above a 144 px tile and could not be drawn in the door view's box). `RESTROOM_RECESS` stays the `back` recess and the
  leaf's 52 px span.
- `sceneryInput(cell, seed, geometry = null, view = 'plan')` adds `facing`, the plates and `view` to a door's input
  (`w`, `h` become the draw box's for a side door's `plan`, the tile's for `elevation`, 36 × 32 for `flag`); the `view`
  is part of the contract — its JSDoc names the three members and the door view is the only caller of the two it adds
  (round 2, F6). Every caller hands the door's geometry: `scene.js` L1392, `door-view.js` L213 (twice for a side door),
  `door-view-leg.mjs`'s `hrefsOf()` (L199–201, which must pass the `view` or its hrefs will not match the view's),
  `tools/design/door-view.selftest.mjs` L163/193, `tools/floor-themes/selftest.mjs` L198/204.
- **`obstaclesOf(cells, geometryOf)`** — the ONE statement of the walkable model's obstacle rule (every `wall` cell and
  every standing tile's rect, less a side door's): exported here and imported by `scene.js` (L1350) and by
  `walk-probe.mjs` (today's copies at L115–117 and L478); `verify-floor.py`'s Python copy keeps a drift check (§ 6;
  design review round 1, F5).

### 3.2 The scene (`floor/scene.js`)

- **Obstacles** (L1350): a door cell whose geometry is a side door is NOT pushed.
- **`restroomDoors[]`** (L1358): carries the geometry — `facing`, `wall`, `doorway`, `draw`, and the plates resolved to
  scene px (`sign`, `plate`, `leaf`; `recess` for `back`).
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
- **`doorLane(line, span, character, facing = 'back', face = line)`** (L742) and `restroomLane(door, threshold,
  character)` (L772): the lane runs from the doorway's FACE to one `body.h` past the THRESHOLD's line — at the elevator
  the two lines coincide and nothing changes; for a side door the rect spans `x ∈ [thr.x − body.h, x_w]` (`right`) and
  `max(doorway.h, body.w + 2 × 8)` along the wall, centred on `y_c`. `restroomLane()` therefore takes the threshold, so
  `placed[]` computes the threshold first and the lane from it; a door with no threshold has no lane (and serves nobody).
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

- **Themes** (`studio/theme.js` `restroomDoor(pen, w, foot, sign, plate, facing, doorway, view)`, `cottage/theme.js`
  likewise): `back` draws today's picture whatever the view. A side door is drawn by `view`, the third member of
  `sceneryInput()`'s contract (`view ∈ { 'plan', 'elevation', 'flag' }`, default `'plan'`; design review round 2, F6):
  **`plan`** (the floor page, in the draw box's px) — the wall strip's recess (dark, over the leaf's 52 px of the
  `doorway` rect), two jamb blocks in the frame colour at the span's ends over the strip, the sill as a 3 px strip just
  inside the room across the span, and the FLAG PLATE at `plate` with a 2 px bracket to the wall face; **`elevation`**
  (the cab's door view, a `cell.w × cell.h` box, 64 × 144) — the frame as today's face-on picture draws it, 6 px jambs,
  the 10 px head with its top 22 px under the box's top, the 4 px sill, the dark recess between, NO pediment and no flag
  (the flag faces the eye and is its own billboard); **`flag`** (the door view's second billboard, a 36 × 32 box) — the
  plate alone. ONE function draws the plate for `plan` and `flag`: the pictogram (the two figures the theme already
  draws) in its upper 18 px, the slider rim at `sign` in its lower half. Nothing else — no `<text>`, no light, nothing
  that moves (AT-D3-25's form checks). The two § 10.6 table rows are amended in this commit; their palette keys are
  unchanged (the drift leg holds table and module equal by key); `tools/floor-themes/selftest.mjs` draws all three views
  per theme (§ 6).
- **Painter** (`painter.js` L1318–1353): per door by facing — `back` as today; side: the leaf a `rect` at `d.leaf`
  with `transform-origin` at the back jamb's end and `scaleY` keyframes (`restroom-leaf-y-n`, the same five levels),
  the field at `d.sign` as today; and in place of the recess clip, **the FADE**. The `inside` walker's element
  (`fx-walker-inside`, L1002–1009, today a child of the door's recess clip) is for a side door an unclipped group in the
  same place in `tiles` — after the scenery documents (L1300–1306), before the leaf and field nodes (L1320–1332), so the
  field stays drawn over the walker's head — standing at the threshold through the leg, with its OPACITY stepped by
  **the fade primitive the painter already has**: `laptopOver()` (L405–415), the A24 laptop fade's — `--laptop-from` /
  `--laptop-to` custom properties read by the `laptop` keyframes, `steps(frames)` at the loop rate, `animation-delay`
  of `−elapsed` so a repaint neither restarts nor skips. It is generalised at this second caller (canon #5): renamed
  `fadeOver(el, from, to, frames, interval, elapsed)` with neutral `--fade-from` / `--fade-to` properties and a
  `step-fade` keyframe, the laptop call moved onto it, nothing else changed. The side-door walker gets TWO runs on one
  element: 1 → 0 over the leaf's CLOSING (`D.close` frames from `inside.start` — the frames the closing leaf covers a
  back-door walker, so the two doors hide the walker on the same frames) and 0 → 1 over the exit OPENING (`D.open`
  frames ending at the leg's end — the frames the opening reveals a back-door walker); between them opacity 0, and the
  walk home begins as today from the threshold at full opacity. `floor.restroom(at)` answers `{ clip, fade }` for the
  door whose threshold is `at` — the clip for `back`, the fade's frames for a side door. No clip at the wall line, no
  glide, no change to any leg: the stylesheet (L107–111) gains only the `scaleY` keyframes and the renamed fade. The
  reading of the ruling's *"fades out over the leaf's open frames"* taken here is *the frames the leaf is moving*,
  matched to the moves that hide and reveal a back-door walker; if the operator reads it as the opening's frames on the
  way in, the fade-out's start moves to `atDoor.start` on the floor element — one constant, named here so it is not a
  redesign.
- **Marker SVG** (`resources/floor/tiles/floor-plane/restroom-door.svg`): still 64 × 144; the lower 64 px band is
  marked as the doorway (a dashed band labelled `doorway` with arrow-heads to both side edges) over the faint upper
  outline, so an author reads *paint this cell against the wall the door is in*. Its `docs/ATTRIBUTION.md` row (L95) is
  re-hashed with the new date; `bin/asset-provenance.py` holds it.

### 3.5 The world and the 3-D room

- **`floor-world.js`** L449–462: per door by facing. `back` as today. Side: `wall_x` (world `x` of the face),
  `jambs: [z_back, z_near]`, `leaf: { x: wall_x + T ÷ 2 ÷ UNIT, z: z of y_c − 26, span: 0.52, y0: 0.05, h: 1.07, pocket:
  'back' }`, `flag: { x0: wall_x, out: −1 | +1, z: z_near, y0: 1.12, h: 0.32, w: 0.36 }` (its top at the tile's height,
  flush under the lintel; its bottom at the head's underside), `field` the sign rect lifted onto the flag (`x` from the
  face by 0.09–0.31, `y` 1.30–1.42). **Walls** (L497–506): a cell of the door's wall column
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
facing, and keeps doing so: with no step through the wall there is nothing to clip and no clipping plane
(`renderer.localClippingEnabled` is not touched; `floor-paint.js` L78's renderer is constructed as it is). The thought
bubble's visibility follows the mesh's (`b.thought.object.visible = b.creature?.mesh.visible ?? false`, L247), so it is
hidden with the body the frame the `inside` leg begins and shown again the frame the walk home begins — confirmed by
reading L247 against L315: the bubble needs no change. The 3-D side door therefore shows the walker standing at the
threshold while the leaf opens, gone while it is inside, and standing there again as it opens — the 2-D fade's
end-states on the same frames, without the fade (a 3-D fade would be a new mechanism; the seat's ruling takes the hide as
it is).

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
| `restroom-corridor.json` RE-CUT (§ 6) | `right`, y 592–656 | (1,732, 628) | as the default | without the lane **(1,652, 628)** 80.0 — on the axis, inside it; with it **(1,684, 564)** 80.0, (1,628, 508), (1,732, 500), (1,652, 436) | 2,005 / 1,485 / 1,092 / 1,992 / 1,472 / 952 px, all `clear`; every stool `clear` (measured without the snack spot 0s as `clear` points; the build adds them) |

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
4 / 1 / 4 frames unchanged, and so is every leg; the fade runs over the closing's 4 frames and the exit opening's 4.

## 6. Tests, fixtures and tools — what is regenerated, and the RED each must keep

- **`ACharacterTakesARestroomBreakTest`** — `test_green_section_12s_figures_are_the_scenes` reads `RESTROOM_PLATES.back`
  and `.side` and the geometry primitive's answer for the default; `test_green_the_visit` holds the legs and the leaf
  timeline UNCHANGED frame for frame against the committed `fx-restroom` (the ruling's *byte-identical* leaf timing is
  the GREEN: the regenerated fixture's `legs`, `leaf`, `occupied` and `claim` equal today's); the lane tests read the
  side lane; `test_red_the_walker_through_the_door` keeps its RED exactly as it is. **GREEN — the fade**: the side door's
  `inside` element carries `fadeOver()`'s two runs — 1 → 0 over `D.close` frames from `inside.start`, 0 → 1 over the
  last `D.open` frames of the leg — read off the painted DOM in `painter-probe.mjs`; a `back` door's element is clipped
  and unfaded as today. **RED — the fade on the wrong frames**: start the fade-out at `atDoor.start` → the walker is
  half-gone while the leaf is still opening. **Every planted RED below was MEASURED with the review's probe before it was
  written down** (`scratchpad/probe/side.mjs` and `r2/side2.mjs`, over the repository's own modules; design review
  round 1, F6 — the first draft's forecourt RED and selftest column could not fail). **New RED — the door as furniture**:
  keep the side door's rect in `obstacles` → the default's threshold is **(1,668, 628)**, not (1,732, 628) — the tile's
  halo (`1,712 − 41.75`) blocks the row to x 1,670, so the fallback walks 64 px further into the room (re-measured after
  round 2: unchanged; and under F8's stop rule the cell at 1,676 is blocked by the door's rect, not the wall, so the
  search STOPS and the threshold is `null` — either reading reds). **New RED — the facing from a property**: a map that
  sets a tile property `facing: left` on the default's door → the scene still says `right`. **New RED — the anchor row**
  (F1): count the wall rows over `[at.y, at.y + h)` instead of the tile's top → every shipped door answers `back`
  (measured: 6 rows of 18 on the default).
- **`TheLoungeFloorRoutesEveryWalkTest`** — re-derives the side threshold and holds the spots and every route of § 4's
  lounge row; `test_red_the_door_moved_under_a_desk` keeps its RED unchanged — it plants the door's cell inside desk 2's
  slot and reads the placement defect (*meets `desks` object id 2*), which no facing rule touches.
- **Fixtures for the two facings this tree has none of** (F9), both MEASURED: a `left` map — the lounge floor mirrored
  in x by the review's `r2/mirror.mjs` over its layers and `desks` objects, committed as
  `fixtures/maps/lounge-floor-mirrored.json`: door drawn at x 8–72, facing `left` (18 of 18 wall rows at x 0–8),
  threshold **(52, 628)** — 44 px from the left face, the lounge's 1,732 mirrored — lane x 8–172, spots (108, 572),
  (124, 668), (84, 500), (164, 516), each the lounge's spot mirrored, which `TheLoungeFloorRoutesEveryWalkTest` holds
  beside the lounge's own; and a `back` fixture **derived from `restroom-corridor.json`** (round 2, F9): its door moved
  five columns off the wall from `furniture` (214, 62) to (209, 62) — tile x 1,672–1,736, y 360–504, touching no wall
  (right 0 rows; left 1 of 18, the fixture's x 1,664 wall crossing one row) — facing `back`, face-on threshold
  **(1,716, 508)** (under the centre the cell is blocked and the fallback walks right), every desk routing to it at
  `clear` (2,230 / 1,710 / 1,377 / 2,217 / 1,403 / 883 px), committed as `fixtures/maps/restroom-back.json` and held by
  `ACharacterTakesARestroomBreakTest` to the face-on rule, the recess clip and the `back` plates, so the `back` path
  stays exercised once every shipped map is a side door.
- **`walk-probe.mjs`** — `restroom` and `walks` modes derive the threshold by facing (the test's restatement of § 12's
  rule, as today's is), answer `facing`, `lane` and the draw box per door; EVERY construction of an obstacle list in the
  file — the population is `git grep -n obstacles -- server/tests/Feature/Floor/walk-probe.mjs`, whose hits that build a
  list (a `filter` on `'wall'` and `standing`) number four at this reading: `floorOf()`'s, the `snack` mode's, the
  `restroom` mode's and the `spots` mode's — is REPLACED by an import of `map-tiles.js`'s `obstaclesOf()`, one rule and
  no copy (round 1 F5, round 2 F7); the slice re-runs the grep and leaves none.
- **`tools/design/verify-floor.py` G-scenery leg 4** (L2216–2280) — derives the threshold by facing (the wall column read
  off the map's `walls` layer beside the tile) and drops a side door's rect from `blocking` as `obstaclesOf()` does. The
  Python copy of the obstacle rule cannot import the module, so it gains a DRIFT CHECK: `verify-design-docs.selftest.py`
  runs `walk-probe.mjs`'s `restroom` mode over the default and holds its `placed`/obstacle count equal to the Python
  list's, with a plant (a side door's rect left in the Python list) seen red (F5). The selftest's leg-4 plant (L909–917,
  the door moved five rows down) no longer reds under the side rule — replaced by a `wall` column at **x 1,688** (col
  211) over rows 70–86, measured `threshold null`; x 1,696 reds too, x 1,704 does NOT (it leaves (1,660, 628) open) —
  so the plant is 1,688 and the number is in the selftest's comment with its derivation.
- **`floor-world-probe.mjs`** — the `restroom` leg holds the lintel cells, the leaf box, the flag and the field in the
  world against the scene's rects and facing; its control still reds over scenes with no door. **New RED**: the lintel's
  `box.y` left at 0 → a wall cell stands through the doorway.
- **`TheFloorIsTheScenesThirdRendererTest`** — its plant table (L113–115) gains *the piece placed without its turn* and
  *the lintel left solid*; nothing for the walker or the bubble, which are unchanged (§ 3.6).
- **`door-view-leg.mjs`** — `hrefsOf()` (L199–201) passes the `view`, and the leg gains a restroom door fixture (F3, F6):
  the elevation billboard stands in the wall's plane (`x1 === x2`, its `z` span the doorway's, its href the theme's
  `elevation` document), the flag billboard faces the eye at the near jamb (its href the `flag` document), the wall
  bodies over the doorway and the footprint's side strips on those rows carry the lintel `y1`; REDs: the billboard stood
  face-on at the tile (today's placement); the side strip left whole behind the cut; the flag's href drawn from the
  `plan` document.
- **`tools/floor-themes/selftest.mjs`** — for the door tile at its size, all three views per theme and seed (`plan` in
  the draw box, `elevation` at 64 × 144, `flag` at 36 × 32), each held textless and well-formed as every scenery
  document is (L198–204's loop gains the `view` dimension).
- **`gen-fx-snack.mjs`** — the `restroom-corridor` variant re-cut as § 4's last row: the door kept at `furniture` (214, 81),
  the snack tables kept, `walls` painted at row 72 over cols 200–204 and 216–221 and at row 83 over cols 200–221, the left
  lamp at `furniture` (197, 82) (GID 9) removed — the variant's `from` guard holds each cell's current GID, so a wrong
  cell throws rather than silently painting floor (the design's first scratch measurement removed (197, 81), an empty
  cell, and read a corridor the lamp still narrowed; the figures in § 4 are from the corrected run); `restroom-one-spot`
  and `restroom-sealed` re-measured against the side threshold and re-walled if their property (one spot; no route) no
  longer holds; `fx-restroom.json` regenerated (`--check` first, to read what moved).
- **`gen-fx-turn-back.mjs`** — `leave-inside` regenerated over the new leg; `AMainAgentTurnsBackToItsDeskTest`'s leaf
  comparison (L506–507) reads the moves unchanged.
- **`NoAnimationFiresWithoutItsEventTest`, `ALayoutActFiresNoAnimationTest`, `DrivesTheDeskFloor`, `AnInternRidesTheElevatorTest`,
  `AWalkerKeepsClearOfWallsAndFurnitureTest`** — read the fixtures; re-run, no edit expected.
- **`tools/design/door-view.selftest.mjs`** — the new `sceneryInput()` arguments at L163/193 (geometry and `view`).
- **`tools/design/floor3d.browser.mjs`** — the `restroom` rows read the flag's field; the fixed term is unchanged.
- **`FLOOR.md`** landing with the slices: AT-D3-33's *the visit* GREEN and seventh RED, AT-D3-35's fixture sentence,
  AT-D3-38's restroom GREEN and seventeenth RED; § 13 row 121, § 14 item 50 (§ 7's questions and their answers),
  Appendix B row 50; § 10.6 item 6's `restroom-door` sentence (L8113).

## 7. Operator questions — each with context, an example per option, and the recommendation

1. **Where the flag hangs.** Context: the operator ruled a flag over the doorway; the design mounts it over the NEAR
   jamb because a centred flag covers the low snack table's top-right on the shipped default (measured, § 2). Option A,
   near jamb: on the default the plate stands at y 512–544 over floor; a walker at the threshold covers its lower half
   while the leaf opens and as it fades. Option B, centred: the plate at y 480–512 over the table's cups, always. **Recommendation: A**, as a seat
   ruling the operator can reverse on reading the sandbox.
2. **The leaf in 2-D.** Context: the floor's walls are plan strips, so a leaf in the wall's plane is a 4 px bar; the
   state is read off the flag's field. Option A, a sliding bar (the elevator's motion, one mechanism with 3-D): the bar
   shortens into the wall as the walker steps through. Option B, a swinging bar (the plan symbol): the bar turns 90°
   about the back jamb into the room — more legible as a door, a second mechanism, and in 3-D the leaf would swing out
   of the wall against the seat's ruling. **Recommendation: A.**
3. **What is behind the door.** Context: the right wall is the room's edge; the walker steps through the wall into
   nothing, clipped at the face in both renderers. Option A, nothing — the door is an entry into the wall, as asked, and
   no closet is drawn. Option B, a closet: a small room beyond the wall on a grown map (§ 6.6 item 3's Option B shape,
   every room drawn from the default growing), drawn in 3-D as a box. **Recommendation: A**, and B as a later card if the
   operator wants it after reading the 3-D floor.

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

1. **The door in the wall, in 2-D** — `map-tiles.js`'s `restroomDoorGeometry()` (the facing from the wall bodies at the
   tile's top), `obstaclesOf()` and `sceneryInput()`'s geometry and `view` at every caller (§ 3.1); `buildScene()`'s
   walls computed before its scenery, the obstacles, doors, scenery, the threshold with its stop rule and the lane — no
   leg, no exit, no pose or turn is edited (§ 3.2); `route.js` untouched (§ 3.3); both themes' three views, the painter's
   side leaf and fade (`fadeOver()` hoisted from `laptopOver()` with its one existing caller moved), the stylesheet, the
   marker SVG and its attribution row (§ 3.4); the door view's two billboards and cut, strips included (§ 3.1);
   `walk-probe.mjs` on the obstacle rule, `ACharacterTakesARestroomBreakTest` with its unchanged-timeline GREEN, fade
   GREEN and measured REDs, `TheLoungeFloorRoutesEveryWalkTest` with the mirrored map, the `back` fixture,
   `verify-floor.py` leg 4 with its drift check and the x 1,688 plant, `gen-fx-snack.mjs`'s re-cut corridor and
   regenerated `fx-restroom` (whose legs and leaf must come out byte-identical — the ruling's check), `gen-fx-turn-back.mjs`
   re-run (nothing moves), `door-view-leg.mjs`'s restroom fixture, the themes' and door view's selftests (§ 6);
   `FLOOR.md`'s AT-D3-33, AT-D3-35, § 13 row 121, § 14 item 50 and Appendix B row 50. One slice because a side threshold
   under a face-on picture is a wrong floor on the sandbox; the coder may stage it as two commits (geometry; drawing)
   in one PR.
2. **The door in the wall, in 3-D** — `floor-world.js` (lintels on `box.y`, the piece's `turn` and `facing`, the leaf,
   flag and field boxes, the door's wall-column ids), the furniture placements with `turn` and the `facing` grouping key,
   `pieces-3d.js`'s side frame and flag from the shared part helpers, `room-paint.js`'s lintel placement and its cut-away
   with the column rule, side leaf and field (§ 3.5); `body-paint.js` and `floor-paint.js` untouched (§ 3.6);
   `floor-world-probe.mjs`, `TheFloorIsTheScenesThirdRendererTest`, `floor3d.browser.mjs` (§ 6); `FLOOR.md`'s AT-D3-38
   and the row markers. Lands after slice 1 and, like it, after re-reading `dev`.

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
- **The flag covered by the entering walker** for the step's seconds (§ 6.7 item 2): accepted and named.
- **The walker vanishes at a threshold 44 px from the wall.** With no step through the wall, a side-door walker fades
  out standing in the room and reappears there; a viewer watching closely sees a person dissolve a body's half-width
  short of the doorway rather than pass it. Accepted by the seat's ruling, named here: the alternative was the walk
  through the wall and the five mechanisms it needed.
- **Hazards with a check**: the facing counted from the anchor row (the anchor-row RED above); the fade on the opening's
  frames on the way in (the fade RED above); the obstacle kept (the forecourt RED above); the facing read from anywhere
  but the wall (the property RED above); the lane measured from the face rather than the threshold (AT-D3-35 over the
  re-cut corridor: the second walker would wait at (1,652, 628), on the axis — the RED *the lane not passed* reds it, and
  `test_green_the_restroom_lane_is_section_12s` holds the 164 px); a threshold found past another piece (F8's stop; the
  RED is the kept rect, whose cell at 1,676 is not wall-blocked).
- **What the scratch measurement does NOT cover, named**: the `restroom-one-spot` and `restroom-sealed` variants against
  the side threshold (the build measures them before regenerating); the lane-clear frame of the walker coming out
  (`laneFrame()` over the deeper lane — the build's `test_green_the_occupant_clears_the_lane_before_the_next_steps_in`
  prints which of the closing and the lane is the later on the default); and the 3-D figures, which are lifts of the
  2-D ones and are held by `floor-world-probe.mjs`, not by this plan.

## 10. Seat rulings in this plan, reversible by the operator

The doorway at the tile's foot end (§ 4); the flag over the near jamb, 112–144 px over the foot (§ 7 Q1); the sliding
2-D leaf (§ 7 Q2); a `back` door drawn face-on wherever a door touches no side wall; `right` on a tie; the lintel from
the tile's height; no closet (§ 7 Q3); the walker fades at the threshold rather than walking through the wall (the
seat's ruling after review round 2), the fade-out on the leaf's closing frames and the fade-in on its exit opening
(§ 3.4's reading of the ruling's words).

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

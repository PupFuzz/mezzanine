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
- **Code that reads the door's rect or facing**: `grep -rn "RESTROOM_PLATES\|RESTROOM_RECESS\|restroomLane\|restroom_doors\|sceneryInput\|kind === 'inside'\|where === 'inside'" server/public/js server/tests tools resources` —
  the sites § 3 lists, one per line of that grep, re-run on the branch before each slice closes.
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
  low snack table's picture (rect x 1,728–1,776, y 432–496). Mounted over the NEAR jamb it stands at y 502–534 over
  floor. The near-jamb mount is a seat ruling, reversible (§ 7 Q1).
- **A 3-D walker hidden by visibility rather than clipped**: `body-paint.js` L315 already hides every non-`floor`
  walker, so the cheapest 3-D is to show the `inside` walker while its point is on the room side of the face. The body
  then pops out of sight with its far half already inside the wall. A clipping plane at the face cuts it as the 2-D clip
  does; it costs `renderer.localClippingEnabled` and a per-walker material clone during the leg (§ 3.6).
- **A restroom closet beyond the wall**: beyond the right wall is outside the room's footprint. A closet means the map
  grows; the operator has not asked for one (§ 7 Q3).

## 3. The derivation — every site, and what each does

### 3.1 The geometry primitive (`floor/map-tiles.js`)

`restroomDoorGeometry(cell, cells)` — `cell` a `mapTiles()` door cell, `cells` its grid's cells, `T` the grid's cell
(the tile height of the cell's own map; every shipped map is on 8 px):

- `facing`: `right` if the `wall` cells with `x === cell.x + cell.w` and `y` within `[cell.y, cell.y + cell.h)` number
  `cell.h ÷ T`; else `left` by the same test at `x === cell.x − T`; else `back`. Both → `right`.
- `back`: `{ facing, wall: null, doorway: null, draw: null, plates: RESTROOM_PLATES.back }` — today's values, so every
  existing consumer is unchanged for a `back` door.
- `right` (`left` mirrors every `x`): `x_w = cell.x + cell.w`, `foot = cell.y + cell.h`, `y_c = foot − cell.w ÷ 2`;
  `wall: { x: x_w, t: T }`; `doorway: { x: x_w, y: foot − cell.w, w: T, h: cell.w }` (the strip over the span);
  `draw: { x: x_w − 40, y: foot − 156, w: 48, h: 160 }`; `plates: RESTROOM_PLATES.side` — in the draw box's px: plate
  `(2, 2, 36, 32)`, sign `(9, 20, 22, 12)`, doorway `(40, 92, 8, 64)`, leaf `(42, 98, 4, 52)`; for `left`, each `x` is
  `48 − x − w`. `RESTROOM_RECESS` stays the `back` recess and the leaf's 52 px span.
- `sceneryInput(cell, seed, cells)` adds `facing` and the plates to a door's input (`w`, `h` become the draw box's for a
  side door); every caller passes its cells: `scene.js` L1392, `door-view.js` L213, `door-view-leg.mjs` L201/256,
  `tools/design/door-view.selftest.mjs` L163/193, `tools/floor-themes/selftest.mjs` L198/204.

### 3.2 The scene (`floor/scene.js`)

- **Obstacles** (L1350): a door cell whose geometry is a side door is NOT pushed.
- **`restroomDoors[]`** (L1358): carries the geometry — `facing`, `wall`, `doorway`, `draw`, and the plates resolved to
  scene px (`sign`, `plate`, `leaf`; `recess` for `back`).
- **`scenery[]`** (L1400): a side door's entry keeps the cell rect in `x, y, w, h` (the 3-D piece's size and foot) and
  adds `draw` (the painter positions the theme's document there) and `facing`.
- **`thresholdOf()`** (L1942): by facing — `back` as today; side: `cellAt(x_w − d − 1, y_c)` (mirrored for `left`), open
  at `clear` or the nearest open cell on that row no deeper than `walkerSize().h` from the face; none → `null`.
- **`doorLane(line, span, character, facing = 'back', face = line)`** (L753) and `restroomLane(door, threshold,
  character)` (L772): the lane runs from the doorway's FACE to one `body.h` past the THRESHOLD's line — at the elevator
  the two lines coincide and nothing changes; for a side door the rect spans `x ∈ [thr.x − body.h, x_w]` (`right`) and
  `max(doorway.h, body.w + 2 × 8)` along the wall, centred on `y_c`. `restroomLane()` therefore takes the threshold, so
  `placed[]` computes the threshold first and the lane from it; a door with no threshold has no lane (and serves nobody).
  `elevatorLane()` passes nothing new.
- **`plan()`'s legs** (L2062–2068): `inside` becomes `{ kind: 'inside', start, frames, at: thr, through, step_in,
  step_out }` with `frames = step_in + D.close + dwell + D.open + step_out`; `through = { x: x_w + T + body.w ÷ 2, y: thr.y }`
  (`back`: `through = at`, steps 0); steps `max(1, ⌈hypot ÷ WALK_PX_PER_FRAME⌉)` for a side door. The leaf timeline's
  second move starts at `inside.start + step_in`; `occupied` from `inside.start + step_in + D.close` for `dwell`; the
  third move at `inside.start + step_in + D.close + dwell`; the fourth as today from `home.start + D.step`. `claim`
  unchanged.
- **`restroomExit()`** (L532): three windows inside the leg — during the step in, `lead` = frames elapsed into the step
  (the walker reverses to the threshold; the closing has not begun, so no leaf move but the pending close is dropped);
  through the close, dwell and open as today with `lead = opening + step_out`; during the step out, `lead` = the step's
  remaining frames. Its returned `at` stays the threshold.
- **`tripStart()`** (L610), **`walkEffect()`'s lead leg** (L1028) and **the intern leave** (L2906): the lead `inside`
  leg is `{ at: thr, through: here, step_in: 0, step_out: min(lead, stepFrames) }` so the walker glides out along the
  same line before its departure walks from the threshold.
- **`poseOf()`** (L2963) gains `inside_at` (the leg's `at`) for `where === 'inside'`; **`turnRound()`** (L3045, L3112)
  builds its `inside` leg as `{ at: pose.inside_at, through: pose.p, step_in: 0, step_out: min(inside_left, …) }` and
  routes home from `inside_at`, never from `p`.
- Unchanged, verified by reading: `floor/in-flight.js` `handPoint()` (L136, the `inside` leg's end is still the
  threshold), `#admit()`'s `laneFrame()` reads (L959, L1011 — the lane is a rule on `where === 'floor'` points, and the
  walk home still starts at the threshold).

### 3.3 The route (`floor/route.js`)

`pointAt()` (L747): an `inside` leg answers `at → through` over its first `step_in` frames, `through` between, and
`through → at` over its last `step_out`, `where: 'inside'` throughout — `cabStep()`'s shape as `insideStep(leg)`, so a
`back` door's leg (steps 0) answers `at` as today. `floorStackOrder()` unchanged (inside legs are not stacked).

### 3.4 The 2-D drawing — both themes and the painter

- **Themes** (`studio/theme.js` `restroomDoor(pen, w, foot, sign, plate, facing, doorway)`, `cottage/theme.js` likewise):
  `back` draws today's picture. A side door draws, in the draw box's px: the wall strip's recess (dark, over the leaf's
  52 px of the `doorway` rect), two jamb blocks in the frame colour at the span's ends over the strip, the sill as a
  3 px strip just inside the room across the span; the FLAG — the plate at `plate` with a 2 px bracket to the wall face,
  the pictogram (the two figures the theme already draws) in its upper 18 px, the slider rim at `sign`; nothing else —
  no `<text>`, no light, nothing that moves (AT-D3-25's form checks). The two § 10.6 table rows are amended in this
  commit; their palette keys are unchanged (the drift leg holds table and module equal by key).
- **Painter** (`painter.js` L1318–1353): per door by facing — `back` as today; side: the leaf a `rect` at `d.leaf`
  with `transform-origin` at the back jamb's end and `scaleY` keyframes (`restroom-leaf-y-n`, the same five levels),
  the field at `d.sign` as today; the clip (`clippedTo`) is the APPROACH BOX `{ x: thr.x − body.w, y: y_c − 32 − body.h,
  w: x_w − (thr.x − body.w), h: 64 + body.h + 8 }` for `right` (mirrored for `left`), and `floor.restroom(at)` answers it
  for the door whose threshold is `at`. The `inside` walker's glide (L1002–1009) is drawn through `pointAt()` as the
  cab's step is (`fx-walker-step`'s `offset-path` precedent), inside that clip. The stylesheet (L107–111) gains the
  `scaleY` keyframes beside the `scaleX` ones.
- **Marker SVG** (`resources/floor/tiles/floor-plane/restroom-door.svg`): still 64 × 144; the lower 64 px band is
  marked as the doorway (a dashed band labelled `doorway` with arrow-heads to both side edges) over the faint upper
  outline, so an author reads *paint this cell against the wall the door is in*. Its `docs/ATTRIBUTION.md` row (L95) is
  re-hashed with the new date; `bin/asset-provenance.py` holds it.

### 3.5 The world and the 3-D room

- **`floor-world.js`** L449–462: per door by facing. `back` as today. Side: `wall_x` (world `x` of the face),
  `jambs: [z_back, z_near]`, `leaf: { x: wall_x + T ÷ 2 ÷ UNIT, z: z of y_c − 26, span: 0.52, y0: 0.05, h: 1.07, pocket:
  'back' }`, `flag: { x0: wall_x, out: −1 | +1, z: z_near, y0: 1.22, h: 0.32, w: 0.36 }`, `field` the sign rect lifted
  onto the flag (`x` from the face by 0.09–0.31, `y` 1.24–1.36). **Walls** (L497–506): a cell of the door's wall column
  whose `y` lies in the doorway's span gets `box.y0 = RESTROOM_DOOR.h ÷ UNIT` (a lintel) and `box.h` the rest to its
  top; a `partition` (0.864 u) shorter than the door contributes no body there. **Pieces** (L507–516): a side door's
  piece has `at` at `(x_w, y_c)` lifted, `turn: −π ÷ 2` for `right`, `+π ÷ 2` for `left`, and `facing` for the theme.
- **`furniture.js`** placements (L228–236 today; after the snack slice, `furniture-placements.js`): a `piece3d`
  placement honours `turn` (`makeRotationY` before the translation) and hands `facing` to `piece3d(kind, { w, h,
  facing })`.
- **`pieces-3d.js`** `restroom-door` (L127): with `facing` a side door's frame has its jambs at `±w ÷ 2` in `x`, head and
  no pediment above the lintel line, and the FLAG at the near-jamb end — a plate `0.01 × 0.32 × 0.36` from `y` 1.22
  projecting toward `+z` (the room, after the turn), the pictogram's two figures as spheres, a box and a cone on BOTH
  faces in `P.figure`; the recess block is dropped (the wall's cut is the recess).
- **`room-paint.js`** `placeWall` (L92): a wall with `box.y0` is translated to `y0` and scaled to `h`; the cut-away
  lowers a lintel to `max(0, CUTAWAY_H − y0)`, i.e. hides it. `standRestroom` / `placeRestroom` (L353–386): side
  leaf box at `leaf` scaled along `z` from the back jamb by `shut`; field box on the flag, `0.012` thick. The browser
  leg's fixed-term formula (`floor3d.browser.mjs` L87, `2 × m.restroom`) is unchanged — one leaf, one field per door.

### 3.6 The 3-D walker (`floor3d/body-paint.js`, `floor3d/floor-paint.js`)

`c.mesh.visible` (L315) admits `where === 'inside'`; the body's materials are cloned for the leg's duration with
`clippingPlanes = [new Plane(normal toward the room, constant at the face)]` and `renderer.localClippingEnabled = true`
set once at the renderer's creation. The clone is dropped when the leg ends. If the chars slice has reshaped how a
body's materials are owned, the coder lands the plane on whatever the single owner is and says so on the card.

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
is corrected in this commit, and which later lay change moved them is not traced.

## 5. The flag sign, the plates and the door motion — figures

In § 12's rows. Scene px on the shipped default: flag plate x 1,738–1,774, y 502–534; field x 1,745–1,767, y 520–532;
leaf x 1,778–1,782, y 598–650; doorway strip x 1,776–1,784, y 592–656. The leaf's motion is *Restroom door motion*'s
4 / 1 / 4 frames unchanged; the step through is 4 frames each way on the default (85.75 px at 24 px a frame).

## 6. Tests, fixtures and tools — what is regenerated, and the RED each must keep

- **`ACharacterTakesARestroomBreakTest`** — `test_green_section_12s_figures_are_the_scenes` reads `RESTROOM_PLATES.back`
  and `.side` and the geometry primitive's answer for the default; `test_green_the_visit` holds the new leg shape
  (`inside` with `at`, `through`, steps; the leaf's second move at `inside.start + step_in`); the lane tests read the
  side lane; `test_red_the_walker_through_the_door` is re-aimed: plant `pointAt()` answering `where: 'floor'` for an
  inside leg → the walker is drawn on the floor past the wall. **New RED — the door as furniture**: keep the side
  door's rect in `obstacles` → the lounge test's threshold still opens (the halo decides it), so the RED is the
  default's FORECOURT: a route from desk 6 to spot 2 (1,668, 676) crosses the freed patch and must shorten by a measured
  amount; plant the rect and watch the length revert. **New RED — the facing from a property**: a map that sets a tile
  property `facing: left` on the default's door → the scene still says `right`.
- **`TheLoungeFloorRoutesEveryWalkTest`** — re-derives the side threshold and holds the spots and every route of § 4's
  lounge row; `test_red_the_door_moved_under_a_desk` keeps its RED unchanged — it plants the door's cell inside desk 2's
  slot and reads the placement defect (*meets `desks` object id 2*), which no facing rule touches.
- **`walk-probe.mjs`** — `restroom` and `walks` modes derive the threshold by facing (the test's restatement of § 12's
  rule, as today's is), answer `facing`, `lane` and the draw box per door.
- **`floor-world-probe.mjs`** — the `restroom` leg holds the lintel cells, the leaf box, the flag and the field in the
  world against the scene's rects and facing; its control still reds over scenes with no door. **New RED**: the lintel's
  `y0` dropped → a wall cell stands through the doorway.
- **`TheFloorIsTheScenesThirdRendererTest`** — its plant table (L113–115) gains *the piece placed without its turn* and
  *the lintel left solid*.
- **`tools/design/verify-floor.py` G-scenery leg 4** (L2216–2280) — derives the threshold by facing (the wall column read
  off the map's `walls` layer beside the tile); `verify-design-docs.selftest.py`'s plant (L909–917, the door moved five
  rows down) no longer reds under the side rule — replaced by a wall column painted at x 1,704 over y 560–696, which
  blocks every cell of the threshold's row within the lane's depth.
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
- **`tools/floor-themes/selftest.mjs`**, **`tools/design/door-view.selftest.mjs`**, **`door-view-leg.mjs`** — the new
  `sceneryInput()` argument; the themes' selftest gains a side-door document per theme and holds it textless.
- **`tools/design/floor3d.browser.mjs`** — the `restroom` rows read the flag's field; the fixed term is unchanged.
- **`FLOOR.md`** landing with the slices: AT-D3-33's *the visit* GREEN and seventh RED, AT-D3-35's fixture sentence,
  AT-D3-38's restroom GREEN and seventeenth RED; § 13 row 121, § 14 item 50 (§ 7's questions and their answers),
  Appendix B row 50; § 10.6 item 6's `restroom-door` sentence (L8113).

## 7. Operator questions — each with context, an example per option, and the recommendation

1. **Where the flag hangs.** Context: the operator ruled a flag over the doorway; the design mounts it over the NEAR
   jamb because a centred flag covers the low snack table's top-right on the shipped default (measured, § 2). Option A,
   near jamb: on the default the plate stands at y 502–534 over floor; a walker stepping in covers its lower half for a
   second. Option B, centred: the plate at y 470–502 over the table's cups, always. **Recommendation: A**, as a seat
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
seen red, the probe's figures on the card. The coders' other worktrees are named where a slice must land after them.

1. **The door in the wall, in 2-D** — `map-tiles.js`'s `restroomDoorGeometry()` and the `sceneryInput()` argument at
   every caller (§ 3.1); the scene's obstacles, doors, scenery, threshold, lane, legs, exit, lead legs, pose and turn
   (§ 3.2); `route.js`'s `pointAt()` (§ 3.3); both themes, the painter, the stylesheet, the marker SVG and its
   attribution row (§ 3.4); `walk-probe.mjs`, `ACharacterTakesARestroomBreakTest`, `TheLoungeFloorRoutesEveryWalkTest`,
   `verify-floor.py` leg 4 and its selftest plant, `gen-fx-snack.mjs`'s re-cut corridor and regenerated `fx-restroom`,
   `gen-fx-turn-back.mjs` and `fx-turn-back`, the themes' and door view's selftests (§ 6); `FLOOR.md`'s AT-D3-33,
   AT-D3-35, § 13 row 121, § 14 item 50 and Appendix B row 50. One slice because a side threshold under a face-on picture,
   or a side picture with a stand-at-the-threshold `inside` leg, is a wrong floor on the sandbox; the coder may stage
   it as two commits (geometry and drawing; the step through) in one PR. **Overlap, named** (read off the sibling
   worktrees' `git diff` on 2026-11-14): `wt-13033-snack` edits `resources/floor/themes/studio/theme.js` at L31 (an
   import) and L1501 (the `piece3d` export) — different regions from `restroomDoor()` at L1091–1130, so a rebase, not a
   conflict; `wt-13033-eye` edits `FLOOR.md` at L1900–1913 and L12531 and `plan-13033-stage2-3d-floor.md` — this design's
   own commit touches `FLOOR.md` § 6.7, § 10.6 and § 12, so whichever lands second rebases; no sibling edits
   `map-tiles.js`, `scene.js`, `route.js`, `painter.js`, `door-view.js`, the cottage theme, the probes or the tests this
   slice names. `door-view.js` is read by the cab slice's `cab-paint.js` but not edited there.
2. **The door in the wall, in 3-D** — `floor-world.js` (lintels, the piece's `turn`, the leaf, flag and field boxes),
   `furniture.js` placements with `turn` and `facing`, `pieces-3d.js`'s side frame and flag, `room-paint.js`'s lintel
   placement and side leaf / field, `body-paint.js`'s clipping plane with `floor-paint.js`'s `localClippingEnabled`
   (§ 3.5, § 3.6); `floor-world-probe.mjs`, `TheFloorIsTheScenesThirdRendererTest`, `floor3d.browser.mjs` (§ 6);
   `FLOOR.md`'s AT-D3-38 and the row markers. **Overlap, named**: `wt-13033-cab` edits `room-paint.js` (+130 lines at
   L60 and the doorway section L316–346, with the restroom section moved to L509–531) and `floor-world.js` (`couchesOf`,
   the band's leaves) — land this slice AFTER the cab slice and rebase onto it; `wt-13033-snack` moves
   `furniture.js`'s `placementsOf()` (L228–272) into a new `furniture-placements.js` and appends ~120 lines to
   `pieces-3d.js` after `KINDS_3D` — land after it and put the `turn` in `furniture-placements.js`; `wt-13033-eye` edits
   `floor-paint.js` L327–363 (the camera) — `localClippingEnabled` goes beside the renderer's construction at L188–193,
   where the cab slice also adds two lines, so rebase; the 3-D body code (`wt-13033-chars`, clean at the time of
   writing) owns the materials the clipping plane is set on — if it lands first, § 3.6's plane goes on its material
   owner.

## 9. What could falsify it, and what is not defended

- **A map on a grid other than 8 px, or two grids on one floor**: the facing test counts wall cells of the cell's OWN
  grid; a wall on another room's grid beside the tile is not seen. Not defended — `floor-walls.js`'s header already
  states one grid per floor.
- **A door touching a wall that ends part-way up the tile**: `back`, drawn face-on beside a wall stub. Not defended; the
  rule is strict so the author's intent is never guessed. An author who wants a side door extends the wall.
- **A side door whose threshold row is blocked within 120 px** (a desk slot against the wall below the doorway): no
  threshold, the door serves nobody, § 5.5's line — the existing failure, reached by `test_red_the_door_moved_under_a_desk`.
- **The flag covered by the entering walker** for the step's seconds (§ 6.7 item 2): accepted and named.
- **The 3-D walker visible outside the building from a camera beyond the right wall** if the clipping plane is not
  landed: the fallback (visibility at the face) leaves a half-body in the wall for one frame; the plane is the design.
- **Hazards with a check**: the leg off the lattice (AT-D3-38's sample excludes `inside`; a plant that samples it must
  red); the leaf's second move timed from `inside.start` rather than `+ step_in` (the field would read occupied while the
  walker is still in the doorway — `test_green_the_visit` holds the frame); the obstacle kept (the forecourt RED above);
  the facing read from anywhere but the wall (the property RED above); the lane measured from the face rather than the
  threshold (AT-D3-35 over the re-cut corridor: the second walker would wait at (1,652, 628), on the axis — the RED
  *the lane not passed* reds it, and `test_green_the_restroom_lane_is_section_12s` holds the 164 px).
- **What the scratch measurement does NOT cover, named**: the `restroom-one-spot` and `restroom-sealed` variants against
  the side threshold (the build measures them before regenerating); the lane-clear frame of the walker coming out
  (`laneFrame()` over the deeper lane — the build's `test_green_the_occupant_clears_the_lane_before_the_next_steps_in`
  prints which of the closing and the lane is the later on the default); and the 3-D figures, which are lifts of the
  2-D ones and are held by `floor-world-probe.mjs`, not by this plan.

## 10. Seat rulings in this plan, reversible by the operator

The doorway at the tile's foot end (§ 4); the flag over the near jamb (§ 7 Q1); the sliding 2-D leaf (§ 7 Q2); a
`back` door drawn face-on wherever a door touches no side wall; `right` on a tie; the lintel from the tile's height;
no closet (§ 7 Q3).

# card#13033 — design and build plan: the building in 3D (stage 1), and the floor in 3D (stage 2, outline)

**What this file is.** The design and the build plan for one card, in the shape `plan-12987-cab-door-view.md`
set: written for the coder who implements stage 1 and the reviewer who attacks it. It owns the *derivation*,
the *sequence* and the *acceptance list as observables*; it owns no rule. Every rule lives in `FLOOR.md`, which
this commit amends where the view touches a ruling: § 1.1's screen table, § 1.2's library row, § 4's screen
count, § 4.1 (the building view is not the cross-section's return; its words are floor names, desk names and
notices), § 4.4's route table, § 4.5's camera bullet (the aimed zoom), § 10.3's projection bullet, § 10.4's
two-conventions bullet (the text layer), § 11's AT-D3-37, § 12's figures, § 13 row 118, § 14 item 49 and
Appendix B row 47 (planned, no landing marker). A reader who finds the two disagreeing trusts `FLOOR.md` and
fixes this file. When stage 1 has landed, §§ 1–10 of this file are history and § 11 (stage 2) is the brief for
the next design.

**Revision 2 (2026-11-09)** folds design review round 1 (card comment 16459) and the operator's rulings of the same day
(card comment 16451): the page route renamed off the API's `building` (B1); the storey derived from visibility at the
home angle, an exploded stack (B2, the seat's ruling); `room.map` applied by the building screen (M1); one picture
rule (M2); a RED that can fire (M3); and the minors folded where they stand. § 13 is now the operator's rulings,
decided, and no open question remains in this file.

**Revision 3 (2026-11-09)** folds design review round 2 (card comment 16469): the storey derived from the HOME
CAMERA's own sight lines by a bounded fixed point, not from parallel rays (M1); an absolute near limit from the floor
page's zoom ceiling (M2); the scene INPUT assembly hoisted beside `composeFloor()` and each painter's failures routed
to the building screen (M3); AT-D3-37 rebuilt on a named fixture run with an explicit import bound (M4); and the
minors, including the false claims the review found (the deploy's `npm ci`, the extent figures, the converter test's
exclusion). The resize rule, the arrival rule and the copy-cost rule are decided in § 3.3 and § 5.

**Revision 4 (2026-11-09) re-derives the text and the close-up as native to 3-D**, on the operator's answer to
design review round 3 (card comments 16478, 16540, 16542; § 13 ruling 7): round 3's M1 found that the near limit
bounded the camera's distance to an orbit target that is never on a slab, that `zoomToCursor` flew the camera
through the top slab, and that the closest look was a magnified bitmap — three failures of one idea, the 2-D
painter's raster asked to be readable in 3-D. The operator chose **B** (*zoom lands on a floor, close enough to read
its desks*) and directed a different angle. This revision: the **desk names leave the slab's picture and become
labels over the stage** (§ 3.4 — the house docking primitive, crisp at every distance, culled by scale and
occlusion; FLOOR § 10.4 item 6 already rules the nameplate a label over the scene); the **zoom is the page's and
aims at the floor under the pointer**, the camera **never crosses a slab**, and the near limit is the depth at which
the floor under the pointer is at its closest scale (§ 3.3, three pure steps, each with a plant that reds); the
**focused floor's picture is rasterised at that scale**, so the closest look is pixel-exact (§ 3.2); the storey's
population gains the ground slab (§ 3.1); and round 3's eight minors and two hygiene items are folded where they
stand (§ 8 and the FLOOR amendment name each). **What the new approach dissolves and what it folds**: round 3's M1
is dissolved in all three parts — there is no target-relative near limit, no library zoom to fly through a slab,
and no magnified closest look; none of the eight minors is dissolved, each is folded where it stood (m1 § 3.3's
*touched*; m2 § 3.1's stop rule; m3 § 3.3's `constrainView()` and its probe arm; m4 § 4's one-room tileset step;
m5 § 1's extent, § 3.1's figures, § 2 item 1's predicate and AT-D3-37's control; m6 § 8 item 2's scoped *no count*;
m7 the harness's rejected theme and the browser plant; m8 the page's own canvases and the `2d` refusal), and both
hygiene items are taken (§ 3.2's L35; Appendix B row 47's slice-2 cell). The painter gains one option (`omit`), the
first change this plan makes to the 2-D floor's own code; the floor page's drawing is unchanged by it. § 2.1 is the
survey of the directions weighed.

**Design approval.** The operator's request of 2026-11-09, recorded on card#13033: offered a building view, a
floor view, or both, the operator chose *"Both"*, building view first. The card's technical notes fix the library
(Three.js with OrbitControls, plain ES modules, no framework), the order (building view ships first and leaves the
floor view untouched), the starting approach (floor drawings as textures, sprites as upright billboards) and what
the design must state (the camera limits that keep sprites from reading as cardboard, whether full freedom later
needs 3D models, and what stage 2 does to the oblique ruling, the camera, the map-projection refusal, routing and the
ride). None of those is re-litigated here.

⚠ **One date in the card was corrected by the record.** The card dates the 3/4 top-down oblique ruling 2026-10-01.
`FLOOR.md` records that ruling as **2026-10-03** (§ 10.4's projection bullet; § 13 row 49). 2026-10-01 is the
date of the two rulings that sit beside it — the any-size viewport (§ 13 row 14, card#7341) and the camera's gestures
(§ 13 row 39, card#11045). The card's text was corrected to 2026-10-03 on 2026-11-09 (ruling 6, card comment 16451).

## 1. The problem, in the product's terms

A viewer has two drawn screens and no way to see the building as a building. The lobby
(`server/public/js/lobby/building-scene.js`) is one room in one-point perspective with a directory on its wall —
the operator's ruling of 2026-10-20 (card#12113, `FLOOR.md` § 4.1) **replaced** the earlier building cross-section
(stacked floor plates, a cab in a shaft, card#7343) with that room, and `lobby/building-model.js`'s header records
that `elevator()`, `NO_STOPS` and `buildingModel()` left with it. The floor (`server/public/js/floor/painter.js`)
is one floor at a time, in the 3/4 oblique, under a 2-D viewBox camera (`server/public/js/wire/camera.js`). Both
are pictures the viewer pans and zooms; neither lets the viewer move round what is drawn.

What the operator asked for is a **third kind of view**, not a change to either picture: the building from outside,
its floors stacked, each floor's slab carrying *that floor's existing drawing*, the viewer orbiting, zooming and
panning, and a click on a floor opening it. Stage 2 — one floor in 3D with desks, furniture and characters standing
in space — is the view that would supersede the oblique; stage 1 ships first *to show whether the sprite look holds
up before stage 2* (the card's own words), and this plan designs stage 1 in full and stage 2 at outline depth.

### The population the view must cover

The floors are **`lobby/building-model.js`'s `plates()` rows** — `lobby-model.js`'s `floors()` with each row's
`level` added (L26–33): every floor the layout declares, plus one implicit floor per install the layout does not
place, floor keys ascending. That is the same list the directory draws and both cabs offer as stops (its header,
*ONE LIST FOR THE DIRECTORY AND BOTH CABS*), so a floor the building view shows is a floor a viewer can already
reach by plaque or by ride, and one it does not show is one nobody can. On this install the list was last
derived on 2026-11-08 by `plan-12987-cab-door-view.md` § 1 — the three `php artisan tinker` commands there print
the layout document, the installs and each authored map; the readings that day were one planned floor (`aimla`,
theme `cottage`) and four implicit floors (`kanban`, `mezzanine`, `sola`, `solo-agents`, house theme), two of them
on authored maps and the rest on the shipped default `resources/floor/default.tmj` (1,784 × 696 px,
`FLOOR.md` § 10.3; **the scene's extent is taller than the map**: the band is drawn ABOVE the frame's extent — `scene.js`
L2566 `y = span.y − BAND_H` — and `scene.extent` is the union of band, extent, **slab** (`SLAB_H` = 8 px under the
frame, L121, L1565–1570; review round 3's minor 5 found it missing from this sentence), desks and strip
(L1624–1630), so the shipped default's slab is **1,784 × 864 scene px**, and deeper by the overflow strip's gap,
header and row only when `frame.overflow.length > 0` (L1503) — **one figure, used everywhere below**). ⚠ **Not re-run for this plan**: the worktree carries no `.env`, and the live checkout is not
this plan's to enter. The coder re-runs those three commands before slice 3 and records the readings in the PR.

Each floor's **drawing** is `floor/scene.js`'s `buildScene(frame, input)` painted by `floor/painter.js` — the
band (back wall, clock, windows, elevator), each room's plane and scenery, the desks with their characters, labels
and chips, the overflow strip. Its extent is `scene.extent`, the rect *Fit the floor* frames
(`floor-screen.js` L721: `this.#camera = frameOn(this.#camera, scene.extent)`; `fitFloor()` L449).

## 2. The shape, and what was rejected

**Chosen — a fourth screen on a route of its own, `/building`, that composes every floor the way the floor page
composes one, paints each with the floor page's own painter into a hidden host — its nameplates omitted — rasterises
that drawing into a texture, stands the textures on slabs in a Three.js scene under OrbitControls, lifts every
desk's name into a label docked over its slab, and aims its zoom at the floor under the pointer.** The decision
logic — which floors, where each slab stands, how high a storey is, where a zoom lands and what it may not cross,
which labels show, which slab a pick names, how big a texture may be — is one DOM-free module; Three.js and the
DOM are confined to the renderer modules and the page entry. Three hoists come first, each at its second caller:

1. **`floor/floor-compose.js` — the floor's composition, hoisted out of `floor-screen.js`'s `#drawFloor()`**
   (L1264–1437): rooms placed (`placeRooms()`), each room's slots assigned (`assignSlots()`, `mapReservation()`),
   desk positions, the band (`backWallBand()`), the extent, the overflow and the per-room notices. Today that code
   runs only inside `FloorScreen`, which also drains the client's journal (`render()` L611–625 → `takeWire()`),
   keeps displacement bookkeeping (`#displacements()`), fires coordination edges (`#coordinate()`) and holds one
   floor's ride, panel and walks. A building view that instantiated one `FloorScreen` per floor would drain the
   journal N times — one journal, N consumers, N−1 of them blind (the probe's own rule, `fleet-client-probe.mjs`
   L519: *two renderers, one journal*). A building view that re-derived the composition would be the second
   answer to *where does this desk stand* that `plan-12987` § 2 rejected for the door view. So the pure part
   moves: `composeFloor(floor, building, seatsByRoom)` → `{ planned, hallway, extent, band, rooms, draw_order,
   overlaps, overflow, desk_positions, notices, assignments }`, frozen; `#drawFloor()` calls it and keeps the
   journal-dependent rest (`#displacements(assignments, …)`, `#coordinate(…)`, the statement, the narration).
   **And the scene's INPUT assembly moves with it** (review round 2's M3: `buildScene(frame, input)` reads nineteen
   input members — `grep -o 'input\.[a-zA-Z_]*' scene.js | sort -u`; a lower-case class prints `input.head` for
   `input.headClear` and an equality built on it reds on the shipped tree (review round 3, minor 5) — assembled
   today in three places: the page's art
   inputs in `floor/main.js` L427–445, the per-frame documents in `FloorScreen#sceneDocs()` L1119–1131, and the
   handover `sceneInputs()` L525). The same module exports **`artInputs(art, measure)`** — the L427–445 literal:
   `{ box, measure, character, draw, crest, headClear, themes }` from `loadArt()`'s result and the page's measurer,
   `themeInputs()` (`cab-paint.js` L90) as today — **`roomMaps(frame, building)`** — the `Map` of each room's held
   map, L1122 — and **`sceneInput(art, docs)`** — the frozen union of the art inputs and the per-frame documents
   `{ maps, hallway, tilesets, layout_version, failed, reduce, effects, previous }` plus the four in-flight members,
   with the member list `SCENE_INPUT_KEYS` exported beside it. `#sceneDocs()` becomes one `sceneInput()` call;
   `floor/main.js` calls `artInputs()`; the building screen calls both. The painter's `failed` callback
   (`createPainter({ failed })`, `painter.js` L249–260) feeds the screen's `assetsFailed()` set on both screens, so
   `failed` is one member with one writer per screen. Behaviour-preserving: every existing floor test is the control.
2. **`floor/floor-data.js` — the press-time prefetch, hoisted out of `floor/door-views.js`'s `prefetch()`**
   (L68–88): every row's rooms through `Building#enterRooms()`, each held map's tilesets through the page's
   `TilesetLoader`, and the theme modules through `cab-paint.js`'s `loadThemes()` where the page holds none —
   the page woken after each step. That is exactly what `/building` needs on entry for *every* floor, and
   `DoorViews` needs at a ride's press. `DoorViews` constructs a `FloorData` and delegates; its `#loaded` moves
   into it (`FloorData#themes`). The two behaviours the hoist must not change: NO `fetchLayout()` here (the
   header's ⛔ — a press is not a reading of the layout), and the only failure signal stays the data's absence.
3. **`floor/painter.js` `createPainter()` takes its host, and the members it leaves unpainted** — `{ host =
   document.getElementById('floor-drawing'), omit = new Set() }` (L250 reads the id directly today; `omit` is § 3.2
   step 7, a set of `DRAWN_MEMBERS` members — `desk-layout.js` L800 — that `paintDesk()`'s element loop skips). The
   building page needs one painter per floor, each over a hidden element and each with `omit` = `{'nameplate'}`; the
   floor page passes nothing and changes nothing — every floor test is the control, and `painter-probe.mjs` gains
   the leg that drives `omit` (§ 8 item 1).

Then the view's own modules (§ 4). Why this split and not another:

| Rejected | Mechanism it would turn on | Why not |
|---|---|---|
| **Draw the stack inside the lobby page**, a toggle between the room and the 3D stack | a second drawing surface under `#lobby-building`, the 2-D camera wire and OrbitControls on one element | The lobby's room is the operator's ruling of 2026-10-20 and its camera, gestures, directory docking and ride are built and tested over that one surface (`lobby/main.js`); a second surface there puts two gesture systems on one page and makes every lobby test's population ambiguous. A route of its own costs one view and one wiring test and leaves card#12113's screen exactly as ratified |
| **One `FloorScreen` per floor**, each painted and rasterised | N screens over one client | N journal drains (above); N rides, N panels, N walk holders nobody will see; and `createPainter()` is one host per painter either way, so the hoist is needed regardless |
| **Scenery-only slabs** — each floor's plane, walls, scenery and band with no desk on it (the door view's model, `floor/door-view.js`, projected flat) | `doorView()`'s bodies, or `buildScene()` over a frame with no seats | A staffed floor drawn with no desks is the empty office `FLOOR.md` § 9 opens on — *a floor that fails quietly is indistinguishable from a fleet that has gone home* — and the operator asked for *that floor's existing drawing*, which has the desks on it. Rejected, and the operator ruled it so on 2026-11-09 (§ 13 ruling 4: the live floor, desks staffed as held) |
| **Server-rendered textures** (`GET /api/building/floors/{key}/picture`) | a PHP rasteriser over the room maps and themes | The client already composes floors and draws them; a second composer is the drift § 4.6's reader table forbids, the same ground `plan-12987` § 2 rejected a server-rendered door view on. And the desks are a client-held picture — a server has no seat state to draw honestly |
| **Load Three.js from a CDN** | `<script type="importmap">` pointing at jsDelivr | A page that fetches code from a third host at run time is one whose behaviour a deploy does not pin and a provenance gate cannot see (`docs/ATTRIBUTION.md`'s *Nothing that inspects a directory can refuse code that fetches upstream art at run time*); the repository's modules are served from `public/` by versioned URL (`App\Support\PublicAsset`), and the vendored library joins that scheme |
| **A 2-D isometric or oblique fake** (CSS transforms over the floor SVGs, no WebGL) | `transform: rotateX() rotateZ()` on the painted `<svg>`s | No real orbit, no depth, and every pointer coordinate has to be un-projected by hand; the card decided the library |

### 2.1 The text and the close-up, as native to 3-D — the directions weighed (revision 4)

The operator's direction (§ 13 ruling 7): *"Try solving the problem from a different angle - change the floor view or
how text is displayed rather than trying to use the 2D system in the 3D world"*, with the freedom to change the
existing rendering system. What the three review rounds kept finding was one idea failing three ways: a picture
rasterised at one texel per scene px, asked to be readable by zooming at it. The directions, each with the mechanism
it turns on HERE and what it costs here:

| Direction | The mechanism here | What it costs | Verdict |
|---|---|---|---|
| **Names as screen-space labels** — HTML docked over the stage at each nameplate's projected point, at the page's own text size, shown by distance and hidden where a slab is in the way | The repository's docking primitive: the lobby parks its directory boxes in the camera's layer and writes each box's `data-home` (`lobby/directory.js` `dockingEntry()` L138); this plan already docks the floors' list entries to the slabs that way (§ 3.4). `buildScene()` already carries every nameplate as an element with its rect and its fitted text (`desk-layout.js` L1159–1170; `scene.js` L2198–2203 moves it to scene px), so the label is read off the scene model, not re-derived. FLOOR § 10.4 item 6 rules the nameplate and its plate *labels over the scene* that obey no projection — this takes the ruling literally | One `<a>` per desk re-projected on every camera change; a depth test per candidate label (the same segment-vs-slab primitive the camera clamp needs); a drag begun on a label is the label's (§ 3.4) | **Chosen for the names.** Crisp at any distance, culled by scale and occlusion, the same layer § 11 already gives stage 2 — so stage 2 inherits it rather than building it |
| **Signed-distance-field text in the scene** (an SDF glyph atlas sampled by a shader on quads lying on the slab) | A glyph atlas — new art under § 10's provenance gates, or a build step this repository has none of (§ 1.2) — plus a shader and one font at one weight; a library (troika-three-text) brings a worker and WebAssembly | The whole atlas pipeline, for text the browser already draws crisply for free in the label layer | Rejected |
| **Desks and agents as 3-D props or billboards, the text out of the slab, the slab carrying layout and theme only** | `floor/door-view.js`'s model stood up with a real camera; each furniture piece and character a quad — § 11's stage 2 | Builds stage 2's scene before the operator has read stage 1, which ruling 5 makes the gate for exactly that; and changes the drawing the operator reads stage 1 to judge | Rejected for stage 1; it is stage 2 |
| **Splitting the painter so both screens share one scene model with a renderer per target** | Already the repository's shape: `buildScene()` is the DOM-free model and `painter.js` its SVG renderer. The 3-D view is the model's SECOND renderer — the picture through the one painter (with one member omitted), the names through the label layer — and nothing is split | A second renderer of the whole scene (planes, scenery, characters) would be a second implementation of the drawing (canon #5) | **Adopted as it stands**: one painter option (`omit`), no parallel renderer |
| **A close-up MODE for a floor** — zooming past a threshold hides the other slabs and orbits one | A mode flag, a fade, a second camera-limit set; the slab-crossing clamp is then trivial (one slab) | The operator's B describes a flight that STOPS over a floor, not a view that changes; hiding floors is a visible behaviour nobody chose; and the stack view would still need the clamp | Rejected; named as the fallback in § 3.3 if the sandbox reading finds the flight clunky |
| **The focused floor's picture at the closest scale** — the slab the zoom landed on re-rasterised at the scale the closest look shows it at, two buckets | `textureSize()` takes a scale; a focus change re-rasterises one slab (§ 3.2 step 8) | One 4,096 × 1,984 raster per focus change and per render of that floor on this install; its paint time UNMEASURED (§ 10) | **Adopted for the picture, not for the text**: the art is SVG drawn at some resolution regardless, and at the closest look no texel is larger than a screen px |
| **Canvas-text sprites in world space** — a small texture per text element, re-rasterised per size bucket, depth-tested | Hundreds of textures per floor (every fact row, chip, bubble line), re-made on each render whose ages changed | The 2-D text rasterised into textures once more, which is the direction the operator ruled out; and layout that holds in scene px buys nothing the label does not | Rejected |

So the slab keeps the floor page's drawing — the live floor, desks staffed as held (ruling 4) — less its nameplates,
and the names come back as labels. The chip, the facts, the bubbles and the strip stay in the picture: at the closest
look they are pixel-exact (the focused scale), at every farther look they are the picture, and the facts are the
floor page's (a click opens it). Nothing on the 2-D floor page changes: `omit` defaults empty.

## 3. The derivation — how the stack falls out of what the client holds

### 3.1 Units, storeys and slabs

The scene's unit is the floor's scene px over **100** — one world unit per 100 scene px (§ 12's *Building view
unit*, Chosen: it keeps a 1,784 px floor about 18 units wide, which is the scale OrbitControls' default
`minDistance`/`maxDistance` arithmetic is comfortable at; any constant works and this one is written once).

- **A floor's slab footprint is its `scene.extent`** — `w × h` scene px, so `w/100 × h/100` units — the rect *Fit
  the floor* frames on the floor page, so the slab shows exactly what that page shows at fit: band, rooms,
  overflow strip. A floor whose scene has no extent (`scene.extent === null`: no map held and no desk —
  `floor-screen.js` L716's comment) gets the **stack's widest footprint** and **no picture**; its list entry carries
  the floor's own notices (`composeFloor()`'s `notices` — `mapFailureNotice()`, `NO_SEATS_REPORTED` — the floor
  page's words, not new ones).
- **A storey is derived from VISIBILITY from the home camera, not from wall height** — the design review's B2 and
  the seat's ruling on it: an exploded stack. The home camera is a 45° `PerspectiveCamera` at `HOME_POLAR` = 50°
  from vertical, aimed at the stack's centre from `fitDistance()`; its sight lines to the LOWER slabs come in
  steeper than the 40° a parallel-ray reading assumes (review round 2's M1), and on a steeper line the slab above
  hides more of the slab below. With front edges aligned (the alignment bullet below) the front edge is always
  visible and the **back edge** disappears first, so the condition is: the ray from the camera to each lower slab's
  back edge crosses the plane of the slab above's front edge BELOW that slab's underside. In the camera's vertical
  plane, with `h_k` the camera's height above slab `k`'s top, `a` its horizontal distance in front of the aligned
  front edges and `d_k` the slab's depth, that is `s ≥ SLAB_T + h_k · d_k / (a + d_k)` **for every slab below the
  top, the ground slab included** — exact for the back corners too, since a ray's vertical projection does not
  depend on its `x`. ⚠ **The ground is in the population** (revision 4): the first drafts took `plates()`'s rows
  alone, and with one floor that set has no member below the top, so the fixed point collapsed to the slab's
  thickness and the floor's slab sat on the lobby's with their two labels a hair apart; the ground is a slab of the
  stack by ruling 3, a click target with a docked entry, and one rule for every slab needs no branch for one floor.
  The cost is a taller stack — the ground's ray is the steepest and binds on every stack: the fit is 74.1 units
  against 64.1 on this install's five floors — and the lobby's slab seen whole from home. `h_k` and `a` are the
  camera's, and the camera's distance depends on the stack's height, which depends on `s`: so
  `storeySpacing(slabs, surface)` is a **bounded fixed-point iteration** — `s₀` the parallel-ray value
  `max(d) · cot(50°) + SLAB_T`, `sᵢ₊₁ = g(sᵢ)` with `g(s) = SLAB_T + max_k h_k(s) · d_k / (a(s) + d_k)` where
  `h_k(s)` and `a(s)` are read off the home camera `cameraLimits()` computes for a stack of spacing `s`; stop when
  `|sᵢ₊₁ − sᵢ| < 0.01` unit (one scene px) or after **8** iterations, **and the result is the last iterate PLUS the
  tolerance**: the iterates rise toward the fixed point on every stack but the smallest, so the last one sits up to
  the tolerance BELOW it and `s ≥ g(s)` fails by a few ten-thousandths — review round 3's minor 2, which the strict
  occlusion probe would have redded on correct code; with the tolerance added, `g(s) − s` is between −0.007 and
  −0.010 on every fixture (`occl4.py`, below), and the probe's assertion stays strict (canon #3: no tolerance is
  added to the probe). **Why it converges:** `g` depends on `s` only through the camera's climb — `h_k` and `a` both
  grow with the fit distance, whose slope in `s` is bounded by `n / (2 · sin(half-angle))`, and the ratio
  `d_k / (a + d_k)` shrinks as `a` grows — so `g` is a contraction on every stack this plan measured: by finite
  difference `g′` is **0.05–0.22** (two floors the least contractive) for one, two, five and ten floors of the
  shipped default's depth on 1,280 × 600, 1,280 × 800 and 390 × 700 stages, and the iteration settled in **2–5**
  steps (the plan's scratch script `occl4.py`, re-creatable from this paragraph and § 3.3 — it prints every figure
  this section and § 3.3 carry; the probe in § 8 item 3 re-runs the derivation on its fixtures). **At the bound**
  the page draws the stack at the last iterate plus the tolerance — the slabs are all drawn and only a lower slab's
  back band could be clipped — and the probe, which asserts convergence within the bound on every fixture, is where
  a non-contracting stack reds. Figures: for this install's five floors at the shipped default's depth (8.64 units)
  on a 1,280 × 600 stage `s` is **≈ 10.6 units** (the parallel-ray `s₀` = 7.37 would have crossed the ground's
  back-edge ray 2.8 units ABOVE slab 0's underside and slab 0's back-edge ray 1.4 above slab 1's — the band of each
  hidden); for two floors ≈ 8.8; for ten ≈ 11.2; for one ≈ 7.4; and on a 390 × 700 stage five floors ≈ 9.3 (a
  narrow stage fits by its width, the camera is farther, and the rays come in shallower). The first draft's `BAND_H`
  storey (160 px, 1.6 units) would have shown the first 1.9 units of each lower slab — the top floor alone. Pure,
  in `stack-model.js`; § 12's *Building view storey* row is Derived from it. **`s` is re-derived whenever a slab's
  extent changes** — a map re-saved, a seat overflowing onto the strip (§ 1: the strip exists only while
  `frame.overflow.length > 0`), a floor arriving — and the stack re-lays out as a setting, no glide (§ 6.5); what
  the camera does then is § 3.3's arrival rule. The slab itself is **0.12 units** thick (12 scene px, § 12's
  *Building view slab*, Chosen: thick enough to read as a slab edge at the entry framing, thin enough that the
  picture is the slab).
- **Order.** `plates()` orders floors by key ascending and the directory draws them top to bottom in that order
  (§ 4.1's floor-list row). The stack puts **the first row at the top storey** and the last just above the ground,
  so a viewer who has read the directory finds the floors where the directory put them — the operator's ruling 3 of
  2026-11-09: `storey(level, n) = n − 1 − level`, slab top at `y = (storey + 1) × s`. The **ground slab** is the lobby: a plain slab of the widest
  footprint at `y = 0`, no picture (the lobby is a perspective drawing, not a plan), its link the page's lobby href
  (`route('dashboard')`, read off the page as `floor/main.js` reads its *Back to the lobby* link).
- **Alignment.** Slabs are centred on `x`; their **front edges** (the overflow strip's side, the drawing's bottom)
  align at `z = +d/2` of the widest footprint, so the bands (the drawings' tops) recede to the back. The
  drawing's top edge faces **−z**; the default camera stands in front (+z) and above, so a slab reads as the floor
  page reads it: back wall at the far edge, desks nearer.

`stackModel(rows, extents, lobbyHref, surface)` returns `{ storey: s, slabs: [{ floor, name, href, level, storey, box: {x, y, z, w, h,
d}, picture: boolean }], ground: {…}, bounds: {x, y, z, w, h, d} }` — frozen, no DOM, no Three.

### 3.2 The texture — the floor page's drawing, rasterised, with its palette inlined

The painter builds an `<svg>` whose stylesheet is `painter.js`'s `STYLE` (L88): **every colour is a `var(--…)`
token whose value lives in `public/css/mezzanine.css`'s `:root`** (its header's ⛔; `ThePageChromeIsOneLinkedStylesheetTest`
holds it). Its pictures are `<image href="data:image/svg+xml…">` elements — theme documents and character frames
through `cab-paint.js`'s `svgUri()` (L104), the plane and scenery through `art(tiles, uris[0], …)` (L1296, L1304)
— and its band's fill is a `<linearGradient>` in its own `<defs>`. Nothing in it references a URL outside the
document. That is what makes it rasterisable: an SVG drawn as an image may load no external resource, and this one
needs none — **except the palette**, which is the page's and not the document's.

**Measured on 2026-11-09 in the pinned headless Chromium (`chrome-headless-shell` 153.0.8010.12, the build
`design-doc-verifiers.yml`'s `browser-smoke` job runs; `~/.cache/ms-playwright/chromium_headless_shell-1243` on
this seat), with the probe at this plan's scratch path, re-creatable from its description:** an SVG blob drawn
through `<img>` onto a 2-D canvas, holding `fill:var(--ink)` rects, a `color-mix(in srgb, var(--a) 50%, var(--b))`
rect and a nested `<image href="data:image/svg+xml,…">` —

- with the tokens inlined as a `style="--ink:#ff0000;--paper:#00ff00"` attribute on the SVG root: the `var()` rects
  read `[255,0,0,255]` and `[0,255,0,255]`, the `color-mix` rect `[128,128,0,255]`, the nested data image
  `[0,0,255,255]`, and `getImageData()` threw nothing (the canvas is **not tainted**);
- with the tokens left to the page's `:root` (the same `<style>` on the page): the `var()` rect read
  `[0,0,0,255]` — **the SVG image does not see the page's custom properties**, which is the rule, not a bug;
- `canvas.getContext('webgl2')` answered a context with and without `--disable-gpu` (the flag
  `tools/design/headless-chromium.mjs` L35 passes): `MAX_TEXTURE_SIZE` **8192**, renderer *ANGLE (Google, Vulkan
  1.3.0 (SwiftShader Device (Subzero)))* — software GL, which is what the CI leg in § 8 rests on.

So the pipeline, in `building/floor-picture.js` (DOM; its two string functions are pure and node-driven):

1. `tokenNames(css)` — the set of `--…` names `STYLE` and `CAB_STYLE` reference, pure, and **the one extraction
   predicate**: `var(--name` followed by `)` OR by `,` — the fallback form `var(--dv-floor, color-mix(…))` that
   `cab-paint.js` L138–148 uses, which `ThePageChromeIsOneLinkedStylesheetTest`'s own regex `var\(--([a-z0-9_-]+)\)`
   (L181) does not match — less the tokens the painter sets on elements itself (`setProperty('--…')`, which that test
   reads at L186). It returns `[{ name, hasFallback }]`, and that test drives it under node (as its `painterStyle()`
   already evaluates `STYLE` under node, L229–235) in place of its regex — holding the **no-fallback** tokens to the
   sheet's `:root` as today, and **exempting the fallback-form tokens from the declared check**: the cab's `--dv-*`
   carriers (`door-view.js` `VIEW_CARRIERS` L55–67, read off a theme's `PALETTE` by `paletteOf()` L126) are declared
   on no `:root` — the door-view model carries them as `palette` and `cab-paint.js` writes them inline on the view's
   root group, a form the sheet test's `setProperty('--…')` read (L186 over `painterJs()`, L262–266) does not match
   — so a predicate that counted them would red the sheet test on the shipped tree (review round 2, minor 1). The picture's half
   resolves every name and step 4 drops the empty ones, so a fallback-form token keeps its fallback.
2. The page resolves each with `getComputedStyle(document.documentElement).getPropertyValue()` once, and again on
   a `prefers-color-scheme` or text-size change if the sheet ever keys on one (today it does not — stated so the
   reader knows why it is read once).
3. The painter paints the floor's scene into its hidden host at a camera whose `view` is the extent:
   `fit(frameOn(createCamera({ width: tw, height: th }), scene.extent))` — `wire/camera.js`'s own fit, over a
   surface of the texture's size, so the viewBox is the extent letterboxed exactly as the floor page would show it
   at that aspect, and no second viewBox arithmetic is written.
4. `pictureSvg(serialised, tokens, tw, th)` — pure: the serialised `<svg>` with its root given `width="tw"
   height="th"` in place of the painter's `100%`s and a `style` attribute carrying every token's resolved value —
   **skipping a token whose value is empty** (an undeclared token resolves to `''`; writing `--x:;` would override
   the painter's own `var(--x, fallback)` with nothing, where leaving it out lets the fallback stand).
5. A `Blob` of type `image/svg+xml;charset=utf-8` → object URL → `<img>` → `drawImage()` onto a canvas of
   `tw × th` → `THREE.CanvasTexture` with `colorSpace = SRGBColorSpace`, `minFilter = LinearMipmapLinearFilter`,
   **the canvas kept for the texture's life** (a `CanvasTexture`'s `image` IS the canvas; it is what a context
   restore re-uploads from — § 7), the object URL revoked and the `<img>` dropped once drawn,
   `anisotropy` the renderer's maximum (the slab is seen obliquely; without anisotropy the desk text smears).
6. **Texture size**: `textureSize(extent, scale)` — `scale` texture px per scene px (step 8 says which), the result
   scaled down proportionally when its longer side would exceed `max = min(gl.MAX_TEXTURE_SIZE, 4096)` (§ 12's
   *Building view texture cap*, Chosen). The `min()` is what guarantees the upload: WebGL 2 inherits OpenGL ES 3.0's
   floor, and that is **2,048** — *"The value must be at least 2048"* (the `glGet` reference page for ES 3.0, read
   2026-11-09) — so a device at the floor draws every picture at half size and draws it. The 4,096 ceiling is a
   memory and rasterisation-time cap chosen above the shipped default's 1,784 px so the common floor stays at 1:1.
7. **The nameplate is not in the picture** (revision 4). The building page's painters are `createPainter({ host,
   failed, omit: new Set(['nameplate']) })` — `omit`, a set of `DRAWN_MEMBERS` members (`desk-layout.js` L800–801:
   `nameplate: ['nameplate']`) the painter does not paint: `paintDesk()`'s element loop (`painter.js` L511 on, its
   `switch` at L532) skips
   an element whose `member` is in it, which drops the plate rect (L1156, `rect('plate', 'nameplate', R.plate)`) and
   the name (L1162–1163, kind `nameplate`, member `nameplate`) together and nothing else — the chip, the facts, the
   bubble (L648–652, drawn off `desk.bubble`, no member) and the strip's header (L1397) stay. The floor page passes
   no `omit`. Why: at the closest look the name is the label layer's (§ 3.4), and a name in the texture under it
   would be the same string twice at two sizes; at every farther look a 13 px name is under 3 screen px and noise.
8. **Texture scale — two buckets** (revision 4). `textureScale(slab, focused, closestScale)`: the **focused** slab —
   the one the last aimed zoom-in landed on (§ 3.3; *Fit the building* clears it) — at `closestScale` (§ 3.3: 2.296
   on this install, so the shipped default's picture is **4,096 × 1,984** — 1,784 × 2.296 and 864 × 2.296); every
   other slab at **1**, each scaled under the cap as step 6 says. A focus change re-rasterises the newly focused
   slab at its scale and the previously focused one back at 1, and the string compare below keys on the pair (SVG,
   scale). The focused picture holds 32.5 MB of canvas and about 43 MB of texture with mipmaps (§ 10 — the figures
   are 4,096 × 1,984 × 4 B, and × 4/3), and its paint time is UNMEASURED: the browser leg prints it, and § 10's
   100 ms trigger stands.

**What the picture shows, and why that is honest.** Each floor is drawn **under `reduce`** — every § 6.2 row in its
static form (§ 6.4: *reduced motion is a first-class rendering, not a degradation*), **no walk, no bird, no break in
flight** (`buildScene()`'s `effects: []`), the desks staffed as the client holds them. A texture is a still; a still
that shows a held loop's static form claims exactly what the held field claims, and a walk mid-stride on a slab the
viewer cannot see move would be motion with no visible driver. **Every render paints every floor in full** — N painter
paints, one per `plates()` row, because the painter rebuilds its drawing on each paint and identity is no signal —
and then **rasterises the floors whose serialised SVG differs from the last uploaded** (a string compare). ⚠ **The
compare is a correctness guard, not a cost bound** (review round 2, minor 3): a staffed desk's text carries its age
readouts (`DeskFloor`'s `floorAgeReadouts()`, `desk-floor.js` L165, re-read each render; on the floor page the
painter's `refresh()` re-reads them every tick, L1513), so on nearly every render every staffed floor's SVG differs
and is re-rasterised — the compare skips only a floor whose desks are all unstaffed or unchanged in the same
second. What bounds the cost is `requestRender()`'s coalescing and the measured trigger in § 10, not the compare.
The building screen wires the desk floor's 1 s tick to NOTHING — a slab's ages are as of its last render, a still —
so no render is caused by the clock. The rasterisation is **awaited inside the render** (the `<img>` decode is
asynchronous), so a render's textures are that render's frame and never a later one's, and the browser leg (§ 8
item 5) prints the per-floor paint and rasterise times, which this plan does not predict. A texture is never
re-made on a timer: the ages the desks carry are those of the
render, which is § 2.5's own rule for a render, and the 1 s age tick repaints **no** texture (the tick redraws text
nobody can read at a slab's scale and would cost N rasterisations a second; § 12 of this file records it as a seat
ruling, and the list beside the stage carries each floor's name and notices, never an age).

### 3.3 The camera — aimed at a floor, never through one (stage 1)

OrbitControls over a `PerspectiveCamera` (fov 45°), read from `examples/jsm/controls/OrbitControls.js` at r187 (the
vendored file, § 5; every line number below is that file's): it does the **orbit** and the **pan**; **the zoom is
the page's**, because what the library's zoom moves is the camera's distance to its orbit TARGET, and the target is
never on a slab — review round 3's M1. With `zoomToCursor` the camera travels the pointer ray by the target-distance
delta whatever lies on the ray (L813–826: `radiusDelta = prevRadius − newRadius`, applied along `_dollyDirection`)
and the target is re-placed straight ahead, in the air (L859–865); re-derived for this revision's stack (`occl4.py`),
a wheel from home on this install's five floors would have flown the camera about 12 units through the top slab
(the slab's centre 60.5 units off, the wheel's travel to the old near limit 72.3). Without `zoomToCursor`, the first
drafts' `dollyBy()` along the target line stopped between storeys looking at nothing. The defaults as read:
`minDistance 0`, `maxDistance Infinity`, `minPolarAngle 0`, `maxPolarAngle π`, `enablePan true`, `enableZoom true`
(L227), `zoomToCursor false` (L306), `screenSpacePanning true` (L289), `mouseButtons { LEFT: ROTATE, MIDDLE: DOLLY,
RIGHT: PAN }`, `touches { ONE: ROTATE, TWO: DOLLY_PAN }` (L371); the target is clamped to a sphere about `cursor` by
`minTargetRadius`/`maxTargetRadius` (L769–771), and `change`, `start` and `end` are dispatched. The view the pure
model reasons about is `{ position, target }` with the stage's `{ width, height }` and the fov; every function
below is `stack-model.js`'s, DOM-free, and `stack-paint.js` only applies what comes back (`camera.position.copy()`,
`controls.target.copy()`, `controls.update()`).

**The library's settings** — `cameraLimits(bounds, surface)`, § 12's *Building view camera* row, each with its
reason:

- **`enableZoom = false`** — its wheel handler returns before it dispatches or prevents anything (L1785), its touch
  dolly is gated off in both touch states (L1354, L1362, L1448, L1456) and the MIDDLE button's dolly with it, and
  `_clampDistance()` (L1079) is then applied at `_scale` 1 (L783); so `minDistance`, `maxDistance` and
  `zoomToCursor` are left at their defaults and read by nothing;
- **`enableDamping = false`** — no inertia: an interrupted gesture stops where it is, the 2-D camera's rule, and
  there is no motion to cut under `prefers-reduced-motion`;
- **`minPolarAngle = 0.1`** rad (nearly overhead: a plan view of a floor is a legitimate view) and
  **`maxPolarAngle = π/2 − 0.05`** (never below the horizon, so the camera is always ABOVE its target — a property
  `constrainView()` below leans on);
- **`cursor`** = the stack's centre and **`maxTargetRadius`** = half the bounds' diagonal, so a pan never loses the
  building off the screen; **`touches.TWO`** left at `DOLLY_PAN`, which with zoom off is a two-finger pan (L1859
  enters the state while either half is enabled; L1925–1929 then pans); `screenSpacePanning` at its default;
- **`camera.near`** 0.1 and **`far`** 8 × fit (past `maxDistance` and the stack's radius);
- **home** — azimuth 0 (in front), polar **50°** from vertical (`HOME_POLAR`, the camera the storey is derived
  from), at `fitDistance(bounds, fov, aspect)` — the distance at which the bounds' bounding sphere fits the narrower
  of the surface's two half-angles — **74.1 units** on this install's five floors on a 1,280 × 600 stage (§ 3.1's
  stack: 53.1 units high, bounding radius 28.4); a setting, no glide (§ 6.5). At home every slab is small and the
  aimed zoom is what brings a floor to reading size;
- **resize and arrival — one rule, the floor page's** (`floor-screen.js` L372: *a viewer who moved keeps its zoom and
  its centre, clamped*): `cameraLimits()` is re-derived on every resize and on every change of the stack's bounds
  (a slab's extent arriving or changing re-derives `s`, § 3.1); while the camera is **untouched** the home framing is
  re-applied; after the first viewer act the camera keeps its position and target, the new limits clamp it, and the
  stack re-lays out under it. **Touched** is ANY viewer act (review round 3, minor 1): OrbitControls' `start`
  (pointer L1745, touch L1893), the page's zoom reader below (wheel, pinch), and `cameraKeys()`'s acts (keys and
  buttons) — not `start` alone, which the keys never dispatch (`_handleKeyDown`, L1189 on, pans and dispatches only
  `change` through `update()`) and the page's buttons never reach. *Fit the building* is what clears the flag.

**The zoom — aimed at the floor under the pointer.** `building/zoom-gestures.js` (DOM) reads the canvas's `wheel`
(`{ passive: false }`, `preventDefault()` unconditionally — ruling 1's mechanism, and now the only wheel listener
that acts, since the library's returns first) and the **pinch** — a second pointer down while one is held, as
`wire/camera-gestures.js` L263 defines it, each move a zoom by the spread's ratio about the fingers' midpoint — and
hands each to `acts.zoomAt(point, notches)`: ±1 per wheel notch, `log(ratio) / log(ZOOM_STEP)` for a pinch
(`ZOOM_STEP` 1.18, `wire/camera.js` L42, imported). The buttons and `+`/`−` are `acts.zoomStep(n)` = `zoomAt(the
stage's centre, n)` through the house keys wire (below). `zoomAt` is three pure steps:

1. **`aim(ray, slabs)`** — the pointer's normalised device coordinates become a world ray on the page (Three's
   `unproject`); the model intersects the ray with every slab's box (an axis-aligned box: the slab method) and
   answers the nearest `{ slab, point, face }`, or `null`. The ground slab counts. The click's pick (§ 3.4) is the
   same function — one pick primitive; the earlier drafts' `Raycaster` is gone.
2. **In** (`notches > 0`), with a hit — **`dollyToward(view, hit, notches, limits)`**: the TARGET becomes the hit
   point, and the camera moves along its own line to the hit so that the hit's view DEPTH — its distance along the
   camera's forward axis, which is what sets its scale on the screen, not the Euclidean distance, so an off-centre
   hit lands at the same scale as a centred one — becomes `max(L, depth / ZOOM_STEP^notches)`, one notch per
   `constrainView()` step. Without a hit (sky, or the gap between slabs): the camera moves along the line to its
   CURRENT target by the same factor and never nearer than `L` to it — nothing under the pointer, nothing to land
   on, and the target stays where the last aim put it. **Out** (`notches < 0`): along the line from the target away,
   by `ZOOM_STEP^|notches|`, never past **`maxDistance` = 4 × fit** (the whole stack small), each notch a
   `constrainView()` step.
3. **`constrainView(prev, next, stack, limits)`** — the ONE guard, pure, answering `next` or `prev`: (a) the target's
   `y ≥ 0` and inside the target sphere; (b) the target not below the top of **the slab it is over** —
   `slabUnder(target)`, the highest slab whose footprint holds the target's `(x, z)` and whose top is at or below
   it — so a pan carries the target through the air above a slab and never into it, and the camera, always above its
   target, never under that slab's top either; (c) the camera's path `prev.position → next.position`, inflated by
   `camera.near`, crosses no slab box's face — **`segmentClears(a, b, box)`**, the one geometry primitive § 3.1's
   occlusion probe and § 3.4's label occlusion also use. ⚠ *Never crosses a slab's plane* is read as the plane
   WITHIN the slab's footprint — its box's faces — and not the infinite plane: to read a lower floor the camera must
   descend past the slab above it (into the gap the exploded storey leaves, 8.2 units deep on this install's five
   floors), which crosses that slab's infinite plane and no part of the slab. A violation answers `prev`: the move
   stops where it is, no slide. The page applies it to every library `change` too (an orbit, a pan): when it answers `prev` the page
   restores the camera's position and the target and calls `update()` ONCE — which, because `update()` copies
   `_lastPosition` only after it dispatches (L910–920), dispatches a second `change`, on which `constrainView(prev,
   prev)` answers its input and the page calls nothing. Review round 3's minor 3: a listener that called `update()`
   on every `change` would recurse without end, and the ground clamp had no test; now it is one pure function with
   a probe arm (§ 8 item 3).

**The near limit, derived from the closest look.** `closestScale(slabs) = min(ZOOM_CEILING, TEXTURE_CAP / max over
slabs of max(w, h))` — the floor page's zoom ceiling (`ZOOM_CEILING` 4, `wire/camera.js` L72) where the texture cap
allows it, else the scale at which the widest floor's picture reaches the cap: **2.3 screen px per scene px on this
install** (4,096 / 1,784 = 2.296; a stack whose widest floor is under 1,024 px would reach the page's 4).
`nearLimit(surface, scale) = H / (2 · UNIT · tan(fov/2) · scale)` — the view depth at which one scene px is
`closestScale` screen px: **3.2 units on a 600 px stage, 4.2 on 800** (`occl4.py`). It bounds the depth of the HIT
the dolly stops at, so at the closest look the floor under the pointer — or under the screen's centre for a button
— is at exactly that scale; and since the focused slab's picture is rasterised at that scale (§ 3.2 step 8), no
texel is larger than a screen px there: the closest look is pixel-exact, not a magnified bitmap (round 3's M1,
third part). **The desk's name is legible at the closest zoom by a different mechanism — it is a label (§ 3.4), at
the floor page's own nameplate size, and the label layer, not the texture, carries it from the label threshold in.**
From home the wheel reaches the top slab's centre in 18 notches and ends 1.1 units above it; a lower slab's centre
in 20, 2.4 units above it and 8.2 below the slab above's underside — every step's segment clear of every slab
(`occl4.py` asserts it on every fixture it prints).

**What a blocked zoom-out looks like.** A camera low over a floor, orbited nearly overhead, that zooms out rises
toward the slab above and stops at its underside (on five floors after about 3.3 × the near limit): the ceiling is
real, the camera stops, and the way up is to orbit to a shallower angle or press *Fit the building*. Named, to be
read on the sandbox; the close-up MODE (§ 2.1) is the fallback if the operator finds it clunky, and it is a
reversible seat ruling (§ 12).

**Keys and buttons — the house wire, not the library's.** `wire/camera-keys.js`'s `cameraKeys(canvas, buttons,
acts, show)` and `offerKeys()` (L67, L111) — the floor's and the lobby's — with the 3-D acts: `zoomStep(n)` as above;
`drag(dx, dy)` = **`panBy(view, dx, dy, surface)`**, pure, OrbitControls' own screen-space pan arithmetic (L992–1008:
`2 · delta · targetDistance · tan(fov/2) / clientHeight` along the camera's local axes), so the arrows and a
right-drag move the view the same way, through `constrainView()`; `camera()` answers `{ bounds: stack?.bounds ??
null }`, so the wire's gate (`framesNothing()`, L73–75) and `offerKeys()` hide the buttons, the hint and the tab stop
until the stack has an extent, as on the drawn pages. OrbitControls' `listenToKeyEvents()` (L583) is NOT called —
the arrows would pan twice. `Tests\Feature\Floor\TheCameraWireIsOneForBothPagesTest`'s subject is the wire itself
(its probe is `camera-wire-probe.mjs`, L76), so it needs no new leg; its title's *both* becomes false with a third
caller, and it is renamed `TheCameraWireIsOneForEveryPageTest` in slice 4, where the building page's wiring test
holds the two calls.

Gestures on the hint line (as `partials/camera-hint.blade.php` says the floor's): left-drag orbits; the wheel zooms
toward the floor under the pointer; right-drag or two fingers pan; a pinch zooms toward the fingers' midpoint; the
arrows pan and `+`/`−` zoom; *Fit the building*. The operator's ruling 1 of 2026-11-09 (card comment 16451); the
2-D floor and lobby keep the gestures of 2026-10-01 (card#11045, § 13 row 39), and a 3-D view has no *edge* at
which a wheel could hand itself to the page (row 39's Q3). ⚠ The pinch is measured with synthetic pointers only,
as the 2-D wire's is (`camera-gestures.js` L103–106).

### 3.4 Names as labels, the pick, and the list

**The nameplate leaves the picture and comes back as a label.** `FLOOR.md` § 10.4 item 6 already rules that the
nameplate and its plate are *labels over the scene* that obey no projection; the 3-D view takes that literally. The
scene model is unchanged — `buildScene()` is DOM-free and already carries, per desk, every element with its kind,
member, rect and text (`desk-layout.js` L1156 the plate rect, member `nameplate`; L1159–1170 the text, kind
`nameplate`, role `name`, the string `fit()` to `PLATE_TEXT_W`; `scene.js` L2198–2203 moves each to scene px). The
painter paints every element but the omitted member (§ 3.2 step 7), and the building screen derives the labels from
the same elements: **`deskLabels(scene)`** → per desk `{ key, install_id, seat_id, text, at, href }` — `text` the
nameplate element's text (the floor page's own fitted string: one truncation rule), `at` that element's rect centre
in scene px, `href` `/floor/{key}/{seat_id}` (§ 4.4's route table's *drill-down open* row; the floor's key, never
its label — § 4.4's ⛔). Interns have no nameplate and no label; a desk on the overflow strip has both. Pure, in
`building-screen.js`, on the frame as `labels` per floor.

**The list is the labels.** `#building-floors`: one `<li>` per `plates()` row — the floor's `<a href>` (its `name`,
`label ?? key`, decided once by `floors()`), its notices as lines, and a `<ul>` of its desks, each an `<a href>` of
the desk's label — plus the ground entry, the page's lobby href (`route('dashboard')`, read off the page as
`floor/main.js` reads its *Back to the lobby* link). The floor page lists its desks under its drawing too
(`FLOOR.md` § 4.4's floor-route paragraph: *below the drawing the page renders the desks as text too*, § 4.5's
list view); here it is the names and their links only, not the list view's rows — the facts are the floor page's. It is the **accessible surface**: a keyboard user or a screen reader opens a floor or a
desk from it; before the first applied snapshot and layout it flows as one WAITING placeholder (the view carries no
layout — there is no list until the client has fetched one, as on the lobby), and it is the whole page when WebGL
is unavailable (§ 7). **The stack is not drawn until at least one floor's scene has an extent**: every scene is
`null` until `loadArt()` has answered and a map is held, so until then the canvas stays hidden, the camera row
hidden, and the list flows; the first frame with an extent draws the stack at its home framing (a setting, § 6.5).

While the renderer runs, **one docking function** places every entry on the stage — `data-home="stage|flow"`, the
lobby directory's pattern (`lobby/directory.js`'s `dockingEntry()` L138 is the one writer of each box's
`data-home`, L166, and the sheet keys every dependent style on it, `mezzanine.css` L632–641): a floor entry at its
slab's front-left corner — `worldOf(slab, { x: extent.x, y: extent.y + extent.height })`, the drawing's bottom-left,
which the alignment rule keeps visible from home — and a desk entry at `worldOf(slab, label.at)`; `worldOf()` is
the one scene-px-to-world map (the slab's local frame: centred on `x`, its front edge at `+d/2`, the drawing's top
toward `−z`, § 3.1), and the page projects each anchor (`Vector3.project(camera)`) into a `transform:
translate()`. One element is both the label and the link, and no second list is written.

**Which labels show** — **`labelVisible(view, anchor, ownSlab, slabs, threshold)`**, pure: (a) the anchor is in
front of the camera; (b) `threshold === null` (a floor entry) or **`screenPxPerScenePx(depth) ≥ threshold`** where
`depth` is the anchor's distance along the camera's forward axis and the threshold is the label's width in screen
px over the furniture box's width (`FLOOR.md` § 12's *Furniture box at the cap*, **440 px**) — the label's width
being the painter's own `measurer()` (`painter.js` L180: the page's canvas `measureText` in the role's font, the
measurer `fit()` cut the string with) over the text in `FONT_NAME`, plus the element's horizontal padding read once
off the sheet — never a DOM read of an element that may be `hidden`, whose width is 0. It is the scale at which the
label is as wide as its desk's box, from which inward two desks' labels cannot overlap because two boxes never do
(§ 10.4 item 3: the slots are disjoint on a valid map, and the strip lays its boxes side by side); a 94 px name —
twelve characters at 13 px bold, an estimate the page replaces with the measure — shows from a depth of about 34
units in on a 600 px stage (`occl4.py`); (c) the segment from the camera to the anchor, **the anchor lifted a hair
above its slab's top** (an anchor ON the face would fail its own slab's test), crosses no OTHER slab —
`segmentClears()`. **(c) is not optional**: a camera 2 units above a floor, looking down, has the floor below it at
a depth of about 12.5 units and a scale of 0.58 — above the threshold — so without it every lower floor's names
would float on the upper floor's picture (`occl4.py`'s occlusion row, on every fixture). **And the B property
follows**: at the close-up on a floor (§ 3.3's end state) the camera is above that floor and below the slab above
it, every one of its desks is at the closest scale — far above any label's threshold — and no slab lies between the
camera and a point just above the floor, so **every desk's name on the focused floor is shown** (§ 8 item 3 holds
it on the five-slab fixture, with the unlifted anchor as its plant). A hidden entry keeps `data-home="stage"` and
is `hidden`; it is still in the list for the screen reader.

**The label element**: an `<a>` at `FONT_NAME` (13 px bold, `desk-layout.js` L69 — set on the element from the
import, so the sheet restates no font; the floor page's own nameplate size, which `FLOOR.md` § 12's *Nameplate type
size* row owns), on `var(--scene-plate)` with a `var(--scene-plate-edge)` border in `var(--scene-ink)` — the
painter's own plate and text tokens (`painter.js` STYLE L89, L109) — so it is the nameplate in the page's palette.
⚠ It stands above the canvas, so a drag begun on a label is the label's, not OrbitControls' (whose listeners are the
canvas's, r187 L499): the viewer drags beside it — the lobby's board boxes over its camera layer have the same
edge. No age, no count and no state is on a label; the building view carries **no figure of its own** (§ 4.1's
reasoning for the lobby — a count a viewer counts by eye — has nothing to disagree with here).

**A click on a slab opens its floor.** `pointerdown` records the point; a `pointerup` whose distance from it is
**under** `DRAG_SLOP_PX` (`wire/camera-gestures.js` L115, 4 px — imported, not copied; `< DRAG_SLOP_PX` is that
wire's own test at L337, so a 4 px move is a drag there and here) is a click (`isClick(down, up, slop)`, pure);
`aim(ray, slabs)` (§ 3.3) names the slab, and the page does `location.assign(hit.slab.href)` — `/floor/{key}` by
the key, the lobby href for the ground slab. A click that follows a drag opens nothing; OrbitControls keeps its
gesture and the page keeps the decision.

**The canvas** is `role="group"` `aria-label="the building drawing"`; it is a tab stop naming its
`aria-keyshortcuts` only while offered (`offerKeys()`, § 3.3), as `#floor-drawing` is (`floor.blade.php`); it is
never the only route to a floor or to a desk.

## 4. Modules, and the DOM-free split

The house rule (`lobby/lobby-model.js`'s, restated in every renderer's header): there is no browser on the build
host, so every DECISION lives in a module `node` can drive; only renderers touch the DOM, and only one touches
WebGL. New files under `server/public/js/`:

| Module | DOM / WebGL | Owns | Driven by |
|---|---|---|---|
| `floor/floor-compose.js` (hoist) | none | `composeFloor(floor, building, seatsByRoom)`, `artInputs()`, `roomMaps()`, `sceneInput()`, `SCENE_INPUT_KEYS` — § 2 item 1 | every existing floor test (control); `TheFloorCompositionIsOneForBothScreensTest`: `floor-screen.js` imports it and holds no second `placeRooms()`/`assignSlots()` call site |
| `floor/floor-data.js` (hoist) | none (`fetch` injected) | `FloorData` — § 2 item 2 | `TheElevatorIsAControlOnTheDrawingTest`'s door-view leg (control), the building screen's probe |
| `building/stack-model.js` | none | `stackModel()`, `storeySpacing()`, `cameraLimits()`, `fitDistance()`, `closestScale()`, `nearLimit()`, `textureScale()`, `textureSize()`, `aim()`, `dollyToward()`, `panBy()`, `constrainView()`, `slabUnder()`, `segmentClears()`, `screenPxPerScenePx()`, `labelVisible()`, `worldOf()`, `isClick()`, the unit and storey constants — §§ 3.1, 3.3, 3.4 | `stack-model-probe.mjs` under `TheBuildingViewStacksTheFloorsTest` (AT-D3-37) |
| `building/building-screen.js` | none | `BuildingScreen`: drains the journal once (`LobbyScreen#render()`'s shape, L162–186: `building.layout` applied, the layout fetched once after the first applied snapshot), runs ONE `DeskFloor` over every held seat with `reduce: true`, holds one `FloorData` and prefetches every row on entry and on each layout change; **applies `room.map`** through `Building#applyRoomMap(entry, rendered)` with `rendered` = every room of every `plates()` row (D2 § 8.7's *a client applies a `room.map` only for a room it renders* — this screen renders every room), records the apply's line in the client's record as the floor screen does (`floor-screen.js` L657–667) and then loads **the applied room's tilesets alone** — `FloorData#roomTilesets(installId)`, one row of the press sequence's tileset step (`door-views.js` L74's call for one id) — never `prefetch()` again, which re-enters every room and re-requests every room whose last request failed (`wire/building.js` L234–247: *a room whose last request failed is fetched again whatever it holds*), so a `room.map` for one floor would have re-fetched another floor's failed map, which the fixture scripts one answer for (review round 3, minor 4); **the interface change**: `BuildingScreen#render()` is `applyRoomMap()`'s second screen caller (the floor screen's `#layoutActs()` is the first; the lobby applies none, `lobby-screen.js` L23) and the first whose `rendered` set is the whole building, so a re-saved or recovered map refreshes its slab on the next render; and per `plates()` row: `composeFloor()` → the frame → `buildScene(frame, sceneInput(art, docs))` with `effects: []`, `previous` empty, the four in-flight members empty, `maps` from `roomMaps()`, `hallway` the row's, `tilesets` the `FloorData`'s, `failed` this screen's set — fed by every floor's painter through `createPainter({ failed: (ids) => screen.assetsFailed(ids) })`, so a theme or character a slab could not draw is on that floor's notices as it is on the floor page's strip — then `deskLabels(scene)` (§ 3.4) → `{ row, frame, scene, labels }`; returns `{ floors, stack: stackModel(…), strip, failure, statement, layout, notices }`. No camera (the 3-D camera is the page's and holds no state the screen reads), no ride, no panel | `fleet-client-probe.mjs` gains a `building` entry beside `floor` and `lobby` (one renderer per scenario, L519's rule), which injects one rejected theme as the `floor` entry does (L669); AT-D3-37 |
| `building/floor-picture.js` | DOM (hidden hosts, canvas, `<img>`); `tokenNames()` and `pictureSvg()` pure | one painter per floor over a hidden `<div>` in `#building-pictures`, each `createPainter({ host, failed, omit: new Set(['nameplate']) })`; the rasterisation of § 3.2 at `textureScale()`'s scale — the focused slab at the closest scale, the rest at 1 — each canvas kept in `#building-pictures` with `data-floor` (it is its texture's `image`, and the browser leg reads the page's own pixels there, § 8 item 5); the compare that skips an unchanged (SVG, scale) pair | `picture-probe.mjs` for the two pure functions; the browser leg for the rest |
| `building/stack-paint.js` | **Three.js** | the renderer over `#building-canvas`, the slab meshes (`BoxGeometry`, a `MeshBasicMaterial` per face — the top face the floor's `CanvasTexture`, the others the sheet's slab token), the ground slab, lights (none: unlit materials, the drawings carry their own light), OrbitControls with `cameraLimits()` (`enableZoom` off), the pointer-to-world ray (`unproject`) handed to `aim()`, the `change` listener that applies `constrainView()`, the application of every view the acts return, `resize()`, and the projector (`Vector3.project`) the labels module docks with | the browser leg |
| `building/zoom-gestures.js` | DOM (the canvas's `wheel` and pointer events) | the wheel (`preventDefault()` unconditionally, ruling 1) and the pinch (a second pointer while one is held, the spread's ratio, the midpoint), each handed to `acts.zoomAt(point, notches)`; decides nothing about the camera (§ 3.3) | `zoom-gestures-probe.mjs` under node with stand-in elements, `camera-wire-probe.mjs`'s shape: a wheel notch over a point is one `zoomAt(point, 1)`, a pinch whose spread ratio is `r` is `zoomAt(midpoint, log r / log ZOOM_STEP)`, every wheel is prevented; plants: the wheel's `preventDefault()` dropped; a pinch zooming about the first finger |
| `building/labels.js` | DOM (`#building-floors`) | the list — floors, their notices, their desks — and the one docking function: `data-home="stage|flow"`, each entry's anchor through `worldOf()`, projected by the paint's projector, shown by `labelVisible()`, the label's font from `FONT_NAME` and its width measured once per text | the browser leg: each entry's `data-home`, the top floor's desk labels shown after a wheel over its slab and `hidden` at home |
| `building/main.js` | DOM entry | `livePage()`, the ids, the screen, the pictures, the paint, the zoom reader, the labels, `cameraKeys()` / `offerKeys()` with the 3-D acts, the WebGL check | `BuildingPageWiringTest` (ids both ways; the log handed to the screen and used nowhere else — `LobbyPageWiringTest`'s shape; `cameraKeys()` called once with the canvas and the camera row's buttons; `listenToKeyEvents(` nowhere) |

Changed files: `floor/floor-screen.js` (calls `composeFloor()` and `sceneInput()`), `floor/main.js` (calls `artInputs()`),
`floor/door-views.js` (holds a `FloorData`), `floor/floor-data.js` (`roomTilesets()`, with the hoist), `floor/painter.js`
(`host` and `omit` options; `painter-probe.mjs` gains the `omit` leg), `server/app/Support/PublicAsset.php` (§ 5's bare
specifier), `server/routes/web.php`, `server/resources/views/building.blade.php` (new), `dashboard.blade.php` and
`floor.blade.php` (the entry link, § 6), `public/css/mezzanine.css` (the stage's size: the viewport height the chrome
leaves, `#floor-drawing`'s rule at L851–858; the label rules keyed on `data-home`),
`server/tests/Feature/Support/ModuleSpecifiers.php` (§ 5), `server/tests/Feature/Floor/TheCameraWireIsOneForBothPagesTest.php`
(renamed `…ForEveryPageTest`, § 3.3), `tools/design/render-floor-view.php` (a `building` view for the browser tool).
⚠ **The 2-D floor page's drawing is changed by none of these**: `omit` defaults to nothing, `roomTilesets()` is a
new method the floor page does not call, and the hoists are behaviour-preserving (§ 8 item 1).

**The frame the picture is painted from** is `composeFloor()`'s output plus what `#drawFloor()` adds around it
(`floor`, `room` from a `RoomClock` the building screen holds as the lobby does, `desks` from the one `DeskFloor`,
`coord` and `coord_fleet` **empty** — the thread edges are drawn on arrival on the floor page and are a still's
nothing — and `failure`/`statement`). `buildScene()` reads `frame.desks`, `frame.overflow`, `frame.floor`,
`frame.rooms`, `frame.draw_order`, `frame.coord_fleet`, `frame.band`, `frame.room`, `frame.planned`,
`frame.extent`, `frame.coord` (grep over `scene.js`, re-run by the coder; `frame.h` at L2141 is `effect.frame.h` and not the frame's) — the building screen hands every one,
and the slice-3 probe holds **both** member sets — the frame's AND the scene input's — equal to the floor screen's
at the same fixture and holds `SCENE_INPUT_KEYS` equal to the set of `input.<member>` reads in `scene.js`, so a
member the floor screen adds later, or an input the building screen drops (`hallway`, `failed`), reds here instead
of drawing a slab from a scene missing it.

## 5. Three.js — where it comes from, how it is pinned, how it is attributed

**Source.** `three` on npm, version **0.187.0** (`curl -s https://registry.npmjs.org/three/latest` → `0.187.0`,
licence `MIT`); its GitHub release is **r187**, published **2026-10-30T08:03:18Z** (`gh api
repos/mrdoob/three.js/releases/latest`). The package's `build/` directory at that version holds `three.cjs`,
`three.core.js` (1.41 MB), `three.module.js` (643.46 KB), `three.tsl.js`, `three.webgpu.js` and
`three.webgpu.nodes.js` — **no minified build** (`https://cdn.jsdelivr.net/npm/three@0.187.0/build/`, read
2026-11-09). `three.module.js` begins with the MIT banner and `import { … } from './three.core.js'` /
`export { … } from './three.core.js'`, so **both** files are vendored. `examples/jsm/controls/OrbitControls.js`
(40,918 B) begins `import { Controls, MOUSE, Quaternion, Spherical, TOUCH, Vector2, Vector3, Plane, Ray, MathUtils }
from 'three'` — a **bare specifier**, which the house import map must resolve (below).

**Where.** `server/public/js/vendor/three/three.module.js`, `three.core.js`, `addons/controls/OrbitControls.js`,
and `LICENSE` (the package's, 1,081 B — *Copyright © 2010-2026 three.js authors*, the MIT text). Under `public/`,
so `PublicAsset::moduleMap()` lists each by its URL and versions it by mtime with no change
(`EveryModuleAPageLoadsIsVersionedTest`'s population is the files on disk). The payload is about 2.1 MB of
unminified JavaScript on the building page and nowhere else (`three.module.js` imports `three.core.js`; no other
page imports either). ⚠ **Whether Apache compresses it is unverified**: `mod_headers` is known absent on the
sandbox (`PublicAsset.php`'s header, 2026-10-20) and `mod_deflate` was not checked; the coder measures
`curl -sI -H 'Accept-Encoding: gzip' <sandbox>/js/vendor/three/three.core.js` in slice 2 and records the transfer
size. No minification step: the repository builds nothing (§ 1.2), and a minifier would be a build.

**Pin.** Each vendored file gets the house vendor header — the form `bin/pr-body-lint.py` L1–60 carries: upstream
repo, path, the version and the published commit, the upstream file's sha256, the declared local edits (**none**
for all three) — and a row in `bin/vendor-pin-check.sh`'s manifest. That script derives a file's BODY as every
line from the first that is neither blank nor a `#` comment (its header, *WHAT IT PINS*); a JavaScript header is
`//` lines, so the manifest row grows a fourth column, the comment leader (`#` for the rows it has, `//` for these),
and `BODY_AWK` takes it — the one primitive extended, not a sibling script. **The selftest's arms take the leader
too**: today the RED arm writes its mutated body line as `# vendor-pin-check selftest: …` (L236), and the GREEN arm
requires line 2 to begin `#` and writes its mutated header line as `# …` (L247–253); with `//` rows those three
sites read the row's leader, or the GREEN arm would refuse every JavaScript row and the RED arm would plant a
syntax-neutral line the body sha still catches but for the wrong reason. With no local edit the body IS the
upstream file byte for byte, so the body sha256 equals the published file's digest: jsDelivr publishes each as a
base64 SHA-256 — `three.module.js` `EE9WFFiZciCUVWSQ14eq4GF8wvwzgvMd8C1yTqGu40E=`, `three.core.js`
`YT43D3Zjj974OSZkK/JW3JYVaEki3hzh9jqFg+XXhXg=`, `OrbitControls.js` `5/yi/p52cKgor5soCEZppZCURbfzbx8mzum/jylhwyw=`
(`https://data.jsdelivr.com/v1/package/npm/three@0.187.0/flat`, read 2026-11-09) — and the header records the hex
of the same number, so for once the pin IS comparable to upstream offline (the script's own caveat, *a green here
means the body is what this repo last DECLARED*, does not apply to these rows). `--selftest`'s control arm mutates
line 2 and requires a comment there: these files' line 2 is a `//` header line, which the leader column makes it
accept.

**The advisory audit.** `dependency-audit.yml` reads `server/composer.lock` and `server/package-lock.json` (its
CUSTOMIZE zone, L126) and prunes paths holding `vendor` (`EXCLUDE_DIRS`, L170); a copy under `public/js/vendor/` is
in no lockfile, so **no advisory on Three.js would reach the audit**. The leg: `three` is added to
`server/package.json`'s `devDependencies` at the exact vendored version (`0.187.0`, no range) so `package-lock.json`
names it and both of the audit's sources cover it — OSV over the lockfile, and `mrdoob/three.js`'s own advisories
through source 2's per-dependency read. **The cost: the deploy installs it.** `bin/deploy.sh` runs `npm ci`
inside the maintenance window (L4260–4264) and `npm run build` (L4268), so every deploy downloads and unpacks
`three@0.187.0` into `server/node_modules` — **21,030,536 B unpacked in 1,277 files** (`dist.unpackedSize` /
`dist.fileCount` from `https://registry.npmjs.org/three/0.187.0`, read 2026-11-09), served by nothing and imported
by nothing (the test suite runs with no `node_modules`, `php-tests.yml` L103; the page imports the vendored copy).
A first draft of this paragraph said nothing installs it; review round 2 (minor 2) read the deploy. The vendor header's version is held equal to the lockfile's by a check in
`bin/vendor-pin-check.sh`'s run (a row-level comparison beside the sha: the header's `plugin version` line against
`package-lock.json`'s `node_modules/three` version), so the audited version is the served one. Accepting the gap on
the record was considered and rejected: a rendering library that reads no network input is a low-risk surface, but
a declared-and-checked one costs one lockfile entry.

**Whole-tree walks over `public/js` that will reach `public/js/vendor/` — every one INCLUDES it**, each read:
`EveryTimeGoesThroughTheConverterTest` scans every `.js` under `public/js` for its `JS_RULES` (L42–55) and asks each
finding to go through `wire/clock.js`; `TheLobbySkyIsTheFloorsA17Test::sourceDefects()` (L429–455) walks every `.js`
for a phase literal. **Measured 2026-11-09 on the three r187 files** (fetched from jsDelivr, comments stripped as
the tests strip them): every `JS_RULES` pattern and `PHASE_LITERAL` find **0** matches — so an exclusion would narrow
a guard with no reachable finding (canon #6), and a first draft's `vendor/` exclusion for the converter test is
withdrawn (review round 2, minor 4); should an upstream version ever match one, the finding is read like any other
and allowed by name in the test's allow-list, which is how it treats its own exceptions. `CoordModuleWiringTest` and
`DrillDownModuleWiringTest` walk the tree for a second `export function clockTime(` and for the drill-down's imports
— a vendored file cannot carry the anchor they look for. `EveryModuleAPageLoadsIsVersionedTest` includes it (above)
and **gains `/building` in `pages()`** (L272–277 hardcodes the lobby, the floor, the console and enrolment; without
the row the building page's module scripts would go unversioned unchecked — review round 2, minor 5).
`DrivesAShippedClientModule::mutatedModules()` copies the WHOLE `public/js` tree for every planted control (L235, for
the `../` imports): `grep -rc 'mutatedModules(' server/tests` counts **487** call sites today, and the tree grows from
1.35 MB (`du -sb server/public/js`) to about 3.5 MB with the vendored files, so a suite run writes about 1.7 GB of
copies instead of 0.66 GB. **The decision rule** (a seat ruling, § 12): the slice-2 PR body states the `php-tests`
job's wall time before and after; a delta **under 5 %** of the baseline keeps the plain copy; over it,
`mutatedModules()` **symlinks** `vendor/` into each copy instead of copying it — nothing is ever planted under
`vendor/`, and a symlink resolves the same relative imports, so no probe learns a second layout. **The one
exclusion that stands**: none. `ModuleSpecifiers` walks reach it only from `building/main.js` (the building page's own graph
test, § 8).

**Attribution.** The asset manifest's gate walks `resources/` alone (`bin/asset-provenance.py` L189
`ASSET_TREES = ("resources",)`; `docs/ATTRIBUTION.md`: *Only `resources/` is measured*), so Three.js is **not** a
manifest row — a row for a path outside the trees is not a shape the gate reads. It is code, attributed as the
repository attributes vendored code: the `LICENSE` file beside it reproduces the notice MIT obliges in *every
copy*, each file's header names the source, and `docs/ATTRIBUTION.md` gains a section **Third-party code under
`server/public/js/vendor/`** outside the manifest markers, naming the three files, the version, the licence and
the pin check — a pointer, since the notice itself lives in the vendored `LICENSE` and a second copy would drift.
The operator's licence allowlist (`CC0-1.0`, `ISC`, `MIT`) covers it; nothing is widened.

**The import map.** `PublicAsset::moduleMap()` adds one entry outside its walk: `"three" =>
self::url('js/vendor/three/three.module.js')`, declared as a constant `BARE_SPECIFIERS` with its reason
(OrbitControls' `from 'three'`). The page's own modules import Three by relative path
(`../vendor/three/three.module.js`, `../vendor/three/addons/controls/OrbitControls.js`), which the URL-keyed map
already versions; the bare entry exists for the addon's one line. `EveryModuleAPageLoadsIsVersionedTest` gains an
assertion that the map's `three` is the vendored module's versioned URL (its `moduleDefects()` ignores keys outside
the on-disk population today, so the bare key passes it unseen — the assertion is what makes the entry checked).
`Tests\Feature\Support\ModuleSpecifiers::judge()` returns a bare name as unfollowable with the reason *a bare name
resolves through no import map this tree has*; that sentence becomes false, so `judge()` takes the map's bare keys
(from `PublicAsset::BARE_SPECIFIERS`) and resolves `three` to its file, and the reason text is corrected. No walk
reaches the vendor directory today (the lobby's bound starts at `lobby/main.js` and `DOOR_VIEW_ROOTS`); the
building page's own graph test (§ 8) is the first.

## 6. The page, the route, and how it coexists with the lobby

- **Route.** `GET /building` → `view('building')`, named **`building.view`** — ⛔ not `building`, which
  `GET /api/building` already holds (`routes/fleet.php` L100–101, `->name('building')`) and which
  `FleetReadGate::SESSION_ONLY_ROUTES` lists by that name (`app/Http/Middleware/FleetReadGate.php` L89); a second
  route of that name would make `bin/deploy.sh`'s `php artisan route:cache` (L4300) throw on the duplicate and
  `route('building')` resolve to the API — inside the `auth`+`mfa` group beside `/dashboard` and `/floor/{floor}`
  (`routes/web.php` L143–170). `FLOOR.md` § 4.4 gains the row: on entry it
  fetches the snapshot (the client protocol, `livePage()`), `GET /api/building`, then — the ride press's sequence
  through `FloorData` — every floor's room maps the client does not hold at the reported version, their tilesets,
  and the theme modules (`loadArt()`, as the floor page loads them: the page holds every theme since it paints
  every floor). It admits the same seats the lobby admits, and changes nothing either existing route fetches.
- **The view.** `building.blade.php`: the layout's header with the fleet counts and a *Back to the lobby* pill
  (the floor page's `#floor-fleet-counts` / `.floor-home` pattern); the status strip (`floor/status-strip.js`,
  shared); the failure statements (`wire/failure-render.js`, shared); a camera row (`#building-zoom-in`,
  `#building-zoom-out`, `#building-fit`); the stage — `#building-canvas` inside `#building-stage`, the hint
  line's row under it — then `#building-floors`, the list; `#building-pictures` (`hidden`), the painters' hosts;
  the event log `<details>`; the sign-in prompt. Every placeholder says it is WAITING (the lobby's ⛔). No cab: the
  building view offers no ride — the way to a floor from here is a click or a link, and a ride from a page that
  draws no elevator would be navigation dressed as a lift.
- **Entry links.** One pill, **3D**, in the lobby's camera `<nav>` (`dashboard.blade.php`) and one in the floor
  page's camera row beside *Whole building* (`floor.blade.php`), each `href` `route('building.view')`; the floor's
  *Whole building* link keeps pointing at the lobby — the lobby stays the home. The operator's ruling 2 of
  2026-11-09.
- **What does not change.** The lobby's room, directory, camera, gestures and ride (card#12113); the floor page
  entire; `wire/camera.js` (the building view runs no 2-D camera on the page — `floor-picture.js` uses `fit()` to
  frame a texture, § 3.3 imports its `ZOOM_STEP` and `ZOOM_CEILING`, and `camera-keys.js`'s gate reads
  `framesNothing()` over the acts' `{ bounds }`); `wire/camera-keys.js` (called by a third page, unchanged); `FLOOR.md` § 4.1's ruling that the lobby draws no desk and the cross-section is gone. The
  building view is **not** the cross-section's return: no shaft, no cab, no cab-position cue, no count; it is
  pictures of floors on slabs, on a route of its own, and § 4.1's amendment says so.

## 7. Fallback when WebGL is unavailable

Three.js has required WebGL 2 since its WebGL 1 support was removed (r163, per its release notes — the coder
confirms at the pinned version by reading `WebGLRenderer`'s context request, which throws without one). Before constructing anything,
`main.js` asks `document.createElement('canvas').getContext('webgl2')`; on `null` it writes the model's one
statement into `#building-statement` — *this browser offers no WebGL 2, so the building is not drawn; the floors
are listed below* — leaves `#building-canvas` and the camera row hidden, leaves the list in flow
(`data-home="flow"`), paints no picture and constructs no painter. The list is the whole page then, and it is
the same list the drawn page docks to its slabs (§ 3.4), so no second rendering exists. A renderer that **loses**
its context later (`webglcontextlost`) shows the same statement and stops painting; `webglcontextrestored`
rebuilds the slabs from the last frame and **re-uploads every floor's texture, bypassing § 3.2's string compare**
(the compare says whether the DRAWING changed, and a lost context has lost every upload regardless) — the canvases
are still held as each `CanvasTexture`'s `image`, so the re-upload is Three's own `needsUpdate` over them and no
floor is re-painted for it (review round 2, minor 6). The statement is a constant in `building-screen.js` (text is a decision),
and the WebGL probe is the page's (a capability read is the browser's own fact, not a model's).

## 8. How the tests pin it — each RED a plant in the shipped tree

Node, through the house rig (`DrivesAShippedClientModule`, `DrivesTheFleetClientModule`), and one browser leg:

1. **The hoists are behaviour-preserving, and the painter's `omit` paints everything by default** — every floor
   test is the control; `TheFloorCompositionIsOneForBothScreensTest` walks `floor-screen.js`'s specifiers
   (`ModuleSpecifiers`) and holds that it imports `floor-compose.js` and that `assignSlots(` and `mapReservation(`
   are called in `floor-compose.js` and nowhere else under `public/js` (a second call site is the defect;
   `floor-layout.js` defines them). `placeRooms(` has a second legitimate caller, `floor/door-view.js` L97 — the
   door view composes geometry and assigns no desk, which is plan-12987's own decision; whether it should read
   `composeFloor()`'s geometry half instead is a smell noted for that module's next change, not this card's scope.
   The door-view leg of `TheElevatorIsAControlOnTheDrawingTest` is `FloorData`'s control, and gains
   `roomTilesets()`: one room's tilesets loaded, no room entered. **`painter-probe.mjs` gains the `omit` leg**: a
   painter with no `omit` draws, for a staffed desk, a `.plate` rect and a `text.role-name` (the control: the
   shipped drawing); one with `omit = {'nameplate'}` draws neither and draws every other member's elements — the
   chip's rect and text, the facts, the monitor, the character — in the same count at the same rects. **Plants**: an
   `omit` that reads an element's `kind` instead of its `member` (the plate rect, kind `plate`, survives → the
   `.plate` assertion reds); an `omit` that also skips the chip (the chip's assertion reds).
2. **AT-D3-37 — the building view stacks the floors** (`FLOOR.md` § 11, gated at Appendix B row 47):
   a **new run `building_stack` in `fx-camera.json`** (review round 2's M4a: `building_room` has `rooms: []`, no map
   endpoint and heartbeats only, so it could drive neither the `room.map` clause nor the failing-map clause): the
   `building_room` layout (`aimla` open, `zeta` *the annex*, `layout_version` 3) plus a third floor `theta`;
   `fx-snapshot-4`'s seats; `/api/building/rooms/aimla/map` answering TWICE as `camera_floor`'s does — the shipped
   default (`@json:resources/floor/default.tmj`, `map_version: null`) then an authored body at `map_version` 4;
   `/api/building/rooms/zeta/map` an authored body once; `/api/building/rooms/theta/map` **503**
   (`camera_nothing_measurable`'s shape); `/art/floor/tiles/floor-plane.tsx` the shipped tileset; messages: a
   heartbeat, a `seat.delta`, then `camera_floor`'s `room.map` for `aimla` at `map_version` 4 (`revision_id` 881),
   then `end` — entered at `/building` through the probe's `building` entry, **which injects one rejected theme**
   for the floor drawn in it as the `floor` entry does (`fleet-client-probe.mjs` L669: `screen.assetsFailed([…
   rejected.map((name) => \`theme:${name}\`)])`) — review round 3's minor 7: the node harness has no painter, so the
   `failed` routing needs a subject the harness can plant, and theta's line is a MAP failure (`mapFailureNotice()`),
   not a failed asset. GREEN: one `{ row, scene, labels }` per `plates()` row, in `plates()` order; each scene's
   extent is the extent the floor screen computes for that floor at the same fixture (the probe runs `floor` for
   each key and compares — the slab shows what the floor page shows); the stack's slabs are `n` with storeys
   `n − 1 − level`; **one picture rule**: a slab carries a picture exactly when its scene's extent is non-null —
   `theta`, whose map request failed, carries the floor page's `mapFailureNotice()` line and no picture (no map was
   ever held, so no extent; § 9 F16: a map held before a failure keeps its extent, so such a floor would still show);
   **theta's map is requested once across the run** — the fixture scripts one 503, the probe's unscripted-request
   list (L993) is empty, and the `room.map` for `aimla` loads aimla's tilesets and enters no room (minor 4); the
   `room.map` for `aimla` re-fetches its map and the next render's scene for `aimla` is built from the authored
   document (its extent differs from the default's — the assertion); the scene input's member set equals the floor
   screen's (`SCENE_INPUT_KEYS`); **the floor drawn in the rejected theme has `scene.art_failed` true and its notices
   name the theme** in the floor page's words; **`labels`**: per floor one label per desk the scene holds — its
   `text` equal to that desk's nameplate element's text, its `at` that element's rect centre, its `href`
   `/floor/{key}/{seat_id}` — and none for an intern; **the frame holds no count, no camera and no ride member —
   scoped** (review round 3, minor 6): the frame's `row` is a `plates()` row and carries `held`, `summary`,
   `staffing`, `chips` and `floor_totals` (`lobby-model.js`'s `plate()`), and the screen returns `strip`, whose
   `totals` the page's header prints as the floor page prints them (`floor/main.js` L284), so what the GREEN reads
   is that `stackModel()` reads the row's key, `level`, `name` and `href` and the scene's extent and nothing else of
   the row, that no slab, label or notice carries a figure, and that the frame has no `camera` and no `ride` key; the
   animation log holds the fixture's own rows and nothing a slab or a texture wrote — the screen reaches the log
   only through its one `DeskFloor`, held over the page's import graph from `building/main.js` **by an explicit
   bound in the form the lobby's test uses** (`TheBuildingCameraMovesTheViewerAndNeverTheFleetTest` `PATH` L71–75
   and `RIDE_IMPORT` L93: an importer may load the set's module only if listed, and a constants-only importer is
   held to its EXACT import line). Review round 2's M4b: the first draft's *reaches the set only through
   `live-page.js` and the desk floor* was false of the design — `floor/scene.js` L32, which every slab's scene goes
   through, imports from `wire/animation-set.js`, and so does one module on the desk floor's graph. The building
   page's bound, each read from the shipped tree on 2026-11-09: constructors — `wire/live-page.js` →
   `wire/animation-log.js`, `desk/desk-floor.js` → `wire/animation-set.js` (`new AnimationSet`, L87, the one
   constructor on this graph); constants-only importers, each to its one import line — `floor/scene.js`:
   `import { ANIMATION_SET, LOOP_FPS, SNACK_HELD, loops } from '../wire/animation-set.js';` (a frozen table, two
   constants and the pure `loops()` reader), `desk/desk-render.js`:
   `import { SNACK_HELD, heldRendering } from '../wire/animation-set.js';` (a constant and the pure held-render
   reader). Any other importer of the set's module on the graph, or either of these two importing another name
   (`AnimationSet` beside its constants is the lobby test's own RED, L310–312), reds. ⚠ The review's list named
   `desk-poses.js` and `state-words.js` as importers; both only MENTION the module in a comment (`grep -n
   "animation-set" desk/desk-poses.js desk/state-words.js` prints header prose and no `import`), so they are not on
   the bound. The module's other importers — `floor/in-flight.js` (`emptyChair`, `staffed`; its one importer is
   `floor-screen.js` L98, and `scene.js` names it only in comments), `floor/floor-screen.js` (`LOOP_FPS`),
   `lobby/lobby-screen.js` (the lobby's constructor) and `wire/ride.js` — are not on this page's graph; the test's
   `ModuleSpecifiers` walk from `building/main.js` is what establishes that, and the bound is written to that walk.
   REDs, each planted in the shipped tree and watched red: *the recounted floor* — `stackModel()` reading
   `row.held` or `row.staffing` to size or label a slab; *the swapped order* — `storey = level`; *the frame the
   floor page would not draw* — a member dropped from the building frame that `buildScene()` reads, or an input
   member (`hallway`, `failed`) dropped from `sceneInput()`'s docs (the member-set equality over both); *the ride on
   the roof* — a `Ride` constructed in the building screen; *the journal drained twice* — the harness's `building`
   entry wraps `client.takeWire` and COUNTS calls per render (its `desk_floor` entry already wraps it to observe
   the drain, `fleet-client-probe.mjs` L586–589) and fails a render that drained more than once; the plant that
   proves it fires is `this.#desks.render()` — which drains for itself, `desk-floor.js` L134 — in place of
   `renderWith(journal)`: the desk floor then reads a journal the screen already emptied, applies nothing, and the
   desks GREEN reds with it — the counter is the assertion that NAMES the cause (review round 2, minor 9). ⚠ The
   probe's *two renderers, one journal* rule (L519–520) reads SCENARIO keys, not calls, and would not catch this;
   the counter is what does; *the map that never refreshes* — `applyRoomMap()` not called on a `room.map` entry →
   the delivered map's scene is the old one; *the near limit measured to the target* — `dollyToward()` clamping the
   camera's distance to the OLD target, the stack's centre, instead of the hit's depth → aimed at the lowest floor
   the camera stops with the hit's depth under `nearLimit()`, or its view ray on no slab — round 3's M1, as a plant;
   *the slab crossed* — `constrainView()` testing the end point only → a zoom-out step from under the top slab
   tunnels it and the swept assertion reds; *the label through the floor above* — `labelVisible()` without its
   occlusion clause → a lower floor's label shows from a camera over the floor above; *the nameplate painted
   twice* — the building page's painter without `omit` → the picture holds a `text.role-name` (the browser leg's
   assertion); *the failure routed nowhere* — `createPainter({ failed: () => {} })` → the rejected theme's floor has
   `art_failed` false (the browser leg's plant; the node GREEN's subject is the harness's injection).
3. **The stack's arithmetic, and the camera's steps** — `stack-model-probe.mjs`: `storeySpacing()` over fixtures of
   one, two, five and ten slabs of the shipped default's depth and of unequal depths, on 1,280 × 600, 1,280 × 800
   and 390 × 700 surfaces, asserting convergence within the bound (§ 3.1: `|Δs| < 0.01` within 8 iterations) and
   `s ≥ g(s)` strictly; and **the occlusion test, cast from the computed camera**: take
   `cameraLimits(stackModel(…), surface).home` — the position `fitDistance()` and `HOME_POLAR` give — and for every
   slab below the top, the ground included, cast the ray from that position to each of the slab's three back-edge
   points (both corners and the middle); where the ray crosses the vertical plane of the slab above's front edge,
   its height must be below that slab's underside (`segmentClears()`, § 3.3's primitive). ⚠ The first draft's test
   checked `s · tan(θ) ≥ D`, which the formula satisfies by construction and so could fail only on the `BAND_H`
   control (review round 2's M1); this one reads the camera, not the formula. **Plants, each seen red**:
   `storeySpacing()` replaced by the parallel-ray formula `max(d) · cot(50°) + SLAB_T` → on the five-floor fixture
   the ground's back-edge rays cross the plane above about 2.8 units ABOVE slab 0's underside and slab 0's about
   1.4 above slab 1's (`occl4.py`); the last iterate returned without the tolerance → `s ≥ g(s)` fails by about
   +0.0005 on the five-floor fixture (round 3's minor 2, planted rather than tolerated); the `BAND_H` storey → every
   lower slab fails; `plates()`'s rows alone as the population → the one-slab fixture's storey collapses to `SLAB_T`
   and the ground's back edge fails; a stack whose deepest slab is not the one the spacing was derived from → that
   slab fails. **The camera's steps** (§ 3.3), each pure, on the five-slab and the two-slab fixtures: **`aim()`** —
   a ray from home through the top slab's centre names the top slab and its top face; through the gap between two
   slabs, `null`; from under a slab through its underside, that face. **`dollyToward()` from home toward the top
   slab's centre and toward the lowest floor's centre, notch by notch until it stops**: at every step the camera is
   outside every slab box and `segmentClears()` holds for its path; at the stop the hit's depth equals `nearLimit()`
   (3.2 units on the 600 px surface, 4.2 on 800), the target is the hit, and the camera is above the hit's slab and
   below the slab above's underside (2.4 above and 8.2 below on five floors, `occl4.py`); the near limit is the SAME
   figure on the two-slab and the five-slab stacks for the same surface, the closest scale the same 2.296.
   **Plants**: the near limit applied to the camera-to-target distance with the target left at the stack's centre →
   on the lowest floor the hit's depth at the stop is under the limit, or the view ray meets no slab (round 3's M1);
   `zoomToCursor`'s rule — the camera moved along the ray by the target-distance delta, the target re-placed
   straight ahead — → the top slab crossed (the camera below its top plane, `segmentClears()` false on the last
   step); `constrainView()` testing the end point alone → a zoom-out step begun 2 units under the top slab's
   underside at the overhead polar, 9 units long, ends above the slab with no violation seen; `closestScale()`
   without its cap term → 4 on the shipped default, and `textureSize()` has to shrink a 7,136 px picture.
   **`constrainView()`**: a pan that would carry the target below `y = 0` answers `prev` (round 3's minor 3, as an
   arm); one that would carry it below the top of the slab it is over answers `prev`; an orbit from the close-up on
   a lower floor toward the overhead polar at a distance over the gap answers `prev` at the underside, and the same
   orbit at the near limit passes. **`panBy()`**: ArrowLeft's drag moves the view left — under the same camera the
   target's projection moves right by `PAN_STEP_PX` — through `constrainView()`. **`labelVisible()`** on the
   five-slab fixture: at the close-up on the lowest floor (the `dollyToward()` end state) a label at that floor's
   centre with threshold 94/440 is visible; from the same camera a label at the ground slab's centre straight below
   is hidden — `segmentClears()` fails on the floor's slab; **at that close-up every label on the focused floor —
   its centre, its four corners' desks and a desk on its strip — is visible** (the B property, § 3.4); at home every
   desk label is hidden by scale (about 0.1 against 0.21) and every floor entry is visible; a label whose threshold
   is 200/440 is hidden at a scale of 0.3 where a 94/440 one shows. **Plants**: the occlusion clause dropped → the
   ground's label shows through the floor; the scale clause dropped → every desk label shows at home; the anchor not
   lifted off its slab's top → every label on the focused floor is hidden by its own slab. **`worldOf()`**: the drawing's bottom-left maps to the
   slab's front-left corner and its top-right to the back-right (the alignment rule, § 3.1). `fitDistance()` fits
   the bounding sphere for a wide and a tall surface; `cameraLimits()`'s polar range excludes under-ground;
   `isClick()` refuses a 4 px move and accepts 3 (`< DRAG_SLOP_PX`); `textureSize()` holds 1:1 under the cap and
   scales proportionally over it (controls: a 1,784 × 864 extent at scale 1 is itself; at scale 2.296 it is
   4,096 × 1,984; at cap 1,024 and scale 1 it is 1,024 × 496). `tokenNames()` returns every token the sheet test
   holds `STYLE` to; `pictureSvg()` writes each token once on the root and the sizes in place of the percentages
   (plant: a token left out → the browser leg's chip rect reads black, which is the leg that can see it).
4. **The page wiring** — `BuildingPageWiringTest`: the ids both ways, the `three` import-map entry, the log handed
   to the screen only, no `fetch` or `EventSource` outside `livePage()`, `cameraKeys()` and `offerKeys()` called
   with the canvas and the camera row's buttons, `listenToKeyEvents(` nowhere, `createPainter(` called with `omit`
   in `floor-picture.js` and without it in `floor/main.js`.
5. **The browser leg** — `tools/design/building-view.browser.mjs`, in `design-doc-verifiers.yml`'s `browser-smoke`
   job after the `starts` row, on the pinned shell: the served `/building` (`render-floor-view.php building`) fed
   the `floor_rides` run as `floor-ride.browser.mjs` feeds the lobby; GREEN: no uncaught exception, the stream
   asked for, `#building-canvas` holds a WebGL 2 context — **`getContext('2d')` on it answers `null`** (a canvas
   holding a context of one kind refuses another; the first draft's *`getContext('webgl2')` returns the same
   context* created one on a bare canvas and compared it with itself — review round 3, minor 8), one `.floor-scene`
   SVG per composed floor under `#building-pictures` with **no `text.role-name` and no `.plate` in it**, one
   `canvas[data-floor]` per picture beside it — **the page's own rasters, read where the page keeps them**: a desk's
   chip rect in the top floor's canvas is not the background, by `getImageData()` on the page's canvas and not on a
   reconstruction (minor 8's first half: the first draft rasterised the hidden host's SVG again through the tool's
   own `pictureSvg()` import, so the page's token resolution and its Blob → `<img>` → canvas path were never read);
   each list entry `data-home="stage"`; at home every desk label `hidden` and every floor entry shown; **wheel
   events dispatched over the top slab's projected centre, repeated, bring the top floor's desk labels out of
   `hidden`** — the aimed zoom seen on the real surface; the strip's art line names the fixture's rejected theme
   (minor 7's browser half). `--selftest` plants: the token inlining dropped (`style` attribute stripped) → the chip
   rect reads black; a floor removed from the layout → one host fewer; `--disable-3d-apis` on the shell → the § 7
   statement and no canvas; `createPainter({ failed: () => {} })` in `floor-picture.js` → the art line is empty;
   `enableZoom` left on with `zoomToCursor` → after the wheels the top floor's labels stay `hidden` (the camera is
   under the slab, the anchors behind it). It prints each floor's paint and rasterise time at scale 1 and the
   focused floor's at 2.296, and the page's `performance.memory` where the shell exposes it. ⚠ **What it does not
   hold**: that a real GPU, Safari or Firefox rasterise the SVG as SwiftShader does, that a pinch on a real touch
   screen zooms, or that OrbitControls feels right under a hand — named, not claimed; `--out building.png` saves a
   picture for the eye, as `floor-fixture.browser.mjs` does.

## 9. The slice plan, for the coder

Each slice is one PR to `dev`, titled `card#13033 [no-close]: …` until the last, which `(closes card#13033)`
only when the operator has read the sandbox (§ 13 ruling 5's reading); each carries
`docs/changelog.d/13033-<slug>.md` and the `FLOOR.md` landing marker for its half of row 47.

1. **The hoists, and the painter's two options** (`floor-compose.js` with `composeFloor()`, `artInputs()`,
   `roomMaps()`, `sceneInput()`; `floor-data.js` with `roomTilesets()`; the painter's `host` and `omit`, with
   `painter-probe.mjs`'s `omit` leg), with test 1 and no behaviour change on the floor page; the import-graph bounds
   of the lobby (`TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`) unchanged.
2. **Three.js vendored and pinned**: the three files and `LICENSE`, the vendor headers, `vendor-pin-check.sh`'s
   leader column with its `--selftest` seen red on a planted body edit, `BARE_SPECIFIERS` and the import-map
   assertion, `ModuleSpecifiers::judge()`, `docs/ATTRIBUTION.md`'s section, `three` in `package.json`'s
   devDependencies with the header-to-lockfile version check and `/building` in
   `EveryModuleAPageLoadsIsVersionedTest::pages()`; the transfer-size measurement and the `php-tests` wall-time
   delta (§ 5's decision rule) in the PR body.
3. **The model and the screen**: `stack-model.js` (the storey with the ground in its population, the camera's
   limits, the aimed dolly, the guard, the labels' visibility, the closest scale), `building-screen.js` (with
   `deskLabels()` and the one-room tileset step after a `room.map`), the probe's `building` entry (with its rejected
   theme), the `building_stack` run in `fx-camera.json`, tests 2–3, `FLOOR.md`'s AT-D3-37 red list confirmed against
   the plants.
4. **The page**: route, view, `main.js`, `floor-picture.js` (the focused scale), `stack-paint.js`,
   `zoom-gestures.js`, `labels.js`, the keys through `cameraKeys()` and the wire test's rename, the stylesheet (the
   stage, the labels), the entry links, test 4, the browser leg with its plants, `render-floor-view.php building`;
   the sandbox deployed and a screenshot on the card for the operator's reading — this is the reading stage 2 waits
   on, and where the blocked zoom-out (§ 3.3) and the label's drag edge (§ 3.4) are read.

## 10. What could falsify it, and what is not defended

- **A drawing that references the page.** The pipeline rests on the painter's SVG being self-contained but for
  the palette. A future painter change that adds an `<image href="/art/…">` (a URL, not a data URI), a
  `<use href="#…">` into the page, or a web font would draw blank in the texture and nowhere else. The browser
  leg's pixel read is the check that catches it; `painter-probe.mjs` could hold *every `<image>` href is a data
  URI* cheaply and should, in slice 4.
- **A token the sheet keys on media.** `tokenNames()` is resolved once; if `mezzanine.css` gains a
  `prefers-color-scheme` block, the textures keep the scheme at load. Named, not defended: no such block exists
  (grep `prefers-color-scheme` over the sheet at build), and the fix then is a re-resolve on the media query's
  `change`.
- **Texture memory — UNMEASURED.** Per floor, three copies exist at the moment of upload: the canvas (w × h × 4 B),
  the decoded `<img>` bitmap (the same), and the GPU texture with its mipmaps (× 4/3); **the canvas stays** for the
  texture's life (§ 3.2 step 5 — it is the `CanvasTexture`'s `image` and the restore path's source), the bitmap goes.
  At the cap that is 64 + 64 + 85 ≈ 213 MB per floor in flight and ≈ 149 MB resident; at the shipped default's
  1,784 × 864 it is 6.2 + 6.2 + 8.2 ≈ 21 MB in flight and ≈ 14 MB resident, and the focused floor at 4,096 × 1,984
  (§ 3.2 step 8) 32.5 + 32.5 + 43.3 ≈ 108 MB in flight and ≈ 76 MB resident. Ten floors at the cap would hold ≈ 1.5 GB
  resident — a hazard with no live precondition on this install (§ 1's five floors, none wider than the default's
  1,784 px). Not defended beyond the cap; the browser leg reads `performance.memory` where available, and a per-page
  texture budget is a stage-2 question when floors become scenes.
- **Paint and rasterisation time.** Every render paints N floors in full and — since a staffed floor's text carries
  ages (§ 3.2) — re-rasterises nearly every one, awaited inside the render: a delta storm could request several
  renders a second. `requestRender()`'s coalescing (`live-page.js`) bounds renders to one at a time; nothing else
  does until measured — the browser leg prints the per-floor paint and rasterise times, and a figure over 100 ms per
  floor on the pinned shell is the trigger to add a per-floor minimum interval (a seat ruling then).
- **The stack moves under the viewer.** `s` is re-derived when a slab's extent changes (§ 3.1) — a seat overflowing
  onto the strip makes its floor's slab deeper and can move every storey — so the stack re-lays out while a viewer
  looks at it. § 3.3's arrival rule keeps a touched camera where it is; what is not defended is the jump itself,
  which is the floor page's own behaviour when its extent grows (*Fit the floor* re-frames), read on the sandbox.
- **The `three` bare specifier and the import map's placement.** The map must precede every module script
  (`layouts/app.blade.php` L123's ⛔); the building view inherits it. A browser without import-map support
  cannot resolve `three` — nor any module the pages load today, since every import already goes through the map
  (card#12158), so this adds no new population.
- **The SVG-as-image rasteriser's limits.** Chromium measured; Safari and Firefox not. Known differences in SVG
  image rendering between engines (filters, `color-mix` support before 2023 releases) are named here and read on
  the sandbox by eye at slice 4; nothing in this plan defends them.
- **The ceiling under a zoom-out** (§ 3.3): a camera orbited nearly overhead, low over a floor, cannot zoom out
  through the slab above; it stops at the underside (after about 3.3 × the near limit on five floors, `occl4.py`).
  Not defended beyond *Fit the building* and the hint line; read on the sandbox; the close-up mode (§ 2.1) is the
  recorded fallback, a seat ruling (§ 12 item 13).
- **The focused picture's cost — UNMEASURED.** One 4,096 × 1,984 raster per focus change, and per render of the
  focused floor whose SVG changed (nearly every render while its desks are staffed, as the ages paragraph of § 3.2
  says); the paint-time trigger above covers it, and the browser leg prints the focused floor's time at its scale.
- **Label density.** Two desks' labels cannot overlap from the label threshold in, because two furniture boxes never
  do on a valid map; a map that fails that (§ 9 F21 — intersecting objects, which the console refuses at the write)
  could overlap two labels, the same reachability as the drawing's own overlap, recorded in § 10.4 item 3 with no
  fix. Not defended.
- **A drag begun on a label** is the label's, not the orbit's (§ 3.4); the lobby's board boxes over its camera layer
  share the edge. Not defended.
- **The label's size is a px size** — the floor page's nameplate's (`FONT_NAME`), not the page's `rem`: a viewer's
  text-size setting scales the lobby's directory faces (§ 4.1 decision 37) and not these, as it does not scale the
  floor page's nameplate either (SVG px under its camera). A seat ruling (§ 12 item 15), reversible to `rem`.
- **Deliberately not defended:** a floor count so large the stack exceeds `maxTargetRadius`'s usefulness (every
  limit scales with the bounds); two viewers' cameras disagreeing (navigation is never state; nothing is shared);
  the lobby slab carrying the lobby room's picture (a perspective drawing is not a plan — ruled out, not deferred).

**Cross-boundary conditions, by name (canon #7).** The one hop is **upstream Three.js → this repository**:
upstream DECLARES its API and licence in the package; this repository CHECKS that its copy is the declared bytes
(`vendor-pin-check.sh`'s body sha, comparable to jsDelivr's published digest — § 5); what neither end can verify
locally is upstream's **behaviour in the viewer's browser** (WebGL 2 presence, SVG rasterisation, OrbitControls'
pointer handling on a given OS), which the browser leg exercises on one pinned engine and names the rest of as
unverified. The other seam — the floor page's drawing → the building page's texture — is intra-repo: the shared
`composeFloor()` and the one painter are what make the two ends one code path, and test 2's extent-equality is
the check that the slab shows the floor the floor page shows.

## 11. Stage 2 — the floor in 3D, at outline depth

Stage 2 is a design of its own, to be written after the operator has read stage 1 on the sandbox (§ 13 ruling 5);
it stays PROPOSED in `FLOOR.md` (§ 14 item 49) until then, and every sentence below is conditional. What it
would do, stated now so stage 1 builds nothing that stage 2 must undo:

- **What it replaces.** The floor page's drawing (`painter.js`'s SVG under `wire/camera.js`) would become a Three.js
  scene: the floor plane would be the floor page's PLANE and SCENERY drawing as a texture (the plan — item 1 of § 10.4's
  projection bullet: *the floor is seen from above … shows no face and no thickness*, which is exactly a texture
  on a horizontal plane); the back-wall band would become a vertical plane at the back, its drawing as texture; every
  `wall` cell would become a box of `ROOM_H` cm on a footprint edge and `PARTITION_H` cm inside a room (`floor/door-view.js`
  L40–50: `SCALE`, `ROOM_H`, `PARTITION_H` — the one 3-D model of a floor the repository already derives, for the cab's
  door view; stage 2 would render **that model** with a real camera instead of the cab's one-point projection, and the
  door view would keep its SVG projection for the cab); desks, furniture, scenery pieces and characters would be **upright
  billboards** — the theme's `scenery()` documents and the character tree's frames as `SpriteMaterial` or
  `PlaneGeometry` quads locked to the vertical axis, standing on their foot line at the position the plan gives them
  (`desk_positions`, `mapTiles()`'s cells), as plan-12987 § 9's seat ruling already stands them in the door view.
  Labels (nameplates, chips, bubbles — § 10.4 item 6's *labels over the scene*) are **stage 1's label layer** (§ 3.4 —
  `worldOf()`, `labelVisible()`, the one docking function), extended from the nameplate to the chip and the bubble:
  stage 1 builds it and stage 2 inherits it, as it inherits the aimed zoom and the slab guard of § 3.3 over one slab.
- **The camera limits that keep billboards from reading as cardboard.** The art is drawn for one viewer *standing
  in front of the room and a little above it* (§ 10.4) — about 30° of elevation. A vertical-axis-locked billboard
  has no edge to catch at any azimuth, so azimuth is free; what betrays it is elevation: below about **15°** the
  floor plane vanishes and billboards behind billboards stack with no ground between them; above about **55°** an
  upright quad foreshortens to a sliver while the desk's drawn top sliver says the eye is lower. So
  `minPolarAngle` would be 35° and `maxPolarAngle` 75° from vertical (elevation 15°–55°), centred on the art's own
  angle; `minDistance` such that a character is never drawn larger than about 2× its rasterised size (or — better,
  since the art is SVG and resolution-independent, which is § 4.5's one required capability — re-rasterise a
  billboard's texture at the size bucket the distance implies, so zoom costs sharpness nowhere). Depth would be real
  (the z-buffer) with alpha-tested cutouts (`alphaTest ≈ 0.5`) so overlapping billboards need no sort — the
  anti-aliased edge the SVG rasteriser gives is lost at the cutout and should be read by eye.
- **What happens to the current camera.** `wire/camera.js` would stay for the lobby's room (a 2-D perspective picture)
  and for `floor-picture.js`'s texture framing; the floor page would stop using it. The gesture rulings of 2026-10-01
  (row 39) and the any-size rule (row 14) would be re-asked for a 3-D surface — the latter would hold unchanged (a canvas
  fills the surface; the list view beside it stays at every size); the former the operator has already answered for
  both stages (§ 13 ruling 1: the 3-D view takes OrbitControls' convention, the 2-D floor keeps its own).
  AT-D3-21's floor half would be re-cut as its building half was for card#12113.
- **The map-projection refusal** (§ 10.3's *a map's projection is the floor's, and it is not isometric*) **would stand and
  get simpler**: a map is a PLAN — the texture on the horizontal plane — and isometric tiles are a *drawing's*
  projection, which a plane texture under a free camera would show as a picture of a projection inside a
  projection. The console would keep refusing by name; the reason it gives would move from *the floor is drawn in the
  oblique* to *the floor is a plan the renderer gives depth to, and a tile may not carry depth of its own*.
- **Routing (A\*) and the walks.** `floor/route.js` plans on the map's cell lattice in scene px and knows nothing of
  how a cell is drawn; a walker's position is a plan point, and stage 2 would place its billboard at `(x, 0, y)` of that
  point each frame. The lattice, `CLEARANCE`, the lanes, the queue spots and every § 6.2 walk row would be unchanged;
  the Y-sort (§ 10.4 item 3) would be replaced by real depth. The one thing to re-derive would be the walker's **size**
  (`walkerSize()`): in 3-D a character's size is its world height, not a px scale.
- **The elevator ride.** The cab is a modal dialog in one-point perspective and would stay (§ 4.5's *One ride, one cab,
  one module*); the floor's elevator would become a doorway in the back-wall plane whose leaves are two quads driven by
  the same `rideLeaves()` / `leafLevel()` figures; the walk's `cab` legs are plan points behind the threshold and
  would render as billboards passing through the doorway. The door view behind the cab's doors would keep its own projection
  (plan-12987) — two renderings of one model, as today the floor and the door view are.
- **Whether full freedom later needs 3-D models.** Within the elevation band above, no: vertical-axis billboards
  of the existing art hold up, and the band is the design. Outside it — a camera at eye level walking among the
  desks, or straight overhead — a billboard is a cutout, and a character seen from behind is still drawn from the
  front. Full freedom therefore needs either **multi-view sprites** (the creature generator is code,
  `resources/characters/creatures.js`, so eight facings per species is a generator extension, not hand-drawn art
  — the furniture would need the same) or **3-D models**, which is new art under § 10's provenance gates. That is
  the finding stage 1 is meant to inform: if the stage-1 slabs, read at the band's angles, look right to the
  operator, stage 2 would proceed on billboards within the band; the reading and its two answers are § 13 ruling 5.

## 12. Seat rulings — reversible by the operator, each one constant or one rule

1. The unit (100 scene px); the storey derived from the home camera's sight lines by the bounded fixed point of
   § 3.1 (the design review's B2 — the coordinator's seat ruling — refined by round 2's M1), **with the ground slab
   in its population and the last iterate plus the tolerance as its result** (revision 4); the slab thickness
   (12 px); the texture cap (4,096 over the 2,048 floor); the camera figures of § 3.3 — § 12's rows.
2. The lobby is a plain ground slab with no picture (the order itself is the operator's ruling 3).
3. A floor with no extent takes the stack's widest footprint and no picture.
4. Textures are stills under `reduce`, re-rasterised per render on change, never on the age tick.
5. Slabs centred on `x`, front edges aligned; the drawing's top toward the back.
6. The list docks to the slabs while the renderer runs and flows below the stage otherwise.
7. Three.js is vendored unminified from the npm package at the pinned version; no minification step.
8. The advisory leg is a lockfile entry held equal to the vendored version (§ 5), not an accepted gap.
9. Every whole-tree walk includes `vendor/` (§ 5; measured 0 findings on r187).
10. The resize/arrival rule: an untouched camera is re-homed, a touched one is clamped and kept; **touched** is any
    viewer act — the library's `start`, the page's zoom reader, the keys and the buttons (§ 3.3).
11. The `mutatedModules()` copy-cost rule: a `php-tests` wall-time delta under 5 % keeps the plain copy; over it,
    `vendor/` is symlinked into each copy (§ 5).
12. **The zoom is the page's**, aimed at the floor under the pointer — the stage's centre for the buttons and the
    keys, the fingers' midpoint for a pinch — and OrbitControls' own zoom is off; a wheel over nothing zooms toward
    the current target and lands nowhere (§ 3.3).
13. **The camera never crosses a slab, and the target never goes under the slab it is over**: `constrainView()`
    answers the previous view — a blocked move stops, no slide — and the blocked zoom-out under a ceiling is
    accepted pending the sandbox reading, the close-up mode (§ 2.1) the recorded fallback (§ 3.3).
14. **The near limit** is the depth at which the floor under the pointer is at the closest scale — the floor page's
    zoom ceiling where the texture cap allows it, the cap's scale otherwise (§ 3.3).
15. **The nameplate is omitted from the slab's picture and carried as a label over the stage** — at the floor page's
    nameplate size in px (not the page's `rem`), docked at the nameplate's centre, a link to the desk's drill-down,
    shown by scale and hidden where another slab is in the way; the chip, the facts, the bubble and the strip stay
    in the picture (§ 3.4). **The focused slab's picture is rasterised at the closest scale**, every other at 1
    (§ 3.2 step 8).
16. The keys and the buttons go through the house wire (`wire/camera-keys.js`), not the library's key listener
    (§ 3.3).

## 13. Operator rulings of 2026-11-09 — decided (card comment 16451; `kbcard comments --task 13033`)

The first revision carried these as questions with recommendations; the operator answered each the same day, and
answered the review loop's product question (item 7) later that day. Items 1–6 are **summarised** and the card's
text is quoted exactly in `FLOOR.md` § 14 item 49; item 7 quotes its record. No open question remains in this file.

1. **Gestures.** The 3-D view uses OrbitControls' convention — left-drag rotates, the wheel zooms, right-drag pans —
   spelled out on the hint line, and the wheel over the canvas never scrolls the page. The 2-D floor keeps the
   gestures of 2026-10-01 (§ 3.3).
2. **Entry.** A **3D** pill in the camera row of both the lobby and the floor page; the lobby stays the home (§ 6).
3. **Storey order.** The lobby is the ground slab; the directory's first plaque is the top storey (§ 3.1).
4. **What a slab shows.** The live floor, desks staffed as held, in their static forms (§ 3.2).
5. **The gate between the stages.** After stage 1 is on the sandbox, the operator reads it at the stage-2 elevation
   band and answers (a) whether billboards are acceptable within 15°–55°, and (b) whether free elevation is wanted.
   Stage 2 is not designed past § 11's outline until then, and stays PROPOSED in `FLOOR.md` § 14 item 49.
6. **The card's date.** Corrected on the card to 2026-10-03; `FLOOR.md`'s record stands.
7. **Zoom and reading — the operator's answer to design review round 3 (2026-11-09; card comments 16478, 16540,
   16542).** Asked whether the 3-D view should zoom to read a desk's name (B) or leave reading to the floor page a
   click opens (A, the recommendation), the operator chose **B** — the registry item's own words, *"Zoom lands on a
   floor, close enough to read its desks"*, with its example, *"you point at floor 3 and scroll in. The camera flies
   toward floor 3 and stops just above it, close enough to read every desk's name in the 3D view"* — and added,
   quoted exactly from the registry's record on the card (comment 16540): *"Try solving the problem from a different
   angle - change the floor view or how text is displayed rather than trying to use the 2D system in the 3D world"*.
   The seat then recorded the operator's further direction on the card (comment 16542, the seat's words, not the
   operator's): *"the designer is free to change the existing rendering system as well to meet its needs"*. Where it
   lands: § 2.1 (the directions weighed), § 3.2 steps 7–8 (the painter's `omit`, the focused scale), § 3.3 (the aimed
   zoom, the slab guard, the near limit), § 3.4 (the label layer); `FLOOR.md` § 14 item 49 carries the same record.

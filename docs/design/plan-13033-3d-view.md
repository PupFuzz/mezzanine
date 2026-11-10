# card#13033 — design and build plan: the building in 3D (stage 1), and the floor in 3D (stage 2, outline)

**What this file is.** The design and the build plan for one card, in the shape `plan-12987-cab-door-view.md`
set: written for the coder who implements stage 1 and the reviewer who attacks it. It owns the *derivation*,
the *sequence* and the *acceptance list as observables*; it owns no rule. Every rule lives in `FLOOR.md`, which
this commit amends where the view touches a ruling: § 1.1's screen table, § 1.2's library row, § 4's screen
count, § 4.1 (the building view is not the cross-section's return; its words are floor names, desk names and
notices), § 4.4's route table, § 4.5's camera bullet (the two poses and the snap), § 10.3's projection bullet, § 10.4's
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
CAMERA's own sight lines by a bounded fixed point, not from parallel rays (M1); the scene INPUT assembly hoisted
beside `composeFloor()` and each painter's failures routed to the building screen (M3); AT-D3-37 rebuilt on a named
fixture run with an explicit import bound (M4); and the minors, including the false claims the review found (the
deploy's `npm ci`, the extent figures, the converter test's exclusion). Its answer to M2 — a near limit from the
floor page's zoom ceiling — was withdrawn with the free camera by revision 5. The copy-cost rule is decided in § 5.

**Revision 4 (2026-11-09) re-derived the text and the close-up as native to 3-D**, on the operator's answer B to
design review round 3 (card comments 16478, 16540, 16542; § 13 ruling 7): the desk names left the slab's picture and
became labels over the stage (§ 3.4), the painter gained its one option (`omit`, § 3.2 step 7), the storey's
population gained the ground slab (§ 3.1), and round 3's eight minors and two hygiene items were folded where they
stood. Its camera — a free flight aimed at the floor under the pointer, with a near limit and a slab guard — was
withdrawn by revision 5.

**Revision 5 (2026-11-09) replaces the free camera with two poses and a snap**, on the operator's answer A to design
review round 4 (card comment 16570, registry item `opq-1794245900-fb26`; § 13 ruling 8): round 4's M1 (the aim
re-read on every notch under the moving view landed on another floor) and M2 (the buttons aimed into the gap between
storeys) were two failures of one idea — a continuous zoom aimed at a point — and dissolve under A rather than being
patched. The view now rests at the **overview** or at one floor's **focus pose**, both computed ahead (§ 3.3); a
wheel, a pinch, a click or **+** glides from the one to the other on the house glide, the camera rounding the
stack's axis so no path crosses a slab (measured over every azimuth, every pose and every interruption); every
gesture during the glide is swallowed; the labels are drawn by state — every desk of the focused floor at rest —
and culled with the sheet's `.visually-hidden`, in the accessibility tree still (round 4's M3); the wheel and the
pinch come through the house gesture wire with a 3-D act set, one snap per gesture by a bucket at one notch (round
4's M4); OrbitControls is not used and not vendored; and each of round 4's eleven minors is folded or dissolved
where it stood (§ 8's plants and § 10 name them: m1, m2, m6, m7, m10 and m11 dissolve with the guard and the
per-label test; m3 is the top-face aim; m4 the wire on the stage; m5 the focus scale in device px; m8 the full
painter call; m9 the re-dock on resize). The one place A's words cannot hold — a stage too narrow for forty labels
— is derived and recorded (§ 3.3, § 10), not papered over.

**Revision 6 (2026-11-09) folds design review round 5, the last round** (the seat's dispatch of that day): the
glide and the mute are specified against the REAL wires (round 5's M1 — the wires call the page's `show` on every
event and the view's own `show()` interrupts a glide, so the gate is the page's `show`, and `wire/camera-view.js`
gains exactly `gliding()`, a `between` option and an `onArrive` option, § 3.3); the wheel and the pinch are muted
until the gesture has been quiet for a gap, not until arrival (minor 4, which also retires the residual revision 5
accepted); the legibility bound is a scale predicate on the pose with the stages it holds and fails on printed, the
label's width the measured one (M2's predicate, minor 3 — the below-bound behaviour then awaited the operator,
registry item `opq-1794250845-e851`, answered in revision 7); the interruption sweep is dropped as unreachable and the
cylinder claim corrected to what is measured, short stages added (minor 5); the wheel's sign in every in-leg (minor
1); the mute's plant on a stream that outlives the glide (minor 2); the stage as the one tab stop and the vertical
arrows advertised nowhere (minor 7); keyboard focus re-applied at arrival (minor 8); and every remaining
OrbitControls, orbit and pan description audited by claim in both documents (minor 6). Right-drag pan at the
overview was then before the operator (`opq-1794250846-d5ff`), answered in revision 7.

**Revision 7 (2026-11-09) records the operator's two answers to round 5's open questions — both A, in one line:
*"do as you recommend for both questions"*** (§ 13 items 9 and 10). Below the legibility bound the close-up is a
PARTIAL view: a pure overlap cull in the floor's reading order over the measured label boxes draws the names that
fit, the rest stay in the accessibility tree, a culled label that takes keyboard focus leads the cull, and a *Read
this floor* link opens the 2-D floor page, which shows every name at any size — no new camera mechanism (§ 3.4,
§ 8 items 3 and 5). And ruling 1 stands amended to *left-drag rotates, wheel zooms*: the pan is dropped everywhere,
right-drag is the browser's default (§ 12 item 12, § 13 item 10).

**Revision 8 (2026-11-09) folds design review round 6, the final round, and records the seat's ruling on how § 8
is written** (§ 12 item 21): three rounds running found tests in this plan's prose with hand-computed counts,
timings and spacings that could not fail, so § 8 now names, for each leg, the PROPERTY, the PLANT and the OBSERVABLE
that must change, and leaves the fixture, the viewport, the stream's length and its timing to be chosen at
implementation by running the leg; every slice's acceptance gains *every plant run and seen red in this PR, the run
recorded in its body*. The fold: the focus-follows rule acts only on `:focus-visible` focus, since a mouse press
focuses a link and would otherwise snap under a drag (M1); the cull's below-bound leg derives a stage on which it
culls (m1, with the *provably empty* claim turned into a stated assumption); the stream legs outlive the glide by
construction, `data-gliding`'s writer is named, and the ungated plant's observable is zero arrivals (m2); the cull's
leader has one source (m3); a turn sets the resting view (m4); `betweenPoses()` has one signature and one return
shape, and `camera-view.js`'s header is a changed file (m5); a line- or page-mode wheel event is a discrete notch
(m6, § 12 item 22); READING ORDER is defined once for the list and the cull (m7); and the leftovers and the three
hygiene items.

**Revision 9 (2026-11-09, with slice 2) moves every run record from the PR body to the card.** `bin/pr-body-lint.py`
holds a change PR's body to the closed section set (Highlights, Upgrade warnings, Bundled, Release artifacts), so a
plant run, a transfer size or a wall-time delta "in the PR body" was a record the CI gate refuses: § 1's re-run
readings, § 5's transfer size and decision rule, § 8's preamble, § 9's preamble and item 2 and § 12 item 21 now say
*on the card*. Slice 2 also found the root `.gitignore`'s unanchored `vendor/` ignoring `server/public/js/vendor/`
and re-includes that one directory (§ 5), names the header's version line `package version` (§ 5), and moves
`/building` in `EveryModuleAPageLoadsIsVersionedTest::pages()` to slice 4, where the route lands (§ 9).




**Design approval.** The operator's request of 2026-11-09, recorded on card#13033: offered a building view, a
floor view, or both, the operator chose *"Both"*, building view first. The card's technical notes fix the library
(Three.js with OrbitControls, plain ES modules, no framework — OrbitControls itself is not used, since under
answer A there is no free camera for it to control, § 3.3; the convention it names stands), the order (building view ships first and leaves the
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
panning, and a click on a floor opening it (the card's words; the operator's answer A re-cut the camera to a turn and a
snap, and the click to a snap, § 13 rulings 1 and 8). Stage 2 — one floor in 3D with desks, furniture and characters standing
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
this plan's to enter. The coder re-runs those three commands before slice 3 and records the readings on the card.

Each floor's **drawing** is `floor/scene.js`'s `buildScene(frame, input)` painted by `floor/painter.js` — the
band (back wall, clock, windows, elevator), each room's plane and scenery, the desks with their characters, labels
and chips, the overflow strip. Its extent is `scene.extent`, the rect *Fit the floor* frames
(`floor-screen.js` L721: `this.#camera = frameOn(this.#camera, scene.extent)`; `fitFloor()` L449).

## 2. The shape, and what was rejected

**Chosen — a fourth screen on a route of its own, `/building`, that composes every floor the way the floor page
composes one, paints each with the floor page's own painter into a hidden host — its nameplates omitted — rasterises
that drawing into a texture, stands the textures on slabs in a Three.js scene, lifts every desk's name into a
label docked over its slab, and snaps its camera between the whole building and a fixed close-up pose per floor at
which every name is in view.** The decision logic — which floors, where each slab stands, how high a storey is,
where each pose is and how the camera gets between two of them, which labels are drawn, which slab a gesture names,
how big a texture may be — is one DOM-free module; Three.js and the DOM are confined to the renderer modules and
the page entry. Three hoists come first, each at its second caller:

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
   `{ maps, hallway, tilesets, layout_version, failed, reduce, effects, previous }` plus the floor screen's in-flight members,
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
| **Draw the stack inside the lobby page**, a toggle between the room and the 3D stack | a second drawing surface under `#lobby-building`, two camera act sets on one element | The lobby's room is the operator's ruling of 2026-10-20 and its camera, gestures, directory docking and ride are built and tested over that one surface (`lobby/main.js`); a second surface there puts two gesture systems on one page and makes every lobby test's population ambiguous. A route of its own costs one view and one wiring test and leaves card#12113's screen exactly as ratified |
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
it turns on HERE and what it costs here. (Round 4 then found the free camera that revision 4 had kept — a zoom
aimed at a point — failing twice more, and the operator chose A, § 13 ruling 8: the camera rests at poses computed
ahead and snaps between them; the table's verdicts on the TEXT stand, and § 3.3 is the camera's.)

| Direction | The mechanism here | What it costs | Verdict |
|---|---|---|---|
| **Names as screen-space labels** — HTML docked over the stage at each nameplate's projected point, at the page's own text size, drawn for the floor the camera is focused on | The repository's docking primitive: the lobby parks its directory boxes in the camera's layer and writes each box's `data-home` (`lobby/directory.js` `dockingEntry()` L138); this plan already docks the floors' list entries to the slabs that way (§ 3.4). `buildScene()` already carries every nameplate as an element with its rect and its fitted text (`desk-layout.js` L1159–1170; `scene.js` L2198–2203 moves it to scene px), so the label is read off the scene model, not re-derived. FLOOR § 10.4 item 6 rules the nameplate and its plate *labels over the scene* that obey no projection — this takes the ruling literally | One `<a>` per desk re-projected on every applied camera and on resize; a culled label is visually hidden, never removed from the tree (§ 3.4) | **Chosen for the names.** Crisp at any distance, drawn exactly where the pose proves them legible, the same layer § 11 already gives stage 2 — so stage 2 inherits it rather than building it |
| **Signed-distance-field text in the scene** (an SDF glyph atlas sampled by a shader on quads lying on the slab) | A glyph atlas — new art under § 10's provenance gates, or a build step this repository has none of (§ 1.2) — plus a shader and one font at one weight; a library (troika-three-text) brings a worker and WebAssembly | The whole atlas pipeline, for text the browser already draws crisply for free in the label layer | Rejected |
| **Desks and agents as 3-D props or billboards, the text out of the slab, the slab carrying layout and theme only** | `floor/door-view.js`'s model stood up with a real camera; each furniture piece and character a quad — § 11's stage 2 | Builds stage 2's scene before the operator has read stage 1, which ruling 5 makes the gate for exactly that; and changes the drawing the operator reads stage 1 to judge | Rejected for stage 1; it is stage 2 |
| **Splitting the painter so both screens share one scene model with a renderer per target** | Already the repository's shape: `buildScene()` is the DOM-free model and `painter.js` its SVG renderer. The 3-D view is the model's SECOND renderer — the picture through the one painter (with one member omitted), the names through the label layer — and nothing is split | A second renderer of the whole scene (planes, scenery, characters) would be a second implementation of the drawing (canon #5) | **Adopted as it stands**: one painter option (`omit`), no parallel renderer |
| **A close-up MODE for a floor** — a state that hides the other slabs and orbits one | A mode flag, a fade, a second camera-limit set | Hiding floors is a visible behaviour nobody chose, and it is not needed: at a floor's focus pose only that floor is in frame by geometry (§ 3.3), and the poses are fixed, so there is nothing to orbit | Rejected; A's fixed pose gives the close-up without a mode |
| **The focused floor's picture at the pose's scale** — the slab the camera is focused on re-rasterised at the scale its pose shows it at in device px, where that is finer than one texel per scene px; every other slab at 1 | `textureSize()` takes a scale; a focus change re-rasterises one slab (§ 3.2 step 8) | One raster per focus change on a high-DPR or large stage (1,920 × 1,000 at DPR 2: 3,520 × 1,705 px), none on the common desktop stage at DPR 1, where the pose's scale is under 1; its paint time UNMEASURED (§ 10) | **Adopted for the picture, not for the text**: the art is SVG drawn at some resolution regardless, and at the focus pose no texel is coarser than a device px up to the cap |
| **Canvas-text sprites in world space** — a small texture per text element, re-rasterised per size bucket, depth-tested | Hundreds of textures per floor (every fact row, chip, bubble line), re-made on each render whose ages changed | The 2-D text rasterised into textures once more, which is the direction the operator ruled out; and layout that holds in scene px buys nothing the label does not | Rejected |

So the slab keeps the floor page's drawing — the live floor, desks staffed as held (ruling 4) — less its nameplates,
and the names come back as labels. The chip, the facts, the bubbles and the strip stay in the picture: at the focus
pose they are drawn no coarser than the screen (the focus scale), at the overview they are the picture, and the facts
are the floor page's (its link opens it). Nothing on the 2-D floor page changes: `omit` defaults empty.

## 3. The derivation — how the stack falls out of what the client holds

### 3.1 Units, storeys and slabs

The scene's unit is the floor's scene px over **100** — one world unit per 100 scene px (§ 12's *Building view
unit*, Chosen: it keeps a 1,784 px floor about 18 units wide, a size the pose and storey figures of §§ 3.1 and 3.3 read
naturally at; any constant works and this one is written once).

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
  `h_k(s)` and `a(s)` are read off the home camera `overviewPose()` computes for a stack of spacing `s`; stop when
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
   no `omit`. Why: at the focus pose the name is the label's (§ 3.4), and a name in the texture under it would be the
   same string twice at two sizes; at the overview a 13 px name is under 2 screen px and noise.
8. **Texture scale — two buckets** (revision 5). `textureScale(slab, view, surface, dpr)`: the **focused** slab — the
   one the view's pose names (§ 3.3) — at `focusScale()`: the scale its pose shows its nearest edge at, in DEVICE px
   per scene px (`screen px per scene px × devicePixelRatio`; round 4's minor 5: a texel per scene px is magnified
   twice over on a retina screen), **at least 1** (a floor the screen shows coarser than its texture is not
   re-rasterised) and **at most the cap over the floor's longer side** (4,096 / 1,784 = 2.296 here); every other
   slab at **1**, each scaled under the cap as step 6 says. On a 1,280 × 600 stage at DPR 1 the pose's nearest
   edge is 0.63 screen px per scene px, so the focused floor's scale is 1 and nothing is re-rasterised; at DPR 2 it
   is 1.26 (2,240 × 1,085); on 1,920 × 1,000 at DPR 2, 1.97 (3,520 × 1,705); on 2,560 × 1,300 at DPR 2 the cap's
   2.296 (4,096 × 1,984) — `snap5.py`. A focus change re-rasterises the newly focused slab at its scale and the
   previously focused one back at 1, and the string compare below keys on the pair (SVG, scale). The largest
   picture holds 32.5 MB of canvas and about 43 MB of texture with mipmaps (§ 10 — 4,096 × 1,984 × 4 B, and
   × 4/3), and its paint time is UNMEASURED: the browser leg prints it, and § 10's 100 ms trigger stands. The claim
   this buys, per configuration: at the focus pose no texel is coarser than a device px, up to the cap; past the
   cap (a stage over about 2,400 px wide at DPR 2) the picture is magnified by the remainder, and the names are
   labels regardless.

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

### 3.3 The camera — two poses, and a snap between them (stage 1)

**There is no free camera.** The operator's answer A (§ 13 ruling 8): the view is either the **overview** — the
whole building, from the home camera, turned by the viewer — or the **focus** on one floor — a fixed close-up pose
for that floor, from which every one of its names is readable on a stage above § 3.4's bound, and the names that
fit below it — and a wheel, a pinch, a click on a slab or the
**+** button moves the camera in **one smooth animation** from the one to the other; a wheel out, **−** or *Fit the
building* brings it back. The poses are computed ahead from the stack and the stage, so the camera never drifts,
never lands on empty space and never passes through a floor; the consequence the operator accepted is that there is
no stopping halfway. Round 4's M1 (the aim re-read on every notch swung the view and landed on another floor) and M2
(the buttons aimed into the gap between storeys) were two failures of a continuous zoom aimed at a point, and both
dissolve: nothing is aimed per notch, and the buttons choose a floor, never a point. Every function below is
`building/stack-model.js`'s, DOM-free, over the view `{ pose, azimuth }` — `pose` is `'overview'` or `{ floor }` —
and the stage's `{ width, height }`; the renderer (`stack-paint.js`) only applies the camera a pose names
(`camera.position.set()`, `camera.lookAt()`), and `snap5.py` (the plan's scratch script for this revision, beside `occl4.py`, re-creatable from this section)
prints every figure.

**The overview pose** — `overviewPose(stack, surface, azimuth)`: the camera at `HOME_POLAR` = **50°** from
vertical, at `fitDistance(bounds, fov, aspect)` from the stack's centre — the distance at which the bounds'
bounding sphere fits the narrower of the surface's two half-angles, **74.1 units** on this install's five floors on
a 1,280 × 600 stage (§ 3.1's stack: 53.1 units high, bounding radius 28.4) — at the viewer's `azimuth` (0 is in
front; the entry framing). **The viewer turns the building and nothing else**: a left-drag or a one-finger drag
changes the azimuth by `ORBIT_RAD_PER_PX` (2π over the stage's width: a drag across the stage is one turn), the
arrow keys by `PAN_STEP_PX` of the same; the tilt and the distance are the pose's. Why not the tilt: the storey is
derived from the home tilt's sight lines (§ 3.1), and a steeper camera hides the lower floors under the slab above
(from 10° off vertical on this install's five floors the ground's back-edge sight line meets the floor above
39.5 units above its underside — the back edge hidden; `snap5.py`'s tilt row), so a free tilt would undo the
storey's whole purpose. ⚠ **The
visibility guarantee is the front's and the back's**: turned to the side, the lower slabs' far halves go under the
slab above as in any stack (at 45° and 90° the ground's far corner is hidden — `snap5.py`), and the pictures are
edge-on there anyway; the overview's turn is for the look of the stack, and the front is where a floor is read.
Why no pan: the whole building is in frame, and a pose that can be panned is not a pose — a transition from a
panned camera would start from a position no derivation covers.

**The focus pose** — `focusPose(stack, i, surface)`: the camera in **front** (azimuth 0) at **`FOCUS_POLAR` =
`HOME_POLAR`**, aimed at floor `i`'s top-face centre, at the least distance at which the face's four corners project
inside the stage inset by `LABEL_MARGIN` — half the widest label across (`LABEL_MAX / 2` = **81** px: the
nameplate's text bound plus the label's padding and border, property (4) below) and a label's height down
(**20** px) — so no label on an edge desk is cut by the frame; found by bisection on the distance (the predicate is monotone).
⚠ **On an axis too short for two margins the inset is a quarter of that axis** (`focusInset()`, slice 3): the first
draft's inset could not be met on a stage under 162 px across or 40 px down at any distance, and the search for it never
ended — a hang on a stage *any browser size* (`FLOOR.md` § 13 row 14) makes reachable; there the face fills the middle
half and the partial view (§ 3.4) holds the names that fit. On a
1,280 × 600 stage that is **14.87 units** from the centre, the camera **9.5 units above** its floor (the storey's
gap is 10.5) and **7.1 units in front** of the stack's front face; on 1,280 × 800, 18.7 units, 12.0 above, 10.0 in
front. Why the front: the floor's drawing is made for the front — its band at the back, its text upright — and from
any other side it is a picture seen from the wrong way. Why the home tilt: one tilt for the whole view, so the snap
is a pure approach (the building does not lean as the camera comes in), and of the tilts measured (35°, 40°, 50°)
it puts the camera farthest in front of the stack (7.1 against 3.7 units at 35°), which is what the transition
proof below rests on; what a steeper tilt would buy is a more even scale across the floor (the far edge at 0.44
against 0.40 screen px per scene px at 35°), not legibility, which holds at all three. Named for the sandbox
reading; one constant.

**Why the focus pose is sound, on any footprint.** (1) *Outside every slab and in front of the stack*: the camera's
horizontal offset from the floor's centre is `d · sin(FOCUS_POLAR)`, and the fit puts the face's near edge inside
the lower half-angle, which needs `d` well past the face's half-depth — 7.1 units of margin on this install (the
probe asserts `z > front + 0` on every fixture; a deeper floor has a larger `d`). (2) *Every sight line to the face
is clear of the slab above*: the camera's sight line to the floor's back edge crosses the plane of the slab above's
front edge at a height `h · d_k / (a + d_k)` under the camera (§ 3.1's inequality with the focus camera's `h` and
`a`), and for a camera at the home tilt that expression RISES toward `d_k · cot(HOME_POLAR)` as the camera
recedes — so the close focus camera's occlusion height is BELOW the far home camera's, which the storey was derived
to exceed: 5.3 units against the 10.5-unit gap here. The storey's proof covers the focus pose for free. (3) *The
face is in frame with its margins* by construction. (4) *Every name is legible — the predicate*: `legible(pose)
:= scale at the face's farthest corner ≥ LABEL_MAX / box.w`, the scale `H / (2 · depth · tan(fov/2) · UNIT)` at the
back corners and `LABEL_MAX` the widest a label can be — the nameplate's text is cut to `PLATE_TEXT_W` (148 px in
the name role, `desk-layout.js`'s `fit()` bound, L1159) plus the label rule's padding and border (6 + 1 px a side,
the sheet's constants, which the page reads once off a label's computed style — round 5's minor 3: the label is a
bold 13 px text in a box, not the plate) — **162 / 440 = 0.368**. From the bound inward no two desks' labels can
overlap, because two furniture boxes never do (§ 10.4 item 3), and the rows cannot either, since a row's pitch on
the screen is `box.h · cos(FOCUS_POLAR) · scale` = 177 × 0.40 = 71 px against a 20 px label. **The bound is
aspect-driven, not a width** (round 5's M2): the farthest corner's scale is **0.398** on 1,280 × 600, 0.438 on
1,280 × 800, 0.640 on 1,920 × 1,000, 0.388 on 1,366 × 500 and 0.372 on 1,100 × 700 — legible; and 0.351 on
1,024 × 700, 0.335 on 1,024 × 600, 0.331 on 960 × 700, 0.340 on 1,200 × 450, 0.361 on 1,600 × 420, 0.284 on
768 × 950 and 0.116 on 390 × 700 — not (`snap5.py`). ⚠ **These figures are the shipped default's footprint's**
(slice 3, measured by `stack-model-probe.mjs`): the bound is the floor's as well as the stage's, since a wider floor is
framed from farther — a 2,400 px floor reads 0.331 on 1,280 × 800 and an authored 1,952 px one fails 1,280 × 600 — so
the probe asserts the predicate on these stages for the default's footprint only, prints it for others, and § 8 item
3's cull leg derives the stages it holds on from the floor it is handed. `FLOOR.md` § 13 row 14 (the operator, card#7341) is *any
browser size*, so the stages below the bound are reachable, and two overlapping `<a>` labels are unreadable and
mis-tappable. **Below the bound the close-up is a PARTIAL view** (§ 13 ruling 9, the operator's answer A): the labels that fit
without overlapping are drawn, in the floor's reading order (§ 3.4's cull), every name stays in the accessibility
tree, and a *Read this floor* link opens the 2-D floor page, which shows every name at any size — no new camera
mechanism. Above the bound the cull is EXPECTED to be empty — § 3.4 states the assumption that rests on, and § 8
item 3 checks it on the fixtures rather than taking it as proven.

**The transition — `betweenPoses(from, to, t, axis)`**, the glide's step, pure, over two SHOWN values `{ view, camera }`
(item 2 below; one signature, one shape — round 6's minor 5): it answers `{ view: to.view, camera }` with the camera
interpolated, and `to` itself at `t ≥ 1`, so the last step applies the exact destination. The target moves in a
straight line, and
the camera moves in **cylindrical coordinates about the stack's axis** — its radius and its height linearly, its
azimuth the shortest way round. The house glide drives it: `wire/camera-view.js`'s `glideTo(from, to, ms)` over
`GLIDE_MS` (850 ms, `wire/camera.js` L75) — a cut under `prefers-reduced-motion` (`glideMs(reduce)`, L315) — with
`cameraView()` given the interpolation as an option (`cameraView(apply, { between })`; today it hard-wires
`camera.js`'s `between()` — its import at L23, the call at L66 — and this is its second caller with a different one; the
2-D pages pass nothing; its header's sentence *the steps between are `camera.js`'s `between()`*, L13, gains *unless the
page gives its own*, which is why the header is among § 4's changed files).
**Why cylindrical**: a straight line from an overview camera at the back azimuth to the front focus pose passes
through the stack (`snap5.py`'s plant: from 180° to floor 1 it crosses the third slab at t = 0.79); on the
cylindrical path the camera's radius never falls under the smaller of its two ends — **11.4 units** at the focus
end on a 1,280 × 600 stage, 8.2 on 1,600 × 420 — while its azimuth converges to the front before the radius falls
under the stack's support in the camera's own direction (9.9 at the diagonal, 4.3 at the front). ⚠ That is a
measured property, not a proof (round 5's minor 5: on short stages the focus radius is under the half-diagonal, and
the path clears by the azimuth's lead, not by the radius) — the sweep is the evidence. Measured over every overview
azimuth at 15° steps to every floor's pose, every pose to every other, and every pose back to every azimuth, on
one, two, five and ten floors and on 1,280 × 600, 1,280 × 800, 1,920 × 1,000, 1,366 × 500 and 1,600 × 420 stages:
**no step crosses a slab**, and the camera's least clearance beyond the stack's support is **2.05 units** (one
floor on 1,600 × 420; 7.1 on five floors at 1,280 × 600) — `snap5.py`'s rows, which § 8 item 3's probe re-runs. No
path starts mid-glide: a snap that interrupts a snap is not a reachable state (below), so the interruption sweep
revision 5 ran defended nothing and is dropped. Between two focus poses the path is the straight vertical line at
the focus radius from the stack's axis, 7.1 units in front of its face on 1,280 × 600. The storey's gap is what
makes the end of the path clear: the camera descends in front of the stack and ends under the slab above's level,
never inside it.

**The snap — `snap(view, intent, stack, surface)`**, pure, the state machine, answering the view to show (the same
view means nothing moves):

| From | wheel in / pinch in over point `p` | click on slab `k` | **+** | wheel out / pinch out / **−** | *Fit* |
|---|---|---|---|---|---|
| overview | `aim(p)` names floor `k` → focus `k`; names the ground or nothing → stay | focus `k` (the ground → stay) | focus the floor whose top-face centre projects nearest the stage's centre (ties: the higher) | stay | overview at azimuth 0 |
| focus `i` | `aim(p)` names floor `k ≠ i` → focus `k`; names `i`, the ground or nothing → stay | focus `k ≠ i`; `i` or the ground → stay | stay | overview at the azimuth it left | overview at azimuth 0 |

`aim(ray, slabs)` intersects the pointer's ray with every slab's **top face** — a hit on an underside or a side is
no hit (round 4's minor 3) — and answers the nearest `{ slab, point }` or `null`; the ground is a slab here and a
non-target in the table. From the overview on this install's five floors the **+** button chooses the **second
floor** (its centre 38 px from the stage's centre; on ten floors the fifth, on one or two the first — `snap5.py`);
from a focus pose it does nothing, and so the keyboard reaches every floor through the labels instead (§ 3.4). At a
focus pose the slab above is out of frame (its front edge is 7.8° above the horizon from the camera, the frame's
top 17.5° below it) and the floor below shows only as a strip at the frame's bottom, beyond the focused floor's
front edge (its centre 60.6° below the horizon against the frame's bottom at 62.5° — `snap5.py`'s frame row), so
a wheel in there is over the focused floor (stay), over that strip (the floor below — a snap the table already
names), or over nothing (stay).
**A snap is one gesture, and nothing moves the camera while it glides — on the real wires.** Both wires hand
every act's answer to the page's `show` on every handled event (`camera-gestures.js` L221–222 the wheel, L255
Safari's pinch, L329 the touch pinch, L358 the drag; `camera-keys.js` L80 and L83 the keys, L88 and L91 the
buttons), and `camera-view.js`'s own `show()` INTERRUPTS a glide in flight (L46–50; its header: *an interrupted
glide stops where it is*) — so an act that merely answered the unchanged view during a glide would, through the
view's `show()`, cut the glide at its origin on every event of the stream (round 5's M1). The mechanism, in three
places, each named:

1. **`wire/camera-view.js` — the interface change, exactly**: `cameraView(apply, { between = cameraBetween,
   onArrive = () => {} } = {})` returns `{ show, glideTo, current, gliding }`. `gliding()` answers `glide !== null`;
   `between` is what the step calls at L66 (`shown = between(from, to, t)`, today `camera.js`'s import at L23);
   `onArrive(to)` is called once, after a glide's last step has applied `to` (L67 at t ≥ 1) and after a cut's
   `show(to)` (L57). The floor and the lobby pass no options and destructure the three names they always did
   (`floor/main.js` L145, `lobby/main.js` L185), so their behaviour is byte for byte today's; `show()`, `glideTo()`'s
   `show(from)` (L54) and the header's rule are unchanged.
2. **The page's `show`** — the function the wires are given — gates first: `if (view.gliding()) return;`, so an
   answer during a glide never reaches `view.show()`. Then a view whose pose equals the resting one (a turn) is
   `view.show(shownOf(next))` **and sets the resting view to `next`** — a turn never arrives, so nothing else would
   record its azimuth and the next glide would start from a stale one (round 6's minor 4) — and a different pose
   culls the desk labels, **writes the stage's `data-gliding="true"`** (this is its one writer) and is
   `view.glideTo(shownOf(resting), shownOf(next), glideMs(reduce))`. `shownOf(view)` = `{ view, camera: poseCamera(view, stack, surface) }`, the
   value `apply` draws; `between` is a closure the page writes over its live stack — `(a, b, t) => betweenPoses(a, b,
   t, live.stack.axis)` — interpolating the two cameras about the stack's axis (the surface is already in them).
   `onArrive(to)` sets the resting view, tells the acts (`acts.arrived(to.view)`), docks and un-culls the labels,
   writes the status line and the stage's `data-view` (`overview` or `focus:<key>`) and `data-gliding="false"` (the
   browser leg's observables), and re-applies the keyboard rule (minor 8, § 3.4) with the leader § 3.4 names. A click's handler asks `view.gliding()`
   first; a resize completes a glide with `view.glideTo(view.current(), shownOf(destination), 0)`, the cut path,
   which arrives.
3. **The acts' mute — until the gesture goes quiet, not until arrival** (round 5's minor 4: a stream that outlives
   the glide, or any stream under reduced motion where the glide is a cut, would snap again from the new pose — on
   a 390 × 700 stage a swipe aimed at the fourth floor lands on the fifth). `building-acts.js` is given a clock
   (`{ now }`, the page's `performance.now`; the probe's stub) and `gliding` (`() => view.gliding()`). On every
   wheel, Ctrl+wheel and pinch step: if `now − last ≥ GESTURE_GAP_MS` (**300** ms, a seat ruling: longer than a
   trackpad stream's gaps between events, shorter than a deliberate second gesture) the gesture is new — `muted`
   false, both buckets `0`; `last = now`; if `gliding()` or `muted`, the resting view is answered and nothing else
   changes; else a PIXEL-mode scroll is added to the bucket and, at a notch, the intent fires, `muted` true, the
   bucket `0`. **A wheel event in line or page mode (`deltaMode` 1 or 2) is a DISCRETE NOTCH and fires alone** —
   a mouse's click of the wheel in Firefox is three lines, under `NOTCH_PX` once normalised, and a bucket the gap
   empties would never let slow clicks add up to a notch (round 6's minor 6; § 12 item 22) — the mute and the gap
   applying to it as to any firing. A two-finger swipe snaps once at its first notch and is muted for the rest of
   its stream and its inertia; the next swipe, after a quiet gap, starts clean. Keys and clicks are one gesture each
   by nature and need no timer; the page's `show` and the click handler drop them while gliding.

During a glide, then: the wires fire, the acts answer the resting view, the page drops it; a `focusin` on a label is
recorded and acted on at arrival (§ 3.4). A snap that interrupts a snap cannot be reached, which is why the sweep
above starts no path mid-glide. Under reduced motion the glide is a cut, `gliding()` is never seen true, and the
mute alone holds the one-snap rule. On arrival the view is the destination, the labels dock (§ 3.4), and the camera
row's status line (`#building-view-status`, a `role="status"` paragraph as every page's statements are) reads
*floor NAME* or *the whole building* — a name, no figure.

**One gesture, one intent — the house reader, not a second one** (round 4's M4). The stage is wired with
`wire/camera-gestures.js`'s `cameraGestures(stage, acts, show)` — the wheel with its `deltaMode` normalisation,
the press, the drag with its slop and capture, the two-finger pinch, Safari's `gesture*` pinch with its
Ctrl+wheel suppression, the click-after-drag veto — and `wire/camera-keys.js`'s `cameraKeys()` / `offerKeys()`,
exactly as the floor and the lobby are, with a **3-D act set** (`building/building-acts.js`, DOM-free) in place of
a 2-D screen's:

- `pan({ deltaX, deltaY, deltaMode }, point)` — a plain wheel. **The wire is extended by one argument**: it hands
  the plain wheel's point beside its deltas (`camera-gestures.js` L213 passes the deltas alone today; a 2-D
  screen's `pan()` ignores the second argument). The act normalises the scroll as `camera.js`'s `zoom()` does
  (L250: pixels, `LINE_PX` lines, the surface's height for pages); a pixel-mode scroll goes into a **bucket** — at
  `+NOTCH_PX` (100 px, L49 — a mouse's one notch) the intent is *out*, at `−NOTCH_PX` *in* over `point`, and the
  bucket resets; short of a notch nothing moves — and a line- or page-mode event is a discrete notch that fires alone
  by its sign (item 3 above). **That is how a trackpad's stream becomes one snap**: a two-finger swipe's many
  small `deltaY`s fill the bucket once, the snap fires, and the acts are muted until the stream — and its inertia —
  has been quiet for `GESTURE_GAP_MS` (above). Always `consumed` (ruling 1: the wheel over the canvas never scrolls
  the page), and the wire prevents it;
- `zoom(point, { deltaY, deltaMode })` — a Ctrl+wheel, which is a Chromium trackpad's pinch: the same bucket, the
  scroll multiplied by `PINCH_GAIN` (10, L63) as the 2-D `zoom()` multiplies it, so a pinch reaches a notch in the
  travel a 2-D pinch zooms one step in;
- `pinch(from, to, factor)` — a touch pinch's or Safari's gesture's step: a second bucket over `log(factor)`,
  fired at `±log(PINCH_SNAP)` (**1.25**: a quarter's spread) — in over `to`, the fingers' midpoint, out otherwise;
  reset on firing and at a new gesture, muted as the wheel is;
- `drag(dx, dy)` — a press that moved, or an arrow key: at the overview, the azimuth by `dx`, **recorded as the acts'
  resting azimuth** (a turn never arrives; `arrived()` overrides — round 6's minor 4); `dy` is nothing; at a focus
  pose, nothing;
- `camera()` — the resting view with `bounds` (the stack's, or `null` before any floor has an extent), so the
  wire's one gate (`framesNothing()`, `camera.js` L114) and `offerKeys()` withhold every gesture, the buttons, the
  hint and the tab stop until the stack is drawn, as on the drawn pages;
- `zoomStep(n)` — `+`/`−` and the two buttons: the table's **+** and **−** columns;
- `arrived(view)` — the page's `onArrive` tells the acts the resting view, which every later aim starts from.

The page's `show(view)` is what turns an act's answer into motion (item 2 above). A click on the stage that the
wire has not vetoed (a press that never moved `DRAG_SLOP_PX`, L115), that is not on a label (`event.target
.closest('a')`) and that arrives while no glide is in flight is the table's *click*: the page unprojects the point,
`aim()` names the slab, and `show(snap(…))`.
**OrbitControls is not used** — under A there is no free camera for it to control: its orbit is an azimuth the
house drag act sets in one line, its pan is gone, its zoom was gone in revision 4, and its pointer and wheel
listeners on the same canvas would race the house wire's for every press. The operator's ruling 1 named the
convention (left-drag turns, the wheel zooms, the wheel never scrolls the page), which stands as A re-cut it; the
library is a mechanism, and § 5 vendors the two files the renderer needs and no addon.

**Resize, and a stack that changes.** The poses are re-derived on every resize and on every change of the stack's
bounds (a slab's extent arriving or changing re-derives `s`, § 3.1): at rest the resting pose is re-applied at once
(the floor page's *a camera at fit stays at fit*, `floor-screen.js` L372, is the same rule for a view that is only
ever at a pose); a glide in flight is **completed at once** to its destination's new pose — never stopped halfway,
which A forbids; and a focused floor the layout no longer lists is the overview, at once. Revision 4's
*touched* flag, and round 4's minor 1 (a clause that could not fail) and minor 2 (a false claim about
OrbitControls' `update()`), dissolve with the guard they belonged to: with every camera a pose or a point on a
proven path, there is no move left to constrain.

**One element, one tab stop** (round 5's minor 7): both wires are given `#building-stage`, and `offerKeys()`
writes the tab stop and the shortcuts on the element it is given (`camera-keys.js` L124–125), so the STAGE is the
page's `role="group"` (§ 3.4) — not the canvas. The wire's `KEY_SHORTCUTS` (L57) advertises `ArrowUp` and
`ArrowDown`, which reach `drag(0, ±PAN_STEP_PX)` and move nothing here; so **`offerKeys()` takes the shortcuts it
advertises as a fourth, optional argument** — `offerKeys(element, buttons, camera, shortcuts = KEY_SHORTCUTS)`,
the 2-D pages passing nothing — and the building page passes `'+ - ArrowLeft ArrowRight'`: what is advertised is
what works.

**The hint line** (`partials/camera-hint.blade.php`, given its text as a slot so the one partial serves three
pages): *drag to turn · scroll, pinch or click a floor to look at it · scroll out, − or Fit for the whole building ·
← → · + −*. The 2-D floor and lobby keep the gestures of 2026-10-01 (card#11045, § 13 row 39), and a 3-D view has
no *edge* at which a wheel could hand itself to the page (row 39's Q3). ⚠ The pinch and Safari's gesture events are
measured with synthetic events only, as the 2-D wire's are (`camera-gestures.js` L103–106); the snap's feel under a
real trackpad is the sandbox reading's.

### 3.4 Names as labels, the list, and the keyboard

**The nameplate leaves the picture and comes back as a label.** `FLOOR.md` § 10.4 item 6 already rules that the
nameplate and its plate are *labels over the scene* that obey no projection; the 3-D view takes that literally. The
scene model is unchanged — `buildScene()` is DOM-free and already carries, per desk, every element with its kind,
member, rect and text (`desk-layout.js` L1156 the plate rect, member `nameplate`; L1159–1170 the text, kind
`nameplate`, role `name`, the string `fit()` to `PLATE_TEXT_W`; `scene.js` L2198–2203 moves each to scene px). The
painter paints every element but the omitted member (§ 3.2 step 7), and the building screen derives the labels from
the same elements: **`deskLabels(scene)`** → per desk `{ key, install_id, seat_id, text, at, href }` — `text` the
nameplate element's text (the floor page's own fitted string: one truncation rule, so a label is never wider than
the plate — § 3.3's legibility bound rests on it), `at` that element's rect centre in scene px, `href`
`/floor/{key}/{seat_id}` (§ 4.4's route table's *drill-down open* row; the floor's key, never its label — § 4.4's
⛔). Interns have no nameplate and no label; a desk on the overflow strip has both. Pure, in `building-screen.js`,
on the frame as `labels` per floor.

**The list is the labels.** `#building-floors`: one `<li>` per `plates()` row — the floor's `<a href>` (its `name`,
`label ?? key`, decided once by `floors()`), its notices as lines, and a `<ul>` of its desks, each an `<a href>` of
the desk's label — plus the ground entry, the page's lobby href (`route('dashboard')`, read off the page as
`floor/main.js` reads its *Back to the lobby* link). The floor page lists its desks under its drawing too
(`FLOOR.md` § 4.4's floor-route paragraph: *below the drawing the page renders the desks as text too*, § 4.5's
list view); here it is the names and their links only, not the list view's rows — the facts are the floor page's.
It is the **accessible surface**: every floor and every desk is a link in it, in document order — floors in
`plates()` order, desks in READING ORDER (defined once below, for the list and the cull alike) — **whatever the
camera shows**, and that order is the tab order; before the
first applied snapshot and layout it flows as one WAITING placeholder (the view carries no layout — there is no
list until the client has fetched one, as on the lobby), and it is the whole page when WebGL is unavailable (§ 7).
**The stack is not drawn until at least one floor's scene has an extent**: every scene is `null` until `loadArt()`
has answered and a map is held, so until then the canvas stays hidden, the camera row hidden, and the list flows;
the first frame with an extent draws the stack at the overview pose (a setting, § 6.5).

While the renderer runs, **one docking function** places every entry on the stage — `data-home="stage|flow"`, the
lobby directory's pattern (`lobby/directory.js`'s `dockingEntry()` L138 is the one writer of each box's
`data-home`, L166, and the sheet keys every dependent style on it, `mezzanine.css` L632–641): a floor entry at the
corner of its slab's top face **nearest the camera** — `labelCorner(slab, azimuth)`, which no slab can hide from a
camera above the stack (the sight line to the nearest corner leaves the footprint at the corner itself, under the
slab above's edge; `snap5.py` holds it at every azimuth) — and a desk entry at `worldOf(slab, label.at)`;
`worldOf()` is the one scene-px-to-world map (the slab's local frame: centred on `x`, its front edge at `+d/2`, the
drawing's top toward `−z`, § 3.1), and the page projects each anchor (`Vector3.project(camera)`) into a
`transform: translate()`, **on every applied camera and on every resize** (round 4's minor 9: a resize moves no
camera but changes every projection). One element is both the label and the link, and no second list is written.

**Which labels are drawn — `labelsShown(view, labelsByFloor, boxes, leader)`** (`view` `null` while a glide is in
flight; `labelsByFloor` each floor's labels by key), pure, from the state and the measured
boxes: a **floor entry** always; a **desk label** when its floor is the focused one, no glide is in flight, **and
the overlap cull keeps it**. **The cull — `cullOverlaps(labels, boxes, leader)`** (§ 13 ruling 9, the operator's
answer A): the focused floor's labels in **READING ORDER — the one order of a floor's desks on this page, for the
list under the stage and for the cull alike (round 6's minor 7): by the nameplate anchor's scene `y`, then `x` —
the drawing's rows top to bottom, left to right.** (`mapDesks()` orders desks by Tiled object id, `floor-layout.js`
L312; this page does not use that order anywhere, so the names shown are always the first of the list that fit.)
Walked once, each label is kept if its screen box intersects no box already kept, culled otherwise; with a
**leader** named it is walked first, so it is always kept. **The leader has one source** (round 6's minor 3):
`document.activeElement` when it is a desk label of the focused floor — read at arrival and on `focusin`; on
`focusout`, `event.relatedTarget` (the element gaining focus) when that is one; otherwise none. Deterministic: the
same boxes and leader give the same set, whatever the camera did before. A label's box is its anchor projected by
the pose's camera (`projectPoint()`, the model's own projection, so the probe needs no browser), centred under a
width the page measured once per text (`measurer()` in `FONT_NAME` plus the label rule's padding and border) and
`LABEL_H` tall. The cull can cull only where two anchors' spacing on the screen is under a label's width —
`min(anchor spacing) × scale < label width` — so on a map whose desks stand farther apart than the box, as the
shipped default's do, the stage on which the cull first culls is NARROWER than the stage on which § 3.3's predicate
first fails (round 6's minor 1); the probe derives both from the fixture's own anchors and widths (§ 8 item 3).
**Above § 3.3's bound the cull is EXPECTED to be empty — an ASSUMPTION, not a proof**: two furniture boxes are
disjoint in scene px and a label is narrower than a box at the predicate's scale; what the argument takes for
granted is perspective, which draws two anchors at different depths at different scales. The probe checks it on
the fixtures (§ 8 item 3) and the browser leg's box assertion checks it on the page; below the bound the close-up
is a partial view — the names that fit, in reading order — and the front rows, at a larger scale, keep more.
A per-label scale or occlusion test (revision 4's `labelVisible()`) has
nothing left to decide and is gone. **A label that is not drawn is CULLED, never hidden** (round 4's M3): it keeps
`data-home="stage"` and its place in the tab order and takes the sheet's existing **`.visually-hidden`** class
(`mezzanine.css` L333–342: absolute, 1 × 1, clipped — in the accessibility tree and focusable; the floor page's
strip heading already uses it) — ⛔ never the `hidden` attribute, which the sheet makes `display: none !important`
(L165–167) and which would take every desk off the page for a screen reader whenever the renderer runs. The pure
functions answer the set; the labels module toggles the class and nothing else.

**The *Read this floor* link** — `#building-read-floor`, an `<a>` in the camera row beside the status line, its
`href` the focused floor's own page, `/floor/{key}` by the key (§ 4.4's route; the floor page lists every desk at
any size), its text *Read this floor*: shown exactly when the view is at a focus pose and `cullOverlaps()` culled
at least one label of that floor — `readFloorLink(view, culled, stack)` (the link the floor's slab carries), pure, `{ hidden, href }` — and `hidden`
otherwise (the attribute: it is a control that does not apply, not a label). So on a small screen the 3-D close-up
is a partial view and full reading is one tap away, on the page that already does it.

**The keyboard reaches every floor through the labels.** Keyboard focus landing on a label of a floor that is not
the focused one — a `focusin` on `#building-floors` **whose `event.target.matches(':focus-visible')`**, which is
keyboard focus and not the focus a mouse press gives a link (round 6's M1: Chromium and Windows Firefox focus an
`<a href>` on `mousedown`, and the gesture wire prevents no `pointerdown`, `camera-gestures.js` L262–295, so without
the predicate a left-drag begun on a floor entry would snap to that floor and the page's gate would then drop the
drag — the amended ruling 1 broken by its own labels) — is the table's *click on slab k* (§ 3.3): the view snaps to that
floor, and on arrival its labels are un-culled — so a Tab through the list brings each floor into view as its
entries are reached, the way a browser scrolls a focused link into view, and a culled desk label a viewer tabs to
is revealed where it stands within the glide. Focus on a label of the focused floor, on the ground's entry or on
the stage moves nothing; a snap moves keyboard focus nowhere (the status line announces the arrival, § 3.3). This
is what makes **+** from a focus pose a no-op affordable (§ 3.3): the keyboard's way to another floor is its label.
**Focus that lands during a glide** (round 5's minor 8: a held Tab can rest focus on a culled, 1 × 1 label of
another floor while the camera is in flight) is not acted on then — the page drops every intent while gliding — and
**at arrival the rule is re-applied**: if `document.activeElement` is a label of a floor other than the one arrived
at, the view snaps on to that floor, so focus rests on an invisible link for at most one glide (WCAG 2.4.7). And on
the focused floor **a culled label that receives focus leads the cull**: `focusin` on it re-runs `cullOverlaps()`
with it as the leader, so it is drawn at its docked point and the labels it would overlap are culled in its place —
focus is never left on an invisible link, and no two drawn labels ever intersect; `focusout` re-runs the cull with
`relatedTarget`, or none, as the leader (the one source, above).

**The label element**: an `<a>` at `FONT_NAME` (13 px bold, `desk-layout.js` L69 — set on the element from the
import, so the sheet restates no font; the floor page's own nameplate size, which `FLOOR.md` § 12's *Nameplate type
size* row owns), on `var(--scene-plate)` with a `var(--scene-plate-edge)` border in `var(--scene-ink)` — the
painter's own plate and text tokens (`painter.js` STYLE L89, L109) — so it is the nameplate in the page's palette.
The gesture wire listens on `#building-stage`, which holds the canvas AND the labels (round 4's minor 4): a wheel
or a pinch over a label is the stage's; a press on a label that moves `DRAG_SLOP_PX` turns the building and the
wire's veto keeps the link from following; a press that does not move is the link's click. No age, no count and no
state is on a label; the building view carries **no figure of its own** (§ 4.1's reasoning for the lobby — a count
a viewer counts by eye — has nothing to disagree with here).

**A click on a slab brings it into view; the links open things.** The canvas's click is the table's *click* (§ 3.3)
— a snap, never a navigation; the floor's entry docked at its slab is the link to `/floor/{key}` (by the key, the
lobby href for the ground), and each desk's label is the link to its drill-down. One gesture, one meaning; the
links are visible at the pose a click reaches, and they are the accessible route as well. (The card's
*clicking a floor opens that floor* is reached by the link on the floor; a seat ruling, § 12, reversible to a
second click opening the focused floor.)

**The stage** (`#building-stage`) is the one element: `role="group"` `aria-label="the building drawing"`, the
element both wires listen on, and the tab stop `offerKeys()` writes `tabindex` and `aria-keyshortcuts` on (§ 3.3),
only while offered, as `#floor-drawing` is on the floor page; the canvas inside it is `aria-hidden="true"`, a
picture the labels and the list describe. The stage is never the only route to a floor or to a desk.

## 4. Modules, and the DOM-free split

The house rule (`lobby/lobby-model.js`'s, restated in every renderer's header): there is no browser on the build
host, so every DECISION lives in a module `node` can drive; only renderers touch the DOM, and only one touches
WebGL. New files under `server/public/js/`:

| Module | DOM / WebGL | Owns | Driven by |
|---|---|---|---|
| `floor/floor-compose.js` (hoist) | none | `composeFloor(floor, building, seatsByRoom)`, `artInputs()`, `roomMaps()`, `sceneInput()`, `SCENE_INPUT_KEYS` — § 2 item 1 | every existing floor test (control); `TheFloorCompositionIsOneForBothScreensTest`: `floor-screen.js` imports it and holds no second `placeRooms()`/`assignSlots()` call site |
| `floor/floor-data.js` (hoist) | none (`fetch` injected) | `FloorData` — § 2 item 2 | `TheElevatorIsAControlOnTheDrawingTest`'s door-view leg (control), the building screen's probe |
| `building/stack-model.js` | none | `stackModel()`, `storeySpacing()`, `fitDistance()`, `overviewPose()`, `focusPose()`, `betweenPoses()`, `snap()`, `aim()`, `nearestToCentre()`, `labelCorner()`, `labelsShown()`, `cullOverlaps()`, `readingOrder()`, `labelBoxes()`, `projectPoint()`, `rayThrough()`, `scaleAt()`, `legibility()`, `readFloorLink()`, `focusScale()`, `textureSize()`, `textureScale()`, `worldOf()`, `segmentClears()`, `poseCamera()`, `liveView()` (§ 12 item 10's dropped floor), `samePose()`, `storeyNeed()` (§ 3.1's `g(s)`, which the probe reads), `focusInset()`, the unit, storey, tilt, margin and snap constants — §§ 3.1, 3.3, 3.4 | `stack-model-probe.mjs` under `TheBuildingViewStacksTheFloorsTest` (AT-D3-37) |
| `building/building-acts.js` | none | the 3-D act set the house wires are given (§ 3.3): `pan()`, `zoom()`, `pinch()`, `drag()`, `zoomStep()`, `camera()`, `arrived()`, each over `snap()` and the two buckets (`wheelBucket()`, `pinchBucket()` — pure over an explicit bucket; the acts hold the buckets, the resting view — set by `drag()` on a turn and by `arrived()` — the mute and the last event's time); a line- or page-mode wheel event a discrete notch; given a clock (`{ now }`) and a `gliding` predicate; `GESTURE_GAP_MS` | `building-acts-probe.mjs` under node with a stub clock, `camera-wire-probe.mjs`'s shape (§ 8 item 3) |
| `building/building-screen.js` | none | `BuildingScreen`: drains the journal once (`LobbyScreen#render()`'s shape, L162–186: `building.layout` applied, the layout fetched once after the first applied snapshot), runs ONE `DeskFloor` over every held seat with `reduce: true`, holds one `FloorData` and prefetches every row on entry and on each layout change; **applies `room.map`** through `Building#applyRoomMap(entry, rendered)` with `rendered` = every room of every `plates()` row (D2 § 8.7's *a client applies a `room.map` only for a room it renders* — this screen renders every room), records the apply's line in the client's record as the floor screen does (`floor-screen.js` L657–667) and then loads **the applied room's tilesets alone** — `FloorData#roomTilesets(installId)`, one row of the press sequence's tileset step (`door-views.js` L74's call for one id) — never `prefetch()` again, which re-enters every room and re-requests every room whose last request failed (`wire/building.js` L234–247: *a room whose last request failed is fetched again whatever it holds*), so a `room.map` for one floor would have re-fetched another floor's failed map, which the fixture scripts one answer for (review round 3, minor 4); **the interface change**: `BuildingScreen#render()` is `applyRoomMap()`'s second screen caller (the floor screen's `#layoutActs()` is the first; the lobby applies none, `lobby-screen.js` L23) and the first whose `rendered` set is the whole building, so a re-saved or recovered map refreshes its slab on the next render; and per `plates()` row: `composeFloor()` → the frame → `buildScene(frame, sceneInput(art, docs))` with `effects: []`, `previous` empty, the in-flight members empty, `maps` from `roomMaps()`, `hallway` the row's, `tilesets` the `FloorData`'s, `failed` this screen's set — fed by every floor's painter through its `failed` callback, so a theme or character a slab could not draw is among that floor's scene's failed assets and on the building's strip, as it is on the floor page's — then `deskLabels(scene, row)` (§ 3.4) → `{ row, frame, scene, labels, notices }`, the frame exactly the members `buildScene()` reads and `notices` the floor page's for that floor; returns `{ floors, stack: stackModel(…), strip, failure, statement, layout }` (slice 3: the first draft's top-level `notices` had no writer and was dropped), and takes the stage (`surface`, `resize()`), which the storey is derived from. No camera (the view is the page's and holds no state the screen reads), no ride, no panel | `fleet-client-probe.mjs` gains a `building` entry beside `floor` and `lobby` (one renderer per scenario, L519's rule), which injects one rejected theme as the `floor` entry does (L669); AT-D3-37 |
| `building/floor-picture.js` | DOM (hidden hosts, canvas, `<img>`); `tokenNames()` and `pictureSvg()` pure | one painter per floor over a hidden `<div>` in `#building-pictures`, each **`createPainter({ host, omit: new Set(['nameplate']), characters, themes, failed, select: () => {}, house, clock })`** — every member `floor/main.js` L411–423 hands its own painter but `ride` (defaulted) and `select` (required at L249, a no-op here: the building view opens no panel), from `artInputs()`'s art; round 4's minor 8: the short form in revision 4 would have thrown at `characters.poseFrames` (`painter.js` L368); the rasterisation of § 3.2 at `textureScale()`'s scale — the focused slab at `focusScale()`, the rest at 1 — each canvas kept in `#building-pictures` with `data-floor` (it is its texture's `image`, and the browser leg reads the page's own pixels there, § 8 item 5); the compare that skips an unchanged (SVG, scale) pair | `picture-probe.mjs` for the two pure functions; the browser leg for the rest |
| `building/stack-paint.js` | **Three.js** | the renderer over `#building-canvas`, the slab meshes (`BoxGeometry`, a `MeshBasicMaterial` per face — the top face the floor's `CanvasTexture`, the others the sheet's slab token), the ground slab, lights (none: unlit materials, the drawings carry their own light), the camera applied from a pose (`position.set()`, `lookAt()`) on every `apply`, `setPixelRatio(devicePixelRatio)`, the pointer-to-world ray (`unproject`) handed to `aim()`, `resize()`, and the projector (`Vector3.project`) the labels module docks with. **No OrbitControls** (§ 3.3) | the browser leg |
| `building/labels.js` | DOM (`#building-floors`, `#building-view-status`) | the list — floors, their notices, their desks — and the one docking function: `data-home="stage|flow"`, each entry's anchor through `labelCorner()` / `worldOf()`, projected by the paint's projector on every applied camera and on resize, culled with `.visually-hidden` by `labelsShown()` over `cullOverlaps()` (the label widths measured once per text, the focused label leading), the label's font from `FONT_NAME`; the *Read this floor* link's `hidden` and `href` by `readFloorLink()`; the `focusin` that — **only when `event.target.matches(':focus-visible')`** — snaps to a label's floor or leads the cull, the leader read from `activeElement` (arrival, `focusin`) or `relatedTarget` (`focusout`); the status line's text on arrival | the browser leg: each entry's `data-home`; the top floor's desk labels `.visually-hidden` at the overview and un-culled after a wheel stream over its slab; a culled label is focusable and not `display: none`; on a below-bound stage the leg derives from the probe's own cull, the link shown and no two drawn boxes intersecting |
| `building/main.js` | DOM entry | `livePage()`, the ids, the screen, the pictures, the paint, `cameraGestures()` and `cameraKeys()` / `offerKeys(…, '+ - ArrowLeft ArrowRight')` on `#building-stage` with the 3-D acts, `cameraView(apply, { between, onArrive })` and the page's `show` that drops every answer while `gliding()`, sets the resting view on a turn, writes `data-gliding="true"` and glides on a pose change (§ 3.3), the slab click, the labels, the WebGL check | `BuildingPageWiringTest` (ids both ways; the log handed to the screen and used nowhere else — `LobbyPageWiringTest`'s shape; `cameraGestures(` and `cameraKeys(` each called once, on the stage; `view.gliding()` read in the page's `show`; `OrbitControls` nowhere) |

Changed files: `floor/floor-screen.js` (calls `composeFloor()` and `sceneInput()`), `floor/main.js` (calls `artInputs()`),
`floor/door-views.js` (holds a `FloorData`), `floor/floor-data.js` (`roomTilesets()`, with the hoist), `floor/painter.js`
(`host` and `omit` options; `painter-probe.mjs` gains the `omit` leg), `wire/camera-gestures.js` (the plain wheel's
point handed to `pan()` beside its deltas, § 3.3; `camera-wire-probe.mjs` holds the 2-D acts unchanged by it),
`wire/camera-view.js` (`cameraView(apply, { between, onArrive })`, a `gliding()` member, and its header's L13 sentence
qualified, § 3.3), `wire/camera-keys.js`
(`offerKeys()`'s optional fourth argument, the shortcuts it advertises),
`server/routes/web.php`, `server/resources/views/building.blade.php` (new), `partials/camera-hint.blade.php` (its
text as a slot), `dashboard.blade.php` and `floor.blade.php` (the entry link, § 6), `public/css/mezzanine.css` (the
stage's size: the viewport height the chrome leaves, `#floor-drawing`'s rule at L851–858; the label rules keyed on
`data-home`), **the rename of `TheCameraWireIsOneForBothPagesTest` to `…ForEveryPageTest`** (§ 3.3), whose file set is
`grep -rl TheCameraWireIsOneForBothPagesTest server docs/design` run at the slice — the test, the tests and wire
headers that name it, `FLOOR.md` and this plan — and which leaves the archived changelogs under `docs/changelog/`
(the same grep over `docs/changelog`) as the history they are;
`tools/design/render-floor-view.php` (a `building` view for the browser tool).
⚠ **The 2-D floor page's drawing is changed by none of these**: `omit` defaults to nothing, `roomTilesets()` is a
new method the floor page does not call, the gesture wire's extra argument reaches a 2-D `pan()` that reads one, the
view's options default to today's interpolation and to no arrival hook, the keys wire's default is today's
shortcuts, and the hoists are behaviour-preserving (§ 8 item 1).

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
`export { … } from './three.core.js'`, so **both** files are vendored. **No addon**:
`examples/jsm/controls/OrbitControls.js` was planned until revision 5 and is not vendored — under A there is no
free camera for it to control (§ 3.3) — and with it goes its `from 'three'` bare specifier, the one reason the house
import map would have needed an entry.

**Where.** `server/public/js/vendor/three/three.module.js`, `three.core.js` and `LICENSE` (the package's, 1,081 B — *Copyright © 2010-2026 three.js authors*, the MIT text). The root `.gitignore`'s unanchored `vendor/` (Composer's and every other install directory) matches this path, so the file re-includes `/server/public/js/vendor/` by name — the one `vendor/` that is source. Under `public/`,
so `PublicAsset::moduleMap()` lists each by its URL and versions it by mtime with no change
(`EveryModuleAPageLoadsIsVersionedTest`'s population is the files on disk). The payload is about 2.05 MB of
unminified JavaScript on the building page and nowhere else (`three.module.js` imports `three.core.js`; no other
page imports either). ⚠ **Whether Apache compresses it is unverified**: `mod_headers` is known absent on the
sandbox (`PublicAsset.php`'s header, 2026-10-20) and `mod_deflate` was not checked; the coder measures
`curl -sI -H 'Accept-Encoding: gzip' <sandbox>/js/vendor/three/three.core.js` once slice 2 is deployed and records
the transfer size on the card, beside slice 2's local `gzip -6` figure. No minification step: the repository builds nothing (§ 1.2), and a minifier would be a build.

**Pin.** Each vendored file gets the house vendor header — the form `bin/pr-body-lint.py` L1–60 carries: upstream
repo, path, the version and the published commit, the upstream file's sha256, the declared local edits (**none**
for both) — and a row in `bin/vendor-pin-check.sh`'s manifest. That script derives a file's BODY as every
line from the first that is neither blank nor a `#` comment (its header, *WHAT IT PINS*); a JavaScript header is
`//` lines, so the manifest row grows a fourth column, the comment leader (`#` for the rows it has, `//` for these),
and `BODY_AWK` takes it — the one primitive extended, not a sibling script. **The selftest's arms take the leader
too**: before slice 2 the RED arm wrote its mutated body line as `# vendor-pin-check selftest: …`, and the GREEN
arm required line 2 to begin `#` and wrote its mutated header line as `# …`; with `//` rows those three sites read
the row's leader, or the GREEN arm would refuse every JavaScript row and the RED arm would plant a
syntax-neutral line the body sha still catches but for the wrong reason. With no local edit the body IS the
upstream file byte for byte, so the body sha256 equals the published file's digest: jsDelivr publishes each as a
base64 SHA-256 — `three.module.js` `EE9WFFiZciCUVWSQ14eq4GF8wvwzgvMd8C1yTqGu40E=`, `three.core.js`
`YT43D3Zjj974OSZkK/JW3JYVaEki3hzh9jqFg+XXhXg=`
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
`bin/vendor-pin-check.sh`'s run (a row-level comparison beside the sha: the header's `package version` line against
`package-lock.json`'s `node_modules/three` version), so the audited version is the served one. Accepting the gap on
the record was considered and rejected: a rendering library that reads no network input is a low-risk surface, but
a declared-and-checked one costs one lockfile entry.

**Whole-tree walks over `public/js` that will reach `public/js/vendor/` — every one INCLUDES it**, each read:
`EveryTimeGoesThroughTheConverterTest` scans every `.js` under `public/js` for its `JS_RULES` (L42–55) and asks each
finding to go through `wire/clock.js`; `TheLobbySkyIsTheFloorsA17Test::sourceDefects()` (L429–455) walks every `.js`
for a phase literal. **Measured 2026-11-09 on the r187 files** (the two vendored, and the addon then planned) (fetched from jsDelivr, comments stripped as
the tests strip them): every `JS_RULES` pattern and `PHASE_LITERAL` find **0** matches — so an exclusion would narrow
a guard with no reachable finding (canon #6), and a first draft's `vendor/` exclusion for the converter test is
withdrawn (review round 2, minor 4); should an upstream version ever match one, the finding is read like any other
and allowed by name in the test's allow-list, which is how it treats its own exceptions. `CoordModuleWiringTest` and
`DrillDownModuleWiringTest` walk the tree for a second `export function clockTime(` and for the drill-down's imports
— a vendored file cannot carry the anchor they look for. `EveryModuleAPageLoadsIsVersionedTest` includes it (above)
and **gains `/building` in `pages()`** with the route, in slice 4 (`pages()` hardcodes the lobby, the floor, the
console and enrolment; without the row the building page's module scripts would go unversioned unchecked — review
round 2, minor 5). Until the page exists the vendored files are in the test's population as files on disk, and every
existing page's import map must version them.
`DrivesAShippedClientModule::mutatedModules()` copies the WHOLE `public/js` tree for every planted control (L235, for
the `../` imports): `grep -rc 'mutatedModules(' server/tests` counts the call sites, and the vendored files add about
2.1 MB to the tree each call copies (`du -sb server/public/js`). **The decision rule** (a seat ruling, § 12): slice 2
records on the card the `php-tests` wall time before and after; a delta **under 5 %** of the baseline keeps the plain copy; over it,
`mutatedModules()` **symlinks** `vendor/` into each copy instead of copying it — nothing is ever planted under
`vendor/`, and a symlink resolves the same relative imports, so no probe learns a second layout. **The one
exclusion that stands**: none. `ModuleSpecifiers` walks reach it only from `building/main.js` (the building page's own graph
test, § 8).

**Attribution.** The asset manifest's gate walks `resources/` alone (`bin/asset-provenance.py` L189
`ASSET_TREES = ("resources",)`; `docs/ATTRIBUTION.md`: *Only `resources/` is measured*), so Three.js is **not** a
manifest row — a row for a path outside the trees is not a shape the gate reads. It is code, attributed as the
repository attributes vendored code: the `LICENSE` file beside it reproduces the notice MIT obliges in *every
copy*, each file's header names the source, and `docs/ATTRIBUTION.md` gains a section **Third-party code under
`server/public/js/vendor/`** outside the manifest markers, naming the two files, the version, the licence and
the pin check — a pointer, since the notice itself lives in the vendored `LICENSE` and a second copy would drift.
The operator's licence allowlist (`CC0-1.0`, `ISC`, `MIT`) covers it; nothing is widened.

**The import map.** Nothing is added: with no addon there is no bare specifier, and the page's own modules import
Three by relative path (`../vendor/three/three.module.js`), which `PublicAsset::moduleMap()`'s URL-keyed walk already
versions; `EveryModuleAPageLoadsIsVersionedTest` reaches them through `/building` in `pages()`, and
`Tests\Feature\Support\ModuleSpecifiers::judge()` follows a relative path as it follows every other. No walk reaches
the vendor directory today (the lobby's bound starts at `lobby/main.js` and `DOOR_VIEW_ROOTS`); the building page's
own graph test (§ 8) is the first.

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
  `#building-zoom-out`, `#building-fit`, and `#building-view-status`, a `role="status"` line that names what the
  camera rests on — *the whole building* or a floor's name — as every page's statements are `role="status"`
  paragraphs, and `#building-read-floor`, the *Read this floor* link, `hidden` until a focus pose culls a name,
  § 3.4); the stage — `#building-canvas` inside `#building-stage`, the labels over it, the hint line's row
  under it (`partials/camera-hint.blade.php` with its text as a slot, § 3.3) — then `#building-floors`, the list; `#building-pictures` (`hidden`), the painters' hosts;
  the event log `<details>`; the sign-in prompt. Every placeholder says it is WAITING (the lobby's ⛔). No cab: the
  building view offers no ride — the way to a floor from here is its link, and a ride from a page that
  draws no elevator would be navigation dressed as a lift.
- **Entry links.** One pill, **3D**, in the lobby's camera `<nav>` (`dashboard.blade.php`) and one in the floor
  page's camera row beside *Whole building* (`floor.blade.php`), each `href` `route('building.view')`; the floor's
  *Whole building* link keeps pointing at the lobby — the lobby stays the home. The operator's ruling 2 of
  2026-11-09.
- **What does not change.** The lobby's room, directory, camera, gestures and ride (card#12113); the floor page
  entire; `wire/camera.js` (the building view runs no 2-D camera on the page — `floor-picture.js` uses `fit()` to
  frame a texture, the acts read its `NOTCH_PX`, `PINCH_GAIN`, `LINE_PX`, `PAN_STEP_PX`, `GLIDE_MS` and
  `glideMs()`, and the wires' gate reads `framesNothing()` (L114) over the acts' `{ bounds }`);
  the three wires each extended and no 2-D caller changed (§ 3.3): `wire/camera-gestures.js` by the plain
  wheel's point as `pan()`'s second argument; `wire/camera-view.js` by an options object (`between`, `onArrive`) and
  a fourth returned member, `gliding()`; `wire/camera-keys.js` by `offerKeys()`'s optional fourth argument, the
  shortcuts it advertises — the floor and the lobby pass none of them and read none; `FLOOR.md` § 4.1's ruling that the lobby draws no desk and the cross-section is gone. The
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

## 8. How the tests pin it — property, plant, observable

**The rule this section is written under — a seat ruling, § 12 item 21.** Three review rounds running found tests
written in this plan's prose with hand-computed counts, timings and spacings that could not fail: a stream too short
to outlive the glide, a stage on which the cull culls nothing, a notch count off by one. Prose arithmetic about a
fixture is the defect class, and no review of prose converges on it. So each leg below names the PROPERTY it asserts,
the PLANT that breaks it, and the OBSERVABLE that must change — and nothing else: the fixture, the viewport, the
stream's length and its timing are chosen at implementation, by running the leg, and are not written here. Where a
leg needs a precondition — a stage on which the cull culls at least one label; a point that `aim()` names as another
floor from the destination pose — the leg DERIVES it from the model's own functions over the fixture and FAILS AS A
CONTROL if it finds none. Each slice's acceptance (§ 9) is: every plant its legs name is run and seen red in that
PR, and the card records the run (a change PR's body holds only the sections `bin/pr-body-lint.py` allows) — canon #9, a check is evidence only once it has been seen to fail. The figures
in §§ 3.1–3.4 are the design's derivations (`occl4.py`, `snap5.py`) and stay; none is a test assertion.

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
   `.plate` assertion reds); an `omit` that also skips the chip (the chip's assertion reds). **The three wire
   extensions are controls too**: `camera-wire-probe.mjs`'s existing legs hold that a 2-D `pan()` still receives
   its deltas first, that `cameraView()` with no option still glides on `camera.js`'s `between()`, and that
   `offerKeys()` with no fourth argument still advertises `KEY_SHORTCUTS`.
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
   screen's (`SCENE_INPUT_KEYS`); **the floor drawn in the rejected theme has `scene.art_failed` true and the theme
   among `scene.failed`**, no other floor's art fails, and the strip's art line is the floor page's words (slice 3:
   the floor page names a failed asset in no notice — its words are the strip's *some art failed to load* — so the
   first draft's *its notices name the theme* was false of the floor page it was to match); **`labels`**: per floor one label per desk the scene holds — its
   `text` equal to that desk's nameplate element's text, its `at` that element's rect centre, its `href`
   `/floor/{key}/{seat_id}` — and none for an intern; **the frame holds no count, no camera and no ride member —
   scoped** (review round 3, minor 6): the frame's `row` is a `plates()` row and carries `held`, `summary`,
   `staffing`, `chips` and `floor_totals` (`lobby-model.js`'s `plate()`), and the screen returns `strip`, whose
   `totals` the page's header prints as the floor page prints them (`floor/main.js` L284), so what the GREEN reads
   is that `stackModel()` reads the row's key, `level`, `name` and `href` and the scene's extent and nothing else of
   the row, that a slab and a label carry their named members and nothing more, that a floor's notices equal the
   floor page's for that floor (slice 3: the first draft's *no notice carries a figure* was false of the floor page's
   own words — theta's line carries `HTTP 503`, a reserved desk's its `id` — so the property is that the view adds
   none), and that the frame has no `camera` and no `ride` key; the
   animation log holds the fixture's own rows — equal to the desk floor's alone over the same run, the
   discriminating control — and nothing a slab or a texture wrote — the screen reaches the log
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
   the delivered map's scene is the old one; *the pose through a floor* — `FOCUS_POLAR` set to 0, a top-down pose
   over the footprint → the path between two floors' poses crosses a slab (item 3's sweep; slice 3: from straight
   above a floor the first slab it meets is that floor's own, not one between), and the pose
   check's *in front of the stack* clause reds on every floor; *the pose that cannot be read* — the focus distance
   doubled → the far corners' scale falls under the label bound on a stage that held it; *the glide through the
   stack* — `betweenPoses()` interpolating the camera in a straight line → from a back azimuth to a low floor's
   pose the sweep meets a slab; *the aim
   re-read* — the acts' mute dropped → a wheel stream that outlives the glide, over a point that lies on another
   floor from the destination, yields a second pose change (item 3's plant); *the label hidden from the tree* — a culled label given
   `hidden` instead of `.visually-hidden` → the browser leg's focusability and `display` reads red; *the nameplate
   painted twice* — the building page's painter without `omit` → the picture holds a `text.role-name` (the browser
   leg's assertion); *the failure routed nowhere* — a painter whose `failed` reports nothing → the rejected theme's
   floor has `art_failed` false (the browser leg's plant; the node GREEN's subject is the harness's injection).
3. **The stack's arithmetic, the poses, the paths, the snap, the acts and the cull** — `stack-model-probe.mjs` and
   `building-acts-probe.mjs`, each leg a property, its plant and its observable, over stacks of one, two, five and
   ten slabs of the shipped default's footprint and of unequal footprints, on the stages § 3.3 names and whatever
   others a leg derives:
   - **The storey converges and clears.** Property: `storeySpacing()` settles within the bound (§ 3.1) with
     `s ≥ g(s)` strictly, and from `overviewPose(…, 0)` the ray to each lower slab's three back-edge points — the
     ground's included — passes under the slab above's underside at that slab's front plane (`segmentClears()`).
     Plants → observable: the parallel-ray formula → a back-edge ray crosses above the underside; the last iterate
     without the tolerance → `s ≥ g(s)` false; the `BAND_H` storey → every lower slab's ray crosses; the ground
     dropped from the population → the one-slab stack's ground ray crosses; the deepest slab not the one derived
     from → a ray crosses — the ground's, on the fixture of unequal footprints (slice 3: the ground carries the
     deepest footprint by construction, so its ray is the one the plant uncovers first, not the deep floor's own). ⚠ The first draft's test checked `s · tan(θ) ≥ D`, which the formula satisfies
     by construction (review round 2's M1); this one reads the camera.
   - **The focus pose's four properties, on every floor of every fixture** (§ 3.3): (1) the camera outside every
     slab and in front of the stack's face; (2) the sight lines to the face's corners and back-edge midpoint clear of
     every other slab; (3) the corners inside the stage less `LABEL_MARGIN`; (4) the farthest corner's scale at least
     `LABEL_MAX / box.w` on the stages § 3.3's predicate holds on, PRINTED on the stages it does not. Plants →
     observable: `FOCUS_POLAR` 0 → (1) false on every floor; the distance doubled → (4) false on a stage that held;
     halved → (3) false; `LABEL_MARGIN` 0 → an edge desk's label box crosses the stage's edge (item 5's box
     assertion).
   - **The paths clear.** Property: from `overviewPose()` at every azimuth on a grid to every floor's pose, every
     pose to every other, every pose back to every azimuth, sampled, no step's segment meets a slab; the least
     clearance beyond the stack's support is printed, not asserted. Plants: `betweenPoses()` linear in position → a
     step from a back azimuth to a low floor meets a slab; `FOCUS_POLAR` 0 → a step between two floors' poses meets
     a slab (the departing floor's own, first).
   - **The floor entries' corner.** Property: from every azimuth on the grid the segment to `labelCorner()`'s corner
     of every slab, lifted a hair, clears every slab. Plant: the front-left corner always → from a side azimuth a
     lower slab's corner is behind the slab above.
   - **The snap table, cell by cell** (§ 3.3). Property: each cell's answer on each fixture — a wheel in over a
     floor's projected centre focuses it, over the ground's or the sky stays; a click likewise; **+** from the
     overview is the floor whose top-face centre projects nearest the stage's centre, whichever the fixture makes it
     (the probe prints which), and from a focus pose unchanged; **−** and a wheel out stay at the overview and
     return to it from a focus pose at the azimuth the fixture had turned to; *Fit* returns at 0; `aim()` through a
     slab's underside answers `null`. Plants: **+** aiming at the centre's ray instead of choosing a floor → on a
     fixture whose centre ray meets no slab (the probe asserts that precondition first) no floor is chosen; a *stay*
     cell answering a move → the view changes where it must not; `aim()` taking any face → an underside hit is a
     floor.
   - **The acts**, under stand-in gestures and a stub clock. Properties: a plain wheel is IN for a negative `deltaY`
     and OUT for a positive one (`camera.js` L245–257's sign); a pixel-mode stream fills the bucket and fires exactly
     once — **the stream's length and spacing chosen by running the leg so that it both fills the bucket and
     continues past the glide, arrival driven from the stub clock at `GLIDE_MS` after the firing event** — every
     later event consumed and unchanged; a line- or page-mode event is a discrete notch and fires alone (§ 3.3,
     § 12 item 22); an event after a quiet gap of `GESTURE_GAP_MS` starts a new gesture with an empty bucket; a
     Ctrl+wheel reaches a notch by `PINCH_GAIN`; a pinch fires at the step whose cumulative ratio first crosses
     `PINCH_SNAP` and not at the next; `drag(dx, 0)` at the overview turns and records the resting azimuth, at a
     focus pose changes nothing; `camera()` before any extent has `bounds: null`. Plants → observable: `±1` per
     event → as many changes as events; the bucket not reset on firing → the next event fires again; **the mute
     dropped → on a point the probe DERIVES — one that `aim()` names as one floor at the overview and as a
     different floor at that floor's pose, searched over the stage's grid on each fixture, a control failure if none
     exists on any — a stream that outlives the glide yields a second pose change**; the gap read from the firing
     event instead of the last → a slow stream re-arms mid-stream; a line-mode notch put through the bucket → one
     click of a Firefox wheel does not snap; `drag()` reading `dy` → an upward drag changes the view; `drag()` not
     recording the azimuth → the next glide's first step jumps (its origin's azimuth differs from the shown one).
   - **`labelsShown()` and `cullOverlaps()`**, over synthetic boxes and over the fixtures. Properties: (a) no two
     kept boxes intersect; (b) of an overlapping pair the earlier in READING ORDER (§ 3.4) is kept; (c) a leader is
     kept and the labels it overlaps culled, and an arrival with a culled active label keeps it; (d) the same input
     gives the same set; (e) **the predicate and the cull agree**: on a stage § 3.3's predicate holds on, the cull is
     empty and `readFloorLink()` is `hidden`; on **a stage on which the cull culls at least one label — derived by
     the probe from the fixture's own anchors and measured widths, narrowing the stage until it does, a control
     failure if no stage does** (the shipped default's desks stand farther apart than the box, so a stage the
     predicate fails on need not cull — round 6's minor 1) — the cull is non-empty and the link is shown with
     `/floor/<key>`. Plants → observable: the cull keeping every label → (a) false on the derived stage; the order
     by screen `x` → (b) false; the leader ignored → (c) false; `LABEL_MAX` doubled → (e) false on a stage that
     held; the link's predicate inverted → (e) false.
   - **The rest.** `focusScale()`: at least 1, at most the cap over the floor's longer side, and the pose's
     nearest-edge scale in device px between (plant: the DPR dropped → on a high-DPR stage it stays 1 where it
     should not); `worldOf()`: the drawing's bottom-left maps to the slab's front-left corner and its top-right to
     the back-right (§ 3.1); `fitDistance()`: the bounding sphere fits for a wide and a tall surface; `textureSize()`:
     1:1 under the cap, proportional over it; `tokenNames()`: every token the sheet test holds `STYLE` to;
     `pictureSvg()`: each token once on the root and the sizes in place of the percentages (plant: a token left out →
     item 5's chip rect reads black, the leg that can see it).
4. **The page wiring** — `BuildingPageWiringTest`: the ids both ways, the log handed to the screen only, no `fetch`
   or `EventSource` outside `livePage()`, `cameraGestures(` and `cameraKeys(` each called once with the stage and
   the camera row's buttons, `cameraView(` called with `between` and `onArrive` options and `view.gliding()` read in
   the page's `show`, `matches(':focus-visible')` in `labels.js`'s `focusin`, `OrbitControls` nowhere under
   `building/`, `createPainter(` called with `omit` in `floor-picture.js` and without it in `floor/main.js`.
5. **The browser leg** — `tools/design/building-view.browser.mjs`, in `design-doc-verifiers.yml`'s `browser-smoke`
   job after the `starts` row, on the pinned shell: the served `/building` (`render-floor-view.php building`) fed
   the `floor_rides` run as `floor-ride.browser.mjs` feeds the lobby. The still properties: no uncaught exception;
   the stream asked for; `#building-canvas` holds a WebGL 2 context and **`getContext('2d')` on it answers `null`**
   (a canvas holding a context of one kind refuses another; the first draft compared a bare canvas's context with
   itself — review round 3, minor 8); one `.floor-scene` SVG per composed floor under `#building-pictures` with **no
   `text.role-name` and no `.plate` in it**; one `canvas[data-floor]` per picture beside it, **the page's own
   rasters read where the page keeps them** — a desk's chip rect in the top floor's canvas is not the background, by
   `getImageData()` on the page's canvas, never a reconstruction; each list entry `data-home="stage"`; at the
   overview every desk label carries `.visually-hidden` and every floor entry none; the strip's art line is the floor
   page's *some art failed to load* and the fixture's rejected theme is among its floor's failed assets (slice 3: the
   floor page's strip names no asset, so *names the rejected theme* was false of it). The moving properties, each with its plant and observable:
   - **A culled label is in the tree and the tab order, and keyboard focus follows.** Property: a culled label's
     computed `display` is not `none`; a Tab (a keyboard event through the protocol, so the focus is
     `:focus-visible`) that lands on a culled label of another floor makes it `document.activeElement` and starts a
     glide to its floor — a cut under the shell's reduced-motion emulation — after which the label is un-culled.
     Plant: the label given `hidden` → `display: none` and the Tab skips it; the focus rule dropped → `data-view`
     unchanged after the Tab.
   - **A mouse press on a label does not snap** (round 6's M1). Property: a mouse press on a floor entry at the
     overview followed by a drag of more than `DRAG_SLOP_PX` leaves `data-view` unchanged and changes the floor
     entries' `transform`s (the building turned). Plant: the `:focus-visible` predicate dropped → `data-view`
     changes to that floor at the press.
   - **The label boxes, on two stages.** Property: on a stage § 3.3's predicate holds on, every desk label of the
     arrived floor is un-culled and `#building-read-floor` is `hidden`; **on a stage on which the cull culls at least
     one label — derived as item 3 derives it, from the extents read off the page's `canvas[data-floor]`s and the
     widths off its labels, run through `cullOverlaps()` under node, the leg failing as a control if it finds none
     and re-launching the shell at the viewport it finds** — at least one label is culled, the link is shown with
     `href` `/floor/<key>`, and a Tab onto a culled label of the floor un-culls it; on both stages no two un-culled
     labels' `getBoundingClientRect()`s intersect and every one lies inside the stage. Plants: the cull keeping every
     label → two boxes intersect on the derived stage; the link's predicate inverted → shown where nothing is culled.
   - **The stream, one event per animation frame, with motion ON** (this run drops the shell's reduced-motion
     emulation). Property: `wheel` events of a small negative `deltaY` dispatched over a floor's projected centre
     from a `requestAnimationFrame` loop make `data-gliding` `true` (its one writer is the page's `show`, § 3.3) and
     keep it so across consecutive frames until arrival, the floor entries' `transform`s changing between those
     frames; exactly one `data-view` change (`overview` → `focus:<key>`) and one write of `#building-view-status`
     with that floor's name; that floor's desk labels un-culled at arrival and no other's; **the stream continued
     until `data-gliding` has read `false` for a run of frames the leg chooses — so it outlives the glide by
     construction — with no second `data-view` change**; a wheel out from there returns every desk label to
     `.visually-hidden`. Plants → observable: the page's `show` not gated on `gliding()` → ZERO `data-view` changes
     (every event cuts the glide at its origin, so it never arrives); **the acts' mute dropped → a second `data-view`
     change, on a point the leg derives as item 3 does (`aim()` over the grid at each viewport it tries; a control
     failure if none)**.
   - **`--selftest` plants**, each seen red: the token inlining dropped (`style` attribute stripped) → the chip rect
     reads black; a floor removed from the layout → one host fewer; `--disable-3d-apis` on the shell → the § 7
     statement and no canvas; a painter whose `failed` reports nothing in `floor-picture.js` → the art line is empty;
     and each plant above.
   It prints each floor's paint and rasterise time at scale 1 and the focused floor's at its `focusScale()`, and
   the page's `performance.memory` where the shell exposes it. ⚠ **What it does not hold**: that a real GPU, Safari
   or Firefox rasterise the SVG as SwiftShader does, that a pinch on a real touch screen snaps, which `deltaMode`
   each browser's wheel sends, or how the snap feels under a real trackpad — named, not claimed; `--out
   building.png` saves a picture for the eye, as `floor-fixture.browser.mjs` does.

## 9. The slice plan, for the coder

Each slice is one PR to `dev`, titled `card#13033 [no-close]: …` until the last, which `(closes card#13033)`
only when the operator has read the sandbox (§ 13 ruling 5's reading); each carries
`docs/changelog.d/13033-<slug>.md` and the `FLOOR.md` landing marker for its half of row 47. **Every slice's
acceptance includes § 8's rule (§ 12 item 21): every plant its legs name is run and seen red in the PR, and the card
records that run** — a leg whose plant has not been seen red is not done.

1. **The hoists, the painter's two options, and the two wire extensions** (`floor-compose.js` with
   `composeFloor()`, `artInputs()`, `roomMaps()`, `sceneInput()`; `floor-data.js` with `roomTilesets()`; the
   painter's `host` and `omit`, with `painter-probe.mjs`'s `omit` leg; `camera-gestures.js`'s plain-wheel point;
   `camera-view.js`'s `between` and `onArrive` options and `gliding()`; `offerKeys()`'s shortcuts argument), with test 1 and no behaviour change on the floor page or the lobby; the
   import-graph bounds of the lobby (`TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`) unchanged.
2. **Three.js vendored and pinned**: the two files and `LICENSE`, the vendor headers, `vendor-pin-check.sh`'s leader
   column with its `--selftest` seen red on a planted body edit, `docs/ATTRIBUTION.md`'s section, `three` in
   `package.json`'s devDependencies with the header-to-lockfile version check, and the root `.gitignore`'s
   re-include of `server/public/js/vendor/`; the transfer-size measurement and the `php-tests` wall-time delta
   (§ 5's decision rule) on the card.
3. **The model, the acts and the screen**: `stack-model.js` (the storey with the ground in its population, the two
   poses, the cylindrical transition, the snap table, the top-face aim, the labels' corner and set, the focus
   scale), `building-acts.js` (the 3-D act set with its two buckets, its mute and its clock), `building-screen.js` (with `deskLabels()` and
   the one-room tileset step after a `room.map`), the probe's `building` entry (with its rejected theme), the
   `building_stack` run in `fx-camera.json`, tests 2–3, `FLOOR.md`'s AT-D3-37 red list confirmed against the plants.
4. **The page**: route, view, `main.js`, `floor-picture.js` (the focus scale), `stack-paint.js`, `labels.js`, the
   house wires on the stage with the 3-D acts, the page's gated `show`, `/building` in
   `EveryModuleAPageLoadsIsVersionedTest::pages()` (§ 5), the wire test's rename (§ 4's ten files),
   the hint partial's slot, the stylesheet
   (the stage, the labels), the status line, the entry links, test 4, the browser leg with its plants, AT-D3-37's
   graph walk rooted at `building/main.js` in place of the three modules slice 3 roots it at (`ROOTS` in
   `TheBuildingViewStacksTheFloorsTest`), its `PATH` gaining `wire/live-page.js` → `wire/animation-log.js`,
   `render-floor-view.php building`; the sandbox deployed and a screenshot on the card for the operator's reading —
   this is the reading stage 2 waits on, and where the snap's feel, the narrow-stage overlap and the side azimuths'
   occlusion (§ 10) are read.

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
  1,784 × 864 it is 6.2 + 6.2 + 8.2 ≈ 21 MB in flight and ≈ 14 MB resident, and a focused floor at the cap's 4,096 × 1,984
  (§ 3.2 step 8: a 2,560 × 1,300 stage at DPR 2) 32.5 + 32.5 + 43.3 ≈ 108 MB in flight and ≈ 76 MB resident. Ten floors at the cap would hold ≈ 1.5 GB
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
  looks at it. § 3.3's resize rule re-applies the resting pose at once; what is not defended is the jump itself,
  which is the floor page's own behaviour when its extent grows (*Fit the floor* re-frames), read on the sandbox.
- **The import map's placement.** The map must precede every module script (`layouts/app.blade.php` L123's ⛔);
  the building view inherits it. A browser without import-map support cannot resolve the vendored module — nor
  any module the pages load today, since every import already goes through the map
  (card#12158), so this adds no new population.
- **The SVG-as-image rasteriser's limits.** Chromium measured; Safari and Firefox not. Known differences in SVG
  image rendering between engines (filters, `color-mix` support before 2023 releases) are named here and read on
  the sandbox by eye at slice 4; nothing in this plan defends them.
- **Stages below the legibility bound** (§ 3.3, § 3.4): the predicate is aspect-driven and fails on reachable
  desktop stages (1,024 × 700, 1,200 × 450, 768 × 950 among them); there the close-up is a partial view — the names
  that fit in reading order, the rest culled and in the tree, the *Read this floor* link to the floor page (the
  operator's answer A, § 13 ruling 9). Not defended: WHICH names show is the reading order's choice, not the
  viewer's — the back rows, farthest from the camera, lose names first; the floor page is where every name is read,
  one tap away.
- **The overview turned to the side hides the lower floors' far halves** (§ 3.3): the storey's visibility holds at
  the front and the back; at 45° and 90° the ground's far corner is under the slab above. Not defended: the pictures
  are edge-on there, and the front is where a floor is read.
- **The focused picture's cost — UNMEASURED.** One raster at `focusScale()` per focus change on a high-DPR or large
  stage, and per render of the focused floor whose SVG changed (nearly every render while its desks are staffed, as
  the ages paragraph of § 3.2 says); none on the common desktop stage at DPR 1, where the scale is 1; the paint-time
  trigger above covers it, and the browser leg prints the focused floor's time at its scale.
- **The mute's gap is one constant** (`GESTURE_GAP_MS`, 300 ms, § 3.3): a trackpad whose inertial stream pauses
  longer than that re-arms mid-stream and could snap twice; a deliberate double-swipe faster than that is read as
  one gesture and snaps once. Read on the sandbox; one constant to move.
- **Keyboard focus on a label moves the camera** (§ 3.4) — read against WCAG 3.2.1 (*On Focus*) as a browser's
  scroll-into-view is: the page, the focus and the content are unchanged, the view of the stage is what follows.
  Named for the review; a *View this floor* button per entry is the fallback if a reader rules it a change of
  context.
- **A drag begun on a label turns the building** (§ 3.4): the wire listens on the stage, the labels are inside it,
  and a press that moves `DRAG_SLOP_PX` is the camera's with its click vetoed. The press also FOCUSES the link
  (Chromium, Windows Firefox: an `<a href>` takes focus on `mousedown`, and the wire prevents no `pointerdown`), and
  that focus does not match `:focus-visible`, so the focus-follows rule ignores it (round 6's M1; § 8 item 5 holds
  it). Not defended beyond that: a browser that marks a mouse-given focus `:focus-visible` would snap at the press
  — read on the sandbox per browser.
- **The wheel's unit per browser** (§ 3.3): a pixel-mode stream fills the bucket; a line- or page-mode event is a
  discrete notch and fires alone. Which `deltaMode` each browser's mouse and trackpad send, and how a snap feels on
  each, is read on the sandbox per browser; one rule to move if a browser's stream is line-mode.
- **The label's size is a px size** — the floor page's nameplate's (`FONT_NAME`), not the page's `rem`: a viewer's
  text-size setting scales the lobby's directory faces (§ 4.1 decision 37) and not these, as it does not scale the
  floor page's nameplate either (SVG px under its camera). A seat ruling (§ 12 item 16), reversible to `rem`.
- **Deliberately not defended:** a floor count so large the overview's fit distance dwarfs every slab (every pose
  scales with the bounds, and the snap is how a floor is read); two viewers' cameras disagreeing (navigation is never state; nothing is shared);
  the lobby slab carrying the lobby room's picture (a perspective drawing is not a plan — ruled out, not deferred).

**Cross-boundary conditions, by name (canon #7).** The one hop is **upstream Three.js → this repository**:
upstream DECLARES its API and licence in the package; this repository CHECKS that its copy is the declared bytes
(`vendor-pin-check.sh`'s body sha, comparable to jsDelivr's published digest — § 5); what neither end can verify
locally is upstream's **behaviour in the viewer's browser** (WebGL 2 presence, SVG rasterisation, the
pointer events on a given OS), which the browser leg exercises on one pinned engine and names the rest of as
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
  `worldOf()`, `labelsShown()`, the one docking function), extended from the nameplate to the chip and the bubble:
  stage 1 builds it and stage 2 inherits it, as it inherits § 3.3's pose-and-snap camera, whose focus pose over one
  floor is where a stage-2 overview of that floor would start.
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
  both stages (§ 13 rulings 1 and 8: the 3-D view's convention as A re-cut it, the 2-D floor keeps its own).
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
   (12 px); the texture cap (4,096 over the 2,048 floor); the pose figures of § 3.3 — § 12's rows.
2. The lobby is a plain ground slab with no picture (the order itself is the operator's ruling 3), and **not a snap
   target**: nothing on it to read; its entry is the lobby's link.
3. A floor with no extent takes the stack's widest footprint and no picture.
4. Textures are stills under `reduce`, re-rasterised per render on change, never on the age tick.
5. Slabs centred on `x`, front edges aligned; the drawing's top toward the back.
6. The list docks to the slabs while the renderer runs and flows below the stage otherwise.
7. Three.js is vendored unminified from the npm package at the pinned version; no minification step; **no addon**
   — OrbitControls is not used (§ 3.3), so no bare specifier and no import-map entry.
8. The advisory leg is a lockfile entry held equal to the vendored version (§ 5), not an accepted gap.
9. Every whole-tree walk includes `vendor/` (§ 5; measured 0 findings on r187).
10. **Resize and a changed stack**: the poses are re-derived; at rest the resting pose is re-applied at once, a glide
    in flight completes at once to its destination's new pose, and a focused floor the layout dropped is the
    overview at once (§ 3.3).
11. The `mutatedModules()` copy-cost rule: a `php-tests` wall-time delta under 5 % keeps the plain copy; over it,
    `vendor/` is symlinked into each copy (§ 5).
12. **The overview is turned and nothing else**: azimuth by a drag or the arrows, the tilt the home tilt, the
    distance the fit, no pan (§ 3.3): the operator amended ruling 1 to *left-drag rotates, wheel zooms*
    (`opq-1794250846-d5ff`, § 13 item 10), so right-drag is the browser's default here as on every page (the gesture
    wire takes the primary button alone, `camera-gestures.js` L268).
13. **The focus pose** is from the front at the home tilt, at the least distance that frames the floor's top face
    with `LABEL_MARGIN` (half the widest label — the plate's text bound plus the sheet's padding and border — and
    a label's height) — one constant for the tilt, the rest derived; **legible** is the scale predicate of § 3.3,
    printed per stage; below it § 3.4's overlap cull (§ 3.3).
14. **The transition** is the house glide (`GLIDE_MS`, a cut under reduced motion) over a cylindrical
    interpolation about the stack's axis; **every act's answer during a glide is dropped by the page's `show`**, and
    **the wheel and the pinch are muted from a snap until the gesture has been quiet for `GESTURE_GAP_MS` (300 ms)**;
    a snap that is already at its destination moves nothing (§ 3.3).
15. **The snap table** (§ 3.3): a wheel in or a pinch in over a floor focuses it, over the focused floor, the ground
    or nothing stays; a click on a slab focuses it, never opens it; **+** focuses the floor nearest the stage's
    centre from the overview and does nothing from a focus pose; a wheel out, a pinch out or **−** returns to the
    overview at the azimuth it left; *Fit the building* returns to the overview at the front. One snap per
    gesture by the bucket at one notch (a pixel-mode wheel), `PINCH_GAIN` on a Ctrl+wheel, and a quarter's spread (a
    pinch); the buckets empty when a gesture begins; a line- or page-mode wheel event is a notch on its own (item 22).
16. **The nameplate is omitted from the slab's picture and carried as a label over the stage** — at the floor page's
    nameplate size in px (not the page's `rem`), docked at the nameplate's centre, a link to the desk's
    drill-down; **drawn exactly for the focused floor at rest, culled with `.visually-hidden` otherwise** (in the
    tree and the tab order, never `hidden`); **on a stage below the bound the labels that fit are drawn, in the
    floor's READING ORDER — defined once in § 3.4, the list's order too — with the focused label leading, the rest
    culled, and the *Read this floor* link to the floor page is shown** (§ 3.4); the floor entries always, at the slab's corner nearest the camera; the chip, the
    facts, the bubble and the strip stay in the picture (§ 3.4).
17. **Keyboard focus on a label of another floor snaps to that floor** — a `focusin` whose target matches
    `:focus-visible`, never the focus a mouse press gives a link — as a browser scrolls a focused link into view; a
    snap moves focus nowhere and the status line announces the floor; focus that landed during a glide is read again
    at arrival; the cull's leader is `activeElement` at arrival and on `focusin`, `relatedTarget` on `focusout` (§ 3.4).
18. **The focused slab's picture is rasterised at `focusScale()`** — the pose's nearest-edge scale in device px, at
    least 1 and at most the texture cap over the floor's longer side — every other at 1 (§ 3.2 step 8).
19. The keys and the buttons go through the house wire (`wire/camera-keys.js`); the wheel, the drag and the pinch
    through `wire/camera-gestures.js`; the glide through `wire/camera-view.js` — each extended by an optional
    parameter its 2-D callers do not pass (§ 3.3, § 6).
20. **The stage is the one element**: the wires', the `role="group"`, the tab stop; it advertises `+ - ArrowLeft
    ArrowRight`, and the vertical arrows move nothing (§ 3.3).
21. **§ 8 names properties, plants and observables, never fixture arithmetic.** Three review rounds found tests in
    this plan's prose with hand-computed counts, timings and spacings that could not fail; prose arithmetic about a
    fixture is the defect class. The fixture, the viewport, the stream's length and its timing are chosen at
    implementation by running the leg; a precondition a leg needs is derived from the model over the fixture and is
    a control failure if absent; and every slice's acceptance is *every plant run and seen red in this PR, the run
    recorded on the card* (§ 8's preamble, § 9; revision 9).
22. **A line- or page-mode wheel event is a discrete notch** and fires alone; the bucket is for pixel-mode streams
    (§ 3.3) — the alternative, resetting the bucket only after a snap, was not taken because it would let two
    unrelated nudges minutes apart add up to a snap.

## 13. Operator rulings of 2026-11-09 — decided (card comment 16451; `kbcard comments --task 13033`)

The first revision carried these as questions with recommendations; the operator answered each the same day, and
answered the review loop's four product questions (items 7–10) later that day. Items 1–6 are **summarised** and
the card's text is quoted exactly in `FLOOR.md` § 14 item 49; items 7–10 quote their records. No open question
remains in this file.

1. **Gestures.** The 3-D view uses OrbitControls' convention — left-drag rotates, the wheel zooms, right-drag pans —
   spelled out on the hint line, and the wheel over the canvas never scrolls the page. The 2-D floor keeps the
   gestures of 2026-10-01 (§ 3.3). **Amended by the operator the same day (items 8 and 10) to *left-drag rotates,
   wheel zooms***: the wheel snaps, and right-drag is the browser's.
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
   lands: § 2.1 (the directions weighed), § 3.2 step 7 (the painter's `omit`), § 3.4 (the label layer); the aimed zoom,
   the slab guard and the near limit revision 4 built on it were withdrawn by item 8. `FLOOR.md` § 14 item 49 carries
   the same record.
8. **Snap, not flight — the operator's answer to design review round 4 (2026-11-09; card comment 16570, registry
   item `opq-1794245900-fb26`).** Round 4 found that the aimed zoom re-aimed on every notch and drifted to another
   floor, and that the buttons aimed into the gap between storeys (its M1 and M2); the seat asked whether zooming
   in should snap to a floor rather than fly freely toward it, and the operator chose **A**, the recommendation —
   the answer itself the single letter *A*; the option, as the registry item states it (quoted exactly from the
   card):
   > **A — Snap to a floor**
   >
   > Wheel, pinch, a click on a slab, or the + button (aimed at the floor nearest the screen centre) moves the camera in one smooth animation to a fixed close-up pose for that floor, where every name is readable. Wheel out, - or Fit returns to the whole building. Example: on /building you wheel over the third floor; the camera glides to a straight-on close view of floor 3 and stops; the next wheel-out glides back to the overview. The poses are computed ahead, so the camera never drifts, never lands on empty space and never passes through a floor.

   No additional info was given; the consequence the item named — no stopping halfway — is accepted with it. Where
   it lands: § 3.3 (the two poses, the transition, the snap table, the house reader), § 3.4 (the labels by state, the
   keyboard), § 3.2 step 8 (the focus scale), § 12 items 2 and 10–20. **What it re-cut in ruling 1**: the wheel
   snaps rather than zooms; the pan half was taken to the operator as its own question and settled by item 10.
   `FLOOR.md` § 14 item 49 carries the same record.
9. **Stages below the legibility bound — the operator's answer A to registry item `opq-1794250845-e851`
   (2026-11-09).** Round 5's M2 found the bound aspect-driven and failing on reachable desktop stages; the seat
   asked what the close-up should do there and recommended A, and the operator answered both open questions in one
   line — *"do as you recommend for both questions"*. The option, as the item states it:
   > On a screen too small, the close-up shows only the names that fit without overlapping (screen readers still get every name) and shows a link "Read this floor" that opens the 2D floor page, which already shows every name at any size.

   The consequence the item named, accepted with it: *"on smaller screens the 3D close-up is a partial view and
   full reading happens on the floor page, one tap away. No new camera mechanism."* Where it lands: § 3.4 (the
   overlap cull in reading order, the focused label leading, the culled labels in the tree, the *Read this floor*
   link), § 3.3 (4), § 8 items 3 and 5, § 12 items 13 and 16; `FLOOR.md` § 14 item 49 carries the same record.
10. **Right-drag pan — the operator's answer A to registry item `opq-1794250846-d5ff` (2026-11-09)**, in the same
   line. Round 5 read ruling 1's *right-drag pans* as binding the overview too; the seat asked whether the overview
   should keep a pan and recommended dropping it everywhere; the operator agreed. **Ruling 1 stands amended to
   *left-drag rotates, wheel zooms***: the wheel snaps (item 8), a left-drag or one finger turns the overview
   (§ 3.3), and right-drag is the browser's default here as on every page (the gesture wire takes the primary button
   alone, `camera-gestures.js` L268). `FLOOR.md` § 14 item 49 carries the amendment.

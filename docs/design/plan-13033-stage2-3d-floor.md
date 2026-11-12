# card#13033 — design and build plan, stage 2: the floor in full 3-D

**What this file is.** The design and the build plan for stage 2 of card#13033, in the shape `plan-13033-3d-view.md`
and `plan-13117-seat-identity.md` set: written for the coders who implement its slices and the reviewer who attacks
it. It owns the *derivation*, the *sequence* and the *acceptance list as observables*; it owns no rule. Every rule
lives in `FLOOR.md`, which revision 2 amends where the 3-D floor touches a ruling: § 1.2's library row, § 4.4's
route table, § 4.5's camera bullet, § 6.3's timer bullet, § 10.1's clause 1, § 10.4's projection bullet (its
stage-2 paragraph), § 10.6's theme model, § 12's two *Floor 3-D* rows, § 13 row 119 and § 14 item 49. A reader who
finds the two disagreeing trusts `FLOOR.md` and fixes this file.

**Why a file of its own and not a stage-2 part of `plan-13033-3d-view.md`.** That plan is history from § 1 to § 10
(stage 1 has shipped: slices 1–4, PRs #457, #458, #460, #461, `git log --oneline dev | grep 'card#13033'`), and its
§ 11 outline and § 9 stage gate are what the ruling below REPLACES. A second half appended to a 1,600-line document
whose first half it contradicts in its premise (billboards within a band) would make every reader resolve which
half holds; a file whose header says what it supersedes does not. `plan-13033-3d-view.md` § 11 is left as the
record it is, with one line pointing here.

**The ruling this file is built on — the operator, 2026-11-11, on the stage gate (card#13033 comment 17048;
`kbcard comments --task 13033`), quoted exactly:** *"I want full 3D models that allow you to move around in the
floor to view from any angle above the floor"*. So stage 2 is real 3-D models — characters of every species,
furniture, the room and its architecture — with a camera that moves within a floor, looks from any heading, and
looks from any elevation between just above the floor plane and straight down, and never goes below the floor. It
supersedes `plan-13033-3d-view.md` § 11's billboard outline, its 15°–55° elevation band and § 13 ruling 5's two gate
questions (both answered by this one sentence: billboards no, free elevation yes), and it replaces the 2026-10-03
3/4 top-down oblique ruling (`FLOOR.md` § 13 row 49) for the floor view.

**Two further rulings of the same day — the operator's words as the seat recorded them on the card (comment 17050),
quoted exactly from that record:**

1. *"in designing this, you should use any open source 3D rendering libaries available to not reinvent the wheel"*
   — prefer an existing open-source library wherever one fits, the Three.js ecosystem and its add-ons first; name
   each, its licence as read at its source, and how it loads under the site's rules (plain ES modules, no build
   framework, a pinned exact version); *build it ourselves* is the exception and is justified each time; where a
   library supplies what today's code builds by hand, say so. § 2.1 and § 5 are where this lands.
2. *"Eventually I want height and depth built into the building view too"* — the stage-1 building view's slabs
   eventually become real floors in a building volume. Not the first slice; the floor model is designed so the
   building view reuses it (§ 3.1's one world, § 9 slice 8).

**Revision 2 (2026-11-11) records the operator's answers to revision 1's three questions and folds the design
review of `121fd05d`.** The answers, quoted exactly from the card: *"Q1. A"* (comment 17077) and *"Q2. A / Q3. A"*
(comment 17079) — characters code-built, furniture from Kenney's Furniture Kit, the room first-party; orbit + pan +
zoom with right-drag panning; `/floor/{key}/3d` beside the 2-D page first. § 13 records them as answered and § 11's
replacing amendments are applied to `FLOOR.md` in this revision, except where § 11 says otherwise. The review's
dispositions: **B1** — walls and the band are never colliders, and nothing else is either (§ 3.7); with no collision
left, `OrbitControls` from the already-vendored package does the whole job and **replaces `camera-controls`** (§ 2.1,
no new dependency); **B2** — the entry pose is set from the spherical figures directly (`camera-controls`'s
`fitToBox()` snaps to an axis view, its dist file's `roundToStep(…, PI_HALF)` at lines 1628–1630, which is why
revision 1's entry would have come in from straight ahead or straight above) and the browser leg asserts the 50°
entry polar (§ 3.6, § 8 item 5); **M1** — *the cap* is defined, the draw-call budget re-derived with the interns
counted, rigid skinning chosen to fit it, every budget row given a plant or dropped (§ 6, § 12 item 4); **M2** —
the frame loop, its stop condition, the mixer's time and `startAt`, one pose encoding (§ 3.5; `FLOOR.md` § 6.3
amended); **M3** — the keyboard reaches rotation and tilt, a seat ruling and an adapter (§ 3.6, § 12 item 3); **M4**
— the sitter's side pinned from the 2026-10-06 ruling, the monitor's and the lid's orientation put to the operator
as Q4 and answered the same day (comment 17086: each faces its user, and the status floats as a camera-facing label
— § 3.4, § 3.9, § 13); **M5** — a Gate 2 clause for `.glb`, served through `/art` (§ 3.2, § 4); **M6**
— the floor page's wiring hoisted into `floor/floor-page.js` with the renderer a parameter (§ 4); **M7** — one
species data set read by both generators, no parallel recipe table (§ 3.3); and the minors where they stand.

**Standing constraints, each read from its owner:** cute, whimsical, high-resolution (`FLOOR.md` § 10.4's first
bullet); Three.js is the library, plain ES modules, no React, no build framework (`FLOOR.md` § 1.2's library row, § 13
row 118); decision logic in node-testable modules with no DOM, only the renderer touching WebGL (`lobby/lobby-model.js`'s
rule, restated in every renderer's header); every asset a provenance row from the closed allowlist — `CC0-1.0`,
`ISC`, `MIT` — (`docs/ATTRIBUTION.md`, `bin/asset-provenance.py`); no private information in the public repository;
MariaDB only; no root.

**Revision 3 (2026-11-11) folds design review round 2 of `686694c7` under the coordinating seat's rule for the round:
round 2 refuted library-API detail the plan had pinned (`startAt`, `screenSpacePanning`, `mergeGeometries`, a clip's
duration, `memory.textures`), and a pinned call is a claim a reviewer can falsify that a slice coder's red test would
catch more cheaply — so where a finding is about library mechanics this revision states the PROPERTY and the TEST
that goes red, and names an API only as a starting point marked *to confirm in slice N*. The decisions stay at design
altitude: routes, module contracts, budgets, slice order, what each test observes. Dispositions: **B1** — no
per-action start time; one mixer per character with its time set from `T` plus that seat's own offset, and the test
reads two seats in one state at one instant (§ 3.5, § 8 item 2); **B2** — the 3-D route grammar is
`/floor/{floor}/3d[/{seat}]`, registered before the seat route with a constraint that excludes the literal `3d` (the
constraint was dropped by revision 4's m1: registration order alone, the seat route unconstrained as today), the
route kind a parameter of the hoisted page from which `routeOf()` and `seatSegment()` derive, and the URL's survival
of the first paint and a reload tested (§ 3.8, § 8 item 5); **M1** — the budget re-derived with laptops, contact
discs, band parts and stools counted, walls instanced so each lowers on its own, stated as its derivation (§ 6);
**M2** — the clock's observable is the pose against the clip at `T`, not a position (§ 8 item 2); **M3** — each
source-shaped test that reads `floor/main.js` is named with where it re-points, and the floor's browser rows stay
unedited as the behaviour control (§ 4); **M4** — the renderer contract carries the keys (§ 4); **M5** — the pan mode
set explicitly, a vertical pan leaving the target's `y` unchanged (§ 3.6, § 8 item 5); **M6** — the frame loop and the
cut-away's moving row move to slice 4 (§ 9); **M7** — a resource becoming ready restarts the loop and the controls'
change wakes it (§ 3.5); the minors as one-line statements or *to confirm* notes with their tests; and § 10 gains the
residual risks, one line each with the slice test that owns it.

**Revision 4 (2026-11-11) folds design review round 3 of `cb06b218` (no blocker; three gating majors and nine
minors).** **M1** — the clock reaches `FLOOR.md` § 6.3 as the rule: `offset(key)` keyed by the seat key or the
intern key, a held loop's time starting at the paint (`heldRendering()` carries no elapsed time, `animation-set.js`
L313–321; the 2-D tick restarts on every paint, `painter.js` L1454–1468), the run and stop conditions on
`held.motion` / `frame_interval_ms`, and a held `motion: false` leg (§ 3.5, § 8 item 2); **M2** — the renderer
supplies the camera: `create()` returns `show`, `current`, `fit` and `resize`, and the page's keys offer, *Fit*, the
resize observer and the painter's camera argument all read from it, so `floor-page.js` never touches
`wire/camera.js` — the 2-D renderer wraps today's `cameraView` and `screen.camera` (§ 4; `FLOOR.md` § 4.5 made true
under it); **M3** — a character's body and face share one material (a face atlas whose white texel the body
samples), the laptop the second call; an intern-specific `renderer.info` leg in slice 3; the budget leg asserts each
derivation term separately; the total re-summed with the restroom leaf, the birdhouse, the window sky and the cab
interior, and set from the honest sum plus a stated margin (§ 6); and the minors m1–m9 and the chrome partial,
each where it stands.



## 1. The problem, in the product's terms

The floor page (`server/public/js/floor/painter.js` over `floor/scene.js`, under `wire/camera.js`) is a picture
drawn for one viewer *standing in front of the room and a little above it* (`FLOOR.md` § 10.4's projection bullet):
a plan for the floor, face-on furniture, flat characters, every other wall a strip. The building view
(`/building`, stage 1) shows that picture on a slab and lets the viewer turn the stack and snap close to a floor —
and the operator, having read it on the sandbox on 2026-11-11 (*"I can see the entire building view and zoom into a
floor"*, card comment 17047), asked for the floor itself in space: models with height, a camera that goes round
and over them. A picture cannot give that: turned to the side, a drawing of a desk is a drawing of a desk seen from
the wrong way (stage 1's own words, `plan-13033-3d-view.md` § 3.3), and the plan's billboard outline could only hold
within a band of elevations the ruling removes.

### The population the design must cover

Each derived from the code, so a reader re-runs it rather than trusting a figure (the command, then its reading on
`dev` `8718ea86`, 2026-11-11):

- **Species** — `node -e 'import("./resources/characters/index.js").then(c=>console.log(c.SPECIES_KEYS.length, c.SPECIES_KEYS.reduce((m,k)=>(m[c.SPECIES[k].kind]=(m[c.SPECIES[k].kind]||0)+1,m),{})))'`
  — read 48 keys over three kinds (animal 24, vegetable 12, fruit 12). Every one needs a 3-D body (the ruling:
  *every species included*); a species with no 3-D data is a RED, § 8 item 2.
- **The seeded appearance space** — `FLOOR.md` § 10.4's dimension table, whose closed lists are the generator's
  (`creatures.js` exports `EYES`, `MOUTHS`, `BLUSH`, `BROWS`, `SIZES`, `STOUT`, `TILTS`, `ACCENTS`; per species `pals`,
  `hats`, `necks`, `extras`). § 3.3 says what each dimension becomes in 3-D; the accessory population is
  `node -e 'import("./resources/characters/index.js").then(c=>{const u=(f)=>new Set(c.SPECIES_KEYS.flatMap(k=>c.SPECIES[k][f]||[]));console.log([...u("hats")],[...u("necks")],[...u("extras")])})'`
  (read: five hats, three necks, four extras).
- **Poses and glyphs** — `desk/desk-poses.js`'s `DESK`: `node -e 'import("./server/public/js/desk/desk-poses.js").then(p=>console.log(new Set(Object.values(p.DESK).map(d=>d.pose)), new Set(Object.values(p.DESK).map(d=>d.glyph))))'`
  — read six poses (`at-keyboard`, `asleep`, `raised-hand`, `head-in-hands`, `present`, `empty-chair`) and eight
  glyphs. Each pose is a rig pose in § 3.5; each glyph a marker or a screen state.
- **The animation set** — `wire/animation-set.js`'s `ANIMATION_SET`:
  `node -e 'import("./server/public/js/wire/animation-set.js").then(a=>console.log(Object.entries(a.ANIMATION_SET).map(([i,r])=>i+":"+r.class).join(" ")))'`
  — read 26 ids, of which two are `retired` (A18, A21) and draw nothing. Every `edge` and `held` row has a 3-D form
  in § 3.5's table, or the table says what changes in the set (nothing does: § 3.5's finding).
- **Scenery kinds** — `resources/floor/themes/index.js`'s `KINDS` (read 19, two of them plane kinds: `wall`, `accent`;
  the rest standing pieces); **themes** — `THEMES` (read `studio`, `cottage`); the theme API's furniture set —
  `chair`, `desk`, `monitorFrame`, `deskProps`, `sideTable` (`API`). Every standing kind and every furniture piece
  needs a 3-D form per theme (§ 3.4).
- **The floors** — `lobby/building-model.js`'s `plates()`, the same list stage 1 covers; ⚠ not re-read for this
  plan (no `.env` in the worktree; the live checkout is not this plan's to enter). The coder re-runs
  `plan-12987-cab-door-view.md` § 1's three `tinker` commands before slice 4 and records the readings on the card.
- **The scene's and the painter's consumers** — `grep -rln "createPainter(\|buildScene(" server/public/js` — read
  `floor/main.js`, `floor/floor-compose.js`, `floor/floor-screen.js`, `floor/painter.js`, `floor/scene.js`,
  `building/building-screen.js`, `building/floor-picture.js` (the door view reaches the painter's drawing through
  `cab-paint.js` and matches neither call — `grep -n 'doorView\|drawFloorView' server/public/js/floor/cab-paint.js`).
  Stage 2 adds a consumer of `buildScene()` and removes none (§ 3.8).
- **The cap floor** (§ 6): the shipped default `resources/floor/default.tmj` — `python3 -c "import json;d=json.load(open('resources/floor/default.tmj'));print([len(l.get('objects',[])) for l in d['layers'] if l.get('type')=='objectgroup'])"`
  reads 6 `desks` objects — every desk staffed with § 8.1's cap of 8 interns (6 × 9 = 54 characters), every standing
  kind placed at least once, and the plane rasterised at stage 1's texture cap (a wider authored floor reaches it;
  the default does not).

## 2. The shape, and what was rejected

**Chosen — the floor in 3-D is the scene model's THIRD renderer, not a second model.** `floor/scene.js`'s
`buildScene(frame, input)` stays the one DOM-free decision about what stands where (every desk's rects, every
tile's cell, every walker's route and position, every § 6.2 form as data), and a new DOM-free module,
`floor/floor-world.js`, lifts that scene into a **world** — boxes, placed pieces, placed characters with their pose and
clip, label anchors — in the units stage 1 already fixed (§ 3.1). A new renderer, `floor3d/`, draws the world with
Three.js and the add-ons § 2.1 adopts, and nothing it draws is decided in it. The 2-D painter is unchanged and
keeps its consumers; the building view's slabs keep their pictures until slice 8 replaces them with the same world
at building scale. Why this and not the others:

| Rejected | Mechanism it would turn on | Why not |
|---|---|---|
| **A second floor model for 3-D** — rooms, slots, routes re-derived in world units | a `floor3d/model.js` reading the maps itself | The second answer to *where does this desk stand* that `plan-12987` § 2 and `plan-13033` § 2 each rejected; every walk, queue and break rule (`scene.js`, `route.js`) would be copied or lost. `buildScene()` is the model; a world is a projection of it (canon #5) |
| **Billboards within an elevation band** (`plan-13033` § 11) | `SpriteMaterial` quads of the existing frames | Removed by the ruling: a billboard seen from above is a sliver and from behind is its front; *any angle above the floor* needs bodies |
| **Multi-view sprites** — eight facings per species from the 2-D generator | the generator drawn at eight azimuths, picked per frame by the camera's bearing | Still cardboard from above (the ruling admits straight down), and eight drawings per species × every pose × every walk phase is a larger art task than one body per species, for a weaker result |
| **The 3-D floor inside the 2-D floor page's `<svg>`** (CSS 3-D transforms over the painted groups) | `transform-style: preserve-3d` on the painter's groups | No depth between a character and the desk it sits behind, no occlusion, no lighting; the card decided the library |
| **A server-rendered 3-D view** | a headless renderer on the host | No server holds seat state to draw honestly (stage 1's reason), and the host has no GPU |
| **Replace the 2-D painter outright** | delete `painter.js`, every consumer re-pointed | The door view and the building slabs read it; AT-D3-19, -20, -23 to -26, -31 to -36 are written over its output; nothing is bought by deleting it in the same change that adds the new renderer (§ 3.8; the operator's answer A to Q3) |

### 2.1 The libraries — the ruling's "don't reinvent the wheel", applied item by item

Each row names what is adopted, what in today's code or in the first draft of this design it REPLACES, its licence
as read at the source on 2026-11-11, and how it loads under the site's rules. § 5 owns the vendoring and pinning.
The rule used: a library is adopted where it supplies a mechanism the design needs *now*; one that supplies a
mechanism only a measured trigger would need is named with its trigger, not vendored ahead of it (canon #6).

| Need | Adopted | Licence, read where | Loads how | What it replaces |
|---|---|---|---|---|
| Load a model file (the furniture kit, any authored `.glb`) | **`GLTFLoader`** — `three@0.187.0/examples/jsm/loaders/GLTFLoader.js`, with its two relative imports `../utils/BufferGeometryUtils.js` and `../utils/SkeletonUtils.js` (read at jsDelivr: it imports from the bare specifier `'three'` and those two files; DRACO, KTX2 and Meshopt decoders are injected only when a file's extensions require them, and no adopted asset will — § 3.2's Gate 2 clause refuses one) | **MIT** — the same npm package stage 1 vendored, whose `LICENSE` is already at `server/public/js/vendor/three/LICENSE` | vendored beside `three.module.js` under `vendor/three/examples/jsm/…`, bytes unchanged; the one bare specifier resolved by **one import-map entry**, `"three"` → the versioned URL of `three.module.js` (§ 5) | nothing today — stage 1 loads no model; without it every model would be code-built |
| Animate a rigged character: clips, mixing, discrete frames | **`AnimationMixer`, `AnimationClip`, `KeyframeTrack`, `Bone`, `Skeleton`, `SkinnedMesh`** — Three.js core (`grep -c 'class AnimationMixer' server/public/js/vendor/three/three.core.js` → 1) | MIT, vendored | already vendored | the painter's own frame timer and per-frame image swap (`painter.js`'s held-loop timer, its header's *THE ONE TIMER HERE*); § 3.5 keeps the DRIVER the scene's and gives the mixer only the clock the scene names |
| Labels over the scene (names, chips, facts, bubbles) | **`CSS2DRenderer` / `CSS2DObject`** — `examples/jsm/renderers/CSS2DRenderer.js` (read at jsDelivr: imports `Matrix4, Object3D, Vector2, Vector3` from `'three'`; appends each object's element to its container on first render where it is not already a child, positions it by a CSS transform at the projected point, and sets `display: none` when the object is outside the camera's depth range or hidden) | MIT, same package | vendored addon, the same import-map entry | **`building/labels.js`'s hand-written docking** — `place()` projecting each anchor with `Vector3.project()` into a `translate()` on every applied camera and resize (`plan-13033` § 3.4's *one docking function*) is exactly what `CSS2DRenderer.render()` does; stage 2 uses the library for the floor's labels, and § 12 item 9 records the stage-1 migration as a later hygiene change |
| Orbit, pan and zoom with limits, damping, touch | **`OrbitControls`** — `examples/jsm/controls/OrbitControls.js` at the same pinned version (read at jsDelivr, 40,918 B: imports `Controls, MOUSE, Quaternion, Spherical, TOUCH, Vector2, Vector3, Plane, Ray, MathUtils` from `'three'`; constructor defaults `minPolarAngle` 0, `maxPolarAngle` π, `minDistance` 0, `maxDistance` ∞, `enablePan` true, `enableDamping` false, `touches { ONE: ROTATE, TWO: DOLLY_PAN }`, `mouseButtons { LEFT: ROTATE, MIDDLE: DOLLY, RIGHT: PAN }`; public `rotateLeft()`, `rotateUp()`, `pan()`, `dollyIn()`, `dollyOut()`, `getPolarAngle()`, `getAzimuthalAngle()`, `getDistance()`, `update()` answering whether anything changed; `minTargetRadius`/`maxTargetRadius` a sphere about `cursor`; no collision — each a reading of the file, each default to confirm in slice 4 by the page's own configuration test) | MIT, same package | vendored addon, the same import-map entry | **`camera-controls`** (revision 1's choice; npm `camera-controls@3.1.2`, MIT, one dist file with no `import`, verified 2026-11-11 and recorded here so the reading is not lost) — chosen then for three things: a box boundary for the target (`setBoundary`), camera collision (`colliderMeshes`) and `fitToBox`/`fitToSphere`. The review's B1 removed every collider (§ 3.7), its B2 found `fitToBox` snaps the azimuth and polar to the nearest quarter turn (`roundToStep(this._sphericalEnd.theta, PI_HALF)`, dist lines 1628–1630 — an entry from straight ahead or straight above, never 50°), and the box boundary is the same three lines inside either library or outside both (`Box3.clampPoint` on the target after `update()`, § 3.6). What was left — polar and distance limits, right-drag pan, damping, touch — `OrbitControls` has, from a package already vendored and pinned, so it is chosen and **no second dependency is added**. And **stage 1's hand-written glide** (`betweenPoses()`, the acts' buckets and mute, `plan-13033` § 3.3), which a free camera does not need |
| Cutting the camera's view through walls (occlusion) | **`Raycaster`** — core; one ray per frame from the camera to its target against the wall meshes (§ 3.7) | MIT, vendored | already vendored | nothing today |
| Fast raycasts over large meshes (BVH) | **`three-mesh-bvh`** (`registry.npmjs.org/three-mesh-bvh/latest`: `0.9.18`, `license: MIT`, `module: src/index.js`, `peerDependencies.three: >= 0.159.0`) — **named, not adopted.** The floor's ray targets are the wall boxes and the one ray a frame plus one a click; the trigger is a measured raycast cost over **1 ms** a frame on the cap floor (§ 6's leg prints it). Its `src/` build is many files importing `'three'`, so adopting it is a slice of its own | MIT | — | a hand-written spatial index, which nobody is writing |
| Repeated furniture drawn in one call | **`InstancedMesh`** — core | MIT, vendored | already vendored | per-piece meshes (§ 6's draw-call budget rests on it) |
| Few draws per character | **`SkinnedMesh` over merged parts** — rigid skinning, every vertex bound to its part's bone (§ 3.3): the starting point for the PROPERTY § 6 budgets, *a character costs at most two draw calls, its laptop and face included* — to confirm in slice 3 by `renderer.info` on one character | MIT | the utils file GLTFLoader needs | one mesh per part (§ 6: hundreds of draws at the cap, which revision 1 budgeted without the interns) |
| Detail by distance (the building volume, slice 8) | **`LOD`** — core | MIT, vendored | already vendored | a hand-written swap by distance |
| Turn the generator's silhouettes into 3-D feature shapes (ears, leaves, stems) | **`SVGLoader`** (`examples/jsm/loaders/SVGLoader.js`, same package; `ShapeGeometry`, `ExtrudeGeometry`, `LatheGeometry`, `CapsuleGeometry` from core) | MIT | vendored addon | hand-written path parsing |

**What is built first-party, and why each time** (the exception the ruling allows): the **creature bodies** —
no open-source pack has 48 animal, vegetable and fruit creatures with the seeded colourways, faces and dress
`FLOOR.md` § 10.4's appearance bullet rules, and the look is the operator's approved sheet (§ 10.2), so the bodies are
a generator extension built FROM the library's geometry classes over the species' own data (§ 3.3), as the 2-D
bodies are code; the **room's architecture** — walls are boxes, the plane is the theme's own plane document as a
texture, the band is the theme's band document on a vertical plane: there is no asset to load, only the theme's
drawings to place (§ 3.4); the **target's box clamp** (three lines over `Box3`, § 3.6); and **the world lift**
(`floor-world.js`) — a projection of this product's own model, which no library can know.

**What is adopted from outside for the furniture, and what is refused.** Read at the source on 2026-11-11:

- **Kenney — Furniture Kit** (`https://kenney.nl/assets/furniture-kit`): licence stated on the page as **"Creative
  Commons CC0"**; *140×* under *Files*; the page lists no formats and no item names. **CC0-1.0 is on the allowlist.**
  This is the kit the operator chose on 2026-09-12 *explicitly as a bridge to first-party vector art*
  (`docs/ATTRIBUTION.md` § The floor) and that left the tree at `FLOOR.md` Appendix B row 22 — the same bridge
  shape, offered again for the same reason; **the operator chose it, Q1 answer A (comment 17077)**.
- **Kenney — Mini Characters** (`https://kenney.nl/assets/mini-characters`): **"Creative Commons CC0"**, 40 files —
  people, not creatures; refused on § 10.2's *never a person*.
- **Quaternius** (`https://quaternius.com/license.html`): the **Quaternius Asset License v1.0**, not CC0 — attribution-free
  but with redistribution terms of its own; **refused by the closed allowlist** (`docs/ATTRIBUTION.md`: anything
  outside `CC0-1.0`, `ISC`, `MIT` is refused, and widening it is the operator's).
- **KayKit** (`https://kaylousberg.itch.io/`): the catalogue page states no licence and the Furniture Bits page
  answered 404; **unverified, so not named** as a source.

## 3. The derivation

### 3.1 One world — units, frame, and why the building view can reuse it

Stage 1 fixed the unit: **one world unit per `UNIT` = 100 scene px** (`building/stack-model.js`, `FLOOR.md` § 12's
*Building view unit*), `y` up, a floor's slab centred on `x` with its front edge (the drawing's bottom) at `+z`, and
**`worldOf(slab, point)`** the one map from a scene-px point to a world point on the slab — read it in
`floor/floor-world.js`, where slice 2 hoisted it; it is not restated here. Stage 2 builds ONE floor in exactly that frame, its plane at `y = 0`, so
every plan point the scene already computes — a desk's anchor, a walker's `pointAt()`, a queue spot, the elevator's
threshold — becomes a world point by the function stage 1 already has, and the building view (slice 8) places N such
worlds at `y = storey × ROOM_H_U` with no second frame. `worldOf()` is hoisted to `floor/floor-world.js` at its
second caller and re-exported from `stack-model.js` unchanged (canon #5).

**Depth against height — how the lift splits the scene's `y`.** The scene is a plan whose `y` is depth, except for
the elements the 2-D layout draws ABOVE a thing's foot: the bubble, the sleeper's z's, the attention and question
markers, the nameplate's plate and the facts column are laid out in scene px above (smaller `y` than) the desk's
foot line, and that offset is a HEIGHT, not a depth. The rule, one function: for an element `el` of a desk `d`,
`anchor3d(el, d) = { x: el.x, depth: d.depth_y, height: max(0, d.foot_y − el.y) }` — `depth_y` is the desk's own
member (`scene.js` L2215: the desk sprite's bottom), `foot_y` the character's foot (`trail_foot.y`, L2224; the desk
sprite's bottom for a desk with no character) — and the world point is `worldOf(slab, { x: anchor.x, y: anchor.depth })`
lifted by `height / UNIT`. So a bubble stands over the character's head at the height the 2-D layout gave it, a z
at `headClear()`'s point above the head, the chip at the box's foot in front of the desk, and nothing is placed by
its drawn `y` as if that were depth. A walker's point is a plan point (height 0); its bubble's height is the same
rule over the walker's own foot.

**Heights.** The room is `ROOM_H` from `floor/door-view.js` — the band's `BAND_H` = 160 scene px, **1.6 units** —
the one height the cab's door view already gives a floor (`FLOOR.md` § 12's *Door-view scale*: 222 cm); a partition is
`PARTITION_H` = 120 cm → `120 / SCALE` = 86.4 scene px = **0.864 units**; a desk's top `DESK_H` = 76 cm → 0.547 units,
its monitor's top `MONITOR_TOP` = 147 cm → 1.058 units; a seat's character stands `walkerSize(character).h` =
`CHARACTER_SCALE × SCENE_H` = 120 scene px = **1.2 units** tall (`floor/scene.js` `walkerSize()`, `desk-layout.js`
`CHARACTER_SCALE` = 3.75, the tree's `SCENE_H` = 32); an intern `STOOL_H` = 56 px = 0.56 units. These are the door
view's own figures in the stage-1 unit, so the floor seen through the cab's doors and the floor in 3-D agree on every
height without a new constant; the only number this section chooses is none.

**The world — `floorWorld(scene, frame, art, theme)`** (`floor/floor-world.js`, pure, frozen, no DOM, no Three):

```
{ extent: { w, d },                       // the scene's extent over UNIT
  plane: { document, w, d },              // the theme's plane document(s) per grid, as today's scene gives them — the floor's TEXTURE
  band:  { document, x, w, h: ROOM_H },   // the theme's band document, a vertical quad along the back edge (z = −d/2)
  walls: [{ id, box: {x, y, z, w, h, d}, kind: 'edge'|'partition' }],   // from door-view.js's wall classification, hoisted
  pieces: [{ kind, at: {x, z}, size: {w, d}, seed, index, theme }],      // every standing scenery tile, foot at its cell's floor line
  desks: [{ key, at, parts: { desk, chair, monitor, sideTable, props }, sitter: { key, species, pose, glyph, lighting, side: 'band' } | null,
            interns: [{ key, species, at, laptop, typing }], screen: { glyph, line } }],
  walkers: [{ key, species, chibi, effect }],  // the scene's in-flight § 6.2 effects, each positioned by route.js's pointAt() at draw time
  elevator: { at, leaves: {…} }, restroom: { at, leaf: {…} }, birdhouse: {…},
  labels: [{ key, kind: 'name'|'chip'|'facts'|'bubble'|'notice', anchor: {x, depth, height}, text|model }] }
```

Everything in it is a read of `scene` (the rects, the tiles, the desks' elements, the effects) and of `frame`
(rooms, band, extent); it adds no fact and reads no field the scene does not carry (the scene's header's rule). The
wall boxes come from `door-view.js`'s classification — a `wall` cell on its room's footprint edge is `ROOM_H`, inside
it `PARTITION_H` — which is hoisted into `floor/floor-walls.js` at its second caller, `doorView()` calling it as
before (behaviour-preserving: `door-view.selftest.mjs` is the control).

### 3.2 Where the models come from — the comparison, and the operator's answer

The three sources the brief named, each against cost, licence and provenance fit, and the look it gives against
*cute, whimsical, high-resolution*; the characters and the furniture weighed separately because they come out
differently.

| Source | Characters | Furniture, scenery, room | Licence and provenance | Cost | Look |
|---|---|---|---|---|---|
| **(a) First-party, code-built** — bodies assembled from Three.js geometry classes by a generator extension, the way the 2-D creatures are drawn by `creatures.js` | **The only source that can carry the species set and the seeded space**: 48 species, five colourways each, five eyes × five mouths × blush × brows, hats, necks, extras, side, girth, size, tilt — a function of the key, as `FLOOR.md` § 10.4 rules it | Possible — boxes and lathes in the theme's `PALETTE` — but every piece is design work with no pack to lean on | First-party, MIT, the generator's source is the asset (as the themes': *the source is the asset, and Gate 2 reads it*, `docs/ATTRIBUTION.md`); **no new file type** under `resources/` | The largest design effort of the three for the characters (48 species over one data set: § 3.3); for furniture, 17 standing kinds × 2 themes + the desk set | Consistent by construction with the 2-D sheet's shapes and colours; low-poly-toy rather than sculpted; resolution-independent (geometry, not texture); the eye-judged risk is a body that reads as a bead-and-tube toy rather than a creature — § 3.3's rules are written against it |
| **(b) CC0 packs** via `GLTFLoader` | None found with creature species; Kenney's characters are people (refused, § 10.2) | **Kenney Furniture Kit, CC0 (verified at source, § 2.1)**: the fast path for desks, chairs, bookcases, plants, lamps, cabinets — ⚠ its item list is **unverified** (the page shows *140× Files* and no names); the slice-1 coder downloads it, lists the files on the card, maps each standing `KINDS` member and each desk part to a kit file, and the kinds the kit lacks are first-party (a) | `CC0-1.0`, on the allowlist; **each `.glb` a manifest row** with its sha256; **`.glb` admitted by Gate 2 with a clause of its own** (below) — a widening of the admitted-format list, the operator's by `docs/ATTRIBUTION.md`'s reading, **given with Q1's answer A** (comment 17077: *"This includes the asset gate admitting .glb files"*, the seat's record of the answer's scope) | Low: files, a mapping, a recolour step | Flat-shaded, blocky, chunky — cute in the Kenney way, not the themes' way; the theme's identity (studio's plum-shaded wood, the cottage's garden) is carried by RECOLOURING the kit's materials from `PALETTE` (§ 3.4), which is what makes two themes two rooms |
| **(c) Hand-authored glTF**, commissioned or made first-party in a modelling tool | The best look ceiling: sculpted creatures on a shared rig, one `.glb` per species, colour slots and accessory meshes driven from the seed | The best look ceiling for furniture too, per theme | First-party rows if made here (MIT), or a commission whose terms assign MIT/CC0 — a contract, not a gate; `.glb` admission as (b) | **Money and calendar** for a commission; for first-party authoring, a skill this seat has not demonstrated — nothing in the repository was made in a modelling tool, and a coder's output under node is checkable where a sculpt is not | The ceiling, if the artist is good; and 48 species + 2 themes is weeks of someone's time |

**The operator's answer — Q1 A (comment 17077): characters by (a), furniture and scenery by (b) with (a) for what
the kit lacks, the room by (a); (c) held as the exit if slice 3's sheet reading finds the code-built bodies
wanting.** The cost the answer accepts: two visual vocabularies on one floor — Kenney's chunky furniture and the
generator's toy creatures — which the recolour and a shared flat-shaded material (`MeshToonMaterial`, core) are to
reconcile, judged by eye on the operator's reading at slice 4.

**Format: glTF binary (`.glb`)** for every file asset — one file per model, buffers and images embedded, no
DRACO/KTX2/Meshopt extension (so no decoder is vendored), under `resources/floor/models/<pack>/…` with a manifest
row each. Procedural models have no file: their source is the asset, as the themes'. **How a `.glb` is served:**
through the asset route, as every file under `resources/` is — `App\Floor\FloorAssets::SERVED` gains
`'.glb' => 'model/gltf-binary'` (today's map: `.ts`, `.js`, `.md`, `.svg`, `.png`, `.tmx`, `.tmj`, `.tsx`, `.tsj`,
`FloorAssets.php` L65–75) and `TheArtRouteServesOnlyWhatTheGatesJudgedTest` gains the row; `PublicAsset` is not
involved — it versions `public/`, and `resources/` is served by this route alone (revision 1 said otherwise; M5).
**How a `.glb` is judged — Gate 2's fourth clause** (`bin/asset-provenance.py`, with `.glb` added to
`ASSET_BINARY_EXTENSIONS` and the clause in `FLOOR.md` § 10.1, applied by revision 2): the file is read as a GLB —
magic `glTF`, version 2, a JSON chunk — and the JSON is held to: **(i)** `buffers[]` every one embedded (no `uri`),
**(ii)** `images[]` every one embedded (a `bufferView`, no `uri`), **at most** `GLB_IMAGES_MAX` of them, each a PNG
whose IHDR width and height are **at most 1,024 px** (read off the bytes), and **covered by the file's own
manifest row** — the embedded-provenance rule: an image inside a `.glb` is part of the file the row names, so the
row's licence and sha256 are its, and the gate prints each image's size beside the row; **(iii)**
`extensionsRequired` absent or empty; **(iv)** no `extensionsUsed` naming a compression extension. A file failing any
clause is refused by name; `bin/asset-provenance.selftest.py` gains **one RED per refusal** (the selftest's `clause 4 RED` cases list them): an external
buffer URI, an external image URI, an image that is not a PNG, an oversize image, more images than `GLB_IMAGES_MAX`,
an `extensionsRequired` entry, a compression extension named, a non-GLB renamed `.glb` — and a GREEN control. **Size budget:** ≤ 2 MB
of `.glb` transferred for a floor (the cap floor's pieces plus the desk set, derived by the slice-1 coder from the
kit's file sizes and the `KINDS` mapping, recorded on the card beside the files chosen — § 6 gives it its plant).
⚠ Whether the kit's materials are flat colours, vertex colours or one palette texture is **unverified** here (the
page names no format); the slice-1 coder reads it off the files and records it, and § 3.4's recolour is written for
either form.

### 3.3 Characters — how the seeded space carries over to 3-D, over one species data set

**One data set, two generators (M7).** Today each species' silhouette lives as drawing code inside `creatures.js`'s
`build()`; a parallel 3-D recipe table would be a second statement of the same shape, free to drift. So slice 3
moves, per species, **the half-outline** (the body's profile as a point list in frame units), **the feature shapes
and their anchors** (ears, leaves, stem, tufts, spines, tail — each a path and the point it attaches at) and **the
face, hat, neck and hand anchors** into DATA on the species entry — `SPECIES[k].shape` — that **both** `build()` (the
2-D frame, which draws the outline it used to hold inline) and `bodyParts()` (3-D) read. The control is the 2-D
side: `tools/characters/sheet.browser.mjs`'s capture before and after the move, held pixel-equal per species by the
selftest, and `distinct.browser.mjs`'s legs unchanged and green. There is no `RECIPES_3D`.

**The 3-D creature is the same recipe, built as parts.** `resources/characters/creatures-3d.js` (first-party, a
manifest row; pure, total, no I/O, no Three import — the character tree's own rules) exports `bodyParts(key, { species,
chibi })` → a frozen **part list** at the REST pose: each part a primitive the renderer knows how to build —
`{ shape: 'lathe'|'extrude'|'capsule'|'sphere'|'box', params, at, rotate, scale, colour, bone }` — in the same
units the 2-D frame uses (`SEAT_RECT`'s 108 × 192, the foot at `FOOT` = 188), so a seat's body is
`CHARACTER_SCALE × SCENE_H` tall by the existing arithmetic and the chibi's head share is measured on the same
geometry the 2-D contract measures; and the fixed bone hierarchy `BONES` — `root`, `body`, `head`, `armL`, `armR`,
`legL`, `legR`, `featureL`, `featureR`, `tail` — the same names for every species, so one pose table animates all
48. **Poses and clips are one encoding (M2):** `POSES` is a table of named poses, each a map bone → `{ rotate, at }`
— `stand`, `step-left`, `step-right` (the walk's three phases, `frameDocument()`'s `phase` 0/1/2), `at-keyboard`,
`asleep`, `raised-hand`, `head-in-hands`, `present` (the six of `DESK`, `empty-chair` drawing no body), `type-a`,
`type-b` (A3's bob), `lean-back` (A4), `sway-l`, `sway-r` (A23) — and `CLIPS` names each § 6.2 form as a sequence of
pose names at `LOOP_FPS`: `walk: ['stand', 'step-left', 'stand', 'step-right']`, `work: ['type-a', 'type-b']`,
`think: ['lean-back']`, `intern-type: ['sway-l', 'sway-r']`, a still pose a one-entry clip. The renderer turns
`POSES` and `CLIPS` into clips that STEP — at any instant a character shows exactly one pose of its clip, never a blend of two (§ 3.5, the property; discrete keyframe tracks the starting point, to confirm in slice 3); the probe reads the tables.

**How the parts are drawn — the property, and a starting point (M1, § 12 item 4).** The renderer
(`floor3d/creature-paint.js`, the one file that imports Three for characters) builds each part with the library's
geometry classes from the part list and draws the whole character — body, face and, for an intern, its laptop — **in
at most two draw calls, as one skinned mesh over one skeleton per character whose joints move rigidly** (a toy's
joints, not a skin's): that is the property § 6's budget rests on and the browser leg reads (`renderer.info` over one
character, § 8 item 5). The starting point is merged rigid skinning — every vertex bound to its part's bone at full
weight, the parts merged into one geometry, **the body and the face sharing ONE material** — a face atlas texture
whose one white texel the body's UVs sample, the vertex colours multiplying through, so a seat's creature is one call
— and the laptop a second material group of its own, its opacity the character's (A24's fade is per intern, so it
cannot be an instance of a shared mesh): a seat ≤ 1 call, an intern ≤ 2 — **to confirm in slice 3 by a
`renderer.info` leg specific to interns** (§ 9 slice 3); if two calls cannot be reached that way, § 6's derivation is re-run with the measured
cost and the budget restated on the card, never the test loosened. The part list stays the model's and the probe still
measures it; the merge is the renderer's.

**What each dimension of § 10.4's table becomes:**

| Dimension (2-D) | In 3-D |
|---|---|
| **species** — the silhouette, the ears or leaves, the face's placement | the species' **half-outline** as a `lathe` (the radish's taper, the pumpkin's squat ribs as a ribbed radius function, the pea-pod's elongation) and its **features** extruded from their shapes at their anchors; the face at the `face` anchor's height — all from `SPECIES[k].shape`, the data the 2-D frame draws from. Where a species is not a solid of revolution (the banana, the watermelon slice, the starfruit) the entry's `shape.solid` is `'extrude'` — its outline extruded with a depth and a bevel — and says so |
| **colourway** (`pals`) | the body material's two tones (body, belly) as vertex colours — the same hex pairs |
| **variant** (lop rabbit, mushroom caps) | a `shape` branch per variant, the same draw |
| **girth** | `x`/`z` scale of the body |
| **size** | a uniform scale about the foot, the same `SIZES` |
| **tilt** | a rotation about the foot's `z` axis, the same `TILTS`; an intern upright |
| **eyes, mouth, blush, brows** | **the face is the 2-D generator's face, as a texture on the head's front**: `creatures.js` gains `faceDocument(key, { pose, glyph, species, blink })` — the eyes, brows, mouth and blush layer of `frameDocument()` alone, on a transparent ground (a generator extension, first-party); the renderer rasterises it with stage 1's SVG-as-image pipeline (`building/floor-picture.js`'s `pictureSvg()` → canvas → `CanvasTexture`, hoisted to `floor3d/svg-texture.js` at its second caller) and maps it onto the face patch of the head at the `face` anchor. Why not geometry: the approved faces are drawn lines — a sparkle eye, a cat mouth — and a texture keeps them the approved drawing at any zoom (the document is vector; the raster is at the size bucket the distance implies: 256 px, and 512 px for the desks within 3 units of the camera) |
| **hat, neck, extra** | one small part group per accessory name (five hats, three necks, four extras — § 1's derivation), attached at the `hat`, `neck` or `hand` anchor — procedural, in the accent colours the draw already gives |
| **accents** | the accessory colours, unchanged |
| **side** | a mirror of the asymmetric parts about `x` |
| **details** (the bear's chest mark, the frog's spots…) | each a named part or a decal in the body's vertex colours, per species entry |
| **interns** — chibi, head ≥ half the height | the same data with the chibi proportions, measured on the part list |

**The seed is the seat id** (`plan-13117` § 3.4, card#13117 S2): `seatKey(seatId)`, the species DELIVERED as
`character.species`; nothing here reads a key apart.

**Rejected for the characters:** a per-species authored `.glb` (c) — a 48-file art task with no node-checkable
property but file presence; multi-view sprites (§ 2); the 2-D frames mapped onto a cylinder (a cutout with a
curve); one mesh per part (§ 6's budget).

### 3.4 Furniture, scenery, the room and the themes

- **The plane** is the theme's `plane()` document — a plan drawing, which is exactly a texture for a horizontal
  surface (`FLOOR.md` § 10.4 item 1 as it stands) — rasterised through the SVG-as-image pipeline onto a `PlaneGeometry`
  of the grid's size, one per room grid and one for the hallway, at the texture cap stage 1 set. The `wall` cells
  the plane draws as strips are ALSO boxes (`walls`), so the strip becomes the wall's foot.
- **The band** is the theme's `band()` document on a vertical quad at the back edge, `ROOM_H` tall, its windows'
  glazing, the clock's hands and the elevator's leaves drawn by the renderer from the scene's data as the painter
  draws them (§ 10.6 item 3: a theme never draws a fact). The clock is a flat dial on the wall with two hand quads;
  the windows' sky is the painter's `skyPaint()` as a flat colour behind the glazing cut-out; the elevator's leaves
  are two sliding boxes in a doorway cut in the band quad, driven by `rideLeaves()` / `leafLevel()` (§ 3.5).
- **Walls** are boxes in the theme's `'wall-run'` colour (`PALETTE['wall-run'].strip`) with a lighter cap, at the two
  heights § 3.1 names; a doorway is a gap in the run (`wallRuns()` in `themes/paint.js` already finds straight
  stretches and free ends, and is reused).
- **Standing scenery** (the 17 standing kinds) and **the desk set** (`desk`, `chair`, `monitorFrame`, `sideTable`,
  `deskProps`) come from the Kenney kit by the slice-1 mapping where it has a piece, each `.glb` loaded once and
  **instanced** per placement — one `InstancedMesh` per **(primitive, material)** pair a file carries, so a file of
  three primitives is three instanced meshes (§ 6 counts them so) — **recoloured from the theme's `PALETTE`**: the
  renderer maps each piece's colour slots — its named materials, or the entries of its palette texture, whichever
  the files carry (§ 3.2's unverified note) — to palette keys (`desk.wood`, `floor.boards`, `accent.*`), the map a
  data file beside the kind map, so a cottage desk and a studio desk are the same mesh in different paint. A kind
  the kit lacks gets a first-party part list in `resources/floor/themes/<name>/pieces-3d.js` (the theme API gains
  **`piece3d(kind, inputs)`**, optional: a theme that exports none falls back to the kit's or the house theme's).
- **Which way a desk faces, and who sits where (M4).** The operator's ruling of 2026-10-06 (card#11046 comment
  10125, `FLOOR.md` § 10.4 item 2): *"The agents are sitting behind the desk, so the drawers face the agent (we
  shouldn't see the the desk drawers - we'd be seeing the other side)"*. So the sitter is on the **band side** of the
  desk — behind it, as the 2-D viewer sees it — and that is pinned as the world's `sitter.side: 'band'`: the chair and
  the character stand between the desk slab and the band (smaller `z`), the character facing `+z` (toward the 2-D
  viewer's side), the drawers on the slab's band-side face. AT-D3-38's *facing* leg (§ 8 item 2) reads exactly that:
  the sitter's `z` less than the slab's, and the desk's drawer face toward the sitter. **The monitor and the
  laptop face their users — the operator's answer to Q4 (2026-11-11, card comment 17086: *"Q4: A"*, the option as
  put: *real 3D — each monitor faces its sitter, each laptop faces its intern; the status also floats as a
  camera-facing label above each desk, readable from any angle. The 2D rulings stand for the 2D floor; the 3D
  floor follows A*).** So in the world `screen.facing: 'sitter'` (the monitor's screen toward −z, the sitter's side)
  and `lid.facing: 'intern'`; `FLOOR.md` § 13 row 59 (*"the monitors face us and show the status"*) and row 87 C1
  (the lid's BACK faces the viewer) keep binding the 2-D floor and are not reversed — the 3-D floor is a second
  rendering with its own orientation, which § 10.4's stage-2 paragraph records. **The status label** that answer
  adds is § 3.9's `status` label: the monitor's state glyph and action line, as `desk-render.js` decides them for the
  screen, floating over the desk as a screen-space element — which faces the camera by construction, every CSS2D
  label does — so the state reads at any angle, and the screen texture on the monitor's face (below) shows the same
  strings to a viewer behind the sitter. ⚠ Revision 1 claimed `door-view.js` *already places* this arrangement; it
  does not — it places a desk's top and its monitor's back as row strips (`door-view.js` L257–258) and no chair or
  sitter. Corrected.
- **The monitor's screen** carries a fact (§ 10.6 item 3) and is a **`CanvasTexture` per desk** drawn by the renderer
  from the desk model's own strings — the glyph and the action line `desk-render.js` already decides — re-drawn
  only when they change; `monitor: 'off'` is a dark material.
- **Lighting**: one `HemisphereLight` (sky/ground in the theme's wall and floor tones) and one `DirectionalLight`
  from the top-left (§ 10.4 item 5's one corner), no shadow maps at first (a cost with no reading yet; the contact
  shadow is a dark disc under each character, as the 2-D rim/shadow bullets have it); `MeshToonMaterial` for the
  creatures and the recoloured furniture so the two sources shade alike. The theme's `surfaces()` contract (the
  colours a chip can stand on) is unchanged because chips are labels (§ 3.9).

### 3.5 Animation — every row of the closed set, in 3-D

**The driver, the log and the clock do not change.** `wire/animation-set.js` decides what fires and `buildScene()`
emits each firing as an effect with its legs, frames, `frame_interval_ms` and the elapsed time at the paint that
drew it (`elapsed_ms`, as the painter reads it at `painter.js` L290 and L757); the 3-D renderer, like the painter,
draws what the scene says and starts nothing (§ 6.1, § 6.3). What the library replaces is the DRAWING of a frame:
each § 6.2 form is a clip on the creature's bones from `POSES`/`CLIPS` (§ 3.3) that steps, one pose per frame,
at `LOOP_FPS` (4). The property that holds: **the same step instants** — a 3-D clip steps at the loop's rate from
the same origin the 2-D loop steps from — **with a per-key offset** (§ 3.5's clock; `loopOffset(key, length)` takes
the 3-D clip's length, which need not equal the 2-D frame count), **and the walker's body moves continuously along
its route by design** (`pointAt()` is continuous, as it is on the 2-D floor) while its legs step at the clip's rate.

**The clock (B1, M1 of round 3).** One mixer per character, keyed by its **seat key or intern key** (`keyOf()`,
`internKey()` — the keys the 2-D painter's loops are keyed by), and its time is never advanced by a free clock: on
every drawn frame the renderer SETS it, and the time it sets depends on what the character is drawing —
- **a held loop** (A3, A4, A6, A7, A9, A15, A23): **`T = (now − paintedAt) / 1000 + offset(key)`** — the loop's time
  STARTS AT THE PAINT, because a held rendering carries no elapsed time (`heldRendering()` answers
  `{ animation_id, motion, form, frame_interval_ms }` and nothing else, `wire/animation-set.js` L313–321), which is
  exactly the 2-D painter's rule: its loop timer is restarted by every paint (`painter.js` L1454–1468) and steps
  from that instant; `offset(key)` is the character's own phase, the tree's `loopOffset(key, length)` in seconds at
  the loop's rate, so two characters in one state step out of phase as the 2-D floor's do;
- **an in-flight row** (a walk, a fade, a bird — every effect the scene emits with `frames` and `elapsed_ms`):
  **`T = (fx.elapsed_ms + (now − paintedAt)) / 1000`**, offset 0 — the row's elapsed time as the paint read it plus
  the time since (the painter's own `began + elapsed_ms` pinning, L757);
- **a held rendering with `motion: false`** (`reduce`, a dimmed or dark treatment, a stilled floor —
  `heldRendering()`'s `motion` false and `frame_interval_ms` null): the still form at every instant, and no time is
  set.
There is no per-action start time: the phase is in the mixer's time. **The properties, each with its test (§ 8
item 2):** at instant `T` a character's bone pose equals the pose its clip names at frame `⌊T × fps⌋ mod length`,
`fps` from `frame_interval_ms` — the observable is the POSE (the pose id, or the bone transforms), never a walker's
position; two characters in the same held state read at one instant show different poses when their offsets
differ by a frame; a held loop repeats and an edge clip plays once and holds its last pose; a held `motion: false`
rendering shows its form's still pose at every instant; and a long task between frames moves nothing the scene did
not, because the time is set and not accumulated (plant: the mixer advanced by a delta instead of set → a stalled
frame drifts the pose from the clip's). The library's clip, action and time API is the starting point, to confirm in
slice 5. The position of a walker is `route.js`'s `pointAt(effect, T × 1000)` lifted by `worldOf()`; its heading is
the leg's direction, so a character walking away is seen from behind — which is the ruling's point and a
billboard's failure.

**The frame loop, its stop and its restarts (M2, M7; round 3's M1).** The renderer runs a frame loop **only while
something is in flight**: an effect with `frames > 0` whose `T` is under its span, **a held rendering whose
`motion` is true** (so `frame_interval_ms` is a number — under `reduce` or a dimmed treatment `heldRendering()`
answers `motion: false` and null, and nothing runs), a cut-away tween, or the camera still settling under damping;
it stops the first frame none of those holds. **It restarts on** a paint, a camera gesture (the controls' own change
event wakes it, so a drag under damping is drawn to rest), a clip's start, **and a resource becoming ready** — a kit
file parsed, a face or plane texture rasterised, the art modules answered — so a scene that was drawn before its
models arrived is drawn again when they do. **With the camera still and only held loops running, the loop draws at
most one frame per `frame_interval_ms`** (a property: a 4-fps loop needs no 60-fps draw; the test counts frames
over a second of rest). This is the painter's own rule — *THE ONE TIMER HERE is a held loop's own frames* — in the
renderer's terms; `FLOOR.md` § 6.3's timer bullet states it as the rule; the browser leg reads the stop, the restart
on a late resource and the frame count (§ 8 item 5). Built in slice 4 with the renderer (M6).

| Row | Class | 3-D counterpart | Changed in the set? |
|---|---|---|---|
| A1 arrive, A2 depart (and the walk note's 6a turn-back, AT-D3-36) | edge | the `walk` clip stepping through the route's legs at `pointAt()`, heading per leg; the leaves two sliding boxes at `leafLevel()`; the call plate a pressed box; the cab interior behind the doorway as a dark box (the cab's own drawing stays the modal's); `turnRound()`'s legs are legs | no |
| A3 work | held | `work` (`type-a`/`type-b`); blink = the face texture's two frames (open, closed) at the loop's cadence — `faceDocument()` takes `blink` | no |
| A4 think | held | `think` (`lean-back`), the same blink | no |
| A5 tool-swap | edge | the screen texture cross-fades over 250 ms — two textures, opacity | no |
| A6 idle | held | pose `asleep`: body pitched onto the desk; the three z's as **labels over the scene** (`CSS2DObject`s at `headClear()`'s point lifted by § 3.1's height rule), lit in turn as today's `sleepDocument` does — they are § 10.4 item 6's labels already | no |
| A7 attention, A9 unknown | held | pose `raised-hand`; the marker a label over the scene with the sleeper's pulse | no |
| A8 stalled | held | pose `head-in-hands`, static | no |
| A10 intern-arrive / leave | edge | the chibi `walk` clip, the laptop fade a material opacity | no |
| A11 badge-raise, A12 gauge | edge | labels — the facts card's badge fades in; the gauge is the drill-down's and the card's | no |
| A13 retire | edge | the desk group removed | no |
| A14 feed-pulse | edge | the status strip's, unchanged | no |
| A15 catching-up | held | the screen texture's replay marker | no |
| A16 desk-move | edge | the `walk` clip between desks | no |
| A17 room-tick | edge | the clock hands' rotation set, the sky colour set — a cut, as today | no |
| A19 envelope, A22 portal-bird, A20 broadcast-pulse | edge | the bird a small first-party part list (body, two wings, beak) on the arc `bird.js` gives, the envelope a box under it; the ring a flat annulus on the plane expanding once | no |
| A23 intern-type | held | `intern-type` (`sway-l`/`sway-r`) on `body` | no |
| A24 laptop | edge | opacity over `LAPTOP_FADE` frames | no |
| A25 snack, A26 restroom | held | the `walk` clip to the table/door; the food thought a label; the restroom leaf a sliding box, the slider's field a texture | no |
| A18, A21 | retired | nothing | no |

**Finding: the closed set is unchanged** — 26 ids, no row added, none re-cut; the one column that moves is HOW a
form is drawn, which § 6.2's Animation cells describe in words a 3-D rendering still satisfies (a walk is a walk).
`TheAnimationSetIsTheDocumentsClosedSetTest` is the control. The **reduced-motion forms** are drawn from the same
effects with `frames: 0` (the scene already emits them so under `reduce`), each shown at its still pose; camera
damping is off under `reduce` (§ 3.6).

### 3.6 The camera

**Gestures — the operator's answer, Q2 A (comment 17079): orbit + pan + zoom about a target on the floor,
right-drag panning, two fingers on touch.** A walk mode was offered and not chosen; it is not designed.

**The gestures, through `OrbitControls`, which reads the pointer itself on the stage:** left-drag or one finger
**rotates** (`mouseButtons.LEFT = ROTATE`, `touches.ONE = ROTATE`, the defaults); **right-drag** and a two-finger
drag **pan** the target across the floor (`RIGHT = PAN`, `TWO = DOLLY_PAN`, the defaults — `screenSpacePanning`
false, so a pan slides along the floor plane rather than the screen's); the wheel and a pinch **dolly**
(`enableZoom`, the default). This restores the right-drag pan the operator dropped for stage 1 (`FLOOR.md` § 14
item 49: *right-drag is the browser's default*) — dropped there because a fixed pose cannot be panned; here the pan
is the ruling's *move around*, and the operator chose it. The stage suppresses `contextmenu` (the library's
`connect()` already prevents it on its element). ⚠ **This is the one page whose pointer gestures do not come
through the house wire** (`wire/camera-gestures.js`): the library IS the pointer reader here, which the operator's
library ruling asks for; `FLOOR.md` § 4.5's amendment says so, and `TheCameraWireIsOneForEveryPageTest` holds the
2-D pages and the building view as before, reads `cameraGestures(` in `floor/painter-renderer.js` (its one home on the floor pages, the contract's `gestures`), and is given the 3-D floor route by name as the page that does not wire
`cameraGestures(`. The keys stay the house wire's (below).

**Limits that follow from the ruling** (`floor3d/view-limits.js`, pure — `limits(extent)` answers the figures the
controls are configured with, and the probe reads it):

- **Elevation**: `minPolarAngle` = 0 (straight down), `maxPolarAngle` = **88°** from vertical (*just above the floor
  plane* — 2° keeps the floor a surface rather than a line; one constant, § 12 item 2);
- **Never below the floor** — one guard, and the arithmetic that makes a second one dead (canon #6): the polar limit
  keeps the camera above its TARGET's height, and the target's box (next bullet) keeps the target at or above the
  plane — so the camera's `y` is `target.y + d · cos(polar) ≥ 0 + d · cos(88°) > 0` for every `d > 0`. **The near
  plane's bottom corners are above the floor too** (the review's minor): with `near` = **0.05** units the lowest
  corner of the near plane sits `near · (cos 88° + tan(fov/2) · sin 88°)` ≈ 0.022 units under the eye, and the eye is
  at least `1.2 · cos 88°` ≈ 0.042 above the plane, so the corner is ≥ 0.02 above it — `near` is 0.05 and not 0.1
  because at 0.1 the corner would be 0.003 BELOW the plane at the limit; the probe asserts the corners' `y` over a
  grid of targets, polars and the minimum distance (§ 8 item 3), and the browser leg's plant widens the polar limit
  past 90° and reads the camera's `y` under a drag that pushes down (§ 8 item 5). No collider and no third guard:
  the review's stop rule applies, and none was needed;
- **Within the floor — the target's box clamp**: after every controls update the renderer clamps the target into
  the extent box (`y` from 0 to `ROOM_H_U`) and, if it moved, moves the camera by the same vector — three lines in
  `floor-paint.js`, the box `view-limits.js`'s. Why not the library's target radius: that is a sphere about a point,
  and the floor is a box. **One clamp**: the keyboard acts (below) write the target through the same controls and
  are clamped by the same lines — there is no second clamp in `view-limits.js`;
- **A pan slides along the floor, never up the screen (M5)**: the library's `screenSpacePanning` is set explicitly to
  the floor-plane mode (to confirm in slice 4); the property — a vertical pan gesture leaves the target's `y`
  unchanged and moves it along the floor — is the browser leg's (§ 8 item 5);
- **Distance**: `minDistance` = **1.2 units** (one character's height: closer than that the near plane cuts a head),
  `maxDistance` = 1.5 × the fit distance of the extent — stage 1's `fitDistance()`, which takes the stage's aspect
  and is therefore **recomputed on every resize** (the limits and the entry pose with it); far enough to see the
  whole floor with a margin, not so far that it is a dot. **At the dolly limit the wheel is released to the page**
  (the 2-D pages' rule at the drawing's edge, `FLOOR.md` § 4.5): a wheel that would move the camera no closer or no
  farther scrolls the page instead — to confirm in slice 4 against the library's wheel handling; the test: a wheel at
  the limit changes `scrollY` and not the distance;
- **Far plane and decals**: the far plane is past the fit's maximum distance with a margin, and the face patch, the
  contact disc and the status's surfaces stand off the geometry they lie on by enough that no z-fighting shows at any
  distance the limits allow — a property read by eye on the sandbox at slice 4 (a z-fight is a shimmer no node probe
  sees) with the near and far figures printed by the leg;
- **Entry (B2)**: the target at the extent's centre, the camera at azimuth 0 (in front) and **polar 50°** — stage
  1's home tilt, so the floor opens as the slab showed it — at the fit distance: set directly from those spherical
  figures, never by a library fit (`camera-controls`'s fit rounds to an axis view; `OrbitControls` has none). *Fit the
  floor* is the same pose, re-derived on resize; a setting, no glide (§ 6.5). The browser leg asserts the polar angle
  is 50° ± 0.5° at entry and after *Fit*;
- **Smoothing**: damping on with motion, **off under `prefers-reduced-motion`** (read once at construction,
  `floor/main.js`'s rule) so every move is a cut; the loop of § 3.5 runs while the view settles.

**The keyboard reaches rotation and tilt — a seat ruling (M3, § 12 item 3), consistent with `/building` where the
arrows turn the view:** `←`/`→` rotate (azimuth), `↑`/`↓` tilt (polar, within the limits), **Shift + arrows pan** the
target along the floor, `+`/`−` dolly, *Fit the floor* the entry pose. **The keys are wired where the 2-D page's are
— in the hoisted page `floor/floor-page.js`, by `wire/camera-keys.js`'s `cameraKeys()` and `offerKeys()` — over the
act set the RENDERER CONTRACT supplies (M4, § 4)**: `floor3d/camera-acts.js` is the 3-D renderer's act set — `drag(dx,
dy, modifiers)` with `modifiers.shift` deciding pan over rotate/tilt, `zoomStep(n)`, `fit()`, `camera()` answering
`{ bounds }` for the wire's gate — each a call on the controls' public rotate, tilt, pan and dolly methods (to
confirm in slice 4) ending in an update and the clamp; the arrows' step is the per-pixel turn constant the building
acts hold today, **hoisted to `wire/camera.js` beside `PAN_STEP_PX`** at its second caller. **The keys wire is
extended by one optional argument**: `cameraKeys()` hands the key event's modifiers (`{ shift }`) as `drag()`'s third
argument (the 2-D acts read two and ignore it, as they ignore `pan()`'s point since stage 1; L69 already lets a
Shift-arrow through, refusing only Ctrl, Meta and Alt). `offerKeys()` advertises `+ - ArrowLeft ArrowRight ArrowUp
ArrowDown Shift+ArrowLeft Shift+ArrowRight Shift+ArrowUp Shift+ArrowDown`. The library's own key listener is never
connected, so the keys have one reader.

**Keyboard focus** on a desk's label (`:focus-visible`, stage 1's predicate) moves the target to that desk (the
target set and the camera moved by the same vector, through `update()` — a cut under `reduce`) and leaves the
distance and angles — the browser's scroll-into-view, as stage 1 argued it under WCAG 3.2.1; the status line
(`role="status"`) names the desk.

### 3.7 Through walls, desks and characters — occlusion and cut-away

- **Nothing is a camera collider (B1).** Walls and the band are never colliders: the cut-away below is what handles
  them, and a collider on a wall would stop the eye at a wall the cut-away has already lowered. Desks are not
  colliders either, **chosen and justified**: a camera that dollies into a desk clips it by the near plane and the
  same gesture reversed un-clips it — a transient the viewer chose, as in every orbit viewer; the minimum distance
  (1.2 units from a target that is a desk's point) keeps the eye out of the slab for every target but one chosen
  deep inside a desk's box by a pan, and a four-rays-a-frame collision test against every desk to defend that one
  case is machinery for a state the viewer causes and reverses. Characters are never colliders for the same reason.
  With no collider the library needs no collision support, which is what let § 2.1 choose `OrbitControls`.
- **Walls between the eye and the target are cut away.** Each render, one ray from the camera to the target over
  the wall and band meshes; every wall it meets is **lowered** to `CUTAWAY_H` = 0.15 units (its foot strip — the 2-D
  rule's *every other wall shows only its top edge*, kept as the cut-away's form) over **150** ms (a cut under
  `reduce`), and restored when clear. The decision is pure — `cutaway(camera, target, walls)` → the set of ids — and
  the renderer scales; the probe plants a wall on the segment and reads the set. **Walls are instanced one per wall
  run so each lowers on its own** (per-instance transforms; the two heights two instanced meshes — M1's count), to
  confirm in slice 4. **The band is a wall for this rule**: when the ray meets it — a target near the band seen from
  behind the room — the band lowers to its foot strip **with everything on it**: the elevator's doorway and its
  leaves, the clock, the windows are the band's children and go down with it, and a ride or an A1/A2 door motion in
  flight keeps animating at the foot (nothing stops; the motion is just low), restored with the band when the camera
  comes round. What a viewer behind the room sees is the room open from the back — the desks, the sitters and the
  labels in full. ⚠ **When the band is actually on the ray (B1):** at a polar of 50° the ray from the eye descends at
  40° to the horizontal, so it crosses the band's top (`ROOM_H` = 1.6 units) only where the target is within
  `1.6 / tan(40°)` ≈ **1.9 units** of the band's plane along the view; a target at the floor's centre rotated to the
  back sees OVER the band. The browser leg therefore **places the target first** — a pan to within 1 unit of the band
  — then rotates to the back and reads the band at `CUTAWAY_H`, then to the front and reads it restored (§ 8 item 5);
  a leg that rotated from the centre would pass with the cut-away deleted. Built in slice 4 with the renderer (M6).

- **Desks and characters are never cut away**: they are the facts; a desk between the eye and the target is a
  reason to orbit, and the labels (§ 3.9) stay readable over it because they are screen-space.
- **Labels through walls**: `CSS2DRenderer` hides nothing by occlusion (it tests depth range only); a name behind
  a wall shows through — accepted, and the right thing for a label (the overlap cull of stage 1,
  `cullOverlaps()` over the projected boxes, is reused unchanged for contention).

### 3.8 The 2-D floor page, the routing, the ride and the lobby

- **The 3-D floor is a route of its own — the operator's answer, Q3 A (comment 17079: *beside first*) — and its
  grammar is `/floor/{floor}/3d[/{seat}]` (B2).** The drill-down form `/floor/{floor}/{seat}` already exists (§ 4.4;
  `routes/web.php` L33, the seat route after the floor route) and **its `{seat}` is unconstrained** — the route carries
  no `where`; `Slug::SEAT_ID` (`[a-z0-9][a-z0-9-]{1,47}`) governs where seats are MINTED, not the route — so
  `/floor/aimla/3d` today is a drill-down on a seat named `3d`. The 3-D routes are
  therefore **registered before the seat route** — `/floor/{floor}/3d` (`floor3d.view`) and `/floor/{floor}/3d/{seat}`
  (`floor3d.seat`), `{seat}` left unconstrained as today's is — **registration order alone decides** (m1: today's
  behaviour is kept and no constraint is added), and **`3d` is reserved where seats are minted**: `App\Fleet\SeatTokens::issue()`
  answers a `SeatTokenRefusal` of its own for the seat id `3d` — beside its `notASlug` refusal, L80–84 — which both
  callers that mint, `IssueIngestToken` and `Admin/SeatTokenController`, already return by name (m2; its test is
  slice 4's; `SeatOption` is untouched), so no seat can ever be named by the route's own segment; the route test of § 4 holds the order, and a route-cache build (`php artisan
  route:cache`, which the deploy runs) is the check that both register. Why this
  grammar and not `?view=3d` or `/3d/floor/{floor}`: the drill-down URL must keep the seat as its last segment, which
  every link the page writes already assumes (`routeOf()`), and a query member would be lost by `routeOf()`'s rewrites
  on the first paint and the panel's open and close (`floor/main.js` L186, L277–278, L439). **The route kind is a
  parameter of the hoisted page** — `startFloorPage(document, window, { renderer, route: '2d' | '3d' })` — and
  `routeOf(floor, seat)` and `seatSegment()` (today `floor/main.js` L166 and L178, which read `location.pathname`'s
  segments) derive from it: with `route: '3d'` the floor segment is followed by `3d` and the seat is the segment after
  it, so a reload of `/floor/aimla/3d/mezzanine-solo` opens that desk on the 3-D floor and the first paint's
  `replaceState` writes the 3-D form back, never the 2-D one. The test (§ 8 item 5): the 3-D URL survives the first
  paint and a reload, with and without a seat. The view `floor3d.blade.php` carries the same header, status strip,
  failure statements and list view (`desk/desk-list.js`, shared) as `/floor/{floor}`, the stage in the drawing's place,
  the gesture hint under it; a *2D* pill back to `/floor/{floor}`; **the floor page's camera row gains a separate
  *Floor in 3D* pill to it, and the *3D* pill keeps opening `/building`** (ruling 2 of 2026-11-09 stands; whether two
  pills read well is confirmed at the slice-4 reading); the building view's focus pose gains a *Walk this floor* link
  to it. The ruling says the 3-D view replaces the oblique **for the floor view**; the route swap — `/floor/{floor}`
  serving the 3-D view and `/floor/{floor}/2d` the drawing — is one route change in a slice of its own after the
  operator's reading (slice 7), so that every floor test stays the control until then and the reading decides the
  default. Why not replace from the first slice: the 2-D page is the reading view for every name at any size (stage
  1's *Read this floor* rests on it), the door view and the building slabs draw through its painter, and a default
  nobody has read is the wrong default.

- **The elevator ride** is unchanged in substance: the floor's elevator is a doorway in the band with its leaves
  (§ 3.5), **a control** as today (`role="button"` on the doorway's label, Enter/Space/click → the page's `ride`); the
  ride is the modal cab (`floor/cab-panel.js`, `floor/cab-paint.js`, `wire/ride.js`, `wire/ride-page.js` — *one ride,
  one cab, one module*, `FLOOR.md` § 4.5), its door view in its own projection (`plan-12987`), and the arrival a
  navigation to the destination's **3-D** route when the ride began on one (the destination route is the origin
  route's kind; where the handover `wire/ride-page.js` writes carries it is the slice-6 coder's to read — one member
  of the record it already hands the destination page, not a second record). The lobby is untouched: its room is
  the operator's ruling of 2026-10-20 and its cab reaches `/floor/{floor}` as today.
- **Routing and the walks** are `route.js`'s, unchanged: the lattice is scene px, a walker's point is a plan
  point, `walkerSize()` stays the body the lattice keeps clear (its `w` is the world body's width over `UNIT` by
  construction), and the Y-sort (`floorStackOrder()`) is not called by the 3-D renderer — the depth buffer is the
  order. `walkerSize()` is NOT re-derived: § 3.1 shows the world height falls out of the same arithmetic.
- **The map-projection refusal** stands on the reason § 10.3's bullet already proposes: a map is a plan, the
  renderer gives it depth, a tile that carries depth of its own is a picture inside a projection.
- **`wire/camera.js`, `wire/camera-view.js` and `wire/camera-gestures.js`** stay for the 2-D pages and the building view's pictures, reached on the floor pages only through `floor/painter-renderer.js` (the renderer contract, § 4); the 3-D floor runs none of them.

### 3.9 Labels — names, chips, facts, bubbles, notices

§ 10.4 item 6's *labels over the scene* are, in 3-D, **HTML elements positioned by `CSS2DRenderer`**: per desk a
**name** (`<a>` to the drill-down, `FONT_NAME`), the **status** (the operator's Q4 answer, comment 17086: the
monitor's glyph word and action line — the strings `desk-render.js` decides for the screen, `monitor: 'off'`
drawing the label's *off* form — computed by `floor-world.js` as a label of kind `status` anchored over the monitor's
top, `anchor3d` height `MONITOR_TOP` over `SCALE` plus a line, so it floats above the desk and, being screen-space,
faces the camera from every angle), a **state chip**, the **facts card** (the plated facts the painter draws:
label, currency, lag, gauge, badges, flag, quiet age — the same strings `desk-render.js` decides, the same
`.visually-hidden` rule for culled ones), and the **bubble** (the task title, `task-bubble.js`'s layout), each at
§ 3.1's `anchor3d` height; **within one desk the labels' order is fixed — name, status, chip, facts, bubble — and
the desks are in `readingOrder()`**, so the container's document order is that sequence per desk in that desk
order, the status label second so a screen reader hears the desk's state right after its name; per floor the **notices** and the **overflow strip** as a flowed list under the stage (a
strip is a list of desks without a slot; in 3-D they stand on a bench past the floor's front edge, as the 2-D strip
is a row below it — `placeDesk()` already positions them, and the world lifts them like any desk). Every label is in
the page's accessibility tree in reading order (stage 1's `readingOrder()`), whatever the camera shows; the list
view below the stage is the floor page's own. **Document order is reading order, on every render (the review's
minor):** the renderer inserts each label's element into the CSS2D container itself, at its reading-order index,
BEFORE the first `render()` that would append it — `CSS2DRenderer` appends only an element that is not already a
child — and a desk that arrives after the first render (a fetched seat, a displacement) is inserted at its index
the same way, never appended; the probe plants a late-arriving desk and reads the container's children in
`readingOrder()`. The fallback (§ 7) never constructs a `CSS2DObject`, so the elements flow.

## 4. Modules, and the DOM-free split

| Module | DOM / WebGL | Owns | Driven by |
|---|---|---|---|
| `floor/floor-page.js` (hoist, M6; round 3's M2) | DOM | **the floor page's wiring with the renderer a parameter, and the camera the renderer's** — what `floor/main.js` does today (its `livePage()`, `startFloorScreen()` with the page's `paint`, `ridePage()`, the age ticker, `loadArt()`, the panel, the keys wire, the surface, the reduced-motion read, the lobby href, `routeOf()` and `seatSegment()`): `startFloorPage(document, window, { renderer, route })`, where **`renderer` is the contract `{ create(art, { screen, hooks }) → { paint(frame), refresh(view), paintCab(host, ride), cabTheme(dialog), show(view), current(), fit(), resize(surface) }, gestures(stage, show), keys: { acts, shortcuts, camera } }`** — **`create()` runs after `loadArt()` has answered, as `createPainter()` does today, and it is at `create()` that the camera is wired and `keys` and `gestures` are bound** (the page offers nothing and wires nothing before it; `cabTheme(dialog)` is today's `painter.cabTheme(cab.dialog)`, `floor/main.js` L427, as a contract member) — **the renderer SUPPLIES the camera**: `show` and `current` are the view the page shows and reads, `fit` is *Fit the floor*, `resize` is what the page's `ResizeObserver` calls, `keys.camera` is what `offerKeys()` and the keys wire's gate read, and the painter's camera argument (`painter.view(camera)`, today `floor/main.js` L147) is inside the 2-D renderer — so **`floor-page.js` never imports `wire/camera.js`, `wire/camera-view.js` or `wire/camera-gestures.js`**: the 2-D renderer, **`floor/painter-renderer.js`** (the one home of `cameraGestures()` and `cameraView()` on the floor page, m6), wraps today's `cameraView((camera) => painter.view(camera))`, `screen.camera`/`pan`/`zoom`/`pinch`/`drag`/`zoomStep` and `screen.fitFloor()` → `glideTo`, and the 3-D renderer's `show`/`current`/`fit`/`resize` are the controls' (§ 3.6) and its `gestures` connects them; `gestures` wires the pointer, **`keys` supplies what `cameraKeys()` and `offerKeys()` are given (M4)**; `route` is `'2d'` or `'3d'` (B2); `floor/main.js` becomes the thin caller handing the painter renderer, `floor3d/main.js` the second, handing the world renderer; **no second copy of the page wiring**. **The source-shaped tests that read `floor/main.js` today, each re-pointed (M3)**: `FloorPageWiringTest` (the ids and the wiring → `floor-page.js`; `floor/main.js` holds the one `startFloorPage(` call), `TheCameraMovesTheViewerAndNeverTheFleetTest` and `TheCameraWireIsOneForEveryPageTest` (`cameraGestures(` once → `painter-renderer.js`; `cameraKeys(` once → `floor-page.js`), `TheListViewRendersEveryDeskMemberTest` and `TheKeyPrimitiveIsTheOnlyKeyBuilderTest` (their greps over `floor/main.js` → the three files), `TheArtRouteServesOnlyWhatTheGatesJudgedTest` (`loadArt()`'s specifiers → `floor-page.js`), `CoordModuleWiringTest` and `DrillDownModuleWiringTest` (import-graph roots → `floor/main.js` still the root, the walk reaching both), `BuildingPageWiringTest` (its `createPainter(` without `omit` read → `painter-renderer.js`), `fleet-client-probe.mjs` (no change: it drives the screen, not the page). **Unedited across the hoist, the behaviour control: `tools/design/floor-ride.browser.mjs`'s rows and the floor's `browser-smoke` rows** | every floor page test as the control, re-pointed as named; the browser rows unedited and green |
| `floor/floor-world.js` (new) | none | `floorWorld()`, `worldOf()` (hoisted from `stack-model.js`, which re-exports it), `anchor3d()`, the heights in units (`ROOM_H_U`, `PARTITION_H_U`, `DESK_H_U` from `door-view.js`'s cm over its `SCALE` and `UNIT`), the placement of every desk, sitter, piece and walker | `floor-world-probe.mjs` under `DrivesTheScene`'s shape: every desk, intern, piece and walker of `fx-snapshot-4` and the snack, restroom, intern-walk and turn-back fixtures placed; AT-D3-38 (§ 8 item 2) |
| `floor/floor-walls.js` (hoist) | none | `wallBodies(maps, rooms)` — the edge/partition classification out of `door-view.js`, which calls it | `door-view.selftest.mjs` (control) |
| `resources/characters/creatures.js` (changed, M7) | none | per species `shape` — the half-outline, the features with their anchors, the face/hat/neck/hand anchors — read by `build()` (2-D) and by `creatures-3d.js`; `faceDocument()` | `tools/characters/selftest.mjs`: the sheet pixel-equal per species before and after; `distinct.browser.mjs` unchanged |
| `resources/characters/creatures-3d.js` (new, a manifest row) | none, no Three | `bodyParts()`, `chibiParts()`, `BONES`, `POSES`, `CLIPS` | `tools/characters/selftest.mjs` gains the 3-D legs (§ 8 item 2) |
| `floor3d/view-limits.js` (new) | none | `limits(extent)`: the polar and distance limits, the near plane, the target's box, the entry pose; `cutaway()` | `view-limits-probe.mjs` |
| `floor3d/camera-acts.js` (new) | none (the controls injected) | the renderer contract's `keys.acts` (§ 3.6): `drag(dx, dy, modifiers)`, `zoomStep(n)`, `fit()`, `camera()` — each a call on the injected controls' public methods and an update, to confirm in slice 4 | `camera-acts-probe.mjs` with a stub controls object recording the calls |
| `floor3d/creature-paint.js` (new) | Three | a part list → one skinned mesh over one skeleton per character (§ 3.3's two-call property), the face texture, `POSES`/`CLIPS` → stepping clips, one mixer per character with its time set per § 3.5 — the library's skinning, clip and mixer classes the starting point, to confirm in slices 3 and 5 | the browser leg; `sheet3d.browser.mjs` (slice 3) |
| `floor3d/furniture.js` (new) | Three, `GLTFLoader` | the kit's files loaded once, the kind → file map (data, in `resources/floor/models/kenney-furniture-kit/map.js`, a manifest row), the palette recolour map, one `InstancedMesh` per (primitive, material) per theme | the browser leg; the map's totality under node (every standing `KINDS` member and desk part maps to a file or a `piece3d`) |
| `floor3d/svg-texture.js` (hoist) | DOM | `pictureSvg()` and the SVG → canvas → `CanvasTexture` step out of `building/floor-picture.js`, which calls it | `picture-probe.mjs` (control) |
| `floor3d/floor-paint.js` (new) | Three, `CSS2DRenderer`, `OrbitControls` | the renderer over `#floor3d-canvas`: plane, band, walls, pieces, desks, characters, walkers, labels (inserted in reading order), lights, the controls configured from `view-limits.js`, the target clamp after `update()`, the cut-away applied, the frame loop of § 3.5, `renderer.info` exposed for the budget leg | the browser leg |
| `floor3d/main.js` (new) | DOM entry | `startFloorPage(document, window, { renderer: worldRenderer, route: '3d' })` and the WebGL check — the second caller of the hoisted page, nothing else | `Floor3dPageWiringTest` (the ids both ways, `startFloorPage(` once with `route: '3d'`, `createPainter(` absent, `cameraGestures(` absent, `OrbitControls` imported from the vendored addon only, `listenToKeyEvents` nowhere) |
| `server/routes/web.php`, `resources/views/floor3d.blade.php`, `App\Fleet\SeatTokens` | — | `GET /floor/{floor}/3d` → `floor3d.view` and `GET /floor/{floor}/3d/{seat}` → `floor3d.seat`, both registered before the seat route, which stays unconstrained (m1); `SeatTokens::issue()` refuses the seat id `3d` with a `SeatTokenRefusal` of its own (m2); the 3-D view's chrome — header, status strip, camera row, list view — in **one Blade partial shared with `floor.blade.php`** (hygiene, slice 4's scope) | `EveryModuleAPageLoadsIsVersionedTest::pages()` gains the route; a route test that `/floor/x/3d` resolves to `floor3d.view` and `/floor/x/3d/s` to `floor3d.seat` with `s` the seat, that `/floor/x/s` still opens the drill-down, and that `route:cache` builds; `SeatTokens::issue()`'s refusal of `3d` seen red on a planted acceptance, through both minting callers (slice 4) |
| `App\Support\PublicAsset::moduleMap()` (changed) | — | the one bare entry `"three"` → `url('js/vendor/three/three.module.js')` | `EveryModuleAPageLoadsIsVersionedTest` admits one bare key by name |
| `App\Floor\FloorAssets::SERVED` (changed, M5) | — | `'.glb' => 'model/gltf-binary'` | `TheArtRouteServesOnlyWhatTheGatesJudgedTest`'s row |
| `bin/asset-provenance.py` (changed, M5) | — | `.glb` in `ASSET_BINARY_EXTENSIONS`; Gate 2's fourth clause (§ 3.2) | `bin/asset-provenance.selftest.py`'s clause 4 REDs (one per refusal) and GREEN |

## 5. The libraries — vendoring, pinning, attribution

- **Three.js addons** (`GLTFLoader.js`, `BufferGeometryUtils.js`, `SkeletonUtils.js`, `CSS2DRenderer.js`, `SVGLoader.js`,
  `OrbitControls.js`) are vendored from the same npm package at the same pinned version `0.187.0` under
  `server/public/js/vendor/three/examples/jsm/<path>` — the upstream paths kept, so their relative imports
  (`../utils/…`) resolve unchanged and the body sha256 of each equals jsDelivr's published digest (read 2026-11-11
  from `https://data.jsdelivr.com/v1/package/npm/three@0.187.0/flat`: GLTFLoader `UyrHJuEgA5rLzWz1QyjFesv3tDgXavqmlRJXEm0Rv/E=`,
  BufferGeometryUtils `e9oqi6g2srz2fLk41Lnhu10bG8YWgXED7YWMC1gpbOc=`, SkeletonUtils `sWMqcDIGw9gw3p/L5RVpZ3DQS3GhXua1CvptLDKYyG8=`,
  CSS2DRenderer `JWq/xq4aog64PBDXoOJlNFJ99lhy5NUtpjVL7ngojDQ=`, SVGLoader `KeIC9QQSPWwS0/mNI74UDO36GBztY7prEnnt4GDFyDY=`,
  OrbitControls `5/yi/p52cKgor5soCEZppZCURbfzbx8mzum/jylhwyw=`, each base64 of the sha256 the vendor header records
  in hex); each gets the house vendor header and a `vendor-pin-check.sh` row with the `//` leader stage 1 added.
  **No local edit**: their `from 'three'` is resolved by the import map, not rewritten.
- **The import map gains one bare entry** — `"three"` → **the versioned URL of `three.module.js` and only that** (`PublicAsset::url()`'s `?v=<mtime>` form; never a bare path, which would bypass the versioning every other module carries) — the first
  bare specifier the map carries (`plan-13033` § 5 avoided it by vendoring no addon; the ruling's libraries bring
  it back). `moduleMap()` adds it beside the URL-keyed entries; `EveryModuleAPageLoadsIsVersionedTest` holds that
  the bare key's value is the versioned URL of a file on disk, and that **no page module imports `'three'` bare
  but the vendored addons** (a `ModuleSpecifiers` walk: the page's own code imports by relative path as stage 1 does).
- **`camera-controls` is not vendored** (§ 2.1); its reading is recorded there so the next designer need not repeat it.
- **Attribution.** `docs/ATTRIBUTION.md`'s *Third-party code under `server/public/js/vendor/`* gains the addons under
  the Three.js entry (same package, same `LICENSE`); every `.glb` under `resources/floor/models/` gets a manifest row
  (`licensed` / `https://kenney.nl/assets/furniture-kit` / Kenney / `CC0-1.0` / the retrieval date / sha256) — CC0
  obliges no notice, and `ATTRIBUTION.md`'s rule says so; `ATTRIBUTION.md`'s *Gate 2* paragraph names the `.glb`
  clause.
- **Not vendored:** `three-mesh-bvh` (its trigger, § 2.1), any DRACO/KTX2/Meshopt decoder (Gate 2's clause refuses a
  file that needs one).

## 6. Performance on a modest laptop — the budget and the check that can fail

**The target device** is a laptop with an integrated GPU and a 1,920 × 1,080 screen at DPR 1 — the operator's
reading device is not known to this plan and is not assumed. **"At the cap" is § 1's cap floor**: the shipped
default's 6 desks each staffed with 8 interns — 54 characters — every standing kind placed once, the plane at stage
1's texture cap; the leg builds that fixture (`fx-snapshot-4`'s seats over the default map with the interns filled
to the cap) and prints each figure beside the budget. A floor with more desks costs linearly per desk — the leg
prints the per-desk cost, and an authored floor past the cap is read against it, not defended.

| Figure | Budget | Derivation at the cap | Plant (seen red) |
|---|---|---|---|
| Draw calls (`renderer.info.render.calls`) — **asserted per term, then as a total** | **≤ 260** | **characters** — a seat ≤ 1 call (body and face one material, § 3.3), an intern ≤ 2 (its laptop): asserted as `≤ S + 2 I` over the S seats and I interns drawn — 6 + 96 = 102 at the cap; **furniture** — one instanced mesh per (primitive, material): the 17 standing kinds + the 5 desk parts (the side table with its stools one of them), times the primitives per file the slice-1 record counts (`k`, ≤ 3 expected): asserted as `≤ 22 k` with `k` read off the loaded files; **walls** — instanced per run, one mesh per height, **the lighter cap sharing the run's material** (a vertex tone, not a second material): `≤ 2`; **the fixed at-rest terms** — plane 1 per grid (1), the band's wall 1, the windows' glazing instanced 1, the window sky 1, the clock's dial and two hands 3, the elevator's two leaves 2, its frame and call plates instanced 1, the cab's interior box 1, the restroom's leaf 1, the birdhouse 1, the contact discs instanced 1, the screens 6 — `≤ 20`, each counted from the world's own members; **the transient term**, bounded — A20's ring ≤ 1, each bird in flight 2 (its merged body and its envelope) for at most one bird per desk of the floor (A19 sends one per destination desk; A22 one per crossing) ≤ 2 × 6, A5's cross-fade a second screen texture per swapping desk ≤ 6 — `≤ 19`; **labels, markers, z's, the status**: 0 (DOM) — **the honest sum is 102 + 22 k + 2 + 20 + 19 = 143 + 22 k, 209 at k = 3; the budget is that sum plus a margin of a quarter, 260**, so a regression in any one term reds against its own bound whatever `k` is, and the total catches a term the derivation forgot; the leg prints every term beside its bound | per term: the character merge dropped → characters > S + 2I; furniture instancing dropped → > 22 k; wall instancing dropped, or the cap a second material → > 2; a second cab box → the at-rest terms > 20; a bird drawn as four meshes → the transient term > 19 on a fixture with a post to every desk; and the total |
| Triangles (`renderer.info.render.triangles`) | **≤ 150,000** | a seat's creature ≤ 2,500 (a lathe of 24 × 16 plus features), a chibi ≤ 1,200, a kit piece ≤ 800 (read per file at slice 1), a desk set ≤ 2,000: 6 × 2,500 + 48 × 1,200 + 17 × 800 + 6 × 2,000 ≈ 98,200, plus walls and the plane under 1,000 | the lathe's segment counts ×4 in a served copy of `creatures-3d.js` → the characters alone past the budget |
| Texture memory (the leg **walks the scene's materials** and sums each texture's image width × height × 4 × 4/3 — not the renderer's texture count, which is a count) | **≤ 128 MB** | one plane texture per room grid and one for a hallway — the cap floor has one grid — **rasterised at one texel per scene px up to the texture cap** (stage 1's rule, `textureSize()`): at the cap 4,096 × 1,984 → ≈ 43 MB (the default's 1,784 × 696 is ≈ 6.6 MB; a planned floor of N rooms counts N), the band 4,096 × 160 → ≈ 3.5 MB, **the faces with their blink frame — two textures per character** (m4): 54 × 2 at the 256 px bucket ≈ 37.8 MB plus the nearest desk's 9 × 2 at 512 px ≈ 25.2 MB, 6 screens at 256 × 160 ≈ 1.3 MB (the status label is DOM and holds no texture) — ≈ 111 MB, the budget that sum plus a margin | every face at the 512 px bucket → ≈ 199 MB |
| `.glb` transfer | **≤ 2 MB** for the cap floor's files | the sum of the mapped files' bytes, read at slice 1 | a 3 MB dummy `.glb` added to the kind map → over |
| Vendored JS beyond stage 1's | **≤ 0.5 MB** | the six addons' sizes (`du -b` over them: GLTFLoader and OrbitControls the largest, ~120 KB and 41 KB) | — a sum the coder prints; no plant: it cannot fail without a file being added, which the pin check already judges |
| Main-thread work per render (`performance.measure()` round `floorWorld()` + the mixers' `setTime` + `render()`) | **≤ 8 ms** on the pinned shell | printed per render | — printed, not asserted: the shell is software GL and measures nothing about a device |
| Frame rate | **≥ 30 fps sustained with every held loop running; 60 fps at rest** | read by hand on the named laptop with the browser's performance panel; recorded on the card at slice 4 | not CI-gated; a device reading |

**The check that can fail**: the browser leg (`tools/design/floor3d.browser.mjs`, § 8 item 5) asserts the draw-call,
triangle, texture and transfer budgets on the cap fixture after a settled frame, reading `renderer.info` off the
page's own renderer and the materials' textures, and each row's plant above is seen red by `--selftest`. The two
rows with no plant are printed, never asserted. The library's `info` members are the starting point, to confirm in
slice 4.

## 7. Accessibility, reduced motion, and the plain-list fallback

- **Every fact is text in the tree**: the labels (§ 3.9) in reading order and the floor page's list view below
  the stage (`desk-list.js`, the same `<details>`), so a screen reader reads the same desk the floor page gives.
  The canvas is `aria-hidden`; the stage is the `role="group"` and the one tab stop with `offerKeys()`'s shortcuts
  (§ 3.6's set — every one moves something here).
- **Reduced motion**: every § 6.2 row in its reduced form (an effect's `frames: 0`; a held rendering's `motion: false`, which sets no clip time — § 3.5), no camera
  damping (`enableDamping` false — the library has one smoothing, so there is no second knob to zero), every camera
  move a cut, no cut-away tween; read once at construction.
- **Colour is never the only carrier** (§ 4.5): the state is the pose and the chip's text; lighting treatments
  (`dimmed`, `dark`, `desaturated`) are material changes AND the chip's words.
- **No WebGL 2 — the statement and a link, never a redirect (m9)**: the route shows the statement (*this browser
  offers no WebGL 2; the floor is drawn as a picture on the 2-D floor page*) with a link to `/floor/{floor}`, the list
  view in flow, no renderer constructed — the 2-D page is the fallback, which is one reason it stays (§ 3.8); a
  redirect was rejected because a viewer who opened the 3-D URL for its list would be bounced from it with no word
  why, and a page that explains itself costs one paragraph. `FLOOR.md` § 4.4's row says the same. A lost context: the
  statement and the list, the renderer stopped; a restored one rebuilds the scene from the last frame (textures
  re-uploaded from their kept canvases, the kit re-parsed from its held `ArrayBuffer`s).

- **Touch**: one finger rotates, two fingers pan and pinch-dolly (`OrbitControls`'s `touches` defaults), the labels
  tappable links; the hint line says so.
- **A label behind the eye** is `display: none` by the library (§ 10); the list view carries every desk regardless.

## 8. How the tests pin it — property, plant, observable

Under `plan-13033` § 12 item 21's rule: each leg names the PROPERTY, the PLANT and the OBSERVABLE; fixtures,
viewports and timings are chosen by running the leg; a precondition is derived from the model over the fixture and
is a control failure if absent; every slice's acceptance is *every plant run and seen red in the PR, the run
recorded on the card*.

1. **The hoists are behaviour-preserving — slice 2's and slice 3's (m7).** *Slice 2*: `door-view.selftest.mjs` (walls), `stack-model-probe.mjs` (`worldOf()` re-exported), every floor page test re-pointed as § 4 names (`floor-page.js`, `painter-renderer.js`) with the floor's browser rows unedited. *Slice 3*: `picture-probe.mjs` and the building browser leg (`svg-texture.js`), `sheet.browser.mjs`'s per-species pixel equality and `distinct.browser.mjs` (the species data). Every existing test is the control; plants: a hoisted
   function given a changed sign → its control reds; one species' outline point moved → that species' sheet capture
   differs; `routeOf()` given the 2-D form under `route: '3d'` → the route leg of item 5.
2. **AT-D3-38 — the floor in 3-D is the scene's third renderer** (added to `FLOOR.md` § 11 by slice 2 with its
   Appendix B row, § 11 item 8): `floor-world-probe.mjs` over `fx-snapshot-4`, `fx-intern-walk`, `fx-snack`,
   `fx-turn-back` and AT-D3-20's overflowing run. Properties: every desk the scene places has a world desk at
   `worldOf()` of its anchor; **its sitter on the band side** — the sitter's `z` less than the slab's and the slab's
   drawer face toward the sitter (M4's definition); every intern a chibi at its stool; every standing tile a piece
   at its cell's floor line; every wall cell a box at the height its footprint position gives (edge/partition);
   every in-flight effect a walker whose `pointAt()` at three sampled instants **outside its cab legs and its
   inside-the-restroom span** (`cabStep()`'s legs and `restroomExit()`'s span are legitimately off the lattice — the
   walker is in the cab or behind the door) lifts to a point inside the extent and on open lattice; every label's
   `anchor3d` height is the 2-D offset above the foot (a bubble higher than a z, a chip at height 0); the labels
   one per desk element the painter would plate, with the painter's own strings; **every desk with a monitor has one
   `status` label whose text equals the screen strings `desk-render.js` decides for that desk — the glyph word and
   the action line, the *off* form for `monitor: 'off'` — anchored above `MONITOR_TOP`, and a desk with no monitor
   (the strip's bare desks included) has none** (the Q4 answer's label; plant: the label reading the chip's state
   word instead of the screen's → the equality reds on a `catching_up` desk, whose screen shows the replay marker
   and whose chip reads the currency); the frame holds no camera and no ride; **nothing the world carries is absent
   from the scene** (set equality of desk keys, intern keys, piece cells, effect ids both ways). **The clock (B1,
   M2)**, under node with the pose tables and a stub mixer that records the time it is set to: at a chosen `T` the
   pose the renderer selects for a character equals the pose its clip names at `⌊T × fps⌋ mod length`, with `T`
   § 3.5's (the offset is already inside it) and `fps` from `frame_interval_ms` (plant: the offset dropped from `T` →
   the pose at the paint is the clip's frame 0 for every seat); **two seats in the
   same held state read at one instant show different pose ids when their `loopOffset(key)`s differ — keyed by the
   seat key or the intern key** (the probe
   searches the fixture for such a pair and fails as a control if none exists); an edge clip past its span holds its
   last pose; a `frames: 0` effect shows its still pose at every `T`; **a held rendering with `motion: false`** —
   `heldRendering()` under `reduce`, or a dimmed treatment — selects its form's still pose at every `T` and the stub
   mixer records no time set (plant: the loop keyed on the row's class instead of `held.motion` → a reduced desk's
   mixer is set and its pose steps); **a held loop's time starts at the paint**: the pose read one frame interval after
   a second paint is the clip's frame 1 plus the offset, not the frame the first paint's elapsed time would give
   (plant: `paintedAt` not reset on paint → the frame index runs on). `tools/characters/selftest.mjs` gains: **every
   `SPECIES_KEYS` member has `shape` data** (the ruling's *every species*), every part list's foot at `FOOT` and its
   height within the frame, the chibi's head share ≥ `CHIBI_HEAD_SHARE` measured on parts, every pose of `DESK` in
   `POSES`, every `CLIPS` entry naming poses that exist, the walk's three poses distinct; and **front-silhouette
   distinctness is measured as the 2-D one is** — `tools/characters/sheet3d.browser.mjs` captures every species'
   standing body from the front in the pinned shell and **`distinct.browser.mjs`'s own IoU + ΔE measure** (its
   `IOU_BAR` and `DE_BAR`, `FLOOR.md` § 12's *Species distinctness bar*) is run over those captures through a
   `--source 3d` option, so one bar and one measure judge both generators. REDs, each seen red: a species' `shape`
   removed → the totality leg; `worldOf()`'s `z` sign flipped → the sitter stands on the front side (the facing
   leg); a wall's edge/partition swapped → the height leg; a walker lifted from its anchor instead of `pointAt()` →
   the sampled points equal; a label whose text is re-fitted → the string inequality; a camera member on the frame
   → red; the chibi's head scaled down → the share leg; a bubble's anchor given depth for height → the height leg; a
   species' outline replaced by another's → the 3-D distinctness red on that pair.
3. **The view limits, the near plane and the cut-away** — `view-limits-probe.mjs`: `limits(extent, surface)`'s polar
   max under 90°, `minDistance` ≥ one character height, the near plane 0.05, the box the extent, the fit distance
   changing with the surface's aspect (plant: the aspect ignored → a tall stage's fit equals a wide one's); **the
   camera's `y` and the near plane's four corners' `y` are positive at every target in the box and every polar up to
   the limit at the minimum distance**, over a grid of both (plants: the limit set to 92° → a negative `y` at the
   floor's edge; `near` set to 0.1 → a corner under the plane at the limit); `cutaway()` names exactly the walls a
   segment meets (plant: a wall on the segment → in the set; moved off → not; the band from behind with a target
   1 unit from it → in; the band from behind with the target at the centre → NOT in, the B1 derivation as a control).
   `camera-acts-probe.mjs`: each key maps to the controls call § 3.6 names (plant: `ArrowUp` reaching pan → the tilt
   leg reds; a Shift-arrow reaching rotate → the pan leg reds). **Gate 2's `.glb` clause** — `bin/asset-provenance.selftest.py`:
   **one RED per refusal** — an external buffer `uri`, an external image `uri`, an image that is not a PNG, an image
   over 1,024 px, more images than **`GLB_IMAGES_MAX` = 4** (pinned: a kit piece carries one palette image or none; a
   file with more is not a furniture piece), an `extensionsRequired` entry, a compression extension named, a non-GLB
   renamed `.glb` — and a GREEN control over an admitted file.
4. **Page wiring** — `Floor3dPageWiringTest`: ids both ways; `startFloorPage(` once in each caller with its `route`,
   and the wiring in `floor-page.js` only; `createPainter(` absent from `floor3d/`; `cameraGestures(` absent from
   `floor3d/`; `cameraKeys(` and `offerKeys(` in `floor-page.js` only; `listenToKeyEvents` nowhere; every `import …
   from 'three'` under `public/js` is in `vendor/three/examples/`; the import map's one bare key resolves to a
   versioned URL of a file on disk; the `.glb` route row; the route test of § 4's routes row.
5. **The browser leg** — `tools/design/floor3d.browser.mjs` on the pinned shell, its still rows in `browser-smoke`:
   `/floor/aimla/3d` served through `render-floor-view.php`, the fixture run answering. Still properties: no
   uncaught exception; a WebGL 2 context; **the URL** — `/floor/aimla/3d` after the first paint's `replaceState`, and
   `/floor/aimla/3d/<seat>` after a reload of that form with the desk's panel open (plant: `routeOf()` ignoring the
   route kind → the 2-D form written at the first paint; `seatSegment()` ignoring it → the seat read as `3d`); the
   polar angle within 0.5° of 50° at entry and after *Fit* (plant: the entry set by azimuth and distance alone → the
   library's default polar); the CSS2D container's children in `readingOrder()` — per desk name, status, chip,
   facts, bubble — after three renders **and after a late-arriving desk** (a `seat.delta` admitting a seat after the
   first render; plant: the late label appended instead of inserted → out of order); one element per label and the
   name a link; **the status label reads the same text from the front and from behind the desk** (the camera rotated
   180° about the desk: the element's `textContent` and its on-screen box unchanged in size — what *faces the camera*
   means for a screen-space element; plant: the status drawn into the monitor's texture only, no element → absent
   from behind); the kit's files requested once each; § 6's four asserted budgets on the cap fixture with their
   plants; **the top-left desk's face is not blank, read with `readPixels` off the WebGL canvas after a render**
   (a 2-D `getImageData()` cannot read a WebGL canvas; plant: the face texture dropped → the head's pixels read the
   body tone); every desk key a row of the list view while at least one label reads `display: none`. Moving
   properties: a drag down past the floor's edge leaves the camera's `y` positive (plant: `maxPolarAngle` widened past
   90° → `y` negative under the same drag); a pan past the extent leaves the target inside the box (plant: the clamp
   dropped); **a vertical pan gesture leaves the target's `y` unchanged and moves it along the floor** (M5; plant:
   screen-space panning → `y` changes); a wheel at the dolly limit scrolls the page and moves no distance; **a pan to
   within 1 unit of the band, then a rotation to the back** drops the band and its leaves and clock to `CUTAWAY_H`,
   and a rotation to the front restores them (plant: the cut-away's ray cast dropped; control: the same rotation
   from the centre must NOT drop it); **the frame loop stops**: after every clip has ended and the controls are still,
   no frame callback runs for a second (counted through the protocol; plant: the stop condition dropped → callbacks
   keep coming); **it restarts on a late resource** — a kit file answered after the first paint is drawn without a
   gesture (plant: the resource-ready restart dropped → the piece absent until a drag); **it wakes on a drag under
   damping** and draws to rest (plant: the controls' change not wired → the view freezes mid-settle); **with the
   camera still and one held loop running, at most `LOOP_FPS` frames are drawn in a second** (plant: the step sleep
   dropped → the shell's frame rate); the walk fixture's walker changes position between two frames with motion on
   and not under the shell's reduced-motion emulation, and **its pose at a frame is the clip's pose at that frame's
   `T`** (plant: the mixer advanced by a delta → a stalled frame drifts the pose); a Tab onto a desk label moves the
   target to its desk. `--selftest` plants each seen red; `--out` saves a picture for the operator's reading.

## 9. The slice plan, for the coder

Each slice one PR to `dev`, `card#13033 [no-close]: …`, with `docs/changelog.d/13033-<slug>.md`; the card closes
on the last only when the operator has read the sandbox. Every slice: its plants seen red, the run on the card.

1. **Libraries and provenance** — the six addons vendored and pinned (§ 5), the import map's bare entry with its
   test, **Gate 2's `.glb` clause** with one RED per refusal and a GREEN, `FloorAssets::SERVED` gaining `.glb` with its
   test row **and `FLOOR.md` § 10.1 clause 1's first bold run reshaped to carry `.glb`** (m3:
   `TheArtRouteServesOnlyWhatTheGatesJudgedTest` reads that run against `SERVED` both ways, L252–270, so the
   document's list and the route's move together — this revision keeps `.glb` outside the run so the test holds on
   the shipped tree until the slice), the Kenney Furniture Kit downloaded, its files, formats, material form and primitives-per-file (`k`)
   listed on the card, the standing `KINDS` + desk-set mapping written (`resources/floor/models/kenney-furniture-kit/map.js`)
   with its totality test — **the scope of totality: every standing `KINDS` member and every desk part maps to a kit
   file, or to a theme's `piece3d`, or is listed in the map as `missing` with the kind named; the test reds on a
   member in none of the three, and the card records the `missing` list as the first-party work slice 4 owes** —
   every adopted `.glb` a manifest row and read through the clause, `ATTRIBUTION.md`'s sections. Controls: the
   provenance selftest, the art route test, the import-map test — no hoist is in this slice.
2. **The page hoist, the world and the limits** — `floor/floor-page.js` and `floor/painter-renderer.js` (M6, round 3's M2) with every floor page test as its control;
   `floor-walls.js` and `worldOf()` hoisted; `floor/floor-world.js`, `floor3d/view-limits.js`, `floor3d/camera-acts.js`
   (the keys wire's `shift` member); AT-D3-38 in `FLOOR.md` § 11 with its Appendix B row; tests 1 (the hoists), 2
   (the world half) and 3.
   **As built (2026-11-11), where it departs from the text above, each the coder's to argue at review:** (a) the
   renderer contract is two calls, not one — `camera(screen)` at once (`show`, `current`, `fit`, `resize`,
   `gestures(stage)`, `keys.acts`/`keys.shortcuts`) and `create(art, hooks)` once the art answers (the drawing:
   `paint`, `refresh`, `paintCab`, `cabTheme`) — because the floor page wired its resize observer, gestures, keys and
   *Fit* at load, and binding them only at `create()` would change when a resize before the art answers reaches the
   screen; and `offerKeys()` reads the frame's camera on both routes (whether a floor is framed is the screen's), so
   the contract carries no `keys.camera`; (b) the walls reach the world through the scene — `buildScene()` gains a
   `walls` member from `floor/floor-walls.js`'s `wallBodies()` (its second caller beside `doorView()`), so `floorWorld()`
   reads the scene alone and takes no `frame`, `art` or `theme` (`floorWorld(scene)`); (c) `UNIT` and `topOf()` move
   with `worldOf()` to `floor/floor-world.js`, the stack model re-exporting all three, so the floor tree does not import
   the building tree back; (d) `anchor3d()`'s foot is the sitter's rect's bottom (its feet), not `trail_foot.y` (§ 3.1
   above), which is the thought trail's foot under the bubble, at the head; its anchor is the element's CENTRE, the point
   a `CSS2DObject` is centred on; (e) the walls' top-row cells and the cells under a desk's slab are no bodies in the
   world either — the classification hoisted is the door view's whole rule, and the band stands in for the top row;
   (f) the sitter stands half the slab's depth behind the slab's back edge (no scene figure places a chair in plan);
   (g) the `notice` label kind is not carried — § 3.9 puts a floor's notices in a flowed list under the stage; (h) the
   building acts' per-pixel turn was a formula, not a constant, so `wire/camera.js` gains `turnOf(px, width)`.
3. **The creatures** — the species `shape` data moved out of `build()` (M7) with the sheet pixel-equal and the
   distinctness legs green; `creatures-3d.js` with `BONES`, `POSES`, `CLIPS`, `bodyParts()`, `chibiParts()`;
   `faceDocument()`; **`floor3d/creature-paint.js`** and the `svg-texture.js` hoist (it needs them to draw; m7: this
   hoist's controls are this slice's); **the intern-specific `renderer.info` leg** — one seat drawn alone is ≤ 1
   call, one intern with its laptop ≤ 2, read off a page holding one character (round 3's M3);
   `tools/characters/sheet3d.browser.mjs` capturing every species from the front, the side and above to a PNG
   **for the operator's reading of the bodies** — the reading at which the toy look (§ 3.2's accepted cost) is judged,
   before the page exists — and the 3-D distinctness run; test 2's character half. Split in two PRs if the reviewer
   asks (the kit and the animal kind, then the rest), but the totality leg is green before slice 4.
   **As built (2026-11-11), where it departs from the text above, each the coder's to argue at review:** (a) the shape
   data is `profiles` (the half-outlines `c.sym()` mirrors), `features` (each named, of a kind — `path`, `stroke`,
   `ell`, `leaf`, `frond`, `scallop`, `lumps`, `lobes`, `outline`, `star`, `spines`, `rect` — with a `side`), the anchors
   and `tones`; what stays inline in a `build()` is paint (an inner ear, a stripe, a whisker), never a part's outline;
   the paws' and feet's colours moved too (`toneOf()`, one reading for both generators), and so did two pieces of face
   paint — the raccoon's mask and the mole's nose, marked `face` and drawn into `faceDocument()` — because without them
   the 3-D distinctness bar read cat / raccoon and mole / potato too close; (b) `bodyParts()` answers an object, not a
   bare list — `parts`, the `joints` the bones stand at (they differ by species), the `face` patch over `faceBoxOf()`'s
   rectangle, and `size`, `tilt`, `top`, `chin`, `tall` — and a part's shapes are also `tube`, `cone`, `cylinder` and
   `torus` (the hats, neckwear and extras are small part groups); (c) the hoist's control is byte equality, stronger
   than pixel equality: `tools/characters/digest.mjs --write` before and `--against` after holds every species'
   documents (every frame, expression, sleeper and intern document over a corpus of keys) byte-equal, its plant a moved
   outline point reddening that species alone, and `sheet.browser.mjs`'s capture compared byte-identical — the digest
   file is the caller's, never committed; (d) `svg-texture.js` hoists the SVG-onto-canvas step alone (`rasterise()`):
   `pictureSvg()` has no second caller and stays the floor picture's, and a `CanvasTexture` is one line each caller
   makes; (e) `CLIPS['intern-type']` is the 2-D sway's four loop frames (centre, left, centre, right — `INTERN_LOOP`),
   not two, and `CLIPS` carries a one-entry clip for each `DESK` pose that draws a body and for `stand`; (f) the blink
   is two atlases a character — the face open and shut, each holding the laptop lid's back and the white block — the
   material's map swapped, so § 6's two face textures a character hold; (g) the node legs are `selftest.mjs` § 8c, and
   AT-D3-38 is split into its world half (row 51) and its characters half (row 52); (h) the triangles a creature draws
   read above § 6's per-creature estimate for the interns — `node tools/characters/sheet3d.browser.mjs --calls` prints
   them (read 2026-11-11: seats median 2,352, interns median 2,296, the sloth the most at 7,552 and 8,292, against
   2,500 and 1,200) — so § 6's triangle total at the cap is re-derived with this reading in slice 4, where it is
   asserted; (i) **the body's paint — the operator's Q6 answer A (2026-11-12): keep the code-built bodies, and carry the
   2-D body paint onto them.** The first build carried each species' outline, parts and paw colours but not its body
   paint, and `distinct.browser.mjs --source 3d` read pair after pair at or over the bar (cat / raccoon, mole / potato,
   apple / lemon, then owl / panda at IoU 0.836, ΔE 9.0) — one class, the paint, so it was stopped and put to the
   operator rather than patched pair by pair. As built on the answer: `paintDocument()` (`creatures.js`: the frame's
   back, body and front layers alone, in the part list's own rectangle, `bodyParts()`'s `paint.box`) is rasterised once
   a character and projected onto every part of the creature from the front — each vertex sampling it where its rest
   point stands, `PAINT_INSET` inside the outline so a side does not wear the outline's stroke, an intern's squash
   undone — through a second sampler injected into the SAME toon material (a seat stays one call, an intern two), fading
   out only over the surfaces facing back (`paintWeight()`), so a belly or a nose is not printed on a creature's back;
   its paws, feet, what it wears and its laptop keep their own colours. The paint texture is as tall as the face atlas
   (its width the rectangle's aspect), so `--calls` prints each creature's texture memory beside its draw calls. In the
   same change the flat bodies (banana, lemon, watermelon, mango, kiwi, starfruit) are extruded `SLAB` thick of the
   lesser of their width and height (0.62, from 0.42), their bevel started inside the outline so the front silhouette
   stays the 2-D outline. **The gate is passed**: every pair of the 48 species is apart, the closest mole / potato (IoU
   read 0.820 against `IOU_BAR` 0.82, ΔE 5.6 — apart by silhouette, at the bar's edge), and the check gains a fifth
   control, the paint dropped from every species, which reads owl / panda too close again (ΔE 9.0 bare, 14.8 painted).
4. **The page** — the routes (B2) and their test, the view, `floor3d/main.js` as the hoisted page's second caller,
   `floor-paint.js`, `furniture.js`, the labels through `CSS2DRenderer` in reading order, `OrbitControls` configured
   from `view-limits.js` with the clamp, the explicit pan mode and the entry pose, the keys through the contract,
   **the frame loop with its stop, its restarts and its `LOOP_FPS` rest rate, and the cut-away with its moving row**
   (M6: they are the renderer's own frame machinery and cannot wait for the clips), the lights, the fallback, **the renderer's `paintCab(host, ride)` a bare contract member until slice 6** — no
   statement and no stub: nothing reaches it before slice 6 provides the doorway control that starts a ride on this
   page (m-6) — the shared chrome partial (hygiene), test 4,
   the browser leg's still rows and the camera, route and loop moving rows, the budget leg; the sandbox deployed and
   screenshots on the card — **the operator's reading** of the floor, where the two pills and the z-fight read are
   confirmed.
5. **Animation** — every § 3.5 row as clips and material changes, the mixer clock with the per-seat offset, the
   elevator and restroom leaves, the birds, the screens, the reduced forms; the browser leg's clip rows (the pose at
   `T`, the drift plant); AT-D3-23, -31, -32, -33, -36's fixtures replayed through the world probe (their walks lift to
   legal points — the node half; the drawing, by eye); `FLOOR.md` § 6.3's amended bullet is this slice's rule.
6. **The ride from the 3-D floor** — the doorway as the control, the cab modal, `ride-page.js`'s route kind, the
   arrival on the destination's 3-D route; `TheFloorsRideIsNavigationTest`'s shape on the new page.
7. **The default** (gated on the operator's reading — Q3's answer A was *beside first*): `/floor/{floor}` serves the
   3-D view and `/floor/{floor}/2d` the drawing, the pills swapped, `FLOOR.md` § 4.4's table amended — one route change.
8. **The building in volume** (the operator's *eventually*, comment 17050; its own operator gate before it starts):
   `/building` stands N `floorWorld()`s at `y = storey × ROOM_H_U` plus a slab's thickness — **the real storey replaces
   stage 1's exploded, visibility-derived storey** (§ 12 item 7: that derivation retires with the pictures), the
   slab's picture replaced by the floor itself; `LOD` per character (the full body near, a merged low body far,
   nothing past the fit distance) and `InstancedMesh` across floors for the kit; the cut-away rule extended to the
   slab above (the floor above a focused floor is lifted or faded — the dollhouse's removable storey); the camera
   `OrbitControls` with the stack's box as its clamp, the overview and per-floor focus poses becoming set poses and
   *Walk this floor* a dolly into the same scene (no navigation); the labels through `CSS2DRenderer`, retiring
   `building/labels.js`'s docking; the budget re-derived for N floors (§ 6 × N with LOD's reductions, measured).
   Gated at its own Appendix B row.

## 10. What could falsify it, and what is not defended

- **The toy look is wrong.** A lathe-and-capsule creature may read as a bead toy rather than the sheet's creature.
  Named, and gated: slice 3's sheet capture is the reading, before any page work rests on it; the fallback is (c)
  for the characters, which this plan prices but does not design.
- **Two vocabularies on one floor** (§ 3.2): Kenney's chunky furniture beside the generator's creatures. The
  recolour and one toon material are the reconciliation; the reading at slice 4 judges it; a first-party furniture
  slice is the exit.
- **The kit's contents are unverified** (§ 3.2): the mapping may leave many kinds to first-party pieces; the slice-1
  card record is where the gap is measured, and the first-party `piece3d` path exists from slice 1.
- **The camera inside a desk** (§ 3.7, chosen): a target panned deep into a desk's box and the eye dollied to the
  minimum shows the desk cut by the near plane until the viewer moves. Not defended; read on the sandbox.
- **The face texture at distance**: a 256 px face on a head 1 unit tall at 20 units is under a device px — fine; at
  `minDistance` it is ~0.5 device px per texel on a 1,080 p stage — the 512 bucket for the desks within 3 units of
  the camera; measured on the shell, read by eye on the sandbox.
- **Walls lowered under a camera that orbits fast** flicker between cut and restored; the 150 ms tween and a
  hysteresis of one frame are the defence; read on the sandbox.
- **`CSS2DRenderer` sets `display: none` on a label whose point is outside the camera's depth range** (read in the
  addon, § 2.1) — a desk behind the eye leaves the accessibility tree while the camera stands inside the floor,
  which is the `hidden` stage 1 refused for its labels. The defence is the floor page's own list view under the
  stage (`desk/desk-list.js`), which carries every desk whatever the camera does, as it does on the 2-D page beside
  the drawing's nameplates; the browser leg holds it (§ 8 item 5). Not defended: a browser that reorders
  absolutely-positioned elements in its accessibility tree (none known).
- **The import map's bare entry** is a first; a browser that supports import maps supports bare keys (they are the
  feature's purpose); no new population.
- **Texture memory at the cap × N floors** is slice 8's, re-derived there.
- **Deliberately not defended**: two viewers' cameras (navigation is never state); a floor so wide the far wall is a
  line (the viewer dollies); a kit file replaced upstream (the manifest's sha256 is the pin); an authored floor with
  more desks than the cap floor (§ 6's per-desk cost is printed, not bounded).

**Residual risks, one line each, and the slice test that owns it.**
- A character cannot be drawn in two calls with its face and laptop → § 6's budget moves; owned by slice 3's
  `renderer.info` read over one character.
- The kit carries more primitives per file than three (`k`) → the draw-call figure moves; owned by slice 1's card
  record and slice 4's budget leg.
- A library default (pan mode, damping, the wheel at the limit, the `info` members) is not what the file was read
  to say → owned by slice 4's page-configuration test and the browser leg's pan, wheel and budget rows.
- The per-seat offset leaves two seats in phase by coincidence on a fixture → owned by slice 5's two-seat leg, which
  searches for a differing pair and fails as a control if none.
- A label behind the eye leaves the tree → owned by slice 4's list-view row.
- The band's foot strip hides a ride's leaves at the moment a viewer behind the room wants them → owned by the
  slice-4 reading; the leg only holds that the motion continues.
- A z-fight at the face patch, the disc or the status surface → owned by the slice-4 sandbox read; no node test
  sees it.
- A 3-D URL written in the 2-D form, or a seat read as `3d` → owned by slice 4's URL rows and the route test.
- The Gate 2 clause admits a `.glb` whose embedded image is not a PNG → owned by slice 1's RED for the image
  format.
- The cap floor under-represents an authored floor → owned by nothing but the printed per-desk cost; stated, not
  defended.

**Cross-boundary conditions by name (canon #7).** Upstream Three.js and its addons, and Kenney's kit → this
repository: each DECLARES its licence and bytes in its package or page; this repository CHECKS the bytes
(`vendor-pin-check.sh` against the published digests; the manifest's sha256 per `.glb`, and Gate 2's clause over
what the file contains) and the licence string against the closed allowlist; what neither end can verify locally is
the behaviour in the viewer's browser (a real GPU, Safari's and Firefox's WebGL 2 and pointer events, a real
trackpad's inertia under `OrbitControls`), which the browser leg exercises on one pinned engine and names as
unverified for the rest. Intra-repo: the scene → the world → the renderer is one code path, and AT-D3-38's set
equalities are the check that the renderer shows the floor the floor page shows.

## 11. FLOOR.md amendments — what revision 2 applied, and what waits

Applied by **revision 2** (this commit), each a diff to the owning text with its reason recorded there:

1. **§ 10.4's projection bullet** — the stage-2 paragraph now states the 3-D floor's projection: a perspective
   camera above the floor, any heading, elevation from just above the plane to straight down, never below; items
   1–6 re-read for it: (1) the plane is a texture on `y = 0` — unchanged; (2) *face-on* becomes *a body, oriented*
   — the sitter on the band side of the desk facing the front (M4, the 2026-10-06 ruling's side), the chair behind
   its sitter, **the monitor facing its sitter and the laptop its intern, with the status floating as a camera-facing
   label over each desk — the operator's answer to Q4 (comment 17086), which applies to the 3-D floor alone; rows 59
   and 87 keep binding the 2-D floor**; (3) the Y-sort
   is the depth buffer; (4) every wall is a box at the door view's two heights and the cut-away lowers the ones
   between the eye and the target to 0.15 units over 150 ms; (5) one light from the top-left — unchanged; (6) labels
   over the scene — unchanged, through `CSS2DRenderer`. Supersedes: row 49's oblique FOR THE 3-D FLOOR ROUTE; the
   2-D route keeps row 49 while it exists (slice 7 decides which route is `/floor/{floor}`).
2. **§ 4.5's camera bullet** — the 3-D floor's camera: `OrbitControls` **reading the pointer itself** (the one page
   off the house gesture wire), the limits of § 3.6 (88°, 1.2 units, 50°; the near plane's figure stays this plan's,
   § 12 item 2, since § 12 of `FLOOR.md` binds a figure to a sentence and the sentence there says only that the
   corners stay above the plane), right-drag pan, the keyboard map through the house keys wire, reduced motion. Supersedes: nothing on the 2-D pages (row 39 binds them); item 49's
   *right-drag is the browser's* is amended for the 3-D floor by the operator's Q2 answer.
3. **§ 4.4's route table** — `/floor/{floor}/3d`; slice 7 swaps the rows.
4. **§ 1.2's library row** — the adopted addons named beside Three.js.
5. **§ 10.1 clause 1** — `.glb` admitted, with the fourth clause's four checks, landing with slice 1's gate change.
6. **§ 10.6's theme model** — the optional `piece3d` export.
7. **§ 12** — rows *Floor 3-D camera* (88°, 1.2 units, 50°) and *Floor 3-D cut-away* (0.15 units, 150 ms), each
   citing the section that carries the figure (`verify-floor.py`'s G4 holds each integer there as a whole token).
8. **§ 6.3's timer bullet** — the renderer's frame loop and its stop (M2).
9. **§ 13 row 119** — the 2026-11-11 ruling, decided, with § 2's rejected alternatives; **§ 14 item 49** — the lead
   line corrected to DECIDED, the three answers recorded with their comment ids, the relayed rulings sourced to
   comment 17050.

Waits for a slice, with its reason:

- **§ 11's AT-D3-38 and its Appendix B row** — added by **slice 2**, which lands the probe they name:
  `tools/design/verify-floor.py`'s G5 holds every acceptance test to a suite and its fixture names both ways, so a
  test written ahead of its suite reds the gate on this commit. **Landed with slice 2 (2026-11-11) as Appendix B row
  51** — rows 49 and 50 were taken by card#13117 slices S4 and S5 in the meantime — and as AT-D3-38's world half; its characters' half
  is added by slices 3 and 5.
- **§ 4.4's row swap** — slice 7, after the operator's reading (Q3 A).

## 12. Seat rulings — reversible by the operator, each one constant or one rule

1. The unit, the frame and `worldOf()` are stage 1's (§ 3.1); the heights are the door view's cm over `UNIT`; the
   depth/height split is `anchor3d()` — no new figure.
2. `maxPolarAngle` 88° from vertical; `minDistance` one character height; `maxDistance` 1.5 × the fit; the camera's
   near plane 0.05 units (derived in § 3.6 so the near plane's corners stay above the floor); the entry pose at the
   home tilt from the front at the fit distance, set from the spherical figures; `enableDamping` on with the library's
   factor, off under `reduce` (§ 3.6).
3. **The keyboard map**: arrows rotate and tilt, Shift+arrows pan, `+`/`−` dolly, *Fit* the entry pose; the keys wire
   hands the key event's modifiers to `drag(dx, dy, modifiers)` as an optional third argument; the keys are wired in
   the hoisted page over the renderer contract's `keys`; the library's key listener is never connected (§ 3.6).
4. **A seat's character costs at most one draw call and an intern's at most two** — one skinned mesh over one skeleton per character, its joints
   rigid, the body and the face one material (a face atlas), the laptop the second; the budget's per-term bounds `S + 2 I`, `22 k`, 2, 20 and 19 (§ 6); poses and clips one encoding (`POSES`, `CLIPS`) that STEPS at the loop's rate;
   the mixer's time SET on every frame — a held loop from the last paint plus the character's own loop offset, keyed by its
   seat or intern key, an in-flight row from its elapsed time — never accumulated, with no per-action start time (§ 3.3, § 3.5).
   in slices 3 and 5.
5. The face is the 2-D face as a texture; the body is geometry over the species' shared `shape` data (§ 3.3).
6. The kit is recoloured from `PALETTE`; one toon material for creatures and furniture; the sitter on the band side
   (§ 3.4). (The monitor's and the lid's facing are the operator's, Q4, not a seat ruling.)
7. Stage 1's exploded storey retires with the pictures at slice 8; the real storey is `ROOM_H`.
8. The 3-D floor is a route beside the 2-D page until the operator's reading (the operator's Q3 A); the 2-D page is
   the WebGL fallback.
9. `building/labels.js`'s hand docking is a hygiene migration to `CSS2DRenderer`, bundled into slice 8 and not
   before (no product impact on its own; canon #18).
10. `three-mesh-bvh` is adopted only on its measured trigger (§ 2.1).
11. No camera collider (§ 3.7); the cut-away lowers walls and the band to 0.15 units over 150 ms and never cuts a
    desk or a character.
12. The target's box clamp is three lines after `update()` in the renderer, the box `view-limits.js`'s — the one
    first-party piece of camera mechanism, and the one clamp (§ 3.6).

## 13. Operator questions — all four answered on 2026-11-11

**Q1 — Where the models come from. ANSWERED A** (the operator, 2026-11-11, card comment 17077: *"Q1. A"*):
characters first-party, furniture from Kenney's Furniture Kit (CC0), the room first-party, with B's `piece3d` path
for what the kit lacks and C held as the exit; the asset gate admits `.glb` (§ 3.2). The three options as put are
revision 1's record, folded into § 3.2's table.

**Q2 — The camera: orbit + pan + zoom, and the pan gesture. ANSWERED A** (comment 17079: *"Q2. A / Q3. A"*): orbit +
pan + zoom, right-drag pans, two fingers on touch (§ 3.6). The walk mode (C) and Shift+drag (B) are not built.

**Q3 — Does 3-D replace the 2-D floor page, or sit beside it? ANSWERED A** (the same comment): beside first —
`/floor/{key}/3d` built beside `/floor/{key}`, the default swap after the operator's reading (slice 7). The example as
put said the floor page's *3D* pill would open the 3-D floor; corrected: the floor page gains a separate *Floor in
3D* pill and `/building` keeps its *3D* pill (§ 3.8) — kept, and whether two pills read well is confirmed at the slice-4 reading — the substance of the answer, *beside first*, is unchanged.

**Q4 — Which way the monitor and the intern's laptop face in 3-D. ANSWERED** (the operator, 2026-11-11, card comment
17086: *"Q4: A"* — the question as the coordinating seat put it and its option A, quoted from that record: *"real 3D
— each monitor faces its sitter, each laptop faces its intern; the status also floats as a camera-facing label above
each desk, readable from any angle. The 2D rulings stand for the 2D floor; the 3D floor follows A"*). Where it lands:
§ 3.4 (the facings), § 3.9 (the `status` label, computed by `floor-world.js`, second in each desk's label order),
§ 6 (no draw and no texture — it is DOM), § 8 items 2 and 5 (its two tests), § 11 item 1 (closed on this answer),
`FLOOR.md` § 10.4's stage-2 paragraph. The options as this plan first put them are kept below as the record of what
was weighed; the operator's option set was the coordinating seat's, and its A is the substance above.
*Context, as weighed here:* two standing rulings fix orientations for a drawing seen from one side. `FLOOR.md` § 13 row 59
(2026-10-06/07, card#11046): *"yes, the monitors face us and show the status"* — the monitor's screen faces the
2-D viewer, a 3/4-game cheat so the state line can be read. Row 87 C1 (2026-10-27, card#12448): the intern's laptop
lid's BACK faces the viewer, with its stickers. In 3-D the viewer walks round the desk: kept as ruled, a character at
its keyboard faces the back of its own monitor, and an intern types on a laptop whose lid faces away from it; the
screen's text is still read by orbiting to the front. The sitter's side is already pinned (the band side, the
2026-10-06 ruling) and is not this question.
*Options:*
- **A — keep both rulings as they stand.** Example: standing at the room's front you see every screen's status
  line and every laptop's stickers, as on the 2-D floor; walking behind a desk you see the sitter's face and the
  monitor's back between you.
- **B — reverse both for the 3-D floor: the monitor faces its sitter, the laptop's lid faces its intern.** Example:
  from the front you see monitor backs and read each desk's state from its chip and facts card (labels, always
  readable); orbiting behind a desk shows the screen over the sitter's shoulder, as in a real office. The 2-D page
  is unchanged either way.
- **C — reverse the monitor, keep the lid** (or the converse): one cheat kept where its reason — the stickers, or the
  status line — is the thing the operator wants seen from the front.
*This plan's recommendation had been B* — the room is now a place the viewer stands in and the labels carry the
state at every angle; the operator's answer is B's facings plus a status label, and slice 4 builds it.

The building-in-volume slice (8) returns to the operator with its own questions before it starts.

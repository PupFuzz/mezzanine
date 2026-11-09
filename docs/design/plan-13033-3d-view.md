# card#13033 — design and build plan: the building in 3D (stage 1), and the floor in 3D (stage 2, outline)

**What this file is.** The design and the build plan for one card, in the shape `plan-12987-cab-door-view.md`
set: written for the coder who implements stage 1 and the reviewer who attacks it. It owns the *derivation*,
the *sequence* and the *acceptance list as observables*; it owns no rule. Every rule lives in `FLOOR.md`, which
this commit amends where the view touches a ruling: § 1.1's screen table, § 1.2's library row, § 4's screen
count, § 4.1 (the building view is not the cross-section's return), § 4.4's route table, § 4.5's camera bullet,
§ 10.3's projection bullet, § 10.4's two-conventions bullet, § 11's AT-D3-37, § 12's figures, § 13 row 118,
§ 14 item 49 and Appendix B row 47 (planned, no landing marker). A reader who finds the two disagreeing trusts
`FLOOR.md` and fixes this file. When stage 1 has landed, §§ 1–10 of this file are history and § 11 (stage 2)
is the brief for the next design.

**Revision 2 (2026-11-09)** folds design review round 1 (card comment 16459) and the operator's rulings of the same day
(card comment 16451): the page route renamed off the API's `building` (B1); the storey derived from visibility at the
home angle, an exploded stack (B2, the seat's ruling); `room.map` applied by the building screen (M1); one picture
rule (M2); a RED that can fire (M3); and the minors folded where they stand. § 13 is now the operator's rulings,
decided, and no open question remains in this file.

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
`FLOOR.md` § 10.3). ⚠ **Not re-run for this plan**: the worktree carries no `.env`, and the live checkout is not
this plan's to enter. The coder re-runs those three commands before slice 3 and records the readings in the PR.

Each floor's **drawing** is `floor/scene.js`'s `buildScene(frame, input)` painted by `floor/painter.js` — the
band (back wall, clock, windows, elevator), each room's plane and scenery, the desks with their characters, labels
and chips, the overflow strip. Its extent is `scene.extent`, the rect *Fit the floor* frames
(`floor-screen.js` L721: `this.#camera = frameOn(this.#camera, scene.extent)`; `fitFloor()` L449).

## 2. The shape, and what was rejected

**Chosen — a fourth screen on a route of its own, `/building`, that composes every floor the way the floor page
composes one, paints each with the floor page's own painter into a hidden host, rasterises that drawing into a
texture, and stands the textures on slabs in a Three.js scene under OrbitControls.** The decision logic — which
floors, where each slab stands, how high a storey is, the camera's limits, which slab a pick names, how big a
texture may be — is one DOM-free module; Three.js and the DOM are confined to two renderer modules and the page
entry. Three hoists come first, each at its second caller:

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
   Behaviour-preserving: every existing floor test is the control.
2. **`floor/floor-data.js` — the press-time prefetch, hoisted out of `floor/door-views.js`'s `prefetch()`**
   (L68–88): every row's rooms through `Building#enterRooms()`, each held map's tilesets through the page's
   `TilesetLoader`, and the theme modules through `cab-paint.js`'s `loadThemes()` where the page holds none —
   the page woken after each step. That is exactly what `/building` needs on entry for *every* floor, and
   `DoorViews` needs at a ride's press. `DoorViews` constructs a `FloorData` and delegates; its `#loaded` moves
   into it (`FloorData#themes`). The two behaviours the hoist must not change: NO `fetchLayout()` here (the
   header's ⛔ — a press is not a reading of the layout), and the only failure signal stays the data's absence.
3. **`floor/painter.js` `createPainter()` takes its host** — `{ host = document.getElementById('floor-drawing') }`
   (L250 reads the id directly today). The building page needs one painter per floor, each over a hidden element;
   the floor page passes nothing and changes nothing.

Then the view's own modules (§ 4). Why this split and not another:

| Rejected | Mechanism it would turn on | Why not |
|---|---|---|
| **Draw the stack inside the lobby page**, a toggle between the room and the 3D stack | a second drawing surface under `#lobby-building`, the 2-D camera wire and OrbitControls on one element | The lobby's room is the operator's ruling of 2026-10-20 and its camera, gestures, directory docking and ride are built and tested over that one surface (`lobby/main.js`); a second surface there puts two gesture systems on one page and makes every lobby test's population ambiguous. A route of its own costs one view and one wiring test and leaves card#12113's screen exactly as ratified |
| **One `FloorScreen` per floor**, each painted and rasterised | N screens over one client | N journal drains (above); N rides, N panels, N walk holders nobody will see; and `createPainter()` is one host per painter either way, so the hoist is needed regardless |
| **Scenery-only slabs** — each floor's plane, walls, scenery and band with no desk on it (the door view's model, `floor/door-view.js`, projected flat) | `doorView()`'s bodies, or `buildScene()` over a frame with no seats | A staffed floor drawn with no desks is the empty office `FLOOR.md` § 9 opens on — *a floor that fails quietly is indistinguishable from a fleet that has gone home* — and the operator asked for *that floor's existing drawing*, which has the desks on it. Rejected, and the operator ruled it so on 2026-11-09 (§ 13 ruling 4: the live floor, desks staffed as held) |
| **Server-rendered textures** (`GET /api/building/floors/{key}/picture`) | a PHP rasteriser over the room maps and themes | The client already composes floors and draws them; a second composer is the drift § 4.6's reader table forbids, the same ground `plan-12987` § 2 rejected a server-rendered door view on. And the desks are a client-held picture — a server has no seat state to draw honestly |
| **Load Three.js from a CDN** | `<script type="importmap">` pointing at jsDelivr | A page that fetches code from a third host at run time is one whose behaviour a deploy does not pin and a provenance gate cannot see (`docs/ATTRIBUTION.md`'s *Nothing that inspects a directory can refuse code that fetches upstream art at run time*); the repository's modules are served from `public/` by versioned URL (`App\Support\PublicAsset`), and the vendored library joins that scheme |
| **A 2-D isometric or oblique fake** (CSS transforms over the floor SVGs, no WebGL) | `transform: rotateX() rotateZ()` on the painted `<svg>`s | No real orbit, no depth, and every pointer coordinate has to be un-projected by hand; the card decided the library |

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
- **A storey is derived from VISIBILITY at the camera's home angle, not from wall height** — the design review's B2
  and the seat's ruling on it: an exploded stack. The home looks down at `HOME_POLAR` = 50° from vertical, so an
  upper slab hides everything on the slab under it beyond `s · tan(HOME_POLAR)` behind the front edge, `s` being
  the storey (front edges align — the alignment bullet below). The first draft's `BAND_H` storey (160 px, 1.6 units)
  would have shown the first 1.9 units of a 10-unit-deep floor and nothing else: only the top floor would have read.
  So `storeySpacing(depths, homePolar) = max(depths) · cot(homePolar) + SLAB_T` — the spacing at which the DEEPEST
  slab's **back edge** is just visible under the slab above it from the home angle; for a floor of the shipped
  default's depth (band 160 + grid 696 + the overflow strip's gap and header, ≈ 1,000 px, ≈ 10 units) that is ≈ 8.5
  units. Pure, in `stack-model.js`, re-derived from the stack's own slabs on every frame, and § 12's *Building view
  storey* row is Derived from it. Its test (§ 8 item 3) computes, for every slab below the top, the depth the home
  sight line reaches on it under the slab above — `s · tan(homePolar)` — and fails when that is less than the
  slab's depth. ⚠ The review's wording names the FRONT edge as what is occluded; with front edges aligned the front
  edge is the one part that is always visible (the sight line to it rises clear of the upper slab's front edge), and
  it is the **back** edge that disappears first — the test is pinned on the back edge, the stricter reading. The
  slab itself is **0.12 units** thick (12 scene px, § 12's *Building view slab*, Chosen: thick enough to read as a
  slab edge at the entry framing, thin enough that the picture is the slab).
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

`stackModel(rows, extents, lobbyHref)` returns `{ storey: s, slabs: [{ floor, name, href, level, storey, box: {x, y, z, w, h,
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
  `tools/design/headless-chromium.mjs` L30 passes): `MAX_TEXTURE_SIZE` **8192**, renderer *ANGLE (Google, Vulkan
  1.3.0 (SwiftShader Device (Subzero)))* — software GL, which is what the CI leg in § 8 rests on.

So the pipeline, in `building/floor-picture.js` (DOM; its two string functions are pure and node-driven):

1. `tokenNames(css)` — the set of `--…` names `STYLE` and `CAB_STYLE` reference, pure, and **the one extraction
   predicate**: `var(--name` followed by `)` OR by `,` — the fallback form `var(--dv-floor, color-mix(…))` that
   `cab-paint.js` L138–148 uses, which `ThePageChromeIsOneLinkedStylesheetTest`'s own regex `var\(--([a-z0-9_-]+)\)`
   (L181) does not match — less the tokens the painter sets on elements itself (`setProperty('--…')`, which that test
   reads at L186). That test drives `tokenNames()` under node (as its `painterStyle()` already evaluates `STYLE` under
   node, L229–235) in place of its regex, so the sheet's reader and the picture's are one function.
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
   `anisotropy` the renderer's maximum (the slab is seen obliquely; without anisotropy the desk text smears).
6. **Texture size**: `textureSize(extent, max)` — 1 texture px per scene px, scaled down proportionally when the
   extent's longer side exceeds `max = min(gl.MAX_TEXTURE_SIZE, 4096)` (§ 12's *Building view texture cap*, Chosen).
   The `min()` is what guarantees the upload: WebGL 2 inherits OpenGL ES 3.0's floor, and that is **2,048** — *"The
   value must be at least 2048"* (the `glGet` reference page for ES 3.0, read 2026-11-09) — so a device at the floor
   draws every picture at half size and draws it. The 4,096 ceiling is a memory and rasterisation-time cap chosen
   above the shipped default's 1,784 px so the common floor stays at 1:1.

**What the picture shows, and why that is honest.** Each floor is drawn **under `reduce`** — every § 6.2 row in its
static form (§ 6.4: *reduced motion is a first-class rendering, not a degradation*), **no walk, no bird, no break in
flight** (`buildScene()`'s `effects: []`), the desks staffed as the client holds them. A texture is a still; a still
that shows a held loop's static form claims exactly what the held field claims, and a walk mid-stride on a slab the
viewer cannot see move would be motion with no visible driver. **Every render paints every floor in full** — N painter
paints, one per `plates()` row, because the painter rebuilds its drawing on each paint and identity is no signal —
and then **rasterises the floors whose serialised SVG differs from the last uploaded** (a string compare); the
rasterisation is **awaited inside the render** (the `<img>` decode is asynchronous), so a render's textures are that
render's frame and never a later one's, and the browser leg (§ 8 item 5) prints the per-floor paint and rasterise
times, which this plan does not predict. A texture is never re-made on a timer: the ages the desks carry are those of the
render, which is § 2.5's own rule for a render, and the 1 s age tick repaints **no** texture (the tick redraws text
nobody can read at a slab's scale and would cost N rasterisations a second; § 12 of this file records it as a seat
ruling, and the list beside the stage carries each floor's name and notices, never an age).

### 3.3 The camera and its limits (stage 1)

OrbitControls over a `PerspectiveCamera` (fov 45°), read from `examples/jsm/controls/OrbitControls.js` at r187:
its defaults are `minDistance 0`, `maxDistance Infinity`, `minPolarAngle 0`, `maxPolarAngle π`, `enablePan true`,
`zoomToCursor false`, `mouseButtons { LEFT: ROTATE, MIDDLE: DOLLY, RIGHT: PAN }`, `touches { ONE: ROTATE, TWO:
DOLLY_PAN }`, and it clamps the target to a sphere through `minTargetRadius`/`maxTargetRadius` (both default
unbounded) and dispatches `change`, `start` and `end`. The limits, all computed by `cameraLimits(bounds, surface)`
in `stack-model.js` from the stack's `bounds` (§ 12's *Building view camera*, Chosen, each with its reason):

- **target** — the stack's centre, and **`controls.cursor`** set to it (OrbitControls constrains the target to a
  sphere about `cursor`, not about the initial target); **`maxTargetRadius`** — half the bounds' diagonal, so a pan
  never loses the building off the screen; and on every `change` the page clamps **`target.y ≥ 0`** and calls
  `update()` — the sphere alone lets a downward pan carry the target, and the camera with it, under the ground slab,
  and with `maxPolarAngle` below π/2 the clamp is what keeps the camera above it;
- **home** — azimuth 0 (in front), polar **50°** from vertical (`HOME_POLAR`, the angle the storey is derived
  from), at `fitDistance(bounds, fov, aspect)` — the distance at which the bounds' bounding sphere fits the narrower
  of the surface's two half-angles — pure trigonometry, re-run on resize so the entry framing is the floor page's
  *fit*: a setting, no glide (§ 6.5). The exploded stack is tall — five floors of the shipped default's depth are
  about 43 units high — so the home framing is far and every slab small; the near bound below is what brings a desk
  to reading size;
- **`minDistance`** 0.3 × fit, **`maxDistance`** 4 × fit — close enough to read a desk's name on the top slab,
  far enough to see the whole stack small;
- **`minPolarAngle`** 0.1 rad (nearly overhead: a plan view of the top floor is a legitimate view),
  **`maxPolarAngle`** π/2 − 0.05 (with the target clamp above, never under the ground slab);
- **`zoomToCursor`** true, **`enableDamping`** false (no inertia: an interrupted gesture stops where it is, the
  2-D camera's rule, and there is no motion to cut under `prefers-reduced-motion`); **`screenSpacePanning`** left
  at its default.

Gestures are **OrbitControls' convention** — left-drag rotates, the wheel zooms, right-drag or two-finger drag pans
— spelled out on the hint line under the canvas (as `partials/camera-hint.blade.php` says the floor's), and **the
wheel over the canvas never scrolls the page**: the operator's ruling 1 of 2026-11-09 (card comment 16451). The 2-D
floor and lobby keep the gestures of 2026-10-01 (card#11045, § 13 row 39: the plain wheel pans, Ctrl+wheel zooms);
a 3-D view has no *edge* at which a wheel could hand itself to the page (row 39's Q3), and OrbitControls has no
wheel-pan mode, so the ruling is also the only shape the library offers without a fork. The keyboard: the canvas is a tab stop, OrbitControls'
`listenToKeyEvents(canvas)` pans on the arrow keys, and *Zoom in* / *Zoom out* / *Fit the building* buttons in a
camera row outside the drawing (`floor.blade.php`'s pattern, card#11045) call `dollyBy()` — `stack-model.js`'s pure
step of the camera along its target line by `ZOOM_STEP` (`wire/camera.js` L42, imported), clamped to the distance
range — and the home framing. Zooming never changes what is drawn: the textures are what they are at any distance.

### 3.4 Picking, labels and the list

- **A click on a slab opens its floor.** `pointerdown` records the point; a `pointerup` whose distance from it is
  **under** `DRAG_SLOP_PX` (`wire/camera-gestures.js` L115, 4 px — imported, not copied; `< DRAG_SLOP_PX` is that
  wire's own test at L337, so a 4 px move is a drag there and here) is a click (`isClick(down, up, slop)`, pure);
  a `Raycaster` from the pointer's normalised device coordinates over the slab meshes names the nearest; the
  mesh's `userData.floor` is the model's slab, and the page does `location.assign(slab.href)` — `/floor/{key}` by
  the key (§ 4.4's ⛔), the lobby href for the ground slab. A click that follows a drag opens nothing; OrbitControls
  keeps its gesture and the page keeps the decision.
- **The floors are also a list, beside the drawing, at every size** — `#building-floors`, one `<li>` per
  `plates()` row with an `<a href>` of the row's `href` and the row's `name` (`label ?? key`, decided once by
  `floors()`), plus the ground entry, plus each floor's notices as lines under its link. It is the **accessible
  surface**: a keyboard user or a screen reader opens a floor from it; before the first applied snapshot and layout it
  flows in the page as one WAITING placeholder (the view carries no layout — there is no list until the client has
  fetched one, as on the lobby), and it is the whole page when WebGL is unavailable (§ 7). **The stack is not drawn
  until at least one floor's scene has an extent**: every scene is `null` until `loadArt()` has answered and a map is
  held, so until then the canvas stays hidden, the camera row hidden, and the list flows; the first frame with an
  extent draws the stack at its home framing (a setting, § 6.5). While the renderer runs, the page **docks** each entry at its slab's
  projected front-left corner (`labelAnchor(slabBox)` → the world point, projected by the page with
  `Vector3.project(camera)`; hidden when behind the camera) — the lobby directory's `data-home="board|dock|flow"`
  pattern (`lobby/directory.js`'s `dockingEntry()`), here `stage|flow`, so one element is both the label and the
  link and no second list is written. No count, no age, no state is on the stage: the building view carries
  **no figure of its own** (§ 4.1's reasoning for the lobby — a count a viewer counts by eye — has nothing to
  disagree with here).
- **The canvas** is `role="group"` `aria-label="the building drawing"`, a tab stop with `aria-keyshortcuts` naming
  the arrows, as `#floor-drawing` is (`floor.blade.php`); it is never the only route to a floor.

## 4. Modules, and the DOM-free split

The house rule (`lobby/lobby-model.js`'s, restated in every renderer's header): there is no browser on the build
host, so every DECISION lives in a module `node` can drive; only renderers touch the DOM, and only one touches
WebGL. New files under `server/public/js/`:

| Module | DOM / WebGL | Owns | Driven by |
|---|---|---|---|
| `floor/floor-compose.js` (hoist) | none | `composeFloor(floor, building, seatsByRoom)` — § 2 item 1 | every existing floor test (control); `TheFloorCompositionIsOneForBothScreensTest`: `floor-screen.js` imports it and holds no second `placeRooms()`/`assignSlots()` call site |
| `floor/floor-data.js` (hoist) | none (`fetch` injected) | `FloorData` — § 2 item 2 | `TheElevatorIsAControlOnTheDrawingTest`'s door-view leg (control), the building screen's probe |
| `building/stack-model.js` | none | `stackModel()`, `cameraLimits()`, `fitDistance()`, `dollyBy()`, `textureSize()`, `isClick()`, `labelAnchor()`, the unit and storey constants | `stack-model-probe.mjs` under `TheBuildingViewStacksTheFloorsTest` (AT-D3-37) |
| `building/building-screen.js` | none | `BuildingScreen`: drains the journal once (`LobbyScreen#render()`'s shape, L162–186: `building.layout` applied, the layout fetched once after the first applied snapshot), runs ONE `DeskFloor` over every held seat with `reduce: true`, holds one `FloorData` and prefetches every row on entry and on each layout change; **applies `room.map`** through `Building#applyRoomMap(entry, rendered)` with `rendered` = every room of every `plates()` row (D2 § 8.7's *a client applies a `room.map` only for a room it renders* — this screen renders every room), records the apply's line in the client's record as the floor screen does (`floor-screen.js` L657–667) and re-runs `FloorData#prefetch()` so the new map's tilesets are held — **the interface change**: `BuildingScreen#render()` is `applyRoomMap()`'s second screen caller (the floor screen's `#layoutActs()` is the first; the lobby applies none, `lobby-screen.js` L23) and the first whose `rendered` set is the whole building, so a re-saved or recovered map refreshes its slab on the next render; and per `plates()` row: `composeFloor()` → the frame → `buildScene()` with `effects: []` → `{ row, frame, scene }`; returns `{ floors, stack: stackModel(…), strip, failure, statement, layout, notices }`. No camera (the 3-D camera is the page's and holds no state the screen reads), no ride, no panel | `fleet-client-probe.mjs` gains a `building` entry beside `floor` and `lobby` (one renderer per scenario, L519's rule); AT-D3-37 |
| `building/floor-picture.js` | DOM (hidden hosts, canvas, `<img>`); `tokenNames()` and `pictureSvg()` pure | one painter per floor over a hidden `<div>` in `#building-pictures`, the rasterisation of § 3.2, the string compare that skips an unchanged picture | `picture-probe.mjs` for the two pure functions; the browser leg for the rest |
| `building/stack-paint.js` | **Three.js** | the renderer over `#building-canvas`, the slab meshes (`BoxGeometry`, a `MeshBasicMaterial` per face — the top face the floor's `CanvasTexture`, the others the sheet's slab token), the ground slab, lights (none: unlit materials, the drawings carry their own light), OrbitControls with `cameraLimits()`, the raycast, `resize()`, and the per-frame label projection | the browser leg |
| `building/main.js` | DOM entry | `livePage()`, the ids, the screen, the pictures, the paint, the WebGL check, the docking of the list | `BuildingPageWiringTest` (ids both ways; the log handed to the screen and used nowhere else — `LobbyPageWiringTest`'s shape) |

Changed files: `floor/floor-screen.js` (calls `composeFloor()`), `floor/door-views.js` (holds a `FloorData`),
`floor/painter.js` (`host` option), `server/app/Support/PublicAsset.php` (§ 5's bare specifier),
`server/routes/web.php`, `server/resources/views/building.blade.php` (new), `dashboard.blade.php` and
`floor.blade.php` (the entry link, § 6), `public/css/mezzanine.css` (the stage's size: the viewport height the
chrome leaves, `#floor-drawing`'s rule at L851–858), `server/tests/Feature/Support/ModuleSpecifiers.php` (§ 5),
`tools/design/render-floor-view.php` (a `building` view for the browser tool).

**The frame the picture is painted from** is `composeFloor()`'s output plus what `#drawFloor()` adds around it
(`floor`, `room` from a `RoomClock` the building screen holds as the lobby does, `desks` from the one `DeskFloor`,
`coord` and `coord_fleet` **empty** — the thread edges are drawn on arrival on the floor page and are a still's
nothing — and `failure`/`statement`). `buildScene()` reads `frame.desks`, `frame.overflow`, `frame.floor`,
`frame.rooms`, `frame.draw_order`, `frame.coord_fleet`, `frame.band`, `frame.room`, `frame.planned`,
`frame.extent`, `frame.coord` (grep over `scene.js`, re-run by the coder; `frame.h` at L2141 is `effect.frame.h` and not the frame's) — the building screen hands every one,
and the slice-3 probe holds the frame's member set equal to the floor screen's frame's at the same fixture, so a
member the floor screen adds later reds here instead of drawing a slab from a frame missing it.

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
through source 2's per-dependency read. Nothing installs it (the suite runs with no `node_modules`,
`php-tests.yml` L103), and the vendor header's version is held equal to the lockfile's by a check in
`bin/vendor-pin-check.sh`'s run (a row-level comparison beside the sha: the header's `plugin version` line against
`package-lock.json`'s `node_modules/three` version), so the audited version is the served one. Accepting the gap on
the record was considered and rejected: a rendering library that reads no network input is a low-risk surface, but
a declared-and-checked one costs one lockfile entry.

**Whole-tree walks over `public/js` that will reach `public/js/vendor/`**, each decided:
`EveryTimeGoesThroughTheConverterTest` scans every `.js` under `public/js` for time-formatting patterns
(`Intl.DateTimeFormat`, `toLocale*String`, the `Date` getters — L43–52) and asks each finding to go through
`wire/clock.js` — **exclude** `vendor/` from its population by name, with the reason in the test: third-party code
the repository does not edit prints no time on a page, and a finding there could not be routed through the
converter; its non-trivial-count assertion is unaffected. `CoordModuleWiringTest` and `DrillDownModuleWiringTest`
walk the tree for a second `export function clockTime(` and for the drill-down's imports — **include**: a vendored
file cannot carry the one anchor they look for, and the walk costs nothing. `DrivesAShippedClientModule::mutatedModules()`
copies the WHOLE `public/js` tree for every planted control (L227–235, for the `../` imports) — **include**: the copy
grows by about 2.1 MB per control, and the coder states the count of `mutatedModules()` calls across the suite and the
measured suite-time delta in the slice-2 PR body; a copy that skipped `vendor/` would break only a building-module
control and would be a second layout the probes must know about. `EveryModuleAPageLoadsIsVersionedTest` **must**
include it (above). `ModuleSpecifiers` walks reach it only from `building/main.js` (the building page's own graph
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
  entire; `wire/camera.js` (the building view does not use it on the page — only `floor-picture.js` uses `fit()`
  to frame a texture); `FLOOR.md` § 4.1's ruling that the lobby draws no desk and the cross-section is gone. The
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
rebuilds the slabs from the last frame. The statement is a constant in `building-screen.js` (text is a decision),
and the WebGL probe is the page's (a capability read is the browser's own fact, not a model's).

## 8. How the tests pin it — each RED a plant in the shipped tree

Node, through the house rig (`DrivesAShippedClientModule`, `DrivesTheFleetClientModule`), and one browser leg:

1. **The hoists are behaviour-preserving** — every floor test is the control; `TheFloorCompositionIsOneForBothScreensTest`
   walks `floor-screen.js`'s specifiers (`ModuleSpecifiers`) and holds that it imports `floor-compose.js` and that
   `assignSlots(` and `mapReservation(` are called in `floor-compose.js` and nowhere else under `public/js` (a second
   call site is the defect; `floor-layout.js` defines them). `placeRooms(` has a second legitimate caller,
   `floor/door-view.js` L97 — the door view composes geometry and assigns no desk, which is plan-12987's own
   decision; whether it should read `composeFloor()`'s geometry half instead is a smell noted for that module's
   next change, not this card's scope. The door-view leg of `TheElevatorIsAControlOnTheDrawingTest` is `FloorData`'s control.
2. **AT-D3-37 — the building view stacks the floors** (`FLOOR.md` § 11, gated at Appendix B row 47):
   `fx-snapshot-4` under `fx-camera.json`'s two-floor layout (the `building_room` run's own) entered at
   `/building` through the probe's `building` entry; GREEN: one `{ row, scene }` per `plates()` row, in `plates()`
   order; each scene's extent is the extent the floor screen computes for that floor at the same fixture (the
   probe runs `floor` for each key and compares — the slab shows what the floor page shows); the stack's slabs are
   `n` with storeys `n − 1 − level`; **one picture rule**: a slab carries a picture exactly when its scene's extent
   is non-null — a floor whose room-map request failed carries the floor page's `mapFailureNotice()` line, and
   whether it also carries a picture follows its extent alone (§ 9 F16: a map held before the failure keeps its
   extent, so that floor still shows); a `room.map` delivered on the stream re-fetches that room's map and the next
   render's scene for its floor is built from the new document; the frame holds no count, no camera and no ride
   member; the animation log holds the
   fixture's own rows and nothing a slab or a texture wrote — the screen reaches the log only through its one
   `DeskFloor`, held over the page's import graph from `building/main.js` as the lobby's test holds the lobby's.
   REDs, each planted in the shipped tree and watched red: *the recounted floor* — `stackModel()` reading
   `row.held` or `row.staffing` to size or label a slab; *the swapped order* — `storey = level`; *the frame the
   floor page would not draw* — a member dropped from the building frame that `buildScene()` reads (the member-set
   equality); *the ride on the roof* — a `Ride` constructed in the building screen; *the journal drained twice* —
   the harness's `building` entry wraps `client.takeWire` and COUNTS calls per render (its `desk_floor` entry already
   wraps it to observe the drain, `fleet-client-probe.mjs` L586–589) and fails a render that drained more than once;
   the plant that proves it fires is `this.#desks.render()` — which drains for itself, `desk-floor.js` L134 — in
   place of `renderWith(journal)`, a change every other assertion passes. ⚠ The probe's *two renderers, one journal*
   rule (L519–520) reads SCENARIO keys, not calls, and would not catch this; the counter is what does; *the map that
   never refreshes* — `applyRoomMap()` not called on a `room.map` entry → the delivered map's scene is the old one.
3. **The stack's arithmetic** — `stack-model-probe.mjs`: `storeySpacing()` over fixtures of two and five slabs of
   unequal depth, and **the occlusion test**: for every slab below the top, the depth the home sight line reaches on
   it under the slab above (`s · tan(homePolar)`) is at least that slab's depth, so its back edge is visible — fails
   on the first draft's `BAND_H` storey (the control) and on a stack whose deepest slab is not the one the spacing
   was derived from; `fitDistance()` fits the bounding sphere for a wide and a tall surface; `cameraLimits()`'s polar
   range excludes under-ground; `dollyBy()` clamps at both ends; `isClick()` refuses a 4 px move and accepts 3
   (`< DRAG_SLOP_PX`); `textureSize()` holds 1:1 under the cap and scales proportionally over it
   (controls: a 1,784 × 1,000 extent at cap 4096 is itself; at cap 1024 it is 1024 × 574). `tokenNames()` returns
   every token the sheet test holds `STYLE` to; `pictureSvg()` writes each token once on the root and the sizes
   in place of the percentages (plant: a token left out → the probe's own `var()` rect reads black in the browser
   leg, which is the leg that can see it).
4. **The page wiring** — `BuildingPageWiringTest`: the ids both ways, the `three` import-map entry, the log handed
   to the screen only, no `fetch` or `EventSource` outside `livePage()`.
5. **The browser leg** — `tools/design/building-view.browser.mjs`, in `design-doc-verifiers.yml`'s `browser-smoke`
   job after the `starts` row, on the pinned shell: the served `/building` (`render-floor-view.php building`) fed
   the `floor_rides` run as `floor-ride.browser.mjs` feeds the lobby; GREEN: no uncaught exception, the stream
   asked for, `#building-canvas` holds a WebGL 2 context (`getContext('webgl2')` returns the same context), one
   `.floor-scene` SVG per composed floor under `#building-pictures`, each docked list entry `data-home="stage"`,
   and a pixel read from a slab's rasterised canvas (the page exposes nothing: the tool rasterises the hidden
   host's SVG through the same `pictureSvg()` it imports and compares a desk's rect to non-background) —
   `--selftest` plants: the token inlining dropped (`style` attribute stripped) → the rect reads black; a floor
   removed from the layout → one host fewer; `--disable-3d-apis` on the shell → the § 7 statement and no canvas; it
   prints each floor's paint and rasterise time and the page's `performance.memory` where the shell exposes it.
   ⚠ **What it does not hold**: that a real GPU, Safari or Firefox rasterise the SVG as SwiftShader does, or that
   OrbitControls feels right under a hand — named, not claimed; `--out building.png` saves a picture for the eye,
   as `floor-fixture.browser.mjs` does.

## 9. The slice plan, for the coder

Each slice is one PR to `dev`, titled `card#13033 [no-close]: …` until the last, which `(closes card#13033)`
only when the operator has read the sandbox (§ 13 ruling 5's reading); each carries
`docs/changelog.d/13033-<slug>.md` and the `FLOOR.md` landing marker for its half of row 47.

1. **The hoists** (`floor-compose.js`, `floor-data.js`, the painter's `host`), with test 1 and no behaviour change;
   the import-graph bounds of the lobby (`TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`) unchanged.
2. **Three.js vendored and pinned**: the three files and `LICENSE`, the vendor headers, `vendor-pin-check.sh`'s
   leader column with its `--selftest` seen red on a planted body edit, `BARE_SPECIFIERS` and the import-map
   assertion, `ModuleSpecifiers::judge()`, `docs/ATTRIBUTION.md`'s section; the transfer-size measurement in the
   PR body.
3. **The model and the screen**: `stack-model.js`, `building-screen.js`, the probe's `building` entry, tests 2–3,
   `FLOOR.md`'s AT-D3-37 red list confirmed against the plants.
4. **The page**: route, view, `main.js`, `floor-picture.js`, `stack-paint.js`, the stylesheet, the entry links,
   test 4, the browser leg with its plants, `render-floor-view.php building`; the sandbox deployed and a screenshot
   on the card for the operator's reading — this is the reading stage 2 waits on.

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
- **Texture memory — UNMEASURED.** Per floor, three copies exist at the moment of upload: the rasterising canvas
  (w × h × 4 B), the decoded `<img>` bitmap (the same), and the GPU texture with its mipmaps (× 4/3). At the cap that
  is 64 + 64 + 85 ≈ 213 MB per floor in flight; at the shipped default (≈ 2,100 × 1,000) ≈ 8.4 + 8.4 + 11.2 ≈ 28 MB,
  of which the mipmapped texture (≈ 11 MB) stays once the canvas and bitmap are released after upload. Ten floors at
  the cap would hold ≈ 850 MB of textures — a hazard with no live precondition on this install (§ 1's five floors,
  none above 2,100 px). Not defended beyond the cap; the browser leg reads `performance.memory` where available, and
  a per-page texture budget is a stage-2 question when floors become scenes.
- **Paint and rasterisation time.** Every render paints N floors in full and re-rasterises the changed ones, awaited
  inside the render: a delta storm could request several renders a second. The string compare skips unchanged floors, and `requestRender()`'s coalescing (`live-page.js`) bounds
  renders to one at a time; not throttled further until measured — the browser leg prints the per-floor
  rasterise time, and a figure over 100 ms per floor on the pinned shell is the trigger to add a per-floor minimum
  interval (a seat ruling then).
- **The `three` bare specifier and the import map's placement.** The map must precede every module script
  (`layouts/app.blade.php` L123's ⛔); the building view inherits it. A browser without import-map support
  cannot resolve `three` — nor any module the pages load today, since every import already goes through the map
  (card#12158), so this adds no new population.
- **The SVG-as-image rasteriser's limits.** Chromium measured; Safari and Firefox not. Known differences in SVG
  image rendering between engines (filters, `color-mix` support before 2023 releases) are named here and read on
  the sandbox by eye at slice 4; nothing in this plan defends them.
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
  Labels (nameplates, chips, bubbles — § 10.4 item 6's *labels over the scene*) would stay HTML over the canvas, projected
  per frame, as the directory's boxes are over the lobby room today.
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

1. The unit (100 scene px), the storey derived from the deepest slab at the 50° home angle (the design review's
   B2 — the coordinator's seat ruling, taken), the slab thickness (12 px), the texture cap (4,096 over the 2,048
   floor), the camera figures of § 3.3 — § 12's rows.
2. The lobby is a plain ground slab with no picture (the order itself is the operator's ruling 3).
3. A floor with no extent takes the stack's widest footprint and no picture.
4. Textures are stills under `reduce`, re-rasterised per render on change, never on the age tick.
5. Slabs centred on `x`, front edges aligned; the drawing's top toward the back.
6. The list docks to the slabs while the renderer runs and flows below the stage otherwise.
7. Three.js is vendored unminified from the npm package at the pinned version; no minification step.
8. The advisory leg is a lockfile entry held equal to the vendored version (§ 5), not an accepted gap.
9. `EveryTimeGoesThroughTheConverterTest` excludes `vendor/`; every other whole-tree walk includes it (§ 5).

## 13. Operator rulings of 2026-11-09 — decided (card comment 16451; `kbcard comments --task 13033`)

The first revision carried these as questions with recommendations; the operator answered each the same day, and
this section is the record. No open question remains in this file.

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

# Changelog

Every PR whose title or branch carries a `card#NNNN` token owes a line-initial
`- **card#NNNN** — …` bullet under `## [Unreleased]`, **in the same PR**. A PR that names no
card owes nothing. `docs/PLAN.md § 4` owns that rule and the reasoning behind it, including
why the bullet must be line-initial; `docs/VERSIONING.md` owns when a release collects these
entries and retitles the section.

⛔ **Enforced since 2026-08-30 by `bin/release-pr-guard.py` R4**, on every PR — for seven days
before that it was prose, and six cards' work merged with no entry at all. The two cases that
owe nothing are a PR into `main` (the release retitles this section) and a PR that REMOVES a
card's bullet (a revert of unreleased work); both are argued in that file's docstring. **R5**
holds this file's SIZE under `1 MiB − the bytes it grew in the last 14 days`, so it reds while
there is still time to archive released sections rather than after the contents API has begun
returning it empty. **R6** (card#9707) gives this section a third reader: on a release PR it
compares the cards bulleted here on `dev` against the head's changelog, so a release cannot
quietly ship with fewer cards than `dev` holds. **R7** (card#9814) refuses a release PR whose
copy of this file carries more than two released sections, and so enforces the archiving below.

Sections are newest-first: `[Unreleased]` collects what has landed on `dev` since the last
release, and a release retitles it (`docs/VERSIONING.md § Release flow` step 4).

This file holds `[Unreleased]` and the latest released section only. Every older release is one
file per tag under [`docs/changelog/`](changelog/) — `ls docs/changelog/` lists them — moved there
verbatim by release flow step 13 (`docs/VERSIONING.md`), which R7 enforces. R5 gates this file's
size; `docs/PLAN.md § 4` says why the archive files need no gate of their own beyond R7's backstop.

## [Unreleased]

- **card#11045** — **The floor's room now stands in an office: a tall back wall with a two-door elevator, a
  clock and wide windows, and each room on its own toned floor.** Operator rulings of 2026-10-01. The back
  wall over the floor is taller (160 px of the scene, about 2.2 m at the floor's scale) and holds, at its
  left, a two-door elevator drawn in the lobby cab's form and door colours and the wall clock, with tall,
  wide windows — two mullions, a transom and a sill — along the rest of the wall. The elevator is scenery:
  it never opens, and the floor's *whole building* control is still the way to the lobby's elevator. No
  window is ever laid over the clock: a wall too narrow for one past the elevator and the clock draws
  none, and a wall narrower than those two is widened to hold them. Each room's floor is filled under its
  own map in one of four tones — oak, walnut, sage or slate — chosen from the room's id, so it never
  changes and agrees in every browser; the wall keeps one house colour on every floor. A slab runs under
  the rooms. Nothing the frame draws covers a map author's grid. Slots no seat holds stay plain floor.
  Seats past a map's desks now wrap onto further bench rows at the floor's width instead of running past
  it. A touch whose pointer capture the browser revokes without a release no longer leaves a press held
  that turns the next touch into a pinch. FLOOR.md § 4.2 (the frame), § 9 F13, § 10.4, § 12's band,
  zone and window rows, § 13 rows 40–41 and AT-D3-20's clock clause record it;
  `Tests\Feature\Floor\TheFloorDrawsItsFrameTest` and the new lost-capture steps in
  `TheCameraWireIsOneForBothPagesTest` fail on the previous code. The lobby is unchanged.

- **card#11045** — **The wheel now pans the floor and the lobby; Ctrl+wheel and a pinch zoom.** Operator
  rulings of 2026-10-01. A plain mouse wheel or a trackpad's two-finger scroll pans the drawing in any
  direction, where it used to zoom; Ctrl+wheel and a trackpad's pinch zoom about the pointer; on a touch
  screen two fingers pinch to zoom about their midpoint and one finger drags to pan. When the drawing can
  pan no further the wheel's way, the wheel scrolls the page on, so the sections below the drawing are
  always one scroll away. A one-line hint under each drawing names the gestures (*scroll to pan ·
  ctrl+scroll or pinch to zoom · drag to pan · arrows · + −*, shortened on a phone), shown while the
  camera frames something. Both pages share the change through one camera wire, and a ride in the lobby
  still always arrives: a wheel or a pinch during its glide cuts it to the floor. FLOOR.md § 4.2, § 4.5,
  AT-D3-21, § 13 row 39 and Appendix B rows 15–16 record it. The rewritten checks in
  `Tests\Feature\Floor\TheCameraMovesTheViewerAndNeverTheFleetTest`,
  `TheBuildingCameraMovesTheViewerAndNeverTheFleetTest` and `TheCameraWireIsOneForBothPagesTest` fail on
  the previous code. Real trackpads, real touch screens and Safari were not exercised; Safari may deliver a
  trackpad pinch as events this change does not read.

- **card#11045** — **Every page now has a stylesheet: a dark page chrome around the warm room.** The app
  linked no CSS until now, so every page rendered in the browser's default black-on-white. One plain
  stylesheet, `server/public/css/mezzanine.css`, is linked from the shared layout with a `?v=` version
  taken from the file's own modification time, so a deploy that changes it reaches browsers without a
  cache flush; there is no build step. Every page keeps its title as the header's heading. On the floor
  page the header carries the building's seat counts and the way back to the lobby; the status strip is
  one row of chips in groups (this page's connection, the fleet, the room's clock and sky); notices
  are amber bars under it; the floor's name and the camera's controls sit on a row above the drawing, never
  over it. The drawing's height is now the window's height less the chrome shown above it, where it was
  the whole window's, and the camera fits the drawing's own box — re-measured whenever a banner, a
  statement or a notice appears or goes — so a pointer lands where it points at every window size. The
  desk list, the overflow, the coordination threads and the event log are each a collapsible section,
  closed when the page opens; the drill-down opens as a card over the floor. The floor's drawing takes
  its colours from the same stylesheet, so the palette has one home. FLOOR.md § 4.2 (the chrome, top to
  bottom), § 4.5 (the list view's section), § 10.4 (the palette's home) and § 12's reference-viewport
  row (which surface its figure is measured on) record it.
  `Tests\Feature\ThePageChromeIsOneLinkedStylesheetTest` and new checks in
  `Tests\Feature\Floor\FloorPageWiringTest` fail on the previous code.

- **card#7341** — **The office floor page draws its room at every window size.** Until now a window smaller
  than 1,280 × 800 CSS px got no drawing at all: the page replaced the room with a text list of the desks.
  The operator ruled on 2026-10-01 that every screen displays at any browser size, so the room is now drawn
  in a window of any size, at fit on entry, and the viewer pans it (drag, arrow keys) and zooms it (wheel,
  trackpad pinch, `+`/`-`, the *Zoom in* and *Zoom out* buttons), with *Fit the floor* bringing the whole
  floor back into view. The desk list stays on the page, below the drawing, at every window size — so on a
  large screen it now appears below the room as well — and it carries every desk fact in full where the
  drawing shortens a string to fit its desk. FLOOR.md § 4.5, § 12's reference-viewport row (renamed from
  *Floor viewport floor*), § 13 row 14 and Appendix B row 15 record the ruling.
  `Tests\Feature\Floor\TheCameraMovesTheViewerAndNeverTheFleetTest` enters the floor in a 390 × 700 and
  a 1,280 × 240 window and requires the room drawn, panned and zoomed there; it fails on the previous code.

## [0.7.0] — 2026-10-01

- **`laravel/framework` moves from 13.26.1 to 13.34.0** (#253), past Dependabot alert #1, an XSS in the
  framework's debug page. The debug page is served only while debug mode is on, and `bin/deploy.sh`
  refuses a `server/.env` whose `APP_DEBUG` is not `false`. From 13.31 the framework also checks a
  remember-me cookie against the user's current password hash, so the cookie stops working when that
  hash changes: after a password change, and after the automatic re-hash at the user's next password
  login once `BCRYPT_ROUNDS` has changed. That signs the user's other remembered devices out. Sessions
  and cookies issued before the upgrade stay signed in.
- **card#7341** — **The floor page draws its room tiles again.** Since the room drawing landed (Appendix
  B row 14, slice A, in 0.6.0), the floor page loaded neither tileset: the page's fetch
  (`server/public/js/wire/live-page.js`) handed its consumers a response with `json()` and no `text()`,
  and the tileset loader reads a tileset as text, so both tilesets failed with *response.text is not a
  function*, no tile of the room was drawn and every desk showed F14's placeholder with *some art
  failed to load* on the status strip — contrary to 0.6.0's entry for that slice. The page's response
  now carries `text()`, which asks for a render once it is read, as `json()` does. The probes had
  passed because their fake fetch (`server/tests/Feature/Support/scripted-fetch.mjs`) gave its
  response a `text()` the page's lacked; `Tests\Feature\Floor\TheHarnessFetchIsNoWiderThanThePagesTest`
  now reds when the fake's response and the page's differ in any member, and drives the tileset
  loader over the page's own fetch against a real `Response`, each check watched to fail on the
  unfixed wrapper.
- **`bin/change-pr-body.py` no longer writes AI attribution into a PR body.** The generated body
  ends on its `Built:` and `**Coordinated in:**` lines: the `Generated with Claude Code` model line
  and the session link are gone, and so are the `--agent` and `--session-url` options that filled
  them — a script still passing either now stops with a usage error. The operator's standing
  instruction of 2026-09-27 keeps AI attribution off every GitHub-bound text, and the linter in
  coord 0.58.0 reds it. `bin/change-pr-body.selftest.py` plants those lines back and shows that
  linter reds each one.
- **card#7343** — **The lobby's elevator ride now arrives at the floor, and the lobby has the floor's
  camera at building scale** (FLOOR.md Appendix B row 16, slice A). The lobby opens with every floor plate
  in view; the wheel zooms and a drag pans the building, and a *Whole building* control brings every plate
  back into view. The building takes keyboard focus like the floor's drawing: `+` and `-` zoom, the arrow
  keys pan, and *Zoom in* and *Zoom out* buttons sit beside *Whole building* — which is also how a touch
  screen zooms it. Tabbing to a floor plate that is out of view brings the camera to it. *Ride the
  elevator* moves the cab to the next floor, zooms the camera to that floor's plate and then opens
  `/floor/{key}` — the floor's key, also on a floor the operator has labelled, so the link does not change
  when a label does. The click commits the ride: the ride control is disabled until the camera has reached
  the floor, and a wheel, a key, a zoom button, a drag, a window resize or the *Whole building* control
  during the ride takes the viewer straight to the floor instead of stopping it halfway; tabbing to a
  plate during the ride leaves it gliding, and clicking a floor plate while the ride is gliding — or
  pressing Enter on one — does not open that floor, so a ride whose glide is under way arrives where it
  was going. Once the page has asked for the floor the controls work again, so a navigation the browser
  cancels does not leave the lobby stuck; coming back with the browser's Back button works too. Under
  `prefers-reduced-motion` every camera move cuts rather than glides. None of it is fleet state: no ride,
  zoom or pan writes an animation-log row, and a render leaves the camera where the viewer put it. Each
  floor's name and status line are now readable with the whole building in view, and a floor's link
  announces its name together with its seat summary, e.g. "Floor 2, 2 working · 1 idle" — clicking the
  seat summary also opens that floor. FLOOR.md § 4.1 states what the label does. With no floor layout loaded,
  the lobby's list of rooms flows in the page as it did before, rather than in the building's fixed-height
  drawing, and the mouse wheel, the arrow keys, text selection and a press on a room's link all work on it
  as they did before, with the zoom buttons and *Whole building* hidden and the building out of the tab
  order: on either page the camera takes a wheel, a key, a press or a drag only while it frames something,
  and otherwise leaves the event to the browser and offers none of its controls — on the floor too, where
  *Fit the floor* is hidden and the drawing is out of the tab order until the camera frames the floor. A
  press on a framed drawing starts no native drag and selects no text, and a drag that is under way when
  the drawing stops framing lets go of the pointer at its next move.
  Slice B draws the building itself, in the reference's warm, rounded style and sharp at every zoom: a
  roof with a *MEZZANINE* sign, each floor plate as a storey with its elevator doors, a ground-floor lobby
  with its entrance, and the elevator cab in its shaft at the floor it is on. *Ride the elevator* glides
  the cab to the next floor together with the camera, and cuts it there under `prefers-reduced-motion`;
  the whole-building view takes in the roof and the ground lobby. The drawing is scenery: it shows no
  fleet state, and every floor of one building is drawn alike. `Tests\Feature\Lobby\TheBuildingIsDrawnAsTheReferencesSectionTest` holds the drawing.
  Each floor plate's arched windows now show the time of day, as the reference's windows do: the sky's
  gradient at full strength, stars and a moon at night, the sun by day, at dawn and at dusk, and a city
  roofline whose windows light up after dark. Behind the building the rest of the drawing shows the same
  sky, dimmed as in the reference. Until the page has been live, the windows and the backdrop show a flat
  sky with no stars, sun, moon or lit windows. It is the floor's own sky value on the floor's own driver:
  it follows the viewer's clock, moves only when a feed heartbeat arrives — never on a timer or a poll —
  and stops when the feed dies, just as the floor's windows do, and it steps to a new phase rather than
  fading, with reduced motion or without. Every sky on both pages is painted from one table in
  `floor/floor-layout.js`, so the floor's windows now show that table's gradient instead of flat colours
  — the lobby's own windows read the same table further, adding the stars, the moon, the sun and the lit
  city the floor's windows do not draw. That sky is
  the lobby's one animation: each heartbeat writes one A17 row to the page's animation log and nothing
  else. Both pages now take their animation log from `wire/live-page.js`, which bounds it with FLOOR.md
  § 12's retention figure, and the floor screen and the lobby share one sky driver, `RoomClock` in
  `floor/floor-layout.js`. `Tests\Feature\Floor\TheLobbySkyIsTheFloorsA17Test` holds the sky.
  The shared camera (`wire/camera.js`) gained `focusOn()`, a zoom to one rect inside what is framed, and
  the floor page and the lobby share one glide through `wire/camera-view.js`, one wheel-and-drag wiring
  through `wire/camera-gestures.js` and one keyboard-and-zoom-button wiring through `wire/camera-keys.js`:
  a drag that ends over a desk or a floor plate is never a click on it. AT-D3-21's building half is
  `Tests\Feature\Floor\TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`, the shared wiring is driven
  under `node` by `Tests\Feature\Floor\TheCameraWireIsOneForBothPagesTest`, and the plates' text size is
  held by `Tests\Feature\Floor\ThePlateNameIsReadAtTheBodyTextSizeTest`.

Older releases: `docs/changelog/<tag>.md`, one per tag.

# Changelog

Every PR whose title or branch carries a `card#NNNN` token owes a line-initial
`- **card#NNNN** — …` bullet under `## [Unreleased]`, **in the same PR**. A PR that names no
card owes nothing. `docs/PLAN.md § 4` owns that rule and the reasoning behind it, including
why the bullet must be line-initial; `docs/VERSIONING.md` owns when a release collects these
entries and retitles the section.

Nothing has been released yet, so `[Unreleased]` is the only section.

## [Unreleased]

- **card#7936** — **A17's clock had two setters and its accessible text was bound to one of them, so
  the hands and the text could render the same minute differently.** `FLOOR.md` § 6.2 A17
  constraint 5 read *set by the same A17 firing that moves the hands and by nothing else* — but
  constraint 4 and § 6.5 give the hands **two** setters: the firing, and the render that establishes
  or re-establishes a LIVE feed, which moves the hands and writes **no** animation-log row (§ 11: *a
  firing writes a row, where the setting of § 6.5 writes none*). *By nothing else* excluded the
  second. A client built from that sentence draws the **connect snapshot** with hands on the current
  minute and an accessible name never set, and a **successful reconnect** with hands on the new
  minute and an accessible name still holding the minute from before the disconnect — **one fact,
  two renderings, disagreeing**, which is the *exactly once* premise of that same constraint and
  § 2.4's one-form-per-fact rule broken by the sentence invoking them. It is worst on the path A17
  exists to make visible: a reconnect onto a feed that then dies (§ 9 F1) freezes the hands at the
  reconnect minute and the text at an **older** one, so a screen-reader user is told a *different*
  wrong time from the one on the wall. **The repair is to bind the text to the hands rather than to
  a named driver** — *set in the same render that sets the hands*, with constraint 4 left owning
  which renders those are — so the coupling survives the driver set moving again, and no fourth
  phrasing of the set-vs-fire distinction is minted. ⭐ **The unset case is stated for the first
  time:** § 6.5 draws a never-live clock **unset**, while *the minute and nothing else* said nothing
  about a clock with no minute; the text is now decision 13's ***not reported***, never an empty
  string (an element with no accessible name is a clock assistive technology cannot find) and never
  a plausible time. **No gate caught any of this and one now does:** AT-D3-6's first read of the
  text fell inside the heartbeat phase, by which time a firing had set it either way, so the test
  passed on a defective client. It gains a GREEN that reads the text **before the first heartbeat**,
  where only the establishing render can have set it, and a **fifth RED** — the text driven by the
  firing alone — which passes every other assertion in the test and fails only that read.
  **Deliberately NOT changed, and reported instead:** § 2.5's 1 s-tick row (*advances on the
  heartbeat and on nothing else*) and § 5.5's row (*read at the moment a `feed.heartbeat` arrives*)
  are statements about what may **drive** motion, and § 6.5 is explicit that setting a value on
  first paint is not an animation of it — so both are true as scoped and neither is restated here.
  § 2.3's computed-values row was the one flatly false copy — *sampled when a `feed.heartbeat`
  arrives **and at no other moment*** is a universal negative § 6.5 contradicts — and it now points
  at constraint 4 rather than carrying its own spelling of the rule. **The reference artifact needed
  no change and is the evidence the property is buildable:** `floor-preview.html` already sets the
  `<title>` in `paintRoom()` on every path that moves the hands, already sets the room in
  `establishLive()`, and already renders unset as *no time set* — the implementation was right and
  the specification was wrong.

- **card#7897 (part 1)** — **the seat's task is a THOUGHT BUBBLE, and the bubble REPLACES the text
  chip rather than joining it.** `FLOOR.md` § 5.1 named the element *the task chip* in four places
  (§ 5.1, § 5.6, § 7.5 and AT-D3-14) while the operator-ratified reference artifact had already been
  drawing a bubble and no chip since 2026-08-26 — so the document and the artifact card#7341 builds
  FROM disagreed about the desk's single most visible element. The amendment is a **form** change on
  the test that decides one: the same five driving fields, the same honesty properties, **no D2
  change and no new § 6.2 row**, because nothing about the bubble moves. § 5.1 now carries the
  element's one statement — six rules — and every other site names it and points there. ⭐ **Three
  of the six are not restatements of the chip's rules.** *(1)* **One rendered form**: a chip
  surviving beside a bubble is one fact drawn twice (§ 2.4). *(2)* **A desk that draws no character
  draws no bubble** — the bubble is anchored to the character, and `stale`, `offline` and `retired`
  draw an empty chair or a cleared desk (§ 7.1). This is **the amendment's only truth-content cost
  and it is stated rather than hidden**: a dark desk that used to carry a chip now carries none, and
  the value is read in the drill-down under that panel's currency treatment — the fact is not lost,
  the desk's claim about a seat nobody can hear is. *(3)* **A title too long for its desk truncates
  with a MARK**, in a box sized from the measured text; a silently clipped title is read as the whole
  title, which is a claim the wire did not make. ⛔ **One directed behaviour is REFUSED and the
  refusal is recorded as decision 22 rather than left in a PR:** the ruling directs adoption of
  upstream's `0.15 s` fade-in → `1.2 s` linger → `0.3 s` fade-out state machine. Its linger is a
  timer (§ 6.3's second forbidden form), its fades are motion with no row, and the fade-out
  **collapses the null render** — once a bubble hides itself, *no bubble* stops meaning *`task` is
  null* and starts meaning *`task` is null **or** the linger expired*, and a null render two facts
  produce is not one. Upstream's machine is right for upstream: its bubble reports a **tool call**,
  an instant, where ours reports a **standing fact**. Its load-bearing half —
  re-show-swaps-text-without-re-fading, which exists to stop a busy seat's label strobing — survives
  for free, because a bubble that never fades cannot strobe. The overlap resolver and the
  measured-box sizing are adopted, as § 5.1 rules 5 and 4. **The reference artifact is corrected in
  the same change on two counts, both of which it was RENDERING:** its `.bub` carried a
  `4.5 s ease-in-out infinite` float — an un-held loop with no § 6.2 row, § 6.3's first bullet, the
  same defect shape as the `10 s` clock interval § 10.4 already refuses — and its character test was
  `render_state !== "stale"` **written twice**, once for the sprite and once for the bubble, so a
  sample seat in `offline` or `retired` would have drawn **both a character and a thought bubble on
  a desk § 7.1 says is an empty chair or cleared**. Both now select on one predicate,
  `hasCharacter()`, keyed on the member set. **Deliberately NOT fixed, and reported instead:** the
  artifact's `.glowpulse` — a `2.4 s` infinite pulse on every occupied desk's lamp, the meeting-room
  lamp and three status dots — is the same un-held-loop class, but it is ratified warmth on an
  operator-taste surface rather than this card's element, so it is surfaced for its own ruling.

- **card#7930** — **D1's harness-fact gate stopped being red for a reason that had nothing to do
  with D1, and D1's own re-capture obligation is discharged at 2.1.247.** `verify-harness-facts.py`
  pinned its ground truth to an absolute path ending in `2.1.240` — a build the installer
  garbage-collects — so it had emitted **31 failures** since the first harness update after it was
  written, **30 of them saying real hooks "are not declared in this build"**. A reader repairing D1
  to match that output would have deleted true facts, and *a gate that reds on correct work is a
  gate that gets ignored*. Three more build-specific literals were pinned beside it: the
  bundler-generated name `Ht` (regenerated every build), § 17's capture-run figures, and — the one
  that could have put a **false fact into the document** — global-first-match identifier
  resolution, which resolved `Notification.notification_type` to a 2-member set at 2.1.247 and to a
  **39-member list of shell dotfile names** at 2.1.243. It was right at 2.1.240 by luck. The build
  is now **READ from D1's five declared-build sites**, which must agree; the versions directory is
  derived from wherever `claude` on `PATH` resolves; identifier resolution is module-scoped and
  follows a bundled `import`/`export` pair to the declaring chunk; and when the declared build is
  absent the gate still reds — **fail-closed is the point** — but with ONE line naming the build,
  what is installed, and the fix. ⭐ **Newest-installed is deliberately not a default**: silently
  re-binding converts *"true of 2.1.240"* into *"true of today's box"*, which is the false clean
  the gate exists to prevent. ▶ **The re-capture then answered the card's real question:
  NOTHING D1 states about the harness moved** across `2.1.240 → 2.1.243 → 2.1.245 → 2.1.246 →
  2.1.247` — 0 of 22 fixture key sets, 0 of 9 enum value sets, 31 hooks, and every firing condition
  re-observed. **Two facts got stronger**: `PostCompact` and `SessionStart(source=compact)` were
  DOCS-CITED as *"not drivable"* and are now MEASURED (`claude -p --resume <sid> "/compact"` drives
  both), and `PostCompact`'s real key set is **exactly** what the stub declared — the first evidence
  this document has that a stub read from the binary is a fact and not a hope. **One thing is
  contested and deliberately left open**: three of three headless dispatch runs at 2.1.247 produced
  the **2.1.240** subagent lifecycle, not the 2.1.245 one D1 records — but that run varies *mode*
  as well as *version* (the 2.1.245 row was measured through a real TUI), so it settles nothing and
  **no fact was edited to prefer either reading**. Raw captures stay uncommitted per the 2026-08-23
  ruling; the rig was scoped with `--settings`, never by editing `~/.claude/settings.json`, because
  three other agents were live on this box. ⭐ **pm ruled the fork closed rather than open** — § 8.7
  already requires *both* lifecycles to be handled and AT-1 carries a GREEN case for each, so
  settling it would buy only the right to delete a trace the document must keep; **the primitive it
  exposed is fixed instead**: § 6.0 obligation 2 now names **MODE** (headless `claude -p` vs a real
  TUI) as a second axis beside VERSION, so *a fact measured in one mode is not established in the
  other* and the next re-capture records which mode a fact came from instead of re-opening that row
  at every build. Its **cost claim is corrected from estimate to measurement** in the same
  obligation — *"re-running it is minutes"* was **the argument the requirement rested on**, and the
  measured discharge was ten driven sessions, a fixture diff, ~25 restated version sites and
  propagation into the reporter; an obligation defended by an estimate an order of magnitude low is
  one that gets deferred, which is what happened across five builds. § 16 now **points at** that
  obligation instead of carrying its own copy of the trigger, and names the two levers that would
  make the next re-capture cheap.

- **card#7913** — **Gate 2 now runs over all of `resources/`, and Tiled's default layer encoding
  is refused rather than exempted.** Gate 2's argument became **universal** on 2026-08-27 (*every
  asset is a file Gate 1 can see*) while its enforcement stayed scoped to `resources/characters/`.
  So `resources/floor/` — the tree about to receive this project's **first vendored third-party
  art** (card#7341) — was covered by Gate 1 and by **neither Gate 2 clause**: a `.psd` there passed
  with a valid row, and a 40 KB base64 PNG pasted into a `.js` there had no path, no row and
  nothing to object. Both of those are now REDs in `bin/asset-provenance.selftest.py`, and both
  **passed** before this change. ⚠ **"Widen Gate 2" is a TWO-knob change** — the tree *and* the
  file-type allowlist — and moving one alone is not a widening: measured on a correct, CSV-only map
  set with zero base64 anywhere, widening only the tree puts **4 of 5 files RED**, not one of them
  for an embedded-bytes reason. Both knobs moved: clause 1 admits **`.tmx` / `.tmj` / `.tsx` /
  `.tsj`**, each with its reason in `FLOOR.md § 10.1`, **including the `.tsx` collision** (Tiled
  Tileset XML *and* TypeScript-JSX — harmless under `resources/` today, named so it is not a trap).
  ⭐ **New clause 3, and it is what lets clause 2's 1,024 B ceiling apply to the map formats with NO
  carve-out:** every Tiled artifact must store layer data **plainly as CSV** and **embed no tileset
  image**, read out of the artifact's own `encoding` / `compression` / `<image source=>` — no
  heuristic, no alphabet, no ceiling to tune. The embedded tileset image is the **true positive**:
  image bytes inside a map, with no path, therefore no row, therefore no provenance. Exempting
  `.tmj`/`.tmx` from clause 2 would have re-opened exactly that. ⭐ **And the reason it is CSV rather
  than a carve-out is a measurement, not a preference:** `looks_encoded()` is an AND over three
  character classes, so a run missing one passes clause 2 **at any length** — and Tiled's ordinary
  uncompressed base64 layer data (little-endian `uint32` GIDs) misses one routinely. Over a uniform
  1,200-tile map at every GID in `0..255` the run is **6,400 B in all 256 cases** and **154 of them
  pass clause 2**, including **GID 1, the first tile of the tileset**. A carve-out would have had to
  be reasoned against a verdict that flips when an artist repositions a tile. The selftest carries
  that coin as a pair — GID 1 evades clause 2 and clause 3 catches it anyway; GID 14 trips both.
  **The understated residue is corrected in the same change, on both surfaces that carried it:**
  `bin/asset-provenance.py` called the one-class evasion *"a deliberate shortcut nobody takes by
  accident"* — **ordinary machine output takes it by accident**, and that sentence was a live claim
  in a gate's stated-residue list that a reader draws a conclusion from. Narrowed to what is true,
  not deleted, in the module docstring, at the predicate, and now in `§ 10.1` clause 2, which had
  never stated the residue at all. **Selftest 43 → 58 fixtures**, all seen failing under three
  reverting mutations. **Out of scope, deliberately:** clause 1 still trusts the extension and
  sniffs no magic bytes (a separate question, recorded in the gate's docstring and § 10.1).
- **card#7912** — **the ratified floor-preview stops encoding three things the spec does not
  support.** `docs/design/floor-preview/floor-preview.html` is the artifact card#7341 is specified
  to build FROM, so a divergence in it propagates by design rather than by accident. **(1) `thinking`
  was an eleventh `render_state` in seven places** — a badge class, a `busyTop` test, the sample
  seat's own wire field, a colour map, a monitor branch, a glyph/label map and a drill-down branch —
  and the drill-down then mapped it **back** to `working` for display, which is both the tell that
  the author knew it was not a wire member and *precisely* the mapping `FLOOR.md` § 5.4 forbids by
  name. It is not a state: it is § 6.2 **A4**'s holding condition over a `working` seat
  (`render_state == "working"` ∧ `open_calls == 0` ∧ `open_turn == true`). The artifact now derives
  the **pose** from those three fields in one place (`poseOf`) and the drawing layer never sees a
  state name at all; the sample seat carries `render_state: "working"` with the two fields that
  select the pose, which is the data that should have driven it all along, and the drill-down shows
  `render_state` verbatim beside the condition. **The state chip renders the STATE** (§ 5.1: *pose
  and glyph | `render_state`*) — so the A4 seat's chip reads *WORKING* where it used to read
  *THINKING*, and the think pose is carried by the pose treatment it always belonged to: the `…`
  marker and the desk's state line. **D2 was not touched, deliberately**: the state set is closed and
  derived, and adding a member to carry a client-side pose would put a rendering concern on the wire.
  **(2) The lobby's per-floor summary iterated its own member list**; it now iterates § 7.1's fixed
  ten-member order, which § 4.1 requires — the same root cause, so the same fix. **(3) The wall clock
  and sky ran on a 10 s `setInterval` off the viewer's clock** — § 6.3's second forbidden form, and
  the mover that keeps moving after the feed dies. They now hold **one value**, set by a delivered
  `feed.heartbeat` and by nothing else (**A17**), so every other render — a zoom, an elevator ride, a
  sky repaint — draws the held value and cannot move it. The minute hand steps once a minute with no
  seconds term (**A17 constraint 1**), the hands' minute is carried as accessible text set on every
  path that moves the hands (**constraint 5**, and in the corrected form card#7936 will bring the
  document to), a live-establishing render **sets** the room while F1's 10 s poll sets nothing
  (**§ 6.5**), and a room that has never been live is drawn **unset** — hands hidden, flat sky —
  never at a plausible time. ⭐ **The artifact now demonstrates the argument instead of asserting
  it:** a *simulated feed* control switches between **live**, **down** and **never live**, and on
  *down* the clock holds while F1's poll count climbs beside a *polling (feed down)* status line —
  which is what makes a frozen clock legible as the feed-down claim rather than as a minute that
  happens not to have changed. The one `setInterval` left is the **feed** itself, whose entire body
  is *deliver a heartbeat*; it is what a socket does in the build. `README.md`'s *"live wall clock +
  day/night windows from the VIEWER's clock"* — the last sentence in the repo an implementer could
  read as licence for the interval — is corrected and points at A17 rather than restating it.
  **Untouched:** `docs/design/FLOOR.md` (card#7913 is editing it concurrently), D1, D2, the ratified
  look everywhere it is not the defect, and the staged `coord.*` communication layer.
- **card#7341** — **the floor's wall clock and day/night sky advance on `feed.heartbeat`, so they
  stop when the feed does.** The operator ruled on 2026-08-27 between three options
  (`docs/design/FLOOR.md § 13` decision 21 records all three): ship them **static**, carve an
  exception into § 6.3 for viewer-clock decoration, or drive them from a **delivered message**. The
  third is not a compromise — it is the only one that makes the element *earn* its motion. The
  ratified reference re-renders clock and sky on a **10-second interval from the viewer's clock**,
  which is § 6.3's second forbidden form and, worse than a rule violation, is a **second mover that
  keeps moving after the feed dies**: the page then never goes still, and AT-D3-6 (*the feed dying is
  visible within 45 s*) loses the observable it asserts. Driven by the heartbeat, **the clock stops
  with the page — and a stopped clock is the feed-down claim in the form every human reads
  instinctively.** ⭐ **§ 6.2 gains A17** (`edge`, driver `feed.heartbeat`, absence = *no message has
  arrived, which at 45 s is the feed-down condition itself*), and the note under that table no longer
  says A14 is *the only thing that moves unconditionally* — a sentence A17 makes false — but the
  **property** it was protecting, which is stronger: *everything on this page that moves without a
  delivered field holding it is driven by the heartbeat, so when the feed dies all of it stops
  together.* **Five constraints ride with the row**, each a defect if dropped: **no second hand** —
  the heartbeat is 15 s, so the minute hand **steps once a minute and is merely sampled four times**,
  and a hand jumping in 15 s steps is the *looks broken* that gets repaired with a timer; the clock
  reads the **viewer's** clock with only its **sampling** event-driven, reconciled at § 5.5 as the
  client's own narration and never a fact about a seat; it is **no authority on the time and grows no
  *as of* stamp** — the status strip already says how current the page is; a render **sets** it
  rather than animating it, and ⭐ **only a render that establishes or re-establishes a LIVE feed sets
  it** — a render the client makes *because* the feed is down never does, which is what keeps F1's
  **10 s poll** from handing the clock back the interval this ruling removed, under the name
  *setting*; and the hands' minute is **readable as text exactly once** (the element's accessible
  name), because an analog clock has no string for a test to assert on and an implementer would
  otherwise invent the target. **AT-D3-6 now ASSERTS the freeze**, in both directions and in a form a
  conformant client can satisfy: its heartbeat phase **crosses a minute boundary**, and the rendered
  minute advances **exactly once — on the heartbeat that crossed it, not on the other three and not
  between them** — then is identical at every read after the feed stops, across the boundary the
  silence phase spans. A **third RED is the exact repair a maintainer will attempt**: put A17 back on
  a 10 s interval and watch the freeze fail at that boundary. § 6.3's timer bullet gains the
  **driven-by versus read-at** distinction that decides the case (*motion that stops is caused;
  motion that continues was on a timer*), § 4.2's enumeration of what the floor screen contains now
  names the room, § 10.4's ⛔ *not admitted* bullet is replaced by the ruling, and § 6.4 **names the
  five animation rows whose reduced-motion form no test asserts** (§ 14 item 15) rather than claiming
  a coverage the two tests do not provide. **Out of scope and untouched:** the animation itself
  (this is the spec), `docs/design/floor-preview/floor-preview.html`'s own `setInterval` (card#7912
  owns the artifact), D1 and D2, and every other § 6.2 row.
- **card#7898** — **D3 admits the ratified art direction, and the asset gates move from absence
  to declared provenance.** The operator ratified a high-resolution, whimsical, modern
  (Ghibli-adjacent in *feel*) direction on 2026-08-26/27; `docs/design/FLOOR.md` as written
  **forbade it in three places** and its CI gate would have red on the first asset it needs.
  **§ 10.1 — Gate 2 stopped asserting an absence.** *"No image file in the character tree"* was
  the mechanised form of *the sprites are generated*, and it became false about the product
  rather than merely strict. It is replaced by the claim Gate 1 needs in order to mean anything:
  **every asset is a file Gate 1 can see**. The manifest gains a seventh column, **`origin`**, a
  closed set of `first-party` / `licensed`, each checked against the row's own source URL; the
  character-tree allowlist widens to `.ts` `.js` `.md` `.svg` `.png`, every member with its
  reason written down and `.avif` / `.webp` / `.jpg` / `.psd` / `.dat` still failing by name; and
  clause 2's purpose is restated as the load-bearing one — **an asset embedded inside another
  file has no path, so no row, so no provenance**. **What it COSTS is named in the document
  rather than discovered later**: the old gate was self-verifying, the new one rests on a row
  being *true*, and somebody who vendors commercial art as a `.png` under a `first-party` row
  passes everything. `bin/asset-provenance.py`, `docs/ATTRIBUTION.md`,
  `resources/characters/LINEAGE.md` and the workflow comment all say so.
  **§ 6.3 — the ambient-life bullet was SHARPENED, not deleted.** The ratified micro-animation
  (blink + wiggle while busy, slumped-asleep idle with drifting z's) was forbidden by a bullet
  that named *blinking*. The rule is now the **property** that bullet's own reason states —
  *motion neither held by a delivered field nor caused by a delivered message* — and every named
  motion survives as an example of it. A blink in every state is still forbidden; a blink held by
  a § 6.2 row's condition is the drawn form of that row. The only door in is still a § 6.2 row.
  **A6 (`idle`) gains a held loop** and its reduced-motion form is the static slumped pose; ⭐
  **a sleeper is never a gone seat** — `stale` / `offline` render the empty chair, stated as a
  § 7.5 rule and asserted by AT-D3-5 (with motion) and AT-D3-13 (without).
  **§ 4.5 — the capability floor is a property, not a technology**: a renderer crisp *at any
  camera zoom without resampling artefacts*, where it read *a 2-D tile renderer drawing sprite
  frames*. The camera is **navigation and animates nothing** — no § 6.2 row. The 1,280 × 800
  floor **stays, and the reason is restated** because *"it's vector now, it scales"* is exactly
  the argument the next reader will make: the rule is about **legibility**, never pixel density.
  **§ 10.4 is new** — the art direction as a specification, because the ratified artifact is not
  one: the ten seeded dimensions (7 silhouettes × 16 hues × 5 sizes + pattern/ears/sprout/eyes/
  mouth/accessory/tilt, **8,064,000** tuples), the intern seeding, ⭐ **the salt is a design
  choice — on visible repetition, widen the space or re-pick the salt, NEVER special-case a
  seat** (a special-cased seat is a stored appearance wearing a disguise), and the collision
  acceptance stated as a figure to be **measured** rather than the birthday estimate this entry
  is careful not to pass off as one. **§ 10.5 is new** — the IP line, **stated as unenforceable
  by gate**: no character owned by another rights-holder ships, and *review*, not a script, is
  what enforces it. The seeded **vibe line** is reconciled with § 5.4 honestly rather than by
  exception — appearance-class text is a rendering of *identity*, labelled as seeded, and drives
  no pose, label, badge or animation.
  **AT-D3-12's RED set was rebuilt** — its *vendored character* case tested a rule that no longer
  exists and would have passed vacuously. **Every new guard was seen to fail**: nine deliberate
  mutations of the gate, each watched red and restored. ⭐ **The control that matters most is the
  SVG false-positive pair** — a complex first-party SVG carrying a 1,926 B minified integer path
  must PASS while an SVG with an inlined `data:image/…` blob must FAIL. The first draft of that
  control did **not** discriminate (its longest run was 20 B, which any alphabet passes); it was
  rebuilt around the shape that actually collides — minified integer path data, where `-` is the
  separator — and clause 2's alphabet was narrowed from base64URL's superset to base64's own,
  which is what makes the drawing pass. **A gate that reds on correct work gets switched off.**
  `docs/PLAN.md` records D-07's supersession as an **append**, not an edit — a register records
  what was decided when. **Out of scope and untouched:** the `coord.*` event family and the
  communication layer (card#7897 — events before animation), the floor build itself (card#7341),
  D1, D2, § 8.1's cap arithmetic and the licence allowlist.
- **card#7341** — the operator-ratified INITIAL DESIGN for the floor UI lands as a working reference artifact (`docs/design/floor-preview/`): building cross-section + elevator navigation, per-floor themes, seeded 7×16×5 characters with one intern sprite per open subagent (cap 8, then +N), held-loop micro-animation, viewer-clock sky, and the staged coord.* communication layer. The build implements this; customization comes later by operator direction. *(This bullet was landed in the file's PREAMBLE — between two halves of a sentence, above `## [Unreleased]` — and is moved here by card#7898. `docs/VERSIONING.md`'s release step collects what is under the section heading, so a bullet above it is a changelog entry no release would ever pick up.)*

- **card#7837** — The fold sampled its version-bearing fingerprint AFTER the projector wrote, so
  every projector-written member was invisible to the delta feed. `StateRecompute::after()` read
  `SeatFacts::versionBearing()` on its own first line, which in `Fold::window()` is *after*
  `Projector::apply()` — making `$before` and `$after` identical on `context.*`, `model_label`,
  `enabled`, `selftest_failed`, D1's reporter badges and the `calls` rows behind `subagents`.
  **Measured on the suite's rig, before → after:** a `context.sample` emitted **no delta at all**
  → `changed: ["context","model_label"]`; an `enabled` flip emitted
  `["link_state","render_state"]` → `["enabled","link_state","render_state"]`. (Card #7827's entry
  above recorded the `context.sample` case as `changed: ["badges"]`; on a fixture where no badge
  moves it emits nothing, which is the same defect one step worse.) **The fix is ONE fingerprint
  sampled earlier and explicitly NOT a projector-returned diff**, which would be a second
  implementation of § 6.5's version-bearing set free to disagree with the first. `$before` is now
  a REQUIRED argument on both `after()` and `forSeat()` — required rather than defaulted so no
  call site, and no test seam overriding them, can keep sampling on the wrong side; the rule is
  the same one line everywhere: *sampled before the first write of the unit of work*. **Four fold
  call sites**: `Fold::window()`, `Fold::recoverOneAtATime()` (sampled inside the retry's own
  transaction, because attempt 1 may have written and rolled back), `Fold::quarantine()` (where
  nothing writes first, so the value is unchanged — stated rather than left to be inferred), and
  `mezzanine:rebuild`, which the card did not name and which § 6.6 requires derive through the
  fold's code rather than a copy of it. **A sibling audit found the same shape in two more
  writers, both fixed**: `mezzanine:retire` set `seats.retired_at/by/reason` before settling, so
  the delta announcing a retirement carried `render_state: "retired"` and left the client's
  `retired` object null (`["render_state"]` → `["render_state","retired"]`);
  `Sweep::orphanCloses()` and `Sweep::quiesce()` close `calls` rows before settling, and
  `subagents` / `subagents_open` read `calls` directly, so a desk kept rendering an intern the
  server had closed. Both are driven separately — job 2 fires on a still-live seat at the call's
  own 60-minute ceiling, job 6 only once the seat is `offline`, and a fix reasoned about for one
  of them is a fix with one instance of evidence; each patch gained `subagents` and
  `subagents_open`. **One instance of the class is reported and NOT fixed**:
  `Sweep::leavingLive()` has no settle of its own and defers to JOB 1, so its `stalled_since`
  clear lands above JOB 1's sample and `api_error_type` never reaches the wire. Moving JOB 1's
  sample above it was measured to be worse — JOB 6 settles in between and writes `render_state`,
  so a wider `$before` mints a SECOND transition row for one physical event (`[offline_quiesce,
  staleness_sweep]` where § 4.6 allows one), and **all 68 of the sweeper's existing tests passed
  under that mutation**, so `SweepJobsTest`'s job-6 case gains an exact-row-list assertion that
  was seen to fail under it. The fix is a decision about which settle owns JOB 5's writes; the
  hazard is recorded at the call site. **AND A SECOND, DISTINCT DEFECT IN THE SAME METHOD, JUDGED
  SEPARATELY AND ALSO FIXED:** `taskTier3()` re-stamped `task_as_of` to `now()` on every recompute
  while a title existed, and `task` is version-bearing — measured at **20 deltas over 20
  heartbeat+sweep passes, every one `changed: ["task"]`, now 0**. `as_of` is what § 4.9's
  freshness bounds are measured against, i.e. when the tier's value was *obtained*; re-stamping an
  unchanged answer claimed it had been re-obtained by a pass that only re-read it. Not fixed by
  dropping `task` from the fingerprint: § 6.5 states that set as a closed subtraction of ten named
  members and adding an eleventh is a D2 change. Two suite cases that had to fence their fixtures
  to a seat with no open call to dodge this now say so and are joined by one that drives the
  open-call fixture directly. `FeedSurfaceTest::test_a_projector_written_member_reaches_the_delta`
  is complete and green (it called `markTestIncomplete()` on its first line); every new assertion
  was seen to fail against the old ordering first, and each carries a control plus a REST-snapshot
  check that the surface which was already correct stayed correct. 297 tests / 3,643 assertions,
  from 293 / 3,561 with one incomplete. ⚠ Run on SQLite: this seat has no MariaDB credential, and
  nothing changed here is engine-specific.

- **card#7827** — Fleet-state PART B: the REST read plane and the WebSocket delta feed —
  `docs/design/FLEET-STATE.md` §§ 8.2, 8.2.1, 8.2.3, 8.2.4, 8.3, 8.4, 8.5, 8.6 and 9. **The four
  REST endpoints** (`/api/fleet/snapshot`, `/seats/{i}/{s}`, `/seats/{i}/{s}/timeline`,
  `/health`), the § 8.2.1 seat object in full, § 8.2.4's fleet-health object stated once and
  carried by all three of its surfaces, and § 4.10's 14-day retired-seat READ FILTER as one
  predicate every read query shares. **Read-side auth** (§ 9): the `feed_tokens` store, a `mzr_`
  `fleet_read` credential with issue/revoke commands, revocation checked per request and never
  cached, `token_wrong_surface` for an `mzn_` ingest token, and § 9's 120/600 req-min limits —
  each seen to fire and seen not to — plus D1 § 12.3's failed-authentication limit on the REFUSAL
  path, which took no rate-limit slot at all before, spending `RateLimiter::hitFailedAuth()`'s
  existing per-source-address budget rather than a second one. A request presenting NO credential
  is excluded (it is unauthenticated, not a failed authentication, and this surface has browsers),
  and so is a token that resolves to a revoked or expired row — D1 § 12.3's own exclusion, which
  matters twice over on a shared budget. **The timeline pages on a KEYSET CURSOR** `(received_at,
  id)`, issued by the server as the response's `next_before` and decided by a `limit + 1`
  look-ahead: `received_at` alone is not unique — one batch stamps one value across up to 200
  events — so the strict `received_at < ?` cursor a client could only derive from the response
  skipped every event sharing the boundary timestamp. Measured before the fix at 120 events in one
  batch: page 1 served 50, page 2 served **0**, and 70 were unreachable — `200 {"events": []}`,
  the one shape `ReadRefusal::badCursor()` exists to refuse, produced from a well-formed cursor on
  ordinary traffic. ⚠ A bare timestamp is now refused `422 bad_cursor`, which TIGHTENS what the
  endpoint accepts: § 8.2 names the parameter and specifies no type for its value, and refusing the
  assembled cursor is what keeps the lossy form from being re-derived by the next client.
  **The feed** (§ 8.3): four of its five message types as
  broadcastable events sharing one envelope and one channel name, published from the ONE place
  `state_version` is bumped, and a 15 s `mezzanine:feed-heartbeat` daemon that is deliberately not
  the sweeper's. `App\Events\SeatRetired` — card #7712's declared publication point — now reaches
  the wire. **AT-D2-7, AT-D2-8, AT-D2-16, AT-D2-19 and AT-D2-20 ship with their REDs DRIVEN**, and
  **AT-D2-21 and AT-D2-23 are now COMPLETE**: their primary REDs were wire-surface assertions card
  #7712 shipped undriven, and both are driven here (the omitted `fold_lag_ms`, and the vanishing
  desk). 56 new tests, 285 total.
  **⛔ AT-D2-15 IS NOT DELIVERED AND IS NOT APPROXIMATED.** Per-connection backpressure is a
  property of the socket server's outbound queue, which no application publish can observe — and
  `laravel/reverb`, § 8.3's pinned transport, is NOT INSTALLABLE on this tree: every version
  through v1.11.1 needs `guzzlehttp/psr7 ^2.6` against this application's `3.1.0`, and
  `composer require -W --dry-run` resolves only by downgrading guzzle 8.1.0 → 7.15.5, promises
  3.0.2 → 2.5.3 and psr7 3.1.0 → 2.13.1. A backpressure test written against a mock would test
  the mock. **Six D2 findings are REPORTED, not patched — the design doc is untouched:**
  (1) § 8.3's 250 ms COALESCING and § 8.5's `delta.state_version == local + 1` cannot both hold,
  because a merged message is indistinguishable from a lost one — this card ships one delta per
  version and names the cost; (2) § 8.2.4 declares five members non-null while declaring
  `db: "down"` reachable on the same object, so they are ABSENT rather than invented on that path;
  (3) § 8.2.3's `detail` enumeration omits this plane's `seat_predicates`, which Appendix A's S11
  requires per seat per predicate — added as an additive member under § 8.1's own rule, and
  § 8.2.4 was NOT its home (`sweep_seat_error`, card #7832, needs none: it is already a
  `seat_counters` row); (4) AT-D2-19's "redirect to the MFA challenge" contradicts § 2.2, and
  § 2.2 wins; (5) AT-D2-16's "closed at its materialized `orphan_due_at`" reads two ways and card
  #7712 chose one — asserted here only on what both readings share; (6) § 8.4 step 5's watermark
  and § 8.5's discard are the same comparison, so one is unobservable without the other.
  **⚠ AND ONE CARD #7339 DEFECT, FOUND HERE AND NOT CROSSED INTO** — *both defects in this
  paragraph were CLOSED BY `card#7837` below; the description is kept as the finding record and is
  no longer a statement about the code:* `StateRecompute::after()`
  samples its version-bearing fingerprint AFTER `Projector::apply()` has written the event, so
  every projector-written member — `context.*`, `model_label`, `enabled`, `selftest_failed`, D1's
  reporter badges, a late `subagents[].title` — is invisible to both the bump decision and the
  delta patch. Measured: an `enabled` flip publishes `changed: ["link_state","render_state"]`; a
  `context.sample` publishes `changed: ["badges"]`. The SNAPSHOT carries all of them correctly, so
  the watchdog and § 8.4's join are unaffected and a reload heals a browser; recorded as an
  incomplete test naming the mechanism and the affected population rather than left green. A
  second, smaller one is reported the same way: `taskTier3()` re-stamps `task_as_of` on every
  recompute, so a seat with an open call emits a delta on every fold pass — the 16 % of pure noise
  § 8.3 refuses. **⛔ AND A `blob` SHIPPED WHERE § 6.4 DECLARES `VARBINARY(16)`, IN BOTH TOKEN
  TABLES:** `$table->binary()` with no length compiles to `blob` on MySQL — `MySqlGrammar::typeBinary()`
  emits `varbinary({$length})` only `if ($column->length)` — and the suite runs on SQLite, where the
  two are the same, so nothing could notice. Fixed in `feed_tokens` and in the identical line
  card #7338's `ingest_tokens` migration carries, and now GUARDED: `MySqlColumnTypeTest` compiles
  the real migrations through the real MySQL grammar with no MySQL server, which proves the SQL
  text and explicitly not the engine's enforcement of it. **Three more not-delivered items are named
  rather than left to be inferred** — `fleet.health` ON CONNECT (§ 8.3 requires it; only the change
  half exists, and the cost is the up-to-15 s latency § 8.3 itself names, NOT blindness, because the
  unconditional heartbeat carries the same object), `feed_resync_required` (no writer anywhere in
  `app/`, and downstream of AT-D2-15 rather than independent: § 8.5 increments it only at the
  socket server's backpressure close), and § 6.4's `revoked_reason`, a column this application
  creates that the document's DDL does not contain. ⚠ Written for both engines and tested on SQLite;
  every MySQL-specific behaviour
  left unexercised is enumerated in the PR body (card #7523, the store host), and there is no PHP
  test lane in CI (card #7344), so this suite is SELF-ATTESTED and the mutation evidence in the PR
  body is the load-bearing part.

- **card#7712** — The three processes `docs/design/FLEET-STATE.md § 2.1` names and neither half of
  card #7339 built. **`mezzanine:sweep`** — a supervised 15 s daemon applying § 2.1's seven
  time-derived jobs (staleness, orphan-timeout closes, attention ceilings, compaction ceilings, the
  leaving-live clears, offline quiescence, the § 5 predicate-constant alarms), recomputing
  `link_state` / `render_state` for every seat and bumping `state_version` under § 6.5's per-writer
  rule; it is what makes `stale` reachable at all, because a seat that has stopped sending has no
  unfolded events and is never claimed by the fold. **`mezzanine:purge`** — hourly, scheduled,
  bounded 5,000-row batches under a 60 s budget, with `purge_backlog_rows` when it falls behind and
  a hard REFUSAL of any retention below `D2-MUST` #3's 10-day dedup window (§ 2.2: "deleting on a
  broken assumption costs the dedup guarantee — the safe direction is to keep"). **`mezzanine:retire`**
  — the only writer of retirement, doing § 4.10's whole act in one transaction: the three columns,
  the recomputed `render_state`, the `cause: operator` transition row, the `state_version` bump and
  the `seat.retired` publish, each of which had no producer before. The publish is dispatched
  **inside** that transaction and carries `ShouldDispatchAfterCommit`, so it is ordered by the act
  that sets the columns and is delivered only if the act commits — a rollback reaches no client.
  Creates § 6.4's `seat_predicates` and records all seven § 5 predicates at their own evaluation
  sites. **One seat's failure costs one desk and no longer kills the daemon:** the per-seat pass is
  inside an error boundary that logs, counts `sweep_seat_error` and continues, and a pass reports
  how many seats it skipped — without it a single reachable raise (§ 2.3's unseeded cursor clock)
  exits the process and, under a supervisor, crash-loops the fleet's time-derived transitions.
  **AT-D2-21** (a frozen fold cannot look healthy — `fold_lag_ms` computed from a basis two
  processes write, the badge, the episode counter, the never-folded seat) and **AT-D2-23** (a
  retired seat is rendered, not disappeared) have their store-side REDs driven rather than
  described; the PRIMARY RED of each is a wire-surface assertion Part B owns and neither is claimed
  complete here. 59 tests; every one of the seven jobs and every new check was SEEN TO FAIL under a
  named mutation whose landing was proved by `git diff` — including one mutation that did NOT red,
  which corrected a false claim in this card's own comments (job order is not what makes § 6.4's
  four deleted ENUM members unreachable; the disjointness of the two jobs' write sets is).
  **Three D2 gaps are REPORTED, not patched — the design doc is untouched:** § 6.4 declares no home
  for § 8.2.4's `sweep_last_run_at` / `purge_last_run_at` (a `plane_state` table is added and
  flagged), no receipt column for § 4.6's compaction ceiling
  (`sessions.compaction_open_received_at`, flagged), and `seat_predicates` cannot express § 5's own
  rolling-window criteria — so those four criteria are **not evaluated at all**: the alarm returns a
  named `cannot_evaluate` outcome rather than guessing in either direction. An earlier revision
  approximated them and claimed the error ran in a safe under-firing direction; that claim was false
  in both directions (a wall-clock proxy fired on the first evaluation after a sweep outage, and a
  cumulative share latched permanently on a months-old incident), and the approximation is gone
  rather than tuned. ⚠ Written for both engines and tested on SQLite: the sweeper's per-seat
  work takes no row locks, so `FOR UPDATE SKIP LOCKED` is untouched by this card and remains
  UNEXERCISED (card #7523, the store host) — and one MySQL-only exposure is now NAMED rather than
  wrongly justified: `Predicates::record()` is a read-modify-write, two of the seven predicates are
  written by two different processes, and the `SKIP LOCKED` claim its docblock used to rest on
  excludes only other *fold* workers. On the pinned engine that is a lost update, and the two
  writers take `seat_predicates` and `seat_state` in opposite orders. Corrected in the docblock,
  carried onto #7523, not fixable here. **Deploy note:** `sessions.compaction_open_received_at` is
  added NULLABLE with no backfill while `compactionCeilings()` requires it NOT NULL — no live data
  exists yet, so pre-existing open compactions cannot be stranded; a backfill would be owed if that
  ever stopped being true. The WebSocket delta feed and the REST snapshot remain Part B's, and the
  feed-bound halves of both acceptance tests are named rather than approximated.

- **card#7339** — PART A of the fleet-state card: the store schema and **the fold** — everything
  that turns accepted events into seat state. Creates `docs/design/FLEET-STATE.md § 6.4`'s
  remaining projection tables (`sessions`, `calls`, `attention_requests`,
  `seat_state_transitions`); adds `App\Fold\*` — § 4.3's derivation, § 4.5's link cascade,
  § 4.2's collapse, § 6.5's per-seat claim / visibility lag / purged-window branch /
  poison-event rule, and a projection for every one of the fourteen kinds the ingest accepts —
  plus `mezzanine:fold` (§ 2.1) and `mezzanine:rebuild` (§ 6.6), which shares the fold's
  `project()` rather than a copy of it. Fourteen acceptance tests from § 11 (AT-D2-1, -2 both
  hook orders and Case β, -3, -4, -5, -6, -9, -10, -11, -17, -22), each seen RED under its own
  named mutation before green, with the mutation's landing proved by `git diff` rather than
  assumed. **Doc-sync: § 6.4's `sessions` gains `last_turn_background_tasks_open`** — the fourth
  component of § 4.3's `L`, which card #7337 added to the derivation and not to the DDL; rule 4,
  the only rule that can mint `idle`, reads it. ⚠ Written for both engines and tested on SQLite:
  `FOR UPDATE SKIP LOCKED`, `ascii_bin`, `ON DUPLICATE KEY`, `ALGORITHM=INSTANT` and
  `DATETIME(3)` are UNEXERCISED, and `SKIP LOCKED` is the fold's concurrency correctness
  (card #7523, the store host, is the operator dependency that closes this). The WebSocket delta
  feed and the REST snapshot are Part B; the sweeper, `mezzanine:purge` and `mezzanine:retire`
  are claimed by neither part.

- **card#7686** — `tools/design/verify-fleet-state.py`'s G6 was RED on `dev`, and the defect was in
  the guard: its predicate read **any** `D2` mention in a D1 section as an unmarked obligation, so
  card#7338's `§ 6.5` doc-sync — which *cites* D2 § 6.4's existing `calls.synthesized` column as
  corroboration, and imposes nothing — was flagged as an obligation with no marker and no Appendix A
  row. Marking it `D2-MUST` would have been false and an Appendix A row would have recorded an
  obligation that does not exist. `docs/design/EVENT-SCHEMA.md § 1` now declares a fourth form,
  **`D2-CITED:`**, for a sentence that references D2 without constraining it, and G6 subtracts those
  lines. It is the one form that subtracts, so it is fenced three ways and each was seen to fail on a
  plant: an **unmarked** D2 mention still reds (silence is never the citation case — the S29 shape is
  untouched), an obligation marker on the same line wins over it, and a `D2-CITED:` line must name
  the place in D2 it cites and must carry no deontic language — an obligation cannot pass by wearing
  the marker. A new CONTROL holds D1 § 1's declared vocabulary and the tool's greps together, so a
  form renamed in the document and not in the checker reds instead of silently forgiving. Appendix
  A's derived 28/1 split is unchanged.
- **card#7338** — The batch ingest endpoint: `POST /api/ingest/events` and
  `GET /api/ingest/health`, implementing `docs/design/EVENT-SCHEMA.md § 12` — the eleven
  validation steps in their stated order, the error bodies of § 12.2, the four rate limits of
  § 12.3 (the failed-authentication one evaluated inside step 4, where its subject is reachable),
  atomic batches, per-event dedup and the § 12.7 counters. Per-seat `mzn_` tokens stored as
  SHA-256 only, issued and revoked by `mezzanine:ingest-token:issue` / `:revoke`. The routes carry
  **no middleware at all** — no session, no CSRF, no MFA and not Laravel's stock `api` throttle —
  so the surface is machine-to-machine in structure rather than by convention, and the separation
  is asserted both ways. Creates the store tables the ingest writes (`installs`, `seats`,
  `batches`, `events`, `seat_state`, the two counter tables); the fold and feed tables are
  card #7339's. Validated against the real `fleet-reporter` over TLS with certificate
  verification on (`server/tests/roundtrip/ingest-roundtrip.py`), which also drives AT-9 and
  AT-13's reporter half. Fixes a wildcard CORS header the unpublished stock config was applying
  to every `/api/*` route, including the MFA-gated snapshot. Doc-sync: `§ 6.5` gains the
  `synthesized` field row that `§ 6.6` has always mandated, and `§ 12.2` splits the one `422` row
  into the two codes `§ 12.1` actually names.
- **card#7337** — Ran D1's AT-1 against a real `/clear` on a real seat, and **it failed** — the
  reporter behaved exactly as `docs/design/EVENT-SCHEMA.md` § 8.2/§ 8.3 specify, while the design's
  guarantee did not hold on the installed harness (2.1.245). Three measured facts: a killed call
  **does** fire `PostToolUseFailure` (`Exit code 137`, `is_interrupt: false`) under the new
  `session_id`; a dispatched subagent runs as a **background task**, so the parent's turn ends clean
  while it works; and a conforming consumer therefore minted *idle* on a seat running a subagent.
  Amended in consequence: `D2-MUST` #1 and D2 § 4.3's `derive_activity` gain
  `background_tasks_open == 0` (and `session.end` clears that one component of `L`, so an idle that
  is **true** after the reap is not suppressed); § 6.6 gains a two-signal **kill signature**
  (`is_interrupt`, **or** exit 137 across a session boundary — exit 137 alone is an OOM kill and a
  genuine failure); § 8.6 refuses a cross-session late close and counts `late_close_cross_session`;
  § 6.0's re-capture obligation widens from minor to **any** version change, because a patch bump is
  what moved the lifecycle. AT-1 is rewritten against that lifecycle, with its idle assertion
  narrowed to the transitions that are false. New rig `tools/at1-kill-vs-complete/` drives the proof
  end to end under a scratch config, with a hermetic `selftest.py`, a RED plant that raises rather
  than silently applying nowhere, and an **operator-run** credential prerequisite the rig refuses to
  script. Whether the harness behaviour changed between 2.1.240 and 2.1.245 is **not established**.

- **card#7334** — Laravel application skeleton in `server/`, with mandatory MFA on stock
  packages (Fortify + `pragmarx/google2fa`). MFA gates three surfaces independently — the
  browser pages, the websocket handshake (`/broadcasting/auth`), and the REST snapshot route
  — through one middleware, `EnsureTwoFactorSatisfied`. Fortify's stock passkey and
  self-registration features are disabled: a passkey completes a login on its own, and
  Fortify logs an un-enrolled user in on a password alone, so `auth` by itself never meant
  "MFA-satisfied". Test-store isolation is pinned and guarded per
  `docs/design/FLEET-STATE.md § 6.2`. Repo layout decision recorded as D-16.
- **card#7340** — Ported munder-difflin's procedural character generator into
  `resources/characters/` as dependency-free ES modules, seeded from `(install_id, seat_id)` so
  a seat's character is identical on every browser and every reload with nothing stored. Added
  `docs/ATTRIBUTION.md` (the asset manifest), `resources/characters/LINEAGE.md` (upstream, the
  pinned commit, the reproduced MIT notice, and what was deliberately not taken), and
  `bin/asset-provenance.py` + its RED fixtures enforcing `docs/design/FLOOR.md § 10.1`'s two
  gates and AT-D3-12's lineage half. The new workflow is **not** a required status check.

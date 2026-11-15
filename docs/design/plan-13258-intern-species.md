# card#13258 — design and build plan: every character in the building is a different species until every species is in use — interns too

**What this file is.** The design and the build plan for one card, in the shape `plan-11818-reporter-outdated.md`
set and `plan-13117-seat-identity.md` follows: written for the coder who implements it and the reviewer who attacks
it. It owns the *derivation*, the *sequence* and the *acceptance list as observables*; it owns no rule. Every rule
it states is an amendment owed to the document that owns the subject — D2 (`FLEET-STATE.md`) for the allocation and
the wire, D3 (`FLOOR.md`) for what the floor draws — and § 12 lists each amendment by section; the amendments this
design could write without a build are in the same commit as this file, marked `card#13258` in each document's
house style. A reader who finds this file and an owning document disagreeing trusts the owning document and fixes
this file. When the build has landed this file is history.

**The operator's words (2026-11-14, the card):** *"I want subagents unique too (repeat is Ok once all species have
been used up)"*, and earlier: *"I thought there can't be duplicates unless all characters - fruits, vegetables,
animals - are already used"*. The card's text is the design's brief: every character in the building — seats and
interns alike — is a different species until every species is in use; then a repeat is allowed.

**Status: built — S1 (the server half, 2026-11-14) and S2 (the floor's half, 2026-11-15); this file is history.** The operator ruled § 11's questions on 2026-11-14:
Q1 (a), one pool — a new seat avoids an open intern's species; Q2 (a), no cool-down. The final design review's round
3 changed seven things at S1's build, each folded into the section it changed and marked *revised at S1's build*:
`ensure()` for `for()` (§ 3.3), one seed primitive covering closed rows by rule 0 (§ 3.5, § 4.1), a seed that never
stops the fold (§ 4.1), no seed in `RebuildCommand` (§ 3.4), the species list kept per worker process (§ 6), T2's
site legs on `Fold::pass()` (§ 7), and three corrected citations and bounds (§ 0.3, § 3.5). § 10 lists the seat
rulings, each reversible by the operator.

## 0. The readings this design rests on

Every claim about the current state names the file and line on `dev` `92a8b651` (the worktree's base), or the
command that prints it. Nothing below was read from the live store: the sandbox's `.env` was not opened, so the
live population figures are **unverified here**, and § 0.3 gives the queries that print them.

### 0.1 The seats' allocation — the precedent

- `docs/design/FLEET-STATE.md § 6.13` (lines 3361–3599): a seat's species is allocated once, at the seat's
  creation, by five rules over the live population (`seats.retired_at IS NULL`), in a preference order that starts
  at the key's own draw; stored on `seats.species`; freed by retirement through the read filter; serialised by the
  one-row `species_allocation_lock` mutex taken as the transaction's **first** statement, because under this
  store's REPEATABLE READ the snapshot is fixed by the first plain read (lines 3479–3484).
- `server/app/Fleet/CharacterAllocation.php`: `choose(string $key, iterable $live, callable $floorInstalls,
  SpeciesList $list): string` — pure, the rules; `livePopulation()` the plain read; `floorOf()` the lazy layout
  thunk.
- `server/app/Fleet/SeatTokens.php:112` — the mutex `lockForUpdate()` as the transaction's first statement, on
  every path, re-issue included; `:140` the allocation on the insert branch alone.
- `server/app/Characters/SpeciesList.php:110–129` — `drawOf($key)` and `preference($key)`: the generator's draw
  over **any** string key (`Draw::draw($key, $field) % count`), not a seat id in particular.
- `server/database/migrations/2026_10_27_000000_add_species_to_seats.php` — the shape of a species migration.

### 0.2 The intern today — a pure draw that avoids one body

- `resources/characters/creatures.js:1840–1866` — `speciesOf(key)` branches on `~` in the key and calls
  `internSpeciesOf(key, seatSpecies)`: the intern key's own draw under `SPECIES_FIELD`, re-drawn under the field
  `species-redraw` over the other species only where it lands on the seat's. It reads **no sibling**, so two
  interns of one desk, or an intern and another seat, share a body whenever their draws coincide.
- `resources/characters/index.js:73, 88` — `chibiFrame(internKey, seatSpecies)` and `internFrames(internKey,
  seatSpecies)` call `internSpeciesOf()` and force the result through `frameDocument(key, { species })`.
- `docs/design/FLOOR.md § 10.4` interns bullet (lines 7242–7264): *"An intern never shares its seat's body"*,
  *"What it does NOT do is keep siblings apart, and nothing pure can"*, and *"the body is NOT distinct among
  siblings, and is not meant to be"*; decision 54 (line 12744); decision 47 (line 12737): an intern is keyed by
  `call_id` so that its look never changes when the array reorders or a sibling leaves.
- `docs/design/FLEET-STATE.md § 14` item 18 (lines 7866–7881): the earlier ruling that interns are **not** agents
  for the uniqueness rule, with the cost that ruling weighed — a stored allocation per intern on the ingest path —
  and the one alternative it named, a client-side set rule, which *"moves a sibling's body when another sibling
  leaves"*. The operator's words of 2026-11-14 reverse that ruling; this design is the card it asked for.

### 0.3 How an intern reaches the browser

- The fold: `server/app/Fold/Projector.php:609–650` (`toolStart()`): a dispatch row is **inserted** when the
  `tool.start` of a tool in `DISPATCH_TOOLS` (`['Agent', 'Task']`, line 104) arrives for a call id with no row;
  `:790–827` (`subagentSpawn()`): a spawn arriving **first** inserts the row (`tool_name` `Agent`, `is_dispatch`
  true), and a spawn for an existing row **updates** `title`, `subagent_type` and sets `is_dispatch = true`;
  `:654` a `tool.start` for a closed row never reopens it (`late_open`); `:830–885` (`subagentStop()`): a stop
  arriving first inserts a **closed** row.
- The read: `server/app/Fold/SeatFacts.php:370–378` `openSubagents()` — `calls` with `closed_at IS NULL AND
  is_dispatch = 1`, newest first, selecting `call_id, title, subagent_type, activity_state, opened_at`; the
  fingerprint carries the whole set (`versionBearing()`, `:198, :224`), so any change to a selected column is a
  delta; `server/app/Read/SeatObject.php:115–125` renders it as `subagents[]`, capped at 8.
- The release of a call: `closed_at` written by the reporter's `tool.end` / `subagent.stop`, and by two sweeper
  jobs, each an `UPDATE` on `calls` that writes no event — the orphan close (`server/app/Sweep/Sweep.php:295–335`)
  and `quiesce()` (`Sweep.php:480`), which closes every open call of a seat gone offline. `leavingLive()` closes
  sessions, not calls (corrected at S1's build; the design's first revisions listed it). The orphan due time is
  materialised at open (`Projector.php:638–642`, 60 min for a dispatch).
- The client: `server/public/js/desk/desk-render.js:255–275` `sideTable()` maps `subagents[]` to stools (`call_id,
  slot, activity, label, type, started_at`); `floor/desk-layout.js:1184` puts `seat_species: desk.species` on each
  stool element and `:1284` keys the intern `seat~<call_id>`; `floor/scene.js:1773–1774` and `:2817–2824` carry
  `intern_seat_key` and `seat_species` on intern walkers and A10 effects; `floor/painter.js:576–580, 1014–1047`
  hand `e.seat_species` / `fx.seat_species` to `characterFrames()`, whose cache key includes the species
  (`:353–366`); `floor/floor-world.js:363` carries `seat_species` into the 3-D world; `floor3d/body-paint.js:80`
  builds each intern body with `species: n.seat_species, chibi: true`.
- **The 3-D defect the in-flight card#13033 branch addresses, read on `dev`:** `body-paint.js:135` →
  `creature-paint.js:231` `C.chibiParts(key, { species })` → `creatures-3d.js:138–140` `bodyParts()` →
  `recipe(key, species)`. Nothing on that path calls `internSpeciesOf()`, so the 3-D floor draws every intern **in
  its seat's body** today, while the 2-D floor draws it off that body. The branch `card-13033-3d-chars` (worktree
  `/home/mezzanine/wt/wt-13033-chars`, read only) was at `92a8b651` with a clean tree when this design's first
  revision was written; at its second revision (the design review's round) it carried **uncommitted** changes — a
  `walkerCharacter()` primitive in `desk-layout.js` read by `painter.js` and `floor-world.js`, an
  `internSpeciesOf()` call in `floor3d/body-paint.js`, a `character-identity-probe.mjs` — none of it landed. So
  this plan does not state that branch's contents; § 4.3 has S2 re-derive its population on the tree it starts
  from, and § 4.4 says what this design does to the function either way.
- The wire version: `server/app/Feed/FeedEnvelope.php:25` and `server/public/js/wire/fleet-client.js:113`, both
  `FEED_VERSION = 3`.

### 0.4 Derivations the design uses, and what each printed on this tree

| What | Command | Read on `dev` `92a8b651` |
|---|---|---|
| the species list's length and kinds | `node -e "import('./resources/characters/creatures.js').then(m=>console.log(m.SPECIES_KEYS.length, Object.entries(m.SPECIES).reduce((a,[k,v])=>{a[v.kind]=(a[v.kind]||0)+1;return a},{})))"` | `48 { animal: 24, vegetable: 12, fruit: 12 }` |
| every module and document that reads an intern's body off its seat's | `git grep -ln seat_species -- server resources tools docs` | `floor/desk-layout.js`, `floor/floor-world.js`, `floor/painter.js`, `floor/scene.js`, `floor3d/body-paint.js`, `tests/Feature/Floor/painter-probe.mjs` |
| every caller of the intern's pure draw | `git grep -ln internSpeciesOf -- server resources tools docs` | `FLOOR.md`, `creatures.js`, `index.js`, `tools/characters/selftest.mjs` |
| the live population against the list — **not run here, left for the dispatcher** (`.env` not opened) | § 6's three-column query: live seats, open interns, the busiest day's dispatches | unverified; recorded on the card when run, against `SPECIES_KEYS.length` |
| the dispatch rate the allocation runs at — **not run here** | `SELECT DATE(received_at), COUNT(*) FROM events WHERE kind = 'subagent.spawn' GROUP BY 1` | D2 § 14 item 18 read *"tens to low hundreds on each of the preceding seven days"* on 2026-10-27; the coder re-prints it |

## 1. The problem, in the product's terms

A viewer looks at a floor and reads each character as *someone*. Two seats sharing a body was the defect card#12443
fixed for seats: the operator saw a pair and said the system should allocate a unique character for each agent. The
same viewer now sees a desk with three interns at its side table, two of them the same creature — or an intern that
is the same creature as the seat two desks over — while most of the list's species stand unused. The rule the
operator believed held (*"there can't be duplicates unless all characters … are already used"*) holds for seats and
not for interns, because an intern's body is a pure draw of its own key (§ 0.2) that avoids exactly one body, its
seat's, and reads nothing else.

**Evidence it is real, by construction rather than by a sighting:** `internSpeciesOf()` is a function of
`(internKey, seatSpecies)` alone (`creatures.js:1858–1866`), so for any two interns whose keys draw the same index
over 48 species the bodies coincide, with no state to prevent it; `tools/characters/selftest.mjs:316–333` prints the
rate at which a desk at the cap of 8 carries a repeated body as *the build's measurement, which gates nothing*. At a
side table of 8 drawing over 47 species the chance that no two coincide is
`∏_{i=1}^{7} (47−i)/47 ≈ 0.53` (`node -e "let p=1;for(let i=1;i<8;i++)p*=(47-i)/47;console.log(p)"`), so about **one
desk in two at the cap** shows a repeat — the selftest's printed
`sibling repeat rates` line is where the measured figure is read; this paragraph does not restate it.

**What the operator sees after the change:** every character on every floor is a different species until the
building holds more characters than the list has species (48 on this tree, § 0.4); past that, a repeat appears first
on a floor other than the one it would repeat on, then as the least-used species, and an intern still never wears
its own seat's body. A body, once on screen, never changes while another character arrives or leaves.

## 2. The shape, and what was rejected

**Chosen: the server allocates an intern's species in the fold, at the moment the dispatch row becomes an open
dispatch, by the same allocator seats use, over one population — live seats and open dispatch calls together — and
stores it in a table of its own, `intern_species`, keyed by the call, which a rebuild does not destroy; the feed
delivers it as `subagents[].species`; both renderers draw exactly the delivered species through the generator's
forced-species path, and the intern-specific identity function is deleted.** It is card#12443's answer applied to the second population, and the one upstream change that makes every
downstream patch unnecessary: once the species is delivered, 2-D and 3-D parity (card#13033's fix) is not a function
to keep in step but a value both read, the list-growth re-draw of open interns (`FLOOR.md § 10.4`'s growth bullet)
vanishes because a stored species does not move, and decision 47's property is held by the mechanism `seat_id`
already uses — a column, not a draw.

**Rejected A — a deterministic browser-side choice.** The browser computes, over the desk's or the building's open
interns sorted by `started_at`, the first free species in each intern's preference order. *Mechanism it turns on:* a
pure function of the **current** open set. *Why it fails the brief:* the brief needs every viewer and every reload
to agree, and decision 47 needs an on-screen body never to change when another character arrives or leaves. A
function of the current set changes an intern's answer when an earlier-started sibling leaves (its species becomes
free, a later intern that avoided it now draws it); a function of the set **at the intern's start** would hold 47,
but a viewer who reloads later does not have that set — the interns that were open then have gone — so two viewers
disagree. D2 § 14 item 18 named this alternative and the same defect. The only state that makes it right is the
answer itself, stored — which is the chosen shape.

**Rejected B — server-side, computed at read time.** `SeatObject::build()` derives each open intern's species from
the open set when it renders. *Mechanism:* the same pure function, run on the server. *Why it fails:* the same
reason — the open set at render time is not the set at the intern's start, so a sibling's departure moves the body,
and the snapshot and the delta can disagree. Storing at the open is the only point where the set the choice was made
against exists.

**Rejected C — a reservation written in its own transaction AHEAD of the fold.** The same row as the chosen
shape's, but written under the mutex in a transaction of its own before the fold window, so the population read is
that transaction's first plain read. *Mechanism:* it buys a fresh snapshot. *Cost:* a second writer outside the
fold's transaction, so a reservation whose call never materialises (a poison window rolled back, a lost
`tool.start`) holds a species until a sweeper frees it — one more sweeper job for one fact. It defends a hazard
§ 3.4 quantifies as rare and whose rendered outcome the rule already admits. Refused as machinery for a state the
product does not need defended; the table itself is kept, for the reason § 3.5 gives, and written inside the fold.

**Rejected E — a `species` column on `calls`.** The first draft of this design. *Mechanism:* one column on the row
the fold already inserts. *Why it fails:* `mezzanine:rebuild` deletes the seat's `calls` rows and replays the log
through the live fold (`RebuildCommand.php:219`; D2 § 6.6), so the replay would allocate again, against a population
that has since moved — an on-screen intern's body changes at a rebuild, and AT-D2-10's *rebuild equals fold* breaks
on the member. D2 § 6.6 names the rule: the fold may read `events` and the durable inputs a rebuild does not destroy;
a projection's own prior value, which other seats' open `calls` are, is state a replay cannot re-read. The species
must therefore be a durable input, as `seats.species` is — § 3.5.

**Rejected D — keep the pure draw and widen what it avoids.** `internSpeciesOf(key, [seatSpecies, …siblings])`.
*Mechanism:* the client hands the tree the species of every character already on the floor. *Why it fails:* it is
alternative A under another name — the avoided set is the current set, and a body moves when a sibling leaves.

## 3. The allocation — one allocator, one population, two writers

### 3.1 The population, and the rules as they read over it

**A character is a live seat or an open dispatch call.** The population the allocation reads is the union of
`seats` with `retired_at IS NULL` (D2 § 4.10's one predicate, `RetirementFilter`) and `calls` with `closed_at IS
NULL AND is_dispatch = 1` (the predicate `subagents[]` is selected on, `SeatFacts::openSubagents()`) joined to
`intern_species` on `(seat_ref, call_id)`, **under `RetirementFilter::renderable()`** as `livePopulation()` already
is, so a retired seat's still-open interns hold nothing — each member read as `(install_id, species)`, an intern's
install its seat's room through `App\Read\SeatIdentity`. An open dispatch row with no `intern_species` row is
outside the population and a defect of § 3.3's invariant, `null` on the wire and F14 on the floor (§ 4.2, § 4.3).

D2 § 6.13's rules read unchanged over that population, with **one filter ahead of them for an intern**:

0. **An intern never wears its own seat's body.** The seat's stored `seats.species` is removed from the candidate
   list before rule 1 is tried. The list has 48 species on this tree (§ 0.4), so 47 candidates remain and the
   allocation always answers. This keeps the one claim decision 54 made true by construction, now as a filter
   rather than a re-draw. (Seat ruling R1, § 10.)
1. **A species no live character holds** — seat or intern, anywhere in the building — in the intern's preference
   order: the species list rotated to start at the intern key's own draw (`SpeciesList::preference(internKey)`,
   the same `draw(key, SPECIES_FIELD) % n` the browser's `internSpeciesOf()` takes first today), so an intern whose
   own draw is free gets the body the floor would have drawn it with before this card. While this rule answers the
   layout is not read.
2. **Else, a species no live character on the intern's FLOOR holds.** An intern of a **placed** seat has a floor
   where a new seat does not: `CharacterAllocation::floorOf($seatInstallId, $installs)` answers it exactly as for a
   backfilled seat — the installs the current layout places on the same floor, or the install alone when the
   layout does not place it, with the lazy read and `character_floor_unread` on a refused layout (§ 6.13 rule 2's
   text, unchanged). An intern of a **room-less** seat — its room key is `SeatIdentity::UNASSIGNED`
   (`SeatIdentity.php:26–29`) — is handed an **empty floor** and skips rule 2, as a new seat does (card#13117
   Q6 (a)); `floorOf('unassigned')` is never asked, because `BuildingLayout::floorOf()` answers `null` for it and
   the thunk would then return the install alone, pooling every room-less seat as one floor (seat ruling R4,
   § 10). Reachable only once the building holds more characters than the list has species, which interns make
   less rare — § 6 prices the read and gives the derivation.
3. **Else, the least-used species** — fewest holders on the floor, then fewest fleet-wide, then the preference
   order; seats and interns count alike as holders.
4. **An intern keeps its species for its life.** Written once, into `intern_species`, in the fold transaction that
   makes its row an open dispatch (§ 3.3), and no runtime writer moves it: not the spawn's title join, not an
   activity event, not a close, not a seat's retirement, not a list that grows, **not a rebuild** (§ 3.5). It is as
   stable as `call_id`, which is decision 47's property held by a stored row rather than by a draw.
5. **The close frees it.** Rule 1's population excludes the call from `closed_at` on, whoever wrote the close —
   the reporter's `tool.end` or `subagent.stop`, or the sweeper's orphan close or `quiesce()` — and nothing writes
   `species` then: the row keeps the body the intern wore, as a retired seat does.

**The seats' allocation reads the same population** (operator question Q1, § 11 — ruled (a) on 2026-11-14,
and built so): a new seat's rule 1 avoids the species an open intern holds, exactly as it avoids a live seat's. The
seats' rules 2–5 are unchanged; rule 2 still skips for a seat on no floor (card#13117).

**Which repeats first, and how it agrees with the seats'.** The same ordering, because it is the same function:
past exhaustion a repeat lands first off the character's own floor (rule 2), then on the least-used species (rule
3), then by preference order; for an intern the exclusion of its own seat's body holds throughout. The two
populations differ in one fact only — an intern has a floor at allocation and a new seat has none — and that
difference lives in what the caller hands rule 2's thunk, not in the rules.

### 3.2 The primitive — `CharacterAllocation::choose()` gains one parameter

`choose(string $key, iterable $live, callable $floorInstalls, SpeciesList $list, array $excluded = []): string` —
`$excluded` the species removed from the candidate list before rule 1 (one species for an intern, none for a seat).
`$live` already accepts any iterable of `(install_id, species)` and does not care which rows are seats. A second
static read, `openInterns()`, returns the open dispatch members in the same shape beside `livePopulation()`, and
one composed read `CharacterAllocation::characters()` returns their union — the one population both writers hand to
`choose()`. No second allocator, no copy of the rules (canon #5).

### 3.3 Where it runs — the fold, at the row's first moment as an open dispatch

**The property:** *every `calls` row that is an open dispatch (`closed_at IS NULL AND is_dispatch = 1`) has an
`intern_species` row, allocated once, in the transaction that made it an open dispatch.* Three arrival orders reach
that state (§ 0.3), and each is a site:

| Arrival | Site | What it does |
|---|---|---|
| `tool.start` of a dispatch tool, no row yet | `Projector::toolStart()`'s insert branch when `is_dispatch` | find-or-allocate, in the same transaction as the insert |
| `subagent.spawn` first, no row yet | `Projector::subagentSpawn()`'s insert branch | find-or-allocate, in the same transaction as the insert |
| `subagent.spawn` for an existing **open** row that is **not yet a dispatch** — its `tool.start` carried a tool name outside `DISPATCH_TOOLS` — which the update flips to `is_dispatch = true` | `subagentSpawn()`'s update branch, **only where it flips the flag** | find-or-allocate: the row's first moment as an open dispatch is this update |

Nothing else allocates. A `subagent.stop` arriving first inserts a **closed** row: no allocation (never in
`subagents[]`, never drawn). A late `tool.start` for a closed row is `late_open` and does not reopen it
(`Projector.php:654`); a duplicate `tool.start` for an **open** row is counted as `duplicate_open` and touches
nothing (`Projector.php:666–668` — there is no open-row update branch, so there is no site there); a
`subagent.spawn` for an open row that is already a dispatch updates its title and type and does not call the helper
— the row had its moment. **What a returning call id gets:** the species its row holds, or nothing — never a second
allocation.

The allocation is one helper the three sites call, `InternSpecies::ensure(int $seatRef, string $callId): void` in
`App\Fleet` beside `CharacterAllocation` — **find-or-allocate**: a plain read of the `intern_species` row for
`(seat_ref, call_id)`; when one exists (a replay, § 3.5) nothing more; else the seat's `species` and room, the union
population, and `choose()` with the intern key `seat_id~call_id` (`desk-layout.js:1284`'s `internKey()`, the key
the browser seeds the intern's every other field from, so the server's first preference IS the body the browser
would draw), the seat's floor thunk and `[$seatSpecies]`; then `INSERT … ON DUPLICATE KEY UPDATE call_id = call_id`.
**It returns nothing**: no production caller reads the species back — the wire reads the table. *Revised at S1's
build (the final design review, round 3):* the third revision's `for(): string` ended in `INSERT IGNORE` and a
locking re-read (`FOR SHARE`); `INSERT IGNORE` downgrades every strict-mode error, a bad value included, to a
warning, and on MariaDB 11.8 `innodb_snapshot_isolation` turns a locking read of a row committed after the
snapshot into an `ER_CHECKREAD` (`1020`) rollback. Measured at the build on MariaDB 11.8.6 with
`innodb_snapshot_isolation = ON`: **every** insert shape — this one, a plain `INSERT`, `INSERT IGNORE` — of a key
another transaction committed after this one's snapshot raises `1020`, so no statement makes two writers agree
silently there; § 3.4 names the one pair that can meet and what each does with the `1020`. Where the setting is
off, the `ON DUPLICATE KEY` form keeps the row already there and raises nothing. The check that the property holds
is T2 (§ 7): the three orders planted, the species present and unchanged across each sequence, and a leg red when
any one site skips the call.

### 3.4 Concurrency — why the fold takes NO mutex, and exactly who can race it

D2 § 6.13's correctness rests on two facts together: the mutex serialises allocations, **and** the population read is
the transaction's first plain read, so its snapshot postdates the lock (lines 3479–3484). The fold cannot give the
second: a fold window's first statement is the seat's `seat_state` row lock and its snapshot is fixed by the window's
first plain read (`Fold.php:147–154`), events before the dispatch open in the same window. A mutex taken mid-window
would serialise the write and leave the read on a snapshot from before the lock — the exact defect § 6.13 names. It
would also **deadlock** with the re-issue path: `SeatTokens::issue()` takes the mutex first on every path and then
`SeatLock` on `seat_state` (`SeatTokens.php:112`; § 6.13's re-issue paragraph), while a fold holds `seat_state` and
would wait for the mutex. A locking read of the population (`FOR SHARE`) would make the read current but S-locks
every open dispatch row of every seat — a deadlock class on the ingest's hot path against any other writer of those
rows, the shape § 6.13 refused for seats (*"a full-scan locking read … rested on next-key semantics and lock
order"*). `Fold::contended()` would roll the window back and retry it on the next pass, so neither is fatal, but both
add a deadlock class to the fold for a guarantee the plain read cannot complete anyway.

**So the fold allocates with a plain read under its own window's snapshot and takes no mutex.** Who can actually
race it, read from the code rather than assumed:

- **Another fold window of the live worker: cannot.** `bin/supervision.sh` starts each daemon under `flock -n`
  (line 131's crontab entry; line 7 and line 153 state the rule), one `mezzanine:fold` per checkout, and
  `Fold::pass()` (`Fold.php:107–116`) commits each `foldSeat()`'s transaction before claiming the next seat. Two
  windows of the worker never overlap, so its intern-vs-intern allocations are serial and each sees the one before
  it — on the same seat and across seats alike. **It returns the day a second fold worker is introduced**
  (`Fold::claim()`'s docblock, `Fold.php:126–131`, names that as card#7523's, untested); so R3 (§ 10) is re-opened by
  that card before a second worker starts, and the condition is declared where that card will read it — D2 § 6.5's
  second-worker sentence and the `Fold::claim()` docblock (S1 adds the sentence to the docblock).
- **A `mezzanine:rebuild` running beside the worker: a second process folding, and so a second allocator wherever
  its replay ALLOCATES rather than finds.** The replay allocates for a dispatch `tool.start` whose `intern_species`
  row does not exist. The first revision of this plan said that was "in practice never"; it was wrong: the seed
  covered only the calls **open** at the deploy, so for the 14 days of retention after it every closed historical
  dispatch would have been allocated afresh at a rebuild — under the seat lock, against the replay transaction's
  frozen snapshot, reading `creatures.js` (222 KB) once per call. **Closed by the migration's backfill (§ 4.1):
  every `is_dispatch = 1` row then in `calls`, closed ones included, gets a row**, so from the deploy on every call
  the replay can see has a row — the backfilled ones until their purge, and every later call from its own open,
  with the one exception § 3.5's retention paragraph bounds (a spawn-first call whose `tool.start` arrived late).
  The replay then finds and does not allocate, and reads no list. **A rebuild and the live fold never meet on a
  key:** the key carries `seat_ref`, and both take the seat's `seat_state` lock as their first statement, so one
  waits for the other and its snapshot follows the other's commit. Its one possible partner is the worker's
  startup seed, which takes no seat lock — a rebuild launched in the seconds a worker starts, over a deploy-window
  row; the meeting raises `1020` (§ 3.3), the rebuild retries it (`RebuildCommand::REPLAY_LOCK_ATTEMPTS`) and finds
  the seed's row, and the seed's caller logs and counts it (§ 4.1).
- **`SeatTokens::issue()` racing a dispatch open, in both directions.** `issue()` holds the mutex and its
  population read is fresh, but the fold's snapshot may predate `issue()`'s commit (the fold misses the new seat),
  and `issue()`'s read may predate the fold's commit (the seat misses the new intern). Exposure: one seat creation
  — an operator act, a handful of days in a store's life (§ 6.13's rate query) — against one dispatch open in the
  same instant, and a coincidence of two preference orders on top. Outcome: a repeat while species remain, which
  rules 2–3 already admit past exhaustion; never a moved body (rule 4 outranks a repair).
- **The deploy window, and the one writer that closes it.** `bin/deploy.sh` runs `php artisan migrate --force`
  (line 4294) before `restart_daemons` (line 4313), so the OLD fold keeps inserting dispatch rows — under code that
  allocates nothing — between the migration's backfill and its own restart. The second revision of this plan closed
  it with a second seed run from `deploy.sh` after the restart; that is **two writers** (the seed and the restarted
  worker's own allocations) meeting on one primary key, and a seed that lost the race would raise inside the
  deploy's ERR trap (`deploy.sh:315–340`, armed at `:4229`) and leave the app down. So: **the seed runs once, at
  the fold worker's startup, before its first `claim()`** — `FoldCommand::handle()` (`FoldCommand.php:25–31`) calls
  it ahead of the `pass()` loop — which is a single writer, because the old worker is dead (its flock released at
  the restart) and the new one has written nothing yet. **`RebuildCommand` does not seed** (revised at S1's build,
  the final review's round 3): a rebuild inside the window replays a deploy-window call's `tool.start` through the
  live fold, whose site allocates it — the replay is that site — and the worker's startup seed covers the rest; a
  second caller of the seed would only add a second writer. No `deploy.sh` step, no ERR-trap exposure,
  and nothing on the ingest path "heals" — the second revision's heal on spawn and on duplicate `tool.start`
  defended nothing the startup seed does not close, and the duplicate-`tool.start` site did not exist
  (`Projector.php:666–668`).

**The observable of the one live race** is a read anyone re-prints:

```sql
SELECT species, COUNT(*) AS holders FROM (
  SELECT s.species FROM seats s WHERE s.retired_at IS NULL
  UNION ALL
  SELECT i.species FROM calls c JOIN intern_species i ON i.seat_ref = c.seat_ref AND i.call_id = c.call_id
   WHERE c.closed_at IS NULL AND c.is_dispatch = 1
) h GROUP BY species HAVING COUNT(*) > 1;
```

read beside the free count (48 on this tree less the distinct species held). A row here **while species are free**
is that race or a defect, and nothing repairs it. The decision rule is not a sandbox measurement: **R3 is re-opened
when a second fold worker is introduced (card#7523)**, and until then the race above is accepted as stated.

### 3.5 Rebuild, replay and retention — why the species is a durable input and not a `calls` column

`mezzanine:rebuild --seat=<id>` deletes the seat's `calls`, `sessions` and `attention_requests` rows and replays
`events` through the live fold (`RebuildCommand.php:205–225`; D2 § 6.6). A species stored on `calls` would be
deleted with the row and **allocated again** at replay — against today's seats, today's open interns of other seats,
in today's order — so an intern on screen could change body at a rebuild, and AT-D2-10's *rebuild equals fold*
would fail on `subagents[].species` for a reason that IS a defect: D2 § 6.6's rule is that the fold reads only
`events` and the durable inputs a rebuild does not destroy (`seats`, `seat_board_task`, and `seats.species` by that
sentence's own example); the open `calls` of other seats are a projection, state a replay cannot re-read.

So the species lives in **`intern_species (seat_ref, call_id, species, allocated_at)`**, primary key
`(seat_ref, call_id)` and an index on `allocated_at`, which `reset()` does not touch — a durable input of exactly
`seats.species`'s kind — and the fold's writer is find-or-allocate (§ 3.3): on the live path it allocates and
inserts; on a replay it **finds** the live allocation and the intern keeps its body. The population read for rule 1
joins `calls` to it, and `openSubagents()` LEFT JOINs it for the wire.

**Every call the replay can see has a row — by the backfill, then by construction.** The migration (§ 4.1) writes a
row for every `is_dispatch = 1` row in `calls` at the deploy: the open ones by § 3.1's rules, the closed ones the
first species of the intern key's preference order that is not its seat's — rule 0 over an empty population, read
from the one species list the run already holds. *Revised at S1's build (the final review, round 3):* the third
revision wrote a closed row the key's plain draw and called it never rendered; it IS rendered — a rebuild reopens a
sweeper-closed call (behaviour (a) below), which then stands on `subagents[]` in that row's body until the next
sweep — so a closed row obeys rule 0 like any other. Every call opened after the deploy has its row from its open
(§ 3.3), the deploy-window ones from the worker's startup seed (§ 3.4). So a replay **finds** for every
`tool.start` it can replay but the one the retention paragraph below bounds, allocates nothing, reads no species
list, and is never a second writer against the worker — and it holds because the backfill
covered the closed rows too; covering the open ones alone would have left 14 days of fresh allocations at every
rebuild (§ 3.4's second bullet). The alternative weighed — leave closed rows unbackfilled and load the species list
once per command run — would have made each of those allocations cheaper and left every one of them: a second
allocator under the seat lock against a frozen snapshot. The backfill removes the allocations; the per-run load
would only have sped them up.

**Retention (D2 § 6.7): an ordinary `Purge::PLAN` row, `allocated_at` against the event retention boundary.**
`Purge::PLAN` (`Purge.php:98–118`) is one shape — table → the column its retention is measured on, drained as
`WHERE col < boundary` and counted into `purge_backlog_rows` — and `intern_species` joins it as
`'intern_species' => 'allocated_at'`, **placed after `events`** in the plan so a pass that runs out of budget has
drained the log before the table that outlives it. `Purge::orderColumns()` (`Purge.php:264–273`) **throws for a table
it does not declare**, so the same slice declares `'intern_species' => ['allocated_at', 'seat_ref', 'call_id']` —
the index's leading column, then the primary key. The boundary is the event retention (14 days,
`Purge::RETENTION_DAYS`), like every table but `feed_outbox`. Why that is the right key: `allocated_at` is the
server's instant in the transaction that wrote the row — the fold's at the call's open, or the seed's (the
migration's or a worker start's) — so it is at or after the `received_at` of the event that **made the row an open
dispatch** at the time it was folded, and of every event of the call received before it. **The bound, stated
honestly (corrected at S1's build — the third revision said "never earlier than the `tool.start`'s"):** at the
spawn-first site that event is the `subagent.spawn`, and the call's own `tool.start` can be received later — a
batch boundary between the two, delivered out of order, up to the reporter's spool residency (D1 § 11.3, 8 days)
later in the worst case. Such a row is purged up to that gap before its `tool.start`, and a full rebuild run
inside the gap replays that `tool.start` with no row and allocates for it afresh — one allocation, against the
replay's snapshot, for a call whose spawn is already gone from the log. The new body is drawn only while the
replay leaves the call open — until a close in the log replays, or the next sweep closes it (behaviour (a) below).
Not defended with a margin: the route
needs a late `tool.start`, a purge in the gap, and a rebuild in the gap, and its worst outcome is one sweep
cadence of a body that may differ from the one the intern wore. No open
intern loses its row: an open dispatch call is never 14 days old (the orphan ceiling is 60 min,
`Projector::ORPHAN_DISPATCH_MS`, and the sweeper closes it). **The first draft's rule — purge a row whose `calls`
row is gone — was wrong**: `mezzanine:rebuild --since` drops the seat's calls before `since` while their events
remain, the anti-join then drops their rows, and a later full rebuild replays those `tool.start`s and allocates
afresh — a body change the design exists to prevent; and it fit neither `PLAN`'s one shape nor the backlog
instrument.

**Two replay behaviours, stated.** *(a)* A call the sweeper closed — the orphan close (`server_orphan`,
`Sweep.php:323–330`) or `quiesce()` (`server_offline`, `Sweep.php:480`), each an UPDATE on `calls` and no event — is
reopened by the replay, with its species (the row is found), and closed again by the next sweep: its
`orphan_due_at` is re-materialised from a `received_at` already past. Between the two it is on `subagents[]` again in
the body its row holds, which is the honest replay of the log. *(b)* The replay allocates afresh only for a call
with no row, which after the backfill is a call whose row was purged while its `tool.start` was not — the
spawn-first gap the retention paragraph bounds. A replay that allocates is a second writer only against the
worker's startup seed (§ 3.4), and a meeting there is a `1020` the rebuild retries.

**The check:** T2's rebuild leg — a seat with an open intern is rebuilt and delivers the same `subagents[].species`;
red when the lookup is skipped and the replay allocates afresh against a planted population that has moved. T2's
closed-history leg — a seat with closed dispatch calls that have their rows is rebuilt and allocates nothing (the
species list's reader is a spy that reds on a read; red when the find is skipped). T4's purge leg — a row younger than the boundary stays
whatever its call's state; a row older goes; red when the purge keys on the `calls` row instead.

## 4. The data shape

### 4.1 The store — `intern_species`, a table of its own, backfilled for every dispatch call

One migration, in `2026_10_27_000000_add_species_to_seats.php`'s shape (a species migration with a printed table):

- `CREATE TABLE intern_species (seat_ref INT UNSIGNED NOT NULL, call_id CHAR(26) ascii_bin NOT NULL, species
  VARCHAR(16) ascii_bin NOT NULL, allocated_at DATETIME(3) NOT NULL, PRIMARY KEY (seat_ref, call_id), KEY
  ix_allocated (allocated_at))` — D2 § 6.3's conventions (the surrogate `seat_ref`, the ULID column, `DATETIME(3)`),
  the species column `seats.species`'s width and charset (`SpeciesList::MAX_KEY_BYTES`), the index for the purge's
  range scan. `calls` is **not altered**: no column on the fold's busiest table, no `ALGORITHM=INSTANT` question,
  and the reason is § 3.5's, not convenience.
- **Every column `NOT NULL`.** A row exists only for a call that was allocated or seeded. A non-dispatch row never
  has one. A `subagent.stop`-first row — closed, and a dispatch — gets none from the fold, and one from the next
  seed (the next worker start), which writes every dispatch row that lacks one; it holds no species in the
  population, since the call is closed. The invariant is *open dispatch ⇒ a row*, held by the
  three sites (§ 3.3) and the startup seed (§ 3.4), seen by T2 and T4; an open dispatch row with no row here is a
  defect, `null` on the wire (§ 4.2) and § 9 F14 on the floor (§ 4.3), never a fallback body.
- **The backfill covers every `is_dispatch = 1` row then in `calls`**, in `calls.id` order, in one transaction with
  the mutex as its **first** statement (a seed can give what the fold cannot): an **open** call by § 3.1's rules over
  the live seats and the open interns that have rows, those this run wrote before it included; a **closed** call the
  first species of its key's preference order that is not its seat's (rule 0 over an empty population), so that a
  replay within the retention window finds every call it can see and allocates nothing — and so that a sweeper-closed
  call a rebuild reopens is drawn in a body that obeys rule 0 (§ 3.5). The species list is read through
  `SpeciesList::current()` once per process and kept (`InternSpecies`' memo, § 6). **One pass**, not the seats' two: the two-pass rule exists so that only
  the later member of a seat collision moves a body a seat keeps for life; an intern lives minutes, and every open
  intern changes body at most once at this deploy, at the reload the `feed_version` bump forces anyway (§ 4.2). Seat
  ruling R2, § 10. The migration prints the open rows it allocated (`seat_id`, `call_id`, `species`) and the count
  of closed rows it wrote — a count printed by the run, not written here — and logs the same; on a store with no
  dispatch call it prints nothing.
- **The seed is ONE primitive, `InternSpecies::seed()`** (revised at S1's build, the final review's round 3; the
  third revision had a command, `mezzanine:intern-species:seed`, whose body was the backfill's open-row pass only) —
  the backfill above, over every dispatch call with no row, open or closed; idempotent (a call that has its row is
  not selected). Its two callers: the migration, as its backfill, and **`FoldCommand::handle()` once at the
  worker's startup, before the first `pass()`** (§ 3.4's deploy window). There is no second body and no command.
  **It is not run by `RebuildCommand`** (§ 3.4) **nor from `bin/deploy.sh`**: a seed outside the worker is a second
  writer, and a seed that lost its race would raise inside the deploy's ERR trap (`deploy.sh:315–340`, armed at
  `:4229`) and leave the app down — the second revision's step, withdrawn.
- **The worker's seed never stops the fold.** `FoldCommand` catches any `Throwable` from it, logs it at ERROR,
  counts it in `intern_species_seed_failed` (D2 § 7.2, on fleet health), and folds on; the open interns the seed did
  not reach render `null` — F14 — for their life, because no fold site revisits an open row. A store too broken for
  the count to be written is one the first pass fails on too.
- **The purge** gains `'intern_species' => 'allocated_at'` in `Purge::PLAN`, placed after `events`, and
  `'intern_species' => ['allocated_at', 'seat_ref', 'call_id']` in `Purge::orderColumns()`, which throws for a table
  it does not declare (§ 3.5); no other purge code changes.
- `down()` drops the table. The previous release's tree reads `FEED_VERSION` 3, so every page reloads into the
  JavaScript that draws the key's bodies again.

D2 § 6.4 gains the table's fence beside `species_allocation_lock`; § 6.6 names it a durable input; § 6.7's
retention table gains its row; § 6.5 and `Fold::claim()`'s docblock carry the second-worker condition (§ 3.4).

### 4.2 The wire — `subagents[].species`, nullable, and `feed_version` 4

- `SeatFacts::openSubagents()` LEFT JOINs `intern_species` on the primary key and selects `species` beside its
  columns; because `versionBearing()` fingerprints the whole set, the member rides the delta that announces the
  intern and is never a delta of its own (rule 4: it does not move). `SeatObject::build()` renders it as
  `subagents[].species`, a string — **nullable, and `null` exactly when the join misses**, which is § 4.1's
  invariant broken; the server sends `null`, never `""`, and the floor draws `null` as F14 (§ 4.3). The first draft
  declared it never-null over a join that can miss; D2 § 6.3's nullability convention (null where the fact genuinely
  does not exist) and the honest render both say nullable.
- The seat-detail response's uncapped call list (D2 § 8.2.3) draws no sprite and gains nothing — a non-goal; if the
  drill-down is ever to name an intern's creature, that is its own card.
- **Sizing.** The member adds its longest form — `"species":"0123456789012345",`, 29 B — to each of the capped
  array's eight elements, 232 B on the worst-case delta. D2's verifier re-derives every stated figure from the
  published blocks, so this commit carries the member in the § 8.2.2 worked snapshot and on every element of the
  § 8.3.2 worst-case block, with the figures moved to what the gate re-derived: worst-case delta 7,383 B, 809 B
  spare under the 8 KiB bound (1.11×), and FLOOR § 8.1's cap ceiling from 11 to 10 — the cap stays 8, and the
  decision there is unchanged. The gates (`verify-fleet-state.py` G3/G5, `verify-floor.py` G3) are the check, and
  each was seen red before the figures moved.
- **`FEED_VERSION` 3 → 4**, both constants, in the release that ships the painter's change (§ 4.3): a page from
  before the deploy re-snapshots silently on a version it knows and keeps drawing interns from their keys, two bodies
  per intern across the fleet; on a version it does not know it shows the reload banner — § 6.13's ⭐ paragraph, the
  same reasoning, the same one-release rule. The server slice may land on `dev` ahead of the client slice (an extra
  member is ignored by the old client) — but **one release** carries both, and the bump lands with the client.

### 4.3 The renderers — one value, forced, in both

- **The tree.** `chibiFrame(internKey, species)` and `internFrames(internKey, species)` force `species` through
  `frameDocument(key, { species })` exactly as `standingFrame(seat, species)` does, with no call to
  `internSpeciesOf()`; `speciesOf(key)` loses its `~` branch and is the plain draw for every key (what a sheet with
  nothing delivered shows — the same posture the seat key has had since card#12443); `internSpeciesOf()` and the
  `species-redraw` field are **deleted**. `index.js`'s re-exports follow.
- **The population S2 changes is re-derived on the tree S2 starts from, not copied from here.** It is
  `git grep -n "seat_species\|internSpeciesOf" -- server resources tools`, run after `card-13033-3d-chars` has
  landed (§ 4.4) — that branch, uncommitted when this revision was written, adds a `walkerCharacter()` primitive in
  `desk-layout.js` (read by `painter.js` and `floor-world.js`) and an `internSpeciesOf()` call in
  `floor3d/body-paint.js`, so the site list this plan's first revision gave from `dev` `92a8b651` is already stale
  and is not repeated. The property after S2: the grep prints nothing under `server/public/js`, `resources` and
  `tools` but history rows in this plan and the documents (T9).
- **Sites that are not a rename, and are easy to miss, named** (verified on `92a8b651`):
  - `desk-layout.js:884` `DRAWN_MEMBERS.species: ['character', 'stool']` — **`'stool'` is removed**, so the entry
    reads `['character']`: the desk model's `species` member is the seat's body and is drawn by the character alone;
    the stool's body is a member of `side_table.stools[]`, which `DRAWN_MEMBERS.side_table` already covers. The
    entry is not redefined to mean the intern's body (`TheSceneDrawsEveryDeskMemberTest` partitions the model's
    members, and the stool element's `species` is not a desk member).
  - `TheNewDeskKeepsEveryLeafTest.php:428`'s mutation *"an intern kept off the seat key's draw, not its seat's
    delivered body"* (`internFrames(seatId, species)` → `internFrames(seatId)`) is re-aimed at *an intern drawn in a
    body not its own* — a plant whose red is that the intern's frame is its seat's or its key's.
  - **The departing intern walks in its own body.** `scene.js`'s `shape()` (`:2817–2824`) builds both the arrival
    and the departure walker and reads the body as `frame.desks.desks[seat]?.species` — the seat's. After S2 it reads
    `element.species`, for the gained and the lost element alike: the lost element is `advanceSlots()`'s copy of the
    **previous** seat object's `subagents[]` element (`desk/intern-slots.js:53–54`, `{ ...had.get(id), slot }`), so
    it carries the departed intern's `species` when the current frame's desk no longer does. A departure read from
    the frame's desk would walk the intern out in its seat's body, or in nothing.
  - `floor3d/body-paint.js:87`'s walker fallback `w.species ?? fx.seat_species ?? speciesOf.get(fx.seat_id) ?? null`:
    the third arm hands a walker its seat's body, which for an intern is exactly the wrong body; it survives for a
    seat walker only.
- **2-D.** Each stool carries `species: s.species` from `sideTable()`; the stool element, the intern walkers, the
  A10 shapes and the effect objects carry `species` where they carried `seat_species`; `characterFrames()` is
  handed `e.species` / `fx.species`, and its cache key already holds the species (`painter.js:373`).
- **3-D, and why `null` needs a route of its own there.** Each intern body is built with its own `species`;
  `createCreature()` → `chibiParts(key, { species })` → `recipe(key, species)` is already the forced path for a
  **string**. But `standBodies()` (`body-paint.js:136`) passes `species: spec.species ?? undefined`, and
  `recipe()` (`creatures.js:1878`, `force || speciesOf(key)`) treats an absent force as *draw the key's own body* —
  so a `null` species would reach the floor as a real creature, the fallback body the design forbids, and F14 would
  never be reported. In S2 `wantedBodies()` / `standBodies()` route an intern whose `species` is not a string
  **straight to `failed(['intern:' + key])`**, with no `createCreature()` call and no `bodies` entry for the key;
  the seat path is unchanged (`character.species` is never null). `card-13033-3d-chars` is changing `body-paint.js`
  (`walkerCharacter()`, its `internSpeciesOf()` call at `:148` on that tree), so this routing is written against
  the landed tree, not against `92a8b651`'s line numbers.
- **F14 for an intern with no species, 2-D** — `characterFrames()`'s `typeof species !== 'string'` branch
  (`painter.js:363`) — is unchanged and now reachable by exactly one route, a `null` from § 4.2, and it draws the
  placeholder, never the key's own body and never the seat's.

### 4.4 Sequencing against card#13033-3d-chars

The card says this builds after card#13033's parity fix lands, because that fix makes 3-D call the identity function
2-D calls and this card changes that function. When this revision was written the branch carried uncommitted
changes to `body-paint.js`, `creature-paint.js`, `creatures-3d.js`, `desk-layout.js`, `floor-world.js` and
`painter.js` (§ 0.3) — the same files S2 touches, `body-paint.js`'s null route (§ 4.3) among them. Two cases, the
dispatcher's call:

- **It lands first (the card's order):** S2 re-derives its population on that tree (§ 4.3), deletes the
  `internSpeciesOf()` call it added alongside 2-D's, and writes the 3-D null route into whatever `standBodies()` has
  become. Its reviewer should know the function is transitional.
- **It has not landed when S2 starts:** S2 subsumes it — after S2 there is no intern-specific identity function in
  either renderer, so there is nothing left for that branch to make parity with — and card#13033-3d-chars closes on
  this card's PR, by the dispatcher's decision, never by this design.

## 5. Across the boundary (canon #7)

- **DECLARE:** the server allocates every character's species — a seat's at creation, an intern's at its dispatch's
  open — and publishes it: `character.species` on the seat (never null), `subagents[].species` on each intern
  (nullable; `null` is the declared defect render, F14), on the snapshot and the delta, under `feed_version` 4. Declared in D2 § 6.13 and § 8.2.1, which the client's
  author reads.
- **CHECK:** AT-D2-31's new legs (§ 7, T2/T4/T5) hold the rules over this plane's own code; `AT-D3-24`'s intern leg
  (T6) holds the browser to drawing exactly the delivered value.
- **What neither end can verify locally, named:** that the list the server allocated from is the list the browser's
  generator holds — § 6.13's hop, unchanged: both read the same bytes of the same deploy, `TheSpeciesListHasOneSourceTest`
  is the server's check that its reading is the generator's, and a rollback of the tree under a store that
  allocated from a longer list renders F14 for that character, never a fallback body. New with this card: the same
  hop now runs at the dispatch rate rather than the seat-creation rate, so a rollback shows F14 on interns within
  minutes rather than at the next seat creation — the honest render, sooner.

## 6. Cost

- **Per dispatch open** (the rate § 0.4's query prints): two plain selects — `livePopulation()` (fleet-sized, as
  `issue()` reads today) and the open interns (`calls` on `closed_at IS NULL`, `ix_orphan`'s leading column, joined
  to `intern_species` on its primary key; a result the size of the fleet's open interns) — one primary-key lookup
  (the find) and one insert into `intern_species`. **The species list is read once per worker process and kept**
  (`InternSpecies`' memo, through `SpeciesList::current()`), not parsed per allocation. That is correct because a
  deploy restarts the fold worker, **verified at S1's build** by reading `bin/deploy.sh`'s `restart_daemons`
  (`deploy.sh:4087–4140`): it SIGTERMs every process holding one of the checkout's daemon locks, waits for them to
  exit, relaunches each daemon of `SUPERVISED_DAEMONS` (`bin/supervision.sh:83`, `mezzanine:fold` among them), and
  refuses the deploy unless each lock's holder **started after the restart** (`holders_started_after`) and still
  holds it a settle interval later. So no worker outlives the `creatures.js` it read; `migrate --force` (line 4294)
  runs before `restart_daemons` (line 4313), so the new worker also starts after the table exists.
- **Past exhaustion, per dispatch open, one more read:** rule 2's thunk calls `Layouts::read()` — one
  `building_layout` row read and one `BuildingLayout::parse()` of its JSON document (`Layouts.php:83–88`), with
  no cache — for an intern of a placed seat. Exhaustion is **less rare than for seats**, because interns count:
  the building is exhausted when live seats plus open interns reach the list's length (48 on this tree). The
  derivation, left for the dispatcher to run on the sandbox (not run here; `.env` not opened):

  ```sql
  SELECT (SELECT COUNT(*) FROM seats WHERE retired_at IS NULL)                        AS live_seats,
         (SELECT COUNT(*) FROM calls WHERE closed_at IS NULL AND is_dispatch = 1)       AS open_interns,
         (SELECT MAX(n) FROM (SELECT COUNT(*) AS n FROM calls WHERE is_dispatch = 1
                                GROUP BY DATE(opened_received_at)) d)                    AS busiest_day_dispatches;
  ```

  against `SPECIES_KEYS.length` (§ 0.4's `node` line). The first two added give today's distance from exhaustion;
  the third bounds how often the layout read would run on an exhausted day. If the sum is near the list, the
  layout read becomes a per-dispatch cost and the right answer is to grow the list (FLOOR.md § 10.4's growth
  bullet), not to cache the layout.
- **Per seat creation:** one more select (the open interns).
- **Per read of a seat's open subagents** (`versionBearing()` on every fold window and sweep that samples it, the
  snapshot, the detail): one LEFT JOIN on a primary key, the size of the seat's open interns.
- **Per fold-worker start:** one run of the seed before the first pass — a scan of the dispatch rows with no
  species row (a left join on the primary key). On an ordinary restart it writes two kinds of closed row and
  nothing else: the `subagent.stop`-first calls since the last start, and the closed calls whose species row the
  purge has already taken while their `calls` row — purged on `closed_at`, which is later than `allocated_at` by the
  call's duration — remains. Both are closed, so neither reads the population; the species list is read only when
  there is a row to write. A rebuild runs no seed.
- **The store:** one row per dispatch for the retention window — the dispatch rate (§ 0.4) times 14 days, in rows
  of under 80 bytes plus the index; purged on `allocated_at` with the ordinary pass.
- **The wire:** one short ASCII string per `subagents[]` element (232 B on the worst-case delta, § 4.2).
- **The hazard's cost (§ 3.4):** a seat creation racing a dispatch open can repeat a species while some remain —
  never a moved body, never a retry loop.
- **What it removes:** the list-growth re-draw of every open intern (FLOOR.md § 10.4's growth bullet, now reversed),
  a second identity function for one body, and the parity obligation between renderers.

## 7. How the tests pin it — property, plant, observable

Each leg names the plant that reds it; a leg not seen red once is not done (canon #9).

| # | Property | Plant / observable | Red when |
|---|---|---|---|
| T1 | `choose()` never returns an excluded species, even when it is the only free one or the least used | unit, over a literal 4-species list as `CharacterAllocationTest` does: exclude `ant`; `ant` free, the rest held once → the result is not `ant`; the rest held twice, `ant` free → still not `ant` | the exclusion filter is removed |
| T2 | every open dispatch row has a species, allocated once, by the rules, over seats and interns; a replay finds and never allocates | feature, `AnInternsCharacterIsAllocatedOnceTest` on `FeedTestCase`'s rig (the real ingest; the sites driven through `Fold::pass()`, **never through `mezzanine:fold`, whose startup seed would give a row a skipped site left bare**): seat S holds X; `tool.start(Agent)` #1, whose own draw is free, not X and not among the list's first three → its own draw (the preference leg); #2 on S → ≠ X, ≠ #1; `tool.start(Agent)` on seat T → ≠ every holder; `tool.end` #1 then #3 whose own draw is #1's species → #3 takes it, and #1's row still holds it; a late `subagent.spawn` and a duplicate `tool.start` on open #2 → `duplicate_open` counted, the row unchanged; spawn-first #4 → a row, unchanged by its later `tool.start`; `tool.start(Bash)` #5 → no row, then `subagent.spawn` → a row; `subagent.stop`-first #6 → a closed row and no species row; every character distinct; `subagents[]` delivers each open intern's row. **The startup-seed leg** (through `mezzanine:fold --once`, which is its subject): an open dispatch row planted with no row gains one, no other row changes, a second start changes nothing. **The throwing-seed leg:** the list's reader throws → the command succeeds, the waiting event folds, `intern_species_seed_failed` reads 1, the bare intern is `null` on the wire. **The rebuild leg:** with an intern open, another seat is planted holding its species, `mezzanine:rebuild --seat` → the same species, `state_rebuilds` 1. **The closed-history leg:** closed dispatch calls with their rows, the list's reader replaced by a spy, rebuild → the spy is never read. *(The third revision's two-writer leg is dropped with the locking re-read it tested, § 3.3.)* | the `tool.start` site, the spawn-first site or the flip site skips `ensure()`; the preference order is replaced by the list's own order; the close does not free a species; `FoldCommand` does not seed; the seed's throw escapes; `reset()` deletes the seat's species rows (the replay allocates again and #2 moves); the find is skipped (the spy reads) |
| T3 | past exhaustion, an intern repeats off its floor first, then least-used, and never its seat's body | feature, the population planted as rows and the call allocated through `InternSpecies::ensure()`: seat S on floor one and seat F on floor two of a stored layout, S's interns holding every other species; the next intern of S (own draw neither body) → F's species, held only on the other floor; the next (own draw S's body, which rule 3 would rank first) → not S's body | rule 0's filter is dropped; rule 2's thunk is not called (an empty floor handed in) |
| T4 | the migration writes a row for every dispatch call in `calls` — the open ones by the rules, the closed ones (a `subagent.stop`-first row is one) their key's first species that is not their seat's — and none for a non-dispatch call; the purge removes a row on `allocated_at` against the event boundary and on nothing else, and the purge's order columns are declared | `TheInternSpeciesMigrationBackfillsEveryDispatchCallTest`, in `TheCharacterMigrationKeepsEveryUniqueDrawTest`'s shape: one seat, `down()`, fixtures (two open dispatch rows; two closed dispatch rows whose own draw IS the seat's body; a closed and an open non-dispatch row), `up()`; the open rows distinct from the seat and each other, each closed row the first of its key's preference order that is not the seat's, the non-dispatch rows with none; its printed table. The purge leg in `PurgeTest`'s rig, aged by the clock: a row older than the boundary goes **whether its call is open or purged**; a row younger stays **even when its `calls` row has been deleted** (the `--since` rebuild's shape); the existing order-columns leg reds on an undeclared table | the backfill reads the open rows alone; it drops rule 0 for a closed row; it writes a row for a non-dispatch call; the table is missing from `Purge::PLAN`; the purge deletes on an anti-join with `calls` (the young-row-without-call plant is what reds that); the `orderColumns()` entry is removed (the pass throws) |
| T5a | the population excludes a retired seat's open interns | feature: retire a seat whose intern holds Y; the next allocation whose own draw is Y takes Y | `openInterns()` skips `RetirementFilter::renderable()` |
| T5b | an intern of a room-less seat skips rule 2 | feature through `InternSpecies::ensure()`: two room-less seats and a placed one, the list exhausted with only the placed seat's species held twice; an intern of a room-less seat → its own draw, ranked by the building alone — pooled as one floor, the room-less characters would leave only the placed species free and rule 2 would take it | `floorOf('unassigned')` is handed to rule 2 |
| T5 | a new seat avoids an open intern's species (Q1 as ruled) | feature: an intern holds species Y; issue a token for a seat whose own draw is Y → the seat gets its next preference | `issue()` reads `livePopulation()` alone |
| T6 | the browser draws exactly `subagents[].species` for each intern, in both renderers, and reports F14 for a `null` one in both | `painter-probe.mjs`: fixtures plant per-intern species apart from the key's own draw **and** apart from today's `internSpeciesOf()` value (so a painter still calling the old function reds — the same reason `body_.apart` exists at `:1368–1376`); each intern image's `href` equals `chibiFrame(internKey, species)` / `internFrames()` in the planted species; a species moved between paints is re-drawn (the cache leg); an element whose species is `null` draws the placeholder and reports `intern:<key>`, and so does an intern **walker** whose species is `null` beside a seat with a species (the `body-paint.js:87` fallback plant); **the departure leg:** a delta that drops an intern from `subagents[]` while the seat's species differs from the departed intern's → the A10 departure walker's frames are the **departed intern's** species (the lost element's), not the seat's and not the frame's current stools'. **The 3-D leg** (`floor-world-probe.mjs` / `body-paint`'s `inspect()`): every intern body's `spec.species` equals the planted value **and is a string**; for a planted `null`, `failed()` is called with `intern:<key>`, `createCreature()` is not called for that key, and `inspect()` lists no body for it — the leg asserts the report and the absence, never `null === null` | the painter or `body-paint` reads `seat_species`, calls `internSpeciesOf()`, falls back to the key, or falls back to the seat's body for an intern; `shape()` reads the frame's desk for a departure; `standBodies()` passes `null ?? undefined` to `createCreature()` (a real body is built — the pre-S2 behaviour, the plant's control) |
| T7 | the tree forces an intern's species; the sheet draws an intern key's plain draw | `tools/characters/selftest.mjs` § 6 rewritten: for every species `sp`, `chibiFrame(k, sp)` and `internFrames(k, sp)` draw `sp`; `speciesOf('a~b')` equals `pick('a~b', SPECIES_FIELD, SPECIES_KEYS)`; the *sibling repeat rates* measurement is **retired** with the function it measured; the head-share and laptop legs stand | a `~` branch survives in `speciesOf()` |
| T8 | the member and the version are on the wire and in lockstep | the existing object-matches-the-document test gains `subagents[].species`; the `FEED_VERSION` lockstep test reads 4 on both sides; a snapshot and the delta announcing an intern both carry the member | one side bumped |
| T9 | no module reads an intern's body off its seat's | `git grep -c seat_species -- server/public/js resources` prints nothing; recorded on the card with its run | a site is missed |

## 8. The slice plan, for the coder

Each slice is one PR to `dev`, `[no-close]` until the last, each with its docs in the same round (§ 12's rows for
that slice) and a `docs/changelog.d/13258[-<slug>].md` note.

| Slice | Content | Closes |
|---|---|---|
| **S1 — the server allocates an intern's species** (✅ built) | § 4.1's migration with its two-kind backfill (open rows by the rules, closed rows rule 0's answer) and printed table; `InternSpecies::seed()`, the one seed primitive — the migration's backfill, and run by `FoldCommand::handle()` before its first `pass()`, its throw logged and counted (`intern_species_seed_failed`) and never stopping the fold — **no `RebuildCommand` seed, no `deploy.sh` step**; `'intern_species'` in `Purge::PLAN` (after `events`) and in `Purge::orderColumns()` (§ 3.5); `choose()`'s `$excluded`, `openInterns()` under `RetirementFilter::renderable()`, `characters()` (§ 3.2), the empty floor for a room-less seat (R4); `InternSpecies::ensure()` — find, allocate, `INSERT … ON DUPLICATE KEY UPDATE call_id = call_id`, nothing returned — at the three sites (§ 3.3); the species list kept per worker process (§ 6); `issue()` reading the union (Q1); `openSubagents()`'s LEFT JOIN and `SeatObject` delivering `subagents[].species` nullable; the second-worker sentence in `Fold::claim()`'s docblock (§ 3.4). T1–T5b (T2's startup-seed, throwing-seed, rebuild and closed-history legs included), T8's server half (the member, with `SeatObjectMatchesTheDocumentTest`'s fixture given an open intern so `subagents[]`'s members are compared; **no bump yet**). **The client fixtures carry the member too** (ruled at S1's build, after `EveryFixtureSeatMatchesThePublishedSeatObjectTest` — which holds every `fx-*.json` seat object to § 8.2.1 as parsed — had gone red on the design commit's § 8.2.1 row): every `subagents[]` element in `server/tests/Feature/Floor/fixtures/fx-*.json` holds `species` = `internSpeciesOf(internKey(seat_id, call_id), character.species)`, the body the floor draws today, computed by the generator's own function — written into the hand-kept fixtures by a one-off script and emitted by `tools/design/gen-fx-snack.mjs` and `gen-fx-turn-back.mjs` from their regenerated sources — so nothing changes on screen; S2's T6 re-plants the elements whose species must differ from that value. Docs: D2 § 6.4, § 6.5, § 6.6, § 6.7, § 6.13, § 8.2.1, § 13 row 67, Appendix B row 18 (this commit drafts them; S1 marks them built) | — |
| **S2 — both floors draw the delivered species** (✅ built) | § 4.3: the tree (`chibiFrame`/`internFrames` forced, `speciesOf` plain, `internSpeciesOf` and `species-redraw` deleted), every `seat_species` site, `body-paint.js`, the probes and the selftest; `FEED_VERSION` 4 on both sides. T6, T7, T8, T9. Docs: D3 § 8, § 10.4 (three bullets), the frame contract, AT-D3-24, § 13 rows 54 and 121, Appendix B row 59, T41 (this commit drafts them; S2 marks them built) | **card#13258** |

Order: S1 → S2; S2 after `card-13033-3d-chars` lands, or subsuming it (§ 4.4). One release carries both.

## 9. What could falsify it, and what is not defended

- **The seat-creation race (§ 3.4)** — reachable, named, not defended; its observable is § 3.4's query. A suite
  leg that holds a fold window open across an `issue()` commit would need a probe connection on one login;
  AT-D2-31's real-commit rig could be extended to it, and the design declines it: the rendered outcome is one the
  rules already admit, and the leg would pin the engine's snapshot semantics rather than this code.
- **A second fold worker** — re-opens R3 before it starts (card#7523); declared in D2 § 6.5 and `Fold::claim()`'s
  docblock. Not defended by code, because the code that would race does not exist yet.
- **The deploy window** — closed by the worker's startup seed (§ 3.4). What remains: a `tool.start` the old
  worker folds after the new worker's seed has run — impossible while `flock -n` admits one worker per checkout,
  and the new one is started by the restart that killed the old; stated so a reader checks `supervision.sh` rather
  than trusts it. A rebuild launched inside the window allocates a deploy-window call at its replayed site; if it
  meets the worker's startup seed on one key, the meeting is a `1020` (§ 3.3) the rebuild retries, and a seed that
  lost it is logged and counted (`intern_species_seed_failed`) and leaves the interns it did not reach `null`.
- **A rebuild inside the retention window after the deploy** — would allocate afresh for every closed historical
  dispatch if the backfill covered only the open calls; the backfill covers every dispatch row in `calls` (§ 4.1),
  and T2's closed-history leg reds if the replay reads the species list at all.
- **An open dispatch row with no `intern_species` row** — a defect of § 3.3's invariant, `null` on the wire,
  rendered as F14 for that intern in both renderers, never hidden by a fallback body — and in 3-D that needs the
  explicit route of § 4.3, because `recipe()`'s absent-force default is the key's own body. T2 covers the three
  arrival orders; T6 covers both renderers' F14; a fourth order nobody has named is what would falsify the table,
  and F14 is how it would show.
- **A rebuild that moves a body** — would mean the find was skipped or the row was deleted; T2's rebuild leg is the
  check, and `reset()`'s delete list (`RebuildCommand.php:213–220`) is where a later hand could add the table by
  mistake — the fence comment in D2 § 6.4 says why it must not.
- **A truncated rebuild** (`--since` inside the window, D2 § 6.6's last paragraph): calls opened before the window
  are absent from the replay and so from `subagents[]`; their `intern_species` rows stay until the purge removes
  them on `allocated_at`, holding no species in the population (the population joins from `calls`), and a later
  full rebuild finds them — which is exactly why the purge is not keyed on the `calls` row (§ 3.5).
- **A sweeper-closed call reopened by a replay** (§ 3.5 (a)) — on `subagents[]` in its original body until the
  next sweep closes it; stated, not defended.
- **A departing intern in the wrong body** — `shape()` reading the frame's desk instead of the lost element
  (§ 4.3); T6's departure leg is the check.
- **The list shrinking under an open intern** — D2 § 6.13's process rule covers seats; it now covers interns by
  the same sentence (a removal's migration re-allocates every `intern_species` row of an open dispatch call holding
  the species and asserts none remains). Not defended by the suite, for the reason § 6.13 gives.
- **A rollback of the tree under a store that allocated from a longer list** — F14 on those interns within minutes
  (§ 5), the honest render.
- **An intern's body in two bodies on one page** — impossible after the bump: a page on `feed_version` 3 shows the
  banner and stops applying deltas (D2 § 8.1).
- **A new seat's permanent body chosen around a transient intern (Q1)** — a product cost, not a defect; § 11 puts
  it to the operator with its example.
- **Not defended, by design:** a cool-down after an intern leaves (Q2, recommended against); a per-desk or
  per-floor pool (the operator's scope is the building); a repair of a repeat found after the fact (rule 4).

## 10. Seat rulings — reversible by the operator, each one rule

- **R1 — an intern never wears its own seat's body, even past exhaustion.** The operator's first ruling on interns
  (*differs from its seat*) and the one claim decision 54 held by construction; a filter of one species leaves 47
  candidates. Reversal: drop the filter; the seat's body becomes an ordinary candidate under rules 1–3.
- **R2 — the seed allocates open interns in one pass, in `id` order.** Every open intern may change body once at
  the deploy's forced reload. Reversal: the seats' two-pass reserve-then-displace, at the cost of its code for a
  population that lives minutes.
- **R3 — the fold takes no mutex and reads the population under its window's snapshot** (§ 3.4), which is correct
  for intern-vs-intern **while one fold worker runs** and leaves the seat-creation race named. **Re-opened, not
  revisited on a schedule, when a second fold worker is introduced (card#7523)** — declared in D2 § 6.5 and
  `Fold::claim()`'s docblock. An engineering decision with a quantified cost rather than a product ruling; listed so
  the operator can see what was traded.
- **R4 — a room-less seat's intern skips rule 2** (an empty floor, as a new seat does under card#13117 Q6 (a)),
  rather than pooling every room-less seat as one floor through `floorOf('unassigned')`. Reversal: hand rule 2 the
  Unassigned room as a floor.

## 11. Operator questions — each with context, an example per option, and the recommendation

**Q1 — Does a NEW SEAT avoid the species an open intern holds, as interns avoid seats?** The brief says *every
character in the building*; the asymmetry is that a seat keeps its body for life and an intern for minutes.

- *(a) Symmetric — the seat avoids open interns too (the build as planned, T5).* Example: a floor holds 10 seats and
  6 interns at the moment the operator issues a token; the new seat's own draw is `otter`, which an intern wears
  for the next four minutes; the seat is allocated its next preference, say `snail`, and is `snail` for life. Cost:
  a body given up permanently to avoid a four-minute repeat.
- *(b) One-way — interns avoid everyone; seats avoid seats only.* Example: the same token issue allocates `otter`; for
  four minutes the new desk and an intern across the floor are both otters, and each time that intern's seat
  dispatches an intern whose own draw is `otter` while `otter` is otherwise free, the pair recurs. Cost: a repeat the
  operator will see at the one moment they are looking — a token issue is an operator act at the console with the
  floor open.

**Ruled (a) by the operator, 2026-11-14 — one pool.** The recommendation was (a): the operator's words are about the building, a seat creation is the moment the operator is
watching, and `otter` versus `snail` for a new seat costs nothing the operator has said they value.

**Q2 — Should a species an intern has just released be held back for a short while, so a newcomer is not mistaken
for the intern that just left?** Decision 47 exists so a viewer can follow a sprite; a departure (A10, the walk to
the elevator) followed within seconds by an arrival in the same body reads as the same intern coming back.

- *(a) No cool-down — the seats' rule as it stands (the build as planned).* Example: intern `fox` leaves at 14:02:10;
  at 14:02:30 another seat dispatches an intern whose own draw is `fox`; it walks in as a fox. It happens when the
  newcomer's own draw is the released species — one chance in 48 while the list is wide open, more often as the
  building fills.
- *(b) A cool-down of N seconds.* The population counts a call closed within the last N seconds as a holder; one
  more term in § 3.1's predicate, no new table. Example: the same newcomer at 14:02:30 is allocated its next
  preference; at 14:03:30 `fox` is free again. Cost: a constant to choose and justify, a second meaning for *held*
  that D2 § 6.13 would have to carry for interns and not for seats, and one more rule for the viewer to learn.

**Ruled (a) by the operator, 2026-11-14 — no cool-down.** The recommendation was (a): one rule for both populations; the sequence is rare and its reading is a mistaken return,
not a wrong state. Reversible in one predicate if the operator sees it and minds.

## 12. Amendments owed to the ratified documents — the diff list

Written with the design (marked `card#13258`, each with the reason attached), marked **built** by the slice that
lands them — S1's rows below are built:

| Document | Section | Change | Slice |
|---|---|---|---|
| D2 `FLEET-STATE.md` | § 6.4 | the `intern_species` fence beside `species_allocation_lock`, with the durable-input comment | S1 — ✅ built |
| D2 | § 6.5 | the second-worker sentence gains the condition: a second fold worker re-opens R3 (card#7523); `Fold::claim()`'s docblock carries the same sentence (code, S1) | S1 — ✅ built |
| D2 | § 6.6 | `intern_species` named a durable input a rebuild re-reads, why the species is not a `calls` column, and the two replay behaviours (a sweeper-closed call reopened with its body; a fresh allocation only for a call whose events are gone) | S1 — ✅ built |
| D2 | § 6.7 | the retention row: an ordinary `Purge::PLAN` row on `allocated_at`, after `events`, with its `orderColumns()` entry, and why the anti-join was wrong | S1 — ✅ built |
| `FoldCommand` | before the first `pass()` | the startup seed (code, S1), its throw logged and counted; `RebuildCommand` runs no seed (revised at S1's build); `bin/deploy.sh` is **not** touched | S1 — ✅ built |
| D2 | § 6.13 | **not retitled** (its anchor is linked from both documents); a card#13258 paragraph at its head: the population as the union; the intern's writer as find-or-allocate, its sites, rule 0, the no-mutex decision and its exposure; the migration's one pass; the wire member | S1 — ✅ built |
| D2 | § 8.2.1, § 8.2.2, § 8.3.2, § 8.3, § 12 | the `subagents[].species` row; the member in the worked snapshot and, at its 16-B bound, on every element of the worst-case block; every stated byte figure re-derived by `tools/design/verify-fleet-state.py` G3/G5 (typical seat, worst seat, worst delta, the 4- and 50-seat snapshots, the pagination trigger, the message-bound ratio and headroom) — the gate is the check, and this commit was seen red on each before the figures moved | S1 (figures already synced here, since the gate binds the document as written) — ✅ built |
| D3 `FLOOR.md` | § 8.1, § 12, § 14 item 9 in D2 | the cap arithmetic re-derived for the proposed wire (per element 319 B, spare 809 B, two more fit, the ceiling 10), its § 12 rows, and the card's line in § 8.1's cost ledger; `verify-floor.py` G3 is the check | S1 (same reason) — ✅ built |
| D2 | § 7.2, § 8.2.4 | the counter `intern_species_seed_failed` on fleet health (added at S1's build, the final review's round 3) | S1 — ✅ built |
| D2 | § 6.4's `species_allocation_lock` fence; § 6.13's mutex paragraph | `InternSpecies::seed()` named a locker of the mutex beside `issue()` and the seats' backfill (found at S1's build) | S1 — ✅ built |
| D2 | § 13 | row 67 | S1 — ✅ built |
| D2 | § 14 item 18 | the 2026-10-27 *"Ruled: no"* for interns marked reversed by the operator on 2026-11-14 | S1 — ✅ built |
| D2 | Appendix B | row 18 | S1 — ✅ built |
| D2 | AT-D2-31 | its intro names the intern legs this plan's T2–T5 add | S1 — ✅ built |
| D3 `FLOOR.md` | § 8 table, the sprite row | drawn in `subagents[].species` | S2 — ✅ built |
| D3 | § 10.4 tree API paragraph; interns bullet; the *NOT distinct among siblings* sentence; the growth bullet's intern sentence; frame contract item 3 | the delivered species replaces the pure draw; the reversal stated with what replaces it | S2 — ✅ built |
| D3 | AT-D3-24 interns leg | the forced-species leg replaces the repeat-rate measurement | S2 — ✅ built |
| D3 | § 5.1 body row; § 5.6; § 11 `fx-nulls`; § 12 | the render map names the intern's own body; the null render for `subagents[].species` (F14 in both renderers, never a fallback body — in 3-D by the explicit route of § 4.3); `fx-nulls`'s `nulls-b` gaining the member's null marked **proposed (S2)**, § 12's nullable count re-derived from D2's `Null? yes` rows, and § 5.6's and § 12's G10 prose pointing at the gate's own printed count instead of a written figure | S2 — ✅ built |
| D3 | § 13 rows 54 and 121 | 54 superseded; 121 the decision | S2 — ✅ built |
| D3 | Appendix B row 59; T41 | the floor half; the hop's consumer statement | S2 — ✅ built |
| D1 `EVENT-SCHEMA.md` | — | nothing: no event changes; the reporter is untouched | — |

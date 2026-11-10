# card#13117 — design and build plan: an agent is known by its seat id alone, and the admin console places seats in rooms and rooms on named floors

**What this file is.** The design and the build plan for one card, in the shape `plan-11818-reporter-outdated.md`
set and `plan-12987-cab-door-view.md` and `plan-13033-3d-view.md` follow: written for the coder who implements it
and the reviewer who attacks it. It owns the *derivation*, the *sequence* and the *acceptance list as observables*;
it owns no rule. Every rule it states is an amendment owed to the document that owns the subject — D1
(`EVENT-SCHEMA.md`), D2 (`FLEET-STATE.md`), D3 (`FLOOR.md`), D4 (`BOARD-TASK.md`), D5 (`COORD-STORE.md`), D6
(`REPORTER-INSTALL.md`) and `fleet-reporter/INSTALL.md` — and § 14 lists each amendment by section, so the coder
lands the diff to the owning document in the same slice as the code. A reader who finds this file and an owning
document disagreeing trusts the owning document and fixes this file. When the build has landed this file is history.

**It also carries card#13118** (several agents on one computer account each get their own desk — folded in by the
dispatching seat on 2026-11-10, card comment 16709), because that card's answer is a property of the seat identity
and the config format this card redefines, and two documents defining one identity would drift. § 8 is its home;
§ 10 gives it its own slices; if the review loop finds the document too large for one approval, § 8 lifts out
verbatim as `plan-13118-seats-per-account.md` with § 8.1 (the token file and the config) staying here, since that
is the half both cards share.

**Design approval: not yet given.** This revision is the designer's proposal for the review loop and then the
operator (the card's *Process* line). The operator's direction, verbatim from the card (`kbcard show --task 13117`):

1. *"the install ID shouldn't be tied to a floor name. It should just be a short string of random letters"*
2. superseding 1: *"each agent should just only use a unique seat-id without an install-id. The mapping of seat-id
   to a floor should take place in the admin console. This way it is easy to rearrange agents to different
   floors"*

and the three rulings of 2026-11-10 (card comment 16707): **rooms survive** — the console creates named rooms, puts
each seat in a room and each room on a floor, and each room keeps its own desk map and its pm-reserved desk; **a
floor's web address is its name** — `/floor/<slug of the floor's name>`, old addresses redirect after a rename,
reversing card#9273's *a label is never a key* and card#9267's derived key; **a new agent appears in an Unassigned
area until the operator places it.**

## 0. The readings this design rests on

Every claim below about the current state names the file and line, or the command that prints it. The live
readings were taken on this host's sandbox on 2026-11-10 with read-only `php artisan tinker` selects from
`~/mezzanine/server`; no token or secret column was selected and nothing was written. They are **examples**: the
design is derived from the code paths, and § 6 re-runs each reading as a migration precondition rather than
trusting the day's figures.

```bash
cd ~/mezzanine/server
php artisan tinker --execute='foreach (DB::table("installs")->select("id","install_id","display_name","retired_at")->orderBy("id")->get() as $r) echo json_encode($r), "\n";'
# {"id":1,"install_id":"mezzanine","display_name":null,"retired_at":null}
# {"id":2,"install_id":"kanban", ...}  {"id":3,"install_id":"aimla", ...}  {"id":4,"install_id":"sola", ...}  {"id":5,"install_id":"solo-agents", ...}
php artisan tinker --execute='foreach (DB::table("seats")->join("installs","installs.id","=","seats.install_ref")->select("installs.install_id","seats.seat_id","seats.retired_at")->orderBy("seats.id")->get() as $r) echo json_encode($r), "\n";'
# live:    aimla/{aimla-pm, magento, moodle, platform}  sola/{sola-pm, inventory, backend, device, fred}
#          solo-agents/{key, kanban, mezzanine, timothy, universe, estela}
# retired: mezzanine/mezzanine-solo  kanban/kanban-solo  solo-agents/mezzanine-solo  solo-agents/kanban-solo
php artisan tinker --execute='$l = DB::table("building_layout")->first(); echo $l->document, "\nlayout_version=", $l->layout_version, "\n";'
# {"floors": [{"label": "aimla", "theme": "cottage", "rooms": {"aimla": {"form": "open"}}},
#             {"label": "The Burrow", "rooms": {"solo-agents": {"form": "open"}}},
#             {"label": "The Treehouse", "rooms": {"treehouse": {"form": "open"}}},
#             {"label": "The Nook", "rooms": {"nook": {"form": "open"}}}]}      layout_version=4
php artisan tinker --execute='foreach (DB::table("floors")->select("install_id","map_version")->get() as $r) echo json_encode($r), "\n";'
# {"install_id":"solo-agents","map_version":3}  {"install_id":"aimla","map_version":1}
```

Three facts in those readings shape § 6 and are not visible from the card's summary:

- **The live seat ids are pairwise distinct across installs, and the RETIRED ones are not.** `mezzanine-solo` is a
  retired seat of install `mezzanine` and again of `solo-agents`; `kanban-solo` likewise. The uniqueness the card
  asserts holds of the live population only. § 3.1 and § 6.2 are written for that.
- **Two seat ids equal an install id** (`solo-agents/kanban`, `solo-agents/mezzanine`). Harmless once the install id
  leaves every route that also carries a seat id (§ 3.3), and a reason to keep room keys and seat ids in separate
  route segments everywhere they still meet.
- **The layout places two rooms no install row exists for** (`treehouse`, `nook`). `App\Building\Building::compose()`
  (`server/app/Building/Building.php`) already serves them with `reported: false`; the directory names them *not
  reported* (`server/public/js/lobby/directory.js:232`). They are the operator's destinations-in-waiting, and § 6.3
  mints them as rooms.

## 1. The problem, in the product's terms

An agent's floor is fixed on its own machine. `fleet-reporter/install.js` writes `install_id` into the seat's
config from the token file or `--install-id` (`install.js:1706-1715`); the reporter sends it on every batch
(`fleet-reporter.js:4051`); the ingest derives `(install_id, seat_id)` from the token and refuses a batch whose
claimed pair differs (`App\Ingest\TokenResolver::resolve()`, `BatchValidator.php:31`, D1 § 3.3's binding rule);
the store keys the seat under its install (`seats.install_ref`, `UNIQUE uq_seat (install_ref, seat_id)`,
`2026_08_25_100000_create_fleet_store_tables.php`); the building draws the install as a room and composes rooms
into floors keyed by the least install id (`BuildingLayout::parse()`, `Building::compose()`, D3 § 4.6). So moving an
agent to another floor is: retire the seat, issue a token under another install id, reinstall the reporter — and the
operator's own floor *The Burrow* could only ever be a label over the key `solo-agents` (D3 § 4.6, card#9273).

The card's five jobs of `install_id`, read from the tree, and where each is in the code today:

| Job | Where it lives today (file:line or section) |
|---|---|
| (a) the ingest identity | the config's `install_id` (D1 § 3.1); the token file's `install_id` line (`install.js:229`); the envelope field (D1 § 4.2, `fleet-reporter.js:3549`); the binding check (`TokenBinding::matches()`, D1 § 12.1 step 7) |
| (b) the store keys | `installs` (`uq_install`), `seats.install_ref` (`fk_seat_install`, `uq_seat`), `coord_hooks.install_ref`, `floors.install_id`, `authored_revisions.subject` (D2 § 6.4) |
| (c) the floor composition | `BuildingLayout::parse()`'s `$floorKey = array_key_first(rooms)`; `Building::compose()`'s implicit floor per unplaced install; `/floor/{floor}` resolved and redirected by the client (`floor-screen.js:132-136`, `main.js:264-277`; D3 § 4.4 rows 3–4) |
| (d) the room maps | `floors.install_id` → one map per install (`App\Floor\Floors`, D2 § 6.11); the console's floors module routes `/admin/floors/{install_id}/…` |
| (e) the coordination scope | `coord_hooks.install_ref` (`HookBinding`), `coord_round.install_id`, D2 § 8.3.3 rule 1's install arm: `(install_id, protocol_agent_name)` → one seat |

And the surfaces that show or take an install id, so § 10's slices can be checked against the tree rather than this
list — re-derive before each slice, the list below is the reading of 2026-11-10:

```bash
cd /home/mezzanine/wt/wt-13117-design
grep -rln 'install_id\|installId\|install_ref' server/app server/routes server/resources/views server/public/js fleet-reporter/*.js | sort
```

## 2. The shape, and what was rejected

### 2.1 The one sentence, and the five jobs' new homes

**A seat is identified by its `seat_id` alone, unique across the site for life; a room is a console-created record
that seats are placed into and may leave; a floor is a console-named set of rooms whose web address is the slug of
its name; the reporter, the token and the ingest know only the seat.** The `installs` table, its `install_id`
column and `seats.install_ref` are not dropped — they are **the rooms**: card#9267 already made a room an install,
so the store's room entity exists and is reused (canon #5). What changes is who creates it (the console, not a token
issue), what joins a seat to it (a console edit, not the token), and that a seat may be in none.

| Job | New home |
|---|---|
| (a) ingest identity | the token binds a **seat** (`ingest_tokens.seat_ref`, unchanged); the batch's `seat_id` must equal the binding; the batch's `install_id` is **accepted and not read** (§ 3.2). The config's `install_id` becomes optional; the token file gains **v3** without it (§ 8.1) |
| (b) store keys | `installs` is the **rooms** table and `install_id` the **room key**; `seats.install_ref` becomes nullable and console-writable (§ 4.1). `seats.seat_id` is unique site-wide among live seats (§ 3.1). Nothing is renamed in slice 1 — § 12 ruling R3 prices the rename |
| (c) floor composition | a floor is still its layout entry; it gains a required `name` whose slug is its key, and console-written `aliases` for its former slugs (§ 5). The derived key and the implicit floor per install are **retired** (§ 5.6 records both reversals) |
| (d) room maps | unchanged: one map per room, keyed by the room key, in `floors` and `authored_revisions` (§ 4.1) |
| (e) coordination scope | the **room**: D2 § 8.3.3 rule 1's install arm becomes the room arm, `coord_hooks.install_ref` points at the room, the wire's `scope` value `install` becomes `room` (§ 4.3). A seat moved between rooms moves its coordination scope with it — stated, with the console's warning |

### 2.2 Rejected, with the mechanism each turns on

| Rejected | Mechanism | Why not |
|---|---|---|
| **Direction 1 — a random install id** (*"a short string of random letters"*) | the installer mints an opaque install id; the console maps it to a floor | superseded by the operator's direction 2; it keeps the agent's machine as the home of a key the console must then translate, and a reinstall still re-keys the desk |
| **Retire rooms; seats placed straight on floors** | `seats.floor_ref`, one map per floor | ruled out by ruling 1 (rooms survive, each with its map and reserved desk); and the coordination scope (e) would have no home smaller than a floor |
| **Keep `(install_id, seat_id)` as the desk key, with the console editing the install half** | a seat move = a key change | D2 § 8.2.1: *changing either member re-identifies the desk* — the client (`fleet-client.js:83` `key()`) and the slot hash (D3 § 3.2) would see a move as a retirement plus an arrival of a stranger; the card's point is that a move is a move |
| **Floors as a table of their own** | `floors_v2(id, name, slug, theme)`, `rooms.floor_ref` | the layout document already holds theme, hallway, placement and form, with revisions, diff, restore and a feed message behind it (D2 § 6.11, § 8.7); a second home for *which rooms are on a floor* is the drift D3 § 4.6 rule 2 refused for the hallway |
| **A hidden stable floor id for redirects** | a console-minted id in the entry, a `floor_slugs(slug → id)` table | needs a table, an id the reader must refuse duplicates of, and a second truth about which floor a slug names; the alias list in the entry (§ 5.3) rides the layout's revisions and needs none of it |
| **Server-side 301/302 for a renamed floor** | a route table over aliases | the redirect is already the client's (D3 § 4.4 row 3, `main.js:264-277`) from the layout it fetches; a server redirect would be a second resolver of one document |
| **`install_id: null` on the wire for an unassigned seat** | every client site that reads `install_id` handles null; the composer special-cases roomless seats | the population is every file `grep -rln install_id server/public/js` prints; the virtual room `unassigned` (§ 4.4) costs one reserved key and no client change, and is a room the composer already knows how to draw |
| **Coordination scope stays an install the agent declares** (a `team` key in the seat config) | an install id under another name, on the agent's machine | it is the thing direction 2 removes |
| **Protocol agent names unique fleet-wide** | one arm in D2 § 8.3.3 | two live PMs are both `pm` today (`aimla-pm`, `sola-pm` declare it — D5 § 2.1's table); both would resolve to nothing |

## 3. Identity — the seat alone

### 3.1 The invariant, where it is enforced, and the collision

**Invariant.** At any time at most one **live** seat carries a given `seat_id`; and a `seat_id` any seat has ever
carried — live or retired — is never issued again.

**Enforced at issue, and only there, because issue is the only creator of a seat.** `App\Fleet\SeatTokens::issue()`
is the one writer of `seats` (`IssueIngestToken`, the console's `SeatTokenController::issue()` both call it;
`grep -rn "table('seats')->insert" server/app` prints that one site). Its transaction already opens by taking the
species mutex `species_allocation_lock` row `FOR UPDATE` as its first statement (`SeatTokens.php`, card#12443), so
every creation is serialised; inside it, after the mutex, a plain read `SELECT id, retired_at FROM seats WHERE
seat_id = ?` decides:

| Found | Outcome |
|---|---|
| no row | create the seat with `install_ref NULL` (§ 4.4), allocate its species, insert the token |
| a live row | **re-issue** for that seat (rotation, D1 § 3.3's order) — today's behaviour, minus the install segment |
| a retired row | **refused**, `SeatTokenRefusal::seatRetired` — today's refusal, now site-wide: the console's *there is no un-retire — a replacement seat takes a new seat id* (`SeatTokenController.php`) |

**Backstop in the store.** `uq_seat (install_ref, seat_id)` is dropped — with `install_ref` nullable it would admit
two unassigned seats of one id, since MariaDB treats NULLs as distinct in a unique key — and replaced by a
generated column `live_seat_id VARCHAR(48) AS (IF(retired_at IS NULL, seat_id, NULL)) VIRTUAL` with
`UNIQUE KEY uq_live_seat (live_seat_id)`. MariaDB indexes a `VIRTUAL` generated column and names a unique
index over one its *"poor man's partial index"* (`mariadb.com/kb/en/generated-columns/`, read 2026-11-10); `IF()`
is deterministic, which an indexed virtual column requires; and this is a generated **column**, not the
expression index D2 § 6.1 says the engine lacks. The sandbox's engine reads `11.8.6-MariaDB` (`SELECT VERSION()`,
2026-11-10). Reachable route for the backstop: there is no second writer today; the
index is what makes the invariant a property the read side may rely on (§ 3.3 resolves by `seat_id`; the client
keys by it) rather than a property of one function, and it is what refuses a hand `INSERT` or a fixture that
bypasses `issue()`. ⚠ **It is deliberately a LIVE-only key.** A `UNIQUE (seat_id)` over every row is false on the
sandbox today — the two retired pairs § 0 names — and a migration that renamed retired rows to satisfy it would
back-edit a record (`seats` is where a retirement's `by`/`reason` live, D2 § 4.10). The never-reused half of the
invariant is therefore the application's, under the mutex, and § 9's test plants a retired-id re-issue to see it
refuse.

**Collision at issue** — the operator types a `seat_id` that is live elsewhere: the console's issue form and the CLI
report **a re-issue for an existing seat** naming its room and its last receipt, because that is what the act is.
There is no *another install has it* case any more: the whole point is that there is one namespace. The console's
issue form shows the existing seats (a `datalist`, as `known-installs` does today) so a typo is seen before it
rotates a stranger's token. A `seat_id` that is retired anywhere: refused as above.

**The two pre-existing retired duplicates are accepted on the record** (`mezzanine-solo`, `kanban-solo`): both rows
of each pair are retired, neither id can be issued again (the refusal reads any row), neither renders anywhere
(`RetirementFilter::renderable()`), and the one surface that lists them — the console's retired table and the
seat-tokens listing — shows two retired rows of one id with their rooms and dates. No read surface resolves a
retired seat by id alone (§ 3.3), so nothing is ambiguous. The migration (§ 6.2) prints the duplicate set it found
and refuses to run if any pair has a LIVE member, which the sandbox has not and § 6.1 shows the code cannot
produce.

### 3.2 The ingest, and exactly what the server does with a reporter's `install_id`

The token resolves to a seat (`TokenResolver::resolve()` joins `ingest_tokens → seats`; the `installs` join and the
`installId` member of `TokenBinding` go). D1 § 12.1 step 7 becomes: **the batch's `seat_id` equals the binding's,
else `403 identity_mismatch` with `expected_seat_id`** (`expected_install_id` leaves the response; the reporter
classifies the answer by status alone — `INGEST_AUTH = { 401: …, 403: 'identity_mismatch' }`,
`fleet-reporter.js:4285` — and reads neither expectation, so the auth stop of D1 § 11.5 is untouched). Step 9's
per-event `seat_id` equals the batch's, as today.

**The envelope's `install_id`** (D1 § 4.2 row) becomes **optional**: when present it must still be a slug by
D1 § 3.1's `install_id` pattern — the shape check keeps every reporter in the field validating exactly as it does
today, since D1 § 12.1 step 6 runs the bounds before step 7 — **and its value is not read**: not compared, not
stored, not logged. The same for the per-event copy (D1 § 4.3). A reporter that omits it is accepted. ⚠ **Why not
compare it to the seat's room key:** the room is now a console fact the operator changes at will; a reporter that
sent the room it was installed under would start failing `identity_mismatch` on the first move — the reinstall this
card exists to remove — so the field is retired from the server's reading rather than re-pointed. D1 § 4.2's row is
amended to *optional; not read since card#13117; a reporter writing to a pre-13117 server must still send it*, and
§ 8.1 is where the reporter stops sending it.

**Attribution of refusals** (D1 § 12.1's table) is unchanged in shape: steps 5–11 attribute to *the token's bound
seat*.

### 3.3 The read plane — routes and `api_version`

| Today | After | Posture |
|---|---|---|
| `GET /api/fleet/seats/{install_id}/{seat_id}` and `…/timeline` | `GET /api/fleet/seats/{seat_id}` and `…/timeline`, `where('seat_id', Slug::SEAT_ID)` | **REST, machine consumers** (D2 § 8.1 row 1: the bridge's watchdog): `api_version` 1 → **2** (a meaning change: `installs[].install_id` is a room key, and a route is removed). **Window:** the two-segment routes keep answering for one minor release after the one that ships this, resolving by `{seat_id}` alone and checking `{install_id}` for shape only — so a watchdog that upgrades on its own schedule keeps reading; the removal is its own changelog note |
| `GET /api/fleet/snapshot` `installs[].install_id` | the same member, now the **room key**, with one extra group `unassigned` when any live seat is in no room (§ 4.4); `seats[]` members unchanged | as above, `api_version` 2 |
| `POST /desk/{install_id}/{seat_id}/compose` | `POST /desk/{seat_id}/compose` | browser-only (`web`, CSRF) — no window |
| `POST /admin/agents/{install_id}/{seat_id}/retire`, `/admin/floors/{install_id}/…` | `/admin/agents/{seat_id}/retire`; the floors (room-map) module keeps `{install_id}` as the **room key** segment | console, no window |
| `/floor/{floor}/{seat_id}` | unchanged shape; `{floor}` is a slug (§ 5.4); the seat is resolved over the floor's rooms as today, and the *names a desk in more than one room* notice (D3 § 4.4) is **deleted** — § 3.1 makes the state unreachable among rendered seats, and canon #6 deletes a guard with no route | browser |
| `/api/building/rooms/{install_id}/map` | unchanged: the segment is the room key | browser-only |

`App\Read\SeatRow::find($seatId)`, `FleetController::seat()/timeline()`, `Snapshot::query()`'s join become
`LEFT JOIN installs` with `COALESCE(installs.install_id, 'unassigned')` as the served `install_id` (§ 4.4).

### 3.4 The client's key, the desk slot hash and the character seed

`fleet-client.js:83` `key(installId, seatId)` → `seatId`; every `${x.install_id}/${x.seat_id}` template is the same
key spelled inline — the population is `grep -rn 'install_id}/\${' server/public/js` plus `key(` call sites, re-run
on the branch — and each becomes `seat_id`. The seat object still carries `install_id`: it is now **the room the
seat is drawn in**, a mutable member like `render_state`, and a `seat.delta` whose `changed[]` names it is **a room
move** (§ 4.2).

Two hashes read the pair and must stop, because a pair that moves is not a seed:

- D3 § 3.2's slot hash `FNV-1a-32(install_id + "/" + seat_id)` → `FNV-1a-32(seat_id)`. The order tie-break
  `(h, seat_id)` is already total on `seat_id`.
- D3 § 10.2's character seed, `resources/characters/seed.js` `seatKey(installId, seatId)` → `seatKey(seatId)`; the
  server's port `App\Characters\Draw` and `CharacterAllocation::choose()`'s preference key `install_id/seat_id` →
  `seat_id`; the three copies are bound through D3 § 3.2's published `h` table, which this change re-publishes
  (`tools/design/verify-floor.py` G8 and `TheSpeciesListHasOneSourceTest` read it).

⚠ **This is a one-time visible change for every existing seat**: on the deploy of the slice that lands it, every
desk re-slots once (a desk move with no cause message — D3 § 13 row 29's *a map or layout apply fires no animation*
is the precedent: the slice bumps `feed_version` so every page reloads, and a reload animates nothing, D3 § 6.5) and
every character re-rolls its colourway, face and dress; **species do not move** (stored, D2 § 6.13). § 13 Q1 asks
whether to accept it or to pin the old look with a stored seed.

### 3.5 Retirement, the board link, the roundtable

- `mezzanine:retire --seat=<seat_id>`; the console's retire form posts the seat id; `App\Fleet\SeatRetirement::retire(seatId, …)`
  resolves the row by `seat_id` among live seats (a retired id is *already retired*). The `seat.retired` message
  keeps `install_id` (the room at retirement, or `unassigned`) beside `seat_id`.
- `mezzanine:seat-board-user --seat=<seat_id>`; `LinkPass`'s label `install/seat` → `seat`. `seats.board_user_id`
  unique: unchanged.
- The roundtable name (D1 § 3.1 `roundtable_agent_name`, D2 § 8.3.3's fleet arm) is fleet-scope and never read
  an install: unchanged.
- A retired seat id is never reused: § 3.1's refusal, now the only rule; D2 § 4.10's sentence *a replacement seat
  takes a new seat id* holds site-wide.

## 4. Rooms

### 4.1 The store: `installs` is the rooms table

| Column | Today | After |
|---|---|---|
| `installs.install_id` | typed at token issue (`SeatTokens::issue()` inserts the install when missing) | the **room key**: typed once at room creation in the console, `Slug::INSTALL_ID`'s pattern, immutable; `unassigned` **reserved** (refused by name, § 4.4). It is the `subject` of the room's map revisions and the `floors.install_id` of its current map, which is why it is not a slug of the name: a rename must not orphan a map's history |
| `installs.display_name` | present, never written (`grep -rn display_name server/app` prints the DDL only) | the room's **name**, free text, renamable; `null` reads as the key, as a floor's absent label did |
| `installs.retired_at` | present, never written | set by the console's **retire room**; refused while any live seat is in the room; the same transaction removes the room from the layout through `Layouts::save()` (one revision), so no stored layout places a retired room; a later save or restore that would place one is refused **at the write** by name (route: the raw JSON editor, a restore of an older revision) — never at load, because no load can meet one. A retired room is listed in the console only, and its map history stands |
| `seats.install_ref` | `NOT NULL`, written at issue | **nullable**; `NULL` = unassigned; written at issue (`NULL`) and by the console's **place seat** (§ 4.2) |
| `coord_hooks.install_ref` | the install | the room (§ 4.3) |
| `floors`, `authored_revisions` | keyed by the install id | keyed by the room key — unchanged bytes |

D2 § 6.4's DDL block gains the comment `-- the ROOM (card#9267; created in the console since card#13117)` on
`installs` where line 2205 already says so for `coord_hooks`, and the `uq_live_seat` key replaces `uq_seat`.

### 4.2 The console: rooms, and the seat move

A **Rooms** module beside *Agents*, *Floors* (room maps) and *Layout*: `GET /admin/rooms` (every room, live and
retired, with its floor from the current layout, its seats, its map version), `POST /admin/rooms` (key, name,
optional floor — placing writes the layout through `Layouts::save()`, in the same transaction as the room's
insert, so the room and its placement are one revision), `PATCH /admin/rooms/{room}` (name), `POST
/admin/rooms/{room}/retire`. The **Agents** index gains one control per live seat: *Room* — a select over live
rooms plus *Unassigned* — posting `PATCH /admin/agents/{seat_id}/room`.

**The move is one transaction and one announcement.** Under `SeatLock::forUpdate()` (the order card#12461 fixed):
`UPDATE seats SET install_ref = ?`, `StateRecompute::forSeat(…, 'operator', …)` bumps `state_version`, and the
`seat.delta` it enqueues carries `install_id` in `changed[]` and the new key in `patch` — the room is a member of
the seat object, so a move is a delta like any other and nothing new rides the feed. The client (§ 3.4) finds the
seat under its `seat_id`, applies the patch, and the next frame's slot function places it in the new room; the old
room's chain re-probes as it does on a retirement (D3 § 3.5). **Render:** the desk leaves the old room as a
retirement's desk does (D3 A13, with a log line *moved to room X*) and appears in the new room as a seat inserted by
§ 2.3's fetch does — no arrival walk, because *the seat was placed* is a console fact, not *the seat came online*
(D3 § 3.4's last paragraph). § 12 ruling R6 makes this reversible.

**The console warns, and does not refuse, on two moves**: into a room where another live seat declares the same
`protocol_agent_name` (the duplicate arm of D2 § 8.3.3 rule 1 will then resolve both to nothing and report it); and
out of the room a coordination hook is bound to, when the seat declares a name that hook's posts address. Both are
one query over `seat_state.protocol_agent_name` and `coord_hooks`, rendered as a notice on the form; the move is the
operator's to make.

### 4.3 The coordination scope is the room

D2 § 8.3.3 rule 1's install arm reads `(room, protocol_agent_name)` → the one live seat **currently in that room**
declaring the name. `coord_hooks.install_ref` references the room; `HookBinding::issue()`'s refusal *no install X
exists here — issue a seat token for it first* becomes *no room X — create it in the console first*; the CLI's
`--scope=install --install=<id>` becomes `--scope=room --room=<key>`. The wire's `scope` value `install` becomes
**`room`** on `coord.round` and in `coord_hooks.scope`'s enum (one `ALTER`), since the feed is browser-only with no
window (D2 § 8.1 row 2) and the client's one branch (`coord-join.js`) is in the same deploy. `coord_round.install_id`
keeps its name and carries the room key. **Cost stated:** which `pm` a team repository's post resolves to is now a
function of the console's placement, and § 4.2's warning is the whole defence; the alternative — a binding naming
its seats explicitly — is a second membership registry (canon #5) and was rejected in § 2.2.

### 4.4 Unassigned

`Building::compose()` no longer mints a floor per unplaced install. It mints **one implicit floor**, key
`unassigned`, name *Unassigned*, house theme, sorted last and present only when non-empty, whose rooms are: every
live room the layout does not place, in key order under § 4.6 rule 3's default arrangement; and, when any live seat
has `install_ref NULL`, the **virtual room** `unassigned` holding those seats. The virtual room is served to the
client exactly as a room is — `Snapshot` groups those seats under `install_id: "unassigned"`, every seat object
and delta of theirs carries it, and `GET /api/building/rooms/unassigned/map` answers the shipped default because no
`floors` row can hold the reserved key — so **the client draws it with no new code path**: a room with the default
map, the house theme, the slot function over its seats. `FloorInventory` (the console's room-map index) excludes
the reserved key, so nobody authors a map for it. The reserved key is refused by name at room creation and by the
layout reader (a layout placing `unassigned` is a document that places a room the console cannot create).

**Why a floor and not a notice:** ruling 3 says *area*, and an area a viewer can ride to — a stop in the lobby's
directory and the cab's panel, shown only while it holds something — is how the operator finds a new agent to place
it. The lobby's F17 *unclaimed rooms* directory (D3 § 9) is subsumed: an unplaced room is now on this floor.

## 5. Floors — named, slugged, redirected

### 5.1 The document

The layout entry (D3 § 4.6's table) changes two members and keeps the rest byte for byte:

| Member | Was | Now |
|---|---|---|
| `label` | optional display text | **removed**; the reader refuses it by name with *since card#13117 the member is `name`* (one shape; two spellings would be two formats for one thing) |
| `name` | — | **required**: a string whose rendered form (D3 § 4.6's whitespace rule, kept) is non-blank and whose slug (§ 5.2) is non-empty and not `unassigned`. Stored byte for byte; rendered wherever `label` rendered (plaque, stop, floor screen, building view, directory) |
| `aliases` | — | optional list of slugs the floor used to answer to (§ 5.3); each a valid slug, not `unassigned`, not equal to any floor's current slug, and present in at most one floor's list. Written by the console's rename; readable and editable in the raw document |
| `rooms`, `theme`, `hallway`, a room's `form` and `origin` | | unchanged |

The served floor (`GET /api/building`, D2 § 8.7) carries `floor` = the slug, `name`, `aliases`, and the rest as
today. `Building::compose()` keys floors by slug.

**Refused at load, each naming what it names** (`InvalidBuildingLayout`, so a stored document a later reader refuses
is D3 § 9 F17 as today): a floor with no `name`, a non-string `name`, a blank or slug-empty `name`; two floors whose
names slug alike (replacing *two floors that would read the same*, which it subsumes: two names that read alike
always slug alike); an alias colliding with any current slug or repeated across floors; the reserved slug as a name
or an alias; a placed room whose key is `unassigned`. **Refused at the write only** (`Layouts::save()` and a
restore, as a theme name the registry lacks is today, D3 § 4.6): a placed room the store holds as retired — § 4.1
says why no load can meet one. A room key the store does not hold stays **allowed**, as today (`reported: false`),
so a layout may still be authored ahead of a room.

### 5.2 The slug function, and every reachable edge

```
slug(name) = name
  → Unicode NFKD, then drop every combining mark (\p{Mn})          "Thé Nook" → "The Nook"
  → lowercase (Unicode simple case folding is not used: after the step below only [a-z0-9] survive)
  → every maximal run of characters outside [a-z0-9] becomes one "-"   "The  Nook!" → "the-nook-"
  → strip leading and trailing "-"                                     → "the-nook"
```

One implementation in PHP (`App\Building\FloorSlug`, used by the reader and the console) and one in the client
(`floor/floor-slug.js`, used only to display the address a name will get on the console form) — the client never
resolves a slug it did not receive from `GET /api/building`, so the two cannot disagree about a route; a lockstep
test drives both over one fixture table (canon #16's self-contained-copy clause: the browser cannot import PHP).

| Edge | What happens | Why not otherwise |
|---|---|---|
| allowed characters in the address | `[a-z0-9-]+`, no leading or trailing `-` | the route segment and D1's slug vocabulary; nothing percent-encoded ever appears in a floor's address |
| a name of only letters outside Latin, or only punctuation (`三階`, `***`) | slug is empty → the name is **refused at the write** by name: *a floor's name must contain a letter or digit a–z 0–9 once accents are folded* | transliteration is a library and a policy this repository does not have; percent-encoded addresses are unreadable and still need a uniqueness rule. The cost is named: a floor cannot be named in a script with no Latin letters, and the operator sees why |
| two names slug alike (`The Nook` / `the nook` / `Thé  Nook`) | the second is **refused at the write**, naming both floors and the shared slug | one address cannot name two floors, and the reader does not pick |
| a name equal to a room key or a seat id | allowed — floors, rooms and seats never share a route segment position (§ 3.3) | the collision D3 § 4.6 feared was floor keys with install ids in one namespace; that namespace is gone |
| a name whose slug is `unassigned` | refused at the write and at load | § 4.4's reserved floor |
| length | unbounded beyond the document's 512 KiB (D2 § 6.11) | D3 § 4.6's `origin` precedent: *a bound would be a number with no derivation behind it*; a long name is a long address the author sees on the form before saving |
| case in a typed address (`/floor/The-Nook`) | the client lowercases the segment before resolving, so it reaches the floor; the address bar is replaced with the canonical slug (`main.js:276-277` already does this for the key) | an address someone typed from a plaque should work |

### 5.3 Rename, redirect, reuse, removal — and why there are no chains

- **Rename** (console, § 5.5): the entry's `name` changes and, when the new slug differs from the old, the **old
  slug is appended to `aliases`** (if not already there). A rename back to a former name removes that slug from
  `aliases` as it becomes current again (the reader would otherwise refuse the collision).
- **Redirect**: `/floor/{segment}` where `segment` is a current slug → that floor; where it is an alias → the
  client performs `location.replace('/floor/{current}[/{seat_id}]')` with the seat segment preserved, exactly as
  the room→floor redirect does today (D3 § 4.4 row 3, `main.js:264-277`; `#ride` survives a `replace`); where it is
  neither → the floor page's existing *no such floor* state.
- **No chains by construction**: every alias maps to the holder's **current** slug, and a second rename appends
  another alias to the same entry; a name used three times ago still redirects in one hop.
- **Reuse of an old slug by another floor**: a save giving floor B a name whose slug is in floor A's `aliases` is
  **refused** at the write, naming A: *`nook` still redirects to The Den; remove it from The Den's old addresses
  first*. The console's rename form lists the floor's old addresses with a *forget* control (= remove from
  `aliases`). § 13 Q2 offers the alternative (B takes the slug, A's alias is dropped silently) with its cost.
- **Removal of a floor** (the entry deleted; the console refuses while the floor holds rooms, the raw editor does
  not): its slug and aliases resolve to nothing; old links reach *no such floor*. Not defended further: a deleted
  floor has no forwarding address, and inventing one is a floor id by another route.
- **A raw-JSON rename** that does not add the alias: the operator wrote a document with no redirect, and gets one;
  the console's form is where the alias is written for them. Named, not defended.
- **A restore** of a revision (D2 § 6.11) is checked exactly as a save — so restoring a revision whose aliases
  collide with a floor created since is refused by name, as a restore that re-creates an overlap is today.

### 5.4 The routes and the client

`routes/web.php`'s `/floor/{floor}` and `/floor/{floor}/{seat}` are unchanged: the segment is handed to the page as
data and resolved against the fetched layout. `floor-screen.js`'s resolver gains the alias branch beside the
room→floor branch it has (`:132-136`) — and the room→floor branch itself **stays**: `/floor/{room-key}` for a room
on a floor still redirects to the floor, which is how every pre-13117 link survives even where a floor's new slug
differs from its old key (§ 6.3 also writes the old key as an alias, so both branches reach the same place; the room
branch covers rooms moved since). The directory (`directory.js:217`), the cab panel and the building view read
`floor` (now the slug) and `name` where they read `floor` and `label`.

### 5.5 The console's floors module — where the requirement forces a large change, priced

Today the layout is a `<textarea>` (`console/layout/edit.blade.php:29`, `LayoutController::update()`). Rulings 1–2
ask the operator to *create named rooms, put each seat in a room, put each room on a floor* and *name floors
freely* — a form, not JSON: a **Floors** module with `GET /admin/building` (floors with their rooms, names, slugs,
aliases, themes), `POST /admin/building/floors` (name, theme), `PATCH /admin/building/floors/{slug}` (rename →
alias rule; theme; forget an alias), `POST /admin/building/floors/{slug}/rooms` (place a room — moves it from its
current floor; `origin` cleared on both floors, since a planned floor is all-or-nothing, D3 § 4.6), `POST
/admin/building/floors/{slug}/remove` (refused while it holds rooms). Every action builds the new document from the
current one and writes it through `Layouts::save()` — one writer, one revision, one `building.layout` message — so
the textarea stays for what the form does not do (`origin`, `hallway`) and the two never disagree. **Price:** a
controller the size of `FloorController.php` (`wc -l`: 370) with four views, its feature tests, and `README.md §
The admin console`'s paragraph; one slice (§ 10 S5). Until it lands the operator authors the same document in the
textarea, with the reader's refusals as the guide — every earlier slice is usable without it.

### 5.6 The two reversals, recorded where the rulings stand

Ruling 2 reverses card#9273 (*a label is never a key*) and card#9267's derived key. Both are stated in the tree at
every site `grep -n 'card#9273\|card#9267' docs/design/*.md server/app -r` prints (on 2026-11-10: D3 at
`grep -c` = 42 lines, D2 at 2, `Building.php` at 2, `BuildingLayout.php` at 5, `FloorInventory.php` at 2). Each
site is amended **in place** with a ⭐ note naming card#13117 — the convention D3 § 3.1 set for card#9267's own
sweep — and the sweep is re-derived from the grep at the slice, never from this paragraph. The load-bearing ones:
D3 § 3.1's floor row (key = slug of the name; the install row becomes the room row), § 4.4 rows 3–4 (the alias
redirect beside the room redirect), § 4.6's `a floor's key`, `a floor's label` and *A floor has no authored id*
paragraphs (the no-collision argument's premise — floor keys and install ids in one route namespace — no longer
holds, which is why the derived key can go), § 13 rows 24, 25, 27, and D2 § 6.11's *(an install, card#9267)*.
**Card#9267's room-is-an-install half survives** (a room is still the coordination and map unit); what reverses is
the key derivation and the implicit floor per install.

## 6. Migration — no agent is reinstalled

### 6.1 The uniqueness claim, verified from the code paths

The card asks for the live seat ids' uniqueness to be verified from the code, not the data. The code **cannot
guarantee it**: `uq_seat (install_ref, seat_id)` scopes uniqueness to an install by design (D2 § 8.2.1's
*uniqueness is per install, not global*), and `SeatTokens::issue()` looks the seat up by the pair, so two installs
may hold one live `seat_id` and the sandbox's retired rows show the path was taken twice. **The migration therefore
measures rather than assumes**, as its first statement:

```sql
SELECT seat_id, COUNT(*) FROM seats WHERE retired_at IS NULL GROUP BY seat_id HAVING COUNT(*) > 1;
```

A non-empty result **refuses the migration** by name, listing each id with its installs: the operator retires all
but one of each pair first (retirement being the operator act the design makes irreversible, the migration does not
choose for them). On the sandbox the result is empty (§ 0's reading); on any other deployment it is the deploy's
precondition, printed in the release note.

### 6.2 The store migration, in order (one file; every refusable step before any DDL)

1. **Preconditions, each refusing by name with nothing written:** the live-duplicate check above is empty; no
   `installs` row is keyed `unassigned` (§ 4.4's reserved key; `SELECT 1 FROM installs WHERE install_id =
   'unassigned'`); and the migrated layout of § 6.3 is **built and validated in memory** by the new reader — the
   precedent is `2026_09_12_000100_create_the_authored_building_store.php`, which validates the configured layout
   before any DDL so a refusal leaves the schema as it was and the fix is *edit, migrate again*.
2. `ALTER TABLE seats MODIFY install_ref SMALLINT UNSIGNED NULL`; drop `uq_seat`; add `live_seat_id` and
   `uq_live_seat`. Existing rows are untouched: every seat keeps its room.
3. `UPDATE coord_hooks SET scope = 'room' WHERE scope = 'install'`, then `ALTER TABLE coord_hooks MODIFY scope
   ENUM('room','fleet')` — the update first, since MariaDB refuses a value the new enum lacks.
4. Mint the rooms the layout places and the store lacks: every room key in the current layout's `rooms` mappings
   `MINUS installs.install_id` → `INSERT installs (install_id, display_name = NULL)`. On the sandbox: `treehouse`,
   `nook` (§ 0).
5. Write the document validated at step 1 as a new `building_layout` revision, `authored_by = 'migration
   card#13117'`, through `Layouts::save()` so it publishes `building.layout` as any save does. ⚠ Steps 2–5 run in
   S1 except this one, which S4 lands with the reader that accepts `name` (§ 10): S1's migration file carries
   steps 1 (less the layout validation), 2, 3 and 4, S4's carries step 1's layout validation and step 5.

### 6.3 The layout migration, worked on the sandbox's document

For each entry in the current document: `name` = `label` where present, else the floor's derived key;
`aliases` = `[derived key]` when `slug(name) ≠ derived key`, else `[]`. Then, for every install the layout does not
place — `Building::compose()`'s implicit floors — a new entry `{ name: <install_id>, rooms: { <install_id>: { form:
"open" } } }` is **materialised**, so the migrated building draws exactly what the composed building drew the day
before (§ 9's observable: `GET /api/building` before and after differ only in `floor`/`name`/`aliases` and in
`label`'s absence). The sandbox's document becomes:

| Entry | `name` | `floor` (slug) | `aliases` | rooms |
|---|---|---|---|---|
| aimla | `aimla` | `aimla` | — | `aimla` (theme `cottage`, map rev 1) |
| The Burrow | `The Burrow` | `the-burrow` | `solo-agents` | `solo-agents` (map rev 3) |
| The Treehouse | `The Treehouse` | `the-treehouse` | `treehouse` | `treehouse` (room minted at step 4) |
| The Nook | `The Nook` | `the-nook` | `nook` | `nook` (minted) |
| sola (was implicit) | `sola` | `sola` | — | `sola` |
| kanban (was implicit) | `kanban` | `kanban` | — | `kanban` — every seat retired; renders as an empty room, as today |
| mezzanine (was implicit) | `mezzanine` | `mezzanine` | — | `mezzanine` — as above |

`/floor/solo-agents` reaches The Burrow by two routes (alias, and the room redirect); `/floor/aimla/aimla-pm` is
unchanged. The `kanban` and `mezzanine` floors are the operator's to retire (room retire → remove floor), with a
revision behind each — the migration changes no pixel and decides nothing it was not told. **Unassigned is empty
on migration day**: every live seat has an `install_ref`, and every room the layout names is placed or materialised.

### 6.4 What existing reporters send, and what the server does

Every reporter in the field keeps sending `install_id` on the envelope and on each event, and keeps its config.
Under § 3.2 the field is shape-checked and not read; `seat_id` is matched to the token; the batch is accepted
exactly as before. **No reporter is touched; no token is reissued.** A reporter's `selftest` still prints
`install_id · seat_id · url` (`install.js:1890`) until § 8.1's installer lands — a stale label on the seat's own
screen, named here, fixed there.

### 6.5 Rollback

`down()` restores `uq_seat` only if no two live seats share a `seat_id` under one `install_ref` — true by
construction if nothing was moved — and cannot restore `install_ref` to `NOT NULL` while any seat is unassigned:
it refuses by name, listing them, and the operator places them first (the console) or retires them. The layout
revision is restorable from the console as any revision is (D2 § 6.11), but a **pre-13117 revision is refused by
the new reader** (`label`, no `name`) — stated as the cost of one shape: an operator restoring one exports it,
edits `label` → `name`, and saves it as a new revision.

## 7. Across the boundary (canon #7)

| Hop | Declares | Checks | Neither end can verify |
|---|---|---|---|
| **reporter → ingest, `install_id`** | D1 § 4.2's amended row (this repo publishes both ends) | AT-23-style test: a batch with `install_id` absent, present-and-valid, present-and-foreign is accepted; a batch with a malformed one is refused at step 6 | that every reporter in the field is the version this repo last shipped — the heartbeat's `reporter_version` is the only reading, and D2 § 8.2.1's `reporter_outdated` badge is where it shows |
| **Seat-tokens page → installer, token file v3** (§ 8.1) | `fleet-reporter/INSTALL.md § The token file` and D1 § 3.1 state v1/v2/v3; the page says *needs installer ≥ <version>* beside the button | `install.selftest.py` drives the page's blob through the parser (D6's R-srv-1) | the installer version on the agent's machine; an old installer refuses a v3 file **by name** (*missing install_id*), which is loud, and the remedy is the page's own text |
| **REST `api_version` 2 → the bridge's watchdog** | D2 § 8.2 and the changelog note name the window | the two-segment routes answer through the window; a test drives both | whether the watchdog reads `installs[].install_id` as a key; the window is the whole defence, and `docs/VERSIONING.md § Wire compatibility` owns the rule |
| **coordination hook → room** (§ 4.3) | D2 § 8.3.3 rule 1 names the room arm | AT-D2-24's REDs re-planted on a room | that the team repository's roster is the room's seats — never verifiable from here (D1 § 18.3.1), and now also moved by a console edit; § 4.2's warning is what this end can do |
| **agent launch → seat** (§ 8.3) | `fleet-reporter/INSTALL.md § Several seats on one account` | the installer prints the config dir it wired, and `selftest --config` prints the seat it will report as | **how the agent was launched** — the server sees the seat whose hooks ran and nothing about the process that ran them |

## 8. The agent side — the token file, the config, and several seats on one account (card#13118)

### 8.1 Token file v3 and the config

**v3** is v2 minus the `install_id` line: exactly three `<key> <value>` lines — `token`, `ingest_url`, `seat_id` —
once each, any order, LF only, the same bounds (D1 § 3.1, `INSTALL.md § The two forms`). The *Seat tokens* page
writes **v3 only** from this card on (its `data-install-id` attribute goes; `seat-token.js` builds three lines).
`parseTokenFile` (`install.js:229-254`) reads v1, v2 and v3 and refuses anything else by name as today; a v2 file's
`install_id` is **read and discarded** (a 13117-era server never reads it), so a file saved before the deploy still
installs. The config's `install_id` row (D1 § 3.1) becomes **optional**; `loadConfig` (`fleet-reporter.js:399`)
checks it only when present; the writer (`install.js:303` `writeConfig`) omits it; the envelope and per-event
`install_id` are **sent when the config has one and omitted when it has not** (`fleet-reporter.js:2094, 4051, 4064,
4075, 4476`); `identityBinding` (`:332`) hashes `${cfg.install_id ?? ''}|${cfg.seat_id}` so an upgraded seat's
`state.json` binding does not move; the hook gate `config.install_id && config.seat_id` (`:3252, :3302`) reads
`seat_id` alone. `--install-id` leaves the installer's flags (`VALUE_FLAGS`, `:1933`); the account-slug default
(`:511, :1714`) goes with it. `selftest`'s identity line prints `seat_id · url`.

**Existing configs are never rewritten**: a seat upgraded in place keeps `install_id` in its file and keeps sending
it, which § 3.2 accepts. One written shape, two read shapes — the posture card#12180 set for `sites`.

### 8.2 Several seats on one account — the model

Today one account is one seat: hooks in `~/.claude/settings.json` (`install.js:127`), one config
(`fleet-reporter.js:299`), one spool, one flusher under one supervisor label (`install.js:55, 117-119`), so every
Claude Code session on the account reports as that seat and a second install rotates its token (`install.js:1756`).

**After:** an account holds **N seats**; each seat is one Claude Code *identity* — a `CLAUDE_CONFIG_DIR` — with its
own `settings.json` carrying hooks that name that seat's config; each seat has its own config, token, spool and
supervised flusher; the artifact (`~/.local/share/fleet-reporter/fleet-reporter.js`) is shared. The account's
**first** seat is the one installed today under `~/.claude` and the default paths, and it is not moved.

### 8.3 How a session learns its seat — the hook command names the seat's config

The hook command the installer writes for a seat beyond the first is `node <artifact> hook <Hook> --config <abs path
of that seat's config.json>` (and `statusline --config …`). D1 § 2.1's contract — `argv[2] == <HookName>`, the
payload on stdin — is unchanged; argv[3–4] are new and the reporter reads them exactly as it reads
`FLEET_REPORTER_CONFIG` today (`configPath()`, `:293`): they select **which file**, and no identity value comes from
the environment or the command line (D1 § 3.4 rule 1 holds: the identity is still file-resident). Precedence:
`--config` over `FLEET_REPORTER_CONFIG` over the default path, and a `--config` naming a file that cannot be read
is a loud `config_readable` failure for that hook, never a fall-back to the default seat. `parseReporterCommand`
(`install.js:148`) admits the suffix, so the installer still recognises and replaces its own entries.

**Why argv and not an env prefix in the command:** `VAR=x node …` is a POSIX shell form; the Windows hook command
is `node "<path>" hook <Hook>` under `cmd` (`hookCommand()`, `:143`), where it is not. One spelling on three
platforms.

**Why the identity is a `CLAUDE_CONFIG_DIR` and not a directory on disk** — the two candidates the card names:

| Candidate | Mechanism | Verdict |
|---|---|---|
| **per-agent `CLAUDE_CONFIG_DIR`** (chosen) | the agent is launched with `CLAUDE_CONFIG_DIR=<dir>`; Claude Code reads **its** `settings.json` from `<dir>` — the documented behaviour: *"All settings, session history, and plugins are stored under this path … Useful for running multiple accounts side by side"* (`code.claude.com/docs/en/env-vars`, read 2026-11-10); the installer, run inside that agent's session (where it reads `process.env.CLAUDE_CONFIG_DIR`) or with `--claude-config-dir <dir>`, wires the seat's hooks into `<dir>/settings.json`. A session under `<dir>` has that file's hooks and not `~/.claude`'s, so **one session reports once, as one seat** | the agent *is* the identity, which is what the operator is naming; nothing in a project directory decides who is reporting; two agents may work the same repository |
| **project `.claude/settings.local.json` carrying `env.FLEET_REPORTER_CONFIG`** | the account-level hooks fire once and read the env — documented as *"Set environment variables for every session and its subprocesses"* (`settings-reference`), and a hook *"inherits the parent environment"* (`hooks`) | **rejected**: (1) the seat is a property of a **directory**, so two agents on one checkout are one desk and one agent across two checkouts is two; (2) *most `env` values apply only after each teammate trusts the folder* (`settings`) — an untrusted or freshly cloned folder would **silently** report as the account's default seat, the misattribution D1 § 3.4 was written against; (3) the file is also where Claude Code writes permission approvals, so it is not the installer's to own |

**The hazard both candidates share, named rather than defended:** a session is attributed to the seat whose hooks
ran, and which hooks ran is decided by how the agent was launched. An agent launched without its `CLAUDE_CONFIG_DIR`
runs under `~/.claude` and reports as the account's first seat (or, where the account has no first seat, not at
all). The server cannot see the launch (§ 7's last row). The installer's `selftest --config <path>` prints the seat
and the config dir it is wired into, which is the operator's check.

### 8.4 Per-seat config, token, spool and supervisor

| Thing | First seat (today's, unchanged) | Each further seat |
|---|---|---|
| config | `~/.config/fleet-reporter/config.json` | `~/.config/fleet-reporter/seats/<seat_id>/config.json`, `0600`, dir `0700` |
| token file at install | `~/.config/fleet-reporter/mezzanine-reporter-token` | `~/.config/fleet-reporter/seats/<seat_id>/mezzanine-reporter-token` |
| spool (`spool_dir`) | `~/.local/state/fleet-reporter` | `~/.local/state/fleet-reporter/seats/<seat_id>` — D1 § 11.1's layout inside it, its own `flusher.lock`, `state.json`, log and quarantine |
| hooks | `~/.claude/settings.json`, command without `--config` | `<CLAUDE_CONFIG_DIR>/settings.json`, command with `--config` |
| supervisor | launchd `com.pupfuzz.fleet-reporter` / the one crontab block / none on Windows | launchd `com.pupfuzz.fleet-reporter.<seat_id>` with `FLEET_REPORTER_CONFIG` in `EnvironmentVariables` (the plist already carries `COORD_CONFIG` that way, `install.js:182`); one crontab block per seat with its own flock file `fleet-reporter-flusher.<seat_id>.flock`; Windows as today per seat |
| flusher | one process per seat, each owning one spool | the same |

Windows paths follow the platform table's `%APPDATA%`/`%LOCALAPPDATA%` roots with the same `seats/<seat_id>`
suffix. The seat id is a slug (`[a-z0-9-]`), so it is a safe path segment on every platform.

**Why one flusher per seat and not one flusher with a lane per seat** (the card's candidate): the flusher is one
closure over `(config, spool, state)` (`fleet-reporter.js:4640-4840`) with ownership, the seat write lock, the
index fold and the site lanes all keyed on that one spool; a per-seat lane is that closure parameterised and
re-entered N times in one process, with lost-ownership in one lane having to stop one lane rather than the process
(`lost()`, `:4690`). One process per seat gets every one of those properties from the OS for the cost of a label
suffix and a flock name, and a seat's flusher dying takes one seat dark, not N. **Cost:** N processes napping on
`K.FLUSH_MS`; at the two-to-four seats the card describes that is not a figure worth a design.

### 8.5 The installer

- **Add a seat**: `node install.js --token-file <path> [--claude-config-dir <dir>]`. The file's `seat_id` (v3; a
  v2 file works the same) is compared with every config on the account (`config.json` and `seats/*/config.json`):
  **equal to one** → that seat's rotation, today's path (`:1756`), the target config dir must be the one its hooks
  are in; **equal to none** → a **new seat**: the config dir is `--claude-config-dir`, else `CLAUDE_CONFIG_DIR` from
  the environment, else refused by name when the account already has a first seat (*this account already reports as
  `<seat>` from `~/.claude`; a second seat needs its own `CLAUDE_CONFIG_DIR` — launch the agent with it and run the
  installer there, or pass --claude-config-dir*); when the account has no seat yet, the default paths and
  `~/.claude` are used as today. A target `settings.json` already carrying this reporter's hooks for **another**
  seat is refused by name (one dir, one seat).
- **Rotate**: unchanged per seat; `--site-name` and `add-site` scope to the seat the file names.
- **Upgrade the artifact**: one artifact; `restart` kickstarts every seat's supervisor entry (`holder` becomes per
  seat: it reads every lock file).
- **Uninstall**: `uninstall --seat <seat_id>` removes one seat's config, spool, supervisor entry and its hooks from
  its config dir; bare `uninstall` on an account with several seats refuses and lists them.
- **`selftest`**: `--config <path>` or `--seat <seat_id>`; without either on a multi-seat account, every seat in
  turn, each block headed by its seat id.

### 8.6 Existing single-seat installs keep working with no reinstall

Their config, spool, hooks (no `--config`), supervisor label and flock are the default-path case of every rule
above; `configPath()`'s default branch is unchanged; the reporter sends their `install_id` and the server accepts it
(§ 3.2). Adding a second seat to such an account changes nothing in the first seat's files — the installer writes
only under `seats/<seat_id>/`, a new supervisor entry and the new config dir's `settings.json`.

### 8.7 Not verified here, by name

- That hooks under a `CLAUDE_CONFIG_DIR` fire with the same payload and environment as under `~/.claude`. The docs
  say settings live there; the hooks page (read 2026-11-10) never mentions the variable. Measured on the first macOS
  seat, which is § 10 S7's install step; this host is Linux.
- That `CLAUDE_CONFIG_DIR` reaches the hook's environment. **Irrelevant to the chosen route** — the command carries
  the path — and named because the rejected route depended on it.
- launchd's behaviour with N labels under one `gui/<uid>` domain: recall says independent; the first macOS install
  measures it.

## 9. How the tests pin it — property, plant, observable

Each row: the **property**, the **plant** that must red (seen to fail before the fix, canon #9), and the
**observable** asserted. Where a ratified AT is amended, its id is named; new ones take the next free number in the
owning document at the slice.

| # | Property | Plant | Observable |
|---|---|---|---|
| T1 | a `seat_id` any row holds is never issued again (§ 3.1) | retire seat `x`; call `issue('x')`; and separately, on a store seeded with § 0's two retired pairs, call `issue('mezzanine-solo')` | `SeatTokenRefusal::seatRetired` both times; `seats` row count unchanged (`SELECT COUNT(*)` before = after, asserted as equality, not a figure) |
| T2 | a live id re-issues, never duplicates | `issue('y')` twice | one `seats` row for `y`, two `ingest_tokens` rows, the second response lists the first's prefix in `priorActive` |
| T3 | the store refuses a second live `y` from any writer | raw `INSERT seats (seat_id='y', retired_at NULL)` | `1062` on `uq_live_seat`; the same insert with `retired_at` set succeeds (the live-only key) |
| T4 | the ingest reads no `install_id` (§ 3.2) | three batches for token-bound seat `y`: no `install_id`; `install_id: "aimla"`; `install_id: "zzz-not-a-room"` — and a fourth with `install_id: "Not A Slug"` | first three `200` with `accepted` = the events sent; fourth `400` at step 6 (shape), never `403` |
| T5 | `seat_id` still binds | batch for `y` claiming `seat_id: "z"` | `403 identity_mismatch`, body carries `expected_seat_id: "y"` and no `expected_install_id`; refusal counted on `y` |
| T6 | REST by seat alone, window honoured (§ 3.3) | `GET /api/fleet/seats/y`, `/api/fleet/seats/anything/y`, `/api/fleet/seats/nope` | first two `200` with identical bodies and `api_version: 2`; third `404` |
| T7 | an unassigned seat is served in the virtual room (§ 4.4) | issue `w` (no room) | snapshot has an `installs[]` group `unassigned` holding `w`; `GET /api/building` lists floor `unassigned` last, with room `unassigned`; `GET /api/building/rooms/unassigned/map` is the shipped default; after placing `w` the floor is absent from `/api/building` |
| T8 | a room move is one delta and the client re-slots (§ 4.2) | place `w` in `aimla` | one `seat.delta` with `changed` ⊇ `['install_id']`, `patch.install_id = 'aimla'`, `state_version` +1; the headless desk model (`desk-floor.js`) holds one desk for `w`, in room `aimla`, and none in `unassigned`; mutate the client to key by the pair → it holds two desks (the RED) |
| T9 | the slot and the seed read `seat_id` alone (§ 3.4) | D3 § 3.2's worked `h` table re-published for `seat_id`; `verify-floor.py` G8 and `TheSpeciesListHasOneSourceTest` read it | both green on the new table; the Python copy mutated to hash `install/seat` → red |
| T10 | the coordination arm is the room (§ 4.3) | AT-D2-24's install-arm REDs with a seat moved out of the room between declaration and post | the post resolves to no desk after the move, to the desk before |
| T11 | the slug function (§ 5.2) | the fixture table: `The Nook`/`the nook`/`Thé  Nook` → `the-nook`; `三階` → `''`; `--a--` → `a`; `Unassigned` → reserved | PHP and JS copies agree on every row; a row changed in one copy reds the lockstep test |
| T12 | the reader's and the write's refusals (§ 5.1) | at load: documents with `label`; no `name`; two floors slugging alike; alias = another floor's slug; alias in two floors; name `unassigned`; room `unassigned`. At the write: a save and a restore placing a room retired since | each refused with a message naming the member and the floors; `{"floors": []}` and a document with `name`+`aliases` accepted; the retired-room document is refused by `Layouts::save()` and, planted into the store directly, is **loaded** (no load refusal exists for it — the plant that reds a reader guard with no route) |
| T13 | rename redirects in one hop, reuse refused (§ 5.3) | rename `The Nook`→`The Den`→`The Burrow` via the console; then try to name another floor `The Nook` | `aliases = ['the-nook','the-den']` on one entry; `/floor/the-nook` → `location.replace('/floor/the-burrow')` (the headless frame's `redirect`), `/floor/the-nook/aimla-pm` keeps the seat; the second floor's save refused naming The Burrow; after *forget* `the-nook`, it saves |
| T14 | the migration changes no pixel (§ 6.3) | a store seeded from § 0's readings; run `migrate` | `GET /api/building` before vs after: same floor set in the same order under `label`→`name`, same rooms, same themes, `aliases` exactly `{the-burrow: [solo-agents], the-treehouse: [treehouse], the-nook: [nook]}`; `installs` gained exactly `treehouse`, `nook`; every seat's `install_ref` unchanged; the snapshot's seat set and `install_id`s unchanged |
| T15 | the migration refuses a live duplicate (§ 6.1) | seed two live seats `q` under two installs | `migrate` exits non-zero naming `q` and both installs; schema unchanged (`SHOW CREATE TABLE seats` equal) |
| T16 | the token file v3, and v2 still installs (§ 8.1) | the page's blob through `parseTokenFile`; a v2 fixture; a v3 with a fourth key | v3 parsed `{version: 3, seat_id}`; v2 parsed with `install_id` discarded by the writer (the written config has no `install_id`); the fourth key refused by name |
| T17 | the envelope omits `install_id` when the config has none, sends it when it has (§ 8.1) | two configs | `REQUEST_KEYS` presence per batch; a server fixture of the pre-13117 validator refuses the omitting batch — the record that the window exists |
| T18 | `--config` selects the file and fails loud (§ 8.3) | hook with `--config /nope`; hook with `--config <seat b>`; hook with no flag | first: `config_readable` fail, nothing spooled anywhere, no fall-back to the default spool (the RED: a build that falls back spools into the default); second spools into b's spool; third into the default |
| T19 | add a seat touches nothing of the first (§ 8.5–8.6) | an account with the default seat; add seat `b` with `--claude-config-dir` | byte-identical `config.json`, `~/.claude/settings.json`, default spool and supervisor entry before and after; `<dir>/settings.json` holds hooks with `--config …/seats/b/config.json`; a second add into the same dir for seat `c` refused |
| T20 | the two-rooms notice is gone (§ 3.3) | the test that planted a seat in two rooms | deleted with its fixture, and the drill-down resolves a seat over the floor's rooms to one desk |

## 10. The slice plan, for the coder

Each slice is one PR to `dev`, `[no-close]` until the last of each card, each with its docs in the same round
(§ 14's rows for that slice), a `docs/changelog.d/<card>[-<slug>].md` note, and shippable alone.

| Slice | Content | Closes |
|---|---|---|
| **S1 — the seat alone, on the server** | § 6.2's migration, steps 1–4 (step 1 less the layout validation; the layout keeps `label` until S4 and the reader accepts the current shape until then); `SeatTokens::issue(seatId, by)` with § 3.1; `TokenResolver`/`TokenBinding`/`BatchValidator` per § 3.2; REST routes per § 3.3 with the window and `api_version` 2; `Snapshot`/`SeatRow` by seat with the `unassigned` group; `Building::compose()`'s one implicit floor (§ 4.4) and the reserved key; `FloorInventory`; console token form (seat id only, the `datalist` of seats), retire route, compose route, CLI signatures (`ingest-token:issue <seat_id>`, `retire --seat=`, `seat-board-user --seat=`, `coord-hook:issue --scope=room --room=`); client fetch paths to the new routes (key still the pair — stable until S3, since no move exists). ⚠ **Between S1 and S3 no room can be created**: token issue no longer mints one and the Rooms module is S3's; a seat issued meanwhile lands in Unassigned and is placed once S3 lands, and nothing existing regresses — stated so the slice is read as shippable rather than complete. Tests T1–T7, T15, T20. Docs: D1 § 3.3, § 4.2, § 4.3, § 12.1, § 12.2; D2 § 6.4, § 8.2, § 8.2.1's binding paragraph, § 8.3.3 rule 1; D3 § 4.4 (the notice), § 4.6's implicit-floor paragraph; D4 § 4.1; D5 § 3.2; `README.md § The admin console` | — |
| **S2 — the client keys by seat** | § 3.4: `key()`, every inline pair key, `seatKey()`, D3 § 3.2's hash and its published table, `Draw`, `CharacterAllocation`; `feed_version` bump. T8's client half, T9. Docs: D3 § 3.1 (desk row), § 3.2, § 10.2, § 13 row 6 | — |
| **S3 — rooms in the console** | § 4.1's `display_name`/`retired_at` writers; the Rooms module; the Agents index's *Room* control and the move transaction (§ 4.2) with its warnings; T8's server half, T10. Docs: D2 § 4.10 (the move beside retirement), § 6.11 (*an install* → *a room*); `README.md` | — |
| **S4 — floors by name** | § 5.1–5.4: `FloorSlug` (PHP+JS, lockstep test), the reader's new members and refusals, `Building::compose()` by slug, `GET /api/building`'s `name`/`aliases`, the client's alias redirect, directory/cab/building view reading `name`; § 6.3's layout migration (step 5, now writing). T11–T14. Docs: D3 § 3.1 (floor row), § 4.1, § 4.4, § 4.6, § 13 rows 24/25/27, the card#9273/card#9267 sweep (§ 5.6); D2 § 6.11, § 8.7's worked JSON | — |
| **S5 — the console's floors module** | § 5.5. T13's console half. Docs: `README.md`, D3 Appendix B row | **card#13117** |
| **S6 — token file v3 and the optional `install_id`** | § 8.1 on the page, the installer and the reporter. T16, T17. Docs: D1 § 3.1 (both rows), § 4.2; D6 § 4.2; `INSTALL.md § The token file`; `UPDATES.md` | — |
| **S7 — several seats on one account** | § 8.2–8.6: `--config` on `hook`/`statusline`, `parseReporterCommand`, per-seat paths, supervisor entries, `add`/`rotate`/`uninstall`/`selftest`/`holder` per seat; the first macOS install as the measurement of § 8.7. T18, T19. Docs: D1 § 2.1, § 3.1, § 11.1; D6 § 3.1, § 3.4, § 3.6; `INSTALL.md` (a new section *Several seats on one account*); `AGENT-INSTALL.md` | **card#13118** |

Order: S1 → S2 → S4 → S3 → S5; S6 after S1; S7 after S6. S3 before S4 is also sound (they touch different
documents); S2 must precede S3, because a move re-keys.

## 11. What could falsify it, and what is not defended

- **A live duplicate `seat_id` on a deployment other than the sandbox.** The migration refuses and names it (§ 6.1).
  Not defended: choosing which to retire.
- **A reporter whose version predates `seat_id` on the envelope.** D1 § 4.2 has carried it since v1; no such
  reporter exists (`fleet-reporter/UPDATES.md`'s first row). Not defended.
- **An operator who moves a PM between rooms and breaks a team repository's `pm` resolution** (§ 4.3). Defended
  only by the console's warning; the posts render as *unresolved*, which is the honest render the join already has.
- **Two agents launched under one `CLAUDE_CONFIG_DIR`** report as one seat (§ 8.3's named hazard). Not defended:
  the server cannot see the launch.
- **An agent launched without its `CLAUDE_CONFIG_DIR`** reports as the first seat. Same.
- **`CLAUDE_CONFIG_DIR` hooks behaving differently from `~/.claude` hooks** (§ 8.7). If the first macOS install
  finds a difference, § 8.3's route is wrong and the rejected env route is re-weighed with the trust hazard priced.
- **A floor name in a non-Latin script** is refused (§ 5.2). Falsifier: the operator wants one; the remedy is a
  transliteration policy this design declines to invent.
- **The one-time re-roll and re-slot** (§ 3.4) displeases the operator — § 13 Q1 exists so it is decided, not
  discovered.
- **A raw-JSON layout edit** that renames without an alias, deletes a floor holding rooms, or places a room on no
  floor: each has a stated render (no redirect; *no such floor*; Unassigned) and none is refused — the textarea is
  the operator's own hand.
- **The REST window**: a watchdog that reads `installs[].install_id` as *the install* after the window closes is
  wrong by its own contract; `api_version` 2 is the signal.
- **Unassigned seats on a floor of the shipped default** with more seats than its `S`: D3 § 3.2's overflow row,
  as any room.
- **The `kanban`/`mezzanine` empty floors persist after migration** until the operator retires them (§ 6.3): a
  no-change migration, by design.

## 12. Seat rulings — reversible by the operator, each one rule

| # | Ruling | Alternative | Cost of the ruling |
|---|---|---|---|
| R1 | the store column `install_id`, the table `installs`, the wire member `install_id` and the route segment `{install_id}` on the building and room-map surfaces **keep their names** and mean *the room key*; D2 § 6.4 and D3 § 3.1 say so once | rename to `room_id`/`rooms` everywhere | a name that no longer says what it is. Priced: `grep -rn 'install_id\|installId\|install_ref' server/app \| wc -l` = 332 lines, `server/public/js` = 331, `server/tests` = 161 files, D1/D2/D3 = 80/71/121 lines (2026-11-10), plus a REST member rename inside the `api_version` 2 window. The precedent is the `floors` table, which has held room maps since card#9267 under its old name with a comment |
| R2 | a new seat is created in **no room**; the token form offers no room | a room select at issue | ruling 3 says Unassigned; one fewer path to the seat's room |
| R3 | a room may exist on no floor and is drawn in Unassigned | a room must name a floor at creation | a floor removed under a room by the raw editor would otherwise be a refusal in the reader, which D3 § 9 F17 shows takes the building down |
| R4 | the migration materialises every implicit floor, empty ones included | drop installs with no live seat | the migration changes no pixel; the cleanup is the operator's act with a revision |
| R5 | `aliases` is a member of the floor's entry, console-written, raw-editable | a hidden floor id and a slug table | a raw rename without an alias has no redirect |
| R6 | a room move renders as a removal from the old room and an insert in the new, no walk | a walk to the elevator and out of the other room's doors | the honesty rule: the seat did not go anywhere; the operator moved it |
| R7 | one flusher process per seat, one supervisor entry per seat | one flusher with a lane per seat | N nappers instead of one |
| R8 | the hook command carries `--config`; `CLAUDE_CONFIG_DIR` is the agent's identity | project-scoped `env` | an agent launched without its dir reports as the first seat |

## 13. Open questions for the operator — each with options and a recommendation

**Q1 — the one-time look change (§ 3.4).** When S2 lands, every existing character re-rolls its colourway, face
and dress (species stay), and every desk re-slots once within its room. *Example:* `aimla-pm` stays a snail, but a
different-coloured one at a different desk in the aimla room.
(a) **Accept the one-time change** — the simplest; nothing stored, nothing to migrate. (b) Pin every existing
seat's look and slot by storing its old key once (`seats.look_seed = 'install/seat'` at migration, `seat_id` for new
seats), served as `character.seed`, hashed by the client in place of the key — one column, one wire member, one
client read, no visible change on deploy. **Recommendation: (a)**; the fleet has not yet been seen by anyone but
the operator, and (b) is a second identity field to carry for life.

**Q2 — reusing a floor's old address for a new floor (§ 5.3).** The Nook was renamed The Den; the operator now
names a new floor The Nook. (a) **Refuse**, naming The Den; the operator forgets the old address on The Den's form
first. (b) Take over: the new floor gets `the-nook` and The Den's alias is dropped silently. **Recommendation:
(a)** — a published link to `/floor/the-nook` changes meaning under (b) with no act that says so.

**Q3 — the internal names (R1).** Keep `install_id`/`installs` meaning *room* (recommended, priced in R1), or fund
the rename as its own slice after S5.

**Q4 — the agent's identity on a shared account (R8).** (a) **`CLAUDE_CONFIG_DIR` per agent**: launch each agent
as `CLAUDE_CONFIG_DIR=~/.claude-<name> claude`; install its seat from inside that session. (b) Project-scoped
`.claude/settings.local.json` with the seat in `env`: one install per checkout. **Recommendation: (a)** for § 8.3's
three reasons; (b)'s trust gating is a silent misattribution.

**Q5 — is the pre-13117 revision restore cost acceptable (§ 6.5)?** A layout revision saved before this deploy
cannot be restored without an export, a `label`→`name` edit and a save. (a) **Accept** (recommended: one shape,
and the sandbox has four such revisions). (b) The reader accepts `label` as a read-only synonym forever.

## 14. Amendments owed to the ratified documents — the diff list

Each row is a diff to the owning document, landed in the slice named, with the reason attached in the amendment's
own ⭐/⚠ note (card#13117, or card#13118 for § 8's rows). Nothing here is a new document.

| Document § | Change | Slice |
|---|---|---|
| D1 § 3.1 | `install_id` row: optional since card#13117, not read by a 13117-era server; the token-file paragraph gains v3; `Sites` subsection: the identity is `seat_id` | S6 |
| D1 § 2.1 | the hook contract: argv[2] unchanged, `--config <path>` as argv[3–4]; the platform table's per-seat rows | S7 |
| D1 § 3.3 | *the token row binds exactly one seat*; the binding rule over `seat_id`; `identity_mismatch` carries `expected_seat_id` | S1 |
| D1 § 4.2, § 4.3, § 12.1 step 7 and 9, § 12.2 | `install_id` optional and not read; the error's fields | S1 |
| D1 § 11.1 | the per-seat spool root | S7 |
| D1 § 18.3.1, § 18.6/18.7 | `scope: "room"`; the binding names a room | S1 |
| D2 § 4.10 | the move as the second operator act beside retirement, and the retired-id rule site-wide | S3 |
| D2 § 6.4 | DDL: `installs` comment, `install_ref NULL`, `live_seat_id`/`uq_live_seat`, `uq_seat` gone, `coord_hooks.scope` enum | S1 |
| D2 § 6.11, § 8.7 | *an install* → *a room*; the worked JSON with `name`/`aliases` | S3, S4 |
| D2 § 8.1, § 8.2 | `api_version` 2 and the window; routes by seat; the `unassigned` group | S1 |
| D2 § 8.2.1 | the binding paragraph: `seat_id` alone is the identity; `install_id` is the room, a mutable member; *uniqueness is site-wide among live seats* | S1 |
| D2 § 8.3, § 8.3.3 rule 1 | `seat.delta` on a move; the room arm | S1, S3 |
| D2 § 6.13 | the allocation's preference key | S2 |
| D2 § 13 | a row for this card's reversal of row 38's *(an install)* wording; row 50's *per install* → *per room* | S3 |
| D3 § 3.1 | the keys table: desk = `seat_id`; room row; floor row = slug of the name; the ⭐ reversal note | S2, S4 |
| D3 § 3.2, § 10.2 | the hash over `seat_id`; the published `h` table | S2 |
| D3 § 3.4, § 3.5 | the move's render beside the first appearance and the removal | S3 |
| D3 § 4.1, § 4.2 | `name` where `label` was | S4 |
| D3 § 4.4 | routes: slug, alias redirect, room redirect kept, the two-rooms notice deleted | S1, S4 |
| D3 § 4.6 | `name`, `aliases`, the reader's refusals, the implicit floor → Unassigned, the *no authored id* paragraph's reversal | S1, S4 |
| D3 § 9 | F17's unclaimed rooms → Unassigned | S1 |
| D3 § 13 rows 6, 24, 25, 27 | reversed or narrowed, each in place | S2, S4 |
| D3 Appendix B | rows for the Rooms module, the Floors module | S3, S5 |
| D4 § 4.1 | `--seat=<seat_id>` | S1 |
| D5 § 3.1, § 3.2 | `--scope=room --room=`; the binding names a room | S1 |
| D6 § 3.1, § 3.4, § 3.6, § 4.2 | v3; `--claude-config-dir`; per-seat supervision; the add-seat flow | S6, S7 |
| `fleet-reporter/INSTALL.md`, `AGENT-INSTALL.md`, `UPDATES.md` | the token file's three forms; *Several seats on one account*; the agent's prompt for the config dir | S6, S7 |
| `README.md § The admin console` | rooms, floors by name, seat-only tokens | S1, S3, S5 |

The sweep of every `card#9273`/`card#9267` site (§ 5.6) is re-derived by grep at S4 and is not enumerated here.

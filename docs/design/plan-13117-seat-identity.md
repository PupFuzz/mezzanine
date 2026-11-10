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

**Revision 2 (2026-11-10)** folds design review round 1 (`~/.cache/coord/build/13117/design-review-r1.md`) and the
operator's answers to revision 1's § 13 (card comment 16717). The two blockers: the snapshot keeps `api_version` 1
and changes additively, because the bridge's reader refuses anything else, and the meaning drift it cannot see is
declared to its owner (§ 3.3, § 7); every `join('installs')` in `server/app` goes through one seat-identity read
primitive in S1, because a seat with no room fell out of every site § 3.3 did not name (§ 3.6). The majors: one
`ALTER` for the seat table (§ 6.2); the Unassigned area rides the snapshot as a virtual install in S1 so neither
composer changes and no unplaced install moves before S4 (§ 4.4); the composition is the browser's as much as the
server's, so T7/T14 assert on both composers; no shape check on the ignored `install_id`, because none exists today
(§ 3.2); the installer's v3 path (§ 8.1); a key primitive on the client (§ 3.4); `Layouts::amend()` and a
compare-and-swap on the textarea (§ 5.5). The operator's answers: Q1, Q3, Q4, Q5 as recommended; **Q2 reversed —
a new floor takes over a former address, delete-first** (§ 5.3). Two findings the round could not decide are new
operator questions (§ 13 Q6, Q7). The minors are folded where they stand.

**Design approval: not yet given.** The operator's direction, verbatim from the card (`kbcard show --task 13117`):

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
php artisan tinker --execute='echo DB::selectOne("SELECT VERSION() AS v")->v, "\n";'      # 11.8.6-MariaDB
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
claimed pair differs (`App\Ingest\TokenResolver::resolve()`, `BatchValidator.php:31`, `EventValidator.php:74`,
D1 § 3.3's binding rule); the store keys the seat under its install (`seats.install_ref`, `UNIQUE uq_seat
(install_ref, seat_id)`, `2026_08_25_100000_create_fleet_store_tables.php`); the building draws the install as a
room and composes rooms into floors keyed by the least install id — on the server (`BuildingLayout::parse()`,
`Building::compose()`) and again in the browser (`lobby-model.js:213-285` `floors()`), the two pinned to one
answer by `server/tests/fixtures/building/compose-cases.json`. So moving an agent to another floor is: retire the
seat, issue a token under another install id, reinstall the reporter — and the operator's own floor *The Burrow*
could only ever be a label over the key `solo-agents` (D3 § 4.6, card#9273).

The card's five jobs of `install_id`, read from the tree, and where each is in the code today:

| Job | Where it lives today (file:line or section) |
|---|---|
| (a) the ingest identity | the config's `install_id` (D1 § 3.1); the token file's `install_id` line (`install.js:229`); the envelope field (D1 § 4.2, `fleet-reporter.js:3549`); the equality checks (`TokenBinding::matches()` at D1 § 12.1 step 7; `EventValidator.php:74` at step 9) |
| (b) the store keys | `installs` (`uq_install`), `seats.install_ref` (`fk_seat_install`, `uq_seat`), `coord_hooks.install_ref`, `floors.install_id`, `authored_revisions.subject` (D2 § 6.4) |
| (c) the floor composition | `BuildingLayout::parse()`'s `$floorKey = array_key_first(rooms)`; `Building::compose()`'s and `lobby-model.js floors()`'s implicit floor per unplaced install; `/floor/{floor}` resolved and redirected by the client (`floor-screen.js:132-136`, `main.js:264-277`; D3 § 4.4 rows 3–4) |
| (d) the room maps | `floors.install_id` → one map per install (`App\Floor\Floors`, D2 § 6.11); the console's floors module routes `/admin/floors/{install_id}/…` |
| (e) the coordination scope | `coord_hooks.install_ref` (`HookBinding`), `coord_round.install_id`, D2 § 8.3.3 rule 1's install arm: `(install_id, protocol_agent_name)` → one seat |

And the surfaces that show or take an install id, so § 10's slices can be checked against the tree rather than this
list — re-derive before each slice:

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
issue), what joins a seat to it (a console edit, not the token), and that a seat may be in none. **Operator ruling
2026-11-10 (card comment 16717, Q3): the names `install_id` and `installs` are kept as the room's internal names;
no rename.** D2 § 6.4 and D3 § 3.1 say so once.

| Job | New home |
|---|---|
| (a) ingest identity | the token binds a **seat** (`ingest_tokens.seat_ref`, unchanged); the batch's `seat_id` must equal the binding; the batch's and each event's `install_id` are **accepted and not read** (§ 3.2). The config's `install_id` becomes optional; the token file gains **v3** without it (§ 8.1) |
| (b) store keys | `installs` is the **rooms** table and `install_id` the **room key**; `seats.install_ref` becomes nullable and console-writable (§ 4.1). `seats.seat_id` is unique site-wide among live seats (§ 3.1). One read primitive answers a seat's identity everywhere (§ 3.6) |
| (c) floor composition | a floor is still its layout entry; it gains a required `name` whose slug is its key, and console-written `aliases` for its former slugs (§ 5). The derived key and the implicit floor per install are **retired at S4** (§ 5.6 records both reversals); until S4 both composers run today's rule unchanged (§ 4.4) |
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
| **`install_id: null` on the wire for an unassigned seat** | every client site that reads `install_id` handles null; both composers special-case roomless seats | the population is every file `grep -rln install_id server/public/js` prints; a virtual install `unassigned` in the snapshot (§ 4.4) is a group both composers already know how to draw, for the cost of one reserved key |
| **A snapshot `api_version` bump to 2** (revision 1's § 3.3) | a meaning change is a bump, D2 § 8.1 row 1 | the one machine consumer refuses anything but `1` (`~/agent-webhook-bridge/app/Bridge/IdleNudge/FleetSnapshotReader.php:110-111`), so the bump turns every idle nudge *unmeasured* from the deploy on; the change is made additive instead and the drift declared to the owner (§ 3.3, § 7) |
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
| no row | create the seat with `install_ref NULL` (§ 4.4), allocate its species (§ 13 Q6 governs rule 2), insert the token |
| a live row | **re-issue** for that seat (rotation, D1 § 3.3's order) — today's behaviour, minus the install segment |
| a retired row | **refused**, `SeatTokenRefusal::seatRetired` — today's refusal, now site-wide: the console's *there is no un-retire — a replacement seat takes a new seat id* (`SeatTokenController.php`) |

**Backstop in the store.** `uq_seat (install_ref, seat_id)` goes — with `install_ref` nullable it would admit two
unassigned seats of one id, since MariaDB treats NULLs as distinct in a unique key — replaced by a generated column
`live_seat_id VARCHAR(48) AS (IF(retired_at IS NULL, seat_id, NULL)) VIRTUAL` with `UNIQUE KEY uq_live_seat
(live_seat_id)`. MariaDB indexes a `VIRTUAL` generated column and names a unique index over one its *"poor man's
partial index"* (`mariadb.com/kb/en/generated-columns/`, read 2026-11-10); `IF()` is deterministic, which an indexed
virtual column requires; and this is a generated **column**, not the expression index D2 § 6.1 says the engine
lacks. The reviewer measured the key on a scratch 11.8.6 (round 1, *verified sound*). Reachable route for the
backstop: there is no second writer today; the index is what makes the invariant a property the read side may rely
on (§ 3.3 resolves by `seat_id`; the client keys by it) rather than a property of one function, and it is what
refuses a hand `INSERT` or a fixture that bypasses `issue()`. ⚠ **It is deliberately a LIVE-only key.** A
`UNIQUE (seat_id)` over every row is false on the sandbox today — the two retired pairs § 0 names — and a migration
that renamed retired rows to satisfy it would back-edit a record (`seats` is where a retirement's `by`/`reason`
live, D2 § 4.10). The never-reused half of the invariant is therefore the application's, under the mutex, and § 9's
T1 plants a retired-id re-issue to see it refuse. § 6.2 states how the key is added in one statement.

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

The token resolves to a seat (`TokenResolver::resolve()` joins `ingest_tokens → seats` through § 3.6's primitive;
the `installId` member of `TokenBinding` goes). D1 § 12.1 step 7 becomes: **the batch's `seat_id` equals the
binding's, else `403 identity_mismatch` with `expected_seat_id`** (`expected_install_id` leaves the response; the
reporter classifies the answer by status alone — `INGEST_AUTH = { 401: …, 403: 'identity_mismatch' }`,
`fleet-reporter.js:4285` — and reads neither expectation, so the auth stop of D1 § 11.5 is untouched). Step 9's
per-event `seat_id` equals the batch's, as today.

**The envelope's `install_id` (D1 § 4.2) and each event's (D1 § 4.3) are accepted and not read.** Today neither is
shape-checked: the batch's is compared for equality at step 7 (`BatchValidator.php:31`) and the event's at step 9
(`EventValidator.php:74`), and both comparisons go; nothing takes their place — no bound, no pattern, no logging.
Present, absent, equal to a room, equal to nothing: the batch is accepted on `seat_id` alone. ⚠ **Why not compare
it to the seat's room key:** the room is now a console fact the operator changes at will; a reporter that sent the
room it was installed under would start failing `identity_mismatch` on the first move — the reinstall this card
exists to remove — so the field is retired from the server's reading rather than re-pointed. ⚠ **Why no shape
check either:** a bound the server never had, added now, is a refusal a reporter in the field could newly take
(D1 § 12.1's ordering note: anything tighter costs the batch's valid neighbours), for a field whose value nothing
reads. D1 § 4.2's and § 4.3's rows are amended to *optional; not read since card#13117; a reporter writing to a
pre-13117 server must still send it*, and § 8.1 is where the reporter stops sending it.

**Attribution of refusals** (D1 § 12.1's table) is unchanged in shape: steps 5–11 attribute to *the token's bound
seat*.

### 3.3 The read plane — routes, the snapshot, and why `api_version` stays 1

The snapshot has one machine consumer this repository can read: the bridge's idle nudge.
`~/agent-webhook-bridge/app/Bridge/IdleNudge/FleetSnapshotReader.php` (kanban-solo's repository, read 2026-11-10,
not edited) refuses any body whose `api_version` is not `1` (`:110-111`, *this build reads only version 1*),
requires its configured `BRIDGE_IDLE_NUDGE_INSTALL` to be present as an `installs[].install_id` group (`:136`,
`:147-148`), and collects every seat of every group (`:139-143`). So:

- **`api_version` stays `1`, and every change to the snapshot is additive**: a new group `unassigned` may appear
  (§ 4.4) — a group like any other to that reader; no member is removed or renamed; the seat object is unchanged.
- **The meaning of `installs[].install_id` drifts** — from *the install the token was issued under* to *the room
  the console placed the seat in, or `unassigned`* — and that drift is **declared to the bridge's owner**, in § 7's
  words, because its reader keys on it: a rename or retirement of the room named in `BRIDGE_IDLE_NUDGE_INSTALL`
  makes every nudge *unmeasured* (its own fail-closed answer, loud), and a move of the bridge's seat into another
  room, or a new seat of that team left in `unassigned`, leaves that seat out of the group the bridge checks for
  **without any refusal** — the silent case, which only the owner can close, by matching on `seat_id` (unique
  site-wide from S1) rather than on the group.
- **The bump to 2 and the removal of the two-segment routes are gated, not clocked**: they land in a slice of their
  own once the bridge's owner declares its reader moved — D1 § 3.3's *the overlap ends on a gate rather than on a
  clock* — and until then this document plans neither.

| Today | After S1 | Posture |
|---|---|---|
| `GET /api/fleet/seats/{install_id}/{seat_id}` and `…/timeline` | **kept**, resolving by `{seat_id}` alone with `{install_id}` ignored (any slug); **added**: `GET /api/fleet/seats/{seat_id}` and `GET /api/fleet/seats/{seat_id}/timeline`, `where('seat_id', Slug::SEAT_ID)`. ⚠ **Registration order:** the new timeline route is registered **before** the two-segment seat route, since `/api/fleet/seats/x/timeline` matches both shapes — Laravel takes the first registered — so a seat's timeline wins over a seat named `timeline` under an install named `x`, which is the reading the new contract intends; a seat literally named `timeline` reaches its own timeline at `/api/fleet/seats/timeline/timeline` by the same order | **REST, machine consumers** (D2 § 8.1 row 1): additive; `api_version` 1 |
| `GET /api/fleet/snapshot` `installs[].install_id` | the same member, now the **room key**, plus the group `unassigned` when any live seat is in no room; `seats[]` members unchanged | additive on the wire; the drift declared (§ 7) |
| `POST /desk/{install_id}/{seat_id}/compose` | `POST /desk/{seat_id}/compose` | browser-only (`web`, CSRF) — no window |
| `POST /admin/agents/{install_id}/{seat_id}/retire`, `/admin/floors/{install_id}/…` | `/admin/agents/{seat_id}/retire`; the floors (room-map) module keeps `{install_id}` as the **room key** segment | console, no window |
| `/floor/{floor}/{seat_id}` | unchanged shape; `{floor}` is a slug from S4 (§ 5.4); the seat is resolved over the floor's rooms as today, and the *names a desk in more than one room* notice (D3 § 4.4) is **deleted** — § 3.1 makes the state unreachable among rendered seats, and canon #6 deletes a guard with no route | browser |
| `/api/building/rooms/{install_id}/map` | unchanged: the segment is the room key | browser-only |

### 3.4 The client's key, the desk slot hash and the character seed

The desk key becomes `seat_id`. **One key primitive, `wire/seat-key.js`**, replaces every inline spelling:
`keyOf(seat)` → `seat.seat_id`; `roomOf(key, held)` → the held seat object's `install_id` (the room the seat is
drawn in; `unassigned` for none). The population it replaces is derived, not listed — re-run on the branch:

```bash
grep -rn "key(\|install_id}/\${\|split('/')\|splitKey" server/public/js --include=*.js | grep -v pathname | grep -v tileset
```

On 2026-11-10 that prints `fleet-client.js:83` `key()` and its call sites, the inline `${…install_id}/${…seat_id}`
templates in `desk-floor.js`, `in-flight.js`, `floor-screen.js`, `scene.js`, `desk-layout.js`, and — the sites a
grep for the join alone misses, round 1's M9 — the code that **splits** a key back into its halves:
`floor-compose.js:84` `splitKey()` with its callers at `floor-compose.js:59` and `floor-screen.js:1351`, and
`coord-model.js:357` `roomOf = (key) => … key.split('/')[0]`, which places a fleet-scope coordination animation in
the room of a key's first half. Each becomes `roomOf(key, held)` over the seats the client holds, because the room
is no longer in the key. The seat object still carries `install_id`: it is now **the room the seat is drawn in**, a
mutable member like `render_state`, and a `seat.delta` whose `changed[]` names it is **a room move** (§ 4.2).

Two hashes read the pair and must stop, because a pair that moves is not a seed:

- D3 § 3.2's slot hash `FNV-1a-32(install_id + "/" + seat_id)` → `FNV-1a-32(seat_id)`. The order tie-break
  `(h, seat_id)` is already total on `seat_id`.
- D3 § 10.2's character seed, `resources/characters/seed.js` `seatKey(installId, seatId)` → `seatKey(seatId)`; the
  server's port `App\Characters\Draw` and `CharacterAllocation::choose()`'s preference key `install_id/seat_id` →
  `seat_id`; the three copies are bound through D3 § 3.2's published `h` table, which this change re-publishes
  (`tools/design/verify-floor.py` G8 and `TheSpeciesListHasOneSourceTest` read it).

**Operator ruling 2026-11-10 (Q1): the one-time change is accepted.** On the deploy of S2 every desk re-slots once
(a desk move with no cause message — D3 § 13 row 29's precedent: the slice bumps `feed_version` so every page
reloads, and a reload animates nothing, D3 § 6.5) and every character re-rolls its colourway, face and dress;
**species do not move** (stored, D2 § 6.13).

### 3.5 Retirement, the board link, the roundtable

- `mezzanine:retire --seat=<seat_id>`; the console's retire form posts the seat id; `App\Fleet\SeatRetirement::retire(seatId, …)`
  resolves the row through § 3.6's primitive among live seats (a retired id is *already retired*). The
  `seat.retired` message keeps `install_id` (the room at retirement, or `unassigned`) beside `seat_id`.
- `mezzanine:seat-board-user --seat=<seat_id>`; `LinkPass`'s label `install/seat` → `seat`. `seats.board_user_id`
  unique: unchanged.
- The roundtable name (D1 § 3.1 `roundtable_agent_name`, D2 § 8.3.3's fleet arm) is fleet-scope and never read
  an install: unchanged.
- A retired seat id is never reused: § 3.1's refusal, now the only rule; D2 § 4.10's sentence *a replacement seat
  takes a new seat id* holds site-wide.

### 3.6 One seat-identity read primitive, and every site that must use it in S1

Round 1's B2: a seat with `install_ref NULL` — every new seat from S1 on — vanishes from every query that inner-joins
`installs`, and revision 1 converted three of them. The population is the grep, re-run before S1 lands:

```bash
grep -rn "join('installs'" server/app
```

On 2026-11-10 it prints these sites, each named with what a dropped unassigned seat would have cost:
`Board/LinkPass.php:369` (the log label), `Console/Commands/RebuildCommand.php:82` (a rebuild of that seat
refused as *no such seat*), `Console/Commands/SeatBoardUserCommand.php:91` and `:119` (the board link refused; the
holder of a taken board user unnamed), `Fleet/CharacterAllocation.php:93` (its species free to be allocated
again), `Fleet/SeatRetirement.php:148` (its retirement *no such seat*), `Fleet/SeatTokens.php:217` (**a leaked
token cannot be revoked**) and `:280` (missing from the tokens page), `Http/Controllers/FleetController.php:105`
(timeline), `Ingest/TokenResolver.php:79` (its batches `401`), `Read/SeatObject.php:235` (**`Publisher::seatDelta()`
sends nothing** — `forSeatRef()` returns `null` and `Publisher.php:36-40` returns), `Read/SeatRow.php:24` (the
seat endpoint), `Read/Snapshot.php:114` (the snapshot).

**The primitive: `App\Read\SeatIdentity`** — the one query that names a seat: `seats LEFT JOIN installs` selecting
`seats.id AS seat_ref, seats.seat_id, COALESCE(installs.install_id, 'unassigned') AS install_id, seats.species,
seats.retired_at, seats.retired_by, seats.retired_reason`, with `byId(string $seatId)` (live by default; `retired()`
for the console's retired table), `byRef(int $seatRef)`, and `query()` for the callers that add their own joins
(`Snapshot`, `TokenResolver`, `SeatTokens::listing()`). `SeatRow::find()` becomes a caller of it. **Every site the
grep prints is migrated in S1**, and T21 plants an unassigned seat through revoke, delta, retire, allocate,
listing, rebuild, board-user and the log label. A `join('installs'` left in `server/app` after S1 is a red in T21's
grep leg — the control that the population was consumed, not sampled.

## 4. Rooms

### 4.1 The store: `installs` is the rooms table

| Column | Today | After |
|---|---|---|
| `installs.install_id` | typed at token issue (`SeatTokens::issue()` inserts the install when missing) | the **room key**: typed once at room creation in the console, `Slug::INSTALL_ID`'s pattern, immutable; `unassigned` **reserved** (refused by name, § 4.4). It is the `subject` of the room's map revisions and the `floors.install_id` of its current map, which is why it is not a slug of the name: a rename must not orphan a map's history |
| `installs.display_name` | present, never read or written: `grep -rn display_name server/app server/resources` prints nothing; the column exists in the DDL (`2026_08_25_100000_create_fleet_store_tables.php`) alone | the room's **name**, free text, renamable; `null` reads as the key, as a floor's absent label did |
| `installs.retired_at` | present, never written (the same grep over `installs.*retired_at` in `server/app` prints nothing) | set by the console's **retire room**; refused by name while any live seat is in the room **or any unrevoked coordination hook is bound to it** (round 1's m8: `coord_hooks.install_ref`; revoke the hook first); the same transaction removes the room from the layout through `Layouts::amend()` (§ 5.5; one revision), so no stored layout places a retired room; a later save or restore that would place one is refused **at the write** by name (route: the raw JSON editor, a restore of an older revision) — never at load, because no load can meet one. A retired room is listed in the console only, and its map history stands |
| `seats.install_ref` | `NOT NULL`, written at issue | **nullable**; `NULL` = unassigned; written at issue (`NULL`) and by the console's **place seat** (§ 4.2) |
| `coord_hooks.install_ref` | the install | the room (§ 4.3) |
| `floors`, `authored_revisions` | keyed by the install id | keyed by the room key — unchanged bytes |

D2 § 6.4's DDL block gains the comment `-- the ROOM (card#9267; created in the console since card#13117)` on
`installs`, in the style its `floors` block already uses (D2 line 2205: `-- the ROOM (card#9267).`), and the
`uq_live_seat` key replaces `uq_seat`.

### 4.2 The console: rooms, and the seat move

A **Rooms** module beside *Agents*, *Floors* (room maps) and *Layout*: `GET /admin/rooms` (every room, live and
retired, with its floor from the current layout, its seats, its map version), `POST /admin/rooms` (key, name,
optional floor — placing goes through `Layouts::amend()` in the same transaction as the room's insert, so the room
and its placement are one revision), `PATCH /admin/rooms/{room}` (name), `POST /admin/rooms/{room}/retire`. The
**Agents** index gains one control per live seat: *Room* — a select over live rooms plus *Unassigned* — posting
`PATCH /admin/agents/{seat_id}/room`.

**The move is one transaction and one announcement.** Under `SeatLock::forUpdate()` (the order card#12461 fixed):
`UPDATE seats SET install_ref = ?`, then `StateRecompute::forSeat(…, 'operator', …)`. For that to emit anything,
**`install_id` joins `SeatFacts::versionBearing()`** (round 1's m1): the function already reads the `seats` row it
needs (`SeatFacts.php:188`), and D2 § 6.5's subtraction is what makes a member version-bearing — a desk drawn in
another room is a different rendering — so the recompute sees the member move, bumps `state_version`, and the
`seat.delta` it enqueues carries `install_id` in `changed[]` and the new key in `patch`. Nothing new rides the
feed. The client (§ 3.4) finds the seat under its `seat_id`, applies the patch, and the next frame's slot function
places it in the new room; the old room's chain re-probes as it does on a retirement (D3 § 3.5). **Render:** the
desk leaves the old room as a retirement's desk does (D3 A13, with a log line *moved to room X*) and appears in
the new room as a seat inserted by § 2.3's fetch does — no arrival walk, because *the seat was placed* is a
console fact, not *the seat came online* (D3 § 3.4's last paragraph). § 12 ruling R5 makes this reversible.

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
**`room`** on `coord.round` and in `coord_hooks.scope`'s enum (§ 6.2 step 3), since the feed is browser-only with
no window (D2 § 8.1 row 2) and the client's one branch (`coord-join.js`) is in the same deploy. `coord_round.install_id`
keeps its name and carries the room key. **Cost stated:** which `pm` a team repository's post resolves to is now a
function of the console's placement, and § 4.2's warning is the whole defence; the alternative — a binding naming
its seats explicitly — is a second membership registry (canon #5) and was rejected in § 2.2.

### 4.4 Unassigned — a virtual install in S1, a floor of its own in S4

**Both composers compose.** The server's `Building::compose()` mints an implicit floor per install the layout does
not place, and so does the browser's `lobby-model.js floors()` (`:273-285`) over the snapshot's `installs[]` — the
lobby, the directory and the floor route read the browser's answer, and `GET /api/building` serves only the
layout (D2 § 8.7). The two are held to one answer by `server/tests/fixtures/building/compose-cases.json`
(`TheDirectoryListsTheComposedFloorsTest.php:236-245`). Revision 1's *the client draws it with no new code path*
was true of the room and false of the floor; this section is written for both composers.

**S1: nothing in either composer changes.** `Snapshot` groups the seats whose `install_ref` is `NULL` under the
virtual install `install_id: "unassigned"` (§ 3.6's `COALESCE`); every seat object and delta of theirs carries it;
the layout does not place it; so **today's rule** — one implicit floor per unplaced install, keyed and labelled by
the install id, one `open` room — draws the floor `unassigned` with the room `unassigned` on both composers,
untouched. `GET /api/building/rooms/unassigned/map` answers the shipped default because no `floors` row can hold
the reserved key; the desk slot function runs over its seats as over any room's. The reserved key is refused by
name at room creation (S3), at the layout write and at load (a layout placing `unassigned` places a room the
console cannot create), and `FloorInventory` excludes it so nobody authors a map for it. ⚠ What S1 does **not**
do: move the unplaced installs `sola`, `kanban`, `mezzanine` — they keep their implicit floors, exactly as today,
until S4 materialises them (round 1's M2).

**S4: the Unassigned floor gathers the unplaced.** With the implicit-floor rule retired (§ 5.6), both composers
mint **one** implicit floor, key `unassigned`, name *Unassigned*, house theme, sorted last and present only when
non-empty, whose rooms are every live room the layout does not place, in key order under § 4.6 rule 3's default
arrangement, plus the virtual room `unassigned` when the snapshot carries it. `compose-cases.json` gains the cases
(unplaced room alone; virtual room alone; both; neither → no floor) and both composers are held to them. D3 § 9
F17's *unclaimed rooms* directory is subsumed: an unplaced room is on this floor.

**Why a floor and not a notice:** ruling 3 says *area*, and an area a viewer can ride to — a stop in the lobby's
directory and the cab's panel, shown only while it holds something — is how the operator finds a new agent to place
it.

## 5. Floors — named, slugged, redirected

### 5.1 The document

The layout entry (D3 § 4.6's table) changes two members and keeps the rest byte for byte:

| Member | Was | Now |
|---|---|---|
| `label` | optional display text | **removed**; the reader refuses it by name with *since card#13117 the member is `name`* (one shape; two spellings would be two formats for one thing — operator ruling Q5 accepts the restore cost, § 6.5) |
| `name` | — | **required**: a string whose rendered form (D3 § 4.6's whitespace rule, kept) is non-blank and whose slug (§ 5.2) is non-empty and not `unassigned`. Stored byte for byte; rendered wherever `label` rendered (plaque, stop, floor screen, building view, directory) |
| `aliases` | — | optional list of slugs the floor used to answer to (§ 5.3); each a valid slug, not `unassigned`, not equal to any floor's current slug, and present in at most one floor's list. Written by the console's rename and take-over; readable and editable in the raw document |
| `rooms`, `theme`, `hallway`, a room's `form` and `origin` | | unchanged |

The served floor (`GET /api/building`, D2 § 8.7) carries `floor` = the slug, `name`, `aliases`, and the rest as
today. Both composers key floors by slug.

**Refused at load, each naming what it names** (`InvalidBuildingLayout`, so a stored document a later reader refuses
is D3 § 9 F17 as today): a floor with no `name`, a non-string `name`, a blank or slug-empty `name`; two floors whose
names slug alike (replacing *two floors that would read the same*, which it subsumes: two names that read alike
always slug alike); an alias colliding with any current slug or repeated across floors; the reserved slug as a name
or an alias; a placed room whose key is `unassigned`. **Refused at the write only** (`Layouts::amend()`'s
validation and a restore, as a theme name the registry lacks is today, D3 § 4.6): a placed room the store holds as
retired — § 4.1 says why no load can meet one. A room key the store does not hold stays **allowed**, as today
(`reported: false`), so a layout may still be authored ahead of a room.

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
| a name whose slug equals a **room key** (`sola`) | allowed; the floor **wins** the address — § 5.3's precedence | a room key's redirect is a courtesy to old links, and a floor the operator named is the operator's intent |
| a name whose slug is `unassigned` | refused at the write and at load | § 4.4's reserved floor |
| length | unbounded beyond the document's 512 KiB (D2 § 6.11) | D3 § 4.6's `origin` precedent: *a bound would be a number with no derivation behind it*; a long name is a long address the author sees on the form before saving |
| case in a typed address (`/floor/The-Nook`) | the client lowercases the segment before resolving, reaches the floor, and replaces the address bar with the **resolved** slug — `main.js:276-277` compares against `routeOf(floorSegment(), …)`, the URL's own segment, so today a typed spelling stays; the frame carries the resolved slug and `routeOf(frame.floor, …)` is what the bar is compared with (round 1's m2) | an address someone typed from a plaque should work and then read canonically |

### 5.3 Rename, redirect, take-over, removal — and why there are no chains

**Operator ruling 2026-11-10 (card comment 16717, Q2), replacing revision 1's refusal:** *a new floor TAKES OVER an
old floor's former address; the old floor's alias is deleted first, then the new name is assigned — "if old data
needs to be deleted, then delete first" — and the old link then opens the new floor.*

- **Precedence of a `/floor/{segment}`** — one order, stated once and read everywhere (round 1's M6/Q2-5):
  **current slug > alias > room key.** A segment equal to a floor's current slug opens that floor; else equal to a
  floor's alias → redirect to that floor's current slug; else equal to a room key placed on a floor → redirect to
  that floor (today's room redirect, kept); else → the page's *no such floor* state. So a floor named `sola` takes
  the address `/floor/sola` from the room `sola`'s redirect, and a floor whose alias is `sola` does too: a name the
  operator chose outranks a key nobody chose.
- **Rename** (console, § 5.5): the entry's `name` changes and, when the new slug differs from the old, the **old
  slug is appended to `aliases`** (if not already there). A rename back to a former name removes that slug from
  `aliases` as it becomes current again.
- **Redirect**: alias → `location.replace('/floor/{current}[/{seat_id}]')` with the seat segment preserved, exactly
  as the room→floor redirect does today (D3 § 4.4 row 3, `main.js:264-277`; `#ride` survives a `replace`). ⚠ **A
  drill-down link whose floor segment was taken over** opens the new floor, where the seat is not — § 13 Q7 asks
  whether the seat (unique site-wide) or the floor wins that link; this revision builds neither until it is
  decided, and the redirect above stays on the floor segment alone.
- **No chains by construction**: every alias maps to its holder's **current** slug, and a second rename appends
  another alias to the same entry; a name used three times ago still redirects in one hop.
- **Take-over**: a save giving floor B a name whose slug is in floor A's `aliases` **removes it from A's list and
  assigns it to B in one revision** — one document, one `building.layout` row, delete-first inside the document
  the write validates, so the reader never meets the collision. `/floor/<that slug>` now opens B. **It is a
  console act and a decision taken inside the layout lock** (§ 5.5's `Layouts::amend()`): the console's rename and
  create actions read the current document under `FOR UPDATE`, find the alias, delete it, assign the name, validate
  and write. **The raw editor can take over too, by writing a consistent document** — the alias gone from A and the
  name on B in one save; a raw document that assigns the name and leaves the alias on A is **refused** at the
  write as the collision it is (§ 5.1), never repaired by the reader (round 1's Q2-2). The console's rename form
  shows the floor that holds the address before the save, so a take-over is never a surprise; and A's form lists
  its old addresses with a *forget* control (remove from `aliases`) for the operator who wants none taken.
- **Two renames racing** (round 1's Q2-3), both refused loudly because the decision is inside the lock: (i) B's
  take-over of `the-nook` commits first; A's rename back to *The Nook* then finds the slug current on B → refused
  naming B. (ii) A's rename back commits first (alias removed, slug current on A); B's take-over then finds no alias
  and a current slug on A → refused naming A. Neither order silently loses the other's write — the textarea's
  compare-and-swap (§ 5.5) refuses a stale base the same way.
- **Removal of a floor** (the entry deleted; the console refuses while the floor holds rooms, the raw editor does
  not): its slug and aliases resolve to nothing; old links reach *no such floor*. Not defended further: a deleted
  floor has no forwarding address, and inventing one is a floor id by another route.
- **A raw-JSON rename** that does not add the alias: the operator wrote a document with no redirect, and gets one;
  the console's form is where the alias is written for them. Named, not defended.
- **A restore** of a revision (D2 § 6.11) is checked exactly as a save — so restoring a revision whose aliases
  collide with a floor created since is refused by name, as a restore that re-creates an overlap is today.

### 5.4 The routes and the client

`routes/web.php`'s `/floor/{floor}` and `/floor/{floor}/{seat}` are unchanged: the segment is handed to the page as
data and resolved against the fetched layout. `floor-screen.js`'s resolver (`:132-136`) implements § 5.3's
precedence — current slug, alias, room key — and the room branch **stays**, which is how every pre-13117 link
survives even where a floor's new slug differs from its old key (§ 6.3 also writes the old key as an alias, so both
branches reach the same place; the room branch covers rooms moved since). The directory (`directory.js:217`), the
cab panel and the building view read `floor` (now the slug) and `name` where they read `floor` and `label`.

### 5.5 The console's floors module, and the one layout writer — where the requirement forces a large change, priced

Today the layout is a `<textarea>` (`console/layout/edit.blade.php:29`, `LayoutController::update()`), saved whole
through `Layouts::save()` inside `Layouts::serialise()` (`Layouts.php:165-178`: `Outbox::transaction`, the
`building_layout` row `FOR UPDATE`, then the write). Rulings 1–2 ask the operator to *create named rooms, put each
seat in a room, put each room on a floor* and *name floors freely* — a form, not JSON.

**`Layouts::amend(callable $edit, string $by): int`** (round 1's Q2-1) — the one writer every console action
uses: inside `serialise()`'s lock it reads the current document, hands it to `$edit` (which returns the new
document or throws a named refusal), validates the result exactly as `save()` does, refuses a no-op, and writes
the revision. The take-over's find-delete-assign, the rename's alias append, a room's placement, a room's
retirement (§ 4.1) and a floor's removal are each one `$edit` closure, so a decision about the document is made
against the document the write replaces, never against the one the form was rendered from. **The textarea is held
to the same rule by a compare-and-swap**: the form carries `base_layout_version`, and `save()` gains a required
`int $base` that it compares with the current `layout_version` under the lock — unequal → refused by name, *the
layout changed since you opened it (revision N is current; you edited from M): reload and re-apply*. A console
action and a textarea save can race in either order and the loser is told.

The **Floors** module: `GET /admin/building` (floors with their rooms, names, slugs, aliases, themes), `POST
/admin/building/floors` (name, theme), `PATCH /admin/building/floors/{slug}` (rename → alias rule and take-over;
theme; forget an alias), `POST /admin/building/floors/{slug}/rooms` (place a room — moves it from its current
floor; `origin` cleared on both floors, since a planned floor is all-or-nothing, D3 § 4.6), `POST
/admin/building/floors/{slug}/remove` (refused while it holds rooms). The textarea stays for what the form does not
do (`origin`, `hallway`), and the two never disagree because both write through `amend()`/`save()` under one lock.
**Price:** a controller of the order of `FloorController.php` (`wc -l server/app/Http/Controllers/Admin/FloorController.php`
re-prints it) with four views, its feature tests, and `README.md § The admin console`'s paragraph; one slice (§ 10
S5). Until it lands the operator authors the same document in the textarea, with the reader's refusals as the
guide — every earlier slice is usable without it.

### 5.6 The two reversals, recorded where the rulings stand

Ruling 2 reverses card#9273 (*a label is never a key*) and card#9267's derived key. Both are stated in the tree at
every site `grep -n 'card#9273\|card#9267' docs/design/*.md server/app -r` prints (on 2026-11-10: D3 at
`grep -c` = 42 lines, D2 at 2, `Building.php` at 2, `BuildingLayout.php` at 5, `FloorInventory.php` at 2). Each
site is amended **in place** with a ⭐ note naming card#13117 — the convention D3 § 3.1 set for card#9267's own
sweep — and the sweep is re-derived from the grep at the slice, never from this paragraph. The load-bearing ones:
D3 § 3.1's floor row (key = slug of the name; the install row becomes the room row), § 4.4 rows 3–4 (the alias
redirect beside the room redirect, and the precedence), § 4.6's `a floor's key`, `a floor's label` and *A floor has
no authored id* paragraphs (the no-collision argument's premise — floor keys and install ids in one route namespace
— no longer holds, which is why the derived key can go), § 13 rows 24, 25, 27, and D2 § 6.11's *(an install,
card#9267)*. **Card#9267's room-is-an-install half survives** (a room is still the coordination and map unit); what
reverses is the key derivation and the implicit floor per install — **at S4**, in both composers at once.

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

### 6.2 The store migration, in order (every refusable step before any DDL; the DDL one statement)

1. **Preconditions, each refusing by name with nothing written:** the live-duplicate check above is empty; no
   `installs` row is keyed `unassigned` (§ 4.4's reserved key); and — S4's half — the migrated layout of § 6.3 is
   **built and validated in memory** by the new reader, which also catches round 1's m5: a floor whose `label`
   slugs like an unplaced install's materialised name (a label `sola` on a floor while install `sola` is unplaced)
   is refused naming both, and the remedy is printed — rename the label, or place the install, then migrate again.
   The sandbox's four labels (§ 0) collide with none of its three unplaced installs. The precedent is
   `2026_09_12_000100_create_the_authored_building_store.php`, which validates the configured layout before any
   DDL so a refusal leaves the schema as it was.
2. **One `ALTER TABLE seats`** carrying every specification — `ADD KEY ix_seat_install (install_ref)`, `DROP KEY
   uq_seat`, `MODIFY install_ref SMALLINT UNSIGNED NULL`, `ADD COLUMN live_seat_id … VIRTUAL`, `ADD UNIQUE KEY
   uq_live_seat (live_seat_id)` — because `DROP KEY uq_seat` alone fails: `fk_seat_install` needs an index whose
   leading column is `install_ref`, which `uq_seat` was the only one of (round 1's M1: `ERROR 1553`, measured by
   the reviewer on a scratch 11.8.6), and a DDL statement is not transactional, so a step that fails after another
   succeeded leaves a migration that can neither re-run nor roll back. One statement applies whole or not at all.
   Existing rows are untouched: every seat keeps its room.
3. `UPDATE coord_hooks SET scope = 'room' WHERE scope = 'install'`, then `ALTER TABLE coord_hooks MODIFY scope
   ENUM('room','fleet')` — the update first, since MariaDB refuses a value the new enum lacks; both idempotent (the
   update matches nothing on a re-run; the `MODIFY` to the same enum is a no-op).
4. Mint the rooms the layout places and the store lacks — every room key in the current layout's `rooms` mappings
   `MINUS installs.install_id` → `INSERT installs (install_id, display_name = NULL)`, as `insertOrIgnore` on
   `uq_install` so a re-run inserts nothing. On the sandbox: `treehouse`, `nook` (§ 0).
5. **S4's step:** write the document validated at step 1 as a new `building_layout` revision, `authored_by =
   'migration card#13117'`, through `Layouts::amend()` so it publishes `building.layout` as any save does.

S1's migration file carries steps 1 (less the layout validation), 2, 3 and 4; S4's carries step 1's layout
validation and step 5.

### 6.3 The layout migration, worked on the sandbox's document

For each entry in the current document: `name` = `label` where present, else the floor's derived key;
`aliases` = `[derived key]` when `slug(name) ≠ derived key`, else `[]`. Then, for every install the layout does not
place — today's implicit floors — a new entry `{ name: <install_id>, rooms: { <install_id>: { form: "open" } } }`
is **materialised**, so the migrated building draws exactly what the composed building drew the day before (T14:
both composers' output before and after differ only in `floor`/`name`/`aliases` and in `label`'s absence). The
sandbox's document becomes:

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
Under § 3.2 neither is read; `seat_id` is matched to the token; the batch is accepted exactly as before. **No
reporter is touched; no token is reissued.** A reporter's `selftest` still prints `install_id · seat_id · url`
(`install.js:1890`) until § 8.1's installer lands — a stale label on the seat's own screen, named here, fixed there.

### 6.5 Rollback

S1's `down()` restores `uq_seat` only if no two live seats share a `seat_id` under one `install_ref` — true by
construction if nothing was moved — and cannot restore `install_ref` to `NOT NULL` while any seat is unassigned: it
refuses by name, listing them, and the operator places them first (the console) or retires them.

**Rolling S4's code back over a new-shape layout** (round 1's m6): the pre-S4 reader refuses `name` and `aliases`
as unknown members, which is D3 § 9 F17 on every page — the hazard D3 § 4.6's `theme` row already names for its
own rollback (*a rollback past row 21 first saves the layout without it*). S4's `down()` therefore writes a new
revision copying the last pre-migration revision's document (the one step 5's revision followed; `restored_from`
set to it) with the two statements `Layouts::write()` performs (`Layouts.php:314`, private to the class — the
migration issues them itself, inside `serialise()`'s lock), **bypassing the new reader's validation by design**: the
old reader is the one that will read it. It prints what is lost: every name and alias authored since, and every floor the operator removed
after the materialisation reappears as an implicit floor. **A pre-13117 revision is refused by the new reader**
(`label`, no `name`) — operator ruling Q5 accepts that cost: an operator restoring one exports it, edits `label` →
`name`, and saves it as a new revision.

## 7. Across the boundary (canon #7)

| Hop | Declares | Checks | Neither end can verify |
|---|---|---|---|
| **reporter → ingest, `install_id`** | D1 § 4.2's and § 4.3's amended rows (this repo publishes both ends) | T4: a batch with `install_id` absent, present-and-valid, present-and-foreign, present-and-malformed is accepted; one with a wrong `seat_id` is refused | that every reporter in the field is the version this repo last shipped — the heartbeat's `reporter_version` is the only reading, and D2 § 8.2.1's `reporter_outdated` badge is where it shows |
| **snapshot → the bridge's idle nudge** (kanban-solo's `agent-webhook-bridge`) | **this repository declares, on the roundtable and on card#13117, before S1 merges:** *From mezzanine vX.Y (card#13117 S1) `GET /api/fleet/snapshot` keeps `api_version: 1` and changes additively: `installs[].install_id` is the ROOM a seat is placed in by the admin console — mutable, renamable, retirable — no longer the install the token was issued under; a group `unassigned` appears whenever a seat is in no room; `seat_id` is unique across the whole site. `BRIDGE_IDLE_NUDGE_INSTALL` therefore names a room key, and a seat moved out of that room or left in `unassigned` is outside the group your `FleetSnapshotReader` checks (`:136`, `:148`) with no refusal; your reader can select by `seat_id` alone from S1 on. The bump to `api_version` 2 and the removal of `/api/fleet/seats/{install_id}/{seat_id}` wait for your word that your reader has moved.* | T6 and T7 hold the shape: `api_version` 1, no member removed, the `unassigned` group a well-formed group; the declaration's text is the release note's *Upgrade warnings* | whether and when the bridge's reader moves; what `BRIDGE_IDLE_NUDGE_INSTALL` is set to on its host — the room `kanban`'s only live seat is in `solo-agents` on the sandbox (§ 0), which that end alone can read |
| **Seat-tokens page → installer, token file v3** (§ 8.1) | `fleet-reporter/INSTALL.md § The token file` and D1 § 3.1 state v1/v2/v3; the page says *needs installer ≥ <version>* beside the button | `install.selftest.py` drives the page's blob through the parser (D6's R-srv-1) | the installer version on the agent's machine; an old installer refuses a v3 file **by name** (*missing install_id*), which is loud, and the remedy is the page's own text |
| **coordination hook → room** (§ 4.3) | D2 § 8.3.3 rule 1 names the room arm | AT-D2-24's REDs re-planted on a room | that the team repository's roster is the room's seats — never verifiable from here (D1 § 18.3.1), and now also moved by a console edit; § 4.2's warning is what this end can do |
| **agent launch → seat** (§ 8.3) | `fleet-reporter/INSTALL.md § Several seats on one account` | the installer prints the config dir it wired, and `selftest --config` prints the seat it will report as | **how the agent was launched** — the server sees the seat whose hooks ran and nothing about the process that ran them |

## 8. The agent side — the token file, the config, and several seats on one account (card#13118)

### 8.1 Token file v3 and the config

**v3** is v2 minus the `install_id` line: exactly three `<key> <value>` lines — `token`, `ingest_url`, `seat_id` —
once each, any order, LF only, the same bounds (D1 § 3.1, `INSTALL.md § The two forms`). The *Seat tokens* page
writes **v3 only** from this card on (its `data-install-id` attribute goes; `seat-token.js` builds three lines).
`parseTokenFile` (`install.js:229-254`) reads v1, v2 and v3 and refuses anything else by name as today; a v2 file's
`install_id` is **read and discarded** (a 13117-era server never reads it), so a file saved before the deploy still
installs. ⚠ **Every branch that reads `parsed.version === 2` takes v3 the same way** — `valuesFrom()`
(`install.js:1705`), the rotation (`:1753`) and `add-site` (`:1785`) — else a v3 file falls to the v1 branch, which
demands `--site` and `--seat-id` and, on a two-site config, rotates the **default** site with the file's token
(round 1's M5). The identity comparison in those branches is **`seat_id` alone**; a v2 file's `install_id` is
compared with nothing. T16 plants it.

The config's `install_id` row (D1 § 3.1) becomes **optional**; `loadConfig` (`fleet-reporter.js:399`) checks it
only when present; the writer (`install.js:303` `writeConfig`) omits it; the envelope and per-event `install_id`
are **sent when the config has one and omitted when it has not** (`fleet-reporter.js:2094, 4051, 4064, 4075,
4476`); `identityBinding` (`:332`) hashes `${cfg.install_id ?? ''}|${cfg.seat_id}` so an upgraded seat's
`state.json` binding does not move; the hook gate `config.install_id && config.seat_id` (`:3252, :3302`) reads
`seat_id` alone. `--install-id` leaves the installer's flags (`VALUE_FLAGS`, `:1933`); the account-slug default
(`:511, :1714`) goes with it. `selftest`'s identity line prints `seat_id · url`.

**Existing configs are never rewritten**: a seat upgraded in place keeps `install_id` in its file and keeps sending
it, which § 3.2 accepts. One written shape, two read shapes — the posture card#12180 set for `sites`.

### 8.2 Several seats on one account — the model

Today one account is one seat: hooks in `~/.claude/settings.json` (`install.js:127`), one config
(`fleet-reporter.js:299`), one spool, one flusher under one supervisor label (`install.js:55, 117-119`), so every
Claude Code session on the account reports as that seat and a second install rotates its token (`install.js:1756`).

**After:** an account holds **N seats**; each seat is one Claude Code *profile* — a `CLAUDE_CONFIG_DIR` — with its
own `settings.json` carrying hooks that name that seat's config; each seat has its own config, token, spool and
supervised flusher; the artifact (`~/.local/share/fleet-reporter/fleet-reporter.js`) is shared. The account's
**first** seat is the one installed today under `~/.claude` and the default paths, and it is not moved. **Operator
ruling 2026-11-10 (Q4): the profile is the identity, as recommended.**

### 8.3 How a session learns its seat — the hook command names the seat's config

The hook command the installer writes for a seat beyond the first is `node <artifact> hook <Hook> --config <abs path
of that seat's config.json>` (and `statusline --config …`), the path **double-quoted on `win32`** exactly as the
artifact path is in `hookCommand()` (`install.js:142`), since a profile path with a space under `cmd` would split
(round 1's m9). D1 § 2.1's contract — `argv[2] == <HookName>`, the payload on stdin — is unchanged; argv[3–4] are
new and the reporter reads them exactly as it reads `FLEET_REPORTER_CONFIG` today (`configPath()`, `:293`): they
select **which file**, and no identity value comes from the environment or the command line (D1 § 3.4 rule 1 holds:
the identity is still file-resident). Precedence: `--config` over `FLEET_REPORTER_CONFIG` over the default path, and
a `--config` naming a file that cannot be read is a loud `config_readable` failure for that hook, never a fall-back
to the default seat. `parseReporterCommand` (`install.js:148`) admits the suffix in both spellings, so the installer
still recognises and replaces its own entries.

**Why argv and not an env prefix in the command:** `VAR=x node …` is a POSIX shell form; the Windows hook command
is `node "<path>" hook <Hook>` under `cmd` (`hookCommand()`, `:142`), where it is not. One spelling on three
platforms.

**Why the identity is a `CLAUDE_CONFIG_DIR` and not a directory on disk** — the two candidates the card names:

| Candidate | Mechanism | Verdict |
|---|---|---|
| **per-agent `CLAUDE_CONFIG_DIR`** (chosen; operator Q4) | the agent is launched with `CLAUDE_CONFIG_DIR=<dir>`; Claude Code reads **its** `settings.json` from `<dir>` — the documented behaviour: *"All settings, session history, and plugins are stored under this path … Useful for running multiple accounts side by side"* (`code.claude.com/docs/en/env-vars`, read 2026-11-10); the installer, run inside that agent's session (where it reads `process.env.CLAUDE_CONFIG_DIR`) or with `--claude-config-dir <dir>`, wires the seat's hooks into `<dir>/settings.json`. A session under `<dir>` has that file's hooks and not `~/.claude`'s, so **one session reports once, as one seat** | the agent *is* the identity, which is what the operator is naming; nothing in a project directory decides who is reporting; two agents may work the same repository |
| **project `.claude/settings.local.json` carrying `env.FLEET_REPORTER_CONFIG`** | the account-level hooks fire once and read the env — documented as *"Set environment variables for every session and its subprocesses"* (`settings-reference`), and a hook *"inherits the parent environment"* (`hooks`) | **rejected**: (1) the seat is a property of a **directory**, so two agents on one checkout are one desk and one agent across two checkouts is two; (2) *most `env` values apply only after each teammate trusts the folder* (`settings`) — an untrusted or freshly cloned folder would **silently** report as the account's default seat, the misattribution D1 § 3.4 was written against; (3) the file is also where Claude Code writes permission approvals, so it is not the installer's to own |

**What a profile is, and what each extra one must be given** (round 1's M7). `CLAUDE_CONFIG_DIR` relocates
*every* `~/.claude` path and `~/.claude.json` (`code.claude.com/docs/en/claude-directory`, read 2026-11-10: *"every
`~/.claude` path on this page lives under that directory instead. The same applies to `.claude.json`"*), and the
login with them (`authentication`: *"keeps the `.credentials.json` file under that directory instead … and keys
the macOS Keychain entry to that directory too, so a session with a different `CLAUDE_CONFIG_DIR` reads a different
entry"*). So a second seat is a second Claude Code profile, and the operator provisions, per profile, what the
installer does not:

| Per profile | Who provides it | Cost per profile |
|---|---|---|
| the login (`<dir>/.credentials.json` or its Keychain entry) | the operator: launch `CLAUDE_CONFIG_DIR=<dir> claude` once and sign in, or `claude setup-token` into `CLAUDE_CODE_OAUTH_TOKEN` for an unattended seat | one browser sign-in; the same claude.ai account may sign in to N profiles |
| `<dir>/settings.json`: permissions, `env.COORD_CONFIG`, the model, the `statusLine` | the operator copies the first profile's, less its hooks; the installer **adds the hooks and the statusLine wrap** and refuses a file already carrying another seat's | one copy |
| `<dir>/CLAUDE.md` (the user-level canon), `<dir>/agents/`, `<dir>/skills/`, `<dir>/rules/` | the operator (copy or symlink from the first profile; the coord plugin's `init-solo`/`onboard-agent` write them per profile where the fleet uses it) | one copy or one link each |
| `<dir>/plugins/` (the coord plugin and its marketplace) | the operator, under that profile: the plugin's own install command | one install |
| `<dir>/.claude.json`: MCP servers, trust decisions, account state | written by Claude Code on first launch; MCP servers re-added per profile | one first launch, plus each MCP server |
| session history, auto memory, projects | profile-local by design; nothing to provision | — |

**The installer provisions the hooks and nothing else, and shows the operator the table once**: on the first
`add-seat` into a profile it prints the rows above with the paths it found present and absent (`settings.json`
missing, no `plugins/`, no credentials) as a checklist, not a refusal — a profile may be deliberately bare — and
`fleet-reporter/INSTALL.md § Several seats on one account` carries the table. **Not verified here:** that the
Keychain entry keyed per directory survives the macOS fallback to a file when the Keychain is locked (the docs say
the fallback file goes under the directory too); the first macOS install measures it.

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

- **Where a seat goes is decided by the profile, not by whether the account has a seat** (round 1's m7). The
  target profile is `--claude-config-dir`, else `CLAUDE_CONFIG_DIR` from the environment, else `~/.claude`. **The
  default paths (§ 8.4's first column) belong to the `~/.claude` profile alone**; any other profile gets the
  per-seat paths, whether or not the account holds a seat yet. So an account whose very first seat is installed
  under `CLAUDE_CONFIG_DIR=~/.claude-a` gets `seats/<seat_id>/…` and a suffixed supervisor label, and the account's
  `~/.claude` profile stays seat-less until something is installed there.
- **Add a seat**: `node install.js --token-file <path> [--claude-config-dir <dir>]`. The file's `seat_id` (v3; a
  v2 file works the same) is compared with every config on the account (`config.json` and `seats/*/config.json`):
  **equal to one** → that seat's rotation, today's path (`:1756`), and the target profile must be the one its hooks
  are in; **equal to none** → a **new seat** in the target profile. A target `settings.json` already carrying this
  reporter's hooks for **another** seat is refused by name (one profile, one seat).
- **Rotate**: unchanged per seat; `--site-name` and `add-site` scope to the seat the file names.
- **Upgrade the artifact**: one artifact; `restart` kickstarts every seat's supervisor entry (`holder` becomes per
  seat: it reads every lock file).
- **Uninstall**: `uninstall --seat <seat_id>` removes one seat's config, spool, supervisor entry and its hooks from
  its profile; bare `uninstall` on an account with several seats refuses and lists them.
- **`selftest`**: `--config <path>` or `--seat <seat_id>`; without either on a multi-seat account, every seat in
  turn, each block headed by its seat id.

### 8.6 Existing single-seat installs keep working with no reinstall

Their config, spool, hooks (no `--config`), supervisor label and flock are the default-path case of every rule
above; `configPath()`'s default branch is unchanged; the reporter sends their `install_id` and the server accepts it
(§ 3.2). Adding a second seat to such an account changes nothing in the first seat's files — the installer writes
only under `seats/<seat_id>/`, a new supervisor entry and the new profile's `settings.json`.

### 8.7 Not verified here, by name

- That hooks under a `CLAUDE_CONFIG_DIR` fire with the same payload and environment as under `~/.claude`. The docs
  say settings live there; the hooks page (read 2026-11-10) never mentions the variable. Measured on the first macOS
  seat, which is § 10 S7's install step; this host is Linux.
- That `CLAUDE_CONFIG_DIR` reaches the hook's environment. **Irrelevant to the chosen route** — the command carries
  the path — and named because the rejected route depended on it.
- launchd's behaviour with N labels under one `gui/<uid>` domain: recall says independent; the first macOS install
  measures it.
- The Keychain-per-directory behaviour under the macOS file fallback (§ 8.3's table).

## 9. How the tests pin it — property, plant, observable

Each row: the **property**, the **plant** that must red (seen to fail before the fix, canon #9), and the
**observable** asserted. Where a ratified AT is amended, its id is named; new ones take the next free number in the
owning document at the slice. *Both composers* means `Building::compose()` and `lobby-model.js floors()` driven over
`compose-cases.json`, which is the only place a floor set is observable (§ 4.4).

| # | Property | Plant | Observable |
|---|---|---|---|
| T1 | a `seat_id` any row holds is never issued again (§ 3.1) | retire seat `x`; call `issue('x')`; and separately, on a store seeded with § 0's two retired pairs, call `issue('mezzanine-solo')` | `SeatTokenRefusal::seatRetired` both times; `seats` row count unchanged (`SELECT COUNT(*)` before = after, asserted as equality, not a figure) |
| T2 | a live id re-issues, never duplicates | `issue('y')` twice | one `seats` row for `y`, two `ingest_tokens` rows, the second response lists the first's prefix in `priorActive` |
| T3 | the store refuses a second live `y` from any writer | raw `INSERT seats (seat_id='y', retired_at NULL)` | `1062` on `uq_live_seat`; the same insert with `retired_at` set succeeds (the live-only key) |
| T4 | the ingest reads no `install_id` (§ 3.2) | four batches for token-bound seat `y`: no `install_id`; `install_id: "aimla"`; `install_id: "zzz-not-a-room"`; `install_id: "Not A Slug"` — and each event in them carrying the same four | all four `200` with `accepted` = the events sent; a build that compares or bounds the field reds on the third or fourth |
| T5 | `seat_id` still binds | batch for `y` claiming `seat_id: "z"` | `403 identity_mismatch`, body carries `expected_seat_id: "y"` and no `expected_install_id`; refusal counted on `y` |
| T6 | REST by seat alone is additive (§ 3.3) | `GET /api/fleet/seats/y`, `/api/fleet/seats/anything/y`, `/api/fleet/seats/nope`, `/api/fleet/seats/y/timeline`, and a seat named `timeline` at `/api/fleet/seats/timeline/timeline` | first two `200` with identical bodies and `api_version: 1`; third `404`; the fourth is `y`'s timeline; the fifth is seat `timeline`'s timeline (the route order) |
| T7 | an unassigned seat rides the snapshot as a virtual install (§ 4.4) | issue `w` (no room) | the snapshot has an `installs[]` group `unassigned` holding `w` and `api_version: 1`; both composers, fed that snapshot and the current layout, produce (S1) an implicit floor `unassigned` by today's rule and (S4) the Unassigned floor; `GET /api/building/rooms/unassigned/map` is the shipped default; after placing `w` neither composer produces the floor |
| T8 | a room move is one delta and the client re-slots (§ 4.2) | place `w` in `aimla` | one `seat.delta` with `changed` ⊇ `['install_id']`, `patch.install_id = 'aimla'`, `state_version` +1 — a build without `install_id` in `versionBearing()` emits nothing (the RED); the headless desk model (`desk-floor.js`) holds one desk for `w`, in room `aimla`, and none in `unassigned`; mutate the client to key by the pair → it holds two desks |
| T9 | the slot and the seed read `seat_id` alone (§ 3.4) | D3 § 3.2's worked `h` table re-published for `seat_id`; `verify-floor.py` G8 and `TheSpeciesListHasOneSourceTest` read it | both green on the new table; the Python copy mutated to hash `install/seat` → red |
| T10 | the coordination arm is the room (§ 4.3) | AT-D2-24's install-arm REDs with a seat moved out of the room between declaration and post | the post resolves to no desk after the move, to the desk before |
| T11 | the slug function (§ 5.2) | the fixture table: `The Nook`/`the nook`/`Thé  Nook` → `the-nook`; `三階` → `''`; `--a--` → `a`; `Unassigned` → reserved | PHP and JS copies agree on every row; a row changed in one copy reds the lockstep test |
| T12 | the reader's and the write's refusals (§ 5.1) | at load: documents with `label`; no `name`; two floors slugging alike; alias = another floor's slug; alias in two floors; name `unassigned`; room `unassigned`. At the write: a save and a restore placing a room retired since; a raw save naming B with the slug still in A's aliases | each refused with a message naming the member and the floors; `{"floors": []}` and a document with `name`+`aliases` accepted; the retired-room document is refused by the write and, planted into the store directly, is **loaded** (no load refusal exists for it — the plant that reds a reader guard with no route) |
| T13 | rename redirects in one hop; a take-over moves the address in one revision; precedence; both races refused (§ 5.3) | rename `The Nook`→`The Den`→`The Burrow` via the console; create floor `The Nook`; name a floor `sola` while room `sola` sits on another floor; run the two mid-rename orders of § 5.3 with the second writer blocked on the lock | `aliases = ['the-nook','the-den']` on one entry; `/floor/the-nook` → the headless frame's `redirect` is `the-burrow`, `/floor/the-nook/aimla-pm` keeps the seat; after the create, **one** new revision, **one** outbox row, `the-nook` absent from The Burrow's aliases and current on the new floor, `/floor/the-nook` opens it; `/floor/sola` opens the floor named `sola`, not room `sola`'s floor; each race's second writer refused naming the first's floor, and the layout holds exactly the first's write |
| T14 | the migration changes no pixel (§ 6.3) | a store seeded from § 0's readings; run `migrate` | both composers' output before vs after: same floor set in the same order under `label`→`name`, same rooms, same themes, `aliases` exactly `{the-burrow: [solo-agents], the-treehouse: [treehouse], the-nook: [nook]}`; `installs` gained exactly `treehouse`, `nook`; every seat's `install_ref` unchanged; the snapshot's seat set and `install_id`s unchanged |
| T15 | the migration refuses its preconditions (§ 6.1, § 6.2 step 1) | seed two live seats `q` under two installs; separately a label `sola` with install `sola` unplaced | `migrate` exits non-zero naming `q` and both installs — and, separately, both floors that would slug `sola`; schema unchanged (`SHOW CREATE TABLE seats` equal) |
| T16 | the token file v3, and v2 still installs; v3 takes v2's path (§ 8.1) | the page's blob through `parseTokenFile`; a v2 fixture; a v3 with a fourth key; a v3 file against a **two-site** config whose second site matches its URL, with `--token-file` and no `--site` | v3 parsed `{version: 3, seat_id}`; v2 parsed with `install_id` discarded by the writer (the written config has no `install_id`); the fourth key refused by name; the rotation writes the **matching** site's token and the default site's is byte-identical before and after (a build that routes v3 through the v1 branch reds here) |
| T17 | the envelope omits `install_id` when the config has none, sends it when it has (§ 8.1) | two configs | `REQUEST_KEYS` presence per batch; a server fixture of the pre-13117 validator refuses the omitting batch — the record that the window exists |
| T18 | `--config` selects the file and fails loud (§ 8.3) | hook with `--config /nope`; hook with `--config <seat b>`; hook with no flag; on `win32`, a quoted path with a space | first: `config_readable` fail, nothing spooled anywhere, no fall-back to the default spool (the RED: a build that falls back spools into the default); second spools into b's spool; third into the default; the quoted path parses and spools |
| T19 | add a seat touches nothing of the first (§ 8.5–8.6) | an account with the default seat; add seat `b` with `--claude-config-dir`; an account with **no** seat and `CLAUDE_CONFIG_DIR=~/.claude-a` | byte-identical `config.json`, `~/.claude/settings.json`, default spool and supervisor entry before and after; `<dir>/settings.json` holds hooks with `--config …/seats/b/config.json`; a second add into the same dir for seat `c` refused; the seat-less account's first seat lands under `seats/<id>/` with a suffixed label and `~/.claude` untouched |
| T20 | the two-rooms notice is gone (§ 3.3) | the test that planted a seat in two rooms | deleted with its fixture, and the drill-down resolves a seat over the floor's rooms to one desk |
| T21 | one seat-identity primitive, every site migrated (§ 3.6) | an unassigned seat `u` with a token: revoke its prefix; move it (delta); `mezzanine:retire --seat=u`; a species allocation after it; the tokens listing; `mezzanine:rebuild --seat=u`; `mezzanine:seat-board-user --seat=u`; `LinkPass`'s label; **and the grep leg**: `grep -rn "join('installs'" server/app` | revoked; one `seat.delta`; retired; `u`'s species counted in the live population; `u` listed; rebuilt; linked; the label `u`; the grep prints nothing — a build that leaves one inner join reds on the leg that site serves |
| T22 | `amend()` and the compare-and-swap (§ 5.5) | two console actions on one base, the second blocked on the lock; a textarea save with a stale `base_layout_version` | the second action reads the first's result and either applies cleanly or is refused naming the floor; the stale textarea save refused naming both revisions, the store unchanged |
| T23 | the key primitive carries the room for a fleet-scope post (§ 3.4) | `coord-model.js edgesOf()` over a fleet-scope round whose origin and traveller seats sit in two rooms, keyed by `seat_id` | A22 edges carry each seat's held `install_id` as its room; a build that splits the key on `/` yields the whole seat id as the room (the RED) |

## 10. The slice plan, for the coder

Each slice is one PR to `dev`, `[no-close]` until the last of each card, each with its docs in the same round
(§ 14's rows for that slice), a `docs/changelog.d/<card>[-<slug>].md` note, and shippable alone.

| Slice | Content | Closes |
|---|---|---|
| **S1 — the seat alone, on the server** | § 6.2's migration, steps 1 (less the layout validation), 2, 3, 4; `App\Read\SeatIdentity` and **every** `join('installs'` site migrated (§ 3.6); `SeatTokens::issue(seatId, by)` with § 3.1; `TokenResolver`/`TokenBinding`/`BatchValidator`/`EventValidator` per § 3.2; the single-segment REST routes added, the two-segment kept, `api_version` 1 (§ 3.3); `Snapshot` by seat with the `unassigned` group; the reserved key refused at the layout write and load and in `FloorInventory` — **no change to either composer** (§ 4.4); console token form (seat id only, the `datalist` of seats), retire route, compose route, CLI signatures (`ingest-token:issue <seat_id>`, `retire --seat=`, `seat-board-user --seat=`, `coord-hook:issue --scope=room --room=`); client fetch paths to the new routes (key still the pair — stable until S3, since no move exists); the § 7 declaration posted before merge. ⚠ **Between S1 and S3 no room can be created**: token issue no longer mints one and the Rooms module is S3's; a seat issued meanwhile lands in Unassigned and is placed once S3 lands, and nothing existing regresses — stated so the slice is read as shippable rather than complete. Tests T1–T7, T15 (first half), T20, T21. Docs: D1 § 3.3, § 4.2, § 4.3, § 12.1, § 12.2; D2 § 6.4, § 8.1 (the declaration), § 8.2, § 8.2.1's binding paragraph, § 8.3.3 rule 1; D3 § 4.4 (the notice); D4 § 4.1; D5 § 3.2; `README.md § The admin console` | — |
| **S2 — the client keys by seat** | § 3.4: `wire/seat-key.js`, every inline pair key and every split site, `seatKey()`, D3 § 3.2's hash and its published table, `Draw`, `CharacterAllocation`; `feed_version` bump. T8's client half, T9, T23. Docs: D3 § 3.1 (desk row), § 3.2, § 10.2, § 13 row 6 | — |
| **S3 — rooms in the console** | § 4.1's `display_name`/`retired_at` writers (with the hook refusal); `install_id` in `versionBearing()`; the Rooms module through `Layouts::amend()` (the primitive lands here, with the textarea's compare-and-swap, § 5.5); the Agents index's *Room* control and the move transaction (§ 4.2) with its warnings; T8's server half, T10, T22. Docs: D2 § 4.10 (the move beside retirement), § 6.5 (the member), § 6.11 (*an install* → *a room*; `amend()`); `README.md` | — |
| **S4 — floors by name** | § 5.1–5.4: `FloorSlug` (PHP+JS, lockstep test), the reader's new members and refusals, the precedence in the client resolver and the URL canonicalisation, both composers by slug with the Unassigned floor and the `compose-cases.json` cases (§ 4.4), `GET /api/building`'s `name`/`aliases`, directory/cab/building view reading `name`; § 6.2 step 1's layout validation and step 5; the take-over as an `amend()` closure. T11–T14, T15 (second half). Docs: D3 § 3.1 (floor row), § 4.1, § 4.4, § 4.6, § 9 F17, § 13 rows 24/25/27, the card#9273/card#9267 sweep (§ 5.6); D2 § 6.11, § 8.7's worked JSON | — |
| **S5 — the console's floors module** | § 5.5's routes and views over `amend()`. T13's console half. Docs: `README.md`, D3 Appendix B row | **card#13117** |
| **S6 — token file v3 and the optional `install_id`** | § 8.1 on the page, the installer (every `version === 2` branch) and the reporter. T16, T17. Docs: D1 § 3.1 (both rows), § 4.2; D6 § 4.2; `INSTALL.md § The token file`; `UPDATES.md` | — |
| **S7 — several seats on one account** | § 8.2–8.6: `--config` on `hook`/`statusline` (quoted on `win32`), `parseReporterCommand`, per-seat paths by profile, supervisor entries, `add`/`rotate`/`uninstall`/`selftest`/`holder` per seat, the profile checklist; the first macOS install as the measurement of § 8.7. T18, T19. Docs: D1 § 2.1, § 3.1, § 11.1; D6 § 3.1, § 3.4, § 3.6; `INSTALL.md` (a new section *Several seats on one account* with § 8.3's table); `AGENT-INSTALL.md` | **card#13118** |
| **S8 — the snapshot's `api_version` 2** (gated) | the bump, the removal of the two-segment routes, D2 § 8.1's row; opened only on the bridge owner's word (§ 3.3) | — |

Order: S1 → S2 → S3 → S4 → S5; S6 after S1; S7 after S6; S8 when gated open. S2 must precede S3, because a move
re-keys; S3 precedes S4 because the take-over and the materialisation write through `amend()`.

## 11. What could falsify it, and what is not defended

- **A live duplicate `seat_id` on a deployment other than the sandbox.** The migration refuses and names it (§ 6.1).
  Not defended: choosing which to retire.
- **A reporter whose version predates `seat_id` on the envelope.** D1 § 4.2 has carried it since v1; no such
  reporter exists (`fleet-reporter/UPDATES.md`'s first row). Not defended.
- **The bridge's idle nudge after S1** (§ 3.3, § 7): a room rename or retirement under `BRIDGE_IDLE_NUDGE_INSTALL`
  is loud on that end; a seat moved out of the group or left in `unassigned` is silent there. Defended only by the
  declaration; closed only by that owner.
- **An operator who moves a PM between rooms and breaks a team repository's `pm` resolution** (§ 4.3). Defended
  only by the console's warning; the posts render as *unresolved*, which is the honest render the join already has.
- **Two agents launched under one `CLAUDE_CONFIG_DIR`** report as one seat (§ 8.3's named hazard). Not defended:
  the server cannot see the launch.
- **An agent launched without its `CLAUDE_CONFIG_DIR`** reports as the first seat. Same.
- **`CLAUDE_CONFIG_DIR` hooks behaving differently from `~/.claude` hooks** (§ 8.7). If the first macOS install
  finds a difference, § 8.3's route is wrong and the rejected env route is re-weighed with the trust hazard priced.
- **A floor name in a non-Latin script** is refused (§ 5.2). Falsifier: the operator wants one; the remedy is a
  transliteration policy this design declines to invent.
- **A take-over that the operator did not mean**: the console names the holder before the save; the raw editor
  refuses the inconsistent document; a consistent raw document is the operator's own hand (§ 5.3).
- **A raw-JSON layout edit** that renames without an alias, deletes a floor holding rooms, or places a room on no
  floor: each has a stated render (no redirect; *no such floor*; Unassigned from S4) and none is refused — the
  textarea is the operator's own hand, now under a compare-and-swap.
- **Unassigned seats on a floor of the shipped default** with more seats than its `S`: D3 § 3.2's overflow row,
  as any room.
- **The `kanban`/`mezzanine` empty floors persist after migration** until the operator retires them (§ 6.3): a
  no-change migration, by design.
- **A species allocated against no floor** (§ 13 Q6) can duplicate a floor-mate's body after placement — undecided,
  and named rather than built either way.

## 12. Seat rulings — reversible by the operator, each one rule

| # | Ruling | Alternative | Cost of the ruling |
|---|---|---|---|
| R1 | a new seat is created in **no room**; the token form offers no room | a room select at issue | ruling 3 says Unassigned; one fewer path to the seat's room |
| R2 | a room may exist on no floor and is drawn in Unassigned (from S4) | a room must name a floor at creation | a floor removed under a room by the raw editor would otherwise be a refusal in the reader, which D3 § 9 F17 shows takes the building down |
| R3 | the migration materialises every implicit floor, empty ones included | drop installs with no live seat | the migration changes no pixel; the cleanup is the operator's act with a revision |
| R4 | `aliases` is a member of the floor's entry, console-written, raw-editable | a hidden floor id and a slug table | a raw rename without an alias has no redirect |
| R5 | a room move renders as a removal from the old room and an insert in the new, no walk | a walk to the elevator and out of the other room's doors | the honesty rule: the seat did not go anywhere; the operator moved it |
| R6 | one flusher process per seat, one supervisor entry per seat | one flusher with a lane per seat | N nappers instead of one |
| R7 | the hook command carries `--config`; the profile is the agent's identity (operator Q4 confirmed) | project-scoped `env` | an agent launched without its dir reports as the first seat |
| R8 | the snapshot stays `api_version` 1 and the bump is gated on the bridge owner's word | bump now with a clocked window | a meaning drift the consumer reads under the old number; declared, not versioned, until the gate opens |
| R9 | the installer provisions a profile's hooks only and prints the checklist | the installer copies settings, CLAUDE.md and plugins between profiles | the operator provisions each profile by hand once |

## 13. Operator questions — answered, and open

**Answered 2026-11-10 (card comment 16717), folded into this revision:** Q1 the one-time re-slot and re-roll is
accepted (§ 3.4); Q2 **reversed** — a new floor takes over a former address, delete-first (§ 5.3); Q3 the names
`install_id`/`installs` stay (§ 2.1); Q4 `CLAUDE_CONFIG_DIR` per agent (§ 8.2); Q5 the pre-13117 restore cost is
accepted (§ 6.5).

**Open — each with options and a recommendation; neither is built until decided:**

**Q6 — a new seat's species, with no room to check against (D2 § 6.13 rule 2).** Species are allocated once, at
issue, and rule 2 — *a species no live seat on its FLOOR holds* — needs a floor. Under ruling 3 a new seat has
none, so at issue rule 2 is vacuous and only rule 1 (fleet-unique while species remain) and rule 3 (least-used)
apply; when the operator then places the seat, its body may match a floor-mate's — the duplicate card#12443 was
opened on. *Example:* the fleet holds more live seats than species; `timothy` is issued, draws the least-used
species fleet-wide, a hedgehog, and is placed in a room whose `device` is a hedgehog.
(a) **Allocate at issue against the fleet only; accept the floor duplicate after placement.** Nothing new;
D2 § 6.13 rule 2 is amended to *the seat's floor, or nothing while it has none*, and rule 4 (*kept for life*) holds.
Reachable only once rule 1 has no answer — more live seats than `SPECIES_KEYS.length` — as today's § 6.13 already
notes for an install placed after its first issue.
(b) **Allocate at the first placement instead of at issue.** An unassigned seat draws its body by the generator's
own pure draw (the pre-12443 `speciesOf`) until placed, then is allocated once against its floor and keeps that
for life. One body change per seat, at a moment the operator is looking at it; rule 4 moves from *at creation* to
*at first placement*; the Unassigned floor's bodies may repeat among themselves.
(c) **Re-allocate on a move when the destination floor already holds the body and a free species exists.** Keeps
floors distinct through every move; breaks rule 4 (a body changes on a console act, not only once).
**Recommendation: (a)** — it changes no mechanism and is reachable only past the species list's length; (b) is the
one to take if the operator weighs *unlike every other seat on its floor* above *the same body from the first
minute*.

**Q7 — a drill-down link whose floor address was taken over (§ 5.3).** `/floor/the-nook/aimla-pm` was saved while
The Nook held `aimla-pm`'s room; The Nook is renamed The Den and a new floor takes `the-nook`. Today's rule
redirects on the floor segment alone, so the link opens the new floor and reads *no desk `aimla-pm` on this floor*.
`seat_id` is unique site-wide from S1, so the client could resolve the seat first.
(a) **The seat wins:** a drill-down link resolves the seat over the whole building and redirects to the floor that
holds it, whatever the floor segment says — `/floor/<the seat's floor>/aimla-pm`. One lookup over the held seats;
the floor segment of a drill-down link becomes advisory.
(b) **The floor wins:** today's rule; the link opens the named floor and the notice says the seat is not there, as
it does for any wrong floor segment.
**Recommendation: (a)** — a link that names a desk was saved to reach that desk, and nothing else on the page
resolves to a seat by name without reaching it. Cost: a floor segment that disagrees with the seat is corrected
silently, which the client's event log records as a line.

## 14. Amendments owed to the ratified documents — the diff list

Each row is a diff to the owning document, landed in the slice named, with the reason attached in the amendment's
own ⭐/⚠ note (card#13117, or card#13118 for § 8's rows). Nothing here is a new document.

| Document § | Change | Slice |
|---|---|---|
| D1 § 3.1 | `install_id` row: optional since card#13117, not read by a 13117-era server; the token-file paragraph gains v3; `Sites` subsection: the identity is `seat_id` | S6 |
| D1 § 2.1 | the hook contract: argv[2] unchanged, `--config <path>` as argv[3–4], quoted on `win32`; the platform table's per-seat rows | S7 |
| D1 § 3.3 | *the token row binds exactly one seat*; the binding rule over `seat_id`; `identity_mismatch` carries `expected_seat_id` | S1 |
| D1 § 4.2, § 4.3, § 12.1 step 7 and 9, § 12.2 | `install_id` optional and not read, no shape check; the error's fields | S1 |
| D1 § 11.1 | the per-seat spool root | S7 |
| D1 § 18.3.1, § 18.6/18.7 | `scope: "room"`; the binding names a room | S1 |
| D2 § 4.10 | the move as the second operator act beside retirement, and the retired-id rule site-wide | S3 |
| D2 § 6.4 | DDL: `installs` comment, `install_ref NULL`, `ix_seat_install`, `live_seat_id`/`uq_live_seat`, `uq_seat` gone, `coord_hooks.scope` enum | S1 |
| D2 § 6.5 | `install_id` version-bearing by the subtraction | S3 |
| D2 § 6.11, § 8.7 | *an install* → *a room*; `Layouts::amend()` and the compare-and-swap as the write path; the worked JSON with `name`/`aliases` | S3, S4 |
| D2 § 6.13 | the allocation's preference key; rule 2 per Q6's answer | S2 |
| D2 § 8.1, § 8.2 | the snapshot's additive change under `api_version` 1 and the gated bump; the routes by seat beside the two-segment ones; the `unassigned` group; the declaration to the bridge owner recorded | S1 |
| D2 § 8.2.1 | the binding paragraph: `seat_id` alone is the identity; `install_id` is the room, a mutable member; *uniqueness is site-wide among live seats* | S1 |
| D2 § 8.3, § 8.3.3 rule 1 | `seat.delta` on a move; the room arm | S1, S3 |
| D2 § 13 | a row for this card's reversal of row 38's *(an install)* wording; row 50's *per install* → *per room* | S3 |
| D3 § 3.1 | the keys table: desk = `seat_id`; room row; floor row = slug of the name; the ⭐ reversal note | S2, S4 |
| D3 § 3.2, § 10.2 | the hash over `seat_id`; the published `h` table | S2 |
| D3 § 3.4, § 3.5 | the move's render beside the first appearance and the removal | S3 |
| D3 § 4.1, § 4.2 | `name` where `label` was | S4 |
| D3 § 4.4 | routes: slug, the precedence (current slug > alias > room key), the two-rooms notice deleted; Q7's answer | S1, S4 |
| D3 § 4.6 | `name`, `aliases`, the reader's refusals, the take-over, the implicit floor → Unassigned, the *no authored id* paragraph's reversal | S4 |
| D3 § 9 | F17's unclaimed rooms → Unassigned | S4 |
| D3 § 13 rows 6, 24, 25, 27 | reversed or narrowed, each in place | S2, S4 |
| D3 Appendix B | rows for the Rooms module, the Floors module | S3, S5 |
| D4 § 4.1 | `--seat=<seat_id>` | S1 |
| D5 § 3.1, § 3.2 | `--scope=room --room=`; the binding names a room | S1 |
| D6 § 3.1, § 3.4, § 3.6, § 4.2 | v3; `--claude-config-dir`; per-seat supervision by profile; the add-seat flow; the profile checklist | S6, S7 |
| `fleet-reporter/INSTALL.md`, `AGENT-INSTALL.md`, `UPDATES.md` | the token file's three forms; *Several seats on one account* with § 8.3's profile table; the agent's prompt for the config dir | S6, S7 |
| `README.md § The admin console` | rooms, floors by name, seat-only tokens | S1, S3, S5 |

The sweep of every `card#9273`/`card#9267` site (§ 5.6) is re-derived by grep at S4 and is not enumerated here.

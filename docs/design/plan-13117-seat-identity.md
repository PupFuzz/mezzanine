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

**Revision 3 (2026-11-10)** folds design review round 2 (`design-review-r2.md`) under the seat ruling of card
comment 16728: for the migration and for every population this document had enumerated by hand — the
`join('installs')` sites, the client's key sites, the supervisor entries — it now states the **property** and the
**check that goes red**, and no longer the exact statements or the site list; the exact DDL and the exact sites are
the coder's, found by executing the check, and each slice's card record shows the check seen red once. Applied in
§ 3.4, § 3.6, § 6.2, § 8.4 and T15/T19/T21/T23. The majors: the per-seat supervision reverses the recorded argv
decision and says so (§ 8.3), with the drain property and its check (§ 8.4); only **reported** unplaced installs are
materialised, and the migration changes the floors' order and numerals, stated (§ 6.3, R3, T14). The minors are
folded where they stand; T17's decoration leg is deleted. Q6 and Q7 stay open.

**Revision 4 (2026-11-10)** folds design review round 3 (`design-review-r3.md`), the last round of the bound, under
the same ruling and the at-bound decision of card comment 16735 (a narrow verification pass follows, not a fourth
round). The blocker: every timeline route is session-only, the new route named in the gate's list, with a token leg
seen red (§ 3.3, T6). The majors: the S4 materialisation excludes the virtual install `unassigned`, and the
*empty on migration day* claim is corrected (§ 6.3, M-1); S1's `down()` re-homes seats issued after S1 into a legacy
install row rather than refusing (§ 6.5, M-2, the choice and its reason); § 8.1's reporter line list becomes a
property — *no reporter path requires `install_id`* — with a restart-with-pending-request check that is red on
today's `readableRequest()` (M-3, T17). The minors are folded where they stand, and a sweep converted the remaining
hand lists — the installer's token-file branches, the route population, the client roots — to a property and a
check each (§ 8.1, § 3.3, § 3.4). Q6 and Q7 stay open.

**Revision 5 (2026-11-10)** folds the narrow verification of revision 4 (`design-verify-r4.md`): the legacy
`unassigned` install row is **never deleted** — `up()` refuses first, by name, an unrevoked hook or a current map
keyed to it, then re-homes its seats and leaves the row inert for `down()` to reuse; § 6.5 walks the sequence state
by state (MAJOR-1). The minors: T16/T17 isolate their red from today's earlier refusals; T14 with an unplaced seat;
the reporter's grep leg; the installer population as `readTokenFile(` call sites; a refused S1 precondition is a
rollback too; T6 selects routes by action and T23 derives its roots from what the pages load. The S1-after-S6
rollback residual is accepted in § 11 with its reason. Q6 and Q7 stay open.

**Revision 6 (2026-11-10)** folds the operator's last answers: Q6 (a), Q7 (b) — the floor wins, keep the code
simple — the per-profile cost kept, and the design approved (§ 13).

**Design approved by the operator, 2026-11-10.** The operator's direction, verbatim from the card (`kbcard show --task 13117`):

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
| (f) the feed's tenancy key — a sixth job the card did not list (round 3's m-L) | `feed_outbox.install_id`, the per-subscriber filter's attachment point: `FeedStream::admits()` (`FeedStream.php:151-154`) admits everything today and the comment reserves the column for the ACL D2 § 9 and § 14 item 7 defer *until a second organisation's install reports in* |

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
| (f) feed tenancy key | the **room key** on every seat-scoped outbox row, `unassigned` for a roomless seat's, `NULL` on a fleet-wide row as today — so the ACL D2 § 9 defers attaches to the room when it is built, and a seat's move changes the tenancy its rows carry from the delta that announces it. Nothing is built: the filter stays all-or-nothing (operator ruling 2026-09-13, D2 § 14 item 7); D2 § 9's deferred ACL is amended to say *room* where it says *install* (§ 14) |

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
| no row | create the seat with `install_ref NULL` (§ 4.4), allocate its species (§ 13 Q6: rule 2 is the seat's floor, or nothing while it has none), insert the token |
| a live row | **re-issue** for that seat (rotation, D1 § 3.3's order) — today's behaviour, minus the install segment |
| a retired row | **refused**, `SeatTokenRefusal::seatRetired` — today's refusal, now site-wide: the console's *there is no un-retire — a replacement seat takes a new seat id* (`SeatTokenController.php`) |
| a live row **and** a retired row of the same id (reachable on a store migrated from a per-install namespace: `x` live under one install, `x` retired under another — § 6.1's precondition checks live pairs only) | **the live row wins**: a re-issue for the live seat, as the row above; refused only when every row of the id is retired (round 3's m-J). T1 plants it |

**Backstop in the store.** `uq_seat (install_ref, seat_id)` goes — with `install_ref` nullable it would admit two
unassigned seats of one id, since MariaDB treats NULLs as distinct in a unique key — replaced by a generated column
`live_seat_id VARCHAR(48) AS (IF(retired_at IS NULL, seat_id, NULL)) STORED` with `UNIQUE KEY uq_live_seat
(live_seat_id)` (⭐ **STORED, corrected at S1's build**: this paragraph said VIRTUAL, and a VIRTUAL column on
`seats` makes MariaDB 11.8.6 refuse every later `ADD COLUMN … ALGORITHM=INSTANT` on the table — `ERROR 1846`,
which this repository's own board-link migration spells, and which its test replays over the current schema;
a STORED column admits it, and `coord_hooks.active_key` is STORED for its key already). MariaDB indexes a `VIRTUAL` generated column and names a unique index over one its *"poor man's
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
  words, because its reader keys on it twice: the group-presence check above, and a **per-seat filter** —
  `~/agent-webhook-bridge/app/Bridge/IdleNudge/IdleNudgeEvaluator.php:103` counts a seat whose `install_id` differs from `BRIDGE_IDLE_NUDGE_INSTALL` as
  *foreign* and skips it. A room key is **immutable** (§ 4.1; only the room's display name changes), so no rename
  can break the check; a **retirement** of that room makes every nudge *unmeasured* (its own fail-closed answer,
  loud); and a **move** of the bridge's seat into another room, or a new seat of that team left in `unassigned`,
  makes that seat *foreign* to the evaluator **without any refusal** — the silent case, which only the owner can
  close, by matching on `seat_id` (unique site-wide from S1) rather than on the group.
- **The bump to 2 and the removal of the two-segment routes are gated, not clocked**: they land in a slice of their
  own once the bridge's owner declares its reader moved — D1 § 3.3's *the overlap ends on a gate rather than on a
  clock* — and until then this document plans neither.

| Today | After S1 | Posture |
|---|---|---|
| `GET /api/fleet/seats/{install_id}/{seat_id}` and `…/timeline` | **kept**, resolving by `{seat_id}` alone with `{install_id}` ignored (any slug); **added**: `GET /api/fleet/seats/{seat_id}` and `GET /api/fleet/seats/{seat_id}/timeline`, `where('seat_id', Slug::SEAT_ID)`. ⛔ **Both timeline routes are session-only** (round 3's B-1): D2 § 9 refuses an `mzr_` token on the timeline, and `App\Http\Middleware\FleetReadGate` enforces that **by route name** — `SESSION_ONLY_ROUTES = ['fleet.timeline', 'building', 'building.room_map']` (`FleetReadGate.php:89`) — so a new timeline route under a new name is, by default, open to a machine token. **The property:** every route that serves a seat's timeline refuses an `mzr_` token `401` exactly as `fleet.timeline` does. **The check (T6's token leg):** an `mzr_` token presented to each timeline route is refused — seen red on a build whose new route name is not in the list (it answers `200`). Closing routes by default — a route attribute the gate reads instead of a name list — is a later hardening this design names and does not build. ⚠ **Registration order:** the new timeline route is registered **before** the two-segment seat route, since `/api/fleet/seats/x/timeline` matches both shapes — Laravel takes the first registered — so a seat's timeline wins over a seat named `timeline` under an install named `x`, which is the reading the new contract intends; a seat literally named `timeline` reaches its own timeline at `/api/fleet/seats/timeline/timeline` by the same order | **REST, machine consumers** (D2 § 8.1 row 1): additive; `api_version` 1 |
| `GET /api/fleet/snapshot` `installs[].install_id` | the same member, now the **room key**, plus the group `unassigned` when any live seat is in no room; `seats[]` members unchanged | additive on the wire; the drift declared (§ 7) |
| `POST /desk/{install_id}/{seat_id}/compose` | `POST /desk/{seat_id}/compose` | browser-only (`web`, CSRF) — no window |
| `POST /admin/agents/{install_id}/{seat_id}/retire`, `/admin/floors/{install_id}/…` | `/admin/agents/{seat_id}/retire`; the floors (room-map) module keeps `{install_id}` as the **room key** segment | console, no window |
| `/floor/{floor}/{seat_id}` | unchanged shape; `{floor}` is a slug from S4 (§ 5.4); the seat is resolved over the floor's rooms as today, and the *names a desk in more than one room* notice (D3 § 4.4) is **deleted** — § 3.1 makes the state unreachable among rendered seats, and canon #6 deletes a guard with no route | browser |
| `/api/building/rooms/{install_id}/map` | unchanged: the segment is the room key | browser-only |

**The route population, as a property rather than this table alone:** after S1 no route addresses a seat by a pair
of segments except the two kept two-segment REST routes, and no route in `routes/web.php`, `routes/admin.php` or
`routes/coord.php` carries `{install_id}` beside `{seat_id}`. **The check:** a leg over `php artisan route:list
--json` that goes red on any route whose URI holds both an `install_id` and a `seat_id` parameter outside the kept
pair, **seen red on today's tree** (the compose and retire routes). The table above is the contract; the leg is what
keeps it the whole population.

### 3.4 The client's key, the desk slot hash and the character seed

The desk key becomes `seat_id`. **One key primitive, `wire/seat-key.js`**, exports the only two functions that may
touch a key: `keyOf(seat)` → `seat.seat_id`; `roomOf(key, held)` → the held seat object's `install_id` (the room the
seat is drawn in; `unassigned` for none).

**The property:** no module the pages load — **under `server/public/js` and under `resources/`**, where the floor's
art and the character tree live and are imported from (round 3's m-B: `resources/floor/themes/cottage/theme.js`'s
`deskKey` and `resources/characters/index.js`'s `seatKey` are two such *examples*) — other than `wire/seat-key.js`
builds a desk key from its parts or takes a key apart. **The check (T23):** a grep leg over both trees that goes red on any old-shape
site — a template or concatenation joining an `install_id` and a `seat_id` with `/`, a `split('/')` or `indexOf('/')`
over a key, a `splitKey` import — **seen red once on a planted site** before the slice's sites are migrated, and green
after; the leg's exact pattern is the coder's, proven by the plant, and this document names none (round 1's M9 and
round 2's M-a were two hand-written patterns that each missed sites). *Examples* of the shapes the leg must catch,
not a list: `fleet-client.js`'s `key(installId, seatId)`; a painter's `${desk.install_id}/${desk.seat_id}` cache
key; `floor-compose.js`'s `splitKey()` and its callers; `coord-model.js`'s `roomOf = (key) => key.split('/')[0]`,
which places a fleet-scope coordination animation in the room of a key's first half and becomes `roomOf(key, held)`
over the seats the client holds, because the room is no longer in the key. The seat object still carries
`install_id`: it is now **the room the seat is drawn in**, a mutable member like `render_state`, and a `seat.delta`
whose `changed[]` names it is **a room move** (§ 4.2).

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
`installs`, and revision 1 converted three of them.

**The primitive: `App\Read\SeatIdentity`** — the one query that names a seat: `seats LEFT JOIN installs` selecting
`seats.id AS seat_ref, seats.seat_id, COALESCE(installs.install_id, 'unassigned') AS install_id, seats.species,
seats.retired_at, seats.retired_by, seats.retired_reason`, with `byId(string $seatId)` (live by default; `retired()`
for the console's retired table), `byRef(int $seatRef)`, and `query()` for the callers that add their own joins.
`SeatRow::find()` becomes a caller of it.

**The property:** after S1 no query in `server/app` reaches `installs` from `seats` except through `SeatIdentity`,
so an unassigned seat is found by every surface a placed one is. **The check (T21), two legs:** a **behavioural**
leg that drives an unassigned seat through every act a seat can be the subject of — token revoke, a state delta, a
retirement, a species allocation, the tokens listing, a rebuild, the board link, the coordination log label — and
asserts each one finds it; and a **grep leg** over `server/app` that goes red on any inner join from `seats` to
`installs` left outside the primitive, **seen red once** on the unmigrated tree. *Examples* of what a missed site
costs, not a list: a leaked token that cannot be revoked (`SeatTokens::revoke()`); a `seat.delta` that is never sent
(`SeatObject::forSeatRef()` answering `null`, which `Publisher::seatDelta()` reads as *no seat*); a retirement
refused as *no such seat*. The exact sites are what the grep leg prints on the day S1 is built.

## 4. Rooms

### 4.1 The store: `installs` is the rooms table

| Column | Today | After |
|---|---|---|
| `installs.install_id` | typed at token issue (`SeatTokens::issue()` inserts the install when missing) | the **room key**: typed once at room creation in the console, `Slug::INSTALL_ID`'s pattern, immutable; `unassigned` **reserved** (refused by name, § 4.4). It is the `subject` of the room's map revisions and the `floors.install_id` of its current map, which is why it is not a slug of the name: a rename must not orphan a map's history |
| `installs.display_name` | present, never read or written: `grep -rn display_name server/app server/resources` prints nothing; the column exists in the DDL (`2026_08_25_100000_create_fleet_store_tables.php`) alone | the room's **name**, free text, renamable, **bounded at 64 characters** — the column's own width (`string('display_name', 64)` in that migration; D2 § 6.4; ⭐ **characters, corrected at S3's build**: this cell said bytes, and a `utf8mb4` `VARCHAR(64)` holds 64 characters, which `RoomConsoleTest` stores whole), refused by name over it, as a floor's name is bounded by nothing but its document (§ 5.2); `null` reads as the key, as a floor's absent label did |
| `installs.retired_at` | present, never written (the same grep over `installs.*retired_at` in `server/app` prints nothing) | set by the console's **retire room**, under the room's `installs` row taken `FOR UPDATE` — **the one lock a move (§ 4.2) and a retirement share**, so a seat cannot be placed into a room in the act of retiring (round 2's m-3); refused by name while any live seat is in the room **or any unrevoked coordination hook is bound to it** (round 1's m8: `coord_hooks.install_ref`; revoke the hook first); the whole act runs **inside `Layouts::serialise()`'s lock** and decides there whether the current document places the room: placed → the same transaction removes it through `amend()` (one revision); unplaced → no revision, since `amend()` refuses a no-op (round 2's m-2) — and the decision is taken under the lock, never from the form's rendering, so a textarea save that places the room cannot slip between the check and the retirement (round 3's m-F; the property § 5.5 states for every decision about the document). So no stored layout places a retired room; a later save or restore that would place one is refused **at the write** by name (route: the raw JSON editor, a restore of an older revision) — never at load, because no load can meet one. A retired room is listed in the console only, and its map history stands |
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

**The move is one transaction and one announcement.** The destination room's `installs` row `FOR UPDATE` first
(the lock § 4.1's retirement takes, so the room cannot retire under the move), then `SeatLock::forUpdate()` (the
order card#12461 fixed), then `UPDATE seats SET install_ref = ?` and `StateRecompute::forSeat(…, 'operator', …)`. For that to emit anything,
**`install_id` joins `SeatFacts::versionBearing()`** (round 1's m1), read through `SeatIdentity::byRef()` — the
function's own `seats` read (`SeatFacts.php:188`) reaches no room, and an inner join there would be the very site
§ 3.6 forbids (round 3's m-H) — and D2 § 6.5's subtraction is what makes a member version-bearing — a desk drawn in
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
console cannot create), and **no console surface offers the reserved key as a room** — `FloorInventory`'s rows and
`renderedInstalls()` exclude it, so the room-map *create* form's `Rule::in(FloorInventory::renderedInstalls())`
(`FloorController.php:97`) refuses it rather than accepting the snapshot's virtual install as a room to author a
map for (round 3's m-I; T7 plants the form). ⚠ What S1 does **not**
do: touch the unplaced installs. `sola` keeps its implicit floor exactly as today; `kanban` and `mezzanine` have **no
floor today** — an implicit floor is minted per install the **snapshot** carries (`Building::compose()`'s input is
the snapshot's installs, `FloorInventory.php:72`; `floors()`'s is `snapshot.installs`), and an install whose every
seat is retired is in no snapshot. Revision 2 said they rendered as empty floors; they do not, and § 6.3 is corrected
with it.

**S4: the Unassigned floor gathers the unplaced that anything can draw.** With the implicit-floor rule retired
(§ 5.6), both composers mint **one** implicit floor, key `unassigned`, name *Unassigned*, house theme, sorted last and
present only when non-empty, whose rooms are the virtual room `unassigned` when the snapshot carries it, plus every
unplaced room that is **reported** (in the snapshot) **or authored** (in `GET /api/building`'s `rooms[]`, which
already lists every room with a current map, D2 § 8.7) — the population `FloorInventory` already defines
(`FloorInventory.php:26-27`: *the union of the installs the snapshot renders, the installs a map has been authored
for, and the rooms the layout places*), reused rather than minted, and the only population the browser can know
without a new member (round 2's m-4). A room that is neither — `kanban` and `mezzanine` on the sandbox: every seat
retired, no current map — is drawn nowhere, as today, and the console's rooms index is where it is listed and
retired. `compose-cases.json` gains the cases (reported unplaced room; authored unplaced room; neither-kind room →
absent; virtual room alone; both; nothing → no floor) and both composers are held to them. D3 § 9 F17's *unclaimed
rooms* directory is subsumed: an unplaced room is on this floor.

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
  drill-down link whose floor segment was taken over** opens the new floor, where the seat is not, and shows the
  ordinary *no such desk on this floor* notice. The floor wins (§ 13 Q7, operator 2026-11-10): the redirect stays
  on the floor segment alone, and a desk that moved is reached by its new link.
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

### 6.2 The store migration — its property, and the check that goes red

Revisions 1 and 2 each scripted the DDL from a recalled schema, and each round found the script wrong against the
engine (round 1: `DROP KEY uq_seat` alone meets `ERROR 1553`, the FK's index; round 2: an `UPDATE` to `'room'`
under `ENUM('install','fleet')` is `ERROR 1265` in strict mode, after which the half-applied table cannot be re-run —
`ERROR 1091` on the already-dropped key). Under the seat ruling (card comment 16728) this section states no
statement. **The exact DDL is the coder's, written against the engine and proven by the check below; each slice's
card record shows the check seen red.**

**What the migration must leave behind** (the end state, S1's share): `seats.install_ref` nullable with an index
the foreign key `fk_seat_install` can still stand on; `uq_seat` gone; the generated column `live_seat_id` with
`uq_live_seat` (§ 3.1); every seat's `install_ref` as it was; `coord_hooks.scope` holding `room` wherever it held
`install`, under an enum that admits `room` and `fleet`; an `installs` row for every room key the current layout
places (on the sandbox: `treehouse`, `nook`); nothing else changed. S4's share: the layout revision of § 6.3,
published as any save is.

**The property:**

1. **Every refusable step comes first, and refuses by name with nothing written**: a live duplicate `seat_id`
   (§ 6.1, listing each id with its installs); a stored layout that **places** a room keyed `unassigned` (round 3's
   m-K: the operator edits the layout first); an **unrevoked coordination hook** bound to an `installs` row keyed
   `unassigned` (revoke it first — `fk_hook_install` keeps every hook row, revoked ones included, pointing at the
   install); a **current room map** keyed `unassigned` (a `floors` row: remove it first, else § 4.4's shipped-default
   answer for the virtual room is false). An `installs` row of that key with neither is **not refused and never
   deleted**: its seats are re-homed to `install_ref NULL` and the row stays, inert — the legacy row § 6.5 walks;
   and — S4's share — the migrated layout of § 6.3 built and validated in memory by the new reader, which also catches a
   `label` that slugs like a reported unplaced install's materialised name (a label `sola` on a floor while
   install `sola` is unplaced and reported — round 1's m5), refused naming both floors with the remedy printed. The
   sandbox's four labels (§ 0) collide with none of its unplaced installs. The precedent is
   `2026_09_12_000100_create_the_authored_building_store.php`, which validates before any DDL.
2. **Every step is idempotent and guarded by a check of the schema** — `information_schema` for a column, an index
   or an enum's value set; a `WHERE` that matches nothing on a re-run for a data step; `insertOrIgnore` on
   `uq_install` for the minted rooms — so a step that already applied is skipped, never re-attempted and never
   failed.
3. **A re-run after a failure at any step succeeds** and reaches the end state above. DDL is not transactional on
   this engine, so this property is what makes the migration safe to run on a live store, and it is tested, not
   argued.
4. **Nothing is written to a row that is not the migration's**: no seat moves, no token changes, no revision is
   rewritten.

**The check (T15):** a test that builds **a copy of today's store shape** — the pre-13117 schema with the sandbox's
own kinds of rows: install-scope coordination hooks (`coord_hooks.scope = 'install'`), the two retired duplicate seat
pairs, installs with zero live seats, an install with an authored map, a layout placing rooms the store lacks — runs
the migration, **injects a failure after each step in turn, re-runs, and asserts the end state** is reached and
identical across every injection point; separately, that each precondition refuses with the schema unchanged
(`SHOW CREATE TABLE` equal before and after). **The injection points are the migration's own steps**: the migration
is written as an ordered list of named steps that the test iterates — one injection after each — so a step added
later is an injection point without anyone editing the test, and a hand list of points in the test is not the
denominator (round 3's m-M); the control is a step planted without its schema guard, which reds the re-run leg.
**Hazards the test must cover by name**, each a plant that reds a
naive sequence: the scope enum widened **before** the `UPDATE` and narrowed after it (an `UPDATE` first is `1265`; a
narrow before the update loses rows); the FK's index present **before** `uq_seat` is dropped (`1553`); a re-run
meeting an index already dropped or a column already added (`1091`, `1060`) and skipping by the schema check; the
generated column's expression admitting the two retired duplicates (the live-only key, § 3.1); the reserved key and
the live-duplicate refusals leaving the schema untouched. `down()` is held to the same property (§ 6.5).

S1's migration carries the preconditions less the layout validation and the S1 share of the end state; S4's carries
the layout validation and the layout revision.

### 6.3 The layout migration, worked on the sandbox's document

For each entry in the current document: `name` = `label` where present, else the floor's derived key;
`aliases` = `[derived key]` when `slug(name) ≠ derived key`, else `[]`. Then, for every **reported** install the
layout does not place — the installs the snapshot carries, which is exactly the set both composers draw an implicit
floor for today (§ 4.4) — **except the virtual install `unassigned`**, which the snapshot carries from the first seat
issued after S1 and which the S4 reader refuses as a placed room (round 3's M-1) — a new entry `{ name: <install_id>,
rooms: { <install_id>: { form: "open" } } }` is **materialised**. An unplaced install with no live seat (`kanban`, `mezzanine` on the sandbox) has no floor today and
gets none: it stays an unplaced room, listed in the console and drawn nowhere (round 2's M-c). The sandbox's
document becomes:

| Entry | `name` | `floor` (slug) | `aliases` | rooms |
|---|---|---|---|---|
| aimla | `aimla` | `aimla` | — | `aimla` (theme `cottage`, map rev 1) |
| The Burrow | `The Burrow` | `the-burrow` | `solo-agents` | `solo-agents` (map rev 3) |
| The Treehouse | `The Treehouse` | `the-treehouse` | `treehouse` | `treehouse` (room minted by the migration) |
| The Nook | `The Nook` | `the-nook` | `nook` | `nook` (minted) |
| sola (was implicit, reported) | `sola` | `sola` | — | `sola` |

**What the migration changes on the screen, stated:** every floor keeps its rooms, theme, map and name, so **no
floor's content changes** — but the directory and the cab sort floors by key, and the key moves from the install id
to the slug of the name, so **the order and the numerals change once**. Today's order (by key: `aimla`, `nook`,
`sola`, `solo-agents`, `treehouse`): 1 aimla · 2 The Nook · 3 sola · 4 The Burrow · 5 The Treehouse. After (by slug:
`aimla`, `sola`, `the-burrow`, `the-nook`, `the-treehouse`): 1 aimla · 2 sola · 3 The Burrow · 4 The Nook · 5 The
Treehouse. T14 asserts the set and the per-floor content equal and the order as the slug sort gives it; R3 says the
same.

`/floor/solo-agents` reaches The Burrow by two routes (alias, and the room redirect); `/floor/aimla/aimla-pm` is
unchanged. **Unassigned is empty on S1's deploy day** — every live seat has an `install_ref` then — and **on S4's
deploy day it holds exactly the seats issued since S1 and not yet placed** (S3's console lands between the two, so
the operator may have placed them all, or none): the migrated layout places none of them, the virtual install is
excluded from the materialisation above, and both composers draw them on the Unassigned floor. Revision 3 said
*empty on migration day* of both days; that was false of S4's. The two unreported rooms are drawn nowhere, as today.

### 6.4 What existing reporters send, and what the server does

Every reporter in the field keeps sending `install_id` on the envelope and on each event, and keeps its config.
Under § 3.2 neither is read; `seat_id` is matched to the token; the batch is accepted exactly as before. **No
reporter is touched; no token is reissued.** A reporter's `selftest` still prints `install_id · seat_id · url`
(`install.js:1890`) until § 8.1's installer lands — a stale label on the seat's own screen, named here, fixed there.

### 6.5 Rollback

**S1's `down()` re-homes the seats issued since S1, and does not refuse** (round 3's M-2 — revision 3 said *place or
retire them first*, which was impossible: placing arrives with S3, and a retirement does not set `install_ref`, so a
rollback would have been refused forever). The choice, and its reason: the two options were **forward-only once a
seat has been issued** — honest, but it makes the first slice irreversible from the first token issued under it,
which is the one deploy an operator most needs to be able to undo — and **re-homing**, chosen: `down()` ensures an
`installs` row keyed `unassigned` and sets every `NULL` `install_ref` to it before restoring `NOT NULL` and `uq_seat`.
Under the pre-S1 code `unassigned` is a legal install id, the snapshot groups those seats under it and both composers
draw them on an implicit floor `unassigned` — **the very floor the viewer saw before the rollback**, so nothing moves
on the screen. Its mirror in `up()` **re-homes the row's seats and never deletes the row** — revision 4 deleted it,
and the verification pass found `fk_hook_install` (`2026_10_24_000100_create_the_coordination_store.php:44`) blocks
that delete whenever a hook, revoked or not, was ever issued for the install: `ERROR 1451` on every re-run, a stuck
migration. So the row is the **inert legacy row**: no live seat, placed by no layout, offered by no console surface
(§ 4.4's reserved key), drawn nowhere by § 4.4's union, kept as the record it is and reused by the next `down()`.
Only a **layout that places** `unassigned`, an **unrevoked hook** bound to the row, or a **current map** keyed to it is
refused first, by name (§ 6.2). An install an operator literally named `unassigned` before this card is read the
same way.

**The sequence, state by state** (T15's rollback leg asserts each):

| Step | `installs` row `unassigned` | the seat issued after S1 | what a viewer sees |
|---|---|---|---|
| S1 `up()` on today's store | absent | — | today's building |
| a seat is issued | absent | `install_ref NULL`; the snapshot groups it under the virtual install | the implicit floor `unassigned` (S1) |
| S1 `down()` | **created** (or reused if a legacy row exists) | `install_ref` → that row; `NOT NULL` and `uq_seat` restored | the same floor, now a real install's |
| pre-S1 code runs | a legal install id | reports and renders under it; a token re-issue for it is a re-issue under that install | unchanged |
| S1 `up()` again | **kept**, seats re-homed to `NULL`; refused first if a hook was issued for it meanwhile and not revoked, or a map authored for it | `install_ref NULL` again | the implicit floor `unassigned` again — the row itself draws nothing |
| S1 `up()` re-run after a failure at any step | every step skipped or applied by its schema check; the row never deleted | as above | as above |

Both directions are idempotent and schema-guarded under § 6.2's property. T15's leg issues a seat after S1, runs
`down()`, asserts the row and the seat under it and the pre-S1 schema, runs `up()`, asserts `install_ref NULL` with
the row present; and plants a hook-bound row (refused until revoked) and a map-bound row (refused until removed).

**A refused precondition is a rollback, not a retry — for S1 as for S4** (round 2's m-6; the verification pass's
MINOR-5): the migration runs at deploy, so when it refuses, the new **code** is already on the host over the old
schema or document — S4's reader refusing the stored `label` document is F17 on every page; S1's code reading a
`live_seat_id` or a nullable `install_ref` the schema lacks fails every seat read. The remedy is the deploy's own
rollback to the previous build, then the act the refusal named (retire a duplicate, revoke a hook, remove a map,
edit a layout, rename a label), then the redeploy; the release note's *Upgrade warnings* say so for both slices,
and each precondition's message says so.

**Rolling S4's code back over a new-shape layout** (round 1's m6): the pre-S4 reader refuses `name` and `aliases`
as unknown members, which is D3 § 9 F17 on every page — the hazard D3 § 4.6's `theme` row already names for its
own rollback (*a rollback past row 21 first saves the layout without it*). S4's `down()` therefore writes a new
revision copying the last pre-migration revision's document (the one the migration's own revision followed; `restored_from`
set to it) with the statements `Layouts::write()` performs (`Layouts.php:314`, private to the class — the migration
issues them itself, inside `serialise()`'s lock: the revision insert, the current row's update, and the
`building.layout` outbox row that announces it, round 3's m-C), **bypassing the new reader's validation by design**: the
old reader is the one that will read it. It prints what is lost: every name and alias authored since, and every floor the operator removed
after the materialisation reappears as an implicit floor. **A pre-13117 revision is refused by the new reader**
(`label`, no `name`) — operator ruling Q5 accepts that cost: an operator restoring one exports it, edits `label` →
`name`, and saves it as a new revision.

## 7. Across the boundary (canon #7)

| Hop | Declares | Checks | Neither end can verify |
|---|---|---|---|
| **reporter → ingest, `install_id`** | D1 § 4.2's and § 4.3's amended rows (this repo publishes both ends) | T4: a batch with `install_id` absent, present-and-valid, present-and-foreign, present-and-malformed is accepted; one with a wrong `seat_id` is refused | that every reporter in the field is the version this repo last shipped — the heartbeat's `reporter_version` is the only reading, and D2 § 8.2.1's `reporter_outdated` badge is where it shows |
| **snapshot → the bridge's idle nudge** (kanban-solo's `agent-webhook-bridge`) | **this repository declares, on the roundtable and on card#13117, before S1 merges:** *From mezzanine vX.Y (card#13117 S1) `GET /api/fleet/snapshot` keeps `api_version: 1` and changes additively: `installs[].install_id` is the ROOM the admin console placed the seat in — a key that never changes, on a room that can be retired, holding a seat that can be moved — no longer the install the token was issued under; a group `unassigned` appears whenever a seat is in no room; `seat_id` is unique across the whole site. `BRIDGE_IDLE_NUDGE_INSTALL` therefore names a room key, and a seat moved out of that room or left in `unassigned` is *foreign* to your `IdleNudgeEvaluator` (`IdleNudgeEvaluator.php:103`) and outside the group your `FleetSnapshotReader` checks (`:136`, `:147-148`), with no refusal; your reader can select by `seat_id` alone from S1 on. The bump to `api_version` 2 and the removal of `/api/fleet/seats/{install_id}/{seat_id}` wait for your word that your reader has moved.* | T6 and T7 hold the shape: `api_version` 1, no member removed, the `unassigned` group a well-formed group; the declaration's text is the release note's *Upgrade warnings* | whether and when the bridge's reader moves; what `BRIDGE_IDLE_NUDGE_INSTALL` is set to on its host — the room `kanban`'s only live seat is in `solo-agents` on the sandbox (§ 0), which that end alone can read |
| **Seat-tokens page → installer, token file v3** (§ 8.1) | `fleet-reporter/INSTALL.md § The token file` and D1 § 3.1 state v1/v2/v3; the page says *needs installer ≥ <version>* beside the button | `install.selftest.py` drives the page's blob through the parser (D6's R-srv-1) | the installer version on the agent's machine; an old installer refuses a v3 file **by name** (*missing install_id*), which is loud, and the remedy is the page's own text |
| **coordination hook → room** (§ 4.3) | D2 § 8.3.3 rule 1 names the room arm | AT-D2-24's REDs re-planted on a room | that the team repository's roster is the room's seats — never verifiable from here (D1 § 18.3.1), and now also moved by a console edit; § 4.2's warning is what this end can do |
| **agent launch → seat** (§ 8.3) | `fleet-reporter/INSTALL.md § Several seats on one account` | the installer prints the config dir it wired, and `selftest --config` prints the seat it will report as | **how the agent was launched** — the server sees the seat whose hooks ran and nothing about the process that ran them |

## 8. The agent side — the token file, the config, and several seats on one account (card#13118)

### 8.1 Token file v3 and the config

**v3** is v2 minus the `install_id` line: exactly three `<key> <value>` lines — `token`, `ingest_url`, `seat_id` —
once each, any order, LF only, the same bounds (D1 § 3.1, `INSTALL.md § The three forms`). The *Seat tokens* page
writes **v3 only** from this card on (its `data-install-id` attribute goes; `seat-token.js` builds three lines).
`parseTokenFile` (`install.js:229-254`) reads v1, v2 and v3 and refuses anything else by name as today; a v2 file's
`install_id` is **read and discarded** (a 13117-era server never reads it), so a file saved before the deploy still
installs.

**The installer's property:** every subcommand that reads a token file's identity reads a v3 file as it reads a v2
file — the identity is `seat_id` alone, a v2 file's `install_id` is compared with nothing — and no subcommand falls
through to the v1 branch on a v3 file (which would demand `--site` and `--seat-id` and, on a two-site config, rotate
the **default** site with the file's token — round 1's M5). The population is **every call site of
`readTokenFile(`** in `install.js`, derived by grep at implementation, never from this document — revision 4 derived
it from the flag table and missed the `token-config` step, which reads the file from the config directory with no
flag at all (`install.js:1946`); the install, the rotation, `add-site` and that step are *examples*. **The check
(T16):** each call site driven with a v3 file — **red isolated from today's parser**: today `parseTokenFile()`
refuses a v3 file first for its missing line (`install.js:244`), so the leg's plant is a build whose parser alone
accepts v3 and whose branches still test `parsed.version === 2`, which falls to the v1 path. `--install-id` leaves
the flags and the account-slug default goes with it.

**The reporter's property** (round 3's M-3): **no reporter path requires `install_id`.** A config without it loads,
spools, heartbeats, sends and persists; the envelope and every event carry `install_id` **when the config has one and
omit it when it has not**; **a persisted request without it stays readable across a flusher restart**; the
`state.json` identity binding of an upgraded seat does not move; and the hooks gate on `seat_id` alone. Revision 3
listed the lines that read the key and missed the one that mattered: `readableRequest()` (`fleet-reporter.js:3549`,
`:3557`, `:3560`) holds the persisted request to the **exact** key set `REQUEST_KEYS`, `install_id` among them, and
refuses a request without it — so every flusher restart on a v3 seat would discard the pending batch and reset the
epoch. The population is every path that reads the key, found by the coder with the check, not by this list.
**The check (T17):** a v3 config, one pending persisted request, a flusher restart — the batch is resent, the epoch
unchanged — **red isolated from today's loader**: today `loadConfig()` refuses a config with no `install_id` outright
(`fleet-reporter.js:399`), so a v3 config never reaches the flusher; the plant is a build whose loader alone makes the
key optional, on which `readableRequest()` refuses the request and the epoch resets. Plus the envelope/event presence
leg per config, the binding leg (an upgraded config's `identity_sha256` unchanged after upgrade), and a **grep leg**
over `fleet-reporter.js` that goes red on any read of `install_id` that is not one of the permitted shapes — the
loader's optional check, an emit guarded on presence, the binding hash — **red on today's tree**, because the
behavioural legs reach no hook path (the hook gate `config.install_id && config.seat_id` at `:3302` runs in a hook
process, which a flusher restart never starts). `selftest`'s identity line prints `seat_id · url`.

**Existing configs are never rewritten**: a seat upgraded in place keeps `install_id` in its file and keeps sending
it, which § 3.2 accepts. One written shape, two read shapes — the posture card#12180 set for `sites`.

**Between S1 and S6 the page still writes v2, and the installers in the field still compare `install_id`**
(round 2's m-5). The page writes the seat's **current room key**, or **`unassigned`** for a seat in no room — a value
D1 § 3.1's pattern admits, which an old installer accepts as any slug and the server never reads (§ 3.2). A **new**
seat's first install compares nothing, so it installs. A **rotation** compares the file's `install_id` with the
config's (`install.js:1756`, `:1786`), so a seat that has **moved** rooms since its install is refused by an old
installer — *the identity differs* — and by the S6 installer with a v3 file it is accepted on `seat_id` alone; an old
installer given a v3 file refuses by name (*missing install_id*). Either refusal is loud and the remedy is one: run
the current installer, which the install command fetches. **So S6 lands before S3** (§ 10): no seat can move before
the installer that rotates a moved seat exists.

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

**This reverses a recorded decision, and says so.** `fleet-reporter.js:288-292` records that *"an argv flag was the
alternative and was rejected: § 2.1 fixes the hook contract as `argv[2] == <HookName>`"*, and chose the
`FLEET_REPORTER_CONFIG` environment variable as the one config selector. That choice is **reversed for the hook
command** here, and the record is amended in S7 to say so: the rejection's premise is untouched — `argv[2]` stays
the hook name, and `argv[3–4]` is a suffix the contract never forbade — and the alternative it chose has no spelling
on one of the three platforms: `VAR=x node …` is a POSIX shell form, and the Windows hook command runs `node
"<path>" hook <Hook>` under `cmd` (`hookCommand()`, `install.js:142`), which has no inline environment assignment.
`FLEET_REPORTER_CONFIG` **stays** as the selector the **supervisor** entries carry (§ 8.4: the plist's
`EnvironmentVariables`, the cron line's environment prefix — both platforms where it has a spelling), and `configPath()`
resolves `--config` over the variable over the default, so the two selectors name one file and never two.

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
closure over `(config, spool, state)` (`flusherMain()`, `fleet-reporter.js:4611` on) with ownership, the seat write
lock, the index fold and the site lanes all keyed on that one spool; a per-seat lane is that closure parameterised
and re-entered N times in one process, with lost-ownership in one lane having to stop one lane rather than the
process (`lost()`, `:4680`). One process per seat gets every one of those properties from the OS for the cost of a
label suffix and a flock name, and a seat's flusher dying takes one seat dark, not N. **Cost:** N processes napping
on `K.FLUSH_MS`; at the two-to-four seats the card describes that is not a figure worth a design.

**The property of per-seat supervision** (round 2's M-b), stated once and checked rather than scripted:

1. **A seat's flusher drains that seat's spool and no other's** — whichever way it was started: by its supervisor
   entry, or by a hook's respawn (D1 § 2.3). Every start path carries that seat's selector through to the process:
   the supervisor entry in its own form, and the hook's respawn by passing the config path it was itself given.
2. **Adding or removing a seat leaves every other seat's supervisor entry byte-identical** — the first seat's
   crontab block, plist or Windows start included.
3. **Removing the first seat removes the first seat's files alone** (§ 8.5).

*Examples* of the shapes that break it, not a list — each a plant for the check: a crontab rewrite that replaces
every reporter block as one (`cronReplace()`, `install.js:542`, replaces the whole marked region); a cron line that
names no selector (`cronLine()`, `:167-171`); a hook's respawn that hands the flusher the **environment's** selector
while the hook itself was selected by argv — `maybeRespawnFlusher()` already passes `FLEET_REPORTER_CONFIG` from
`configPathUsed` (`fleet-reporter.js:3155`; revision 3 said it passed the environment alone, which was false), so the
plant is a `--config` hook whose `configPathUsed` is not what the respawn forwards, which respawns the **default**
seat's flusher. **The check (T19):** on each supervised platform's fixture, install seat `a`, add seat `b`, remove `b`,
and assert `a`'s supervisor entry byte-identical at every step; spool one line into each seat and assert each lane
drains its own (the plant: a build whose cron line or respawn carries no selector drains the default spool for
both); run a hook with `--config <b>` and assert the flusher it spawns owns `b`'s lock, not the default's.

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
  its profile; bare `uninstall` on an account with several seats refuses and lists them. ⚠ **The first seat's
  directories contain the others'** (`seats/` sits under `~/.config/fleet-reporter/` and
  `~/.local/state/fleet-reporter/`, § 8.4), and today's uninstall removes the config and spool directories
  recursively (`install.js:1669`, `:1690`) — so `uninstall --seat <first>` **refuses while `seats/` holds any seat**,
  and when it runs it removes the first seat's own files (`config.json`, its token file, its spool's files and
  directories other than `seats/`) and never the `seats/` subtree (round 2's m-8). **The shared artifact directory
goes with the last seat on the account and no earlier** — today's uninstall removes it with the spool (`install.js:1690`),
which would take every other seat's hooks' target with it (round 3's m-G). T19 plants both.
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
| T1 | a `seat_id` any row holds is never issued again, and a live row wins over a retired one of the same id (§ 3.1) | retire seat `x`; call `issue('x')`; separately, on a store seeded with § 0's two retired pairs, call `issue('mezzanine-solo')`; separately, a store holding `z` live under one legacy install and `z` retired under another, call `issue('z')` | `SeatTokenRefusal::seatRetired` for `x` and `mezzanine-solo`; a **re-issue** for the live `z` (one more token row, no seat row); `seats` row count unchanged in all three (`SELECT COUNT(*)` before = after, asserted as equality, not a figure) |
| T2 | a live id re-issues, never duplicates | `issue('y')` twice | one `seats` row for `y`, two `ingest_tokens` rows, the second response lists the first's prefix in `priorActive` |
| T3 | the store refuses a second live `y` from any writer | raw `INSERT seats (seat_id='y', retired_at NULL)` | `1062` on `uq_live_seat`; the same insert with `retired_at` set succeeds (the live-only key) |
| T4 | the ingest reads no `install_id` (§ 3.2) | four batches for token-bound seat `y`: no `install_id`; `install_id: "aimla"`; `install_id: "zzz-not-a-room"`; `install_id: "Not A Slug"` — and each event in them carrying the same four | all four `200` with `accepted` = the events sent; a build that compares or bounds the field reds on the third or fourth |
| T5 | `seat_id` still binds | batch for `y` claiming `seat_id: "z"` | `403 identity_mismatch`, body carries `expected_seat_id: "y"` and no `expected_install_id`; refusal counted on `y` |
| T6 | REST by seat alone is additive, every timeline route is session-only, and no route pairs the segments (§ 3.3) | `GET /api/fleet/seats/y`, `/api/fleet/seats/anything/y`, `/api/fleet/seats/nope`, `/api/fleet/seats/y/timeline`, and a seat named `timeline` at `/api/fleet/seats/timeline/timeline` — each with a session; **the token leg**: an `mzr_` token presented to both timeline routes; **the route leg**: `route:list --json` scanned for a URI holding both parameters outside the kept pair, run on today's tree first; the timeline routes for the token leg are **selected from `route:list` by action** (`FleetController@timeline`), never by name, so a route the gate's name list does not know is still presented the token | first two `200` with identical bodies and `api_version: 1`; third `404`; the fourth is `y`'s timeline; the fifth is seat `timeline`'s timeline (the route order); both timeline routes answer the token `401` as `fleet.timeline` does today — **red on a build whose new route name is absent from `FleetReadGate::SESSION_ONLY_ROUTES`**; the route leg red on today's tree (compose, retire) and green after S1 |
| T7 | an unassigned seat rides the snapshot as a virtual install (§ 4.4) | issue `w` (no room) | the snapshot has an `installs[]` group `unassigned` holding `w` and `api_version: 1`; both composers, fed that snapshot and the current layout, produce (S1) an implicit floor `unassigned` by today's rule and (S4) the Unassigned floor; `GET /api/building/rooms/unassigned/map` is the shipped default; the room-map create form refuses `unassigned` (red on a build whose `renderedInstalls()` carries the snapshot's virtual install); after placing `w` neither composer produces the floor |
| T8 | a room move is one delta and the client re-slots (§ 4.2) | place `w` in `aimla` | one `seat.delta` with `changed` ⊇ `['install_id']`, `patch.install_id = 'aimla'`, `state_version` +1 — a build without `install_id` in `versionBearing()` emits nothing (the RED); the headless desk model (`desk-floor.js`) holds one desk for `w`, in room `aimla`, and none in `unassigned`; mutate the client to key by the pair → it holds two desks |
| T9 | the slot and the seed read `seat_id` alone (§ 3.4) | D3 § 3.2's worked `h` table re-published for `seat_id`; `verify-floor.py` G8 and `TheSpeciesListHasOneSourceTest` read it | both green on the new table; the Python copy mutated to hash `install/seat` → red |
| T10 | the coordination arm is the room (§ 4.3) | AT-D2-24's install-arm REDs with a seat moved out of the room between declaration and post | the post resolves to no desk after the move, to the desk before |
| T11 | the slug function (§ 5.2) | the fixture table: `The Nook`/`the nook`/`Thé  Nook` → `the-nook`; `三階` → `''`; `--a--` → `a`; `Unassigned` → reserved | PHP and JS copies agree on every row; a row changed in one copy reds the lockstep test |
| T12 | the reader's and the write's refusals (§ 5.1) | at load: documents with `label`; no `name`; two floors slugging alike; alias = another floor's slug; alias in two floors; name `unassigned`; room `unassigned`. At the write: a save and a restore placing a room retired since; a raw save naming B with the slug still in A's aliases | each refused with a message naming the member and the floors; `{"floors": []}` and a document with `name`+`aliases` accepted; the retired-room document is refused by the write and, planted into the store directly, is **loaded** (no load refusal exists for it — the plant that reds a reader guard with no route) |
| T13 | rename redirects in one hop; a take-over moves the address in one revision; precedence; both races refused (§ 5.3) | rename `The Nook`→`The Den`→`The Burrow` via the console; create floor `The Nook`; name a floor `sola` while room `sola` sits on another floor; run the two mid-rename orders of § 5.3 with the second writer blocked on the lock | `aliases = ['the-nook','the-den']` on one entry; `/floor/the-nook` → the headless frame's `redirect` is `the-burrow`, `/floor/the-nook/aimla-pm` keeps the seat; after the create, **one** new revision, **one** outbox row, `the-nook` absent from The Burrow's aliases and current on the new floor, `/floor/the-nook` opens it; `/floor/sola` opens the floor named `sola`, not room `sola`'s floor; each race's second writer refused naming the first's floor, and the layout holds exactly the first's write |
| T14 | the migration changes no floor's content, and changes the order as stated (§ 6.3) | a store seeded from § 0's readings **plus one seat issued after S1 and never placed**; run S4's `migrate` | both composers' output before vs after: **six floors** — the four placed, `sola`, and the unplaced seat's floor, which is the implicit `unassigned` before and the *Unassigned* floor after (the one name that changes, and it sorts **last** by rule, not by slug); no `kanban`, no `mezzanine`; every other floor's rooms, theme and name equal under `label`→`name`, the order **after** equal to the slug sort § 6.3 states and different from the order before; `aliases` exactly `{the-burrow: [solo-agents], the-treehouse: [treehouse], the-nook: [nook]}`; `installs` gained exactly `treehouse`, `nook`; every seat's `install_ref` unchanged; the snapshot's seat set and `install_id`s unchanged |
| T15 | the migration's property (§ 6.2): refusable first, idempotent, schema-guarded, re-runnable after a failure at any step; `down()` re-homes (§ 6.5) | a copy of today's store shape — install-scope hooks, the two retired duplicate pairs, installs with zero live seats, an authored map, a layout placing rooms the store lacks, **and for S4 a seat issued after S1**; the migration run with a failure injected after **each** of its own named steps in turn, then re-run; **the rollback leg**: issue a seat after S1, run `down()`, then `up()`, then `up()` again; and separately the preconditions planted: two live seats `q` under two installs; a layout **placing** `unassigned`; an `installs` row `unassigned` with **an unrevoked hook** bound to it; the same row with a **current map**; the same row with a revoked hook only (re-homed, kept); a label `sola` beside a reported unplaced `sola` | every injection point reaches the **same** end state (§ 6.2's list, compared as `SHOW CREATE TABLE` plus row sets); the hazards § 6.2 names each red a naive sequence (the `UPDATE` before the enum is widened; `uq_seat` dropped before the FK has another index; a re-run meeting an already-applied step); each precondition refuses naming its subject with the schema unchanged; `down()` under the same injections reaches the pre-migration schema; the rollback leg's states are § 6.5's table, and the `installs` row `unassigned` is **present** after every `up()` — a build that deletes it reds on the hook-bound plant (`1451`) |
| T16 | the token file v3, and v2 still installs; v3 takes v2's path (§ 8.1) | the page's blob through `parseTokenFile`; a v2 fixture; a v3 with a fourth key; a v3 file against a **two-site** config whose second site matches its URL, with `--token-file` and no `--site` | v3 parsed `{version: 3, seat_id}`; v2 parsed with `install_id` discarded by the writer (the written config has no `install_id`); the fourth key refused by name; the rotation writes the **matching** site's token and the default site's is byte-identical before and after — the red isolated as § 8.1 says: a build whose parser alone accepts v3 routes it through the v1 branch and reds here; every `readTokenFile(` call site is driven, the `token-config` step included |
| T17 | no reporter path requires `install_id` (§ 8.1) | a v3 config with one pending persisted request, then a flusher restart — on a build whose **loader alone** makes the key optional (today's `loadConfig()` refuses the config first); two configs (with and without the key) sending; an upgraded config's binding before and after; the grep leg over `fleet-reporter.js`, run on today's tree | the pending batch is resent after the restart and `seq_epoch` is unchanged — **red on the isolating build**, where `readableRequest()` refuses the request and the epoch resets; the envelope and every event carry the key exactly when the config has it; `identity_sha256` unchanged for the upgraded config; the grep leg red today (the hook gate at `:3302` among what it finds) and green after S6 |
| T18 | `--config` selects the file and fails loud (§ 8.3) | hook with `--config /nope`; hook with `--config <seat b>`; hook with no flag; on `win32`, a quoted path with a space | first: `config_readable` fail, nothing spooled anywhere, no fall-back to the default spool (the RED: a build that falls back spools into the default); second spools into b's spool; third into the default; the quoted path parses and spools |
| T19 | per-seat supervision's property (§ 8.4), and add/remove touches nothing of the others (§ 8.5–8.6) | on each supervised platform's fixture: an account with the default seat `a`; add seat `b` with `--claude-config-dir`; spool one line into each spool; run a hook with `--config <b>`; remove `b`; `uninstall --seat a` while `b` exists; an account with **no** seat and `CLAUDE_CONFIG_DIR=~/.claude-a` | `a`'s `config.json`, `~/.claude/settings.json`, spool and supervisor entry byte-identical after the add and after the remove; `<dir>/settings.json` holds hooks with `--config …/seats/b/config.json`; each seat's flusher drains its own spool and neither the other's (the plant: a cron line or a respawn carrying no selector drains the default spool for both); the hook's respawn owns `b`'s lock; a second add into the same dir for seat `c` refused; `uninstall --seat a` refused while `seats/` holds `b` (the plant: the recursive remove takes `b` with it); `uninstall --seat b` leaves the artifact directory in place while `a` remains (the plant: today's remove takes it); the seat-less account's first seat lands under `seats/<id>/` with a suffixed label and `~/.claude` untouched |
| T20 | the two-rooms notice is gone (§ 3.3) | the test that planted a seat in two rooms | deleted with its fixture, and the drill-down resolves a seat over the floor's rooms to one desk |
| T21 | one seat-identity primitive (§ 3.6): an unassigned seat is found by every surface a placed one is | behavioural leg: an unassigned seat `u` with a token — revoke its prefix; move it (delta); `mezzanine:retire --seat=u`; a species allocation after it; the tokens listing; `mezzanine:rebuild --seat=u`; `mezzanine:seat-board-user --seat=u`; `LinkPass`'s label. Grep leg: the pattern the coder writes for an inner join from `seats` to `installs` outside `SeatIdentity`, **run on the unmigrated tree first** | revoked; one `seat.delta`; retired; `u`'s species counted in the live population; `u` listed; rebuilt; linked; the label `u`; the grep leg red on the unmigrated tree (the plant) and green after S1 — a site left over reds the leg, and the behavioural leg reds on the act that site serves |
| T22 | `amend()` and the compare-and-swap (§ 5.5) | two console actions on one base, the second blocked on the lock; a textarea save with a stale `base_layout_version` | the second action reads the first's result and either applies cleanly or is refused naming the floor; the stale textarea save refused naming both revisions, the store unchanged |
| T23 | the key primitive is the only place a key is built or split (§ 3.4), and it carries the room for a fleet-scope post | behavioural leg: `coord-model.js edgesOf()` over a fleet-scope round whose origin and traveller seats sit in two rooms, keyed by `seat_id`. Grep leg: the pattern the coder writes for a key joined from `install_id` and `seat_id`, split on `/`, or a `splitKey` import, over **every module the pages load** — the import graph walked from each page's module script (`floor.blade.php`, `dashboard.blade.php`, the building view), the precedent being `TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`'s root walk — which today spans `server/public/js` and `resources/`, less `wire/seat-key.js`; **run on the unmigrated tree first** | A22 edges carry each seat's held `install_id` as its room (a build that splits the key on `/` yields the whole seat id as the room — the RED); the grep leg red on the unmigrated tree (the plant) and green after S2 — a site left over reds it |

**Build notes, S1 (2026-11-10) — where the build read this section differently, and why.** (1) The plants'
one-letter seat ids (`x`, `y`, `z`, `q`, `w`, `u`) are placeholders: D1 § 3.1's seat slug needs two characters, so
the tests use `seat-x`, `seat-y` and the like. (2) T21's *move it (delta)* has no act to drive in S1 — the move is
S3's (§ 4.2) — so S1's behavioural leg reads the delta a roomless seat's **retirement** publishes (`seat.delta`
and `seat.retired`, both on the `unassigned` tenancy); the move's delta is T8's, at S3. (3) T20's *test that
planted a seat in two rooms* did not exist: the notice was never under a test, so the slice deletes the branch
and its D3 § 4.4 wording, and adds no test for a state `uq_live_seat` makes unreachable. (4) A new seat's
species preference key in S1 is `unassigned/<seat_id>` — the room it is drawn in, in the pair shape the client's
draw (`seed.js`) still computes until S2 re-keys all three copies together (§ 3.4). (5) § 6.2's *an `installs`
row for every room key the current layout places* mints only keys that are D1's install slug; a layout key that
is not cannot be a room. `down()` leaves the minted rows in place: under the pre-S1 code an install with no seat
is drawn nowhere, so they are inert, and the next `up()` finds them present.

**Build notes, S2 (2026-11-10) — where the build read this section differently, and why.** (1) T23's roots are every
module script a Blade view under `resources/views` names, read out of the views, which adds `console/seat-token.js` and
`local-times.js` to the three pages; the walk skips `vendor/` (third-party code holds no seat). The leg's patterns are in
`Tests\Feature\Floor\TheKeyPrimitiveIsTheOnlyKeyBuilderTest`, with a control per shape, and the URL and asset-path splits
it spares are named there by module and receiver. (2) The leg also found the lobby plaque's seat names — `install_id/seat_id`
on a floor of several rooms, a display label rather than a key, justified by *a `seat_id` is unique within one install
only*, which S1 made false: the plaque now names a seat by its `seat_id` on every floor, and D3 § 4.1's marker-row and
attention-line sentences are amended with it (a D3 section beyond this slice's list). (3) The character tree's entry
points (`standingFrame`, `walkFrames`, `poseFrames`, `sleepFrames`, `chibiFrame`, `internFrames`, `crestTop`,
`headClear`, `loopOffset`) take the seed key alone, as `seatKey(seatId)` does; asset ids and the client's record lines
name a seat by its `seat_id` (`character:<seat_id>`, `intern:<seat_id>~<call_id>`, `theme:<name>/desk:<seat_id>`).
`App\Characters\Draw` needed no change — it hashes the key it is handed; the key changed at its one caller,
`SeatTokens::allocate()`. (4) `edgesOf()` takes the room-scope room, or for a fleet-scope post the held seats
(`edgesOf(round, room, held)`), so the room is read through `roomOf()`. (5) T8's client half amends AT-D3-3 (a GREEN and its
Fifth RED) over a new fixture, `fx-room-move`; the server half stays S3's. (6) The `feed_version` bump to 3 moved
`fx-refusals`' unknown-version run from 3 to 4, and `ASeatsCharacterIsAllocatedOnceTest`'s pin of the card#12443 bump
became its lasting property (*at least 2*). (7) D3 § 3.2's worked table is re-published for the four `aimla` seats under
the new hash, and § 3.3's worked collision re-chosen (`aimla-win-6` onto `aimla-impl-2`'s slot 3), since the old pair no
longer collides; D3 sentences elsewhere that named the pair as the desk's key or seed were made true in the same commit
(§ 1's principle 7, § 2.1 row 3, § 2.2 step 2, § 2.3's insert row, § 3.3's identity paragraph, § 5.x's seed sentences,
§ 10.4's furniture row, the asset ids and record lines). (8) D2 § 6.13's preference key (§ 14's S2 row) is amended here.
(9) Fixtures whose scenarios rested on a hash coincidence of the old key were re-chosen, not edited to pass: `fx-collision`'s
arriving seats (each scenario's shape kept — a collision, a free slot, the PM's free hash slot, two arrivals in one
render, a three-desk cascade — and re-derived by the tests from § 3.2's function), the § 3.3 arrival in `fx-elevator`,
`fx-intern-walk`, `fx-drilldown` and `fx-office`'s relay run (`aimla-win-6`), and a fifth seat in `fx-office-none`
whose hash slot is the reserved desk, since none of `fx-snapshot-4`'s four seats hashes there any more. `fx-snack`,
`fx-restroom`, `fx-turn-back` and `fx-turn-back-fuzz` were regenerated by their generators over the new keys (the
restroom generator's search window for its two four-walker instants widened, the old window holding none); the
fixtures' `final` maps and asset ids are re-keyed by `seat_id`. The cascade can no longer start from a seat hashing
to the reserved desk — no arrival into the room's free slots both does so and moves three desks — so it starts from
an arrival that sorts below `aimla-review`, and the probe past the reserved desk is `aimla-impl-1`'s. The elevator waits
are seeded from the desk key too (`draw(key, 'elevator-wait:<state_version>')`), so the hand-authored runs whose story
rests on a wait were re-derived: `fx-elevator`'s lane runs and mass-departure with its reordered twin, and `fx-intern-walk`'s
leave-outside-the-lane, keep their waits by shifting the walkers' state versions to ones whose new draw equals the old;
`fx-intern-walk`'s queue-gain-after-boarding does too, and its later deltas also move, its intern's route having grown;
`fx-elevator`'s queue-return-after-boarding keeps its versions and moves its last return into the window its story
names; and two-walkers keeps its versions, its story holding at the redrawn waits, which its note now states. The tests
that named the fixture floor's free slots (0 and 5, now 4 and 5), a re-slot's target, the crowded map's overlapping pair,
the walled-in desk's run and the turn-round fuzz's label runs read the new layout; a planted seat whose delivered species
became its key's own draw got another; and the lobby run that held one `seat_id` in two rooms — a state S1's
`uq_live_seat` makes unreachable — renames the second.

**Build notes, S3 (2026-11-11) — where the build read this section differently, and why.** (1) T22's *second action
blocked on the lock* is driven in sequence (`Tests\Feature\Admin\RoomConsoleTest`): the suite holds one connection
(card#7523), so what is proven is that the second action, rendered from the first's base, reads the first's result and
applies on top of it or is refused naming the floor; the lock's serialisation of two concurrent ones is not measured
here. (2) T8's RED reads `changed[]`, not *nothing is emitted*: the move records its author as a `cause: operator`
transitions row (`owesRow`, the ledger's home for an operator act's cause), and that row bumps the version, so a build
without `install_id` in `versionBearing()` still publishes a delta — one that does not carry the room, which is the
defect. (3) § 4.2's two warnings are shown on the Agents page after the move, computed under the move's locks, rather
than on the form before it: the duplicate warning depends on the destination the select names. (4) A room is placed at
creation on a floor chosen by its DERIVED key (the lexically least room on it — S4 is where a floor gets a name), as
`{"form": "open"}`; a planned floor refuses it by the reader's own all-or-nothing rule, and the room's row rolls back
with the refusal. (5) Retiring the last room of a floor drops the floor's entry in the same `amend()`: a floor of no
rooms is a document the reader refuses. (6) § 5.1's *a placed room the store holds as retired* write refusal lands
here, with the retirement that makes it reachable, at `save()`, `amend()` and `restore()`. (7) Binding a coordination
hook takes the room's row `FOR UPDATE` and refuses a retired room and the reserved key, so § 4.1's *no unrevoked hook*
check cannot be passed against a binding about to commit. (8) A retired room is not renamed: it is a record. (9) The
render: the old room's chain re-probes under A16 naming the moved seat (`floor/floor-screen.js`'s `#displacements()`,
`fx-room-move`'s `move_collision`), and the new room treats the move as an arrival (`move_in_collision`). **Not built:**
§ 4.2's A13 for the departing desk and its *moved to room X* log line — A13's driving fact is D3 § 6.2's closed table,
and a desk that leaves one room while the same key is drawn in another is a scene question this slice did not take on.
**Not addressed, the plan silent:** an open drill-down panel whose seat moves to a room on another floor keeps naming
the floor it was opened on; § 4.2 says nothing of the panel, and Q7 (b) rules on links only. (10) The move's bounded
lock wait is retirement's, extracted at this second caller into `SeatLock::withOperatorWait()`.

**Build notes, S4 (2026-11-11) — where the build read this section differently, and why.** (1) T13's console half is
S5's, so S4 drives the acts through the closures the console will hand `Layouts::amend()`: `App\Building\FloorEdits`'s
`rename()` (the alias append, the rename back, the take-over delete-first) and `create()` — a new floor holding one
room, taken off its old floor, since the reader refuses a floor of no rooms (§ 5.5's `POST /admin/building/floors (name,
theme)` will need a room, or a reader rule, at S5). The redirects are asserted where a viewer meets them: the store's
layout served by `GET /api/building`, composed by `floors()` and resolved by `resolveRoute()` under `node`
(`Tests\Feature\Building\AFloorIsAddressedByItsNameTest`); the races are driven in sequence, as S3's were. (2) The URL
canonicalisation (§ 5.2's last row) has no headless page harness: `floor/main.js` takes the bar's floor segment from the
drawn frame's key, and `FloorPageWiringTest` holds that by its source with a control, which is a statement about the text
and not a measurement of a browser. (3) T14 seeds § 0's store after S1 and S3 — S1 already minted `treehouse` and `nook` —
so it asserts S4 adds no `installs` row, where the T14 row's *installs gained exactly `treehouse`, `nook`* is the two
migrations together. The *before* is written down from the retired rule (`BEFORE` in
`TheFloorNamingMigrationKeepsEveryFloorTest`), since the composer that drew it is gone. (4) T15's injection points: S4's
migration is one step, so the re-run leg runs it after the step with the store moved on in between (a room left unplaced
must stay on Unassigned), and the control is a copy of the migration with its *already named* guard removed, which
materialises that room on the re-run. (5) An alias is written only where the old key is itself an address (a fixed point of
the slug): an `install_id` such as `a--b` is not one, and the room-key arm of the precedence reaches it instead. (6) The
historic `2026_09_12_000100` migration validates a configured layout with today's reader, so a configured `label` is now
refused there; no deployment holds `config/building.php` (it left the tree with card#9208), and its test's documents carry
`name`. (7) The browser composer takes the authored rooms as a fourth argument (`floors(snapshot, layout, nowMs,
authored)`), read from `wire/building.js`'s `Building#authored` — `rooms[]`, then each map answer — at every composition
site. (8) Every fixture whose served layout was the empty building now places its reported installs as the migration would,
so no scene moved; the runs that enter a floor ride to its new key (`the-big-room`, `two-rooms`, `the-empty-room`), and the
generated fixtures were regenerated by their generators. (9) F17's cold-start list is not replaced by the Unassigned floor
(§ 4.4's *subsumed* reads the directory's unclaimed rooms of an earlier draft): the Unassigned floor is a composition and
needs the layout F17 failed to fetch. (10) § 4.4's *authored* arm left a hole: retiring a room keeps its current map
as a record, so a retired room would have been drawn on the Unassigned floor; `GET /api/building`'s `rooms[]` lists the
LIVE rooms with an authored map (`Floors::versions()`, D2 § 8.7 amended), which both composers read. (11) With every
fixture floor placed, a floor is drawn once with no seat before a run's snapshot applies — as every authored floor
already was — so the walk tests' "every drawn frame" helper (its copies, hoisted into `ReadsTheDrawnScenes`) and
`DrivesTheFloorScreen::firstFloorWithRoom()` start at the first frame drawn over seats. One run met a real behaviour
there and was moved rather than masked: on a placed floor the entry's camera fit is taken before the seats arrive, so
an overflow row they bring is outside the fit view; `camera_degraded_low` now enters the Unassigned floor, composed
only once its seats are held, and the behaviour is reported to the card rather than changed in this slice.

**Build notes, S5 (2026-11-11) — where the build read this section differently, and why.** (1) **A floor is created
holding a room** (S4's first finding): the create form is (name, theme, first room), and `FloorEdits::create()` keeps
its room. The alternative — a reader that admits a floor of no rooms — was declined: FLOOR.md § 4.6 defines a floor
as its rooms and refuses an empty one as *a floor that draws nothing*, and admitting it would give both composers,
the directory, the cab and the building view a floor with nothing on it to draw, for an act the operator can do with
a room in hand. (2) **A new floor is refused the only room of another floor** (S4's second finding), naming the floor
and saying to rename it: that act would only re-home the floor's one room under a new name, which the rename does
while keeping every link, so moving the aliases with the room would be a second rename path, and dropping them is
the silent loss the finding named. (3) **No *remove a floor* route**: § 5.5's `POST …/remove` *refused while it holds
rooms* could only ever refuse, since the reader refuses a floor of no rooms; a floor goes with its last room, placed
elsewhere or retired, and the status line says its addresses then open nothing (§ 5.3's removal). (4) **The floor a
room leaves keeps its plan**; only the planned floor a room is placed on loses its `origin`s and `hallway`. § 5.5
cleared both, but every room left on the source keeps its `origin`, so the all-or-nothing rule holds there and
clearing it would discard positions no rule requires discarding; the destination must be cleared because the form
has no position to give the room. One primitive takes a room off a floor (`FloorEdits::takeOff()`), used by the
create, the placement and a retirement (`Rooms::retire()` through `FloorEdits::unplace()`), and the Rooms module's
create-on-a-floor is the same `FloorEdits::place()`, so it now unplans a planned floor rather than being refused by
it. (5) **One change per `PATCH`** — the name, the theme, or one alias to forget, each its own form — so a save never
re-sends a member from the page's rendering. (6) **`amend()` refuses by name to edit a stored document the reader
refuses** (F17's state), which the closures would otherwise meet as a shape they cannot read. (7) The consequences an
act decides under the lock — a take-over, a floor removed with its last room, a floor unplanned — come back on a
`FloorEditReport` and are said on the status line. (8) card#9085's room-map module keeps its routes and is labelled
*Room maps*, so the nav carries one *Floors*. (9) **Not fixed here, recorded:** retiring an UNPLACED room with an
authored map writes no layout revision (`amend()` refuses the no-op), so no message reaches an open page, which keeps
drawing the room on the Unassigned floor until its next `GET /api/building`. The fix is on the wire, not the console:
`building.layout` is applied only when its `layout_version` differs from the one held, a `room.map` makes the
browser re-fetch a map the retired room still answers with, and the lobby does not apply `room.map` at all — so
closing it is a message (or a rule for an existing one) in D2 § 8.3/§ 8.7 and its apply on the floor, the lobby and
the building view.

## 10. The slice plan, for the coder

Each slice is one PR to `dev`, `[no-close]` until the last of each card, each with its docs in the same round
(§ 14's rows for that slice), a `docs/changelog.d/<card>[-<slug>].md` note, and shippable alone.

| Slice | Content | Closes |
|---|---|---|
| **S1 — the seat alone, on the server** | § 6.2's migration, its S1 share (the preconditions less the layout validation, and the schema end state) under § 6.2's property; `App\Read\SeatIdentity` with T21's two legs (§ 3.6); the new timeline route named in `FleetReadGate::SESSION_ONLY_ROUTES` (§ 3.3); `SeatTokens::issue(seatId, by)` with § 3.1; `TokenResolver`/`TokenBinding`/`BatchValidator`/`EventValidator` per § 3.2; the single-segment REST routes added, the two-segment kept, `api_version` 1, T6's token and route legs (§ 3.3); `Snapshot` by seat with the `unassigned` group; the reserved key refused at the layout write and load and in `FloorInventory` — **no change to either composer** (§ 4.4); console token form (seat id only, the `datalist` of seats), retire route, compose route, CLI signatures (`ingest-token:issue <seat_id>`, `retire --seat=`, `seat-board-user --seat=`, `coord-hook:issue --scope=room --room=`); client fetch paths to the new routes (key still the pair — stable until S3, since no move exists); the § 7 declaration posted before merge. ⚠ **Between S1 and S3 no room can be created**: token issue no longer mints one and the Rooms module is S3's; a seat issued meanwhile lands in Unassigned and is placed once S3 lands, and nothing existing regresses — stated so the slice is read as shippable rather than complete. Tests T1–T7, T15 (first half), T20, T21. Docs: D1 § 3.3, § 4.2, § 4.3, § 12.1, § 12.2; D2 § 6.4, § 8.1 (the declaration), § 8.2, § 8.2.1's binding paragraph, § 8.3.3 rule 1; D3 § 4.4 (the notice); D4 § 4.1; D5 § 3.2; `README.md § The admin console` | — |
| **S2 — the client keys by seat** | § 3.4: `wire/seat-key.js` and every site T23's leg finds under both trees, `seatKey()`, D3 § 3.2's hash and its published table, `Draw`, `CharacterAllocation`; `feed_version` bump. T8's client half, T9, T23. Docs: D3 § 3.1 (desk row), § 3.2, § 10.2, § 13 row 6 | — |
| **S3 — rooms in the console** | § 4.1's `display_name`/`retired_at` writers (with the hook refusal); `install_id` in `versionBearing()`; the Rooms module through `Layouts::amend()` (the primitive lands here, with the textarea's compare-and-swap, § 5.5); the Agents index's *Room* control and the move transaction (§ 4.2) with its warnings; T8's server half, T10, T22. Docs: D2 § 4.10 (the move beside retirement), § 6.5 (the member), § 6.11 (*an install* → *a room*; `amend()`); `README.md` | — |
| **S4 — floors by name** | § 5.1–5.4: `FloorSlug` (PHP+JS, lockstep test), the reader's new members and refusals, the precedence in the client resolver and the URL canonicalisation, both composers by slug with the Unassigned floor and the `compose-cases.json` cases (§ 4.4), `GET /api/building`'s `name`/`aliases`, directory/cab/building view reading `name`; § 6.2's S4 share (the layout validation and the layout revision); the take-over as an `amend()` closure. T11–T14, T15 (second half). Docs: D3 § 3.1 (floor row), § 4.1, § 4.4, § 4.6, § 9 F17, § 13 rows 24/25/27, the card#9273/card#9267 sweep (§ 5.6); D2 § 6.11, § 8.7's worked JSON | — |
| **S5 — the console's floors module** | § 5.5's routes and views over `amend()`. T13's console half. Docs: `README.md`, D3 Appendix B row | **card#13117** |
| **S6 — token file v3 and the optional `install_id`** | § 8.1 on the page, the installer (every `--token-file` subcommand, T16) and the reporter (every path that reads the key, T17 red on today's build). T16, T17. Docs: D1 § 3.1 (both rows), § 4.2; D6 § 4.2; `INSTALL.md § The token file`; `UPDATES.md` | — |
| **S7 — several seats on one account** | § 8.2–8.6: `--config` on `hook`/`statusline` (quoted on `win32`), `parseReporterCommand`, per-seat paths by profile, supervisor entries, `add`/`rotate`/`uninstall`/`selftest`/`holder` per seat, the profile checklist; the first macOS install as the measurement of § 8.7. T18, T19. Docs: D1 § 2.1, § 3.1, § 11.1; D6 § 3.1, § 3.4, § 3.6; `INSTALL.md` (a new section *Several seats on one account* with § 8.3's table); `AGENT-INSTALL.md` | **card#13118** |
| **S8 — the snapshot's `api_version` 2** (gated) | the bump, the removal of the two-segment routes, D2 § 8.1's row; opened only on the bridge owner's word (§ 3.3) | — |

Order: S1 → S2 → S6 → S3 → S4 → S5; S7 after S6; S8 when gated open. S2 must precede S3, because a move re-keys;
**S6 precedes S3** because a moved seat's rotation needs the installer that compares `seat_id` alone (§ 8.1); S3
precedes S4 because the take-over and the materialisation write through `amend()`.

## 11. What could falsify it, and what is not defended

- **A live duplicate `seat_id` on a deployment other than the sandbox.** The migration refuses and names it (§ 6.1).
  Not defended: choosing which to retire.
- **A reporter whose version predates `seat_id` on the envelope.** D1 § 4.2 has carried it since v1; no such
  reporter exists (`fleet-reporter/UPDATES.md`'s first row). Not defended.
- **The bridge's idle nudge after S1** (§ 3.3, § 7): a retirement of the room under `BRIDGE_IDLE_NUDGE_INSTALL`
  is loud on that end; a seat moved out of the room or left in `unassigned` is *foreign* to its evaluator and
  silently un-nudged. Defended only by the declaration; closed only by that owner.
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
- **The unreported rooms `kanban` and `mezzanine` stay drawn nowhere after the migration** (§ 6.3), listed in the
  console for the operator to retire: no floor is minted for them, as none is drawn for them today.
- **A species allocated against no floor** (§ 13 Q6) can duplicate a floor-mate's body after placement — accepted
  by the operator; reachable only once the fleet holds more live seats than species.
- **Rolling S1 back after S6 has shipped — accepted.** A seat installed from a v3 token file after S6 has no
  `install_id` in its config and sends none; a pre-S1 server compares the envelope's `install_id` with the token's
  binding (step 7) and refuses the batch `403 identity_mismatch`, which is the reporter's auth stop — every such seat
  is dark until S1 is redeployed. Rotating the seat against the rolled-back page does not rescue it: both the old
  and the S6 installer leave a config with no `install_id` without one (found while building S6). The cheap
  closure — a v3 reporter sending `install_id: "unassigned"` when its config has none, the value `down()` would have
  given its seat — was weighed and **declined**: it writes a wire value from an absence to serve one rollback path,
  and it is wrong for a v3-rotated seat whose room is not that row. Accepted because S6 lands after S1 by § 10's
  order, a rollback that crosses S1 after S6 is a rollback of two released slices, and the release note's *Upgrade
  warnings* name it; the seats affected are exactly those issued after S1, which `selftest` names by the auth stop.

## 12. Seat rulings — reversible by the operator, each one rule

| # | Ruling | Alternative | Cost of the ruling |
|---|---|---|---|
| R1 | a new seat is created in **no room**; the token form offers no room | a room select at issue | ruling 3 says Unassigned; one fewer path to the seat's room |
| R2 | a room may exist on no floor and is drawn in Unassigned (from S4) | a room must name a floor at creation | a floor removed under a room by the raw editor would otherwise be a refusal in the reader, which D3 § 9 F17 shows takes the building down |
| R3 | the migration materialises a floor for every **reported** unplaced install — the floors both composers draw today — and none for an install with no live seat, which has no floor today either; the floors' order and numerals change once with the key (§ 6.3) | materialise every unplaced install, empty ones included; or retire the unreported ones in the migration | no floor's content changes; the unreported rooms stay listed in the console for the operator to retire, with a revision |
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

**Answered 2026-11-10, folded into revision 6:** Q6 **(a)** — allocate at issue against the fleet; rule 2 is
amended to *the seat's floor, or nothing while it has none*, and rule 4 holds. Q7 **(b)** — the floor wins; the
operator's reason: *"keep the code simple. If a desk moves, use the new link"*. The `CLAUDE_CONFIG_DIR` per-profile
setup cost (§ 8.2) is kept. The design is approved.

The questions as they were asked, kept for the record:

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
| D2 § 6.13 | the allocation's preference key; rule 2 is the seat's floor, or nothing while it has none (Q6) | S2 |
| D2 § 8.1, § 8.2 | the snapshot's additive change under `api_version` 1 and the gated bump; the routes by seat beside the two-segment ones; the `unassigned` group; the declaration to the bridge owner recorded | S1 |
| D2 § 9, § 14 item 7, § 6.4's `feed_outbox` comment | the deferred ACL's attachment point is the **room** key, `unassigned` for a roomless seat's rows; the timeline's session-only rule names both timeline routes | S1 |
| D2 § 8.2.1 | the binding paragraph: `seat_id` alone is the identity; `install_id` is the room, a mutable member; *uniqueness is site-wide among live seats* | S1 |
| D2 § 8.3, § 8.3.3 rule 1 | `seat.delta` on a move; the room arm | S1, S3 |
| D2 § 13 | a row for this card's reversal of row 38's *(an install)* wording; row 50's *per install* → *per room* | S3 |
| D3 § 3.1 | the keys table: desk = `seat_id`; room row; floor row = slug of the name; the ⭐ reversal note | S2, S4 |
| D3 § 3.2, § 10.2 | the hash over `seat_id`; the published `h` table | S2 |
| D3 § 3.4, § 3.5 | the move's render beside the first appearance and the removal | S3 |
| D3 § 4.1, § 4.2 | `name` where `label` was | S4 |
| D3 § 4.4 | routes: slug, the precedence (current slug > alias > room key), the two-rooms notice deleted; a drill-down link redirects on its floor segment alone (Q7) | S1, S4 |
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

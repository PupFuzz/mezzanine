# Changelog

Every PR whose title or branch carries a `card#NNNN` token owes a line-initial
`- **card#NNNN** — …` bullet under `## [Unreleased]`, **in the same PR**. A PR that names no
card owes nothing. `docs/PLAN.md § 4` owns that rule and the reasoning behind it, including
why the bullet must be line-initial; `docs/VERSIONING.md` owns when a release collects these
entries and retitles the section.

Nothing has been released yet, so `[Unreleased]` is the only section.

## [Unreleased]

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

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
returning it empty.

Sections are newest-first: `[Unreleased]` collects what has landed on `dev` since the last
release, and a release retitles it (`docs/VERSIONING.md § Release flow` step 4).

## [Unreleased]

- **card#7582** — **The board task-title producer is DESIGNED, and deliberately not built.**
  New `docs/design/BOARD-TASK.md` (**D4**) designs tier 1 of `FLEET-STATE.md § 4.9`'s task-title
  merge: the kanban poller, its cadence, the seat→board-user join, the read-scoped credential and
  its never-emitted rule, every failure path, and eight acceptance tests with their REDs.
  ⭐ **The one real design question was rebuildability** — `§ 6.6` + `AT-D2-10` make `seat_state`
  reproducible from `events` and `RebuildCommand::reset()` nulls the five `task_*` columns, so any
  tier-1 value written INTO the projection is erased by the documented recovery path. Priced
  against routing board facts through `events` (a D1 change, and one that would need a forged seat
  identity or a new producer endpoint) and against excluding `task_*` from AT-D2-10 (the exact
  shape card#9214 had just deleted), the answer is neither: **the poller writes a durable INPUT
  table and the fold derives the columns from it**, exactly as it already derives `render_state`
  from `seats.retired_at` — an operator-written value in no event that `reset()` deliberately does
  not touch. ⇒ no D1 change, no new event kind, no change to `reset()`, and **no fourth exclusion**.
  D2 § 12 asks for the tier-1 freshness bound to be "re-derived once the board producer exists and
  its poll cadence is known"; it is, from a 5-minute cadence, and the figure is unchanged while its
  basis moves from *Chosen, provisional* to *Derived*.
  ⛔ **Nothing is implemented and that is the deliverable.** Every structural piece needs a D2
  amendment — a § 2.1 process row, a § 6.4 table and column, two § 7.2 counters — and D2 § 6.4
  says a builder "may reorder columns and add nothing". The amendments are stated as exact text on
  the PR, unapplied, for ratification. Two reasons hold independently: no board card is assigned
  anywhere on board 14 (re-measured over the whole population, not a sample), so tier 1's answering
  branch cannot be exercised on a real surface; and the server's board credential does not exist.
  ⚠ **A finding recorded on this card is corrected here:** the raw board API's list endpoint DOES
  carry `assigned_user_id` on every row — the earlier "no such key" was true of `kbcard`'s
  ten-key projection and not of the API, and designed around the wrong reading the poller would
  have issued one request per card per tick instead of one for the board.
  `docs/PLAN.md § 2` gains D4 and **loses its "three design artifacts" count** — the same
  set-versus-figure repair § 2.1 made for its process table. `tools/design/README.md` declares
  that D4 is a design document under **no** verifier, rather than leaving that quietly true.

- **card#9223** — **The ingest rate-limit suite pins its clock, and a real flake mechanism is
  closed.** `App\Support\FixedWindow` indexes on `intdiv(now()->getTimestamp(), $windowS)` — an
  **absolute** window — so a 120-request loop that straddled a real minute boundary put its 121st
  request in a fresh window, where `202` is correct limiter behaviour and the TEST was the thing
  that was wrong. Root-caused at source, then **seen to fail**: the boundary was forced both by
  travelling the clock and by sleeping across a real one, reproducing `Expected 429 but received
  202` on demand, and passing with the pin.
  ⚠ **A severity claim in this bullet's first version was false and is corrected here rather than
  quietly dropped.** It said the flake "reddened roughly one PR in three"; that was card#9223's
  local sample of *three* runs restated as a CI rate. The record refutes it — `gh run list
  --workflow php-tests.yml` gave 33 runs, 32 success, the one failure a composer/PHP-version error
  on the lane's own setup branch. **No CI run had ever failed on this test.** The real exposure is
  the loop's duration over the window length, ~1 %. What justifies the fix is not the rate but that
  the lane is a candidate to become a REQUIRED check, where any nonzero flake rate blocks merges.
  ⛔ **No assertion was weakened and no production behaviour changed** — the pin supplies the
  precondition each test already names in its own title.
  New `Tests\Feature\Support\PinsTheRateLimitWindow` pins the window;
  `FixedWindowPinCoverageTest` **re-derives the population every run** and reds on any class that
  asserts a `429` without a pinned clock. That guard replaced a first attempt that lived on the
  trait itself and could not fail for either hazard it named — it was deleted along with the trait
  it was meant to protect. Also corrected: a comment claiming the release test catches a mis-sized
  TTL, which mutation testing shows it does not.
- **card#9214** — **`task.as_of` is derived from the log, and AT-D2-10's blind fourth exclusion is
  gone.** § 6.6 makes `seat_state` reproducible from `events` and states what a divergence means:
  *"some fold rule is reading state that is not in the log, and that rule is a defect by
  construction"*. `StateRecompute::taskTier3()` WAS such a rule — it stamped `task_as_of` from the
  **wall clock** — and `mezzanine:rebuild` resets all five `task_*` columns, so the documented
  `derivation_error` recovery **re-stamped every replayed seat at rebuild time** and the desk's
  thought bubble claimed its title had been obtained when the operator ran the recovery. Worse the
  moment § 4.9's tiers 1/2 exist, where `as_of` is the basis of the 30-minute staleness drop: one
  rebuild would reset the staleness clock fleet-wide.
  ⇒ **The stamp now comes from the answering call's own `opened_received_at`** — the server-clock
  receipt of the event that carries the title — which satisfies § 8.2.1's *"server clock"* and
  § 4.9's *"when this tier's value was obtained"* **with no D2 change at all**, and makes card
  #7837's no-re-stamp property structural, so that card's `$unmoved` guard is **deleted** rather
  than kept beside it. `task_as_of` also goes to null **with** the title now: the null branch used
  to leave the vanished title's stamp behind — invisible on the wire, and not reproducible either.
  ⛔ **The test that should have caught it was blind AND unexercised, and both halves are fixed.**
  `At10RebuildEqualsFoldTest` unset `task_as_of` from its comparison — a **fourth** exclusion
  beyond § 11's three named ones, with no justification in its docblock — and deleting it changed
  nothing. ⚠ **The recorded reason for that was half wrong, and the measurement is corrected here:**
  the column was NOT unpopulated. It read `2026-08-26 12:00:03.000` on **both** sides; the blinder
  was the **frozen clock** — one batch, one instant, so the fold and the replay stamped the same
  value. The fixture now ends with a second, live session whose titled dispatch call is still
  **open**, the rebuild runs **5 s** after the fold (12× inside the tightest compared threshold,
  § 7.2's 60 s `fold_lag`), the exclusion is deleted, and `snapshot()`'s docblock now names every
  surviving member with the reason it is on the list.
  ⭐ **SEEN RED FIRST** (canon #9): with the extended fixture and the exclusion removed but the fold
  unfixed, AT-D2-10 failed on `task_as_of` **alone** — `12:00:03.000` expected, `12:00:08.000`
  actual — then went green on the fix.
  ⚠ **A D2 amendment was considered and REFUSED, not deferred.** Naming `task.as_of`
  rebuild-excluded would have had to weaken § 11's *"the rendered object is byte-identical"* as
  well, and would have carved out the exact defect class AT-D2-10 exists to detect. The alternative
  text is written out in the PR body, unapplied and unratified.

- **card#8300** — **The coordination thread line — card#7897 part 2 slice 2, D3 § 5.7 and its
  client.** D2 § 8.3.3's two objects had a read surface (card#9212) and no render map; this is the
  render map, and the first thing on the floor drawn **between** desks rather than at one.
  **§ 5.7** names all twenty-two members — `coord_thread.*` × 11, `coord_round.*` × 11 — with a
  null column of its own, because § 5.6's population is § 8.2.1's and is closed against it both
  ways. **§ 6.2 gains A18/A19/A20**: the held thread line, the envelope, the broadcast pulse.
  ⛔ **RESOLVE OR RENDER UNRESOLVED, and today nothing resolves.** No wire event and no config in
  this repository maps a protocol agent name to a `seat_id` (D2 § 8.3.3; card#7957's ruling *(d)*
  is unlanded), so **every participant renders unresolved and no thread line is drawn on any
  floor** — the thread renders as its beads, its label and its named-unresolved participants. That
  is card#7957's ruling *(2)* built rather than deferred: the renderer is correct on day one and
  does not change shape when the join lands. `public/js/coord/`'s `resolve()` has **no else
  branch** and `main.js` builds no join; both are held there by planted controls, because a
  guessed line looks exactly like a correct one on a screen.
  ⚠ **NOT RENDERED, each with the reason:** no duration anywhere — § 2.4's format is closed but
  publishes no **wording** for a coordination age, and § 14 item 17 owns that gap "rather than
  [having it] filled by whichever surface reaches them first", so both receipt clocks are labelled
  timestamps and nothing is subtracted (the lobby made the same call at the same item; this is the
  second surface to). No convergence — `lifecycle: "closed"` is *ended* and `declares_close` is
  *somebody performed the close act*, and D2 publishes no flag to compute one from.
  ⭐ **The gate was widened again and the widening is checked.** `verify-floor.py` reads § 5.7's
  source column (G2) and its markers (G9); the § 12 row that ENUMERATES G2's tables is now
  set-differenced against the tool's own map in both directions, closing the second home that
  over-claimed for two revisions — six planted controls, each reding by name.
  The lobby's `node` rig was **hoisted at its second caller** to
  `Tests\Feature\Support\DrivesAShippedClientModule` rather than copied.
  ⚠ **There is no browser on the build host.** Nothing here verifies layout, position or paint,
  and `main.js` is exercised only against a stub of the four DOM calls it makes.
  ⚠ **Slice 3 is not this card's and its text is wrong.** card#8300 still calls the producer "the
  `coord.*` bridge producer"; D1 § 18.1 correction 1 moved it to Mezzanine's own
  `POST /api/ingest/github` receipt route, so the coordination wire crosses no repository boundary
  and `agent-webhook-bridge` is owed nothing.

- **card#9209** — **D3 publishes a duration format.** § 7.1's Label cells rendered durations and
  the document published no rule for them: its own exemplars — `4m 12s`, `11m`, `2h 06m` — are
  produced by **no single rule**, § 12 carried no row, and the ratified preview sidestepped the
  question with pre-formatted sample strings, so an implementer inherited a gap that looked solved.
  **§ 2.4 now publishes ONE function** — seconds in, one string out — in seven clauses with a
  boundary table: at most two units (`h`/`m`/`s`, no day unit), the largest non-zero unit first and
  unpadded, the second zero-padded to two digits and **dropped when zero**, the remainder truncated
  and never rounded, `0s` for zero and for anything under a second and for a negative age, and
  *nothing done yet* / *no data yet* — never `0s` — for a **missing** one.
  ⭐ **Shared by every rendered duration on the page, with no exception**, which is what retired the
  two forms that were not the others': § 2.4's own *this state is 117 s behind* is now *1m 57s*, and
  the fleet banner's *N minutes ago* is *N ago*. § 7.1's exemplars are regenerated from the rule
  (all three already conformed); § 12 gains the units row, the day-boundary row and the guard-class
  row; **§ 13 decision 23** records the no-day-unit call with its alternative, because that is the
  half a reviewer is most likely to contest.
  ⚠ **The gap was wider than § 7.1 and the population is now named.** § 5.3's `sweep_last_run_at`
  and `ingest_last_receipt_at` ages, the panel's context-sample age, reporter uptime, oldest-unsent
  age and timeline-row age, and the fleet banner are all durations this document renders and none of
  them was among § 2.4's four — so § 2.1 row 2's *three* was a **closed list of instances** where the
  row means a kind, and it was already false. Row 2 now names the kind; **§ 14 item 17** carries the
  remaining **wording** gap (the format is closed for all of them; the string four of them are
  spoken in is not).
  ⛔ **Reconciled in the same change, so no shipped instance outlives the ruling** (card#8075's
  lesson): the preview's `0m 50s` and `0m 21s` become `50s` and `21s`, and the lobby's three
  card#9209 comments — which said *there is no format* — now say what is actually still open.
  **New gates, both seen to fail:** `verify-floor.py` **G12** re-implements the function from
  § 2.4's clauses, reproduces its boundary table before either leg runs, holds every duration inside
  a published rendered span to it as a fixed point, and re-derives § 7.1's `stale` / `offline` ages
  **arithmetically** from the timestamp and corrected clock those cells state themselves; the
  preview selftest gains the same two legs over the artifact's own sample fleet, with the dark
  desk's age derived from `last_receipt_at` and the page's frozen clock — and never from
  `action.started_at`, which is the seat clock § 2.4 forbids subtracting.
  ⚠ **One correction found in passing:** § 2.4 said the fleet banner's words were *"in words D2 § 2.3
  fixes"*. D2 § 2.3 fixes the banner's trigger and its threshold and publishes no wording for it —
  `grep` returns nothing — so the attribution is withdrawn and the words are named as this
  document's.

- **card#9212** — **D2 gains the coordination read surface that card#7897 part 2 slice 1 was
  ruled to write and did not.** That slice was defined as two documents; `PupFuzz/mezzanine#31`
  landed only D1 (`EVENT-SCHEMA.md § 18`), and `FLEET-STATE.md` carried **no** `coord.*` surface
  under any name — which is why `verify-floor.py` red by name on the first § 5.1 render row a D3
  build tried to source from one. **New D2 § 8.3.3** declares both objects as read-surface fields
  (`coord_thread.*`, `coord_round.*`), and § 8.3's message table gains `coord.thread` and
  `coord.round`. Nothing is re-derived: every field, bound and nullability is D1 § 18.6/§ 18.7's,
  cited, and the section mints no number.
  ⭐ **What the surface refuses is the load-bearing half.** No mapping from a protocol agent name to
  a `seat_id` — UNVERIFIED at D1 § 18.13 row 6, ruled on card#7957, and a renderer that reads one as
  the other draws a line to a desk no fact names; no `needs_human`, no `converged`, no round number,
  no `is_broadcast`, no delivery digest, each with the D1 finding that killed it. ⇒ **The escalation
  flare has no field at all and must not be drawn**, and the convergence spark has one that means
  something narrower than convergence — a thread *ended*, a post *declared the close*.
  ⚠ **The producer is Mezzanine's own receipt route, not the bridge** (D1 § 18.1 correction 1, on
  D-10): the coordination wire crosses no repository boundary, so the ruling's cross-repo DECLARE
  obligation has one end rather than two, and nothing is owed into `agent-webhook-bridge`.
  **Feed only** — no snapshot member and no fifth endpoint, because a snapshot is a read of a
  coordination store that D1 § 18.13 hands to a later slice; the cost (a just-connected floor draws
  no thread line until the next post) is priced in § 8.3.3 and carried as § 14 item 14.
  **Doc-sync of claims this falsified — the CLAIM was audited for siblings, not just the file.** In D2:
  § 1.2's *"ingest of GitHub webhook … not designed anywhere yet"*, § 4.9's and § 13 row 25's *"the
  producers of tiers 1 and 2 are designed in no document in this repo"*, and § 14 item 3. In **D3**,
  where the same claim had a fourth and fifth copy: § 1.2's non-goal row and § 14 item 4's *closes it*.
  Tier 2's producer has been D1 § 18 since PR #31 merged, and every one of these said otherwise.
  D3's § 12 guard row moves with the gate change below — it enumerates the D2 surfaces G2 reads.
  **Gates:** `verify-floor.py` learns D2's **fifth** field surface (reading **both** of § 8.3.3's
  tables — a first-table-only read would publish one object and report clean over the other), and
  `verify-fleet-state.py`'s G7 adds `coord` to the message-type prefixes it closes prose against.
  Both were watched failing: a fabricated `coord_thread.bogus` in § 5.1 reds, and a `coord.bogus` in
  D2's prose reds. New **AT-D2-24** pins the two rules a build could not otherwise be held to — the
  invented join, and a coordination fact minting a seat state.
- **card#7833** — **§ 5's predicate criteria are now evaluable, because § 6.4's `seat_predicates`
  was EXTENDED to carry the evidence they ask for** (operator ruling: extend the store, do not
  restate the criteria). Every criterion in § 5 is stated over a window, and the table carried only
  cumulative branch counts plus two timestamps — from which no windowed count is derivable. ⭐ **The
  gap is provable in one pair of histories, and that pair is now a test:** 250 clean turns inside a
  day (criterion MET) and 249 spread over a month plus one an hour ago (NOT met) produce a
  **byte-identical row** — `true_count` 250, `false_count` 0, `last_true_at` an hour ago,
  `last_false_at` null, `alarm_since` null. No function of that tuple separates them. Since PR#21's
  interim, four of the seven answered `cannot_evaluate` on every row of every pass: honest, and
  inert.
  **What § 6.4 gained:** `run_length` / `run_started_at` (the constancy evidence) and
  `window_start` / `window_true` / `window_false` / `prev_start` / `prev_true` / `prev_false` (a
  tumbling 24 h window and the last completed one). The run's BRANCH is deliberately not a column —
  `Predicates::priorBranch()` already derives it from the two `last_*_at` timestamps, and a second
  copy would be free to disagree.
  **What the code gained:** *constant across ≥ N evaluations in a window W* is exactly *an unbroken
  same-branch run of ≥ N that began no more than W ago*, so `seat_live`, `activity_recent`,
  `turn_clean`, `ingest_receiving` and `fold_current` collapse from three implementations into ONE
  `run` rule at five settings of `(direction, n, window)` — the old `consecutive` kind is that rule
  with no window. The verdict is decided at `record()` and **latched** in `alarm_since`, never
  recomputed cold: a run that crosses N inside W and keeps going outgrows W while still being
  constant, and a cold recomputation would withdraw an alarm whose subject had not changed. Exactly
  one event withdraws it — the run breaking.
  ⚠ **THE ACCEPTED COST, recorded rather than absorbed: `call_closed_by_wire` moves from a ROLLING
  24 h window to a TUMBLING one.** Its criterion is a *share* ("≥ 5 % server-closed across ≥ 1,000
  in 24 h") and § 5 says so in terms, so restating it as a run would not weaken it — it would
  **delete** it, and it is the only one of the seven that is a health signal rather than a
  discrimination meta-monitor. Evaluated over the last **completed** window it is exact, at two
  costs the operator accepted: the alarm can only arrive **at a window roll**, so worst-case
  detection of a reap outage stretches by up to one window; and a burst of server closes that
  **straddles** a boundary can leave both halves under the 1,000 floor and alarm on neither. Both
  are under-firing, which is the direction § 5's own trade prefers — and the straddle is pinned by
  a test, so a later change that makes it fire is visibly a design change and not a bug fix.
  ⚠ **A second residue, also under-firing:** the run's window is measured from the run's START, so
  a run that began before W and only reached N afterwards does not fire. Closing that needs the
  timestamp of the (`run_length` − N + 1)-th evaluation — a per-evaluation bucket table, which was
  refused: it would put a second row-write on the fold's hot path, written by two processes, and a
  second lock-order edge on top of the one card#7523 already carries. **`Predicates::record()`'s
  concurrency posture is untouched by design** — the new state is computed in PHP from the row it
  already read and written by the upsert it already did: no new statement, no new writer, no new
  lock-order edge.
  ⭐ **`cannot_evaluate` is GONE, and that closes a read-side gap rather than merely tidying one.**
  `Sweep::pass()` discards `Predicates::alarm()`'s return and § 8.2.3's `detail.predicates`
  publishes only the row's own columns, so while a third outcome existed that wrote NOTHING, a
  predicate **nobody was checking** rendered identically to a healthy one. It is closed by removing
  the third outcome, not by plumbing a field: after a pass, `alarm_since !== null` holds if and only
  if the outcome was `FIRES`, on every row visited — asserted directly, over a fixture containing
  both verdicts so the check can fail. **AT-D2-13 is satisfiable for all seven predicates**, each
  seen to fire and seen not to fire across its own boundary (5,759/5,760 · 199/200 · 49/50 ·
  999/1,000 · exactly 7 days vs one second more), with ten mutations of the new rules each driven
  red.
  ⚠ **The migration was edited IN PLACE rather than shipped as an `ALTER`**, on a stated basis and
  not a convenience: § 6.1 records that "the deploy host is not built yet" and § 6.8 that "no seat
  has been instrumented yet", so no store anywhere holds a row of this table. Splitting one table's
  declaration across two files to alter a table nothing has created would have left that migration's
  own "§ 6.4's verbatim DDL" comment false.
  ⚠ **One claim in this work was made from recall, then measured, and was WRONG** — recorded because
  the correction is now load-bearing in a comment. The share comparison uses integer
  cross-multiplication, and its first justification said `0.05 * 1000` is `50.000000000000007` in
  IEEE-754 so the float form could not meet its own boundary. It is exactly `50.0`, and the float
  form diverges from the integer form at no total in the reachable range. The integer form stays —
  exact by construction, and § 5 states the threshold in percent — but it is recorded as a
  robustness choice **with no failing case behind it**: a mutation to the float form leaves the
  suite green, deliberately.

- **card#7523** — **the store is repinned to MariaDB ≥ 11.8.6, replacing MySQL ≥ 8.0.12** (operator
  ruling, 2026-09-09). This is the DOCUMENTATION AND PINS half; host provisioning is the operator's
  and is not in it. **D2 § 6.1 does not carry its requirements across — it re-argues each one**: the
  engine row now cites MariaDB for `FOR UPDATE SKIP LOCKED` (10.6.0) and `ALGORITHM=INSTANT` (10.3.2,
  with no MySQL-style 64-row-version ceiling), and the character-set row moves off
  `utf8mb4_0900_ai_ci` — a MySQL-only identifier that spells no vendor name, which is why a grep for
  "MySQL" would have left it. The value it moves to is `utf8mb4_unicode_ci`, which is what
  `server/config/database.php` has said all along: **the document and the config had already drifted
  apart on this row and nobody could see it**, because the only thing the schema's correctness
  actually rests on is `ascii_bin` on identifier columns, and that is present on both engines.
  ⭐ **`JSON` is the one requirement that changed meaning, not just vendor.** On MariaDB it is an
  alias for `LONGTEXT` + an automatic `CHECK (json_valid(…))`, not a binary type, and the `->`/`->>`
  operators do not exist before 13.1. That is inert **here** and the reason is recorded rather than
  assumed: this repo has **zero** SQL-side JSON — every JSON column is written whole, read whole and
  decoded in PHP (re-verified this pass, not taken on trust). The same pass re-verified the other
  divergences: no `uuid`/`ulid` column in any migration, so the one driver difference the research
  names has nothing in this schema to act on; and no functional/expression index.
  ⚠ **ONE ITEM IS UNSOURCED AND IS RECORDED AS UNSOURCED, not smoothed over.** Quantified
  whole-column JSON read/write performance on a `LONGTEXT`-backed `JSON` against MySQL's binary
  `JSON` — neither vendor isolates that access pattern. § 6.1 now says in terms that this document
  claims **no parity in either direction**; it needs a benchmark, not a citation. § 6.8's row-cost
  model carries the same caveat, having been built on the binary-`JSON` assumption.
  ⚠ **`docs/PLAN.md` D-15 was superseded by an APPEND, never an edit** — that register's own rule
  (`§ Amendments`). The row stands as written; the amendment states that the engine and its floor
  move and that the *dedicated host*, the provisioning ownership and § 6.2's pinned names do not.
  ⛔ **What this deliberately did NOT change, each for a stated reason.** `bin/deploy.sh` still
  refuses any `DB_CONNECTION` but `mysql` — that is the LARAVEL CONNECTION NAME, not the server
  product, Laravel's `mysql` driver speaks to MariaDB, and moving the app to `config/database.php`'s
  `mariadb` connection would change what the deploy accepts *and* what the § 6.2 isolation guards key
  on. Its refusal text and the comment above it now say all of that; the decision is the operator's
  and is named as open in the D-15 amendment. Migrations, `config/database.php` and the suite's SQLite
  pin are untouched. `docs/sprint-burndown.html` is generated and card#7523's own title still says
  *"MySQL provisioning"* — both move when the operator retitles the card, not from here.
  ⛔ **PHP docblocks still name MySQL as the deployed engine** (`Fold`, `Predicates`, `BatchWriter`,
  `Clock`, the migrations, `MySqlColumnTypeTest`, `ingest-roundtrip.py`, …). No count is written
  here because a count is a claim with a maintenance schedule; the population is whatever
  `grep -rIil mysql server/app server/database server/tests` returns, less the identifiers
  (`MYSQL_ATTR_SSL_CA`, `database.connections.mysql.*`, `MySqlGrammar`, `MySqlColumnTypeTest`)
  which are names and not claims. They are reported as ONE class rather than fixed here — this
  dispatch is docs and pins, and a repo-wide comment sweep during parallel work is how a rebase
  eats a real change. **Every** copy of the `utf8mb4_0900_ai_ci` claim WAS fixed, in code comments
  as well as in D2, because that claim is the one this card falsifies and a corrected claim with
  false copies left behind is the drift the correction exists to remove. Also reported: `MySqlColumnTypeTest` compiles the real migrations through Laravel's
  **MySqlGrammar**, which is no longer the grammar the prod store is reached by — inert today (the
  `mariadb` driver differs only on `uuid`, which this schema has none of) and named so it does not go
  quiet.
- **card#9203** — **the PHP floor is now `^8.4.1`, and no surface restates it.** `server/composer.json`
  declared `"php": "^8.3"` while the committed `server/composer.lock` pinned `symfony/*` v8.1.5, every
  one of which requires `php >=8.4.1` — so **the repository could not install its own lockfile on the
  version it declared**. Measured, not inferred: `composer install` on PHP 8.3.33 exits 2 with eighteen
  *"your php version (8.3.33) does not satisfy"* problems, found by the first run of card#7344's lane.
  ⛔ **The severity was in `bin/deploy.sh`, and it is what this card closes.** Precondition **A6** exists
  for one reason — *"composer would refuse anyway, but it would refuse INSIDE the window, after the app
  was already taken down"* — and it carried its own hand-written copy of the floor, `8.3*|8.4*|8.5*|9.*`.
  On an 8.3 host that copy **PASSED**: the maintenance window opened, `php artisan down` ran, and
  `composer install` then failed in-window. Per the deploy contract that is exit **2** — the app is left
  DOWN, a marker is left on disk, nothing is rolled back and a bare re-run refuses. The guard was not
  broken; it was faithfully enforcing a claim that had stopped being true. The same restatement had
  drifted the OTHER way too, unnoticed: the case list accepted `9.*`, which `^8.3` never allowed.
  ⛤ **A6 now READS the constraint instead of restating it, out of the RELEASE BEING DEPLOYED.** It moved
  to after A7's ref resolution, out of letter order and commented as such, because it needs `$SHA`: the
  floor that matters is the target tree's, not the prod checkout's, and those two differ on exactly one
  deploy — **the one that raises the floor**, which is this one. Reading the checkout would have passed
  it; reading the target refuses it before anything is touched.
  ⛤ **`MEZZ_FPM_SERVICE`'s default is derived from the host's PHP** (`php8.4-fpm` on 8.4, `php8.5-fpm` on
  8.5) rather than the literal `php8.3-fpm` it used to be. An FPM unit tracks the version a host has
  INSTALLED, not the floor `composer.json` declares, so *any* literal there is wrong for some satisfying
  host. A host whose CLI and FPM pool are different minors sets `MEZZ_FPM_SERVICE`, and does not get a
  surprise for forgetting: A13 already requires the unit to exist and be enabled before the window.
  ⚠ **`composer.lock` was NOT re-resolved.** `composer update --lock` moved exactly two lines — the
  `content-hash` and the `platform.php` block, which had been saying `^8.3` while the lock's own contents
  required `>=8.4.1`. **No package version changed** — the whole locked set was diffed name-by-version
  before and after, and only those two lines moved. A dependency bump was not in this card's scope and
  would have needed its own review.
  ⛔ **The check that would have caught the mint now exists: `tools/verify-php-floor.py`.** It asserts that
  the DECLARED floor can carry every package the LOCK pins, and that the lock is in step with the
  declaration. `composer validate --strict` was clean for the whole time this was broken — it checks
  composer.json's content hash, never whether the declared platform can install the locked tree — so the
  `php-tests` lane now runs both, and derives its own `php-version` pin and cache key from
  `server/composer.json` instead of carrying the `8.4` it used to spell out. That header's explanation of
  why it deliberately did not follow the floor is rewritten, not left standing.
  ⚠ **What is still not covered, stated rather than implied:** the lane pins the floor's MINOR, so it runs
  the newest 8.4.x and never executes the exact `8.4.1` the declaration promises. That gap is closed
  statically by the new tool (it reds if any locked package needs more than the declaration) rather than
  by a patch-level pin, which would freeze CI on a PHP that stops receiving security patches and would
  depend on `setup-php` resolving an exact patch level.
  ⛤ **Seen to fail (canon #9).** `bin/deploy.selftest.sh` grew a paired section for A6 — a host one minor
  below the floor, one PATCH below it, exactly at it, above it on a later minor, above the ceiling, a
  release that RAISES the floor above what the checkout declares, an unevaluable constraint, an absent
  `require.php` and an absent `composer.json` — each refusal next to a single-variable control that
  passes. Restating the floor as the stale `^8.3` inside A6 reds that section — including the card's
  own case going from *refused* to *proceeds* — and restoring it greens it. The 8.3 fixture is given
  its own `php8.3-fpm` unit on purpose, so that A6 is the only thing that can refuse it and the exit
  code cannot go on reading 1 for an unrelated reason.
  ⚠ **Found and NOT fixed, reported rather than folded in:** `composer.lock` also carries packages capped
  at PHP 8.5 (`8.1 - 8.5`, `8.2 - 8.5`), so its true installable range is `[8.4.1, 8.6)` while `^8.4.1`
  promises `[8.4.1, 9.0)`. Nothing can reach that today — 8.6 does not exist — and the new tool checks the
  floor only, so the ceiling half is unguarded and named here rather than left silent.
- **card#9208** — **the floor map has no read surface, and that is now a ruling instead of a
  silence.** D3 § 4 renders a tiled floor from an authored `.tmj` and D2 published nothing to obtain
  one — card#8075's defect shape one layer up, where the cheap move is for the client to mint the
  surface it needs. ⭐ **Operator ruling, 2026-09-09: the map is a BUILD ARTIFACT shipped with the
  client and never served at runtime**, with the two other candidate shapes (a served map surface; the
  map riding the snapshot) declined, and **a floor edit is a redeploy** accepted explicitly as its
  cost. Recorded where each half belongs rather than where it was convenient: the read-surface half in
  **D2** — § 8.2 declares that no endpoint serves a map, § 13 row 38 is the ruling with its
  alternatives and its cost — because D3 § 1.2 makes every read surface D2's and § 1.3 forbids D3 to
  edit it; the client half in **D3 § 10.3**, which replaces *"deliberately not invented here"* with the
  answer, the artifact's path, and what a redeploy actually costs an author.
  ⚠ **D2 declares the seat→desk binding and it needs NO new wire member.** § 8.2.1 now states that
  `install_id` + `seat_id` **are** the binding and the whole of it — they ride every seat object and
  every seat-scoped message already, the desk is a pure client-side function of that pair (D3 § 3.2),
  and what was missing was the statement that they are load-bearing for the layout, not a field. No
  slot index, no desk id, no map reference is published, and § 8.2.1's field table is unchanged.
  ⛔ **`verify-floor.py`'s `S = 12` check was a decoration and is now a check.** It regexed the number
  out of D3's own prose — a sentence calling the map *shipped* while **no `.tmj` exists anywhere in
  this repository** — so the gate asserted the document against itself. G8 now resolves the artifact
  from § 10.3's declared path in either Tiled spelling (both re-derived from § 10.1's allowlist) and
  takes one of two branches, each seen to red: with a map present it counts the objects of the `desks`
  layer and reds on a count that is not `S`; with none it requires § 10.3 to **declare** the absence
  and sweeps the tree for any map that would falsify it. The gate's output says which branch ran,
  because *S held against a file* and *S held against a declaration* are different claims.
  ⭐ **The absence is now stated, not implicit:** § 10.3 declares the artifact as
  `resources/floor/<install_id>.tmj`, that none is vendored, and that card#7341 vendors the `aimla`
  one. Minting a map is deliberately NOT in this card — it needs the tileset D3 § 14 item 7 is still
  open on and a `docs/ATTRIBUTION.md` row for it.
  ⚠ **The ruling opened one question and it is filed rather than left as a consequence:** card#9085's
  console still authors and stores one Tiled document per floor, and under this ruling nothing reads
  that store but the console itself — an operator can author a floor, watch it save, and see no change
  ever. D3 § 14 item 16 carries it with the three answers and says it is an operator call.

- **card#9181** — **D2 § 2.1's process table now names `mezzanine:feed-heartbeat`, and states no
  count.** The table is what an operator provisions a host from, and it listed every process except
  the 15 s feed heartbeat — so a host built from it would supervise the fold, the sweep and the
  purge, come up green, and leave `feed.heartbeat` unsent. The client half of § 8.3 then does the
  visible damage: **a channel that sees no message of any kind for 45 s renders `feed_down` and
  reconnect-loops against a perfectly healthy fleet**, and because the timer is armed by *any*
  message, the channels that fail are the QUIET ones — exactly the case § 8.3 built the heartbeat
  for ("a quiet fleet and a dead socket must not look the same"). A `db`/`fold`/`sweep` change also
  never reaches a connected client, `FeedHeartbeatCommand::tick()` being the only caller of
  `Publisher::healthChanged()`. Nothing errors anywhere in that sequence.
  ⛤ **The opening count is deleted rather than corrected.** *"Five processes — four that run on
  their own"* is a second copy of the set standing next to it, and it is what let the omission read
  as complete: the heartbeat was built, `bin/deploy.sh` supervised it, and the figure went on
  saying five. § 2.1 now opens with "the rows of this table are the processes", states in
  terms that it carries no count, and says *add a process, add a row* — so the next daemon has one
  place to be recorded and no number to contradict it.
  ⚠ **`bin/deploy.sh` is the guard that already existed, and it now cites one section instead of
  two.** Its `DAEMON_SERVICES` default has carried `mezzanine-feed-heartbeat` since card#7459 and it
  refuses to deploy without a systemd unit for every member, so a host missing the unit fails loudly
  at deploy time — that is what kept this from being a live outage. Its comment described the set as
  "§ 2.1's population plus § 8.3's heartbeat"; § 2.1 is now the whole of it.
  ⛔ **Nothing checks § 2.1 against the processes the code actually defines** — no gate, no test,
  and `tools/design/verify-fleet-state.py` does not parse that table. The sibling audit for this
  card was run by hand off `server/app/Console/Commands/*.php`, `server/routes/console.php` and
  `bin/deploy.sh`; it found the heartbeat and two more gaps of the same shape (Reverb, which no D2
  section lists as a process to provision, and the per-minute scheduler tick `mezzanine:purge`
  needs, which appears nowhere in this repo). Both are reported with this card rather than fixed
  here — one change does one thing — together with the proposal for the check that would have
  caught all three: a G-check re-deriving § 2.1's membership from the commands the code defines.
- **card#7341** — **the lobby: `/dashboard` was a placeholder that said the floor "stays empty so
  that #7341 has nothing to delete before it can start", and it now renders the fleet.** D3 § 4.1's
  building summary, live from `GET /api/fleet/snapshot`: the floor list (`installs[].install_id`,
  ascending), a per-floor state summary in § 7.1's **fixed member order**, the fleet totals
  (`fleet.seats_total` / `fleet.seats_live`) **read from the wire and never recounted**, § 4.1's
  discrepancy render in both directions with its one-fetch-per-distinct-`(N, M)` budget, the
  membership stamp (the response's own `server_time`), and store / derivation / sweep as **three
  separate indicators** plus § 5.3's ingest recency — four sibling elements, so there is no element
  on the page that could carry an aggregate (D2 § 8.2.4: "the wire keeps them apart").
  ⛤ **Native ES modules from `server/public/js/lobby/`, no bundler and no `@vite`** (§ 1.2 leaves
  that choice to the implementer): there is no `package-lock.json` in this repository and `npm ci`
  cannot run, so a build step would be a dependency this slice could not honestly gate.
  ⛔ **THE MEMBER SET IS RE-DERIVED FROM D3 ON EVERY RUN, IN BOTH DIRECTIONS AND IN ORDER.**
  § 7.1's table is parsed out of `docs/design/FLOOR.md` and compared to
  `public/js/lobby/render-state.js`'s array position by position — a member D3 publishes that the
  client does not know, a member the client knows that D3 does not publish, and a set that agrees
  while two members have swapped places are three distinct failures with three distinct messages.
  The floor-preview README's rule is the reason: "**six** copies of one member set are what this
  replaced … a second member set, in any spelling, is how the unrecognised case gets lost again."
  ⛤ **Seen to fail before being trusted — eleven planted controls**, each mutating the shipped
  module (or the rendered page) and naming the check that must go red: § 7.1's closing anchor
  renamed (the parse silently widening), a member dropped, `thinking` added — § 6.2 A4's
  *derivation* mistaken for a wire member — two members swapped (set clean, order red), the fleet
  totals recounted from the desks (AT-D3-15's own RED), the discrepancy budget's memory removed (it
  becomes a poll), the summary's unrecognised remainder deleted (AT-D3-15's *silent* half: a seat in
  no member set falls out of its own floor's count with no throw and no glyph), a null timestamp
  coalesced to `00:00:00` (`docs/KANBAN.md § G-1`'s clean zero), an element renamed out from under
  the client (`getElementById` answering `null` — nothing throws and one fact is never rendered),
  an element nothing writes into, and a fifth indicator with no cell for it.
  ⛤ **The render assertions are driven over a REAL snapshot body**, built by the real ingest and the
  real fold through `FeedTestCase` and fetched over HTTP by an MFA-satisfied session — and the
  fixture is chosen so the two candidate orders disagree: the wire serves `aimla-impl` (`offline`)
  before `aimla-pm` (`idle`), while § 7.1 puts `idle` first, so a summary built in arrival order
  fails. The client itself runs under `node`, so what the assertions drive is the file the browser
  is served rather than a PHP re-implementation of it.
  ⛔ **TWO THINGS ARE DELIBERATELY NOT BUILT, AND NEITHER IS AN OVERSIGHT.** The **tiled map, camera,
  desks and elevator** are card#9208: D2 publishes no read surface for an authored floor map, § 10.3
  says that path "is deliberately not invented here", and § 1.3 corollary 2 forbids a guessed
  endpoint — so no map endpoint, no map fixture and no client-side map loader was minted. **No
  duration string is rendered anywhere** — card#9209: § 7.1's own exemplars disagree (`4m 12s` /
  `11m` / `2h 06m`) and § 12 carries no row, so § 5.3's two "as an age" readouts render the labelled
  **timestamp** the wire actually carries instead, subtracting nothing; a test asserts no duration
  shape reaches the page. `fleet.max_fold_lag_ms` is **not** rendered here either: its published
  form is the fleet banner's (§ 2.4, § 7.4), which belongs to the floor's status strip, and a second
  rendering of one fact is what § 2.4's one-form-per-fact rule forbids.
  ⚠ **WHAT IS NOT VERIFIED, stated rather than implied: there is no browser on the build host.**
  Nothing here has been laid out, painted, clicked or seen. "Visually apart" is bought
  structurally — four separate block elements under their own heading — and the DOM layer
  (`main.js`) is deliberately thin and decides nothing, because it is the part no check exercises.
  What IS checked is that every id it addresses exists on the page and every id the page declares is
  written into, in both directions, with the health cells' ids derived from the model rather than
  listed.

- **card#7344** — **the PHP half: CI now actually runs `server/`'s test suite.** Until
  `.github/workflows/php-tests.yml` landed, **no workflow in this repo executed a line of PHP** —
  the suite under `server/tests/` ran only when somebody remembered to run it, so every
  `php artisan test` figure in a PR body here was self-attested and unreproducible. ⚠ **The cost
  was already realised, not predicted:** `Tests\Feature\Feed\SeatObjectMatchesTheDocumentTest`
  sat RED on `dev` for days (D2 § 8.2.1 declared `blocked_since`, `App\Read\SeatObject` did not
  carry it — see the card#8075 entry below), and it was found because one agent happened to run
  the suite by hand. The guard existed, had merged, and was failing the whole time. A guard
  nothing runs is not a guard.
  ⛤ **The lane runs the repo's own entry point, `composer test`** (`artisan config:clear` then
  `artisan test`), rather than a hand-rolled phpunit command line: `server/composer.json` already
  owns what "run the tests" means here and a second spelling in CI is a second thing to keep in
  step with it. PHP was pinned to **8.4**, and NOT to the declared floor — the lane's own first run
  measured why: `composer.lock` carried `symfony/*` v8.1.5 requiring `php >=8.4.1`, so the committed
  lock COULD NOT be installed on the `^8.3` that `composer.json` declared and `bin/deploy.sh` reloaded
  (`php8.3-fpm`). ⚠ **So the lane did NOT test the version the app was configured to deploy onto** —
  those three surfaces disagreed, and that was filed as **card#9203**, a live deploy defect: on an 8.3
  host `deploy.sh`'s A6 precondition PASSED, the maintenance window opened, and `composer install`
  then failed INSIDE it. **card#9203's entry above is where that ends**: the floor is `^8.4.1`, and the
  lane now derives its pin from `server/composer.json` rather than spelling one out. With `pdo_sqlite`/`sqlite3`, composer's download
  cache keyed on `composer.lock`, and every action pinned to an exact commit.
  ⛔ **No `paths:` filter, and here that is measured rather than inherited.** The house argument
  applies (a filtered workflow produces NO RUN, which as a required check reads *pending*, never
  *passed*), but this suite has a concrete second reason: **it reads outside `server/`** —
  `SeatObjectMatchesTheDocumentTest` opens `base_path('../docs/design/FLEET-STATE.md')` and
  compares § 8.2.1's field table to the wire object, so a `server/**` filter would be dark on a
  docs-only PR editing that table, which is one of the two ways the card#8075 gap could have been
  minted.
  ⛤ **The backend is ASSERTED, not exported.** `server/phpunit.xml` leaves `DB_CONNECTION`
  deliberately unforced and `docs/design/FLEET-STATE.md § 6.2`'s argument rests on nothing in this
  repo's CI selecting a backend by exporting one; an `export DB_CONNECTION=sqlite` would have
  falsified that sentence for a value the committed template already carries. So the lane greps
  its `.env` and reds by name if `.env.example` is ever flipped, instead of silently running
  somewhere else. **Seen to fail before being trusted**, both arms: the suite red on a planted
  removal of `blocked_since` from `App\Read\SeatObject::build()` (naming
  `SeatObjectMatchesTheDocumentTest`, *"§ 8.2.1 declares fields the seat object does not carry"* →
  `['blocked_since']`), green again on restore; and the backend assertion red on a `.env` flipped
  to mysql, green on the template's own value.
  ⚠ **THIS CHANGE DOES NOT MAKE THE CHECK REQUIRED, AND THAT IS DELIBERATE, NOT AN OVERSIGHT.**
  Requiring a context is a repository-settings act reserved to the operator, so it is raised
  separately — `docs/PLAN.md § 4`'s row for this card asks for the required-check list to move in
  the same PR, and this is the half of that row that is knowingly left open rather than done
  quietly. `docs/VERSIONING.md § Branch model` is the one home of that list; **re-measured live on
  2026-09-09 while adding this workflow, as that section instructs — both rulesets still require
  exactly the five contexts it records, `updated_at` unmoved, so no copy needed updating.**
  ⚠ **The JS half of the card stays open, and the reason is measured, not assumed.**
  `server/package.json` declares exactly two scripts, `build` and `dev`; no JS test framework is
  installed anywhere under `server/`, so a JS *test* lane would have nothing to run. A
  `npm ci && npm run build` lane WOULD be meaningful — but `server/package-lock.json` does not
  exist (and is not gitignored; it was simply never committed) and `npm ci` requires one. Minting
  a lockfile is a dependency-pinning decision of its own, not a side effect of adding a test lane.

- **card#9054** — **the sibling audit: more surfaces claimed a required-check status, and the one
  "pin" standing behind them could not fail.** The v0.3.0 entry below fixed the two surfaces the
  card named. Re-deriving the population instead of grepping its phrasing found the rest, and the
  ones that mattered most used none of the card's words. `.github/workflows/deploy-selftest.yml`
  repeated the false claim verbatim (*"`card-token-lint` is the only mechanically required check"*,
  plus the same dead `card#7344` owner). ⛤ **`release-pr-guard.yml` said `card-token-lint` was
  *already* required *"so making this one required is the obvious next step"* — while
  `release-pr-guard` had itself been required since 2026-08-30**, i.e. the file describing the repo's
  strictest merge gate told a reviewer that gate did not block. `design-doc-verifiers.yml` spoke of
  *"the moment this becomes required"* about two contexts (`design-docs`, `design-artifact`) required
  since 2026-08-31, and `asset-provenance.yml` still carried *"if this check is ever promoted to
  required"* five lines under the block that had just been corrected. Every one of those headers
  now states the mechanism without a membership claim and points at the one home.
  ⛔ **The pin that was offered as the model for the others was a tautology.**
  `bin/harness-fixture-drift.selftest.py` asserted that the string `NOT A REQUIRED STATUS CHECK` was
  **present** in that workflow's header — so it reddened only if somebody DELETED the sentence, and
  never if the sentence went FALSE, which is the one direction that costs anything. It is replaced by
  the property this checkout can actually hold: **the header states no required-check status in
  either direction, and points at `docs/VERSIONING.md § Branch model` instead.** Seen to fail three
  ways before being trusted — a planted *IS required* claim, a planted *NOT required* claim (true
  today, and it must still red: no file here can verify it), and the pointer removed — each red alone
  and named its own plant; green on restore.
  ⚠ **`docs/VERSIONING.md § Branch model`'s own lead paragraph was the last unmarked copy**: *"No
  ruleset requires a status check"*, bold and first, was the one block in that stack of dated
  amendments that was never marked superseded, so the reader who skims one paragraph got the 2026-08-23
  answer. It is now marked like the rest. `docs/PLAN.md` D-12 is amended by APPEND, per that
  register's own rule, rather than edited.
  ⛤ **What is deliberately NOT fixed, and it is the class:** nothing stops the *fifth* copy. The new
  assertion holds one workflow's header; the other ten are guarded by convention. A repo-wide check
  needs a lane that runs unfiltered on every PR, which is a new context — a required-check-list
  question, and therefore the operator's. Filed rather than built quietly.

## [0.3.0] — 2026-09-09

- **card#8075** — **the CODE half: `blocked_since` was a published member no server ever
  populated.** The declaration landed in #46 — D2 § 8.2.1's row (`rfc3339_ms`, nullable, non-null
  only when `activity_state == "blocked"`), its promotion note, and D3's reconciliation — and
  **nothing followed it into `App\Read\SeatObject`** — #46's bullet landed in the v0.3.0 cut with no
  serializer behind it, and this is the half that answers it. **Both halves ship in v0.3.0**, so no
  released version ever carries the gap; `dev` carried it, between the two. What `dev` carried is a
  **contract a consumer could read and the server never answered** — a client written against
  § 8.2.1 asks a `blocked` desk when the hand went up and gets no key at all. The drift guard has
  been RED on `dev` the whole time
  (`SeatObjectMatchesTheDocumentTest` — *"§ 8.2.1 declares fields the seat object does not carry"*
  → `['blocked_since']`), which is the guard doing exactly its job; with no PHP lane in CI
  (card#7344) nothing announced it, and that is the second thing this gap measures.
  ⛤ **One read primitive, not a query in two places.** `SeatFacts::blockedSince()` reaches
  `attention_requests.opened_at` through `seat_state.open_attention_ref` and is its only home —
  the wire object (`App\Read\SeatObject`, which publishes state and derives none) and the fold's
  version-bearing FINGERPRINT (`SeatFacts::versionBearing()`) both call it, because a fingerprint
  that disagreed with the object it is the fingerprint OF would emit deltas for changes the wire
  does not carry, or withhold them for changes it does.
  ⚠ **The "only when `blocked`" invariant is ENFORCED in that primitive rather than assumed of the
  writer** — it gates on `activity_state` before it reads, exactly as `apiErrorType()` does. § 8.2.1
  promoted this member *because nothing else on the object dates the wait*, so a value that
  outlived its state would have nothing on the desk to contradict it.
  ⛤ **Version-bearing, so both edges are delivered**: § 6.5's subtraction keeps it in, so it joins
  the fingerprint and `SeatDelta::WIRE_MEMBER` in the same change — the guard asserts those two key
  sets are identical in BOTH directions, so either one alone reds a green test — and the hand going
  up and the hand coming down each ride the delta `activity_state` already emits.
  ⭐ **A value test, because the drift guard can only see the NAME.**
  `SeatObjectMatchesTheDocumentTest` re-derives § 8.2.1's field list from the document, and a
  `blocked_since` hard-wired to `null` passes it while drawing a `blocked` desk with no *waiting
  since* line — the defect the promotion exists to close. `FeedSurfaceTest` now asserts the VALUE
  against the request the fixture raised, the null against the state § 8.2.1 gates it on, and the
  delta on both edges; **seen to fail first**, both by removing the member (the drift guard reds
  naming `['blocked_since']`) and by pinning the primitive to `null` (the value test reds while the
  drift guard stays green, which is why it exists).
  ⚠ **One stale pointer fixed on the way, in a file this change was already editing**:
  `SeatDelta::WIRE_MEMBER`'s docblock credited its guard to `SeatDeltaMapCoversTheFingerprintTest`,
  a class that exists nowhere — the guard is
  `SeatObjectMatchesTheDocumentTest::test_the_delta_map_covers_every_version_bearing_member`. The
  claim is what makes that restatement admissible, so a pointer to nothing is not cosmetic. **The
  claim was audited for siblings, not just the file**: every `…Test` name referenced from a comment
  under `server/app`, re-derived from disk against the test classes that exist — this was the only
  stale one.
  ⚠ **No DDL, no event, no derivation rule and no ceiling moved** — § 8.2.1's promotion note is
  explicit that the value has always existed server-side; the only thing that changed is the
  surface it is published on.
- **card#9077** — **losing your authenticator was a permanent lockout, and the way out was already
  built.** Measured before the change: `two_factor_recovery_codes` has been a column since the 2FA
  migration, `POST /two-factor-challenge` has always accepted a `recovery_code`, and
  `auth/two-factor-challenge.blade.php` has always said *"Lost the device? Use a recovery code
  instead"* — while **nothing in the application ever displayed a code.** The offline recovery
  mechanism was wired end to end and unusable, and the challenge screen offered a path the user had
  never been given the means to take.
  ⛤ **Step 1 — the codes are shown.** At enrolment, on the screen that mints them (that branch is
  only reachable after `two-factor.enable`, which is `auth` + `password.confirm`), and again at
  `/two-factor/recovery-codes` behind `auth` + `mfa` + `password.confirm`, linked from the
  dashboard. Regeneration is Fortify's own route and action — one regeneration path, not two — with
  only its browser landing replaced, because the stock response flashes the raw translation key.
  ⚠ **The card asked for "shown once, never retrievable again" and this deliberately does not do
  that**, because the premise was false of this codebase: Fortify stores the codes *encrypted*, not
  hashed, and already registers `GET /user/two-factor-recovery-codes` returning them as JSON behind
  the same password confirmation. A view that refused to render them would not have made them
  unretrievable — it would have left that route the only way to read them and let the application
  claim a property it does not have.
  ⛤ **Step 2 — an emailed reset**, on the operator's ruling that it may go to the address already on
  the account. ⛔ **It clears the enrolment and signs nobody in:** after consuming a code the account
  has no second factor and takes the same forced-enrolment path a new account takes, password and
  all. A reset that authenticated would be a password-less login built by accident. The code is
  100 bits from `random_int()` over Crockford's base32, stored only as a SHA-256, single-use by the
  claiming `UPDATE`'s own predicate, expiring in 30 minutes, and **typed into a form rather than
  clicked in a link** so it never reaches an access log or a `Referer`. The destination is read from
  the user row and there is no request field that could redirect it. Requesting is rate-limited on
  the *submitted* address AND on the source — two limits, both applied — and every answer is the
  same sentence whether the address is enrolled, unenrolled, retired or unknown. A retired account
  is refused at both ends, so retirement is not reversible by anyone holding the mailbox.
  ⚠⚠ **The security consequence, on the record because it is the point of the feature: an email
  reset downgrades the second factor to mailbox possession.** Inherent to the mechanism, accepted
  knowingly, and the reason the address can only be intercepted and never redirected.
  ⛤ **`MAIL_MAILER=log` is now a REFUSAL rather than a fallback**, and that is a canon #20 fix
  rather than caution: `LogTransport` writes the whole rendered message — reset code included — into
  `storage/logs/`, so on the DEFAULT configuration minting a code would have put a live credential
  in a log file. `php artisan mezzanine:mail:preflight [--to=…]` is the preflight, and `docs/PLAN.md`
  § 5 carries the deployment obligation.
  ⚠ **Found while building this and fixed here (canon #7): `code` and `recovery_code` were being
  flashed into the session on every failed attempt.** Laravel flashes the whole request body on a
  validation failure and stock `$dontFlash` covers only the three password fields — so a mistyped
  TOTP code and a WHOLE RECOVERY CODE were already being written into the session, on paths that
  predate this card.
- **card#9078** — **a removed seat's desk goes immediately; retirement is an announcement, not an
  inference.** Operator ruling, which **reverses `docs/design/FLOOR.md` § 3.5** and D2 § 4.10's
  fourteen-day render window: *"A removal is a deliberate action by the operator. When an agent is
  removed, its seat and desk should go away immediately."* `App\Read\RetirementFilter::renderable()`
  now selects on `retired_at IS NULL` alone, so a retired seat leaves the snapshot, `seats_total`,
  the seat-detail and timeline endpoints and the console's agent list in the same transaction that
  announces it. **The 14-day window is retired, not kept as a backstop** — two removal paths for one
  act would leave the second as dead code free to disagree with the first.
  ⛤ **The record does not disappear, it MOVES**: the admin console's agent page gains a **retired
  seats** list (`Snapshot::retiredSeats()`, the exact complement of the read the floor renders), so
  `retired_at` / `retired_by` / `retired_reason` stay answerable with no window at all — a better
  home than a ghost desk, and it consumes no slot on a finite floor.
  ⛔ **What did not change, and must not:** removal is driven by the EXPLICIT retirement and never by
  an absence — not by a delta, a poll, a scoped read, a timeout or silence. A seat that merely goes
  quiet keeps its desk and renders degraded (`stale` at 300 s, `offline` at 900 s); that arm is
  asserted, with the whole time axis driven past every ceiling in the design.
  ⚠ **Docs and acceptance tests were rewritten, not deleted:** D3 § 3.5, § 2.3's backstop row, § 2.5,
  § 4.2's lobby case, § 5.1, § 5.6, A13, § 7.1, decisions 10 and 35, § 12's number tables and
  Appendix A; D2 § 4.2, § 4.5, § 4.10, § 6.7, § 8.2.1, § 8.2.4 and § 8.3. **AT-D2-23** and
  **AT-D3-16** now assert the new rule and carry the old one in their own text — an acceptance test
  that vanishes with the behaviour it pinned leaves no record that the rule was ever considered.
  ⚠ **Gotcha for the floor build:** `docs/design/floor-preview/floor-preview.html` still draws a
  retired desk. It is operator-ratified art direction and re-cutting it is its own change; the
  artifact gate's `retired` label comparison is exempted with that stated in the gate itself.
- **card#7459** — **the repo had no production deploy path at all.** `docs/PLAN.md § 5` + D-13 say
  prod moves only via `bin/deploy.sh` and that "hand-deploys to prod are not a path"; the file did
  not exist, so every prod change would have been a hand-deploy by construction. **`bin/deploy.sh`**
  is that path: a precondition phase that refuses before touching anything, then a maintenance
  window that takes the app down, checks out the target commit, **re-execs the deployed release's
  own copy of itself** (so the new code is deployed by ITS procedure, not by the previous
  release's), installs dependencies, migrates **forward-only**, rebuilds the caches in the order
  that matters, restarts the long-lived daemons, brings the app up and smoke-checks `/up`.
  ⚑ **The kanban-board sample this was to be adopted from is NOT reachable from this seat** —
  `share/issue-347/kanban-solo/kanban-board-deploy.sh @ ee7df9b9` is on no path here and
  `PupFuzz/agent-roundtable` answers 404 to this credential — so **no line was copied** and the
  script's header says so rather than implying a provenance it does not have. What was adopted is
  rt#347's enumeration of the sample's load-bearing properties, each implemented with its reasoning
  stated at the step it governs. ⭐ **One deliberate divergence, ruled binding by rt#347 item 1**:
  the daemons are restarted **inside** the window. The sample's host runs a shared host-scoped
  daemon serving several tenants and is silent about restarting it; this host is single-tenant, so
  `mezzanine:fold`, `mezzanine:sweep`, `mezzanine:feed-heartbeat` and — once card#7339 makes it the
  broadcaster — Reverb all hold *this* app's code in memory, and copying that silence would leave
  every deploy serving stale broadcast code with the sockets still up and the floor still
  rendering. Reverb's membership in the restart set is **derived from `BROADCAST_CONNECTION`, never
  asserted**, so it becomes mandatory the moment #7339 flips it; a Reverb unit named while the app
  does not broadcast through Reverb is **refused** rather than silently never restarted. A unit
  that restarts and then dies is an in-window failure too — `systemctl restart` returns when a unit
  *started*, which is why the state is re-read after a settle. ⛔ **Down and stay down**: any
  failure after the window opens leaves the app down, leaves a marker on disk and rolls nothing
  back — a failed MySQL migration is half-applied and `down()` would be a second guess at a state
  nobody has read yet. A **bare re-run then REFUSES** until an operator clears the marker, because
  "run it again" is the reflex that erases the evidence. The exit codes are distinct on purpose
  (0 deployed · 1 refused and untouched · 2 broke in-window and is down · 3 up but unverified).
  ⭐ **Every refusal was seen to fail before it was trusted** — the card's whole acceptance.
  `bin/deploy.selftest.sh` is hermetic and network-free (real git fixtures, the real script, the
  real re-exec; php/composer/npm/systemctl/curl/id stubbed on PATH), prints its own assertion count
  rather than pinning one here, and pairs every red with a **single-variable control that passes**:
  the same migration with and without its `ALGORITHM=` comment, the same run with and without a
  failing `migrate` — asserting `artisan up` IS called in one and NEVER in the other. It also pins
  the cache order as an ordering assertion (`optimize:clear` → `migrate` → `config:cache` → … →
  daemons → `up`) instead of leaving it to a reader, and asserts no `APP_KEY` or `DB_PASSWORD`
  value reaches the transcript. The suite was **mutation-tested**: four single-variable defects
  planted in the script (marker guard removed, post-restart `is-active` weakened, `optimize:clear`
  moved after `migrate`, the § 6.9 pattern widened) each turned it red, and it caught a real one —
  a config-drift check placed before the ref was resolved, which under `set -u` was a check that
  could not fail. ⚑ **Two deployment obligations that were prose became gates**:
  `docs/design/FLEET-STATE.md § 6.9` rule 1 says a migration on `events` states its algorithm
  *"and the deploy checks it"* — this is that check, read out of the target tree before the window
  opens — and `docs/PLAN.md § 5`'s `CACHE_STORE` rule, where `array`/`null` reintroduces the login
  path's user-enumeration oracle, is now a refusal rather than a paragraph. ⚠ **The live-host leg is
  entirely unexercised and cannot be**: the prod host does not exist (D-08). Nothing here has run
  against systemd, sudo, MySQL, PHP-FPM or a real `/up`, and rt#347 item 5 — the review with
  kanban-solo against the actual sample — is still owed. ⚠ **`server/package-lock.json` does not
  exist, so the script refuses today**: `package.json` floats (vite ^8, tailwind ^4) and a
  lockfile-less prod build can ship different JavaScript from the same commit on two different
  days. Committing it is a prerequisite of the first real deploy, not of this PR.

- **card#7684** — **The reporter mapped `PostToolUseFailure` by `is_interrupt` alone while D1
  § 6.6, amended by card#7337, required a two-signal kill signature** — so the code contradicted the
  spec on the one hook the whole kill-vs-complete contract turns on. `is_interrupt` is MEASURED
  `false` on the headline kill (a `/clear` SIGKILL reports `Exit code 137` at 2.1.245), which is the
  hazard § 6.6 names in prose and the mapping then shipped: the killed call closed `failed`. ⭐ **The
  fix is the conjunction, and neither leg may be promoted**: `is_interrupt`, **or** exit 137 across a
  session boundary — because exit 137 alone is SIGKILL in general and an **OOM kill is a genuine
  failure**, which read as `aborted` would block *idle* on a turn that legitimately finished, the
  same defect wearing the other hat. The second leg is a property of the CALL, not of the payload,
  so the mapping now runs **after** the close has matched its open entry rather than on the payload
  alone. ⛔ **The leg that cannot fire says so** rather than being resolved silently: a SIGKILL close
  that crosses no boundary — an OOM kill, or a `/clear` kill whose close beat both `/clear` signals
  — and a close whose open was never seen at all both increment `kill_close_same_session`, § 6.6's
  stated residual made into a rate someone can read. ⭐ **Seen to fail before trusted**: against the
  pre-fix reporter four of the new checks go red, headed by the `/clear` kill closing
  `('failed', None)` where § 6.6 requires `('aborted', 'interrupted')`; and both single-leg
  promotions are driven on planted copies, each producing the wrong close for the opposite reason.
  Doc-sync: § 6.6's residual records what is now driven **and what a synthetic drive cannot
  establish** — that the harness ever delivers that order, which still needs a real `/clear`; § 9.3's
  counter row gains the unknowable-open case; and § 6.0's subscription table and § 6.6's own
  `is_error` paragraph, which both still restated the pre-amendment `per is_interrupt` mapping, now
  point at the kill signature instead.

- **card#9085** — **the console's floors module, and the schema behind it.** `card#9070` shipped the
  console shell with `App\Admin\ConsoleModules` as the one list its nav and landing page both read,
  so this module lands as an **entry** in that list: nothing in the shell changed to accommodate it,
  and both of `ConsoleShellTest`'s population checks reds until its six routes were listed as gated.
  It ships the **`floors` table** — one authored Tiled map per floor, keyed by
  `docs/design/FLOOR.md § 3.1`'s `install_id` — plus the module that authors, replaces and removes
  one, and lists every floor with the seats it renders against the desk slots its map declares.
  ⛔ **It is the (a) half of the operator's 2026-09-08 ruling and stops exactly at the (b) line.**
  Nothing here names a seat: § 3.2 puts a seat at a desk by a pure function of the rendered seat set,
  "without a stored position and without a server field", and pinning one is `card#9071`'s undecided
  ruling. The absence is asserted rather than commented — over the route table, and over the schema's
  own column list, because the edit that would cross the line is one column and one form field.
  ⛤ **`S` is derived from the map, never stored beside it** — `docs/design/FLOOR.md § 10.3` makes the
  map the one home for the slot count, so a `slot_count` column would be a second home free to
  disagree with the document it describes.
  ⛤ **A map an operator pastes in is validated against § 10.1 clause 3 at the write** (CSV layer
  data, no embedded tileset image, one object layer named `desks`), because the two asset gates run
  over *files in the repository* and a document in a database column is not one. § 10.3 now says that
  in terms, including what the check does **not** cover: it cannot tell whether the tileset a map
  names was ever vendored. The base64-run heuristic of clause 2 is deliberately not re-implemented —
  clause 3 removes the base64 at its source, and § 10.1 records what that heuristic is worth on
  machine output.
  ⛤ **The seat count is `Snapshot::seats()`, the read the floor itself uses** — a second query would
  disagree with the floor about which seats exist, starting with `FLEET-STATE.md § 4.10`'s 14-day
  read filter. A floor whose install the snapshot no longer renders is surfaced on the page rather
  than dropped from it.
  ⚠ **Corrected in the same change:** `routes/admin.php` claimed "nothing here deletes a row", which
  this module makes false — a floor map is a drawing, not a record. The console still registers no
  `DELETE` verb, and that is asserted over the whole route table.

- **card#9070** — **the admin console: nobody could sign in to a fresh deploy, and no path created
  the first account.** Measured before the change: a `User` model, a users table, 2FA columns and a
  `UserFactory`, a login page and `/two-factor-enroll` — and **no `UserController`, no admin routes,
  and no artisan command that creates a user.** This ships the **console shell** (`/admin`, its own
  `server/routes/admin.php`, a nav generated from `App\Admin\ConsoleModules` so `card#9085`'s
  floors module is an entry rather than an edit), the **user module** (create, edit, retire) and the
  **agent module** (read seat state, and the existing `mezzanine:retire` act). ⛔ **No floors/desks
  schema and no seat-create path**, per the operator's (a)/(b) ruling: a seat exists because it
  *reported* (`docs/design/FLOOR.md § 3.4`), so hand-authoring one is `card#9071`'s deferred
  decision.
  ⛤ **`php artisan mezzanine:user:create` is the whole fix for the deployment blocker, and it takes
  no `--password` option** — an argument lands in argv and in shell history, so it prompts (never
  echoed) or `--generate`s and prints once. It is also the documented way back from a lockout, which
  is why retiring the **last** account that can still sign in is a **refusal** and not a warning:
  there is no registration page and no mailer, so an install with no active account would be
  unrecoverable except through this command.
  ⛤ **Accounts RETIRE, they never delete, and a retired record does not change** — `users` gains
  `retired_at`/`retired_by`/`retired_reason`,
  the same shape `seats` carries, so an account that minted a token or retired a seat cannot vanish
  and leave dangling references. The name and the address are part of that record, so
  `App\Admin\UserProvisioning::update()` refuses a retired subject **at the write**: without that,
  a rename followed by a create hands the freed address to a different person and does everything a
  delete does in two authenticated requests. (Found in review: the only thing refusing it was the
  `@unless` that hides the console's Edit link — a read-time guard for a write-site rule.)
  A retired account is refused at **every credential path** because
  the filter lives in the guard's user provider (`App\Auth\ActiveUserProvider`), not at the login
  route: the login form, the session resolved on every request, and `remember me` all funnel through
  `newModelQuery()`. Fortify's pending two-factor challenge does **not** (it resolves the challenged
  user with `$model::find()`); that is measured, named in the test file, and unreachable because
  `login.id` is only written after a credential check that already went through the provider.
  ⛤ **A seat retired from the console performs `mezzanine:retire`'s act, not a copy of it** — the
  transaction moved to `App\Fleet\SeatRetirement` at its second caller, so the console cannot skip
  the recompute, the `cause: operator` transition row or the `seat.retired` publish. A mutant that
  writes the three columns directly reds on both.
  ⚠ **Found while building this, and fixed here: `database/seeders/DatabaseSeeder.php` shipped
  Laravel's stock body**, which mints `test@example.com` with the factory's password — the literal
  `password` — and `database/factories/` was in composer's PRODUCTION autoload, so
  `php artisan db:seed` on a deployed host would have put a publicly-known credential behind the
  login page. The card's premise was that nothing created a user; something did, and it was the one
  path that must not be used. The seeder now creates nothing — **and, after review, neither half of
  the mechanism ships either:** `Database\Factories\` and `Database\Seeders\` moved to
  `autoload-dev`, and `composer install --no-dev` on a host is now a stated deployment obligation
  (`docs/PLAN.md § 5`, `README.md`). Emptying the seeder closed the instance; this closes the class,
  so the N+1th caller cannot re-mint it for free. Measured on a real `--no-dev` install from this
  lockfile: `App\Admin\UserProvisioning` resolves, `Database\Factories\UserFactory` does not.
  ⚠ **Canon #20 is asserted on surfaces that could actually carry the value** — the flashed input
  Laravel re-renders a form from, the validation messages, the command's own output, and a live log
  file with a canary line proving the capture works. A failed login for an unknown address is
  word-for-word identical to a wrong password **and now costs the same one bcrypt comparison**,
  because the stock provider returns before hashing on a miss, which is an enumeration oracle in
  timing. ⚠ **The residual first stated here was false about this deployment and is corrected:** the
  dummy hash was memoised into an *instance* property of a provider the container rebuilds every
  request, so nothing was amortised over a php-fpm worker — a miss paid **two** bcrypts and a wrong
  password on a real account paid **one**. The oracle's magnitude was stock Laravel's; only its sign
  had flipped, and the expensive side was the one an unauthenticated attacker picks. The hash is now
  paid for **once per deployment** (kept in the cache store, which `throttle:login` already puts on
  that route for both branches, and re-minted if `BCRYPT_ROUNDS` changes); the residual is one cache
  read on the miss path. The arm that missed this counted `check()` and *stubbed* `make()` — it
  counts total hashing work on both paths now, which is the property.
  ⛔ **AND THE COMMAND PRINTED A PASSWORD IT HAD NOT STORED — found in the second review round, on
  the one surface this repository calls the only way back from a locked-out install.** Rate,
  re-derived rather than relayed: 20 000 draws of `Str::password(24)` pushed through a real console
  formatter came back mismatched 132 times — 0.66%, about one run in 150. `Str::password()`'s alphabet contains `<`, `>` and `\`, and
  `Illuminate\Console\Command::line()` writes through Symfony's console formatter, whose last act
  rewrites `\<` and `\>` and whose first consumes anything shaped like a style tag. So the operator
  was shown a value that would not sign in while the account was created with the unmangled one —
  an application with no mailer, no password reset and no registration page, and an address burned
  permanently because a retired row is unrenameable. It had been reporting itself for a round as a
  flaky test (the end-to-end `--generate` arm failed about one run in forty). Every credential this
  application prints now goes through `App\Console\SecretLine`, which writes `OUTPUT_RAW` — the
  bytes given are the bytes written, so nothing has to stay a transformation's inverse — and all
  three commands that print a secret route through it rather than each being safe or not depending
  on its own alphabet.
  ⚠ **Three more from that round.** The retirement act and its command held two spellings of one
  emptiness test, so `mezzanine:retire --by="   "` walked past the command's `=== ''` refusal into
  the act's `trim()` throw and gave the operator a stack trace where the documented answer is
  `INVALID`; the predicate now lives once, in `App\Support\RetirementAttribution`.
  `UserProvisioning::update()` decided "is this account retired?" on the model implicit route-model
  binding resolved at the top of the request, so an account retired mid-form could still be
  renamed — it now re-reads under `lockForUpdate()` inside the transaction that writes, the way the
  sibling act next to it already did. And `CACHE_STORE` is now a stated deployment obligation
  beside `--no-dev`: the equal-cost login path depends on a store that persists between requests,
  and only the `--no-dev` half of the round's two obligations had reached the list an operator
  standing up a host reads.
  ⛔ **AND THE THIRD REVIEW ROUND FOUND THE SAME SHAPE IN BOTH ACTS THAT MATTER MOST.** A seat
  retirement decided its § 2.1 no-op on a read taken *before* its transaction and then UPDATEd on
  `id` alone, so two retirements of one seat both passed the guard and both wrote — the second
  overwriting the original author, reason and timestamp, and telling every connected floor the seat
  retired twice. A double-clicked console button was enough. The guard now lives IN the UPDATE
  (`whereNull('retired_at')`, the shape `mezzanine:feed-token:revoke` already revokes with), so
  `0 rows affected` **is** the no-op and no lock is load-bearing for it.
  ⛔ **And a password reset wrote the hash and nothing else, which is not a recovery from the
  compromise it exists for.** `SESSION_DRIVER=database` makes a signed-in browser's authority a ROW
  that survives a password change — Laravel's opt-in for invalidating it, `AuthenticateSession`, is
  not on this stack — and remember-me is live end to end, so a stolen session cookie or remember-me
  cookie kept working after the **only** compromise-recovery path this product has (no mailer, no
  self-service reset). A reset now rotates `remember_token` and deletes that account's
  `web_sessions` rows, **including the one the request is on**: a stolen session cookie *is* that
  session's id, so "log the other devices out but keep mine" would keep the attacker's. A self-reset
  therefore signs the operator out, and the edit form says so.

- **card#9054** — **two documents said a red `asset-provenance` does not block a merge, and it
  does.** Measured live 2026-09-08: rulesets `21222661` (`dev`) and `21222660` (`main`) are both
  `active`, both have `bypass_actors: []`, and both require the same **five** contexts —
  `asset-provenance`, `card-token-lint`, `design-artifact`, `design-docs`, `release-pr-guard`. The
  three beyond the two `docs/VERSIONING.md` last recorded were added on **2026-08-31** (the
  rulesets' own `updated_at`), **and no document moved with them for eight days**, so
  `.github/workflows/asset-provenance.yml` and `docs/ATTRIBUTION.md` each told a reviewer that a red
  there was advisory while it was blocking. ⛤ **The list is a REPOSITORY-SETTINGS fact and no file
  in the checkout can verify a copy of it**, which is why the fix is not three corrected copies:
  `docs/VERSIONING.md § Branch model` is the single home and now carries the API command that
  re-derives it, and the other two surfaces **state the rule nowhere and point there instead**. The
  standing caveat that a later-added workflow is not automatically required is KEPT — it is true,
  and it is precisely why this drifted. *(Found in review of card#8301's PR; `card#7344`, which the
  workflow comment named as owning the gap, is the app-code CI-lane card — `docs/PLAN.md`'s build
  table gives it a required-check obligation for the lanes it will add, not for this one.)*

- **card#8301** — **`docs/design/FLOOR.md § 10.1`'s closed licence allowlist admits `ISC`**, by
  operator ruling of 2026-08-31, and `bin/asset-provenance.py`'s `LICENCE_ALLOWLIST` — the gate that
  actually enforces the list, on every PR via `.github/workflows/asset-provenance.yml` — moved in the
  same change. It unblocks **card#7341**, whose Tiled map and camera are ISC ports from
  `shahar061/the-office`. The reason is written beside the member rather than left implied: ISC is
  OSI-approved and **attribution-only**, functionally MIT, so it is not stricter than this
  repository's own terms, and the list exists to keep **copyleft and non-commercial** terms out, not
  to choose between two attribution licences. ⚠ **It admits ISC, not permissiveness as a category** —
  a new RED fixture holds `Apache-2.0` refused for exactly that reason, so the ruling cannot be read
  as a class widening. ⛤ **Widening the list CREATED an obligation, so the obligation was gated in
  the same change:** ISC grants the licence only *"provided that the above copyright notice and this
  permission notice appear in all copies"*, so Gate 1 now requires ISC's permission notice in
  `docs/ATTRIBUTION.md` as soon as **any** row declares `ISC` — matched as the licence's own text,
  because a link is not a reproduction and neither is the label. Without it a row could declare a
  licence whose condition the repository never met while every check stayed green (§ 10.1 states the
  residue: it is a presence check, not a licence audit).
  ⛤ **THE OBLIGATION IS GATED IN BOTH FILES THAT OWE IT, AND THE ALLOWLIST IS NOW DERIVED FROM THE
  OBLIGATION** — corrected on review, because the first cut of this change gated only the manifest.
  `§ 10.1` and `§ 10.2` both say an ISC **port** under `resources/characters/` owes its notice in
  `resources/characters/LINEAGE.md` as well, and the lineage half of the check was hard-coded to
  MIT's text: it asked an ISC port for MIT's notice, found the MIT port's copy, and reported clean.
  The mirror defect ran the other way — an `MIT` row **outside** the character tree owed a notice in
  no file at all, because MIT reached the manifest only through the lineage file's mirror. One
  behaviour, two implementations, two guarantees. There is now **one** notice check, keyed on the
  licences the rows actually declare, called at both homes; and `LICENCE_ALLOWLIST` is the KEY SET of
  the SPDX→notice table, so **admitting the next licence is the same edit as deciding what notice it
  obliges** and cannot be done by forgetting one. `CC0-1.0` maps to `None` — a public-domain
  dedication obliges no notice — and a control holds that a `CC0-1.0` row in a manifest with no
  notice at all still passes.
  **Where each arm was watched, said precisely, because this bullet first said *the real tree* about
  runs that were fixtures:** the allowlist and both notice homes are held by
  `bin/asset-provenance.selftest.py` — **`total=64 of POP=64`**, POP re-derived from the suite's own
  source on every run (58 before this card, 60 at first review). They were **additionally** watched
  on 2026-09-08 by running the shipped script over a byte-identical copy of this repository's own
  manifest with `resources/characters/index.js` temporarily flipped to `ISC`: no notice anywhere →
  exit 1 naming **both** files; the manifest notice alone → exit 1 naming `LINEAGE.md`, **while the
  pre-review gate reported `BOTH ASSET GATES PASS` on the same bytes**; both notices → exit 0. The
  two new REDs were each seen going from exit 0 to exit 1 against the pre-change gate, and
  `CC-BY-NC-4.0` / `Apache-2.0` still exit 1. ⚠ **No `ISC` row is committed** — the manifest declares
  four rows, all `MIT` — so on the tree that ships, the ISC path is exercised **by fixtures alone**.
  **`tools/design/verify-floor.py` G5 now binds the doc to the suite:** AT-D3-12's ordinal REDs must
  run contiguously and each must be carried by a FIXTURE NAME in the suite the test names, both
  directions. Read out of the suite's `case(...)` calls with `ast`, not grepped — the first cut
  grepped the file and a mutant that stripped a RED from its fixture still passed, because the
  suite's own docstring says the words.
  **`resources/characters/LINEAGE.md § 3` was the doc this invalidated** — three ISC-derived upstream
  files were refused there *because of the allowlist*, and that reason has expired: they are still not
  taken, but taking them is now a port decision carrying a notice obligation, not a licence
  escalation. Its ISC evidence was also re-read at source (`LICENSE`, not the API's `spdx_id`, which
  is the documented trap on this chain). **Nothing was ported and no ISC asset was added** — that is
  card#7341's work, deliberately not done here.

- **card#8174 (part 2)** — **`docs/CHANGELOG.md`'s per-PR bullet rule and `docs/PLAN.md § 4`'s
  changelog size gate were both stated in the present tense and neither existed.** The bullet rule
  — every PR whose title or branch carries a `card#NNNN` token owes a line-initial
  `- **card#NNNN** — …` entry under `## [Unreleased]`, in the same PR — was prose for seven days,
  and a sweep of `dev` found **six cards across seven merged commits** with no entry, including the
  entire fleet-reporter (`card#7335`) and all three D-documents. ⛤ **Stated precisely, because the
  first count was wrong: those six split three and three.** `docs/CHANGELOG.md` was created
  2026-08-25 (`ceea110`); `card#7455`, `#7521` and `#7457` merged BEFORE it existed, so they broke
  no rule and R4 correctly reports *cannot measure* on them. **Three of three card-bearing PRs that
  merged after the rule existed missed the bullet** — a worse compliance rate than the inflated
  figure suggested, not a better one. **R4** now asserts the rule on every PR, reading its card
  grammar at run time from `CARD_RE` in `bin/promote-cards-by-token` — the same line
  `card-token-lint.py` reads, so the accept-set has one home and no copy to drift. Two carve-outs,
  both keyed on something observable rather than on a declared intent: a PR into `main` owes nothing
  (release step 4 has just emptied the section **by construction**), and a PR that REMOVES a card's
  bullet is exempt for that card (a revert of unreleased work owes the deletion, not a second
  entry) — which cannot be claimed without deleting a visible line. **R5** implements `§ 4`'s own
  formula literally — `threshold = 1 MiB − the bytes this file grew in the last 14 days`, growth
  **re-measured from git history on every run and never stored** — and refuses only a PR that makes
  an already-over-threshold file bigger, so the archiving PR that fixes it is not itself refused.
  It was built rather than withdrawn because the file went 1,182 B → 150,881 B in five days, which
  puts the contents-API truncation cliff about five weeks out rather than years. **Nine mutations
  were each seen to red**, and R4 was replayed against real history: it refuses PR #41 exactly as it
  merged and passes at the commit that backfilled it. ⛤ **Two defects in the guard itself surfaced
  only on the real surface, not in review:** `git fetch --depth=1` **re-shallows a repository cloned
  in full** — a six-commit branch collapsed to two, so R5 would have exited 2 on every PR while
  `fetch-depth: 0` sat above it looking correct — and one selftest arm asserted something false
  about itself, since a `--depth 3` clone of a three-commit history is a complete clone.

- **card#7947** — **`verify-harness-facts.py`'s `COMMON` was a hand-transcribed list of nine
  harness payload key names, and it backed most of that gate's fixture-key assertions.** The build
  declares those fields ONCE, on a base schema every hook declaration intersects
  (`<base>().and(<obj>({hook_event_name:…}))`), and the tool's key walker only ever entered the
  hook-SPECIFIC half — so the transcription was never compared against the binary at all. ⛔ **That
  is measured, not argued: 126 of the gate's 186 fixture-key assertions resolved against that
  list**, and a copy of the real 2.1.247 bundle with `cwd:e()` rewritten to `dwc:e()` inside the
  base schema — two bytes, the file's only difference from the installed build — **passed the
  pre-change gate, `HARNESS-FACT CHECKS PASS`, exit 0**. A renamed common key could not red it,
  which is the decoration canon #9 names; it is the fifth instance of the class card#7930 fixed
  four of, and the largest by assertion count. **The base schema is now RESOLVED out of the
  binary** at each hook's own declaration site — module-scoped like § 6's enum resolution, because
  `h=` has 5009 assignments in a 2.1.247 bundle — and `hook_event_name` is a CAPTURE of the text
  the hook pattern matched rather than a second spelling of it: **104 of the 186 assertions now
  resolve against the derived base set and 22 against the captured discriminant. No harness key
  name is written anywhere in the file.** ⭐ **Seen to fail, on the real bundle, before being
  trusted**: the planted rename reds with 22 failures each naming `'cwd'` and the fixture that
  carries it; a declaration stripped of its base prefix aborts naming `['Setup']` rather than
  guessing which common fields it has; and the new fabricated-builder control, pointed at a REAL
  builder, aborts saying it would confirm anything. **Both in-tool control legs name no field** —
  one spelled `"cwd" in common` would be the transcribed list back again, wearing an assert.
  ⚠ **One primitive fixed on the way, because the derivation made it hot**: the key walker
  re-sliced the whole remaining 47 MB of bundle at every depth-0 comma (`re.match(pat, s[i:])`) —
  the same defect `_assignments` records one screenful below, wearing a slice instead of an
  unanchored `\b`. Anchored as `pattern.match(s, i)` the whole gate went **8.9 s → 4.9 s** on
  2.1.247, so the added derivation costs less than nothing. ⚠ **Still NOT wired into CI, and not
  proposed for it** — the gate is fail-closed on the installed build D1 declares and card#7929's
  reasoning is unchanged, so this was validated locally against **Claude Code 2.1.247**, the build
  D1's five declaration sites agree on, and against two planted copies of it. ⚠ **The derivation
  deliberately did NOT move into `tools/design/d1_appendix.py`**: that library is the one parser
  for § 17's MARKDOWN and has two callers because both need that grammar, whereas
  `bin/harness-fixture-drift.py` never opens a bundle — hoisting a binary extractor into it would
  put a second, unrelated subject in a shared library to serve one caller.
- **card#8075** — **D3 § 7.1's `blocked` desk rendered *since 14:31* from NO D2 MEMBER, and the
  ratified floor-preview had already invented `blocked_since` to draw it.** D2 § 8.2.1's seat object
  declared nothing carrying when a wait began: the open attention request lives in § 8.2.3's
  `detail`, which is the drill-down's source and not the desk's — so the one rendered fact on that
  desk stood against § 5's own rule that a rendered fact with no field is a fact the client invented.
  ⭐ **The measurement that decided it, and it points the opposite way from the cheap fix**: a
  NON-live blocked desk already carries a real time through § 7.3's currency label, and a live one
  carries no currency label at all — so dropping the timestamp would have made the **stale** desk the
  better-informed of the two. The other candidate member is worse than absent: `activity.last_event_time`
  equals the request's time at the instant the hand goes up and then moves with the next activity
  event of any kind, silently re-dating a forty-minute wait. **card#8075's ruling took the expensive
  answer**: D2 § 8.2.1 now declares **`blocked_since`** — `rfc3339_ms`, nullable, non-null only when
  `activity_state == "blocked"`, carrying the open request's `event_time` on the seat's own clock,
  the same basis § 4.7 already measures the 60-minute attention ceiling from. It is a **PROMOTION,
  not a new fact**: `attention_requests.opened_at` is a stored column the seat row already points at
  through `open_attention_ref`, so no DDL, no event and no derivation rule moved, and § 8.2.3's
  *"~1.5 KiB of counters on every seat"* objection is priced on a drill-down payload, not on a member
  that is `null` on every seat that is not blocked. **The preview's invented field is reconciled in
  this change** — the spelling it minted is the ruled one, and both it and its README now say which
  it is. D3 carries the render row (§ 5.1), the null render (§ 5.6), the seat-clock listing (§ 2.4)
  and the § 7.1 cell's citation; the card#7966 bullet that held that cell OPEN is closed.
  ⛔ **What was deliberately NOT built: a server-clock twin, and therefore no age.** The desk draws a
  labelled seat-clock timestamp and § 2.4's four durations are still four; *waiting for 40m* would
  need a basis D2 does not publish, and minting one is a product question this card did not put.
  **Every derived figure moved and every one was re-measured, not re-typed** — the seat object
  1,807→1,828 B, the worst case 5,529→5,572 B, the worst-case delta 6,112→6,171 B and with it D3
  § 8.1's whole cap arithmetic (spare 2,080→2,021 B; the cap could still reach 15, and 16 still
  breaches). ⭐ **The gate's own count of the population it checks was stale the moment the member
  landed**: § 12 said *73 field names* beside a tool that re-derives 74 and reported clean, so
  `verify-fleet-state.py` G2 now holds that number against the table too. **Both directions of G2
  and the new count guard were each planted and watched red** before being trusted.
- **card#7946** — **`fleet-reporter/fixtures/hooks/` vendored D1 § 17 verbatim and nothing
  checked that the two agreed.** Editing § 17 alone left the fixtures stale with EVERY CHECK
  GREEN: the reporter's own `harness_payload_keys` check reads the FIXTURES (so it validates
  the copy against itself) and `verify-harness-facts.py` reads the APPENDIX (so it validates
  the original against the harness binary) — neither can see the seam. ⭐ **That is measured,
  not hypothesised: it diverged during card#7930, under the implementer's own hands, with
  every gate green, and was caught only because they happened to be editing both ends.**
  **`bin/harness-fixture-drift.py` + `.github/workflows/harness-fixture-drift.yml`** close it
  by making the fixtures a DERIVATION rather than a copy: the guard regenerates all fifteen
  files from the appendix — payloads grouped by `hook_event_name`, in document order, tagged
  with the `_source` their region declares — and requires the committed bytes to be exactly
  that, with `--write` repairing from the authority instead of by hand. ⛔ **Nothing about
  § 17 is retyped in the guard**: the appendix is located by its own heading and its section
  number is derived, the capture/stub split is read off which side of the DOCS-CITED heading
  a payload sits on, and every failure to READ the authority is **exit 2 with the reason** —
  heading renamed or duplicated, stub subsection gone, a payload parked where no region
  classifies it, an empty region — kept distinct from exit 1 so a broken gate never reads as
  a wrong fixture. **§ 17's grammar now has ONE spelling in this repo**: the shared parser
  `tools/design/d1_appendix.py`, which `verify-harness-facts.py` now uses in place of its own
  copy (same output, verified against 2.1.247 before and after), because a second parser is
  free to disagree about what the appendix contains and that disagreement is this same defect
  one level up. **Every arm was seen to fail** — nine drift arms, eleven fail-loud arms, and a
  meta-control that mutates each of the fifteen fixtures in turn and requires each to red
  ALONE naming itself, so the one green over fifteen files cannot be a comparison that
  silently skipped one. ⚠ **The gate runs but does not BLOCK** — both rulesets still require
  only `card-token-lint`. ⚠ **Nothing had drifted at the time of the fix**: today's fixtures
  are byte-identical to the appendix, so this closes the hole rather than repairing a break.
- **card#8161** — **D3's honesty principle was attributed to the operator, who disclaims it, and
  cited to a document nobody in this repository can open.** `FLOOR.md § 6.1` read *"Every animation is
  driven by a real event, or absent. — operator, via the proposal"*; the operator's verbatim ruling of
  2026-08-30 (card#7953) is *"I never forbade motion that is neither held by a delivered field nor
  caused by a delivered motion. Actually a little extra motion once in awhile is a nice touch.
  Therefore blinking LEDs on a server rack is ok as long as it is not distracting"*, and **the
  proposal is not in this repo** (tree-wide search at `dev`, with a control filename returning 0). ⛔
  **The attribution is why the rule was never re-argued** — a rule an operator handed down is not
  reopened, and a source no reader can fetch is not checked, so the design's own argument for it went
  unexamined for four revisions while the ratified reference artifact rendered motion the document
  forbade. ⭐ **The rule now has ONE home** — `§ 6.1`, as *this document's* design principle borrowed
  from the prior art `README.md` names — and the three other sites **point** rather than restate
  (`README.md`, whose *"borrowed from prior art"* framing was the honest one and is now the model;
  `docs/PLAN.md § 2`; `FLOOR.md § 0` item 3). **No site attributes it to the operator.**
  ⭐ **§ 6.3 relaxed, by SCOPING rather than loosening**: `§ 6.2` stays the closed set — for motion
  that makes a **claim**, which is the half a viewer must be able to trust, so a `working` desk still
  means working. Decorative motion is admitted and decided by **three property tests**, not a list of
  names: does anything **read** it (decision 21's surviving constraint — the wall clock was refused
  because `AT-D3-6` reads it and *"a frozen clock is not a defect and not a lie — it is the claim"*);
  would it **assert something false on a dead feed**; could a viewer read it as one of the seventeen
  (motion on an element a row draws is claim-bearing whatever drives it, which is what keeps
  `AT-D3-1`'s idle-breathing RED red). ⚠ ***"Not distracting"* is given an operational form** or it is
  a condition no reviewer can apply: **≥ 2 s cycle, opacity/scale only with no change of position, and
  never a row's visual vocabulary** — a bound written to admit what the operator ratified (`.glowpulse`
  at 2.4 s, 85%→55%) rather than to re-litigate it, with the 2 s carried in `§ 12` as **Chosen** and
  saying so. Position is excluded because *an avatar walking IS the status*, which is also why moving
  clouds and passing NPCs stay refused — by the amplitude bound, not by the claim test. **Three things
  the card did not ask for and the amendment owes anyway:** `§ 6.2`'s *"everything that moves without a
  delivered field is driven by the heartbeat"* note — which asks in its own text to be re-derived at
  every amendment — **went false** and is re-derived with what `AT-D3-6` does and does not lose;
  `§ 6.4`'s reduced-motion mechanism is **per-§ 6.2-row**, so decorative motion was outside it and now
  stops under `reduce` explicitly; and `§ 6.3` bullet 2 (*motion driven by a timer*) would have
  re-forbidden every decorative loop, since a loop with no driver is exactly what decoration is.
  ⚠ **Stated rather than covered over: no mechanised check reaches decorative motion** — it writes no
  animation-log row, so `AT-D3-1` cannot see it, and `verify-floor.py` reads the document and never
  opens `floor-preview.html`, which is how `.glowpulse` sat unnoticed (card#7929: wiring the verifiers
  is necessary and not sufficient). Review is what stands there, and saying so is the condition of
  taking the option. **No change to `floor-preview.html`** — card#7953 ruled all three sites stay.
- **card#7929** — **`tools/design/` was the only directory in this repository CI never entered:
  246 KB of verification code referenced by zero workflows and zero hooks, while D1/D2/D3 cited
  those tools as *"reds the gate"* in the PRESENT TENSE some fifty times between them.** The
  convention behind it was a runbook sentence in `tools/design/README.md`, and **a runbook
  protects nobody who does not follow it** — every D-document PR to that point merged on a gate
  that never fired. **Four gates now run on every pull request**: `verify-event-schema.py`,
  `verify-fleet-state.py`, `verify-floor.py`, and `floor-preview.selftest.mjs`, all pure repo
  reads on stock `python3`/`node` — no credential, no network, no interpreter pin to keep honest.
  ⛔ **Two are deliberately NOT wired, and named rather than skipped**: `verify-harness-facts.py`
  is fail-closed on the installed Claude Code build D1 declares, and `floor-preview.browser.mjs`
  on a headless Chromium; a stock runner carries neither, so wiring either would red every PR on
  correct work — **and a gate that reds on correct work gets disabled**. Wiring them as a SKIP
  would be worse: an *"N/A"* that reads green is a check reported as passed that never ran, which
  is this very defect one layer down. ⚠ **No `paths:` filter, for a MEASURED reason** —
  `verify-event-schema.py` resolves `docs/VERSIONING.md`'s path references and reds on any that is
  missing, so a PR deleting `bin/release-pr-guard.py` breaks D1's gate while touching nothing under
  `docs/design/`; a filter would pass that PR and leave the next unrelated one holding the red.
  **No `branches:` filter either** — a filtered workflow produces no run, which as a required check
  reads as PENDING, not passed. **`verify-design-docs.selftest.py` keeps the three document gates
  from becoming decorations**: it copies the tracked tree, plants a defect of each verifier's own
  headline class and requires a red naming the plant, asserting a DIFFERENTIAL rather than an
  absolute pass so a PR that legitimately reds a verifier still gets its real message instead of a
  control failure. ⚠ **A hole neither gate closes is declared rather than left implicit**:
  `verify-floor.py`'s G1 holds `§ 6.2`'s closed animation set against the DOCUMENT only and the
  artifact gate reads no animation at all, so **an animation in `floor-preview.html` with no row in
  `§ 6.2` is invisible to both** — which is how `.glowpulse` sat unnoticed (card#8161).
## [0.2.0] — 2026-08-30

- **card#8174** — **`docs/VERSIONING.md` specified the release act in twelve numbered steps and
  nothing enforced any of them.** On 2026-08-30 PR #38 merged `dev` → `main` green, breaking three
  documented rules at once: the head was the integration branch (which `delete_branch_on_merge`
  would have DELETED — the ruleset backstop the doc says *"do not lean on"* is what appears to have
  held, and that path had never been taken before), `VERSION` was unmoved, and there was no
  changelog section. `auto-tag-version` then failed **after** the merge — a correct report arriving
  at the one moment it costs the most, because the commit is already on `main` and the only remedy
  left is another release PR. ⭐ **The fix gates WHAT is merged, not WHO merges it**: one GitHub
  identity is shared by the agent and the operator here, so no actor-based control — CODEOWNERS, a
  bypass list, a required reviewer — can tell them apart, and every such rule would either block the
  operator or admit the agent. A content gate is actor-independent, free, and states its verdict
  while the fix is still one commit. **`bin/release-pr-guard.py` + `.github/workflows/
  release-pr-guard.yml`** assert three rules on any PR whose base is `main`, and nothing at all on a
  PR into `dev`: **R1** the head ref equals `release/` + the tag this PR would mint, **R2** `VERSION`
  is strictly greater than `main`'s by semver precedence, **R3** `docs/CHANGELOG.md` carries a
  section naming the new version. ⛔ **Every fact it needs is DERIVED, never retyped** — the release
  branch from `on.push.branches` in `auto-tag-version.yml`, the tag form from `.release-pr.json`'s
  `tag_format`, and the accepted `VERSION` spelling from that same workflow's own validation regex,
  because a private looser copy of it would green a release the tagger then reds post-merge, which is
  #38's harm shape rebuilt by the gate meant to prevent it. Every extraction failure is **exit 2, no
  fallback to a guess**, and exit 2 is kept distinct from exit 1 so a broken guard does not send an
  author to rename a branch that was fine. ⭐ **Refuses PR #38's real shape on all three rules**,
  measured against the real commits (head `7139cd7`, `main` `556ac3f`) as well as a hermetic replay;
  a well-formed release PR passes, so the reds are evidence rather than a check that always fails.
  Every rule and every fail-loud path was seen red against a single-variable mutation of that
  control, and the selftest caught one real defect while doing it: the R3 boundary was refusing a
  legitimate `## v0.2.0` heading. ⚠ **The gate runs but does not BLOCK** — both rulesets still
  require only `card-token-lint`; requiring context `release-pr-guard` is a one-line ruleset edit,
  and the workflow deliberately carries **no `branches:` filter** so it is safe to require (a
  filtered required check produces no run, which reads as *pending*, never *passed*). Steps 5, 6, 8,
  9, 11 and 12 stay unenforced on purpose, named in `docs/VERSIONING.md` rather than covered by a
  gate that would claim to have checked a judgement when it had checked a string.
- **card#7966** — **D3's § 7.1 Label line column published strings that its own rule statements
  forbid, and the reason no gate could say so is that the COMPOSITION of those strings was published
  nowhere.** Two known instances, one root cause, and a sweep of all ten cells that found three more.
  ⭐ **The root-cause fix is a stated convention, not the two corrected strings**: § 7.1 now declares
  that its Label line column is a **worked instance and never a rule**, so where a cell and a rule
  statement disagree the **rule governs and the cell is the defect** — which disposes of instance N+1
  without a round trip — and that where two **rule statements** disagree neither the table nor its
  reader settles it, because picking makes one of two ratified statements silently dead.
  **`stalled`** read *API error — rate limit*: § 7.6's **phrase** with the raw wire value **elided**,
  against § 7.6's own column heading *The line beside the raw value*, § 5.4's *"the line carries the
  raw string either way"*, § 5.1's *rendered verbatim* and **the same row's own `Never` column**
  (*"`api_error_type` is always on the line"*) — one row contradicting itself, and five statements
  outranking one example. ⛔ **Correcting the string was half of it**: the order and separators of
  raw value + phrase were stated by **no site in D3 at all**, which is exactly what let a second
  reading be minted, so **§ 7.6 now publishes the composed line once** — *API error — the raw value
  (the phrase)*, **(unrecognised)** in place of the phrase for a thirteenth value, **API error**
  alone for a null — and § 7.1's cell is a worked instance pointing there rather than a second copy.
  **`retired`** was the mirror image: § 3.5 asserted **one** string for **both** surfaces, in the
  words *"rendered on the desk's label line **and in full in** the drill-down"* — self-refuting,
  because a string that is *in full* on one surface is not the summary on the other. § 7.1's shorter
  string stands; § 3.5 now states **two strings for two surfaces**, each site naming the other, and
  answers the clock-basis question at source rather than by invention: `retired.at` is a **server**
  clock (D2 § 8.2.1), and D3 labels a **seat** clock precisely because it is another machine's claim,
  so neither string takes a label — exactly as the `stale` desk's *no data since 14:18* carries none.
  **A third site was corrected on the same reading:** § 5.1's `api_error_type` row said the value is
  *rendered verbatim* and then illustrated it with *rate limit* — § 7.6's **phrase**, where the raw
  value is `rate_limit` — so the site stating the rule was showing the reader the value the rule
  forbids.
  ⭐ **The class now has a gate that did not exist: `verify-floor.py` G11**, an eleventh guard class
  holding a **worked example against the rule statement governing it**. § 7.6's twelve member/phrase
  pairs are re-derived from that table on every run and both rendering sites are held against them;
  nothing is stored, and each predicate is **fed its own defect on every run** and must reject it.
  Seen to fail three ways before being trusted, each on its own message and each restored: § 7.1's
  cell reverted to the pre-fix string, § 5.1's illustration reverted to the phrase, and § 7.6's
  phrase drifted with § 7.1 left untouched — the third proving the guard catches drift from **either**
  end, which is why its message now names both sides instead of blaming § 7.1.
  ⚠ **The downstream carve-outs went with it, because an exemption outliving its reason is a
  permanently weakened check wearing a stale justification**: `stalled` is no longer in
  `floor-preview.selftest.mjs`'s `LABEL_NOT_COMPARABLE` (three members, now two), its literal is
  compared to § 7.1's cell like every other member's, and a new planted control re-mints the pre-fix
  elision **from the artifact's own composition** and requires the comparison to go red on it — it
  does, with *want "API error — rate_limit (rate limit)", got "API error — rate limit"*. The two
  property-based assertions that stood in for that comparison are gone as the weaker restatement they
  became, and the artifact's comment recording the tension now records its resolution.
  ⛔ **The sweep's remainder is NAMED IN THE DOCUMENT rather than left for whoever builds the floor**,
  because a clean column with an unnamed remainder reports where the searcher stopped: **`blocked`**
  renders *since 14:31* from **no field at all** — no § 5 row carries it and D2 § 8.2.1 declares no
  such timestamp, the open attention request living in `detail`, which is the drill-down's source and
  not the desk's — against § 5's own rule that a rendered fact with no field is a fact the client
  invented; **`working`** renders a descriptor § 5.1 assigns to the monitor, and states nothing for
  the A4 seat whose turn is open with no call, where that descriptor does not exist; and
  **`catching_up`** carries a second wording of the labelled seat-clock timestamp § 7.6 fixes once.
  All three turned on one question D3 did not answer — whether § 7.3's currency label and § 7.1's
  Label line are one rendered element or two — which is **rule against rule**, so the convention
  reserved it rather than letting the first round pick it.
  ⭐ **That question is now ANSWERED, and the answer closes two of the three: TWO rendered elements.**
  § 7.6's `activity_state` table — the section that **owns** that render form — says *under the label*
  in five rows; § 7.3's `catching_up` and `disabled` rows said *in the label only*. Rule against rule
  was rule against **defect**, and the sanity check is what makes it more than a word choice: read as
  one element, a `catching_up` desk's single line would carry *replaying history — last event 12:47
  (seat clock)* **and** *was: working (last event 12:47, seat clock)* — the same field, twice, in one
  line. **Both cells are corrected to *under the label***, and the sibling sweep found a **third** the
  ruling had not seen: § 7.6's own `link_state` table said *activity state in the label only* for
  `catching_up` too. § 7.3 now states the two-element rule and names § 7.6 as the form's owner, and
  § 7.6 says it owns it — so the next divergence has a governing statement to lose against instead of
  a second opinion to argue with.
  ⇒ **`catching_up`'s Label line drops the restated timestamp** — *replaying history*, the state
  sentence, with `activity.last_event_time` left to the currency label that owns it. **Not drift: a
  real redundancy the one-element reading hid**, because under that reading the repetition read as a
  paraphrase rather than as one field rendered twice on one desk.
  ⇒ **`working`'s Label line is *working*, the state's own sentence** — and the alternative was
  refused on the document's own rules rather than on taste. It may not be the descriptor (§ 5.1 gives
  that to the **monitor**, and that row's null case sends the monitor back to *the desk's state line*,
  so a repeat would point the fallback at a copy of what it stands in for and would say nothing at all
  for the A4 seat whose `action` is null); it may not be *turn open, no call* or any other wording of
  `open_turn` / `open_calls`, which A3 / A4's pose already renders; and it may not be **nothing**,
  because **AT-D3-5 and AT-D3-13 both assert all ten members are pairwise distinguishable BY LABEL
  LINE**. A bare state word is the only string left that renders `render_state` and no second fact.
  ⛔ **`blocked` is NOT fixed here, and that is the finding rather than an omission.** It renders
  *waiting on a human since 14:31 (seat clock)* from a timestamp **D2 publishes no member for** — the
  open attention request lives in `detail`, the drill-down's source, not the desk's. Dropping the time
  deletes the one thing a raised hand is for and inventing a field is what the preview already had to
  do, so it is a **D2 gap, not a D3 drafting error**, carried as its own item (**card#8075**) and live
  for the build (card#7341). The cell is untouched; § 7.1's remainder paragraph is the notice, and it
  **stays** — a document naming its own known-contradictory cell is the whole point of that paragraph,
  and the alternative is a document that reads complete while a cell is known wrong.
  ⚠ **The downstream carve-out moved again, and for the second time the exemption's REASON was what
  expired**: `working` is no longer in `floor-preview.selftest.mjs`'s `LABEL_NOT_COMPARABLE` (two
  members, now **one** — `unknown` alone, whose literals are the sibling table's). Its stated reason
  was *"the line is a wire field, not a fixed string"*, and D3 has now ruled that the line is not the
  wire field. Both newly-comparable literals owe proof the comparison can fail **on them**, so both
  got a planted control that re-mints the pre-fix expression from the artifact's own code: `working`
  reds at *want "working", got "Bash: composer test"*, `catching_up` at *want "replaying history", got
  "replaying history — last event 12:47 (seat clock)"*. Both run on **every** invocation.
  The artifact (`floor-preview.html`) follows D3 rather than the gate being relaxed to follow it:
  both labels are now constants. ⛔ **One honest consequence, declared rather than hidden:** the
  descriptor now has **no desk render in the preview at all**, because that file draws the monitor as
  a tint and not as text — § 5.1's monitor is unbuilt there, which is card#7341's to draw, and the
  timestamp's new home is a surface the artifact's own `D3_SCOPE` already declares *not implemented*.
  ⭐ **And the placement got a GATE, because a corrected string with nothing holding it is how the
  first two instances arrived.** `verify-floor.py`'s **G11** — still eleven guard classes, this is a
  second **fact** inside the one class it already names, *a worked example against the rule statement
  governing it* — now re-derives the placement phrase from § 7.6's five `activity_state` rows and
  holds every worked instance against it. **Nothing is stored:** the five rows must agree with **each
  other** (if they do not, the tool reports the rule as disagreeing with **itself** and judges no
  instance — that is a rule-against-rule amendment, not a defect in any example), the population is
  found **structurally** (any table cell carrying a *was:* span or naming the `activity state`, so a
  fourth site is in it the moment it exists), a run finding **none** reds rather than reporting clean
  over an empty set, and the predicate's own defect arm substitutes a preposition the rule does **not**
  use, picked from the recognizer's alternation. Seen red **six** ways, each on its own message with
  `FLOOR.md`'s md5 verified identical after every restore: each § 7.3 row reverted; § 7.6's
  `link_state` row reverted; one § 7.6 rule row drifted; **all five rule rows moved with the instances
  left behind — three reds, which is the leg following the OWNER rather than a string typed into the
  tool**; and the rule table's header renamed so the rule is never read at all.
  ⚠ **One carve-out, and it is a finding rather than a convenience: § 12's own guard-class rows are
  excluded by role.** A row documenting a guard necessarily **quotes the defect it guards** — § 12's
  G11 row quotes *in the label only* in order to say what was wrong — so a recognizer that read it
  **fails on the correction and passes a silent fix**, getting redder the more honestly the write-up
  is done. It fired exactly that way on this leg's own documentation row before the carve-out existed.
  § 12 renders nothing; G9 already excludes those rows by the same role.
  ⚠ **And one hole is DECLARED rather than closed**, because the fix for it was worse: the leg asks
  whether a cell **contradicts** § 7.6, never whether it states the placement at all, so a cell that
  re-words the placement out of the recognizer's vocabulary escapes by matching nothing. The stricter
  tier — every cell carrying a *was:* span must contain § 7.6's phrase — was written, run, and
  **removed**: it red on § 7.1's own corrected `catching_up` cell, which says the form is drawn
  *under this line* while pointing at § 7.3 and § 7.6. That cell is right; it **mentions** the form in
  order to say the timestamp is not the Label line's, and no structural test here can tell a mention
  from a placement, so the literal rule would have red on a careful paraphrase and passed a careless
  overwrite. All three limits are written into § 12's own Status cell rather than left in a commit
  message.

- **card#7897 (part 2, slice 1)** — **the coordination-event producer is designed in D1, and the card
  is corrected twice on the way.** `EVENT-SCHEMA.md` gains § 18: Mezzanine's own GitHub webhook
  receiver, deriving two fact objects — `coord.thread` and `coord.round` — from a coordination
  repository's deliveries. ⛔ **Correction 1: the producer is NOT the `agent-webhook-bridge`,** which
  the card specifies (*"all bridge-produced"*). § 1 non-goals a bridge dependency outright on D-10,
  and the card's route is additionally **unbuildable**: the bridge's `HandlerRegistry` resolves ten
  handlers and not one is a generic HTTP forwarder, so consuming its stream would have meant a
  feature request into another team's write-side actuator, on our critical path. ⛔ **Correction 2:
  two objects, not three** — `coord.message` was already collapsed into `coord.round` by the card's
  own ruling, and the brief re-listed all three; every coordination act is a post on a thread, so a
  third object is a second format for one fact. ⭐ **The bridge's classification behaviour was
  re-derived AT SOURCE** (`v0.77.0`, `f85b419`) as prior art, and § 18.3 records eleven findings with
  their file — DL-252's actor-vs-thread-author split, the three-member `AUTHORING_ACTIONS` allow-list,
  DL-002's shared identity, DL-035's frozen label, the body-`TO:` addressing rule, and DL-176's
  signed-body dedup key. ⭐ **The card's honesty audit is FALSIFIED in three places, and each is
  stated rather than designed around.** *(A)* The `from:`/`to:` labels are **not** a property of the
  `opened` delivery — the coordination repo's integrity Action materialises missing labels from the
  body *after* the issue exists, and the posting tool auto-adds a `to:` label to a live thread, so
  the addressee set is neither reliably present nor frozen; the bridge's own source records **641**
  such `issues.labeled` deliveries already dropped on the reference install. The derivation therefore
  keys on the body's `FROM:`/`TO:` lines wherever it needs an **address**, which is the scope the
  protocol makes them authoritative for — ⚠ **not membership, which is label-authoritative, as the
  round-4 bullet below establishes and this line originally elided.**
  *(B)* **A convergence has an act and may have no actor**: `issues.closed` is not an authoring
  action, so under shared identity the closer is unrecoverable, and the phrase and the close arrive
  as two different deliveries; the floor may show *that* a thread converged and never *who* closed
  it. *(C)* **The escalation flare is a WON'T-DO** — its claimed observable, a gated / USER-ACTION
  post, is barred by protocol from coordination threads (the banner is chat-output only), so
  `needs_human` is deleted rather than carried permanently false, with the closure act named as a
  protocol change. Also settled: the family gets **its own endpoint, HMAC auth (a third auth mode)
  and validation order**, because nine of the batch envelope's invariants are false for a webhook
  delivery and admitting it would weaken that path for the reporter too; **no post body ever
  transits**; and one producer serves both consumers, the `coord.*` family and D2 § 4.9's tier-2 task
  title.
  ⭐ **A review round then found the same defect SHAPE at three sites, and fixing the shape is most
  of what changed: a rule derived over one population, applied to a second without re-deriving it.**
  *(1)* § 18.3's derivability test excluded *"the bridge's configuration, which this producer cannot
  see"* and never asked the same question of the **coordination repo's own** config, equally
  invisible — so four fields reached for it in four unstated places. § 18.3.1 is the consolidation,
  and two of the four dissolved: `carrier` reads the title's bracketed token **syntactically** with no
  set consulted (measured: all 728 real titles carry one, and the six distinct tokens are exactly the
  six configured), and `participants` stops expanding `all` because `targets` already does it once.
  What is left is **one declared input** provisioned with the hook registration — `roster[]` and
  `shared_identity` — carrying the config revision it was copied at, and **guarded**, since a
  body-derived name absent from the copy reds `coord_roster_unknown_name` on that seat's first post.
  *(2)* § 7.3's redaction rules are derived over **argv** and § 18.10 claimed to reuse them
  *unchanged*, *"covered for free"* by § 7.5's fixtures. Both false: measured over all **728** real
  coordination titles, the pass corrupts **7.0%** of them, **49 of the 51 hits being rule 4's
  bare-whitespace separator firing on English** (`token that` → `token ‹redacted›`) — and § 7.5 could
  not have caught it, because all 13 fixtures were `(tool, command-or-path)` pairs. § 7.3 now declares
  two **profiles** differing in exactly one rule (`coord.subject` requires an explicit `:`/`=`), which
  takes the damage to **0.7%** while still redacting every credential shape planted in a title;
  narrowing rule 4 for *all* callers was refused because it deletes what fixture 9 holds. § 7.5 gains
  **fixtures 14–17** and a **caller** column, and AT-2 gains a fourth RED that fails in **both**
  directions. *(3)* Decision 45's no-rate-limit argument is sound only for deliveries that **pass**
  step 3; a request failing the HMAC is by construction not GitHub's, so § 12.3's failed-auth limit is
  reused for it and `coord_signature_invalid` counts a refusal that previously left no trace.
  ⛔ **`converges` is deleted, because it could not make the distinction it existed to make.** Measured
  over the coordination repository's **entire** comment population — 10,552 comments, with a positive
  and a negative control — the *"zero open questions"* phrase is on **78.1%** of posts: it is a
  per-post sign-off, so the flag was true **11.5** times per closed thread and `converges + closed`
  collapsed to `closed`. The protocol's `[CLOSE]` token is on **2.12%** of posts, **37.8%** of closed
  threads and **0.6%** of open ones — so `declares_close` keys on that, named for what the post
  declares rather than for a thread property it cannot establish. It **gains** a fact finding B looked
  to have lost: all 224 anchored `[CLOSE]` comments carry a `FROM:` line, so the closer is nameable
  where `issues.closed` never names one. **Thread-level convergence is a QUORUM and is now declared
  NON-DERIVABLE** — no observer-CC flag, no required-participant set, no ACK ledger — rather than
  approximated. **AT-26 asserts the RATE over a replayed corpus**, because the defect is statistical
  and every per-post assertion passes under both implementations.
  ⚠ **Two claims this entry itself made are corrected.** *(a)* It said the obligations D2 and D3
  inherit were *"recorded as requests"*; **no request was recorded anywhere in the diff**, and § 18.11
  said the opposite in terms. There is still **no D2 or D3 edit** — what those documents inherit is
  cited at its site and carried in § 18.13, and the join key is ruled on `card#7957`. *(b)* § 18.8
  step 7 claimed the idempotency key *"cannot expire into a double-derivation"*. True of the
  constraint's form, false of any store: the reporter's equivalent is bounded by § 11.3's 8-day spool
  residency and **GitHub's Redeliver has no such floor**, so an old redelivery re-derives. Marked
  `D2-CITED:` at its site — a gate that structurally could not see the class now can — with the
  residual in § 18.13, and **steps 7 and 9 now commit together**, so a failed derivation leaves no
  digest to absorb the operator's retry. Also corrected: `to` falls back to labels on a **measured 30.1%**
  of posts, not the 15.7% a 300-comment sample suggested; the § 18.3 prefix row no longer restates a
  set its own source says *"do not restate"*; `lifecycle`'s unreachable unrecognised-value clause is
  gone; AT-24's fixture no longer wants two `issues.opened` on one thread and AT-25's no longer
  depends on a config switch that is **off** here. New overall: **AT-26**, **decisions 46–48**,
  **§ 18.3.1** and **§ 18.8.1**, eight § 14 rows, three § 16 build-order rows, four § 7.5 fixtures.
  ⛔ **The route counters — ten then, eleven now — are declared in § 18.8.1 and deliberately NOT
  enrolled in § 12.7**:
  membership there is an obligation on the store's counter plane, and enrolling them makes
  `verify-fleet-state.py` red once per counter — which is how the obligation was found, and it is
  filed in § 18.13 rather than papered over.
  ⛔ **A second review round then found three rationales that were false about their own sources, and
  all three are re-derived rather than re-worded.** *(1)* **`participants` no longer reads labels
  ALONE at `closed`/`reopened`** — its justification, *labels are more current than the body*, is
  false at source, because the integrity Action **materialises** missing labels from the body's
  `FROM:`/`TO:` lines and the `closed` delivery carries the body it has at close, already listed in
  § 18.13 row 1 among the keys read. The cost was concrete: a thread whose labels had not arrived
  emitted `participants: []` at its close, **losing both endpoints of the thread line at the moment
  the floor renders it**, while `issue.body` in that same delivery named them. **AT-25 gained a fourth
  RED at the `closed` moment** so the rule has a check that fails rather than a paragraph that
  asserts. ⛔⛤ **The repair that round shipped — body-only, labels supplying no member at any moment —
  is SUPERSEDED by the round-4 entry below, and its stated premise was FALSE.** It is described here
  as it was, so the correction below has something to correct, and every claim it rests on is
  withdrawn there by name. *(2)* **§ 18 never said what the hook
  SUBSCRIBES to**, which is the root cause of § 18.13 row 5 claiming the `issue_comment.edited`
  deferral was un-backfillable *because the hook was not subscribed* while pricing the fix, in the
  same cell, as *"one action in step 8"* — a subscription problem and a derive-set problem at once.
  § 18.8 now declares the subscription (**two whole events, every action**: `issues` and
  `issue_comment`) as the deliberately wider set, with step 8 as the narrower derive set, written
  granularity-independently so no unread claim about GitHub's registration model is load-bearing. It
  also decides the default riding on it: **a step-8-ignored delivery commits NO digest**, so a later
  widening is backfillable from GitHub's delivery list instead of being absorbed as a duplicate — and
  row 5 is re-derived from those two facts, bounded honestly by a vendor retention nothing here can
  read. *(3)* **The step-3 rate-limit bucket is keyed `(route, source IP)`**, a bucket of this route's
  own. § 12.3's limit was priced over **seats sharing with seats**; this endpoint is unauthenticated,
  so a shared bucket would have let anyone on the internet spend the refusal budget only a bad-token
  holder could previously reach — decision 45's *"the same trade § 12.3 already accepted"* was
  understating a population change, which is the round's own defect shape a fourth time. The
  distributed-source residual a per-IP key cannot cover is now named rather than implied. Also
  corrected, none of it load-bearing: AT-2's first RED said *"all 17 fail"* where fixture 14 passes
  under an identity sanitizer (the fourth RED covers it, and § 7.5's own preamble said the same thing
  wrongly); `coord_name_malformed` had **three disagreeing definitions** and now has one, at § 18.8.1,
  covering both callers and both causes with absence distinguished from malformation; § 18.13's
  *"`posted_at` is `null` on a `coord.thread`"* is scoped to `closed`/`reopened`; § 15's *"rows 46–48
  are all one shape"* enumerated 47, 48 and **45**; and the one 8-character source pin in a document
  that uses seven is gone with the sentence that carried it.
  ⛔⛤ **A third review round then REFUTED the second round's own repair at source, and `participants`
  is now the UNION of the body-derived names and the `to:`/`from:` labels.** ⚠ **This paragraph
  supersedes a claim that is published in an uneditable place** — the commit message of `e2b0be1`, and
  the paragraph above it — that *"a `to:` label naming anyone the body's `TO:` line does not is a
  structural hard-fail … so the label set is a **subset** of the body-derived set by construction and
  reading labels can only lose names."* ⛔ **That claim is FALSE and inverted, and this bullet is where
  it is withdrawn**, because a commit message cannot be corrected in place and a reader who finds it
  first must be able to find this. Three mechanisms, each read at `e9bc22a`: the integrity workflow
  subscribes `issues: [opened, edited]`, `issue_comment: [created, edited]` and
  `pull_request_target: [opened, edited]` and has **no `labeled` trigger**, so a label added to a live
  thread is validated by nothing; the hard-fail **detects and repairs nothing** — the script's only
  label mutation on any path is `addLabels`, and it removes none; and `DESIGNS/protocol-spec.md`
  § Recipient addressing states that **membership is label-authoritative** and that widening it *is* a
  deliberate label edit, which the gate's own remediation text repeats. **A label-widened thread is
  the protocol's PRESCRIBED way to widen membership, not a violation nobody has caught yet.**
  ⭐ **Measured over the whole live population 2026-08-28 — 729 non-PR issues: 36 (4.9%) whose labels
  name someone the body does not, and ZERO in the opposite direction** — so the body-only rule was not
  merely unproven, it was strictly **lossier** than the rule it replaced, on 36 real threads. Issue
  `#635` is the worked case and is now AT-25's fifth-RED fixture: body `FROM: pm` / `TO: magento`,
  labels `from:pm, to:pm, to:magento, to:platform, to:moodle` — body-only drops `platform` and
  `moodle` while the delivery carries both. **Documenting it as a known miss was explicitly refused**;
  it would ship a published field knowingly wrong 4.9% of the time and rename the defect as
  documentation. ⭐ **AT-25 gains a FIFTH RED because the fourth could not catch this**: the fourth
  pins *"do not read labels alone"*, so it passes under every body-reading rule including the body-only
  one — which is how the defect shipped past five green gates. § 18.5's *"the protocol names that
  source authoritative"* is scoped to **addressing**, where it is true, and away from **membership**,
  where it contradicted the spec sentence § 18.5 itself quotes. `coord_participants_unlabelled` is
  kept — it guards the no-source-at-all boundary — but its live population is **0 of 729** and it is
  no longer cited as the counter that would have caught this; the **new `coord_participants_label_only`**
  counts the union's disagreement case, with a known expectation of 36 of 729. Also corrected in the
  same pass: the *"14.6 times per closed thread"* ratio divided two different populations and the
  honest same-population figure is **11.5** (6,526 phrase-carrying posts on closed threads ÷ 568
  closed threads, 2026-08-28); § 18.6 re-minted finding A's **641** as *"unlabelled deliveries"* when
  it is a count of `issues.labeled` **deliveries** on another system's stream (`CoordinationClassifier`
  at `f85b419`) and **0 of 729 live issues lack an addressing label**; § 18.13's *"two of these nine
  are closed by one act"* is **two fully and one partly**, since three rows name a capture; § 12.3's
  failed-auth limit is keyed `(route, source IP)` to match the bucket § 18.8 actually reuses; and
  § 4.1's endpoint table now lists `/api/ingest/github` by pointer.
- **card#7976** — **the acceptance suite leaked one live flusher daemon per run, and the mechanism
  was not the one the card described.** `Seat.freeze_flusher` writes `flusher.lock` so a hook
  observes a live owner (§ 2.3) instead of forking a real flusher into an exact-count assertion —
  legitimate precisely because it uses the product's own liveness rule and disables nothing. But
  that rule is `now() - lock.mtimeMs < LOCK_STALE_MS`, `now()` is whatever `FLEET_REPORTER_NOW_MS`
  says, and the freeze used **wall time**. So the one hook the spool-overflow entry directly below
  this one pinned **two hours ahead**
  (§ 4's aged-out drop, `of_at + 2 h`) read a just-written lock as two hours dead, concluded
  correctly by § 2.3 that no flusher was alive, and forked a real detached one with no exit
  condition — **within 35 s of the run starting, not after the 90 s expiry the card described.**
  Measured at `cbc2a6a`: a full 163 s run took the box from 12 live daemons to 13, and the one it
  added carried `FLEET_REPORTER_CONFIG=…/overflow-aged/config.json` with
  `FLEET_REPORTER_NOW_MS` 54 minutes ahead of wall time.
  ⚠ **Two corrections to the filed report, both measured rather than argued.** *(1)* The 90 s
  expiry is real but **latent**: no seat here is driven late enough on a real clock to age out of
  its own freeze, and a run in which it fired would have leaked more than the single daemon
  observed. *(2)* The dozen daemons accumulated on the reporting workstation were **not this
  suite's** — their configs are `/tmp/tmp.*`, `/tmp/mezzanine-roundtrip-*`, `/tmp/rtprobe-*` and
  two scratchpad paths, while every seat here lives under a per-run `/tmp/fr-suite-*`. They came
  from ad-hoc probe rigs, which is a separate leak with a separate owner.
  ⛔ **So the obvious fix — re-freeze before every hook — was rejected on evidence rather than on
  the docstring's disclaimer**: re-freezing on wall time leaves a pinned invocation exactly as
  stale, and would have fixed none of the leak that was actually happening.
  **The freeze is instead pinned to the clock the invocation will read**, in `Seat.env()` — the
  one place that both decides `FLEET_REPORTER_NOW_MS` and is reached by every invocation,
  including the AT-10 and AT-16 writer bursts that build their hooks in generated source and never
  call `hook()`. The lock is then 0 s old under whatever clock that hook uses, which is the state
  the freeze always modelled and never achieved when pinned; `flush()` opts out
  (`env(freeze=False)`) because it *is* the flusher and must find no lock.
  ⚠ **Reached by every invocation, but re-applied per `env()` CALL — and those two coincide
  everywhere except the writer bursts.** AT-10 and AT-16 call `env()` once per worker `Popen` and
  the worker then drives 40 (resp. 15) hooks off that one freeze, so for those two paths the
  window is **narrowed, not closed**, and the "0 s old" property does not extend to their hooks
  2..N. Measured under the bursts' own concurrency rather than extrapolated from the idle p99:
  the lock's age at the last hook is **22.6 s** (AT-10) and **6.5 s** (AT-16) against
  `LOCK_STALE_MS` 90 s — a **4x** margin, and AT-10 would have to grow ~4x, to ~159 hooks per
  worker, before a hook in it read its own lock as stale. Left open deliberately: closing it
  requires a second freeze implementation inside generated worker source which could only
  re-stamp the lock on *wall* time — the exact defect fixed above — and would be silently wrong
  the day a burst is given a pinned clock. § 17 is the guard, and it fails loudly.
  ⭐ **The freeze is prevention, and prevention that fails is silent — so the run now also
  MEASURES.** A new § 17 sweeps `/proc` for flusher daemons whose config lies under this run's own
  temp directory, **fails** the suite naming each one, then reaps them; scoping identity to the
  run's `mkdtemp` is what makes reaping safe beside another checkout's daemons or a concurrent run
  of this same file. **A control daemon is planted first** — a wall-clock freeze plus a hook pinned
  two hours ahead, the defect verbatim — and the sweep must find it and then see it gone, because a
  sweep that passes by finding nothing is worth nothing until it has found something. Where
  `/proc` cannot be read the sweep prints `SKIP` and is reprinted as *not measured*, never as a
  pass. Shown to discriminate: with the clock-correct freeze reverted the run reds four checks and
  names both leaked daemons by config path.
  **CI was not accumulating these.** All six workflows are GitHub-hosted `ubuntu-latest`, whose
  per-job VM is discarded, so nothing carries across runs — this was workstation hygiene. One
  in-job consequence was real, though: `fleet-reporter.yml` runs the suite twice in one job and the
  second step is the latency gate, whose own comment has it running "on an otherwise-idle job". A
  daemon leaked by the first step was alive throughout the second, so that premise did not hold;
  the end-of-run sweep restores it. ⚠ **No claim is made that these daemons affected any
  measurement** — they idle at 0% CPU and their contribution has never been measured.

- **card#7965** — **the ratified floor-preview is a reference implementation of a subset of D3 and
  never said which subset, so an absent render surface was indistinguishable from one that was
  missed.** The artifact is what card#7341 builds FROM, and the reader who needed that answer is
  that card's implementer. Three surfaces already said the artifact was *partial*; none said *of
  what*, and `docs/design/floor-preview/README.md`'s *"encodes, as working code, every design
  ruling of the 2026-08-26/27 operator sessions"* — true of the operator's **design** rulings —
  reads as coverage of D3's **render surfaces**. **That conflation was the defect, not the
  absence.** ⛔ **The alternative was declined on the product, not on cost:** completing the preview
  against D3 would make it a second full implementation of D3's render surfaces — the floor built
  twice — and card#7341 a port of it rather than the build. There is one build. So the artifact now
  carries **one machine-readable scope table**, a row per membership-tested render surface `FLOOR.md`
  § 5.4 publishes (the same six `verify-floor.py` **G7** closes over): *implemented*, naming the
  render table it is implemented by; *partial*, for `link_state`, which renders **raw** in two
  drill-down blocks and is **not** membership-tested; or *not implemented*, naming why in one clause
  (§ 7.2's eighteen badges, § 7.6's `activity_state`). It is rendered on the page from that same
  table rather than re-typed, and the membership test itself is **derived** from it, so a row is the
  one place a surface's status is written. ⭐ **A declaration with no check is a comment, and this is
  the case where that bites**, so `floor-preview.selftest.mjs` re-derives § 5.4's six from the
  document — from **both** of § 5.4's enumerations and its own count in words, cross-checked — and
  set-differences them against the table **in both directions**: a surface D3 gains with no row reds,
  and a row for a surface D3 does not publish reds. **D3 gaining a seventh surface now reds the gate
  instead of passing silently**, and each direction has its own anchored mutation control, one of
  them a D3 that *grew* a surface rather than one that broke.
  **Three defects in what the artifact does implement, all in the same class and none reachable by
  the member-set primitive card#7943 landed — they are keyed on IDENTITY, not on an enum member.**
  (1) A seat's `desk` against the floor map: the SVG iterated a **fixed four-slot list** and
  silently **dropped** any seat outside it, while the overlay pass read `THEMES[inst].desks[s.desk].x`
  and **threw the whole render away** — one fact derived twice, failing in opposite directions on the
  same seat. § 3.2's overflow rule and § 9 **F13** state the behaviour that is neither: the surplus
  seats render in a **labelled overflow row** with the same desk and the same render, under a
  persistent notice reading *floor map is short N desks*, and F13's *Never* column is one phrase —
  **dropping a seat**. Placement is now **one pure function** of (map, seat list) that both the SVG
  and the overlay pass read, and the map's slot count `S` comes from the map. (2) Three unguarded
  `THEMES[…]` lookups threw on an install the client's art has never seen; all three now resolve
  through one function, and the unthemed floor declares **no slots**, so § 3.2's overflow rule is
  already the whole answer for it — every seat of an unknown install renders, labelled. (3)
  `subagents[].subagent_type` was defaulted to *"intern"*; § 5.6 says the type tag is **not drawn**,
  so it is not, and a substitute stated a fact the wire never sent. ⛔ **`title || "untitled"` beside
  it was NOT changed and must not be**: § 5.1 gives a null title the literal *untitled* and AT-D3-4's
  first RED is falling back to `subagent_type`, the tool name or *"subagent"* — the two nulls are
  different rules, and treating them alike would break the compliant render to fix its neighbour.
  Both are now one function with a control per direction. **The sample fleet carries a seat with no
  slot on the `sola` floor and interns for both null edges**, because a behaviour no sample reaches
  is one no implementer sees — the artifact is where card#7341 reads what these render like. The
  gate carries a planted mutation for every layer, and **how many is counted at run time and printed
  on the run's own last line** rather than restated in the files that describe it.
  ⭐ **And the overflow row was drawn INSIDE the floor — on top of sola's tea bar — while every
  check above passed. D3 § 3.2 had already said where it goes: BELOW THE FLOOR.** Four passes read
  § 9 **F13**'s summary row, which names the row and its notice but not its position, and § 3.2 at
  `FLOOR.md:537` is what owns the position. Drawn inside the floor, the row needed machinery to be
  safe — a per-theme `overflow:{x,y}` origin, a declared "clear run" between the tea bar and the
  plant, a stated row capacity, and a pairwise desk-versus-furniture collision gate over an
  arbitrary-shape bbox extractor. **All of it is deleted, because the question stops being asked**:
  an overflowing floor is now drawn on a canvas one band taller and the row lives in that appended
  strip, so the floor's shapes stop at `FH`, the band's start there, and collision is impossible by
  construction **at any row length**. What replaces the collision gate is one invariant with one
  scalar per floor in each direction — **nothing the floor emits reaches below `FH`, and nothing the
  band emits rises above it** — with a planted-mutation control for each leg (furniture pushed below
  the line; the band raised back up into the floor), each seen red first. ⛔ **And an unmeasurable
  shape now makes that assertion FAIL.** The deleted layer guaranteed *unmeasurable is loud* and the
  guarantee was false in four demonstrated forms — an arc command, a `<polygon>`, a `NaN` width and
  an unmodelled `stroke-width` — three of which stayed **green** with an obstacle drawn on the desk,
  because each fell through a silent `else` and left a shorter list of boxes that still looked
  clean. Every element is classified now, a tag the measurement does not own is named, and the
  answer is *not measured* rather than a number. Two of the four are now MEASURED instead — an arc
  is bounded by ±2r about its endpoints, a stroke pads its shape by half its width — and the other
  two are planted controls, beside the two generalisations of the same class (a path command the
  measurement does not implement, a transform it does not model). ⚠
  **Still not evidence about the picture**: this is arithmetic on the artifact's own emitted
  coordinates, so z-order, opacity and the difference between a box and the ink in it are outside
  it, and where a bound cannot be computed exactly it is computed **wide** so that its only possible
  error is over-reporting.
  ⭐ **AND THAT LAST SENTENCE IS WHY THE ROW MOVED AND COLLIDED AGAIN — this bullet's own fix was
  the fourth in one class, and the fifth round stopped patching it.** With the band correctly below
  the floor, the sample seat's thought bubble landed **on the band's own arithmetic line** (the slot
  and seat counts, behind an opaque white bubble) and its nameplate hung **out of the strip**, chip
  and command line over the lobby. Every gate above was green, and the root cause is structural, not
  arithmetic: **the artifact has two coordinate systems** — SVG user units, and HTML overlays
  (`.bub`, `.plate`, `.mk`, `.hit`) positioned over that SVG but **sized in CSS pixels** — and
  **every gate ever built here measured only the SVG layer**, so each round moved SVG content
  correctly and re-collided with a layer in no denominator. § 8's own overlay leg made the same
  mistake one level down: it compared the bubble's anchor against `FH`, which is where the strip
  *begins* — and the strip begins with the header the row must not cover.
  **The upstream fix is `tools/design/floor-preview.browser.mjs`**: assert geometry from
  `getBoundingClientRect()` in a real headless browser, where both layers share ONE coordinate
  space, so *does the bubble cover the header* is a rect intersection between things that were
  actually laid out — **no transform parser, no arc bounding, no stroke padding and no *not
  measured* category**, none of which is a question once the browser has done the layout. It asserts
  its client↔user-unit map against a measured element before deriving any region through it; that
  **no overlay covers any line of the band's header**, measured with the browser's own glyph widths;
  that **every overlay is inside the storey region its own `data-band` declares** — the artifact
  now declares that, and the gate checks the declaration instead of believing it; and that **every
  child of `#world` is either judged or a declared animation affordance**, so an untagged overlay
  class reds rather than sitting outside every check. Its population is two room widths × three
  framings, and **widths are the only axis that can falsify anything**: `#world` scales the SVG and
  the overlays together, so an overlay's user-unit size depends on the room's width alone — measured
  across all six cases, not assumed. **No browser is a FAILURE, never a skip.** Its controls re-mint
  head `fe482eb` whole — both constants at the values that shipped — and require both defects to go
  red on their own messages, beside a floor-side containment defect, a lying `data-band` and an
  untagged overlay. The two instances are fixed by giving the band a budget for the **whole overlay
  stack**: the desks move to `FH+300` (the bubble's anchor clears the header's own deepest bound by
  40 user units) and the strip to `520` (the nameplate is `53.8` CSS px, so the strip must cover
  `300 + 64 + 53.8·BW/room`, which carries down to a 655 px room — derived by the gate, not stored).
  § 8's static invariant is **kept** as the cheap no-browser backstop, with its overlay leg now
  held against the header's re-derived extent and a control planted at the value the old `>= FH`
  leg passed. ⚠ The browser gate measures **boxes, not ink**: opacity, z-order and legibility are
  outside it, and opening the file remains the last word on how it looks.
  Two adjacent limits are **stated rather than left to be inferred**: `deskSVG` hangs the intern
  tray *outside* the slot, so at n ≥ 2 a seat with subagents reaches into the next one, and past
  roughly n = 12 the clamped slab is narrower than the character drawn on it — both are `deskSVG`
  re-layouts and belong to card#7341's build; and `placeFloor` is **order-dependent**, so it is a
  pure function of the seat *list* and not of the seat *set*
  § 3.2 asks for — § 3.2's own answer is the slot function `h(seat)` with forward probing, which
  this preview does not implement and card#7341 does. **A gate hole in the same class was closed in
  the gate itself, at every site that had it**: an `indexOf` result was used as a **slice bound**,
  and `indexOf` answers `-1` for a heading that has moved — `slice(i, -1)` is *the document minus
  one character*, not *to the end*. A § 5.4 whose closing anchor had moved silently widened the
  population to the rest of the document, where six surface names are still findable, and the gate
  printed `ok parsed 6 render surfaces from § 5.4` over a § 5.4 that published none — **the
  false-clean shape this whole deliverable exists to prevent, inside it.** Fixing that site alone
  left the others, and renaming § 7.1's closing anchor `### 7.2 Badges` widened its slice to a
  quarter of the document with the ten `render_state` members still parsing out of it, so the gate
  stayed PASS. All of them go through **one `sliceBetween(md, open, close)`** that returns `null`
  when either anchor is missing, every caller treats `null` as a failure rather than an
  empty-but-clean parse, and one control renames each closing anchor in turn and **prints** what the
  replaced shape would have widened to. ⚠ **The widths are not written down here, and that is the
  point rather than an omission**: an earlier revision of this bullet and of the primitive's own
  doc-comment each carried one, and both were stale within two commits — a figure restated inside
  the argument that restated figures drift is that argument demonstrating itself. The run prints
  them. § 5.5's notice is asserted **in full** against the
  re-derived shortfall rather than by its opening words, which left the count and the noun free to
  drift and stay green.

- **card#7952** — **the spool-overflow check read a wall clock it never meant to depend on, and reds
  on CORRECT behaviour when a run straddles a top-of-hour by more than the grace below.**
  `… and drops nothing while deferring`
  asserts that a hook over the 2 KiB bound refuses to unlink the live bucket. That refusal is
  **hour-keyed**: `enforceSpoolBoundFromHook` defers while the oldest bucket is the current-hour
  bucket, and once that hour has ended plus `BUCKET_GRACE_MS` the same hook **drops it, correctly**
  (§ 11.3). The 20 hooks were driven off the real clock and take **~5.0 s measured** — the same order
  as that **5 s** grace — so a run crossing the roll watched the reporter do exactly the right thing
  and failed the assertion for it. Reproduced deterministically under a pinned clock: a simulated
  crossing that ends past the grace drops the pre-roll bucket (`spool_dropped_events=5`), and pinning
  the roll to just after the first hook reproduces the **exact reported `expected 0, got 1`**; the
  same crossing held *inside* the grace, and a pinned run that does not cross at all, both stay green
  — so it is the crossing, not the pinning. **The contention it was blamed on is real but is the
  amplifier, not the cause:** no drop path in the reporter is reachable without the oldest bucket's
  hour having ended, so load can only red this check by stretching the block past the 5 s grace —
  and a ~5.0 s block against a 5 s grace has essentially no margin to lose. ⛔ **Not fixed with a
  retry and not by loosening the assertion**: the assertion was right and the reporter was right, and
  the harness was the wrong side. The 20 hooks are now pinned 30 minutes into the previous UTC hour,
  where no run duration — loaded, slow disk or otherwise — can reach a boundary. ⭐ **The pin also
  turns the accident into coverage.** The hook-side aged-out drop, § 11.3's other half, was executed
  only when a run happened to straddle the roll — the flake *was* its only exercise — and is now a
  stated check that one hook two hours on drops the bucket and counts **all 20** lines. Both arms are
  shown to discriminate: with the hour guards planted out the deferral assertion reds (`dropped 20
  while deferring`), and with the drop planted out the complement reds — **the latter being a defect
  the block could not previously catch at all**, because a reporter that never drops satisfied every
  assertion it contained.

- **card#7943** — **the ratified floor-preview carried SIX copies of one enum's member set, four of
  them covering only the four `render_state` members its frozen sample fleet happens to contain.**
  `FLOOR.md` § 7.1 defines **ten**, and § 5.4 / § 9 F9 / AT-D3-11 require an eleventh case — a
  member the client does not know — to render as **explicitly unrecognised, carrying its raw
  string**, never mapped to the nearest known member and never a crash. `GL`, `SCREENC`, `POSE`
  and `labelFor`'s switch each broke that, and **the loud one was the least of them**:
  `GL[s.render_state]` destructured `undefined` and threw a TypeError that
  killed the whole render, while `SCREENC` emitted `fill="undefined"` into the SVG, `POSE` drew a
  default creature and `labelFor`'s switch fell through to `undefined` — three SILENT wrong renders
  that look like a render and report nothing. ⭐ **A FIFTH site was found by re-reading the
  subsystem rather than by grepping the field name, and it was silent too:** the lobby's per-floor
  summary iterated § 7.1's fixed member order, which over a wire value is a **filter** — a seat in
  an unrecognised state fell straight out of its own floor's count (four seats at the desks, three
  on the line), which is AT-D3-15's *the lobby never invents a count* failing in the direction
  nobody watches — **and it was MASKED by the crash above.** The desk loop runs over every floor
  before the lobby does, so at the pre-fix revision an unrecognised seat anywhere threw out of
  `GL[…]` and no lobby line was ever drawn to be wrong; removing the crash is what makes the
  undercount the live failure mode. It had to land in the same change for exactly that reason: a
  fix that converts a loud failure into a silent one is not a fix.
  **The repair is one table and six derivations, not six patched call-sites**: one
  row per § 7.1 member in § 7.1's own order, carrying the desk render, pose, marker, chip class,
  monitor tint, currency and label line as cells, and one explicitly unrecognised row beside it.
  The member set is now written **once**; patching the sites independently is what minted six
  copies of it in the first place. **Reachability, stated rather than implied:** unreachable today — the artifact ships a
  fixed sample fleet — and reachable the moment card#7341 feeds it a live wire, where D2 can
  deliver any of the ten. ⛔ **The unrecognised desk keeps its character and is drawn NOT-CURRENT.**
  An empty chair is the render of `stale` and `offline` (§ 7.1), so drawing one for a member nobody
  recognises would be the nearest-member guess arriving through the desk instead of through the
  chip; the character is the seat's identity redrawn, which § 5.4 admits explicitly and which says
  nothing about state. ⭐ **`api_error_type` gets the same treatment, because § 5.4 gives it the
  same rule** — *"rendered verbatim **and** membership-tested… Reading the two rules as
  alternatives is what would make one of them dead"* — and § 5.2's drill-down table has no row for
  it, so the desk line is its only surface. § 7.6's twelve now render as **the raw value with the
  published phrase beside it**, a thirteenth renders with the **unrecognised** marker, and an
  unrecognised value in *any* membership-tested field the desk draws — not only `render_state` —
  makes the desk not-current. Without that, AT-D3-11's second RED would have arrived through a
  different field in the release that fixed it for the first. **⚠ Reported to D3's owner, not
  settled here:** § 7.1's Label line cell writes the example as *API error — rate limit*, eliding
  the raw value that § 5.4, § 5.1 (*rendered verbatim*) and § 7.6's column heading (*The line
  beside the raw value*) all require. Two rule statements and a heading outrank one abbreviated
  example.
  **Four label cells were not § 7.1's Label line and three of the four were visible on the
  ratified artifact**, carried in from the old switch: `idle` dropped ***finished — ***, which is
  the half that makes it a *positive observation* rather than the silence § 7.5 refuses; `blocked`
  dropped ***(seat clock)***; `stale` and `offline` wrote *— 11m* where § 7.1 writes *— **no data
  for** 11m*. Consolidating six copies into one primitive is where the primitive owes being right.
  **Visible changes to the ratified picture, and only these:** the `stale` sample desk gains the
  not-current treatment § 7.1 and § 7.3 already specified for it (*empty chair, desk dimmed*), and
  four sample desk labels gain the § 7.1 text they were missing. The floor SVG is otherwise
  byte-identical.
  **The check is `tools/design/floor-preview.selftest.mjs`** — Node, no dependencies, no network.
  It re-derives **three** published tables from `FLOOR.md` and compares them **cell by cell**:
  § 7.1's ten members *with their Label line column*, § 7.1's seven `unknown_reason` sentences,
  and § 7.6's twelve `api_error_type` phrases — so a member added to D3 with no row reds it, and
  so does a rendered string that has drifted from the document's. ⛔ **The first revision of this
  suite parsed only the member NAMES while three surfaces claimed it checked the sentences**; a
  sentence rewritten to say the opposite of D3 ran green. Fixed, and the fix is proved by
  mutation. **Seen to fail**: 38 checks red against the pre-fix artifact, plus five anchored
  mutations — the pre-fix lookup, the lobby filter, a rewritten sentence, a rewritten phrase, and
  a label cell reverted — each required to go red on its own, because a check that only ever ran
  behind a crash, or only ever ran green, is a check nobody has seen work.

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

- **card#7335** — **the producer half of D1**: `fleet-reporter/` — one zero-dependency Node file
  driven by Claude Code hooks and by the statusLine integration, D1 § 17's captured payloads
  vendored beside it, and a hermetic acceptance suite. This is D1 § 16's artifacts 0–4; the ingest,
  the server-side ledger and the staleness/predicate alarms are D2's and card #7338's. Built to the
  card's three properties, each driven RED before GREEN. **Never blocks the seat** — one try/catch
  per entry point with `process.exit(0)` in a `finally`, no stdout from a hook on any path, every
  write a synchronous append; the network modules are required **lazily**, which makes that property
  structural rather than observed (a hook process never loads an HTTP client at all) and takes 67 ms
  of module loading off every hook fire. **Survives the bridge being down** — hour-bucketed
  append-only spool, per-bucket read offsets, exponential backoff with full jitter, and a poison-pill
  rule that costs one bad batch its own events and never the stream behind it; every discard is
  counted, and a 30-hook outage followed by a restore delivers every event by id. **Programmatic end
  to end** — every field is read from a hook payload or from process state, no model is asked to
  describe itself, and the descriptor allowlist is the control with redaction as the second layer.
  ⛔ **Five defects the suite found, every one of them silent in production**: a new flusher could
  never claim ownership of `state.json`, so the cursor and `seq` never persisted and every restart
  re-sent the spool from `seq` 1 — **dedup absorbs duplicate events but not a duplicated ordering
  key**; a single `(bucket, offset)` cursor permanently skipped any bucket older than itself, losing
  the straggler writes § 11.1's deletion grace exists for, with nothing counted; corrupt spool lines
  were re-quarantined and re-counted on every retry pass, inflating the loss numbers the floor
  renders precisely during an outage; `selftest`'s own sanitizer fixtures incremented the seat's live
  `sanitizer_redactions`, a diagnostic moving an operational counter; and the statusLine passthrough
  threw `ERR_UNKNOWN_ENCODING` and swallowed it, blanking the status line of every seat that had one.
  ⭐ **Review then found that the known-VALUE leg of `redactSecrets` had no live subject at all.** The
  function has two independent legs — values the process holds, and shapes (`CRED_PREFIX_RE`) — and
  every credential in the suite carried a shape the regex already matches, so **deleting the value
  leg outright left the whole section green**, reproduced before it was fixed. The only secret ever
  registered was `config.token`, which by validation always carries the `mzn_` prefix, while
  `proxy_url` is `https://user:password@host:port` and § 3.1 constrains nothing inside it — an
  arbitrary password, matched by no prefix in the shape list, held in memory by every hook process.
  `registerConfigSecrets` now registers every configured secret, and the new test isolates the leg
  with a control in each direction. ⚠ **Two false-clean traps caught while building it are named
  rather than passed over**: the first sweep read the spool line the harness itself had planted, so
  RED and GREEN reported the same hit; and the corrupt line was initially placed behind a valid
  event, where the disposal rule commits it only on a successful POST, so against an unreachable
  proxy it never reached the sink and the sweep was reading a file that did not exist.
  ⛔ **A failed spool append lost the event UNCOUNTED** — against D1 § 0 item 9 (*a counter for every
  discarded event*) and § 11.4 (*nothing is discarded uncounted*), and reproduced rather than
  inferred: with the current bucket made a directory and `counters/` + `log/` left writable, a real
  `PreToolUse` gave rc 0, zero spooled events and a counter sink reading `"c":{}` — **the seat
  renders healthy while dropping events**. **Fixed in the PRIMITIVE rather than at the four
  call-sites, only one of which was correct**: `appendLine` counts its own failure keyed by subtree
  and retries once through an uncached open, which previously spent exactly one event per transient
  error. Two more of that round: the bucket name is now derived immediately before the write, which
  the primitive's own header had always claimed and only `journal()` implemented; and
  `projectLabel()` returned `basename(cwd)` *before* sanitizing, so § 7.3 rule 6 could never match a
  bare username token and `cwd=$HOME` put **the OS username on the wire** — literally conformant with
  § 6.1 and a § 1 non-goal violation.
  ⇒ **D1 was the wrong side once**: § 6.1's `harness_label` pattern could not accept the value that
  same row mandates (`claude-code/2.1.240` contains `/`), and left alone, an ingest implementing
  § 6.1 literally would 422 the first correctly-configured seat, reject all 200 events in the batch
  and quarantine them permanently. The pattern is widened, and the check now re-reads the pattern AND
  the example out of the doc row and asserts them against each other, so the two copies cannot drift
  again. ⚠ **The latency assertion moved behind `FLEET_REPORTER_PERF=1` on measurement, not
  preference**: subtracting a baseline was tried and MEASURED to fail — interleaving baseline and
  reporter samples so both see the same load gives an attributable median of **53 ms** idle against
  **82 ms** and **163 ms** under six busy cores, with the p99 difference swinging **−1,070 ms** to
  **+520 ms** on identical code, so the p99 is noise outright and the median — the better statistic —
  still triples under load. What is asserted on every run is what is not load-sensitive: every hook
  exits 0 and prints nothing on stdout on every adverse path. ⚠ **`K.FLUSH_MIN_EVENTS` was DELETED
  rather than wired** — § 11.5's early-flush leg needs the flusher's whole duty cycle restructured,
  the 10 s leg bounds the delay either way, and a defined-but-unread constant reads as an implemented
  trigger to anyone grepping for it. ⚠ **Named rather than assumed**: a real Windows install
  (card #7336), a real `/clear` against a real subagent (AT-1, card #7337), the `proxy_url` CONNECT
  path, and reachability against a real ingest host are all unverified here. Suite: 189 → 217 checks,
  exit 0 (219 under `FLEET_REPORTER_PERF=1`).

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

- **card#7456** — **D2, the fleet-state model and feed contract** (`docs/design/FLEET-STATE.md`):
  everything between a durably-accepted batch and the floor — the per-seat state model, the MySQL
  store on its dedicated host, the fold, retention, the server-side counters and the two read
  surfaces, with twenty-three acceptance tests each carrying its RED, plus the mechanical verifier
  `tools/design/verify-fleet-state.py`. D1 owns the wire and is cited, never restated: its five
  `D2-MUST` constraints and the **twenty-nine** further places D1 addresses this document are
  enumerated in Appendix A, one row each, pointing at the section that discharges them.
  ⭐ **State is a PURE FUNCTION of stored facts, not a stored state machine** — five facts, one
  precedence, recomputed each fold pass, because a machine has states that can be entered and not
  left (the one-way trapdoor D1 had to fix twice) and a function over bounded facts cannot; every
  open fact carries a stated ceiling and `offline` quiescence is the backstop under all of them.
  ⛔ **DELIVERY IS NEVER ACTIVITY**: every receipt-derived timestamp is named for receipt and may
  drive only transport states, activity claims come only from the seat's own turn and tool events,
  and `reporter.heartbeat` is explicitly not one. The fleet PM channel's maxim — that a stamp which
  refreshes only when a seat posts *"corroborates; it cannot exonerate"* — is quoted verbatim and
  attributed, and **AT-D2-4's RED is the single line that would break it**.
  ⇒ Also settled rather than deferred: the feed's ordering key is a server-minted `state_version` and
  **not** D1's `(seq_epoch, seq)`, because orphan closes, staleness, ceilings and quiescence mint
  transitions with no wire event behind them, so a `seq`-ordered feed could not sequence precisely
  the transitions that fire when a seat goes quiet; `blocked` outranks `working`, stated loudly
  because it is the one place two D1 rules are simultaneously true and D1 states no precedence;
  fail-posture is stated per path and never inherited; and **`events` is deliberately NOT
  partitioned** — MySQL requires every unique key to contain every partitioning column, so a RANGE
  partition would force the dedup key to include the date and `D2-MUST` #3's dedup would silently
  stop working. Retention is 14 days because the dedup guarantee IS the unique key on `events`, so
  the chain 8 d spool < 10 d dedup < 14 d retention is one inequality that moves together. **Four D1
  amendment needs are RECORDED in § 14 rather than edited into D1** — closed later on card#7521.
  ⛔ **Six of round 1's 35 findings needed design changes rather than wording**, chief among them
  that **the frozen-fold detector was written only by the fold, so it died with the thing it
  detects**: `fold_lag_ms` is now computed at read time from a basis the ingest and the fold write
  separately, and its operand is the OLDEST unfolded event rather than the newest, which reads ~0 on
  a busy seat. § 4.5's five self-referential link-state predicates became an ordered cascade with a
  total function and a defined value for a silent seat, and leaving `live` now CLEARS `stalled` and
  `blocked` at the stale boundary while only MASKING `idle`, with the asymmetry and its reason
  stated.
  ⚠ **The review loop's stop-observable fired on the INSTRUMENT, so the instrument was repaired
  first** and the document fixes were validated under the repaired tool. G5 was a bare substring
  search over the whole cited section — vacuous for 24 of 50 § 12 rows, and **a live 2 s → 7 s drift
  passed green**; G6 built its cited set from the whole row, so D2's own § links satisfied a
  D1-citation requirement; G3 hard-coded 8940/86400, 8192 and the seat counts 4/50/200. Each is now
  re-derived from its definition site and PERTURBED per row to prove the match can fail, with the
  residue printed honestly — **64 of 81 proven discriminating, 17 not** — instead of counted as
  passes. **G8 checked writers against rows and not rows against writers**, so a § 7.2 counter no
  rule increments was invisible (measured: re-adding `offline_quiesced_attention` passed green); the
  mirror direction's first run found five pre-existing rows with no writer. **The mirror then had to
  be tightened from a bare name match to WRITERSHIP** — a declaration list satisfied the old
  predicate, and so, per the second gate, did a sentence DENYING the counter exists — after which its
  first run named four more, one of them the live sixth instance the previous pass could not see.
  ⛔ **A sibling audit of one shape — a D2 claim about D1 content asserted without reading D1 — found
  the delta-volume population missing `compaction.start` / `.end`**, which § 3.2 declares ARE
  activity events: 8,940 → **8,980/seat-day**, with an independent cross-check the document now
  carries (every D1 kind but the heartbeat, so D1's own ceiling sum 10,420 − 1,440 = 8,980). All
  eight occurrences and every dependent figure were re-derived rather than restated, and G3 re-adds
  the sum from the document's own components and is proven capable of the other answer.
  ⇒ **AT-D2-14's hostile export is a GREEN, and the row asserting an abort was a check that could
  never fire.** The row claimed the test-store guard aborts under `DB_DATABASE=mezzanine php artisan
  test`. It cannot, while the pin is intact: PHPUnit's `<env>` writes only `putenv`/`$_ENV`, a shell
  export lands in `$_SERVER`, and Laravel reads `$_SERVER` first — so the `<server>` twin beats the
  export, **the resolved value never moves, and the guard has nothing to refuse**. Measured during
  card #7334's build: the suite PASSES under that export. **A check that can never fire is the mirror
  of the check that cannot fail**, so the row now asserts the green and reads the refusal from the
  third RED, which deletes half a pin — the only lever that moves the resolved value. The sibling
  audit found the same false claim in four more places, all corrected: § 6.2's proof bullet, the
  AT-run-order row, the closing summary, and `docs/PLAN.md § 3`'s acceptance line for the
  MySQL-provisioning card (card #7523), **where it would have sent a future builder chasing a refusal
  that cannot happen and then weakening a correct pin to produce it**.

- **card#7457** — **D3, the floor UI specification** (`docs/design/FLOOR.md`): everything after a D2
  JSON object reaches a browser — the lobby, the floor and the desk drill-down, the identity mapping,
  the closed animation table, degraded rendering, every failure path with its observable, asset
  provenance as a build gate, and seventeen acceptance tests each with its RED. D2 owns the state
  model and is cited, never restated; the **thirty-eight** places D2 addresses this document and the
  **twelve** more D1 does are enumerated in Appendix A, one row each. Ships
  `tools/design/verify-floor.py` — eight guard classes, every population re-derived from D2 rather
  than stored in the checker, each watched failing against a planted defect before its pass was
  claimed.
  ⭐ **THE CLIENT DERIVES NO STATE, and the seven things it computes for itself are a CLOSED list**
  (§ 2.1): every rendered fact names the D2 field it comes from, and the verifier reds when a source
  cell names a field D2 does not send. A closed list is checkable against a candidate computation;
  *"only presentational"* is not.
  ⭐ **The honesty principle is a TABLE, not a principle** — sixteen animations, each with the wire
  field or message that drives it and the exact edge that starts it, and **an animation with no row
  is a defect**. The renderer must log `(animation_id, seat, cause)` for every animation it starts,
  which is what makes **AT-D3-1** able to fail: its RED is adding idle breathing to the character
  sprite, the single most natural thing to add to a pixel-art office and the thing that would quietly
  make the floor's motion meaningless. ⛔ **No ambient life at all** — motion is the floor's
  vocabulary and decoration would spend it on nothing; a state-held loop may say WHICH state, never
  HOW MUCH, at one frame per D2's 250 ms coalescing tick, so no loop can appear more informative than
  the feed that drives it. ⚠ **§ 6.1 landed carrying the attribution card#8161 later withdrew**
  (*"— operator, via the proposal"*, at two sites), and § 6.3's blanket refusal of decorative motion
  is scoped there too; this bullet describes the document as it shipped.
  ⭐ **Identity is a pure function, not a stored position** — the desk slot is FNV-1a-32 of D1's
  config-resident `(install_id, seat_id)` with deterministic probing, so two browsers, two reloads
  and two server restarts agree with no server field and no local storage; sorted order and arrival
  order are both refused with their failure modes (a sorted floor shifts wholesale when a seat is
  provisioned; arrival order is not a function of the rendered set, so two browsers disagree). The
  cost is paid openly: on a collision an arriving seat can displace an incumbent, bounded to the
  chain, **animated as a move** because nothing on this floor moves without a cause, with the
  frequency stated as `N/S` — 4 seats over 12 slots, **1 in 3** on the shipped map.
  ⇒ **The subagent cap STAYS at 8**, closing D2 § 14 item 9, and the arithmetic is re-derived by the
  gate rather than transcribed: **2,080 B** spare (8,192 − 6,112), ⌊2,080 ÷ 263⌋ = **7** more would
  fit, the cap **could** reach **15** at 7,953 B, and 16 breaches at 8,216 B — **24 B over**. It
  stays at 8 on a D2 fact rather than a taste judgement: **the drill-down does not read
  `subagents[]`** — it reads the seat-detail response, whose open-call list D2 § 8.2.3 serves *"in
  full (not capped at 8)"* — so the array's only consumer is the floor's side table, and the spare is
  the margin the next field addition will need.
  ⛔ **Asset provenance is two build gates, one of them an ABSENCE**: every asset file owes an
  `ATTRIBUTION.md` row carrying source URL, author, SPDX identifier, retrieval date and the vendored
  file's SHA-256, a missing row or a mismatched hash failing the build; and because character art is
  GENERATED from the seat key by the ported MIT generator, the second gate asserts **there is no
  image file in the character tree at all** — a hash denylist could only refuse the copies someone
  thought to enumerate, an empty tree refuses the one nobody anticipated. The licence allowlist is
  closed at `CC0-1.0` and `MIT`, and widening it is an operator decision, not an implementer's.
  ⚠ **Nothing is invented where the contract cannot answer**: five gaps are filed as D2 amendment
  needs, each with what is rendered in the meantime — the timeline and detail endpoints have no field
  tables, the feed has no message for a seat or install entering the population, D2 refuses machine
  tokens on the socket for a revocation property the browser's own session shares and D2 does not
  address, no compaction fact reaches a consumer, and `fleet.reload.reason` has no member set. The
  task-title fallback is **tier 3 only**, carried forward as an open question, because the proposal's
  three tiers are not in this repository and inventing them from the phrase would put a guessed rule
  in a contract.
  ⚠ **The closing rounds' recurring finding was RESTATEMENT — a fact spelled at N sites drifts — so
  six facts were each given one owner and a guard**: the dark-only desk render (12 sites), where the
  derivation lag renders (5 sites), the animation log's episode schema, record-versus-lobby, § 2.4's
  marker rule, and the AT build-order rule (6 instances); G9's population is derived rather than
  stored; and **G6's Appendix-A recognizer stopped being the literal `D3`** — it is now `D3` plus the
  render-directed phrasings D2 and D1 actually use, matched **wrap-tolerantly**, because grepping for
  `D3` alone reported clean while D2 § 4.7 and § 4.8 placed three render obligations this document
  neither listed nor discharged, and a line-scoped list would have left the check clean over a phrase
  typeset across a wrap exactly as before.

- **card#7521** — **D1 and D2 are reconciled: the eight § 14 items D2 filed against D1 are closed at
  the positions § 14 itself named**, plus four Gate-C findings on the D2 side and the doc-sync those
  amendments oblige. **Item 1** — the flusher's 90-minute `inferred_silence` close emits **no**
  `turn.end`; the server closes the turn, because a hook-path exception buys nothing the idempotent
  server close does not already provide and puts a second writer on a fact one writer owns.
  **Item 2** — both orphan ceilings are measured from the server's `received_at`:
  `started_at = event_time` is right for a *duration* and wrong for a *ceiling*, because seat clock
  skew must not move a server ceiling. **Item 4** — the ordering key becomes
  `(event_time, seq_epoch, seq)`, total across an epoch reset and reducing to the old key whenever
  the epoch is constant, with § 10.2's out-of-order row moved so the key has one spelling.
  **Item 5** — sticky-until-restart is confirmed INTENDED, with its reason (a badge that clears
  itself is indistinguishable from one that never fired) and the consumer clause, and no wire change.
  **Item 10** — a clean turn's `idle` survives a later `session.end`, the idle rule reading two facts
  off the `turn.end` that a session end falsifies neither of. **Item 11** — `mzr_` joins rule 3's
  known-prefix regex.
  ⭐ **Item 12 took the CLASS fix, not the row fix.** D2 § 14 named two possible closes — re-word
  § 12.7's consequence column, or state that the server's badges are a separate vocabulary — and the
  second is the upstream one, so § 12.7's preamble now says as a class that no badge that table names
  is a member of § 9.3's array. That disposes of the `seq_collision` and `batches_refused.<error>`
  rows the same item flagged in the same breath; **a row-by-row fix would have left both standing and
  the next server counter would have minted the contradiction again**.
  ⛔ **Item 13 is the marker convention, and its root cause was D1 § 1's own false claim**: § 1 said
  its only D2 marker was `D2-MUST` with *"exactly five such constraints"*, against Appendix A's
  twenty-nine further obligations. § 1 now declares `D2-MUST` for the five numbered constraints and a
  `D2:` prefix (or a *constraining D2* note) for every other consumer-addressed obligation, **on the
  obligation sentence rather than somewhere in its section** — which is the property that let S29 be
  walked past three times while a section-level check stayed green.
  ⇒ **The verifier now follows the convention rather than the mention**: G6 builds a second
  population from markers ON the obligation sentence, splits Appendix A against it, and **fails on a
  D1 section that mentions D2 without marking which sentence is the obligation** — the S29 shape
  itself. Measured on the plant rather than argued: with D1 § 12.5's marker stripped and the
  section's other D2 mention left intact, **the `origin/dev` tool still derived 28 / 1 and carries no
  such check**. **Manual residue: 14 rows before, 1 after**, and the one left is printed by name on
  every run even at zero — S25's D1 source is a decision-register row rather than a section number,
  so no marker convention in D1 can reach it, and minting a normative D1 sentence to make it
  greppable would have contradicted a recorded decision.
  ⚠ **Gate-C on the D2 side, where the sharpest of the four is a stored denominator**: two prose
  comments said the delta-volume sum has *"six"* components against a seven-operand sum the tool
  itself prints as seven, so the count is struck from the prose and **the neighbouring `< 6` floor is
  RE-DERIVED rather than kept** — it is now the operand count of the sum's own arithmetic, read
  independently of the value extraction it guards, because a pinned floor goes on passing after the
  document grows a component, which is the one change the control exists to catch. Also: § 8.2's
  `snapshot_denied` pointer sent the reader to § 9 when § 8.6 owns the rule; § 4.6's quiescence write
  sequence now writes `last_turn_aborted_count: 0`, so the precedence paragraph restates a written
  rule instead of asserting a value nothing sets; and § 6.5's version/platform volume disposition is
  re-derived from § 8.3 and § 12 rather than guessed. § 14 items 3, 6, 7 and 9 stay open — **none of
  them is D1's**.

- **card#7455** — **D1, the keystone P0 design artifact** (`docs/design/EVENT-SCHEMA.md`): the
  complete contract between `fleet-reporter` — a zero-dependency Node hook bundle on every agent
  seat, Linux and Windows — and the Mezzanine ingest. Fourteen event kinds with field-level tables
  and worked payloads, the batch envelope, the client-side sanitizer, the spool and flusher
  mechanics, the server's response contract, and twenty-two acceptance tests each with its RED,
  written to `docs/PLAN.md`'s D-14 standalone-implementer bar. `docs/VERSIONING.md § Wire
  compatibility` keeps the versioning policy and gains **rule 7** (a new event kind and a new
  closed-enum member are backward-compatible — ignore-or-coerce-and-count on the receiver); § 5
  records only how these fields comply, and cites rather than restates.
  ⭐ **Kill-vs-complete is an explicit open/close call ledger, not an inference from turn
  boundaries.** A `/clear` SIGKILLs an in-flight subagent tool call and no `PostToolUse` ever fires
  (measured 26/26, roundtable #341/#340), so **absence must never read as completion**: every
  `PreToolUse` opens a call with a reporter-minted `call_id`, and every session or turn boundary
  REAPS the calls still open and closes them `aborted` with a stated `abort_reason` and
  `close_source` ahead of the boundary event, which then names them in `aborted_call_ids`. One
  consequence makes the whole thing checkable at the wire: **`idle` may be minted ONLY from a
  `turn.end` with `end_reason` `stop_hook` and an empty `aborted_call_ids`**. The cost accepted is
  over-eager aborts for a call legitimately outstanding at Stop, bounded by the late-completion rule
  — a completion is an observation and an abort is an inference, so the observation always overrides,
  and a rising `late_completion` count is the signal that a reap rule is wrong.
  ⭐ **Sanitization is an allowlist first and regexes second**: a descriptor is built only from an
  explicitly allowlisted input key of an explicitly allowlisted tool, and every other tool —
  including every `mcp__*` tool, whose input schemas third parties define — contributes `tool_name`
  and no descriptor at all. The redaction pass exists because allowlisted text can still carry a
  credential, **not as the primary control**, and that ordering is what the RED fixtures test:
  fixture 8 removes the allowlist alone and must fail alone.
  ⛔ **Silence is the failure mode this design fears most, so liveness is asserted continuously
  rather than inferred.** The prior art is a fleet predicate keyed on the undocumented
  `CLAUDE_CODE_CHILD_SESSION` marker that went constant on a harness upgrade and left two consumers
  dark for **30 days with no log line**. So identity comes from an install-time config file and never
  from the environment; no emission is gated on any harness marker (classifiers label, they never
  suppress); every predicate reports both branch counts and the server alarms when one goes constant;
  `/clear` is detected by two independent signals whose counters diverging is itself the self-test;
  and a 60 s heartbeat with a 300 s staleness alarm makes *reporter dark* a rendered state rather
  than a quiet desk.
  ⛔ **Round 1 found the schema resting on three false premises about the harness, and each took a
  redesign rather than a patch**: `session.end` becomes an OBSERVATION (`SessionEnd` exists, so the
  four-way inference is gone and one inferred member remains, reversible via `session_reopened`, with
  the supersede-on-different-session rule deleted outright because it made two terminals on one seat
  abort each other's healthy calls); subagent matching is rebuilt on `agent_id`, so parallel
  dispatches stop losing their stop edge; and **`blocked` becomes a PAIR** — it had an entry event
  and no exit event.
  ⭐ **Round 3's class fix is the one worth carrying: hand-transcribed facts about another product's
  schema, with nothing binding them to a source and nothing able to red when they diverged.** Round 1
  found two wrong; **the round-2 fix corrected those instances and minted five more**, so with
  per-instance patching having failed twice, this round landed the binding first. Fifty-six real hook
  payloads across ten events were captured from Claude Code 2.1.240, § 6.0's fact layer was rebuilt
  on them — 36 rows, each **MEASURED** (fixture + version pin), **DOCS-CITED** (source + date) or
  **UNVERIFIED** (cost + closure act) — sixteen of those payloads are reproduced in § 17, one per
  distinct shape, sanitized only by replacing ids and paths with placeholders of the same shape and
  length, beside five labelled stubs for the hooks `claude -p` cannot drive; and a SELFTEST-MUST
  makes the reporter assert its expected payload keys against those fixtures and red on divergence.
  ⭐ **What the capture found that no review did**: the dispatch tool's payload `tool_name` is
  **`Agent`, not `Task`** — keyed on `Task` alone, `subagent.spawn` would never have fired, no
  `agent_id` would ever have bound, and the descriptor allowlist would have returned null for every
  dispatch; `Stop` does **not** fire inside a subagent (settling the highest-cost open fact);
  `PostToolUseFailure` carries `is_interrupt`, the harness's own kill-vs-fail discriminator; and a
  permission-refused call fires no close hook at all. It also **overruled the review** on three key
  names — `SessionStart.source`, `SessionEnd.reason` and `PreCompact.trigger` are real, while the
  asserted `session_start_reason` / `session_end_reason` / `compact_reason` occur nowhere in the
  installed binary.
  ⛔ **Round 4 bound the VALUE sets as well as the key names, and both of its blockers lived in that
  gap.** The call index's `open` record carried one `agent_id` field meaning two opposite things —
  the scope a call was opened in, and the child a dispatch spawned — and splitting it into
  `agent_scope_id` / `child_agent_id` exposed that **no rule in the design ever closed a call opened
  inside a subagent**, because `Stop` does not fire there. And `reporter.heartbeat.degraded` was
  `array<enum>` with its member set stated nowhere and its two examples spelling one member two ways;
  twelve members are now declared in § 9.3, named after their counters, and **the array's bound is
  that table's size rather than a chosen 16**. `notification_type` was corrected **14 → 16** against
  the binary — the omitted pair being `elicitation_dialog` and `elicitation_url_dialog`, the two the
  `elicitation` branch depends on, which no guard could see because the guard bound key names and not
  value sets — and `notification_kind.other` was **deleted as structurally unreachable rather than
  hedged**.
  ⇒ **`tools/design/verify-event-schema.py` and `verify-harness-facts.py` ship with the document**,
  re-deriving their ground truth every run rather than storing it; the harness verifier reads key
  sets and enum value sets out of the installed binary, and **its controls ABORT rather than report
  clean** — two declarations must resolve to different sets, no set may be empty, and a fabricated
  field must raise. Every check in both was seen to fail on a planted or real defect before its pass
  was trusted.
  ⚠ **One figure was wrong three rounds running, in the same direction each time, so it now has one
  home and a gate**: the heartbeat's fill time read *"50+ days"* from a 500 B assumption, then
  *"~25 days"* from a *"~900 B"* measurement of the worked example **taken with its 524 B `counters`
  object left out**. It serializes to **1,487 B**, so a heartbeat-only seat fills the 32 MiB spool in
  **~15.7 days** — the conclusion survives with a narrower margin — and `verify-event-schema.py` now
  re-serializes that example on every run and reds if any figure in the paragraph disagrees. The
  superseded value was deleted at its three other sites rather than re-synced, so the fill time has
  one home and the others state the property they actually rest on.
  ⚠ **The raw 56-payload capture run stays UNCOMMITTED by PM ruling** (2026-08-23): this repository
  is public and the captures carry a real seat's session ids, cwd paths and prompt text, so § 17's
  reproduced fixtures plus the binary-derived guard are the durable evidence and the 56-run counts
  stay labelled run-provenance, **unfalsifiable by design**.

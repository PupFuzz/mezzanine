# Mezzanine — build plan

The full plan from specification to a running floor. This is the durable record of *what* gets
built, *in what order*, and *why the order is what it is*. The proposal (the operator-reviewed
artifact) owns the vision; this document owns the execution. Where the two disagree, this one
is newer and wins — and the disagreement should be fixed in the proposal.

⚠ **The proposal is NOT in this repository**, and every citation of it here and in `docs/design/`
should be read as pointing outside what a reader can open. That is a real limit rather than a
formality: on 2026-08-30 a load-bearing D3 rule was found attributed to *"the operator, via the
proposal"* — a rule the operator disclaims, cited to a source nobody could check, which is why nobody
had (`docs/design/FLOOR.md § 6.1`). **A citation a reader cannot open is not a citation.** Where a
decision rests on an operator ruling, cite the **card** it was given on;
`docs/design/FLEET-STATE.md § 14` item 3 is the worked example of the other honest response —
declining to invent the proposal's content rather than paraphrasing it from memory.

Status: **adopted 2026-08-23** · owner: aimla-pm · work tracked on kanban **board 14**.

---

## 0. Decisions register

Every load-bearing decision, with who made it. Nothing below is open for silent re-litigation —
reopen an entry by talking to its decider.

| # | Decision | Decider | Date |
|---|---|---|---|
| D-01 | Project name **Mezzanine**; office-building metaphor (floor per PM, solo agents share a floor) | operator | 2026-08-22 |
| D-02 | Separate public repo (`PupFuzz/mezzanine`), MIT | operator | 2026-08-22 |
| D-03 | Laravel host; public deployment behind **MFA** | operator | 2026-08-22 |
| D-04 | MFA is **rebuilt on stock packages** (Fortify + a standard TOTP package), not extracted from sola-inventory | operator | 2026-08-23 |
| D-05 | Telemetry is **programmatic end to end** — hooks fire, a script posts; no model self-report — and must run on **Linux and Windows** seats | operator | 2026-08-22 |
| D-06 | Telemetry payload is **minimized**: tool name + short sanitized descriptor, turn boundaries, context %, subagent task titles. Full args/outputs never transit. | operator | 2026-08-23 |
| D-07 | Floor art from **CC0 tilesets**; characters ported from munder-difflin's procedural generator (MIT, with attribution). The upstream's commercial tilesets are never vendored. | operator | 2026-08-22 |
| D-08 | App deploys to a **separate host** (operator provisions; target TBD) | operator | 2026-08-23 |
| D-09 | Human-readable docs live in `docs/`; repo root is reserved for AI-parsed `CLAUDE*.md` files (structure pending roundtable #346) | operator | 2026-08-23 |
| D-10 | **Aggregation plane lives in the Mezzanine app**, not the bridge — the analysis in § 1 | pm, under operator delegation ("best technical solution") | 2026-08-23 |
| D-11 | Changelog discipline adapted from agent-board-toolkit per kanban-solo's #344 answer, **plus a size gate** they don't have (§ 4) | pm | 2026-08-23 |
| D-12 | Branch model: `dev` integrates (squash by convention), `main` releases (merge-commit, ruleset-enforced); `card-token-lint` is a required check on both | pm | 2026-08-23 |
| D-13 | After the P0 designs land, the project **splits to a dedicated Mezzanine agent** running a **sandbox** instance; **prod** is a separate deployment driven by `bin/deploy.sh` (pattern requested from kanban-solo's kanban-board project) | operator | 2026-08-23 |
| D-14 | Design docs are written to the **standalone-implementer standard** (§ 2, "The bar") — complete enough that an AI agent with no access to this project's conversational history implements from the document alone | operator | 2026-08-23 |
| D-15 | The fleet-state store is **MySQL on a dedicated DB host**; provisioning it is a deployment task downstream of D2's schema, owned by the Mezzanine build agent (D-13) | operator | 2026-08-23 |
| D-16 | The Laravel application lives in **`server/`**, not at the repo root — the root already owns `README.md`, `VERSION`, `bin/`, `docs/`, `tools/` and `fleet-reporter/`, and the downstream consumers (CI lanes #7344, `bin/deploy.sh` #7459, ingest #7338, store/feed #7339) each need one stable path to key on | pm | 2026-08-25 |

### Amendments — a decision is superseded by an APPEND, never by an edit

**A register records what was decided when.** Rewriting a row to say what is true now destroys
the only thing a register is for, so a superseding decision is recorded beneath the table with
its date, its decider and the scope of what it moved. The original row above stands as written.

- **D-07 · art direction superseded (the middle clause only) — operator, 2026-08-27.**
  D-07 reads *"Floor art from **CC0 tilesets**; characters ported from munder-difflin's
  procedural generator (MIT, with attribution). The upstream's commercial tilesets are never
  vendored."* The operator ratified a new art direction on **2026-08-26** (card#7898) and
  ratified a working reference for it on **2026-08-27** (`docs/design/floor-preview/`, card#7341),
  in these words: *"I actually don't want to copy the munder difflin 'minecraft' style. I want
  high resolution images that are whimsical but look modern."* **What moves:** the ported pixel
  generator's **art** is now **interim placeholder art**; the product ships original,
  high-resolution, resolution-independent art of its own, specified at
  [`docs/design/FLOOR.md § 10.4`](design/FLOOR.md#104-the-art-direction-as-a-specification).
  **What does NOT move, and each is load-bearing:** the **first** clause (floor art from CC0
  tilesets) is untouched; the **last** clause (*the upstream's commercial tilesets are never
  vendored*) is untouched and permanent; the port's MIT attribution obligations are untouched;
  and the **seed machinery** — appearance derived from `(install_id, seat_id)` — is what the port
  actually bought and is untouched, because the art direction changes what is drawn, never what
  selects it. **No rework of card#7340's lineage or licence work is owed.** The consequence for
  the asset gates is D3's to state and is stated at
  [`§ 10.1`](design/FLOOR.md#101-the-manifest-and-the-two-gates): they move from asserting an
  absence to asserting declared provenance, at a named cost.

- **D-07 · the first clause EXERCISED, not superseded — operator, 2026-09-12 (card#7341).**
  D-07's *"floor art from **CC0 tilesets**"* now names one: Kenney's **Furniture Kit**
  (<https://kenney.nl/assets/furniture-kit>, `CC0-1.0`), vendored under `resources/floor/` with a
  `docs/ATTRIBUTION.md` row per file and its terms, archive hash and omissions recorded in
  `resources/floor/LINEAGE.md`. This closes
  [`docs/design/FLOOR.md § 14`](design/FLOOR.md#14-open-questions-for-the-review-loop) item 7, whose
  generator half closed on 2026-08-25. **The decision is recorded as an append rather than left
  implicit because of the clause attached to it:** the operator chose this pack **explicitly as a
  bridge to first-party vector art**, and being pre-rendered raster it does **not** meet the
  2026-08-27 amendment's resolution-independence requirement. So the two appends stand together —
  the ported pixel art is interim, and so is this — and neither is a reading of the other.
  **What does NOT move:** the last clause (*the upstream's commercial tilesets are never vendored*)
  is untouched and permanent, and nothing here widens the licence allowlist, which remains an
  operator decision taken separately.

- **D-12 · the required-check clause only — measured, not re-decided; 2026-09-09 (card#9054).**
  D-12 records *"`card-token-lint` is a required check on both"*. That was the measured state on
  2026-08-23 and it is no longer the whole list — contexts have been added since, and a reader who
  takes the row for the current list reads *the only one* into it — the same reading that told
  reviewers a red `asset-provenance` did not block a merge while it did. **What moves:**
  nothing that was *decided*. The branch model, the merge methods and ruleset enforcement all
  stand as written. Only the membership of the list has moved, and it is **not this register's to
  carry**: it is a repository-settings fact no document in this repo can verify.
  [`docs/VERSIONING.md § Branch model`](VERSIONING.md) is its one home and carries the API command
  that re-derives it. Read the list there, never here.

- **D-15 · the engine only — operator, 2026-09-09 (card#7523).**
  D-15 reads *"The fleet-state store is **MySQL on a dedicated DB host**; provisioning it is a
  deployment task downstream of D2's schema, owned by the Mezzanine build agent (D-13)"*. The
  operator repinned the engine on **2026-09-09**: the store is **MariaDB**, at the version floor
  [`§ 6.1`](design/FLEET-STATE.md#61-deployment-posture) pins, replacing MySQL ≥ 8.0.12.
  **What moves:** the engine and its version floor, and nothing else. **What does NOT move,
  and each is load-bearing:** the *dedicated DB host* clause is untouched; provisioning
  is still downstream of D2's schema and still the build agent's (D-13); the pinned database names
  and the test-isolation posture of
  [`docs/design/FLEET-STATE.md § 6.2`](design/FLEET-STATE.md#62-database-names-pinned-and-published)
  are untouched. **Where the consequence is worked out:**
  [`§ 6.1`](design/FLEET-STATE.md#61-deployment-posture) — it re-argues every requirement against
  MariaDB rather than carrying it across, records the engine divergences that were checked and
  found unreachable from this schema, and records the one item that is **UNSOURCED** (quantified
  whole-column JSON read/write performance, which needs a benchmark and has not had one).
  ⚠ **Not settled by this amendment:** whether the application moves from Laravel's `mysql`
  connection to `config/database.php`'s `mariadb` connection. The app is wired to `mysql` today,
  `bin/deploy.sh` refuses anything else, and the test-isolation guards key on
  `database.connections.mysql.database` — so that is a separate decision with its own blast radius,
  and it is the operator's to take.

## 1. The aggregation ruling (D-10) — standalone, and why

The operator's question: *can Mezzanine function without the bridge, and what is best technically —
efficiency (no code duplication) and best practice?*

**Answer: yes, fully — and standalone is also the better design, not just the feasible one.**

**Mezzanine needs three inputs, and none requires the bridge:**

1. **Seat telemetry** — new data that has never existed; `fleet-reporter` POSTs it wherever we
   point it. Pointing it at Mezzanine involves the bridge in nothing.
2. **GitHub events** (coord posts, PR lifecycle, pushes) — GitHub webhooks **natively fan out to
   multiple consumers per repo**. Mezzanine registers its own hooks beside the bridge's. That is
   the platform's intended multi-consumer mechanism, not duplication: the only code both apps then
   share in spirit is webhook receipt + HMAC verification, which is thin framework glue in Laravel
   (~a middleware), not business logic.
3. **Board state** — read-only kanban API polling with the patterns the fleet already runs.

**What the bridge actually owns that Mezzanine must NOT duplicate is its business logic** — the
classifiers, the card writeback, the channel push that wakes agents. Mezzanine needs none of it:
the bridge is the fleet's **actuator** (write-side: move cards, wake seats), Mezzanine is an
**observer** (read-side: aggregate, display). Keeping those separate is the actual best practice
at stake here — coupling a dashboard's deploy cadence and availability to a production actuator
owned by another team buys nothing and costs both directions (a dashboard bug can never take down
fleet coordination; a bridge deploy can never blank the floor).

**Efficiency favors it too.** The alternative — FRs into the bridge — puts Mezzanine's critical
path behind kanban-solo's queue, and their stated position (roundtable #341) is that they want
our prototype numbers *before* bridge features land. Duplicating ~50 lines of webhook glue is
cheaper than a cross-team dependency on every iteration.

**The integration point is an API, not shared code:** Mezzanine exposes a REST **fleet-snapshot
endpoint**. The bridge's future autonomy watchdog (#341's ask 2) consumes it for exactly the
liveness signal kanban-solo said no one could give them — the seat-produced turn boundaries. One
producer, clean boundary, either side deployable alone.

## 2. Design-first gates — the order is the plan

Design artifacts precede their builds. The **D1 → D2 → D3 chain** below runs in strict order,
because each of the three is the contract the next consumes; a document that consumes the chain
rather than extending it (D4) is listed after it and is not in that ordering. Each is a PR into
`dev` reviewed like code. **No count is written here** — this section is the set a reader works
from, and a figure beside a set it counts is a second copy of that set, false at the next addition
(`docs/design/FLEET-STATE.md § 2.1` takes the same position about its process table, for the same
measured reason).

**The bar (D-14): every design doc must be implementable by an AI agent that has ONLY the
document.** The implementing agent — plan for a capable frontier model (Opus-class) — will not
have this session's context, the roundtable threads, or the proposal open. Concretely, each
design doc must carry:

- **Field-level tables** for every wire/store structure: name, type, units, nullability, size
  bounds, one realistic example value per field — never a prose gesture at "the obvious fields."
- **Every failure path enumerated** with its required behavior (reject/retry/drop/log) and its
  observable signal. "Handle errors appropriately" is a defect in a design doc.
- **Worked examples**: at least one complete example payload/flow per event kind or interaction,
  including one deliberately-invalid example and what the system must do with it.
- **Acceptance tests specified, with fixtures** — what to build, what to break, what RED looks
  like — so seen-to-fail is designed in, not improvised by the implementer.
- **Stated non-goals** per document, so an implementer cannot drift scope in good faith.
- **Inlined context**: where a requirement's reason lives in a coordination thread or a measured
  incident, the doc restates the reason in one or two sentences and cites the source — the cite
  is provenance, never the only carrier of the requirement.
- **No unstated defaults**: timeouts, limits, cadences, and retention windows appear as numbers
  with their derivation, not as adjectives ("short", "frequent", "a while").

A design doc that fails this bar fails review regardless of how right its ideas are — the review
question is "could a fresh agent build this correctly from the file alone," not "do we, who were
in the room, understand it."

**D1 — the wire schema (`docs/design/EVENT-SCHEMA.md`).** The keystone; everything downstream is
shaped by it, and it is the most expensive thing to get wrong because seats upgrade independently
of the server (`docs/VERSIONING.md § Wire compatibility` already owns the compatibility rules — this
artifact fills in the fields). Hard requirements it must satisfy:
- explicit `schema` version field; ingest declares accepted versions; unknown ⇒ **loud reject,
  never silent drop** (a floor that quietly looks idle is worse than one visibly broken);
- **minimized payload** (D-06) by construction — sanitization at the *reporter*, so secrets
  cannot transit even if the server misbehaves;
- **kill-vs-complete discrimination**: a `/clear` SIGKILLs an in-flight subagent tool call
  (measured, roundtable #341/#340); a killed call must be distinguishable from a completed turn
  or the busiest seats mint false idles. The schema carries what the acceptance test needs to
  prove this — break it on purpose, watch a killed call, confirm no idle transition;
- event kinds: session start/end, turn start/end, tool start/end (name + descriptor), subagent
  spawn/stop (with task title), compaction, context %, and a reporter heartbeat.

Status: **merged 2026-08-23** (PR #7).

**D2 — fleet-state model + feed contract (`docs/design/FLEET-STATE.md`).** What the store keeps
(per-seat current state + a short activity window, keyed by install/seat), retention, and what
the browser receives: **snapshot-on-connect, deltas after**, over native Server-Sent Events
(card#9287; Reverb until then); the REST snapshot for
non-browser consumers (the watchdog). Merge rules for the three sources (telemetry supplies the
live *action*; GitHub/board events supply the human-readable *task title*; the three-tier status
fallback from the proposal).

Status: **merged 2026-08-23** (PR #8).

**D3 — floor UI spec (`docs/design/FLOOR.md`).** Screens: lobby (building summary), floor, desk
drill-down panel (current task linked to card/thread, recent-activity timeline, context gauge,
subagents as interns at a side table — their titles from the Task dispatch, programmatic).
Identity mapping: how a seat becomes a desk and an install becomes a floor, stable across
restarts. The honesty principle binds every animation **that makes a claim**: driven by a real event,
or absent — **`docs/design/FLOOR.md § 6.1` owns that rule and its provenance**, and this line restates
neither. *Amended 2026-08-30:* it read *"the honesty principle **from the proposal**"*, sourcing a
load-bearing rule to a document no reader of this repository can open; D3 records what the rule
actually rests on and what the operator ruled on card#7953.

Status: **in review** — drafted; the adversarial review loop runs before merge.

**D4 — the board task-title producer (`docs/design/BOARD-TASK.md`).** Not part of the chain above:
it **consumes** D2 and extends nothing. It designs the producer behind tier 1 of D2 § 4.9's
task-title merge — the kanban poller, the seat→board-user join, the credential posture, and the
input table the fold derives the title from rather than a value written into `seat_state`, which is
what keeps a board-sourced title reproducible by D2 § 6.6's rebuild. **It exists as a document of
its own because D2 puts it there:** D2 § 1.2 lists the kanban poller among its non-goals by name,
and D2 § 14 item 3 asked for a ruling on where the board producer is designed — a question that item
now answers with this document's name, the ratification of 2026-09-12 having written the answer into
it. It is held to the D-14 bar above like every document listed before it.

Status: **designed and ratified; the poller is not built** — card#7582. The D2 amendments every
structural piece needed were ratified and applied on 2026-09-12, and the store shape shipped with
them because retirement now clears the board-user mapping; `docs/design/BOARD-TASK.md § 13` records
where each landed and what remains unbuilt.

## 3. Work breakdown

Board 14 is the queue; this table is the map. Order within a phase is by dependency; phases
overlap where the dependency arrows allow. "Accept:" lines are the review floor, not the ceiling.

| Phase | Work (board card) | Depends on | Accept |
|---|---|---|---|
| **P0 design** | D1 event schema (new card) | — | review + the kill-vs-complete test is *specified* |
| | D2 fleet-state + feed (new card) | D1 | review; snapshot+delta contract explicit |
| | D3 floor UI spec (card#7457) | D2 draft | review; identity mapping defined |
| **P1 telemetry** | `fleet-reporter` core: spool + flusher (#7335) | D1 | hermetic selftest; **never blocks the agent**; survives server down; sanitizer has RED fixtures |
| | installer, Linux + **Windows validated** (#7336) | #7335 | real install on a Windows seat before anything trusts the signal |
| | kill-vs-idle proof (#7337) | #7335 | the D1-specified test, run for real against a `/clear` |
| **P2 server** | Laravel skeleton + MFA on stock packages (#7334, re-scoped per D-04) | — | Fortify + TOTP; MFA gates page, **the feed** (SSE since card#9287), and REST snapshot; seat-token ingest is separate and never browser-facing |
| | ingest endpoint (#7338) | D1, skeleton | rejects unknown schema loudly; per-seat tokens; rate limits; statusLine sampled not streamed |
| | fleet-state store + SSE feed + REST snapshot (#7339) | D2, ingest | snapshot+delta observed in a browser; REST snapshot serves the watchdog case |
| | MariaDB provisioning on the dedicated DB host (new card, D-15, as amended 2026-09-09) | D2 schema | prod/sandbox/test databases created as `docs/design/FLEET-STATE.md § 6.2` pins them; TLS from the app host verified; the test-DB guard seen to refuse **under the one lever that moves the resolved value — deleting half a pin** (an intact pin correctly defeats a hostile export; corrected 2026-08-25, card#7334) before any suite is trusted |
| **P3 floor** | character port + ATTRIBUTION (#7340) | — | renders in a plain browser; lineage file complete |
| | floor v1 (#7341) | D3, P2 feed, #7340 | live desks from real telemetry; CC0 tiles; Tiled map |
| | drill-down + interns (#7342) | #7341 | subagent titles appear from real Task dispatches |
| **P4 building** | elevator, floor-per-PM, solo floor (#7343) | #7341 | schema already multi-install; second floor renders from a second install's feed |
| | CI lanes for app code (#7344) | first PHP/JS code | required-check list updated the same PR (see `docs/VERSIONING.md` — a new workflow is not auto-required) |
| **cont.** | changelog + card-entry gate (card#8174, per #344) — ✅ **landed 2026-08-30** as `release-pr-guard` R4 (the card's bullet) and R5 (the size gate § 4 had claimed since D-11) | — | § 4; every arm seen to red on a planted defect first — eight guard mutations, each producing a targeted failure |
| | `CLAUDE*.md` structure (new card, blocked on #346) | #346 answer | index + chapters per sola-inventory's pattern |
| | `bin/deploy.sh` prod deploy (#7459) | P2 server host (D-08) | prod moves only via the script; seen to fail on a broken precondition before trusted — `bin/deploy.selftest.sh` is that evidence, and the live-host leg stays unexercised until a host exists |

Deliberately **not** in this plan: the autonomy watchdog (roundtable #341 + our `[WAKE]` prototype
#659 — separate track; Mezzanine's contribution to it is the REST snapshot), and the aimla
cutover work, which outranks all of this whenever it is live.

## 4. Documentation & release discipline

Adopted from kanban-solo's #344 answer (measured, not folklore), with one deliberate addition.

⚠ **Every rule in this section was prose with nothing behind it until 2026-08-30.** A sweep of
`dev` that day found **30 cards named in commit subjects and 25 bulleted** — card#7335 (the whole
fleet-reporter), #7455, #7456, #7457, #7521 and #7929 had merged with **no changelog entry at
all**, and the first release would have collected a changelog missing an entire subsystem. The six
were backfilled in PR #44 and the hole was closed in card#8174: the rules below now name the check
that enforces each of them, and the ones nothing enforces say so. ⚠ **Replayed on the real commits
the six split three and three** — the gate refuses #7335, #7456 and #7929 as they merged, while
#7455, #7457 and #7521 predate the changelog file itself (created 2026-08-25) and are exit 2, not
rule violations anyone could have committed at the time.

- **The enforced unit is the card, not the PR.** A PR whose **head branch or title** carries
  `card#NNNN` owes a line-initial `- **card#NNNN** — …` bullet under `## [Unreleased]` in
  `docs/CHANGELOG.md`, **in the same PR**. Tokenless PRs owe nothing. Bold-anywhere is not
  accepted — line-initial is the rule, and their incident log records why (a prose mention
  discharged another card's obligation).
  ⛔ **Enforced by `bin/release-pr-guard.py` R4** on every PR. Two surfaces, not three: the
  branch and the title are the two the correlators parse
  (`bin/card-token-lint.py § SURFACES`), and keying on commit **subjects** — which this bullet
  said before card#8174 — would drag every back-merge into scope and demand it re-add bullets
  the release had just retitled away. Two carve-outs, both argued in the guard's docstring: a PR
  whose base is `main` owes nothing under `[Unreleased]` (step 4 has just emptied it), and a PR
  that **removes** a card's existing bullet is exempt for that card (a revert of unreleased work
  owes the deletion of an entry, not one more — and the removal is the only way to claim it).
- **Written at PR time; the release only collects.** The release retitles `[Unreleased]` and
  opens a fresh empty one.
- **The gate fails closed on every unmeasurable state** (a history too truncated to see past the
  size window, no `[Unreleased]` heading, an unreadable authority — never a skip). Note the
  shape: it is *unmeasurable*, not *shallow* — R5 measures a shallow clone that can still answer,
  because refusing every one of them would red work that is fine. This is the property most worth
  copying, and it
  gets a seen-to-fail exercise before it is trusted. ⭐ **Ours splits the verdict further than
  theirs:** exit **1** is "this PR breaks a rule" and exit **2** is "the guard could not
  measure", because the two send different people to different files.
- **Our addition — a size gate.** The toolkit pairs "never truncate" with no size check; theirs
  is at 59.5% of the contents-API's silent 1 MiB truncation cliff. A new project should not adopt
  anti-trim without the check, so `docs/CHANGELOG.md`'s size is gated with headroom:
  **threshold = cliff − the bytes the file actually grew in the last 14 days**, so it reds while
  a fortnight of runway is still left to archive released sections.
  ⛔ **Enforced by `bin/release-pr-guard.py` R5** on every PR. It owns the numbers and this
  section deliberately does not restate them — the growth term is **measured from git history on
  every run**, never stored, because a written rate stops being a measurement the moment growth
  changes. R5 refuses only a PR that makes an over-threshold file **bigger**; over threshold
  while flat or shrinking is a loud warning, so the archiving PR is never blocked by the
  condition it fixes.
  ⚠ **This bullet claimed the gate in the present tense from 2026-08-23 (D-11) until 2026-08-30,
  and no such gate existed** — every `CHANGELOG` reference in `bin/`, `tools/` and `.github/` was
  the release guard's section-existence check. It exists now; the note stays because a decisions
  register that asserted a shipped gate for a week is the more useful thing to remember than the
  gate. The `doc-size-threshold` prior art this was adapted from lives outside this repository
  and **its content is not readable from here** — per the ⚠ at the top of this document, treat
  the name as provenance and the formula above, which is implemented and tested, as the rule.
  ⚑ **Measured 2026-08-30, and it is closer than the wording suggests:** the file went 1,182 B →
  150,881 B in six days (mean ≈ 25 KB/day, peak day 43.5 KB). At that mean the 1 MiB cliff is
  roughly **five weeks** out, not years — the size gate is a live concern, which is why it was
  built rather than withdrawn.
- **Cite only the card the PR is about.** The same `card#N` token drives the changelog obligation
  *and* the board writeback (#343's mention-vs-closure defect). A card cited "for context" is a
  spurious changelog obligation and a wrongly-moved card at once. Nothing enforces this yet —
  it is discipline, stated here so it is at least written.
- `CLAUDE.md` (root) will carry the last-10-changes snapshot table per the operator's structure
  direction; format details wait on #346 rather than being guessed.

## 5. Deployment

- **Two instances, two owners (D-13).** A **sandbox** instance run by a dedicated Mezzanine
  agent (stood up after the P0 designs land — see the handoff below), where all development and
  the first live-floor demos happen; and a **prod** instance on the operator-provisioned
  separate host (D-08), deployed only via **`bin/deploy.sh`** — hand-deploys to prod are not a
  path.
- **`bin/deploy.sh` exists (card#7459) and has never run against a host.** ⚠ The kanban-board
  sample it was to be adopted from — `share/issue-347/kanban-solo/kanban-board-deploy.sh @
  ee7df9b9` — **was not reachable from this seat** (no such path on this host; the roundtable repo
  answers 404 to this credential), so the fleet's deploy scripts are *not* yet demonstrably one
  shape and no line of the sample was copied. What was adopted is rt#347's own enumeration of the
  sample's load-bearing properties: forward-only migrations (the store's DDL is
  non-transactional), the load-bearing cache-rebuild order, down-and-stay-down on failure for operator review,
  re-exec-after-checkout, and every "fails once then silently succeeds on a bare re-run" state made
  loud. **Two things are outstanding**: rt#347 item 5 — take the adapted script to kanban-solo on a
  fresh thread once the P2 host exists — and the first real run, which is the only place the
  live-host leg (systemd units, sudo posture, MariaDB, PHP-FPM, `/up` through the real proxy) is
  exercised at all.
- **One divergence from the sample's topology, ruled binding by rt#347 item 1:** the deploy
  restarts the long-lived daemons *inside* the window — `mezzanine:fold`, `mezzanine:sweep`,
  `mezzanine:feed-heartbeat` — and no feed daemon: card#9287 re-pinned the feed to Server-Sent
  Events served by PHP-FPM, so the feed's code is reloaded with FPM, after `mezzanine:feed-reload`
  has told every open stream to end (`docs/design/FLEET-STATE.md § 2.1`). The sample's
  host runs a host-scoped shared daemon serving several tenants and is silent about restarting it;
  this host is single-tenant, so every long-lived PHP process on it holds *this* app's code, and
  copying that silence would leave every deploy serving stale code invisibly — daemons up, floor
  rendering, payloads one release old. ⚠ `bin/deploy.sh` still models a Reverb unit derived from
  `BROADCAST_CONNECTION`; retiring that, adding the feed-reload step before the FPM reload, and
  running § 8.3's two host checks (R1, R2) is deploy-script work the amendment files rather than
  does, this being design-only.
- **What the deploy refuses on** — every one of them seen to fail before it was trusted: root,
  an unreviewed failure marker, a modified prod tree, `.env` (missing, world-readable, non-production,
  `APP_DEBUG=true`, empty `APP_KEY`, a `DB_CONNECTION` other than `mysql`, TLS-less, a
  non-persistent `CACHE_STORE`), the PHP floor, a commit not contained in `origin/main` (`--allow-unreleased` is the deliberate escape), a
  same-commit no-op (`--redeploy`), `trustProxies('*')`, a missing npm lockfile, a migration that
  ALTERs `events` without stating its algorithm (`docs/design/FLEET-STATE.md § 6.9` rule 1 —
  *"the deploy checks it"*, and this is that check), and a systemd unit that is missing or
  disabled. It warns, rather than refusing, where the doc's own reading is that the state is
  fail-safe: no `trustProxies()` at all, and keys the release's `.env.example` names that the
  host's `.env` does not set.
- **The handoff milestone:** when D1–D3 are merged, the project moves to its own agent seat
  (sandbox owner + implementer); aimla-pm drops to coordinator (reviews, cross-project routing,
  this plan's upkeep). The new seat inherits this plan as its orientation — which is a reason
  this document stays current rather than aspirational.
- **Trusted proxies must be set to the actual reverse proxy at first deploy, and never to `*`.**
  D1 § 12.3's failed-authentication limit is keyed on the **source IP**, and Laravel resolves that
  from `X-Forwarded-For` only for proxies it trusts. The app currently trusts none, which is
  correct for an unproxied host and fails safe either way: behind an untrusted proxy every request
  appears to come from the proxy and the limit is merely coarse, whereas `trustProxies('*')` would
  let any client forge the header and defeat the key entirely — the limit would then be a
  decoration, which is the one thing § 12.3 says it must not be. The deploy host is not
  provisioned (D-08), so the value cannot be set now; setting it is part of standing that host up.
  `bin/deploy.sh` enforces the half that is enforceable: it **refuses** a target tree carrying
  `trustProxies('*')` and **warns** when none is configured — this paragraph's own reading of the
  two states, not a stricter one.
- **A deployed host installs with `composer install --no-dev`, and that is a security obligation
  rather than a size one.** `database/factories/` and `database/seeders/` are in composer's
  **`autoload-dev`** block (card#9070's review round moved them), so on a `--no-dev` install
  `Database\Factories\UserFactory` and every seeder are not loadable at all. Install *with* dev
  dependencies and they are loadable again: the two halves are one obligation. ⚠ **This is now
  defence in depth rather than the whole defence**, and the change is card#9070's second review
  round: the factory used to hash the literal `password`, and it now mints a random value per run
  (`UserFactory::password()`), so there is no known credential left for a dev-dependency install to
  expose. The obligation stays because it is the cheaper of the two guarantees to lose — nothing
  reds if a host never honours the flag. **`bin/deploy.sh` now honours it** (card#7459 — it
  installs with `composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist`),
  and it is the only thing that does, which is the same reason D-13 makes the script the only path
  to prod: a hand-deploy loses the guarantee silently. Verified rather than reasoned: a real `--no-dev` install
  from this lockfile resolves `App\Admin\UserProvisioning` and does **not** resolve
  `Database\Factories\UserFactory`. `server/tests/Feature/Admin/ProductionAutoloadTest` holds the
  repo's half — the namespaces stay dev-only, and nothing under a production autoload root mints a
  credential from a literal.
- **A deployed host sets `CACHE_STORE` to a store that PERSISTS between requests — `.env.example`
  ships `database` — and `array` or `null` is a security regression rather than a tuning choice.**
  The login path's non-enumerability depends on it: `server/app/Auth/ActiveUserProvider` pays for
  its dummy bcrypt **once per deployment** by keeping it in the cache, so an unknown address and a
  known one with a wrong password cost the same hashing work. On a store that does not survive the
  request, every miss mints that hash again — two bcrypts against one — and the timing difference
  between "no such account" and "wrong password" comes back as a user-enumeration oracle, on an
  endpoint whose rate limiter keys on **email+IP** and therefore does not throttle probing N
  addresses from one IP at all. The class's own docblock names this as the one configuration that
  reintroduces the oracle; this bullet is where an operator standing a host up reads it, because a
  comment in `app/` is not a deployment obligation. The property itself is held by
  `server/tests/Feature/Admin/AuthSecretsNeverSurfaceTest::test_a_miss_and_a_hit_cost_the_same_total_hashing_work`
  and by `::test_the_dummy_hash_survives_the_request_that_minted_it` — the second is the arm that
  reds when the value stops outliving a request, which is exactly what a non-persistent store does
  to it.
- **A deployed host that wants the two-factor reset sets `MAIL_MAILER` to a real transport and
  PROVES it, and a host that does not sets nothing and the path stays closed** (card#9077). The
  operator ruled that a locked-out user may reset their second factor by email; the code path is
  built and `App\Auth\TwoFactorReset::isAvailable()` REFUSES to mint a code when the resolved
  mailer's transport is `log` or `array`. That refusal is not conservatism about delivery: under
  `log`, `Illuminate\Mail\Transport\LogTransport` writes the whole rendered message — the reset code
  included — into `storage/logs/`, so a host on the default configuration would be minting live
  credentials into a log file. ⛔ **Configured is not working**, and the difference is what gets
  discovered at 3am: `php artisan mezzanine:mail:preflight --to=…` is the command that exercises the
  boundary, and standing a host up should include running it. ⚠ **The security consequence is
  stated rather than buried:** an emailed reset downgrades two-factor authentication to mailbox
  possession for the second factor — anyone who controls the account's mailbox can strip it (they
  still need the password to sign in). That is inherent to the mechanism and was accepted
  knowingly; it is why the destination is read from the user row and is never a request parameter,
  and it is why an install that does not want the property simply leaves `MAIL_MAILER` alone.
- Plan-side obligations, host-agnostic: Laravel behind the web server, the feed served as SSE by
  PHP-FPM (card#9287), from
  `server/` (D-16); `.env` copied from `server/.env.example` and filled in on the host, with
  `php artisan key:generate` run there — the example ships an empty `APP_KEY` and no
  credential; **`php artisan mezzanine:user:create` run there too, because nothing else creates a
  user account and a host without one cannot be signed into at all** (card#9070; `README.md`
  owns the command's options and the lockout-recovery case, and is the surface an operator
  deploying reads); seat-token store with 0600 posture; the same release≠deploy rule as
  `docs/VERSIONING.md` — a release states which of the **two deploy targets** (server app;
  per-seat reporter) it touches, and prod only ever moves by `bin/deploy.sh`.
- **Reporter rollout order:** aimla's four seats first (all on one box — cheap), then the
  operator-named Windows seat as the P1 validation gate, then other installs as they opt in.
- **The first-release preconditions are all SPENT** — `v0.1.0` was tagged 2026-08-24 and
  `v0.2.0` on 2026-08-30. The changelog file exists (§ 4; `docs/CHANGELOG.md` since
  2026-08-25), and the first-release `--base` dispatch is history: an ordinary release passes
  no `base` at all, and `docs/KANBAN.md § G-2` owns the cases where `--base` is still the
  escape — do not restate them here. The one thing that did **not** expire with them is the
  bootstrap trap (`auto-tag-version` tags *any* push to `main`, not only a release PR);
  `docs/VERSIONING.md` owns that standing rule.

## 6. Risks & standing cautions

| Risk | Standing answer |
|---|---|
| Old reporter → new ingest skew | D1's versioning + support window; loud reject; VERSIONING owns the policy |
| False idles on busy seats | the kill-vs-complete gate (#7337) blocks trusting the signal until passed. **Run 2026-08-25: it FAILED and the bar stands** — the reporter was right and the design's guarantee did not hold on the installed harness. D1/D2 amended (`D2-MUST` #1's background-task condition, § 6.6's kill signature, § 8.6's cross-session exclusion); the reporter's own § 6.6 mapping is **not yet updated** |
| Secrets in telemetry | minimized payload at the reporter (D-06); RED fixtures for the sanitizer |
| Board-14 mention-vs-closure (#343) | inherited from the bridge writeback; first-release card enumeration discipline until the upstream fix lands |
| **Harness facts drifting under the design** | D1 § 6.0's re-capture obligation, widened to **any** version change after a PATCH bump moved the call lifecycle (#7337). `tools/at1-kill-vs-complete/` re-runs the proof; the version is read from the running binary, never from config |
| Windows divergence | validation seat is a P1 gate, not a P4 afterthought |
| Doc structure churn | root `CLAUDE*` files wait on #346; nothing to migrate later |
| Scope creep toward the watchdog | out of plan; only the REST snapshot is Mezzanine's part |

## 7. What "v0.1.0 shipped" means

A fleet member can log in through MFA on the public host, see the aimla floor with four desks
showing live, honest state (working / idle / blocked, current task title, context gauge), click
a desk and see the drill-down with real subagent titles — with every animation that **claims**
anything traceable to a real event (`docs/design/FLOOR.md § 6.1`; ambient decoration that claims
nothing is permitted and bounded there), the reporter proven harmless to its host seat, and the
schema's compatibility story
already in force. The building (multi-floor) may still be one floor deep; the *architecture*
may not be one floor deep.

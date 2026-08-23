# Mezzanine — build plan

The full plan from specification to a running floor. This is the durable record of *what* gets
built, *in what order*, and *why the order is what it is*. The proposal (the operator-reviewed
artifact) owns the vision; this document owns the execution. Where the two disagree, this one
is newer and wins — and the disagreement should be fixed in the proposal.

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

Three design artifacts precede their builds, in strict order, because each is the contract the
next consumes. Each is a PR into `dev` reviewed like code.

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
of the server (`docs/VERSIONING.md § Wire protocol` already owns the compatibility rules — this
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

**D2 — fleet-state model + feed contract (`docs/design/FLEET-STATE.md`).** What the store keeps
(per-seat current state + a short activity window, keyed by install/seat), retention, and what
the browser receives: **snapshot-on-connect, deltas after**, over Reverb; the REST snapshot for
non-browser consumers (the watchdog). Merge rules for the three sources (telemetry supplies the
live *action*; GitHub/board events supply the human-readable *task title*; the three-tier status
fallback from the proposal).

**D3 — floor UI spec (`docs/design/FLOOR.md`).** Screens: lobby (building summary), floor, desk
drill-down panel (current task linked to card/thread, recent-activity timeline, context gauge,
subagents as interns at a side table — their titles from the Task dispatch, programmatic).
Identity mapping: how a seat becomes a desk and an install becomes a floor, stable across
restarts. The honesty principle from the proposal binds every animation: driven by a real event,
or absent.

## 3. Work breakdown

Board 14 is the queue; this table is the map. Order within a phase is by dependency; phases
overlap where the dependency arrows allow. "Accept:" lines are the review floor, not the ceiling.

| Phase | Work (board card) | Depends on | Accept |
|---|---|---|---|
| **P0 design** | D1 event schema (new card) | — | review + the kill-vs-complete test is *specified* |
| | D2 fleet-state + feed (new card) | D1 | review; snapshot+delta contract explicit |
| | D3 floor UI spec (new card) | D2 draft | review; identity mapping defined |
| **P1 telemetry** | `fleet-reporter` core: spool + flusher (#7335) | D1 | hermetic selftest; **never blocks the agent**; survives server down; sanitizer has RED fixtures |
| | installer, Linux + **Windows validated** (#7336) | #7335 | real install on a Windows seat before anything trusts the signal |
| | kill-vs-idle proof (#7337) | #7335 | the D1-specified test, run for real against a `/clear` |
| **P2 server** | Laravel skeleton + MFA on stock packages (#7334, re-scoped per D-04) | — | Fortify + TOTP; MFA gates page, **websocket handshake**, and REST snapshot; seat-token ingest is separate and never browser-facing |
| | ingest endpoint (#7338) | D1, skeleton | rejects unknown schema loudly; per-seat tokens; rate limits; statusLine sampled not streamed |
| | fleet-state store + Reverb feed + REST snapshot (#7339) | D2, ingest | snapshot+delta observed in a browser; REST snapshot serves the watchdog case |
| **P3 floor** | character port + ATTRIBUTION (#7340) | — | renders in a plain browser; lineage file complete |
| | floor v1 (#7341) | D3, P2 feed, #7340 | live desks from real telemetry; CC0 tiles; Tiled map |
| | drill-down + interns (#7342) | #7341 | subagent titles appear from real Task dispatches |
| **P4 building** | elevator, floor-per-PM, solo floor (#7343) | #7341 | schema already multi-install; second floor renders from a second install's feed |
| | CI lanes for app code (#7344) | first PHP/JS code | required-check list updated the same PR (see `docs/VERSIONING.md` — a new workflow is not auto-required) |
| **cont.** | changelog + card-entry gate (new card, per #344) | — | § 4; gate seen to fail once before trusted |
| | `CLAUDE*.md` structure (new card, blocked on #346) | #346 answer | index + chapters per sola-inventory's pattern |
| | `bin/deploy.sh` prod deploy (new card, blocked on kanban-solo sample) | sample + P2 server | prod moves only via the script; seen to fail on a broken precondition before trusted |

Deliberately **not** in this plan: the autonomy watchdog (roundtable #341 + our `[WAKE]` prototype
#659 — separate track; Mezzanine's contribution to it is the REST snapshot), and the aimla
cutover work, which outranks all of this whenever it is live.

## 4. Documentation & release discipline

Adopted from kanban-solo's #344 answer (measured, not folklore), with one deliberate addition:

- **The enforced unit is the card, not the PR.** A PR whose title/subjects carry `card#NNNN` owes
  a line-initial `- **card#NNNN** — …` bullet under `## [Unreleased]` in `docs/CHANGELOG.md`,
  **in the same PR**. Tokenless PRs owe nothing. Bold-anywhere is not accepted — line-initial is
  the rule, and their incident log records why (a prose mention discharged another card's
  obligation).
- **Written at PR time; the release only collects.** The release retitles `[Unreleased]` and
  opens a fresh empty one.
- **The gate fails closed on every unmeasurable state** (shallow clone, no tag, missing floor —
  exit 1, never skip). This is the property most worth copying, and it gets a seen-to-fail
  exercise before it is trusted.
- **Our addition — a size gate.** The toolkit pairs "never truncate" with no size check; theirs
  is at 59.5% of the contents-API's silent 1 MiB truncation cliff. A new project should not adopt
  anti-trim without the check, so ours gates `docs/CHANGELOG.md` growth with headroom (the
  moodle `doc-size-threshold` pattern: threshold = cliff − 14 days of measured growth).
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
  path. The deploy script follows the kanban-board project's pattern (sample requested from
  kanban-solo); adopted, not invented, so the fleet's deploy scripts stay one shape.
- **The handoff milestone:** when D1–D3 are merged, the project moves to its own agent seat
  (sandbox owner + implementer); aimla-pm drops to coordinator (reviews, cross-project routing,
  this plan's upkeep). The new seat inherits this plan as its orientation — which is a reason
  this document stays current rather than aspirational.
- Plan-side obligations, host-agnostic: Laravel + Reverb behind the web server; `.env` from a
  staged example; seat-token store with 0600 posture; the same release≠deploy rule as
  `docs/VERSIONING.md` — a release states which of the **two deploy targets** (server app;
  per-seat reporter) it touches, and prod only ever moves by `bin/deploy.sh`.
- **Reporter rollout order:** aimla's four seats first (all on one box — cheap), then the
  operator-named Windows seat as the P1 validation gate, then other installs as they opt in.
- The first release (`v0.1.0`) waits for: the changelog file existing (§ 4), the release being
  deliberate (`auto-tag-version` tags *any* push to `main` — the bootstrap trap is documented),
  and the first-release `--base` dispatch (G-2).

## 6. Risks & standing cautions

| Risk | Standing answer |
|---|---|
| Old reporter → new ingest skew | D1's versioning + support window; loud reject; VERSIONING owns the policy |
| False idles on busy seats | the kill-vs-complete gate (#7337) blocks trusting the signal until passed |
| Secrets in telemetry | minimized payload at the reporter (D-06); RED fixtures for the sanitizer |
| Board-14 mention-vs-closure (#343) | inherited from the bridge writeback; first-release card enumeration discipline until the upstream fix lands |
| Windows divergence | validation seat is a P1 gate, not a P4 afterthought |
| Doc structure churn | root `CLAUDE*` files wait on #346; nothing to migrate later |
| Scope creep toward the watchdog | out of plan; only the REST snapshot is Mezzanine's part |

## 7. What "v0.1.0 shipped" means

A fleet member can log in through MFA on the public host, see the aimla floor with four desks
showing live, honest state (working / idle / blocked, current task title, context gauge), click
a desk and see the drill-down with real subagent titles — with every animation traceable to a
real event, the reporter proven harmless to its host seat, and the schema's compatibility story
already in force. The building (multi-floor) may still be one floor deep; the *architecture*
may not be one floor deep.

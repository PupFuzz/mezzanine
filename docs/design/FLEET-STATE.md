# D2 — the fleet-state model and feed contract

**Wire events → durable fleet state → the floor.** What the server keeps, what it derives, and the
contract every consumer reads.

> **Status: Draft — pending design review.** Owner: aimla-pm. Gate:
> [`docs/PLAN.md § 2`](../PLAN.md#2-design-first-gates--the-order-is-the-plan) (P0 design, board 14).
> Written to the **standalone-implementer standard (D-14)**: an agent holding only this file and
> [D1](EVENT-SCHEMA.md) must be able to build the store, the fold and both read surfaces. Nothing here
> is built yet — there is no Laravel application in this repo. Every number carries its derivation;
> every failure path names its behaviour and its observable. Decisions a reviewer is most likely to
> contest are collected in [§ 13](#13-decisions-taken-revisable-at-review), and each is **decided**,
> not parked. The obligations [D1](EVENT-SCHEMA.md) places on this document are enumerated in
> [Appendix A](#appendix-a--every-d1-obligation-and-where-it-is-discharged), one row per obligation,
> each pointing at the section that discharges it.

---

## 0. Overview

1. **D1 is upstream and is not restated here.** [D1](EVENT-SCHEMA.md) owns the wire: the 14 event
   kinds, the batch envelope, ingest authentication, validation, error bodies, rate limits and the
   atomic-batch rule. This document owns everything after a batch is durably accepted: the store, the
   derivation, and the two read surfaces. Where a fact belongs to D1 it is **cited by section**, never
   copied.
2. **State is a pure function of stored facts, not a stored state machine.** The fold projects wire
   events into six fact tables (sessions, calls, attention requests, and three counters/registry
   tables); the seat's rendered state is recomputed from those facts by one deterministic function
   ([§ 4.3](#43-the-derivation-function)). Nothing in this design can get stuck in a state, because
   there is no state to get stuck in — and every fact the function reads has a **stated ceiling**
   ([§ 4.6](#46-every-open-fact-has-a-ceiling)).
3. **Derivation is asynchronous, by D1's own contract.** [D1 § 4.6](EVENT-SCHEMA.md#46-successful-response)
   returns `202` because "the server has durably accepted the batch for processing, and state
   derivation is asynchronous". The ingest writes the durable log; a separate **fold worker** advances a
   per-seat cursor over it ([§ 6.5](#65-the-fold)).
4. **Delivery is never activity.** Every timestamp derived from *receiving* data is named
   `*_received_at` / `last_receipt_at` and may drive only transport states. A claim that a seat was
   *doing* something comes only from that seat's own turn and tool events. [§ 3](#3-delivery-is-not-activity)
   is that rule and its enforcement.
5. **Silence is a rendered state.** A seat that stops reporting becomes `stale` at 300 s and `offline`
   at 900 s, carrying `no_data_since` — never a stale `working` glyph, never a quiet row removal, and
   never `idle` ([D1 § 9.1](EVENT-SCHEMA.md#91-the-cadence-and-the-alarm), `D2-MUST` #2). The same
   property is built one layer out, in the feed itself: a browser can always tell "the fleet is quiet"
   from "the feed died" ([§ 8.3](#83-the-websocket-delta-feed)).
6. **The store is MySQL 8.0 on a dedicated host** (operator decision, `docs/PLAN.md` D-15). Every
   query crosses a network, so the design batches writes, reads a snapshot in one query, and states a
   fail-posture for the store being unreachable on every path that touches it ([§ 2.2](#22-fail-posture-per-path)).
7. **Two read surfaces with two different compatibility postures.** The REST snapshot has an
   independently-upgraded consumer (the bridge's autonomy watchdog), so it carries a version line and
   the additive-change discipline. The WebSocket delta feed ships with the browser code in the same
   deploy act, so it does not — and says so, rather than inheriting a rule it does not need
   ([§ 8.1](#81-two-surfaces-two-compatibility-postures)).
8. **Snapshot-then-deltas is a protocol, not a sequence.** The client subscribes first, buffers, then
   fetches the snapshot, then discards buffered deltas at or below the snapshot's watermark. The
   watermark is a server-minted per-seat `state_version`, **not** D1's `(seq_epoch, seq)` — and
   [§ 8.5](#85-gaps-reconnect-and-why-state_version-is-not-seq) states exactly why both exist and which
   is authoritative for what.
9. **Every server-side predicate reports both branch counts and alarms when one goes constant**, the
   same structural backstop D1 builds for the reporter ([D1 § 9.4](EVENT-SCHEMA.md#94-the-predicate-constant-alarm)),
   applied to this plane's own predicates ([§ 5](#5-server-side-predicates-and-their-controls)). A peer
   install ran 30 days dark on exactly this failure and the incident is cited where the rule is stated.
10. **The store's names are pinned and published before anything is built** — production, sandbox and
    test database names, and the test-suite pins that make a test run incapable of touching the other
    two ([§ 6.2](#62-database-names-pinned-and-published)).

```
   seats (D1)          │  Mezzanine host                                    │  consumers
   ─────────────       │  ────────────────────────────────────────────      │  ─────────
   fleet-reporter ──▶ POST /api/ingest/events        (D1 § 12, card #7338)
                        │        │                                          │
                        │        ▼  one transaction                         │
                        │   ┌──────────────┐    MySQL 8.0, dedicated host   │
                        │   │  events      │◀── durable log, 14-day         │
                        │   │  batches     │    retention, dedup key        │
                        │   └──────┬───────┘                                │
                        │          │ per-seat cursor (fold worker)          │
                        │          ▼                                        │
                        │   ┌──────────────────────────────┐                │
                        │   │ sessions · calls · attention │  facts         │
                        │   │ seat_state · transitions     │  + projection  │
                        │   └──────┬───────────────────────┘                │
                        │          │  derive() ─ one function               │
                        │          ├──────────▶ GET /api/fleet/snapshot ────┼─▶ browser (MFA)
                        │          │            GET /api/fleet/seats/…      │   watchdog (mzr_ token)
                        │          └──────────▶ Reverb  private-fleet.<id> ─┼─▶ browser only
                        │   sweeper (15 s): staleness, orphans, ceilings    │
```

---

## 1. Scope, non-goals, and the D1 boundary

### 1.1 What this document owns

| Owned here | Section |
|---|---|
| The per-seat state model: states, entry and exit edges, ceilings, and what may never mint a state | [§ 4](#4-the-seat-state-model) |
| Server-side predicates and the control that proves each can answer both ways | [§ 5](#5-server-side-predicates-and-their-controls) |
| The store: deployment posture, database names, DDL, the fold, retention, sizing, migrations | [§ 6](#6-the-store) |
| Where D1 § 12.7's server-side counters live and how they are exposed, plus this plane's own counters | [§ 7](#7-counters) |
| The feed: REST snapshot, WebSocket deltas, snapshot-then-deltas, gaps, reconnect, backpressure | [§ 8](#8-the-feed-contract) |
| Read-side authentication and rate limits | [§ 9](#9-read-side-authentication) |

### 1.2 Non-goals — stated so an implementer cannot widen scope in good faith

| Not in this contract | Why, and who owns it |
|---|---|
| **The wire schema, ingest auth, validation, error bodies, rate limits, the atomic-batch rule** | [D1](EVENT-SCHEMA.md). This document consumes an accepted batch; it does not re-specify how a batch becomes accepted. Card #7338 builds the ingest from D1; card #7339 builds everything here. |
| **Anything rendered** — desks, floors, sprites, animation, the identity→desk mapping | D3 (`docs/design/FLOOR.md`). This document ends at a JSON object and a state name. Where D1 or this document says "renders", it is naming the **obligation** D3 inherits, not the pixels. |
| **Ingest of GitHub webhook and kanban board events** | Not designed anywhere yet. [§ 4.9](#49-the-task-title-merge-and-what-is-not-specified-here) specifies the *merge rule and the columns* — which are state-model questions and therefore D2's — and declares the producers' design an open question with a named cost, rather than inventing them. |
| **The autonomy watchdog** | Roundtable #341, a separate track. Mezzanine's contribution is the REST snapshot ([`docs/PLAN.md § 3`](../PLAN.md#3-work-breakdown)), and this document specifies it as a first-class consumer. |
| **MFA and the browser session** | Card #7334 (Fortify + a stock TOTP package, D-04). This document states *which* surfaces MFA gates and what a failed gate returns; it does not specify the second factor. |
| **Alerting, paging, e-mail** | There is no notifier here. Every degraded condition surfaces as a counter, a badge and a rendered state. A fleet that wants a pager reads the REST snapshot. |
| **Historical analytics beyond the retention window** | Retention is 14 days ([§ 6.7](#67-retention-and-purge)). There is no warehouse, no roll-up table and no trend history, deliberately: the product answers *what is happening now*, and a second, slower copy of the data with its own schema is a second thing to keep true. |
| **Backups of derived state** | Everything except `events` is re-derivable by replay ([§ 6.6](#66-rebuild-from-the-log)). [§ 6.10](#610-durability-posture) states the whole durability position, including what a total loss of the store actually costs. |
| **Any server → seat channel** | D1 § 1 forbids it and nothing here needs it. The read plane is read-only in both directions: it never writes to a seat and never asks one for anything. |

### 1.3 The boundary, stated as a rule

**A fact has one home. If [D1](EVENT-SCHEMA.md) states it, this document cites it by section number and
does not paraphrase it.** Where this document *adds* a rule on top of a D1 fact — a server-side ledger
close D1 does not specify, a clock choice D1 leaves ambiguous — it says so in the sentence that adds it
and files the underlying gap in [§ 14](#14-open-questions-for-the-review-loop) as a D1 amendment need.
This document **never edits D1**: an amendment is a request, not an edit, because a downstream document
silently correcting its upstream is how two documents start disagreeing about which one is the contract.

---

## 2. The two planes, and what each one owes

### 2.1 Processes

**Five processes** — four that run on their own and one an operator invokes — all of which must be
individually restartable without losing or double-applying anything.

| Process | Kind | Cadence | Job | If it dies |
|---|---|---|---|---|
| **ingest** | HTTP request (PHP-FPM) | per batch | validate per [D1 § 12.1](EVENT-SCHEMA.md#121-validation-order), write `events` + `batches`, the seat's **`head_event_id`**, and — only where it is still `NULL`, i.e. on the seat's first-ever event — the **seed of `fold_cursor_received_at`** ([§ 2.3](#23-a-frozen-fold-is-the-dangerous-degradation)), all in one transaction, return `202` | the reporter spools and retries ([D1 § 11.5](EVENT-SCHEMA.md#115-retry-and-backoff)); nothing is lost until a seat's 8-day residency cap |
| **fold** | long-lived daemon (`mezzanine:fold`), supervised | continuous, ≤ 1 s idle poll | advance each seat's cursor (`fold_cursor_event_id` **and `fold_cursor_received_at`**) over `events`, project facts, recompute state, emit deltas | states **freeze** while receipts keep arriving — the one degradation that could look healthy, so it is badged and alarmed ([§ 2.3](#23-a-frozen-fold-is-the-dangerous-degradation)) |
| **sweep** | long-lived daemon (`mezzanine:sweep`), supervised | every **15 s** | apply the **seven** time-derived jobs, and this is their one list: staleness ([§ 4.5](#45-link-states)), orphan-timeout closes ([§ 4.6](#46-every-open-fact-has-a-ceiling)), attention ceilings ([§ 4.4](#44-activity-states-every-entry-and-exit-edge)), compaction ceilings ([§ 4.6](#46-every-open-fact-has-a-ceiling)), the leaving-live clears ([§ 4.5](#45-link-states)), offline quiescence ([§ 4.6](#46-every-open-fact-has-a-ceiling)) and the predicate-constant alarms ([§ 5](#5-server-side-predicates-and-their-controls)). Each pass also recomputes `link_state` and `render_state` for **every** seat, which is what makes a time-derived transition arrive at all, and a pass that moves a version-bearing field bumps `state_version` and enqueues its delta under [§ 6.5](#65-the-fold)'s per-writer rule like any other writer | time-derived states stop advancing; a dead seat keeps rendering its last activity state. Detected the same way as a frozen fold — `sweep_last_run_at` feeds fleet health |
| **purge** | scheduled command (`mezzanine:purge`) | hourly | delete rows past retention in bounded batches | the store grows; alarmed at a stated size, and the dedup guarantee is unaffected for 4 days ([§ 6.7](#67-retention-and-purge)) |
| **retire** | operator command (`mezzanine:retire --seat=<install>/<seat> --by= --reason=`) | on demand | the **only** writer of retirement, and it does the whole of it in one transaction: set `seats.retired_at` / `retired_by` / `retired_reason`, recompute `render_state` (which [§ 4.2](#42-render-precedence) collapses to `retired`), write the transition row with `cause: operator`, bump `state_version`, and publish the `seat.retired` message and the delta ([§ 4.10](#410-retirement-is-a-rendered-state)) | nothing retires — which is correct, because retirement is an operator act and no timeout may ever stand in for one. Re-running it on an already-retired seat is a no-op |

**15 s sweep cadence, derived.** The tightest deadline any time-derived transition has is the `stale`
threshold, 300 s ([D1 § 9.1](EVENT-SCHEMA.md#91-the-cadence-and-the-alarm)). A 15 s cadence bounds
lateness at 15 s = **5 %** of that threshold, which keeps the rendered transition inside the human
tolerance a 300 s threshold already implies, at 5,760 passes/day — each pass being two indexed range
scans over rows with a materialized due-time ([§ 6.4](#64-ddl)) plus one scan of `seat_state`, which
carries exactly one row per seat and is what the per-seat recompute above costs. A 60 s cadence would be 20 % late on the
tightest deadline; a 1 s cadence would multiply the query load by 15 to buy lateness nobody can see.

### 2.2 Fail-posture per path

**Every path states its own posture and its own reason. None of them inherits one from a sibling.**
"Fails closed" here means *refuses to answer rather than answering with data it cannot stand behind*;
"fails open" means *keeps accepting or keeps serving, with the degradation labelled on the answer*.

| Path | Store/dependency unavailable | Posture | Why this posture and not the other |
|---|---|---|---|
| **Ingest write** | MySQL unreachable or the transaction fails | **CLOSED** — `503 server_error`, retryable, nothing acknowledged | The reporter advances its spool cursor on `202` ([D1 § 4.6](EVENT-SCHEMA.md#46-successful-response)). Acknowledging a batch we did not store destroys the only other copy — the exact defect [D1 § 12.4](EVENT-SCHEMA.md#124-batches-are-atomic) refuses for partial ingest, arriving through the store instead of through validation. The seat spools for days; we lose nothing by refusing. |
| **REST snapshot read** | MySQL unreachable | **CLOSED** — `503 fleet_unavailable`, machine-readable, **never `200` with an empty or partial fleet** | An empty fleet is indistinguishable from a calm fleet. This is `docs/KANBAN.md § G-1`'s defect (a 200 with empty data reading as a clean zero) and [`docs/VERSIONING.md § The failure direction`](../VERSIONING.md#the-failure-direction-must-be-safe--reject-loudly-never-drop-quietly)'s rule, on the read side. |
| **REST snapshot read** | MySQL reachable, **some seats' fold cursors stale** | **OPEN, labelled** — serve the state, with `derivation.fold_lag_ms` per seat and `fleet.fold` ≠ `ok` | Frozen state is still the last true state; refusing the whole fleet because one seat's derivation is behind would turn a partial degradation into a total outage. The label is what stops it being read as current. |
| **WebSocket connect** | Reverb up, MySQL down | **CLOSED** — the connection is accepted and immediately sent `fleet.health` with `db: "down"`; no snapshot is served | Same argument as the REST read. The socket stays up deliberately, because it is the channel that tells the browser *why* there is nothing. |
| **WebSocket feed** | Reverb down, MySQL up | **OPEN for reading, CLOSED for the live claim** — REST still serves; the client polls at **10 s** and must render a `feed_down` indicator | A dashboard that silently degrades from live to polled is a dashboard whose age nobody can trust. The poll interval matches D1's flush interval, so the polled floor is no more stale than the live one's own input cadence. |
| **Fold worker** | dead or lagging | **OPEN for ingest, CLOSED for the currency claim** — receipts keep landing, state freezes, seats badge `fold_lag`, `fleet.fold` goes `stalled` | Refusing ingest because *derivation* is broken would discard data we can still store and later derive. Freezing silently is the failure this whole product exists to prevent, so the freeze is announced. See [§ 2.3](#23-a-frozen-fold-is-the-dangerous-degradation). |
| **Fold worker** | a single event raises during projection | **OPEN, counted, quarantined-in-place** — the cursor advances past it, `fold_error` increments, the seat badges `derivation_error`; the event stays in `events` for replay | One malformed event must not wedge a seat's derivation forever — the same judgement [D1 § 11.4](EVENT-SCHEMA.md#114-corruption-the-torn-last-line-and-a-lost-statejson) makes for a torn spool line. Because the log is retained, the fix plus a rebuild recovers the seat exactly. |
| **Sweep worker** | dead | **OPEN for ingest, CLOSED for the currency claim** — the fleet object's `sweep_last_run_at` keeps its last value and `fleet.sweep` goes `stalled` past **60 s** since it ([§ 8.2.4](#824-the-fleet-health-object)) | Identical reasoning to the fold, and stated separately rather than inherited because the *consequence* differs: a dead fold freezes wire-driven transitions, a dead sweep freezes time-driven ones, and only the second one can leave a dead seat rendering `working`. |
| **Feed backpressure** | a client cannot drain its queue | **CLOSED for that connection** — at 256 queued messages or 512 KiB the connection is closed with `resync_required`; other clients are untouched | Dropping deltas silently leaves that browser permanently and invisibly wrong. Closing the connection costs one snapshot fetch and is self-healing. |
| **Read-token verification** | the token store is unreachable | **CLOSED** — `503`, never a cached or assumed grant | A read token gates the whole fleet's activity picture. There is no posture in which "we could not check, so we allowed it" is correct. |
| **Purge job** | dead | **OPEN** — data accumulates; alarm on table size at the stated threshold | Retaining too much costs disk; deleting on a broken assumption costs the dedup guarantee ([§ 6.7](#67-retention-and-purge)). The safe direction is to keep. |
| **Clock (NTP) on the Mezzanine host** | skewed | **OPEN, visible** — `received_at` remains the authority ([D1 § 10.1](EVENT-SCHEMA.md#101-two-clocks-and-which-is-authoritative-for-what)) and every seat's `clock_skew_ms` moves together | A fleet-wide skew shows up as *every* seat badging `clock_skew` in the same direction, which is a legible signature. Nothing else can produce it, so no extra instrument is needed — but the reading is stated here so it is not mis-read as a fleet of broken seats. |

### 2.3 A frozen fold is the dangerous degradation

Receipts are written by the **ingest**; derived state is written by the **fold**. If the fold stops
while the ingest keeps working, `last_receipt_at` keeps moving and the desk keeps showing whatever it
was doing when derivation stopped. That is a floor that looks alive and is lying — the precise failure
[§ 3](#3-delivery-is-not-activity) exists to forbid, arriving through the derivation plane instead of
through a timestamp.

So the fold's own lag is a first-class rendered quantity — and it is **computed at read time from a
basis two different processes write**, never stored as a number the fold itself maintains:

| Quantity | Definition | Threshold | Consequence |
|---|---|---|---|
| `fold_lag_ms` | `0` when `fold_cursor_event_id >= head_event_id`; otherwise `server_now − fold_cursor_received_at`. **The second branch is total, and that is bought at the write site rather than patched at the read one** — see the NULL boundary below. All three operands are columns of the seat's own `seat_state` row: the **ingest** writes `head_event_id` and seeds `fold_cursor_received_at`, the **fold** advances `fold_cursor_event_id` / `fold_cursor_received_at` | — | rides every seat object |
| `fold_lag` badge | per seat | **> 60 s** | seat badges `fold_lag`, counting `fold_lag_alarm_entered` once per lag episode ([§ 7.2](#72-this-planes-own-counters-and-badges)); D3 must not present the seat's activity state as current |
| `fleet.fold = "lagging"` | fleet-wide, over the population [§ 8.2.4](#824-the-fleet-health-object) names once | **any** seat past **60 s** | the fleet object says derivation is behind; no banner |
| `fleet.fold = "stalled"` | fleet-wide, same population | **any** seat past **300 s** | fleet health is degraded; D3 shows a fleet banner |

**Why the basis and not the lag — and why "the newest unfolded event" is the wrong operand.** A stored
`fold_lag_ms` whose only writer is the fold pass dies with the thing it detects: pause the fold and the
number freezes at whatever the last pass wrote, the badge can never fire, and
[AT-D2-21](#at-d2-21-a-frozen-fold-cannot-look-healthy) can never go green. The operand matters as much
as the storage. The age of the *newest* unfolded event is near zero on any seat that is still receiving,
so a frozen fold on a busy seat would read healthy under that definition however it was stored. The
quantity that actually rises is the age of the **oldest** unfolded event, and
`server_now − fold_cursor_received_at` is an upper bound on it: it exceeds the true age by at most one
inter-arrival gap, equals it exactly while the fold is stalled — the case the instrument exists for —
and is pinned to `0` by the cursor test whenever the seat is caught up, so a quiet seat never badges.
Both operands sit on the seat's own row, so the computation costs nothing against
[§ 6.1](#61-deployment-posture)'s one-query snapshot budget; that is why it is done at read time and why
no sweeper recomputation is needed. The three sibling liveness instruments of this design
(`sweep_last_run_at`, `purge_last_run_at`, `state_computed_at`) are already timestamps for the same
reason: a reader can age a timestamp whose writer has died, and cannot age a number.

**The NULL boundary, closed at the write site and not at the read one.** There is one reachable state
in which a fold-written cursor clock has no value: a seat whose first batch has landed and which the
fold has not yet visited. `head_event_id` is above `0` so the cursor test does not fire;
`fold_cursor_received_at` was never written, so `server_now − NULL` is not a number. It is not a rare
window — it opens on every seat's first event, it lasts at least the 2 s visibility lag plus the
fold's poll ([§ 6.5](#65-the-fold)), and it is **unbounded if the fold is down when that first batch
arrives**, which is precisely the state the instrument exists to make visible. So the **ingest** writes
the seed: in the same transaction that first raises `head_event_id` above `0`, and **only where
`fold_cursor_received_at` is still `NULL`**, it sets that column to the `received_at` it stamped on the
batch. The write is one-shot and the fold's advance takes over from there. Three consequences worth
stating:

- The seed is not the "newest unfolded event" operand this section rejects. On a never-folded seat the
  cursor is at `0`, so the oldest unfolded event **is** the seat's first event, and the first batch's
  `received_at` is that event's own receipt time — the lag it yields is exact rather than an upper
  bound, and it rises from the moment the seat's first event lands if the fold is not running.
- `fold_cursor_received_at` is therefore `NULL` only for a seat that has never received an event, and
  such a seat has `head_event_id = 0`, where the cursor test already pins the lag to `0`. The second
  branch is total by construction, which is what lets [§ 8.2.1](#821-the-seat-state-object) declare
  `derivation.fold_lag_ms` non-null, [§ 5](#5-server-side-predicates-and-their-controls)'s
  `fold_current` report both branches on every evaluation, and
  [§ 8.2.4](#824-the-fleet-health-object)'s `max_fold_lag_ms` aggregate a population with no holes in it.
- `mezzanine:rebuild` re-enters the same state deliberately and leaves it the same way: it resets the
  cursor to `0` **and** its clock to the `received_at` of the oldest event it is about to replay
  ([§ 6.6](#66-rebuild-from-the-log)), so the lag is honest for the length of the rebuild rather than
  null through it.

Handling the NULL at read time instead — a `COALESCE` to `0`, or "treat null as caught up" — was
rejected for the reason [§ 6.3](#63-conventions) gives for every other column: a read-time fallback for
missing data is a defect to trace to its write site, and here the fallback would have read *healthy*
on the one state the instrument is for.

**60 s and 300 s, derived.** 60 s is one heartbeat interval ([D1 § 9.1](EVENT-SCHEMA.md#91-the-cadence-and-the-alarm)):
a seat whose derivation is a whole heartbeat behind has certainly missed at least one input, so the
badge cannot fire on a healthy pass. 300 s is the `stale` threshold reused deliberately, so that "the
transport went quiet" and "derivation went quiet" become visible at the same age and an operator
comparing two seats is comparing the same unit. The healthy value is bounded by the fold's own poll
interval plus one pass, ~1 s, so both thresholds sit two to three orders of magnitude above healthy.

---

## 3. Delivery is not activity

### 3.1 The rule

> **A stamp that refreshes only when a seat posts corroborates; it cannot exonerate.**
>
> — sola-pm, roundtable [`PupFuzz/agent-roundtable#341`](https://github.com/PupFuzz/agent-roundtable/issues/341),
> 2026-08-23; a design maxim from the fleet's PM channel, quoted verbatim.

The context, restated so the rule does not depend on the thread: the fleet already had a per-seat
context-percentage stamp that refreshed **when the seat posted a coordination comment**. It was
proposed as a liveness signal, and it cannot be one — a seat working silently for an hour carries a
stale stamp and reads as quiet. A fresh stamp proves the pipe worked; a missing stamp proves nothing
about the agent. Mezzanine's entire value proposition is the difference between those two readings, so
the rule is structural here rather than advisory:

1. **Any timestamp derived from receiving data is named for receipt** — `received_at`,
   `last_receipt_at`, `last_heartbeat_received_at`, `*_received_at` on every projection row. No column
   in this design named for activity is ever written from a receipt.
2. **Activity claims come only from the seat's own emitted turn and tool events.** The activity event
   set is closed and stated in [§ 3.2](#32-the-activity-event-set); `reporter.heartbeat` is **not** in
   it, and neither is a batch arrival.
3. **Receipt drives transport states only.** `live`, `catching_up`, `stale`, `offline` and `disabled`
   are computed from receipt and from heartbeat fields. `working`, `idle`, `blocked`, `stalled` and
   `unknown` are computed from activity facts and may not read a receipt timestamp at all — with one
   stated exception, the orphan/ceiling clocks of [§ 4.7](#47-which-clock-each-ceiling-is-measured-from),
   which use receipt because a *timeout* is a statement about how long we have been waiting for
   something, not a claim about what a seat did.
4. **A seat that only heartbeats is quiet, and renders as quiet.** Its receipt age stays near zero and
   its activity age grows without bound. Both are on the wire, separately, so no consumer has to guess
   which one it is holding ([AT-D2-4](#at-d2-4-a-heartbeat-only-seat-never-looks-busy)).

### 3.2 The activity event set

| Kind | Counts as activity? | Note |
|---|---|---|
| `turn.start`, `turn.end` | **yes** | the agent's own turn boundaries |
| `tool.start`, `tool.end` | **yes** | the agent's own tool calls |
| `subagent.spawn`, `subagent.stop` | **yes** | second projections of a dispatch call's own lifecycle |
| `compaction.start`, `compaction.end` | **yes** | the harness acting on the session because the agent filled it |
| `attention.request`, `attention.resolved` | **yes** | a wait on a human is something the seat did, and is the entry edge of `blocked` |
| `session.start`, `session.end` | **yes** | a session boundary is the agent's own lifecycle |
| `context.sample` | **no** | sampled by the statusLine integration on a **render**, not on an agent action ([D1 § 6.11](EVENT-SCHEMA.md#611-contextsample)); it updates the gauge and the sample age, never `last_activity_*` |
| `reporter.heartbeat` | **no** | produced by the flusher on a timer with `session_id: null`. This is the stamp the maxim is about. |
| *(an unknown kind)* | **no** | stored, counted as `ignored_unknown_kinds` ([D1 § 12.7](EVENT-SCHEMA.md#127-server-side-counters)), and never used to claim activity — a kind we do not understand cannot be evidence of what a seat did |

`context.sample`'s exclusion is the one a reviewer should push on, so the reasoning is on the record: a
status line re-renders on harness-internal triggers, and D1 measured that it is event-driven with a
debounce and no timer ([D1 § 6.0](EVENT-SCHEMA.md#60-conventions-and-how-harness-payloads-are-read),
DOCS-CITED). It correlates with activity and is not produced by it. Treating it as activity would make
the gauge's own refresh look like work — a stamp corroborating itself.

### 3.3 The two ages, and the arithmetic each one is computed by

| Rendered quantity | Computed as | Direction of its error | Bound on the error |
|---|---|---|---|
| **Receipt age** — "no data for N" | `server_now − last_receipt_at` | none — both ends are the server clock | exact |
| **Quiet age** — "nothing done for N" | `server_now − last_activity_received_at` | **understates** the true quiet time by the transit lag of that event | ≤ 70 s on a healthy seat (60 s heartbeat + 10 s flush, [D1 § 9.1](EVENT-SCHEMA.md#91-the-cadence-and-the-alarm)); unbounded on a `catching_up` seat, which is why `catching_up` outranks the activity state in the render precedence ([§ 4.2](#42-render-precedence)) |
| **Narrative timestamps** — "this call started at 14:23:09.882" | `event_time`, the seat's own clock, rendered **as the seat's own claim**, never as an age | seat clock skew, unbounded | `clock_skew_ms` rides the seat object; [D1 § 10.1](EVENT-SCHEMA.md#101-two-clocks-and-which-is-authoritative-for-what) rule 2 forbids rendering it as an absolute clock |

**Why quiet age is computed from `received_at` and not from `event_time`.** `event_time` is the seat's
clock, and a seat resumed from suspend can be minutes out. Deriving an age from it produces "last seen
in 3 hours" ([D1 § 10.1](EVENT-SCHEMA.md#101-two-clocks-and-which-is-authoritative-for-what) rule 2 names
exactly that outcome). The cost of using `received_at` is that the quiet age is short by the transit
lag — the desk looks *more* recently active than it is, by at most ~70 s on a healthy seat. That is the
error direction to state loudly, because it is the one that flatters: it never claims a seat has been
quiet longer than it has. Both timestamps ride the wire, so a consumer that wants the seat's own
narrative has it, labelled.

**The browser's own clock is never used for an age either.** Every feed message and every REST response
carries `server_time`; the client maintains `offset = server_time − browser_now`, refreshed on every
feed heartbeat ([§ 8.3](#83-the-websocket-delta-feed)), and renders ages against the corrected clock. A
browser with a wrong clock is the same defect one layer out, and it is the layer nobody controls.

### 3.4 What this rule forbids, concretely

An implementer reading only this section could still write the defect, so the forbidden forms are
named:

- **Forbidden:** setting `last_activity_received_at` (or any activity column) from a `reporter.heartbeat`,
  a `context.sample`, a batch arrival, or the fold's own pass time.
- **Forbidden:** deriving `idle` from "nothing received for N seconds". *Idle is minted from exactly one
  event* (`D2-MUST` #1) and from nothing else, ever. Silence is `stale`, which is a different state with
  a different glyph.
- **Forbidden:** rendering an activity state without its currency label when the seat is `catching_up`,
  `stale`, `offline` or badged `fold_lag`.
- **Required:** [AT-D2-4](#at-d2-4-a-heartbeat-only-seat-never-looks-busy) is the seen-to-fail test, and
  its RED is precisely the forbidden write — point the activity column at the heartbeat and watch the
  desk stay busy forever on a seat that has done nothing since Tuesday.

---

## 4. The seat state model

### 4.1 Two axes and a badge set

A seat's state is **not** a single scalar, and the reason is `D2-MUST` #2: `stale` may never render as
`idle`. With one scalar, every transport condition would have to be collapsed into the activity
vocabulary at write time, and the collapse would then be the only thing stored — losing, permanently,
the answer to "what was it doing when it went dark".

| Axis | Values | Source |
|---|---|---|
| **`link_state`** | `live` · `catching_up` · `stale` · `offline` · `disabled` | receipt and heartbeat fields ([§ 4.5](#45-link-states)) |
| **`activity_state`** | `working` · `idle` · `blocked` · `stalled` · `unknown` | the fold's facts ([§ 4.3](#43-the-derivation-function)) |
| **`badges[]`** | the reporter's own `degraded` members ([D1 § 9.3](EVENT-SCHEMA.md#93-degradation-counters), 12 members, closed) **plus** the server-derived set ([§ 7.2](#72-this-planes-own-counters-and-badges)) | both |
| **`render_state`** | one value, the precedence collapse of the two axes | derived, [§ 4.2](#42-render-precedence) |

`render_state` is computed **once, on the server**, and shipped alongside its components. D3 renders
`render_state` and may use the components for the drill-down; it never re-derives the collapse. One
fact, one home — a precedence re-implemented in JavaScript is a second copy free to drift, and the
first thing it would drift on is the `stale`-vs-`idle` rule that `D2-MUST` #2 exists to protect.

### 4.2 Render precedence

```
render_state =
    seats.retired_at IS NOT NULL -> "retired"                # an operator act, § 4.10
    link_state != "live"         -> link_state               # disabled | offline | stale | catching_up
    otherwise                    -> activity_state           # working | idle | blocked | stalled | unknown
```

Read top-down; the first match wins. **There is deliberately no ordering here among the transport
values.** `link_state` is a single scalar, so a collapse that ranked `disabled` above `offline` would be
ranking two values one column cannot hold at once; the ordering that decides *which* value the scalar
takes is a real decision and it lives once, in [§ 4.5](#45-link-states)'s cascade, where it is made.
`render_state`'s **ten** members are therefore exactly `retired`, the four non-`live` link values and
the five activity values — a set with no member of its own. Three consequences worth stating because a
reviewer will test them:

- **`stale` and `offline` can never render as `idle`** — they short-circuit above the activity axis
  entirely. That is `D2-MUST` #2 discharged structurally rather than by a rule someone must remember.
- **`catching_up` outranks the activity state** because a draining seat's activity facts are hours old
  ([D1 § 9.1](EVENT-SCHEMA.md#91-the-cadence-and-the-alarm): "`received_at` is fresh while the *content*
  is hours old"). The activity state still rides the object as `activity_state`, with
  `activity.last_event_time` saying how old it is.
- **`disabled` and `offline` are distinct desks, and [§ 4.5](#45-link-states)'s cascade is what keeps
  them apart.** A seat with `enabled: false` keeps heartbeating
  ([D1 § 6.14](EVENT-SCHEMA.md#614-reporterheartbeat)) — it is off, not gone, and D1 states plainly that
  the two must not look alike. A seat that is both disabled *and* has stopped heartbeating takes
  `offline`, because the cascade tests silence **before** it tests the flag and the flag is only known
  from a heartbeat that is no longer arriving; the object's `enabled` field carries the last value seen,
  with `delivery.last_heartbeat_at` saying when.

### 4.3 The derivation function

`activity_state` is a pure function of five facts, evaluated in a fixed precedence. It reads no
timestamp of receipt, **no `link_state`**, holds no memory of the previous state, and is total — every
input combination yields exactly one output.

```
derive_activity(seat) -> (state, unknown_reason)

  facts:
    A = open attention request for this seat            (attention_requests, resolved_at IS NULL)
    S = stalled session for this seat                   (sessions.stalled_since IS NOT NULL, ended_at IS NULL)
    C = count of open calls for this seat               (calls, closed_at IS NULL)
    T = an open turn on the seat's current session      (sessions.turn_open = 1)
    L = the seat's last turn record, SEAT-scoped and outliving its session:
        the row of `sessions` for this seat with the greatest `last_turn_ended_at`,
        read as (last_turn_end_reason, last_turn_aborted_count, stalled_cleared_by);
        null when the seat has no turn record at all

  1. if A                      -> ("blocked",  null)
  2. if S                      -> ("stalled",  null)
  3. if C > 0 or T             -> ("working",  null)
  4. if L.end_reason == "stop_hook" and L.aborted_count == 0
                               -> ("idle",     null)
  5. if L is null              -> ("unknown",  "no_data_yet")
  6. otherwise                 -> ("unknown",  unknown_reason_for(L))
```

**`L` is seat-scoped and survives its session's end, and that is a decision, not an oversight.** A seat
that finished a turn cleanly and then exited — the ordinary `/exit`, and the flusher's 90-minute
`inferred_silence` close of an already-idle session — stays `idle`. The `idle` was minted by the
`turn.end`, which is `D2-MUST` #1's only permitted minter; the `session.end` changes no fact rule 4
reads, so it cannot un-mint it, and rendering `unknown` there would replace a positive observation ("the
agent said it finished") with an absence of one. What `session.end` **does** clear is `T`, `C` and `S`,
which is why a session ending over a *dirty* or *api_error* turn record still lands in rule 6. The
selection rule matters on a seat running two terminals: `L` is the newest turn record the seat has,
whichever session produced it. [§ 4.8](#48-what-may-never-mint-a-state) row 5 states the same rule from
the other side, and the discriminating fixture is `clean_turn_then_exit`
([§ 11](#11-acceptance-tests)). D1 does not state whether `idle` survives a `session.end`; the reading
above is filed as a D1 amendment need in [§ 14](#14-open-questions-for-the-review-loop), item 10.

**Why precedence and not a state machine.** Two of these facts are genuinely simultaneous. A permission
prompt fires while the tool call it is about is already open ([D1 § 6.12](EVENT-SCHEMA.md#612-attentionrequest):
the request carries the `call_id` of the open call), so the seat has an open call **and** an open
attention request at once. [D1 § 8.6](EVENT-SCHEMA.md#86-server-side-interpretation-of-open-call-state)'s
last row says any open call renders `working`; `D2-MUST` #5 says an `attention.request` mints `blocked`.
Both are true, and **D1 states no precedence between them** — so this document states it here, loudly,
because it is the one place two upstream rules are simultaneously satisfiable:

> **`blocked` outranks `working`.** A seat waiting on a human is not working, whatever its call ledger
> says. The alternative — `working` outranks `blocked` — would make *blocked* unreachable on the exact
> path that produces it (`PermissionRequest` fires for a call that is already open), rendering
> `D2-MUST` #5 and `docs/PLAN.md § 7`'s *blocked* requirement dead on arrival.

`stalled` above `working` follows the same reasoning one step down: the reap that accompanies a
`turn.end(api_error)` closes that scope's calls ([D1 § 8.3](EVENT-SCHEMA.md#83-the-reap-rules)), so `C`
is normally 0 — but a call opened inside a subagent survives it, and a rate-limited seat with one
orphaned subagent call is stalled, not working.

**Rule 3 is `working`, and `T` alone is enough.** A turn open with no call is the model generating
tokens. Reading that as `idle` would render every thinking seat as a quiet desk, which is the false-idle
class in its most ordinary form.

**Rule 4 is `D2-MUST` #1, transcribed as a predicate and nothing more.** It is the *only* rule in this
document that can produce `idle`. [§ 4.8](#48-what-may-never-mint-a-state) is the list of things that
must never reach it.

`unknown_reason_for(L)`:

| Last turn's `end_reason` | `unknown_reason` |
|---|---|
| `stop_hook` with `aborted_call_ids` non-empty | `turn_aborted_calls` |
| `session_cleared` | `turn_killed_by_clear` |
| `session_ended` | `turn_ended_with_session` |
| `api_error`, with `stalled_cleared_by = "left_live"` ([§ 4.5](#45-link-states)) | `stalled_left_live` |
| `api_error`, with **any other** `stalled_cleared_by` — `session_end`, `turn_start`, or none recorded | `stalled_session_ended` |
| `server_session_close` — the server closed the turn because its session closed ([§ 4.6.1](#461-the-turn-has-no-timer-of-its-own)) or the seat went offline ([§ 4.6](#46-every-open-fact-has-a-ceiling)) | `session_closed_turn_open` |
| *(no turn record at all — rule 5, not this function)* | `no_data_yet` |

Every row is a **function of `L` alone**, and that is load-bearing rather than tidy: the reason column
is `unknown_reason_for(L)`, so a reason that needed a sixth fact would be a member no input can select.

Two members were minted by earlier drafts and are gone for exactly that reason. `derivation_error` was
one: a fold error is not one of the five facts, and overwriting a seat's derived state with `unknown`
because one event failed to project would *destroy* the reading its other facts still support — so the
poison-event path raises the `derivation_error` **badge** ([§ 6.5](#65-the-fold)) and leaves the state
alone, which is the same "label, never collapse" discipline the rest of this document applies. The
other was `session_closed_turn_open` **as a reason with no producer**: rule 5 fires first whenever `L`
is null, so the row can only be reached if the server-side turn close actually *writes* a turn record —
which is why `sessions.last_turn_end_reason` carries the server-side member `server_session_close`
([§ 6.4](#64-ddl)) and [§ 4.6.1](#461-the-turn-has-no-timer-of-its-own) sets it.

**And the converse property is bought explicitly, because it is the one an earlier draft lost.** The
table must be total over `L`'s *declared* domain — the five `last_turn_end_reason` members crossed with
`stalled_cleared_by`'s three members and null — not merely over the combinations today's paths happen to
reach, because an input no row selects is exactly as broken as a row no input reaches and is harder to
see. Three of the five end reasons select a row regardless of `stalled_cleared_by`; `stop_hook` splits
on the aborted count and its clean half never arrives here at all (rule 4 fires first); and
`api_error`'s two rows are written as *`left_live`* and *anything else*, a catch-all rather than an
enumeration, so a fourth `stalled_cleared_by` member added later cannot silently fall through. The
combination that found this — `api_error` with `stalled_cleared_by` null, reached when a session is
closed under a rate-limited turn by something that recorded no clearer — is now covered twice over:
[§ 4.5](#45-link-states)'s leaving-live clear fires at `stale` **or** `offline` and so records
`left_live` on every seat that goes quiet, before [§ 4.6](#46-every-open-fact-has-a-ceiling)'s
quiescence can close the session under it, **and** the catch-all row would have caught the null anyway.
The catch-all is what makes the second cover real rather than a restatement of the first: it is the row
that holds if the ordering argument is ever wrong.

`unknown` is a single state with seven reasons rather than seven states, because the *rendering* is one
glyph ("we do not know what this seat is doing") and the *diagnosis* belongs in the drill-down. Seven
top-level states would put six of them in D3's render switch to no benefit.

### 4.4 Activity states: every entry and exit edge

Because the state is derived, an "edge" is exactly *an event or sweep rule that changes one of the five
facts*. This table is therefore complete by construction: every writer of every fact is listed.

#### `working`

| Direction | Trigger | Fact changed |
|---|---|---|
| **enter** | `tool.start` | opens a call (`C+1`) |
| **enter** | `turn.start` | `T := true`; also clears a `stalled` session ([D1 § 6.4](EVENT-SCHEMA.md#64-turnend)) |
| **enter** | `subagent.spawn` | none of its own — it shares the dispatch call's `call_id` ([D1 § 6.7](EVENT-SCHEMA.md#67-subagentspawn)); it fills the intern label |
| **exit → `idle`** | `turn.end` with `end_reason == "stop_hook"` and `aborted_call_ids == []`, once `C == 0` | `T := false`, `L := clean` |
| **exit → `unknown`** | `turn.end` with any other `end_reason` (other than `api_error`) | `T := false`, `L := dirty` |
| **exit → `stalled`** | `turn.end` with `end_reason == "api_error"` | `S := set`, `T := false` |
| **exit → `blocked`** | `attention.request` | `A := set` |
| **exit (fact only)** | `tool.end` (any outcome), server orphan close, session close, offline quiescence | `C−1` |

#### `idle`

| Direction | Trigger |
|---|---|
| **enter** | the only entry is rule 4 of [§ 4.3](#43-the-derivation-function): a `turn.end(stop_hook, [])` with no open calls and no open turn |
| **exit** | `turn.start` (→ `working`), `tool.start` (→ `working`), `attention.request` (→ `blocked`) |
| **exit** | a later turn on any of the seat's sessions ending dirty — `L` is seat-scoped and the newest record wins ([§ 4.3](#43-the-derivation-function)) |
| **not an exit** | `session.end`, including the flusher's 90-minute `inferred_silence` close. It clears `T`, `C` and `S` and changes no fact rule 4 reads, so a cleanly-finished seat stays `idle` ([§ 4.3](#43-the-derivation-function), [§ 4.8](#48-what-may-never-mint-a-state) row 5) |
| **not an exit** | a `link_state` change out of `live`. The activity state is **masked, not cleared** — [§ 4.2](#42-render-precedence) renders the transport state and `activity_state` still rides the object ([AT-D2-3](#at-d2-3-stale-offline-and-disabled-are-rendered-never-idle)) |

An idle seat that goes quiet **stays `idle` while it keeps heartbeating** and becomes `stale` when the
heartbeat stops. That is the honest reading: idle is a positive observation (the agent said it
finished), and its expiry is a transport fact, not an activity fact. It is also why leaving `live`
masks `idle` rather than clearing it, while it *clears* `blocked` and `stalled` — those two are claims
that the seat is **currently** waiting or currently refused, and D1 names leaving-live as a clear for
both ([D1 § 6.4](EVENT-SCHEMA.md#64-turnend), `D2-MUST` #5); `idle` is a claim about something that
already happened, which staleness does not falsify.

#### `blocked` (`D2-MUST` #5)

| Direction | Trigger | Note |
|---|---|---|
| **enter** | `attention.request` | the **only** entry, per `D2-MUST` #5. Never from `notification_kind` inspection — every member of that three-member field is a wait on a human because D1 gates the hook before emission ([D1 § 6.12](EVENT-SCHEMA.md#612-attentionrequest)); there is no `other` member and **D2 builds no branch for one** |
| **exit** | `attention.resolved` joined on `request_id` | the ordinary exit; records `resolution`, `resolution_source`, `waited_ms` |
| **exit** | that session's `session.end`, or any reap of it | D1 emits `attention.resolved(session_ended)` **after** the boundary event ([D1 § 8.3](EVENT-SCHEMA.md#83-the-reap-rules)); the server also closes the request when the session closes, so a lost resolution cannot strand the state |
| **exit** | `link_state` reaches **`stale` or `offline`** — the sweeper **resolves** the request at that boundary with `resolution: seat_left_live` / `resolution_source: server_left_live`, counting `left_live_resolved_attention` ([§ 4.5](#45-link-states)). Stated as the two values rather than as "leaves `live`", which is wider than the rule: a seat heartbeating with `enabled: false` takes `disabled` at [§ 4.5](#45-link-states) rule 4 without crossing either boundary, and nothing clears — correctly, because it is reporting and can still answer | permitted explicitly by `D2-MUST` #5, and discharged by clearing the fact rather than by masking it: a seat returning at 400 s must not re-render a wait whose evidence is five minutes stale |
| **exit** | **server ceiling at 60 min** from the request's `event_time` | [§ 4.7](#47-which-clock-each-ceiling-is-measured-from) |
| **not an exit** | a second `attention.request` while one is open | at most one is open per session ([D1 § 6.12](EVENT-SCHEMA.md#612-attentionrequest)); a second is stored as a duplicate and counted `attention_request_duplicate_server`, never opening a second *blocked* |

**The 60-minute server ceiling, and why it is not 65.** D1's reporter resolves an unresolved request at
60 minutes and emits `attention.resolved(timeout)`. If that event is lost, the server must still clear —
`D2-MUST` #5 says a seat "may never render *blocked* for longer than the 60-minute ceiling without a
matching `attention.resolved`". So the server clears at exactly 60 minutes measured from the request's
own `event_time` (the same basis the reporter uses, so the two cannot disagree by construction),
recording `resolution: "server_ceiling"`, `resolution_source: "server_ceiling"`, counting
`attention_ceiling_expired`, and writing a transition row with `cause: attention_ceiling` — the cause
value exists so the drill-down can say *the server cleared this*, which is exactly the distinction a
`staleness_sweep` or a `wire_event` cause would lose. An `attention.resolved` that arrives afterwards **overrides the label**
(the resolution and `waited_ms` become the reporter's) and **never re-opens `blocked`** — an observation
overrides an inference, which is D1's own rule for late completions
([D1 § 12.5](EVENT-SCHEMA.md#125-late-completions-and-orphan-timeouts)), applied to the state D1 hands
this document. A rising `attention_ceiling_expired` means resolutions are being lost, and that is the
instrument that says so.

#### `stalled` (`D2-MUST` #1's carve-out)

| Direction | Trigger |
|---|---|
| **enter** | `turn.end` with `end_reason == "api_error"`; `api_error_type` is stored ([§ 6.4](#64-ddl)) and **rides the seat object** ([§ 8.2.1](#821-the-seat-state-object)) |
| **exit** | that session's next `turn.start` — `stalled_cleared_by := turn_start` |
| **exit** | that session's `session.end` — **including** the flusher's 90-minute `inferred_silence` close ([D1 § 6.2](EVENT-SCHEMA.md#62-sessionend)) — `stalled_cleared_by := session_end`, after which the seat is `unknown` (`stalled_session_ended`), **never `idle`** |
| **exit** | `link_state` reaches **`stale` or `offline`** — the sweeper clears `stalled_since` at that boundary, `stalled_cleared_by := left_live`, counting `left_live_cleared_stalls`; the seat is then `unknown` (`stalled_left_live`) ([§ 4.5](#45-link-states)). The two values, not "leaves `live`": a `disabled` seat is still reporting and its rate limit is still real |

**Three exits, and offline quiescence is deliberately not a fourth.** All three are D1's
([D1 § 6.4](EVENT-SCHEMA.md#64-turnend) states them). An earlier draft added a fourth,
`stalled_cleared_by: server_offline`, written by [§ 4.6](#46-every-open-fact-has-a-ceiling)'s offline
quiescence as a backstop under the other three — and it was a member no path could select, because
the third exit fires at **`stale` or `offline`** and so has *by construction* already cleared the flag
before any seat can reach quiescence ([§ 4.6](#46-every-open-fact-has-a-ceiling) states that precedence
where quiescence is defined). A backstop under a rule that cannot be got past is not a backstop; it is
a second write-site for one fact, and two write-sites recording *different* clearers for one physical
event is how a drill-down comes to disagree with itself. `stalled` is per **session**, not per seat: a seat running two
terminals can have one rate-limited session and one healthy one, and the derivation's precedence takes
`stalled` if any session of the seat is stalled — because a rate-limited fleet is a thing an operator
acts on and hiding it behind a second healthy session would be the same collapse D1 refuses when it
declines to fold `api_error` into `unknown`.

#### `unknown`

Entered by rules 5 and 6 of the derivation and left as soon as any fact changes. `unknown` is never
sticky, has no timer of its own, and needs none: it is the *absence* of a positive claim, so it cannot
be a trapdoor.

### 4.5 Link states

`link_state` is an **ordered cascade**, in the shape of [§ 4.3](#43-the-derivation-function)'s
function and for the same reason: the column is `NOT NULL`, so every seat must have exactly one value
on every input, and a table of five predicates that reference each other has no derivable value at all
on the fixture [AT-D2-20](#at-d2-20-catching-up-is-not-current-and-not-stale) builds. Read top-down;
the first match wins; the last rule is unconditional, so the function is total.

```
link_state(seat) ->

  1. if last_receipt_at IS NULL                 -> "offline"      # provisioned, never reported
  2. if server_now − last_receipt_at > 900 s    -> "offline"
  3. if server_now − last_receipt_at > 300 s    -> "stale"
  4. if enabled == false                        -> "disabled"
  5. if oldest_unsent_age_s > 300               -> "catching_up"
  6. otherwise                                  -> "live"
```

| Rule | Threshold and its source |
|---|---|
| 1 | this document's: a seat row exists from token-issue time ([§ 6.4](#64-ddl)), and a provisioned-but-silent seat must render as gone rather than as live-with-no-data |
| 2 | `offline`, 900 s — D1's number, cited not chosen |
| 3 | `stale`, 300 s — D1's number, cited not chosen |
| 4 | `disabled` — [D1 § 6.14](EVENT-SCHEMA.md#614-reporterheartbeat) |
| 5 | `catching_up` — [D1 § 9.1](EVENT-SCHEMA.md#91-the-cadence-and-the-alarm) states this obligation on D2 in terms, with the number |
| 6 | `live` = receipt within 300 s, enabled, and not draining — the old table's `live` predicate, now derived rather than self-referential |

**Two orderings inside the cascade are decisions.** *Silence above the flag* (2 and 3 above 4): the
`enabled` flag is only ever learned from a heartbeat, so a seat that has stopped heartbeating is telling
us nothing current about whether it is off — reporting the last flag we saw as though it were live
information is the stale-stamp defect of [§ 3](#3-delivery-is-not-activity) in another costume. *Off
above draining* (4 above 5): a disabled seat's spool backlog is a fact about a seat that is not working,
and "off" is the more actionable of the two readings.

**Leaving `live` clears the two current-claim facts, and that is a sweeper rule stated once here.** When
a seat's `link_state` first becomes `stale` **or `offline`** — both, not only rule 3, because a seat
silent for more than 900 s between two sweep passes takes `offline` directly and never has a pass in
which rule 3 matched — the sweeper clears `sessions.stalled_since` **for every session of that seat
whose `stalled_since IS NOT NULL` and whose `stalled_cleared_by IS NULL`**, recording
`stalled_cleared_by: left_live` and counting `left_live_cleared_stalls`; and resolves every open
attention request with `resolution: seat_left_live`, `resolution_source: server_left_live`, counting
`left_live_resolved_attention`.

**The two conditions on that write are not defensive padding; each excludes a real write.** Without the
first, the sweeper would stamp `stalled_cleared_by` onto sessions that were never stalled. Without the
second, it would **overwrite** a value already recorded — a session cleared by `turn_start` or
`session_end` earlier that day — and `stalled_cleared_by` is an input to
[§ 4.3](#43-the-derivation-function)'s reason table, so the overwrite would silently change the reason
a later derivation reports for a turn that ended long before the seat went quiet. A clear is a
one-shot record of *who cleared it*; a rule that can run twice must say which write wins, and here the
first one does. Both writes record a
transition `cause` of `staleness_sweep`, which is the same cause the `stale` and `offline` renders
themselves carry: one rule, one cause value. **And this rule is the only write-site either fact has on
the quiescence edge** — [§ 4.4](#44-activity-states-every-entry-and-exit-edge)'s exit tables name the
others, and every one of them belongs to a seat that is still reporting: `turn_start` and `session_end`
for `stalled_cleared_by`, and `attention.resolved`, the session close and the 60-minute server ceiling
for an open request. What no path reaches is a **second** write on the way out of `live`:
because this rule's trigger is `stale` *or* `offline`, a seat cannot reach
[§ 4.6](#46-every-open-fact-has-a-ceiling)'s offline quiescence without having passed through it first,
so quiescence neither re-clears `stalled_since` nor re-resolves an attention request
([§ 4.6](#46-every-open-fact-has-a-ceiling) states that precedence and the members it deletes). Both are D1 clauses —
`D2-MUST` #1's *"or the seat leaving live state"* and `D2-MUST` #5's *"or leaving live"* — and **neither
is discharged by the render precedence alone**: masking would leave the fact standing, and a seat that
returns at 400 s would re-render a claim whose evidence is five minutes old. `idle` is deliberately not
in this rule ([§ 4.4](#44-activity-states-every-entry-and-exit-edge)).

`stale` and `offline` both carry **`delivery.no_data_since` = `last_receipt_at`**, so the rendered
string is "no data since 14:18" rather than a glyph that means nothing on its own. A seat is **never
removed** from the fleet because it went quiet: rows disappear only on an explicit operator retirement
([§ 6.4](#64-ddl), `seats.retired_at`), which is an act with an author and a reason, and even then the
seat is rendered as `retired` for the remainder of the retention window rather than vanishing between
two refreshes — [§ 4.10](#410-retirement-is-a-rendered-state) states that state, its fields and its
window.

**`last_receipt_at` is the receipt time of the newest event of any kind, heartbeats included** — that is
correct and is the one place a heartbeat is load-bearing, because the heartbeat's entire purpose is to
assert that the pipe is alive when the agent is quiet ([D1 § 2.3](EVENT-SCHEMA.md#23-the-flusher-must-be-alive-whenever-the-seat-is)).
It drives transport states only, per [§ 3](#3-delivery-is-not-activity) rule 3.

### 4.6 Every open fact has a ceiling

The property this table asserts is that **no fact in this model can stay open forever**, which is what
makes the derived state incapable of a one-way trapdoor — the defect D1 names for *blocked* and
*stalled* and gives both an acceptance test for.

| Open fact | Its own ceiling | Where the ceiling comes from | Backstop |
|---|---|---|---|
| open call, ordinary tool | **15 min** after its `tool.start` receipt | [D1 § 12.5](EVENT-SCHEMA.md#125-late-completions-and-orphan-timeouts) | offline quiescence |
| open call, dispatch (`Agent`/`Task`) | **60 min** | [D1 § 12.5](EVENT-SCHEMA.md#125-late-completions-and-orphan-timeouts) | offline quiescence |
| open turn | closed when its session closes | this document's rule, [§ 4.6.1](#461-the-turn-has-no-timer-of-its-own) | offline quiescence |
| open session | `session.end`, incl. the flusher's 90-minute `inferred_silence` | [D1 § 6.2](EVENT-SCHEMA.md#62-sessionend) | offline quiescence |
| open attention request | **60 min** after its `attention.request` `event_time`, or the seat reaching `stale` (300 s) or `offline` (900 s), whichever is first | `D2-MUST` #5; [§ 4.5](#45-link-states) | **none needed** — the leaving-live edge in its own ceiling *is* the backstop, and it fires strictly before quiescence ([§ 4.5](#45-link-states)) |
| `stalled` flag | next `turn.start` / that session's `session.end` / the seat reaching `stale` (300 s) or `offline` (900 s) | [D1 § 6.4](EVENT-SCHEMA.md#64-turnend); [§ 4.5](#45-link-states) | **none needed** — same reason |
| open compaction (`sessions.compaction_open_since`) | `compaction.end`, its session closing, or **15 min** after the `compaction.start` receipt — the ordinary orphan ceiling reused, because a compaction is a harness operation of the same order as a tool call and `PostCompact` is one of D1's un-driven hook stubs | this document's rule | offline quiescence |
| **everything above** | — | — | **offline quiescence at 900 s** — except the two rows whose own ceiling already carries the leaving-live edge, which quiescence can never get in front of |

**Offline quiescence** (transition `cause: offline_quiesce`). When a seat crosses the `offline`
threshold, the sweeper closes its open facts:
every open call becomes `aborted` / `seat_offline` / `close_source: server_offline`, counting
`offline_quiesced_calls`; an open turn is recorded as ended without a `turn.end`
(`turn_close_source: server_offline`, `last_turn_end_reason: server_session_close`, so the derivation
lands on `unknown` / `session_closed_turn_open` rather than on a null `L`); an open compaction is closed
(`compaction_open_since := NULL`, counting `compaction_ceiling_closed`); and every open session is
marked `ended_at` with `closed_by: server_offline`, counting `offline_quiesced_sessions`. Nothing is
synthesized onto the wire
([§ 4.8](#48-what-may-never-mint-a-state)); these are ledger writes only.

**Quiescence never touches the `stalled` flag or an open attention request, and that is a precedence
statement rather than an omission.** Reaching `offline` means the seat's `link_state` has *first become*
`stale` **or** `offline`, which is exactly [§ 4.5](#45-link-states)'s leaving-live trigger — so by the
time quiescence can see the seat, the leaving-live clear has already run and recorded
`stalled_cleared_by: left_live` and `resolution: seat_left_live` / `resolution_source: server_left_live`.
On the ordinary path it ran ~40 sweep passes earlier, at 300 s; on the one-pass jump — a seat silent for
more than 900 s between two passes, which takes `offline` directly — it runs in **this** pass, ahead of
quiescence, which is why [§ 2.1](#21-processes)'s job list is an execution order and states the
leaving-live clears before offline quiescence. Either way quiescence finds `stalled_since` null and no
open request, so a `server_offline` clearer and a `seat_offline` resolution are values no path can
select; they were declared once and are deleted rather than kept as unreachable
[§ 6.4](#64-ddl) members. **One quiet seat, one write-site, on the earlier edge** — the wire's own
exits keep theirs ([§ 4.4](#44-activity-states-every-entry-and-exit-edge)); what is refused is a second
*sweeper* write for the one physical event of a seat going quiet, the alternative being two
sweeper jobs racing to record different clearers for it, which
[§ 4.3](#43-the-derivation-function)'s reason table would then read as two different diagnoses.

The clear itself is still load-bearing, and [§ 4.5](#45-link-states) is where it earns its keep: the `S`
fact of [§ 4.3](#43-the-derivation-function) is *(`stalled_since` set **and** `ended_at` null)*, so a
quiescence that merely marked the session ended would make `S` false through its second term while
leaving `stalled_cleared_by` null, and `unknown_reason_for(L)` would read that column and reach rule 6
with an input carrying no record of *who* cleared the stall. The leaving-live clear is what stops that
happening, and [AT-D2-6](#at-d2-6-stalled-is-a-state-with-three-exits)'s second RED is the test that
narrowing it back to the `stale` edge alone re-opens it.

Why quiesce at all, when the render already shows `offline`? Because a seat that comes back must not
inherit an hour-old open call as *current work*, and because the facts feed counters and the drill-down.
When the seat returns, its events re-open exactly what is still real: `tool.end` for a call the server
already closed is a late close and takes D1's override path
([D1 § 12.5](EVENT-SCHEMA.md#125-late-completions-and-orphan-timeouts)), and an event for a closed
session re-opens it and counts `session_reopened` ([D1 § 12.7](EVENT-SCHEMA.md#127-server-side-counters)).
The projections are idempotent upserts precisely so this path is ordinary rather than special.

#### 4.6.1 The turn has no timer of its own

An open turn is bounded by its session, not by a clock this document invents. The reasoning, and the
gap it exposes:

- A turn ends on `Stop`/`StopFailure`, or on a session boundary with a turn open
  ([D1 § 6.4](EVENT-SCHEMA.md#64-turnend)).
- If the harness produces neither, the session goes silent, and the flusher closes it at 90 minutes with
  `session.end(inferred_silence)`.
- **But D1's kind table lists `turn.end` as hook-emitted only**, and the flusher's `inferred_silence`
  close is not a hook. So it is not stated whether a `turn.end` accompanies it. This document therefore
  closes the turn **server-side** when its session closes by any means, recording
  `turn_close_source: "session_close"` **and a turn record of
  `last_turn_end_reason: "server_session_close"` with `last_turn_aborted_count` set to the number of
  calls still open at the close** (each of those calls the server closes itself —
  `abort_reason: session_close`, `close_source: server_session_close` — counting
  `session_close_orphans`, [§ 7.2](#72-this-planes-own-counters-and-badges)) — without which `L` would
  stay null, rule 5 would fire and `session_closed_turn_open` would be a member no path can
  select. It therefore derives
  `unknown` / `session_closed_turn_open` — never `idle`, because no `turn.end(stop_hook, [])` was ever
  observed. Filed as a D1 amendment need in [§ 14](#14-open-questions-for-the-review-loop), item 1.
- A dead flusher emits nothing at all, and that seat is `stale` at 300 s — long before the turn's
  openness could mislead anyone.

### 4.7 Which clock each ceiling is measured from

| Ceiling | Measured from | Why |
|---|---|---|
| orphan close, 15 / 60 min | **`received_at`** of the `tool.start` (server clock) | A timeout is a statement about how long *we* have waited. Measuring it on the seat's clock makes a +10-minute skewed seat's calls expire on arrival and a −10-minute one's expire ten minutes late. [D1 § 8.6](EVENT-SCHEMA.md#86-server-side-interpretation-of-open-call-state) says to record `started_at = event_time` and does not say which clock the timeout runs on; this document uses receipt for the timer and keeps `event_time` for the narrative. Filed as a D1 amendment need, [§ 14](#14-open-questions-for-the-review-loop) item 2. |
| attention ceiling, 60 min | **`event_time`** of the `attention.request` (seat clock) | Here the *reporter* owns the competing timer and fires at 60 min on its own clock. Using the same basis makes the two fire together; using receipt would make the server clear first on every skewed seat and mint a `server_ceiling` resolution for a request the reporter was about to resolve properly. |
| `stale` / `offline` / `catching_up` | **server clock vs `received_at`** | [D1 § 10.1](EVENT-SCHEMA.md#101-two-clocks-and-which-is-authoritative-for-what) rule: `received_at` is authoritative for liveness. |
| offline quiescence, 900 s | server clock vs `received_at` | it *is* the offline threshold |
| durations rendered in the drill-down | the event's own `duration_ms`, else `event_time` arithmetic, with `duration_source` | [D1 § 6.6](EVENT-SCHEMA.md#66-toolend) already ranks these; D2 stores the field and never recomputes it |

**The materialized due-time.** Each ceiling is written onto the row when the fact opens
(`calls.orphan_due_at`, `attention_requests.ceiling_at`), so the sweeper is one indexed range scan and
so that **changing a constant later does not retroactively rewrite history** — a call opened under a
15-minute rule keeps its 15-minute deadline even if the constant moves, which is what makes the
`late_completion` counter interpretable across a change.

### 4.8 What may never mint a state

`D2-MUST` #1 exists because a `/clear` SIGKILLs an in-flight subagent tool call (measured upstream,
26/26 — [D1 § 8.1](EVENT-SCHEMA.md#81-the-problem-restated)) and the killed call produces no completion
signal. D1 makes that discriminable on the wire; this section is D2 not throwing it away.

| Pattern | What it must **not** produce | What it produces here |
|---|---|---|
| `turn.end` with `end_reason ∈ {session_cleared, session_ended}` | `idle` | `unknown` (`turn_killed_by_clear` / `turn_ended_with_session`) |
| `turn.end` with `aborted_call_ids` non-empty, whatever the `end_reason` | `idle` | `unknown` (`turn_aborted_calls`) |
| `tool.end` with `outcome: "aborted"` (any `abort_reason`, including `interrupted`) | a completion, or any input to the idle rule | a closed call with `outcome: aborted`; the turn's own `aborted_call_ids` is what the idle rule reads |
| a burst of reap-produced `tool.end`s followed by `turn.end` then `session.end` | an idle transition between them | one derivation pass per applied event; `working → unknown`, with **no intermediate `idle`** — because rule 4 requires the *last turn's* `end_reason` to be `stop_hook` and it never is on this path |
| `session.end` **over a dirty, `api_error` or absent turn record** | `idle`, or a row removal | the session's facts close (`T`, `C`, `S`); the seat derives `unknown` with the reason `L` selects. A `session.end` over a **clean** record is not in this row: it changes no fact rule 4 reads, so the seat stays `idle` ([§ 4.3](#43-the-derivation-function)) |
| a `session.end` with `end_reason: "other"` | a badge, a degradation, or any inference about the seat's health | nothing at all beyond closing the session. `other` is **a common value, not a residue** — a non-interactive `claude -p` session ends this way and it was the majority of D1's capture run ([D1 § 6.2](EVENT-SCHEMA.md#62-sessionend)) — so it is stored in `sessions.end_reason` and read by no rule, no badge and no predicate in this document |
| a `tool.end` whose `match` is `synthesized` — a close with no open ([D1 § 6.6](EVENT-SCHEMA.md#66-toolend)) | a negative open count, or a dropped close | a call row **created already closed** with `synthesized = 1`; the flag is stored and rendered in the drill-down, so the anomaly is a visible flag rather than an absorbed one, and the ledger stays total |
| the **absence** of events | `idle` | `stale` → `offline`, transport states, per [§ 4.5](#45-link-states) |
| a `reporter.heartbeat` | any activity state at all | transport freshness only ([§ 3.2](#32-the-activity-event-set)) |
| a `compaction.start` / `compaction.end` | an activity **state** — neither `working` nor a state of its own | `compaction.start` refreshes `last_activity_*` (it **is** activity, [§ 3.2](#32-the-activity-event-set)) and sets `sessions.compaction_open_since`; `compaction.end` clears it. [§ 4.3](#43-the-derivation-function) reads no compaction fact, so a seat compacting between turns derives from `L` — `idle` after a clean turn. **That is the decision, and it is deliberate**: a compaction is the harness reclaiming context, not the agent doing work, and rendering `working` for it would put a busy desk on the floor for a seat whose agent is idle. The fact is still bounded ([§ 4.6](#46-every-open-fact-has-a-ceiling)) and still visible in the drill-down, which is where "why is this seat quiet for 40 s" is answered |
| a server-side orphan close, a ceiling expiry, or offline quiescence | a **wire event** | ledger writes only. [D1 § 8.6](EVENT-SCHEMA.md#86-server-side-interpretation-of-open-call-state): "no wire event is synthesized, because the wire is what a seat said and the server must not put words in a seat's mouth". `events` therefore contains only what seats sent, which is what makes [§ 6.6](#66-rebuild-from-the-log)'s replay meaningful. |
| a `tool.end` whose `match` is `lifo_tool_name` | a different open/closed **count** | the mis-match can swap two concurrent same-tool calls' ids and durations and nothing else ([D1 § 8.2](EVENT-SCHEMA.md#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)); `match` is stored and rendered in the drill-down so an approximate attribution is legible as one |
| a `tool.start` carrying `agent_scope` or `parent_call_id` | a scope-dependent state rule | those are **labels** ([D1 § 6.5](EVENT-SCHEMA.md#65-toolstart)); the server ledger is seat-scoped and models no agent scope ([D1 § 8.6](EVENT-SCHEMA.md#86-server-side-interpretation-of-open-call-state)). They are stored for the intern join and never gate anything. |

The golden fixture for this whole section is D1's own worked `/clear` trace, replayed end to end in
[§ 10](#10-worked-example-the-clear-trace-folded-end-to-end) and asserted by
[AT-D2-2](#at-d2-2-the-clear-trace-mints-no-idle).

### 4.9 The task-title merge, and what is not specified here

[`docs/PLAN.md § 2`](../PLAN.md#2-design-first-gates--the-order-is-the-plan) assigns D2 the merge of
three sources: telemetry supplies the live *action*, GitHub and board events supply the human-readable
*task title*, and the proposal contributes a three-tier status fallback.

**What is specified here** — the columns and the precedence, because those are state-model questions:

| Tier | Source | Field | Freshness bound | Precedence |
|---|---|---|---|---|
| 1 | board card assigned to this seat | `task.title`, `task.ref = "card#NNNN"` | re-read at the board poll cadence; stale past **30 min** | highest |
| 2 | the seat's most recent coordination/PR activity correlated to it | `task.title`, `task.ref = "<repo>#N"` | stale past **30 min** | middle |
| 3 | the seat's own telemetry — the newest open dispatch call's `title`, else the current call's `descriptor` | `task.title`, `task.ref = null` | live | lowest |

- `task.source` is always on the wire (`board_card` · `coord_thread` · `telemetry` · `null`), so a
  consumer never has to guess which tier answered — and a floor showing tier 3 everywhere is visibly a
  floor whose board integration is dark, rather than a floor that looks fine.
- A tier's value past its freshness bound is **dropped, not rendered stale**: the merge falls through to
  the next tier and `task.degraded` is set. A stale card title on a desk that moved on an hour ago is
  the same class of lie this document is otherwise built to prevent.
- Tier 3 is always available while the seat is live, so the merge never yields "no title" on a working
  seat.

**What is deliberately not specified here, and why.** The *producers* of tiers 1 and 2 — a GitHub
webhook receiver and a kanban poller — are designed in no document in this repo. And the proposal's
"three-tier status fallback" is a document this repo does not contain: it is named in
`docs/PLAN.md § 2` and nowhere reproduced. **This document does not invent its tiers.** Specifying a
fallback from the phrase alone would put a guessed rule in a contract, and a guessed rule that reads
plausibly is worse than an absent one. So: the merge above is derived from what this repo states, and
[§ 14](#14-open-questions-for-the-review-loop) item 3 asks review for the proposal's actual tiers and
for a decision on where the two producers are designed. Until that answers, an implementer builds tier 3
(which needs nothing new) and leaves tiers 1 and 2 as the stated columns they populate.

### 4.10 Retirement is a rendered state

A seat leaves the floor by one act and one act only: an operator runs
**`mezzanine:retire`** ([§ 2.1](#21-processes)), which sets `seats.retired_at`, `retired_by` and
`retired_reason` ([§ 6.4](#64-ddl)). Nothing else — no timeout, no purge, no silence — ever removes
a row from the fleet.

**The command is named because three of the four things retirement is supposed to produce had no
producer.** `render_state` is a stored column with two writers: the fold, which claims only seats with
unfolded events ([§ 6.5](#65-the-fold)) and so never visits a retired seat that has stopped reporting,
and the sweeper, which recomputes every seat every pass ([§ 2.1](#21-processes)) and therefore *would*
reach `retired` — but up to a sweep pass late, and writing a transition row whose `cause` says
`staleness_sweep` for a change an operator made. The `cause: operator` member of that ENUM had no
writer at all, and **nothing in this document emitted `seat.retired`**: [§ 8.3](#83-the-websocket-delta-feed)'s
table said only *when* the message is sent and named no process to send it, which is a wire message a
consumer is told to expect and no path produces.

So `mezzanine:retire` does the whole act in **one transaction** — the three columns, the recomputed
`render_state`, the `cause: operator` transition row, the `state_version` bump, and the publish of both
`seat.retired` and the delta — and it is the only producer of the last three. The sweeper's own
recompute then agrees with it on every later pass rather than racing it, because
[§ 4.2](#42-render-precedence) makes `retired` a function of `retired_at`, which by then is set.

| Question | Answer |
|---|---|
| Does a retired seat appear in the snapshot? | **Yes**, for **14 days** after `retired_at`, with `render_state: "retired"` and a `retired` object carrying `at`, `by` and `reason`. After 14 days the read queries stop selecting it |
| Is it purged? | **No.** `seats` is retained forever ([§ 6.7](#67-retention-and-purge)); the 14 days is a **read filter**, not a deletion, so an operator query can still find the row and its reason |
| Why 14 days? | the retention window, one home: a retired seat stays visible for exactly as long as the events that explain what it was doing |
| What do connected clients see at the moment of retirement? | the `seat.retired` feed message and the delta carrying `render_state: "retired"`, **both published by `mezzanine:retire` in the transaction that sets the columns** ([§ 2.1](#21-processes), [§ 8.3](#83-the-websocket-delta-feed)) — so the re-render is immediate rather than up to one sweep pass late, it carries `cause: operator` rather than `staleness_sweep`, and `seat.retired` reaches the wire at all, which nothing else in this document would have done. A row never vanishes between two refreshes |
| Does `link_state` or `activity_state` change? | Retirement itself changes neither — it is an administrative fact, not a transport or activity one. But **the axes keep deriving**: the sweeper recomputes `link_state` for every seat on every pass ([§ 2.1](#21-processes), [§ 4.5](#45-link-states)), so a retired seat that stops reporting still reaches `stale` at 300 s and `offline` at 900 s underneath. What does not change is the **render**: `retired` short-circuits above both axes in [§ 4.2](#42-render-precedence), so the desk keeps saying an operator retired it while the drill-down keeps saying what the seat was doing and what its transport did afterwards |

`retired` sits at the top of [§ 4.2](#42-render-precedence)'s collapse because it is the one state that
is true regardless of what the seat is still doing: a retired seat that keeps reporting is a
misconfiguration, and rendering it as `working` would hide that. [AT-D2-23](#at-d2-23-a-retired-seat-is-rendered-not-disappeared)
is the test, and its RED is the disappearance.

---

## 5. Server-side predicates and their controls

A peer install ran **30 days dark** because a predicate stopped discriminating and nothing noticed:
`CLAUDE_CODE_CHILD_SESSION` began being set on top-level seats, a seat-detection guard pinned to
"always suppress", two consumers went silent, and "wrong" and "working" looked identical from outside
for a month ([D1 § 3.4](EVENT-SCHEMA.md#34-why-identity-never-comes-from-the-environment), which
records it as
*"Measured in this fleet, 2026-08-23"* and is the only in-repo source for it). D1 answers it on the
reporter. This section answers it on the server, because the
same failure is available here: a staleness predicate that can only say `live`, an idle predicate that
can only say `no`.

**Three binding rules for this plane:**

1. **No predicate gates on an undocumented environment marker** — not the harness's, and not the
   server's own. Every input to every predicate in this document is either a stored column or a
   constant declared in [§ 12](#12-every-number-and-where-it-comes-from). A predicate that would need
   `getenv()` is a defect.
2. **Every predicate reports both branch counts** into `seat_predicates` ([§ 6.4](#64-ddl)), on every
   evaluation, and the sweeper alarms when one branch goes constant against its stated criterion.
3. **Every predicate names the control that proves it can produce both answers**, and that control is a
   test, not a paragraph ([AT-D2-13](#at-d2-13-every-predicate-can-answer-both-ways)).

| Predicate | Branches | Evaluated | Alarm criterion | The control that proves it discriminates |
|---|---|---|---|---|
| `seat_live` | `now − last_receipt_at ≤ 300 s` / `>` | per sweep pass, per seat — ~5,760/seat/day | **constant-`false` across ≥ 5,760 evaluations in a rolling 7 days**, per seat — a seat that has not been live in a seat-day of passes. Constant-**`true`** is deliberately **not** a criterion: a fleet in which no seat is ever stale for a week is the good outcome, and an alarm that fires on the healthy case is worse than no alarm, because it is the one that gets trained away. The `true`/`false` discrimination of this predicate is proved by test ([AT-D2-13](#at-d2-13-every-predicate-can-answer-both-ways)), not by production constancy | a fixture seat whose last receipt is back-dated past 300 s must flip the branch in the next pass; a fixture seat receiving normally must not |
| `activity_recent` | `now − last_activity_received_at ≤ 900 s` / `>` | per sweep pass, per seat | **constant across ≥ 5,760 evaluations in a rolling 7 days**, in **either** direction — and unlike `seat_live` above, both directions are right here. Constant-`true` means a seat has done something in the activity set every 15 minutes for a week without a single quiet quarter-hour, which no real desk does and a receipt-fed activity column does exactly; constant-`false` means a week with no activity at all on a seat that is still reporting. Neither is the healthy case, so neither alarm fires on one | the heartbeat-only fixture of [AT-D2-4](#at-d2-4-a-heartbeat-only-seat-never-looks-busy) drives `false` while `seat_live` stays `true`; a working fixture drives `true`. **If these two predicates ever move together, activity is being written from receipt** — that is the discriminating pair, and it is the mechanised form of [§ 3](#3-delivery-is-not-activity) |
| `turn_clean` | a `turn.end` had `end_reason == "stop_hook"` and `aborted_call_ids == []` / it did not | per `turn.end` — ~200–600/seat/day ([D1 § 6.0](EVENT-SCHEMA.md#60-conventions-and-how-harness-payloads-are-read)) | **0 % or 100 % across ≥ 200 evaluations in a rolling 24 h.** The 100 % end is kept deliberately, against `seat_live`'s rule, and the asymmetry has a reason: 200 consecutive clean turns is a *plausible* healthy day, so this criterion can fire on a good seat — but the thing it would be missing if it did not is the false-idle defect itself, D1's headline failure arriving through a derivation that has stopped seeing aborts. A criterion that can cry wolf on the one defect both documents exist to prevent is the trade this document takes, and it is recorded here rather than left as an inconsistency with the row above | AT-D2-2's `/clear` fixture drives `false`; AT-D2-1's ordinary turn drives `true`. Constant-`true` means the abort path is not reaching the derivation — the false-idle defect returning; constant-`false` means idle has become unreachable, which is what a wrongly-scoped reap looked like in D1's own review |
| `call_closed_by_wire` | a call closed by a `tool.end` / by a server orphan or quiescence | per call close — ~1,000–3,000/seat/day | **≥ 5 % server-closed across ≥ 1,000 in 24 h** is the alarm direction here (not constancy): server closes should be rare | drive a fixture with the reap disabled → the share jumps; the healthy fixture keeps it near zero. This is the server-side twin of D1's `late_completion` signal |
| `attention_resolved_by_wire` | resolved by an `attention.resolved` / by the server ceiling | per resolution — 0–50/seat/day | **any** server-ceiling resolution in 24 h is surfaced; constant-server over ≥ 10 alarms | stub the resolution events → ceiling branch; ordinary approval → wire branch |
| `ingest_receiving` | any batch received fleet-wide in the last 300 s / none | per sweep pass, fleet-wide | **constant-`false` for 2 consecutive passes** alarms | stop the ingest → `false` within 300 s; a single live seat → `true`. This is the predicate that separates "every seat died" from "our pipe is broken", and without it a fleet-wide ingest outage renders as 40 independently-stale desks |
| `fold_current` | `fold_lag_ms ≤ 60,000` / `>`, with `fold_lag_ms` **computed** from the cursor and head columns per [§ 2.3](#23-a-frozen-fold-is-the-dangerous-degradation), never read from a stored lag | per sweep pass, per seat | **constant-`false` for 2 consecutive passes** alarms | pause the fold daemon → `false` within one pass; resume → `true`. This control is only reachable because the sweeper and the fold are different processes and the lag's basis is a **timestamp two processes write** ([§ 2.3](#23-a-frozen-fold-is-the-dangerous-degradation): the ingest seeds `fold_cursor_received_at`, the fold advances it), not a number the fold maintains — a stored lag the fold wrote would freeze with it |

**On the thresholds.** They are chosen the way D1 chooses its own and carry the same obligation: each
criterion is reachable by its predicate's own evaluation rate — the rule D1 states as "a threshold above
a predicate's own rate is an alarm that can never fire". The per-sweep predicates run 5,760 times a
seat-day, so a 7-day window at 5,760 evaluations is roughly one seat-day of evidence; `turn_clean` runs
200–600 times a day, so its window is 24 h at 200. **All of them are provisional**: the implementer
records per-predicate evaluation counts through the first week of live running and the operator
re-picks every number from that data. What review must not change is that each predicate has a criterion
its own volume can reach, that it fires visibly, and that it has been **seen to fire**.

---

## 6. The store

### 6.1 Deployment posture

**MySQL 8.0 or later, on a host dedicated to it** (`docs/PLAN.md` D-15; the operator's decision, and the
reason [§ 2.2](#22-fail-posture-per-path) has a row for the store being unreachable at all). The version
floor is not decorative — four features below are load-bearing:

| Requirement | Value | Why it is required, and its state |
|---|---|---|
| Engine / version | **MySQL ≥ 8.0.12** | `SELECT … FOR UPDATE SKIP LOCKED` for the fold's seat claim ([§ 6.5](#65-the-fold)); `ALGORITHM=INSTANT` column adds on `events` ([§ 6.9](#69-migrations-on-a-live-events-table)); native `JSON`; `DATETIME(3)`. **DOCS-CITED** (MySQL 8.0 reference manual), **verified at provisioning** — the deploy host is not built yet |
| Storage engine | InnoDB, `ROW_FORMAT=DYNAMIC` | transactions; the fold's cursor advance and its projections are one transaction |
| Character set | `utf8mb4` / `utf8mb4_0900_ai_ci`; **all identifier columns `ascii_bin`** | descriptors are arbitrary valid UTF-8 ([D1 § 7.3](EVENT-SCHEMA.md#73-redaction-rules-applied-in-this-order) rule 13 guarantees validity); ULIDs, slugs and session ids are ASCII, and an `ascii_bin` key is 1 byte per character and compares exactly |
| Session time zone | **`SET time_zone = '+00:00'`** on every connection | every `DATETIME` in this schema is UTC. A `DATETIME` is *not* converted by MySQL, but a `TIMESTAMP` is — which is why this schema uses no `TIMESTAMP` column anywhere. [AT-D2-14](#at-d2-14-the-store-is-pinned-and-the-pin-bites) asserts the connection's resolved time zone |
| Transport | **TLS required**, certificate verified, no fallback to plaintext | the app and the store are on different machines, so the credential and every descriptor cross a network. Loosening verification "because it is our own network" is the constraint-weakening fix D1 refuses for the reporter's TLS, and it ships to production the same way. Fail **closed**: no TLS, no connection, `503` |
| Connections | request path: one per request (PHP-FPM), no persistent connections; daemons: one long-lived connection each, with reconnect-on-`gone away` and capped backoff | a persistent pool under FPM keeps `wait_timeout` sessions alive across unrelated requests and makes the time-zone and session-variable posture per-worker rather than per-request |
| Query budget | the fleet snapshot is **one query**; the fold's per-batch work is **one transaction** | every round trip is a WAN round trip. An N+1 over 50 seats is 50 round trips on the dashboard's critical path |

### 6.2 Database names, pinned and published

**The origin, cited rather than recalled.** Roundtable
[`PupFuzz/agent-roundtable#349`](https://github.com/PupFuzz/agent-roundtable/issues/349) (2026-08-23):
three tenants' test suites collided on one shared host — one seat's suite was reading and writing a
Redis database another seat's suite calls `FLUSHDB` on before every test. The measured findings from
that thread that this section acts on, each one someone else's measurement:

1. An unforced `<env>` pin in `phpunit.xml` **does** beat `.env` (Laravel's Dotenv repository is
   immutable and PHPUnit writes first). What defeats it is a variable already **exported** into the
   environment.
2. **`force="true"` alone is not enough**: PHPUnit's `force` writes `putenv()` and `$_ENV` and never
   `$_SERVER`, and Laravel's `env()` reads `$_SERVER` first. Measured with a control:
   `force="true"` under an exported `REDIS_DB=9` left `config()` resolving `9`. **The fix is the pair —
   `<env name=… force="true">` *and* a matching `<server name=…>`.**
3. `DB_URL` / `REDIS_URL` go through `ConfigurationUrlParser` and the URL's path **replaces** the pinned
   database, so a correctly pinned `DB_DATABASE` can still be ignored. Both must be pinned to the empty
   string.
4. **Do not force a variable a CI matrix exports to select a backend** — a bridge repo's MariaDB matrix
   would have silently re-run both legs on SQLite: green, testing nothing.
5. The guard must assert the **resolved** value (`config()`), not the declared one, because all three
   mechanisms above leave the declaration looking correct.

**Mezzanine's values, claimed and published here.** Mezzanine's production store is on a dedicated host,
but its **sandbox** instance (D-13) runs wherever its agent runs, and that may be a shared box — so the
pinning is adopted from birth, before it costs anything.

| Environment | Database | Redis `REDIS_DB` / `REDIS_CACHE_DB` | Set by |
|---|---|---|---|
| **production** | **`mezzanine`** | **11 / 10** | `.env` on the prod host, never in the repo |
| **sandbox** | **`mezzanine_sandbox`** | **11 / 10** (distinct host) | `.env` on the sandbox host |
| **test** | **`mezzanine_test`** | **11 / 10** | `phpunit.xml`, pinned, paired, forced |

Redis 11 and 10 are chosen against the fleet's published claims on #349 — 14/15 and 13/12
(kanban-solo), 15/14 and 2/3 (sola) — and against the defaults every unpinned seat gets (`0` and `1`).
**11, 10 and the three database names above are hereby claimed for Mezzanine**; a future seat reading
this document has them.

The `phpunit.xml` shape this obliges, stated exactly because "pin your test database" is what everyone
believed they had already done:

```xml
<!-- every isolation-critical pin is TWO entries, adjacent, and they must agree -->
<env    name="DB_DATABASE"    value="mezzanine_test" force="true"/>
<server name="DB_DATABASE"    value="mezzanine_test"/>
<env    name="DB_URL"         value="" force="true"/>
<server name="DB_URL"         value=""/>
<env    name="REDIS_DB"       value="11" force="true"/>
<server name="REDIS_DB"       value="11"/>
<env    name="REDIS_CACHE_DB" value="10" force="true"/>
<server name="REDIS_CACHE_DB" value="10"/>
<env    name="REDIS_URL"      value="" force="true"/>
<server name="REDIS_URL"      value=""/>
```

**And the pin is guarded, not trusted** ([AT-D2-14](#at-d2-14-the-store-is-pinned-and-the-pin-bites)):

- A test-suite bootstrap assertion reads **`config('database.connections.mysql.database')`** — the
  resolved value — and **aborts the run** before the first migration if it is not exactly
  `mezzanine_test`. Same for the two Redis databases. Aborting, not skipping: a suite that cannot prove
  its isolation must not run at all.
- A second test asserts the `phpunit.xml` file itself: every pinned key has both an `<env force="true">`
  and a `<server>`, and the two agree. That catches the silent-divergence mode where one line of the
  pair is edited.
- `DB_CONNECTION` is **not** forced, deliberately, and the omission is commented as load-bearing:
  nothing in this repo's CI selects a backend by exporting it today, but forcing it is exactly the shape
  that turned another repo's MariaDB matrix into a SQLite run reporting green.
- The proof is a **hostile export**, not a clean run: `REDIS_DB=9 DB_DATABASE=mezzanine php artisan test`
  must abort on the guard. Watched failing once, or the guard is decoration.

**Production migrations** additionally require `--force` (Laravel's own production confirmation) and
`APP_ENV=production` — and `migrate:fresh`, `db:wipe` and `migrate:refresh` are **removed from the
production console kernel entirely**, not merely gated. A command that can destroy the store and is
guarded by a flag is one typo from being run; a command that does not exist there cannot be.

### 6.3 Conventions

| Convention | Value | Reason |
|---|---|---|
| Surrogate keys | `installs.id SMALLINT UNSIGNED`, `seats.id INT UNSIGNED`; every hot table carries `seat_ref` | a natural key on `events` would be `install_id` (≤ 32 B) + `seat_id` (≤ 48 B) on every row and in every index — ~76 B against 4 |
| ULIDs | `CHAR(26) CHARACTER SET ascii COLLATE ascii_bin` | 26 bytes, exact comparison, legible in a query, and lexicographically ordered by mint time. `BINARY(16)` would save 10 B/row and make every diagnostic query require a conversion function; at 10,420 events/seat/day that saving is ~0.1 MB/seat/day against permanent illegibility |
| Timestamps | `DATETIME(3)`, UTC, never `TIMESTAMP` | millisecond precision matches `rfc3339_ms` on the wire; `TIMESTAMP` converts by session time zone |
| Enums | MySQL `ENUM` for closed sets D1 owns; `VARCHAR` + application validation for open ones | an `ENUM` rejects an unknown member at the storage layer, which is wrong for a value D1's rule 7 says must be coerced-and-counted. So: `ENUM` only where the coercion has already happened at the ingest ([D1 § 12.1](EVENT-SCHEMA.md#121-validation-order) step 10), which is every enum this schema stores |
| `data` | `JSON NOT NULL`, opaque | the fold projects every field the state model reads into a typed column. Nothing queries into `data` on a hot path; it is kept for the drill-down, for replay and for forensics |
| String lengths | `VARCHAR(n)` where `n` is D1's **byte** bound | MySQL counts `VARCHAR` in *characters*, so a `VARCHAR(200)` `utf8mb4` column holds any 200-**byte** descriptor with room to spare. The column is deliberately never the binding constraint — D1's cap is |
| Nullability | a column is `NULL` only where D1's field table says the wire value is nullable, or where the fact genuinely does not exist yet | a nullable column that "means zero" is a read-time fallback, which is a defect to trace to its write site |

### 6.4 DDL

Sketches, not migrations: they state every column, its type, its nullability and its indexes, which is
what an implementer needs. Names are final; a builder may reorder columns and add nothing.

```sql
CREATE TABLE installs (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  install_id    VARCHAR(32)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  display_name  VARCHAR(64)  NULL,
  created_at    DATETIME(3)  NOT NULL,
  retired_at    DATETIME(3)  NULL,
  UNIQUE KEY uq_install (install_id)
) ENGINE=InnoDB;

CREATE TABLE seats (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  install_ref   SMALLINT UNSIGNED NOT NULL,
  seat_id       VARCHAR(48)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at    DATETIME(3)  NOT NULL,
  retired_at    DATETIME(3)  NULL,          -- operator act only; never set by a timeout. The
                                            -- one writer is `mezzanine:retire` (§ 2.1, § 4.10)
  retired_by    VARCHAR(64)  NULL,
  retired_reason VARCHAR(255) NULL,
  UNIQUE KEY uq_seat (install_ref, seat_id),
  CONSTRAINT fk_seat_install FOREIGN KEY (install_ref) REFERENCES installs (id)
) ENGINE=InnoDB;
-- A seat row is created at ingest-token issue time (D1 § 3.3), which is why the row can exist
-- before any event arrives: a provisioned-but-silent seat renders `offline`/`no_data_yet`
-- rather than being invisible. The token rows themselves live in the ingest's own table
-- (card #7338, D1 § 3.3) and are never read by anything in this document.

CREATE TABLE batches (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  seat_ref      INT UNSIGNED NOT NULL,
  batch_id      CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  seq_epoch     CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  sent_at       DATETIME(3) NOT NULL,          -- seat clock
  received_at   DATETIME(3) NOT NULL,          -- server clock
  clock_skew_ms BIGINT       NOT NULL,         -- received_at - sent_at (D1 § 10.1)
  event_count   SMALLINT UNSIGNED NOT NULL,
  accepted      SMALLINT UNSIGNED NOT NULL,
  duplicates    SMALLINT UNSIGNED NOT NULL,
  ignored_unknown_kinds SMALLINT UNSIGNED NOT NULL,
  coerced_enum_values   SMALLINT UNSIGNED NOT NULL,
  response_status SMALLINT UNSIGNED NOT NULL,
  reporter_version  VARCHAR(24) CHARACTER SET ascii NOT NULL,
  reporter_platform ENUM('linux','win32','darwin','other') NOT NULL,
  runtime_version   VARCHAR(24) CHARACTER SET ascii NOT NULL,
  KEY ix_batch_id (seat_ref, batch_id, received_at),   -- NOT unique: see below
  KEY ix_batch_recv (seat_ref, received_at)
) ENGINE=InnoDB;
-- D1 § 10.4's 24 h batch-id memory is enforced by COMPARING received_at, never by deleting the
-- row: a policy expressed as a deletion is indistinguishable from data loss. Rows are retained
-- for the same 14 days as events so that a repeat batch_id outside the window is still
-- diagnosable, and answered as a fresh batch (per-event dedup is the correctness mechanism).
-- ix_batch_id is deliberately NOT UNIQUE, and that is the whole point of the two sentences
-- above: a unique key on (seat_ref, batch_id) would reject the second row for the full 14 days
-- of retention, so "answered as a fresh batch" would raise instead of answering. Uniqueness was
-- never the mechanism -- D1 § 10.4 calls the batch-id memory "an optimisation, not the
-- correctness mechanism", and events.uq_dedup is what actually makes a replay free. The lookup
-- is "the newest row for this (seat_ref, batch_id) whose received_at is within 24 h", which the
-- index above serves directly.

CREATE TABLE events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,  -- assignment order; see § 6.5
  seat_ref      INT UNSIGNED NOT NULL,
  event_id      CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  batch_ref     BIGINT UNSIGNED NOT NULL,
  schema_version SMALLINT UNSIGNED NOT NULL,  -- stored exactly as received; the server never
                                              -- writes or rewrites it (D1 decision 19)
  kind          VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_time    DATETIME(3) NOT NULL,        -- seat clock, stored verbatim, never rewritten
  received_at   DATETIME(3) NOT NULL,        -- server clock
  seq_epoch     CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  seq           BIGINT UNSIGNED NOT NULL,
  session_id    VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,  -- null on heartbeats
  oversize      TINYINT(1) NOT NULL DEFAULT 0,
  data          JSON NOT NULL,
  UNIQUE KEY uq_dedup (seat_ref, event_id),                 -- D2-MUST #3
  KEY ix_seat_seq  (seat_ref, seq_epoch, seq),              -- gap detection, ordering, replay
  KEY ix_seat_recv (seat_ref, received_at),                 -- purge, timeline, staleness
  KEY ix_fold      (seat_ref, id)                           -- the fold's cursor scan
) ENGINE=InnoDB;
-- NOT PARTITIONED, deliberately: MySQL requires every unique key to contain every partitioning
-- column (DOCS-CITED, MySQL 8.0 manual, "Partitioning Keys, Primary Keys, and Unique Keys";
-- verified at provisioning with the version floor of § 6.1), so a RANGE partition on received_at would force uq_dedup to become
-- (seat_ref, event_id, received_at) -- under which the same event re-sent on a later day no
-- longer conflicts, and D2-MUST #3's dedup silently stops working. Cheap partition drops are
-- not worth buying with the guarantee they would break; purge runs as bounded DELETEs instead
-- (§ 6.7).

CREATE TABLE sessions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  seat_ref      INT UNSIGNED NOT NULL,
  session_id    VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  started_at    DATETIME(3) NULL,            -- event_time of session.start; null if never seen
  started_received_at DATETIME(3) NULL,
  start_source  ENUM('startup','resume','clear','compact','fork','unknown') NULL,
  project_label VARCHAR(48)  NULL,
  harness_label VARCHAR(32) CHARACTER SET ascii NULL,
  previous_session_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
  ended_at      DATETIME(3) NULL,
  end_reason    ENUM('clear','resume','logout','prompt_input_exit','other','inferred_silence') NULL,
  closed_by     ENUM('wire','server_offline') NULL,
  reopened      INT UNSIGNED NOT NULL DEFAULT 0,       -- D1 § 12.7 session_reopened
  turn_open     TINYINT(1) NOT NULL DEFAULT 0,
  turn_started_at DATETIME(3) NULL,
  turn_prompt_chars INT UNSIGNED NULL,
  turn_close_source ENUM('wire','session_close','server_offline') NULL,
  last_turn_end_reason ENUM('stop_hook','api_error','session_cleared','session_ended',
                            'server_session_close') NULL,   -- the last is SERVER-side (§ 4.6.1)
  last_turn_ended_at   DATETIME(3) NULL,
  last_turn_aborted_count SMALLINT UNSIGNED NULL,
  last_turn_tool_calls    SMALLINT UNSIGNED NULL,
  last_turn_failed_calls  SMALLINT UNSIGNED NULL,
  stalled_since DATETIME(3) NULL,
  stalled_cleared_by ENUM('turn_start','session_end','left_live') NULL,
                                    -- one member per exit of § 4.4's `stalled` block, which has
                                    -- THREE.  A fourth, 'server_offline', was declared for § 4.6's
                                    -- offline quiescence and deleted: the third exit fires at
                                    -- `stale` OR `offline`, so quiescence can never be the clearer.
  api_error_type ENUM('rate_limit','overloaded','server_error','authentication_failed',
                      'billing_error','invalid_request','model_not_found','max_output_tokens',
                      'oauth_org_not_allowed','account_on_hold','unknown','unrecognised') NULL,
  compaction_open_since DATETIME(3) NULL,
  applied_event_time DATETIME(3) NOT NULL,   -- the LWW comparator, § 6.5
  applied_seq_epoch  CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  applied_seq        BIGINT UNSIGNED NOT NULL,
  updated_at    DATETIME(3) NOT NULL,
  UNIQUE KEY uq_session (seat_ref, session_id),
  KEY ix_session_open (seat_ref, ended_at)
) ENGINE=InnoDB;

CREATE TABLE calls (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  seat_ref      INT UNSIGNED NOT NULL,
  session_ref   BIGINT UNSIGNED NULL,
  call_id       CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  tool_name     VARCHAR(64) CHARACTER SET ascii NOT NULL,
  descriptor    VARCHAR(200) NULL,           -- sanitized at the reporter (D1 § 7); never re-sanitized here
  descriptor_truncated TINYINT(1) NOT NULL DEFAULT 0,
  agent_scope   ENUM('main','subagent') NULL,          -- label only, never gated on
  parent_call_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  is_dispatch   TINYINT(1) NOT NULL DEFAULT 0,
  title         VARCHAR(120) NULL,           -- from subagent.spawn, joined on call_id
  subagent_type VARCHAR(32) CHARACTER SET ascii NULL,
  harness_call_ref VARCHAR(64) CHARACTER SET ascii NULL,
  synthesized   TINYINT(1) NOT NULL DEFAULT 0,          -- D1 § 6.6
  opened_at     DATETIME(3) NULL,            -- event_time
  opened_received_at DATETIME(3) NULL,       -- server clock; the orphan timer's basis
  orphan_due_at DATETIME(3) NULL,            -- materialized at open: +15 min, or +60 min if is_dispatch
  closed_at     DATETIME(3) NULL,            -- event_time of the close, or the server's close time
  closed_received_at DATETIME(3) NULL,
  outcome       ENUM('completed','failed','aborted') NULL,
  abort_reason  ENUM('session_cleared','session_ended','turn_boundary','api_error','interrupted',
                     'reporter_restart','orphan_timeout','seat_offline','session_close') NULL,
  close_source  ENUM('post_tool_use','post_tool_use_failure','reap_session_boundary',
                     'reap_turn_boundary','reap_reporter_restart','subagent_stop_hook',
                     'server_orphan','server_offline','server_session_close') NOT NULL DEFAULT 'post_tool_use',
  match_kind    ENUM('harness_ref','sole_open','lifo_tool_name','agent_id','tombstone_ref',
                     'synthesized','reap') NULL,
  duration_ms   BIGINT UNSIGNED NULL,
  duration_source ENUM('harness','index','none') NULL,
  late_completed TINYINT(1) NOT NULL DEFAULT 0,         -- D1 § 12.5 override applied
  applied_event_time DATETIME(3) NOT NULL,
  applied_seq_epoch  CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  applied_seq        BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_call (seat_ref, call_id),
  KEY ix_open   (seat_ref, closed_at),                  -- "WHERE seat_ref=? AND closed_at IS NULL"
  KEY ix_orphan (closed_at, orphan_due_at),             -- the sweeper's range scan
  KEY ix_recent (seat_ref, opened_received_at)          -- drill-down timeline
) ENGINE=InnoDB;
-- SIX members of these two columns are SERVER-side vocabulary and never appear on the wire, and
-- they are enumerated rather than described because this comment is the only statement in this
-- document of which members may cross the wire, and an implementer validating D1's closed enums
-- at the ingest needs a list rather than a pattern:
--   abort_reason  adds  orphan_timeout, seat_offline, session_close   (D1's set: session_cleared,
--                       session_ended, turn_boundary, api_error, interrupted, reporter_restart)
--   close_source  adds  server_orphan, server_offline, server_session_close  (D1's set ends at
--                       subagent_stop_hook)
-- Three of the six begin with `server_`; three do not, which is why the count is stated and the
-- members are named. They are in the same columns as D1's wire values deliberately: one column
-- answers "how did this call end" for every call, and close_source says whether a seat said so or
-- the server inferred it. D1's enums are unchanged and unextended -- nothing here is ever emitted.

CREATE TABLE attention_requests (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  seat_ref      INT UNSIGNED NOT NULL,
  session_ref   BIGINT UNSIGNED NULL,
  request_id    CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source        ENUM('permission_request_hook','notification_hook') NOT NULL,
  notification_kind ENUM('permission_required','input_awaited','elicitation') NOT NULL,
  call_id       CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  opened_at     DATETIME(3) NOT NULL,        -- event_time
  opened_received_at DATETIME(3) NOT NULL,
  ceiling_at    DATETIME(3) NOT NULL,        -- opened_at + 60 min, materialized
  resolved_at   DATETIME(3) NULL,
  resolution    ENUM('granted','denied','human_input','session_ended','timeout',
                     'server_ceiling','seat_left_live') NULL,
  resolution_source ENUM('permission_denied_hook','call_close','user_prompt_submit','session_end',
                         'timeout','server_ceiling','server_left_live') NULL,
  waited_ms     BIGINT UNSIGNED NULL,
  applied_event_time DATETIME(3) NOT NULL,
  applied_seq_epoch  CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  applied_seq        BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_request (seat_ref, request_id),
  KEY ix_open    (seat_ref, resolved_at),
  KEY ix_ceiling (resolved_at, ceiling_at)
) ENGINE=InnoDB;
-- notification_kind has THREE members and no `other`. D1 § 6.12 deletes the fourth as
-- structurally unreachable; a render branch for it would be a branch nobody can ever reach.
-- TWO members of resolution and TWO of resolution_source are SERVER-side vocabulary and never
-- appear on the wire, enumerated for the same reason the calls block enumerates its six:
--   resolution        adds  server_ceiling, seat_left_live
--   resolution_source adds  server_ceiling, server_left_live
-- 'seat_offline' / 'server_offline' were a third pair, written by § 4.6's offline quiescence, and
-- are deleted for the same reason as stalled_cleared_by's fourth member: § 4.5's leaving-live rule
-- fires at `stale` OR `offline` and has already resolved every open request before quiescence runs.
-- ('server_offline' survives on calls.close_source and sessions.closed_by, which quiescence DOES
--  write -- a 60-min dispatch call is still open at 900 s.)
-- D1's sets (§ 6.13) are granted | denied | human_input | session_ended | timeout, and
-- permission_denied_hook | call_close | user_prompt_submit | session_end | timeout.

CREATE TABLE seat_state (
  seat_ref      INT UNSIGNED NOT NULL PRIMARY KEY,
  state_version BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- feed ordering key; +1 per change to a
                                                      -- VERSION-BEARING field of the § 8.2.1 wire
                                                      -- object -- that object less the ten
                                                      -- delivery/derivation bookkeeping members
                                                      -- § 6.5 names, without which a heartbeat and
                                                      -- every fold pass would mint a delta
  render_state  ENUM('working','idle','blocked','stalled','unknown',
                     'catching_up','stale','offline','disabled','retired') NOT NULL,
  link_state    ENUM('live','catching_up','stale','offline','disabled') NOT NULL,
  activity_state ENUM('working','idle','blocked','stalled','unknown') NOT NULL,
  unknown_reason ENUM('no_data_yet','turn_aborted_calls','turn_killed_by_clear',
                      'turn_ended_with_session','stalled_session_ended','stalled_left_live',
                      'session_closed_turn_open') NULL,
  current_session_ref BIGINT UNSIGNED NULL,
  current_call_ref    BIGINT UNSIGNED NULL,           -- newest open call = the rendered action
  open_calls    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  open_turn     TINYINT(1) NOT NULL DEFAULT 0,
  open_attention_ref BIGINT UNSIGNED NULL,
  -- ACTIVITY (written only from the § 3.2 activity set)
  last_activity_event_time  DATETIME(3) NULL,
  last_activity_received_at DATETIME(3) NULL,
  last_activity_kind        VARCHAR(32) CHARACTER SET ascii NULL,
  -- DELIVERY (never written into an activity column)
  last_receipt_at           DATETIME(3) NULL,
  last_heartbeat_received_at DATETIME(3) NULL,
  last_event_seq_epoch      CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  last_event_seq            BIGINT UNSIGNED NULL,
  clock_skew_ms             BIGINT NULL,
  spool_lag_events          INT UNSIGNED NULL,
  oldest_unsent_age_s       INT UNSIGNED NULL,
  enabled                   TINYINT(1) NULL,
  reporter_version          VARCHAR(24) CHARACTER SET ascii NULL,
  reporter_platform         ENUM('linux','win32','darwin','other') NULL,
  reporter_uptime_s         BIGINT UNSIGNED NULL,
  harness_label             VARCHAR(32) CHARACTER SET ascii NULL,
  heartbeat_counters        JSON NULL,       -- last heartbeat's counters object, verbatim
  heartbeat_predicates      JSON NULL,       -- last heartbeat's predicates object, verbatim
  selftest_failed           JSON NULL,       -- names whose value was "fail"
  reporter_degraded         JSON NULL,       -- D1's 12-member array, verbatim
  server_badges             JSON NULL,       -- § 7.2
  badge_first_seen          JSON NULL,       -- {badge: rfc3339_ms} for every badge CURRENTLY
                                             -- present; a badge that clears is dropped from the
                                             -- map, so `badges_since` = min(values) and there is
                                             -- one timestamp per badge rather than one for D1's
                                             -- whole 12-member array (§ 8.2.1)
  -- CONTEXT
  context_used_pct   DECIMAL(4,1) NULL,
  context_used_tokens INT UNSIGNED NULL,
  context_total_tokens INT UNSIGNED NULL,
  context_source     ENUM('harness','computed') NULL,
  context_sampled_at DATETIME(3) NULL,
  context_sampled_received_at DATETIME(3) NULL,
  model_label        VARCHAR(48) NULL,
  -- TASK (§ 4.9)
  task_title  VARCHAR(120) NULL,
  task_source ENUM('board_card','coord_thread','telemetry') NULL,
  task_ref    VARCHAR(64) NULL,
  task_as_of  DATETIME(3) NULL,
  task_degraded TINYINT(1) NOT NULL DEFAULT 0,   -- a higher tier was dropped past its bound (§ 4.9)
  -- DERIVATION.  fold_lag_ms is NOT a column: it is computed from the three below (§ 2.3),
  -- because a stored lag has only one writer -- the fold -- and therefore freezes at the last
  -- value the fold wrote when the fold is the thing that died.
  head_event_id          BIGINT UNSIGNED NOT NULL DEFAULT 0,  -- written by the INGEST
  fold_cursor_event_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,  -- written by the FOLD
  fold_cursor_received_at DATETIME(3) NULL,                   -- SEEDED by the ingest on the seat's
                                             -- first event (only while NULL), then advanced by the
                                             -- FOLD.  Two writers is the point: it is NULL only for
                                             -- a seat that has never received anything, and such a
                                             -- seat has head_event_id = 0, where § 2.3's cursor test
                                             -- already pins the lag to 0.  So `server_now - NULL`
                                             -- is unreachable and fold_lag_ms is total (§ 2.3).
  fold_errors   INT UNSIGNED NOT NULL DEFAULT 0,
  state_computed_at DATETIME(3) NOT NULL,
  updated_at    DATETIME(3) NOT NULL,
  KEY ix_render (render_state),
  KEY ix_cursor (fold_cursor_event_id),
  KEY ix_behind (fold_cursor_received_at)        -- the fold's claim order (§ 6.5)
) ENGINE=InnoDB;

CREATE TABLE seat_state_transitions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  seat_ref      INT UNSIGNED NOT NULL,
  state_version BIGINT UNSIGNED NOT NULL,
  at            DATETIME(3) NOT NULL,        -- server clock
  from_render_state VARCHAR(16) CHARACTER SET ascii NULL,
  to_render_state   VARCHAR(16) CHARACTER SET ascii NOT NULL,
  cause         ENUM('wire_event','orphan_timeout','staleness_sweep','attention_ceiling',
                     'offline_quiesce','fold_error','rebuild','operator') NOT NULL,
  cause_event_ref BIGINT UNSIGNED NULL,      -- events.id, when cause = wire_event
  detail        JSON NULL,                   -- the facts that changed, for the drill-down
  KEY ix_seat_at (seat_ref, at),
  KEY ix_version (seat_ref, state_version)
) ENGINE=InnoDB;
-- Not a duplicate of `events`: it records WHICH RULE FIRED and what the state became, which the
-- event log does not contain. It is what makes "why did this desk go idle at 14:23" answerable
-- without re-running the fold, and it is what the acceptance tests assert against.

CREATE TABLE seat_counters (
  seat_ref  INT UNSIGNED NOT NULL,
  name      VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  value     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME(3) NOT NULL,
  PRIMARY KEY (seat_ref, name)
) ENGINE=InnoDB;

CREATE TABLE global_counters (
  name      VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  value     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME(3) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE seat_predicates (
  seat_ref   INT UNSIGNED NOT NULL,          -- 0 = fleet-wide predicate
  name       VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  true_count  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  false_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_true_at  DATETIME(3) NULL,
  last_false_at DATETIME(3) NULL,
  alarm_since   DATETIME(3) NULL,
  PRIMARY KEY (seat_ref, name)
) ENGINE=InnoDB;
-- seat_ref 0 is a reserved sentinel for the fleet-wide row, NOT a real row in `seats`, and there
-- is deliberately no FK on this table, because the population is "predicates", not "seats".
-- A fleet-wide predicate has no seat and inventing a fake seat row for it would put a
-- non-existent desk on the floor.

CREATE TABLE feed_tokens (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(64) NOT NULL,          -- "bridge autonomy watchdog"
  token_hash  BINARY(32) NOT NULL,           -- SHA-256; the plaintext is never stored (D1 § 3.3)
  prefix      CHAR(12) CHARACTER SET ascii NOT NULL,  -- "mzr_" + first 8 chars, for identification
  scope       ENUM('fleet_read') NOT NULL,
  created_at  DATETIME(3) NOT NULL,
  created_by  VARCHAR(64) NOT NULL,
  expires_at  DATETIME(3) NOT NULL,
  revoked_at  DATETIME(3) NULL,
  last_used_at DATETIME(3) NULL,
  last_used_ip VARBINARY(16) NULL,
  UNIQUE KEY uq_hash (token_hash),
  KEY ix_prefix (prefix)
) ENGINE=InnoDB;
```

**Row counts and index choices are sized in [§ 6.8](#68-sizing).**

### 6.5 The fold

**One transaction per pass, per seat.**

```
loop:
  claim = SELECT seat_ref, fold_cursor_event_id
            FROM seat_state
           WHERE fold_cursor_event_id < head_event_id
           ORDER BY fold_cursor_received_at ASC   -- furthest behind first; there is no stored
                                                 -- lag, and this column is never NULL for a
                                                 -- seat this WHERE clause can select (§ 2.3)
           LIMIT 8
          FOR UPDATE SKIP LOCKED            -- MySQL 8.0; another worker's seats are skipped
  for each seat in claim:
     BEGIN
       rows = SELECT * FROM events
               WHERE seat_ref = ? AND id > cursor
                 AND received_at <= server_now - INTERVAL 2 SECOND   -- the visibility lag, below
               ORDER BY id                  -- assignment order; see "what the cursor needs"
               LIMIT 500
       for each event: project(event)       -- idempotent upserts, LWW-guarded (below)
       recompute derive_activity() + link_state + render_state
       if any VERSION-BEARING field changed (the set is named below): state_version += 1
       if render_state changed:                    INSERT seat_state_transitions
       if rows is non-empty:
         UPDATE seat_state SET fold_cursor_event_id    = last row's id,
                               fold_cursor_received_at = last row's received_at, ...
       else:
         H, window_empty = SELECT head_event_id,                  -- ONE statement, so the bound H
                                  NOT EXISTS (SELECT 1 FROM events   -- and the emptiness proof come
                                               WHERE seat_ref = ? AND id > cursor)  -- from ONE snapshot
                             FROM seat_state WHERE seat_ref = ?
         if window_empty:
           -- the unfolded window was PURGED out from under the cursor (§ 6.7).  Advance, or this
           -- seat re-claims every pass forever and never folds again; see below.  Advance to H --
           -- the head the proof covers -- and NEVER to `head_event_id` re-read at UPDATE time: that
           -- column is the INGEST's (§ 2.1, written in the same transaction as its events), so a
           -- commit landing between the proof and the write would put the cursor on the id of an
           -- event this pass never folded and never will, stranding it and every lower id of its
           -- batch while `fold_lag_ms` reads 0 because the cursor is at the head.  The guard below
           -- is what makes that interleaving harmless, rather than a lock held across the loop's
           -- per-seat COMMITs: head still H => no ingest committed since the proof => (cursor, H]
           -- is still empty; head moved => zero rows match, nothing advances, and the next pass
           -- folds the new rows through the branch above.  Same class of race as the visibility
           -- lag, and bought the same way -- at the write site, not left to an implementer.
           fold_window_purged += 1              -- § 7.2, and counted on the PROOF, never on the
                                                -- write below: window_empty IS the purge, and a
                                                -- lost race does not un-purge it.  Counted on the
                                                -- write instead, the lost race admits nothing --
                                                -- and the next pass jumps that same interval
                                                -- through the ordinary branch above, which is the
                                                -- SILENT skip this counter exists to prevent.  One
                                                -- worker cannot count the episode twice: once the
                                                -- interleaved batch is committed the window is no
                                                -- longer empty, so this branch is not reached for
                                                -- it again.
           UPDATE seat_state SET fold_cursor_event_id    = H,
                                 fold_cursor_received_at = server_now, ...
            WHERE seat_ref = ? AND head_event_id = H   -- the guard is about the CURSOR alone
         -- else: rows exist but are all inside the 2 s visibility lag.  Do NOT advance; wait.
     COMMIT
     if state_version changed: enqueue a delta (§ 8.3)
```

**The empty read has two causes and they need opposite handling, which is why the branch is in the
loop above rather than left to an implementer.** The claim's predicate is `fold_cursor_event_id <
head_event_id`, and the read under it is filtered twice — by `id > cursor` and by the 2 s visibility
lag. A seat can therefore satisfy the claim and read **zero rows** two ways. *Everything above the
cursor is younger than 2 s*: the events are still coming, and advancing the cursor would skip them, so
the pass must do nothing and let the next one have them. *Everything above the cursor has been purged*
— the fold was down longer than [§ 6.7](#67-retention-and-purge)'s 14-day retention, or a
`mezzanine:rebuild --since` left the cursor below a window that has since aged out — and here doing
nothing is the defect: the claim still matches, the read still returns nothing, and the seat is
re-claimed on **every** pass forever, never advancing, permanently frozen while `fold_lag_ms` grows
without bound. That is [§ 2.3](#23-a-frozen-fold-is-the-dangerous-degradation)'s frozen fold arriving
one seat at a time, and it would badge and alarm correctly while being unfixable by waiting. The
`NOT EXISTS` above is the discriminator between the two, and counting `fold_window_purged`
([§ 7.2](#72-this-planes-own-counters-and-badges)) is what makes the skip visible rather than silent:
the events those passes would have folded are **gone**, so the seat's state is honest but shorter, the
same admission `rebuild_truncated` makes.

**`state_version` and the delta are a per-writer rule, not a property of this loop.** The pseudocode
above is the fold's execution of it, but the rule is: *any* process that changes a version-bearing
field bumps `state_version` and enqueues a delta in the same transaction. This document has three such
writers — the fold above; the **sweeper**, whose every pass recomputes `link_state` and `render_state`
for every seat ([§ 2.1](#21-processes), [§ 4.5](#45-link-states)); and `mezzanine:retire`
([§ 4.10](#410-retirement-is-a-rendered-state)), which states its own bump and publish. The sweeper is
named explicitly because its `live → stale` transition is the **only** delta a permanently quiet desk
will ever get, and leaving that one to be inferred from a rule written inside the fold's loop would
leave exactly the seat this document exists to make legible depending on an implementer's reading.

**The version-bearing field set, stated as a subtraction rather than left as "any field".** An earlier
draft's rule was *any field of the [§ 8.2.1](#821-the-seat-state-object) object*, and that rule is
incompatible with [decision 23](#13-decisions-taken-revisable-at-review) and with
[§ 8.3](#83-the-websocket-delta-feed)'s *"an ordinary `reporter.heartbeat` … emits no delta"*: a heartbeat moves
`delivery.last_receipt_at` and `delivery.last_heartbeat_at`, which **are** fields of that object, so the
literal rule mints 1,440 deltas/seat/day that the volume figures explicitly exclude. Worse, every fold
pass moves `derivation.computed_at` and `derivation.cursor_event_id`, and `derivation.fold_lag_ms` is
computed at read time and so changes continuously — under the literal rule the "state-changing events"
filter does not exist at all and every applied event mints a delta. So the set is named here, where the
rule lives, and it is the [§ 8.2.1](#821-the-seat-state-object) object **less these ten bookkeeping
members**:

| Not version-bearing | Why it moves without anything being rendered differently |
|---|---|
| `delivery.last_receipt_at` · `delivery.last_heartbeat_at` · `delivery.last_seq` · `delivery.clock_skew_ms` · `delivery.spool_lag_events` · `delivery.oldest_unsent_age_s` | all move on **any** receipt, heartbeats included, which is the whole of `reporter.heartbeat`'s effect on this object |
| `reporter.uptime_s` | moves on every heartbeat by construction; it is the flusher-restart discriminator, read server-side from `events` ([§ 7.3](#73-how-the-reporters-own-counters-are-handled)), not from a client's copy |
| `derivation.computed_at` · `derivation.cursor_event_id` | move on every fold pass that applies anything, including passes that change no fact |
| `derivation.fold_lag_ms` | is **computed at read time** ([§ 2.3](#23-a-frozen-fold-is-the-dangerous-degradation)) and therefore changes between any two reads; a version keyed on it would advance with the clock |

**The subtraction is the whole of it, and `activity.*` is on the version-bearing side.** The list above
is closed: every other member of the [§ 8.2.1](#821-the-seat-state-object) object is version-bearing,
and the three activity members are named here because they are the ones a reader is most tempted to
file as bookkeeping. Any event of [§ 3.2](#32-the-activity-event-set)'s activity set moves
`activity.last_event_time`, `activity.last_received_at` and `activity.last_kind`, so **every activity
event emits a delta, whether or not it changes the rendered state** — including the ones whose whole
effect is a drill-down detail, such as the `subagent.stop` at [§ 10](#10-worked-example-the-clear-trace-folded-end-to-end)'s
E6. That is not a concession: it is what [§ 8.3](#83-the-websocket-delta-feed)'s **8,940**/seat-day
already counts (all 120 subagent events among them), and the alternative — excluding `activity.*` —
would freeze the quiet age on every connected client between deltas, which is the false-idle class this
document exists to prevent.

**Which makes "a heartbeat emits no delta" a statement about the ordinary heartbeat, and the exceptions
are named here rather than left to collide with the closed list above.** Every heartbeat moves seven
members of the object **unconditionally** — the six `delivery` bookkeeping members and
`reporter.uptime_s` — and all seven are inside the ten, so the heartbeat that carries no news moves
nothing version-bearing and emits no delta, which is the whole of
[decision 23](#13-decisions-taken-revisable-at-review) and of the 1,440/seat/day
[§ 8.3](#83-the-websocket-delta-feed) excludes. What a heartbeat moves **conditionally** is a short list
of facts outside the ten, and the closed list means each of them is version-bearing:

| Moved by a heartbeat, outside the ten | Why a heartbeat is what moves it |
|---|---|
| `enabled` | the flag is *only ever* learned from a heartbeat ([§ 4.5](#45-link-states) rule 4), so no other event can move it |
| `badges` · `badges_since` | D1's twelve `degraded` members ride the heartbeat ([§ 7.3](#73-how-the-reporters-own-counters-are-handled)); a badge onset or clear is a rendered change |
| `reporter.selftest_failed` | the `selftest` object is a member of the heartbeat's own `data` ([D1 § 6.14](EVENT-SCHEMA.md#614-reporterheartbeat)) and of no other event's; a failing self-test is exactly what a consumer must be told |
| `delivery.seq_epoch` | changes on an epoch reset ([D1 § 10.2](EVENT-SCHEMA.md#102-ordering-seq-and-gap-detection)), which a heartbeat can be the first event to carry |
| `link_state` · `render_state` · `delivery.no_data_since` | derived, not carried: a receipt is what ends `stale`/`offline` and clears `no_data_since`, and `oldest_unsent_age_s` past 300 s is what enters and leaves `catching_up` ([§ 4.5](#45-link-states), [AT-D2-20](#at-d2-20-catching-up-is-not-current-and-not-stale)) — the excluded members are the *inputs*, and a derived value computed from an excluded input is not itself excluded |

Every row of that table is **edge-triggered**: it emits on the heartbeat that *changes* the fact and on
no other, so the population is transitions per seat-day — single digits — and not the 1,440. That is why
the rule is stated as *the heartbeat that moves nothing but bookkeeping emits no delta* rather than as
*heartbeats emit no delta*, and why [§ 8.3](#83-the-websocket-delta-feed)'s 8,940 is unchanged by it:
that figure counts state-changing **events**, and these edges belong to the same handful-per-day
population as the sweeper's own `stale` transition, which the figure has never counted either. The
alternative — suppressing them because their carrier is a heartbeat — is a seat whose `enabled` flip or
`lossy` badge reaches a connected client only on its next unrelated delta, which on a quiet desk is the
false-idle class again in another costume.

**`reporter.version` and `reporter.platform` are deliberately absent from that table, because no event
carries them.** Both are **batch-envelope** fields — [D1 § 4.2](EVENT-SCHEMA.md#42-batch-envelope-fields)
declares `reporter_version` and `reporter_platform` non-null on **every** batch of every kind — and
neither appears in any event's `data`, the heartbeat's
([D1 § 6.14](EVENT-SCHEMA.md#614-reporterheartbeat)) included. That is why [§ 6.4](#64-ddl) stores them
on `batches` while `events` carries only a `batch_ref`, and the fold reads them exactly as
[§ 7.2](#72-this-planes-own-counters-and-badges) already reads the `clock_skew_ms` gauge: the `batches`
column of the batch the event it is applying arrived in, latest into `seat_state`. The **fold** is the
writer and not the ingest, because both members sit outside the ten and are therefore version-bearing,
and a version-bearing field is written by one of the three writers named above. The edge is real and does
emit a delta — but it rides **the first event of any kind whose batch carries the new value**, which on a
busy seat is an ordinary hook event and only on a quiet one a heartbeat. An implementer who went looking
for a `version` member on `reporter.heartbeat`'s `data` to compare would find none, and would write a
rule that can never fire.

**Why excluding them costs a consumer nothing, which is the load-bearing half.** Every quantity this
document says is *rendered* from one of the ten is rendered from a value that cannot be moving at the
moment it is read. "No data for N" is rendered only on a `stale` or `offline` seat
([§ 4.5](#45-link-states)) — a seat that by definition is receiving nothing, so its `last_receipt_at`
is frozen — and the *transition* into `stale` moves `render_state` and `delivery.no_data_since`, both
version-bearing, so the client is told. The quiet age is computed from `activity.last_received_at`,
which is version-bearing. `catching_up` is the rendered consequence of `oldest_unsent_age_s`, and it is
a `link_state`. `clock_skew` past ±120 s is a **badge**, and `badges` is version-bearing. What is left
— the raw skew, the spool depth, the cursor, the fold lag — is drill-down and fleet-health material,
served fresh by [§ 8.2.3](#823-the-seat-detail-response), the snapshot and
[§ 8.2.4](#824-the-fleet-health-object) rather than held by a client between deltas.

Two consequences of the split, stated because an implementer has to choose: the ten **ride the object**
on every snapshot and every detail response and are simply never a *reason* to emit; and when a delta
is emitted for something else, its `patch` carries the changed members of the version-bearing set —
except that [§ 8.3.1](#831-worked-delta)'s shallow merge replaces a nested object whole, so a patch that
touches `delivery.no_data_since` necessarily re-sends the rest of `delivery` with it, which refreshes
the bookkeeping members for free and is why no separate refresh rule is needed.

**`state_version` counts rendered changes; a transition row records only a `render_state` change.**
The two are deliberately different populations: the feed must carry a new action or a moved context
gauge, none of which changes the seat's state name, so a version keyed on `render_state` alone would
leave a client's action field permanently stale ([§ 10](#10-worked-example-the-clear-trace-folded-end-to-end)
has five such changes inside one `working` state). A transition row, conversely, exists to answer *why
did this desk change state*, and one row per tool call would bury that in noise.

**Arrival order for visiting, `(event_time, seq_epoch, seq)` for applying.** The fold *reads* in
`events.id` order because `seq` cannot carry a cursor at all: it can have permanent holes
([D1 § 10.2](EVENT-SCHEMA.md#102-ordering-seq-and-gap-detection)), so a cursor over `seq` could wait
forever for an event that will never arrive. But **it applies with last-write-wins guarded by the
ordering key**, which is `D2-MUST` #4: every projection row carries `applied_event_time`,
`applied_seq_epoch`, `applied_seq`, and a field group is overwritten only when the incoming triple is
greater. Arrival order therefore decides *when* work happens and never *which value wins*.

> **The comparator includes `seq_epoch`, and that is a refinement of `D2-MUST` #4 rather than a
> deviation from it.** `seq` restarts at a new epoch ([D1 § 10.2](EVENT-SCHEMA.md#102-ordering-seq-and-gap-detection)),
> so `(event_time, seq)` alone is not a total order across an epoch change: two events one millisecond
> apart in different epochs could compare backwards. `seq_epoch` is a ULID and therefore sorts by mint
> time, so `(event_time, seq_epoch, seq)` is total, and reduces to `D2-MUST` #4's key exactly whenever
> the epoch is constant — which is every comparison except across a reset. Noted for D1 in
> [§ 14](#14-open-questions-for-the-review-loop) item 4.

**What the cursor actually needs — which is not gaplessness, and is not what AUTO_INCREMENT
gives.** An earlier draft justified the cursor by calling `events.id` "gapless", and that is both false
and the wrong property. It is false because InnoDB burns an AUTO_INCREMENT value on a rolled-back
transaction and interleaves values across concurrent statements under
`innodb_autoinc_lock_mode = 2`, the MySQL 8.0 default (**DOCS-CITED**, MySQL 8.0 reference manual;
**verified at provisioning** with every other MySQL fact of [§ 6.1](#61-deployment-posture)). And it is
the wrong property because a cursor does not care about holes: it needs **no row with `id ≤ cursor` to
become visible after the cursor has passed it**. AUTO_INCREMENT does not give that either — ids are
assigned at `INSERT` and rows become visible at `COMMIT`, so two overlapping ingest transactions for
one seat (an anticipated state: D1 § 10.3's ambiguous-timeout retry) can commit out of id order, and a
fold pass landing between the two commits would advance past the lower id and leave those events
**permanently unfolded** until a manual rebuild.

So the fold buys the property it needs with a **2-second visibility lag**: it reads only rows whose
`received_at` is at least 2 s old. `received_at` is stamped inside the ingest transaction, so a row
becomes eligible only 2 s after its id was assigned — by which time the transaction that assigned it
has committed or rolled back. 2 s is ~3 orders of magnitude above the ingest transaction this design
specifies (one multi-row `INSERT` of ≤ 200 events plus one `batches` row,
[§ 2.1](#21-processes)), and an ingest transaction that exceeds 2 s is a slow-query alarm in its own
right. The residual is stated rather than hidden: this is a bound, not a proof — an exact guarantee
would need a commit-ordered column, which MySQL does not offer — and
[AT-D2-22](#at-d2-22-concurrent-ingest-cannot-strand-an-event-behind-the-cursor) is the test that drives
two overlapping same-seat ingest transactions against a live fold rather than reasoning about them. The
lag covers the advance that *reads* rows; the purged-window branch above advances without reading any,
so it buys the same property the other way — a cursor write guarded on the head its emptiness proof
covered, which an interleaved commit turns into a no-op instead of a skip, and which the second case of
that same test drives. The
cost is that derivation is at least 2 s behind the wire, which is inside the fold's own ≤ 1 s poll plus
one pass and two orders of magnitude below the 60 s `fold_lag` badge.

**Idempotency has two independent mechanisms, and both are load-bearing:**

1. The cursor advance is in the same transaction as the projections, so a crash mid-pass rolls back
   both — an event is applied exactly once.
2. Every projection is an **upsert keyed on a natural key** (`(seat_ref, call_id)`,
   `(seat_ref, session_id)`, `(seat_ref, request_id)`) guarded by the LWW comparator, so applying the
   same event twice is a no-op regardless. This is what makes [§ 6.6](#66-rebuild-from-the-log)'s replay
   safe to run against live tables, and it is why mechanism 1 alone is not enough.

**Per-seat cursors, not one global cursor.** A single global cursor makes one unprojectable event freeze
the entire fleet's derivation — the "one bad batch wedges the stream" shape D1 refuses in the spool. Per
seat, a poison event costs one desk, and that desk says so (`derivation_error`).

**The poison-event rule.** If `project()` raises, the transaction is rolled back, the event is retried
alone once, and on a second raise the cursor advances past it, `fold_error` increments,
`seat_state.fold_errors` increments, the seat badges `derivation_error` and a transition row records the
cause. The event stays in `events`: the fix plus `mezzanine:rebuild --seat` recovers the seat exactly,
which is only true because the log is the source of truth and the projections are derived.

**Batch size 500 and claim size 8, derived.** 500 events is ~2.5 batches at D1's 200-event cap, so a
pass consumes a real seat's arrivals in one transaction while holding row locks for a few
milliseconds; 8 seats per claim keeps one worker's transaction footprint small enough that a second
worker can be added for a larger fleet without changing anything (the claim is `SKIP LOCKED`, so
workers partition themselves). At the ceiling volume a seat produces 10,420 events/day ≈ 0.12/s, so a
500-row pass is ~70 minutes of one seat's traffic: the batch size binds only during a drain.

### 6.6 Rebuild from the log

`php artisan mezzanine:rebuild --seat=<install>/<seat> [--since=<datetime>]` truncates that seat's
projections, resets its cursor — `fold_cursor_event_id` to `0` and `fold_cursor_received_at` to the
`received_at` of the oldest event it is about to replay, never to `NULL`, so
[§ 2.3](#23-a-frozen-fold-is-the-dangerous-degradation)'s lag stays computable and honest for the
length of the run — and replays `events` in `id` order through the identical `project()`
path used by the live fold, counting `state_rebuilds`. **The command shares the fold's code, not a copy
of it** — a rebuild that runs different code is a rebuild that proves nothing.

This exists for three reasons, in order of weight: it is the recovery path after a `derivation_error`;
it is the migration path when a projection gains a column; and it is the **strongest available test of
the derived-not-stored property** — [AT-D2-10](#at-d2-10-rebuild-equals-fold) asserts that a rebuilt
seat's state equals the incrementally folded one field for field. If it ever does not, some fold rule is
reading state that is not in the log, and that rule is a defect by construction.

Bounded honestly: a rebuild can only reconstruct what the retention window still holds. A seat rebuilt
after 14 days starts from the oldest retained event, so calls opened before the window are absent and
the seat derives from what it has — with `rebuild_truncated` counted and a transition row recording it,
rather than a silently shortened history.

### 6.7 Retention and purge

| Data | Retention | Derivation |
|---|---|---|
| `events` | **14 days** after `received_at` | the floor is the **10-day dedup window** (`D2-MUST` #3), because the dedup guarantee *is* the unique key on this table: an event purged early can be re-inserted by a re-send and would double-count. 14 = the floor + 4 days, and the margin is the purge job's own failure budget — the hourly job can be dead for four days before the guarantee is at risk, and a four-day outage of an hourly job is visible in `purge_last_run_at` ~96 times over |
| `batches` | **14 days** | aligned with `events` so a forensic question about an event can always reach its batch. D1 § 10.4's 24 h idempotency memory is a timestamp comparison, not a deletion ([§ 6.4](#64-ddl)) |
| `sessions`, `calls`, `attention_requests` | **14 days** after the row closed; open rows are never purged | a closed fact older than the log it was derived from cannot be re-derived, so purging it early would make a rebuild produce a *different* answer than the live fold — breaking [AT-D2-10](#at-d2-10-rebuild-equals-fold)'s equality for a reason that is not a defect |
| `seat_state_transitions` | **14 days** | the drill-down's history horizon; same number, one home |
| `seat_state`, `seat_counters`, `global_counters`, `seat_predicates`, `installs`, `seats`, `feed_tokens` | **never** | current state and monotonic counters. A seat row outlives its events deliberately: a provisioned seat that has never reported must render, not vanish. A **retired** seat is likewise never purged; it drops out of the read surfaces 14 days after `retired_at` by a query filter, not by a deletion ([§ 4.10](#410-retirement-is-a-rendered-state)), so an operator question about why it went can still be answered |

**The retention chain, stated as one inequality because all three numbers move together:**

```
spool residency  8 days   (D1 § 11.3, the oldest event a seat can still deliver)
      <  dedup window  10 days   (D2-MUST #3)
      <  event retention 14 days  (this document)
```

If any of the three moves, all three are re-checked in the same change. A retention below the dedup
window silently re-ingests re-sent events as new ones — the single most confusing possible corruption of
a timeline, and the reason D1 states the first half of this chain at all.

**Purge mechanics.** `DELETE FROM events WHERE received_at < ? ORDER BY id LIMIT 5000`, looped until it
deletes fewer than the limit or a **60-second wall-clock budget** expires, then the next table. Bounded
batches keep the transaction and the binlog small and keep the store responsive during the pass; the
budget means a purge that cannot keep up **falls behind visibly** (`purge_backlog_rows` is counted)
rather than holding a long transaction. Table-size alarm: `events` past **20 GB** raises
`store_size_alarm` — that is ~2.9× the 50-seat 14-day figure of [§ 6.8](#68-sizing), so it can only
fire on a fleet much larger than planned or a purge that has been dead for a long time, either of which
is worth a human.

### 6.8 Sizing

All of it derives from D1's own volume estimate, which is itself an estimate: **10,420 events/seat/day
at the ceiling** ([D1 § 6.0](EVENT-SCHEMA.md#60-conventions-and-how-harness-payloads-are-read)). No seat
has been instrumented yet, so every figure below inherits that uncertainty and is re-derived from the
first week of live data.

| Quantity | Value | Derivation |
|---|---|---|
| `events` row cost | **~732 B** | clustered row 479 B (columns 449 B + ~30 B InnoDB header) × 1.05 fill, plus three secondary index entries totalling 153 B × 1.5 for B-tree fill and per-entry overhead |
| `events` per seat-day | **7.6 MB** | 10,420 × 732 B |
| projections per seat-day | **~2.1 MB** | calls ~3,000 × 300 B, transitions ~1,400 × 160 B, sessions/attention/heartbeat-derived ~1,740 × 200 B, each × 1.4 for indexes |
| **total per seat-day** | **~9.7 MB** | the two above |
| **per seat, 14 days** | **~136 MB** | × 14 |
| aimla today (4 seats) | **~0.54 GB** | × 4 |
| a plausible fleet (12 seats) | **~1.6 GB** | × 12 |
| a large fleet (50 seats) | **~6.8 GB** | × 50 |

**Transitions are sized from the render-change rate, not from the delta rate**, and the two are
different populations by construction ([§ 6.5](#65-the-fold)): a transition row is written only when
`render_state` changes, while a delta is emitted whenever a **version-bearing** field of the
[§ 8.2.1](#821-the-seat-state-object) object moves ([§ 6.5](#65-the-fold) names the set). At D1's ceiling a seat's render changes are
~1,200/day of turn boundaries (each `turn.start` enters `working`, each `turn.end` leaves it) plus
~200/day of attention edges plus a handful of staleness and ceiling transitions — **~1,400/seat/day**,
against 8,940 deltas. An earlier draft sized this table at the delta rate; the error was conservative
(it over-sized the store by 1.7 MB/seat-day) but it made two rows of
[§ 12](#12-every-number-and-where-it-comes-from) claim a derivation they did not have.

Read the shape rather than the digits: at the fleet sizes this product is for, the entire store fits in
a few gigabytes, so **no design decision here is made for storage reasons** — not the retention window,
not the JSON column, not the transitions table. The one number that would change that is the seat count,
and it scales linearly with a re-derivation that is one multiplication.

**Tool-checked vs hand-verified.** The per-seat-day and per-seat figures are **hand-verified** from the
row-cost model above, and they are the largest block of hand-verified arithmetic left in this document.
`tools/design/verify-fleet-state.py` checks that each of them appears at the
[§ 12](#12-every-number-and-where-it-comes-from) row that cites this section — it does **not** re-derive
the row-cost model itself, because the 449 B column sum and the 153 B index-entry figure are properties
of a MySQL host that does not exist yet ([§ 6.1](#61-deployment-posture)). Both are re-measured at
provisioning against the real table, which is when they stop being estimates; the closure act is stated
here rather than left implicit.

### 6.9 Migrations on a live `events` table

`events` is the largest table and the ingest writes it on the request path, so a blocking `ALTER` is an
ingest outage. Three rules, and the first one is the one that gets skipped:

1. **Every migration on `events` states its algorithm in a comment and the deploy checks it** —
   `ALGORITHM=INSTANT` for an added nullable column at the end (MySQL ≥ 8.0.12), `INPLACE` for a
   secondary index. Anything that would be `COPY` does not ship as a migration; it ships as a documented
   maintenance-window operation or not at all.
2. **A projection change needs no `ALTER` on `events` at all** — add the column to the projection, then
   `mezzanine:rebuild`. That is the payoff for keeping `data` opaque and the projections derived.
3. **New columns are nullable and additive.** Backfills run in the same bounded-batch shape as the
   purge, never as one statement.

### 6.10 Durability posture

**The only irreplaceable asset in this store is `events`, and its value expires in 14 days.** Everything
else is derived and rebuildable ([§ 6.6](#66-rebuild-from-the-log)). So the honest position, stated
rather than assumed:

- **Total loss of the store** costs the retained history and nothing structural. Seats keep reporting;
  the fleet re-derives from the events that arrive after the loss, and seats that still hold spool
  (≤ 8 days, [D1 § 11.3](EVENT-SCHEMA.md#113-rotation-and-the-overflow-policy)) re-deliver what they had
  not yet sent. Within minutes the floor is correct again and within a day the drill-downs are useful
  again.
- **Backups are therefore an operational choice, not a correctness requirement**, and this document does
  not specify one. What it does specify is that the *decision* is recorded at provisioning
  ([§ 14](#14-open-questions-for-the-review-loop) item 6) rather than discovered during an incident.
- **What is not survivable is a silent partial loss** — a store that returns some seats and not others.
  That is why the snapshot read fails closed ([§ 2.2](#22-fail-posture-per-path)) rather than serving
  what it can reach.

---

## 7. Counters

### 7.1 D1's server-side counters — where they live

[D1 § 12.7](EVENT-SCHEMA.md#127-server-side-counters) defines **seventeen** counters the ingest and the
derivation keep — plus one gauge, `clock_skew_ms`, which the table below also has to place — each with
the condition that increments it and the consequence it carries. **Those definitions are not restated
here.** This section says only where each one is stored, which surface exposes it, and which badge it
raises — the questions D1 leaves to the store.

**Seventeen, not the sixteen an earlier revision stated in two places.** D1 § 12.7 has seventeen
*rows*, and its first row carries **two** counter names (`accepted` / `duplicates`), so subtracting the
one gauge from the row count lands one short — a count of rows is not a count of names. The set
equality was always tool-checked and was always right; only the prose count was wrong, in this
sentence and at [Appendix A](#appendix-a--every-d1-obligation-and-where-it-is-discharged) S16, and it
is now re-derived from D1 on every run in both places.

| D1 § 12.7 counter | Stored | Exposed | Badge raised |
|---|---|---|---|
| `accepted` / `duplicates` | `batches` columns (per batch), summed into `seat_counters` | seat detail | — |
| `ignored_unknown_kinds` | `batches` column + `seat_counters` | seat detail | `reporter_ahead` |
| `ignored_unknown_fields` | `seat_counters` | seat detail | `reporter_ahead` |
| `coerced_enum_values` | `batches` column + `seat_counters` | seat detail | `reporter_ahead` |
| `duplicate_open` | `seat_counters` | seat detail | — |
| `late_open` | `seat_counters` | seat detail | — |
| `late_completion` | `seat_counters`; the call also carries `late_completed` | seat detail | — (a **design signal**, read as a rate, not a badge) |
| `orphan_timeout_closes` | `seat_counters`; the call carries `abort_reason: orphan_timeout` | seat detail | — |
| `session_reopened` | `seat_counters`; `sessions.reopened` | seat detail | — |
| `seq_gap` | `seat_counters` | snapshot (badge) + seat detail | **`seq_gap`** — this plane's own badge ([§ 7.2](#72-this-planes-own-counters-and-badges)), **never** D1's `lossy`; see the note below |
| `seq_collision` | `seat_counters` | snapshot (badge) + seat detail | **`seq_collision`** — this plane's own badge |
| `seq_epoch_change` | `seat_counters` | snapshot (badge) + seat detail | **`epoch_reset`** |
| `batches_refused.<error>` | `seat_counters`, one row per error code | seat detail | **none** — the reporter observes the same refusal from the other side and D1 § 9.3's `batches_rejected` member is raised by its own counter. A second badge for one condition would be a second home for one fact, and the two counts disagreeing is itself the signal |
| `unattributed_refusals` | `global_counters` **only** | fleet health | **none — no seat is degraded by it**, because no seat is known ([D1 § 12.1](EVENT-SCHEMA.md#121-validation-order)) |
| `auth_failed_by_ip` | `global_counters`, plus a per-IP window in the cache | fleet health | none — a token that resolves to nothing names no seat |
| `revoked_token_presented` | `global_counters` | fleet health, **operator alert** | none — same reason |
| `clock_skew_ms` *(gauge)* | `batches` column, latest into `seat_state` | snapshot | **`clock_skew`** past ±120 s |

Two properties of this mapping are load-bearing and easy to get wrong:

- **Attribution follows the token, never the body** ([D1 § 12.1](EVENT-SCHEMA.md#121-validation-order)).
  The three global-only counters exist because their refusals happen before any identity is
  established, and writing them against the seat the *body* claimed would let any token-holder degrade
  a colleague's desk. `seat_counters` is only ever written under a `seat_ref` resolved from the token
  binding.
- **A server counter never raises a member of D1's `degraded` array, and D1 says two different
  things about that.** [D1 § 9.3](EVENT-SCHEMA.md#93-degradation-counters) declares the array's twelve
  members and states plainly that `reporter_ahead`, `clock_skew` and **`seq_gap`** "appear in § 12.7 as
  badges the **server** derives; they are not members here, because this array is what the *reporter*
  knows about itself and a reporter cannot observe its own skew or its own gaps." But
  [D1 § 12.7](EVENT-SCHEMA.md#127-server-side-counters)'s `seq_gap` row says "seat badge `lossy`", and
  D1 § 10.2 says "the server counts `seq_gap` and renders the seat `lossy`" — `lossy` being a member of
  the twelve. **This document takes § 9.3's reading**, because it is the one with a mechanism behind it:
  `lossy` means *the reporter discarded events and counted them*, and a server-side gap means *we did
  not receive what the reporter says it sent*. Those are different failures with different fixes, and
  writing both onto one member makes them indistinguishable on the wire — destroying the "the number is
  rendered" semantics § 9.3 attaches to `lossy`. So `seq_gap` raises this plane's own `seq_gap` badge;
  D1's array is stored verbatim and is never written by this server. The contradiction is filed as a D1
  amendment need, [§ 14](#14-open-questions-for-the-review-loop) item 12.
- **A counter with no badge is not a lesser counter.** D1's rule is that a badge for every counter is a
  floor of permanently-yellow desks; the drill-down reads the rest. The mapping above is therefore
  deliberately sparse, and the sparseness is the design.

### 7.2 This plane's own counters and badges

New here — full definitions, because these are D2's. The three columns
[§ 7.1](#71-d1s-server-side-counters--where-they-live) answers for D1's counters are answered here too,
for the same reason: a counter with no stated home is a counter two implementers will put in two places.

| Counter | Stored | Exposed | Incremented when | Consequence |
|---|---|---|---|---|
| `fold_error` | `seat_counters`; `seat_state.fold_errors` | seat detail | a wire event raised twice in `project()` and its cursor was advanced past it | seat badges `derivation_error`; the event is replayable |
| `fold_lag_alarm_entered` | `seat_counters` | seat detail | a seat's `fold_lag_ms` first crossed 60 s in a lag episode | seat badges `fold_lag`; `fleet.fold` degrades past 300 s |
| `server_orphan_closes` | `seat_counters` | seat detail | the sweeper closed a call at its materialized `orphan_due_at` | the server-side twin of D1's `orphan_timeout_closes`, counted separately because one is D1's ledger rule and one is this sweeper's execution of it — a divergence between them means the sweeper is not running |
| `attention_ceiling_expired` | `seat_counters` | seat detail | the sweeper resolved an attention request at its 60-minute ceiling | rising ⇒ `attention.resolved` events are being lost; the `attention_resolved_by_wire` predicate is the alarm |
| `attention_ceiling_overridden` | `seat_counters` | seat detail | an `attention.resolved` arrived after a `server_ceiling` resolution and relabelled it | rising ⇒ the ceiling is firing too early, i.e. resolutions are merely slow, not lost |
| `attention_request_duplicate_server` | `seat_counters` | seat detail | a second `attention.request` arrived while one was open for that session | D1 counts the reporter-side case; this is the server's independent observation of the same thing, and the two disagreeing means one of them is wrong |
| `offline_quiesced_calls` / `offline_quiesced_sessions` | `seat_counters` | seat detail | facts closed by offline quiescence ([§ 4.6](#46-every-open-fact-has-a-ceiling)). There is no `offline_quiesced_attention` twin: an open attention request is resolved by the leaving-live clear before quiescence sees it, and `left_live_resolved_attention` below is the counter that carries it | a spike means a seat left abruptly with work open |
| `left_live_cleared_stalls` / `left_live_resolved_attention` | `seat_counters` | seat detail | the sweeper cleared a `stalled` flag or resolved an attention request at the seat's leaving-live boundary — `stale` at 300 s, or `offline` at 900 s on the one-pass jump ([§ 4.5](#45-link-states)) | rising ⇒ seats are going quiet while blocked or rate-limited, which is a different story from either state ending properly |
| `compaction_ceiling_closed` | `seat_counters` | seat detail | the sweeper closed a `compaction_open_since` at its 15-minute ceiling ([§ 4.6](#46-every-open-fact-has-a-ceiling)) | rising ⇒ `compaction.end` is not arriving; `PostCompact` is one of D1's un-driven hook stubs, so this is the instrument that says so |
| `session_close_orphans` | `seat_counters` | seat detail | a `session.end` arrived with calls still open server-side and the server closed them (`abort_reason: session_close`, `close_source: server_session_close`) | rising ⇒ reap `tool.end`s are being lost in transit, since D1's reaps should have closed them on the wire first |
| `fold_window_purged` | `seat_counters` | seat detail | the fold's emptiness proof found its unfolded window gone to [§ 6.7](#67-retention-and-purge)'s purge, so the cursor advances to the head that proof covered rather than the seat re-claiming forever ([§ 6.5](#65-the-fold)). Counted on the **proof**, not on the guarded cursor write: a pass that loses the race to an ingest advances nothing and still admits the purge, because the same window is jumped by the ordinary branch on a later pass and that jump must not be silent | non-zero ⇒ that seat's state is honest but shorter, and the fold was down longer than retention; the same admission `rebuild_truncated` makes |
| `state_rebuilds` / `rebuild_truncated` | `seat_counters` | seat detail | a `mezzanine:rebuild` ran / ran against a window shorter than the seat's history | operator-visible; a truncated rebuild's state is honest but shorter |
| `feed_resync_required` | `global_counters` | fleet health | a connection was closed for backpressure or a version mismatch | rising ⇒ clients or the network cannot keep up |
| `feed_gap_detected` | `global_counters` | fleet health | a client reported a `state_version` gap on resync, via `?resync_from=` ([§ 8.5](#85-gaps-reconnect-and-why-state_version-is-not-seq)) | rising ⇒ deltas are being lost between the server and the browser |
| `snapshot_served` / `snapshot_denied` | `global_counters` | fleet health | a REST snapshot was served / refused (`503`, `401`) | fleet health |
| `token_wrong_surface` | `global_counters` | fleet health | an `mzn_` ingest token was presented to a read endpoint, or an `mzr_` read token to the ingest | **operator alert** — it is either a misconfiguration that will otherwise present as a mysterious dark seat, or a probe |
| `purge_backlog_rows` | `global_counters` | fleet health | a purge pass hit its 60 s budget with rows still past retention | rising ⇒ the purge cannot keep up; the retention chain's margin is being consumed |

**Which table, and why the split is not arbitrary.** A counter goes in `seat_counters` when a seat can
be named for it and the answer is about that seat; in `global_counters` when it cannot, or when the
subject is the plane rather than a desk. The three read-plane counters and the purge are fleet facts —
no seat caused them and degrading a desk for them would be the attribution error
[§ 7.1](#71-d1s-server-side-counters--where-they-live) refuses for D1's own global-only three.
`seat_counters`' `PRIMARY KEY (seat_ref, name)` therefore needs no sentinel convention: nothing
fleet-wide is ever written to it.

**Reset and overflow, for both tables.** Neither is ever reset — not on a rebuild, not on a deploy, not
on a flusher restart (that is the *reporter's* counters, [§ 7.3](#73-how-the-reporters-own-counters-are-handled),
which are a different population) — and [§ 6.7](#67-retention-and-purge) retains both forever, because
a monotonic counter whose baseline moves is a counter no rate can be computed from. Neither can
overflow in practice: `BIGINT UNSIGNED` tops out at 1.8 × 10¹⁹, and the fastest-moving counter here is
bounded by D1's whole-event ceiling of 10,420/seat/day, which needs ~4.7 × 10¹² years to reach it. That
is stated rather than assumed because "we never thought about wrap" is how a counter comes back as a
negative rate.

**The server-derived badge set** (`seat_state.server_badges`) — the reporter cannot observe any of these
about itself, which is why they are separate from D1's twelve-member `degraded` array and never merged
into it:

`seq_gap` · `seq_collision` · `clock_skew` · `epoch_reset` · `reporter_ahead` · `fold_lag` ·
`derivation_error` — **seven declared, six of them new**, because `epoch_reset` is shared with D1's set.
Every badge [§ 7.1](#71-d1s-server-side-counters--where-they-live)'s "Badge raised" column names is a
member of this list; that is what makes [§ 8.2.1](#821-the-seat-state-object)'s `badges` bound of
**18** a bound over the right population rather than over a list plus some prose.

`catching_up` is deliberately **not** a badge: it is a `link_state`
([§ 4.5](#45-link-states)), and a fact with two homes is a fact free to disagree with itself.

`epoch_reset` appears in both sets, and deliberately: D1's reporter raises it from its own `state_reset`
counter, and the server raises it independently from `seq_epoch_change`. Two independent observations of
one transition is the same discipline D1 applies to `/clear` detection, and the two disagreeing is
itself a signal.

### 7.3 How the reporter's own counters are handled

`reporter.heartbeat.counters` and `.predicates` are **stored verbatim as a snapshot**
(`seat_state.heartbeat_counters` / `heartbeat_predicates`), never summed and never merged into
`seat_counters`. They are monotonic *since flusher start*
([D1 § 6.14](EVENT-SCHEMA.md#614-reporterheartbeat)), so last-write-wins is the only correct handling —
adding two heartbeats' values would double-count, and a value that decreases means the flusher restarted
rather than that a counter went backwards.

Two consequences a consumer must be told about, because both are surprising:

0. **`badges_since` is per badge, and it is a server-side observation.** The server keeps
   `seat_state.badge_first_seen`, a map of every currently-present badge to the time this server first
   saw it present ([§ 6.4](#64-ddl)); a badge that clears is dropped from the map, so
   `badges_since` = the minimum of the values, and it is `null` when `badges` is empty. That is a
   different statement from the sticky-rendering rule below and the two must not be conflated: for a
   D1 `degraded` member the *first-seen* is when the condition first reached the server within that
   flusher's life, while the *counter beside it* is cumulative since flusher start. One timestamp for
   D1's whole twelve-member array — which is what an earlier draft's single
   `reporter_degraded_since` column was — cannot answer "when did **this** badge appear", which is the
   only question `badges_since` is asked.
1. **The reporter's `degraded` array is sticky until the flusher restarts.** A single dropped event at
   09:00 leaves `spool_dropped_events` non-zero, so `lossy` rides *every* heartbeat for the rest of that
   flusher's life. It is therefore rendered as **"since reporter start"** with
   `reporter.uptime_s` and the counter's value beside it — never as "now". Rendering a sticky badge as a
   current condition would make a seat that had one bad minute look permanently broken.
   [§ 14](#14-open-questions-for-the-review-loop) item 5 asks D1 whether a windowed variant is wanted;
   until then this is a rendering rule, not a data problem.
2. **A flusher restart resets the counters to zero.** `reporter_uptime_s` decreasing is the
   discriminator, and it is also how the predicate-window arithmetic below detects a restart.

**The predicate-constant alarm over reporter predicates needs no new table.** D1's heartbeat carries
cumulative branch counts; the rolling-window delta is computed from the **retained heartbeat events**
themselves — the newest heartbeat's counts minus those of the newest heartbeat at or before
`now − W`. Both are rows in `events`, which retains 14 days, comfortably more than the longest window
(7 days). If `reporter_uptime_s` decreased between the two samples, the flusher restarted and the window
is truncated at the restart, with the current values used as the window's totals. That is one query and
no second copy of a number the wire already carries.

---

## 8. The feed contract

### 8.1 Two surfaces, two compatibility postures

| Surface | Consumers | Upgrades | Compatibility posture |
|---|---|---|---|
| **REST** `GET /api/fleet/*` | the browser **and** machine consumers — the bridge's autonomy watchdog ([`docs/PLAN.md § 1`](../PLAN.md#1-the-aggregation-ruling-d-10--standalone-and-why): "One producer, clean boundary, either side deployable alone") | **independently** — the watchdog is another team's deploy | carries `api_version`; additive changes are free and a consumer must ignore unknown fields; a removal, a rename or a **meaning change** is a version bump with a stated window — the same rules as [`docs/VERSIONING.md § Wire compatibility`](../VERSIONING.md#the-rules) 1, 3, 4 and 7, for the same reason: two parties that upgrade separately |
| **WebSocket** `private-fleet.<install_id>` | the browser only | **together** — the client JavaScript is served by the same deploy that serves the feed | carries `feed_version` for detection, but **no support window and no N/N-1 obligation**: there is never a client in the wild older than the server. A client that sees an unknown `feed_version` stops applying deltas and tells the user to reload — it does not attempt a compatibility dance it cannot win |

**Stating the second row is the point.** Inheriting D1's wire-compatibility discipline for a channel
whose two ends ship in one act would cost a support window, a version negotiation and a set of rules
nobody can ever exercise — and an obligation nobody can exercise is one nobody maintains. The asymmetry
is deliberate and rests on a property that is checkable: if the delta feed ever gains a consumer that
upgrades on its own schedule, this row is wrong and the row above it applies.

### 8.2 REST

All four endpoints require authentication ([§ 9](#9-read-side-authentication)). All responses are
`application/json; charset=utf-8` and carry `server_time`.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `/api/fleet/snapshot` | session+MFA **or** `mzr_` token | the whole fleet: every install, every seat, current state. The snapshot half of snapshot-then-deltas, and the watchdog's entire interface |
| `GET` | `/api/fleet/seats/{install_id}/{seat_id}?resync_from=<state_version>` | session+MFA **or** `mzr_` token | one seat: its state object plus the drill-down extras (counters, predicates, open calls, session). `resync_from` is **optional** and is the client's last applied `state_version`: when it is present and the seat's current version exceeds it by more than 1, the server increments `feed_gap_detected` ([§ 8.5](#85-gaps-reconnect-and-why-state_version-is-not-seq)). It changes nothing about the response |
| `GET` | `/api/fleet/seats/{install_id}/{seat_id}/timeline?limit=&before=` | session+MFA | the recent-activity window for D3's drill-down: the seat's renderable events, newest first, `limit` ≤ 200, default 50 |
| `GET` | `/api/fleet/health` | session+MFA **or** `mzr_` token | fleet-level health only, no seat data: store, fold, sweep, ingest recency, counts — **plus the nine fleet-scoped counters**, which this endpoint alone carries ([§ 8.2.4](#824-the-fleet-health-object)) |

`/api/fleet/health` is a **different endpoint from D1's `/api/ingest/health`** and the two are never
merged: one answers "which schema versions does the ingest accept" to a seat holding an ingest token,
the other answers "is the aggregation plane telling the truth right now" to a reader. Merging them would
put the read plane's authorization on the ingest surface.

**The timeline is a query, not a table.** D3's recent-activity window is a bounded read over `events`
filtered to the renderable kinds, ordered by `(seat_ref, received_at)` on an index that exists for the
purge anyway. A materialized activity table was considered and rejected: it would be a second copy of
rows we already keep for 14 days, with its own retention, its own backfill and its own opportunity to
disagree with the log.

#### 8.2.1 The seat-state object

Every field, its type, whether it can be null, its bound and a realistic value. This is the object the
snapshot repeats per seat and the delta patches.

| Field | Type | Null? | Bounds | Example |
|---|---|---|---|---|
| `install_id` | slug | no | ≤ 32 B | `"aimla"` |
| `seat_id` | slug | no | ≤ 48 B | `"aimla-pm"` |
| `state_version` | int | no | ≥ 0, monotonic per seat | `48219` |
| `render_state` | enum | no | the 10 members of [§ 4.2](#42-render-precedence) | `"working"` |
| `link_state` | enum | no | `live`·`catching_up`·`stale`·`offline`·`disabled` | `"live"` |
| `activity_state` | enum | no | `working`·`idle`·`blocked`·`stalled`·`unknown` | `"working"` |
| `unknown_reason` | enum | **yes** | the 7 members of [§ 4.3](#43-the-derivation-function); non-null only when `activity_state == "unknown"` | `null` |
| `api_error_type` | enum | **yes** | D1 § 6.4's 12 members, stored in `sessions.api_error_type` ([§ 6.4](#64-ddl)); non-null **only** when `activity_state == "stalled"`. `D2-MUST` #1 requires it on the object: *"`stalled` carries `api_error_type` so the drill-down can say which error"* | `null` |
| `action` | object | **yes** | the newest open call; `null` when none is open | see below |
| `action.call_id` | ULID | no | 26 chars | `"01K3TA4E5F6G7H8J9K0M1N2P3Q"` |
| `action.tool_name` | string | no | ≤ 64 B | `"Bash"` |
| `action.descriptor` | string | **yes** | ≤ 200 B, sanitized at the reporter | `"Bash: composer test"` |
| `action.started_at` | rfc3339_ms | no | seat clock — a narrative timestamp, never an age | `"2026-08-23T14:23:09.882Z"` |
| `action.started_received_at` | rfc3339_ms | no | server clock — what an age is computed from | `"2026-08-23T14:23:14.201Z"` |
| `action.agent_scope` | enum | **yes** | `main`·`subagent`; a label, never gated on | `"main"` |
| `action.parent_call_id` | ULID | **yes** | the intern join key | `null` |
| `open_calls` | int | no | ≥ 0. **Not** bounded by D1's 64: that is the *reporter's* index cap, which evicts ([D1 § 8.2](EVENT-SCHEMA.md#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)), while the server ledger holds an evicted call open until its 15/60-minute orphan ceiling ([§ 4.6](#46-every-open-fact-has-a-ceiling)) and so can exceed it. The ceiling is the bound: at D1's ~3,000 opens/seat/day a 15-minute window admits ~31 concurrently-open ordinary calls, and the column is `SMALLINT UNSIGNED`, so the bound this field takes in a worst case is **65,535** and not the JS-safe ceiling — its bound is closed, which is the condition rule 4 of the worst-case construction turns on | `1` |
| `open_turn` | bool | no | — | `true` |
| `subagents` | array | no | **0…8 elements**, newest first; `subagents_open` carries the true count | see below |
| `subagents[].call_id` | ULID | no | 26 chars, joins `action`/timeline | `"01K3TA6G7H8J9K0M1N2P3Q4R5T"` |
| `subagents[].title` | string | **yes** | ≤ 120 B; `null` when the `subagent.spawn` was lost — an honest orphan, **never invented**. A later `subagent.spawn` for the same `call_id` does fill it ([§ 10](#10-worked-example-the-clear-trace-folded-end-to-end) E2 is that path); what is forbidden is deriving a title from anything but that event | `"draft the D1 event schema"` |
| `subagents[].subagent_type` | string | **yes** | ≤ 32 B | `"coder"` |
| `subagents[].started_at` | rfc3339_ms | no | seat clock | `"2026-08-23T14:23:31.004Z"` |
| `subagents_open` | int | no | ≥ 0, on the same footing as `open_calls` above and for the same reason — same ceiling mechanism, same `SMALLINT UNSIGNED` column, same closed bound of **65,535** | `1` |
| `task` | object | **yes** | [§ 4.9](#49-the-task-title-merge-and-what-is-not-specified-here) | see below |
| `task.title` | string | no | ≤ 120 B | `"ingest endpoint"` |
| `task.source` | enum | no | `board_card`·`coord_thread`·`telemetry` | `"board_card"` |
| `task.ref` | string | **yes** | ≤ 64 B | `"card#7338"` |
| `task.as_of` | rfc3339_ms | no | server clock | `"2026-08-23T14:05:00.000Z"` |
| `task.degraded` | bool | no | `true` when a higher tier's value was **dropped past its freshness bound** and the merge fell through ([§ 4.9](#49-the-task-title-merge-and-what-is-not-specified-here)). `task.source` says which tier answered; this says whether a better one was discarded, which is a different question | `false` |
| `context` | object | **yes** | `null` until the first `context.sample` | see below |
| `context.used_pct` | float | no | 0.0…100.0, one decimal | `73.2` |
| `context.used_tokens` | int | **yes** | 0…10,000,000 | `146401` |
| `context.total_tokens` | int | **yes** | 1…10,000,000 | `200000` |
| `context.source` | enum | no | `harness`·`computed` — never averaged across the two ([D1 § 6.11](EVENT-SCHEMA.md#611-contextsample)) | `"harness"` |
| `context.sampled_at` | rfc3339_ms | no | seat clock | `"2026-08-23T14:41:00.310Z"` |
| `context.sampled_received_at` | rfc3339_ms | no | server clock | `"2026-08-23T14:41:04.880Z"` |
| `model_label` | string | **yes** | ≤ 48 B | `"claude-opus-5"` |
| `session` | object | **yes** | `null` when no session is open | see below |
| `session.session_id` | string | no | ≤ 128 B, opaque | `"a7f2c918-…"` |
| `session.started_at` | rfc3339_ms | **yes** | seat clock | `"2026-08-23T14:22:40.201Z"` |
| `session.source` | enum | **yes** | D1's 6 members | `"clear"` |
| `session.project_label` | string | **yes** | ≤ 48 B | `"mezzanine"` |
| `session.harness_label` | string | **yes** | ≤ 32 B | `"claude-code/2.1.240"` |
| `activity` | object | no | **never null** — always emitted, with nullable members. Declared because [§ 8.3.1](#831-worked-delta)'s patch is a shallow merge that replaces a nested object whole, so an implementer must know whether an unpopulated `activity` is `null` or an object of nulls; it is the second | see below |
| `activity.last_event_time` | rfc3339_ms | **yes** | seat clock; from the [§ 3.2](#32-the-activity-event-set) set only | `"2026-08-23T14:23:09.882Z"` |
| `activity.last_received_at` | rfc3339_ms | **yes** | server clock; **the basis of the quiet age** | `"2026-08-23T14:23:14.201Z"` |
| `activity.last_kind` | string | **yes** | ≤ 32 B | `"tool.start"` |
| `delivery` | object | no | **never null**, as `activity` above | see below |
| `delivery.last_receipt_at` | rfc3339_ms | **yes** | server clock, any kind incl. heartbeats | `"2026-08-23T14:23:14.201Z"` |
| `delivery.last_heartbeat_at` | rfc3339_ms | **yes** | server clock | `"2026-08-23T14:23:00.412Z"` |
| `delivery.no_data_since` | rfc3339_ms | **yes** | non-null **only** when `link_state ∈ {stale, offline}`; equals `last_receipt_at` | `null` |
| `delivery.clock_skew_ms` | int | **yes** | signed | `412` |
| `delivery.spool_lag_events` | int | **yes** | ≥ 0 | `0` |
| `delivery.oldest_unsent_age_s` | int | **yes** | ≥ 0; **> 300 ⇒ `catching_up`** | `null` |
| `delivery.seq_epoch` | ULID | **yes** | 26 chars | `"01K3T0000A5N7M2X9V4B6D0FGH"` |
| `delivery.last_seq` | int | **yes** | ≥ 1 | `48211` |
| `badges` | array\<string\> | no | **0…18** — the union of D1's 12 `degraded` members and [§ 7.2](#72-this-planes-own-counters-and-badges)'s 7, of which `epoch_reset` is in both. The bound is that union's size and moves only when one of the two tables moves; no duplicates; D1's members first, in D1 § 9.3's order, then this document's, in § 7.2's | `["lossy"]` |
| `badges_since` | rfc3339_ms | **yes** | when the oldest currently-present badge first appeared | `"2026-08-23T09:14:02.118Z"` |
| `enabled` | bool | **yes** | last heartbeat's value; `null` before the first heartbeat | `true` |
| `reporter` | object | no | **never null**, as `activity` above; `uptime_s` and `selftest_failed` are null before the first heartbeat, `version` and `platform` before the seat's first **batch** — they ride the batch envelope, not any event ([§ 6.5](#65-the-fold)) | see below |
| `reporter.version` | semver | **yes** | ≤ 24 B | `"0.1.0"` |
| `reporter.platform` | enum | **yes** | D1's 4 members | `"linux"` |
| `reporter.uptime_s` | int | **yes** | ≥ 0 — the flusher-restart discriminator | `401150` |
| `reporter.selftest_failed` | array\<string\> | no | **0…8**, the failing check names. Not 0…6: [D1 § 6.14](EVENT-SCHEMA.md#614-reporterheartbeat) declares that key set **open at the ingest** — *"a reporter shipping a seventh check ahead of an edit to this table costs one key a consumer does not yet render, and no `422` … the headroom above holds two further members … A ninth does not fit at that bound"* — so a conforming reporter may send 7 or 8 and a consumer validating against 6 would reject valid data. The names total ≤ 175 B across 8, which is what D1's 256 B serialized cap on `selftest` leaves | `[]` |
| `retired` | object | **yes** | `null` unless `seats.retired_at` is set; present for 14 days after retirement ([§ 4.10](#410-retirement-is-a-rendered-state)) | `null` |
| `retired.at` | rfc3339_ms | no | server clock | `"2026-08-20T09:11:04.000Z"` |
| `retired.by` | string | no | ≤ 64 B, the operator | `"aimla-pm"` |
| `retired.reason` | string | no | ≤ 255 B | `"host decommissioned"` |
| `derivation` | object | no | **never null**, as `activity` above | see below |
| `derivation.computed_at` | rfc3339_ms | no | server clock | `"2026-08-23T14:23:14.318Z"` |
| `derivation.fold_lag_ms` | int | no | ≥ 0; **computed, not stored** ([§ 2.3](#23-a-frozen-fold-is-the-dangerous-degradation)) | `117` |
| `derivation.cursor_event_id` | int | no | ≥ 0 | `9912837` |

**`subagents` is capped at 8 with the true count beside it, and that is a stated reduction rule, not a
silent truncation.** D1's index cap admits up to 64 open calls; a side table rendering 64 interns is a
list, not a desk. The 8 kept are the most recently started, `subagents_open` always carries the true
number, and the per-seat detail endpoint returns all of them. The cap is what keeps the worst-case seat
object inside the message bound below.

**Sizes — MEASURED, by serializing an artefact published in this document** (`json.dumps`, no
insignificant whitespace). Every row below names the block it is measured from, and
`tools/design/verify-fleet-state.py` re-derives all seven on every run:

| Object | Bytes | How |
|---|---|---|
| seat state, typical | **1,807 B** | the seat object of the [§ 8.2.2](#822-worked-snapshot) snapshot, serialized |
| seat state, worst case | **5,529 B** | the `patch` of the [§ 8.3.2](#832-worked-worst-case-delta) block, serialized |
| snapshot envelope | **302 B** | the [§ 8.2.2](#822-worked-snapshot) snapshot **less** its one seat object: fleet health + one install wrapper |
| snapshot, 4 seats | **~7.5 KB** typical, **~22 KB** worst | 302 + n × the above |
| snapshot, 50 seats | **~91 KB** typical, **~277 KB** worst | — |
| delta, typical | **323 B** | the [§ 8.3.1](#831-worked-delta) example, serialized |
| delta, worst case | **6,112 B** | the [§ 8.3.2](#832-worked-worst-case-delta) block itself, serialized |

**The worst case is published as an object rather than described as a construction, and that is the
whole point.** An earlier draft labelled these figures *Measured* while the worst case existed only as a
sentence naming six of roughly twenty bounded fields — which is not reproducible, and a figure nobody
can reproduce is not a measurement whatever it is labelled. The block in
[§ 8.3.2](#832-worked-worst-case-delta) is built by four rules, so a reviewer can check the *object* as
well as the byte count:

1. **Every nullable field is populated**, including the ones no real seat carries together — a retired
   seat with an open call is not a reachable state, and this is a size bound rather than a scenario.
2. **Every bounded string is at its bound**: 32 B `install_id`, 48 B `seat_id`, 64 B `tool_name`, 200 B
   `descriptor`, 8 subagents at 120 B titles and 32 B types, 120 B `task.title`, 64 B `task.ref`, 48 B
   `model_label`, 128 B `session_id`, 48 B `project_label`, 32 B `harness_label`, 32 B
   `activity.last_kind`, 24 B `reporter.version`, 64 B `retired.by`, 255 B `retired.reason`, and 175 B
   of `selftest_failed` names (D1's 256 B cap on `selftest`, eight keys).
3. **Every enum is at its longest member**, which is why the object reads `catching_up`,
   `session_closed_turn_open`, `authentication_failed` and `coord_thread`.
4. **Every integer takes its own declared bound, and the JS-safe integer ceiling where it has none** —
   2⁵³−1 (16 digits, 17 with a sign), the same ceiling D1 § 6.0 admits and D1's own verifier uses for
   its worst-case arithmetic. Both halves are load-bearing and an earlier revision stated only the
   second: `context.used_tokens` correctly reads 10,000,000, its declared bound, while `open_calls`
   and `subagents_open` carried the ceiling despite
   [§ 8.2.1](#821-the-seat-state-object) giving them a closed one with a mechanism behind it (the
   15/60-minute orphan ceilings, and a `SMALLINT UNSIGNED` column) — so they now read **65,535**. The
   error was in the safe direction, over-sizing the object by 22 B, which is exactly why it survived:
   these four rules are what this document offers a reviewer *in place of* a reachable scenario, so a
   block that does not follow them is not checkable and the byte figure is back to being asserted.
   Where a bound genuinely is open — `state_version`, `reporter.uptime_s`, `cursor_event_id` — the
   ceiling is deliberately pessimistic, so the bound cannot be falsified by a fleet that runs longer
   than anyone planned.

The worst-case delta at 6,112 B sits inside the **8 KiB per-message bound** this design holds itself to
([§ 8.3](#83-the-websocket-delta-feed)) at **1.34×**, with **2,080 B** spare.

**No pagination, and the threshold at which that stops being true.** A 50-seat snapshot is ~91 KB, which
is one response. Past **200 seats** (~362 KB typical) the snapshot should page by install — stated now
as the trigger, and deliberately not built, because building pagination for a four-seat fleet is
mechanism for a case that does not exist and the trigger is one number away from being noticed.

#### 8.2.2 Worked snapshot

```json
{
  "api_version": 1,
  "server_time": "2026-08-23T14:23:14.400Z",
  "fleet": {
    "db": "ok",
    "fold": "ok",
    "sweep": "ok",
    "sweep_last_run_at": "2026-08-23T14:23:08.002Z",
    "ingest_last_receipt_at": "2026-08-23T14:23:14.201Z",
    "max_fold_lag_ms": 117,
    "seats_total": 4,
    "seats_live": 4
  },
  "installs": [
    {
      "install_id": "aimla",
      "seats": [
        {
          "install_id": "aimla", "seat_id": "aimla-pm", "state_version": 48219,
          "render_state": "working", "link_state": "live", "activity_state": "working",
          "unknown_reason": null, "api_error_type": null,
          "action": {
            "call_id": "01K3TA4E5F6G7H8J9K0M1N2P3Q", "tool_name": "Bash",
            "descriptor": "Bash: composer test",
            "started_at": "2026-08-23T14:23:09.882Z",
            "started_received_at": "2026-08-23T14:23:14.201Z",
            "agent_scope": "main", "parent_call_id": null
          },
          "open_calls": 1, "open_turn": true,
          "subagents": [
            { "call_id": "01K3TA6G7H8J9K0M1N2P3Q4R5T", "title": "draft the D1 event schema",
              "subagent_type": "coder", "started_at": "2026-08-23T14:23:31.004Z" }
          ],
          "subagents_open": 1,
          "task": { "title": "ingest endpoint", "source": "board_card",
                    "ref": "card#7338", "as_of": "2026-08-23T14:05:00.000Z",
                    "degraded": false },
          "context": { "used_pct": 73.2, "used_tokens": 146401, "total_tokens": 200000,
                       "source": "harness", "sampled_at": "2026-08-23T14:41:00.310Z",
                       "sampled_received_at": "2026-08-23T14:41:04.880Z" },
          "model_label": "claude-opus-5",
          "session": { "session_id": "a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
                       "started_at": "2026-08-23T14:22:40.201Z", "source": "clear",
                       "project_label": "mezzanine", "harness_label": "claude-code/2.1.240" },
          "activity": { "last_event_time": "2026-08-23T14:23:09.882Z",
                        "last_received_at": "2026-08-23T14:23:14.201Z",
                        "last_kind": "tool.start" },
          "delivery": { "last_receipt_at": "2026-08-23T14:23:14.201Z",
                        "last_heartbeat_at": "2026-08-23T14:23:00.412Z",
                        "no_data_since": null, "clock_skew_ms": 412,
                        "spool_lag_events": 0, "oldest_unsent_age_s": null,
                        "seq_epoch": "01K3T0000A5N7M2X9V4B6D0FGH", "last_seq": 48211 },
          "badges": ["lossy"], "badges_since": "2026-08-23T09:14:02.118Z", "enabled": true,
          "reporter": { "version": "0.1.0", "platform": "linux", "uptime_s": 401150,
                        "selftest_failed": [] },
          "retired": null,
          "derivation": { "computed_at": "2026-08-23T14:23:14.318Z", "fold_lag_ms": 117,
                          "cursor_event_id": 9912837 }
        }
      ]
    }
  ]
}
```

Read the object against [§ 3](#3-delivery-is-not-activity): `activity.last_received_at` and
`delivery.last_receipt_at` are equal here because the newest event *is* an activity event. On a quiet
seat they diverge, and the divergence is the whole point — `delivery` keeps moving with the heartbeat,
`activity` does not.

#### 8.2.3 The seat detail response

`GET /api/fleet/seats/aimla/aimla-pm` returns the object above plus a `detail` member: the full
`heartbeat_counters` and `heartbeat_predicates` snapshots, this plane's `seat_counters` rows, the open
call list in full (not capped at 8), the open attention request if any, and the current session's turn
statistics. It is the drill-down's source and is deliberately **not** in the fleet snapshot: putting
~1.5 KiB of counters on every seat of every snapshot would multiply the fleet payload by ~2 to serve a
panel that is open for one seat at a time.

#### 8.2.4 The fleet health object

Carried by three surfaces — `GET /api/fleet/health`, the `fleet` member of every snapshot, and the
`fleet` member of every `feed.heartbeat` and `fleet.health` message — and therefore stated once here,
with its value vocabularies enumerated. **The eight health fields are the same on all three, byte for
byte**; `GET /api/fleet/health` carries **one further member, `counters`, and the snapshot and the feed
never do.** That asymmetry is stated as part of the contract rather than left to be discovered, because
a consumer that assumed the three were identical would read a missing `counters` as a zeroed one.

| Field | Type | Null? | Bounds | Example |
|---|---|---|---|---|
| `db` | enum | no | `ok` · `down` — `down` is the [§ 2.2](#22-fail-posture-per-path) fail-closed posture, and it is the only value that can accompany a response with no seat data | `"ok"` |
| `fold` | enum | no | `ok` · `lagging` (any seat's `fold_lag_ms` > 60,000) · `stalled` (any seat > 300,000), per [§ 2.3](#23-a-frozen-fold-is-the-dangerous-degradation) | `"ok"` |
| `sweep` | enum | no | `ok` · `stalled` — `stalled` past **60 s** since `sweep_last_run_at`, which is [§ 2.2](#22-fail-posture-per-path)'s dead-sweep rule; the field exists because that rule had a threshold and no field to put it on | `"ok"` |
| `sweep_last_run_at` | rfc3339_ms | **yes** | server clock; `null` before the sweeper's first pass | `"2026-08-23T14:23:08.002Z"` |
| `ingest_last_receipt_at` | rfc3339_ms | **yes** | server clock, newest receipt of any seat; the `ingest_receiving` predicate's input ([§ 5](#5-server-side-predicates-and-their-controls)) | `"2026-08-23T14:23:14.201Z"` |
| `max_fold_lag_ms` | int | no | ≥ 0, the maximum over **the same population `seats_total` counts** — every seat not retired more than 14 days ago, not only the live ones. One population, named once, because `fleet.fold`'s thresholds ([§ 2.3](#23-a-frozen-fold-is-the-dangerous-degradation)) are stated over *any* seat and two fields of one object reading two populations can disagree: a `stale` seat 117 s behind would set `fleet.fold` to `lagging` while `max_fold_lag_ms` read `0`. A silent seat contributes `0` on its own once its cursor catches up, so widening the population costs nothing and closes that gap | `117` |
| `seats_total` | int | no | ≥ 0, excluding seats retired more than 14 days ago ([§ 4.10](#410-retirement-is-a-rendered-state)) | `4` |
| `seats_live` | int | no | ≥ 0, `link_state == "live"` | `4` |
| `counters` | object | **yes** | **`GET /api/fleet/health` only.** The nine fleet-scoped counters whose `Exposed` cell names this surface — `unattributed_refusals`, `auth_failed_by_ip`, `revoked_token_presented` ([§ 7.1](#71-d1s-server-side-counters--where-they-live)) and `feed_resync_required`, `feed_gap_detected`, `snapshot_served`, `snapshot_denied`, `token_wrong_surface`, `purge_backlog_rows` ([§ 7.2](#72-this-planes-own-counters-and-badges)) — one `BIGINT UNSIGNED` member each, read from `global_counters`, monotonic and never reset. Whenever the object is present **all nine members are**, each at `0` before its first increment: a per-member omission is forbidden, because an omitted counter and a zero counter are the same wire shape to a consumer and only one of them is true. It is `null` — and **only** — when `db` is `down`, because the nine live in the store and a response that could not reach it cannot report them; reporting `0` there would be `docs/KANBAN.md § G-1`'s clean zero on the very surface [§ 2.2](#22-fail-posture-per-path) built its read posture to keep honest. `null` says *we could not read these*; `0` would say *nothing has happened* | `{"purge_backlog_rows": 0, "token_wrong_surface": 0, …}` |

**Why `counters` is on the endpoint and not on the object.** [§ 7.1](#71-d1s-server-side-counters--where-they-live)
declares that its tables answer *where each counter is stored, which surface exposes it, and which badge
it raises* — and for nine counters the answer to the second was a surface that carried no field for
them. `purge_backlog_rows` is the instrument [§ 6.7](#67-retention-and-purge) relies on to make a
falling-behind purge *"fall behind visibly"*, and `token_wrong_surface` is marked **operator alert**;
both were readable on no surface this document defined, which is a counter that reads zero forever by
another route. They belong on `GET /api/fleet/health` rather than on the shared object because that
endpoint's stated purpose is *"is the aggregation plane telling the truth right now"*
([§ 8.2](#82-rest)) and it is polled by an operator, while the shared object rides **every** snapshot
and a `feed.heartbeat` every 15 s — nine monotonic integers on that path would be permanent bytes
carrying, almost always, no news. `tools/design/verify-fleet-state.py` now reds when a counter's
`Exposed` cell names fleet health and this member does not list it, so the two tables cannot drift
apart again.

**No aggregate rolls three health facts into one.** A single `fleet.status: "degraded"` would make "the
store is down" and "one seat's derivation is 70 s behind" the same value, and the whole argument of
[§ 2.2](#22-fail-posture-per-path) is that those two have different postures. D3 may compose a banner
from these three; the wire keeps them apart.

### 8.3 The WebSocket delta feed

**Transport: Laravel Reverb**, the framework's first-party WebSocket server, speaking the Pusher
protocol, with channel authorization through the standard `/broadcasting/auth` endpoint (DOCS-CITED,
Laravel documentation; pinned at build, and the only properties this design depends on are private
channels and per-message publish). **Channel: `private-fleet.{install_id}`** — one per install, so a
floor subscribes to what it renders and a future per-install authorization has a channel to hang on.

| Message `t` | Direction | When | Payload |
|---|---|---|---|
| `seat.delta` | server → client | a seat's `state_version` advanced | `install_id`, `seat_id`, `state_version`, `at`, `changed[]`, `patch{}` |
| `feed.heartbeat` | server → client | every **15 s**, per channel, unconditionally | `server_time`, `fleet{}` (the same health object the snapshot carries) |
| `seat.retired` | server → client | `mezzanine:retire` ran ([§ 2.1](#21-processes)) — the one producer | `install_id`, `seat_id`, `reason`, `at` |
| `fleet.reload` | server → client | `feed_version` changed under a running client (a deploy) | `feed_version`, `reason` |
| `fleet.health` | server → client | **on connect**, and whenever `db`, `fold` or `sweep` changes value | `fleet{}` ([§ 8.2.4](#824-the-fleet-health-object)) |

**`fleet.health` is on this table because [§ 2.2](#22-fail-posture-per-path) and
[AT-D2-12](#at-d2-12-the-store-failing-is-never-a-quiet-zero) both require it**: with MySQL down the
connection is accepted and immediately sent `fleet.health` with `db: "down"`, which is the whole reason
the socket stays up in that posture. It is a separate message type from `feed.heartbeat` even though
both carry the same object, because the heartbeat is unconditional and periodic — a client that inferred
health only from heartbeats would learn about a store outage up to 15 s late, on the one path where the
client is waiting to be told why there is nothing.

**Envelope** — every message: `{"feed_version":1,"t":…,"server_time":"…", …}`.

**`feed.heartbeat` is the property that makes the whole surface honest.** Without it, a socket that has
silently died is indistinguishable from a fleet where nothing is happening — which is exactly the
failure this product exists to remove, one layer further out than D1 solved it. So: the server sends a
heartbeat every 15 s whether or not anything changed, and **a client that has seen no message of any
kind for 45 s (3 intervals) treats the feed as dead**, renders a feed-down indicator, and reconnects.
15 s and 3× are the same shape as D1's own 60 s/300 s heartbeat-and-alarm pair, scaled to a channel
where the round trip is milliseconds instead of a WAN flush.

**Coalescing: one delta per seat per 250 ms.** A seat's changes inside a tick are merged into one
message. 250 ms is chosen because it is below the ~300 ms at which a human notices a change in latency
(the same threshold D1 derives its hook budget against, and — separately — the same order as the
~300 ms status-line debounce D1 records for the harness itself,
[D1 § 6.0](EVENT-SCHEMA.md#60-conventions-and-how-harness-payloads-are-read), DOCS-CITED), so the
floor cannot be made to flicker faster than a person can see. It bounds a single seat's
outbound rate at **4 msg/s** regardless of what the seat does.

**Volume, derived from D1's kind-table ceiling.** State-changing events per seat-day at the ceiling:
6,000 tool events + 1,200 turn events + 1,440 context samples + 120 subagent + 80 session + 100
attention = **8,940**, i.e. **0.103 msg/s/seat** before coalescing. For a 50-seat fleet that is
**5.2 msg/s** and, at the measured 323 B typical delta, **~1.6 KiB/s** per connected client
(5.17 × 323 B = 1,670 B/s). Heartbeats are **not** in that number and must not
be: an **ordinary** `reporter.heartbeat` — one that moves nothing but the six `delivery` bookkeeping
members and `reporter.uptime_s` — moves no version-bearing member ([§ 6.5](#65-the-fold)), so it emits no
delta, and the client's ages come from
`server_time` plus each seat's stored timestamps ([§ 3.3](#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by)).
Emitting a delta per heartbeat would add 1,440/seat/day of pure noise, a 16 % increase in feed traffic
carrying no information. The heartbeat that carries *news* is a different case and does emit — that
exception set is [§ 6.5](#65-the-fold)'s, named there **once**, closed against the version-bearing one,
and deliberately not enumerated again here, where a second copy could drift from it.
Those are **edge-triggered**, single digits per seat-day, and they no more belong in this figure than
the sweeper's own `stale` transition does: 8,940 counts state-changing **events**, and both classes sit
outside it, which is why it stands unchanged.

**Message bound: 8 KiB.** The worst-case delta is 6,112 B, measured by serializing
[§ 8.3.2](#832-worked-worst-case-delta), so the bound cannot bind on a conforming message; it exists so that a future field addition that would
breach it fails a test rather than a client. Reverb's own configured maximum is read at provisioning
(**UNVERIFIED** — the host is not built; closure act: read the deployed `config/reverb.php` and record
it here), and 8 KiB is chosen far below any plausible value so that the two limits cannot interact.

#### 8.3.1 Worked delta

```json
{
  "feed_version": 1,
  "t": "seat.delta",
  "server_time": "2026-08-23T14:23:11.229Z",
  "install_id": "aimla",
  "seat_id": "aimla-pm",
  "state_version": 48220,
  "at": "2026-08-23T14:23:11.229Z",
  "changed": ["render_state", "activity_state", "action", "open_calls"],
  "patch": { "render_state": "idle", "activity_state": "idle", "action": null, "open_calls": 0 }
}
```

`changed` is redundant with `patch`'s keys **on purpose**: a client applies `patch` and uses `changed`
to decide what to animate, and a delta that patches a field to the value it already held (possible after
a resync) is distinguishable from one that did not touch it. `patch` is a shallow merge at the top
level: a nested object is replaced whole, never deep-merged, because a deep merge makes "this field
became null" and "this field was not mentioned" the same wire shape.

#### 8.3.2 Worked worst-case delta

This block exists so the two worst-case figures of [§ 8.2.1](#821-the-seat-state-object) are
**measurable rather than asserted**: the delta's own size is this block serialized, and the worst-case
seat object's size is its `patch` serialized. It is built by the four rules stated at
[§ 8.2.1](#821-the-seat-state-object), and the filler strings are decimal rulers so a reader can count a
bound rather than trust it. **It is not a reachable seat state** — a retired seat with an open call and
all eighteen badges is a size bound, not a scenario — and that is stated rather than left to be noticed.

```json
{
  "feed_version": 1,
  "t": "seat.delta",
  "server_time": "2026-08-23T14:23:09.882Z",
  "install_id": "01234567890123456789012345678901",
  "seat_id": "012345678901234567890123456789012345678901234567",
  "state_version": 9007199254740991,
  "at": "2026-08-23T14:23:09.882Z",
  "changed": ["action", "activity", "activity_state", "api_error_type", "badges", "badges_since", "context", "delivery", "derivation", "enabled", "install_id", "link_state", "model_label", "open_calls", "open_turn", "render_state", "reporter", "retired", "seat_id", "session", "state_version", "subagents", "subagents_open", "task", "unknown_reason"],
  "patch": {
    "install_id": "01234567890123456789012345678901",
    "seat_id": "012345678901234567890123456789012345678901234567",
    "state_version": 9007199254740991,
    "render_state": "catching_up",
    "link_state": "catching_up",
    "activity_state": "working",
    "unknown_reason": "session_closed_turn_open",
    "api_error_type": "authentication_failed",
    "action": {"call_id": "01K3TA4E5F6G7H8J9K0M1N2P3Q", "tool_name": "0123456789012345678901234567890123456789012345678901234567890123", "descriptor": "01234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789", "started_at": "2026-08-23T14:23:09.882Z", "started_received_at": "2026-08-23T14:23:09.882Z", "agent_scope": "subagent", "parent_call_id": "01K3TA4E5F6G7H8J9K0M1N2P3Q"},
    "open_calls": 65535,
    "open_turn": true,
    "subagents": [
      {"call_id": "01K3TA4E5F6G7H8J9K0M1N2P3Q", "title": "012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789", "subagent_type": "01234567890123456789012345678901", "started_at": "2026-08-23T14:23:09.882Z"},
      {"call_id": "01K3TA4E5F6G7H8J9K0M1N2P3Q", "title": "012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789", "subagent_type": "01234567890123456789012345678901", "started_at": "2026-08-23T14:23:09.882Z"},
      {"call_id": "01K3TA4E5F6G7H8J9K0M1N2P3Q", "title": "012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789", "subagent_type": "01234567890123456789012345678901", "started_at": "2026-08-23T14:23:09.882Z"},
      {"call_id": "01K3TA4E5F6G7H8J9K0M1N2P3Q", "title": "012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789", "subagent_type": "01234567890123456789012345678901", "started_at": "2026-08-23T14:23:09.882Z"},
      {"call_id": "01K3TA4E5F6G7H8J9K0M1N2P3Q", "title": "012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789", "subagent_type": "01234567890123456789012345678901", "started_at": "2026-08-23T14:23:09.882Z"},
      {"call_id": "01K3TA4E5F6G7H8J9K0M1N2P3Q", "title": "012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789", "subagent_type": "01234567890123456789012345678901", "started_at": "2026-08-23T14:23:09.882Z"},
      {"call_id": "01K3TA4E5F6G7H8J9K0M1N2P3Q", "title": "012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789", "subagent_type": "01234567890123456789012345678901", "started_at": "2026-08-23T14:23:09.882Z"},
      {"call_id": "01K3TA4E5F6G7H8J9K0M1N2P3Q", "title": "012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789", "subagent_type": "01234567890123456789012345678901", "started_at": "2026-08-23T14:23:09.882Z"}
    ],
    "subagents_open": 65535,
    "task": {"title": "012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789", "source": "coord_thread", "ref": "0123456789012345678901234567890123456789012345678901234567890123", "as_of": "2026-08-23T14:23:09.882Z", "degraded": true},
    "context": {"used_pct": 100.0, "used_tokens": 10000000, "total_tokens": 10000000, "source": "computed", "sampled_at": "2026-08-23T14:23:09.882Z", "sampled_received_at": "2026-08-23T14:23:09.882Z"},
    "model_label": "012345678901234567890123456789012345678901234567",
    "session": {"session_id": "01234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567", "started_at": "2026-08-23T14:23:09.882Z", "source": "startup", "project_label": "012345678901234567890123456789012345678901234567", "harness_label": "01234567890123456789012345678901"},
    "activity": {"last_event_time": "2026-08-23T14:23:09.882Z", "last_received_at": "2026-08-23T14:23:09.882Z", "last_kind": "01234567890123456789012345678901"},
    "delivery": {"last_receipt_at": "2026-08-23T14:23:09.882Z", "last_heartbeat_at": "2026-08-23T14:23:09.882Z", "no_data_since": "2026-08-23T14:23:09.882Z", "clock_skew_ms": -9007199254740991, "spool_lag_events": 9007199254740991, "oldest_unsent_age_s": 9007199254740991, "seq_epoch": "01K3TA4E5F6G7H8J9K0M1N2P3Q", "last_seq": 9007199254740991},
    "badges": ["lossy", "batches_rejected", "harness_contract_moved", "reporter_behind", "value_clamped", "counters_omitted", "index_overflow", "invalid_tool_name", "bad_session_id", "config_invalid", "statusline_degraded", "epoch_reset", "seq_gap", "seq_collision", "clock_skew", "reporter_ahead", "fold_lag", "derivation_error"],
    "badges_since": "2026-08-23T14:23:09.882Z",
    "enabled": true,
    "reporter": {
      "version": "012345678901234567890123",
      "platform": "darwin",
      "uptime_s": 9007199254740991,
      "selftest_failed": ["0123456789012345678901", "0123456789012345678901", "0123456789012345678901", "0123456789012345678901", "0123456789012345678901", "0123456789012345678901", "0123456789012345678901", "012345678901234567890"]
    },
    "retired": {"at": "2026-08-23T14:23:09.882Z", "by": "0123456789012345678901234567890123456789012345678901234567890123", "reason": "012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234"},
    "derivation": {"computed_at": "2026-08-23T14:23:09.882Z", "fold_lag_ms": 9007199254740991, "cursor_event_id": 9007199254740991}
  }
}
```

`changed` lists every key of `patch`, which is what makes this the worst case rather than a large one: a
delta that patches fewer fields is strictly smaller, and no delta can patch more than all of them.

### 8.4 Snapshot-then-deltas

The hazard is ordinary and the protocol is the ordinary answer, stated exactly because getting it
subtly wrong produces a client that is permanently and invisibly wrong about one desk:

```
1. client connects, subscribes to private-fleet.<install>
2. client BUFFERS every seat.delta it receives from this moment
3. client GETs /api/fleet/snapshot            -> each seat carries its own state_version
4. client applies the snapshot
5. client drains the buffer, DISCARDING any delta whose state_version <= that seat's
   snapshot version, and applying the rest in order
6. steady state: apply deltas as they arrive
```

Subscribing **before** fetching is what closes the window: a state change that happens while the
snapshot query is in flight is in the buffer, and the snapshot's per-seat `state_version` is the
watermark that says whether it is already included. Fetching first and subscribing after leaves a hole
exactly the width of the round trip, and the desk that changed in that window stays wrong until
something else changes it — which on a quiet desk is never.

**The watermark is per seat, not per fleet**, because `state_version` is per seat. That is what makes
step 5 exact rather than approximate, and it is why the snapshot must carry the version on every seat
rather than one snapshot-wide sequence number.

### 8.5 Gaps, reconnect, and why `state_version` is not `seq`

**Rule:** a client applies a delta iff `delta.state_version == local.state_version + 1`. If it is
greater, deltas were lost: the client **re-syncs that one seat** via
`GET /api/fleet/seats/{install}/{seat}?resync_from=<its last applied version>`. If it is less than or
equal, the delta is a duplicate or a straggler and is discarded.

**`feed_gap_detected` is counted by the server, from that parameter, and the parameter exists because
nothing else can carry the report.** The read plane is four `GET`s and a server→client feed; there is
no client→server channel on this surface and this document does not add one, because a report endpoint
would be a write surface with its own authorization, rate limit and abuse story for one integer. A
resync `GET` is otherwise byte-identical to an ordinary drill-down fetch, so without the parameter the
server cannot tell a gap from a panel being opened — and a counter nothing can increment is a counter
that reads zero forever, which is the false-clean this document refuses everywhere else. The server
also validates it: `resync_from` greater than the seat's current version is ignored and counted
nowhere, because a client cannot be ahead of the server.

**Why not D1's `(seq_epoch, seq)`.** They are the ordering key for the **event log** and `D2-MUST` #4
makes them load-bearing there — the fold's comparator is built on them
([§ 6.5](#65-the-fold)) and gap detection over them is what raises the `seq_gap` badge. But a *state*
transition can be minted by a rule that has no wire event behind it at all: an orphan-timeout close, a
staleness sweep, an attention ceiling, offline quiescence. Those transitions carry no `seq`, and there
is no honest value to invent for them. A feed ordered by `seq` would therefore be unable to sequence
precisely the transitions that fire when a seat has gone quiet — the ones that matter most.

So the two keys coexist, with a stated division:

| Key | Orders | Authoritative for | Where it appears |
|---|---|---|---|
| `(event_time, seq_epoch, seq)` | the event log, per seat | which of two conflicting **wire facts** wins (`D2-MUST` #4); gap and collision detection | `events`, the projections' `applied_*` columns, `delivery.seq_epoch` / `last_seq` in the snapshot as provenance |
| `state_version` | derived **state**, per seat | which of two **state observations** a client should hold; the snapshot watermark | `seat_state`, every delta, every transition row |

A consumer that wants to correlate a rendered state with the wire has both: the seat object carries the
newest `(seq_epoch, seq)` the fold has applied.

**Reconnect.** On reconnect the client re-runs [§ 8.4](#84-snapshot-then-deltas) from step 1. A full
re-snapshot is ~91 KB for a 50-seat fleet, so there is no per-seat delta-replay buffer on the server
and deliberately so: a replay buffer is a second, stateful copy of recent history whose correctness
would have to be maintained against the store, to save a request that costs less than the buffer's own
memory.

**Backpressure.** Each connection has a bounded outbound queue: **256 messages or 512 KiB, whichever
binds first**. At the fleet's 5.2 msg/s ceiling, 256 messages is ~49 seconds of a 50-seat fleet's
traffic — long enough that an ordinary network hiccup drains, short enough that a wedged client is
noticed within a minute. On overflow the connection is **closed with `resync_required`** and
`feed_resync_required` is counted. Dropping individual deltas instead would leave that browser
permanently wrong with no way to know it; closing costs one snapshot and is self-healing. Other
connections are unaffected, and the bound is per connection precisely so that one slow client cannot
consume the memory of the process serving the rest.

### 8.6 A deliberately-invalid exchange

A machine consumer presents a revoked read token:

```http
GET /api/fleet/snapshot HTTP/1.1
Host: mezzanine.example.org
Authorization: Bearer mzr_<43 chars>
Accept: application/json
```

**Required response — exactly this shape:**

```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json
```
```json
{
  "error": "token_revoked",
  "message": "this read token was revoked on 2026-08-20T09:11:04.000Z",
  "revoked_at": "2026-08-20T09:11:04.000Z",
  "server_time": "2026-08-23T14:23:14.400Z"
}
```

And, as **required behaviour rather than a status code**: zero seat data appears in the body — not a
count, not an install list, not a seat name; `snapshot_denied` increments; `feed_tokens.last_used_at`
and `last_used_ip` are **not** updated (a revoked token's use is recorded in `global_counters` and the
log, not on the row, so a revoked row cannot be made to look live); and the response is identical in
shape and timing whether the token is revoked, expired or has never existed **except for the `error`
code**, which names which — because the caller is a fleet-internal consumer that needs to know why, and
this endpoint is behind an unguessable 256-bit credential rather than exposed to enumeration.

The one outcome forbidden everywhere on this surface: **a `200` with an empty fleet.** That is the
`docs/KANBAN.md § G-1` shape — a clean zero that means "we could not answer" — and on a dashboard it
renders as an empty office, which is indistinguishable from a fleet that has gone home.

---

## 9. Read-side authentication

**D1 owns ingest authentication and none of it is restated here** ([D1 § 3.3](EVENT-SCHEMA.md#33-authentication-and-the-identity-binding-rule)).
This section is the read side, which shares D1's *credential discipline* — 256 bits of entropy from a
CSPRNG, SHA-256 at rest, a greppable prefix — and cites it rather than re-deriving it.

| Surface | Credential | Notes |
|---|---|---|
| the floor, the drill-down, the WebSocket handshake, and REST from a browser | **Laravel session + MFA** (Fortify + TOTP, D-04; card #7334) | `docs/PLAN.md § 3`: MFA gates the page, the websocket handshake **and** the REST snapshot |
| REST from a machine consumer | **`Authorization: Bearer mzr_<43 base64url chars>`** | scope `fleet_read`, read-only. **Never valid on the ingest**, and an `mzn_` ingest token is never valid here: distinct prefixes, distinct tables, and a token presented on the wrong surface is `401` plus `token_wrong_surface` and an operator alert |
| the WebSocket, from a machine consumer | **not supported** | see below |

**The live feed is browser-only, and that is a decision with a cost.** A long-lived socket authenticated
by a bearer token needs a revocation story *on an already-open connection* — a token revoked at 09:00
must not keep streaming until the client disconnects — which means either periodic re-authorization on
the socket or accepting a revocation lag. The known machine consumer is the bridge's autonomy watchdog,
whose decision cadence is minutes ([`docs/PLAN.md § 1`](../PLAN.md#1-the-aggregation-ruling-d-10--standalone-and-why)),
so REST polling serves it exactly. The cost, stated: a future machine consumer that genuinely needs
sub-second fleet state gets polling latency instead, and reversing this means specifying socket
re-authorization, not just opening a port.

| Property | Value | Derivation |
|---|---|---|
| Token entropy and storage | 32 CSPRNG bytes → 43 base64url chars; SHA-256 stored, plaintext never | [D1 § 3.3](EVENT-SCHEMA.md#33-authentication-and-the-identity-binding-rule), cited |
| Prefix | `mzr_` | greppable and distinct from the ingest's `mzn_`; the distinction is what makes `token_wrong_surface` detectable rather than a mystery `401`. **This document mints a credential prefix D1's sanitizer does not know**: [D1 § 7.3](EVENT-SCHEMA.md#73-redaction-rules-applied-in-this-order) rule 3's known-prefix regex enumerates `mzn_` and not `mzr_`, so a read token pasted into a descriptor would not be redacted by that rule. Reachability is low — D1 § 7.1's descriptor allowlist runs first, and a read token has no reason to be near a seat — but [§ 1.3](#13-the-boundary-stated-as-a-rule) obliges the sentence that adds the rule to file the gap, and it is [§ 14](#14-open-questions-for-the-review-loop) item 11 |
| Expiry | **90 days** | long enough that rotation is quarterly rather than constant, short enough that a forgotten token dies. Multiple tokens may be active, so rotation is issue-then-revoke with no overlap window to specify |
| Revocation | checked **per request**, never cached | a revoked credential that keeps working for a cache TTL is a revocation that did not happen. One indexed lookup per request, at a consumer rate of ≤ 1/min |
| Rate limit, token | **120 req/min** | the same ceiling D1 sets for a seat's ingest requests, reused so the fleet has one request-rate number; the watchdog's real cadence is ~1/min, so this is ~120× headroom and can only be reached by a loop |
| Rate limit, session | **600 req/min** | a browser opening drill-downs bursts; 600 is ~10 req/s sustained, far above any human interaction and far below anything that threatens the store |
| Over limit | `429` with `retry_after_s` | — |
| CORS | **none**; no `Access-Control-Allow-*` headers | the browser client is same-origin. A cross-origin read surface for fleet activity is a decision nobody has made |
| Cookies on the machine path | **never sent, never accepted** | the token path is stateless; a cookie there would make CSRF a question this surface does not otherwise have |
| What the read surfaces never contain | ingest tokens, read tokens, token hashes, `.env` values, prompt text, file contents, absolute paths beyond D1's sanitizer output | D1 minimizes at the reporter ([D1 § 1](EVENT-SCHEMA.md#1-non-goals)); **D2 adds no field that D1 did not send**, so the read surface cannot leak what the wire never carried. `config_fingerprint` is safe by D1's construction (it excludes the token) and is exposed in the seat detail only |

**Authorization within the fleet is currently all-or-nothing**: any MFA-authenticated user sees every
install, and any `fleet_read` token does too. That is stated rather than assumed, because the moment a
second organisation's install reports into one Mezzanine it is wrong. The channel and endpoint shapes
are already per-install so that a future ACL has somewhere to attach; whether one is needed is
[§ 14](#14-open-questions-for-the-review-loop) item 7, and it is an operator question, not a design one.

---

## 10. Worked example: the `/clear` trace, folded end to end

This is [D1 § 8.7](EVENT-SCHEMA.md#87-worked-flow--a-clear-during-a-subagents-bash-call)'s trace — a
`/clear` landing while a dispatched subagent runs `Bash: sleep 120` — applied event by event through
this document's fold. It is the golden fixture of [AT-D2-2](#at-d2-2-the-clear-trace-mints-no-idle), and
it is the whole of `D2-MUST` #1 made concrete.

`E0` is a `turn.start` immediately preceding D1's trace, so the seat begins in a state rather than in
mid-air. **The seat's state before E0 is stated rather than left open**, because the trace's transition
count depends on it: this is a **fresh seat with no prior events**, so its `activity_state` is
`unknown` / `no_data_yet` (rule 5 of [§ 4.3](#43-the-derivation-function): `L` is null) and its
`link_state` is `offline` (rule 1 of [§ 4.5](#45-link-states): `last_receipt_at IS NULL`). The
**rendered** value is therefore `offline`, not `unknown`, because [§ 4.2](#42-render-precedence)'s
collapse takes `link_state` whenever it is not `live` — the same reading
[§ 6.4](#64-ddl)'s `seats` comment states in words, *"a provisioned-but-silent seat renders
`offline`/`no_data_yet` rather than being invisible"*. `state_version` is `0`. E0 therefore mints a
transition of its own, and the versions below run 1…10 rather than from an arbitrary base.

| # | Wire event | Facts after applying | `activity_state` | `link_state` | `render_state` | `state_version` | Delta? |
|---|---|---|---|---|---|---|---|
| — | *(pre-E0: no events at all)* | none | `unknown` (`no_data_yet`) | `offline` | **`offline`** | 0 | — |
| E0 | `turn.start` | `T := true` | `working` | `live` | **`working`** | 1 | yes — **transition row 1 of 2**, `offline → working` |
| E1 | `tool.start` `A` (`Agent`) | call `A` open, `is_dispatch`, `orphan_due_at = +60 min` | `working` | `live` | `working` | 2 | yes — `action` becomes `A`; `subagents` gains a title-less entry |
| E2 | `subagent.spawn` `A` | call `A` gains `title`, `subagent_type` | `working` | `live` | `working` | 3 | yes — the intern's label appears |
| E3 | `tool.start` `B` (`Bash`, `agent_scope: subagent`, `parent_call_id: A`) | call `B` open, `orphan_due_at = +15 min`; `open_calls = 2` | `working` | `live` | `working` | 4 | yes — `action` becomes `B` |
| — | *(the operator types `/clear`; the harness SIGKILLs `B`; **no `PostToolUse` and no `PostToolUseFailure` ever fire**)* | — | — | — | — | — | — |
| E4 | `tool.end` `B` — `aborted` / `session_cleared` / `reap_session_boundary` / `match: reap` | call `B` closed `aborted`; `open_calls = 1` | `working` | `live` | `working` | 5 | yes — `action` reverts to `A` |
| E5 | `tool.end` `A` — `aborted` / `session_cleared` | call `A` closed `aborted`; `open_calls = 0`; `subagents` empties; **`T` still true** | `working` | `live` | `working` | 6 | yes — `action` becomes `null` |
| E6 | `subagent.stop` `A` — `aborted` / `session_cleared` | the dispatch projection records the stop's outcome | `working` | `live` | `working` | 7 | yes — `subagent.stop` is in [§ 3.2](#32-the-activity-event-set)'s activity set, so `activity.last_kind` moves from `"tool.end"` to `"subagent.stop"` and both activity timestamps advance; none of the three is among [§ 6.5](#65-the-fold)'s ten excluded members, so this is a version-bearing change like any other. The rendered state does not move — a delta is not a transition — and the stop's own detail lands in the drill-down |
| E7 | `turn.end` — `session_cleared`, `open_calls_at_end: 2`, `aborted_call_ids: [B, A]` | `T := false`; `L := {end_reason: session_cleared, aborted_count: 2}` | **`unknown`** (`turn_killed_by_clear`) | `live` | **`unknown`** | 8 | yes — **transition row 2 of 2**, `working → unknown` |
| E8 | `session.end` — `clear`, `aborted_calls: 2` | session closed, `closed_by: wire`; no open session | `unknown` (`turn_killed_by_clear`) | `live` | `unknown` | 9 | yes — `session` becomes `null` |
| E9 | `session.start` — `clear`, new `session_id`, `previous_session_id` = the old one | new session open, no turn, no calls | `unknown` (`L` is still E7's record — `L` is seat-scoped, [§ 4.3](#43-the-derivation-function)) | `live` | `unknown` | 10 | yes — `session` becomes the new one |

**What the derivation never does here, and why each is structurally impossible rather than merely
avoided:**

- **No `idle` at any point.** Rule 4 of [§ 4.3](#43-the-derivation-function) requires the last turn's
  `end_reason` to be `stop_hook` **and** its aborted count to be zero. E7 supplies `session_cleared` and
  `2`. There is no other rule that produces `idle`.
- **No `idle` in the gap between E5 and E7.** After E5 the seat has zero open calls — which is exactly
  the shape a naive "no open calls ⇒ finished" rule would read as idle. It stays `working` because the
  **turn is still open** (`T`), and `T` is only cleared by a `turn.end`. Precedence rule 3 covers both
  facts with one condition for this reason.
- **No completion is inferred from an absence.** `B` was killed and its close came from the reporter's
  reap, carrying `outcome: aborted` — the ledger discipline D1 built. The server closed nothing here;
  `close_source` for both calls says `reap_session_boundary`, so the drill-down can say *the clear
  killed these*, not *these ended*.
- **`aborted_call_ids` is read from the event, not reconstructed from the ledger.** The idle decision
  therefore does not depend on E4–E6 having been folded before E7. If the batch containing them arrives
  *after* the batch containing E7 — which D1 says is possible
  ([D1 § 10.2](EVENT-SCHEMA.md#102-ordering-seq-and-gap-detection)) — E7's own fields still forbid idle,
  and the LWW comparator keeps the later turn record. That is `D2-MUST` #1 and `D2-MUST` #4 holding
  together rather than one depending on the other.

**The alternate hook order is the same state.** D1 states that `SessionStart(clear)` may arrive before
`SessionEnd(clear)` and that both reap idempotently. In that ordering the reap events arrive under the
`SessionStart` invocation instead; the wire is the same sequence of kinds, so the fold's inputs are the
same and the state path is identical. AT-D2-2 runs both orders.

**Ten events, ten deltas, two transition rows.** All three counts are re-derived from the table above by
`tools/design/verify-fleet-state.py` rather than restated by hand. A client watching this desk sees it
work, sees the action change four times, and sees it fall to `unknown` — and never sees it go idle. The floor shows no
idle animation, which is the entire requirement, made checkable at the state layer exactly as D1 made it
checkable at the wire.

---

## 11. Acceptance tests

Each test names **what to build, what to break to make it RED, and what GREEN asserts.** A test never
seen to fail is not evidence; it is a decoration that reports the harness ran. Every test here runs
against the pinned test database ([§ 6.2](#62-database-names-pinned-and-published)) — which is itself
[AT-D2-14](#at-d2-14-the-store-is-pinned-and-the-pin-bites)'s subject, and that is why AT-D2-14 is the
one that must pass first.

**Fixtures.** Every test below drives the fold with **event fixtures** — arrays of wire events in D1's
exact shape, replayed through the real ingest path into the real store. Six named fixtures are shared:

| Fixture | Contents |
|---|---|
| `clean_turn` | `turn.start`, `tool.start`/`tool.end(completed)`, `turn.end(stop_hook, [])` |
| `clean_turn_then_exit` | `clean_turn`, then `session.end(prompt_input_exit)` — the discriminating fixture for [§ 4.3](#43-the-derivation-function)'s seat-scoped `L`: the seat must stay `idle` |
| `clear_kill` | [§ 10](#10-worked-example-the-clear-trace-folded-end-to-end)'s **ten** events (E0…E9, including the `turn.start` that opens the trace), in both hook orders. Ten, not nine: replaying E1–E9 alone leaves `T` false through the E5–E7 window, which is precisely the window [AT-D2-2](#at-d2-2-the-clear-trace-mints-no-idle)'s first RED probes, so a nine-event fixture would exercise a different rule than the one under test |
| `failed_call` | `turn.start`, `tool.start`, `tool.end(failed, post_tool_use_failure)`, `turn.end(stop_hook, [], failed_calls: 1)` |
| `heartbeat_only` | 60 `reporter.heartbeat` events, one per minute, no activity event of any kind |
| `blocked_pair` | `attention.request(permission_request_hook)` … `attention.resolved(granted, call_close)` |

### AT-D2-1 idle is minted by exactly one rule

- **Build:** replay `clean_turn`; read `seat_state` and `seat_state_transitions`.
- **GREEN:** `activity_state == "idle"`, `render_state == "idle"`, one transition row with
  `cause: wire_event` pointing at the `turn.end`'s `events.id`. Replay `failed_call`: **also `idle`** —
  a failed call is a closed call and does not block idle ([D1 § 6.4](EVENT-SCHEMA.md#64-turnend)), with
  `last_turn_failed_calls == 1`.
- **RED:** weaken rule 4 of [§ 4.3](#43-the-derivation-function) to "any `turn.end` ⇒ idle" and replay
  `clear_kill` → the seat mints `idle`, and AT-D2-2 goes red at the same time. Both halves — the wire's
  discrimination and this rule — must be individually necessary, which is what running both fixtures
  against both rule versions proves.
- **GREEN — idle survives its session:** replay `clean_turn_then_exit` → the seat is **still `idle`**
  after the `session.end`, `L` is unchanged, and no transition row is written. This is
  [§ 4.3](#43-the-derivation-function)'s seat-scoped `L` and [§ 4.8](#48-what-may-never-mint-a-state)
  row 5 asserted together; without it the two sections can disagree and nothing notices.
- **GREEN — `other` is not a degradation:** replay `clean_turn_then_exit` with the `session.end`'s
  `end_reason` set to `other` → identical result, `badges` unchanged and empty, no counter moved. D1
  § 6.2 calls `other` *"a common value, not a residue"* — the majority of its own capture run — and
  forbids reading it as a degradation signal.
- **Discriminating control:** replay `clean_turn` with the `turn.end`'s `end_reason` changed to
  `session_ended` → `unknown`, not `idle`. The test measures the predicate, not the presence of a
  `turn.end`.

### AT-D2-2 the `/clear` trace mints no idle

*This is the D2 half of D1's headline test ([D1 AT-1](EVENT-SCHEMA.md#at-1-kill-vs-complete-the-headline-test)),
and the gate on trusting the derived signal at all.*

- **Build:** replay `clear_kill`, both hook orders, and assert the full state path of
  [§ 10](#10-worked-example-the-clear-trace-folded-end-to-end) from `seat_state_transitions`.
- **GREEN:** the transition sequence is exactly `offline → working` then `working → unknown` — **two**
  rows, because the fixture starts on a fresh seat ([§ 10](#10-worked-example-the-clear-trace-folded-end-to-end)) —
  with **no `idle` row at any version** and no `idle` in any row's `from_render_state`;
  `unknown_reason == "turn_killed_by_clear"`; both calls closed `aborted`/`session_cleared` with
  `close_source: reap_session_boundary`; `open_calls == 0`; no `close_source` beginning `server_`
  anywhere (the server inferred nothing — the reporter said it all).
- **RED — the E5/E7 window:** apply the derivation with rule 3 reduced to `C > 0` (dropping the open-turn
  term) → between E5 and E7 the seat has no open calls and no rule holding it, and it renders `idle` for
  the duration of one fold pass. That flicker is the false idle, arriving through a condition rather
  than through a rule.
- **Second RED — out of order:** deliver E7's batch **before** E4–E6's → GREEN must be unchanged, because
  the idle decision reads `aborted_call_ids` off the event. If it changes, the derivation is
  reconstructing from the ledger and `D2-MUST` #4 is not being honoured.
- **Discriminating control:** the same fixture with the reap events' `outcome` changed to `completed`
  and `aborted_call_ids` emptied → the seat **does** mint idle, proving the test measures the abort
  discrimination and not the shape of the trace.

### AT-D2-3 stale, offline and disabled are rendered, never idle

- **Build:** a folded seat in `idle`; stop delivering events; run the sweeper across the thresholds.
- **GREEN:** **past** 300 s `render_state == "stale"` with `delivery.no_data_since` equal to the last
  receipt, and **past** 900 s `"offline"` — the direction matters and is [§ 4.5](#45-link-states)'s
  cascade, rules 2 and 3: a seat is stale when it has been silent for *more* than 300 s, and asserting
  the ceiling instead would pass on a seat that never went stale at all; `activity_state` is preserved as `idle` underneath and is **never**
  what `render_state` returns; the row is still present in the snapshot at every point. A heartbeat
  carrying `enabled: false` on a live seat renders `disabled`, not `offline`.
- **RED:** derive staleness from `activity.last_received_at` instead of `delivery.last_receipt_at` →
  `heartbeat_only` goes stale in 300 s while the reporter is demonstrably alive and heartbeating, and
  the operator is sent to look at a healthy seat. **Second RED:** derive it from `event_time` → replay
  the same fixture with the seat clock set +10 min and the seat never goes stale at all.
- **Discriminating control:** a seat receiving normally must not enter `stale` in the same run, or the
  sweep is simply marking everything.

### AT-D2-4 a heartbeat-only seat never looks busy

*The mechanised form of [§ 3](#3-delivery-is-not-activity), and the maxim's test.*

- **Build:** replay `clean_turn`, then `heartbeat_only` for an hour of simulated time.
- **GREEN:** `render_state` stays `idle` (the seat is live and its last turn was clean);
  `delivery.last_receipt_at` advances every minute; **`activity.last_received_at` does not move at all**;
  the quiet age grows to 60 minutes while the receipt age stays under 60 s; the `activity_recent`
  predicate flips to `false` while `seat_live` stays `true`.
- **RED:** write `last_activity_received_at` from the heartbeat → the two ages become identical, the
  desk reports "active seconds ago" forever, and `activity_recent` can never flip. **That single line is
  the whole defect**, and this is the test that makes writing it impossible to ship.
- **Discriminating control:** an interleaved fixture (heartbeats **plus** one `tool.start` at minute 30)
  → the activity age resets exactly once, at minute 30. Without this control the RED could be passed by
  a column that never updates at all.

### AT-D2-5 blocked has an exit, including when the exit event is lost

- **Build:** replay `blocked_pair`; then a second seat with only the `attention.request` half.
- **GREEN — ordinary:** `blocked` on the request, cleared on the `attention.resolved` joined by
  `request_id`, with `resolution: granted`, `resolution_source: call_close` and a plausible `waited_ms`.
  **`blocked` outranks the open call** ([§ 4.3](#43-the-derivation-function)): assert the seat renders
  `blocked` while its `call_id` is still open, or the state D1 requires is unreachable on the path that
  produces it.
- **GREEN — the ceiling:** the second seat clears at exactly 60 minutes from the request's `event_time`
  with `resolution: server_ceiling`, `attention_ceiling_expired == 1`, and it renders `blocked` for
  59 minutes and not 61.
- **GREEN — the late override:** deliver the real `attention.resolved` *after* the ceiling fired → the
  resolution is relabelled to the reporter's, `attention_ceiling_overridden == 1`, and the seat **does
  not re-enter `blocked`**. An observation overrides an inference and never reopens a state.
- **GREEN — session end:** a third seat whose session ends while blocked clears via `session_ended`.
- **GREEN — leaving live:** a fourth seat that stops reporting while blocked is resolved by the sweeper
  at the `stale` boundary with `resolution: seat_left_live`, `resolution_source: server_left_live` and
  `left_live_resolved_attention == 1`; when it resumes at 400 s it renders whatever its facts then say
  and **not** `blocked`. `D2-MUST` #5 names leaving live as a clear, and a clear that only masked the
  fact would come back ([§ 4.5](#45-link-states)).
- **RED:** remove the ceiling sweep → the second seat renders `blocked` forever, every later turn
  underneath a stale badge, with no counter marking it unresolved. A state with an entry edge and no
  exit edge is the defect.
- **Discriminating control:** a seat that is never blocked emits neither kind and never renders
  `blocked` — reachable only because D1 gates the `Notification` hook
  ([D1 § 6.12](EVENT-SCHEMA.md#612-attentionrequest)); if it fails, the gate has been lost upstream and
  every seat is about to render `blocked` on `auth_success`.

### AT-D2-6 stalled is a state with three exits

- **Build:** a fixture whose `turn.end` carries `end_reason: api_error`, `api_error_type: rate_limit`.
- **GREEN:** `activity_state == "stalled"`, and the seat object's **`api_error_type` field**
  ([§ 8.2.1](#821-the-seat-state-object)) reads `"rate_limit"` — assert the wire field, not the
  `sessions` column, because `D2-MUST` #1 requires the type to reach the consumer and a column the
  snapshot does not serialize discharges nothing. Then, on three separate seats: (i) the session's next
  `turn.start` clears it → `working`; (ii) the flusher's 90-minute `session.end(inferred_silence)`
  clears it → **`unknown` (`stalled_session_ended`), not `idle`**; (iii) the seat stops reporting → at
  300 s the sweeper clears the flag (`stalled_cleared_by: left_live`, `left_live_cleared_stalls == 1`)
  and the seat renders `stale`, with `activity_state == "unknown"` / `stalled_left_live` underneath —
  and on resuming at 400 s it does **not** return to `stalled`.
- **GREEN — the one-pass jump, which is where the third exit's `or offline` term is bought:** a fourth
  seat goes fully quiet and the fixture withholds the sweeper for >900 s, so its first pass takes the
  seat from `live` **straight to `offline`** with no pass in which rule 3 matched. Assert that the
  leaving-live clear still fired in that pass — `stalled_cleared_by == "left_live"`,
  `left_live_cleared_stalls == 1`, `activity_state == "unknown"` / `stalled_left_live` under a
  `render_state` of `offline` — and that offline quiescence, running **after** it in the same pass
  ([§ 2.1](#21-processes)'s job order), found `stalled_since` already null and wrote no
  `stalled_cleared_by` of its own. There is no `server_offline` clearer to assert, and asserting one
  would be asserting a value no path produces ([§ 4.6](#46-every-open-fact-has-a-ceiling)).
- **RED:** give `stalled` the entry edge and no exit rule, then leave the seat heartbeating and quiet.
  Because the flusher heartbeats every 60 s regardless of session activity, the seat never reaches
  `stale` and one transient rate limit at 09:00 renders `stalled` for the rest of the day on a healthy
  machine. Watch that once.
- **Second RED — narrow the clear back to the `stale` edge:** drop the `or offline` term from
  [§ 4.5](#45-link-states)'s trigger, so the leaving-live clear fires on rule 3 only, and re-run the
  one-pass jump. No pass ever matched rule 3, so nothing clears the flag; quiescence then marks the
  session `ended_at` and `S` goes false through its second term with `stalled_cleared_by` still null.
  The derivation reaches rule 6 with `{end_reason: api_error, stalled_cleared_by: null}`, the catch-all
  row selects **`stalled_session_ended`**, and the seat reports *its session ended* for a seat that in
  fact went silent — while `left_live_cleared_stalls` stays `0`. Assert the reason is
  `stalled_left_live` and the counter is `1`: what the `or offline` term buys is not totality (the
  catch-all row already has that) but the **correct recorded clearer**, and this is the only test that
  can tell the two apart.
- **Discriminating control:** a `turn.end(stop_hook, [])` on the same seat → `idle`, so the test measures
  `api_error` and not the presence of a turn ending.

### AT-D2-7 snapshot-then-deltas has no window

- **Build:** a client harness that subscribes, buffers, snapshots and drains per
  [§ 8.4](#84-snapshot-then-deltas), with a **forced 500 ms delay** injected between the subscribe and
  the snapshot query, and a state change driven inside that window.
- **GREEN:** the client's final state equals the server's `seat_state` exactly, whether the change landed
  before or after the snapshot's read; the buffered delta at or below the watermark is discarded and the
  one above it is applied; running the same scenario 100 times yields 100 identical results.
- **RED — order:** snapshot first, subscribe after → the change made in the window is in neither, and the
  desk stays wrong until something unrelated changes it. Assert the divergence explicitly; on a quiet
  desk it is permanent.
- **Second RED — no watermark:** apply every buffered delta unconditionally → a delta already included
  in the snapshot is re-applied. Assert a case where that is *visible* (a patch that clears `action`
  followed by a snapshot that already has it cleared, then a newer delta that sets it) — a re-application
  that happens to be idempotent proves nothing.

### AT-D2-8 a delta gap is detected and resynced

- **Build:** a client harness; drop exactly one `seat.delta` in flight.
- **GREEN:** the client sees `state_version` jump by 2, fetches
  `GET /api/fleet/seats/{install}/{seat}?resync_from=<its last applied version>`, converges to the
  server's state, and **the server** increments `feed_gap_detected` — assert the counter on the server
  after the request, because the counter has exactly one write path and it is that query parameter
  ([§ 8.5](#85-gaps-reconnect-and-why-state_version-is-not-seq)). The rest of the fleet is untouched — assert other seats' versions did not move.
- **RED:** remove `state_version` from the delta and apply on arrival → the client diverges silently and
  renders the pre-drop state indefinitely. Assert the divergence by comparing the client's object to
  `seat_state` field by field; a test that only checks "no error was thrown" would pass here.
- **Discriminating control:** drop **zero** deltas in an otherwise identical run → no resync, no counter.
  Without it, a client that resyncs constantly would pass the GREEN. **Second control:** fetch the same
  endpoint **without** `resync_from` (an ordinary drill-down open) → `feed_gap_detected` does not move,
  which is what proves the counter measures gaps and not panel opens.

### AT-D2-9 the fold is idempotent across a restart

- **Build:** replay a 2,000-event fixture; `SIGKILL` the fold daemon mid-pass (inside the transaction);
  restart it.
- **GREEN:** the cursor is at the last committed event, every projection is identical to an uninterrupted
  run, `open_calls` and every counter match exactly (equality, not a threshold), and no event was applied
  twice. Repeat with the kill landing between the projection writes and the cursor update — the
  transaction makes that window empty, and the assertion is that the outcome is unchanged.
- **RED:** advance the cursor in a separate transaction from the projections → the kill window becomes
  real and the restart either double-applies (counters high) or skips (calls stuck open). Run it 20
  times; a single run may miss the window, and a race that reproduces sometimes must be driven until it
  reproduces before the GREEN means anything.

### AT-D2-10 rebuild equals fold

*The strongest available check that state is derived and not stored.*

- **Build:** replay a 10,000-event fixture covering every kind through the live fold; snapshot every
  projection row; run `mezzanine:rebuild --seat=…`; compare.
- **GREEN:** every column of `seat_state`, `sessions`, `calls` and `attention_requests` is identical
  except `updated_at`, `state_computed_at` and `state_version` (which counts transitions, and a rebuild
  produces them in one pass). The **rendered** object — everything in
  [§ 8.2.1](#821-the-seat-state-object) — is byte-identical.
- **RED:** make one fold rule read a value that is not in the log — the classic form is "if the seat is
  currently `idle`, treat this event differently" — and the rebuild diverges. That divergence is the
  definition of the defect: a rule whose output depends on history the log does not contain cannot be
  replayed, recovered, or reasoned about.
- **Discriminating control:** a rebuild of an untouched seat must produce **zero** differences, so the
  comparison is known to be capable of reporting equality.

### AT-D2-11 out-of-order batches converge

- **Build:** capture three consecutive batches; deliver them 3, 1, 2.
- **GREEN:** the final state equals in-order delivery exactly, including a `tool.end` that arrives before
  its `tool.start` (the call is created already closed and the late `tool.start` **does not reopen it**,
  counting `late_open` — [D1 § 8.6](EVENT-SCHEMA.md#86-server-side-interpretation-of-open-call-state)),
  and a superseded `turn.end` that must not overwrite a newer one.
- **RED:** apply state by arrival order (drop the `applied_*` comparator) → the older `turn.end` wins,
  the seat's last-turn record regresses, and a completed call reopens and renders `working` forever.
- **Second RED — the epoch:** deliver an event from a **new** `seq_epoch` with a lower `seq` than the
  previous epoch's newest, in the same millisecond → with a `(event_time, seq)` comparator the newer
  event loses; with `(event_time, seq_epoch, seq)` it wins. This is the case
  [§ 6.5](#65-the-fold)'s refinement exists for, and it is the only way to see it.

### AT-D2-12 the store failing is never a quiet zero

- **Build:** run the app with MySQL stopped.
- **GREEN:** `POST /api/ingest/events` returns `503` (retryable — the reporter spools and nothing is
  acknowledged); `GET /api/fleet/snapshot` returns `503 fleet_unavailable` with a machine-readable body
  and **no `installs` key at all**; a connected WebSocket client receives `fleet.health` with
  `db: "down"` and the floor renders an unavailable state, not an empty office.
- **RED:** return `200` with `{"installs": []}` → the floor renders as a building where everyone went
  home, which is `docs/KANBAN.md § G-1`'s defect exactly: a clean zero that means "we could not answer".
  Assert the body, not the status code — a `200` with an empty array is the failure, and it looks fine
  from every monitor that watches status codes.
- **Second RED:** acknowledge the ingest batch (`202`) while the write failed → the reporter advances its
  spool cursor and the events are gone from both copies. Assert the events are absent from the store
  afterwards; that is the only way to see the loss.

### AT-D2-13 every predicate can answer both ways

- **Build:** for **each** predicate in [§ 5](#5-server-side-predicates-and-their-controls), the two
  fixtures its control row names.
- **GREEN:** each predicate records a non-zero count in **both** branches over the fixture pair, and the
  alarm fires when its own criterion is forced. **Negative control:** a mixed distribution over the same
  volume does not alarm.
- **RED:** apply a criterion above a predicate's own evaluation rate — the 5,760/7-day rule on
  `turn_clean`, which is evaluated a few hundred times a day — and it never fires on any real seat. An
  alarm that cannot reach its own threshold is a decoration, and this is what proves the per-predicate
  criteria are load-bearing rather than tidy.
- **Second RED — the pairing that matters most:** make `activity_recent` read
  `delivery.last_receipt_at`. It then moves in lockstep with `seat_live` on every fixture, and the pair
  can no longer discriminate delivery from activity. Assert the lockstep, not just the value.

### AT-D2-14 the store is pinned and the pin bites

*Runs first, because every other test's isolation depends on it.*

- **Build:** the bootstrap guard and the `phpunit.xml` pin-shape test of
  [§ 6.2](#62-database-names-pinned-and-published).
- **GREEN:** the suite runs against `mezzanine_test`, and asserts `config('database.connections.mysql.database')`,
  `config('database.redis.default.database')` and `config('database.redis.cache.database')` resolve to
  the pinned values; the connection's resolved `time_zone` is `+00:00`; every isolation-critical key has
  both an `<env force="true">` and a matching `<server>` entry with equal values.
- **RED — the hostile export, which is the only proof that counts:**
  `DB_DATABASE=mezzanine REDIS_DB=9 php artisan test` **aborts on the guard before the first
  migration**, naming the resolved value it found. A clean run proves nothing here — three separate
  mechanisms (an export, a `force`-only pin, a `_URL` key) leave the declaration looking correct, so the
  guard must be watched refusing.
- **Second RED — the `_URL` mechanism:** with the pins intact, set `DB_URL` to a URL naming
  `mezzanine` → the guard must still abort, because it reads the resolved value and not the declared
  one. Repeat with `REDIS_URL`.
- **Third RED — the pair:** delete the `<server>` half of one pin under an export of that key → the
  shape test fails, naming the key. That is the silent-divergence mode where one line of a two-line pin
  is edited and everything still reads correctly.

### AT-D2-15 feed backpressure closes one connection and no others

- **Build:** two connected clients; stop reading on one; drive fleet traffic past its queue bound.
- **GREEN:** the stalled client's connection is closed with `resync_required`, `feed_resync_required`
  increments, and it converges after reconnecting; the healthy client misses nothing (assert its final
  state equals the server's, field by field); the server's memory does not grow with the stalled
  client's backlog.
- **RED — unbounded queue:** remove the bound → the process's memory grows with the stalled client and
  the healthy client's latency rises with it. **Second RED — drop deltas instead:** the stalled client
  reconnects to a *wrong* state with no gap detected, because dropping a delta without closing loses the
  `state_version` step the gap check depends on.

### AT-D2-16 server-side closes write no wire events

- **Build:** open a call and deliver nothing further; run the sweeper past 15 minutes (ordinary) and
  60 minutes (dispatch), on separate seats.
- **GREEN:** each call is closed in `calls` with `abort_reason: orphan_timeout`,
  `close_source: server_orphan`, at its **materialized** `orphan_due_at`; `server_orphan_closes`
  increments; and **`events` contains no new row** — assert the count is unchanged, because that is the
  only way to see a synthesized event. The seat's `render_state` leaves `working`.
- **GREEN — the late close:** deliver the real `tool.end` afterwards carrying `match: tombstone_ref` →
  it **overrides** the aborted close to the stated outcome and counts `late_completion`
  ([D1 § 12.5](EVENT-SCHEMA.md#125-late-completions-and-orphan-timeouts)); `late_completed` is set on the
  call row.
- **RED — synthesis:** have the sweeper write a synthetic `tool.end` into `events` → the log now contains
  something no seat ever said, `mezzanine:rebuild` re-applies it, and AT-D2-10's equality quietly becomes
  a test of the sweeper rather than of the fold.
- **RED — the wrong clock:** measure the ceiling from `event_time` and run the fixture on a seat with a
  +10-minute clock skew → the call is orphaned 10 minutes early, on arrival, and the desk drops out of
  `working` while the tool is still running.

### AT-D2-17 dedup, retention and the chain between them

- **Build:** deliver a batch; re-deliver it verbatim; then a batch containing one event whose
  `received_at` would place it before the retention boundary.
- **GREEN:** the re-delivery returns `202` with `duplicates` equal to the batch size and `accepted: 0`;
  the store's event count is unchanged; the derived state is **byte-identical** before and after
  (assert the rendered object, not just the counts — a double-applied `tool.start` shows up as a phantom
  open call).
- **RED — retention below the dedup window:** set event retention to 7 days, purge, then re-deliver an
  8-day-old event → it inserts as new, the timeline double-counts it, and the ledger gains a second open
  for a call that closed a week ago. **That is the whole reason the chain in
  [§ 6.7](#67-retention-and-purge) is one inequality**, and this RED is what makes it a test instead of a
  paragraph.
- **Discriminating control:** the same re-delivery inside the window → `duplicates` non-zero and no new
  rows, proving the test measures the window and not the dedup key.

### AT-D2-18 seq gaps, collisions and epoch resets are visible

- **Build:** three fixtures — a batch with a `seq` hole; two events sharing one `(seq_epoch, seq)` with
  different `event_id`s; a batch under a fresh `seq_epoch`.
- **GREEN:** `seq_gap` increments and the seat badges **`seq_gap`** — this plane's own badge, **not**
  D1's `lossy`, and assert that `badges` contains `seq_gap` and does **not** contain `lossy` on a seat
  whose reporter reported no drops, because merging the two would make "the reporter discarded events"
  and "we did not receive what it sent" the same wire value ([§ 7.1](#71-d1s-server-side-counters--where-they-live));
  `seq_collision` increments and the seat badges **`seq_collision`** **and neither event is silently
  dropped** (`D2-MUST` #4 says counted, not applied
  blindly — assert both rows exist and which one won by the comparator); `seq_epoch_change` increments,
  the seat renders `epoch_reset`, and **no gap is reported across the epoch boundary** (a reset is a
  re-numbering, not a loss).
- **RED:** compute gaps across epochs → an ordinary reset reports a ~48,000-event gap and the seat badges
  `seq_gap` for a re-numbering, which is the alarm crying wolf on the one signal that is supposed to
  mean real loss. **Second RED — the merge:** raise D1's `lossy` from the server's `seq_gap` counter, as
  D1 § 12.7's row literally reads, and drive a fixture where the reporter dropped nothing → the seat
  badges `lossy` with `spool_dropped_events == 0`, so the badge's own number contradicts it and
  § 9.3's "the number is rendered" semantics is dead.
- **Discriminating control:** a clean contiguous batch → no counter moves, so the detector is known to be
  capable of reporting "no gap".

### AT-D2-19 read-side auth refuses correctly

- **Build:** four requests to `GET /api/fleet/snapshot` — a valid `mzr_` token, an expired one, a revoked
  one, and a valid **`mzn_` ingest** token — plus a browser session without MFA.
- **GREEN:** `200` / `401 token_expired` / `401 token_revoked` / `401 token_wrong_surface` (with the
  counter and the operator alert) / a redirect to the MFA challenge with **no** fleet data in the
  response body in any of the four failing cases. Assert the *body* is free of install and seat names,
  not merely that the status is non-200.
- **GREEN — no revocation cache:** revoke a token mid-run and issue the next request immediately → it is
  refused on the first attempt, not after a TTL.
- **RED:** cache the token row for 60 s → a revoked credential keeps reading the fleet for a minute, which
  is a revocation that did not happen. **Second RED:** accept the `mzn_` token → the ingest credential
  becomes a read credential, and every seat's token is now a fleet-wide read grant.

### AT-D2-20 catching up is not current, and not stale

- **Build:** a seat whose heartbeat carries `oldest_unsent_age_s > 300` while batches keep arriving
  (D1's post-outage drain, [D1 § 11.5](EVENT-SCHEMA.md#115-retry-and-backoff)).
- **GREEN:** `link_state == "catching_up"`, `render_state == "catching_up"`, the seat is **never**
  `stale` (because `received_at` keeps moving), the underlying `activity_state` still rides the object,
  and `activity.last_event_time` is visibly hours behind `server_time`.
- **RED:** ignore `oldest_unsent_age_s` → the floor animates hours-old work as if it were happening now,
  with no indication anywhere that the desk is replaying history.
- **Discriminating control:** the same seat after the drain completes (`oldest_unsent_age_s` null) →
  `live`, so the state is known to be leaveable.

### AT-D2-21 a frozen fold cannot look healthy

- **Build:** a live fleet; pause the fold daemon while the ingest keeps accepting.
- **GREEN:** within 60 s every affected seat badges `fold_lag`; past 300 s `fleet.fold` reports
  `stalled` and `fold_current` alarms; the REST snapshot still serves (`derivation.fold_lag_ms` rising
  per seat) and every seat object says how stale its derivation is. Resume the daemon → the badges clear
  and the states converge to what an uninterrupted run would have produced (assert against a control
  run).
- **GREEN — the never-folded seat:** with the fold still paused, deliver a **provisioned seat's very
  first batch**. Its cursor has never been advanced, so this is the one state in which the fold has
  written nothing for the lag to be measured from. Assert `derivation.fold_lag_ms` is a **number and
  not `null`**, that it rises from the batch's own `received_at`, that the seat badges `fold_lag` at
  60 s like any other, and that `fleet.max_fold_lag_ms` counts it. This is
  [§ 2.3](#23-a-frozen-fold-is-the-dangerous-degradation)'s ingest-seeded cursor clock, and it is the
  reachable state a read-time `COALESCE` would have rendered as *caught up*.
- **Fourth RED — the unseeded cursor:** drop the ingest's one-shot seed of `fold_cursor_received_at`
  and re-run the GREEN above → `server_now − NULL` yields no value, a non-null wire field serializes
  `null`, `fold_current` records neither branch on the seat the alarm exists for, and
  `fleet.max_fold_lag_ms` aggregates over a hole. Assert the **serialized field**, because a `null`
  here reads to a client as "no lag reported" and to a chart as zero.
- **RED:** omit `fold_lag_ms` from the seat object → the floor renders hours-old states as current with
  fresh receipt ages beside them, and every instrument on the page agrees that everything is fine. This
  is [§ 3](#3-delivery-is-not-activity)'s defect arriving through the derivation plane, and it is the one
  degradation in this design that is invisible without a deliberate instrument.
- **Second RED — the instrument written by the thing it measures:** make `fold_lag_ms` a stored column
  that the fold pass writes, and run the same fixture → the number **freezes** at whatever the last pass
  wrote, no badge fires, `fold_current` never flips, and the GREEN above becomes unreachable. Watch that
  once: it is the reason [§ 2.3](#23-a-frozen-fold-is-the-dangerous-degradation) stores a basis two
  processes write instead of a lag one process writes.
- **Third RED — the wrong operand:** compute the lag from the *newest* unfolded event instead of the
  cursor's own receipt time, and run the fixture against a seat that keeps receiving → the lag reads
  near zero throughout, because the newest unfolded event is always seconds old. The storage and the
  operand are independent defects and this is the one that survives fixing the other.

### AT-D2-22 concurrent ingest cannot strand an event behind the cursor

*The test for [§ 6.5](#65-the-fold)'s visibility lag, and the only way to see the defect the discarded
"gapless" claim was hiding.*

- **Build:** two ingest requests for **one seat**, overlapping in time, against a **running** fold.
  Transaction 1 inserts its events (taking the lower `events.id` values) and is held open; transaction 2
  inserts and commits; then transaction 1 commits. Drive it 20 times, as
  [AT-D2-9](#at-d2-9-the-fold-is-idempotent-across-a-restart) drives its race, because a window that
  reproduces sometimes proves nothing until it has been made to reproduce.
- **GREEN:** every event of both batches is folded, `fold_cursor_event_id` ends at the true head, and
  the seat's rendered object equals a control run that delivered the two batches serially. Assert the
  **event set** the fold applied, not just the final state — a state that happens to match while an
  event was skipped is the failure this test exists to catch.
- **RED — remove the visibility lag:** drop the `received_at <= server_now - INTERVAL 2 SECOND` term and
  run the same 20 iterations → a fold pass that lands between the two commits advances past transaction
  1's lower ids, and those events are **never folded** until a manual `mezzanine:rebuild`. The seat looks
  healthy: no counter moves, no badge fires, `fold_lag_ms` reads 0 because the cursor is at the head.
  That silence is the point.
- **Discriminating control:** the same fixture delivered serially, with the lag in place → zero
  difference from the control run, so the test is known to be capable of reporting "no loss".

**The purged-window branch, driven with the same interleaving.** The case above cannot reach
[§ 6.5](#65-the-fold)'s purge branch — it has no purged window — so that branch gets its own case here
rather than being left untested against the concurrency its own trigger implies: it fires when a fold
restarts against a **live, actively ingesting** fleet — the fold was down longer than
[§ 6.7](#67-retention-and-purge)'s 14-day retention, or a `mezzanine:rebuild --since` left the cursor
below a window that has since aged out.

- **Build:** one seat whose entire unfolded window has been purged, so `fold_cursor_event_id <
  head_event_id` with no event above the cursor; then, with the fold paused **between** its emptiness
  proof and its cursor write, commit an ordinary ingest batch for that seat (which writes its events and
  raises `head_event_id` in one transaction, [§ 2.1](#21-processes)). Drive it 20 times, as above.
- **GREEN:** the cursor never lands above an unfolded event, and **`fold_window_purged` is +1 in both
  arms** — it records the emptiness proof, which both arms passed — with only the cursor differing:
  either the guarded write matched and the cursor is **H**, the head the proof covered, or the
  interleaved commit moved the head, the write matched no row and nothing advanced. In **both** cases
  the next pass folds the interleaved batch and the seat's final state equals a control run in which the
  same batch arrived after the purge branch completed. Assert the applied **event set**, as above.
- **RED — write the head instead of the proven bound:** restore `fold_cursor_event_id = head_event_id`
  with no `AND head_event_id = H` guard and run the same 20 iterations → a pass that loses the race
  writes the cursor to the interleaved batch's head, and that batch is **never folded** while nothing
  records the loss: `fold_window_purged` moves, but it is recording the purge the proof saw and says
  nothing about the stranded batch; no badge fires; `fold_lag_ms` reads 0 because the cursor is at the
  head. The same silence the visibility-lag RED produces, arriving through the other branch.
- **Discriminating control:** the same purged-window fixture with **no** concurrent ingest → the cursor
  advances to `H` on the first pass, `fold_window_purged` = 1, and the seat leaves the claim, so the test
  is known to be capable of reporting "the branch did its job".

### AT-D2-23 a retired seat is rendered, not disappeared

- **Build:** a folded seat with history; retire it by running **`mezzanine:retire`**
  ([§ 2.1](#21-processes)) — not by writing the columns directly, because the command *is* the
  mechanism under test; then advance the clock past 14 days.
- **GREEN:** connected clients receive `seat.retired`; the next snapshot carries the seat with
  `render_state: "retired"` and a populated `retired` object, and at **that** snapshot `link_state` /
  `activity_state` still carry what the seat was doing when it was retired; `fleet.seats_total` still
  counts it. Past 14 days the axes have kept deriving underneath — `link_state` has reached `offline`
  and the render is **still** `retired`, because `retired` short-circuits above both axes
  ([§ 4.10](#410-retirement-is-a-rendered-state), [§ 4.2](#42-render-precedence)) — and the seat is
  absent from the snapshot **while its row is still in `seats`**: assert both, because the
  disappearance must be a read filter and not a deletion ([§ 4.10](#410-retirement-is-a-rendered-state)).
- **RED — the vanishing desk:** drop retired seats from the snapshot query at `retired_at` → a browser
  that reloads sees a seat that existed a second ago simply gone, which is the "vanishing between two
  refreshes" [§ 4.5](#45-link-states) forbids, and there is no rendered state that says why.
- **Second RED — the stale render:** keep the seat but leave `render_state` at its last derived value →
  it renders `offline`, which is a claim about the transport of a seat that has been decommissioned, and
  nothing on the object says an operator did it.
- **Third RED — the columns without the command:** set `retired_at` / `retired_by` /
  `retired_reason` directly and let the ordinary machinery run. **No `seat.retired` ever reaches a
  connected client** — nothing else in this document publishes it — and the transition row the
  sweeper eventually writes carries `cause: staleness_sweep` for a change an operator made, up to a
  full sweep pass after they made it. Assert the *absence of the message* and the *cause value*, not
  the eventual `render_state`: the render does converge, which is exactly why this defect is
  invisible from the desk and has to be asserted on the wire and on the ledger.
- **Discriminating control:** a live seat in the same fleet is unaffected at every step.

---

## 12. Every number, and where it comes from

One table, so a reviewer can audit the arithmetic without reading the prose and a future change can find
every number that moves with it.

**Cited** = D1's or the policy's number, used unchanged and not re-derived here. **Derived** = computed
from another number in this table or in D1. **Chosen** = a judgement call, with its reasoning and what
would re-derive it. **Measured** = produced by serializing or counting a worked artefact in this
document.

| Value | Number | Basis | Where |
|---|---|---|---|
| Seat `stale` threshold | 300 s | **Cited** — D1 § 9.1 | [§ 4.5](#45-link-states) |
| Seat `offline` threshold | 900 s | **Cited** — D1 § 9.1 | [§ 4.5](#45-link-states) |
| `catching_up` threshold | `oldest_unsent_age_s > 300` | **Cited** — D1 § 9.1 states the obligation and the number | [§ 4.5](#45-link-states) |
| Orphan timeout, ordinary call | 15 min | **Cited** — D1 § 12.5; measured here from `received_at`, [§ 4.7](#47-which-clock-each-ceiling-is-measured-from) | [§ 4.6](#46-every-open-fact-has-a-ceiling) |
| Orphan timeout, dispatch call | 60 min | **Cited** — D1 § 12.5 | [§ 4.6](#46-every-open-fact-has-a-ceiling) |
| Attention server ceiling | 60 min | **Cited** — `D2-MUST` #5's ceiling, measured from the request's `event_time` so the server and the reporter fire on one basis | [§ 4.4](#44-activity-states-every-entry-and-exit-edge) |
| Session `inferred_silence` | 90 min | **Cited** — D1 § 6.2; consumed, never re-implemented (the flusher emits it) | [§ 4.6.1](#461-the-turn-has-no-timer-of-its-own) |
| Clock-skew badge | ±120 s | **Cited** — D1 § 10.1 | [§ 7.1](#71-d1s-server-side-counters--where-they-live) |
| Dedup window | 10 days | **Cited** — `D2-MUST` #3; the floor under event retention | [§ 6.7](#67-retention-and-purge) |
| Spool residency (the chain's lower bound) | 8 days | **Cited** — D1 § 11.3 | [§ 6.7](#67-retention-and-purge) |
| **Event retention** | **14 days** | **Derived** — the 10-day dedup floor plus a 4-day margin, the margin being the hourly purge job's failure budget: it can be dead four days before the guarantee is at risk, and a four-day outage of an hourly job is ~96 missed runs | [§ 6.7](#67-retention-and-purge) |
| Projection and transition retention | 14 days | **Derived** — same number, one home: a closed fact must not outlive, or predecease, the log it was derived from, or a rebuild answers differently from the live fold | [§ 6.7](#67-retention-and-purge) |
| Sweep cadence | 15 s | **Derived** — 5 % of the tightest deadline any time-derived transition has (the 300 s `stale` threshold), at 5,760 passes/day of two indexed range scans | [§ 2.1](#21-processes) |
| `fold_lag` badge | 60 s | **Derived** — one heartbeat interval (D1 § 9.1): a seat a whole heartbeat behind in derivation has certainly missed an input, so the badge cannot fire on a healthy pass. Healthy value is ~1 s | [§ 2.3](#23-a-frozen-fold-is-the-dangerous-degradation) |
| `fleet.fold = stalled` | 300 s | **Derived** — the `stale` threshold reused, so transport silence and derivation silence become visible at the same age and are comparable in one unit | [§ 2.3](#23-a-frozen-fold-is-the-dangerous-degradation) |
| Fold batch size | 500 events | **Derived** — ~2.5 D1 batches (200-event cap), a few ms of row locks per transaction; ~70 min of one seat's ceiling traffic, so it binds only during a drain | [§ 6.5](#65-the-fold) |
| Fold claim size | 8 seats | **Chosen** — small enough that a second worker partitions cleanly under `SKIP LOCKED`, large enough that a four-seat fleet is one claim | [§ 6.5](#65-the-fold) |
| Purge batch / budget | 5,000 rows / 60 s | **Chosen** — bounded DELETEs keep the transaction and the binlog small; the wall-clock budget makes a purge that cannot keep up fall behind *visibly* (`purge_backlog_rows`) instead of holding a long transaction | [§ 6.7](#67-retention-and-purge) |
| `events` table-size alarm | 20 GB | **Derived** — ~2.9× the 50-seat 14-day figure below, so it can only fire on a fleet far larger than planned or a long-dead purge | [§ 6.7](#67-retention-and-purge) |
| `events` row cost | **~732 B** | **Derived** — 479 B clustered (449 B of columns + ~30 B header) × 1.05, plus 153 B of three secondary index entries × 1.5 for fill and overhead | [§ 6.8](#68-sizing) |
| Render-state changes per seat-day | **~1,400** | **Derived** — ~1,200 turn boundaries (each `turn.start` enters `working`, each `turn.end` leaves it) + ~200 attention edges + a handful of staleness and ceiling transitions. **Not** the 8,940 delta rate: a transition row is written only on a `render_state` change and the two are different populations ([§ 6.5](#65-the-fold)) | [§ 6.8](#68-sizing) |
| Store per seat-day | **~9.7 MB** | **Derived** — 7.6 MB of `events` (10,420 × 732 B) + 2.1 MB of projections (calls 3,000 × 300 B, transitions 1,400 × 160 B, other 1,740 × 200 B, × 1.4) | [§ 6.8](#68-sizing) |
| Store per seat, 14 days | **~136 MB** | **Derived** — × 14 | [§ 6.8](#68-sizing) |
| Store, 4 / 12 / 50 seats | **0.54 / 1.6 / 6.8 GB** | **Derived** — × seat count. Inherits D1's volume *estimate*; re-derived from the first week of live data | [§ 6.8](#68-sizing) |
| Seat-state object | **1,807 B** typical, **5,529 B** worst | **Measured** — the [§ 8.2.2](#822-worked-snapshot) snapshot's seat object and the `patch` of [§ 8.3.2](#832-worked-worst-case-delta), each serialized with no insignificant whitespace. Both artefacts are published in this document precisely so the figures are reproducible, and `tools/design/verify-fleet-state.py` re-derives them | [§ 8.2.1](#821-the-seat-state-object) |
| Fleet snapshot | **7.5 KB** (4 seats) … **91 KB** (50 seats) | **Measured** — 302 B envelope + n × the above | [§ 8.2.1](#821-the-seat-state-object) |
| Snapshot pagination trigger | 200 seats (~362 KB) | **Derived** — stated as the trigger, deliberately not built for a four-seat fleet | [§ 8.2.1](#821-the-seat-state-object) |
| Delta message | **323 B** typical, **6,112 B** worst | **Measured** — [§ 8.3.1](#831-worked-delta) and [§ 8.3.2](#832-worked-worst-case-delta) serialized | [§ 8.3](#83-the-websocket-delta-feed) |
| Feed traffic per connected client | **~1.6 KiB/s** at 50 seats | **Derived** — 5.17 msg/s × the measured 323 B typical delta = 1,670 B/s | [§ 8.3](#83-the-websocket-delta-feed) |
| Worst-case integer magnitude | 2⁵³−1 (16 digits) | **Chosen** — the JS-safe ceiling D1 § 6.0 admits, used for every integer whose own bound is open, so the worst-case object cannot be falsified by a fleet that outlives its estimates | [§ 8.2.1](#821-the-seat-state-object) |
| Feed message bound | 8 KiB | **Chosen** — 1.34× the measured worst case, so a conforming message cannot breach it and a future field addition that would breaks a test rather than a client. Reverb's own configured maximum is **UNVERIFIED** (host not provisioned; closure: read the deployed `config/reverb.php`) and 8 KiB sits far below any plausible value | [§ 8.3](#83-the-websocket-delta-feed) |
| `subagents` array cap | 8, with `subagents_open` carrying the truth | **Chosen** — D1's index cap admits 64 open calls and a side table rendering 64 interns is a list. The cap is what holds the worst-case object inside the message bound | [§ 8.2.1](#821-the-seat-state-object) |
| Delta coalescing tick | 250 ms | **Derived** — below the ~300 ms at which a human notices added latency, which is D1's own basis for its hook budget and the same order as the status-line debounce D1 records; bounds one seat at 4 msg/s | [§ 8.3](#83-the-websocket-delta-feed) |
| Delta volume | **8,940/seat/day = 0.103 msg/s/seat**; 5.2 msg/s at 50 seats | **Derived** — from D1 § 6.0's kind-table ranges: 6,000 tool + 1,200 turn + 1,440 context + 120 subagent + 80 session + 100 attention. Ordinary heartbeats are excluded and that exclusion is a design rule, not an omission; the edge-triggered deltas that are not events at all — [§ 6.5](#65-the-fold)'s heartbeat exceptions and the sweeper's own transitions — are single digits a seat-day and this event count does not carry them | [§ 8.3](#83-the-websocket-delta-feed) |
| Feed heartbeat | 15 s, dead at 45 s | **Derived** — the same assert-and-alarm shape as D1's 60 s/300 s heartbeat pair, scaled to a channel whose round trip is milliseconds; 3× is the same multiple D1's flusher-lock staleness uses against its own cadence | [§ 8.3](#83-the-websocket-delta-feed) |
| Feed outbound queue | 256 messages / 512 KiB | **Derived** — 256 messages is ~49 s of a 50-seat fleet's ceiling traffic: long enough that an ordinary hiccup drains, short enough that a wedged client is noticed within a minute | [§ 8.5](#85-gaps-reconnect-and-why-state_version-is-not-seq) |
| `fleet.sweep = stalled` | 60 s | **Derived** — four sweep passes at the 15 s cadence: one missed pass is a hiccup, four is a dead daemon, and the fleet object needs a threshold it can render ([§ 8.2.4](#824-the-fleet-health-object)) | [§ 2.2](#22-fail-posture-per-path) |
| Fold visibility lag | 2 s | **Derived** — ~3 orders of magnitude above the ingest transaction (one multi-row `INSERT` of ≤ 200 events plus one `batches` row), which is what makes "the transaction that assigned this id has finished" true rather than hoped; an ingest transaction past 2 s is a slow-query alarm in its own right | [§ 6.5](#65-the-fold) |
| Compaction ceiling | 15 min | **Derived** — the ordinary orphan ceiling reused, because a compaction is a harness operation of the same order as a tool call and reusing the number keeps one home for it | [§ 4.6](#46-every-open-fact-has-a-ceiling) |
| Leaving-live clear of `stalled` / `blocked` | 300 s | **Cited** — the `stale` threshold, reused because D1 words both clauses as *"the seat leaving live state (`stale` at 300 s…)"*; it is not a second number | [§ 4.5](#45-link-states) |
| Retired-seat render window | 14 days | **Derived** — the retention window, one home: a retired seat stays visible for exactly as long as the events that explain it | [§ 4.10](#410-retirement-is-a-rendered-state) |
| REST poll fallback (feed down) | 10 s | **Cited** — D1's flush interval, so a polled floor is no staler than its own input cadence | [§ 2.2](#22-fail-posture-per-path) |
| Read token entropy / storage | 256 bits / SHA-256 | **Cited** — D1 § 3.3 | [§ 9](#9-read-side-authentication) |
| Read token expiry | 90 days | **Chosen** — quarterly rotation; a forgotten token dies. Multiple active tokens make rotation issue-then-revoke with no overlap to specify | [§ 9](#9-read-side-authentication) |
| Rate limit, read token | 120 req/min | **Cited** — D1's per-seat request ceiling, reused so the fleet has one number; ~120× the watchdog's real cadence | [§ 9](#9-read-side-authentication) |
| Rate limit, browser session | 600 req/min | **Chosen** — ~10 req/s, above any human interaction and far below anything the store notices | [§ 9](#9-read-side-authentication) |
| Task-title tier staleness | 30 min | **Chosen, provisional** — a card title older than half an hour is likely describing the previous task; re-derived once the board producer exists and its poll cadence is known ([§ 14](#14-open-questions-for-the-review-loop) item 3) | [§ 4.9](#49-the-task-title-merge-and-what-is-not-specified-here) |
| Predicate criteria | constant-`false` over ≥ 5,760/7 d (`seat_live`), constant over ≥ 5,760/7 d (`activity_recent`), 0 % or 100 % over ≥ 200/24 h (`turn_clean`), ≥ 5 % server-closed over ≥ 1,000/24 h (`call_closed_by_wire`), any server ceiling in 24 h and constant-server over ≥ 10 (`attention_resolved_by_wire`), constant-`false` for 2 consecutive passes (`ingest_receiving`, `fold_current`) — one clause per row of [§ 5](#5-server-side-predicates-and-their-controls), transcribed rather than summarised | **Chosen provisionally** — each is reachable by its own predicate's evaluation rate, which is the property review must preserve; every one is re-picked from the first week of live per-predicate counts | [§ 5](#5-server-side-predicates-and-their-controls) |
| MySQL version floor | 8.0.12 | **Cited** — DOCS-CITED (MySQL 8.0 manual) for `SKIP LOCKED`, `ALGORITHM=INSTANT`, `JSON`, `DATETIME(3)`; **verified at provisioning** | [§ 6.1](#61-deployment-posture) |
| Redis test databases | 11 / 10 | **Chosen** — against the fleet's published claims (14/15, 13/12, 15/14, 2/3 on roundtable #349) and clear of the `0`/`1` defaults every unpinned seat gets | [§ 6.2](#62-database-names-pinned-and-published) |

**Three figures rest on an estimate and say so at their definition:** everything derived from D1's
10,420 events/seat/day ceiling (the store sizing, the delta volume, the fold's batch-size reasoning).
Each names what re-derives it — the first week of live data — and each has ≥ 2× headroom in the
direction that fails safely.

**Tool-checked versus hand-verified.** `tools/design/verify-fleet-state.py` is **this document's**
verifier and it ships with this change. It is a third, separate script rather than an extension of
D1's two: `verify-event-schema.py` and `verify-harness-facts.py` hard-code
`docs/design/EVENT-SCHEMA.md` and are **not modified here**, because widening a working guard to a
second document is a change to that guard and belongs in its own round. The split below is what the
tool actually re-derives, stated so a reader can tell a checked figure from a read one:

| Check | What the tool re-derives | Status |
|---|---|---|
| **Byte figures** — the seven rows of [§ 8.2.1](#821-the-seat-state-object)'s size table and their restatements here | `json.loads` + `json.dumps(separators)` + `len` over all three published blocks; the worst case is measured from [§ 8.3.2](#832-worked-worst-case-delta), which exists so it can be | **tool-checked** |
| **Field table ↔ worked examples, both directions** | the 73 field names of [§ 8.2.1](#821-the-seat-state-object) against the flattened paths of every seat object in the document, set-differenced each way | **tool-checked** |
| **DDL `ENUM` member reachability** | every member of every `ENUM` in [§ 6.4](#64-ddl), counted across the rest of the file; a member occurring only in its own declaration is a member no path can produce | **tool-checked** |
| **Cross-document enum containment** | D2's `abort_reason` / `close_source` / `resolution` / `resolution_source` extension sets against D1's declared sets, with the counts stated in the DDL comments | **tool-checked** |
| **Feed message-type closure** | every `t` value named anywhere against [§ 8.3](#83-the-websocket-delta-feed)'s table | **tool-checked** |
| **Counter closure** | every counter named in prose or an AT against [§ 7.1](#71-d1s-server-side-counters--where-they-live) / [§ 7.2](#72-this-planes-own-counters-and-badges), including the `Stored` and `Exposed` columns; that a counter naming **fleet health** as its surface is carried by [§ 8.2.4](#824-the-fleet-health-object)'s `counters` member, because a surface that does not carry the thing is no surface; D1 § 12.7's population against § 7.1; and the **count** of that population as this document states it in prose | **tool-checked** |
| **[§ 12](#12-every-number-and-where-it-comes-from) ↔ definition site** | each row's number as a **whole numeric token** at the section it cites, with its unit beside it where this table gives one; then the same match **perturbed** to prove it can fail for that row | **tool-checked**, with its own residue printed — not "checked" flatly, because an earlier version of this check was a substring search over the whole cited section and could not see a two-digit value change at all. Numbers for which some other value would also have matched — one- and two-digit values in sections full of them — are listed individually on every run rather than counted as passes |
| **Appendix A** | its three stated counts against its own row counts; the marker/semantic **split** re-derived per row; and every D1 section containing a literal `D2` against the sections Appendix A cites **from a D1-attributed position only** — the D1-source column, an explicit `D1 §`, or a link into D1, never the "Discharged in" column, whose `§ n` links are this document's own sections and once satisfied the requirement by collision | **tool-checked**, with a stated limit: the tool cannot re-derive a **semantic** obligation D1 addresses to a consumer without the marker (row S29 is one), so it checks the marker population, re-derives the size of the semantic half (fourteen rows) and reports that sweep as manual |
| **Fixture arity** | a fixture described as "§ N's *k* events" against § N's own row count | **tool-checked** |
| **Retention chain** `8 < 10 < 14` | the three numbers extracted from their one home each, as an inequality | **tool-checked** |
| **§ 10's trace** | delta count and transition count re-derived from the table's own columns | **tool-checked** |
| The store sizing model (row costs, index entry sizes) | — | **hand-verified**: it needs a provisioned host to measure, and [§ 6.8](#68-sizing) says so |
| Every **Cited** row's agreement with D1 | — | **hand-verified**: the tool checks the number's presence at its D2 home, not its truth at D1's |

**What the tool deliberately does not do.** It does not check prose for meaning, it does not verify
MySQL behaviour (no host exists), and it does not re-derive the D1 obligations that carry no marker.
Where a guard class could not be mechanised without implementing the system, the tool implements the
nearest checkable invariant and **says so in its own output** rather than reporting a clean over a
population it never measured.

---

## 13. Decisions taken, revisable at review

This document contains no placeholders and no deferred decisions. Where a call was genuinely
contestable it was **made**, and it is listed here with the alternative and the cost of being wrong, so
review can reverse it deliberately rather than discover it later.

| # | Decision | Alternative considered | Why this one | Cost if wrong |
|---|---|---|---|---|
| 1 | **State is a pure function of stored facts, recomputed on every fold pass** | a stored state machine with explicit transitions | A machine has states that can be entered and not left — the one-way trapdoor D1 had to fix twice (`blocked`, `stalled`). A function over facts cannot: bound the facts and the state is bounded. It also makes replay meaningful and `rebuild == fold` checkable | a recomputation per applied event. Measured cost is one function over ~8 in-memory values; if a much larger fleet makes it matter, the function is memoizable on the facts it reads |
| 2 | **Two axes (`link_state`, `activity_state`) plus a server-computed `render_state`** | one scalar state | `D2-MUST` #2 forbids `stale` rendering as `idle`; with one scalar the collapse happens at write time and the answer to "what was it doing when it went dark" is destroyed. Computing `render_state` on the server keeps the precedence in one home rather than in D3's render switch | three fields instead of one on the wire (~60 B/seat), and D3 must be told to render `render_state` rather than inventing its own collapse |
| 3 | **`blocked` outranks `working`** | `working` outranks `blocked` | A permission prompt fires for a call that is already open, so both facts are true at once and **D1 states no precedence**. Under the alternative, *blocked* is unreachable on the exact path that produces it, and `docs/PLAN.md § 7`'s required state never renders | a seat with an open call and a stale unresolved attention request renders `blocked` rather than `working` — bounded by the 60-minute ceiling, and `attention_ceiling_expired` measures how often it happens |
| 4 | **Derivation is asynchronous, behind a per-seat cursor** | derive inside the ingest transaction | [D1 § 4.6](EVENT-SCHEMA.md#46-successful-response) already decided it: `202` means accepted for asynchronous processing. Synchronous derivation also puts a fold bug on the ingest's critical path, where it becomes a `5xx` for a seat whose data is fine | fold lag, which is why `fold_lag_ms` is a first-class rendered quantity and [AT-D2-21](#at-d2-21-a-frozen-fold-cannot-look-healthy) exists |
| 5 | **Per-seat fold cursors, not one global cursor** | one global cursor over `events.id` | A global cursor makes one unprojectable event freeze the whole fleet's derivation — "one bad batch wedges the stream", which D1 refuses in the spool for the same reason | eight cursors to advance instead of one, and a `SKIP LOCKED` claim; the parallelism is free rather than a cost |
| 6 | **Visit in `events.id` order behind a 2 s visibility lag, apply with `(event_time, seq_epoch, seq)` last-write-wins** | order the cursor by `(seq_epoch, seq)` | `seq` can have permanent holes ([D1 § 10.2](EVENT-SCHEMA.md#102-ordering-seq-and-gap-detection)), so a cursor over it can wait forever for an event that will never arrive. `events.id` is **not** gapless and the cursor does not need it to be — what it needs is that no row at or below it becomes visible afterwards, which the lag buys for the reading advance and a guarded write buys for the purged-window one ([§ 6.5](#65-the-fold)) | three `applied_*` columns on every projection row (~40 B), and derivation is ≥ 2 s behind the wire |
| 7 | **The comparator includes `seq_epoch`** | `(event_time, seq)` exactly as `D2-MUST` #4 words it | `seq` restarts at a new epoch, so the literal two-part key is not a total order across a reset. The three-part key reduces to it whenever the epoch is constant, which is every comparison but one | none functionally; it is a wording divergence from D1 and is filed as such ([§ 14](#14-open-questions-for-the-review-loop) item 4) rather than left to be discovered |
| 8 | **The feed's ordering key is a server-minted `state_version`, not `(seq_epoch, seq)`** | order deltas by the wire key | State transitions are also minted by rules with **no wire event** — orphan closes, staleness, ceilings, quiescence. Those carry no `seq` and there is no honest value to invent. A `seq`-ordered feed could not sequence precisely the transitions that fire when a seat goes quiet | two ordering keys in the system, which is why [§ 8.5](#85-gaps-reconnect-and-why-state_version-is-not-seq) states the division explicitly and the snapshot carries the wire key as provenance |
| 9 | **Resync per seat on a gap; no server-side delta replay buffer** | keep a bounded per-connection replay buffer and re-send the missing range | A replay buffer is a second stateful copy of recent history whose correctness must be maintained against the store, to save a request that costs less than the buffer's own memory (~1.7 KB for one seat) | a gapped client makes one extra HTTP request. `feed_gap_detected` measures how often |
| 10 | **The live feed is browser-only; machine consumers poll REST** | authenticate the socket with an `mzr_` token too | A long-lived socket needs revocation *on an open connection*, which is a mechanism nobody has asked for; the known machine consumer's decision cadence is minutes | a future consumer needing sub-second fleet state gets polling latency; reversing this means specifying socket re-authorization, not opening a port |
| 11 | **REST carries the compatibility discipline; the WebSocket does not** | apply `docs/VERSIONING.md § Wire compatibility` to both | Only REST has a consumer that upgrades on someone else's schedule. An N/N-1 window on a channel whose two ends ship in one act is an obligation nobody can exercise, and therefore one nobody maintains | if the delta feed ever gains an independent consumer this is wrong — which is a checkable condition, stated in [§ 8.1](#81-two-surfaces-two-compatibility-postures) as the trigger |
| 12 | **`events` is not partitioned** | RANGE partition on `received_at` for O(1) purge by `DROP PARTITION` | MySQL requires every unique key to contain every partitioning column, so `uq_dedup` would become `(seat_ref, event_id, received_at)` — under which a re-sent event on a later day no longer conflicts and **`D2-MUST` #3's dedup silently stops working**. A cheap purge is not worth the guarantee it would break | purge is bounded `DELETE`s with a wall-clock budget instead, and `purge_backlog_rows` says when that stops keeping up |
| 13 | **`data` stays opaque JSON; the fold projects every field the state model reads** | generated/stored columns or functional indexes over `data` | One home per fact, and a projection change needs no `ALTER` on the largest table — just a rebuild. Indexing into a JSON column would make the log's shape part of the query plan | the projections must be kept in step with D1's field tables, which is what [AT-D2-10](#at-d2-10-rebuild-equals-fold) and the fixtures are for |
| 14 | **ULIDs stored as `CHAR(26) ascii_bin`** | `BINARY(16)` | 10 B/row cheaper is ~0.1 MB/seat/day against every diagnostic query needing a conversion function, on a store whose total is single-digit gigabytes. Legibility wins where storage is not scarce | ~1.4 % of the store |
| 15 | **Integer surrogate keys (`seat_ref`) on every hot table** | natural keys (`install_id`, `seat_id`) everywhere | ~76 B against 4 on every event row *and* in every index entry — the one place in this schema where the storage argument actually binds | one join to render a seat name, on a table with tens of rows |
| 16 | **No materialized activity table; the timeline is a bounded query over `events`** | a projection table for the drill-down timeline | It would be a second copy of rows already retained for 14 days, with its own retention, backfill and opportunity to disagree with the log | one indexed range scan per drill-down open, on an index the purge needs anyway |
| 17 | **`seat_state_transitions` exists, and is not a duplicate** | derive "why did it change" from `events` on demand | The transition row records **which rule fired** — including the rules that have no event (orphan, ceiling, sweep) — which the log does not contain. It is new information, and it is what the acceptance tests assert against | ~0.31 MB/seat/day (1,400 render changes × 160 B × 1.4) and a 14-day retention |
| 18 | **The server closes facts D1 leaves open: turns at session close, everything at `offline`** | leave them to the wire and render whatever arrives | D1 bounds calls and attention; it does not state whether the flusher's `inferred_silence` close carries a `turn.end`, and an offline seat's facts have no wire-side ceiling at all. An unbounded open fact renders `working` forever | if D1 later states that the flusher does emit a `turn.end`, this server close becomes redundant — harmlessly, because the wire event and the server close converge on the same row through the same idempotent upsert |
| 19 | **The attention ceiling fires at exactly 60 min from `event_time`, and a late `attention.resolved` relabels without reopening** | 65 min (60 + a delivery allowance) | `D2-MUST` #5 says *never longer than* 60 minutes. Firing at 65 would breach the constraint to buy a tidier counter; firing at 60 on the reporter's own clock basis means the two timers agree, and D1's own late-completion doctrine ("an observation overrides an inference") covers the ordering | `attention_ceiling_expired` fires on merely-slow resolutions; `attention_ceiling_overridden` is the counter that distinguishes slow from lost |
| 20 | **Orphan ceilings are measured from `received_at`; the attention ceiling from `event_time`** | one clock for both | A timeout is a claim about how long *we* waited, so a skewed seat must not expire its calls early — but the attention ceiling competes with a reporter-side timer on the seat's clock, and using a different basis would make the server win every race on a skewed seat | the two clocks differ by the skew, which is bounded and badged at ±120 s; both choices are stated per ceiling in [§ 4.7](#47-which-clock-each-ceiling-is-measured-from) rather than inherited |
| 21 | **The ceiling is materialized on the row at open time** | compute it in the sweeper's `WHERE` clause from a constant | An indexed range scan instead of a full scan, and — the real reason — **changing a constant later does not retroactively re-date history**, so `late_completion` stays interpretable across the change | one column per bounded fact |
| 22 | **The quiet age is computed from `activity.last_received_at`, not from `event_time`** | the seat's own clock, which is what the seat actually experienced | A skewed seat renders "last active in 3 hours" ([D1 § 10.1](EVENT-SCHEMA.md#101-two-clocks-and-which-is-authoritative-for-what) names that outcome) | the age **understates** true quiet time by the transit lag — ≤ 70 s on a healthy seat, unbounded while `catching_up`, which is why `catching_up` outranks the activity state. Both timestamps ride the wire so a consumer can compute the other reading |
| 23 | **An ordinary heartbeat emits no delta** — one that moves nothing but the six `delivery` bookkeeping members and `reporter.uptime_s` — which is enforced by naming the version-bearing field set as a subtraction ([§ 6.5](#65-the-fold)) rather than as "any field of the object" | a delta per heartbeat so clients always hold fresh ages | 1,440/seat/day of messages carrying no rendered change — a 16 % traffic increase for nothing. Clients compute ages from `server_time` plus stored timestamps instead, and every quantity rendered from an excluded member is one that cannot be moving when it is read ([§ 6.5](#65-the-fold)). Stated for the *ordinary* heartbeat because the subtraction is closed both ways: a heartbeat that carries **news** does move a version-bearing member and does emit — edge-triggered, single digits a seat-day, and [§ 6.5](#65-the-fold) is where that set is named, once, rather than enumerated again here | a client that ignores `feed.heartbeat`'s `server_time` renders ages against its own clock; the protocol requires it not to, and [§ 3.3](#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by) says why |
| 24 | **The reporter's `degraded` array is rendered as "since reporter start"** | render it as a current condition | It is sticky until the flusher restarts, because its counters are monotonic since flusher start ([D1 § 6.14](EVENT-SCHEMA.md#614-reporterheartbeat)). Rendering a sticky badge as current makes a seat that had one bad minute look permanently broken | a genuinely-recovered condition still shows until the flusher restarts. [§ 14](#14-open-questions-for-the-review-loop) item 5 asks D1 whether a windowed variant is wanted |
| 25 | **The task-title merge is specified; the producers of tiers 1 and 2 are not** | specify the GitHub/board ingest here too, or specify nothing | The merge is a state-model question and is D2's; the producers are a separate plane with their own auth, cadence and failure modes. And **the proposal's three-tier status fallback is not in this repo** — writing tiers from the phrase alone would put a guessed rule in a contract | an implementer building today gets tier 3 only, which needs nothing new and renders correctly. [§ 14](#14-open-questions-for-the-review-loop) item 3 is the unblock |
| 26 | **Database names and Redis databases are pinned, paired and published in this document** | pin them in `phpunit.xml` at build time, as every seat believed it had already done | Roundtable #349 measured three separate mechanisms that leave a pin looking correct while it resolves wrong: an exported variable, `force="true"` without `<server>`, and a `_URL` key replacing the parts. Publishing the values is what let two seats discover a mutual collision in four minutes | the claimed values (`mezzanine`, `mezzanine_sandbox`, `mezzanine_test`, Redis 11/10) constrain other seats not to take them, which is the point of publishing |
| 27 | **The guard asserts the resolved value (`config()`), not the declaration** | assert the `phpunit.xml` contents | All three mechanisms above leave the declaration correct. Reading `getenv()` would have shown `force="true"` "working" in the measurement that disproved it | one extra bootstrap assertion, and a hostile-export run in CI |
| 28 | **`DB_CONNECTION` is deliberately not forced** | force every DB variable | Forcing a variable a CI matrix exports to select a backend silently re-runs every leg on the wrong backend: green, testing nothing. Nothing in this repo does that today, and the absence is commented as load-bearing so nobody "fixes" it | if a future matrix does select by export, this comment is what stops the next person forcing it |
| 29 | **The read plane exposes no field D1 did not send** | enrich the snapshot with server-side context (host names, IPs, token identifiers) | D1 minimizes at the reporter so a secret cannot transit even if the server misbehaves; a read surface that adds fields the wire never carried would reintroduce exactly what that buys | the drill-down cannot show anything the seat did not report, which is the correct constraint |
| 30 | **`seat_predicates` uses a reserved `seat_ref = 0` for fleet-wide predicates and carries no foreign key** | create a synthetic seat row for the fleet | The table's population is *predicates*, not seats. A synthetic seat row would be a desk on the floor that is not a seat, and every query over `seats` would have to exclude it | one documented sentinel, stated at the DDL |
| 31 | **Retention purges by bounded `DELETE` with a wall-clock budget** | delete everything past the boundary in one statement per pass | A long transaction on the largest table blocks the ingest's writes and inflates the binlog; a budget makes a purge that cannot keep up fall behind *visibly* | `purge_backlog_rows` must be watched; a purge permanently behind is a real signal about volume |
| 32 | **`fold_lag_ms` is computed from a basis the ingest and the fold write separately, not stored by the fold** | one `fold_lag_ms` column the fold pass maintains | An instrument written only by the process it measures dies with it: a paused fold leaves the number frozen, so the badge never fires and the one degradation this design calls "could look healthy" stays invisible. Two writers means the reader can always tell them apart ([§ 2.3](#23-a-frozen-fold-is-the-dangerous-degradation)). The ingest also **seeds** the cursor clock on the seat's first event, which is what makes the second branch total rather than null on a never-folded seat | two extra columns on `seat_state`, one one-shot conditional write in the ingest transaction, and an expression in the snapshot query — all on the seat's own row, so the one-query budget is unaffected |
| 33 | **Leaving `live` CLEARS `stalled` and `blocked`, and only MASKS `idle`** | mask all three at the render layer, or clear all three | D1 names leaving-live as a clear for `stalled` and `blocked` specifically, and both are claims that the seat is *currently* refused or *currently* waiting — a returning seat must not re-assert them from five-minute-old evidence. `idle` is a claim about something that already happened, which silence does not falsify, and [AT-D2-3](#at-d2-3-stale-offline-and-disabled-are-rendered-never-idle) requires it preserved | one sweeper rule, one `sessions.stalled_cleared_by` column and two `unknown_reason` / `resolution` members; the asymmetry has to be stated or an implementer will make all three the same |
| 34 | **A clean turn's `idle` survives its session's `session.end`** | `session.end` resets the seat to `unknown` | The `idle` was minted by the `turn.end`, which is `D2-MUST` #1's only permitted minter; a `session.end` changes no fact rule 4 reads. Replacing a positive observation ("the agent said it finished") with an absence of one is a loss of information, not a gain in caution | `L` is seat-scoped and outlives its session, which has to be stated in the fact list or two sections disagree. D1 is silent, so it is filed ([§ 14](#14-open-questions-for-the-review-loop) item 10) |
| 35 | **`retired` is a `render_state` member, and a retired seat stays in the snapshot for 14 days** | drop retired seats from the read surfaces immediately | [§ 4.5](#45-link-states) already forbade a seat "vanishing between two refreshes", and a promised rendered state with no member, no field and no window is a promise D3 cannot keep. Keeping it for the retention window means the seat is visible for as long as the events that explain it | a tenth `render_state` member and a four-field object on the wire, plus a read filter every fleet query carries |
| 36 | **A server counter never writes a member of D1's `degraded` array** | follow D1 § 12.7's `seq_gap` row literally and raise `lossy` | D1 contradicts itself here — § 9.3 declares `seq_gap` a server badge and *not* a member, § 12.7 and § 10.2 say the server renders the seat `lossy` — and § 9.3's reading is the one with a mechanism: `lossy` means the reporter discarded events *and counted them*, so a server-raised `lossy` with a zero counter beside it is a badge contradicting its own number | D2 carries its own `seq_gap` badge, so a consumer sees two members where D1's text implies one; filed as an amendment need ([§ 14](#14-open-questions-for-the-review-loop) item 12) |
| 37 | **A D2 verifier ships with this document** | leave it to the build phase, as an earlier draft of [§ 14](#14-open-questions-for-the-review-loop) item 8 recommended | The review that produced this revision found four blockers and nineteen majors, of which ten were single-surface edits to multi-surface facts — a class a set-difference catches in milliseconds and a reader catches on the third pass, if ever. Deferring the guard until after the facts had been fixed by hand would be deferring it past the moment it was most needed | one more script to keep true, and every figure in this document is now a figure a change must move in all its homes at once |

---

## 14. Open questions for the review loop

Each names what it blocks, what this document does in the meantime, and what would close it. Items 1,
2, 4, 5, 10, 11 and 12 are **D1 amendment needs**: this document does not edit D1
([§ 1.3](#13-the-boundary-stated-as-a-rule)), so they are written here as requests. In every one of the
seven, D2 states a well-defined rule of its own and says which reading it took — an amendment need is
never a reason to leave two readings live.

1. **⇢ D1 — does the flusher's `inferred_silence` `session.end` carry a `turn.end`?**
   D1 § 6.0's kind table lists `turn.end` as hook-emitted, and § 6.2's turn-closing reap is on the
   `SessionEnd` **hook** path. The flusher's 90-minute close is neither. **Blocks:** nothing — this
   document closes the turn server-side and derives `unknown` / `session_closed_turn_open`
   ([§ 4.6.1](#461-the-turn-has-no-timer-of-its-own)). **Closes it:** one sentence in D1 § 6.2 either
   way. If the flusher does emit one, the server close becomes a harmless no-op through the same
   idempotent upsert.

2. **⇢ D1 — which clock does the orphan timeout run on?**
   § 8.6 says "record `started_at = event_time`" and § 12.5 gives the ceilings, but neither says whether
   the 15/60-minute clock is the seat's or the server's. On a seat skewed +10 minutes the two readings
   differ by the whole ordinary ceiling. **Blocks:** nothing — [§ 4.7](#47-which-clock-each-ceiling-is-measured-from)
   uses `received_at` and states why. **Closes it:** a clock named on D1 § 12.5's row.

3. **⇢ Review / operator — the proposal's three-tier status fallback, and who designs the board and
   GitHub producers.**
   `docs/PLAN.md § 2` assigns D2 a three-source merge and names a "three-tier status fallback from the
   proposal"; the proposal is not in this repo and this document **does not invent its tiers**
   ([§ 4.9](#49-the-task-title-merge-and-what-is-not-specified-here)). **Blocks:** tiers 1 and 2 of the
   task title — a floor built today shows telemetry-derived titles only. **Closes it:** the proposal's
   text, plus a ruling on whether the two producers are a D2 addendum or their own card.

4. **⇢ D1 — `D2-MUST` #4's ordering key across a `seq_epoch` change.**
   The key is written `(event_time, seq)`; `seq` restarts at a new epoch, so the two-part key is not
   total across a reset. This document uses `(event_time, seq_epoch, seq)`, which reduces to D1's
   whenever the epoch is constant ([§ 6.5](#65-the-fold)). **Blocks:** nothing. **Closes it:** adding
   `seq_epoch` to the key in D1 § 12.6 row 4, or a statement that the tie is not worth ordering.

5. **⇢ D1 — is `reporter.heartbeat.degraded` meant to be sticky?**
   Its members are raised by counters that are monotonic since flusher start, so one dropped event
   badges `lossy` for the life of that flusher. This document renders it as "since reporter start" with
   `uptime_s` beside it ([§ 7.3](#73-how-the-reporters-own-counters-are-handled)). **Blocks:** nothing.
   **Closes it:** either a confirmation that sticky-until-restart is intended, or a windowed variant
   (a `degraded_since` per member, or counters reported as deltas) — the second is a wire change and
   therefore a D1 decision, not a D2 one.

6. **⇢ Operator — backups for the store, and where the sandbox's MySQL lives.**
   [§ 6.10](#610-durability-posture) argues that backups are an operational choice rather than a
   correctness requirement, because everything but `events` is rebuildable and `events` expires in 14
   days. That is a recommendation, not a ruling. Separately: prod's store is on a dedicated host
   (D-15), but D-13's **sandbox** instance may land on a shared box — if it does, the pinned names of
   [§ 6.2](#62-database-names-pinned-and-published) are load-bearing rather than precautionary.
   **Blocks:** provisioning (D-15). **Closes it:** two operator answers.

7. **⇢ Operator — is fleet-read all-or-nothing?**
   Today any MFA-authenticated user and any `fleet_read` token sees every install
   ([§ 9](#9-read-side-authentication)). The channel and endpoint shapes are per-install so an ACL has
   somewhere to attach. **Blocks:** nothing while every install belongs to one operator.
   **Closes it:** a ruling, ideally before a second organisation's install reports in.

8. **✅ CLOSED — a D2 verifier exists and ships with this document.**
   `tools/design/verify-fleet-state.py` mechanises **eleven** guard classes (G1–G11), listed with their
   status in [§ 12](#12-every-number-and-where-it-comes-from); it is a separate script and D1's two
   verifiers are unmodified. It runs green on this document, and every check in it has been watched
   failing against a planted defect.

   **That last sentence was written once before it was true, and the correction is the useful part of
   this item.** A later review planted defects against the checks rather than against the document and
   found **two that could not fail**: the [§ 12](#12-every-number-and-where-it-comes-from) check was a
   bare substring search over the cited section, so `2 s` could be restated as `7 s` and pass on the
   `7` inside "~70 minutes"; and the Appendix A check built its cited-section set from the whole row,
   so this document's own `§ 6.4` links satisfied a requirement to cite **D1** § 6.4. Both are
   repaired — the first matches a whole numeric token with its unit and then **perturbs it to prove
   the match can fail for that row**, the second reads only D1-attributed positions — and both were
   re-planted red before this sentence was rewritten. A check that has been watched failing is
   evidence; a check *described* as having been is not.

   **Two things remain open, and neither is small enough to leave implicit.** First, the obligation
   table's *semantic* population: **fourteen** of Appendix A's twenty-nine rows cite D1 sections that
   carry no `D2` marker, so they are not re-derivable by grep — the tool now re-derives that split per
   row and prints it, rather than the paragraph asserting it. Second, the
   [§ 12](#12-every-number-and-where-it-comes-from) check prints a **residue**: the numbers for which
   some other value would also have matched at the definition site, mostly one- and two-digit values
   in sections that are full of them. The residue is printed on every run instead of being folded into
   a pass count, because a count of vacuous passes reports where the searcher stopped. **Closes the
   first:** a marker convention in D1 (a `D2:` prefix on every consumer obligation), which is item 13.
   **Closes the second:** nothing cheap — it is a property of prose, and it is stated so a reader
   knows which rows the gate is holding.

9. **⇢ Review — the `subagents` cap of 8.**
   It is a rendering judgement made in a state document because it bounds the wire object
   ([§ 8.2.1](#821-the-seat-state-object)). If D3 wants a different number the cap moves and the
   worst-case byte figure moves with it — measurably now, because the worst case is a published block
   ([§ 8.3.2](#832-worked-worst-case-delta)) and each further subagent adds a **measured 263 B** —
   the block's own element, 262 B serialized, plus its comma separator — against **2,080 B** of
   spare under the 8 KiB bound. Seven more therefore fit and an eighth does not: **the cap could
   reach 15**, where the worst-case delta is 7,953 B, and at 16 it is 8,216 B, which **breaches**
   the 8,192 B bound the same sentence invokes. An earlier revision of this item offered ~16, which
   is the wrong side of the boundary it exists to locate. **Closes it:** D3's drill-down design.

10. **⇢ D1 — does a clean turn's `idle` survive its session's `session.end`?**
    D1 § 6.4 states the idle rule as a property of the `turn.end` and says nothing about what a later
    `session.end` does to the state it minted. Both readings are defensible and they differ on the most
    ordinary path there is — a seat that finishes and the operator types `/exit`. **This document says
    it survives** ([§ 4.3](#43-the-derivation-function), decision 34): the session end changes no fact
    the idle rule reads. **Blocks:** nothing. **Closes it:** one clause on D1 § 6.4's idle rule.

11. **⇢ D1 — add `mzr_` to the § 7.3 rule 3 credential regex.**
    This document mints a second Mezzanine credential prefix for the read plane
    ([§ 9](#9-read-side-authentication)); D1's known-prefix sanitizer enumerates `mzn_` only, so a read
    token appearing in a descriptor would pass that rule unredacted. Reachability is low (D1 § 7.1's
    allowlist runs first) and this is filed rather than worked around because
    [§ 1.3](#13-the-boundary-stated-as-a-rule) requires the sentence that adds a rule on top of a D1
    fact to file the gap. **Blocks:** nothing. **Closes it:** four characters in one regex.

12. **⇢ D1 — `seq_gap` raises `lossy` in § 12.7 and § 10.2, and is declared not a member in § 9.3.**
    § 9.3's member table is "the field's value set" and its closing note says `seq_gap` is a
    server-derived badge that is **not** a member; § 12.7's `seq_gap` row says "seat badge `lossy`" and
    § 10.2 says the server "renders the seat `lossy`". They cannot both hold. **This document takes
    § 9.3's reading** and raises its own `seq_gap` badge, never writing into D1's array
    ([§ 7.1](#71-d1s-server-side-counters--where-they-live), decision 36), because a server-raised
    `lossy` with `spool_dropped_events == 0` beside it is a badge contradicting the number § 9.3 says
    must be rendered with it. § 12.7's `seq_collision` and `batches_refused.<error>` rows say "renders
    degraded", which § 9.3 defines as severity prose needing a named member, and neither has one.
    **Blocks:** nothing. **Closes it:** § 12.7's consequence column naming members of § 9.3's set, or a
    sentence saying the server's badges are a separate vocabulary.

13. **⇢ D1 — a marker convention for consumer obligations.**
    [Appendix A](#appendix-a--every-d1-obligation-and-where-it-is-discharged) is re-derivable by tool
    only for the obligations D1 spells `D2`; S29 is one it addresses to "a consumer" instead, and it
    was missed by exactly the grep that finds the others — its D1 section carries the marker
    elsewhere, which is why a section-level coverage check stayed green over it.
    **Blocks:** the **fourteen-row** semantic half of the obligation table, which stays a manual
    sweep. **Closes it:** a `D2:` prefix, or a "constraining D2" note, on every consumer-addressed
    rule in D1 — on the *obligation*, not merely somewhere in its section.

---

## Appendix A — every D1 obligation, and where it is discharged

[D1 § 12.6](EVENT-SCHEMA.md#126-the-five-d2-must-constraints) carries **five numbered `D2-MUST`
constraints**. D1 also addresses this document in **twenty-nine** further places — a `D2` mention, a
"constraining D2" note, a server-side rule that only this plane can implement. All of them are
enumerated here, because an obligation a downstream document did not notice is indistinguishable from
one it declined. The two counts above and the two tables' row counts are checked against each other by
`tools/design/verify-fleet-state.py`, so a row added to a table and not to a sentence reds the gate.

**The population has two halves and only one is machine-derivable, which is stated because the
difference is where the last miss was.** **Fifteen** of the twenty-nine cite a D1 section that carries
the literal marker `D2`; the other **fourteen** cite sections that contain no `D2` anywhere, and those
obligations were found by reading D1 rather than by grepping it. Both counts are re-derived by
`tools/design/verify-fleet-state.py` on every run — from D1's own marker sections and from this table's
D1-source column — rather than counted by hand, because an earlier revision of this paragraph claimed
twenty-eight and one, and the manual remainder it understated by thirteen rows is the whole basis of
[§ 14](#14-open-questions-for-the-review-loop) item 8's scope of closure.

**What the tool checks is the coverage direction, which is not the same property**, and saying so is
the point: it verifies that every D1 section carrying the marker is cited by some row below, from a
position this document attributes to D1 — never from the "Discharged in" column, whose `§ n` links are
D2's own sections and which used to satisfy the requirement by collision. It does **not** verify that a
row was findable by grep. **S29 is the case that shows the gap.** Its obligation line carries no marker
— D1 § 6.2 addresses it to "a consumer" — even though § 6.2 elsewhere carries one, so the grep that
finds the others walked past it three times while the section-level check stayed green.
[§ 14](#14-open-questions-for-the-review-loop) item 13 asks D1 for a marker convention on the
*obligation* rather than the section, which is what would close it; until then the semantic half is a
manual sweep of fourteen rows and the tool says so in its output rather than reporting a clean over it.

### The five numbered constraints

| # | Obligation | Discharged in | Tested by |
|---|---|---|---|
| **1** | *(D1 § 12.6, restated at § 6.4; the worked flow that exercises it is D1 § 8.7, replayed at [§ 10](#10-worked-example-the-clear-trace-folded-end-to-end))* **Idle only from `turn.end(stop_hook, aborted_call_ids == [])`**; every other ending is `unknown`, except `api_error` → `stalled` carrying `api_error_type`; `failed` does not block idle, `interrupted` does; `stalled` clears on the next `turn.start`, that session's `session.end` (incl. `inferred_silence`), or leaving live, and then renders `unknown` | [§ 4.3](#43-the-derivation-function) rule 4 (the only idle rule), [§ 4.4](#44-activity-states-every-entry-and-exit-edge) `stalled` (all three exits, each recording `stalled_cleared_by`), [§ 4.5](#45-link-states) (leaving live **clears** the flag at 300 s — a sweeper rule, not a render mask, so a returning seat cannot re-assert it), [§ 8.2.1](#821-the-seat-state-object) (`api_error_type` is a **wire field**, not only a `sessions` column), [§ 4.8](#48-what-may-never-mint-a-state), [§ 10](#10-worked-example-the-clear-trace-folded-end-to-end) | [AT-D2-1](#at-d2-1-idle-is-minted-by-exactly-one-rule), [AT-D2-2](#at-d2-2-the-clear-trace-mints-no-idle), [AT-D2-6](#at-d2-6-stalled-is-a-state-with-three-exits) |
| **2** | **`stale` (300 s) and `offline` (900 s) are visibly degraded rendered states, never `idle`**, and a seat with `degraded` non-empty renders its badge | [§ 4.2](#42-render-precedence) (short-circuits above the activity axis), [§ 4.5](#45-link-states), [§ 7.3](#73-how-the-reporters-own-counters-are-handled) | [AT-D2-3](#at-d2-3-stale-offline-and-disabled-are-rendered-never-idle) |
| **3** | **Per-event dedup on `(install_id, seat_id, event_id)`, 10-day window, exceeding the 8-day spool residency** | [§ 6.4](#64-ddl) `events.uq_dedup`, [§ 6.7](#67-retention-and-purge) (the chain, and why retention is the window's real floor) | [AT-D2-17](#at-d2-17-dedup-retention-and-the-chain-between-them) |
| **4** | **Transitions ordered by `(event_time, seq)`, never arrival order; `received_at` the only clock for liveness, retention and cross-seat comparison; a repeated `(seq_epoch, seq)` with differing `event_id`s counted as `seq_collision`, not silently applied** | [§ 6.5](#65-the-fold) (the LWW comparator, with `seq_epoch` inserted — a refinement, filed at [§ 14](#14-open-questions-for-the-review-loop) item 4), [§ 4.7](#47-which-clock-each-ceiling-is-measured-from), [§ 6.7](#67-retention-and-purge), [§ 7.1](#71-d1s-server-side-counters--where-they-live) | [AT-D2-11](#at-d2-11-out-of-order-batches-converge), [AT-D2-18](#at-d2-18-seq-gaps-collisions-and-epoch-resets-are-visible) |
| **5** | *(D1 § 12.6, stated in full at D1 § 6.13 with its resolution edges)* **Blocked only from `attention.request`, cleared only by its matching `attention.resolved` (by `request_id`), the session ending, or leaving live — never longer than the 60-minute ceiling; no second predicate over `notification_kind` is needed or wanted** | [§ 4.4](#44-activity-states-every-entry-and-exit-edge) `blocked` (all four exits — offline quiescence is not a fifth: [§ 4.5](#45-link-states)'s leaving-live resolve fires at `stale` **or** `offline` and has always run first), [§ 4.5](#45-link-states) (leaving live **resolves** the request at 300 s with `seat_left_live`, so the clause is discharged by clearing the fact and not by masking it), [§ 4.3](#43-the-derivation-function) (precedence rule 1), [§ 6.4](#64-ddl) (`notification_kind` has three members and no `other`) | [AT-D2-5](#at-d2-5-blocked-has-an-exit-including-when-the-exit-event-is-lost) |

### The twenty-nine further obligations

| # | D1 source | Obligation | Discharged in |
|---|---|---|---|
| S1 | § 1 non-goals | D2 owns the storage schema, retention and state model; D1 says what arrives and what it means | [§ 1.1](#11-what-this-document-owns), [§ 1.3](#13-the-boundary-stated-as-a-rule) |
| S2 | § 2.3, § 10.2 | The `(seq_epoch, seq)` ordering key is load-bearing; a collision is counted and badged, never assumed away | [§ 6.5](#65-the-fold), [§ 7.1](#71-d1s-server-side-counters--where-they-live) |
| S3 | § 6.4, § 8.6 | `stalled` is a rendered state of its own, carrying `api_error_type` for the drill-down — never folded into `unknown` | [§ 4.4](#44-activity-states-every-entry-and-exit-edge), [§ 8.2.1](#821-the-seat-state-object) (the `api_error_type` **field row**, in the worked snapshot, asserted by [AT-D2-6](#at-d2-6-stalled-is-a-state-with-three-exits) against the wire object) |
| S4 | § 6.12, decision 36 | `notification_kind` has three members and no `other`; D2 must not build a render branch nothing can reach | [§ 6.4](#64-ddl) (the `ENUM` is three members), [§ 4.4](#44-activity-states-every-entry-and-exit-edge) |
| S5 | § 8.2 | A `lifo_tool_name` match can swap two concurrent same-tool calls' ids and durations but never counts or outcomes, so the idle rule is unaffected — and the match quality must stay legible | [§ 4.8](#48-what-may-never-mint-a-state), [§ 6.4](#64-ddl) (`calls.match_kind` stored and rendered) |
| S6 | § 8.3 | A reap that ends a session emits `attention.resolved(session_ended)` **after** the boundary event; D2 needs that exit edge | [§ 4.4](#44-activity-states-every-entry-and-exit-edge) `blocked` exits |
| S7 | § 8.6 | The server ledger's seven rules: open on `tool.start`; `duplicate_open`; close on `tool.end`; **override** a `tombstone_ref` close over an abort; create-closed and never reopen on a late `tool.start` (`late_open`); orphan-close server-side; any open call ⇒ `working` | [§ 4.3](#43-the-derivation-function) rule 3, [§ 4.6](#46-every-open-fact-has-a-ceiling), [§ 6.5](#65-the-fold), [AT-D2-11](#at-d2-11-out-of-order-batches-converge), [AT-D2-16](#at-d2-16-server-side-closes-write-no-wire-events) |
| S8 | § 8.6 | The ledger is **seat-scoped and models no agent scope**; `agent_scope` and `parent_call_id` are labels the server never reaps on | [§ 4.8](#48-what-may-never-mint-a-state) (last row), [§ 6.4](#64-ddl) (stored, never in a predicate) |
| S9 | § 9.1 | A seat with `oldest_unsent_age_s > 300` renders **catching up**, not current | [§ 4.5](#45-link-states), [§ 4.2](#42-render-precedence) (it outranks the activity state) · [AT-D2-20](#at-d2-20-catching-up-is-not-current-and-not-stale) |
| S10 | § 9.1 "Rendering (constraining D2/D3)" | `stale`/`offline` are a distinct rendered desk; an empty floor and a broken floor must never look alike | [§ 4.2](#42-render-precedence), [§ 2.2](#22-fail-posture-per-path) (the read fails closed rather than serving an empty fleet) |
| S11 | § 9.4 | The **server** alarms `predicate_constant`, surfaced per seat, per predicate, at criteria each predicate's own volume can reach | [§ 5](#5-server-side-predicates-and-their-controls), [§ 7.3](#73-how-the-reporters-own-counters-are-handled) (windows computed from retained heartbeats, no second copy) |
| S12 | § 10.1 | Never rewrite `event_time`; never render a seat timestamp as an absolute clock; compute `clock_skew_ms` per batch and badge past ±120 s | [§ 3.3](#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by), [§ 6.4](#64-ddl) (`events.event_time` stored verbatim), [§ 7.1](#71-d1s-server-side-counters--where-they-live) |
| S13 | § 10.2 | A missing `seq` inside an epoch is a real gap → `seq_gap`, seat `lossy`; a spool-overflow drop produces **no** gap and the two losses must not be conflated | [§ 7.1](#71-d1s-server-side-counters--where-they-live), [AT-D2-18](#at-d2-18-seq-gaps-collisions-and-epoch-resets-are-visible) |
| S14 | § 10.4 | `batch_id` remembered for 24 h; a repeat returns the previous response; per-event dedup remains the correctness mechanism | [§ 6.4](#64-ddl) `batches` (a timestamp comparison, never a deletion) |
| S15 | § 12.5 | Orphan timeouts 15 min / 60 min; the close is **server-side only, with no wire event synthesized**; a late `completed`/`failed` carrying `tombstone_ref` **overrides** the abort and counts `late_completion` | [§ 4.6](#46-every-open-fact-has-a-ceiling), [§ 4.8](#48-what-may-never-mint-a-state), [AT-D2-16](#at-d2-16-server-side-closes-write-no-wire-events) |
| S16 | § 12.7 | The seventeen server-side counters (and the `clock_skew_ms` gauge), each with its consequence | [§ 7.1](#71-d1s-server-side-counters--where-they-live) (one row each: storage, surface, badge) |
| S17 | § 6.14 | `enabled: false` renders **disabled** — a seat that is off and a seat that is gone must not look alike | [§ 4.2](#42-render-precedence), [§ 4.5](#45-link-states) |
| S18 | § 6.14, § 9.3 | The `degraded` array is the badge source so a consumer never re-derives badges from raw counters; twelve members, closed | [§ 7.2](#72-this-planes-own-counters-and-badges) (server badges kept **separate**, never merged into D1's array), [§ 7.3](#73-how-the-reporters-own-counters-are-handled) |
| S19 | § 6.2, § 12.7 | An event for a session closed by `inferred_silence` **re-opens it** server-side and counts `session_reopened` | [§ 4.6](#46-every-open-fact-has-a-ceiling), [§ 6.4](#64-ddl) (`sessions.reopened`) |
| S20 | § 6.6 | A close with no open is **synthesized at the reporter**, so the ledger is total and the anomaly is a visible flag rather than a negative count | [§ 6.4](#64-ddl) (`calls.synthesized`), [§ 4.8](#48-what-may-never-mint-a-state) (the `match: synthesized` row: created already closed, flag stored and rendered) |
| S21 | § 6.8 | The subagent title lives on `subagent.spawn` only; the consumer joins on `call_id`; a lost spawn yields a **title-less stop**, an honest orphan never papered over | [§ 8.2.1](#821-the-seat-state-object) (`subagents[].title` is nullable and **never invented** — a later `subagent.spawn` for the same `call_id` does fill it, and what is forbidden is deriving a title from anything else) |
| S22 | § 6.11 | `used_pct_source` keeps the two branches distinguishable rather than silently averaged | [§ 8.2.1](#821-the-seat-state-object) (`context.source` rides every object; no aggregate mixes them) |
| S23 | § 5 rule 3, § 12.7 | An unknown `data` key at a known version is ignored and counted `ignored_unknown_fields`, per seat | [§ 7.1](#71-d1s-server-side-counters--where-they-live) |
| S24 | § 12.1 | Every refusal is attributed to the **token's binding**; refusals before authentication degrade no seat and are global only | [§ 7.1](#71-d1s-server-side-counters--where-they-live) (the three global-only counters and why) |
| S25 | decision 19 | The store does **not** stamp `schema_version` onto events at ingest — the event carries it | [§ 6.4](#64-ddl) (`events.schema_version`, whose column comment states it: *"stored exactly as received; the server never writes or rewrites it"*) |
| S26 | § 6.4, § 6.6, decision 13 | A `failed` tool call is a closed call and does not block idle; an `interrupted` one closes `aborted` and does | [§ 4.4](#44-activity-states-every-entry-and-exit-edge), [AT-D2-1](#at-d2-1-idle-is-minted-by-exactly-one-rule) (the `failed_call` fixture is a GREEN, not an edge case) |
| S27 | § 3.4, § 9.2 | The heartbeat plus a server-side staleness alarm is the structural backstop; no gating on undocumented environment markers; every predicate reports both branches and is alarmed when one goes constant | [§ 5](#5-server-side-predicates-and-their-controls) (all three rules, restated for this plane with its own predicates and controls) |
| S28 | § 9.3, § 11.3 | `spool_dropped_events` badges the seat `lossy` **and the number is rendered** — a loss is never a badge alone | [§ 7.3](#73-how-the-reporters-own-counters-are-handled) (badges render with their counter value and `uptime_s`), [§ 7.1](#71-d1s-server-side-counters--where-they-live) (no server counter writes `lossy`, so the rendered number always belongs to the badge beside it) |
| S29 | § 6.2 | **A consumer must not read `end_reason: "other"` as a degradation signal** — it is "a common value, not a residue", the majority of D1's own capture run, and what a non-interactive `claude -p` session ends with | [§ 6.4](#64-ddl) (`sessions.end_reason` carries `other` as an ordinary member), [§ 4.8](#48-what-may-never-mint-a-state) (the explicit row: no badge, no degradation, no rule reads it), [AT-D2-1](#at-d2-1-idle-is-minted-by-exactly-one-rule) (a `clean_turn_then_exit` with `end_reason: other` leaves `badges` empty and moves no counter) |

**Nothing in D1 addressed to D2 is undischarged.** Four obligations are discharged with a stated
divergence rather than literally, and each is filed as a D1 amendment need in
[§ 14](#14-open-questions-for-the-review-loop) rather than absorbed silently:

1. `D2-MUST` #4's ordering key gains `seq_epoch` ([§ 6.5](#65-the-fold)) — item 4.
2. S7's ledger gains server-side closes D1 does not enumerate
   ([§ 4.6](#46-every-open-fact-has-a-ceiling)) — items 1 and 2.
3. `D2-MUST` #1's *"leaving live"* clause is discharged for `stalled` by **clearing the fact at the
   `stale` boundary**, not by masking the render ([§ 4.5](#45-link-states)); the same clause's silence
   about whether a clean `idle` survives a `session.end` is item 10, where this document states its
   reading.
4. S13's *"seat `lossy`"* is discharged by this plane's own `seq_gap` badge, because D1 § 9.3 and
   D1 § 12.7 disagree about whether `seq_gap` is a `degraded` member at all
   ([§ 7.1](#71-d1s-server-side-counters--where-they-live)) — item 12.

---

## Appendix B — what an implementer builds from this

In dependency order, with the gate each must pass before the next is trusted. Card #7339
(`docs/PLAN.md § 3`) is the whole of it; card #7338 (the ingest, from D1) is a prerequisite for
everything from step 3 onward.

| Order | Artifact | Gate |
|---|---|---|
| 0 | the pinned test database, the paired `phpunit.xml` entries and the resolved-value guard | **[AT-D2-14](#at-d2-14-the-store-is-pinned-and-the-pin-bites)** RED (hostile export) then GREEN — first, because every test below runs against a database, and a suite that cannot prove its isolation must not run |
| 1 | migrations: `installs`, `seats`, `events`, `batches` | the ingest can write and the dedup key holds — [AT-D2-17](#at-d2-17-dedup-retention-and-the-chain-between-them) |
| 2 | migrations: `sessions`, `calls`, `attention_requests`, `seat_state`, `seat_state_transitions`, counters, predicates, `feed_tokens` | schema only |
| 3 | `project()` — the per-kind projections, with the LWW comparator | [AT-D2-11](#at-d2-11-out-of-order-batches-converge) |
| 4 | `derive_activity()` + link states + `render_state` | [AT-D2-1](#at-d2-1-idle-is-minted-by-exactly-one-rule), **[AT-D2-2](#at-d2-2-the-clear-trace-mints-no-idle)** — the gate on trusting the derived signal at all — [AT-D2-5](#at-d2-5-blocked-has-an-exit-including-when-the-exit-event-is-lost), [AT-D2-6](#at-d2-6-stalled-is-a-state-with-three-exits) |
| 5 | `mezzanine:fold` — cursor, transaction, claim, visibility lag, poison rule | [AT-D2-9](#at-d2-9-the-fold-is-idempotent-across-a-restart), [AT-D2-10](#at-d2-10-rebuild-equals-fold), [AT-D2-22](#at-d2-22-concurrent-ingest-cannot-strand-an-event-behind-the-cursor) |
| 6 | `mezzanine:rebuild` | [AT-D2-10](#at-d2-10-rebuild-equals-fold) |
| 7 | `mezzanine:sweep` — the seven time-derived jobs [§ 2.1](#21-processes) lists, which is their one home | [AT-D2-3](#at-d2-3-stale-offline-and-disabled-are-rendered-never-idle), [AT-D2-4](#at-d2-4-a-heartbeat-only-seat-never-looks-busy), [AT-D2-13](#at-d2-13-every-predicate-can-answer-both-ways), [AT-D2-16](#at-d2-16-server-side-closes-write-no-wire-events) |
| 8 | REST: snapshot, seat detail (with `resync_from`), timeline, health — with the fail-closed postures and the retirement read filter | [AT-D2-12](#at-d2-12-the-store-failing-is-never-a-quiet-zero), [AT-D2-19](#at-d2-19-read-side-auth-refuses-correctly), [AT-D2-20](#at-d2-20-catching-up-is-not-current-and-not-stale), [AT-D2-23](#at-d2-23-a-retired-seat-is-rendered-not-disappeared) |
| 9 | Reverb channel, deltas, coalescing, feed heartbeat, backpressure | [AT-D2-7](#at-d2-7-snapshot-then-deltas-has-no-window), [AT-D2-8](#at-d2-8-a-delta-gap-is-detected-and-resynced), [AT-D2-15](#at-d2-15-feed-backpressure-closes-one-connection-and-no-others) |
| 10 | `mezzanine:purge`, the size alarm, `fold_lag` fleet health | [AT-D2-17](#at-d2-17-dedup-retention-and-the-chain-between-them), [AT-D2-21](#at-d2-21-a-frozen-fold-cannot-look-healthy) |
| 11 | `mezzanine:retire` — the three columns, the recomputed render, the `cause: operator` transition row and the two publishes, in one transaction ([§ 2.1](#21-processes), [§ 4.10](#410-retirement-is-a-rendered-state)). It comes after step 9 because it publishes on the feed | [AT-D2-23](#at-d2-23-a-retired-seat-is-rendered-not-disappeared) |

**Three of these are hard requirements before anything downstream may treat this state as true:**
**AT-D2-2** (the `/clear` trace mints no idle — the D2 half of D1's headline test, and the reason both
documents exist in this order); **AT-D2-4** (a heartbeat-only seat never looks busy — the maxim, made
into a test); and **AT-D2-14** (the pin bites under a hostile export — because every other result here
is only as trustworthy as the database it was produced against).

**A note on order.** Steps 3 and 4 are separable and must stay so: `project()` writes facts and
`derive_activity()` reads them, and nothing in the derivation may write a fact. That separation is what
makes [AT-D2-10](#at-d2-10-rebuild-equals-fold)'s equality meaningful — if the derivation could write,
a rebuild would produce a different answer from the live fold and the divergence would be a property of
the design rather than a defect in it.

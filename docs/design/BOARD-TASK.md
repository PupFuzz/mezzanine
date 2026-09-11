# D4 — the board task-title producer

*Card#7582. The producer behind tier 1 of
[D2 § 4.9](FLEET-STATE.md#49-the-task-title-merge-and-what-is-not-specified-here)'s task-title merge:
what reads the kanban board, what it writes, how a seat is joined to a board user, and why the value
it produces survives [D2 § 6.6](FLEET-STATE.md#66-rebuild-from-the-log)'s rebuild.*

---

## 0. Overview

D2 § 4.9 specifies a task-title merge whose **highest tier is a board card assigned to this seat** —
its columns, its reference format, its freshness bound and its precedence are all pinned there, and
none of them is restated here. What D2 does not own, and says so twice, is the thing that *produces*
that fact: [§ 1.2](FLEET-STATE.md#12-non-goals--stated-so-an-implementer-cannot-widen-scope-in-good-faith)
lists *"Ingest of kanban board events…"* among its non-goals and records that **"The kanban poller is
designed nowhere yet"**, and [§ 14](FLEET-STATE.md#14-open-questions-for-the-review-loop) item 3 names
*"a ruling on where the board producer is designed"* as one of three things that would close it.

**This document is that ruling's answer, and the producer.** In one sentence: a scheduled command
reads the configured kanban boards over HTTPS, joins each card's `assigned_user_id` to a seat through
a mapping an operator declares, and writes the answer into a **durable input table** — never into
`seat_state`. The fold and the sweeper then derive the five `task_*` columns from that table exactly
as they already derive them from `calls`, which is what makes the value reproducible by a rebuild
with no new event kind, no change to `RebuildCommand::reset()`, and no exclusion added to
[AT-D2-10](FLEET-STATE.md#at-d2-10-rebuild-equals-fold). [§ 2](#2-the-rebuildability-question) is the
argument for that, and it is the reason this document exists in the shape it does.

⛔ **Nothing in this document is built.** Every structural piece of it needs an amendment to a shipped
design document that this card may not make unilaterally; [§ 13](#13-what-is-deliberately-not-built)
states the gate and lists the amendments. What is delivered is the design.

⚠ **Tier 1 arrives DARK, and that is correct rather than a defect.** Three independent things must be
true before a single board title reaches a desk, and today none of them is; [§ 10](#10-dark-on-arrival)
enumerates them and states how each one is separately legible. A producer that answered *"no tier-1
card"* by inventing one would be the failure this whole product exists to prevent.

---

## 1. Scope, non-goals, and the D2 boundary

### 1.1 What this document owns

| Owned here | Section |
|---|---|
| The rebuildability argument, and the rule it establishes about non-`events` inputs | [§ 2](#2-the-rebuildability-question) |
| The poller process: its kind, its cadence and that cadence's derivation, and what its death costs | [§ 3](#3-the-process) |
| Identity: seat → board user, which card answers when several could, and which boards are read | [§ 4](#4-identity-and-the-join) |
| The credential: what it is, where it lives, its scope, and the rule that it is never emitted | [§ 5](#5-the-credential) |
| The read: the endpoint, the response fields consumed, pagination, and every degraded-read rule | [§ 6](#6-the-read) |
| The write: the input table's fields and semantics, and the one transaction | [§ 7](#7-the-write) |
| The merge function the fold and the sweeper run, tier 1 included | [§ 8](#8-the-merge) |
| Failure paths and their observables; acceptance tests; every number | [§ 9](#9-failure-paths) · [§ 11](#11-acceptance-tests) · [§ 12](#12-every-number-and-where-it-comes-from) |

### 1.2 Non-goals — stated so an implementer cannot widen scope in good faith

| Not in this contract | Why, and who owns it |
|---|---|
| **The tier table, the precedence, the `task.*` wire members, the 30-minute bound** | [D2 § 4.9](FLEET-STATE.md#49-the-task-title-merge-and-what-is-not-specified-here) and [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object). This document produces an input; it does not re-specify the merge's contract. Where it needs one of those facts it cites the section and does not paraphrase it. |
| **Anything GitHub-sourced** | Nothing is owed. Tier 2 was the GitHub-sourced title and it is **retired** by operator ruling (card#9234) — the number 2 with it. The coordination producer ([D1 § 18](EVENT-SCHEMA.md#18-the-coordination-event-producer)) and the thread line it feeds are untouched by that retirement and by this document. |
| **Writing to the board** | The credential is read-scoped ([§ 5](#5-the-credential)) and the poller issues `GET` only. Mezzanine never moves a card, never assigns one, and never comments. A floor that could edit its own subject would be an instrument inside the thing it measures. |
| **Deciding that cards get assigned** | An operator workflow convention, ruled on 2026-09-10 (card#7582). This document designs against `assigned_user_id` being populated; it does not populate it and does not backfill it. |
| **Alerting** | [D2 § 1.2](FLEET-STATE.md#12-non-goals--stated-so-an-implementer-cannot-widen-scope-in-good-faith)'s position, inherited deliberately: a degraded poll surfaces as a counter and as `task.degraded` on the wire, and there is no notifier. |
| **A second producer for the same fact** | There is one board producer and there must not be a second — the rule [D1 § 18.11](EVENT-SCHEMA.md#1811-one-producer-two-consumers) states for its own producer, applied here for the same reason. |

### 1.3 The boundary, stated as a rule

**This document never edits D2.** Where it needs a rule that lives in D2 — a column in § 6.4's DDL, a
row in § 2.1's process table, a member in § 7.2's counters — it states the amendment as a **request**,
with its exact text and the section it belongs in, and the amendment is ratified before anything is
built against it. A producer document silently adding a table to the store document's DDL is how two
documents start disagreeing about what the store is. [§ 13](#13-what-is-deliberately-not-built) is the
list.

### 1.4 Why this is a document of its own

Three independent answers agree, and they are recorded here because a fourth top-level design document
is not a thing to add casually:

1. **D2 says so.** [§ 1.2](FLEET-STATE.md#12-non-goals--stated-so-an-implementer-cannot-widen-scope-in-good-faith)
   places the kanban poller *outside* D2's contract by name. A design cannot live in the document whose
   non-goals exclude it.
2. **D2 asks for the ruling.** [§ 14](FLEET-STATE.md#14-open-questions-for-the-review-loop) item 3 lists
   *"a ruling on where the board producer is designed"* as an open question. This is the answer, and it
   closes that third of the item.
3. **The operator ruled it new scope.** Recorded on card#7582 (2026-08-24, decisions-log s556): the
   task-title producers are *"new scope, not an amendment to a shipped design."*

**The alternative that lost, and why.** A `§ 4.9.1` subsection inside D2 would have kept one file. It
loses on (1) — D2 would then own a fact its own non-goals disclaim — and it loses again on blast
radius: D2 is a shipped, verifier-gated contract, and a producer's design churns while a contract must
not. The split this document takes is the one the repository already runs between D1 § 18 and D2
§ 8.3.3: **the producing document owns the fact and its derivation; D2 owns the store column and the
read surface.** [§ 7.1](#71-the-input-table)'s field table and D2 § 6.4's DDL are that split applied
here, and they are deliberately different content rather than two copies of one thing — semantics and
writers here, SQL type and index there.

---

## 2. The rebuildability question

*The one real design question on this card, and the reason a board poller is not simply "a cron job
that writes a title".*

### 2.1 The problem

[D2 § 6.6](FLEET-STATE.md#66-rebuild-from-the-log) makes `seat_state` reproducible by replaying
`events`, and [AT-D2-10](FLEET-STATE.md#at-d2-10-rebuild-equals-fold) asserts that a rebuilt seat
equals an incrementally folded one field for field — *"the strongest available test of the
derived-not-stored property"*. `RebuildCommand::reset()` nulls all five `task_*` columns before the
replay.

So a board title written **into `seat_state`** by a poller is erased by the documented recovery path.
Two consequences, and the second is the one that matters:

- the desk loses its title after a `derivation_error` recovery, silently, until the next poll; and
- AT-D2-10 diverges on five columns — a rebuilt seat shows the tier-3 title where the folded one
  showed tier 1. That is the test reporting a defect, and it would be right to.

### 2.2 The options, priced

**(a) Route board facts through `events` as a new event kind.** A **D1** change, and the largest one
available. The ingest is authenticated by a per-seat token and
[D1 § 3.3](EVENT-SCHEMA.md#33-authentication-and-the-identity-binding-rule)'s identity-binding rule ties
an event to the seat whose token carried it — a poller holds no seat's token, so this needs either a
forged seat identity (which that rule exists to forbid) or a new authenticated producer endpoint, a new
schema kind, its validation order, its sanitization, its dedup and `seq` handling, and an explicit
exclusion from [D2 § 3.2](FLEET-STATE.md#32-the-activity-event-set)'s activity event set — which
answers *counts as activity?* for every kind there is — so that a board poll cannot mint activity on a
silent seat. [D1 § 18.9](EVENT-SCHEMA.md#189-why-this-does-not-ride-the-batch-contract)
already argues at length why coordination facts do **not** ride the batch contract; the same argument
applies unchanged. It also inherits a hole it does not close: `events` is purged at 14 days
([D2 § 6.7](FLEET-STATE.md#67-retention-and-purge)), so a rebuild past the window loses the board
title anyway. **Highest cost, touches the keystone document, and still not total.** Rejected.

**(b) Treat tier 1 as non-reproducible and exclude the `task_*` columns from AT-D2-10.** Cheapest to
write and the worst of the three. Card#9214 has just **deleted** an unjustified fourth exclusion of
exactly this shape from `At10RebuildEqualsFoldTest`, and its finding is the argument against minting a
fifth: the exclusion was *unexercised*, so it was not protecting a known divergence — it was
suppressing an unknown one. An exclusion here would additionally blind the test to **tier 3**, whose
reproducibility card#9214 has just established by reading `task_as_of` off
`calls.opened_received_at` instead of the wall clock. And it fixes nothing a user can see: the desk
still loses its title on the documented recovery path; the test simply stops noticing. Rejected.

**(c) ⭐ Derive tier 1 at recompute time from a durable input the rebuild does not destroy.** Taken.

### 2.3 The rule this establishes

AT-D2-10 asserts **reproducibility**. D2 § 6.6 *explains* it with a narrower sentence — *"some fold
rule is reading state that is not in the log"* — and those are not the same property. **The repository
already relies on the difference, in two places, deliberately:**

- **`seats.retired_at` / `retired_by` / `retired_reason`.** Operator-written, in no event, read by the
  fold on every recompute (`SeatFacts::for()` → `Derivation::render()`), and **not touched by
  `reset()`**. A rebuild re-reads the same durable row and reaches the same answer, so AT-D2-10 is
  green over them.
- **`seat_state.badge_first_seen`.** Explicitly *not* reset, with the reason argued in `reset()`'s own
  comment: *"a badge that has been up since Tuesday has been up since Tuesday whether or not its seat
  was rebuilt this afternoon."*

⇒ **The property the fold must hold is not "read only `events`". It is "read only durable inputs that
a rebuild does not destroy."** `seats` is such an input. `seat_board_task` ([§ 7.1](#71-the-input-table))
is another, and it is one for the same reason `seats` is: the poller **owns** it, the rebuild has no
business truncating another writer's input, and re-reading it is idempotent.

### 2.4 What therefore does not change

| Thing | Change |
|---|---|
| `RebuildCommand::reset()` | **none.** It goes on nulling the five `task_*` columns — they are projection, and the first `writeDerivedColumns()` of the replay re-derives them from `calls` **and** `seat_board_task` together. |
| `AT-D2-10`'s exclusion list | **none.** Its three named exclusions stand; no fourth is added. [AT-D4-1](#at-d4-1-rebuild-equals-fold-with-a-board-sourced-title) widens its *fixture* to cover a board-titled seat, which is the opposite of an exclusion. |
| D1 | **none.** No event kind, no endpoint, no producer identity, no schema version. |
| `task_as_of`'s basis | **none, in kind.** Card#9214's rule — *the stamp is a value already in the input, never `now()`* — is honoured by taking tier 1's stamp from `seat_board_task.observed_at`, a stored value, exactly as tier 3 takes it from `calls.opened_received_at`. |

### 2.5 The one bounded window, stated rather than glossed

`RebuildCommand` calls the recompute **per replayed event**. A seat with **zero** retained events is
therefore left with `task_*` at the values `reset()` wrote — all null — until something recomputes it.
That something is the sweeper, which recomputes **every** seat **every** pass
([D2 § 2.1](FLEET-STATE.md#21-processes)), so the window is **one sweep cadence**, self-healing, and
identical in kind to the window every other time-derived column on that seat already has after a reset
(`link_state` is `offline` and `activity_state` `unknown` for exactly as long). It is named here rather
than left for a reader to discover, because *"the value comes back on its own shortly"* is a claim that
has to be true for a stated reason, not hoped for.

---

## 3. The process

### 3.1 `mezzanine:board-poll`

A **scheduled command**, on the model of `mezzanine:purge` and not of the fold or the sweeper.
`server/routes/console.php` already states the distinction and the reason: the daemons are *"continuous
loops with their own poll intervals"* and get a supervisor; a bounded, idempotent job gets a schedule
entry. A board poll is bounded — one paged HTTPS read per configured board, then one transaction — and
holds no state between runs beyond the rows it writes, so a supervised daemon would buy nothing and
cost a unit file. `->withoutOverlapping()` for the same reason `purge` carries it: a slow board must
not start a second poll beside the first.

**D2 § 2.1 owes it a row** ([§ 13](#13-what-is-deliberately-not-built)). That table *"is what a host is
provisioned from"*, and card#9181's finding is exactly what happens to a process that is built without
one — the feed heartbeat ran as a sixth process against a table of five, and a host provisioned from
the document supervised everything except it.

### 3.2 The cadence, derived — and the 30-minute bound, re-derived

D2 § 12 carries the tier-1 freshness bound as **"Chosen, provisional — … re-derived once the board
producer exists and its poll cadence is known ([§ 14](FLEET-STATE.md#14-open-questions-for-the-review-loop)
item 3)"**. That re-derivation is owed here, so it is done here, and it is done in the honest
direction: **the cadence is derived first, because it is the number with a real cost, and the bound is
derived from it.**

**Cadence — 5 minutes.** What the poll carries is *which work item is this agent on*, a fact that moves
at human granularity and whose consumer is a floor a person watches. The cost is one paged HTTPS read
per configured board per tick, and it is measurable rather than notional: the whole-board read of
board 14 is a single page of ~340 KB, dominated by card `description` bodies the poller discards
(derivation in [§ 12](#12-every-number-and-where-it-comes-from)). At 5 minutes that is 288 reads and
~98 MB/day per board. **At 1 minute** it is five times that — ~490 MB/day — to reduce the worst-case
lateness of a card reassignment from 5 minutes to 1 on a fact that changes a handful of times a day,
lateness no one watching a floor can perceive. **At 15 minutes** an operator who moves a card and
watches the floor waits a quarter of an hour, which is long enough to read the integration as broken.
5 minutes sits between those two failures with a factor of three of margin either way.

**Bound — 30 minutes, and the number does not move; its basis does.** The bound's job is not liveness,
it is lie-prevention: it decides how long the floor keeps standing behind the last observation when the
poller or the board is gone. So it is measured in *consecutive failed polls*, and it has a floor and a
ceiling:

- it must be **strictly more than two cadences**, or a single transient HTTP failure drops **every**
  tier-1 title on the floor at once — a total degradation minted by one lost packet, which is the
  over-sensitivity [D2 § 2.2](FLEET-STATE.md#22-fail-posture-per-path)'s postures argue against
  throughout; and
- it must be short enough that a title an operator can read is still plausibly current.

**30 min = 6 × 5 min ⇒ five consecutive failed polls tolerated**, which is a real board outage rather
than a blip, and half an hour is still inside "plausibly the current task" for work at this
granularity. ⇒ D2 § 12's row changes from **Chosen, provisional** to **Derived**, and D2 § 4.9's
*"re-read at the board poll cadence"* gains the cadence by reference. ⭐ **The published figure survives
its own re-derivation** — which is worth stating plainly, because a re-derivation that confirms is
evidence the original judgement was sound, and is not the same thing as never having checked.

### 3.3 If it dies

Nothing writes; `observed_at` stops moving; at the bound every tier-1 title is **dropped, not rendered
stale** per D2 § 4.9, the merge falls through to tier 3, and `task.degraded` goes `true` on the wire
for every affected seat. The degradation is therefore **per-seat, rendered, and self-labelling** — the
opposite of D2 § 2.3's frozen fold, which is dangerous precisely because it can look healthy. A poller
that never worked at all is the one case that mechanism cannot see (there is no dropped value to
label), and [§ 9](#9-failure-paths) gives it the counter that makes it legible.

---

## 4. Identity and the join

### 4.1 Seat → board user

**`seats.board_user_id INT UNSIGNED NULL`, UNIQUE.** One value per seat, at most one seat per board
user. `NULL` — the default and, on arrival, the value on every row — means *this seat is not a tier-1
candidate*, and the poller never asks the board about it.

**It lives on `seats` and not in a config file.** `seats` is already the home of a seat's identity
facts, and a configuration map would be a second identity registry free to drift from the first: a seat
retired in the store but still named in a YAML file is a join that keeps answering.

**Its one writer is an operator command**, on the exact model of `mezzanine:retire` — an administrative
fact that no timeout, poll or inference may set:

```
php artisan mezzanine:seat-board-user --seat=<install>/<seat> --board-user=<id>
php artisan mezzanine:seat-board-user --seat=<install>/<seat> --clear
```

`--clear` deletes that seat's `seat_board_task` row **in the same transaction** that nulls the column.
Two writes, one act: a cleared mapping that left the input row behind would leave the merge answering
from a card the seat is no longer joined to, and the deletion is the refusable-first half (nothing is
un-set until the row is gone). An unknown seat is refused with a non-zero exit before anything is
written; a `--board-user` that is not a positive integer likewise.

⛔ **What is NOT done: matching a board username to `seats.seat_id`.** It would need no mapping at all
and it is wrong. A board user name and a Mezzanine seat id are two identifiers from two systems that
happen to be strings; asserting they are equal invents a join key, which is the defect this card was
blocked on for two cycles. An explicit declaration that refuses an unknown value is the whole
difference between a join and a coincidence.

### 4.2 Which card answers, when several could

A board user may hold several assigned cards. D2 § 4.9 says *"board card assigned to this seat"*,
singular, so the producer owes a **deterministic** choice — an answer that flapped between two cards
across polls would make the desk's title oscillate with nothing changing.

**The rule: greatest `updated_at`; on an exact tie, greatest `id`.** Both keys come from the board row
itself, so the choice is reproducible from the same input by anyone re-running it, and the tie-break is
total. This is the convention `StateRecompute` already uses for the newest open call
(`orderByDesc('opened_at')->orderByDesc('id')`), reused rather than re-invented.

**The alternative that lost:** preferring a card in an "in progress" column. It reads better and it
costs a dependency on column semantics the board owns and can retitle, keyed by stage ids that live in
a per-board config file this design would then have to consume. `updated_at` is a property of the row,
needs no vocabulary, and a card someone is working on is the card being touched.

### 4.3 Which boards are read

A configured list of board ids ([§ 5](#5-the-credential)), read with one credential. The join is
`assigned_user_id` alone, so a card on **any** configured board can answer for a seat, and § 4.2's rule
resolves a seat holding cards on two of them.

**No `installs.board_id` column.** An install→board mapping would be a second thing to keep true, and
the only question it answers — *which board is this seat's?* — is already answered by the seat→user
mapping being unique. If a future fleet needs per-install credentials, that is the change that mints
the column; today it would be a column with one value.

---

## 5. The credential

| Key | Value | Notes |
|---|---|---|
| `BOARD_API_BASE` | e.g. `https://<kanban-host>/api/v3` | No trailing slash. HTTPS only; a plain-`http` base is refused at startup. |
| `BOARD_API_TOKEN` | a **read-scoped** bearer token | Sent as `Authorization: Bearer …`. |
| `BOARD_IDS` | comma-separated board ids | Empty or unset ⇒ the poller is **unconfigured**, which is a clean no-op, not a failure ([§ 9](#9-failure-paths)). |

Read through `config/mezzanine.php` like every other setting, never `env()` at a call site.

**Read-scoped is a requirement, not a preference.** The poller issues `GET` only and there is no path
in this design that writes to the board; a token that *could* write is a token that turns a bug in a
read loop into a board mutation. The scope is the operator's act at the board, and the design's
obligation is to need nothing more.

### 5.1 ⛔ The token value never reaches an output stream

Not a file rule — a *resolution* rule. Before anything is printed, logged, or put in an exception
message, the question is **what does this resolve**, never *what file did it come from*. Concretely:

- the token is sent in a **header**, never in a URL query, so it cannot ride a logged URL, a redirect,
  a traceback or a proxy log;
- any URL the poller logs is logged **with its userinfo component redacted**, because a base URL is
  allowed to carry one and a logged URL is the easiest accidental sink there is (the board toolkit's
  own fetch path redacts the same component before logging, for the same reason — named as prior art,
  not as this document's authority);
- a non-2xx is logged as a **status code and a failure class**, never as a response body and never with
  the request headers — a board that echoes an `Authorization` header into an error body must not be
  able to write it into our log;
- the command's own verbose output and its exit message name the board id and the class, nothing else.

[AT-D4-4](#at-d4-4-the-credential-is-never-emitted) is the check, and it is written to be *able* to
find a leak rather than to assert one is absent.

### 5.2 No credential ⇒ nothing is written

A missing, malformed or refused (`401`/`403`) credential is a **degraded read**, and a degraded read
writes nothing at all — see [§ 7.3](#73-a-failed-poll-writes-nothing), which is the single most
important behavioural rule in this document after [§ 2](#2-the-rebuildability-question).

---

## 6. The read

### 6.1 The endpoint

```
GET {BOARD_API_BASE}/tasks/search.json?q=board_id%3D<id>&limit=200&page=<n>
Authorization: Bearer <token>
Accept: application/json
```

**Measured, not recalled** (2026-09-11, board 14; re-derivation in [§ 12](#12-every-number-and-where-it-comes-from)):
the response is `200` with `data` an array of card objects and `meta.last_page` an integer, and **every
row carries `assigned_user_id`**.

⚠ **This corrects a finding recorded on card#7582 on 2026-09-10** — *"`kbcard list` carries NO such
key"*. That was true and is still true, and it is a fact about **`kbcard`'s projection**, which emits
ten keys and drops the rest; it is **not** a fact about the API, which returns the field on every row
of the list endpoint. The distinction is load-bearing: designed around the earlier reading, this poller
would have had to `GET /tasks/<id>.json` once per card every tick — on this board, one request per card
instead of one for the board.

### 6.2 The fields consumed

| Field | Type | Used for | Example |
|---|---|---|---|
| `id` | int | `task.ref` = `"card#" . id`; the § 4.2 tie-break | `7582` |
| `name` | string | `task.title`, truncated — [§ 8.4](#84-truncation) | `"Board + GitHub task-title producers (…)"` |
| `assigned_user_id` | int \| null | **the join.** `null` ⇒ not a candidate | `null` |
| `updated_at` | rfc3339 | the § 4.2 ordering key; stored as `card_updated_at` | `"2026-09-10T18:26:00+00:00"` |
| `board_id` | int | stored, so the answering board is auditable | `14` |
| `archived_at` | rfc3339 \| null | **excluded when non-null** | `null` |
| `deleted_at` | rfc3339 \| null | **excluded when non-null** | `null` |

Every other member of the row — `description`, `tags`, `payload`, `position`, the stage and type ids —
is **read and discarded**. Nothing about a card's column, type, lane or tags reaches a desk: the desk
renders *what work item this agent is on*, and a board taxonomy on a floor would be a second rendering
of the board.

**Why `archived_at` / `deleted_at` are filtered here even though the default scope excludes them.** The
scope is the *remote server's* default, and a default is not a contract. This is a system boundary, and
filtering on two fields the response already carries is the boundary validation D2 § 6.3's nullability
convention asks for — not a defence against an unreachable state.

### 6.3 Pagination, and the false-clean rule

Read `page` from 1 while `page < meta.last_page`, capped at **200 pages** as a runaway guard (not a
truncation policy: 200 × 200 rows is far past any real board, so reaching the cap means the pager is
looping).

⛔ **Any of these makes the WHOLE POLL degraded — not the page, not the board, the poll:** a non-`200`
on any page; a body that is not an object, or whose `data` is not an array; a `meta.last_page` that is
absent or not a positive integer; a row that is not an object or whose `id` is not an integer; a
transport failure or timeout. A degraded poll writes nothing ([§ 7.3](#73-a-failed-poll-writes-nothing)).

The reason is the one `docs/KANBAN.md § G-1` names and D2 § 2.2's read posture is built on: a short
read and a genuinely small board have the same shape. Here they have the same shape **and** the same
effect — a card silently missing from page 2 is a seat whose title silently becomes someone else's, or
none.

### 6.4 A worked read

Request as § 6.1, board 14. Response, elided to the fields § 6.2 consumes:

```json
{ "data": [
    { "id": 7582, "board_id": 14, "name": "Board + GitHub task-title producers (…)",
      "assigned_user_id": null, "updated_at": "2026-09-10T18:26:00+00:00",
      "archived_at": null, "deleted_at": null },
    { "id": 9234, "board_id": 14, "name": "Drop tier 2 (the GitHub-sourced task title) — …",
      "assigned_user_id": 14, "updated_at": "2026-09-11T02:10:00+00:00",
      "archived_at": null, "deleted_at": null },
    { "id": 9208, "board_id": 14, "name": "Floor map artifact",
      "assigned_user_id": 14, "updated_at": "2026-09-09T11:04:00+00:00",
      "archived_at": null, "deleted_at": null }
  ],
  "meta": { "current_page": 1, "last_page": 1 } }
```

With `seats` holding one row at `board_user_id = 14`, seat `mezzanine/solo`: the candidates are 9234
and 9208; § 4.2 takes **9234** (greater `updated_at`). One row is written — `card_id = 9234`,
`title = "Drop tier 2 (the GitHub-sourced task title) — …"`, `card_updated_at = 2026-09-11T02:10:00.000`,
`board_id = 14`, `observed_at` = the poll's start stamp. Card 7582 is not a candidate: its
`assigned_user_id` is null.

### 6.5 A deliberately-invalid read, and what must happen

```json
{ "data": [ { "id": "7582", "assigned_user_id": 14, "name": "…" } ],
  "meta": { "current_page": 1 } }
```

Two defects, and **either one alone** is enough: `id` is a string where an integer is required, and
`meta.last_page` is absent so there is no trustworthy end-of-pages signal. Required behaviour: the poll
is **degraded**; **no row is written or updated, including `observed_at`**; `board_poll_failed`
increments; one log line names the board id and the class (`shape` / `pagination`), with no body and no
header; the command exits non-zero. ⛔ It must **not** coerce `"7582"` to `7582`, and it must **not**
treat a one-page response as complete because it looks like one.

---

## 7. The write

### 7.1 The input table

`seat_board_task` — one row per **mapped** seat. Semantics and writers here; the SQL type, index and
foreign key belong to D2 § 6.4 ([§ 13](#13-what-is-deliberately-not-built)).

| Field | Type | Null? | Meaning | Example |
|---|---|---|---|---|
| `seat_ref` | int, PK | no | `seats.id`. One row per seat, so the merge's read is a primary-key lookup | `3` |
| `card_id` | int | **yes** | the answering board card. **`NULL` means the board ANSWERED and this seat has no assigned card** — a positive fact, not an absence | `9234` |
| `board_id` | int | **yes** | which configured board answered; `NULL` exactly when `card_id` is | `14` |
| `title` | string ≤ the `task_title` bound | **yes** | the card `name`, truncated to `seat_state.task_title`'s own bound so the two can never disagree ([§ 8.4](#84-truncation)); `NULL` with `card_id` | `"Drop tier 2 (the GitHub-sourced task title) — …"` |
| `card_updated_at` | datetime(3) | **yes** | the board row's own `updated_at` — § 4.2's ordering key, stored so the choice is auditable after the fact. **Never the freshness basis** | `"2026-09-11T02:10:00.000"` |
| `observed_at` | datetime(3) | no | **server clock at the START of the poll that wrote this row.** D2 § 4.9's bound is measured from this, and it becomes `task.as_of` | `"2026-09-11T03:20:00.000"` |

**Why `observed_at` and not `card_updated_at` is the freshness basis.** The bound asks *how old is our
knowledge*, not *how old is the card*. A card nobody has touched for a week is perfectly current
information if we read the board a minute ago; a card edited a minute ago is worthless to us if we last
read the board yesterday.

**Why one `observed_at` for the whole pass, sampled once at the start.** Every mapped seat's bound then
expires on one basis. Stamping each row as it is written would make two seats' titles drop at different
moments for no reason anyone could explain from the data.

### 7.2 The upsert

One transaction per poll. For **every** seat with a non-null `board_user_id`: upsert its row with the
winning candidate's values, or with `card_id`, `board_id`, `title`, `card_updated_at` all `NULL` when
that user has no candidate — and `observed_at` set in both cases. A seat with no mapping gets no row
(and § 4.1's `--clear` deletes any it had).

⭐ **Writing the `NULL` row is the point, not bookkeeping.** *The board says this seat has nothing
assigned* and *we have not heard from the board* are different facts with different consequences — the
first falls through to tier 3 clean, the second must eventually drop a title and set `task.degraded` —
and the only thing that distinguishes them is a fresh `observed_at` on a row whose `card_id` is null.
Collapsing them into "no row" would make a dead poller indistinguishable from an empty board.

**A retired seat is skipped** (`seats.retired_at IS NOT NULL`), whatever its `board_user_id` still
says. Retirement takes a seat off every read surface in the transaction that sets the column
([D2 § 4.10](FLEET-STATE.md#410-retirement-is-a-rendered-state)), so polling one would be work for a
desk that does not exist — and, worse, a board fact arriving for a retired seat is the shape
[D2 § 4.10](FLEET-STATE.md#410-retirement-is-a-rendered-state) is emphatic about: nothing outside the
retirement act may write a retired seat's state.

**Retention.** One row per mapped, unretired seat — a population bounded by the seat count, not by
traffic — so it is retained like `seats` itself and **purged by nothing**. A row leaves in exactly two
ways: § 4.1's `--clear`, and a seat's retirement. That is a posture
[D2 § 6.7](FLEET-STATE.md#67-retention-and-purge) owes a line, and it is in
[§ 13](#13-what-is-deliberately-not-built)'s amendment list for that reason; a table with no stated
retention is a table § 6.7 is no longer total over.

The poll **never writes `seat_state`.** That is [§ 2](#2-the-rebuildability-question)'s whole
conclusion, and it is what a reviewer should check first.

### 7.3 A failed poll writes nothing

Not one row, not `observed_at`, not a null-out. The existing rows keep their stamps and age out under
D2 § 4.9's bound, which is the behaviour that makes a transient failure cost nothing and a real outage
cost exactly what it should, on a schedule that is already specified and already rendered.

⛔ **The inverse — writing `card_id = NULL` when the read failed — is the defect this rule exists to
forbid**, and it is the attractive one, because it looks like honest "we don't know". It is not: it is
indistinguishable from the board saying *no card*, so every title on the floor would vanish instantly
on one failed HTTPS request, `task.degraded` would never fire, and the floor would render a confident
fall-through to tier 3 with nothing anywhere saying the board was unreachable.
[AT-D4-3](#at-d4-3-an-unreachable-board-writes-nothing) is the test, and that is its RED.

---

## 8. The merge

### 8.1 The function

`StateRecompute::writeDerivedColumns()` today appends `taskTier3($seatRef, $currentCall)`. It appends
`task($seatRef, $currentCall)` instead:

```
task(seatRef, currentCall):
    row ← seat_board_task[seatRef]                     # PK lookup; may be absent
    dropped ← false
    if row exists and row.card_id is not null:
        if nowMs − ms(row.observed_at) ≤ BOUND:        # BOUND: D2 § 4.9 / § 12
            return { task_title:  row.title,
                     task_source: 'board_card',
                     task_ref:    'card#' + row.card_id,
                     task_as_of:  row.observed_at,
                     task_degraded: false }
        dropped ← true                                  # a value existed and was dropped
    t3 ← taskTier3(seatRef, currentCall)                # unchanged, card#9214's version
    return t3 + { task_degraded: dropped and t3.task_title is not null }
```

`taskTier3()` is **not modified**. It keeps its name, its number and its behaviour — D2 § 4.9's ruling
retires the number 2 rather than renumbering, precisely so that every existing *tier 3* reference in
this repository's code, tests and comments stays true.

Both writers reach it unchanged: the fold on every event, the sweeper on every seat every pass. The
sweeper is what makes the 30-minute drop **arrive** — a time-derived transition with no wire event
behind it, which is what D2 § 2.1 lists the sweeper for.

### 8.2 `task_degraded`, precisely

`true` **only** when a tier-1 value existed, was past its bound, and a lower tier answered in its place.

- No `seat_board_task` row, or a row whose `card_id` is null ⇒ **false**. Nothing was dropped; the seat
  is simply not board-titled. *Dark is not degraded.*
- Tier 1 dropped and tier 3 also has no title ⇒ **false**, and the whole `task` object is absent from
  the wire (`SeatObject` gates it on `task_title === null`). This follows card#9214's grouping rule:
  the group goes to null together, and a flag whose subject is absent is a value no consumer can read
  and no rebuild can be checked against.

### 8.3 `task_as_of`

`row.observed_at` on the tier-1 branch — a **stored** value, never `now()`. D2 § 8.2.1 makes
`task.as_of` non-nullable inside a `task` object that exists, and `observed_at` is `NOT NULL`, so the
branch cannot put a hole in the wire contract. This is card#9214's rule applied to a second input, and
it is also what makes [AT-D4-1](#at-d4-1-rebuild-equals-fold-with-a-board-sourced-title) green: a
rebuild re-reads the same stamp.

### 8.4 Truncation

`title` is truncated **once, at the poller**, to `seat_state.task_title`'s bound, with the same
`mb_substr` the tier-3 path uses — one bound, one place, so the input row and the projection can never
disagree about what the title is.

This is not theoretical. Measured on board 14, 2026-09-11, the longest card `name` is **more than
twice** the column's bound (derivation in [§ 12](#12-every-number-and-where-it-comes-from)). A poller
that refused an over-long title, or that let the database truncate it silently, would be wrong on a
meaningful fraction of a real board.

---

## 9. Failure paths

Every path, its posture, and the observable that makes it legible. Two fleet-scoped counters are owed
to D2 § 7.2 and to `GET /api/fleet/health`'s `counters` member: **`board_poll_ok`** and
**`board_poll_failed`**.

| Condition | Posture | Observable |
|---|---|---|
| `BOARD_IDS` empty / unset | **no-op, exit 0** | neither counter moves. Unconfigured is not failure, and a job that exited non-zero every five minutes on a fleet that has no board would train an operator to ignore it |
| Credential missing or malformed | **CLOSED** — no request issued | `board_poll_failed`; one log line naming the class, no value |
| `401` / `403` | **CLOSED** — nothing written | `board_poll_failed`; the class, the board id, the status. Never the body |
| Transport failure / timeout | **CLOSED** — nothing written | `board_poll_failed`; rows age out under D2 § 4.9's bound and every affected desk sets `task.degraded` within the bound |
| Non-`200`, bad shape, bad `meta.last_page`, page cap hit | **CLOSED** — nothing written, on **any** page | `board_poll_failed` + the class ([§ 6.3](#63-pagination-and-the-false-clean-rule)) |
| `200`, valid, **no assigned cards anywhere** | **OPEN** — a clean poll | `board_poll_ok`; every mapped seat gets a `card_id = NULL` row. This is today's state ([§ 10](#10-dark-on-arrival)) |
| A card assigned to a user no seat maps | **ignored, silently** | none, deliberately. The board is the operator's and carries assignments Mezzanine has no business having an opinion about |
| Two seats mapped to one board user | **unreachable** — the UNIQUE key refuses it at the mapping command | the command's non-zero exit |
| Store unreachable during the write | **CLOSED** — the transaction rolls back | `board_poll_failed`; nothing partial. D2 § 2.2's ingest-write posture, same reasoning |
| The poller is dead | **degrades visibly, per seat** | `task.degraded` within the bound on every board-titled seat ([§ 3.3](#33-if-it-dies)) |
| The poller **never worked** | **the one case the per-seat signal cannot see** | `board_poll_ok` stuck at `0` on `GET /api/fleet/health` — there is no dropped value to label, so the counter is the instrument |

**Why a `fleet.board` health member is NOT added, having been considered.** It was the first design: an
enum beside `fold` and `sweep`. It loses on price against what it adds. D2 § 8.2.4's health object
rides **every** snapshot and a heartbeat every 15 s, its field count is stated in that section's prose,
and its members' sizes feed D2 § 12's measured byte figures and the verifier that re-derives them — so
one enum costs a wire-contract change, a prose count, and a re-measure of the worst-case delta. Against
that, the per-seat `task.degraded` signal already covers every case except *never worked*, and D2
§ 8.2.4 states the sanctioned home for an operator-only instrument in terms: the `counters` member of
`GET /api/fleet/health`, *"polled by an operator"*, which is exactly who asks this question.
⇒ two counters, no wire change. Recorded here so the option is not silently re-litigated.

---

## 10. Dark on arrival

Three independent conditions must hold before one board title reaches one desk. **None holds today**,
and the design's obligation is that each is separately legible rather than collapsing into one
undiagnosable silence.

| # | Condition | State, measured 2026-09-11 | How its absence reads |
|---|---|---|---|
| 1 | A credential and `BOARD_IDS` are configured | absent | `board_poll_ok` stays `0`; the job no-ops at exit 0 |
| 2 | At least one seat carries a `board_user_id` | absent — the column does not exist yet | polls succeed, write nothing, `board_poll_ok` climbs |
| 3 | At least one card carries a non-null `assigned_user_id` | **zero across the whole of board 14** — not a sample: `meta.last_page` is 1, so one request is the entire population (derivation in [§ 12](#12-every-number-and-where-it-comes-from)) | every mapped seat gets a `card_id = NULL` row; every desk falls through to tier 3 with `task.source: "telemetry"` and `task.degraded: false` |

⭐ **The floor is honest in that state without anything being added**, because D2 § 4.9 put
`task.source` on the wire for this exact purpose: *"a floor showing tier 3 everywhere is visibly a
floor whose board integration is dark, rather than a floor that looks fine."*

⛔ **A title is never fabricated, stubbed, defaulted or back-filled to make tier 1 visible.** Condition
3 is the operator's workflow convention, ruled on 2026-09-10 and coming; `kbcard patch --assign` is
merged upstream and not in this seat's released toolkit. Building the producer dark is correct, and
[AT-D4-7](#at-d4-7-dark-is-not-an-error) is the test that dark is a **clean** state rather than a
degraded one.

---

## 11. Acceptance tests

*What to build, what to break, and what RED looks like — so seen-to-fail is designed in.*

### AT-D4-1 rebuild equals fold with a board-sourced title

*The central property. It widens AT-D2-10's fixture; it adds no exclusion to it.*

- **Build:** a seat with `board_user_id` set and a `seat_board_task` row inside the bound; replay
  AT-D2-10's event fixture through the live fold; snapshot every projection row; run
  `mezzanine:rebuild --seat=…`; compare on AT-D2-10's terms.
- **GREEN:** AT-D2-10's three named exclusions and no others. All five `task_*` columns are identical,
  `task_source` reads `board_card` on both sides, and the rendered object is byte-identical.
- **RED:** make the poller write `seat_state.task_*` directly instead of `seat_board_task`. `reset()`
  nulls them, nothing restores them, and the comparison diverges on five columns — which is
  [§ 2.1](#21-the-problem)'s defect, reproduced on demand.
- **Second RED:** have `reset()` truncate `seat_board_task`. The same divergence arriving from the
  other side, and it is the guard against a future reset that "tidies up" another writer's input.
- **Discriminating control:** the same comparison on a seat with **no** `seat_board_task` row must
  report **zero** differences, so the comparison is known to be capable of reporting equality.

### AT-D4-2 a stale board row is dropped, never rendered

- **Build:** a `seat_board_task` row with a title and `observed_at` past the bound, over a seat with a
  live tier-3 title; run one sweep pass.
- **GREEN:** `task_source = telemetry`, `task_title` is the tier-3 title, `task_ref = null`,
  `task_degraded = true`. The board title appears **nowhere** in the rendered object.
- **RED:** move `observed_at` to inside the bound → `board_card`, the card title, `task_ref` the
  `card#NNNN` form, `task_degraded = false`.
- **Control:** run the pair at bound − 1 s and bound + 1 s, so the assertion is on the boundary and not
  on an arbitrary distance from it.

### AT-D4-3 an unreachable board writes nothing

- **Build:** seed a fresh `seat_board_task` row; point `BOARD_API_BASE` at a host that refuses the
  connection; run the poll. Repeat for `401`, for a `500` on page 2 of a two-page read, and for a body
  with no `meta.last_page`.
- **GREEN:** non-zero exit; `board_poll_failed` + 1 on each; the row is **byte-identical**,
  `observed_at` included; the desk still renders its board title until the bound, then drops it with
  `task.degraded`.
- **RED:** a poller that writes `card_id = NULL` on a failed read — the title vanishes on the first
  failure and `task.degraded` never fires ([§ 7.3](#73-a-failed-poll-writes-nothing)).
- **Control:** the same fixture with the board **reachable** must update `observed_at`, so the test is
  known to be able to see a write.

### AT-D4-4 the credential is never emitted

- **Build:** run every [§ 9](#9-failure-paths) failure path with maximum verbosity, capturing stdout,
  stderr, the application log and the command's own argv.
- **GREEN:** the token value occurs **zero** times in all four; the log lines carry a status, a class
  and a redacted URL.
- **RED, and it is required before the green is trusted:** move the token into the URL query string.
  The check must find it in the logged URL. A check for an absent string that has never once seen the
  string present is a decoration.

### AT-D4-5 two cards, one seat, one deterministic answer

- **Build:** two candidate cards for one board user, differing `updated_at`; then a second fixture with
  identical `updated_at` and differing `id`.
- **GREEN:** greatest `updated_at` wins; on the tie the greater `id` wins; a second poll over unchanged
  input leaves `card_id` and `title` unchanged.
- **RED:** remove the `id` tie-break — the tied fixture's answer flaps between runs, which is the desk
  title oscillating with nothing having changed.

### AT-D4-6 an unmapped seat is untouched, and a long title is truncated

- **Build:** one mapped seat and one unmapped seat; the mapped seat's card carries a `name` longer than
  the column bound (the real board supplies one — [§ 8.4](#84-truncation)).
- **GREEN:** the unmapped seat has no `seat_board_task` row before or after, and its `task_*` columns
  are exactly what tier 3 produced; the mapped seat's `title` is the truncated form and equals
  `seat_state.task_title` **exactly**.
- **RED:** drop the truncation — the insert raises or the database truncates silently, and the two
  values disagree.

### AT-D4-7 dark is not an error

- **Build:** a reachable board on which **no** card carries `assigned_user_id`; at least one mapped
  seat with a live tier-3 title.
- **GREEN:** exit 0; `board_poll_ok` + 1 and `board_poll_failed` unmoved; every mapped seat has a row
  with `card_id = NULL` and a fresh `observed_at`; every desk renders `task.source: "telemetry"` with
  `task.degraded: false`.
- **RED:** treat "no candidates" as a failed poll — `board_poll_failed` climbs forever against a
  perfectly healthy board, which is the alarm that trains an operator to ignore alarms.

### AT-D4-8 clearing a mapping clears the row

- **Build:** a mapped seat with a board title on its desk; run
  `mezzanine:seat-board-user --seat=… --clear`.
- **GREEN:** `seats.board_user_id` is null **and** the `seat_board_task` row is gone, in one
  transaction; the next recompute renders tier 3 with `task_degraded = false`.
- **RED:** null the column and leave the row — the merge goes on answering from a card the seat is no
  longer joined to, and no poll will ever correct it because the poller no longer writes that seat.

---

## 12. Every number, and where it comes from

**Cited** = another document's number, used unchanged. **Derived** = computed from another number here
or in D2. **Chosen** = a judgement call, with what would re-derive it. **Measured** = produced by
running the command in the cell, which is what a reader re-runs rather than trusting the figure.

| Value | Number | Basis | Where |
|---|---|---|---|
| Board poll cadence | **5 min** | **Chosen** — bounded above by an operator's tolerance for a card move reaching the floor, below by board API load; the two failures at 15 min and 1 min are argued, with the measured payload, in [§ 3.2](#32-the-cadence-derived--and-the-30-minute-bound-re-derived) | [§ 3.1](#31-mezzanineboard-poll) |
| Tier-1 freshness bound | **30 min** | **Derived** — 6 × the cadence ⇒ five consecutive failed polls tolerated, with a floor of *strictly more than two cadences* so one lost request cannot clear the floor. ⭐ **This re-derives D2 § 12's "Chosen, provisional" row, as that row asks; the figure is unchanged and its basis is not** | [D2 § 4.9](FLEET-STATE.md#49-the-task-title-merge-and-what-is-not-specified-here) |
| Search page size | **200 rows** | **Cited** — the search endpoint's documented maximum (its default is 50), from the board API's own `/docs.openapi`; a whole-board enumeration's only cost axis is round-trips, so take the max. ⚠ Cited from a live document outside this repository, which is the one class of citation nothing here can re-derive — the poller must therefore treat a page that returns more rows than it asked for as a shape failure like any other | [§ 6.3](#63-pagination-and-the-false-clean-rule) |
| Page cap | **200 pages** | **Chosen** — a runaway guard, not a truncation policy: 200 × 200 is far past any real board, so reaching it means the pager is looping | [§ 6.3](#63-pagination-and-the-false-clean-rule) |
| `title` bound | `seat_state.task_title`'s own | **Cited** — D2 § 6.4. Never restated as a figure here: one bound, one home ([§ 8.4](#84-truncation)) | [§ 7.1](#71-the-input-table) |
| Whole-board payload, board 14 | **Measured 2026-09-11** | `GET /tasks/search.json?q=board_id%3D14&limit=200&page=1` → `200`; then `len(json.dumps(body))`, `len(body["data"])`, `body["meta"]["last_page"]`. ~340 KB on one page. **Re-run it rather than trusting this cell** | [§ 3.2](#32-the-cadence-derived--and-the-30-minute-bound-re-derived) |
| Assigned cards on board 14 | **Measured 2026-09-11** | the same request; `sum(1 for r in body["data"] if r["assigned_user_id"] is not None)` — and `meta.last_page == 1` is what makes it the **population** rather than a sample | [§ 10](#10-dark-on-arrival) |
| Longest card `name` on board 14 | **Measured 2026-09-11** | the same request; `max(len(r["name"]) for r in body["data"])` — more than twice the `title` bound, which is why [§ 8.4](#84-truncation) is load-bearing | [§ 8.4](#84-truncation) |

---

## 13. What is deliberately not built

⛔ **Nothing in this document is implemented by the card that wrote it, and that is a decision with a
reason rather than an unfinished job.**

Every structural piece needs an amendment to **D2**, a shipped and verifier-gated contract:

| Amendment | Section |
|---|---|
| A process row for `mezzanine:board-poll`, and one for `mezzanine:seat-board-user` | D2 § 2.1 |
| `seats.board_user_id`, and the `seat_board_task` DDL | D2 § 6.4 |
| `board_poll_ok` / `board_poll_failed`, and their `Exposed` cells | D2 § 7.2, § 8.2.4's `counters` list |
| § 4.9's *"designed in no document in this repo"* sentence, which this document falsifies | D2 § 4.9 |
| § 12's tier-1 bound row: **Chosen, provisional** → **Derived**, per [§ 3.2](#32-the-cadence-derived--and-the-30-minute-bound-re-derived) | D2 § 12 |
| § 1.2's non-goal row and § 14 item 3, which name the poller as designed nowhere | D2 § 1.2, § 14 |
| `seat_board_task`'s retention posture — retained like `seats`, purged by nothing ([§ 7.2](#72-the-upsert)) — without which § 6.7 is no longer total over the store | D2 § 6.7 |
| A sizing line for the same table: bounded by the seat count rather than by traffic, so it changes no figure in § 6.8 — which is itself the statement § 6.8 owes | D2 § 6.8 |

**D2 § 6.4 states the gate in its own words: *"Names are final; a builder may reorder columns and add
nothing."*** A migration adding a table that § 6.4 does not carry is a builder adding something, and a
poller written against it is code written against an unratified design. The amendments are stated as
exact text on this card's pull request, under a heading that says they are not applied.

Two further reasons hold independently of ratification, so that the gate is not the only thing standing
here:

- **The positive path cannot be validated on a real surface.** No board card is assigned and
  `kbcard patch --assign` is not in this seat's released toolkit, so tier 1's answering branch could be
  exercised only against a fixture. A producer whose one interesting path has never run against the
  system it reads is not a producer anyone should trust: a synthetic test is not a substitute for the
  first real exercise of a boundary, which is a rule here rather than a preference.
- **The credential does not exist.** Issuing a read-scoped board token for the server is an operator
  act.

⚠ **One optimization is named and deliberately not taken:** filtering server-side with an
`assigned_user_id` predicate in `q`, which would cut the per-poll payload by orders of magnitude. It
cannot be validated today — with zero assigned cards on the board, a working filter and a
silently-ignored-or-erroring one both return the same empty result, so there is no control that
discriminates them, and a filter whose failure mode is *returns nothing* is indistinguishable from
*nothing is assigned*. It is the first thing to revisit once a single assigned card exists to test
against.

---

## 14. Open questions

1. **⇢ Operator — the credential.** A read-scoped board token for the Mezzanine server, distinct from
   any seat's. **Blocks:** condition 1 of [§ 10](#10-dark-on-arrival). **Closes it:** the token, and the
   board ids it may read.
2. **⇢ Operator — the seat→board-user values.** The mechanism is [§ 4.1](#41-seat--board-user); the
   values are a declaration only the operator can make. **Blocks:** condition 2.
3. **⇢ Review — the amendments in [§ 13](#13-what-is-deliberately-not-built).** **Blocks:** every line
   of implementation. **Closes it:** ratification.
4. **⇢ Owed — this document's own verifier.** D1, D2 and D3 each have one, wired into
   `.github/workflows/design-doc-verifiers.yml`; **this document has none**, so no gate re-derives
   its numbers, resolves its anchors or holds its tables against its worked examples.
   `tools/design/README.md` declares the gap on the surface a reader of those gates checks.
   **Blocks:** nothing today — the document is unbuilt. **Closes it:** ratification, then D4's
   verifier in its own round, which is the rule that README states for every gate.
5. **⇢ Deferred — the server-side assignee filter** ([§ 13](#13-what-is-deliberately-not-built)).
   **Blocks:** nothing. **Closes it:** one assigned card, which makes a discriminating control possible.

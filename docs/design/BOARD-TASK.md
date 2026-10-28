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
lists *"Ingest of kanban board events…"* among its non-goals. ⚠ **Two sentences quoted here in an
earlier revision are gone from D2, because this document is what removed them** — until the
amendments below were ratified (`card#7582`, 2026-09-12) § 1.2 recorded that the kanban poller was
designed nowhere yet, and § 14 item 3 asked for a ruling on where the board producer is designed.
Both now name this document instead, in D2's own text, and the quotations are recorded as history
rather than left standing as citations of a file that no longer says it.

**This document is that ruling's answer, and the producer.** In one sentence: a scheduled command
reads the configured kanban boards over HTTPS, joins each card's `assigned_user_id` to a seat through
a mapping an operator declares, and writes the answer into a **durable input table** — never into
`seat_state`. The fold and the sweeper then derive the five `task_*` columns from that table exactly
as they already derive them from `calls`, which is what makes the value reproducible by a rebuild
with no new event kind, no change to `RebuildCommand::reset()`, and no exclusion added to
[AT-D2-10](FLEET-STATE.md#at-d2-10-rebuild-equals-fold). [§ 2](#2-the-rebuildability-question) is the
argument for that, and it is the reason this document exists in the shape it does.

✅ **The poller is built** (`card#11289`): `mezzanine:board-poll`, `mezzanine:seat-board-user` and
the tier-1 branch of the merge, against the D2 amendments RATIFIED and applied on 2026-09-12
(`card#7582`) — D2 § 6.4 carries the store shape and the two counters have their rows. The store
itself shipped first, with the ratification, because retirement clears the board-user mapping in its
own transaction ([§ 7.2](#72-the-upsert)) and had nothing to write against otherwise.
[§ 13](#13-what-is-deliberately-not-built) records where each amendment landed and what is still
deliberately not built.

⭐ **card#12447 — BUILT 2026-10-28 (operator ruling 2026-10-26).** *"When an agent is set up,
(A — link agents to board users) should occur automatically. If an agent doesn't connect to a
board, then B should occur automatically (not opt-in). The monitor continues to show (C)."* This
document's half of that ruling is **A**: the seat→board-user link stops being a mapping an operator
types and becomes a **declaration the seat makes and the poller confirms**
([§ 4.4](#44-the-link-pass--automatic-mapping-from-the-seats-declaration-card12447)), with the
command of [§ 4.1](#41-seat--board-user) kept as the operator's pin. **B** — the to-do tier — is
D2's and D1's ([D2 § 4.9](FLEET-STATE.md#49-the-task-title-merge-and-what-is-not-specified-here)
tier 4, [D1 § 6.16](EVENT-SCHEMA.md#616-todoprogress)); **C** changes nothing. Every section this
card amends says so in its own text; [§ 13](#13-what-is-deliberately-not-built)'s second table is
where each amendment's state is read.

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
| **The compose path** — the ONE write this product makes to the board, its credential, its target rule, its channel seam, and the hop it cannot verify | [§ 15](#15-the-compose-path--one-verb) (`card#9417`) |

### 1.2 Non-goals — stated so an implementer cannot widen scope in good faith

| Not in this contract | Why, and who owns it |
|---|---|
| **The tier table, the precedence, the `task.*` wire members, the 30-minute bound** | [D2 § 4.9](FLEET-STATE.md#49-the-task-title-merge-and-what-is-not-specified-here) and [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object). This document produces an input; it does not re-specify the merge's contract. Where it needs one of those facts it cites the section and does not paraphrase it. |
| **Anything GitHub-sourced** | Nothing is owed. Tier 2 was the GitHub-sourced title and it is **retired** by operator ruling (card#9234) — the number 2 with it. The coordination producer ([D1 § 18](EVENT-SCHEMA.md#18-the-coordination-event-producer)) and the thread line it feeds are untouched by that retirement and by this document. |
| **Writing to the board — with ONE exception, owned by [§ 15](#15-the-compose-path--one-verb)** | The poller's credential is read-scoped ([§ 5](#5-the-credential)) and the poller issues `GET` only: **the poller** never moves a card, never assigns one, and never comments. ⭐ **Amended on `card#9417`.** Until that card this row read *"Mezzanine never moves a card, never assigns one, and never comments"*, and the product now makes exactly one write to the board: **a comment, authored by a signed-in operator (`can:operate`, card#9415) and posted under a named read/write board account the floor holds, on the card the seat's desk is showing** — never a move, never an assign, never a comment on any other card, and never a write that a timer, a poll or an inference originates. It rides a second, write-scoped credential the poller never reads ([§ 5.3](#53-the-compose-credential)). The sentence this row carried — *a floor that could edit its own subject would be an instrument inside the thing it measures* — still stands as the rule, and [§ 15.3](#153-the-one-verb-and-why-it-is-not-an-instrument-inside-the-measurement) is the argument that this verb does not breach it: the floor derives a seat's state from telemetry and from the card fields [§ 6.2](#62-the-fields-consumed) lists; a comment writes none of them directly, and the one it moves as a side effect — `updated_at`, **measured** ([§ 14](#14-open-questions) item 9) — is [§ 4.2](#42-which-card-answers-when-several-could)'s ordering key, so the compose path reads the board live before it writes and refuses, with no write, unless the card the operator was shown is still the seat's winner; what remains is the window between that read and the write, stated with its figure in § 15.3 rather than rounded away. |
| **Deciding that cards get assigned** | An operator workflow convention, ruled on 2026-09-10 (card#7582). This document designs against `assigned_user_id` being populated; it does not populate it and does not backfill it. |
| **Alerting** | [D2 § 1.2](FLEET-STATE.md#12-non-goals--stated-so-an-implementer-cannot-widen-scope-in-good-faith)'s position, inherited deliberately: a degraded poll surfaces as a counter and as `task.degraded` on the wire, and there is no notifier. |
| **A second producer for the same fact** | There is one board producer and there must not be a second — the rule [D1 § 18.11](EVENT-SCHEMA.md#1811-one-producer-and-the-one-consumer-it-serves) states for its own producer, applied here for the same reason. |

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
2. **D2 asked for the ruling.** [§ 14](FLEET-STATE.md#14-open-questions-for-the-review-loop) item 3
   carried *a ruling on where the board producer is designed* as an open question. This document is the
   answer; the ratification of 2026-09-12 wrote it into that item, which is why the words are no longer
   quotable from it — the last of its three producer questions is closed.
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

AT-D2-10 asserts **reproducibility**. D2 § 6.6 used to *explain* it with a narrower sentence — a fold
rule "reading state that is not in the log" — and those are not the same property. ⭐ **That gap is
now closed in D2's own text and not only in this one** (`card#7582`, ratified 2026-09-12): § 6.6 and
AT-D2-10's RED both state the rule in full — *"the fold reads only `events` and the durable inputs a
rebuild does not destroy (`seats`, `seat_board_task`)"* — so the narrow sentence is no longer live
anywhere to be read as the definition, which is what an amendment owes rather than leaving two
readings standing. What follows is the argument that produced it. **The repository already relied on
the difference before this document was written — and on inspection the clean precedent is ONE:**

- **`seats.retired_at` / `retired_by` / `retired_reason`.** Operator-written, in no event, read by the
  fold on every recompute (`SeatFacts::for()` → `Derivation::render()`), and **not touched by
  `reset()`**. A rebuild re-reads the same durable row and reaches the same answer, so AT-D2-10 is
  green over them.
- ⚠ **`seat_state.badge_first_seen` is NOT a second precedent of this shape, and an earlier revision
  of this section counted it as one.** It is explicitly *not* reset, with the reason argued in
  `reset()`'s own comment — *"a badge that has been up since Tuesday has been up since Tuesday
  whether or not its seat was rebuilt this afternoon."* — but it is a **projection** column carved out
  of the reset, which is option (b)'s shape, not a durable INPUT the fold reads. It supports the
  conclusion by analogy only, and the load-bearing precedent is `seats`, on its own.

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
cost a member of `bin/supervision.sh`'s supervised set, with the crontab entries it renders. `->withoutOverlapping()` for the same reason `purge` carries it: a slow board must
not start a second poll beside the first.

⛔ **Two bounds are part of that choice rather than deployment detail, because without them
`withoutOverlapping()` converts one stuck request into a stalled integration.** Every HTTP request
carries the timeout [§ 12](#12-every-number-and-where-it-comes-from) states, so a hung board fails
the poll and increments `board_poll_failed` instead of hanging it; and the overlap lock carries an
**expiry below** [D2 § 4.9](FLEET-STATE.md#49-the-task-title-merge-and-what-is-not-specified-here)'s
30-minute bound, because the framework's default is a day — a poll killed mid-run would otherwise
hold the lock long after every title it was protecting had aged out.

**D2 § 2.1 owes it a row** ([§ 13](#13-what-is-deliberately-not-built)). That table *"is what a host is
provisioned from"*, and card#9181's finding is exactly what happens to a process that is built without
one — the feed heartbeat ran as a sixth process against a table of five, and a host provisioned from
the document supervised everything except it.

### 3.2 The cadence, chosen — and the 30-minute bound, re-derived

D2 § 12 carried the tier-1 freshness bound as **"Chosen, provisional — … re-derived once the board
producer exists and its poll cadence is known ([§ 14](FLEET-STATE.md#14-open-questions-for-the-review-loop)
item 3)"** until the ratification of 2026-09-12 replaced that cell with the derivation below. The
re-derivation is owed here, so it is done here, and it is done in the honest direction: **the cadence
is settled first, because it is the number with a real cost, and the bound is measured in cadences.**
⛔ **"Settled", not "derived": the cadence is a JUDGEMENT and this document's own
[§ 12](#12-every-number-and-where-it-comes-from) calls it `Chosen`.** An earlier revision of this
section called it derived, in a document whose number table said otherwise on the same page — and a
cost argument that rules two alternatives out is what a judgement call looks like when it is made
well, not a computation.

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
granularity. ⇒ D2 § 12's row loses its **provisional** and states this derivation, **staying
`Chosen`** — because that table's `Derived` means *computed from another number in that table or in
D1*, and the cadence is in neither; in THIS document's table the same figure is `Derived`, from a
cadence that is in it. One figure, two tables, each using its own published vocabulary, and the
difference is stated here so it cannot be read as drift. ⭐ **The published figure survives its own
re-derivation** — worth stating plainly, because a re-derivation that confirms is evidence the
original judgement was sound and is not the same thing as never having checked. ⚠ And it is a
judgement the multiple cannot launder: six was picked to land on a bound that already existed, so
what the arithmetic buys is a stated floor — strictly more than two cadences — and a standing
obligation to re-derive when the cadence moves, which is what D2 § 12's row now carries.

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

**Its writers are two, since card#12447: the operator's command below, which PINS a
value, and the board poll's link pass ([§ 4.4](#44-the-link-pass--automatic-mapping-from-the-seats-declaration-card12447)),
which writes a value the seat declared and the board confirmed.** `seats.board_user_source`
([D2 § 6.4](FLEET-STATE.md#64-ddl)) says which: `operator` for the command's pin,
`declared` for the pass's write, `refused` for the command's `--refuse` — a `NULL` mapping the pass may
not fill — and `NULL` exactly when the column is `NULL` and no refusal stands. Until card#12447 the command was the only writer, on
the exact model of `mezzanine:retire` — an administrative fact that no timeout, poll or inference may
set — and that sentence narrows rather than dies: **no timeout and no inference** writes the column
still; what the pass writes is a fact two parties stated, the seat and the board, and the pass is a
checker between them, not an inference. The command:

```
php artisan mezzanine:seat-board-user --seat=<install>/<seat> --board-user=<id>
php artisan mezzanine:seat-board-user --seat=<install>/<seat> --clear
php artisan mezzanine:seat-board-user --seat=<install>/<seat> --refuse      # card#12447
```

⛔ **ANY write of the column — `--clear` or `--board-user` — deletes that seat's `seat_board_task`
row IN THE SAME TRANSACTION.** Two writes, one act. For `--clear` the reason is that a cleared mapping
which left the input row behind would leave the merge answering from a card the seat is no longer
joined to; for `--board-user` on an ALREADY-mapped seat it is the same defect with a shorter fuse —
the row still holds the previous user's card, and it would answer for up to one poll cadence before
the next poll overwrote it, which is a desk showing another person's work with nothing degraded and
nothing to see. The rule is stated over the COLUMN rather than over the `--clear` flag for that
reason: a rule that named the flag would be true of one of the two writes. The deletion is the
refusable-first half (nothing is un-set until the row is gone). An unknown seat is refused with a
non-zero exit before anything is written; a `--board-user` that is not a positive integer likewise.

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

### 4.4 The link pass — automatic mapping from the seat's declaration (card#12447)

*Built on card#12447 (`App\Board\LinkPass`). The operator's A: the link is automatic at set-up. Before
it one seat of the fleet was mapped, by hand (`SELECT seat_id FROM seats WHERE board_user_id IS NOT NULL` on the
sandbox re-prints the set), and every other seat's tier 1 is dark for want of an operator typing an id
nobody but the seat's own machine can read off a board.*

**Where a seat's board identity comes from, measured.** The seat's machine holds the kanban
credential the agent works the board with — on a coordination-framework seat, the credential store's
`[kanban] api_token_file` pointer, the one `kbcard` discovers — and that credential answers the
board's `GET /users/current.json` with the integer `data.id` the board's own `assigned_user_id`
names (**MEASURED 2026-10-27 on this seat: `200`, `data.id` an integer, equal to the id card#12447
itself is assigned to**). So the reporter's installer asks the board *who am I* once and writes the
answer into the seat config, and the seat declares it on every heartbeat beside its API base's
fingerprint — [D1 § 3.1](EVENT-SCHEMA.md#31-the-seat-config-file)'s *`board_user_id` and
`board_api_base`*, [D1 § 6.14](EVENT-SCHEMA.md#614-reporterheartbeat)'s two members `board_user_id` and `board_fingerprint`. The fold
stores the last heartbeat's pair on `seat_state` as `board_user_declared` /
`board_fingerprint_declared` ([D2 § 6.4](FLEET-STATE.md#64-ddl)), **off the seat
object** and read by one consumer: this pass.

**What the pass does, every poll, before the card read — in two phases.** **Phase 1, outside any
transaction:** the members read ([§ 6.6](#66-the-members-read)) and the card read's pages
([§ 6.1](#61-the-endpoint)) — every HTTP request the poll makes — and, from a plain read of `seats` and
`seat_state`, the **candidate set**: every unretired seat with a declaration, each declaration judged
by the site and board legs of step 4 below. **Phase 2, one transaction:** lock every `seats`
row the pass may write `FOR UPDATE`, in `seats.id` order — the order [§ 4.1](#41-seat--board-user)'s
command and the retirement act take, `seats` then `seat_board_task` — **re-read** each locked seat's
`board_user_id`, `board_user_source` and its declaration columns; a declaration that differs from
what phase 1 judged is **skipped this poll** (phase 1's member data cannot vouch for a user it did not
see), and for the rest every leg of step 4 is **re-run** inside the lock over phase 1's member data
and what the locks now show; then write, and commit with the card read's upsert. Nothing waits on the network while a
row is locked, and a declaration or a pin that landed between the two phases is seen by the re-read
rather than overwritten. Over each candidate seat:

1. **Pinned and refused seats are skipped.** `board_user_source = operator` means an operator said
   which user; `refused` means an operator said *none, and do not honour what it declares* — a
   human's account, another install's seat reporting to this site. A declaration outranks neither.
   The command's `--clear` is the way back to automatic for both.
2. **A seat declaring nothing clears a `declared` mapping — on a reporter that sends the members.**
   `board_user_declared IS NULL` with `board_user_source = declared` ⇒ write `NULL` to both columns and
   delete the seat's `seat_board_task` row in the same transaction — the rule
   [§ 4.1](#41-seat--board-user) states over any write of the column — counting `board_link_cleared`.
   ⚠ **`NULL` cannot say whether the heartbeat omitted the member or declared none** — the ingest
   normalises a missing key to `null` ([D1 § 6.0](EVENT-SCHEMA.md#60-conventions-and-how-harness-payloads-are-read),
   *Missing vs null*) — so the clear runs only when the seat's last batch's `reporter_version`
   ([D2 § 6.4](FLEET-STATE.md#64-ddl) `seat_state.reporter_version`) is at least the build whose
   heartbeat carries the members on every send: `SchemaVersions::reporterAtLeast($version, '0.5.0')`
   (`SchemaVersions::TODO_AND_BOARD_DECLARATION_FROM`),
   a primitive beside `reporterIsOutdated()` — numeric precedence per component, an
   unparseable or null version answering `false` — and **not** `reporterIsOutdated()`, which compares
   against `CURRENT_REPORTER_VERSION` and would stop clearing the day the current build moved past
   `0.5.0` (AT-D4-12's third case). A seat on an older reporter, or one
   with no batch yet, is left exactly as it is: silence is not a declaration of anything.
3. **A declaration equal to the seat's current `declared` mapping is already done** — nothing is
   written, nothing counted.
4. **Otherwise the declaration is checked, in this order, and the first refusal wins:**
   - **the site.** `board_fingerprint_declared` must equal the first 16 hex of SHA-256 over this
     site's own `BOARD_API_BASE`, normalized exactly as
     [D1 § 6.14](EVENT-SCHEMA.md#614-reporterheartbeat)'s `board_fingerprint` member states — scheme and host
     lowercased, a default port removed, trailing slashes removed; userinfo is refused at the reporter's
     `loadConfig`, so none reaches either side. Unequal ⇒ `board_link_site_mismatch`. The seat works
     another kanban, and an id that coincides on it is the join-by-coincidence
     [§ 4.1](#41-seat--board-user) refuses.
   - **the board.** The user must be a member of at least one board in `BOARD_IDS`, and not a
     `disabled` one — read from `GET /boards/{id}/members.json` under the poller's read token
     ([§ 6.6](#66-the-members-read)). Not found, or disabled ⇒ `board_link_unknown_user`: no card on
     these boards can ever be assigned to that user, so a mapping would be a join to nothing.
   - ⛔ **No account-type leg** (seat ruling on card#12447, 2026-10-28, reversible, recorded on the card).
     The members objects carry `account_type`, and its values on board 14 were **measured on 2026-10-28
     as `human` and `service`** ([§ 12](#12-every-number-and-where-it-comes-from)) — with this project's
     own agent login among the `human` ones. Agent logins are provisioned as ordinary users, so a gate
     on the type would refuse the very seats the operator asked to link (*"yes each PM has their own
     login"*, [§ 14](#14-open-questions) item 12). The pass therefore reads no type. **The control
     against a wrong declaration — a seat declaring a person's id, whose cards would then reach its
     desk and its compose box ([§ 15](#15-the-compose-path--one-verb)) — is the operator's**:
     `mezzanine:seat-board-user --board-user` pins the right user and `--refuse` refuses the
     declaration ([§ 4.1](#41-seat--board-user)), and the fleet leg below refuses any user a second seat
     holds or declares.
   - **the fleet.** No other unretired seat may hold the user, pinned or declared — and no other
     member of the **contention set** may declare it. The contention set is defined, not left to the
     read order: *every declaration that passed the site and board legs this pass, from a
     seat step 1 did not skip*. Two members of it declaring one free user map **neither**; a seat
     the operator has since pinned or refused is not a member, so a `--refuse` on one of two
     contenders resolves the contention at the next poll and the other maps. For every conflict the
     poll writes **one log line naming both seats and the user** (`board link conflict: user 14
     declared by aimla/impl-2, held by aimla/impl-1` — seat ids and a board user id, nothing from the
     board's response), so an operator reads the pair without a query. Held, or contended ⇒ `board_link_conflict` on
     **every** refused seat, the holder untouched. This is the one state no seat can detect — a config
     copied to a second machine, a replacement seat whose predecessor was never retired — so it is
     counted where the operator will look rather than resolved by a rule that picks a winner. The
     UNIQUE key is the backstop beneath the check: a violation it raises at the write, from a mapping
     landed between the check and the write, is **treated as this same conflict** — counted, the
     seat's write rolled back, the pass continued — never an error that ends the poll.
5. **A refused declaration that CHANGED clears the mapping it no longer declares.** A seat mapped by
   declaration to user A, now declaring user B, whose B is refused by any leg above, keeps nothing: in
   the same transaction `board_user_id` and `board_user_source` go to `NULL` and the row is deleted,
   counting `board_link_cleared` beside the refusal. A desk showing A's cards while the seat says it is
   B would be a mapping the seat has withdrawn and the floor kept.
6. **A declaration that passes is written**: `board_user_id` = the declared id,
   `board_user_source = declared`, the seat's `seat_board_task` row deleted in the same transaction
   (it held the previous user's card, if any), counting `board_link_mapped`. The card read that
   follows in the same poll writes the new row.

**Why the command and the pass cannot interleave badly, stated by mechanism and not by a lock the
scheduler does not hold.** `->withoutOverlapping()` serialises polls against polls and nothing else;
the command runs whenever an operator runs it. What keeps the two honest is the row lock both take on
the `seats` row before either reads it — the command's `lockForUpdate()` ([§ 4.1](#41-seat--board-user))
and the pass's `FOR UPDATE` in `id` order — so each sees the other's committed write or waits for it,
and the UNIQUE key refuses the one interleaving a lock cannot (two transactions choosing one free user
for two different seats), which step 4 counts as the conflict it is.

**Re-homed seats.** A seat is retired and re-created under a new `seat_id` — the fleet's ordinary
move. Retirement already clears the old seat's mapping in the retirement transaction
([D2 § 4.10](FLEET-STATE.md#410-retirement-is-a-rendered-state)), and since card#12447 clears
`board_user_source` with it; the new seat's install resolves the same board user and declares it; the
next poll's pass finds the user free and maps it. ⚠ **A seat re-created WITHOUT retiring its
predecessor conflicts until the predecessor is retired** — step 4's fleet check, counted on the new
seat — and nothing here retires anything: removal is an operator act and no inference stands in for
one (D2 § 4.10's first sentence).

**Seats with no board** declare nothing ([D1 § 3.1](EVENT-SCHEMA.md#31-the-seat-config-file): the
install leaves the keys unset where the box holds no kanban credential), are never candidates, and
take the to-do tier ([D2 § 4.9](FLEET-STATE.md#49-the-task-title-merge-and-what-is-not-specified-here)
tier 4) — the operator's B.

**The manual command stays, as the override, with one verb added and two whose meaning moves by one
word each.** `--board-user=<id>` **pins**: it writes `operator`, and the pass leaves the seat alone from
then on, whatever it declares — the operator who maps a seat to a different user than the one it
declares is the operator who knows why. `--refuse` **refuses**: it writes `NULL` with `refused`, deletes
the row, and the pass leaves the seat alone from then on — the verb for a seat whose declaration is
not this operator's to honour, which is the case a fleet of several installs reporting to one site
puts in front of this site's operator, who cannot edit those seats' configs. `--clear` **unpins and
un-refuses**: it writes `NULL` to both columns (and deletes the row, as before), which is not *this
seat is never a candidate* any more but *let the declaration decide at the next poll*.

**What this does NOT do, named.** It does not validate the declaration against `/users/current.json`
— the site holds a read token that cannot speak for the seat, and a membership read with the token
it has is the check it can make. It does not re-check a mapped user on later polls beyond step 3's
equality: a user removed from every board after mapping gets no card and the merge falls through
clean, which is the same render as a user with nothing assigned. It does not judge WHOSE account a
declared user is — no field of the members response tells an agent's login from a person's (the
`account_type` measured above does not) — so a seat declaring a person's or a shared account, alone
on its box, is mapped; [§ 14](#14-open-questions) item 12 names that residual, and the operator's pin
or refusal is its control. And it
does not write `seat_board_task`: the card read does, as before, so [§ 2](#2-the-rebuildability-question)'s
argument is untouched — `seats` gains one more operator-or-pass-written durable input, and the fold
still reads only `events` and the durable inputs a rebuild does not destroy.

**The seams, by name (canon #7).** Each with a declaring end and a checking end:
- *the seat declares the board user and the board the id belongs to* — DECLARED by D1 § 3.1 on the
  heartbeat; CHECKED here, step 4's site and board legs; what neither end can verify is that
  the seat's credential is the agent's own and not a shared one — the operator's provisioning fact,
  NAMED at [§ 14](#14-open-questions) item 12 as the precondition it is.
- *the installer finds the seat's board credential and base where the coordination framework keeps
  them* — `kanban.base_url` in the config `$COORD_CONFIG` names and the credential store's
  `[kanban] api_token_file` pointer: another product's file layout, which that product may move. The
  installer DECLARES what it read, by printing the base's scheme and host and the resolved id; it
  CHECKS the read by making the one request and refusing to write on anything but a `200` with an
  integer `data.id`; what it cannot verify is that the layout still holds on the next install — so the
  `board-identity` block **re-resolves on every run of `install.sh`, upgrades included**, rewriting the
  keys when a `200` names a different user or base, clearing them on *no credential*, and **keeping them
  with `kept` printed on any other failure** — an outage does not undeclare a seat (D1 § 3.1's subsection
  states the rule) — joining `kanban.base_url` to `api/v3` with exactly one `/`; and the
  carry of that contract to the framework's owner is NAMED here as owed, beside
  [D1 § 18.13](EVENT-SCHEMA.md#1813-what-this-section-does-not-establish) row 6's roster contract.
- *the board's `GET /users/current.json` returns the same id space `assigned_user_id` uses* —
  DECLARED by nothing this repository can cite (the board's API contract is `kbcard`'s to state and
  it does not state it); MEASURED once, 2026-10-27, by assigning this card to this seat's user and
  reading both; NAMED as a measurement, re-derived by `kbcard show --task 12447` against the seat's
  `/users/current.json`.
- *the members endpoint lists every member a card can be assigned to* — DECLARED by nothing cited;
  MEASURED 2026-10-27 as a bare `data` array for board 14 with no `meta` (`len(body["data"])`
  re-prints its size), so whether it pages past some count stays **unverified** ([§ 6.6](#66-the-members-read) treats a `meta.last_page` that appears as a shape
  change and degrades the pass, never the poll).

---

## 5. The credential

| Key | Value | Notes |
|---|---|---|
| `BOARD_API_BASE` | e.g. `https://<kanban-host>/api/v3` | No trailing slash. HTTPS only; a plain-`http` base is refused at startup. |
| `BOARD_API_TOKEN` | a **read-scoped** bearer token | Sent as `Authorization: Bearer …`. |
| `BOARD_IDS` | comma-separated board ids | Empty or unset ⇒ the poller is **unconfigured**, which is a clean no-op, not a failure ([§ 9](#9-failure-paths)). |
| `BOARD_COMPOSE_TOKEN` | a **write-scoped** bearer token, for [§ 15](#15-the-compose-path--one-verb)'s compose path **only** | `card#9417`. Sent as `Authorization: Bearer …` on the one write shape § 15.7 states. **Never read by the poller.** Empty or unset ⇒ compose is **unconfigured** — refused by name at the panel and at the route ([§ 15.11](#1511-failure-paths-and-every-sentence-the-panel-renders)) — and the poller is unaffected. Shares `BOARD_API_BASE`; it does not get a base of its own, because two bases is two boards |

Read through `config/mezzanine.php` (`mezzanine.board.api_base`, `.api_token`, `.board_ids`,
`.compose_token`), never `env()` at a call site. The four keys are in `server/.env.example`, empty.

**Read-scoped is a requirement, not a preference — for the poller's token.** The poller issues `GET`
only and there is no path in the *poller* that writes to the board; a token that *could* write is a
token that turns a bug in a read loop into a board mutation. The scope is the operator's act at the
board, and the design's obligation is to need nothing more. ⭐ `card#9417` **does not loosen this; it
adds a second credential beside it** ([§ 5.3](#53-the-compose-credential)) so that the read loop keeps
a token that cannot write, and the one path that writes holds a token the read loop never sees.

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

### 5.3 The compose credential

*`card#9417`.* **Two tokens, two scopes, one base.** `BOARD_API_TOKEN` is the poller's and is read-scoped;
`BOARD_COMPOSE_TOKEN` is the compose path's ([§ 15](#15-the-compose-path--one-verb)) and is
write-scoped. **The invariant, stated over requests rather than over readers:** every `GET` the
application sends to the board carries the read token, and the one `POST` ([§ 15.7](#157-the-write-and-the-receipt))
carries the compose token — never the other way. Every site that reads a token value is named here,
so the invariant can be audited, and AT-C10's capability population is the check that the list is
the whole population: `App\Board\BoardPoll::credential()` reads the read token for the poller
(unchanged); the compose path's live read ([§ 15.6](#156-target-resolution) step D) goes through the
poller's own reader and therefore the same read token; and **`App\Compose\ComposeCredential` is the one
place that reads both** — it hands the compose token to the writer and reads the read token for
exactly one purpose, the equality check below, and hands it to no request. [§ 15.12](#1512-acceptance-tests)'s
AT-C2 asserts the `POST`'s header, AT-C4 that a missing compose token does **not** fall back to the
poller's, and AT-C4b that the poller's requests never carry the compose token.

**What the scope buys, and what Mezzanine can and cannot check about it.** Which operations a token may
perform is the board's to decide and the operator's to issue; Mezzanine cannot read a token's scope.
What it CAN check locally, and does: the two values are **not equal** — an operator who pasted one
read/write token into both keys has made the read loop a writer, which is the exact state § 5's rule
exists to forbid, and it is the one misconfiguration of that shape a program can see. Equal values
refuse the compose path by name (`credential_shared`, [§ 15.11](#1511-failure-paths-and-every-sentence-the-panel-renders)) and leave the
poller running: the poller is not the party at fault, and stopping it would turn a credential mistake
into a dark floor. ⚠ Whether the board issues per-token scopes at all is **unverified here**
([§ 14](#14-open-questions) item 6); if it does not, the one-verb rule is enforced by this
application's code alone — § 15.7's single request shape and AT-C2's request-population assertion —
and the operator provisions a dedicated board account whose *role* at the board is the narrowest that
can comment.

**§ 5.1's resolution rule applies to this token unchanged**: a header and never a query; a non-2xx as
a status and a class and never a body; every logged URL with its userinfo redacted. The shared client
that enforces it for both tokens is [§ 15.7](#157-the-write-and-the-receipt)'s `App\Board\BoardHttp`,
hoisted out of `BoardPoll` at the second real caller rather than written twice (canon #5), and
AT-D4-4's planted defect — the token moved into the query — is re-run over the compose path as AT-C5.

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

### 6.6 The members read (card#12447)

```
GET {BOARD_API_BASE}/boards/<id>/members.json
Authorization: Bearer <token>        — the POLLER's read token (§ 5), never the compose token
Accept: application/json
```

**Measured, not recalled** (2026-10-27, board 14, under a member's own credential — the poller's
read token has not yet been issued, [§ 14](#14-open-questions) item 1, so whether a read-scoped token
is admitted to this endpoint is **unverified** and is the first thing the build measures): `200`,
`data` a bare array of member objects, each carrying `user_id` (int), `name`, `role`, `account_type`,
`disabled` (bool), `is_owner` and `custom_permissions`; **no `meta`**, so the response is read as
one page. Consumed: `user_id` and `disabled`, nothing else — a member's name or role reaching a desk
would be the board taxonomy [§ 6.2](#62-the-fields-consumed) keeps off the floor. `account_type`'s
values, read on 2026-10-28 — `human` and `service`, agent logins among the `human` ones — are recorded in
[§ 12](#12-every-number-and-where-it-comes-from) and are why [§ 4.4](#44-the-link-pass--automatic-mapping-from-the-seats-declaration-card12447)
reads no account type.

**When it is read.** In the pass's first phase, before any row is locked, and only when [§ 4.4](#44-the-link-pass--automatic-mapping-from-the-seats-declaration-card12447)
step 4 has at least one declaration to check — a fleet whose every declaring seat is mapped or pinned
reads it never — and then once per configured board per poll, under the same 20 s timeout as every
request here. The cost is bounded by the board count, not the seat count.

**A degraded members read degrades the PASS, not the poll.** A non-`200`, a body that is not an
object with a `data` array, a row without an integer `user_id`, or a `meta` object appearing where
none was measured (a pager this design did not see) ⇒ the pass checks nothing this poll, writes
nothing, counts `board_link_failed` on the fleet, and the card read proceeds: a link that waits one
cadence costs a title five minutes; a card read that waited on the link would cost every mapped seat
its title at the bound. ⚠ **The inverse — mapping on a members read that failed — is forbidden for
the reason [§ 7.3](#73-a-failed-poll-writes-nothing) gives**: a declaration nobody could check is a
declaration, not a confirmation.

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
| `title` | string ≤ the `task_title` bound | **yes** | the card `name`, truncated to `seat_state.task_title`'s own bound — **120 bytes, by D1 § 7.4's procedure** — so the two can never disagree ([§ 8.4](#84-truncation)); `NULL` with `card_id` | `"Drop tier 2 (the GitHub-sourced task title) — …"` |
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

**A retired seat is skipped** (`seats.retired_at IS NOT NULL`) — and since the ratification of
2026-09-12 it has no row and no `board_user_id` to skip on, because retirement clears both in its
own transaction ([D2 § 4.10](FLEET-STATE.md#410-retirement-is-a-rendered-state)). The predicate
stays, and it is not redundant: it is what makes the poll correct in the window between a
retirement and anything else, and it is the rule rather than a consequence of one. Retirement takes
a seat off every read surface in the transaction that sets the column
([D2 § 4.10](FLEET-STATE.md#410-retirement-is-a-rendered-state)), so polling one would be work for a
desk that does not exist — and, worse, a board fact arriving for a retired seat is the shape
[D2 § 4.10](FLEET-STATE.md#410-retirement-is-a-rendered-state) is emphatic about: nothing outside the
retirement act may write a retired seat's state.

**Retention.** One row per mapped, unretired seat — a population bounded by the seat count, not by
traffic — so it is retained like `seats` itself and **purged by nothing**. A row leaves in exactly two
ways, and ⭐ **both of them now have a writer**: any write of `seats.board_user_id` by § 4.1's command
(`--clear` or `--board-user`), and a seat's **retirement**, which deletes it inside the retirement
transaction. The second way was a sentence with no writer when this document was drafted — the row
and the UNIQUE `board_user_id` both survived the retirement, so that board user could not be mapped
to a replacement seat until someone ran an undocumented `--clear` — and the operator's ruling of
2026-09-12 closed it by giving retirement the write rather than by softening the sentence. The
posture is now carried by [D2 § 6.7](FLEET-STATE.md#67-retention-and-purge)'s own retention table, so
§ 6.7 is total over the store again.

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

`title` is truncated **once, at the poller**, to `seat_state.task_title`'s bound — one bound, one
place, so the input row and the projection can never disagree about what the title is.

⛔ **That bound is 120 BYTES, and the procedure is [D1 § 7.4](EVENT-SCHEMA.md#74-truncation)'s**, the
one every other title in this system is already held to. D1 states the procedure over the descriptor
— *"cut at the last character boundary at or before byte 197 and append `…` (U+2026, 3 bytes)"* —
and then states the title case in the same breath: *"`subagent.spawn.title` uses the same procedure
at **120 bytes** (117 + `…`)"*. That is the bound and the procedure, cited rather than restated as a
second arithmetic here.

⭐ **The procedure has ONE implementation in this repository — `App\Support\ByteTruncation` — and
the poller calls it rather than writing the arithmetic a second time.** `StateRecompute::taskTier3()`
is its other caller, at the same bound (`StateRecompute::TASK_TITLE_MAX_BYTES`), which is what makes
*one bound, one place* above true of the projection as well as of the input row.

⚠ **`mb_substr($title, 0, 120)` is NOT that procedure** — it counts CHARACTERS. ⛔ **An earlier
revision of this section called the tier-3 path HARMLESS for that reason, and it reasoned from the
wrong bound** (`card#9282`, fixed 2026-09-12). Tier 3's input IS byte-capped by the reporter — at
**200 bytes**, the `action.descriptor` cap, not the title's — so a multibyte descriptor shorter than
120 CHARACTERS was not cut at all and reached the wire at up to its full 200 bytes against
§ 8.2.1's *"≤ 120 B"*. The store could not catch it either: `seat_state.task_title` is
`VARCHAR(120)` and MariaDB counts `VARCHAR` in characters
([D2 § 6.3](FLEET-STATE.md#63-conventions)).

⚠ **The tier-1 magnitude is LARGER and comes from a DIFFERENT arithmetic — neither figure re-derives
the other.** A board card `name` is capped by **nothing**, so 120
characters of a title carrying an em dash or a box-drawing character is up to 480 bytes. That would blow D2 § 8.2.1's *"≤ 120 B"* bound on
`task.title` and D2 § 12's measured worst-case seat object with it — a wire-contract violation minted
by a producer, which is the class this document exists to keep out of the store.

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
| Two seats mapped to one board user | **unreachable** — the UNIQUE key refuses it at the mapping command, and the link pass checks the fleet before it writes ([§ 4.4](#44-the-link-pass--automatic-mapping-from-the-seats-declaration-card12447) step 4) | the command's non-zero exit; `board_link_conflict` on the declaring seat (card#12447) |
| Two seats **declaring** one board user (card#12447) | **a holder keeps it and the other is refused every poll; two unmapped seats contending in one pass map neither** — no rule picks a winner | `board_link_conflict` on every refused seat's detail, once per poll while it persists; the operator retires the stale seat, pins one, or refuses one |
| A declared user no configured board knows, or a disabled member (card#12447) | **refused, nothing written** | `board_link_unknown_user` on the seat's detail |
| A seat mapped by declaration now declares a user the pass refuses (card#12447) | **the refusal above, and the old mapping and its row cleared in the same transaction** | that refusal's counter and `board_link_cleared`, both on the seat's detail |
| A seat the operator refused declares anything (card#12447) | **skipped** — nothing read, nothing written | none; `board_user_source = refused` is the record |
| A declaration fingerprinting another kanban (card#12447) | **refused, nothing written** | `board_link_site_mismatch` on the seat's detail |
| The members read fails — status, shape, a pager appearing (card#12447) | **the link pass is CLOSED this poll; the card read proceeds** ([§ 6.6](#66-the-members-read)) | `board_link_failed` on fleet health; every pending declaration waits one cadence |
| A seat stops declaring while mapped by declaration (card#12447) | **the mapping and its row are cleared at the next poll** | `board_link_cleared` on the seat's detail; the desk falls through to tier 4 or 3 with `task.degraded` false |
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
| 2 | At least one seat carries a `board_user_id` | absent — the column does not exist yet. **Since 2026-09-14 one seat does, by hand; card#12447 makes this condition hold automatically for every seat whose install resolves a board user and whose declaration the link pass confirms ([§ 4.4](#44-the-link-pass--automatic-mapping-from-the-seats-declaration-card12447)) — the condition stays, its writer changes** | polls succeed, write nothing, `board_poll_ok` climbs; a declaring, unmapped seat shows why in its detail counters |
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
- ⛔ **The board-poll schedule is OFF for the duration, and the test asserts that it was.** Unlike
  `seats.retired_at`, this input is REWRITTEN every cadence: a poll landing between the snapshot and
  the rebuild moves the input the two sides are being compared over, and the divergence it produces
  is a moved input rather than a fold rule reading what it may not. A test that can red for a reason
  its RED does not describe is worse than no test, because the next reader believes the message.
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

### AT-D4-8 every way a mapping leaves clears the row

- **Build:** a mapped seat with a board title on its desk; run
  `mezzanine:seat-board-user --seat=… --clear`.
- **GREEN:** `seats.board_user_id` is null **and** the `seat_board_task` row is gone, in one
  transaction; the next recompute renders tier 3 with `task_degraded = false`.
- **RED:** null the column and leave the row — the merge goes on answering from a card the seat is no
  longer joined to, and no poll will ever correct it because the poller no longer writes that seat.
- **Second build — the re-map:** run `--board-user=<a different id>` on the same mapped seat. **GREEN:**
  the old row is gone in that transaction, so nothing answers for the seat until the next poll writes
  the new user's answer. **RED:** leave the row — the desk shows the PREVIOUS user's card for up to one
  cadence, `task.degraded` false and every field internally consistent, which is the hardest shape of
  this defect to see.
- **Third build — the retirement:** `mezzanine:retire` a mapped seat. **GREEN:** `board_user_id` is
  null and the row is gone, in the retirement's own transaction, and the same board user can then be
  mapped to a replacement seat. **RED:** leave either behind — the UNIQUE key refuses the replacement
  mapping, and its whole symptom is the mapping command's bare non-zero exit
  ([D2 § 4.10](FLEET-STATE.md#410-retirement-is-a-rendered-state)).

### AT-D4-9 a confirmed declaration is mapped, and a pin or a refusal outranks it (card#12447)

- **Build:** a seat whose last heartbeat declares user `14` with the fingerprint of the test's
  `BOARD_API_BASE`; a faked members read listing `14` on board 14 as an enabled agent account; run one
  poll.
- **GREEN:** `seats.board_user_id = 14`, `board_user_source = declared`, `board_link_mapped` + 1,
  and — the card read running in the same poll — a `seat_board_task` row for the seat. A second
  poll over the same declaration writes nothing and counts nothing (step 3). Then
  `mezzanine:seat-board-user --board-user=15` on the seat: `board_user_source = operator`, the row
  gone in that transaction, and a third poll leaves `15` standing while the heartbeat still declares
  `14`. Then `--clear`: both columns `NULL`, and the fourth poll maps `14` again. Then `--refuse`:
  `board_user_id` `NULL`, `board_user_source = refused`, the row gone, and a fifth poll writes and
  counts nothing for the seat while the heartbeat still declares `14`.
- **RED, each seen to fail:** let the pass overwrite a pinned seat — the operator's `15` becomes
  `14` with nothing degraded; let it fill a refused seat — the refusal is a comment. ⚠ A third RED
  this AT once named — skip the row deletion on the pass's write — is **not observable** and was
  measured so (card#12447's build): the card read in the same transaction upserts the newly mapped
  seat's row, overwriting what the deletion would have removed, so the plant changes no committed
  state. The deletion stays because [§ 4.1](#41-seat--board-user)'s rule is stated over every write
  of the column.
- **Control:** a seat declaring nothing, never mapped, is untouched by all five polls and counts
  nothing, so the pass is known to be able to leave a seat alone.

### AT-D4-10 a conflict is counted on every refused seat and resolved only by a human act (card#12447)

- **Build:** seat A mapped to `14` (declared); seat B's heartbeat declares `14`; run a poll. Then,
  separately, seats C and D both unmapped and both declaring a free user `16`; run a poll.
- **GREEN:** A keeps `14`; B's `board_user_id` stays `NULL`; `board_link_conflict` + 1 on B,
  nothing on A; a second poll counts B again. Retire A: the retirement transaction clears A's
  mapping, row and `board_user_source`; the next poll maps B. In the second build **neither** C nor
  D is written and `board_link_conflict` + 1 on each; the same fixture with the rows read in the
  other order gives the same answer; the poll's log carries one line per conflict naming both seats
  and the user; then `mezzanine:seat-board-user --refuse` on D, and the next poll maps C — D left
  the contention set by the operator's word, not by any rule of the pass.
- **RED, each seen to fail:** a rule that prefers the newer declaration — A's desk loses its card to a
  copied config; a pass that catches the UNIQUE violation and counts nothing — B is refused with no
  instrument, the bare non-zero exit AT-D4-8's third build names, one layer down; a pass that maps
  the first of C and D it reads — the mapped seat changes with the read order.
- **Control:** the same fixture with B declaring `15` maps B, so the conflict assertion is known
  to turn on the collision and not on B.

### AT-D4-11 the site and the board are checked before the fleet, a member the board types `human` links, and a failed members read maps nothing (card#12447)

- **Build:** declaring seats, one per refusal leg and one control: a seat whose fingerprint is of
  another base (`https://Other.example/api/v3/` normalized); a seat declaring a user the members read
  does not list; a seat declaring a listed but `disabled` member; a seat declaring a member whose
  `account_type` is `human` (the type this project's own agent login carries on the measured board); and a seat whose base
  differs from the test's only by case and a trailing slash, which must MATCH. Then the full fixture
  with the members read answering `500`, then a body carrying `meta`.
- **GREEN:** `board_link_site_mismatch`, `board_link_unknown_user` and `board_link_unknown_user`,
  nothing written for those three; the `human`-typed member MAPS; the case-and-slash seat maps; on
  the two bad reads `board_link_failed` + 1 each, **no** seat written, and `board_poll_ok` still + 1
  with the card rows refreshed — the card read ran. Then one more: a seat mapped by declaration to
  `14` whose heartbeat now declares the `disabled` member — `board_link_unknown_user` + 1 **and**
  `board_link_cleared` + 1, both columns `NULL`, the row gone, in one transaction.
- **RED, each seen to fail:** compare the raw base strings (the case-and-slash seat is refused);
  skip the fingerprint (a user id from another kanban maps); map on a failed read (a declaration
  nobody checked becomes a join); keep the old mapping on a refused change (the desk shows `14`'s
  cards for a seat that says it is someone else); gate on `account_type` (the `human`-typed member —
  an agent login on the measured board — is refused, and automatic linking dies for most seats).
- **Control:** every refused seat above with a clean members read listing its user as an enabled
  member of the test's base maps, so each refusal is known to be the check and not the fixture.

### AT-D4-12 an undeclared heartbeat clears a declared mapping only from a reporter that sends the members (card#12447)

- **Build:** a seat mapped by declaration with a board-titled desk, whose last batch's
  `reporter_version` is the build that sends the members; fold a heartbeat carrying
  `board_user_id: null`; run a poll. Separately, the same seat whose last batch's `reporter_version`
  is `0.4.0` — a build whose heartbeat omits the member, which the ingest stores as the same `null` —
  with the same heartbeat folded; separately a mapped seat with no batch at all since the mapping; and
  separately the first seat again reporting a build **above** `0.5.0`.
- **GREEN:** the first seat's `board_user_id` and `board_user_source` are `NULL`, its row is gone in the pass's transaction,
  `board_link_cleared` + 1, and the recompute renders tier 4 or 3 with `task_degraded = false`;
  the `0.4.0` seat and the silent seat are untouched; the seat on the higher build is
  cleared exactly as the first was — the comparison is against `0.5.0`, never against the current build.
- **RED, each seen to fail:** treat `board_user_declared IS NULL` as a clearing declaration without reading
  `reporter_version` — every seat on a reporter that predates the member is unmapped by the first
  poll after the server upgrade, before any seat has been upgraded; compare with `reporterIsOutdated()`
  — the clear stops the day the current build passes `0.5.0`.
- **Control:** a seat mapped by the **operator** on the current build that stops declaring keeps its
  mapping (step 1 skips it), so the clear is known to act on `declared` alone.

### AT-D4-13 the migration pins every mapping that exists (card#12447)

- **Build:** on a store at the migration's base, map one seat by the command and leave another
  unmapped; run the migration; run a poll on which the mapped seat's heartbeat declares a different
  user.
- **GREEN:** the mapped seat reads `board_user_source = operator` and keeps its user through the poll;
  the unmapped seat reads `NULL`; `SELECT COUNT(*) FROM seats WHERE board_user_id IS NOT NULL AND
  board_user_source IS NULL` is `0` after the migration.
- **RED:** a migration that adds the column with no backfill — the pre-existing mapping reads `NULL`,
  step 2 treats it as the pass's own, and the first poll on which the seat declares something else
  clears or replaces the operator's mapping.
- **Control:** the same migration on a store with no mapping at all backfills nothing and the query
  above is `0` for the trivial reason, so the assertion is known to depend on the mapped row.

---

## 12. Every number, and where it comes from

**Cited** = another document's number, used unchanged. **Derived** = computed from another number here
or in D2. **Chosen** = a judgement call, with what would re-derive it. **Measured** = produced by
running the command in the cell, which is what a reader re-runs rather than trusting the figure.

| Value | Number | Basis | Where |
|---|---|---|---|
| Board poll cadence | **5 min** | **Chosen** — bounded above by an operator's tolerance for a card move reaching the floor, below by board API load; the two failures at 15 min and 1 min are argued, with the measured payload, in [§ 3.2](#32-the-cadence-chosen--and-the-30-minute-bound-re-derived) | [§ 3.1](#31-mezzanineboard-poll) |
| Tier-1 freshness bound | **30 min** | **Derived** — 6 × the cadence ⇒ five consecutive failed polls tolerated, with a floor of *strictly more than two cadences* so one lost request cannot clear the floor, and a standing re-derivation whenever the cadence moves. ⭐ **This re-derived D2 § 12's "Chosen, provisional" row, as that row asked; the figure is unchanged.** ⚠ **`Derived` HERE and `Chosen` THERE, and that is not drift**: the basis is the cadence, which is in THIS table and in neither D2's nor D1's, and D2 § 12's `Derived` is defined over those two ([§ 3.2](#32-the-cadence-chosen--and-the-30-minute-bound-re-derived)) | [D2 § 4.9](FLEET-STATE.md#49-the-task-title-merge-and-what-is-not-specified-here) |
| Search page size | **200 rows** | **Cited** — the search endpoint's documented maximum (its default is 50), from the board API's own `/docs.openapi`; a whole-board enumeration's only cost axis is round-trips, so take the max. ⚠ Cited from a live document outside this repository, which is the one class of citation nothing here can re-derive — the poller must therefore treat a page that returns more rows than it asked for as a shape failure like any other | [§ 6.3](#63-pagination-and-the-false-clean-rule) |
| HTTP request timeout | **20 s**, connect and read, per request | **Chosen** — and it exists at all because `->withoutOverlapping()` makes a hung request a fleet-wide stall rather than one slow poll: the request never returns, so `board_poll_failed` never increments, every later poll is skipped, and the only symptom is titles ageing out one bound later with nothing naming the cause. Bounded above by the cadence — a whole poll, every configured board and its pages, must finish inside 5 min with room to spare, and 20 s leaves room for fifteen requests — and below by a real network's slow-but-working read, which for a ~340 KB page is seconds, not tens of them | [§ 3.1](#31-mezzanineboard-poll) |
| Overlap-lock expiry | **10 min** | **Chosen** — § 3.1 requires it below the 30-minute bound and the framework default is a day. Bounded above by the bound: a poll killed holding the lock at T + 5 min frees it at T + 5 + E, the next tick polls within 5 min, and that must land before T + 30 — so E < 20 min. Bounded below by a live poll's own length, or a slow poll gets a second copy beside it: the timeout row's fifteen requests at 20 s is 5 min. Re-derive when either the cadence or the bound moves | [§ 3.1](#31-mezzanineboard-poll) |
| Page cap | **200 pages** | **Chosen** — a runaway guard, not a truncation policy: 200 × 200 is far past any real board, so reaching it means the pager is looping | [§ 6.3](#63-pagination-and-the-false-clean-rule) |
| `title` bound | `seat_state.task_title`'s own | **Cited** — D2 § 6.4. Never restated as a figure here: one bound, one home ([§ 8.4](#84-truncation)) | [§ 7.1](#71-the-input-table) |
| Board members, board 14 | **Measured 2026-10-27** (card#12447) | `GET /boards/14/members.json` under a member's own credential → `200`, `len(body["data"])` members, no `meta`; `[m["user_id"] for m in body["data"]]` holds this seat's `data.id`; `sorted({m["account_type"] for m in body["data"]})` — **read 2026-10-28: `human` and `service`, this seat's own login among the `human` ones**, which is why [§ 4.4](#44-the-link-pass--automatic-mapping-from-the-seats-declaration-card12447) reads no account type. **Re-run it rather than trusting this cell**, and re-run it under the poller's read token once one is issued — whether that token is admitted is unverified | [§ 6.6](#66-the-members-read) |
| This seat's board user | **Measured 2026-10-27** (card#12447) | `GET /users/current.json` under the credential store's `[kanban] api_token_file` → `200`, `body["data"]["id"]` an integer equal to card#12447's own `assigned_user_id` as `kbcard show --task 12447` prints it; the same request under the exported kanban read variable → `401` | [§ 4.4](#44-the-link-pass--automatic-mapping-from-the-seats-declaration-card12447) |
| Members reads per poll | **≤ the board count**, and 0 when no declaration is pending | **Derived** — one request per configured board, issued only when [§ 4.4](#44-the-link-pass--automatic-mapping-from-the-seats-declaration-card12447) step 4 has a declaration to check (card#12447) | [§ 6.6](#66-the-members-read) |
| Whole-board payload, board 14 | **Measured 2026-09-11** | `GET /tasks/search.json?q=board_id%3D14&limit=200&page=1` → `200`; then `len(json.dumps(body))`, `len(body["data"])`, `body["meta"]["last_page"]`. ~340 KB on one page. **Re-run it rather than trusting this cell** | [§ 3.2](#32-the-cadence-chosen--and-the-30-minute-bound-re-derived) |
| Assigned cards on board 14 | **Measured 2026-09-11** | the same request; `sum(1 for r in body["data"] if r["assigned_user_id"] is not None)` — and `meta.last_page == 1` is what makes it the **population** rather than a sample | [§ 10](#10-dark-on-arrival) |
| Longest card `name` on board 14 | **Measured 2026-09-11** | the same request; `max(len(r["name"]) for r in body["data"])` — more than twice the `title` bound, which is why [§ 8.4](#84-truncation) is load-bearing | [§ 8.4](#84-truncation) |

---

## 13. What is deliberately not built

✅ **The poller is implemented** (`card#11289`) — see the paragraph after the table for what that
pull built and what it still could not validate. ✅ **The amendments below were RATIFIED and
APPLIED** — operator ruling of 2026-09-12 on `card#7582`, taken with an adversarial review's required
edits and one product fork answered (retirement clears the mapping, [§ 7.2](#72-the-upsert)). The
table is therefore a record of where each amendment landed rather than a list of what is owed, and
the design gate below is **discharged**.

| Amendment | Section | State |
|---|---|---|
| A process row for `mezzanine:board-poll`, and one for `mezzanine:seat-board-user` | D2 § 2.1 | ✅ applied |
| ⭐ **Added by the ratification, not by this list:** § 6.6 and AT-D2-10 state the rebuild rule in full — the fold reads only `events` and the durable inputs a rebuild does not destroy | D2 § 6.6, AT-D2-10 | ✅ applied — [§ 2.3](#23-the-rule-this-establishes)'s rule now lives in D2 rather than only here |
| `seats.board_user_id`, and the `seat_board_task` DDL | D2 § 6.4 | ✅ applied — and **migrated**, because retirement now writes both ([§ 7.2](#72-the-upsert)) |
| `board_poll_ok` / `board_poll_failed`, and their `Exposed` cells | D2 § 7.2, § 8.2.4's `counters` list | ✅ applied — both directions of D2's G8 close on it |
| § 4.9's *"designed in no document in this repo"* sentence, which this document falsified | D2 § 4.9 | ✅ applied — the sentence is gone |
| § 12's tier-1 bound row: **Chosen, provisional** → **Chosen**, with this document's derivation and a standing re-derivation when the cadence moves, per [§ 3.2](#32-the-cadence-chosen--and-the-30-minute-bound-re-derived) | D2 § 12 | ✅ applied — ⚠ **not** as `Derived`, which is what this row asked for and what the review measured against D2 § 12's own vocabulary |
| § 1.2's non-goal row and § 14 item 3, which named the poller as designed nowhere | D2 § 1.2, § 14 | ✅ applied — item 3's last third is closed |
| `seat_board_task`'s retention posture — retained like `seats`, purged by nothing ([§ 7.2](#72-the-upsert)) — without which § 6.7 is no longer total over the store | D2 § 6.7 | ✅ applied, with retirement named as the second way a row leaves |
| A sizing line for the same table: bounded by the seat count rather than by traffic, so it changes no figure in § 6.8 — which is itself the statement § 6.8 owes | D2 § 6.8 | ✅ applied |

**D2 § 6.4 states the gate in its own words: *"Names are final; a builder may reorder columns and add
nothing."*** A migration adding a table that § 6.4 did not carry would have been a builder adding
something, and a poller written against it code written against an unratified design — which is why
the amendments were stated as exact text and not applied by the card that wrote them. ⭐ **§ 6.4 now
carries both**, so the store change is the ratified shape rather than an invention, and it ships with
the ratification because retirement's clearing act has nothing to write without it.

✅ **The poller is built — `card#11289`**, the separate pull the ratification named:
`mezzanine:board-poll` (`App\Board\BoardPoll`, scheduled in `server/routes/console.php`),
`mezzanine:seat-board-user`, and the tier-1 branch of the merge (`StateRecompute::task()`), with
§ 11's acceptance tests in `server/tests/Feature/Board/` and AT-D4-1 beside AT-D2-10 in
`At10RebuildEqualsFoldTest`. The two reasons this section gave for holding it back are now what the
build could NOT close, and both still stand:

- **The positive path has not run on a real surface.** No board card is assigned, so tier 1's
  answering branch has been exercised only against a faked board at the HTTP client. The first real
  exercise of that boundary is still owed, and it is the first poll after a card is assigned to a
  mapped seat's board user.
- **The credential does not exist.** Issuing a read-scoped board token for the server is an operator
  act ([§ 14](#14-open-questions) item 1); until it is issued the poller is UNCONFIGURED on every host
  and § 10's condition 1 keeps tier 1 dark.

⚠ **One optimization is named and deliberately not taken:** filtering server-side with an
`assigned_user_id` predicate in `q`, which would cut the per-poll payload by orders of magnitude. It
cannot be validated today — with zero assigned cards on the board, a working filter and a
silently-ignored-or-erroring one both return the same empty result, so there is no control that
discriminates them, and a filter whose failure mode is *returns nothing* is indistinguishable from
*nothing is assigned*. It is the first thing to revisit once a single assigned card exists to test
against.

### 13.1 card#12447 — the automatic link and the to-do tier: every amendment, and its state

**Designed 2026-10-27, built 2026-10-28** (one PR, the design's PR #383). ⚠ **B was re-derived on design review round 3** —
rounds 1 to 3 each found a MAJOR in the same to-do-lifecycle class (r1: a hook-side race, a `TaskList`
wording flip and an unknown-id contract alarm; r2: an unbounded journal line; r3: a refusing cap that
went dark, and a widened session-reopen rule that resurrected ended stalls), which fired the dispatching
seat's pre-committed tripwire; the re-derived shape keeps no reconstructed list (a small evicting LRU of
task texts, titled by the one call that moved an item) and couples nothing to server session rows (one
slot per seat, the reporter the sole writer of its life, deriving the seat's answer over its open
sessions — round 4's F1 — and re-syncing it on every flusher start). Stated as exact requests per
[§ 1.3](#13-the-boundary-stated-as-a-rule), written into each owning document in the same change
as this section and marked *card#12447* there, so a reader of any of them sees the whole. Each row's surface carries
its text; this table is where the state is read.

| Surface | Amendment | State |
|---|---|---|
| this document § 0, § 4.1, **§ 4.4**, **§ 6.6**, § 9, § 10, § 11 (AT-D4-9 … AT-D4-13), § 12, § 14, § 15.6 | the link pass, the members read, the pin and the refusal, the counters, the failure rows, the measurements, the compose arm | built |
| D1 § 3.1 (`board_user_id`, `board_api_base` and their subsection), § 6.0's fact table (the to-do tool families, measured at 2.1.311), § 6's kind table, **§ 6.16 `todo.progress` and its two heartbeat members** — rows of the tables `Tests\\Feature\\Ingest\\EventSchemaDriftTest` holds to the registry — § 7.1 (the `TaskCreate`/`TaskUpdate`/`TaskList` row), § 7.4, **§ 8.2** (the `todo_list` / `todo_create` / `todo_update` / `todo_end` records, sanitised and byte-measured at append), § 9.3 (`todo_task_unknown`, `todo_map_evicted`, `board_declaration_malformed`), AT-10's to-do case, AT-30, AT-31, § 14 (the LRU and retained-map caps), § 15 rows 62–65, § 16 row 12 | the declaration and the to-do kind, reporter side; the reporter bump `0.4.0 → 0.5.0` with its `UPDATES.md` row (`0.4.0` was card#12451's) | built |
| D2 § 2.1 (both rows), § 3.2, § 4.6, **§ 4.9** (tier 4, `task.source`'s `todo`, the one `task_degraded` rule), § 4.6's todo row (the reporter's `null` is the title's only end; no reopen rule is widened), § 6.4's fence and its paragraph (`seats.board_user_source` with its backfill, `seat_state.board_user_declared` and `board_fingerprint_declared`, `seat_state.todo_title`, `todo_as_of` and `todo_event_time`, the `todo` member), § 6.5 (the `todo` group guarded on `todo_event_time`; an integer arm and `conforming()` on the declared members), `SchemaVersions::reporterAtLeast()`, § 7.2 (the `board_link_*` rows on seat detail and the fleet's `board_link_failed`, listed on § 8.2.4's `counters` member), § 8.2.1 (`task.source`, `task.ref`), § 12, § 13 rows 64–65, AT-D2-33, AT-D2-34, Appendix A S33–S34, Appendix B row 17 | the merge tier, its one slot per seat and its reporter-owned life; the declaration's store and the link's counters | built |
| D3 § 13 row 96, § 14 item 4 | `todo` renders as `telemetry` does — a title with no reference; no new render, no new animation row | built |
| `fleet-reporter/INSTALL-LINUX.md` Step 2 — a `board-identity` block (`run`), the values block's two new exports, the writer's two new keys; Step 3's printer; **Step 4 (c) — the `todo-tools` block, `run todo-tools`** (the operator's ruling, item 11), with `wire-hooks.js --todo-tools` and `check-preserved.js --todo-tools`; § Upgrading an existing seat — U5's `config` touch for `0.5.0` names both blocks as what an installed seat runs; Step 8 removes the to-do tools key only when `todo-tools-set` says this install set it | the installer resolves `kanban.base_url` + `/api/v3` and the store's `[kanban] api_token_file` from the coordination config the identity block already reads, calls `GET /users/current.json` in the user's shell, prints only `board user <id> on <scheme://host>`, exports `SEAT_BOARD_USER` / `SEAT_BOARD_BASE`, and writes them; a box with no coordination config or no kanban pointer prints `undeclared: this seat has no board credential` and exits 0 | built — the runbook is held to `install.sh` by `--check-runbook` |
| `fleet-reporter/install.sh` | `board-identity` and `todo-tools` in `MZI_BLOCKS` — `todo-tools` run after `hooks-apply` with `todo-tools\|: unchanged` as its one marker, which both the `already set` and the `kept` lines end in, so neither counts as a change the run restarts anything for; **the consolidation:** `wire-hooks.js --todo-tools` and `check-preserved.js --todo-tools` extend Step 4 (a)'s stage-check-apply pipeline to one `env` key rather than adding a second writer of `settings.json`, and the apply is a temp file renamed onto `realpath` so a symlinked settings file stays one — a shape (a) may adopt without changing what it writes; `board-identity` run after `identity` in `set_token` and in **every** `install` run, upgrades included, so a moved layout or a changed answer is re-resolved; `--no-board` to leave the keys unset; **no `--board-user` flag** — an id the operator chooses is the server's pin ([§ 4.1](#41-seat--board-user)), never a seat's typed declaration; the `config-check` reader unchanged (it prints every key, and `board_api_base` carries no userinfo to mask) | built |
| `fleet-reporter/AGENT-INSTALL.md` § 6 and § 3 | the to-do tools sentence is in; one more sentence for § 3: the board link needs nothing from the user — the script reads the seat's own board credential from the coordination framework's store and asks the board; a seat with no board stays unlinked and the desk shows its to-do instead | built |
| `fleet-reporter/UPDATES.md` | the `0.5.0` row: touches `artifact, config`; *New: the desk shows the agent's in-progress to-do when no board card answers, and a seat whose install can reach its board is linked to its board user automatically; the config gains `board_user_id` and `board_api_base`, written by Step 2's `board-identity` block, which `install.sh` runs on every install and upgrade; and the install now sets `CLAUDE_CODE_ENABLE_TODO_TOOLS=1` in the account's `~/.claude/settings.json` `env` when it is absent (Step 4 (c)) — an account-wide change to what the harness provides in every session, kept if the account already set the key, and removed by Step 8 only when this install set it* | built — `bin/reporter-version-guard.py` holds it to the bump |
| `README.md § The board card on a desk` | the sentence that says a seat is mapped by `mezzanine:seat-board-user` becomes: mapped automatically from the seat's own declaration, with the command as the operator's pin | built |
| `server/.env.example` | no new key: the link pass reads `BOARD_API_BASE`, `BOARD_API_TOKEN` and `BOARD_IDS`, which exist | — |
| the migration | one migration: the columns [D2 § 6.4](FLEET-STATE.md#64-ddl)'s fence lists, and the backfill `UPDATE seats SET board_user_source = 'operator' WHERE board_user_id IS NOT NULL` — AT-D4-13 | built |

---

## 14. Open questions

1. **⇢ Operator — the credential.** A read-scoped board token for the Mezzanine server, distinct from
   any seat's. **Blocks:** condition 1 of [§ 10](#10-dark-on-arrival). **Closes it:** the token, and the
   board ids it may read.
2. **⇢ Operator — the seat→board-user values.** The mechanism is [§ 4.1](#41-seat--board-user); the
   values are a declaration only the operator can make. **Blocks:** condition 2.
3. **✅ CLOSED — the amendments in [§ 13](#13-what-is-deliberately-not-built) are ratified** (operator,
   2026-09-12, `card#7582`), with an adversarial review's edits and F5's product fork answered as
   *retirement clears the mapping*. What it unblocks is implementation of the poller; what it does not
   do is authorise it in the same breath, which is the ruling's own sentence.
4. **⇢ Owed — this document's own verifier.** D1, D2 and D3 each have one, wired into
   `.github/workflows/design-doc-verifiers.yml`; **this document has none**, so no gate re-derives
   its numbers, resolves its anchors or holds its tables against its worked examples.
   `tools/design/README.md` declares the gap on the surface a reader of those gates checks.
   **Blocks:** nothing the poller needs — it is built (`card#11289`) and its acceptance tests run in the
   PHP suite; what is ungated is this document's own numbers and anchors. ⚠ **Ratification has
   happened, which was the first half of what closed this**, so what is left is D4's verifier in its own round — the rule that
   README states for every gate. The shape a guard would be written against has stopped moving.
5. **⇢ Deferred — the server-side assignee filter** ([§ 13](#13-what-is-deliberately-not-built)).
   **Blocks:** nothing. **Closes it:** one assigned card, which makes a discriminating control possible.
6. **⇢ Operator — the compose credential and the floor's board account** (`card#9417`,
   [§ 5.3](#53-the-compose-credential), [§ 15.13](#1513-what-the-operator-provisions)). A board
   account for the floor — the comment author the board records — and a write-scoped token for it,
   distinct from `BOARD_API_TOKEN` and from any seat's. **Blocks:** [§ 15](#15-the-compose-path--one-verb)
   condition 1 — compose is unconfigured on every host until it exists. **Closes it:** the token and the
   account's name. ⚠ Whether the board can scope a token to *comment only* is part of the answer, and it
   is not readable from this repository; [§ 5.3](#53-the-compose-credential) says what holds either way.
7. **✅ CLOSED — the product fork the hop opens is answered: (a)** (operator, 2026-10-09,
   `opq-1791449375-326a`; [§ 15.2](#152-the-hop-and-what-this-repository-cannot-verify)).
   `card#9417` rests on *"posts into a channel the agent already reads"*, and for a card comment the
   live half of that is false today: this install's bridge subscription carries `task.created` alone,
   the bridge classifies no `comment.created`, and the bridge owner's own tool documentation says
   *"Do not rely on a comment to wake another seat."* The comment still reaches the agent the way every
   card comment does — at the agent's next read of the card, which the build brief orders on every
   dispatch — so the audit trail and the one-verb amendment stand either way. **The fork was:** (a) build
   `BoardCommentChannel` first as the card says, render the receipt as *posted* and never *delivered*,
   and raise the bridge-side feature request (`comment.created` in the subscription filter; a
   `card_comment` intent routed to the seat whose card it is) with kanban-solo now, so the live hop
   lands as a bridge change and not a Mezzanine one; or (b) make the coordination-channel thread the
   first channel, which delivers live today and costs a GitHub credential, a participant identity for
   the server and a thread model (item 8). **The ruling: (a).** Card comments ship first, as
   `BoardCommentChannel`, with the honest receipt — *posted*, never *delivered*, in the wording
   [§ 15.11](#1511-failure-paths-and-every-sentence-the-panel-renders)'s `posted` row owns — and the
   bridge-side request is filed with kanban-solo
   ([§ 15.13](#1513-what-the-operator-provisions) item 3). It has landed upstream; until
   [§ 15.2](#152-the-hop-and-what-this-repository-cannot-verify)'s three conditions hold on a host, a
   comment reaches the agent at its next read of the card. **Built** (card#9417). The seam in
   [§ 15.9](#159-the-composechannel-seam) is what keeps (b) additive later rather than a redesign.
8. **⇢ Operator — the coordination-channel target** ([§ 15.6](#156-target-resolution)). The second
   target in the card's resolution order is *a roundtable thread addressed to the seat's declared
   `protocol_agent_name`*. Three facts gate it, none of them this repository's to decide: the declared
   name is the **roster** name (`mezzanine` on this install, read from the reporter's config) while the
   roundtable's addressing identity is `cross_project.identity` (`mezzanine-solo`) — they are not the
   same string, so a `to:<declared name>` label addresses nobody on the roundtable, and only on a
   multi-agent install, where the coordination repo IS the channel and its `to:` labels ARE roster
   names, does the declared name address a seat directly; posting needs a GitHub credential with
   issues-write on the channel repo and a `from:` identity for the floor, which makes the server a
   roundtable participant; and a compose must land on *some* thread — a new issue per message is a
   fan-out the roundtable's own guidance argues against. **Blocks:** nothing in the first build — the
   resolver reaches this arm and refuses by name ([§ 15.6](#156-target-resolution) step 3) until a
   channel is declared for the install. **Closes it:** a ruling on which repo, which identity, which
   credential and which thread; then `CoordThreadChannel` is the second `ComposeChannel`
   implementation, before Slack.
9. **✅ MEASURED — a comment DOES move the card's `updated_at`** (the seat, 2026-10-08, on this card).
   Card#9417's `updated_at` read `2026-09-14T00:08:07Z` before and `2026-10-08T08:49:38Z` after, and
   the latter is exactly the `created_at` of comment 10549; the comment was posted after the card's
   assignment and its column move, so neither of those explains the change. The board's API contract
   leaves this unstated (`kbcard` records it as *the kanban API contract's to state*), so it is a
   measurement and not a cited fact, and it re-derives by posting one comment on a scratch card and
   reading `updated_at` before and after. **What it changed in this design:** a comment moves
   [§ 4.2](#42-which-card-answers-when-several-could)'s ordering key, so [§ 15.3](#153-the-one-verb-and-why-it-is-not-an-instrument-inside-the-measurement)
   leg 2 no longer argues that a compose cannot change the desk's card; it reads the board live before
   writing, refuses when the shown card is no longer the winner, and states the residual window.
10. **⇢ Operator — the residual window is accepted on the record, in two parts** ([§ 15.3](#153-the-one-verb-and-why-it-is-not-an-instrument-inside-the-measurement)
    leg 2). **(i) The bounded part:** between the first page of the compose path's live read and the
    board's acceptance of an answered write — at most `(P + 1) ×` the request timeout, `P` being the
    total page count across `BOARD_IDS` ([§ 15.15](#1515-every-number) derives it from config and the
    measured page count) — a board-side touch of another of the seat's cards can be overtaken by the
    compose's own `updated_at`, and the desk then shows the composed card while the seat works the
    other until the other is touched again. **(ii) The unbounded part:** a write whose answer never
    arrived (`unverified`, [§ 15.11](#1511-failure-paths-and-every-sentence-the-panel-renders)) may be
    accepted by the board at any later moment, so no figure bounds when its `updated_at` lands; what
    the design does there is tell the operator the message may be on the card and to read it before
    posting again. Closing (i) fully needs a board-side conditional write (compare-and-set), which the
    board's API is not known to offer (unverified); nothing closes (ii) short of a board-side
    idempotency key. **Recommendation:** accept both, with (i)'s derivation stated and AT-C7's second
    arm holding the part this repository can hold. **Closes it:** the ruling, or a measured board-side
    primitive.
11. **✅ RULED — the installer sets the harness's to-do tools on** (operator, 2026-10-27, verbatim:
    *"this should be checked and set as part of the mezzanine reporter installation"*). The
    task-tracking tools are provided by default only on some models ([D1 § 6.0](EVENT-SCHEMA.md#60-conventions-and-how-harness-payloads-are-read)'s
    fact table); the opt-in is `CLAUDE_CODE_ENABLE_TODO_TOOLS=1` — its name and scope read from Claude
    Code's own `env-vars` reference on 2026-10-27 (*set to `1` to get the task-tracking tools on every
    model; requires v2.1.233 or later*), and a settings file's `env` block reaches every session however
    `claude` was launched. **The ruling, as built:** `fleet-reporter/INSTALL-LINUX.md` Step 4 (c) —
    a `todo-tools` block beside the hooks block, run by `install.sh` on every install and upgrade — reads
    the key in the seat's user-level `~/.claude/settings.json` `env`, sets `1` when it is absent, leaves
    `1` as *already set*, and **keeps** any other value an operator chose, printing which; the
    `--check-runbook` drift check holds the block and its `already set` marker to the script as it holds
    every other block. What it does not do, named: read which model a seat runs (not a file fact, and not
    needed once the variable is set), or touch a session already running (the next session has the
    tools). **Blocks:** nothing — built with [§ 13.1](#131-card12447--the-automatic-link-and-the-to-do-tier-every-amendment-and-its-state); ⚠ the measurement that a settings `env` block alone provides the tools is owed (`fleet-reporter/INSTALL-LINUX.md` Step 4 (c)).
12. **✅ ANSWERED, with one residual — whose credential a seat's board identity is** (operator,
    2026-10-27, verbatim: *"yes each PM has their own login"*). A PM seat's `[kanban] api_token_file`
    is that agent's own account, so — **provided no other seat declares the same user**, which the
    contention set refuses on both sides — its declaration is right by construction and tier 1 lights
    on it at its first poll after the link pass ships. **Impl seats on the same box may share a credential**,
    and the design needs no second mechanism for that: every seat sharing one declares the same user,
    the contention set ([§ 4.4](#44-the-link-pass--automatic-mapping-from-the-seats-declaration-card12447)
    step 4) maps **none** of them and counts `board_link_conflict` on each, the poll's log names the
    pair, and the operator pins the one seat that works that user's cards or refuses the rest. **The
    residual, still the operator's:** a person's, a shared or a service account declared by the one
    seat of a box maps that seat to a user whose cards may not be its own — no field of the members
    response tells whose account it is (`account_type` does not: agent logins carry `human`), and the
    conflict rule needs a second seat to fire. **Blocks:** nothing fleet-wide — PM seats are answered; on a box whose one seat holds a
    shared or service-account credential the residual blocks the link pass for that seat until it is
    `--refuse`d, which is the precondition [§ 4.4](#44-the-link-pass--automatic-mapping-from-the-seats-declaration-card12447)'s
    *What this does NOT do* names. **Recommendation, unchanged:** `--refuse` such a seat before its
    first poll. The members response's `account_type` values were read on 2026-10-28 — `human` and
    `service` ([§ 12](#12-every-number-and-where-it-comes-from)), this project's own agent login among the
    `human` ones — so the link pass reads no account type (seat ruling on card#12447, 2026-10-28,
    reversible): a gate on it would have refused the PM seats this item's answer says to link.
13. **⇢ Build — the poller's read token on the members endpoint.** [§ 6.6](#66-the-members-read)
    was measured under a member's own credential; whether a read-scoped board token is admitted to
    `GET /boards/{id}/members.json` is unverified until item 1's token exists. **Blocks:** the link
    pass on a host, not the design. **Closes it:** one request under the issued token; a `403` makes
    the board check a second `GET` the operator's token must be scoped to allow, or drops that leg
    to the fleet and fingerprint checks with the drop recorded here.

---

## 15. The compose path — one verb

*`card#9417`. The design for the desk compose box: the one write this product makes to the board, the
credential it rides, how its target is chosen, the seam a second channel plugs into, and — first,
because it decides what the panel may claim — the hop between the board and the seat that this
repository cannot verify. Built by card#9417's build change, which landed this section's
amendments to D2, D3, `README.md` and `.env.example` with it, stated as requests per
[§ 1.3](#13-the-boundary-stated-as-a-rule); [§ 15.14](#1514-amendments-owed-to-other-surfaces) is the
list.*

### 15.1 The problem, in the product's terms

An operator watching a desk can see what the agent is doing (D3's drill-down, card#7341) and can open
the agent's console on claude.ai (card#9416), and has no way to say one sentence to the agent from the
floor. The operator's ruling of 2026-09-13 sequenced this card after the console link for exactly that
reason — *"commanding an agent directly is not useful unless I can see what the agent is outputting"*
— and bounded the shape: the floor **writes into a channel the agent already reads** rather than
commanding the seat. `docs/PLAN.md § 0` (the card#9415 entry) pins the same bound from the other side:
D-10 stands, Mezzanine owns no actuator, and this box is *not a server→seat command path*.

**Where a message can enter, read at the source rather than from the card.** The bridge
(`agent-webhook-bridge`, local checkout at commit `a4cfb0f`) exposes HMAC-signed provider webhooks
and a loopback-only `POST /agent-tools/call`; neither is a free-text door for a named agent from
another host. The seat's channel socket is local to the seat. So the floor can reach an agent only by
writing something the agent reads: the card it is working on, or a coordination-channel thread
addressed to it. Of those two, the card is the one this repository already joins a seat to
([§ 4](#4-identity-and-the-join)) and already holds a credential for ([§ 5](#5-the-credential)); the
thread is [§ 14](#14-open-questions) item 8.

### 15.2 The hop, and what this repository cannot verify

The card's premise is that a comment on the joined card is **delivered** to the seat — *"the kanban
webhook → bridge → channel push chain then delivers it exactly as measured"*. ⛔ **That chain was not
what the card measured, and it does not exist on this install today.** Read, not recalled:

| Leg | What was read | Where |
|---|---|---|
| the measurement | two pushes **into the seat's channel socket directly**; a turn opened in ~14 s idle, delivered at the next tool boundary mid-turn | the card's own probe log. It proves the **last** hop only — socket → agent — and nothing upstream of it |
| the board emits the event | the board records a `comment.created` webhook for a comment | the bridge's `docs/board-tools.md` § `board_comment_card`, which also says, in the bridge owner's own words: **"Do not rely on a comment to wake another seat. Use the coordination surface for anything that needs an answer."** |
| the bridge receives it | this install's kanban subscription is provisioned with `event_filter: ["task.created"]` — the filter is written INTO the board's webhook (`KanbanProvisionClient`), so a `comment.created` is never sent to the bridge for this scope | `~/.config/agent-webhook-bridge/mezzanine.yml` § `subscriptions`; `mezzanine-solo.yml` subscribes to the roundtable repo only |
| the bridge classifies it | `InboxOnlyClassifier::classify()` matches `task.created`, `task.moved`, `task.updated` and the four `LIFECYCLE` types; everything else is `default => null` — no intent, no inbox row, no push. `grep -rn 'comment\.created' app/ routes/ config/` on the bridge prints nothing | `app/Bridge/Classifiers/InboxOnlyClassifier.php` |

So for a card comment, every leg between the board and the socket is dark, and two of them are the
bridge owner's to light (the subscription filter; a classifier arm that routes a `card_comment` intent
to the seat whose card it is). **What is true today is weaker and still real:** the comment is on the
card, and the agent reads the card — the build brief every dispatch on this install carries opens with
*"The CARD is the spec. Read it in full, including its comments"*, and a solo seat reads its board at
every session start. A comment therefore reaches the agent at its next read of the card, with no
bridge in the path; what it does not do is open a turn.

**This is canon #7's seam, and the three things it owes are stated here rather than assumed.**

- **DECLARE.** The floor declares the guarantee a post carries on the surface the operator reads: the
  receipt names the comment and the card, says *posted* and **never the word *delivered***, and says in
  one clause that the floor does not confirm delivery. [§ 15.11](#1511-failure-paths-and-every-sentence-the-panel-renders)'s
  `posted` row is the one wording. The bridge owner has declared the far end's guarantee on its own
  surface, quoted above; this document cites it rather than restating it as a Mezzanine fact.
- **CHECK.** This repository checks that its declaration is true of its own code: AT-C2 asserts the
  request population a compose issues (one `POST`, one path) and AT-C8 asserts the receipt wording
  against § 15.11's table (the comment id present, *delivered* absent) — so the panel cannot drift
  into a claim the hop does not back.
- **NAME WHAT CANNOT BE VERIFIED.** Whether the board's webhook for this scope carries
  `comment.created`, whether the bridge forwards it, and whether the seat's channel is live are not
  establishable from this repository; the `posted` row's last clause is where the receipt says so, and
  [§ 14](#14-open-questions) item 7 records the operator's ruling on the product fork and the
  bridge-side request that would close it. ⚠ The card's acceptance line *"the seat's channel receives
  it (control on a live seat, not a mock)"* is therefore a **live, cross-boundary** check that no test
  in this suite can run; it is owed on the real surface once the bridge change lands, exactly as
  [§ 13](#13-what-is-deliberately-not-built) still owes the poller's first real tier-1 read.

**The live-delivery note — the bridge's side, and when the receipt may change.** The bridge-side
request ([§ 15.13](#1513-what-the-operator-provisions) item 3) has an answer upstream: the bridge's
`card_comment` intent, routed to the card's assignee seat only, documented in the bridge's own
[`docs/consumer-guide.md` § Card comments](https://github.com/PupFuzz/agent-webhook-bridge/blob/dev/docs/consumer-guide.md#card-comments-card_comment)
— which owns how that delivery behaves, and which this document points at rather than restates. Live
delivery on a host needs **all three** of: (1) a board (kanban) release after v0.52.3 carrying the
encoded-size cap on a comment's content; (2) a bridge release carrying the `card_comment` intent; and
(3) `comment.created` added to the board subscription's `event_filter` in the install's bridge YAML,
then `bridge:provision --reconcile`. ⛔ **The receipt stays *posted*, never *delivered*, until all
three hold on the host** — and even then the floor still cannot see the hop, so a change to the
`posted` row is a design change to this document, measured on the real surface first (AT-C6), not an
edit made because the bridge shipped. ⛔ **Two bridge settings can silence a floor comment with no
error anywhere**, and both are the operator's ([§ 15.13](#1513-what-the-operator-provisions) item 1):
the floor's board account must never be the bridge's writeback identity (the bridge drops a comment
whose author is that identity as an echo), and an assignee seat that sets
`echo_suppression.treat_as_signal` must list the floor's account on it (that list admits named
authors only).

### 15.3 The one verb, and why it is not an instrument inside the measurement

[§ 1.2](#12-non-goals--stated-so-an-implementer-cannot-widen-scope-in-good-faith)'s rule is that a
floor that could edit its own subject would be an instrument inside the thing it measures. The
amendment admits one verb, and the argument that the rule survives it has four legs, each checkable:

1. **The floor measures the fields [§ 6.2](#62-the-fields-consumed) lists, and telemetry; a comment
   writes none of them directly.** Every other `seat_state` column is folded from events. A comment is
   a new row in the card's `comments` relation — `kbcard`'s measured semantics: `POST
   /tasks/<id>/comments.json`, `201`, the row echoed — and touches no stage, no assignee, no name, no
   archive flag. ⚠ **It does move `updated_at` as a side effect — measured, [§ 14](#14-open-questions)
   item 9** — and `updated_at` is [§ 4.2](#42-which-card-answers-when-several-could)'s ordering key,
   which is why leg 2 exists in the form it has.
2. **A compose can change which card the desk shows, and the design confines that to a window it
   states.** An earlier revision of this leg claimed a compose could *only confirm* the § 4.2 winner,
   reasoning from the projection; the claim is false when the projection lags the board: a poll picks
   A at T0, the board touches B at T1, a compose on A at T2 bumps A past B, and the floor keeps A while
   the seat works B — its own write having changed the thing it measures. **So the compose path reads
   the board live before it writes** ([§ 15.6](#156-target-resolution) step D): with the READ token,
   through the poller's own candidate reader and § 4.2's own selection over a fresh `GET`, it
   recomputes the seat's winner and refuses `target_moved` — with **zero** writes — unless that winner
   is the card the operator was shown (`expected_ref`). **What remains is the gap between the first page
   that read answered and the board's acceptance of the write**: a touch of B landing inside it is
   overtaken by the compose's `updated_at`, and the desk shows A until B is touched again. For a write
   the board answers, its figure is `(P + 1)` request durations at most, `P` the total page count
   across `BOARD_IDS` ([§ 15.15](#1515-every-number) derives it); it needs the board to be touched for
   that seat's user inside those seconds; and the state it leaves is self-correcting at the next touch
   and visible on the desk as a title, never a silent one. ⚠ **For a write the board never answers —
   the `unverified` branch — there is no bound at all**: the request may be accepted at any later
   moment, and the only honest thing the design can do is say so to the operator. Closing the bounded
   part fully needs a board-side conditional write, which the board's API is not known to offer, so
   both parts are **accepted on the record** at [§ 14](#14-open-questions) item 10 rather than rounded
   away. AT-C7's second arm holds the part this
   repository can hold: a projection that says A over a board whose newer card is B refuses a compose
   on A with no write.
3. **The floor originates nothing.** Every comment is one signed-in operator's deliberate act, carries
   that operator's name in its body, and lands on the card whose title that operator is looking at. No
   timer, poll, sweep or inference may call the compose path — the same rule [§ 4.1](#41-seat--board-user)
   states for `seats.board_user_id`'s writer, and for the same reason. The route is the only caller
   ([§ 15.4](#154-the-routes-and-the-gate)); there is no command, no job and no schedule entry — and
   that is a population AT-C10 asserts over the tree, not a sentence.
4. **The write and the read never share a credential**, so a defect in the read loop cannot become a
   board mutation ([§ 5.3](#53-the-compose-credential)).

Never a move, never an assign, never a comment on a card the seat is not joined to — and
[§ 15.12](#1512-acceptance-tests)'s AT-C2 is the request-population assertion that makes *never* a
measured word rather than a promise.

### 15.4 The routes and the gate

| Route | Stack | Name | Serves |
|---|---|---|---|
| `POST /desk/{install_id}/{seat_id}/compose` | `web` → `auth` → `mfa` → `can:operate` → `throttle:compose` | `desk.compose` | the post. `{install_id}` constrained to `App\Support\Slug::INSTALL_ID`, `{seat_id}` to `Slug::SEAT_ID`, so a malformed segment is the router's `404` and reaches no gate and no store |

**Why `routes/web.php` and not `routes/fleet.php`.** The read plane's stack is `web` + `fleet.read`
and admits an `mzr_` machine token (D2 § 9); a write must never be reachable by a credential that
carries no account, and `can:operate` cannot even be asked of one (`FleetController::mayOperate()`'s docblock: *"A machine
read token (`mzr_`) carries no account, so it is never an operator"*). D2 § 8.2's REST surface is
also `GET`-only by contract, and putting a `POST` under `/api/fleet/` would amend D2 for a route D2 has
no reason to own. So the route sits beside the floor page it serves, inside the same `['auth', 'mfa']`
group in `routes/web.php`, with `can:operate` added — the stack `routes/admin.php` already documents
for the console, which `App\Providers\AppServiceProvider`'s gate comment names as *"every per-desk
write route that follows (card#9417's compose box)"*.

**The gate, in two places, both the server's.** The route refuses an observer `403` (Laravel's
`can:` middleware); the page renders the compose block under `@can('operate')`, so an observer's floor
page carries **no compose slot at all** — not a hidden one — which is the card's *"compose control
absent"* in the strongest form the page can give it. `Tests\Feature\Admin\AnObserverReadsTheFleetTest::test_no_route_outside_the_admin_console_requires_the_operate_gate`
carries this route by name: `desk.compose` is on its allow-list, which is the one edit that says *a
write control and not a read surface is being gated*, and the test reds if the gate is ever dropped
from the route.

**CSRF and the throttle.** The route is in `web`, so the group's CSRF check applies to the `POST`
(the read plane never needed one: every route behind `fleet.read` is a `GET`, as `routes/fleet.php`
records); the compose form carries `@csrf` as `resources/views/dashboard.blade.php` already does, and
the client sends that token with the post. `throttle:compose` is a per-account limiter (`RateLimiter::for('compose', …)` keyed on
`users.id`), defined in `AppServiceProvider::boot()` beside the `operate` gate it pairs with — not in
`FortifyServiceProvider`, whose limiters are one authentication surface's story. The figure is in
[§ 15.15](#1515-every-number).

### 15.5 Where the credential lives, and how a missing one fails loud

`BOARD_COMPOSE_TOKEN` → `config('mezzanine.board.compose_token')` ([§ 5](#5-the-credential)'s table,
[§ 5.3](#53-the-compose-credential)). It lives in `server/.env` and nowhere else: not in the store, not
per operator, not in the session.

**Missing is loud at two moments.** At page render, the floor page's route asks
`App\Compose\ComposeCredential::refusal()` — the same check the route makes — and hands the answer
to an operator's page as data; when compose is unconfigured (or the two tokens are equal) the client
draws the block **disabled**, with § 15.11's `target_unconfigured` (or `target_shared`) row in place
of the target line, so no operator types a message into a box that cannot post it. At the route, the
post is refused by name (`unconfigured`, [§ 15.11](#1511-failure-paths-and-every-sentence-the-panel-renders))
**whatever the page said**, because configuration can change between render and post. ⛔ **The block is never hidden for a missing credential.** A
hidden block reads as *this floor has no compose box*, which is a different fact; the role gate hides
it for an observer, and nothing else does.

**Per-operator board tokens, considered and rejected.** They would make the board's own `user_id`
the attribution and spare the body its name line. They cost a credential store in Mezzanine (one
secret per operator, with issue, rotation and retirement paths the application does not have), and
they buy nothing the body line does not already give an audit: the board records *which token* posted;
the body records *which person*. One floor account, one key, the person in the text.

### 15.6 Target resolution

Resolved **on the server, at the post, from the same row the desk is rendered from** — the
`seat_state` ⋈ `seats` ⋈ `installs` row `FleetController::seat()` selects, through
`App\Read\RetirementFilter::renderable()` so a retired seat is a `404` here exactly as it is on the
read plane. That selection is hoisted into one primitive (`App\Read\SeatRow`, [§ 15.9](#159-the-composechannel-seam))
at this second caller rather than copied.

**The post carries what the operator was shown.** The client sends `expected_ref` — the target label
the panel rendered when the button was pressed (`card#9234`; for a thread target, the declared name) —
and the server treats it as a **precondition over every arm**, never as the target: the target is
resolved from the server's own state, and `expected_ref == resolvedTarget.label` is checked **after
resolution and before any live read or channel call**; a mismatch is refused `target_moved` with
**zero** board requests. Without it, the sequence *the desk renders A → a poll or delta moves tier 1 to
B → the operator presses the button for card#A → the server resolves B and posts there* lands a message on
a card the operator never saw. AT-C9's RED is a server that ignores the field.

**The precedence, closed — each step runs only if every step above it passed:**

| Step | What is checked | Refusal |
|---|---|---|
| **0 — the seat, then the configuration** | the seat exists and renders (`App\Read\SeatRow`, through the retirement filter); then `ComposeCredential::resolve()`: the compose token, its equality with the poller's, the base, and `BOARD_IDS` — ⭐ `BOARD_IDS` empty or malformed lives **here and only here** (design review F6): a read over zero boards has no winner to compare and is never `target_moved` | `404` `seat_not_found`; `503` `unconfigured` (naming the key) / `credential_shared` |
| **A — shape** | the text (a valid UTF-8 string, non-empty after trim, within [§ 15.15](#1515-every-number)'s bound measured **encoded**); `expected_ref` present and matching `^card#[1-9][0-9]*$` or [D1 § 3.1](EVENT-SCHEMA.md#31-the-seat-config-file)'s `slug` pattern — ⛔ a value that fails this step is **never echoed** into any sentence | `422` `text` / `expected_ref` |
| **B — resolution** | the arms below, in order, from the seat row alone (`TargetResolver`) | `422` `no_target` |
| **C — precondition** | `expected_ref == resolvedTarget.label` — then `ComposeChannels::for()` selects the target's channel and refuses a thread target (design review F2) | `409` `target_moved` — the desk's card moved since it was shown; then `422` `no_channel_for_agent` |
| **D — the live read** (card targets only) | with the READ token, through the poller's own reader (`App\Board\CandidateRead::winners()`, [§ 15.9](#159-the-composechannel-seam)), every configured board is read and § 4.2's winner for the seat's `board_user_id` recomputed over the fresh rows — read and **discarded**, never written to `seat_board_task`, so the compose path is not a second producer ([§ 1.2](#12-non-goals--stated-so-an-implementer-cannot-widen-scope-in-good-faith)) | the live winner is not `expected_ref`, or the user has no candidate ⇒ `409` `target_moved` — the board moved and the desk has not yet; the read degraded by any of [§ 6.3](#63-pagination-and-the-false-clean-rule)'s rules, or an unusable read token ⇒ `502` `target_unread` |
| **E — the write** | [§ 15.7](#157-the-write-and-the-receipt) | the board rows of [§ 15.11](#1511-failure-paths-and-every-sentence-the-panel-renders) |

**Step B's arms, and what each reads:**

| # | Arm | Predicate | Target |
|---|---|---|---|
| 1 | **the joined card** | `task_source = 'board_card'` and `task_ref` matches `^card#([1-9][0-9]*)$` | `BoardComment(card_id)`, label `card#N` |
| 2 | **the declared agent name** | arm 1 false; `protocol_agent_name` non-null and `protocol_agent_name_check = 'checked'` | `CoordThread(name)`, label the name — it **resolves** at step B and is compared at step C like any target, and is then **refused by name in the first build** by `ComposeChannels::for()` (`no_channel_for_agent`), because no coordination channel is declared for the install ([§ 14](#14-open-questions) item 8). The arm exists so the refusal names the seat's declared name rather than *no target* |
| 3 | **neither** | — | refused: `no_target`, naming both facts — the task source that answered (`telemetry`, `todo` since card#12447 — a to-do is the seat's own list, which no comment can reach — or `none`) and the name-check state (`undeclared`, `unchecked`, `disagreed`, or `none` before the first heartbeat) |

**What the live read costs.** One paged read per configured board — on board 14 one page of ~340 KB
([§ 12](#12-every-number-and-where-it-comes-from)), the poller's own per-tick cost — per compose, at a
human rate capped by `throttle:compose`. It is paid so that the write is conditioned on the board's
state rather than on a projection up to one cadence old, and [§ 15.3](#153-the-one-verb-and-why-it-is-not-an-instrument-inside-the-measurement)
leg 2 states what it does not buy.

**Why arm 1 reads the projection and not `seat_board_task`.** The projection is what the operator is
looking at. It carries the 30-minute bound ([§ 8.1](#81-the-function)): a card whose row has aged out
is a card the desk has **dropped** and labelled degraded, and a compose that posted to it would be
posting to a target the floor has stopped showing. Reading the input table would re-implement the
bound a second time or skip it; reading the projection reuses it. The cost is [§ 2.5](#25-the-one-bounded-window-stated-rather-than-glossed)'s
window — for one sweep cadence after a rebuild, `task_*` is null and arm 1 refuses — and that refusal
is honest, self-healing, and the same window every other derived column has.

**Why step D is skipped for a thread target.** The live read answers one question — *is this card
still the seat's § 4.2 winner?* — and a thread target has no card; its precondition is step C alone.

**Why arm 2 requires `checked` and not merely a name.** D1 § 3.1's state table: `unchecked` is a name
no roster could confirm, `disagreed` is a name the roster does not hold. A thread addressed to either
is addressed to a string, not a seat, and D3 § 5.7 already renders both as *unresolved* for that
reason. The compose path adopts the same bar rather than a looser one.

**Why the order is card first.** The card is the target the floor already joins, already shows and
already holds a credential for; the thread is a target three rulings away. When both resolve, the
card wins because it is the one the operator can see on the panel at the moment of composing — the
target line names it, so there is no surprise about where the message went.

### 15.7 The write, and the receipt

**One write shape, and only one.** `POST {BOARD_API_BASE}/tasks/{card_id}/comments.json` with the
body `{"content": "<text>"}` — flat, which `kbcard` measured against the wrapped form that `422`s — and
`Authorization: Bearer <compose token>`, `Accept: application/json`, no redirects followed, connect and
read timeouts at [§ 12](#12-every-number-and-where-it-comes-from)'s 20 s. The channel issues this write
and no other: no `PATCH`, no second `POST`, and no `GET` of its own — the only `GET`s on a compose are
[§ 15.6](#156-target-resolution) step D's live read, issued by the poller's reader with the read token
**before** the channel is called, so a channel test sees exactly one request and a controller test
sees the read's pages followed by exactly one `POST`. ⛔ **The `comments.json` request builder lives
inside `App\Compose` (`BoardCommentChannel`) and nowhere else** — AT-C10's population holds that the
path literal has one home — so the one-verb rule is a property of where the write can be built from,
not of who remembers not to call it.

**The content.** One attribution line, then a blank line, then the operator's text verbatim:

```
Posted from the Mezzanine floor by <users.name> at 2026-10-08T14:03:11Z
<blank line>
<the operator's text>
```

`users.name` and not the email — the comment is readable by every account on the board and by every
agent that reads the card, and a name is what an audit needs. The timestamp is the server's, in D2's
`rfc3339_ms` form truncated to seconds. The text is trimmed, must be non-empty, and is bounded in
encoded bytes ([§ 15.15](#1515-every-number)); it is otherwise untouched — D1 § 7's sanitizer is the reporter's
and this is an operator's own words on an operator's own board.

**The receipt is the board's own confirmation, not ours.** Success is a `2xx` (the board answers
`201`) whose body carries `data.id` as an integer; that integer is the receipt (`comment#M`). A `2xx` with no integer id is
**`unverified`** — the post may have landed, and the panel renders that row of
[§ 15.11](#1511-failure-paths-and-every-sentence-the-panel-renders)'s table, which owns the words —
and nothing is retried. ⛔ No retry anywhere on this path: a retried comment is a duplicate comment,
and the operator is present to decide.

**The shared client.** `BoardPoll` today owns the base validation, the token shape check, the
`Http::withToken()…withoutRedirecting()` builder and `redact()`. The compose path is the second real
caller of every one of them, so they are hoisted into `App\Board\BoardHttp` and both callers use it
(canon #5) — a hoist, not a rewrite: `BoardPoll`'s behaviour and every AT-D4 test are unchanged by it,
and that is asserted by the Board suite staying green across the hoist with no test edited.

### 15.8 The audit trail — the comment itself, and no new table

The board holds the row: its `id`, `task_id`, `user_id` (the floor's account), `content` (the name
line and the text) and `created_at`. That is who, what, when and where, on the surface the agent reads
and the operator can open, and it survives a Mezzanine rebuild because Mezzanine never held it.
**No table is added**, no `events` kind, no `seat_state` column: a compose changes nothing the fold
derives, so there is nothing for a rebuild to reproduce (the rule [§ 2.3](#23-the-rule-this-establishes)
states, applied in the direction of writing nothing).

One application log line per outcome, at the fields [§ 5.1](#51--the-token-value-never-reaches-an-output-stream)
admits: `compose posted seat=<install>/<seat> card=<id> comment=<id> user=<users.id>` or
`compose refused seat=… class=<class> status=<n> board_status=<n|-> url=<redacted|->` (`status` is the
response's, `board_status` the board's where it answered; a seat that is not found logs
`class=seat_not_found`) — the refused line carrying the
request URL with its userinfo redacted, exactly as `BoardPoll::countFailure()` logs the poller's
(`'url' => $failure->redactedUrl`), so that AT-C5's planted defect has a line it can appear on; never
the text, never a header, never a body.
**No counter**: a compose fails synchronously in front of the operator who sent it, so the failure is
legible by construction and a `compose_failed` on `GET /api/fleet/health` would count what a person
has already read.

### 15.9 The `ComposeChannel` seam

```php
namespace App\Compose;

interface ComposeChannel
{
    /** 'board_comment' now; 'coord_thread' and 'slack' later. One kind per implementation. */
    public function kind(): string;

    /** @throws ComposeRefused  one of § 15.11's classes; never a free-text message */
    public function post(ComposeTarget $target, ComposeMessage $message): ComposeReceipt;
}

final class ComposeTarget  { kind, address, label }        // 'board_comment', 9234, 'card#9234'
final class ComposeMessage { authorName, text, composedAt } // the attribution line is the CHANNEL's to render
final class ComposeReceipt { kind, address, ref, postedAt } // 'board_comment', 9234, 'comment#117', …
final class ComposeRefused extends \RuntimeException        // BoardPollFailed's shape: class, status, redactedUrl, plus § 15.11's members (ref, boardStatus, key, moved, maxBytes, taskSource, nameCheck) — NO message parameter
final class TargetResolver { public function resolve(object $seatRow): ComposeTarget; }   // § 15.6 step B; throws ComposeRefused (no_target only)
final class ComposeCredential { resolve(): array; refusal(): ?ComposeRefused; }           // § 5.3: the ONE reader of the compose token, and step 0's check; the page and the route both ask it
final class ComposeChannels { public function for(ComposeTarget $t): ComposeChannel; }    // kind ⇒ implementation; a thread target throws no_channel_for_agent; unknown kind throws
final class BoardCommentChannel implements ComposeChannel   // § 15.7 — the write only; the ONE home of the `comments.json` builder
```

**Two hoists in `App\Board` and `App\Read`, named so the build does not copy.**

- **`App\Board\CandidateRead::winners(array $ids, string $base, string $token, ?callable $say): array<int, array<string, mixed>>`**
  — board user id ⇒ the § 4.2 winner — **owns the paging and § 4.2's fold**, hoisted out of
  `BoardPoll::read()` and `beats()`; `CandidateRead::boardIds()` is the one `BOARD_IDS` parse.
  **`BoardPoll::run()` and the compose path both call it.** Per design review F7, `run()` keeps the
  `BOARD_IDS` parse call and hands the `-v` per-board line in as `$say`, so the poller's behaviour is
  unchanged; the compose path passes no `$say`. It throws `BoardPollFailed` and **never counts and
  never logs**: `run()` alone keeps
  `countFailure()` and the counters, so a compose's live read moves `board_poll_failed` by nothing and
  writes no poll log line — the counter counts polls. The read token is read by `BoardPoll::credential()`
  as today (now public, so the compose path's live read takes the same token from the same reader),
  handed in. `App\Board\BoardHttp` is § 15.7's shared client.
- **`App\Read\SeatRow::find()`** — the `seat_state` ⋈ `seats` ⋈ `installs` selection hoisted from
  `FleetController::seat()`, **widened by one column: `seats.board_user_id`**, which the compose path
  needs to pick the live winner and which the read plane today does not select. `FleetController`
  keeps publishing what it published (`SeatObject` reads no new member), so the widening changes no
  wire.

**What the seam fixes and what it leaves open.** `ComposeMessage` is channel-neutral — a name, a
text, a time — and each channel renders attribution in its own idiom (a comment body line here; a
`FROM:` line and labels on a coordination thread; a Slack `username` later). The target's `kind`
selects the channel, so *"chosen per agent"* (the card's phrase for Slack) is a resolver change —
a per-seat declaration of which kinds it reads, on the model of `seats.board_user_id` — and not a
channel change. The controller, `App\Http\Controllers\DeskComposeController::store()`, is the one
caller of the post: resolve, select, post, render; it holds no channel logic and renders no sentence.
The floor page's route (`routes/web.php`) is the package's only other caller, and asks
`ComposeCredential::refusal()` and nothing else (design review F3; AT-C10 holds the pair).

**Shape (b) stays what the card said.** A bridge endpoint that stages a free-text intent for one agent
is the bridge owner's build; if it lands, `BridgeMessageChannel` is one more implementation behind
this interface and the resolver gains an arm. Nothing here pre-builds it.

### 15.10 The panel

A D3 § 4.3 amendment ([§ 15.14](#1514-amendments-owed-to-other-surfaces), landed with the build); the
behaviour it specifies:

- **An operator's panel gains a `compose` block**: a target line, a text box, a button, and a receipt
  line. The block is rendered under `@can('operate')`; an observer's page does not carry it. Its slots
  are `data-panel-compose-target`, `data-panel-compose-text`, `data-panel-compose-post` and
  `data-panel-compose-receipt` inside `data-panel-compose`, and they join the slot contract
  `Tests\Feature\DrillDown\DrillDownModuleWiringTest` checks both ways — which that test fetches **as
  an operator**, and a second arm fetches as an observer and asserts no `data-panel-compose` slot in
  the HTML at all.
- **The target line is computed by the client from the held seat object alone** — the `target_card`
  row when `task.source` is `board_card`; else the `target_thread` row when
  `protocol_agent_name_check` is `checked`; else the `target_none` row; and the `target_unconfigured`
  or `target_shared` row whenever the page was rendered with compose refused (§ 15.5) — and **the post
  carries the label it rendered as `expected_ref`**, so the server re-resolves and refuses
  `target_moved` if the two differ. The button (the `button` row) is drawn beside a card target only
  and is disabled whenever the client's resolution is a refusal, so the common case costs no request.
- **The receipt line renders exactly one sentence, and every sentence the block can render — target
  line, button, success, in flight, and every refusal — is a row of [§ 15.11](#1511-failure-paths-and-every-sentence-the-panel-renders)'s
  table.** This section owns none of them, so no second copy exists to drift; AT-C8 drives the client
  over every row and asserts the slot against that table. The text box is cleared on the `posted` row
  and on no other; a refusal leaves the text where the operator can resend or edit it, and the panel's
  1 s re-render never touches it. ⛔ The word *delivered* appears in no row, which AT-C8 holds.
- **While a post is in flight** the button is disabled, a second press sends nothing, and the receipt
  line reads the `in_flight` row; a delta arriving meanwhile re-renders the target line and nothing
  else of the block. The post follows no redirect, so a signed-out answer is read as § 15.11's
  `client_401` row rather than as a login page.

### 15.11 Failure paths, and every sentence the panel renders

⭐ **This table is the ONE owner of every sentence the compose block can show** — the target line, the
button, success and in-flight included — and of every refusal class and status. Every other section
of this document, and D3's rows ([§ 15.14](#1514-amendments-owed-to-other-surfaces)), points here and
restates nothing. AT-C8 parses this table out of the document on every run and drives the shipped
client over every keyed row, so a sentence edited here reds the client until it follows, and a client
sentence that is in no row has nowhere to hide. An earlier revision carried the sentences in three
places and one copy kept saying *not posted* for a timed-out send after the others had stopped — the
duplicate-comment defect, minted by a restatement.

**How a row reads.** `Key` is the row's stable name: the client's sentence map and AT-C8's fixtures are
keyed by it, and a row whose key is `—` renders no sentence. The sentence is the one italic span in
its cell, written with **marked placeholders** that the client substitutes and nothing else:

| Placeholder | Substituted with |
|---|---|
| `{card_a}` | the target label the panel showed and the post carried as `expected_ref` — a `card#N`, or for a thread target the declared name |
| `{card_b}` | the response's `ref` on a `target_moved` — the label the server resolved, or the board's live winner |
| `{comment_m}` | the success response's `ref` — `comment#M`, the board's own comment id |
| `{hh_mm_ss}` | the success response's `posted_at`, in the viewer's zone through the one converter (`wire/clock.js`) |
| `{key}` | the refusal's `key` — the configuration key that is empty or unusable |
| `{max_bytes}` | the refusal's `max_bytes` — [§ 15.15](#1515-every-number)'s message bound |
| `{board_status}` | the refusal's `board_status` — the board's HTTP status, never its body |
| `{task_source}` | the refusal's (or, on the target line, the seat object's) task source: `telemetry`, or `none` when no task answered |
| `{name_check}` | the refusal's (or the seat object's) `protocol_agent_name_check`, or `none` before the first heartbeat |
| `{name}` | the declared `protocol_agent_name` |

**The response body** of `desk.compose` is `{ref, posted_at}` on success and
`{class, ref?, board_status?, key?, moved?, max_bytes?, task_source?, name_check?}` on a refusal — a
member present only where its row substitutes it, plus `moved` (`desk` or `board`), which tells the
two `target_moved` rows apart. `ComposeRefused` carries exactly these members (`ref`, `boardStatus`,
`key`, `moved`, `maxBytes`, `taskSource`, `nameCheck`) and the redacted URL for the log, and never a
message parameter. The `404` is the read plane's own `seat_not_found` body.

The classes are a closed set on `ComposeRefused`. The status to the client is one per meaning: `403`
for the gate, `404` for a desk that is gone, `422` for a refusal the request's shape or the seat's
state produced, `409` for a precondition the server's own resolution or the board's live state failed,
`502` for a board that did not answer as the contract says, `503` for an unconfigured path. **Three
sentence families, by what is known:** *not posted* — said only where no write was attempted, or the
transport proves the write never left; *may be on {card_a} — read the card before posting again* —
said wherever a write may have landed and the board's answer is not in hand, **and for any answer the
client does not recognise**; *posted* — said only on an integer comment id from the board.

| Key | Condition | Class | Status | Panel sentence | Board requests |
|---|---|---|---|---|---|
| `target_card` | **target line** — the seat object's `task.source` is `board_card` with a `card#N` ref | — (client) | — | *a comment on {card_a}, written as you* | — |
| `button` | **the button** — beside `target_card` only; disabled on every other target line | — (client) | — | *Post to {card_a}* | — |
| `target_thread` | **target line** — no joined card, and a `checked` declared name: the refusal the server will give, so the button is disabled | — (client) | — | *this desk declares `{name}` and no coordination channel is configured for this install* | — |
| `target_none` | **target line** — no joined card and no checked name ([§ 15.6](#156-target-resolution) arm 3); the button is disabled | — (client) | — | *no target: this desk shows no board card (task answered by `{task_source}`) and declares no checked agent name (`{name_check}`)* | — |
| `target_unconfigured` | **target line** — the page was rendered with compose unconfigured ([§ 15.5](#155-where-the-credential-lives-and-how-a-missing-one-fails-loud)); the block is drawn disabled, never hidden | — (client) | — | *compose is not configured — set `{key}`* | — |
| `target_shared` | **target line** — the page was rendered with the compose token equal to the poller's; the block is drawn disabled | — (client) | — | *compose is refused — `BOARD_COMPOSE_TOKEN` is the same value as `BOARD_API_TOKEN`* | — |
| `posted` | **success** — a `2xx` whose body carries `data.id` as an integer (the board answers `201`) | — | `200` | *posted as {comment_m} on {card_a} at {hh_mm_ss} — the floor does not confirm delivery* | the read's pages + 1 write |
| `in_flight` | **in flight** — the client's own state between press and answer | — (client) | — | *posting…* | — |
| — | observer | — (`can:operate`) | `403` | no block exists to render one | 0 |
| `client_419` | **client** — the page's CSRF token expired (`419`) | — (client) | — | *the page has expired — reload the floor and post again; the message was not posted* | 0 |
| `client_401` | **client** — signed out meanwhile (`401`, or the login redirect, which a post that follows no redirect reads as status `0`) | — (client) | — | *you are signed out — sign in again; the message was not posted* | 0 |
| `client_429` | **client** — `throttle:compose` (`429`) | — (client) | — | *too many posts — wait a minute; the message was not posted* | 0 |
| `client_403` | **client** — `403` on a page rendered while the account was still an operator | — (client) | — | *this account can no longer post from the floor; the message was not posted* | 0 |
| `desk_gone` | seat not found or retired (step 0) | — | `404` | *this desk is gone — it was retired or is no longer served; the message was not posted* | 0 |
| `unconfigured` | `BOARD_COMPOSE_TOKEN` empty or unusable; `BOARD_API_BASE` empty or not `https`; **or `BOARD_IDS` empty or malformed** (step 0) — `key` names the first of these that fails | `unconfigured` | `503` | *compose is not configured — set `{key}`; the message was not posted* | 0 |
| `credential_shared` | compose token equals the poller's token ([§ 5.3](#53-the-compose-credential)) (step 0) | `credential_shared` | `503` | *compose is refused — `BOARD_COMPOSE_TOKEN` is the same value as `BOARD_API_TOKEN`; the message was not posted* | 0 |
| `text_empty` | text absent, not a string, or empty after trim (step A) | `text` | `422` | *write something — the message was not posted* | 0 |
| `text_bytes` | text over the byte bound, measured encoded (step A, [§ 15.15](#1515-every-number)) | `text` | `422` | *the message is over {max_bytes} bytes — the message was not posted* | 0 |
| `expected_ref` | `expected_ref` absent or malformed (step A) — ⛔ the submitted value is **never** echoed | `expected_ref` | `422` | *the post did not say which card it was for — reload the floor and post again; the message was not posted* | 0 |
| `no_target` | no joined card and no checked name (step B) | `no_target` | `422` | *no target: this desk shows no board card (task answered by `{task_source}`) and declares no checked agent name (`{name_check}`); the message was not posted* | 0 |
| `no_channel_for_agent` | a checked name and no channel for it (`ComposeChannels::for()`, after step C) | `no_channel_for_agent` | `422` | *this desk declares `{name}` and no coordination channel is configured for this install; the message was not posted* | 0 |
| `target_moved_desk` | the resolution's label is not `expected_ref` (step C) — the **desk** moved; `moved` is `desk` | `target_moved` | `409` | *the desk's card has moved to {card_b} since you were shown {card_a}; the message was not posted* | 0 |
| `target_moved_board` | the live winner is not `expected_ref` (step D) — the **board** moved and the desk has not yet; `moved` is `board` | `target_moved` | `409` | *the board now shows {card_b} as this desk's card, and the desk follows at its next read; the message was not posted* | the read's pages; 0 writes |
| `target_moved_board_no_card` | the user has no candidate on the live read (step D); `moved` is `board`, no `ref` | `target_moved` | `409` | *the board now shows no card for this desk, and the desk follows at its next read; the message was not posted* | the read's pages; 0 writes |
| `target_unread` | the live read degraded (any [§ 6.3](#63-pagination-and-the-false-clean-rule) rule, `401`/`403`, transport, an unusable read token) (step D) | `target_unread` | `502` | *the board could not be read to confirm {card_a}; the message was not posted* | the read's pages; 0 writes |
| `auth` | board `401` / `403` on the write | `auth` | `502` | *the board refused the floor's credential (`{board_status}`); the message was not posted* | 1 write |
| `status` | board other non-`2xx` on the write, a redirect included | `status` | `502` | *the board answered `{board_status}`; the message was not posted* | 1 write |
| `transport` | the write failed **before it was sent** — name resolution, connection refused, connect timeout, TLS handshake (the client's own connect-class failure) | `transport` | `502` | *the board could not be reached; the message was not posted* | 1 attempted write |
| `unverified_lost` | the write failed **after it was sent, or it cannot be told** — read timeout, connection lost mid-exchange, any transport error that is not connect-class | `unverified` | `502` | *the board's answer did not arrive — the message may be on {card_a}; read the card before posting again* | ≤ 1 write |
| `unverified_no_id` | `2xx` with no integer `data.id`; `board_status` carries the `2xx` | `unverified` | `502` | *the board answered without a comment id — the message may be on {card_a}; read the card before posting again* | 1 write |
| `no_response` | no response reaches the browser — the browser cannot tell a request that never left from one whose answer was lost | — (client) | — | *the floor got no answer from the server — the message may be on {card_a}; read the card before posting again* | ≤ 1 write |
| `unrecognised` | **the catch-all** — any response the client cannot place in a row above: no recognised class, a class whose status is not its row's, a body that is not JSON (a post-write `500`, a proxy's HTML `502`/`504`), a `200` without a `comment#M` | — (client) | — | *the server's answer was not one the floor recognises — the message may be on {card_a}; read the card before posting again* | ≤ 1 write |

⛔ **Zero board requests on every row above the step-D rows is a property, not a convenience**: a
refusal decided from local state must never have cost the board a request, and AT-C3/AT-C4/AT-C9
assert the count. ⛔ **The four `client_*` rows are refusals the middleware makes before the controller
runs** (`VerifyCsrfToken`-class, `auth`, `throttle`, `can:operate`), so *not posted* is known there by
construction. ⛔ **A `non-2xx` with a body is reported as its status alone**: a board that echoes the
request into an error body must not be able to write the message, or a header, into the panel or the
log. ⛔ **The catch-all is in the *may be* family on purpose**: an answer the client cannot read is an
answer it cannot rule a write out of.

### 15.12 Acceptance tests

*In `server/tests/Feature/Compose/`, on a `ComposeTestCase` that extends
`Tests\Feature\Board\BoardTaskTestCase` — its `Http::fake()` for the fixture host and its
`configure()` are reused, the latter gaining the compose token. Every test names its RED and is seen
red before it is trusted (the build brief's rule).*

- **AT-C1 the gate.** *Build:* an observer and an operator, both `twoFactorConfirmed()`. *GREEN:* the
  observer's `GET /floor/{install}/{seat}` carries no `data-panel-compose` slot; the observer's
  `POST desk.compose` is `403` with zero board requests; the operator's page carries the four slots.
  *RED:* drop `can:operate` from the route — the observer's post is answered by the resolver, and
  `AnObserverReadsTheFleetTest`'s population arm reds in the same run. *Control:* the operator's post
  on the same fixture reaches the fake board, so the `403` is a measurement.
- **AT-C2 one write, one shape, the right token.** *Build:* a mapped seat with a fresh
  `seat_board_task` row (`card_id = 9234`) and a folded `seat_state` showing it; the fake board's
  search page lists 9234 as the user's only candidate and answers the comment `POST` with
  `201 {"data":{"id":117}}`; the post carries `expected_ref = card#9234`. *GREEN:* `Http::recorded()`
  holds the search `GET`(s) with the **read** token and then **exactly one** write — `POST`,
  `/tasks/9234/comments.json`, body `{"content": "…"}` opening with the attribution line, `Authorization:
  Bearer <compose token>` and not the poller's — and no other `POST`; the response carries
  `comment#117`; the log line names seat, card, comment and user id and nothing else. *RED (two):* swap
  the tokens in the channel — the header assertion fails; add a `GET /tasks/9234.json` read-back after
  the write — the write-side population is wrong.
- **AT-C3 refusal by name, zero requests.** *Build:* a seat with `task_source = telemetry` and
  `protocol_agent_name_check = undeclared`; the post carries an explicit, well-formed
  `expected_ref = card#9234` (so step B's refusal is shown to come before step C's comparison, not
  instead of a missing field). *GREEN:* `422 no_target`, both facts substituted into the sentence,
  `Http::recorded()` empty. Repeat for `checked` with a name and `expected_ref` = that name →
  `422 no_channel_for_agent` naming it. *RED:* a resolver that falls through to tier 3's title or to any card the user holds — a
  request appears.
- **AT-C4 unconfigured does not borrow the poller's token.** *Build:* `compose_token` empty,
  `api_token` set, a joined card. *GREEN:* `503 unconfigured` naming the key, zero requests, and the
  operator's page renders the block disabled with the same sentence. *Second build:* both keys equal →
  `503 credential_shared`, zero requests, **and `mezzanine:board-poll` still runs clean** on the same
  config. *RED:* a channel that reads `api_token` when `compose_token` is empty — the request goes
  out with the read token.
- **AT-C4b the poller never sends the compose token.** *Build:* the two tokens as **literals** —
  `api_token = 'kbr_at_c4b_read'`, `compose_token = 'kbw_at_c4b_compose'` — two configured boards with
  a two-page fixture; run `mezzanine:board-poll`. *GREEN:* every request in `Http::recorded()` carries
  `Authorization: Bearer kbr_at_c4b_read` and the literal `kbw_at_c4b_compose` occurs in **no** header,
  URL or body of any request. *RED:* make `BoardPoll::credential()`
  read `compose_token` — the first request's header fails the assertion. *Control:* the same
  assertion run with the two values swapped in config must fail, so the header is known to be read.
- **AT-C5 the credential is never emitted** — AT-D4-4's build over the compose path: every row of
  [§ 15.11](#1511-failure-paths-and-every-sentence-the-panel-renders) at maximum verbosity, the token's value occurring zero times in
  stdout, stderr, the log and the response bodies. *RED, required first:* move the compose token into
  the query string in `BoardHttp`'s write builder and drive the `status` row — the refused line's
  `url=` field ([§ 15.8](#158-the-audit-trail--the-comment-itself-and-no-new-table)) must show it,
  which is why that line carries the redacted URL at all; a compose log with no URL field would make
  this RED unreachable and the GREEN a decoration.
- **AT-C6 the live control — cross-boundary, not in this suite.** On the sandbox, an operator posts
  on a live seat's joined card; `kbcard comments --task <N>` shows the row with the name line.
  Whether the seat's channel receives it is **not asserted** ([§ 15.2](#152-the-hop-and-what-this-repository-cannot-verify)); the
  run is recorded on the card with what was and was not observed, as [§ 13](#13-what-is-deliberately-not-built)
  records the poller's.
- **AT-C7 a compose is conditioned on the board, not on the projection.** *First arm:* one board user
  holding cards A and B, `A.updated_at > B.updated_at`; a poll makes A the projection's card; the live
  fixture still lists A as the winner; a compose on A with `expected_ref = card#A` posts (one write).
  *Second arm — the F2 sequence:* the projection says A (poll at T0), **the board's fixture now carries
  B with a newer `updated_at`** (touched at T1), a compose on A with `expected_ref = card#A` arrives at
  T2. *GREEN:* `409 target_moved` with the step-D sentence naming `card#B` (the board-moved form, not
  the desk-moved one), `Http::recorded()` holds the search `GET`(s) and **zero** `POST`s,
  `seat_board_task` is byte-identical (the live read wrote nothing) and `board_poll_failed` /
  `board_poll_ok` are unmoved (the reader counts nothing). *RED:* a compose
  path that resolves from the projection alone — the write goes out and the next poll's winner is A,
  which is the desk keeping A while the seat works B. *Control:* the first arm, so the comparison is
  known to be able to see a post.
- **AT-C9 the post lands only on the card the operator was shown.** *Build:* the projection and the
  live fixture both say B; the post carries `expected_ref = card#A` (the panel rendered A before a
  delta moved the desk). *GREEN:* `409 target_moved` with the step-C (desk-moved) sentence naming A
  and B, **zero board requests** — the mismatch is decided before the live read. *Second build:*
  `expected_ref` absent, then malformed (`card#0`, `../x`, a 300-byte string): `422 expected_ref`,
  zero requests, and **the submitted value occurs nowhere in the response body** (the esoteric case:
  a failed-validation value is never echoed). *Third build — the thread arm:* a seat with no board
  card and a `checked` name, `expected_ref` = a different name: `409 target_moved` before
  `no_channel_for_agent` would have answered — the precondition binds every arm. *RED:* a server that
  ignores `expected_ref` — the live read runs and the write lands on B.
- **AT-C10 the capability population — who can read the compose token, and who can build the
  write.** Two populations over the tree, each listed by name so an empty answer is a measurement, by
  a walk of every `.php` under the named roots (tracked or not), so the controls can point the same
  matcher at a planted tree. **(a) readers of the compose token** — reads, not name mentions (design
  review F3): `mezzanine\.board\.compose_token` or `env('BOARD_COMPOSE_TOKEN')` under `server/app`,
  `config`, `routes`, `bootstrap`, `resources` and `database` is exactly
  `{server/app/Compose/ComposeCredential.php, server/config/mezzanine.php}` — which is
  [§ 5.3](#53-the-compose-credential)'s check; a refusal naming the key to the operator is not a read.
  **(b) builders and callers of the write:** `comments\.json` under `server/app` is exactly
  `{server/app/Compose/BoardCommentChannel.php}`, and `App\Compose(\|;)` under `server/app`,
  `server/routes` and `server/bootstrap`, minus `server/app/Compose/`, is exactly
  `{server/app/Http/Controllers/DeskComposeController.php, server/routes/web.php}` — the second being
  the floor page's render-time `ComposeCredential::refusal()` (F3) — and the `(\|;)` tail is what
  makes a bare `use App\Compose;` a member rather than a miss. No command, job, scheduler entry,
  listener or provider binding appears in either. *RED (three), planted in a scratch tree and asserted
  every run:* read `compose_token` in `BoardPoll::credential()`; build a `comments.json` URL in a
  console command; add `use App\Compose;` to any command — each is found, and a bare mention of the
  key's name is not. ⚠ *What it cannot see:* a read through a computed key, or of the whole
  `mezzanine.board` array; none exists, and a reviewer of a change that adds one is the check.
- **AT-C8 the receipt renders [§ 15.11](#1511-failure-paths-and-every-sentence-the-panel-renders)'s
  table and nothing else.** *Build:* drive the shipped client — `compose-box.js`, `compose-model.js`
  and `drilldown/main.js`'s `renderCompose()`, through `tests/Feature/Compose/compose-probe.mjs` — over
  **every keyed row** of that table: the target lines and the button, the success response, the
  in-flight state, each client row's status, each server class at the status the table gives it, no
  response, and the catch-all. The expected sentences are **parsed from the document**, the table's
  marked placeholders filled with the fixture's values, so a sentence changed in the table reds the
  client until it follows. *Parse floor* (design review F1): the rows cover every class of
  `ComposeRefused`'s closed set plus every client row, the client's sentence map holds exactly the
  table's keys, and a renamed header makes the parse return nothing (*control*, precedent
  `DurationFormatMatchesTheDocumentTest`). *GREEN:* each slot's text equals its row's sentence with no
  placeholder left; the string `delivered` occurs in none; the text box is cleared on the success row
  only and survives a later render; a second press while in flight sends nothing. *RED (two), planted
  every run:* a client that writes *delivered to the agent* on success; a client that writes *the
  message was not posted* on an `unverified` class.

### 15.13 What the operator provisions

Three acts, each a declaration only the operator can make, and compose stays unconfigured — by name,
at the panel and at the route — until the first two are done:

1. **A board account for the floor** (the comment author the board records), with the narrowest role
   that can comment on board 14's cards, and **a write-scoped token for it** — `BOARD_COMPOSE_TOKEN`
   in `server/.env` on each host, beside the existing three keys ([§ 14](#14-open-questions) item 6).
   ⛔ Not the poller's token, and not any seat's. The bridge side names this account
   **`mezzanine-floor`**, and two bridge settings follow from it, each of which would drop a
   floor comment silently (the bridge's `docs/consumer-guide.md` § Card comments and § Echo-suppression
   semantics own the mechanics): ⛔ **`mezzanine-floor` is never the bridge's writeback identity** —
   the bridge's echo gate drops a comment whose author is that identity, so the assignee would never be
   woken; and ⛔ **an assignee seat that sets `echo_suppression.treat_as_signal` must have
   `mezzanine-floor` on that list** — a non-empty list admits only the authors it names.
2. **The seat → board-user mapping and an assigned card** — [§ 10](#10-dark-on-arrival)'s conditions 2
   and 3, unchanged: arm 1 has nothing to resolve until tier 1 is lit. Compose arrives as dark as tier
   1 did, and for the same reasons, each separately legible.
3. **✅ FILED — the bridge-side request** ([§ 14](#14-open-questions) item 7; the operator chose fork
   (a) on 2026-10-09): a feature request to kanban-solo for
   `comment.created` in the subscription filter and a `card_comment` intent routed to the seat whose
   card it is. It has landed upstream (the bridge's `card_comment` intent); live delivery on a host
   still needs [§ 15.2](#152-the-hop-and-what-this-repository-cannot-verify)'s three conditions. Until
   they hold, a comment reaches the agent at its next read of the card, and the receipt's `posted` row
   ([§ 15.11](#1511-failure-paths-and-every-sentence-the-panel-renders)) says the floor does not confirm
   delivery.

### 15.14 Amendments owed to other surfaces

Stated as exact requests, per [§ 1.3](#13-the-boundary-stated-as-a-rule); ratified before anything was
built against them; **landed by the build (card#9417) in the same change** — each row's surface now
carries it.

| Surface | Amendment |
|---|---|
| D3 § 4.3 (the panel table) | a row **`compose` — an operator's only**: *a target line naming where a message goes (`task.ref` when `task.source` is `board_card`; else the refusal § 15.6 names), a text box, a button beside a card target and a receipt line whose every sentence is a row of BOARD-TASK § 15.11's table, which D3 cites and does not restate; rendered under `@can('operate')`, so an observer's page carries no compose slot; the post carries the rendered label as `expected_ref`; source: `task.*`, `protocol_agent_name`, `protocol_agent_name_check`, and the `desk.compose` response* |
| D3 § 5.2 (the drill-down render map) | a row **the compose receipt** — source *the `desk.compose` response*; example `comment#117`; rule *one sentence, the row BOARD-TASK § 15.11 gives for the response's class and status, cited and not copied; never the word* delivered; *`fetch-fresh` by construction — no delta carries it* |
| D3 § 9 (failure paths) | one row for the compose post's client-side outcomes — no response, `419`, `401`, `429`, `403` — whose render column says *the sentence BOARD-TASK § 15.11's client rows give for the case*, with the text left in the box; D3 carries no sentence of its own, because the copy that drifted in an earlier revision of this section was exactly such a row |
| D3 § 1.2 (non-goals) | a row stating that the compose box is the floor's one write, that it writes to the board and never to a seat, and that this document owns it — D3 states no read-only claim of its own to narrow (a grep of `FLOOR.md` for *read-only* finds none), but a non-goals table that does not name the one write the panel now carries would be read as excluding it. The phrase *read-only telemetry* lives in `AnObserverReadsTheFleetTest`'s docblock, where it describes the read surfaces and stays true |
| D2 | **none.** No route under `/api/fleet`, no store column, no counter, no event kind, no process row ([§ 15.8](#158-the-audit-trail--the-comment-itself-and-no-new-table)); D2 § 9's gate text already names *every per-desk write route* |
| `README.md § The board card on a desk` (the step that lists `BOARD_API_TOKEN`) and `§ The admin console` (the sentence on what an operator's desk detail offers) | the fourth key in the first; in the second, one sentence: an operator's desk detail also offers a compose box that posts a comment on the agent's current card |
| `server/.env.example` | `BOARD_COMPOSE_TOKEN=` with the comment block naming it write-scoped, compose-only and never the poller's |
| `docs/PLAN.md § 0` (the card#9415 entry) | no change: *"writes into a channel the agent already reads"* stays true of the card as a document the agent reads; what this section adds is that the live hop is the bridge's, and is dark on a host until [§ 15.2](#152-the-hop-and-what-this-repository-cannot-verify)'s three conditions hold |
| `AnObserverReadsTheFleetTest` | `desk.compose` in the allow-list |

### 15.15 Every number

| Value | Number | Basis |
|---|---|---|
| message bound | **2,000 bytes** of operator text, after trim, **measured encoded** — the bytes of the text as a JSON string with raw UTF-8 (`ComposeMessage::encodedBytes()`), so a control character counts as its 6-byte escape and an emoji as its 4 bytes | **Chosen** — a sentence or a short paragraph is the product (*say one thing to the agent*). Measured encoded because that is the size that travels: the board sends non-ASCII as raw UTF-8 and a control character as a 6-byte escape, which is the bridge's own account of a comment's delivered size (the bridge's `docs/consumer-guide.md` § Card comments, `body` row). A `422` from the board is still reported as class `status`. Re-derive if a lower board bound is published |
| composed message ceiling | **the bound above + the attribution line**: `Posted from the Mezzanine floor by ` + `users.name` (a `VARCHAR(255)` — `database/migrations/0001_01_01_000000_create_users_table.php` — at most 255 characters, each at most a 6-byte escape encoded) + ` at ` + a 20-character stamp + two encoded newlines | **Derived** — and set against the board's comment cap, **65,535 characters**, **cited** from the bridge's `docs/consumer-guide.md` § Card comments (`body` row): the composed message is more than an order of magnitude under it at its worst case, so a floor message is never the comment the board or the bridge truncates (card comment 10727). A test builds the worst case and measures it (`AtC1ToC4ComposeGateAndWriteTest`), so a widened bound or column re-measures there. ⚠ The board's encoded-size cap on a comment's content (kanban card 11588) has a figure this seat cannot read (the card answers `403` to this seat's board token); if it publishes one below the ceiling, re-derive |
| `throttle:compose` | **10 per minute per account** | **Chosen** — a person composing by hand cannot reach it; a stuck client or a pasted loop does, and the limit is what keeps one account from filling a card with duplicates. Keyed on `users.id`, never IP: an operator is a person, not an address |
| board rate limit, the shared READ token | **300 requests per window**, per credential | **Measured 2026-10-10** — the board publishes it on every response as `X-RateLimit-Limit: 300` with `X-RateLimit-Remaining`; read with one `GET` of [§ 6.1](#61-the-endpoint)'s endpoint (`limit=1`) under the READ token, printing only those headers, and never driven toward a `429`. Remaining fell by one per request and was back at 299 within 95 s (07:25:51Z → 07:27:26Z), so the window is at most that, which fits Laravel's per-minute default; the window length itself is not published. A request with this seat's own (different) board token did not move the READ token's count, so the bucket is per credential (per token or per account — this measurement cannot tell which). **What it means here:** the compose path's live read uses the READ token, so it shares the poller's bucket. Each compose costs `P` requests (one page per board on this install), at most 10 per minute per operator account (the `throttle:compose` row), against the poller's one pass per 5 minutes: 300 per window leaves room for dozens of operators composing at the limiter's ceiling before the poller could see a `429` (which would be `board_poll_failed`, § 9). Two hosts provisioned with one READ token share one bucket. **Re-measure** by re-running the one-`GET` header read whenever `BOARD_IDS`, the board, or the operator count changes |
| request timeout | 20 s connect and read | **Cited** — [§ 12](#12-every-number-and-where-it-comes-from), the same client |
| residual window, [§ 15.3](#153-the-one-verb-and-why-it-is-not-an-instrument-inside-the-measurement) leg 2 — **the answered-write part** | **≤ (P + 1) × the request timeout**, where `P` is the total page count across `BOARD_IDS` | **Derived** — the window opens when the live read's FIRST page answers (a touch landing after a board's page was read is unseen) and closes when the board stamps the write's `updated_at`; between them are the remaining `P − 1` pages and the write, so at most `P + 1` requests, each bounded by the timeout row. `P` is **re-derived from config and the board, never written here as a figure**: `BOARD_IDS` from `config/mezzanine.php`, and per board the `meta.last_page` of [§ 12](#12-every-number-and-where-it-comes-from)'s whole-board request — on this install that request measured one page for board 14, and `BOARD_IDS` lists it alone. **Re-derive whenever `BOARD_IDS` or any board's page count changes.** Accepted on the record at [§ 14](#14-open-questions) item 10 (i) |
| residual window — **the unanswered-write part** | **no bound** | **Stated, not derived** — a write whose answer never arrived (`unverified`) may be accepted by the board at any later moment; nothing in this design bounds it, and [§ 14](#14-open-questions) item 10 (ii) is where that is accepted |
| refusal statuses | `403` / `404` / `409` / `422` / `502` / `503` | **Chosen** — one status per meaning in [§ 15.11](#1511-failure-paths-and-every-sentence-the-panel-renders)'s paragraph, so the client branches on the class and a reverse proxy on the status; `409` is the precondition (`target_moved`) and `422` the shape and the seat's state, which is why a malformed `expected_ref` is `422` and never `409` |

### 15.16 Rejected shapes, priced

- **Posting to `seat_board_task.card_id` directly** — cheaper by one join and wrong by one bound
  ([§ 15.6](#156-target-resolution)).
- **Writing from the projection alone, with no live read** — the first revision's shape; it let a
  compose land on a card the board had already superseded ([§ 15.3](#153-the-one-verb-and-why-it-is-not-an-instrument-inside-the-measurement)
  leg 2) and cost one page read per compose to remove.
- **Letting the live read refresh `seat_board_task`** — it would make the compose path a second
  producer of tier 1 ([§ 1.2](#12-non-goals--stated-so-an-implementer-cannot-widen-scope-in-good-faith)),
  with its own `observed_at` semantics to reconcile against the poller's one-stamp-per-pass rule
  ([§ 7.1](#71-the-input-table)). The read is discarded.
- **A board-side conditional write** (compare-and-set on `updated_at`) — the only thing that closes
  leg 2's window fully; not known to exist in the board's API, so recorded as what would close
  [§ 14](#14-open-questions) item 10 rather than designed against.
- **A `compose` member on D2 § 8.2.3's `detail`, operator-only, carrying the resolved target** — the
  console-link precedent, and a wire change for a value the client can compute from members the seat
  object already carries. It would also let the server resolve once and the client post against a
  target that a delta has since moved; the post re-resolves anyway. Rejected: no wire change.
- **A `compose_failed` counter** ([§ 15.8](#158-the-audit-trail--the-comment-itself-and-no-new-table)).
- **A read-back of the card after the post** — `kbcard` does one, against pitfalls of its own shell
  parsing (an unsuffixed path that `404`s with a well-formed body). Here success is decided on the
  status class and an integer `data.id`, which closes that trap without a second request; the receipt
  is the board's own echo.
- **Retrying a failed post** — a duplicate comment is the worse outcome, and the operator is present.
- **Hiding the block when unconfigured** ([§ 15.5](#155-where-the-credential-lives-and-how-a-missing-one-fails-loud)).

# D3 — the floor UI specification

**Fleet state → a room you can read at a glance.** What the browser draws, what drives every pixel of
it, and what it must never draw.

> **Status: Draft — pending design review.** Owner: aimla-pm. Gate:
> [`docs/PLAN.md § 2`](../PLAN.md#2-design-first-gates--the-order-is-the-plan) (P0 design, board 14).
> Written to the **standalone-implementer standard (D-14)**: an agent holding only this file and
> [D2](FLEET-STATE.md) must be able to build the lobby, the floor and the drill-down. Nothing here is
> built yet — there is no application in this repo. Every number carries its derivation; every failure
> path names its observable; every animation names the wire fact that drives it. Decisions a reviewer
> is most likely to contest are collected in [§ 13](#13-decisions-taken-revisable-at-review), and each
> is **decided**, not parked. The obligations [D2](FLEET-STATE.md) and [D1](EVENT-SCHEMA.md) place on
> this document are enumerated in
> [Appendix A](#appendix-a--every-obligation-addressed-to-this-document), one row per obligation, each
> pointing at the section that discharges it.

---

## 0. Overview

1. **D2 is upstream and is not restated here.** [D2](FLEET-STATE.md) owns the state model, the store
   and both read surfaces; [D1](EVENT-SCHEMA.md) owns the wire. This document owns everything after a
   JSON object reaches a browser. Where a fact belongs to D2 it is **cited by section**, never copied.
2. **The client derives no state.** Every rendered fact is a field of a D2 object, named in
   [§ 5](#5-the-render-map--every-rendered-fact-and-its-d2-field). The seven things the client computes
   for itself are enumerated as a **closed list** in [§ 2.1](#21-the-seven-client-computed-values-closed),
   and every one of them is presentation — a clock offset, an age, a desk position, an animation
   selection, a per-floor count over the objects it already holds, a sort order, and the client's own
   narration of what it did, saw, and reads on its own clock ([§ 5.5](#55-the-clients-own-narration)),
   which is labelled as the client's own everywhere it renders. A client-side
   state machine over activity facts is forbidden, and so is re-deriving `render_state`
   ([D2 § 4.1](FLEET-STATE.md#41-two-axes-and-a-badge-set): "a precedence re-implemented in JavaScript
   is a second copy free to drift").
3. **The honesty principle binds every animation** (operator, via the proposal; restated at
   `docs/PLAN.md § 2`): *driven by a real event, or absent.*
   [§ 6.2](#62-the-animation-table--the-closed-set) is the closed table of every animation in the
   product, each with the wire field or message that drives it and the exact edge that starts it. **An
   animation with no row in that table is a defect, not a flourish**, and
   [AT-D3-1](#at-d3-1-no-animation-without-its-event) is the mechanised form of that sentence.
4. **A state-held loop may say WHICH, never HOW MUCH.** The typing loop runs while
   `render_state == "working"` and at a fixed rate that encodes nothing
   ([§ 6.1](#61-the-rule-and-what-a-loop-is-allowed-to-mean)). A loop whose speed tracked a rate would
   be inventing a quantity the wire never sent — the same defect as an animation with no event, one
   level down.
5. **A degraded seat is visibly degraded, never frozen-but-healthy-looking.** The desk renders
   `render_state`, which D2 computes once on the server and which collapses transport over activity
   ([D2 § 4.2](FLEET-STATE.md#42-render-precedence)); a seat that is `catching_up`, `stale`, `offline`
   or badged `fold_lag` renders its activity state **only** under a currency label
   ([D2 § 3.4](FLEET-STATE.md#34-what-this-rule-forbids-concretely),
   [§ 7](#7-degradation--how-a-degraded-seat-is-unmistakable)).
6. **An empty office is never an error render.** Every failure path
   ([§ 9](#9-failure-paths-and-their-observables)) has a stated, non-empty observable: a `503` renders
   the store's unavailability, not a quiet floor; a dead feed renders a feed-down indicator and keeps
   the ages growing; an expired session renders a sign-in prompt over a floor labelled with the moment
   it stopped being live. `docs/KANBAN.md § G-1`'s clean zero has a rendering twin, and this is the
   document that refuses it.
7. **Identity is the seat's, not the screen's.** A desk is keyed by `(install_id, seat_id)` — D1's
   config-file-resident pair ([D1 § 3.1](EVENT-SCHEMA.md#31-the-seat-config-file)), which survives
   restarts, `/clear`, reboots and harness upgrades — and its position is a **pure function of that
   key**, so two browsers and two reloads agree without a server field and without local storage
   ([§ 3](#3-identity-seat--desk-install--floor)).
8. **The subagent cap stays at 8, and the arithmetic is published**
   ([§ 8](#8-interns--subagent-rendering-and-the-cap)). That closes
   [D2 § 14](FLEET-STATE.md#14-open-questions-for-the-review-loop) item 9, which asked D3 to decide it.
9. **Assets carry their provenance as a gate, not as a footnote.** Every asset file declares **where
   it came from** — `first-party` or `licensed`, a closed set — under a closed licence allowlist, and
   the upstream's commercial tilesets are never vendored (D-07).
   [§ 10](#10-art-and-assets--provenance-as-a-gate) states the manifest, the allowlist and the two
   checks that fail the build; [§ 10.4](#104-the-art-direction-as-a-specification) states the look
   those assets serve, ratified by the operator on 2026-08-26/27.
10. **Where the D2 contract cannot answer a UI need, nothing is invented.** Where the need can be met
    by fetching an object D2 already serves, this document fetches
    ([§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold),
    [§ 4.3](#43-the-desk-drill-down-panel)); where it cannot, it is filed as a D2 amendment need in
    [§ 14](#14-open-questions-for-the-review-loop), with what is rendered in the meantime. This mirrors D2's own discipline toward D1
    ([D2 § 1.3](FLEET-STATE.md#13-the-boundary-stated-as-a-rule)): a downstream document that silently
    corrects its upstream is how two documents start disagreeing about which one is the contract.

```
   Mezzanine host                      │  browser (session + MFA)
   ─────────────────                   │  ────────────────────────
   GET /api/fleet/snapshot ────────────┼─▶ seat map  ──▶ lobby  (building summary)
   Reverb private-fleet.<install> ─────┼─▶ deltas    ──▶ floor  (one install, S desk slots)
   GET /api/fleet/seats/<i>/<s> ───────┼─▶ detail    ──▶ drill-down panel
   GET …/timeline?limit=&before= ──────┼─▶ events    ──▶ recent-activity timeline
   GET /api/fleet/health ──────────────┼─▶ fleet{}   ──▶ banners
                                       │
                                       │  the client holds: seat objects, a clock offset,
                                       │  and nothing else it did not receive
```

---

## 1. Scope, non-goals, and the D2 boundary

### 1.1 What this document owns

| Owned here | Section |
|---|---|
| The client protocol as the browser runs it, and the closed list of client-computed values | [§ 2](#2-the-client-end-to-end) |
| Identity: seat → desk, install → floor, desk position, collisions, first appearance, retirement | [§ 3](#3-identity-seat--desk-install--floor) |
| The three screens — lobby, floor, drill-down — and what each fetches | [§ 4](#4-the-screens) |
| The render map: every rendered fact, its D2 field and its example value; **the null render for every one of [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)'s 36 nullable members, in one table** ([§ 5.6](#56-the-null-render-for-every-nullable-member)); and the one surface whose facts are the client's own ([§ 5.5](#55-the-clients-own-narration)) | [§ 5](#5-the-render-map--every-rendered-fact-and-its-d2-field) |
| The honesty principle and the closed animation table | [§ 6](#6-the-honesty-principle--every-animation-and-its-driving-event) |
| Degraded rendering, per state and per badge | [§ 7](#7-degradation--how-a-degraded-seat-is-unmistakable) |
| Subagent rendering and the array cap | [§ 8](#8-interns--subagent-rendering-and-the-cap) |
| Failure paths and their user-visible observables | [§ 9](#9-failure-paths-and-their-observables) |
| Asset provenance, the licence allowlist and the attribution obligation | [§ 10](#10-art-and-assets--provenance-as-a-gate) |

### 1.2 Non-goals — stated so an implementer cannot widen scope in good faith

| Not in this document | Why, and who owns it |
|---|---|
| **Any server-side change** — a new field, a new endpoint, a new message type, a new query parameter | [D2](FLEET-STATE.md). This document consumes the read surfaces exactly as D2 publishes them. Where a UI need is unmet, [§ 14](#14-open-questions-for-the-review-loop) files it as a D2 amendment need and states what is rendered in the meantime. **A UI that needed one new field to be honest would be a UI that is dishonest today**, so each of those items also says what the floor does without it |
| **The state model** — what `working` means, when a seat is `stale`, which rule mints `idle` | [D2 § 4](FLEET-STATE.md#4-the-seat-state-model). This document renders `render_state`; it never computes one |
| **The wire schema and the reporter** | [D1](EVENT-SCHEMA.md), cards #7335–#7337 |
| **The choice of rendering library, bundler, or component framework** | The implementer's, deliberately. This document states the *capabilities* required ([§ 4.5](#45-the-viewport-rule-and-the-capability-floor)) and constrains nothing else: a spec that pinned a framework would be a spec that expires when the framework does, and none of the properties here depend on one. What it does constrain is the **asset pipeline**, because that is where a licence violation enters ([§ 10](#10-art-and-assets--provenance-as-a-gate)) |
| **MFA, login, session lifetime** | Card #7334 (Fortify + a stock TOTP package, D-04). This document states what the floor does when a session **expires** ([§ 9](#9-failure-paths-and-their-observables)); it does not specify the second factor |
| **Prod and sandbox provisioning, deploy** | D-13 and D-15 (`docs/PLAN.md § 5`), owned by the Mezzanine build agent |
| **Operator ACLs — who may see which install** | [D2 § 14](FLEET-STATE.md#14-open-questions-for-the-review-loop) item 7 owns it, and it is an operator question. Today any MFA-authenticated user sees every install ([D2 § 9](FLEET-STATE.md#9-read-side-authentication)), and this document renders exactly what the snapshot returns |
| **The producers of task-title tiers 1 and 2** — a GitHub webhook receiver, a board poller | Designed in no document in this repo ([D2 § 4.9](FLEET-STATE.md#49-the-task-title-merge-and-what-is-not-specified-here)). [§ 5.1](#51-the-desk) renders whichever tier `task.source` says answered, and [§ 14](#14-open-questions-for-the-review-loop) item 4 carries the question forward |
| **Sound** | There is no audio in this design. A sound is an animation by another sense and would need its own rows in [§ 6.2](#62-the-animation-table--the-closed-set) with the same totality rule; adding one without them would be adding an un-driven cue. If audio is wanted it is a review decision, not an implementer's |
| **Historical views, charts, trends** | [D2 § 1.2](FLEET-STATE.md#12-non-goals--stated-so-an-implementer-cannot-widen-scope-in-good-faith) rules out the warehouse; the product answers *what is happening now*. The drill-down's timeline is a bounded window over retained events, not a history |
| **Multi-tenant theming, per-user preferences, layout customisation** | Nobody has asked. The one preference honoured is the platform's own `prefers-reduced-motion` ([§ 6.4](#64-reduced-motion-is-a-first-class-rendering-not-a-degradation)), because a state carried only by motion is a state some users cannot read |

### 1.3 The boundary, stated as a rule

**A fact has one home. If [D2](FLEET-STATE.md) states it, this document cites it by section number and
does not paraphrase it.** Three corollaries bind an implementer:

1. **No rendered fact without a named field.** Every row of
   [§ 5](#5-the-render-map--every-rendered-fact-and-its-d2-field) names the D2 field it reads. A number
   or a label with no field is a number the client invented, and
   `tools/design/verify-floor.py` reds when a source column names a field D2 does not declare.
2. **No server behaviour invented to meet a UI need.** Where the contract cannot answer, the answer is
   an entry in [§ 14](#14-open-questions-for-the-review-loop) plus a stated interim rendering — never a
   guessed endpoint, a guessed field or a hopeful default. This document **never edits D2**: an
   amendment is a request, not an edit.
3. **A quotation is verbatim in its words and this document's in its emphasis.** Every span inside
   quotation marks is [D1](EVENT-SCHEMA.md)'s or [D2](FLEET-STATE.md)'s own wording, with an ellipsis
   marking anything elided; **bold and italic inside a quotation are this document's**, added to point
   at the clause that binds the render layer, and are never a claim that the source emphasised it. The
   convention is stated here once because the alternative is an *emphasis added* note on each of the
   dozens of quotations in this file, and a note repeated dozens of times is a note that will be
   omitted from the next one. Where a span is accurate in substance but is **not** a verbatim quote it
   says so at the site — [Appendix A](#appendix-a--every-obligation-addressed-to-this-document) U9 is
   the one such case.

---

## 2. The client, end to end

### 2.1 The seven client-computed values, closed

Everything the client computes for itself, and nothing else. The list is closed so that a reviewer can
check a candidate computation against it rather than against a feeling. **Rows 1–6 are computations
about a seat; row 7 is the client narrating itself**, and it is on the list because a list that
enumerated only the seat-facing six left the whole status strip — the feed verdict, the *live* claim,
the resync counter, the event log — outside the rule that exists to catch exactly that class.

| # | Computed | From | Why it is presentation and not state |
|---|---|---|---|
| 1 | **`clock_offset_ms`** = `server_time − browser_now` | every REST response and every feed message ([D2 § 3.3](FLEET-STATE.md#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by)) | D2 **requires** it: "the browser's own clock is never used for an age either… it is the layer nobody controls" |
| 2 | **Durations** — *nothing done for 4m 12s*, *no data for 11m*, *running for 2m 05s*: **three** of [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s four, and no fourth on this list. Its fourth row, *this state is 117 s behind*, is deliberately **not** here: `derivation.fold_lag_ms` (**`named-not-rendered`** — this row names the member and draws nothing from it; [§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy) owns its render) is a duration **D2 computes at read time and sends**, and formatting a delivered number into a string is not computing one. Nor is a currency label's parenthetical, which carries a labelled seat-clock timestamp rather than a duration, nor the fleet banner's `fleet.max_fold_lag_ms` | a D2 timestamp subtracted from the corrected clock | The timestamps are the wire's; the subtraction is a rendering of them, and D2 states which basis each age takes ([D2 § 3.3](FLEET-STATE.md#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by)) |
| 3 | **Desk slot** | `(install_id, seat_id)` and the map's slot count ([§ 3.2](#32-the-desk-slot-function)) | A layout function of identity. It reads no state field, so it cannot change when a seat's state does |
| 4 | **Animation selection** and its reduced-motion form | `render_state`, the delta's `changed[]`, and [§ 6.2](#62-the-animation-table--the-closed-set) | A pure function of a delivered field and a published table |
| 5 | **Per-floor counts** | the seat objects the client already holds for that install | The wire has no per-install count ([D2 § 8.2.4](FLEET-STATE.md#824-the-fleet-health-object)'s counts are fleet-wide), so this is the only place it can come from. It is labelled as a count of the seats the client holds, and [§ 4.1](#41-the-lobby--the-building-summary) requires the client to **render the disagreement** rather than pick a winner when the floors do not sum to `fleet.seats_total` |
| 6 | **Sort orders** | floors by `install_id` ascending; desks by slot; timeline as served | Deterministic ordering of received objects |
| 7 | **Client self-narration** — the feed-liveness verdict, the *live* claim, counters over the client's own events (*resyncs: N*), the client's event log, the *membership as of* stamp, the overflow determination, [§ 9](#9-failure-paths-and-their-observables) F9's once-per-distinct-value dedup, and the **wall clock's reading and the sky phase** — the viewer's own clock, sampled when a `feed.heartbeat` arrives and at no other moment ([§ 6.2](#62-the-animation-table--the-closed-set) A17) | the client's own connection state, its own request outcomes, the seat set it holds, and the **viewer's own clock** ([§ 5.5](#55-the-clients-own-narration)) | Every one is a fact about **the client**, not about a seat. It is labelled as the client's own wherever it renders, it is never drawn as a seat's field or mixed into a fleet number the wire carries, and it never becomes a desk's pose, currency label or badge. [§ 5.5](#55-the-clients-own-narration) is its render map and its honesty rule |

**Forbidden, named because each is a computation an implementer would otherwise reach for:** deriving
`render_state` from the two axes; inferring `idle`, `busy` or "gone" from the absence of deltas;
smoothing or extrapolating `context.used_pct` beyond the one stated tween
([§ 6.2](#62-the-animation-table--the-closed-set) row A12); holding a seat's last-known-good object
across a resync and merging it with the new one; counting `subagents[]` to display an intern count
when `subagents_open` carries the truth ([§ 8](#8-interns--subagent-rendering-and-the-cap)); and
computing a fleet-wide count by adding up desks when `fleet.seats_total` is on the wire.

### 2.2 Connect, snapshot, deltas

The protocol is [D2 § 8.4](FLEET-STATE.md#84-snapshot-then-deltas)'s, executed by this client, with the
subscription set and the render points made explicit:

```
 1. authenticate (session + MFA) and open the socket
 2. ADMIT(I) step a -- SUBSCRIBE -- for every install I the client already knows of;
    on a cold start that set is EMPTY, which is why step 6 exists  (see § 2.3)
 3. BUFFER every seat.delta from this moment
 4. GET /api/fleet/snapshot            -> installs[], each seat with its own state_version
 5. render the world as delivered      -> NO animation fires on this render (§ 6.5)
 6. ADMIT every install seen in step 4 and not yet subscribed -- in full, a/b/c below.
    Its channel was closed for the whole of steps 3-5, so a delta emitted for it in that
    window was never RECEIVED and is in no buffer to drain  (see below, § 14 item 14)
 7. DRAIN the buffer: discard any delta whose state_version <= that seat's snapshot version,
    apply the rest in ascending order
 8. steady state: apply deltas as they arrive, iff delta.state_version == local + 1
      greater  -> resync THAT seat: GET /api/fleet/seats/<i>/<s>?resync_from=<last applied>
      <=       -> discard (duplicate or straggler)
      unknown seat -> the § 2.3 insert path, never a partial object
 9. no message of any kind for 45 s   -> feed presumed dead: indicator, poll at 10 s, reconnect
10. reconnect                          -> re-run from step 1
```

**ADMIT — the one ordering that closes the missed-delta window, for an install entering the rendered
set at ANY time.** It is stated once, here, and cited from every place an install enters that set
([§ 2.2](#22-connect-snapshot-deltas) step 6, [§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold)
row 3, [§ 4.1](#41-the-lobby--the-building-summary), [§ 4.4](#44-routes-and-what-each-one-fetches)) —
because the same three lines owed at four call-sites is three chances to write two of them and a
fourth call-site minted without any:

```
ADMIT(install_id):
  a. SUBSCRIBE to private-fleet.<install_id>, and BUFFER every seat.delta on it
     from this moment
  b. FETCH  GET /api/fleet/snapshot  -- D2 § 8.2 publishes exactly ONE snapshot
     endpoint, fleet-wide, with NO per-install parameter, and § 1.2 forbids this
     document from minting one; the client reads that install's rows out of the
     whole-fleet response and leaves every other install's held state alone.
     THIS IS NOT A FULL SNAPSHOT APPLY, and the difference is load-bearing rather
     than a nicety: a full apply is a complete POPULATION statement and is the one
     thing that may remove a desk (§ 2.3) or re-render everything (§ 2.5). ADMIT (b)
     is a statement about ONE install, so it removes nothing, re-renders that
     install's desks alone, and does not advance the lobby's *membership as of*
     stamp (§ 5.5). Read the other way, admitting one install would delete every
     desk of every install whose rows the client chose not to read.
  c. DRAIN  that install's buffered deltas: discard any whose state_version is <=
     the version its seat came back with at (b), apply the rest in ascending order
```

**Why the order and not the other one.** (a) strictly precedes (b), so a `seat.delta` emitted during
(b)'s round trip is *received and buffered* rather than lost — the loss the reverse order produces is
unrecoverable, because a watermark filters a buffer and cannot recover a message nobody was subscribed
for. (c)'s per-seat watermark is what makes (a)'s buffer safe to apply: a change already inside (b)'s
response is discarded rather than applied twice. That is D2's own reasoning
([D2 § 8.4](FLEET-STATE.md#84-snapshot-then-deltas)), lifted out of the connect sequence so that it
binds an install admitted at second 0 and an install admitted at minute 40 identically.

**ADMIT's fetch is not a poll and is not budgeted like one.** It is one fetch per install *admitted*,
which is one fetch per install *ever*, on a population that changes when an operator provisions an
install. In particular it is **not** subject to [§ 4.1](#41-the-lobby--the-building-summary)'s
one-fetch-per-distinct-`(N, M)` rule, which governs the *discrepancy* fetch that discovers an install;
reading the two as one budget is what would leave a discovered install subscribed-to and never
drained.

**On a cold start step 2 admits nothing, and that is the window
[D2 § 8.4](FLEET-STATE.md#84-snapshot-then-deltas) does not close.** Step 2 runs ADMIT (a) over the
installs the client already knows of; on a cold start that set is **empty**, so steps 3–5 run with no
channel open at all and a `seat.delta` emitted in that round trip is not buffered-and-discarded — it is
**never received**. The watermark cannot help, because it filters a buffer rather than recovering a
message nobody was subscribed for. The ordinary convergence still applies — that seat's next delta
arrives at `local + 2`, the gap is detected, and [§ 9](#9-failure-paths-and-their-observables) F2
resyncs it — but a seat whose only change fell inside the window and which then goes quiet (a
`seat.retired`, a `/clear` landing at `unknown`, a seat that just went `offline`) holds its pre-change
state until the next full snapshot. That is this section's own stated failure, arriving through the
door it claimed to have shut: *the desk that changed in that window stays wrong until something else
changes it — which on a quiet desk is never.*

**Step 6 closes it, because step 6 is ADMIT in full** — (b) and (c) as much as (a) — at the cost of one
extra snapshot on a cold start and none afterwards. Step 4's snapshot cannot serve as step 6's,
because it was answered before those channels existed; ADMIT (b) is a *second* fetch, and (c) drains
each newly-subscribed install's buffer against the versions that second fetch returned. Step 6 exists
at all because the *first* snapshot is also how the client learns which installs exist — see
[§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold) — and
[§ 14](#14-open-questions-for-the-review-loop) item 14 asks D2 for the installs list or bootstrap
channel that would make step 2 complete on a cold start and reduce step 6 to a no-op.

**The client never applies a delta to a seat whose current version it does not know**, which is the
whole content of step 8's third branch and is what keeps a shallow-merge patch
([D2 § 8.3.1](FLEET-STATE.md#831-worked-delta): "a nested object is replaced whole, never
deep-merged") from ever being applied to a partial object.

### 2.3 Membership: a seat or an install the client does not hold

Two membership events have no message on D2's feed table
([D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed)) — a seat entering the rendered population, and
an install entering it. Both are reachable: a seat row exists from token-issue time
([D2 § 6.4](FLEET-STATE.md#64-ddl)) and a newly-provisioned seat's first delta can name a `seat_id` a
connected client has never seen. **This document adds no message. It fetches.**

| Case | What the client does | Why this and not the alternative |
|---|---|---|
| A `seat.delta` names a seat the client does not hold | Buffer deltas for that `(install_id, seat_id)`; `GET /api/fleet/seats/{install}/{seat}` — the endpoint D2 already publishes, which returns the **whole** seat object plus `detail`; insert it; drain the buffer against the fetched `state_version` exactly as [§ 2.2](#22-connect-snapshot-deltas) step 7 does | Applying the patch alone would insert a seat object with holes — no `render_state` on a patch that changed only `context`, no `seat_id` on one that did not. A desk drawn from a partial object is a desk whose missing fields render as *nothing is happening*, which is the one reading this product exists to remove. The fetch costs one request per new seat, ever |
| A `seat.delta` is emitted on a channel the client is not subscribed to | **The client never receives it — which is a loss, not a non-event.** Nothing is buffered and nothing is discarded: the change is simply absent from this client's world until a full object for that seat arrives. It is reachable in exactly one window — the round trip between an install entering the rendered set and ADMIT (a) subscribing for it, which on a cold start is [§ 2.2](#22-connect-snapshot-deltas) steps 3–5 — and **ADMIT (b)+(c) is what closes it**, on a cold start and mid-session alike. It is also why an **install** entering the population is invisible until a snapshot | Recording this row as *cannot happen* was the error: what cannot happen is the **receive**; the state change it would have carried very much can, and calling the loss a non-event is how it stayed unclosed |
| An install exists that the client has no channel for | It appears at the **next full snapshot**, and the client fetches one **as soon as the counts disagree**: `fleet.seats_total` rides every `feed.heartbeat` ([D2 § 8.2.4](FLEET-STATE.md#824-the-fleet-health-object)), so a newly-provisioned install raises it within 15 s on any channel the client already holds, [§ 4.1](#41-the-lobby--the-building-summary)'s discrepancy check renders the disagreement, and the one snapshot fetch it triggers **discovers** the install. **Discovery is not admission, and the difference is the whole of this row:** an install the client has fetched once and never subscribed to is a floor of frozen desks reading `live`. So the client then runs **[§ 2.2](#22-connect-snapshot-deltas)'s `ADMIT`** for it — subscribe, re-fetch, drain — exactly as step 6 does for an install discovered at connect time, and ADMIT (b)'s fetch is outside the discrepancy trigger's per-`(N, M)` budget. Until (c) completes, the install's desks render from the discovery snapshot and the lobby's *membership as of* stamp is what dates them. A reconnect and the lobby's refresh control are the other two paths, and both reach ADMIT the same way. The lobby carries a **`membership as of HH:MM:SS`** readout beside the fleet counts either way, so the age of the *membership* picture is visible separately from the age of the *state* picture | Polling the snapshot on a timer would invent a cadence D2 does not state and would fetch ~91 KB ([D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object) at 50 seats) on a schedule to answer a question that changes when an operator provisions an install. **The discrepancy fetch is not that poll**: it is one fetch per *distinct* disagreement, driven by a number the wire already sends every 15 s, and it fires only when the client can already prove it is wrong — which is the same evidence it renders. [§ 14](#14-open-questions-for-the-review-loop) item 2 still asks D2 for the membership message that would close it properly |
| A seat the client holds is **absent** from a fresh snapshot | Remove it — but **only on a *full* snapshot apply, never on a delta, a poll, or `ADMIT` (b)'s scoped read of one install's rows ([§ 2.2](#22-connect-snapshot-deltas))**, and write one line into the client's event log ([§ 5.5](#55-the-clients-own-narration)) naming the seat and the reason (*retired more than 14 days ago*, [D2 § 4.10](FLEET-STATE.md#410-retirement-is-a-rendered-state)) | A removal driven by a delta would be a removal driven by an absence, which is the inference this whole design refuses. The only honest removal is a fresh, complete population telling the client the seat is no longer in it |

### 2.4 The clock, and every age on the page

`clock_offset_ms = server_time − browser_now`, refreshed on **every** message and response that carries
`server_time` — which is all of them ([D2 § 8.2](FLEET-STATE.md#82-rest),
[D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed)) — and applied to every age the page renders.
The `feed.heartbeat` at 15 s is what keeps it fresh on an otherwise-silent fleet.

- Ages re-render **every 1 s**, which is the unit the smallest age is rendered in: slower would show a
  second that has already passed, faster would repaint for nothing.
- A **duration** is rendered from the field D2 assigns to it and no other, **and each has exactly one
  rendered form, stated here so that no second surface mints a second string for one fact**. **Three
  of the four the client computes** — the corrected clock minus a timestamp the wire carries — and
  they are [§ 2.1](#21-the-seven-client-computed-values-closed) row 2's closed list. **The fourth it
  does not:** `derivation.fold_lag_ms` is a duration D2 computes at read time and sends, so this
  document only formats it, and it is on this table because the table's job is to fix **one rendered
  form per fact**, which binds a delivered duration exactly as it binds a computed one:

  | Duration | Field | The string, verbatim | Where it may appear |
  |---|---|---|---|
  | **quiet age** | `activity.last_received_at` | ***nothing done for 4m 12s*** | desk and drill-down; version-bearing, so it ticks. On an `idle` desk it appears inside that state's label line as *finished — nothing done for 4m 12s* ([§ 7.1](#71-the-render-per-state)) — the same readout under the state's own sentence, never a second wording |
  | **receipt age** | `delivery.last_receipt_at` | ***no data for 11m*** | the **desk**, under the **`dark-only`** marker below, which owns which desks may draw it and why it may tick; and the drill-down's transport block on **any** seat, under that block's *as of* stamp, **never** ticked (**`fetch-fresh`**). The form's exemplar is 11m rather than the 4m 12s the rows above use, because 4m 12s is inside no state the first surface named here can be in ([D2 § 4.5](FLEET-STATE.md#45-link-states): `stale` begins at 300 s). **`named-not-rendered`** — this row fixes the string and draws no value from the member; the two markers it names are pointers to the rule below, and it is the render-map rows that draw it that carry them |
  | **action elapsed** | `action.started_received_at` | ***running for 2m 05s*** | desk and drill-down, wherever the open action is drawn; version-bearing, so it ticks. **Both ends are the server clock**, which is what makes it the one honest duration over an action ([§ 5.1](#51-the-desk)) — `action.started_at` is the seat's own claim and is rendered as a labelled timestamp beside it, never subtracted from anything |
  | **derivation lag** | `derivation.fold_lag_ms` | ***this state is 117 s behind*** | **`fetch-fresh`**, so never ticked on any surface — and *which* surfaces is [§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy)'s to say, not this table's. This row fixes the string; that section owns where it appears and under what condition. **`named-not-rendered`** — this row draws no value from the member either, for the same reason: the marker it names is the one the render-map row that draws it carries |

  **Two rendered figures look like a fifth row and are neither.** *(a)* A **currency label's
  parenthetical** — *was: working (last event 12:47, seat clock)* — carries a labelled seat-clock
  **timestamp**, not a duration ([§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim),
  [§ 7.6](#76-the-three-remaining-member-sets-published-so-membership-is-testable)); an earlier
  revision wrote *(3h 12m ago)* there, which subtracted a seat clock from the server's and is exactly
  what the rule below forbids. *(b)* The **fleet banner**'s *these desks show what seats were doing N
  minutes ago* renders `fleet.max_fold_lag_ms` — a **fleet-scoped** figure, in words
  [D2 § 2.3](FLEET-STATE.md#23-a-frozen-fold-is-the-dangerous-degradation) fixes, over a different
  field from the per-seat lag above ([§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy)).
  Neither is a second wording of one of the four.

  **One rendered string on this page looks like an age and is not:** a `stale`/`offline` desk's
  ***no data since 14:18***, which is `delivery.no_data_since` rendered as a **timestamp**
  ([§ 5.1](#51-the-desk)). On those two states it is the **same instant** as the receipt age's basis —
  [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object) declares `no_data_since` *"equals
  `last_receipt_at`"* there — delivered twice, once version-bearing and once not, and the desk renders
  both halves of it — [§ 7.1](#71-the-render-per-state)'s `stale` and `offline` rows are where that
pair carries values. The timestamp says *when* and is
  delivered on the transition; the age says *how long* and is `dark-only`. Their differences are the point
  ([D2 § 3.3](FLEET-STATE.md#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by)); collapsing
  them into one "last seen" would destroy exactly the distinction the product is for. **Which surface
  may render which of the three is not a free choice**, because only one of them is refreshed by the
  feed — the rule is below, and it is D2's.
- A **seat-clock** timestamp — `action.started_at`, `context.sampled_at`, `activity.last_event_time`,
  `session.started_at`, `subagents[].started_at` — is rendered **as the seat's own claim**, labelled
  *seat clock*, and **never as a duration of any kind**: not as an age, not as an elapsed time, not
  inside a currency label's parenthetical. Subtracting a seat clock from the corrected server clock
  measures the skew between two machines and calls it work. D2 forbids the age reading directly
  ([D2 § 3.3](FLEET-STATE.md#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by), citing
  [D1 § 10.1](EVENT-SCHEMA.md#101-two-clocks-and-which-is-authoritative-for-what)), and the seat's
  `delivery.clock_skew_ms` is rendered beside it in the drill-down whenever it is non-null.

**Ten fields the feed never re-sends, and the two markers every render of one must carry. This
section owns that rule** — the two markers, the third token, which tables the rule runs over and what
each marker permits — **and every other site in this document cites it rather than restating it.**
[D2 § 6.5](FLEET-STATE.md#65-the-fold) states the version-bearing field set as a **subtraction**: the
[§ 8.2.1](FLEET-STATE.md#821-the-seat-state-object) object *less ten bookkeeping members* —
`delivery.last_receipt_at`, `delivery.last_heartbeat_at`, `delivery.last_seq`,
`delivery.clock_skew_ms`, `delivery.spool_lag_events`, `delivery.oldest_unsent_age_s`,
`reporter.uptime_s`, `derivation.computed_at`, `derivation.cursor_event_id` and
`derivation.fold_lag_ms`. **D2 states the two consequences of that split itself, and this document
carries them rather than paraphrasing them** ([D2 § 6.5](FLEET-STATE.md#65-the-fold)): the ten *"**ride
the object** on every snapshot and every detail response and are simply never a **reason** to emit"*;
and *"when a delta is emitted for something else, its `patch` carries the changed members of the
version-bearing set — **except that [§ 8.3.1]'s shallow merge replaces a nested object whole**, so a
patch that touches `delivery.no_data_since` necessarily re-sends the rest of `delivery` with it, which
refreshes the bookkeeping members for free"*.

So a client's copy of the ten is **not** unconditionally frozen, and saying it was — as an earlier
revision of this paragraph did — contradicted the frozen section this rule rests on. What is true is
narrower and is what the markers below encode: **no delta ever carries one of the ten for its own
sake**, so no rendered value among them may be *ticked* — **except the one the `dark-only` marker
names**, whose basis is frozen at the server as well as at the client, which is what makes an age
ticked from it exact rather than merely old; a value among them moves only when a whole
object arrives that carries it — a snapshot, a detail fetch, a resync, or (for a nested object alone)
a patch that touched a version-bearing sibling under the same key. D2 states the consequence as a
constraint on this layer, in that same section: *"Every quantity this document says is rendered from
one of the ten is rendered from a value that cannot be moving at the moment it is read."*

**The stamp rule, stated once and cited rather than repeated** (by
[§ 4.3](#43-the-desk-drill-down-panel) and [§ 5.2](#52-the-drill-down), the two surfaces that render a
stamped **block**, and by [§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy), the one
surface that stamps a single **line** — the desk's lag line, which is the only `fetch-fresh` value the
**desk** renders and therefore the only one that cannot borrow a block's stamp):

> **A block's *as of HH:MM:SS* stamp is the `server_time` of whatever delivered the values the block is
> currently showing** — a fetch, a snapshot apply, a resync, or a delta whose shallow merge re-sent
> that nested object whole. The stamp advances **with** the values and never independently of them,
> and the block re-renders when it advances.

That is the rule the alternative readings both get wrong. If the stamp did **not** advance on a
whole-object patch, the panel would render a value that just moved under a stamp naming an older fetch
— a stamped lie on the one block whose whole job is to date its numbers. If the client instead
*discarded* bookkeeping members arriving in a patch, it would be throwing away the freshest copy it
will ever be sent and holding an older one under the same stamp. The stamp follows the bytes.
`reporter.uptime_s` is the sibling that makes this concrete: `reporter.version`, `.platform` and
`.selftest_failed` are version-bearing, so a patch that moves any of them re-sends `uptime_s` with them
under the shallow merge — and the reporter block's stamp advances to that delta's `server_time`, which
is exactly what makes the re-sent `uptime_s` honest to render.

Two markers carry that rule. **Every row of the render map whose source is one of the ten carries one
of them** — [§ 5.1](#51-the-desk), [§ 5.2](#52-the-drill-down),
[§ 5.3](#53-the-fleet-on-both-screens), [§ 5.5](#55-the-clients-own-narration) and
[§ 5.6](#56-the-null-render-for-every-nullable-member), plus
[§ 4.3](#43-the-desk-drill-down-panel)'s panel table,
[§ 6.2](#62-the-animation-table--the-closed-set)'s driver column and
[§ 7.1](#71-the-render-per-state)'s per-state table — and `tools/design/verify-floor.py` reds when one
does not, **against the member the row actually sources rather than against the presence of a token**.
**That list of tables is itself set-differenced against the gate's own column map, both directions**,
because it is this sentence — the rule's statement of its own scope — that has gone false twice: once
naming five tables while § 5.6 sat outside the gate, once naming seven while § 7.1 rendered the
receipt age on the desk.
**It derives that population from this document's own headings rather than from a list of its own**:
every table under a [§ 5](#5-the-render-map--every-rendered-fact-and-its-d2-field) heading is in the
gate's population by structure, and one the gate has no source column for **reds** instead of being
skipped. An earlier revision of this sentence was false in both halves at once — § 5.6 had just been
added, six of its rows sourced one of the ten with no marker, and the gate's table list did not
contain it, so nothing could say so. § 7.1 is the same defect found a second time and one table
further out: its `stale` and `offline` rows render the receipt age on the **desk**, and until the gate
was given a column for them the only thing standing between those two rows and any marker at all was
that the string `dark-only` appeared somewhere in the line. **`dark-only` is a permission on the desk
specifically**, so the two tables that render on a desk — § 5.1 and § 7.1 — must carry `dark-only` for
that member and may not substitute the drill-down's `fetch-fresh` for it:

| Marker | What it permits | Why the value it renders is honest |
|---|---|---|
| **`fetch-fresh`** | rendered only from a response that has **just answered** — the snapshot apply, the drill-down's seat-detail fetch ([§ 4.3](#43-the-desk-drill-down-panel)), a resync fetch, or `fleet.health` / `feed.heartbeat` for the fleet object — stamped *as of HH:MM:SS*, and **never ticked as an age** between fetches | these are exactly the surfaces D2 names for the ten: *"served fresh by [§ 8.2.3], the snapshot and [§ 8.2.4] rather than held by a client between deltas"*. The stamp is what keeps the value a reading of a moment rather than a claim about now |
| **`dark-only`** | **This row is the document's one statement of the dark-desk receipt age; every other site points here and none of them restates it.** `delivery.last_receipt_at` renders **on the desk of a `stale` or `offline` seat, on no other desk and in no other state**, as the receipt age in [the duration table above](#24-the-clock-and-every-age-on-the-page)'s one form, **ticking**, beside the *since* timestamp of the same instant. A `live` desk therefore renders **no receipt age at all** — the cost that buys is stated below. [§ 7.1](#71-the-render-per-state)'s `stale` and `offline` rows carry the worked label line for each of the two states, derived from this row, and are the only site in this document that carries values for it | D2's own carve-out: such a seat *"by definition is receiving nothing, so its `last_receipt_at` is frozen"* at the server too, and the **transition** into `stale`/`offline` moves `render_state` and `delivery.no_data_since`, both version-bearing — so the client is told, and the age it then renders is exact rather than merely old |

**A third token, and it is deliberately not a marker.** A table row outside the render map — the
duration table **above** included, which fixes a string and draws no value from any member — may
*name* one of the ten without rendering any quantity from it: a fixture's contents
([§ 11](#11-acceptance-tests)), an upstream derivation rule quoted
([§ 7.6](#76-the-three-remaining-member-sets-published-so-membership-is-testable)), an obligation
restated in [Appendix A](#appendix-a--every-obligation-addressed-to-this-document). Such a row carries
**`named-not-rendered`**, which permits nothing and asserts only that — *this row names the member and
draws no value from it*. `tools/design/verify-floor.py` reds on any table row outside the render map
that names one of the ten and does not carry that token. **A marker written into such a row buys it
nothing**, and that is deliberate: a marker is a claim about a surface, so a row the gate has no
source column for cannot be checked against the member it marks, and admitting it on the strength of
the token was a test of the bytes rather than of the fact. The two rows that legitimately *discuss* a
marker instead of obeying one are exempt **by their role, not by their address** — the marker table
above, recognised by its rows having the marker itself as their key, and
[§ 12](#12-every-number-and-where-it-comes-from)'s guard-class table, recognised by its own header —
so the rule above cannot be outgrown by a table added where the gate was not looking, nor bought past
by a row that writes the word. What is left outside is **prose**, which no
set difference reaches; the tool prints every prose mention of the ten **in full** on every run, never
a capped sample of them.

**What that costs, stated rather than hidden, and it is a cost on the `live` desk alone.** A `stale`
or `offline` desk **does** carry the receipt age, exactly as D2's carve-out permits. A `live` desk
renders **no receipt age** — which is the whole of the cost, because a live seat is the one whose
receipt age an operator would most like to watch. The fact one
would carry — *this pipe is alive* — is carried instead by `link_state`, which is version-bearing and
therefore delivered, and by [§ 5.5](#55-the-clients-own-narration)'s feed status for the client's own
half of it. The two-age divergence [D2 § 3.3](FLEET-STATE.md#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by)
calls the product is rendered in the **drill-down**, and the two halves are not symmetric there: the
quiet age is version-bearing and ticks, the receipt half is `fetch-fresh` and holds the stamp of the
fetch that produced it. At the moment of the fetch both are fresh and their difference is exact;
afterwards each is labelled with the moment it describes, which is the whole difference between
comparing two readings and comparing a live number to a frozen one wearing the same face. The alternative — a receipt age ticked from a held value on a live desk —
renders *no data for 41m* on a seat that is heartbeating perfectly, which is this document's own sin
inverted: a healthy desk drawn dark, on every quiet seat, for as long as the socket stays up.
[§ 14](#14-open-questions-for-the-review-loop) item 12 asks D2 for the delivery or refresh story that
would let a live desk carry the receipt age honestly.

### 2.5 What re-renders, and when

| Trigger | Re-renders | Note |
|---|---|---|
| **full** snapshot applied | everything | no animation ([§ 6.5](#65-a-snapshot-never-animates)) |
| `ADMIT` (b) applied | **that install's desks only** | the scoped read of [§ 2.2](#22-connect-snapshot-deltas)'s `ADMIT`. It is not a population statement, so it removes no desk ([§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold)) and does not advance the *membership as of* stamp ([§ 5.5](#55-the-clients-own-narration)); no animation, for the same reason a snapshot fires none |
| `seat.delta` applied | that desk only, and the drill-down if it is open on that seat | `changed[]` selects the animations ([§ 6.2](#62-the-animation-table--the-closed-set)); a delta that patches a field to the value it already held still counts as a change, which is what `changed[]` is for ([D2 § 8.3.1](FLEET-STATE.md#831-worked-delta)) |
| `fleet.health` / `feed.heartbeat` | the banner row, the fleet counts, the clock offset — and, on the heartbeat alone, the **room render**: the wall clock and the windows' sky | the heartbeat drives both of the table's message-fired rows ([§ 6.2](#62-the-animation-table--the-closed-set) rows A14 and A17), which is why those two stop together when it does. `fleet.health` is not periodic ([D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed)) and moves no clock |
| `seat.retired` | that desk, immediately | D2 publishes it in the same transaction as the delta ([D2 § 4.10](FLEET-STATE.md#410-retirement-is-a-rendered-state)); the client may receive either first and both are idempotent |
| `fleet.reload` | a full-page banner; **delta application stops** | [D2 § 8.1](FLEET-STATE.md#81-two-surfaces-two-compatibility-postures): a client that sees an unknown `feed_version` stops applying deltas and tells the user to reload |
| 1 s tick | every age readout, and nothing else | not a state change; no animation may be driven by it ([§ 6.3](#63-forbidden-forms-named-so-they-cannot-be-written-in-good-faith)). **In particular not the wall clock**, which advances on the heartbeat above and on nothing else — an age is a subtraction from a timestamp the client holds and is honest between messages; a clock hand moved by this tick would be motion with no delivered cause ([§ 6.2](#62-the-animation-table--the-closed-set) A17) |

---

## 3. Identity: seat → desk, install → floor

### 3.1 The keys, and why they are the only ones

| Rendered thing | Key | Source | Stability |
|---|---|---|---|
| **desk** | `(install_id, seat_id)` | [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object), which carries both on every seat object and every delta, bounded at 32 B and 48 B | D1's identity-stability rule: both are **config-file resident** and "survive session restarts, `/clear`, reboots, host renames, and harness upgrades" ([D1 § 3.1](EVENT-SCHEMA.md#31-the-seat-config-file)). They change only when a human edits the file, which is a deliberate re-identification of the desk |
| **floor** | `install_id` | the snapshot's `installs[].install_id`, and the channel name `private-fleet.{install_id}` ([D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed)) | same file, same rule |
| **character identity** (which sprite a seat gets) | `(install_id, seat_id)` | the procedural generator, seeded from the key ([§ 10.2](#102-characters-the-munder-difflin-port)) | a seat looks the same on every reload and on every browser, because the seed is the identity and not a random draw |

**What may never key a desk**, each named because it is a plausible mistake: `session_id` (it changes
on every `/clear` — D1 § 3.2), the harness or model label, the reporter version, the seat's position in
the snapshot array, arrival order, a browser-local id, or anything derived from hostname, cwd or
username (D1 § 3.4 is a 30-day outage caused by exactly that class of key). A desk keyed on any of
them re-identifies itself under the ordinary operation of the seat it draws.

### 3.2 The desk slot function

A floor's map declares its desk slots. The client assigns seats to slots by a **pure function of the
rendered seat set**, so two browsers, two reloads and two server restarts agree without a stored
position and without a server field.

```
  h(seat)  = FNV-1a-32( install_id + "/" + seat_id )      # offset basis 2166136261, prime 16777619,
                                                          # over the UTF-8 bytes; both ids are ASCII
                                                          # by D1 § 3.1's slug patterns
  S        = the number of slots the floor's map declares
  order    = the floor's seats ascending by (h, seat_id)   # total: seat_id is unique within an install
  for seat in order:
      for i in 0 … S-1:
          if slot ((h + i) mod S) is free -> take it, stop
      # no free slot: the overflow rule below
```

**Worked assignment — the shipped `aimla` map, S = 12.** Every value below is re-derived by
`tools/design/verify-floor.py` from the function above, not transcribed:

| Seat | `h` | `h mod 12` | Probes | Slot |
|---|---|---|---|---|
| `aimla/aimla-review` | 185683291 | 7 | 0 | **7** |
| `aimla/aimla-impl-1` | 671323131 | 3 | 0 | **3** |
| `aimla/aimla-impl-2` | 688100750 | 2 | 0 | **2** |
| `aimla/aimla-pm` | 2865560748 | 0 | 0 | **0** |

**Why a hash and not the obvious alternatives.** Sorting seats by `seat_id` into slots 1…N is simpler
and is rejected: inserting `aimla-alpha` would shift **every** later desk by one, so provisioning one
seat rearranges the whole office and every operator's spatial memory of it. Assigning in arrival order
is rejected for the opposite reason — it is not a function of the rendered set at all, so two browsers
disagree and a reload moves the furniture. A server-assigned position would be a new field, which
[§ 1.2](#12-non-goals--stated-so-an-implementer-cannot-widen-scope-in-good-faith) forbids this document
from minting.

**Overflow: a seat is never dropped.** If the floor's seat count exceeds `S`, the seats with no slot
are rendered in an explicitly labelled **overflow row** below the floor — same desk, same render, same
drill-down — and the floor shows a persistent notice reading *floor map is short N desks*. A silent
drop would be a seat that exists and is invisible, which is the same lie as an empty office and is
worse for being local. Map authors should size `S` above the install's planned seat count; the shipped
`aimla` map declares 12 for a 4-seat install ([`docs/PLAN.md § 5`](../PLAN.md#5-deployment): aimla's
four seats first, then a Windows validation seat, then others as they opt in).

### 3.3 Collision, displacement, and why a desk move is itself an event

Two seats of one install can hash to one slot. The chain resolves by the `(h, seat_id)` order above,
which means an **arriving seat can displace an incumbent** — deterministically, and only when it sorts
lower in the chain.

**Worked collision.** On the `aimla` map above, provisioning `aimla-impl-4` (h = 721655988,
h mod 12 = **0**) collides with `aimla-pm` (h = 2865560748, slot 0). 721655988 < 2865560748, so
`aimla-impl-4` takes slot 0 and `aimla-pm` probes to slot **1**. Every other desk is untouched.

This is a real cost and it is paid deliberately: incumbent-stability would require tenure, tenure would
require a `first_seen` the wire does not carry, and inventing one is what
[§ 1.2](#12-non-goals--stated-so-an-implementer-cannot-widen-scope-in-good-faith) forbids. Three things
make the cost bounded and honest:

1. **A displacement has a cause and is rendered as one.** The move is animation row
   [A16](#62-the-animation-table--the-closed-set), driven by the arrival of a seat in the rendered set —
   a real event, not a re-layout. The displaced character walks to the new desk; under reduced motion
   the desk simply appears in its new place on the next render.
2. **It is bounded to the collision chain.** No seat outside the chain moves, and
   [AT-D3-3](#at-d3-3-identity-is-stable-across-a-restart) asserts exactly that against the fixture
   above.
3. **The frequency is stated, not hoped.** The chance that an arriving seat collides is `N/S`; on the
   shipped map (N = 4, S = 12) that is **1 in 3**, and it displaces only when it also sorts lower —
   so a map author who wants displacement rarer raises `S`, and the formula says by how much.

**Two seats claiming one identity is not a case, and no branch is built for it.** A token binds exactly
one `(install_id, seat_id)` ([D1 § 3.1](EVENT-SCHEMA.md#31-the-seat-config-file),
[D1 § 3.3](EVENT-SCHEMA.md#33-authentication-and-the-identity-binding-rule)), so duplicate identity is
caught at token-issue time and cannot reach the wire. A client-side de-duplication branch would be a
branch no input can select — the defect D1 and D2 both name and delete.

### 3.4 A new seat's first appearance

A seat exists on the floor from the moment it is provisioned, because
[D2 § 4.5](FLEET-STATE.md#45-link-states) rule 1 gives a seat that has never reported
`link_state: "offline"`, and [D2 § 4.3](FLEET-STATE.md#43-the-derivation-function) rule 5 gives it
`activity_state: "unknown"` / `no_data_yet`. `render_state` collapses to **`offline`**.

| Moment | What the floor shows | Driven by |
|---|---|---|
| provisioned, never reported | the desk, the nameplate, **no character** — an empty chair and the label *no data yet* | `render_state: "offline"`, `unknown_reason: "no_data_yet"` — and `delivery.last_receipt_at: null` is the upstream **input** D2 § 4.5 rule 1 mints those from, **`named-not-rendered`** here ([§ 2.4](#24-the-clock-and-every-age-on-the-page)) |
| first batch lands | the character **walks in** and sits ([A1](#62-the-animation-table--the-closed-set)) | the delta whose `changed[]` carries `render_state`, leaving `offline` |
| first `tool.start` | the working loop begins ([A3](#62-the-animation-table--the-closed-set)) | `render_state: "working"` |
| the seat was inserted by [§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold)'s fetch | the desk appears **without** an arrival animation, and the client's event log records *seat added to the floor* | an insert is not a state change; the arrival animation is reserved for a seat leaving `offline`, which is a claim the wire actually made |

The distinction in the last row is the honesty principle applied to the one edge where an implementer
would naturally reach for the nicer effect: *a desk appearing because the client had not fetched it yet*
and *a seat coming online* are different facts, and only the second one happened to the seat.

### 3.5 Retirement, and the only removal

Retirement is an operator act ([D2 § 4.10](FLEET-STATE.md#410-retirement-is-a-rendered-state)) and it
is **rendered, not disappeared**:

| Fact | Render |
|---|---|
| `render_state: "retired"` | the desk stays in its slot, cleared: no character, chair pushed in, the nameplate marked **retired** |
| `retired.at` / `.by` / `.reason` | rendered on the desk's label line and in full in the drill-down: *retired 2026-08-20 09:11 by aimla-pm — host decommissioned* |
| `link_state` / `activity_state` underneath | still rendered in the drill-down, labelled *at retirement, and since* — D2 keeps deriving them and `retired` short-circuits only the **render** ([D2 § 4.2](FLEET-STATE.md#42-render-precedence)) |
| the `seat.retired` message | the desk transitions immediately, with the reason and the time — the two its payload carries ([D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed): `install_id`, `seat_id`, `reason`, `at`, and **no `by`**). The **operator** appears when the delta carrying `retired.by` lands, whichever of the two arrives first; both are idempotent ([§ 2.5](#25-what-re-renders-and-when), [AT-D3-16](#at-d3-16-retirement-is-rendered-and-the-removal-is-explained)) |
| 14 days later | the seat leaves the snapshot by D2's read filter, and [§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold)'s last row removes the desk **on a *full* snapshot apply only**, with a log line |

**A desk never vanishes between two refreshes** — the rule
[D2 § 4.5](FLEET-STATE.md#45-link-states) states and
[AT-D2-23](FLEET-STATE.md#at-d2-23-a-retired-seat-is-rendered-not-disappeared) tests at the state layer.
[AT-D3-16](#at-d3-16-retirement-is-rendered-and-the-removal-is-explained) is its rendering half.

---

## 4. The screens

Three screens, one client, one held seat map. The drill-down is a panel over the floor rather than a
route of its own, because closing it must not cost a re-subscribe.

### 4.1 The lobby — the building summary

**Purpose:** which floors exist, whether the building is telling the truth, and where to go.

| Element | Source | Rendered as |
|---|---|---|
| floor list | `installs[].install_id` from the snapshot, ascending | one row per floor, the row being the link to the floor |
| per-floor state summary | the seat objects the client holds for that install ([§ 2.1](#21-the-seven-client-computed-values-closed) row 5) | a count per `render_state` member present, e.g. *2 working · 1 idle · 1 stale*, in [§ 7.1](#71-the-render-per-state)'s fixed member order |
| fleet totals | `fleet.seats_total`, `fleet.seats_live` | *4 seats · 4 live*, read from the wire and **never recounted** |
| the discrepancy check | the two above | when `Σ floors ≠ fleet.seats_total` the lobby renders the disagreement — *the client holds N of M seats — refreshing* when N < M, and *the client holds N seats; the fleet reports M — refreshing* when N > M, which is reachable: a seat retired more than 14 days ago leaves `seats_total` at once while [§ 3.5](#35-retirement-and-the-only-removal) keeps its desk until a snapshot apply. It triggers **one snapshot fetch per distinct (N, M) observation**: a disagreement still standing after that fetch is rendered and **not** re-fetched, so a discrepancy the snapshot cannot resolve costs one request rather than one every 15 s. It never silently picks a winner ([AT-D3-15](#at-d3-15-the-lobby-never-invents-a-count)), and it is how a new install is **discovered** — every install that fetch discovers is then **admitted** by [§ 2.2](#22-connect-snapshot-deltas)'s `ADMIT`, whose own fetch is **not** counted against the per-`(N, M)` budget above, because that budget exists to bound a *disagreement* and ADMIT is bounded by the install set instead ([§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold), [decision 9](#13-decisions-taken-revisable-at-review)) |
| membership age | the time of the last full snapshot | *membership as of 14:23:14* ([§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold)) |
| store / derivation / sweep | `fleet.db`, `fleet.fold`, `fleet.sweep`, `fleet.max_fold_lag_ms`, `fleet.sweep_last_run_at`, `fleet.ingest_last_receipt_at` | three separate indicators, never one aggregate — [D2 § 8.2.4](FLEET-STATE.md#824-the-fleet-health-object): "no aggregate rolls three health facts into one… D3 may compose a banner from these three; the wire keeps them apart" |
| feed status | the client's own connection state ([§ 9](#9-failure-paths-and-their-observables)) | *live* · *polling (feed down)* · *reconnecting* · *reload required*, with the resync count beside it |
| the event log | the client's own record ([§ 5.5](#55-the-clients-own-narration)), newest first, capped at 200 lines | text only. **The lobby is a renderer of that record and not its home** — § 5.5 owns what the record is and what goes into it; what this row states is that the lobby is where it is read, so a desk that moved, appeared or vanished has a written cause somebody can find |

**The ratified building cross-section is a *rendering* of this table, and changes nothing in it.**
[§ 10.4](#104-the-art-direction-as-a-specification)'s reference draws the lobby as a building seen in
section — one **floor plate per install**, stacked, with an elevator as the way between them — and
that was checked against this table row by row rather than assumed: the plates are
`installs[].install_id` in the same ascending order, each plate carries the same **per-floor state
summary** over the seats the client already holds in [§ 7.1](#71-the-render-per-state)'s fixed member
order, the fleet totals and the membership stamp are the same two readouts, and **the plate is the
link** exactly as the list row was. No new field is read, no count is recomputed, and
[§ 4.4](#44-routes-and-what-each-one-fetches)'s three routes are untouched — an elevator ride and a
zoom-to-floor are [§ 4.5](#45-the-viewport-rule-and-the-capability-floor)'s camera arriving at
`/floor/{install_id}`, which is the route this document already declares and which must still
deep-link on a cold start. **A cross-section that had replaced the summary with the desks themselves
would have been a different change** — it would have made the lobby's counts a thing a viewer counts
by eye, and [AT-D3-15](#at-d3-15-the-lobby-never-invents-a-count) exists because counting by eye is
where an invented count comes from. It does not, so this row stands.
**One consequence for the ratified sky, stated here because a reader deciding what a plate draws will
be standing on this paragraph:** a plate carries the summary, not the room, so **the lobby draws no
wall clock** — that element is the floor's ([§ 6.2](#62-the-animation-table--the-closed-set) A17).
Whether the cross-section draws sky behind the building is a rendering choice this table does not
make; what is **not** a choice is where it comes from if it is drawn, which is A17's row and A17's
driver. A second sky on a second driver would be two renderings of one fact, and the one on the timer
would keep moving after the feed died.

### 4.2 The floor

One install. `S` desk slots from the map, one desk per seat
([§ 3.2](#32-the-desk-slot-function)), a side table per desk for interns
([§ 8](#8-interns--subagent-rendering-and-the-cap)), and a persistent status strip carrying the same
fleet indicators the lobby shows.

The desk is the unit. Everything on it is [§ 5.1](#51-the-desk)'s table; every motion on it is
[§ 6.2](#62-the-animation-table--the-closed-set)'s table; every degraded treatment is
[§ 7](#7-degradation--how-a-degraded-seat-is-unmistakable)'s.

**The floor is legible without hover.** A viewer standing back must be able to read, per desk: the
state (pose + glyph), whether the state is current (the currency treatment), the seat name (nameplate),
and whether anything is wrong (badge cluster). Everything else — the descriptor, the task, the gauge
numerals, the ages — is desk-adjacent text at a size that rewards approaching, and all of it is in the
drill-down at full fidelity.

### 4.3 The desk drill-down panel

Opened by selecting a desk; closes to the floor; does not change the subscription. On open it issues
**two** requests, both D2's:

- `GET /api/fleet/seats/{install_id}/{seat_id}` — the seat object plus `detail`
  ([D2 § 8.2.3](FLEET-STATE.md#823-the-seat-detail-response)), which is also the uncapped source of the
  intern list.
- `GET /api/fleet/seats/{install_id}/{seat_id}/timeline?limit=50` — the recent-activity window
  ([D2 § 8.2](FLEET-STATE.md#82-rest)), paginated with `before` on scroll, `limit` never above 200.

**While the panel is open, deltas for that seat patch it live — the version-bearing members, and only
those.** A delta's `patch` carries the changed members of the version-bearing set
([D2 § 6.5](FLEET-STATE.md#65-the-fold)), so the ten bookkeeping members named in
[§ 2.4](#24-the-clock-and-every-age-on-the-page) are **not** patched by the feed. They are rendered
from the open-fetch response, under one ***as of HH:MM:SS*** stamp per block — [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s
**stamp rule** and its **`fetch-fresh`** marker, both of which this section cites rather than
restates: *when* such a value moves, and that it is never ticked, are that section's to say. **What is
this panel's own is which of its blocks that rule leaves moving**, because the answer differs per
block and nowhere else in this document says so: `delivery` is re-sent whole when a delta moves one of
its two version-bearing members (`no_data_since`, `seq_epoch`) and `reporter` when a delta moves
`version`, `platform` or `selftest_failed` ([D2 § 8.3.1](FLEET-STATE.md#831-worked-delta)), so those
two blocks can advance on a delta — while **no delta ever refreshes `derivation`**, all three of whose
members are among the ten, so it has no version-bearing sibling to ride and is the one block that
moves on a snapshot or a fetch and on nothing else. This document states no polling
cadence for the panel — inventing one would be inventing a cadence D2 does not state
([§ 1.2](#12-non-goals--stated-so-an-implementer-cannot-widen-scope-in-good-faith)) — it states the
stamp instead, so a reader can always see which moment those numbers describe.
[§ 14](#14-open-questions-for-the-review-loop) item 12 is the amendment that would retire the stamp.

| Panel section | Contents | Source |
|---|---|---|
| **header** | seat name, floor, `render_state` with its plain-language line, the currency label if any | [§ 5.1](#51-the-desk) |
| **current task** | `task.title`, the tier that answered (`task.source`), the reference as a link when a base URL is configured for its shape ([§ 5.2](#52-the-drill-down)), *stale title dropped* when `task.degraded` | `task.*` |
| **current action** | `action.tool_name`, `action.descriptor`, the seat-clock start time as a labelled timestamp, the elapsed time from `action.started_received_at` as ***running for 2m 05s*** ([§ 2.4](#24-the-clock-and-every-age-on-the-page)), `agent_scope`, `parent_call_id` | `action.*` |
| **context gauge** | the bar, the percentage to one decimal, `used_tokens / total_tokens` when non-null, the sample's own age, and `context.source` (`harness` or `computed`, never mixed — [D1 § 6.11](EVENT-SCHEMA.md#611-contextsample)) | `context.*` |
| **interns** | the subagent list — from the **detail** response, uncapped ([§ 8](#8-interns--subagent-rendering-and-the-cap)) | `detail`, `subagents_open` |
| **recent activity** | the timeline, newest first: `kind`, the seat-clock `event_time`, the receipt time, and the per-kind detail this document renders ([§ 5.2](#52-the-drill-down)) | the timeline endpoint |
| **transport** — **`fetch-fresh`**, one *as of* stamp | both ages, `no_data_since`, `clock_skew_ms`, `spool_lag_events`, `oldest_unsent_age_s`, `seq_epoch`, `last_seq` | `delivery.*` |
| **derivation** — **`fetch-fresh`**, one *as of* stamp | `computed_at`, `fold_lag_ms`, `cursor_event_id`, and the *this state is N s behind* line on the terms [§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy) states — this cell names the block's contents and leaves the render rule where it is owned | `derivation.*` |
| **reporter** — **`fetch-fresh`**, one *as of* stamp | `version`, `platform`, `selftest_failed`, `enabled` patch live; `uptime_s` is **`fetch-fresh`** and is **re-sent under the shallow merge** whenever one of the first three moves, so the block's stamp advances with it ([§ 2.4](#24-the-clock-and-every-age-on-the-page)'s stamp rule) rather than dating a fetch the value has already outlived | `reporter.*`, `enabled` |
| **badges** | every member of `badges[]`, each with its meaning and its counter value from `detail`, *since reporter start* framing for D1's array, and **one cluster-scoped** *oldest badge since HH:MM* line — `badges_since` is the minimum over the present members and is never stamped on an individual badge ([§ 7.2](#72-badges-every-member-has-a-render)) | `badges[]`, `badges_since`, `detail` |
| **session** | `session_id`, start (seat clock), `source`, `project_label`, `harness_label`, `model_label` | `session.*`, `model_label` |
| **retirement** | when `retired` is non-null: at, by, reason | `retired.*` |
| **raw** | `state_version` and the applied `seq_epoch` / `last_seq`, so a rendered state can be correlated with the wire. `state_version` and `seq_epoch` are version-bearing; `last_seq` is one of the ten and is **`fetch-fresh`** under the transport block's stamp | `state_version`, `delivery.*` |

### 4.4 Routes, and what each one fetches

| Route | Fetches on entry | Subscribes |
|---|---|---|
| `/` (lobby) | `GET /api/fleet/snapshot` | **`ADMIT`** ([§ 2.2](#22-connect-snapshot-deltas)) for every install in the snapshot not already admitted — never a bare subscribe, because a subscribe with no re-fetch behind it leaves the install's own admission window open |
| `/floor/{install_id}` | nothing new — the snapshot already holds it | as above; the floor renders one install's seats from the same map |
| `/floor/{install_id}/{seat_id}` (drill-down open) | the seat detail and the timeline | unchanged |

Deep-linking to a floor or a desk on a cold start runs the whole of
[§ 2.2](#22-connect-snapshot-deltas) first — the snapshot is the client's only complete population, and
a route that fetched one seat and rendered a floor around it would be rendering a floor whose other
desks it had not asked about.

### 4.5 The viewport rule and the capability floor

- **The floor requires ≥ 1,280 × 800 CSS px.** Below that, the route renders the **lobby's list view**
  for that install — the same facts as text, one row per seat, no map. A scaled-down floor whose
  nameplates and badges are unreadable is a floor that shows state without letting anyone read it,
  which is worse than the honest list.
  **Being crisp at any zoom does not license shrinking this number**, and the sentence is here
  because *"it is vector now, so it scales"* is exactly the argument the next reader will make. The
  floor is not 1,280 × 800 because of pixel density; it is 1,280 × 800 because a **nameplate and a
  badge cluster have to be readable**, and a legible glyph has a minimum size in the viewer's eye
  that no amount of resolution independence changes. Resolution independence removes the
  *resampling* failure; it does not remove the *legibility* failure, and this rule was always about
  the second.
- **Capabilities the implementer must have, and nothing further:** a renderer able to draw the map and
  the characters **at any camera zoom without resampling artefacts** — that is, a
  **resolution-independent** one, which is a property rather than a technology and is the property
  [§ 10.4](#104-the-art-direction-as-a-specification)'s art direction depends on; a WebSocket client
  speaking the Pusher protocol (Reverb's,
  [D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed)); and `prefers-reduced-motion` support. No
  framework, bundler or state library is specified
  ([§ 1.2](#12-non-goals--stated-so-an-implementer-cannot-widen-scope-in-good-faith)), and stating
  the capability as a property rather than as *a 2-D tile renderer drawing sprite frames* — which is
  what this bullet said while the art direction was pixel art — is what keeps that non-goal true.
- **The camera is navigation, and navigation is never state.** The ratified reference
  ([§ 10.4](#104-the-art-direction-as-a-specification)) zooms out to a building overview, zooms to a
  floor, and pans by wheel and drag. **A camera move animates nothing in
  [§ 6.2](#62-the-animation-table--the-closed-set)'s sense**: it renders no fact, it has no driving
  D2 field, and it gets **no row in that table** — it is the viewer moving their own head, not the
  fleet doing anything. Anything a camera move appeared to *start* would be an animation with no
  causing message, which [§ 6.1](#61-the-rule-and-what-a-loop-is-allowed-to-mean) forbids, and the
  reason to pin it here rather than leave it obvious is that a zoom transition is the single most
  natural thing to add to a table of animations it does not belong in.
- **Colour is never the only carrier of a fact.** Every state has a pose or glyph and a text label;
  every badge has a name in the drill-down. A palette is a rendering choice; a state legible only by
  hue is a state some viewers cannot read. **A seat's own hue is not a fact at all** — it is seeded
  appearance ([§ 10.4](#104-the-art-direction-as-a-specification)), and nothing on the page may read
  a state out of it.

---

## 5. The render map — every rendered fact, and its D2 field

**The rule this table exists to make checkable: every rendered fact names the D2 field it comes from,
and a rendered fact with no field is a fact the client invented.** The example column carries a real
value from [D2 § 8.2.2](FLEET-STATE.md#822-worked-snapshot)'s worked snapshot wherever that object has
one, so the two documents can be read side by side.
`tools/design/verify-floor.py` reds when a source cell names a field
[D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object) does not declare.

### 5.1 The desk

| Rendered element | D2 field | Example | When null / absent |
|---|---|---|---|
| nameplate | `seat_id` | `"aimla-pm"` | never null |
| floor name, and the desk's floor when seen from the lobby | `install_id` | `"aimla"` | never null |
| **pose and glyph** | `render_state` | `"working"` | never null. The one field the desk's appearance is switched on ([§ 7.1](#71-the-render-per-state)) |
| currency treatment — whether the pose may be read as *now* | `link_state` | `"live"` | never null ([§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim)) |
| the underlying activity, shown **under a label** when the desk is not `live` | `activity_state` | `"working"` | never null |
| the *why we do not know* line | `unknown_reason` | `null` | non-null only when `activity_state == "unknown"`; then one of seven reasons, each with its own sentence ([§ 7.1](#71-the-render-per-state)) |
| the rate-limit line on a `stalled` desk | `api_error_type` | `null` | non-null only when `activity_state == "stalled"`; rendered verbatim, e.g. *rate limit* |
| the monitor's content — what the seat is doing right now | `action.tool_name`, `action.descriptor` | `"Bash"`, `"Bash: composer test"` | `action` is null when no call is open: the monitor shows the desk's state line instead, never a stale last action |
| the action's start, as the seat's claim | `action.started_at` | `"2026-08-23T14:23:09.882Z"` | rendered *seat clock*, never as an age ([§ 2.4](#24-the-clock-and-every-age-on-the-page)) |
| the action's elapsed time | `action.started_received_at` | `"2026-08-23T14:23:14.201Z"` | rendered ***running for 2m 05s***, the fourth of [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s four durations. It is the basis of the **only** honest elapsed time over an action, because both ends are the server clock; version-bearing, so it ticks |
| the intern join key, and the *this is a subagent's call* marker | `action.call_id`, `action.agent_scope`, `action.parent_call_id` | `"01K3TA4E5F6G7H8J9K0M1N2P3Q"`, `"main"`, `null` | labels, and **the intern join is what they are stored for** — what [D2 § 4.8](FLEET-STATE.md#48-what-may-never-mint-a-state) forbids is a **state rule** gated on them ("a scope-dependent state rule"; "stored for the intern join and never gate anything"). So no pose, currency label or badge reads them, and [§ 5.2](#52-the-drill-down)'s intern list selects on them, which is the join D2 names |
| the open-call count, when it exceeds 1 | `open_calls` | `1` | never null; `0` renders nothing rather than a zero |
| the *thinking* pose — a turn open with no call | `open_turn` | `true` | never null; read **with** `open_calls`, and both are D2's facts, not an inference ([§ 6.2](#62-the-animation-table--the-closed-set) row A4) |
| the side table's stools | `subagents`, `subagents[].title`, `subagents[].subagent_type`, `subagents[].started_at`, `subagents[].call_id` | `"draft the D1 event schema"`, `"coder"` | a null `title` renders **untitled** and never an invented one ([§ 8](#8-interns--subagent-rendering-and-the-cap)) |
| the *+N more* tag on the side table | `subagents_open` | `1` | never null; the tag appears only when it exceeds the array's length |
| the task chip | `task.title`, `task.source`, `task.ref`, `task.as_of`, `task.degraded` | `"ingest endpoint"`, `"board_card"`, `"card#7338"` | `task` null ⇒ **no chip**, never a placeholder title |
| the context gauge | `context.used_pct` | `73.2` | `context` null ⇒ the gauge renders as *not reported*, **never as 0 %** ([§ 7.5](#75-what-a-degraded-desk-may-never-look-like)) |
| the gauge's numerals and its own age | `context.used_tokens`, `context.total_tokens`, `context.source`, `context.sampled_at`, `context.sampled_received_at` | `146401`, `200000`, `"harness"` | tokens are nullable; the bar still renders from `used_pct`, which is not |
| the model label | `model_label` | `"claude-opus-5"` | null ⇒ omitted |
| badge cluster | `badges`, `badges_since` | `["lossy"]` | empty ⇒ nothing rendered, and `badges_since` is then null; a badge appearing is animation [A11](#62-the-animation-table--the-closed-set). `badges_since` is the **cluster's** oldest onset ([D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)), never a per-badge stamp ([§ 7.2](#72-badges-every-member-has-a-render)) |
| the *reporting disabled* treatment | `enabled` | `true` | `null` before the first heartbeat, which is not the same as `false` and does not render as off |
| *no data since …* | `delivery.no_data_since` | `null` | non-null only when `link_state ∈ {stale, offline}`; then the desk's label reads *no data since 14:18* rather than a bare glyph ([D2 § 4.5](FLEET-STATE.md#45-link-states)). It is **version-bearing**, so the transition into dark is delivered; [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object) declares it *"equals `last_receipt_at`"* there, which is why the row below may tick an age from the same instant |
| the receipt age, on a dark desk only | `delivery.last_receipt_at` | `"2026-08-23T14:23:14.201Z"` | **`dark-only`**, and the marker is the whole of this cell: [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s marker table owns which desks draw this age, in which states, why it may tick and what a `live` desk therefore does not show — this row neither repeats that rule nor qualifies it. What is this table's own is the **field**: it is one of [D2 § 6.5](FLEET-STATE.md#65-the-fold)'s ten, and the *since* timestamp the desk draws beside the age comes from the version-bearing `delivery.no_data_since` of the row above. In the drill-down's transport block the same field is **`fetch-fresh`** ([§ 5.2](#52-the-drill-down)) |
| the quiet age | `activity.last_received_at` | `"2026-08-23T14:23:14.201Z"` | drives *nothing done for N*. All three `activity` members are **version-bearing** — every activity event emits a delta ([D2 § 6.5](FLEET-STATE.md#65-the-fold)) — so this is the one age a live desk may render and tick. Its divergence from the receipt age is the product ([D2 § 3.3](FLEET-STATE.md#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by)), and the drill-down is where both are read under one stamp |
| the last thing the seat did, and when it says it did it | `activity.last_kind`, `activity.last_event_time` | `"tool.start"`, `"2026-08-23T14:23:09.882Z"` | the second is a seat-clock claim |
| the *replaying history* treatment | `link_state`, `delivery.oldest_unsent_age_s` | `"catching_up"`, `null` | the **treatment** is driven by `link_state` / `render_state`, which are version-bearing and therefore delivered; `oldest_unsent_age_s` is the input D2 derives them from (`> 300` ⇒ `catching_up`, [D2 § 4.5](FLEET-STATE.md#45-link-states)) and is one of the ten, so its **number** is **`fetch-fresh`** in the drill-down and never on the desk. The desk renders the drain, not the work |
| the *this state is N s behind* label | `badges`, `derivation.fold_lag_ms` | `["fold_lag"]`, `117` | [§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy) owns this render — the four things it draws, the two surfaces it draws them on, and why the **badge** and not the number decides the treatment — and this row states none of it a second time. What is this table's own is the **source**: the treatment reads `badges`, which is version-bearing and therefore delivered, and the number is `derivation.fold_lag_ms`, one of [D2 § 6.5](FLEET-STATE.md#65-the-fold)'s ten and therefore **`fetch-fresh`**. `fold_lag_ms` is never null ([D2 § 2.3](FLEET-STATE.md#23-a-frozen-fold-is-the-dangerous-degradation)) |
| the retirement plate | `retired.at`, `retired.by`, `retired.reason` | `null` | present for 14 days after retirement ([§ 3.5](#35-retirement-and-the-only-removal)) |

### 5.2 The drill-down

Everything in [§ 5.1](#51-the-desk), at full fidelity, plus:

| Rendered element | Source | Example | Rule |
|---|---|---|---|
| heartbeat freshness | `delivery.last_heartbeat_at` | `"2026-08-23T14:23:00.412Z"` | **`fetch-fresh`** — rendered beside the receipt age under the panel's one *as of* stamp, because a heartbeat-only seat is the case the two readings separate ([AT-D2-4](FLEET-STATE.md#at-d2-4-a-heartbeat-only-seat-never-looks-busy)) |
| clock skew | `delivery.clock_skew_ms` | `412` | **`fetch-fresh`**; rendered whenever non-null, beside **every** seat-clock timestamp in the panel, so a narrative time is never read as an absolute one. The `clock_skew` **badge** past ±120 s is version-bearing and is what carries the condition onto the desk ([§ 7.2](#72-badges-every-member-has-a-render)) |
| spool state | `delivery.spool_lag_events`, `delivery.oldest_unsent_age_s` | `0`, `null` | **`fetch-fresh`** — both are of the ten; `link_state == "catching_up"` is the delivered consequence and is what the desk renders |
| wire provenance | `delivery.seq_epoch`, `delivery.last_seq`, `state_version`, `derivation.cursor_event_id`, `derivation.computed_at` | `"01K3T0000A5N7M2X9V4B6D0FGH"`, `48211`, `48219`, `9912837` | the correlation D2 provides for exactly this panel ([D2 § 8.5](FLEET-STATE.md#85-gaps-reconnect-and-why-state_version-is-not-seq)). `state_version` and `seq_epoch` are version-bearing and patch live; `last_seq`, `cursor_event_id` and `computed_at` are of the ten and are **`fetch-fresh`**, which is why the panel stamps the block rather than the line |
| session block | `session.session_id`, `session.started_at`, `session.source`, `session.project_label`, `session.harness_label` | `"a7f2c918-…"`, `"clear"`, `"mezzanine"`, `"claude-code/2.1.240"` | `session` null ⇒ *no session open*, which is a fact, not a blank |
| reporter block | `reporter.version`, `reporter.platform`, `reporter.uptime_s`, `reporter.selftest_failed` | `"0.1.0"`, `"linux"`, `401150`, `[]` | a non-empty `selftest_failed` is rendered as a list of named checks, up to its bound of 8 ([D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)). `uptime_s` is of the ten and is **`fetch-fresh`** — it is the flusher-restart discriminator, so a stale copy would mis-date a restart; `version`, `platform` and `selftest_failed` are version-bearing and patch live. **They share a nested object, so the shallow merge re-sends `uptime_s` with any of the three** ([D2 § 8.3.1](FLEET-STATE.md#831-worked-delta)): the value moves, and the block's stamp advances to that delta's `server_time` under [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s stamp rule. It is still never *ticked* — a `fetch-fresh` value moves when an object carrying it arrives, and at no other moment |
| counters | `detail`'s `seat_counters` rows and the reporter's `heartbeat_counters` / `heartbeat_predicates` snapshots | — | **`fetch-fresh`** by construction — `detail` exists only on the fetch ([D2 § 8.2.3](FLEET-STATE.md#823-the-seat-detail-response)) and no delta carries it. The reporter's are labelled **since reporter start** with `reporter.uptime_s` beside them, per [D2 § 7.3](FLEET-STATE.md#73-how-the-reporters-own-counters-are-handled) — never as *now* |
| the intern list, uncapped | `detail`'s full open-call list | — | [§ 8](#8-interns--subagent-rendering-and-the-cap). **The selection is stated rather than left to the reader:** the intern list is the subset of that list whose `agent_scope == "subagent"` — equivalently, the calls carrying a `parent_call_id` — which is the **intern join** D2 stores those two labels for ([D2 § 4.8](FLEET-STATE.md#48-what-may-never-mint-a-state): *"stored for the intern join and never gate anything"*). What D2 forbids there is a **scope-dependent state rule**; choosing which rows a panel lists is not one, and no pose, currency label or badge reads either field. [§ 14](#14-open-questions-for-the-review-loop) item 1 names this as the reading it took, because *"the open call list in full"* could equally have meant every open call, and the panel that listed every one would call a seat's own `Bash` call an intern |
| the recent-activity timeline | the timeline endpoint | — | see the rule below |
| the task reference as a link | `task.ref` | `"card#7338"` | rendered as a link **only** when a base URL is configured for that reference shape (`card#N` and `<repo>#N` are the two shapes [D2 § 4.9](FLEET-STATE.md#49-the-task-title-merge-and-what-is-not-specified-here) declares); with no configured base it renders as plain text. A guessed URL is a link that goes somewhere wrong, which is worse than no link — [§ 14](#14-open-questions-for-the-review-loop) item 3 |

**The timeline renders only fields that provably exist.** [D2 § 8.2](FLEET-STATE.md#82-rest) declares
the endpoint, its parameters and its ordering, and describes its rows as "the seat's renderable events"
without a field table or a kind list. So this document renders, per row, only fields something upstream
**declares** on a stored event:

- `kind` and `event_time` (seat clock, labelled) — [D1 § 4.3](EVENT-SCHEMA.md#43-common-per-event-fields)
  declares both on **every event of every kind**, and [D2 § 6.4](FLEET-STATE.md#64-ddl) stores them
  verbatim.
- `received_at` (server clock, the basis of the row's age) — **D2's field, not D1's.**
  [D1 § 4.3](EVENT-SCHEMA.md#43-common-per-event-fields)'s common-field table declares `event_id`,
  `schema_version`, `kind`, `event_time`, `seq`, `install_id`, `seat_id`, `session_id`, `data` and
  `oversize`, and **no `received_at`** — a seat cannot stamp a server clock, which is the whole of why
  [D1 § 10.1](EVENT-SCHEMA.md#101-two-clocks-and-which-is-authoritative-for-what) calls it *"server
  clock, recorded at ingest"* and makes it authoritative for *"every relative age the UI renders"*. It
  is [D2 § 6.4](FLEET-STATE.md#64-ddl)'s `events.received_at`, `NOT NULL` on every row of the table
  this endpoint reads. Attributing it to D1's common fields — as an earlier revision of this paragraph
  did — would have rested the timeline's only age on a guarantee D1 cannot give, which is exactly the
  provability this paragraph exists to assert.

Plus the `data` members this document already renders elsewhere for that kind, where the response
carries them. **It renders no per-kind
detail the response is not specified to carry**, and it renders an empty window as *no activity in this
window* rather than as an empty panel. [§ 14](#14-open-questions-for-the-review-loop) item 1 asks D2 for
the field table and the renderable-kind set; until it answers, the timeline is a true list of what the
seat did and when, and nothing is guessed onto it.

### 5.3 The fleet, on both screens

| Rendered element | Source | Rule |
|---|---|---|
| store indicator | `fleet.db` | `down` ⇒ [§ 9](#9-failure-paths-and-their-observables)'s store-unavailable render, which is a full statement, not a red dot |
| derivation indicator | `fleet.fold`, `fleet.max_fold_lag_ms` | `lagging` ⇒ indicator only; `stalled` ⇒ **a fleet banner**, which is [D2 § 2.3](FLEET-STATE.md#23-a-frozen-fold-is-the-dangerous-degradation)'s stated obligation on this document |
| sweep indicator | `fleet.sweep`, `fleet.sweep_last_run_at` | `stalled` ⇒ indicator plus the age; a dead sweep is what leaves a dead seat rendering `working`, so the banner text says so |
| ingest recency | `fleet.ingest_last_receipt_at` | rendered as an age; it is the fleet-wide reading that separates *every seat died* from *our pipe is broken* |
| fleet counts | `fleet.seats_total`, `fleet.seats_live` | never recounted ([§ 4.1](#41-the-lobby--the-building-summary)) |
| fleet counters | `GET /api/fleet/health`'s `counters` — the nine fleet-scoped counters | rendered on an operator view of the health endpoint only; **a `null` `counters` renders as *unreadable*, never as zeros** ([D2 § 8.2.4](FLEET-STATE.md#824-the-fleet-health-object): "`null` says *we could not read these*; `0` would say *nothing has happened*") |

### 5.4 What is never rendered

- **Anything the wire did not send.** D2 exposes no field D1 did not send
  ([D2 decision 29](FLEET-STATE.md#13-decisions-taken-revisable-at-review)); this document adds no
  field D2 did not send. There is no host name, no IP, no path, no prompt text and no file content on
  any screen, because none of them is on the wire.
  **The one thing this rule does not reach, said here rather than left to be argued at the render
  site: a rendering of the seat's own IDENTITY.** A desk's slot, and a character's shape, hue and
  seeded **vibe line** ([§ 10.4](#104-the-art-direction-as-a-specification)), are all pure functions
  of `(install_id, seat_id)` — two fields the wire **does** send
  ([§ 3.1](#31-the-keys-and-why-they-are-the-only-ones)) — so none of them is a fact the client
  invented; each is the identity redrawn. What the rule above does bind is the **direction**: an
  appearance-class rendering may **never become a fact about state**, so it carries a label saying it
  is seeded, and it drives **no pose, no currency label, no badge and no animation**. That is the
  same boundary [§ 5.5](#55-the-clients-own-narration) draws for the client's own narration, arriving
  from the other side — the narration is *the client talking about itself*, this is *the client
  drawing the seat's name* — and neither is ever a state. A vibe line that changed with
  `render_state` would be state-bearing text with no field, which is exactly what this section
  refuses.
- **A token, a token prefix, a token hash, or any part of one.** They are not on the read surfaces
  ([D2 § 9](FLEET-STATE.md#9-read-side-authentication)) and nothing on any screen may display one even
  if a future field carried it.
- **An unrecognised enum member guessed into a known one.** A `render_state`, `link_state`,
  `activity_state`, `unknown_reason`, `api_error_type` or badge the client does not know renders as an
  explicitly **unrecognised** glyph carrying the raw string, and the desk is treated as
  not-current — never mapped to the nearest known member and never defaulted to a healthy-looking one
  ([§ 9](#9-failure-paths-and-their-observables), [AT-D3-11](#at-d3-11-an-unrecognised-member-renders-as-unrecognised)).
  **"The client does not know" is a membership test against a set this document publishes, and all six
  sets are published here**: `render_state` and `unknown_reason` in [§ 7.1](#71-the-render-per-state),
  the 18 badges in [§ 7.2](#72-badges-every-member-has-a-render), and `link_state`, `activity_state`
  and `api_error_type` in [§ 7.6](#76-the-three-remaining-member-sets-published-so-membership-is-testable).
  A rule whose known-set lived nowhere would be a rule an implementer could only guess at, and the
  first thing it would guess wrong is the member this document forgot to list.
  **`api_error_type` is rendered verbatim *and* membership-tested, which is not a contradiction**: the
  line carries the raw string either way ([§ 5.1](#51-the-desk)), and membership decides only whether
  it carries its plain-language sentence or the **unrecognised** marker beside the raw value. Reading
  the two rules as alternatives is what would make one of them dead.

### 5.5 The client's own narration

[§ 2.1](#21-the-seven-client-computed-values-closed) row 7 is the one rendered surface whose facts are
not D2's, so [§ 5](#5-the-render-map--every-rendered-fact-and-its-d2-field)'s rule — *every rendered
fact names the D2 field it comes from* — cannot be the one that holds it. It gets its own, and the rule
is stricter rather than looser: **a narration line states what the client did or saw, is labelled as
the client's own, and never becomes a fact about a seat.**

| Rendered narration | The client's own record | Rule |
|---|---|---|
| feed status — *live* · *polling (feed down)* · *reconnecting* · *reload required* | the time since the last message of any kind on the socket, against [§ 9](#9-failure-paths-and-their-observables) F1's 45 s | a statement about **this client's connection**, never about a seat. Whatever it reads, every desk keeps its last delivered state and its currency label still comes from `link_state` ([§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim)) |
| the ***live*** claim | a feed message newer than 45 s **and** a REST response newer than the last `401` ([decision 18](#13-decisions-taken-revisable-at-review)) | three inputs, all the client's own. It is the most consequential label on the page and it is deliberately conservative: erring toward *not live* is the correct direction for this product ([§ 9](#9-failure-paths-and-their-observables) F7) |
| ***resyncs: N*** | the number of [§ 9](#9-failure-paths-and-their-observables) F2 resyncs this client has issued since it loaded | a count of **this client's own requests**, labelled as one. It is not a seat field and not D2's `feed_gap_detected`, which is the server's count of the same events and is on no read surface this document renders |
| the client's event log, **200 lines** | membership changes, resyncs, reconnects, removals and F9's unrecognised values — newest first, one line each, carrying the moment **the client** saw it | text only, capped at 200 ([§ 12](#12-every-number-and-where-it-comes-from)). It is a narration and not a history: D2's own surfaces hold the durable record ([§ 1.2](#12-non-goals--stated-so-an-implementer-cannot-widen-scope-in-good-faith)). **This cell owns the record-versus-renderer distinction and every other site in this document points at it: the record is the CLIENT PROTOCOL's artifact, written as the client acts, and the lobby is one *renderer* of it ([§ 4.1](#41-the-lobby--the-building-summary)) — so nothing in this document calls it *the lobby log*, because naming the renderer where the artifact is meant is what gated three acceptance tests on a screen built six steps after the record they read.** The build-order consequence is [§ 11](#11-acceptance-tests)'s to draw |
| ***membership as of HH:MM:SS*** | the moment of the last full snapshot **apply** | the age of the *membership* picture, rendered separately from the age of the *state* picture ([§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold)) |
| ***the client holds N of M seats — refreshing*** | the per-floor counts it holds, against `fleet.seats_total` | the only narration line that names a wire number, and it names it **as the wire's**: [§ 4.1](#41-the-lobby--the-building-summary) renders the disagreement rather than picking a winner ([AT-D3-15](#at-d3-15-the-lobby-never-invents-a-count)) |
| ***floor map is short N desks*** | the rendered seat count against `S`, the map's own slot count ([§ 3.2](#32-the-desk-slot-function)) | a fact about the map and this client's layout, not about any seat ([§ 9](#9-failure-paths-and-their-observables) F13) |
| the **wall clock** and the windows' **sky** ([§ 6.2](#62-the-animation-table--the-closed-set) A17) | the **viewer's own clock**, read at the moment a `feed.heartbeat` arrives — never the server clock, never corrected by [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s offset, and never a seat's | a fact about **the viewer's machine**, labelled on the page as the viewer's own local time so that nothing about it reads as wire data. It is the one line here rendered by an animation rather than as text, which is why the rule below is stated in terms of *drives* rather than *appears in*: the heartbeat drives A17 and this value is what A17 **sets**. Its stopping is the point ([§ 9](#9-failure-paths-and-their-observables) F1) and it carries no *as of* stamp of its own — the feed-status line above is where this page says how current it is |

**None of these is a state, and none of them may become one.** A narration line never drives a desk's
pose, a currency label, a badge or an animation — the only effect the client's own connection state has
on a desk is [§ 9](#9-failure-paths-and-their-observables) F1's, which is *none*, beyond the ages
continuing to tick from the timestamps the client already holds. That is the same boundary
[§ 2.1](#21-the-seven-client-computed-values-closed) draws between presentation and state, applied to
the one surface where the client is allowed to talk about itself.
**The wall clock row is inside that rule, not an exception to it, and the difference is causal
direction.** A narration may not *drive* an animation, because motion on this page is a claim that the
fleet did something and the client's own state is not the fleet's.
[A17](#62-the-animation-table--the-closed-set) is driven by a delivered `feed.heartbeat` — the same
message that drives [A14](#62-the-animation-table--the-closed-set) — and the viewer's clock is only the
**value it sets**, exactly as [A5](#62-the-animation-table--the-closed-set)'s cross-fade is driven by a
delta and sets the glyph the delta named. Reverse the direction — let the viewer's clock decide *when*
to render — and it is a timer, which
[§ 6.3](#63-forbidden-forms-named-so-they-cannot-be-written-in-good-faith) forbids and this row does not
touch. Everything else in the rule binds it unchanged: it drives no pose, no currency label and no
badge, and it never becomes a fact about a seat.

**There is a second such surface and it is not this one:** the seat's **seeded appearance**, including
the **vibe line** in the drill-down ([§ 10.4](#104-the-art-direction-as-a-specification)). It is not
narration — it says nothing about what the client did or saw — and it is not a wire fact either; it
is `(install_id, seat_id)` redrawn, which [§ 5.4](#54-what-is-never-rendered) admits and labels. The
rule it inherits from this section is the last one above, unchanged and for the same reason: **it
never drives a pose, a currency label, a badge or an animation.** Two surfaces, two different
justifications, one boundary.

### 5.6 The null render, for every nullable member

[Decision 13](#13-decisions-taken-revisable-at-review) — *a null is rendered as **not reported**, never
as a zero* — is a rule an implementer can only obey against a **stated** behaviour per member, and
[§ 1.1](#11-what-this-document-owns) claims this document carries one for every rendered fact. It is
here, in one table, rather than distributed across [§ 5.1](#51-the-desk)'s and
[§ 5.2](#52-the-drill-down)'s rule cells, because a null render written into whichever row happened to
name the member is a null render that is stated for some members and silently absent for the rest —
which is what an earlier revision of this document did for two dozen of them, including the one age a
live desk ticks.

**The population is [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)'s own `Null? yes` column,
all 36 of it**, re-derived by `tools/design/verify-floor.py` on every run and set-differenced against
this table **in both directions** (G10): a member D2 marks nullable with no row here, and a row here
for a member D2 does not mark nullable, both red the gate. That is the same closure
[§ 7.6](#76-the-three-remaining-member-sets-published-so-membership-is-testable) gives the six enum
sets, applied to the one property every one of those members has.

**The default, so that the table states departures rather than repeating itself:** a null renders as
**the absence it is** — the element is simply not drawn — and where the element's own space is drawn
unconditionally, it reads ***not reported***. A null is never a zero, never a placeholder, never the
last non-null value the client held, and never a value derived from a different field standing in for
it.

| D2 member | What renders when it is null |
|---|---|
| `unknown_reason` | the *why we do not know* line is not drawn. Null on every seat whose `activity_state` is not `unknown`, which is the ordinary case |
| `api_error_type` | the rate-limit line is not drawn, and the `stalled` desk carries its pose and label without one. Null on every seat that is not `stalled` |
| `action` | no monitor content: the monitor shows the desk's **state line**, never a stale last action ([§ 5.1](#51-the-desk)) |
| `action.descriptor` | the monitor shows `action.tool_name` alone. **Never a descriptor synthesized from the tool name** — *"Bash"* and *"Bash: composer test"* are different claims, and only one of them was sent |
| `action.agent_scope` | no *this is a subagent's call* marker, and the call is **not** admitted to the drill-down's intern list, which selects on this field ([§ 5.2](#52-the-drill-down)). Never defaulted to `main`: a defaulted scope would move a call between the seat's own list and the intern list on a field the wire left empty |
| `action.parent_call_id` | no parent is named. The call is not drawn as an intern on the strength of a missing field |
| `subagents[].title` | **untitled**, with the `call_id` in the drill-down — the honest orphan D1 § 6.8 and D2 § 8.2.1 both refuse to paper over ([§ 8](#8-interns--subagent-rendering-and-the-cap)) |
| `subagents[].subagent_type` | the type tag beside the label is not drawn. The stool and its label are unaffected |
| `task` | **no task chip at all**, never a placeholder title ([§ 5.1](#51-the-desk)) |
| `task.ref` | the title renders with **no link and no reference text** — not an empty link, not *(no reference)*. [§ 14](#14-open-questions-for-the-review-loop) item 3 is the base-URL question, which is a different absence |
| `context` | the gauge reads ***not reported*** and **the bar is absent** — not a bar at 0 % ([§ 7.5](#75-what-a-degraded-desk-may-never-look-like), [decision 13](#13-decisions-taken-revisable-at-review)) |
| `context.used_tokens` | the numerals read *not reported*; **the bar still renders**, because `used_pct` is not nullable ([§ 5.1](#51-the-desk)) |
| `context.total_tokens` | as above, and **no percentage is recomputed** from the token pair to stand in for `used_pct` |
| `model_label` | omitted entirely — no label, no *(unknown model)* ([§ 5.1](#51-the-desk)) |
| `session` | the session block reads ***no session open***, which is a fact and not a blank ([§ 5.2](#52-the-drill-down)) |
| `session.started_at` | the block renders without a start line. **Never the seat's first-seen time**, which is a client fact standing in for a seat's |
| `session.source` | no source tag. Never defaulted to `startup`: *we were not told how this session began* and *it began at startup* are different facts ([D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)) |
| `session.project_label` | the line is omitted. Never `install_id` in its place |
| `session.harness_label` | the line is omitted. Never `reporter.version` in its place |
| `activity.last_event_time` | the seat-clock line beside the last kind is not drawn |
| `activity.last_received_at` | **the quiet age is not drawn, and the desk reads *nothing done yet*** — never *nothing done for 0s*, which would claim a measurement at this instant. **This is the reachable case the whole rule is for**: [§ 3.4](#34-a-new-seats-first-appearance)'s provisioned-never-reported seat, and [D2 § 3.1](FLEET-STATE.md#31-the-rule) rule 4's heartbeat-only seat — *"A seat that only heartbeats is quiet, and renders as quiet"* — which is `live`, so its receipt age is `dark-only` and **the quiet age is the only age its desk carries** ([§ 5.1](#51-the-desk), Appendix A T29) |
| `activity.last_kind` | the *last thing the seat did* line is not drawn. Never *(unknown)*, which is a `render_state` member's word for a different fact |
| `delivery.last_receipt_at` | the drill-down's transport block renders the receipt age as ***no data yet***, under the block's *as of* stamp (**`fetch-fresh`**). On the desk the **`dark-only`** age has nothing to draw and is not drawn: this is the never-reported seat [D2 § 4.5](FLEET-STATE.md#45-link-states) rule 1 mints `offline` from, so `delivery.no_data_since` is null too and [§ 7.1](#71-the-render-per-state)'s `offline` row already reads ***no data yet*** — one string, not a second one beside it |
| `delivery.last_heartbeat_at` | the heartbeat-freshness line reads *not reported* — **`fetch-fresh`**, in the drill-down's transport block under that block's *as of* stamp ([§ 5.2](#52-the-drill-down)) |
| `delivery.no_data_since` | on a `stale`/`offline` desk the label reads ***no data yet*** rather than *no data since null* ([§ 7.1](#71-the-render-per-state), [§ 3.4](#34-a-new-seats-first-appearance)); on a `live` desk it is null by construction and the line is not drawn at all |
| `delivery.clock_skew_ms` | not rendered, and **no seat-clock timestamp gains a skew note** ([§ 5.2](#52-the-drill-down): *rendered whenever non-null*). Never 0, which would claim the two clocks were measured to agree. **`fetch-fresh`** — the non-null render is the panel's, under the transport block's stamp |
| `delivery.spool_lag_events` | the spool line reads *not reported*. **Never 0** — an unmeasured spool and an empty one are opposite facts. **`fetch-fresh`**, transport block |
| `delivery.oldest_unsent_age_s` | *not reported*, never 0 — **`fetch-fresh`**, transport block. The `catching_up` treatment is driven by `link_state` either way ([§ 5.1](#51-the-desk)), so nothing on the desk changes |
| `delivery.seq_epoch` | the wire-provenance line omits it. Never a zero-ULID and never the previous epoch |
| `delivery.last_seq` | *not reported*, never 0 — the wire-provenance line, **`fetch-fresh`** under the transport block's stamp ([§ 5.2](#52-the-drill-down)) |
| `badges_since` | no *oldest badge since* line. It is null exactly when `badges` is empty, and an empty cluster renders nothing ([§ 5.1](#51-the-desk), [§ 7.2](#72-badges-every-member-has-a-render)) |
| `enabled` | **the *reporting disabled* treatment is not applied.** Null is *before the first heartbeat* and is not `false`; rendering the two alike would be [D1 § 6.14](EVENT-SCHEMA.md#614-reporterheartbeat)'s *off must not look like gone* failing on the third value ([§ 5.1](#51-the-desk)) |
| `reporter.version` | *not reported*. Never the last version the client held — the flusher may have restarted into a different one, which is the fact `uptime_s` exists to discriminate |
| `reporter.platform` | *not reported*. Never inferred from anything else on the object |
| `reporter.uptime_s` | *not reported*, never 0 — **`fetch-fresh`**, under the reporter block's own stamp. The ***since reporter start*** framing beside D1's twelve badges ([§ 7.2](#72-badges-every-member-has-a-render), [D2 § 7.3](FLEET-STATE.md#73-how-the-reporters-own-counters-are-handled)) then reads *since reporter start — uptime not reported*, because the counters are still monotonic-since-start and only the **length** of that window is unknown |
| `retired` | no retirement plate on the desk and no retirement block in the panel. The seat is not retired ([§ 3.5](#35-retirement-and-the-only-removal)) |

**Two of these are the same fact wearing two markers, and that is not a contradiction.**
`delivery.last_receipt_at` and `delivery.no_data_since` are both null on a provisioned-never-reported
seat, and both render *no data yet* — on different surfaces, from different fields, under different
delivery rules ([§ 2.4](#24-the-clock-and-every-age-on-the-page)). The desk's line is the
version-bearing one and is therefore delivered; the panel's is `fetch-fresh` and carries a stamp.

---

## 6. The honesty principle — every animation and its driving event

### 6.1 The rule, and what a loop is allowed to mean

> **Every animation is driven by a real event, or absent.**
> — operator, via the proposal; restated at
> [`docs/PLAN.md § 2`](../PLAN.md#2-design-first-gates--the-order-is-the-plan).

Three consequences, stated because the rule alone leaves each of them arguable:

1. **[§ 6.2](#62-the-animation-table--the-closed-set) is the closed set.** Every animation in the
   product has a row naming the field or message that drives it and the edge that starts it. An
   animation with no row is a defect; `tools/design/verify-floor.py` reds when an animation id is named
   anywhere in this document without a row, or when a row's driving fact is not a field or message D2
   declares.
2. **A state-held loop says WHICH state, never HOW MUCH.** The working loop runs while
   `render_state == "working"` and stops the moment it is not. Its frame rate is **fixed at 4 fps for
   every loop on the floor** — one frame per [D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed)'s
   250 ms coalescing tick, which is the fastest rate at which the wire can inform the client of
   anything, so **no loop can appear more informative than the feed**. A loop whose speed tracked
   tokens, tool calls or throughput would be rendering a quantity nothing sent.
3. **An edge-triggered animation fires once, from a delta the client applied**, and never from a
   snapshot, a poll, a re-render, a resync or the 1 s age tick
   ([§ 6.5](#65-a-snapshot-never-animates)). A **held** render is a different class and takes a
   different rule: it is entered whenever the object the client holds says so — including on a
   snapshot, a resync or a per-seat fetch — because it is held by a delivered field rather than
   started by a message. What [§ 6.5](#65-a-snapshot-never-animates) forbids is an **edge** animation
   on a snapshot; a `working` desk that stopped typing after every reconnect would be the same lie in
   the other direction ([§ 6.2](#62-the-animation-table--the-closed-set)).

### 6.2 The animation table — the closed set

**Every animation, its class, its driver, and what its absence means.** The table carries **two
classes**, and the split is load-bearing rather than descriptive — one schema over both is what made
this document's own headline test unsatisfiable:

- **`edge`** — fires **once**, caused by a wire message the client applied: a `seat.delta`, a
  `feed.heartbeat`, a `seat.retired`, or the seat-set change of
  [A16](#62-the-animation-table--the-closed-set). What makes the class is that an edge animation **has
  a causing message** and **is an instant** — it fires once and there is no later moment at which it
  stops. What the animation log writes for it follows from those two facts and is
  [§ 11](#11-acceptance-tests)'s to state. An edge animation with no causing message is the defect the
  honesty principle exists to refuse.
- **`held`** — a render **held** for exactly as long as a delivered field has a value: the working,
  thinking, attention and replay loops, and the three states whose held render carries no motion at all
  (`idle`, `stalled`, `unknown`). A held render has **no causing message** — it is entered whenever the
  object the client holds says so, and that object may have arrived by delta, by snapshot, by resync or
  by fetch. What makes the class is those two facts plus a third: **one render may be entered and left
  more than once on one seat**, so a held render has a beginning and an end where an edge animation has
  only an instant. The log schema that follows — the version it records instead of a message, the two
  rows, and the `episode_id` that pairs them — is [§ 11](#11-acceptance-tests)'s. A held render
  is not exempt from the honesty principle: it is held by a delivered field, and the log records which
  field, which value and which version — on the way in, and again on the way out, against the object
  that ended the hold.

**Why the split, and not four more words in one column.** [§ 6.1](#61-the-rule-and-what-a-loop-is-allowed-to-mean)
rule 2 makes a `working` desk run a loop for as long as it is `working`, and
[D2 § 8.2.2](FLEET-STATE.md#822-worked-snapshot)'s snapshot — the one
[`fx-snapshot-4`](#11-acceptance-tests) is built from — delivers a `working` seat. So a correct client
starts a loop on a snapshot apply, where there is no causing message to record; under one schema the
log's totality rule ("every row has a non-null cause") and [§ 6.5](#65-a-snapshot-never-animates)'s
"a snapshot fires nothing" contradicted each other on the headline fixture, and the implementer's two
escapes were both worse than the defect: suppress held renders on snapshots and the floor goes
**static after every reconnect** — which [§ 6.3](#63-forbidden-forms-named-so-they-cannot-be-written-in-good-faith)
and [decision 3](#13-decisions-taken-revisable-at-review) make a *lie*, because a still floor **is** a
still fleet — or stop logging held renders and [AT-D3-5](#at-d3-5-a-degraded-seat-is-visibly-degraded)'s
*motion stopped* assertion loses its instrument. Edge animations keep the strict rule; held renders get
the rule that is true of them.

"Edge that starts it, or the fact it is held by" is the exact condition; "reduced-motion form" is what
replaces the motion under [§ 6.4](#64-reduced-motion-is-a-first-class-rendering-not-a-degradation), and
it carries the same fact.

| # | Class | Animation | Where | Driving fact (D2) | Edge that starts it, or the fact it is held by | Ends | Reduced-motion form | Its absence means |
|---|---|---|---|---|---|---|---|---|
| **A1** | `edge` | `arrive` — the character walks in and sits | desk | `render_state` | a delta whose `changed[]` contains `render_state` and whose new value leaves `offline` | on arrival at the desk | the character is simply present | the seat has not left `offline` |
| **A2** | `edge` | `depart` — the character stands and walks out, leaving the chair empty | desk | `render_state` | a delta whose new `render_state` is `offline` | at the door | the chair is empty and labelled | the seat is still reporting |
| **A3** | `held` | `work` — typing at the keyboard, with the eye **blink** and the gentle in-place **wiggle**, 4 fps loop | desk | `render_state` | `render_state == "working"` **and not** A4's condition — the two are exclusive, and stating it here is what makes *the held rows this table predicts* a single answer rather than two ([§ 7.1](#71-the-render-per-state)'s `working` row says the same thing in prose) | when it is not | a *working* pose, static, with the glyph | the seat is not working **now** |
| **A4** | `held` | `think` — leaning back, watching the monitor, with the same **blink** and **wiggle**, 4 fps loop | desk | `open_calls`, `open_turn` | `render_state == "working"` **and** `open_calls == 0` **and** `open_turn == true` | when either fact changes | a *thinking* pose, static | there is an open call, so A3 runs instead |
| **A5** | `edge` | `tool-swap` — the monitor's glyph changes, one 250 ms cross-fade | desk monitor | `action.tool_name` | a delta whose `changed[]` contains `action` and whose `action.tool_name` differs from the held one | after one tick | the glyph changes with no fade | the action did not change |
| **A6** | `held` | `idle` — the character is **slumped asleep on the desk**, the monitor dimmed, with drifting **z**'s, 4 fps loop | desk | `render_state` | `render_state == "idle"` | when it is not | the **static slumped pose**, z's drawn once and still | the seat has not cleanly finished a turn. **A sleeper is never a gone seat** — [§ 7.5](#75-what-a-degraded-desk-may-never-look-like) owns that rule |
| **A7** | `held` | `attention` — a raised hand and a marker above the desk, 4 fps loop | desk | `render_state` | `render_state == "blocked"` | when it is not | a static raised-hand pose and the marker | no `attention.request` is open ([D2 § 4.4](FLEET-STATE.md#44-activity-states-every-entry-and-exit-edge)) |
| **A8** | `held` | `stalled` — head in hands, static, with the `api_error_type` line | desk | `render_state`, `api_error_type` | `render_state == "stalled"` | when it is not | identical | no `turn.end(api_error)` is standing |
| **A9** | `held` | `unknown` — a question marker over an occupied desk | desk | `render_state`, `unknown_reason` | `render_state == "unknown"` | when it is not | identical | the seat's last turn record supports a positive reading |
| **A10** | `edge` | `intern-arrive` / `intern-leave` — a stool at the side table fills or empties | side table | `subagents` | a delta whose `changed[]` contains `subagents` and whose array gains or loses a `call_id` | on arrival / at the door | the stool is simply occupied or empty | the subagent set did not change |
| **A11** | `edge` | `badge-raise` — a badge appears with a single 250 ms fade | desk | `badges` | a delta whose `changed[]` contains `badges` and whose array gains a member | after one tick | the badge is simply present | no new badge |
| **A12** | `edge` | `gauge` — the context bar eases to its new value over 250 ms | desk, drill-down | `context.used_pct` | a delta whose `changed[]` contains `context` | at the new value | the bar jumps to the value | no new sample; **the bar never drifts between samples** |
| **A13** | `edge` | `retire` — the desk clears, the chair pushes in, the plate is stamped | desk | `render_state`, `seat.retired` | `render_state == "retired"`, or the `seat.retired` message | when the plate is set | the desk is simply cleared and stamped | the seat is not retired |
| **A14** | `edge` | `feed-pulse` — a one-frame pulse on the feed indicator | status strip | `feed.heartbeat` | each `feed.heartbeat` message received | after one frame | a *last message HH:MM:SS* readout that updates instead | **no message has arrived** — which at 45 s is the feed-down condition itself ([§ 9](#9-failure-paths-and-their-observables)) |
| **A15** | `held` | `catching-up` — a replay marker sweeps the monitor, 4 fps loop | desk | `render_state` | `render_state == "catching_up"` — D2 derives it from `delivery.oldest_unsent_age_s > 300`, but that input is one of [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s ten and a held copy of it freezes, so the **delivered** collapse is what holds this render | when it is not | a static replay marker and the *replaying* label | the seat's spool is not draining |
| **A16** | `edge` | `desk-move` — a displaced character walks to its new desk | floor | the rendered seat set | a seat entering the set displaces an incumbent ([§ 3.3](#33-collision-displacement-and-why-a-desk-move-is-itself-an-event)) | on arrival | the desk appears in its new slot on the next render | no arrival collided |
| **A17** | `edge` | `room-tick` — the wall clock's hands step to the viewer's current minute and the windows' sky is re-evaluated for that time | the **floor's room** — its wall clock, and the sky in its windows ([§ 4.2](#42-the-floor)). **On the lobby it is this row or nothing:** [§ 4.1](#41-the-lobby--the-building-summary)'s cross-section renders a per-floor *summary* rather than the rooms, so it draws no wall clock at all; if it draws sky behind the building, that sky is this row's, on this row's driver, and never a second one of its own | `feed.heartbeat` | each `feed.heartbeat` message received, on any subscribed channel. **The same trigger as A14, and the pairing is the design rather than a duplication** — the note below is where that is argued | at the new time and the new sky value: one step, no tween | the hands **jump** to position and the sky **steps** to its new value with no cross-fade — the same fact, without the transition ([§ 6.4](#64-reduced-motion-is-a-first-class-rendering-not-a-degradation)) | **no message has arrived** — which at 45 s is the feed-down condition itself ([§ 9](#9-failure-paths-and-their-observables) F1). **A stopped clock is that condition in the form every viewer reads without being told**, which is why this row exists at all |

**Two rows move with no seat's state behind them — A14 and A17 — and both are driven by
`feed.heartbeat`, so when the feed dies they stop together and the page goes still.** That is the
property, and it is what an earlier revision of this note was protecting when it read *"A14 is still
the only thing on the page that moves unconditionally"*: that sentence went false the moment A17 was
written, and it is quoted here as the claim being amended rather than deleted, because the property
under it is the one [AT-D3-6](#at-d3-6-the-feed-dying-is-visible-within-45-s) rests on and it is
**stronger** now than it was — *everything on this page that moves without a delivered field holding it
is driven by the heartbeat*. The reason is still the class column: a `held` loop runs only while a
delivered field has a value, so **every** loop on the floor — A3, A4, A6, A7, A15 — is conditional on
something the wire delivered, and a desk with nothing delivered behind it is still; the only rows
conditional on **no seat's state at all** are these two `edge` rows, and the heartbeat that fires them
is the heartbeat whose absence *is* the feed-down condition. **This claim is re-derived at every
amendment rather than carried over** — it was re-derived when A6 gained a loop and survived, and
re-derived when A17 landed and did not, which is exactly the kind of claim an amendment falsifies
silently.

**One clock, every floor, and it freezes only when every channel is silent.** The heartbeat is **per
channel**, which is per install ([D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed)), so a client
holding four installs samples up to four times in a 15 s window. That changes nothing about the value —
there is one viewer clock, so two floors can never disagree about the time — and it makes the stopping
condition the right one: the clock stops when **no message of any kind** is arriving, which is
[§ 9](#9-failure-paths-and-their-observables) F1's condition to the letter. One install going dark is a
fact about that floor's desks and their `link_state`, and the clock must not claim it.

⭐ **What A17's clock is for, written down because it is the thing a maintainer will undo.** The wall
clock on this floor is not there to tell the time — the viewer's own machine already does that, in the
corner of the same screen. **It is there to show the room is live**, and its stopping is the whole of
its value. Someone will see the hands freeze on a dead feed, read it as a bug, and fix it with a
10-second timer off the viewer's clock. That edit is
[§ 6.3](#63-forbidden-forms-named-so-they-cannot-be-written-in-good-faith)'s **second** forbidden form,
it re-mints a mover that keeps moving after the feed dies, and it costs
[AT-D3-6](#at-d3-6-the-feed-dying-is-visible-within-45-s) its instrument: with a clock still ticking the
page never goes still, and *the page is still* is how a human reads *the feed is down* before reading
anything. **A frozen clock here is not a defect and not a lie — it is the claim.** AT-D3-6's RED is
that exact edit, so the regression trips a test rather than a review.

**Four things A17's row does not carry on its own. Each is a defect if it is left out.**

1. ⚠ **No second hand: minute resolution only.** [D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed)
   sends `feed.heartbeat` every **15 s**, so a second hand driven by A17 would advance in 15-second
   jumps — and a clock that looks broken is precisely what gets "fixed" with the timer the paragraph
   above refuses. A minute hand steps at most four times a minute and is indistinguishable from
   continuous at floor zoom, which is the resolution this row is sized to.
   [§ 12](#12-every-number-and-where-it-comes-from) carries the 15 s, and it is load-bearing for a
   rendered element now rather than for the feed indicator alone.
2. ⚠ **The clock reads the VIEWER's own clock, and only its *sampling* is event-driven.** It is not the
   server clock, it is **not** corrected by [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s
   `clock_offset_ms`, and it is none of that section's ages or timestamps — every one of those is a
   wire value or a subtraction from one, and this is neither. It is a fact about the viewer's own
   environment, so it is admitted where the client's other non-wire renderings are, at
   [§ 5.5](#55-the-clients-own-narration), under that section's boundary: it is labelled as the
   viewer's own, and it **never becomes a fact about a seat** — no pose, no currency label, no badge.
   What A17 does is fire on a delivered message and **set** the clock to whatever the viewer's machine
   then reads; the viewer's clock is the **value**, never the driver, and § 5.5 says so where a reader
   will meet it.
3. ⚠ **The clock is not an authority on the time and grows no *as of* stamp of its own.** When the feed
   is down the reading is stale by construction — that is the design, not a gap in it. What tells the
   viewer so is the feed-status narration [§ 5.5](#55-the-clients-own-narration) and
   [§ 9](#9-failure-paths-and-their-observables) F1 already require, on the status strip, in words. A
   stamp on the clock face would be a **second** rendering of the one fact those two already carry,
   which [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s one-rendered-form-per-fact rule refuses for
   the same reason it refuses a second wording of an age.
4. ⚠ **First render *sets* the clock and the sky; it does not animate them.**
   [§ 6.5](#65-a-snapshot-never-animates) forbids an **`edge` animation** on a snapshot, a poll, a
   resync, a per-seat fetch or a reconnect — and setting a value on the first paint is not one, exactly
   as § 6.5 already reasons for a held render. So on every one of those the room is **set** to the
   viewer's current time and sky with no step, no transition and **no animation-log row**; A17 fires
   only on a heartbeat thereafter. That is what keeps
   [AT-D3-1](#at-d3-1-no-animation-without-its-event)'s instrument half — *no `edge` row at all* on
   `fx-snapshot-4` — true of a correct client with a clock on its wall.

**Per-seat loop phase, from the appearance seed — and it carries no information.** Loops are
**phase-offset per seat**, so a floor of busy desks does not blink and wiggle in lockstep; the offset
is drawn from the same `(install_id, seat_id)` seed as the character itself
([§ 10.4](#104-the-art-direction-as-a-specification)). **This is stated rather than left as an art
note, because a per-seat offset is precisely the thing a careful reader would suspect of being data.**
It is not: [§ 6.3](#63-forbidden-forms-named-so-they-cannot-be-written-in-good-faith) forbids motion
whose **rate, amplitude or direction** encodes a quantity, and phase is none of the three — the rate
stays [§ 6.1](#61-the-rule-and-what-a-loop-is-allowed-to-mean) rule 2's fixed 4 fps for every loop and
every seat, the amplitude is the row's own, and the offset is derived from **identity**, which does not
change while the seat exists and therefore cannot report anything that does. A phase drawn from a
seat's *state*, its context percentage or its call count would be forbidden by the same rule that
permits this one.

### 6.3 Forbidden forms, named so they cannot be written in good faith

- **Motion that is neither held by a delivered field nor caused by a delivered message — *ambient
  life*.** That property is the rule; the named forms are its examples, and they remain forbidden
  **as** examples: idle breathing, blinking, foot-tapping, coffee sipping, passing NPCs, flickering
  monitors, moving clouds, swaying plants. The reason is unchanged and is the reason the property is
  the right way to say it — **a viewer cannot distinguish such motion at a glance from state-bearing
  motion**, which is precisely what makes the floor readable-at-a-glance in the first place. The cost
  is accepted and stated: a still floor looks still, and a still floor **is** a still fleet.
  **The property is what decides a case the list of names cannot.** A blink that runs on every desk in
  every state is ambient and is forbidden — nothing delivers it, and it is the first name on the list
  above. A blink that runs **only while a `§ 6.2` row's hold condition holds** is not ambient at all:
  it is the drawn form of that row, held by a delivered field, stopping when the field stops, and the
  honesty principle is satisfied by the very mechanism that has always satisfied it. **The bullet is
  therefore sharper than the list it started as, never looser** — it now refuses one motion the list
  never named (any un-held loop, whatever it depicts) and admits none the list forbade except by
  writing it into a row, where a reviewer sees the field that drives it. **The only door in is a
  `§ 6.2` row.** That table stays closed, `tools/design/verify-floor.py` reds when an animation id is
  named anywhere in this document without one, and nothing in this bullet weakens that.
- **Motion driven by a timer.** Nothing may be driven by the 1 s age tick, by a render loop's frame
  count, or by wall-clock time, except a state-held loop's own frames at the fixed rate of
  [§ 6.1](#61-the-rule-and-what-a-loop-is-allowed-to-mean) rule 2.
  **Driven by is not the same as read at, and the wall clock is where the difference is worth the
  sentence.** [A17](#62-the-animation-table--the-closed-set) fires on a delivered `feed.heartbeat` and
  *reads* the viewer's clock for the value it sets, the way
  [A5](#62-the-animation-table--the-closed-set) reads a delivered tool name for the glyph it swaps to.
  A timer that fired A17 every 10 s **would** be this bullet's motion, and it is the specific edit
  [§ 6.2](#62-the-animation-table--the-closed-set)'s note and
  [AT-D3-6](#at-d3-6-the-feed-dying-is-visible-within-45-s)'s RED exist to catch — the clock would keep
  moving after the feed died, which is the property that bullet's *ambient life* sibling above is also
  written to refuse. **The test is what would happen on a dead feed: motion that stops is caused;
  motion that continues was on a timer.**
- **Motion whose rate, amplitude or direction encodes a quantity.** A faster typing loop for a busier
  seat, a gauge that drifts upward between samples, a badge that pulses harder as a counter rises: each
  invents a number the wire never sent.
- **An animation on a snapshot, a poll response, a resync or a reconnect**
  ([§ 6.5](#65-a-snapshot-never-animates)).
- **An animation for a state the client inferred.** In particular: no *finished* animation on
  `open_calls` reaching 0 — [D2 § 10](FLEET-STATE.md#10-worked-example-the-clear-trace-folded-end-to-end)
  is the trace where that inference is wrong, and
  [AT-D3-2](#at-d3-2-the-clear-trace-shows-no-idle-anywhere) is the test that catches it.
- **A transition tween between two states.** A desk changes pose on the frame the delta is applied. An
  interpolation between `working` and `idle` would be rendering a state that never existed.

### 6.4 Reduced motion is a first-class rendering, not a degradation

Under `prefers-reduced-motion: reduce`, every row of
[§ 6.2](#62-the-animation-table--the-closed-set) renders its **reduced-motion form**, and the column is
part of the contract rather than an afterthought: a fact carried only by motion is a fact some viewers
cannot read, and this floor's facts are the whole product.
[AT-D3-13](#at-d3-13-every-state-is-legible-without-motion) asserts that every `render_state` member is
distinguishable with motion off — which also means the floor is legible in a screenshot, which is how
most of it will be reviewed. **That test's population is the ten states, so it does not reach
[A17](#62-the-animation-table--the-closed-set)**, whose room render belongs to no state and whose
driver none of its fixtures deliver;
[AT-D3-6](#at-d3-6-the-feed-dying-is-visible-within-45-s)'s floor half replays the `reduce` condition
over a heartbeat feed and is where that row's form is asserted. Every row of the column is asserted
somewhere; **which test asserts which is stated rather than assumed**, because a column covered by
*one* test is how an uncovered row goes unnoticed.

### 6.5 A snapshot never animates

A snapshot, a poll response, a resync fetch, a per-seat insert and a reconnect all render the world **as
delivered**, with **no `edge`-class animation** ([§ 6.2](#62-the-animation-table--the-closed-set)).
Their arrival is not a claim that anything happened to any seat — it is a claim about what the client
knows. Animating them would put an arrival at every desk on every reconnect, and a fleet that appeared
to walk back in every time the network hiccupped would have made the floor's motion meaningless in
exactly one afternoon. [AT-D3-9](#at-d3-9-the-client-half-of-snapshot-then-deltas) asserts it.

**What a snapshot does do is render the states it delivers, held renders included, and that is not an
exception to the rule above but the other half of it.** A snapshot carrying a `working` seat renders a
working desk, loop and all: the loop is held by a delivered field, not started by a message
([§ 11](#11-acceptance-tests) states what the animation log records for it). The rule is *no edge
animation on a snapshot*, never *no motion after a snapshot* — the second would make the floor go still on every reconnect, and
[§ 6.3](#63-forbidden-forms-named-so-they-cannot-be-written-in-good-faith) is explicit that a still
floor is read as a still fleet.

**And it renders the room, by the same reasoning applied to an `edge` row's value rather than to a
held one's condition.** [A17](#62-the-animation-table--the-closed-set)'s wall clock and sky are **set**
on each of the five renders above — and on a backgrounded tab's return
([§ 9](#9-failure-paths-and-their-observables) F15) — to whatever the viewer's clock then reads, with
no step, no transition and **no animation-log row**, because nothing happened to any seat and nothing
is being claimed. **Setting a value on first paint is not an animation of it**; what § 6.5 forbids is
the *firing*, and A17 fires only on a `feed.heartbeat`. The distinction is the same one this section
already draws for a held render, one class over: a floor that refused to set its clock until the first
heartbeat would show a stopped clock on a healthy fleet for up to 15 s after every reconnect, which is
the feed-down claim made falsely — the exact inverse of the defect A17 exists to prevent.

---

## 7. Degradation — how a degraded seat is unmistakable

### 7.1 The render per state

`render_state` has **ten** members ([D2 § 4.2](FLEET-STATE.md#42-render-precedence)) and every one has a
distinct render. The order below is the fixed order the lobby's per-floor summary uses.

| `render_state` | Desk | Label line | Animation | Never |
|---|---|---|---|---|
| `working` | character at the keyboard | the action's descriptor | A3, or A4 when the turn is open with no call | rendered without its currency treatment when the seat is not `live` |
| `idle` | **character present, slumped asleep on the desk**, monitor dimmed | *finished — nothing done for 4m 12s*, the quiet age in [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s one stated form | A6 | rendered as absent, and **never as an empty desk** ([§ 7.5](#75-what-a-degraded-desk-may-never-look-like)). Idle is a **positive observation**, not a silence ([D2 § 4.4](FLEET-STATE.md#44-activity-states-every-entry-and-exit-edge)) |
| `blocked` | raised hand, marker above the desk | *waiting on a human since 14:31 (seat clock)* | A7 | shown as working, whatever `open_calls` says ([D2 § 4.3](FLEET-STATE.md#43-the-derivation-function): `blocked` outranks `working`) |
| `stalled` | head in hands | *API error — rate limit* | A8 | folded into `unknown`; `api_error_type` is always on the line |
| `unknown` | character present, question marker | one sentence per `unknown_reason` (below) | A9 | rendered as `idle`, and never as seven different desks |
| `catching_up` | character present, replay marker, desaturated | *replaying history — last event 12:47 (seat clock)* — a labelled seat-clock **timestamp**, because the only quantity that would make it a duration is a seat clock subtracted from the server's ([§ 2.4](#24-the-clock-and-every-age-on-the-page)) | A15 | rendered as current work. This is [AT-D2-20](FLEET-STATE.md#at-d2-20-catching-up-is-not-current-and-not-stale)'s rule at the pixel layer |
| `stale` | **empty chair**, desk dimmed | *no data since 14:18 — no data for 11m* — **the worked label line for this state**, derived from [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s **`dark-only`** marker rather than restating it, read at a corrected clock of **14:29**: the timestamp is the version-bearing `delivery.no_data_since` and the ticking age is `delivery.last_receipt_at`. The age is inside this state's own window and not merely large — [D2 § 4.5](FLEET-STATE.md#45-link-states) puts `stale` past 300 s and `offline` past 900 s, so a worked 41m here would have been an `offline` seat wearing the `stale` row's label | none | rendered as `idle`, ever ([D2](FLEET-STATE.md#42-render-precedence) `D2-MUST` #2) |
| `offline` | empty chair, desk dark | *no data since 12:23 — no data for 2h 06m* — the same worked pair for this state, at the same **14:29** so the two rows describe one moment rather than two, silent past 900 s (**`dark-only`** for the age, [§ 2.4](#24-the-clock-and-every-age-on-the-page)). **When `delivery.no_data_since` is null** — the provisioned-never-reported seat [D2 § 4.5](FLEET-STATE.md#45-link-states) rule 1 mints, whose `last_receipt_at` is `NULL` too — the line reads ***no data yet*** alone ([§ 3.4](#34-a-new-seats-first-appearance), [§ 5.6](#56-the-null-render-for-every-nullable-member)), never *no data since null* and never an age beside it | none (A2 played on the way in) | removed from the floor |
| `disabled` | character present, monitor off | *reporting disabled* | none | shown as `offline` — a seat that is off and a seat that is gone must not look alike ([D1 § 6.14](EVENT-SCHEMA.md#614-reporterheartbeat)) |
| `retired` | desk cleared, chair pushed in, plate stamped | *retired 2026-08-20 by aimla-pm — host decommissioned* | A13 | made to vanish ([§ 3.5](#35-retirement-and-the-only-removal)) |

**The seven `unknown_reason` members, each with its sentence** — one glyph, seven explanations, exactly
as [D2 § 4.3](FLEET-STATE.md#43-the-derivation-function) intends ("the *rendering* is one glyph… the
*diagnosis* belongs in the drill-down"):

| `unknown_reason` | Sentence |
|---|---|
| `no_data_yet` | *no data yet — this seat has never reported* |
| `turn_aborted_calls` | *the last turn ended with calls aborted* |
| `turn_killed_by_clear` | *the last turn was killed by a `/clear`* |
| `turn_ended_with_session` | *the last turn ended with its session* |
| `stalled_left_live` | *rate-limited, then the seat went quiet* |
| `stalled_session_ended` | *rate-limited, then the session ended* |
| `session_closed_turn_open` | *the session closed with a turn still open* |

### 7.2 Badges: every member has a render

`badges[]` carries **0…18** members — D1's twelve `degraded` members plus D2's seven server-derived
badges, of which `epoch_reset` is in both
([D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object),
[D2 § 7.2](FLEET-STATE.md#72-this-planes-own-counters-and-badges)). Every member has a render, because a
badge a consumer does not draw is a condition the fleet reports and nobody sees.

| Badge | Origin | Rendered on the desk | Drill-down line |
|---|---|---|---|
| `lossy` | D1 | badge cluster | *events discarded: N* — **the number is always beside it**, per [D1 § 9.3](EVENT-SCHEMA.md#93-degradation-counters) and D2 S28: a loss is never a badge alone |
| `batches_rejected` | D1 | badge cluster | *N batches refused — last status and error code*, the count read from `detail`'s `seat_counters` rows for `batches_refused.<error>` ([D2 § 7.1](FLEET-STATE.md#71-d1s-server-side-counters--where-they-live), which raises **no** server-side badge for them precisely because this one already exists). [D1 § 12.2](EVENT-SCHEMA.md#122-error-responses) also requires the **received and accepted schema versions** to be *readable in its drill-down*; neither is on any read surface, so the panel reads *the refused schema versions are not reported* rather than inventing them ([§ 14](#14-open-questions-for-the-review-loop) item 9, Appendix A U12) |
| `harness_contract_moved` | D1 | badge cluster | *the harness payload moved under this reporter* |
| `reporter_behind` | D1 | badge cluster | *the harness has an enum member this reporter coerces* |
| `value_clamped` | D1 | badge cluster | *a reported value left its declared range and was clamped* |
| `counters_omitted` | D1 | badge cluster | *N counters did not fit the heartbeat* |
| `index_overflow` | D1 | badge cluster | *the seat passed its open-call or open-session index cap, or skipped history when its index journal tail was truncated at 8 MiB* — [D1 § 9.3](EVENT-SCHEMA.md#93-degradation-counters) raises this badge from **three** counters (`open_call_index_overflow`, `open_session_index_overflow`, `index_fold_truncated`) and the line names all three cases, because the third is a seat whose own history is short rather than one that is merely busy |
| `invalid_tool_name` | D1 | badge cluster | *a tool name failed its pattern and was sent as `INVALID_TOOL_NAME`* |
| `bad_session_id` | D1 | badge cluster | *a session id failed its pattern and was sent as null* |
| `config_invalid` | D1 | badge cluster, **and the desk is treated as not-current** | *the reporter's config failed validation; it is spooling and sending nothing* |
| `statusline_degraded` | D1 | badge cluster | *the wrapped status-line command is failing* |
| `epoch_reset` | both | badge cluster | *a new sequence epoch was minted; nothing was discarded* — and the drill-down says which side observed it, because D1's reporter and D2's server raise it independently ([D2 § 7.2](FLEET-STATE.md#72-this-planes-own-counters-and-badges)) |
| `seq_gap` | D2 | badge cluster | *N events the reporter sent did not arrive* — **not** the same statement as `lossy`, and the two are never merged ([D2 § 7.1](FLEET-STATE.md#71-d1s-server-side-counters--where-they-live)) |
| `seq_collision` | D2 | badge cluster | *two events claimed one sequence number* |
| `clock_skew` | D2 | badge cluster | *seat clock is N s from the server's* — rendered beside every seat-clock timestamp in the panel |
| `reporter_ahead` | D2 | badge cluster | *this seat is sending values this server does not know* |
| `fold_lag` | D2 | badge cluster, **and the desk is treated as not-current** | *this state is N s behind the events that produced it* ([§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy)) |
| `derivation_error` | D2 | badge cluster | *an event could not be projected; this seat's state is missing it* |

**D1's twelve are rendered *since reporter start*, never as *now*.**
[D2 § 7.3](FLEET-STATE.md#73-how-the-reporters-own-counters-are-handled) states why: they are raised by
counters monotonic since flusher start, so one dropped event at 09:00 badges `lossy` for the rest of
that flusher's life. The panel renders each with **the counter's value and `reporter.uptime_s`** — a
sticky badge drawn as a current condition would make a seat that had one bad minute look permanently
broken. D2's own seven are current conditions and are drawn as such.

**`badges_since` is one line for the whole cluster, not a stamp per badge.**
[D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object) declares it as *"when the **oldest**
currently-present badge first appeared"*, and
[D2 § 7.3](FLEET-STATE.md#73-how-the-reporters-own-counters-are-handled) item 0 spells out the misuse
in advance: the server keeps a per-badge map `seat_state.badge_first_seen`, *"`badges_since` = the
minimum of the values"*, and *"one timestamp for D1's whole twelve-member array … cannot answer 'when
did **this** badge appear', which is the only question `badges_since` is asked"*. So the cluster
carries **one** line — *oldest badge since 09:14* — and **no badge is stamped with an onset the wire
did not give it**. Stamping each member with `badges_since` would date a `fold_lag` that started
thirty seconds ago to the sticky `lossy` that has been up all day: a misdated degradation, on the one
panel whose job is to date degradations. `badge_first_seen` is a stored column
([D2 § 6.4](FLEET-STATE.md#64-ddl)) and appears in neither
[§ 8.2.1](FLEET-STATE.md#821-the-seat-state-object)'s object nor
[§ 8.2.3](FLEET-STATE.md#823-the-seat-detail-response)'s enumerated `detail` members;
[§ 14](#14-open-questions-for-the-review-loop) item 13 asks D2 to put it on one.

### 7.3 Currency labels: what a non-`live` desk may claim

[D2 § 3.4](FLEET-STATE.md#34-what-this-rule-forbids-concretely) forbids "rendering an activity state
without its currency label when the seat is `catching_up`, `stale`, `offline` or badged `fold_lag`".
The rendering rule:

| Condition | The desk shows | The activity state | Treatment |
|---|---|---|---|
| `link_state == "live"`, no `fold_lag` | its activity render | as the pose | full colour, motion permitted |
| `catching_up` | the replay render (A15) | in the label only, as *was: working (last event 12:47, seat clock)* | desaturated, no working loop |
| `stale` / `offline` | the empty-chair render | in the drill-down only, under *when it went dark* | dimmed |
| badged `fold_lag` | its activity render, plus the fold-lag render [§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy) owns in full | as the pose, explicitly labelled *N s behind* | motion **stops**: a loop implies *now*, and *now* is what the lag denies |
| badged `config_invalid` | its activity render, with the badge and *sending nothing* | as the pose | motion stops, for the same reason |
| `disabled` | the *reporting disabled* render ([§ 7.1](#71-the-render-per-state)) — character present, monitor off | in the label only, as *was: working (last event 12:47, seat clock)* | dimmed, motion **stops**; the seat is still heartbeating, which is how the flag is known at all, but it is sending no activity events, so everything under the label is older than the flag |

**`config_invalid` and `disabled` are this document's own additions to D2's four, and both are named
as ones.** [D2 § 3.4](FLEET-STATE.md#34-what-this-rule-forbids-concretely) lists `catching_up`,
`stale`, `offline` and `fold_lag`. `config_invalid` is a D1 badge meaning the reporter "keeps spooling
and sends nothing" ([D1 § 9.3](EVENT-SCHEMA.md#93-degradation-counters)), and `disabled` is
[D2 § 4.5](FLEET-STATE.md#45-link-states) rule 4's seat whose reporting is switched off — it still
heartbeats, which is the only way the flag is ever learned, and it sends no activity event at all. On
both, everything under the label is by construction older than the condition — the same reading D2's
four get, arriving through the reporter instead of through the transport or the fold. Both are
additions on top of a D2 rule, so [§ 1.3](#13-the-boundary-stated-as-a-rule) obliges the sentence that
adds them; neither needs an amendment from D2, because both constrain only what this document renders.
Leaving `disabled` out was a real hole rather than a tidy omission: it is a `link_state` member, so a
desk could have rendered a `disabled` seat's last known activity as current, which is
[D1 § 6.14](EVENT-SCHEMA.md#614-reporterheartbeat)'s *off must not look like gone* failing in the
other direction.

### 7.4 The frozen fold is the one that could look healthy

[D2 § 2.3](FLEET-STATE.md#23-a-frozen-fold-is-the-dangerous-degradation) names this as the single
degradation that can present as a healthy floor: receipts keep arriving, so the ages look fine, while
every desk shows what it was doing when derivation stopped. Three renders, and they are obligations D2
places on this document by name:

1. **Per seat**, the **`fold_lag` badge** — which D2 raises past 60 s of `derivation.fold_lag_ms`.
   **This section owns the fold-lag render, and every other site in this document points at it rather
   than restating it** — [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s duration table for the
   string, [§ 5.1](#51-the-desk) and [§ 4.3](#43-the-desk-drill-down-panel) for the two surfaces'
   source rows, [§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim) for the currency
   treatment, [§ 7.2](#72-badges-every-member-has-a-render) for the badge's own line. **The whole
   treatment, in one place, is four things and no others:** the **badge**, a **hatched overlay**, the
   **lag line** *this state is N s behind — as of HH:MM:SS*, and **motion stops** — "D3 must not
   present the seat's activity state as current". **That line renders on two surfaces and no
   others**: the **desk**, and only while the badge is up, the line carrying its own inline stamp
   because the number is `fetch-fresh` and there is no block on the desk whose stamp it could borrow
   ([§ 2.4](#24-the-clock-and-every-age-on-the-page)'s stamp rule); and the **drill-down's derivation
   block**, where the same number appears under that block's stamp
   ([§ 4.3](#43-the-desk-drill-down-panel)). Two surfaces, one owner, one string — **and all three of
   those totals are counted over the lag line**, which is what this section owns.
   **[§ 7.2](#72-badges-every-member-has-a-render)'s `fold_lag` row is therefore not a third surface
   for that line, and its wording is not a second string for it:** what that row's drill-down-line
   column carries — *this state is N s behind the events that produced it* — is the **badge's own**
   render, the sentence every badge in that table gets in the **badge cluster**, and it takes no
   *as of* stamp because it states the badge's condition rather than dating a number. Two facts, two
   owners, two strings: the badge's line is [§ 7.2](#72-badges-every-member-has-a-render)'s and the
   lag line is this section's, and neither is a restatement of the other. **The treatment is driven by
   the badge and never by a held `fold_lag_ms`**, and that is the whole of why this degradation cannot
   present as a healthy floor *here*: the fold is the delta emitter, so when it stops, a client's copy
   of the lag freezes at the value it had and can never cross 60 s — while `badges` **is**
   version-bearing and the sweeper delivers it ([D2 § 6.5](FLEET-STATE.md#65-the-fold)). A floor that
   drove this treatment off the number would render the badge beside the line *this state is 0 s
   behind*. The number itself is **`fetch-fresh`** and carries the stamp of the fetch that produced it
   ([§ 2.4](#24-the-clock-and-every-age-on-the-page)).
2. **Fleet-wide**, `fleet.fold == "lagging"`: the derivation indicator changes, no banner.
3. **Fleet-wide**, `fleet.fold == "stalled"` — any seat past 300 s: **a fleet banner** — D2's words — reading *derivation is
   behind: these desks show what seats were doing N minutes ago*, with `fleet.max_fold_lag_ms` in it.

### 7.5 What a degraded desk may never look like

- **Frozen but healthy.** Any desk whose state may not be read as current carries a visible treatment
  and a label; there is no state in which the floor shows a confident pose over uncertain data.
- **Empty.** A `stale` or `offline` desk is a *rendered* empty chair with a *no data since* line, not an
  absent desk. An absent desk and a quiet fleet are the two things D1 § 9.1's rendering clause and
  [D2 § 2.2](FLEET-STATE.md#22-fail-posture-per-path) exist to keep apart.
- ⭐ **Asleep. A sleeping character and a gone seat must never be confusable, and this is a rule rather
  than an art note.** [§ 6.2](#62-the-animation-table--the-closed-set) A6 draws `idle` as a character
  **slumped asleep at its own desk** — and `idle` means *the seat cleanly finished a turn*, which is a
  **positive observation** the fleet made ([D2 § 4.4](FLEET-STATE.md#44-activity-states-every-entry-and-exit-edge)).
  `stale`, `offline` and `unknown` mean the opposite: nobody can say what the seat is doing.
  **So `stale` and `offline` render the empty chair of [§ 7.1](#71-the-render-per-state) — the seat
  itself, with nothing in it — and never a sleeper**, however restful a dark desk looks; and `unknown`
  keeps its question marker over an **occupied** desk, because its seat is present and its last turn
  is what is unreadable. **This is where the honest-looking mistake lives**: *asleep* and *gone quiet*
  are the same picture in most offices, and a floor that drew them alike would turn the one degradation
  the whole of [§ 7](#7-degradation--how-a-degraded-seat-is-unmistakable) exists to surface into the
  most reassuring thing on the screen. The distinction survives motion being switched off, because
  A6's reduced-motion form is the **static slumped pose** and the empty chair has no pose at all
  ([§ 6.4](#64-reduced-motion-is-a-first-class-rendering-not-a-degradation)) — a sleeper is a
  *character*, an empty chair is an *absence*, and neither needs the z's to be told from the other.
  [AT-D3-5](#at-d3-5-a-degraded-seat-is-visibly-degraded) and
  [AT-D3-13](#at-d3-13-every-state-is-legible-without-motion) both assert it, with motion on and with
  motion off. *(The ratified art draws that chair as a cushion — the noun in the render tables is the
  chair, and one noun is what keeps the two documents one document.)*
- **Zeroed.** A null `context` renders *not reported*; a null `task` renders no chip; a null
  `subagents[].title` renders *untitled*; a null `counters` object renders *unreadable*. **A null is
  never drawn as a zero**, because a zero is a measurement and a null is the absence of one — the same
  clean-zero defect `docs/KANBAN.md § G-1` records, arriving through a gauge.
- **Guessed.** An unrecognised member renders as unrecognised
  ([§ 5.4](#54-what-is-never-rendered)).

### 7.6 The three remaining member sets, published so membership is testable

[§ 5.4](#54-what-is-never-rendered)'s unrecognised-member rule and
[§ 9](#9-failure-paths-and-their-observables) F9 are **membership tests**, and a membership test needs a
published set: *"a member the client does not know"* is unimplementable against a set that lives
nowhere, and the first member an implementer would guess wrong is the one this document forgot to list.
[§ 7.1](#71-the-render-per-state) publishes `render_state` (10) and `unknown_reason` (7);
[§ 7.2](#72-badges-every-member-has-a-render) publishes the 18 badges. The remaining three are here.
`tools/design/verify-floor.py` re-derives all six sets from upstream — five from D2, and
`api_error_type` from D1 § 6.4, which is where D2 sources it — and set-differences each against its
table here **in both directions**, so a member D2 gains with no row here, and a row here for a member
no input can select, both red the gate.

**`link_state` — five members**, the member set of
[D2 § 4.5](FLEET-STATE.md#45-link-states)'s cascade, bounded at these five by
[D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object). It is the field the desk's **currency
treatment** is switched on ([§ 5.1](#51-the-desk)). **The rows below carry no order at all, and that
is stated rather than left to be assumed** — there is no *render order* for this field: nothing in
this document iterates `link_state`, the way [§ 7.1](#71-the-render-per-state)'s fixed member order is
iterated by the lobby's per-floor summary ([§ 4.1](#41-the-lobby--the-building-summary)). In
particular the rows are **not** D2's cascade order, which is `offline`, `offline`, `stale`,
`disabled`, `catching_up`, `live`, first match wins. The **set** is the whole of what this table
publishes; the **order** is D2's, is not restated here, and is not what is below:

| `link_state` | What it says about the seat | Currency treatment |
|---|---|---|
| `live` | receipt within 300 s, enabled, not draining | none — the pose may be read as *now* ([§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim) row 1) |
| `catching_up` | the spool is draining: `oldest_unsent_age_s > 300` — D2's own derivation input, **`named-not-rendered`** here | the replay render, desaturated, activity state in the label only ([§ 7.1](#71-the-render-per-state), [§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim)) |
| `stale` | silent past 300 s | empty chair, dimmed, *no data since … — no data for …* ([§ 7.1](#71-the-render-per-state)) |
| `offline` | silent past 900 s, or never reported | empty chair, dark, *no data since … — no data for …* / *no data yet* ([§ 7.1](#71-the-render-per-state), [§ 3.4](#34-a-new-seats-first-appearance)) |
| `disabled` | `enabled == false` — reporting switched off, still heartbeating | *reporting disabled*, monitor off, motion stops ([§ 7.1](#71-the-render-per-state), [§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim)) |

**`activity_state` — five members**, bounded by [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)
and given their entry and exit edges by [D2 § 4.4](FLEET-STATE.md#44-activity-states-every-entry-and-exit-edge).
The desk never switches on this field — it switches on `render_state`, which collapses transport over
activity ([D2 § 4.2](FLEET-STATE.md#42-render-precedence)) — so what each member owes is a render
**under a currency label** when the seat is not `live`. **The *was:* form's parenthetical is
`activity.last_event_time` as a labelled seat-clock timestamp — *(last event 12:47, seat clock)* — and
never an elapsed time**, because the only elapsed time it could carry is a seat clock subtracted from
the server's ([§ 2.4](#24-the-clock-and-every-age-on-the-page)); it is written *(…)* in the rows below
so the form is stated once rather than five times:

| `activity_state` | What it says the seat is doing | Rendered as |
|---|---|---|
| `working` | a turn is open, or a call is | the working render when `render_state` agrees; otherwise *was: working (…)* under the label ([§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim)) |
| `idle` | a turn ended cleanly — a **positive observation**, never a silence | the idle render, or *was: idle (…)* under the label |
| `blocked` | an `attention.request` is open | the attention render, or *was: blocked (…)* under the label |
| `stalled` | the last turn ended on an API error | the stalled render with its `api_error_type` line, or *was: stalled (…)* under the label |
| `unknown` | the last turn record supports no positive reading | the unknown render with its `unknown_reason` sentence ([§ 7.1](#71-the-render-per-state)), or *was: unknown (…)* under the label |

**`api_error_type` — twelve members**, [D1 § 6.4](EVENT-SCHEMA.md#64-turnend)'s set, which
[D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object) cites as *"D1 § 6.4's 12 members"* without
repeating them. Non-null **only** when `activity_state == "stalled"`. The line on a `stalled` desk
renders **the raw value verbatim** ([§ 5.1](#51-the-desk)); this table adds the plain-language phrase
beside it, and a value **not** in this table renders the raw string with the **unrecognised** marker and
the desk is treated as not-current ([§ 5.4](#54-what-is-never-rendered)):

| `api_error_type` | The line beside the raw value |
|---|---|
| `rate_limit` | *rate limit* |
| `overloaded` | *the API was overloaded* |
| `server_error` | *the API returned a server error* |
| `authentication_failed` | *authentication failed* |
| `billing_error` | *a billing error* |
| `invalid_request` | *the request was rejected as invalid* |
| `model_not_found` | *the model was not found* |
| `max_output_tokens` | *the turn hit its output-token ceiling* |
| `oauth_org_not_allowed` | *this organisation is not permitted* |
| `account_on_hold` | *the account is on hold* |
| `unknown` | *the harness reported the error as unknown* — a **real harness member**, not a residue |
| `unrecognised` | *this reporter did not recognise the harness's error member* — D1's coercion target, and the reason `unknown` above cannot double as one ([D1 § 6.4](EVENT-SCHEMA.md#64-turnend)) |

The last two rows are one distinction and it is worth the two lines: *the harness does not know what
went wrong* and *the reporter has never heard of what the harness said* are different facts about
different components, and D1 minted a twelfth member precisely so a consumer would not have to collapse
them.

---

## 8. Interns — subagent rendering, and the cap

A dispatched subagent is an **intern at the side table** beside its seat's desk. The mapping is D1's
and D2's, and this document adds only pixels:

**The side table is the DESK's, and the uncapped intern list is the PANEL's — two artifacts, two
sources, two build steps, and this paragraph is the one place that says which is which.** The side
table renders `subagents[]`, a member of the seat object every desk already holds
([D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)), so it is built with the desk's render map at
[Appendix B](#appendix-b--what-an-implementer-builds-from-this) **step 5** — its two rows are
[§ 5.1](#51-the-desk)'s, and [A10](#62-the-animation-table--the-closed-set) animates it with the rest
of the animation set at step 6. The drill-down's intern list renders the seat-detail response's
uncapped open-call list, which [D2 § 8.2.3](FLEET-STATE.md#823-the-seat-detail-response) calls *"the
drill-down's source"* and puts on the panel's fetch alone, so it is built with the drill-down at
**step 10**. That split is not bookkeeping: it is the whole of [§ 8.1](#81-the-cap-stays-at-8--the-arithmetic-and-the-reason)'s
reason for keeping the cap at 8. An earlier revision of Appendix B named *the side table* at step 10,
which put two [§ 5.1](#51-the-desk) rows and one [§ 6.2](#62-the-animation-table--the-closed-set) row
four steps after the tables that carry them.

| Rendered | Source | Rule |
|---|---|---|
| one stool per open subagent | `subagents[]`, newest first ([D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)) | the array is a **reduction**, not the truth |
| the intern's label | `subagents[].title` | from the `subagent.spawn` event's `title` — the dispatch's own description, **programmatic**, sanitized at the reporter ([D1 § 6.7](EVENT-SCHEMA.md#67-subagentspawn)). ≤ 120 B, one line |
| a **title-less** intern | `subagents[].title == null` | renders **untitled**, with the `call_id` in the drill-down. The spawn was lost; D1 and D2 both call this an honest orphan and forbid inventing a title ([D1 § 6.8](EVENT-SCHEMA.md#68-subagentstop), [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)). **A later `subagent.spawn` for the same `call_id` fills it**, and the label appears then |
| the intern's type | `subagents[].subagent_type` | a small tag beside the label, e.g. `coder` |
| when it started | `subagents[].started_at` | a seat-clock claim, rendered as a **labelled timestamp** and never as *how long it has been running*: the field is the seat's own clock, and the only duration it could yield is a seat clock subtracted from the server's ([§ 2.4](#24-the-clock-and-every-age-on-the-page)). The drill-down carries it in full. There is no server-clock start time for a subagent on any read surface, which is why this row renders no duration rather than inventing one |
| **+N more** | `subagents_open` minus the array's length | appears only when positive. The count is the wire's, never `subagents.length` |
| the full list | the seat-detail response's uncapped open-call list ([D2 § 8.2.3](FLEET-STATE.md#823-the-seat-detail-response)), **selected on `agent_scope == "subagent"` / a non-null `parent_call_id`** — the intern join those labels are stored for ([D2 § 4.8](FLEET-STATE.md#48-what-may-never-mint-a-state)), never a state rule gated on them | the drill-down's intern list is **not** `subagents[]`, is not capped at 8, and is not the seat's own open calls either ([§ 5.2](#52-the-drill-down), [§ 14](#14-open-questions-for-the-review-loop) item 1) |
| arrival and departure | `subagents` in a delta's `changed[]` | animation [A10](#62-the-animation-table--the-closed-set) |

### 8.1 The cap stays at 8 — the arithmetic, and the reason

[D2 § 14](FLEET-STATE.md#14-open-questions-for-the-review-loop) item 9 hands this decision to D3: *"It
is a rendering judgement made in a state document because it bounds the wire object… If D3 wants a
different number the cap moves… Closes it: D3's drill-down design."* **This document keeps 8.**

**The arithmetic, from D2's own measured figures** ([D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object),
[D2 § 8.3.2](FLEET-STATE.md#832-worked-worst-case-delta)), re-derived on every run by
`tools/design/verify-floor.py`:

| Quantity | Value | Source |
|---|---|---|
| worst-case delta at the cap of 8 | **6,112 B** | D2, measured by serializing its published worst-case block |
| per-message bound | **8,192 B** (8 KiB) | D2 |
| spare | **2,080 B** | 8,192 − 6,112 |
| each further subagent element | **263 B** | D2, measured: the element at 262 B plus its comma |
| further elements that fit | **7** | ⌊2,080 ÷ 263⌋ = 7 |
| the cap could reach | **15** | 8 + 7, at a worst-case delta of **7,953 B** (6,112 + 7 × 263) |
| 16 breaches | **8,216 B** | 7,953 + 263, which is **24 B over** the 8,192 B bound |

**So seven more would fit, and the answer is still 8.** Three reasons, in the order that decides it:

1. **The drill-down does not read `subagents[]`.** It reads the seat-detail response, whose open-call
   list is explicitly *"in full (not capped at 8)"*
   ([D2 § 8.2.3](FLEET-STATE.md#823-the-seat-detail-response)). So the panel that would benefit from a
   longer array already has every intern, and **raising the cap buys the drill-down exactly nothing**.
   D2's item 9 says the cap is closed by the drill-down design; the drill-down design is what makes it
   unnecessary.
2. **The only consumer of the array is the floor's side table, where 8 is already past the point of
   reading.** A side table beside a desk is read at a glance for *how many, and doing what*; the
   *how many* is `subagents_open`, which is exact at any number, and the *doing what* is unreadable
   past a handful of one-line labels at desk scale. Fifteen stools is D2's own objection to 64 — "a
   side table rendering 64 interns is a list, not a desk" — at a smaller number.
3. **The 2,080 B of spare is worth more unspent.** It is the margin that lets a future field be added
   to the seat object without touching a version-bearing bound or re-measuring the worst case. Spending
   87 % of it on stools nobody reads, to make a bound that binds at 97.1 % of the message limit, is
   buying a rendering nobody asked for with the headroom the next real change will need.

**What moves this decision**: an observed fleet in which seats routinely run more than 8 concurrent
dispatches *and* the floor's side table proves to be where operators read them. Both halves are
measurable after P3 (`docs/PLAN.md § 3`, card #7342), and neither is measurable now — which is why the
cap is kept rather than raised speculatively.

---

## 9. Failure paths and their observables

**Every row states what the user sees.** "Handle errors appropriately" is a defect in a design document
(D-14), and on a dashboard the failure render *is* the product: a floor that fails quietly is
indistinguishable from a fleet that has gone home.

| # | Failure | Detected by | User-visible observable | Recovery | Never |
|---|---|---|---|---|---|
| F1 | **Feed silent** | no message of any kind for **45 s** ([D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed): 3 heartbeat intervals) | the status strip reads **feed down — polling**, [A14](#62-the-animation-table--the-closed-set)'s pulse has stopped, **the wall clock and the sky have stopped with it** ([A17](#62-the-animation-table--the-closed-set)) — the two rows the heartbeat fires stop together, and a stopped clock is this row's observable in the form a viewer reads before reading anything, which is what [AT-D3-6](#at-d3-6-the-feed-dying-is-visible-within-45-s) asserts. **The clock freezes at the last heartbeat, not at 45 s**: it is already up to 15 s behind on a healthy feed, so the freeze is legible only as it lengthens, and the strip's own words are what make the verdict at 45 s. Every desk keeps its last state and **its quiet age keeps growing** — an age is the corrected server clock minus a timestamp the client holds, so it ticks whether or not anything arrives, which is the point. The `fetch-fresh` values of [§ 2.4](#24-the-clock-and-every-age-on-the-page) do **not** tick: each 10 s poll **re-stamps** them | poll `GET /api/fleet/snapshot` every **10 s** ([D2 § 2.2](FLEET-STATE.md#22-fail-posture-per-path)) and attempt reconnect | claiming *live*. A dashboard that silently degrades from live to polled is one whose age nobody can trust |
| F2 | **Delta gap** — `state_version` jumped | `delta.state_version > local + 1` | no desk-level effect; the status strip's **resyncs: N** increments and the client's event log records the seat ([§ 5.5](#55-the-clients-own-narration)) | `GET /api/fleet/seats/{i}/{s}?resync_from=<last applied>`, apply, continue. The parameter is required: it is the **only** write path for D2's `feed_gap_detected` counter ([D2 § 8.5](FLEET-STATE.md#85-gaps-reconnect-and-why-state_version-is-not-seq)) | applying the delta anyway. A silently divergent desk is permanently wrong on a quiet seat |
| F3 | **Connection closed `resync_required`** — backpressure | the close frame ([D2 § 8.5](FLEET-STATE.md#85-gaps-reconnect-and-why-state_version-is-not-seq)) | **reconnecting** in the status strip; the floor keeps rendering with growing ages | re-run [§ 2.2](#22-connect-snapshot-deltas) from step 1 | blanking the floor while reconnecting |
| F4 | **Snapshot `503 fleet_unavailable`** | the status code and body ([D2 § 2.2](FLEET-STATE.md#22-fail-posture-per-path)) | a full-width statement: **fleet state is unavailable — the store could not be read at 14:23:14**, over a floor that keeps its last state and is labelled *last known good*. On a cold start there is no floor to keep, and the screen says so in words | retry the snapshot with backoff; the socket stays open and will carry `fleet.health` | **an empty office.** This is [D2 § 8.6](FLEET-STATE.md#86-a-deliberately-invalid-exchange)'s forbidden outcome at the render layer |
| F5 | **`fleet.health` with `db: "down"` on connect** | the message D2 sends immediately on connect in that posture | the same statement as F4, and the socket's own indicator stays **connected** — the channel is up and is telling us why there is nothing | wait; D2 will send `fleet.health` again when `db` changes | rendering a connected socket as a healthy fleet |
| F6 | **Any read returns `401`** — session expired, or a token revoked/expired for an operator view | the status code and the `error` code ([D2 § 8.6](FLEET-STATE.md#86-a-deliberately-invalid-exchange)) | a blocking sign-in prompt over the floor; **the floor beneath is dimmed and labelled *not live since HH:MM:SS***, and the socket is closed by the client | re-authenticate, then re-run [§ 2.2](#22-connect-snapshot-deltas) from step 1 | leaving a live-looking floor behind a modal. A frozen floor that still animates is the lie this whole document is written against |
| F7 | **MFA session expires while the socket is open** | it cannot be detected on the socket alone — see the note below | the first REST call the client makes (the 10 s poll under F1, a drill-down open, or a resync) returns `401` and F6's render fires | as F6 | claiming *live* on the basis of a socket whose authorization the client can no longer verify |
| F8 | **`fleet.reload` — `feed_version` changed under a running client** | the message ([D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed)) | a full-width banner: **a new version was deployed — reload to continue**, and **delta application stops immediately** | the user reloads | attempting a compatibility dance. [D2 § 8.1](FLEET-STATE.md#81-two-surfaces-two-compatibility-postures): "it does not attempt a compatibility dance it cannot win" |
| F9 | **An unrecognised enum member** in any state or badge field | the value is not in this document's tables | the desk renders the **unrecognised** glyph carrying the raw string, is treated as not-current, and the client's event log records it once per distinct value | none needed; the value is displayed | mapping it to the nearest known member, or defaulting to a healthy-looking one |
| F10 | **Timeline request fails** (any non-200) | the status code | the drill-down's timeline area reads **could not load recent activity — HTTP N**; the rest of the panel renders | retry on the user's action | an empty timeline, which reads as *this seat did nothing* |
| F11 | **Seat-detail request fails** | the status code | the drill-down opens with the seat object it already holds and the sections that need `detail` read **unavailable**; the intern list falls back to `subagents[]` **and says it is capped** | retry | showing a capped list as if it were complete |
| F12 | **A delta names a seat the client does not hold** | the seat map | none — the client fetches it ([§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold)); the client's event log records *seat added to the floor* | — | applying a shallow-merge patch to a partial object |
| F13 | **Floor map has fewer slots than the install has seats** | `S` against the rendered seat count | the surplus seats render in a labelled **overflow row**, and a persistent notice reads *floor map is short N desks* | an operator edits the map | dropping a seat |
| F14 | **An asset fails to load** — a tile, a sprite sheet | the load error | the desk renders its **placeholder**: a plain rectangle carrying the nameplate, the state label and the badge cluster — every fact, no art — and the status strip reads *some art failed to load* | retry on reload | a blank desk, which reads as an empty office |
| F15 | **The browser tab is backgrounded and returns** | the gap in the age ticker, or a socket the platform closed | on return, the client re-runs [§ 2.2](#22-connect-snapshot-deltas) from step 1 and renders **without animation** ([§ 6.5](#65-a-snapshot-never-animates)) | — | replaying the deltas that arrived while hidden, which would animate a history the operator did not watch |

**F7 is the residual, and it is stated rather than papered over.**
[D2 § 9](FLEET-STATE.md#9-read-side-authentication) refuses machine tokens on the socket precisely
because "a long-lived socket authenticated by a bearer token needs a revocation story *on an
already-open connection*" — and the browser's session-authenticated socket has the identical property,
which D2 does not address: the handshake is authorized once, at
`/broadcasting/auth`, and nothing re-checks it. The client therefore cannot detect expiry on an
otherwise-silent socket, and this document does not invent a re-authorization mechanism to hide that.
What it does instead is make the *claim* conditional: the status strip reads **live** only while the
client has both a fresh feed message and a REST response newer than the last `401`, and any `401` on
any surface tears the socket down. [§ 14](#14-open-questions-for-the-review-loop) item 5 is the request
to D2 for the rule that would close it properly.

---

## 10. Art and assets — provenance as a gate

**Decision D-07** (`docs/PLAN.md § 0`): *floor art from CC0 tilesets; characters ported from
munder-difflin's procedural generator (MIT, with attribution). The upstream's commercial tilesets are
never vendored.* This section turns that decision into obligations an implementer can fail.

**D-07's last sentence is untouched and always will be; its middle clause was superseded on
2026-08-27.** The operator ratified a new art direction ([§ 10.4](#104-the-art-direction-as-a-specification)),
under which the ported pixel generator is **interim placeholder art** and the product ships
**original, high-resolution, resolution-independent** art of its own. `docs/PLAN.md § 0`'s register
carries the supersession as an append beside D-07 rather than as an edit to it, because a register
records what was decided when. **What this does to this section is one thing and it is the whole of
§ 10.1's change:** **Gate 2** used to enforce that the character tree held **no art at all**, which was
the mechanised form of *the sprites are generated*. Art now ships as files, so an absence is the wrong
assertion — and the right one is not a weaker version of it but a different one: **no asset without
declared provenance.**

### 10.1 The manifest, and the two gates

Every asset file in the repository has a row in **`docs/ATTRIBUTION.md`**, and the row carries all of:
*(The directory is `docs/` because `docs/PLAN.md § 0` **D-09** reserves the repository root for AI-parsed
`CLAUDE*.md` files and puts human-readable documents under `docs/`; `README.md` already writes the path that
way. Written in full here because this document names `resources/characters/LINEAGE.md` in full and the bare
filename beside it read as a root path — card#7340.)*

| Column | Example | Why it is required |
|---|---|---|
| path | `resources/floor/tiles/office-16.png` | the row's subject |
| **origin** | `first-party` | **where the asset came from, as a value from a closed set of exactly two** — see below. It is the column that makes *"where did this picture come from"* a membership test rather than prose a reader has to interpret |
| source URL | `https://opengameart.org/content/…` | where it came from, checkable by a human. Its **meaning depends on `origin`**, which is the point of typing that column |
| author | *(as the source states)* | the attribution obligation's subject |
| licence identifier | `CC0-1.0` | an SPDX identifier, not prose. "Free to use" is not a licence |
| retrieved | `2026-08-23` | a licence can change; the row records which one was accepted |
| SHA-256 of the file as vendored | `a3f1…` | so a later edit or replacement of the bytes is visible without re-reading the source |

**`origin` is a closed set of exactly two, and a row outside it fails the build:**

| `origin` | What it claims | What the row must then carry |
|---|---|---|
| **`first-party`** | drawn or written **for this repository** | the source URL is an **in-repo reference** — the repository's own URL, which is what the existing first-party rows already write — and the author is this repository's contributors. **The URL half is the gated half**, and it is the only one: a row claiming `first-party` while pointing at somebody else's URL contradicts itself, and that contradiction is the one a machine can catch. Its licence is ordinarily the repository's own (`MIT`), and that is a **convention, not a check** — the closed allowlist already bounds it, and a gate demanding `MIT` here would red on an original asset somebody deliberately dedicated `CC0-1.0`, which is strictly more permissive and harms nothing. Stated rather than implied, because a rule presented as gate-enforced when it is not is worse than one honestly labelled |
| **`licensed`** | obtained from **outside** | a genuine **external** source URL — not this repository's — an SPDX identifier in the closed allowlist, and a `retrieved` date |

A row with **no** `origin`, or an `origin` outside those two, fails Gate 1 by name. The set is closed
for the same reason the licence allowlist is: a third value invented at a call site is a value nobody
decided, and *"where did this come from"* answered in free text is a question nobody can re-ask a year
later.

**Gate 1 — every asset has a row, and the row says where the asset came from.** The build fails when
any file under the asset trees has no `docs/ATTRIBUTION.md` row, when a row's SHA-256 does not match
the file, when a row's licence is outside the closed allowlist, or when a row's `origin` is missing,
outside the set, or contradicted by the row's own source URL. A missing row is an asset whose licence
nobody recorded, which is the only way an incompatible asset ever ships.

**Gate 2 — every asset is a file Gate 1 can see.** This is the gate that changed, and it changed its
**assertion**, not its strictness. It used to assert an **absence** — no image file under the
character tree at all — which was the mechanised form of *the sprites are generated*. Under
[§ 10.4](#104-the-art-direction-as-a-specification) art ships as files, so that assertion is simply
false about the product being built, and a gate asserting something false is worse than no gate: the
next reader trusts its green. What replaces it is **not** a relaxed version of the absence. It is the
claim Gate 1 needs in order to mean anything — *every asset is a file with a path, so every asset has
a row*. Two clauses, and the **allowlist shape** the old text argued for at length is unchanged and is
the reason this gate is still worth having: a denylist can only refuse the copies someone thought to
enumerate, and every classifier written as a list of image extensions passes the format nobody
anticipated. So the gate is an **allowlist**, and it fails on anything neither clause admits:

1. **File types.** Every file under the character tree carries one of **`.ts`, `.js`, `.md`, `.svg`,
   `.png`** — and each member is here for a stated reason, because an allowlist whose members have no
   reasons is a denylist that has not noticed yet:
   - **`.ts`, `.js`** — the generator's source. The seed machinery survives the art change unchanged
     ([§ 10.2](#102-characters-the-munder-difflin-port)); appearance is still computed from the key.
   - **`.md`** — the lineage file.
   - **`.svg`** — the ratified direction is **vector-first**, and vector is what
     [§ 4.5](#45-the-viewport-rule-and-the-capability-floor)'s resolution-independence requirement
     actually needs. It is also **text**, so clause 2 can read inside it, which no raster format
     permits.
   - **`.png`** — the one raster admitted, for artwork that genuinely cannot be vector. Lossless, so
     an asset is not re-encoded into a worse copy of itself on each pass through a tool; universally
     decodable with no pipeline of its own; and already the format [§ 10.3](#103-the-floor-map)'s
     Tiled tilesets ship in, so admitting it adds no decoder the floor did not already need.
   - **Everything else fails the build, named** — `.avif`, `.webp`, `.jpg`, `.psd`, `.dat`, and the
     extension nobody has thought of. `.webp` and `.avif` are refused not because they are bad but
     because **a second raster format buys nothing and doubles the surface**; `.jpg` because lossy
     re-encoding of original art is a quality loss discovered after it ships; `.psd` and `.dat`
     because a working file and an unnamed blob are not deliverables.
   - **Widening this list is a change to this section**, made here with a reason beside it, and it is
     *not* the licence allowlist and not an operator gate — it is a format decision, whose cost when
     wrong is a build reddening on correct work. That is why every member above carries its argument:
     so the next person can disagree with the argument rather than with the list.
2. **Embedded bytes, and its purpose is sharper now than when it was written.** No file under the
   character tree carries a `data:image/` URI or a single base64-shaped literal longer than
   **1,024 B**. It used to exist because clause 1 could not see image bytes pasted *inside* a file it
   admitted. Now that images are admitted as files, its job is the load-bearing one: **an asset
   embedded inside another file has no path of its own, therefore no manifest row, therefore no
   provenance** — and it is invisible to Gate 1 by construction, because Gate 1 walks paths. Clause 2
   is what forces every asset to be a file Gate 1 can see. It is also why `.svg` being text matters
   twice: an SVG that inlines a `data:image/png;base64,…` blob is a raster asset wearing a vector
   file's extension, and clause 2 is the only thing in this document that can tell them apart.
   ⚠ **The clause must not fire on legitimate SVG.** Path data (`d="M12.5 3.2c-1.1…"`) is long,
   mixed-case and full of digits, and a base64 heuristic loose about its alphabet reds on a complex
   first-party drawing — **and a gate that reds on correct work gets switched off**, which costs more
   than the gate ever bought. The base64 alphabet is `A–Z a–z 0–9 + / =` and nothing else; path data
   is broken up by `.`, `-`, `,` and spaces, so a run of it is not a run of that alphabet.
   `bin/asset-provenance.selftest.py` carries the discriminating pair — **a genuinely complex
   first-party SVG passes, and an SVG with an inlined `data:image/…` blob fails** — because either
   half alone is not evidence.

**What this change COSTS, named rather than left to be discovered.** The old Gate 2 was
**self-verifying**: it asserted an absence, and an absence needs no truthful claim from anybody — the
gate could see for itself that the tree held no art. The new one rests on a **row being true**. An
implementer who vendors somebody's commercial art, drops it in as `.png` and writes `first-party` /
`MIT` in its row **passes both gates**. That is a real loss of assurance and it is not recovered by
anything below; what stands in its place is weaker and is worth naming exactly:

- the **closed licence allowlist**, which refuses the honest-but-incompatible case even when the row
  is true;
- the closed **`origin`** set with its per-value checks, which cannot detect a lie but can detect an
  **inconsistency** — the vendored asset whose author cell names somebody outside this project, or
  whose source URL is external while its origin says `first-party`;
- the lineage file's *what was deliberately not taken and why*
  ([§ 10.2](#102-characters-the-munder-difflin-port)), which is a human statement and is the only
  artifact here that addresses intent at all;
- the **IP line** ([§ 10.5](#105-the-ip-line--stated-and-unenforceable-by-gate));
- and **review**, which is now doing more of the work than it was and should be told so.

The honest summary is that these gates now prove **an asset was declared**, not that the declaration
was true. That is a smaller claim than the one they used to make, and the reason to take the trade
anyway is that the alternative — a gate that keeps asserting an absence the product no longer has —
proves nothing at all while looking exactly as green.

**The residue is named rather than implied.** Neither clause can refuse a generator that *fetches*
upstream art at run time — nothing that inspects a tree can. That is refused by the lineage file's
*what was deliberately not taken and why* ([§ 10.2](#102-characters-the-munder-difflin-port)) and by
review, and it is said here so nobody reads Gate 2 as a proof that no upstream pixel can reach the
screen.

**The licence allowlist is closed: `CC0-1.0` and `MIT`.** Anything else — `CC-BY-*`, `CC-BY-SA-*`,
any `-NC` or `-ND` term, "free for personal use", or an asset with no stated licence — is refused by
Gate 1 and is an **operator decision to widen**, never an implementer's. The repository is MIT (D-02)
and public (`PupFuzz/mezzanine`), so an asset whose terms are stricter than the repository's is a term
the repository cannot honour. **This is the one allowlist in this document the amendment did not
touch**, and it is stated here, once, rather than restated beside the `origin` set and again beside
the file-type list.

### 10.2 Characters: the munder-difflin port

**The port is MACHINERY, not the look. Read this subsection as answering one question — *what did the port actually buy, now that its art
is not the product's art?*** The answer is *the seed machinery and the generator algorithm*, and
nothing in the port's licence work is undone by the art direction changing.

- **What is ported is the generator, not its art.** The upstream project ships a procedural character
  generator under MIT *and* commercial tilesets under terms that do not permit redistribution. This
  document's requirement is that the port takes the **algorithm and the MIT-licensed source only**.
  *(This bullet used to end "…and that the character tree contains no image file at all". That
  sentence was true of the pixel-art design and is false of the ratified one; it is gone from here,
  from Gate 2, from [AT-D3-12](#at-d3-12-asset-provenance-gates-bite), from `docs/ATTRIBUTION.md`,
  from `resources/characters/LINEAGE.md` and from `bin/asset-provenance.py`'s module docstring, which
  is the whole of the population that carried it.)*
- **The port's pixel art is INTERIM PLACEHOLDER art**, superseded by
  [§ 10.4](#104-the-art-direction-as-a-specification). It renders today, from the seat key, in a plain
  browser; it is not the look this product ships. **No rework of card#7340's lineage or licence work is
  owed by that** — the obligations below are obligations of the *port*, and the port is still here.
- **The identity property is unchanged and is the load-bearing half.** A character's appearance is
  derived from `(install_id, seat_id)` ([§ 3.1](#31-the-keys-and-why-they-are-the-only-ones)), so a
  seat looks the same on every browser and every reload **with nothing stored** — the same property,
  and the same reasoning, as the desk slot function. That property belongs to the *seed machinery*,
  which the art direction does not touch: [§ 10.4](#104-the-art-direction-as-a-specification) changes
  what is drawn, never what selects it.
- **The port carries a lineage file** — `resources/characters/LINEAGE.md` — recording the upstream
  repository URL, the **commit SHA** the port was taken from, the files ported, the MIT copyright line
  and licence text as required by MIT, and, explicitly, **what was deliberately not taken and why**.
  The last item is the one that makes a later reader able to tell a port from a fork, and it is
  **unchanged in every particular** — it is also, now, one of the few things standing where Gate 2's
  absence used to stand ([§ 10.1](#101-the-manifest-and-the-two-gates)).
- **The MIT notice ships with the distribution**, in `docs/ATTRIBUTION.md` and in the lineage file. MIT's
  obligation is to reproduce the copyright notice and permission notice; a link is not a reproduction.
- **The upstream repository and commit are recorded** — closed by card#7340 on 2026-08-25 and carried
  in the two files above ([§ 14](#14-open-questions-for-the-review-loop) item 7's generator half).
  What is still open there is the **tileset**, not the generator.

### 10.3 The floor map

- **Tiled** (`.tmx`/`.tmj`) is the map format, per `docs/PLAN.md § 3`'s P3 acceptance line ("CC0 tiles;
  Tiled map"). It is the one format choice this document makes, and it is inherited from the plan
  rather than minted here.
- The map declares an **object layer named `desks`** whose objects are the slots of
  [§ 3.2](#32-the-desk-slot-function), and `S` is their count in `id` order. The shipped `aimla` map
  declares **12**.
- The map declares nothing about state. No slot is bound to a `seat_id`, because a map that named seats
  would be a second home for identity and would have to be edited every time a seat is provisioned.

### 10.4 The art direction, as a specification

**This subsection exists because until it did, the only carrier of the ratified look was an
artifact — `docs/design/floor-preview/`, ratified by the operator on 2026-08-27 — and an artifact is
not a specification.** A reference artifact answers *what does it look like*; it cannot answer *what
may I change*, and an implementer holding only D3 (the standalone-implementer standard, D-14) could
read every pixel of it and still not know which of them are rulings. Every bullet below is an
**operator ruling** of the 2026-08-26 / 2026-08-27 sessions, not a suggestion, and the reference
artifact is the worked example of it.

- **Visual target: high-resolution, whimsical, modern, warm** — Ghibli-adjacent in *feel*, never in
  content ([§ 10.5](#105-the-ip-line--stated-and-unenforceable-by-gate)). **Not pixel art.** The look
  must be **resolution-independent**, which is not a style note but the capability
  [§ 4.5](#45-the-viewport-rule-and-the-capability-floor) requires and the reason `.svg` heads
  [§ 10.1](#101-the-manifest-and-the-two-gates) Gate 2's admitted formats: the camera zooms from a
  whole building to one desk, and art that resamples on the way is art that is wrong at every zoom but
  one.
- **The seeded appearance space, and it is a space rather than a palette.** Appearance is drawn from
  `fnv1a32(install_id, seat_id)` ([§ 3.2](#32-the-desk-slot-function)'s hash, already published here),
  one independent draw per field, across **ten** dimensions: **7** silhouettes × **16** hues ×
  **5** size buckets, plus pattern (**3**), ears (**4**), sprout (**5**), eye style (**4**), mouth
  (**4**), accessory (**5**) and posture tilt (**3**). The operator's ruling is what makes this a
  requirement rather than a flourish — *"we need more different characters, not just different colors.
  Each agent and subagent needs their own appearance and personality"*, and then *"the AIMLA floor has
  a repeated body. Be more creative on the different bodies and colors."* **Colour alone is not
  variety**, and a body repeated across a floor is the defect that ruling names.
- **Interns seed from the parent seat plus the intern index** — the key `seat~internN` — so siblings
  at one side table differ from each other and from their seat
  ([§ 8](#8-interns--subagent-rendering-and-the-cap)). One sprite **per open subagent**; the cap and
  its arithmetic are § 8.1's and are **not** changed by anything here.
- ⭐ **The salt is a design choice, and this is the rule that must survive this document's author.**
  The per-field salts (the reference's `s18` for silhouette, `s3` for hue) were **searched against the
  real roster** so that the known fleet renders all-distinct bodies and hues. **Determinism is
  untouched** — one salt, picked once, fixed forever; the function stays pure and the appearance stays
  a function of the key alone. **If the operator reports visible repetition, the response is to widen
  the space or re-pick the salt — never to special-case a seat.** A special-cased seat is a *stored
  appearance wearing a disguise*: it breaks [§ 3.1](#31-the-keys-and-why-they-are-the-only-ones)'s
  property that two browsers agree with nothing stored, and it breaks it invisibly, because the seat
  that was special-cased looks right on the machine where the special case lives.
- **The collision acceptance is MEASURED, not asserted.** Full-tuple appearance collisions must be
  vanishingly rare at fleet scale — call it **50** seats. What can be computed from the reference's
  own field cardinalities is the size of the space: **8,064,000** distinct tuples
  (7 × 16 × 5 × 3 × 4 × 5 × 4 × 4 × 5 × 3), and, **as an estimate that assumes the ten draws are
  independent and uniform**, a birthday expectation of **1** collision in about **6,583** fleets of
  50 seats. **That estimate is not the acceptance.** The assumption it rests on is exactly what a
  *searched* salt perturbs, and a searched salt is what the bullet above requires — so the figure the
  build owes is a **measurement**: run the shipped generator over the real roster and over a synthetic
  roster at 50 seats, count full-tuple collisions, and record the count with the roster it was
  measured against. State the measurement; do not restate the estimate as though it were one.
- **The seeded vibe line.** A short flavour line in the drill-down, drawn from the same seed — the
  operator's *"each agent and subagent needs their own appearance and personality"*. It is
  **appearance-class text**: [§ 5.4](#54-what-is-never-rendered) admits it as a rendering of identity
  rather than a fact about state, and [§ 5.5](#55-the-clients-own-narration) binds it with the rule it
  shares with the client's own narration. Concretely, three obligations, and the first is the one a
  reader arriving from § 5.4 must find here too: **it is labelled on the page as seeded flavour**, so
  nothing about it can be mistaken for wire data; it **never drives a pose, a currency label, a badge
  or an animation**; and it **never changes with state** — a line that moved when `render_state` moved
  would be state-bearing text with no field, which is the defect § 5.4 exists to refuse. **Vibe
  collisions between seats are expected and fine** (the list is short and the line is flavour);
  **appearance** collisions on the full tuple are not, which is what the bullet above measures.
- ⭐ **The LIVE WALL CLOCK and the day/night SKY are admitted — driven by `feed.heartbeat`, which is
  the operator's ruling of 2026-08-27 on card#7341 and is [A17](#62-the-animation-table--the-closed-set)'s
  row.** The reference moves clock hands and re-renders the sky on a **10-second interval from the
  viewer's local clock**, and *that* form stays forbidden: it is
  [§ 6.3](#63-forbidden-forms-named-so-they-cannot-be-written-in-good-faith)'s **second** forbidden
  form, motion driven by wall-clock time, and that bullet is not widened by this ruling. What changed
  is the **driver**, not the rule. **The three options the ruling chose between are recorded at
  [decision 21](#13-decisions-taken-revisable-at-review)**, which is where the reasoning lives; what
  belongs here is what the art direction may draw. The clock and the sky are **elements of the room**,
  drawn where a floor's room is drawn — the floor screen ([§ 4.2](#42-the-floor)); the lobby's plates
  carry a summary rather than a room and draw no clock at all, and § 4.1 says what governs their sky
  if they have one — and they **step on each delivered heartbeat**, so on a dead feed they stop with the rest of the page. **A build must not
  ship the reference's interval verbatim**, and must not add a second hand: both are
  [§ 6.2](#62-the-animation-table--the-closed-set)'s to state and the row's four constraints say why.
- **What is deliberately NOT specified here:** the palette's hex values, the drawing itself, the file
  layout of the art, and the renderer. [§ 1.2](#12-non-goals--stated-so-an-implementer-cannot-widen-scope-in-good-faith)'s
  non-goal stands — **no framework, bundler or state library is specified**, and this subsection does
  not sneak one in by naming SVG: SVG is a *file format on the asset side*, admitted by
  [§ 10.1](#101-the-manifest-and-the-two-gates) and required by
  [§ 4.5](#45-the-viewport-rule-and-the-capability-floor)'s property, not a rendering stack.

### 10.5 The IP line — stated, and unenforceable by gate

**Verbatim operator direction (card#7898):** drawing **from** the Pokémon or Ghibli aesthetic is fine;
shipping actual Pokémon or Ghibli **characters** is IP infringement and never ships. Generalised,
because the franchise named is an example and not the rule: **no character owned by another
rights-holder ships in this product, whatever the franchise, however transformed the drawing.**
Original creatures only — or commissioned or licensed art carrying its own provenance row
([§ 10.1](#101-the-manifest-and-the-two-gates)).

**No gate can test this, and saying so is part of stating it.** Nothing in
`bin/asset-provenance.py` — nothing that inspects file types, hashes, licence identifiers or an
`origin` column — can look at a drawing and recognise somebody else's character in it. A row reading
`first-party` / `MIT` over a traced Pikachu passes every check in this document. **Its enforcement is
review**, by a human who looks at the picture, and that is the whole of it. This is written down
rather than left implicit for the reason [§ 10.1](#101-the-manifest-and-the-two-gates)'s cost
paragraph gives: **a rule presented as gate-enforced when it is not is worse than one honestly
labelled**, because the next reader sees a green build and concludes the question was asked.

---

## 11. Acceptance tests

Each test names **what to build, what to break to make it RED, and what GREEN asserts.** A test never
seen to fail is not evidence; it is a decoration that reports the harness ran.

**A test is gated at or after the step that builds every artifact its GREEN reads. This section owns
that rule; [Appendix B](#appendix-b--what-an-implementer-builds-from-this) is the build order it runs
over and states no version of it, and [§ 12](#12-every-number-and-where-it-comes-from)'s G5 row
describes the gate rather than restating the rule.** It is scoped to the **GREEN** deliberately: a RED
plants a defect and a discriminating control proves the gate can report clean, and both may reach for
an artifact the GREEN does not depend on — it is the GREEN that has to be *true* at the step, so it is
the GREEN that fixes the step.

**How the rule is made checkable, because for two revisions it was stated over every artifact and
enforced over one.** Three populations, none of them written into the tool:

- **The artifacts, and the step that builds each,** are the **bold** names in Appendix B's own Artifact
  cells. An artifact named at two steps has no step and reds.
- **What a test reads** is declared by the test, in a **`Reads:`** clause on each of its `Build`
  bullets, naming artifacts by those same names. Whether a GREEN sentence *reads* the desk or merely
  stands on it is a reading of prose, not a grep — so the reading lives here, where a reviewer can
  disagree with it, and the gate holds the arithmetic over it. A test that declares nothing reds; a
  test that declares a name Appendix B does not use reds.
- **Which half a gate gates** is the qualifier in Appendix B's Gate cell: `AT-D3-6 (floor half)` gates
  that half alone, and an **unqualified** mention gates the **whole** test — every half of it. That is
  what stops a late gate from covering for an early one: being listed again at step 10 does not
  discharge a step-3 mention, because an unqualified step-3 mention claims the whole test is runnable
  at step 3.

1. **A test with two halves does not pick one: it splits, and each half is named at its own step in
   Appendix B's Gate cell** — with the drill-down as the case this rule was first written for, and
   **eight more, enumerated in [Appendix B](#appendix-b--what-an-implementer-builds-from-this)'s note
   on order**, found once the check stopped being drill-down-shaped, and they took **three**
   mechanisms: five resolved by splitting, one by re-gating, and two by **moving the artifact** to the
   step that actually builds it — because a gate stands on nothing either when the test reaches ahead
   of the build order or when the build order files the artifact in the wrong row. The count is stated
   as its enumeration's length rather than beside it, because a
   summary figure that disagrees with the list under it is the defect this document has already
   shipped twice. A gate on an artifact that does not
   exist yet is a gate an implementer either skips or satisfies by building out of order — and out of
   order is the one thing Appendix B exists to prevent. Where no half of a test is observable before
   its artifact, the test is **re-gated** rather than split, and says so; and where the artifact is
   the thing in the wrong row, the **artifact moves** and neither the test nor its gate changes.
2. **The build-order consequence of the record's ownership** — which is
   [§ 5.5](#55-the-clients-own-narration)'s to state and this corollary's to apply. Because the record
   is the client protocol's artifact and the lobby merely renders it, a protocol test may assert a line
   in the record at [Appendix B](#appendix-b--what-an-implementer-builds-from-this) step 3, before the
   lobby exists at step 9 — which is what [AT-D3-7](#at-d3-7-a-delta-gap-resyncs-exactly-one-seat),
   [AT-D3-11](#at-d3-11-an-unrecognised-member-renders-as-unrecognised) and
   [AT-D3-17](#at-d3-17-a-seat-the-client-does-not-hold-is-fetched-never-patched) do, and they say
   *the client's event log* rather than *the lobby log* so that the artifact they read is the one
   their step builds. Seven further sites still said *the lobby log* where the record was meant, and
   [§ 12](#12-every-number-and-where-it-comes-from)'s row for its length was titled after the renderer
   too; none of them does now, and `tools/design/verify-floor.py` reds on the wording anywhere it is
   **used** rather than quoted. That is not cosmetics: the name is what tells an implementer which
   step owes the artifact, and this class has been re-minted twice already.

**The harness.** A headless client — the real client code, with the real message-application path —
driven by **fixture scripts**: a snapshot object followed by an ordered list of feed messages and
simulated clock advances, with the HTTP surfaces stubbed to return stated responses. No server is
required and none of these tests needs one, which is deliberate: they gate the client's honesty, and
the state layer's honesty is already gated by D2's own twenty-three.

**The animation log is the instrument, and it is a build requirement, not a test fixture.** The
renderer **must** record, for every animation it starts, every held render it enters and every held
render it leaves, a row of
`(animation_id, episode_id, install_id, seat_id, class, phase, cause, motion, at)`. The six fields
below take their meaning from the row's **class** and, on a `held` row, from its **phase** — because a
held render's entry and its exit are opposite facts and a schema that gave them one shape made this
document's own headline test unsatisfiable on every exit row.

**This section owns the animation-log schema — the row tuple, what each field means per class, and the
episode that pairs an exit with its entry. [§ 6.2](#62-the-animation-table--the-closed-set) owns the
`edge`/`held` split itself and [decision 20](#13-decisions-taken-revisable-at-review) records the call;
neither restates what is below.** `episode_id` is what pairs an exit with its entry, and it is the
third revision of this schema because the first two had nothing that could. An **episode** is one
continuous run of one render on one seat: the renderer mints a fresh `episode_id` each time it starts an animation or enters a held
render, and writes that same id on the `left` row that ends it. `(animation_id, install_id, seat_id)`
is **not** unique per episode and never was — on this document's own headline fixture,
`fx-clear-trace`, A4 is entered **twice** on `aimla-pm` (the walk is below), so that triple names two
entries and two exits with nothing to say which pairs with which. Two properties follow and are
asserted in [AT-D3-1](#at-d3-1-no-animation-without-its-event): an `episode_id` appears on **at most
one** `entered` row and **at most one** `left` row, and a `left` row's `episode_id` **must** match an
`entered` row that precedes it.

| Field | On an `edge` row | On a `held` row, `phase: entered` | On a `held` row, `phase: left` |
|---|---|---|---|
| `episode_id` | a fresh id, unique to this firing — an edge animation is an instant, so its episode is one row long and no `left` row ever carries this id | a fresh id, minted on entry | **the entering row's id**, repeated — this is the only field the two rows of one episode share by construction, and it is what makes *for how long* recoverable |
| `class` | `edge` | `held` | `held` |
| `phase` | **`fired`**, always — an edge animation is an instant, so it has exactly one row and no exit | `entered` | `left` |
| `cause` | the id of the **wire message that caused it** — a `seat.delta`'s `state_version`, a `feed.heartbeat`, a `seat.retired`, or the seat-set change of [A16](#62-the-animation-table--the-closed-set), recorded as the arriving seat's key. **An edge animation started with no causing message writes `null`**, which is what makes [AT-D3-1](#at-d3-1-no-animation-without-its-event) able to fail | the **`state_version` of the seat object the render is held by** — the object the client holds, whether it arrived by delta, snapshot, resync or per-seat fetch. **A held render entered against no held object writes `null`**, which is the same defect one class over: a render with nothing delivered behind it | the **`state_version` of the object that ENDED the hold** — the first object the client applied in which that row's hold condition is false. Never the entering version: two rows identical in every field are two rows from which *which states, and for how long* cannot be recovered, which is the whole reason the exit row is written |
| `motion` | `true`, or `false` when [§ 6.4](#64-reduced-motion-is-a-first-class-rendering-not-a-degradation)'s reduced-motion form is what was drawn | `true` while the loop runs; `false` when the held render is drawn static — the **two** states with no motion by design (`stalled` and `unknown` — `idle` was the third until [A6](#62-the-animation-table--the-closed-set) gained its sleeping loop), a loop stopped by a currency treatment ([§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim)), or reduced motion | **`false`**, always — nothing is drawn by a render that has been left, so an exit row is never evidence that motion ran |
| `at` | the **corrected server-clock instant** the row was written ([§ 2.4](#24-the-clock-and-every-age-on-the-page)'s offset, applied) — the client's own record of when it drew this, labelled as the client's own and rendered on no screen | as `edge` | as `edge` |

A **held** row is written when the render is **entered** and again when it is **left**, so the log
records which states a desk held and for how long — and *for how long* is
`left.at − entered.at` **for the two rows sharing an `episode_id`**, which is why `at` is on the row
at all. A version pair cannot answer it: `state_version` counts changes, not seconds. Neither can the
`(animation_id, install_id, seat_id)` triple an earlier revision paired on: a desk that enters,
leaves and re-enters one render — which `fx-clear-trace` does to A4 twice over — writes two entries
and two exits under one triple, and *for how long* over that set is a question with two answers and no
way to choose. The episode is the unit the question is asked about, so the episode is what the log
identifies.
**Why `motion: false` rather than no row at all:** a loop that was
stopped by a currency treatment and a render that was never entered are the same absence in a log that
records only starts, and they are opposite facts — the first is [§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim)
working, the second is a desk that never reached the state. A renderer that cannot produce this log
cannot be shown to obey the honesty principle, and the principle is the product's headline claim.

**Fixtures.** Nine, shared by the tests below:

| Fixture | Contents |
|---|---|
| `fx-snapshot-4` | [D2 § 8.2.2](FLEET-STATE.md#822-worked-snapshot)'s snapshot, extended to the four `aimla` seats of [§ 3.2](#32-the-desk-slot-function)'s worked assignment. **All four are `link_state: "live"`**, because D2's own `fleet` block in that snapshot reads `"seats_total": 4, "seats_live": 4` and [D2 § 8.2.4](FLEET-STATE.md#824-the-fleet-health-object) defines `seats_live` as `link_state == "live"` — so a non-live seat here would contradict the fixture's own fleet object. The three D2 does not publish are stated here rather than left to the builder, because [AT-D3-1](#at-d3-1-no-animation-without-its-event)'s control asserts an **exact** log over all four: `aimla-pm` is D2's published seat verbatim (`working`, `open_calls: 1`, `open_turn: true`, one subagent); **`aimla-impl-1`** is `working` with `open_calls: 1`, `open_turn: true`, `subagents: []`; **`aimla-impl-2`** is `idle` with `open_calls: 0`, `open_turn: false`, `action: null`; **`aimla-review`** is `blocked` with `open_calls: 0`, `open_turn: false`. All four carry `badges: []`, `enabled: true`, a non-null `context` and a non-null `session`; every `activity_state` equals its `render_state`. **No desk in this fixture renders a receipt age** — that readout is `dark-only` ([§ 2.4](#24-the-clock-and-every-age-on-the-page)) and every seat here is `live` |
| `fx-clear-trace` | `fx-snapshot-4`, then the **ten** deltas of [D2 § 10](FLEET-STATE.md#10-worked-example-the-clear-trace-folded-end-to-end)'s trace applied to `aimla-pm`, in order, in **both** hook orders D2 runs |
| `fx-degraded` | one seat per non-`live` render: `catching_up` (with `oldest_unsent_age_s` = 4,000), `stale`, `offline`, `disabled`, `retired`, plus a `live` seat badged `fold_lag` with `derivation.fold_lag_ms` = 117,000. The `stale` and `offline` seats carry `delivery.no_data_since` **equal to** their `delivery.last_receipt_at`, which is what [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object) declares on those two states and what makes the desk's timestamp and its ticking age one instant ([§ 2.4](#24-the-clock-and-every-age-on-the-page)'s `dark-only`, [AT-D3-5](#at-d3-5-a-degraded-seat-is-visibly-degraded)). A fixture sets values; it renders none, so this row is **`named-not-rendered`** |
| `fx-interns` | one seat whose `subagents` goes 0 → 8 → 8-with-`subagents_open`-9, including one element with `title: null` |
| `fx-collision` | `fx-snapshot-4`, then a delta for `aimla-impl-4` ([§ 3.3](#33-collision-displacement-and-why-a-desk-move-is-itself-an-event)) |
| `fx-membership` | **three legs.** (a) a delta for a seat absent from `fx-snapshot-4`; (b) a later snapshot missing a seat that was present; (c) **the mid-session install leg** — a `feed.heartbeat` whose `fleet.seats_total` is 6 against the four seats the client holds, then a snapshot carrying a **second install** `aimla-win` with two `live` seats (`aimla-win/win-1`, `aimla-win/win-2`), and a `seat.delta` for `aimla-win/win-1` emitted on that install's channel **during** the ADMIT (b) round trip, at `state_version` one above what (b) returns |
| `fx-gap` | `fx-snapshot-4`, then three deltas for one seat with the middle one dropped |
| `fx-refusals` | the four responses of [D2 § 8.6](FLEET-STATE.md#86-a-deliberately-invalid-exchange) and [§ 2.2](#22-connect-snapshot-deltas): `503 fleet_unavailable`, `401 token_revoked`, a `fleet.health` with `db: "down"`, and a `fleet.reload` |
| `fx-nulls` | **two** seats, because the **36** members [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)'s field table marks `Null? yes` cannot all be null on one object — nulling a container removes its children rather than exercising their null renders, and a fixture that claimed otherwise would overstate its own coverage sixfold. **`nulls-a`** — every nullable **container** null: `action`, `task`, `context`, `session`, `retired`, plus `unknown_reason`, `api_error_type`, `model_label`, `badges_since`, `enabled`, and `subagents: []`. **`nulls-b`** — every container **present** with every nullable member under it null: `action.descriptor` / `.agent_scope` / `.parent_call_id`; one `subagents[]` element with `title` and `subagent_type` null; `task.ref`; `context.used_tokens` / `.total_tokens`; `session.started_at` / `.source` / `.project_label` / `.harness_label`; all three `activity.*`; all eight `delivery.*` — `last_receipt_at` and `no_data_since` null being [§ 3.4](#34-a-new-seats-first-appearance)'s never-reported seat (a fixture sets values and renders none: **`named-not-rendered`**); all three nullable `reporter.*`. The two together cover all 36, and neither covers them alone |

### AT-D3-1 no animation without its event

*The honesty principle, mechanised. This is the headline test and the gate on trusting the floor at
all.*

- **Build — the instrument half:** replay `fx-snapshot-4` alone, then silence; collect the animation
  log. **Reads:** the **animation log**.
- **GREEN — the instrument half, and it is a discriminating control:** on that fixture
  → the log carries **no `edge` row at all**, **no `phase: left` row at all** (nothing ended, because
  nothing arrived), and carries **exactly** the `held` `entered` rows
  [§ 6.2](#62-the-animation-table--the-closed-set) predicts for the four states the fixture delivers —
  **A3 for `aimla-pm` and `aimla-impl-1`, A6 for `aimla-impl-2`, A7 for `aimla-review`**, four rows and
  no fifth — each with that seat's `state_version` as its cause and each opening a **distinct**
  `episode_id`, four ids and no repetition. The control is two-sided on purpose: without it a log-writing bug that recorded nothing
  would pass the GREEN, and a client that fired arrivals on the snapshot would pass it too.
- **Build — the closed-set half:** replay `fx-snapshot-4`, `fx-clear-trace`, `fx-degraded` and
  `fx-interns` end to end; collect the animation log. **Reads:** the **animation log**, the
  **animation set**.
- **GREEN — the closed-set half:** every `animation_id` in the log is a row of
  [§ 6.2](#62-the-animation-table--the-closed-set); **every `edge` row has a non-null `cause`** that is
  one of the four causing messages, and for each edge row the driving field named by that table appears
  in the causing delta's `changed[]` (or the row's driver is a message type, and the cause is a message
  of that type); **every `held` row's `cause` is a `state_version` the client holds for that seat**,
  and the two phases are asserted in **opposite** directions, which is what makes both satisfiable on a
  correct client: in a `phase: entered` row's object the fact its
  [§ 6.2](#62-the-animation-table--the-closed-set) row names **has** the value its hold condition
  states, and in a `phase: left` row's object it **does not** — an exit row whose object still
  satisfies the hold condition is a render the client stopped drawing while the wire still said to draw
  it. **The pairing is over `episode_id`, not over `(animation_id, install_id, seat_id)`:** every
  `episode_id` appears on at most one `entered` row and at most one `left` row; every `left` row's
  `episode_id` matches an `entered` row earlier in the log for the same `animation_id`, `install_id`
  and `seat_id`; `left.at > entered.at` for that pair; and every `left` row's `motion` is `false`. The
  triple cannot carry this predicate, and `fx-clear-trace` is where it breaks rather than a case
  imagined for the rule — the walk is below, and A4 has **two** episodes on `aimla-pm` in it, so the
  triple names two entries and two exits with nothing to say which ended which. A predicate that
  asserted the hold condition on **both** phases would also fail a correct client on that fixture: A4's
  first episode is left at E1, where `open_calls` is 1, so A4's condition is false in exactly the
  object that ended it.

  **The `fx-clear-trace` episode walk, re-derived from [D2 § 10](FLEET-STATE.md#10-worked-example-the-clear-trace-folded-end-to-end)'s
  own facts column and carried to E9 rather than stopped at the first hand-off.** `aimla-pm` enters
  the trace from `fx-snapshot-4` at `working` with `open_calls: 1`, and each delta below carries the
  facts D2's table states after applying it:

  | At | The facts that moved | Episodes |
  |---|---|---|
  | snapshot apply | `working`, `open_calls: 1`, `open_turn: true` | A3 **entered** (episode 1) — cause is the snapshot object's `state_version` |
  | E0 `turn.start` | `T := true`; no call is open, so `open_calls: 0` | A3 **left** (episode 1); A4 **entered** (episode 1) |
  | E1 `tool.start` A | `open_calls: 1` | A4 **left** (episode 1); A3 **entered** (episode 2) |
  | E2 · E3 · E4 | `open_calls` 1 → 2 → 1, `render_state` never leaves `working` | no episode boundary: A3's condition holds throughout |
  | E5 `tool.end` A | `open_calls: 0`, **`T` still true** | A3 **left** (episode 2); A4 **entered** (episode 2) |
  | E6 `subagent.stop` | a version-bearing change that moves neither fact A4 reads | no episode boundary |
  | E7 `turn.end` | `T := false`; `render_state` → `unknown` | A4 **left** (episode 2); A9 **entered** (episode 1) |
  | E8 · E9 | still `unknown` | no episode boundary; A9 is still held when the fixture ends, so it has **no** `left` row |

  **Five `held` episodes, nine `held` rows** — A3 twice, A4 twice, A9 once and unclosed, so five
  `entered` rows and four `left` rows — and the count is the point: under the old triple, A3's two
  exits and A4's two exits were four rows over two keys. **Both figures are re-derived from the walk
  table's own `(A_n, episode N)` pairs by `tools/design/verify-floor.py`**, in both directions, so the
  sentence and the table cannot part company again — which they had, this sentence reading *six* and
  *eleven* over a table that yields five and nine. A9's missing `left` row is not a defect but the other half of the rule: an episode still held
  when the log ends is an episode with no exit, which is why the predicate is *at most one* `left` row
  per episode and not *exactly one*. **The `changed[]` clause binds `edge` rows only**, because a held render
  hands off on whatever fact its condition reads and that is not always its own driver: in
  `fx-clear-trace`, A4 gives way to A3 at **E1** when `open_calls` becomes 1 while `render_state` never
  leaves `working`, and E1's `changed[]` is `{action, subagents, open_calls}`
  ([D2 § 10](FLEET-STATE.md#10-worked-example-the-clear-trace-folded-end-to-end),
  [D2 § 8.3.1](FLEET-STATE.md#831-worked-delta): `changed` is the patch's keys). A predicate demanding
  `render_state` in that delta would fail a correct client on the fixture it replays.
- **RED:** add an ambient idle-breathing loop to the character sprite — the single most natural thing to
  add to an office full of creatures, and **more tempting since [A3](#62-the-animation-table--the-closed-set)
  and [A4](#62-the-animation-table--the-closed-set) gained a blink**: the difference between the
  ratified blink and this defect is not what it depicts, it is that one is **held by
  `render_state`** and the other runs always
  ([§ 6.3](#63-forbidden-forms-named-so-they-cannot-be-written-in-good-faith)) — and re-run. The log
  gains rows whose `animation_id` has no row in
  [§ 6.2](#62-the-animation-table--the-closed-set) at all, and whose `cause` is `null` under either
  class's rule — there is no message that caused it and no delivered field holding it — and the test
  fails twice over.
  **Watch that once**: it is the whole difference between a floor that reports state and a floor that
  performs it.
- **Second RED:** drive the working loop's frame rate from `open_calls` — a "busier seats type faster"
  change that looks like a feature — and assert that the loop's frame interval is constant across every
  seat and every fixture. A rate that varies is a quantity the wire never sent.

### AT-D3-2 the `/clear` trace shows no idle anywhere

*The D3 half of D1's and D2's headline test
([AT-D2-2](FLEET-STATE.md#at-d2-2-the-clear-trace-mints-no-idle)).*

- **Build:** replay `fx-clear-trace`, both hook orders, capturing the rendered `render_state` and the
  animation log at every applied delta. **Reads:** the **desk render**, the **side table**, the
  **animation log**, the **animation set**.
- **GREEN:** the desk renders `working` from E0 through E6, `unknown` from E7 onward, and **never
  `idle` at any version**; the animation log contains **no** `idle` row (A6) and no `depart` (A2); the
  side table gains an intern at E1 (title-less), gains its title at E2, and empties at E5; `action`
  changes four times — at E1, E3, E4 and E5 — which is four A5 rows and no more, each an `edge` row
  with its own one-row episode and no `left` row, because an edge animation is an instant.
- **RED — the inferred finish:** add a *finished* render when `open_calls` reaches 0 — which the E5→E7
  window makes true while the turn is still open — and the desk animates a completion for a seat whose
  work was **killed**. That is the false idle, arriving through the render layer after D1 and D2 both
  removed it from theirs.
- **Discriminating control:** `fx-snapshot-4`'s ordinary seat driven to a clean `turn.end` **does**
  render `idle` and does log an **A6 `held` `entered` row** (`motion: true` under ordinary motion —
  A6 holds the sleeping loop since the 2026-08-27 amendment, and this control asserted
  `motion: false` while it was the *no-loop* row; the value is what
  [§ 6.2](#62-the-animation-table--the-closed-set) says, not a constant — opening a fresh
  `episode_id`), preceded by the **A3 `left` row** whose cause is that same
  `turn.end` delta's `state_version` and whose `episode_id` is the one A3's `entered` row opened at
  the snapshot apply — assert the pairing, not merely that both rows exist, because two rows in the
  right order with unrelated episodes is a log that cannot say the loop this seat was running is the
  loop that stopped. So the test measures the trace and not the absence of an idle render. Name the seat:
  `aimla-impl-1`, whose `fx-snapshot-4` state is `working`.

### AT-D3-3 identity is stable across a restart

- **Build:** apply `fx-snapshot-4`; record every desk's slot. Discard the client entirely and apply the
  same snapshot again (a reload). Then apply it in **reverse seat order**, and again with the seats
  shuffled. **Reads:** the **floor layout**, the **desk render**, the **animation set**, the
  **animation log**.
- **GREEN:** the four assignments of [§ 3.2](#32-the-desk-slot-function)'s worked table, identically, in
  all four runs — slot is a function of the key and not of arrival order, delivery order or session.
- **GREEN — an arrival that collides:** replay `fx-collision` → `aimla-impl-4` takes slot 0,
  `aimla-pm` moves to slot 1, **and no other desk moves**; the animation log carries exactly one A16
  row, whose cause is the arriving seat.
- **GREEN — an arrival that does not collide:** deliver `aimla-win-1` (h mod 12 = 9) instead → it takes
  slot 9 and **no desk moves at all**; the log carries no A16 row.
- **RED:** key the desk on `session.session_id` → replay a `/clear` on any seat (`fx-clear-trace`'s E9
  mints a new session id) and the desk moves, taking its character with it, because the seat restarted
  its session. Watch it once: it is the identity defect D1 § 3.4's 30-day incident is the general form
  of.
- **Second RED:** assign slots by sorted `seat_id` position → deliver `aimla-alpha` and every desk on
  the floor shifts by one.

### AT-D3-4 the subagent cap boundary

- **Build:** replay `fx-interns`, and open the drill-down against a stubbed detail response carrying
  nine open dispatch calls. **Reads:** the **side table**, the **drill-down**, the **uncapped intern
  list**.
- **GREEN:** at 8 elements, 8 stools and **no** *+N more* tag; at `subagents_open: 9` with 8 elements,
  8 stools **and** a *+1 more* tag whose number comes from `subagents_open − 8`; the drill-down, opened
  against a stubbed detail response carrying 9 open dispatch calls, lists **9**. The element with
  `title: null` renders **untitled** and its `call_id` appears in the drill-down.
- **RED — the invented title:** fall back to `subagent_type`, to the tool name, or to *"subagent"* for a
  null title → the floor shows a label for a spawn event that was never received, which is the honest
  orphan D1 § 6.8 and D2 § 8.2.1 both refuse to paper over.
- **Second RED — the counted array:** render the intern count as `subagents.length` → the count reads 8
  on a seat running 9, and a floor whose count silently saturates is worse than one that says *+1 more*.
- **Discriminating control:** a seat with `subagents: []` and `subagents_open: 0` renders no stools and
  no tag.

### AT-D3-5 a degraded seat is visibly degraded

- **Build:** replay `fx-degraded`; capture each desk's render and the animation log. The judgement
  worth stating is what this test does *not* read: every assertion below is that a render was entered
  and drawn *static*, which is exactly what a desk with no loops in it produces, so the test measures
  something real at step 5 and does not read the animation set. Its discriminating control names
  motion, and [§ 11](#11-acceptance-tests)'s ordering rule is scoped to the **GREEN**, for the reason
  that section gives. **Reads:** the **desk render**, the **animation log**.
- **GREEN:** all six desks are pairwise distinguishable by pose/glyph **and** by label line, per
  [§ 7.1](#71-the-render-per-state); the `catching_up` desk renders the replay treatment and its
  activity state appears **only** under a *was:* label; `stale` and `offline` render an empty chair with
  *no data since …* built from `delivery.no_data_since` **and the ticking age *no data for …* beside
  it, built from `delivery.last_receipt_at`** — the one desk render `dark-only` permits
  ([§ 2.4](#24-the-clock-and-every-age-on-the-page)); advance the harness clock and assert the age string moved while the
  timestamp did not; `disabled` renders a present character with the
  monitor off and is **not** the `offline` render; the `fold_lag` seat renders its pose with the hatched
  overlay, the *117 s behind* line **carrying its own *as of* stamp** — advance the harness clock and
  assert the number has **not** moved, because it is `fetch-fresh` and nothing has delivered a new
  one ([§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy)) — and **no motion** — assert that the `entered` row of that seat's
  `held` episode carries **`motion: false`**, which says the render was entered and
  drawn static; asserting the absence of a row instead would pass a client that never rendered the seat
  at all, and asserting it over *every* `held` row instead would be satisfied for free by the exit
  rows, whose `motion` is `false` by definition.
  **Plus the sleeper assertion, which this test owns because it is a degradation claim:** the `stale`
  and `offline` desks render the **empty chair** and **no character at all** — specifically **not** the
  sleeping figure [A6](#62-the-animation-table--the-closed-set) draws for `idle`
  ([§ 7.5](#75-what-a-degraded-desk-may-never-look-like)) — asserted against a `live` `idle` seat
  rendered in the same run, so the test compares the two pictures rather than describing one. Assert
  it on the **render**, not on the animation log: a client that drew a sleeper on a `stale` desk and
  logged nothing would satisfy a log-scoped assertion for free.
- **RED — render the axis, not the collapse:** switch the desk on `activity_state` instead of
  `render_state` → the `stale` and `offline` seats render `idle` (their activity state is preserved
  underneath), which is `D2-MUST` #2 broken at the last layer after two documents held it. **Under the
  ratified art this RED is now also the sleeper defect**, and that is the reason it is worth watching a
  second time: the `stale` desk does not merely mislabel itself, it draws a character **peacefully
  asleep at a desk nobody has heard from in eleven minutes**, which is the most reassuring possible
  rendering of the fleet's worst state.
- **Second RED — the frozen fold:** drop the `fold_lag` treatment → a desk showing two-minute-old work
  beside a fresh receipt age, with every instrument on the page agreeing that everything is fine. This
  is [AT-D2-21](FLEET-STATE.md#at-d2-21-a-frozen-fold-cannot-look-healthy)'s defect at the render layer.
- **Third RED — the sleeper on a dark desk:** render `stale` and `offline` with A6's sleeping pose
  instead of the empty chair, keeping every label correct → the labels still read *no data since …*
  and the picture says the seat is resting. Watch it: it is the one defect in this test a viewer
  standing back cannot catch, because at floor-reading distance the label is the part they are not
  reading.
- **Discriminating control:** the `live` `working` seat of `fx-snapshot-4` renders full colour, with
  motion, and no currency label — so the test measures degradation and not a treatment applied to
  everything.

### AT-D3-6 the feed dying is visible within 45 s

*Two halves, gated at their own steps per [§ 11](#11-acceptance-tests)'s ordering rule: the floor half
at [Appendix B](#appendix-b--what-an-implementer-builds-from-this) step 8, the panel half at step 10.*

- **Build — the floor half:** apply `fx-snapshot-4`, deliver heartbeats for 60 s of simulated time,
  then deliver nothing for 60 s more. Run it twice: once ordinarily, and once under
  `prefers-reduced-motion: reduce`. **Reads:** the **status strip**, the **age readout**, the
  **animation set** ([A14](#62-the-animation-table--the-closed-set)'s pulse and
  [A17](#62-the-animation-table--the-closed-set)'s room render), the **floor layout**.
- **GREEN — the floor half:** at 45 s of silence the status strip reads **feed down — polling**,
  [A14](#62-the-animation-table--the-closed-set)'s
  pulse has stopped, a `GET /api/fleet/snapshot` is issued and repeats every 10 s, and **every desk's
  quiet-age readout has continued to grow throughout** — assert the rendered age strings, not the
  internal timestamps.
- **GREEN — the floor half, the frozen room, and this is the assertion the whole design of
  [A17](#62-the-animation-table--the-closed-set) is for:** during the heartbeat phase the **rendered
  wall-clock string advances**, once per heartbeat and never between them — assert it moved on the
  four heartbeats of one minute and did **not** move on any simulated second in between; and after the
  feed stops, **the rendered wall-clock string is identical at every subsequent read, out to the end of
  the run, and the sky is the same phase it held at the last heartbeat.** Assert the rendered strings
  and the rendered sky value, not an internal timer's state: a client whose clock element is still
  being repainted from a live source is exactly what this asserts against, and only the rendered value
  can tell the two apart. **The two directions are one test on purpose** — a client that never advanced
  the clock at all would satisfy the freeze and prove nothing, and the heartbeat-phase assertion is
  what stops it. Under `prefers-reduced-motion: reduce` the same advances happen with **no
  transition** — the hands jump, the sky steps — and the freeze is identical, which is
  [§ 6.4](#64-reduced-motion-is-a-first-class-rendering-not-a-degradation)'s requirement that the
  reduced form carry the same fact.
- **Build — the panel half:** the same run **with the drill-down open on `aimla-pm`**. It is a second
  half rather than a line in the first because the panel does not exist until step 10, and a test
  gated at step 8 that read it would be a gate on an artifact nobody has built. **Reads:** the
  **drill-down**.
- **GREEN — the panel half:** the drill-down's `fetch-fresh` blocks are **re-stamped** by each poll
  rather than ticked ([§ 2.4](#24-the-clock-and-every-age-on-the-page)): a transport block whose
  numbers moved between two polls would be rendering a value nothing delivered, which is the same
  defect as a frozen age wearing the opposite face.
- **RED — the frozen page:** stop the age tick when the feed stops → the floor freezes with every age at
  the value it held when the socket died, which is the most convincing lie this UI can tell: it looks
  exactly like a fleet where nothing has happened.
- **Second RED — the optimistic strip:** leave the indicator on *live* while polling → a polled floor
  claiming to be a live one.
- ⭐ **Third RED — the clock back on a timer, which is the one a maintainer's *fix* will write:** drive
  [A17](#62-the-animation-table--the-closed-set) from a 10-second interval off the viewer's clock — the
  ratified reference's own mechanism, and the obvious repair for a clock that "looks frozen" — and
  re-run. The clock keeps ticking through the 60 s of silence, so the GREEN's *identical at every
  subsequent read* fails on the first read after the feed stops; **the room never goes still, and the
  most legible feed-down signal on the page is gone while every other assertion in this test still
  passes.** That last clause is why this RED is named here rather than left to
  [AT-D3-1](#at-d3-1-no-animation-without-its-event): the timer version writes an
  [A17](#62-the-animation-table--the-closed-set) row for an animation that *does* have a table row, so
  the closed-set test is satisfied by it, and the only thing that catches it is an assertion about what
  happens when nothing arrives. **Watch this one fail before trusting the freeze** — a clock asserted
  frozen by a client that never moves it is a decoration reporting that the harness ran.
- **Discriminating control:** deliver heartbeats every 15 s for the whole run → the indicator never
  leaves *live*, no poll is issued, and the wall clock advances on every heartbeat throughout.

### AT-D3-7 a delta gap resyncs exactly one seat

*Two halves, gated at their own steps per [§ 11](#11-acceptance-tests)'s ordering rule: the protocol
half at [Appendix B](#appendix-b--what-an-implementer-builds-from-this) step 3, the strip half at step
8. The **resyncs: N** readout is a status-strip rendering and the strip is not built until step 8; the
resync itself, and the line the record gains, are the protocol's and are observable at step 3.*

- **Build — the protocol half:** replay `fx-gap`. **Reads:** the **client protocol**, the **client's
  event record**.
- **GREEN — the protocol half:** the client detects `state_version` jumping by 2, issues **exactly one**
  `GET /api/fleet/seats/aimla/aimla-pm?resync_from=<its last applied version>` — assert the query
  parameter is present and carries the last **applied** version, because it is the only write path for
  D2's `feed_gap_detected` counter ([D2 § 8.5](FLEET-STATE.md#85-gaps-reconnect-and-why-state_version-is-not-seq))
  — converges to the served object, and **no other seat is refetched**; **the client's event log**
  records it — the record the protocol layer writes, which is this step's artifact
  ([§ 5.5](#55-the-clients-own-narration), [§ 11](#11-acceptance-tests)).
- **Build — the strip half:** the same fixture, with the status strip rendered. **Reads:** the
  **status strip**.
- **GREEN — the strip half:** the **resyncs: N** readout increments by exactly one, and the lobby
  renders the same record at step 9.
- **RED — the strip half:** increment the counter on every applied delta rather than on every resync
  the client issued → the readout stops being a count of **this client's own requests**
  ([§ 5.5](#55-the-clients-own-narration)) and becomes a traffic meter that never reads zero on a
  healthy feed, which is the one reading it exists to make possible.
- **RED:** apply deltas unconditionally → the desk diverges silently and stays wrong until something
  else changes it, which on a quiet seat is never. Assert the divergence field by field against the
  fixture's final object; a test that only checked "no error was thrown" passes here.
- **Second RED:** issue the resync **without** `resync_from` → the fetch succeeds and the desk converges,
  so nothing on the client looks wrong, while the server's gap counter can never move. Assert the
  parameter, not the convergence.
- **Discriminating control:** the same fixture with no delta dropped → no resync request, no counter
  movement.

### AT-D3-8 a refusal is never an empty office

- **Build:** replay `fx-refusals`, each response in a separate run, both on a cold start and on a client
  already holding `fx-snapshot-4`. **Reads:** the **failure renders**, the **floor layout**.
- **GREEN:** `503` renders the store-unavailable statement — on a warm client over a floor labelled
  *last known good*, on a cold one as words; `401` renders the sign-in prompt with the floor beneath
  dimmed and labelled *not live since HH:MM:SS*, **and the client closes the socket**; `db: "down"`
  renders the same statement as `503` while the connection indicator stays *connected*; `fleet.reload`
  renders its banner and **delta application stops** — assert a delta delivered after it changes
  nothing.
- **RED:** render a `503` as a floor with no desks → an empty office, which is indistinguishable from a
  fleet that has gone home and is exactly the failure [D2 § 8.6](FLEET-STATE.md#86-a-deliberately-invalid-exchange)
  forbids on the wire, arriving through the renderer instead.
- **Second RED:** keep animating the floor behind the `401` modal → a live-looking floor whose data the
  client is no longer authorized to have.
- **Discriminating control:** a `200` snapshot in the same harness renders the floor normally.

### AT-D3-9 the client half of snapshot-then-deltas

*The client's half of [AT-D2-7](FLEET-STATE.md#at-d2-7-snapshot-then-deltas-has-no-window).*

*Two halves, gated at their own steps per [§ 11](#11-acceptance-tests)'s ordering rule: the protocol
half at [Appendix B](#appendix-b--what-an-implementer-builds-from-this) step 3, the render half at step
6. The watermark and the subscription are observable in the seat map the protocol holds; **no `edge`
row** and **the held `entered` rows the delivered states require** are claims about the animation set,
which does not exist until step 6, and a floor with no animations in it satisfies *no edge row* for
free.*

- **Build — the protocol half:** a run in which the subscribe is followed by a **forced 500 ms delay**
  before the snapshot response, with two deltas delivered inside that window — one below the snapshot's
  watermark for its seat, one above. **Reads:** the **client protocol**.
- **Build — the protocol half, mid-session leg:** `fx-membership` leg (c), with the same forced 500 ms
  delay on ADMIT (b)'s response. This leg exists because [§ 2.2](#22-connect-snapshot-deltas)'s `ADMIT`
  claims the window is closed for an install entering the rendered set **at any time**, and a test that
  only ever admitted installs at connect time would leave the *at any time* half unexercised — which is
  precisely the half a client can get wrong without any test noticing. **Reads:** the **client
  protocol**.
- **GREEN — the protocol half:** the client's final seat map equals the server fixture's exactly; the
  below-watermark delta is **discarded** and the above-watermark one **applied**; running the scenario
  100 times yields 100 identical results. On the mid-session leg the client **subscribes** to
  `private-fleet.aimla-win` before issuing ADMIT (b), and the delta emitted inside (b)'s window is
  **applied** at (c) rather than lost. Assert the **subscription** was opened, not merely that the seat
  map filled: a client that fetched without subscribing passes every assertion on the first frame and
  is wrong forever after.
- **Build — the render half:** both runs above, replayed with the desk and the animation set in place
  and the animation log collected. **Reads:** the **desk render**, the **animation set**, the
  **animation log**.
- **GREEN — the render half:** the snapshot render fires **no `edge`-class animation** — assert the log
  gains **no `edge` row** across the snapshot apply, while the `held` `entered` rows the delivered
  states require **are** present, each opening a fresh `episode_id` and carrying the snapshot object's
  `state_version` as its cause ([§ 6.5](#65-a-snapshot-never-animates)); and `aimla-win/win-1`'s
  **rendered desk** equals the fixture's post-delta object — not (b)'s object.
- **RED — order:** fetch the snapshot before subscribing → the delta made in the window is in neither,
  and on a quiet desk the divergence is permanent.
- **RED — discovery without admission:** render the discovered install from the discrepancy fetch and
  **never subscribe** to its channel → every desk on that floor holds its discovery-snapshot state for
  the life of the connection while `link_state` reads `live` off a frozen object, and no second
  discrepancy can fire because the counts now agree. Watch this one: it is a whole floor of
  [D2 § 8.4](FLEET-STATE.md#84-snapshot-then-deltas)'s *"permanently and invisibly wrong about one
  desk"*, and it is invisible to every assertion that only reads the first frame.
- **Second RED — no watermark:** apply every buffered delta → construct the visible case D2 names, a
  patch that clears `action` followed by a snapshot that already has it cleared and then a newer delta
  that sets it.
- **Third RED — the animating snapshot:** fire edge animations on the snapshot apply → every desk on the
  floor plays an arrival on every reconnect ([§ 6.5](#65-a-snapshot-never-animates)).

### AT-D3-10 ages come from the server clock

*Two halves, gated at their own steps per [§ 11](#11-acceptance-tests)'s ordering rule: the floor half
at [Appendix B](#appendix-b--what-an-implementer-builds-from-this) step 4, the panel half at step 10.
The split is the whole point here rather than bookkeeping: the receipt half of this test's claim is
observable on **no** surface built before step 10.*

- **Build — the floor half:** `fx-snapshot-4`, with the harness's browser clock set **+3 h** from the
  fixture's `server_time`. **Reads:** the **age readout** — every assertion below is about a rendered
  age string and the offset behind it, which is what step 4 builds; *desk* names where the string sits,
  not the artifact it reads.
- **GREEN — the floor half:** every rendered age matches the age computed from `server_time` — every
  desk's quiet age (*nothing done for N*) reads seconds, not three hours; `clock_offset_ms` is applied
  to every readout; and every seat-clock timestamp (`action.started_at`, `context.sampled_at`,
  `activity.last_event_time`, `session.started_at`) renders as a labelled seat-clock claim and **not**
  as an age.
- **Build — the panel half:** the same fixture and the same skewed clock, **with the drill-down opened
  on `aimla-pm`**. Every seat in `fx-snapshot-4` is `live`, so **no desk on that floor renders a
  receipt age at all** ([§ 2.4](#24-the-clock-and-every-age-on-the-page)'s `dark-only` marker) — the transport block's *both ages
  under one* as of *stamp* is the only surface the receipt half is observable on, and it is built at
  step 10. **Reads:** the **drill-down**.
- **GREEN — the panel half:** the transport block's receipt age likewise reads seconds, not three
  hours, and carries its *as of* stamp.
- **RED:** compute ages from `Date.now()` → **every desk on the floor reads *nothing done for 3h*** on
  a fleet that is reporting normally (the floor half), and the open panel's receipt age reads *no data
  for 3h* beside a stamp naming a fetch three hours in its own past (the panel half). The observable
  is named on the readout the fixture actually produces: an earlier revision of this RED named a desk
  receipt age, which [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s `dark-only` marker permits only on a `stale` or `offline`
  desk and every seat in this fixture is `live` — so the failure it claimed to watch could not have
  appeared, and a RED nobody can see is the decoration
  [§ 11](#11-acceptance-tests)'s preamble refuses.
- **Second RED:** render `action.started_at` as an elapsed time → a seat skewed by +10 minutes shows a
  call that started in the future ([D2 § 3.3](FLEET-STATE.md#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by)).
- **Discriminating control:** the same fixture with the browser clock correct → identical output, so the
  test measures the offset and not the rendering.

### AT-D3-11 an unrecognised member renders as unrecognised

- **Build:** deliver a delta whose `render_state` is `"pondering"`, one whose `badges` contains
  `"quantum_flux"`, and one whose `unknown_reason` is `"reasons"`. **Reads:** the **desk render**, the
  **failure renders**, the **client's event record**.
- **GREEN:** each renders the **unrecognised** glyph or badge carrying the raw string; the desk is
  treated as not-current; **the client's event log** records each distinct value **once**
  ([§ 5.5](#55-the-clients-own-narration): the record, not the lobby's rendering of it); nothing crashes and no
  other desk is affected.
- **RED — the nearest match:** map the unknown `render_state` to the closest known member → a seat in a
  state this client has never heard of renders as `working`, which is the most flattering possible
  guess and the one a fresh deploy would produce during a rolling upgrade.
- **Second RED — the healthy default:** default to `live`/`working` → the same defect with no guess at
  all.

### AT-D3-12 asset provenance gates bite

*Two halves, gated at their own steps per [§ 11](#11-acceptance-tests)'s ordering rule: the manifest
half at [Appendix B](#appendix-b--what-an-implementer-builds-from-this) step 0, the lineage half at
step 1. The gates themselves are step 0 and must exist before any asset does; the **lineage file**
and the **character tree** are step 1's artifacts, so a lineage assertion at step 0 asserts the
contents of a file no step has yet created.*

*⚠ **Its RED set was rebuilt on 2026-08-27** with [§ 10.1](#101-the-manifest-and-the-two-gates)'s
gates. The old third RED planted a `sprites.webp` in the character tree to fail an **absence** clause
that no longer exists; leaving it would have left this test passing over a rule the document had
deleted — a green that reports the harness ran. What replaces it tests the rule that is actually
there: not *is there art*, but **does every asset declare where it came from**.*

- **Build — the manifest half:** run the asset gates against the repository
  ([§ 10.1](#101-the-manifest-and-the-two-gates)). **Reads:** the **provenance gates**.
- **GREEN — the manifest half:** every asset file has a `docs/ATTRIBUTION.md` row; every row's SHA-256
  matches its file; every licence identifier is in the closed allowlist; and **every row's `origin` is
  one of the two members and is consistent with the row's own source URL** — `first-party` against an
  in-repo reference, `licensed` against a genuine external one.
- **Build — the lineage half:** run the same gates over the ported character tree
  ([§ 10.2](#102-characters-the-munder-difflin-port)). **Reads:** the **lineage file**, the
  **character tree**.
- **GREEN — the lineage half:** the lineage file names the upstream repository, the commit and the MIT
  notice; and every file in the **character tree** carries an admitted extension and no embedded image
  bytes — Gate 2's two clauses, asserted here rather than at step 0, because a tree that does not
  exist yet satisfies both for free.
- **RED — the lineage half:** drop the **commit SHA** from `resources/characters/LINEAGE.md`, leaving
  the repository URL → the lineage check fails naming the missing field. Watch that one: a port whose
  upstream commit nobody recorded is a port nobody can tell from a fork
  ([§ 10.2](#102-characters-the-munder-difflin-port)).
- **RED — the unlisted asset:** add a tile with no row → Gate 1 fails naming the path. **Second RED —
  the undeclared picture:** drop a `creature.svg` into the character tree with **no manifest row** →
  Gate 1 fails naming it. This is the RED the amendment is *for*: under the old gate the file was
  refused for being an image, so the manifest was never the thing tested; now art is admitted and the
  **row** is the only thing standing between it and the build.
  **Third RED — the `origin` column, three ways, because a typed column with one RED is a column
  tested at one value:** a row with **no** `origin` → Gate 1 fails naming the missing cell; a row whose
  `origin` is `vendored` — a plausible third word nobody decided → fails as outside the closed set;
  and a row claiming **`first-party`** while its source URL points at somebody else's repository →
  fails on the contradiction, which is the only lie in this class a machine can catch at all
  ([§ 10.1](#101-the-manifest-and-the-two-gates)'s cost paragraph says why the rest cannot be).
  **Fourth RED — the swapped bytes:** replace a listed file's contents, leaving the row → the SHA-256
  check fails. **Fifth RED — the unanticipated format:** drop a `sprites.avif` into the character tree
  **with a complete, honest manifest row** → Gate 2 clause 1 fails naming the extension. The row being
  *correct* is the point of this one: it tests the file-type allowlist and nothing else, where the old
  `sprites.webp` case tested an absence that has been repealed.
  **Sixth RED — the embedded asset:** paste a 40 KB base64 PNG into `characters/atlas.ts`, a file
  clause 1 admits → clause 2 fails on the embedded literal; **and** inline a
  `data:image/png;base64,…` blob inside an otherwise-legitimate `.svg` → clause 2 fails again, on the
  format the amendment newly admits. Both are watched: an embedded asset has no path, so no row, so no
  provenance, and it is invisible to Gate 1 by construction.
  **Seventh RED — the wrong licence:** set a row's identifier to `CC-BY-NC-4.0` → the allowlist check
  fails.
- **Discriminating controls — two, and the second is the one that keeps this gate switched on:**
  *(a)* the clean tree passes every check, so the gates are known to be capable of reporting
  *provenance is complete*; *(b)* **a genuinely complex first-party `.svg` — long, mixed-case,
  digit-dense path data — PASSES clause 2.** Without (b) the sixth RED is satisfied by a gate that
  refuses every SVG ever drawn, and **a gate that reds on correct work gets disabled**, which is a
  worse outcome than the one it was protecting against. Both halves of the pair run in
  `bin/asset-provenance.selftest.py`; either alone is not evidence.

### AT-D3-13 every state is legible without motion

*Gated at [Appendix B](#appendix-b--what-an-implementer-builds-from-this) **step 6**, not step 5, and
the reason is the test's own claim rather than bookkeeping: this test asserts that no state is carried
by motion alone, and before the **animation set** exists there is no motion for any state to be
carried by — every desk is static, so the assertion is satisfied by a floor nobody has finished
building. It is not split, because no half of it is observable earlier.*

- **Build:** render `fx-snapshot-4`, `fx-degraded` and a seat in each remaining `render_state` member
  under `prefers-reduced-motion: reduce`; capture a static image of each desk. **The remainder is
  named rather than left to be worked out**, because *"each remaining member"* is only checkable
  against a stated partition: `fx-snapshot-4` delivers `working`, `idle` and `blocked`; `fx-degraded`
  delivers `catching_up`, `stale`, `offline`, `disabled` and `retired`; so the seats this test adds are
  **`stalled`** (with an `api_error_type` of `rate_limit`) and **`unknown`** (with an `unknown_reason`
  of `turn_killed_by_clear`) — two, and the ten are covered. **Reads:** the **desk render**, the
  **animation set**, the **animation log**.
- **GREEN:** all **ten** `render_state` members are pairwise distinguishable from the static images
  alone, and each carries its label line — **including the `idle` / `stale` / `offline` triple, named
  here because it is the pair-set the ratified art makes hardest and the one
  [§ 7.5](#75-what-a-degraded-desk-may-never-look-like) turns into a rule**: `idle` is the **static
  slumped sleeper** and `stale` and `offline` are the **empty chair**, so with the z's switched off
  the difference is a character being there or not, and the assertion is that the three static images
  differ, not that three labels do; every animation row's reduced-motion form is what appears;
  the log gains **no `edge` row**, and every `held` row with **`phase: entered`** reads
  **`motion: false`** — which is the assertion that the reduced-motion form was *selected*, where an
  empty log would equally have reported a renderer that drew nothing at all. The phase scope is
  load-bearing rather than pedantic: a `left` row's `motion` is `false` by definition, so a predicate
  over *every* `held` row is satisfied in part by rows that prove nothing about reduced motion.
  **And *every animation row* means every row these fixtures exercise — the ten states' rows. It does
  not reach [A17](#62-the-animation-table--the-closed-set)**, whose room render belongs to no state and
  which no fixture here fires, because none of them delivers a heartbeat; A17's reduced-motion form is
  asserted where its driver actually runs, in
  [AT-D3-6](#at-d3-6-the-feed-dying-is-visible-within-45-s)'s floor half, which replays this test's
  `reduce` condition over a heartbeat feed. **Scoping the sentence is the point of writing it:** a
  claim that reads as total over a table it never touched is how an unasserted row goes unnoticed.
- **RED:** distinguish `working` from `idle` by motion alone — the same pose, one animated — and the two
  become one desk in a screenshot, which is how most of this floor will be reviewed and how all of it
  will be read by anyone who has motion disabled.
- **Discriminating control:** with motion enabled, the same fixtures produce the animation rows
  [§ 6.2](#62-the-animation-table--the-closed-set) predicts.

### AT-D3-14 a null is never drawn as a zero

*Two halves, gated at their own steps per [§ 11](#11-acceptance-tests)'s ordering rule: the desk half
at [Appendix B](#appendix-b--what-an-implementer-builds-from-this) step 5, the panel half at step 10.
Every assertion below is labelled with the half it belongs to, because the thirty-six members split
across the two surfaces and a single list read as though the desk could show them all.*

- **Build — the desk half:** render `fx-nulls` on the floor. **Reads:** the **desk render**, the
  **side table**.
- **Build — the panel half:** the same fixture with the drill-down opened on each of the two seats,
  plus the operator health view for the `counters` assertion. **Reads:** the **drill-down**, the
  **uncapped intern list**.
- **GREEN — `nulls-a`, the containers, desk half:** the context gauge reads **not reported** and the
  bar is absent — **not** 0 %; there is no task chip; the monitor shows the state line rather than a
  blank; `model_label` is omitted rather than empty; the side table shows no stools rather than zero
  stools; and `retired` being null draws no plate.
- **GREEN — `nulls-a`, the containers, panel half:** `session` renders *no session open*; the
  drill-down's intern list is absent rather than showing an empty list; and a `null` `counters` object
  on the health view reads **unreadable** rather than a column of zeros.
- **GREEN — `nulls-b`, the members, asserted against [§ 5.6](#56-the-null-render-for-every-nullable-member)
  row by row:** for **every** member `nulls-b` sets null, the rendered output is that member's § 5.6
  cell — not a zero, not a placeholder, not a value borrowed from a sibling field. The fixture was
  built to name that population and the assertion now follows it, rather than sampling eight of the
  thirty-six and reporting clean over the rest. **Each § 5.6 cell names the surface it renders on**,
  so the row-by-row walk is itself split between the two halves. The ones worth naming because an
  implementer's obvious default is wrong on each — **desk half:**
  - `activity.last_received_at` null → the desk reads ***nothing done yet***, **not** *nothing done for
    0s*. This is the one age a `live` desk carries ([§ 5.1](#51-the-desk)), and `nulls-b`'s seat is
    exactly [D2 § 3.1](FLEET-STATE.md#31-the-rule) rule 4's heartbeat-only seat, so a client that
    coalesced it to zero would render *nothing done for 0s* on a seat that has never done anything —
    the clean zero, on the only readout left.
  - `delivery.no_data_since` null on an `offline` desk → ***no data yet*** alone — never *no data
    since null*, and never a `dark-only` age beside it, because `delivery.last_receipt_at` is null on
    that same seat ([§ 7.1](#71-the-render-per-state), [§ 3.4](#34-a-new-seats-first-appearance)).
    This is the render an implementer building the § 7.1 switch from its `offline` row alone would
    get wrong.
  - `action.descriptor` null → the monitor shows `action.tool_name` alone, never a descriptor
    synthesized from it.

  And **panel half:**
  - `delivery.last_receipt_at` null → the open panel's transport block reads ***no data yet***, not a
    receipt age of zero and not an empty cell.
  - `delivery.spool_lag_events` / `.last_seq` / `.clock_skew_ms` / `reporter.uptime_s` null → *not
    reported* on each, **never 0** — four separate chances to draw a measurement nobody made.
  - `session.source` null → no source tag, never a defaulted `startup`.
- **RED:** coalesce nulls to zero anywhere on the page → a seat that has never reported a context sample
  renders a full, empty gauge reading 0 %, which is a measurement the wire never made. This is
  `docs/KANBAN.md § G-1`'s clean zero with a progress bar around it.
- **Discriminating control:** a seat with `context.used_pct: 0.0` — a real zero — **does** render a bar
  at 0 %, so the test distinguishes a measured zero from an absent measurement.

### AT-D3-15 the lobby never invents a count

- **Build:** `fx-snapshot-4`, then drop one seat from the client's map without a snapshot (simulating a
  missed insert) — the **N < M** direction. Then, from the intact fixture, deliver a `feed.heartbeat`
  whose `fleet.seats_total` is one **lower** than the seats the client holds — the **N > M** direction,
  reachable whenever a seat retired more than 14 days ago leaves `seats_total` at once while
  [§ 3.5](#35-retirement-and-the-only-removal) keeps its desk until a snapshot apply — and then a
  second, identical heartbeat. **Reads:** the **lobby**.
- **GREEN:** the fleet counts render `fleet.seats_total` / `fleet.seats_live` verbatim; the per-floor
  summary is labelled as a count of held seats; when the two disagree the lobby renders *the client
  holds 3 of 4 seats — refreshing* and issues one snapshot fetch, after which they agree.
- **GREEN — the other direction:** on N > M the lobby renders *the client holds 4 seats; the fleet
  reports 3 — refreshing* and issues one fetch; the **second** identical heartbeat issues **no** fetch,
  because the trigger is one fetch per *distinct* (N, M) observation and not a poll
  ([§ 4.1](#41-the-lobby--the-building-summary), [decision 9](#13-decisions-taken-revisable-at-review)).
  A test built only on N < M would leave the wording *N of M* — which reads as a subset — unexercised on
  the direction where it is false.
- **RED:** compute the fleet counts by counting desks → the lobby confidently reports 3 seats on a
  4-seat fleet, and the missing desk is invisible precisely because the count agrees with the floor.
- **Discriminating control:** the intact fixture renders no discrepancy notice and issues no fetch.

### AT-D3-16 retirement is rendered, and the removal is explained

- **Build:** `fx-degraded`'s retired seat; then deliver `seat.retired` for a live seat **alone**, with
  the delta carrying `retired.*` held back until after it — the message-first order
  [§ 2.5](#25-what-re-renders-and-when) says the client may see; then deliver that delta; then a later
  snapshot that omits the retired seat entirely. **Reads:** the **desk render**, the **animation set**,
  the **drill-down**, the **client's event record**.
- **GREEN — on the message alone:** the desk clears in place with the plate, the **reason** and the
  **time**, both of which the message carries, and with **no operator name** — [D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed)'s
  payload for `seat.retired` is `install_id`, `seat_id`, `reason`, `at`, and carries no `by`, so a name
  on the plate at that moment would be a name the wire never sent ([§ 5.1](#51-the-desk)).
- **GREEN — on the delta:** `retired.by` lands and the plate gains the operator; the result is
  identical in either arrival order and applying both twice changes nothing
  ([§ 2.5](#25-what-re-renders-and-when)); the drill-down still shows what the seat was doing and what
  its transport did afterwards; the desk is **still present** in the snapshot for 14 days; when a
  snapshot finally omits it, the desk is removed **and the client's event log carries a line naming
  the seat and the reason** — the record, which the lobby renders
  ([§ 5.5](#55-the-clients-own-narration)).
- **RED — the vanishing desk:** remove the desk when `render_state` becomes `retired` → a desk that
  existed a second ago is simply gone, with no rendered state that says why
  ([D2 § 4.10](FLEET-STATE.md#410-retirement-is-a-rendered-state)).
- **Second RED — the removal on a delta:** remove a desk on any signal other than a **full** snapshot
  apply → a missed delta or a resync can delete a live seat from the floor. Plant `ADMIT` (b)'s scoped
  read as the second half of this RED: admitting one install must not remove a desk of any other,
  which a client treating every snapshot response as a population statement would do to the whole
  fleet ([§ 2.2](#22-connect-snapshot-deltas), [§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold)).
- **Third RED — the invented operator:** fill the plate's operator on the message-only render — from a
  default, from the fleet's operator, or from whatever the field last held — → a retirement attributed
  to a person the wire never named, on the one plate whose whole content is *who did this and why*.

### AT-D3-17 a seat the client does not hold is fetched, never patched

*Two halves, gated at their own steps per [§ 11](#11-acceptance-tests)'s ordering rule: the protocol
half at [Appendix B](#appendix-b--what-an-implementer-builds-from-this) step 3, the render half at
step 6. *The inserted desk renders **without** an arrival animation* is an assertion about the
**animation set**, and before step 6 there is no arrival animation to withhold — while the fetch, the
buffering and the line the record gains are the protocol's and are observable at step 3.*

- **Build — the protocol half:** replay `fx-membership`. **Reads:** the **client protocol**, the
  **client's event record**.
- **GREEN — the protocol half:** the delta for the unknown seat triggers exactly one
  `GET /api/fleet/seats/{install}/{seat}`; deltas for that seat received while the fetch is in flight
  are buffered and drained against the fetched `state_version`; **the client's event log** records
  *seat added to the floor* ([§ 5.5](#55-the-clients-own-narration): the record is this step's
  artifact, the lobby is its renderer at step 9).
- **Build — the render half:** the same fixture, replayed with the desk and the animation set in
  place and the animation log collected. **Reads:** the **desk render**, the **animation set**, the
  **animation log**.
- **GREEN — the render half:** the inserted desk renders **without** an arrival animation
  ([§ 3.4](#34-a-new-seats-first-appearance)) — assert the log gains no
  [A1](#62-the-animation-table--the-closed-set) row for that seat.
- **RED:** apply the patch to an empty object → the desk renders from a partial seat object: no
  `render_state`, so it draws as the client's default, which is a desk showing a state the server never
  reported. Assert the **rendered** desk, not the internal object.
- **Second RED:** animate the insert as an arrival → a seat that came online hours ago walks in the
  moment the client notices it.

---

## 12. Every number, and where it comes from

One table, so a reviewer can audit the arithmetic without reading the prose and a future change can find
every number that moves with it.

**Cited** = D1's, D2's or the plan's number, used unchanged and not re-derived here. **Derived** =
computed from another number in this table or in D2. **Chosen** = a judgement call, with its reasoning
and what would re-derive it. **Measured** = produced by evaluating a function this document publishes.

| Value | Number | Basis | Where |
|---|---|---|---|
| Feed heartbeat | 15 s | **Cited** — [D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed). **It is now the cadence of a rendered element and not only of the feed indicator**: [§ 6.2](#62-the-animation-table--the-closed-set) A17 steps the wall clock and the sky on it, which is what fixes that clock at **minute** resolution — a second hand advancing in 15 s jumps is the *looks broken* that gets repaired with a timer. If D2 ever moves this number, the clock's resolution is the second thing to re-derive | [§ 2.4](#24-the-clock-and-every-age-on-the-page), [§ 6.2](#62-the-animation-table--the-closed-set) |
| Feed presumed dead | 45 s | **Cited** — D2 § 8.3, three heartbeat intervals | [§ 9](#9-failure-paths-and-their-observables) |
| REST poll while the feed is down | 10 s | **Cited** — [D2 § 2.2](FLEET-STATE.md#22-fail-posture-per-path) | [§ 9](#9-failure-paths-and-their-observables) |
| Delta coalescing tick | 250 ms | **Cited** — D2 § 8.3, below the ~300 ms at which a human notices latency | [§ 6.1](#61-the-rule-and-what-a-loop-is-allowed-to-mean) |
| **Loop frame rate** | **4 fps** | **Derived** — one frame per 250 ms coalescing tick, so no loop on the floor can appear more informative than the fastest rate at which the wire can inform it. It is fixed across every loop and every seat, because a rate that varied would encode a quantity nothing sent | [§ 6.1](#61-the-rule-and-what-a-loop-is-allowed-to-mean) |
| **Gauge tween and glyph cross-fade** | **250 ms** | **Derived** — the coalescing tick again: a tween longer than the interval between two deltas would still be animating the previous value when the next arrives | [§ 6.2](#62-the-animation-table--the-closed-set) |
| **Age readout refresh** | **1 s** | **Chosen** — the unit the smallest rendered age uses. Slower shows a second that has passed; faster repaints for nothing | [§ 2.4](#24-the-clock-and-every-age-on-the-page) |
| Seat `stale` / `offline` thresholds | 300 s / 900 s | **Cited** — [D2 § 4.5](FLEET-STATE.md#45-link-states), D1's numbers | [§ 7.1](#71-the-render-per-state) |
| `catching_up` threshold | `oldest_unsent_age_s > 300` | **Cited** — D2 § 4.5; the threshold is D2's derivation input and this row renders nothing from it (**`named-not-rendered`**) | [§ 5.1](#51-the-desk) |
| `fold_lag` badge | 60 s | **Cited** — [D2 § 2.3](FLEET-STATE.md#23-a-frozen-fold-is-the-dangerous-degradation) | [§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy) |
| `fleet.fold = stalled` banner | 300 s | **Cited** — D2 § 2.3 | [§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy) |
| Retired-seat render window | 14 days | **Cited** — [D2 § 4.10](FLEET-STATE.md#410-retirement-is-a-rendered-state) | [§ 3.5](#35-retirement-and-the-only-removal) |
| Timeline page size / ceiling | 50 / 200 | **Cited** — [D2 § 8.2](FLEET-STATE.md#82-rest) | [§ 4.3](#43-the-desk-drill-down-panel) |
| Snapshot size at 50 seats | ~91 KB | **Cited** — [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object), measured there | [§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold) |
| `render_state` members | 10 | **Cited** — [D2 § 4.2](FLEET-STATE.md#42-render-precedence); every one has a render | [§ 7.1](#71-the-render-per-state) |
| `unknown_reason` members | 7 | **Cited** — [D2 § 4.3](FLEET-STATE.md#43-the-derivation-function); one glyph, seven sentences | [§ 7.1](#71-the-render-per-state) |
| `badges` bound | 18 | **Cited** — D2 § 8.2.1, D1's 12 plus D2's 7 less the shared `epoch_reset`; every one has a render | [§ 7.2](#72-badges-every-member-has-a-render) |
| `reporter.selftest_failed` bound | 8 | **Cited** — D2 § 8.2.1 | [§ 5.2](#52-the-drill-down) |
| Subagent title bound | 120 B | **Cited** — [D1 § 4.4](EVENT-SCHEMA.md#44-size-caps-and-their-derivations), which derives it as "a dispatch description is 3–8 words" and sizes it to "the drill-down panel's one-line intern label" | [§ 8](#8-interns--subagent-rendering-and-the-cap) |
| `install_id` / `seat_id` bounds | 32 B / 48 B | **Cited** — [D1 § 3.1](EVENT-SCHEMA.md#31-the-seat-config-file)'s slug patterns | [§ 3.1](#31-the-keys-and-why-they-are-the-only-ones) |
| Worst-case delta at the cap of 8 | **6,112 B** | **Cited** — D2 § 8.2.1, measured by serializing [D2 § 8.3.2](FLEET-STATE.md#832-worked-worst-case-delta) | [§ 8.1](#81-the-cap-stays-at-8--the-arithmetic-and-the-reason) |
| Feed message bound | **8,192 B** | **Cited** — D2 § 8.3 | [§ 8.1](#81-the-cap-stays-at-8--the-arithmetic-and-the-reason) |
| Spare under the bound | **2,080 B** | **Derived** — 8,192 − 6,112 | [§ 8.1](#81-the-cap-stays-at-8--the-arithmetic-and-the-reason) |
| Each further subagent element | **263 B** | **Cited** — D2 § 14 item 9, measured there: a 262 B element plus its comma | [§ 8.1](#81-the-cap-stays-at-8--the-arithmetic-and-the-reason) |
| Further elements that fit | **7** | **Derived** — ⌊2,080 ÷ 263⌋ | [§ 8.1](#81-the-cap-stays-at-8--the-arithmetic-and-the-reason) |
| The cap could reach / its worst case | **15** / **7,953 B** | **Derived** — 8 + 7; 6,112 + 7 × 263 | [§ 8.1](#81-the-cap-stays-at-8--the-arithmetic-and-the-reason) |
| A cap of 16 breaches by | **8,216 B**, 24 B over | **Derived** — 7,953 + 263 against 8,192 | [§ 8.1](#81-the-cap-stays-at-8--the-arithmetic-and-the-reason) |
| **The chosen cap** | **8** | **Chosen** — the drill-down reads the uncapped detail response, so the array's only consumer is the floor's side table; the spare is worth more unspent. What moves it is measurement after P3 | [§ 8.1](#81-the-cap-stays-at-8--the-arithmetic-and-the-reason) |
| FNV-1a-32 constants | offset 2166136261, prime 16777619 | **Cited** — the published FNV-1a-32 parameters; chosen for being short enough to re-implement from this line alone | [§ 3.2](#32-the-desk-slot-function) |
| Desk slots in the shipped `aimla` map | **12** | **Chosen** — 3× the install's four seats (`docs/PLAN.md § 5`'s rollout order), which leaves room for the Windows validation seat and the next few without an edit | [§ 3.2](#32-the-desk-slot-function) |
| The worked slot assignment | 0 · 2 · 3 · 7 | **Measured** — FNV-1a-32 of the four keys, mod 12, evaluated by `tools/design/verify-floor.py` on every run | [§ 3.2](#32-the-desk-slot-function) |
| Collision chance per arrival | `N/S` = **1 in 3** on the shipped map | **Derived** — 4 seats over 12 slots; a map author who wants it rarer raises `S` | [§ 3.3](#33-collision-displacement-and-why-a-desk-move-is-itself-an-event) |
| Floor viewport floor | **1,280 × 800 CSS px** | **Chosen** — below it the nameplates and badge clusters are unreadable at the map's scale, so the route serves the list view instead. Re-derived once the tileset is chosen and a desk's rendered width is a measured number rather than a design intent | [§ 4.5](#45-the-viewport-rule-and-the-capability-floor) |
| **Seeded appearance dimensions** | **10** | **Chosen** — the independent draw fields of the ratified art direction (silhouette, hue, size, pattern, ears, sprout, eye style, mouth, accessory, tilt). One dimension is a palette; ten is a space, and the operator's ruling was that colour alone is not variety. **What re-derives it:** the shipped generator's own field list | [§ 10.4](#104-the-art-direction-as-a-specification) |
| **The full appearance tuple's space** | **8,064,000** | **Derived** — 7 × 16 × 5 × 3 × 4 × 5 × 4 × 4 × 5 × 3, the ten cardinalities above multiplied out | [§ 10.4](#104-the-art-direction-as-a-specification) |
| **Expected full-tuple collisions at 50 seats** | **1 in 6,583** | **Derived**, and explicitly **not** the acceptance — a birthday estimate over the space above, resting on an assumption (ten independent, uniform draws) that a **searched** salt is precisely what perturbs. § 10.4 requires the real figure to be **measured** over the shipped generator and the real roster, and the measurement is what the acceptance reads | [§ 10.4](#104-the-art-direction-as-a-specification) |
| Gate 2's embedded-literal bound | **1,024 B** | **Chosen**, and now **re-derived against a real tree rather than an intent**: the longest look-encoded run of base64's own alphabet anywhere under `resources/` is **62 B** (in `index.js`, measured 2026-08-27), sixteen times under the ceiling — and far below the smallest useful sprite sheet, so clause 2 cannot fire on the port and cannot miss a vendored one. This row previously deferred that measurement to *"the moment the port lands"*; the port landed on 2026-08-25 and the number is above. **What re-derives it:** the same measurement, whenever art is added. ⚠ **The bound is not what keeps clause 2 off legitimate SVG — the ALPHABET is** ([§ 10.1](#101-the-manifest-and-the-two-gates)): minified path data can exceed 1,024 B easily and is excluded because `.`, `-`, `,` and spaces are not base64 characters | [§ 10.1](#101-the-manifest-and-the-two-gates) |
| D2 § 8.2.1's nullable members | **36** | **Cited** — the rows [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)'s field table marks `Null? yes`; the population `fx-nulls` must cover, and the reason it is two seats rather than one | [§ 11](#11-acceptance-tests) |
| Client event-log length | **200 lines** | **Chosen** — enough to hold a reconnect storm's worth of membership and resync lines; it is a narration of the client, not a record, and D2's own surfaces hold the durable history. **What re-derives it:** the line count one measured reconnect storm writes — every line has a named producer in [§ 5.5](#55-the-clients-own-narration), so it is measurable as soon as a client exists, and a storm that fills the log is the trigger | [§ 4.1](#41-the-lobby--the-building-summary), [§ 5.5](#55-the-clients-own-narration) |

**One figure rests on an intent rather than a measurement and says so at its definition:** the 1,280 ×
800 viewport floor, which cannot be derived until the tileset is chosen and a desk has a rendered width
([§ 14](#14-open-questions-for-the-review-loop) item 7). Every other **Chosen** row states what would
re-derive it.

**Tool-checked versus hand-verified.** `tools/design/verify-floor.py` is **this document's** verifier and
it ships with this change. It is a fourth, separate script: `verify-event-schema.py`,
`verify-harness-facts.py` and `verify-fleet-state.py` hard-code their own documents and are **not
modified here**, because widening a working guard to a second document is a change to that guard and
belongs in its own round.

| Check | What the tool re-derives | Status |
|---|---|---|
| **G1 animation totality** | every animation id named anywhere in this document against [§ 6.2](#62-the-animation-table--the-closed-set)'s rows, both directions; and every row's driving fact against the fields and message types D2 declares | **tool-checked** |
| **G2 source-field closure** | **Two halves, and the row names the tables rather than the section numbers, because a section number is what let this row over-claim for two revisions.** *(a)* every field named in the source column of [§ 5.1](#51-the-desk), [§ 5.2](#52-the-drill-down), [§ 5.3](#53-the-fleet-on-both-screens), [§ 6.2](#62-the-animation-table--the-closed-set)'s driver column and [§ 4.3](#43-the-desk-drill-down-panel)'s panel table, against [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)'s field table, § 8.2.4's fleet object, § 8.2.3's `detail` and § 8.3's message types. *(b)* every backticked field-shaped token in the prose columns of **[§ 7](#7-degradation--how-a-degraded-seat-is-unmistakable)'s seven tables** — § 7.1's two, § 7.2's badges, § 7.3's currency table and § 7.6's three — classified against five re-derived vocabularies: a D2 field, the **leaf** of one, a member of any of the six enum sets this document publishes, a [D1 § 9.3](EVENT-SCHEMA.md#93-degradation-counters) counter name, or one of D1's 14 event kinds. A token in none of the five is a field this document invented. Half (b) exists because half (a)'s five tables contain **no § 7 table**, so a fabricated D2 field planted in § 7.1, § 7.2 or § 7.6 left this gate green while the same fabrication in § 5.1 red it. Its control is a **capability test rather than a token count** — the classifier is fed a fabricated field on every run and must reject it — because three of the seven tables name no field at all today, which is a property of the document and would make a count floor either vacuous or wrong. Plus the **residue** — D2 fields this document renders nowhere — printed rather than counted as a pass | **tool-checked** |
| **G3 cap arithmetic** | 6,112 / 8,192 / 263 / 2,080 / 7 / 15 / 7,953 / 8,216 / 24 re-computed from the **three** inputs (worst case, bound, per-element), and those three checked for **presence in D2** — anywhere in D2, not at a named statement, which is the narrower claim the tool can actually make and is why "is a Cited number true at its D2 home" stays on the hand-verified rows below | **tool-checked** |
| **G4 § 12 ↔ definition site** | each row's number as a whole numeric token at the section it cites, then **perturbed** to prove the match can fail for that row; the residue — numbers some other value would also have matched — printed individually | **tool-checked**, with its residue printed |
| **G5 acceptance-test closure** | every fixture named in a test against the fixture table, both directions; every test having a **RED**; the AT ids contiguous from 1 with no gaps or duplicates. **Plus the build-order half:** every test is gated by at least one [Appendix B](#appendix-b--what-an-implementer-builds-from-this) row, every row gates a test that exists, and **every declared half of every test is gated at or after the step that builds every artifact that half declares it reads** ([§ 11](#11-acceptance-tests) owns the rule; this row describes the gate). **Three** populations re-derived, none stored: the artifact→step map from Appendix B's own bold Artifact names, what each test reads from its `Reads:` clauses, and which half a gate gates from the Gate cell's own qualifier — so renumbering the build order, renaming an artifact or re-splitting a test all move the check with them rather than leaving a stored `10` behind. **An unqualified gate mention gates every half**, which is what stops a step-10 co-gating from discharging a step-3 mention on the same test — the hole a `max()` over the gate steps left open. Residue printed in full: an artifact a test's body emphasises and its `Reads:` clause does not declare. **Plus the record's name:** the phrase *the lobby log* reds wherever it is **used** rather than quoted — the record is the client protocol's artifact ([§ 5.5](#55-the-clients-own-narration)) and the lobby is one renderer of it, so naming the renderer is what gates a test on a screen built six steps after the thing it reads; a wording this document must quote in order to forbid is marked with emphasis, and the recognizer is wrap-tolerant because a phrase broken over a line break is how the last one hid. **Plus the log-schema half:** [§ 11](#11-acceptance-tests)'s animation-log row tuple against the per-class field table beside it, and that table's row count against the number the prose states — one schema, two homes, three revisions so far, and the count read `four` against five rows for a whole revision. **Plus the episode-walk half:** the `fx-clear-trace` walk's own `(A_n, episode N)` pairs re-added into an episode count and a row count and checked against the sentence beneath it, in both directions, plus a `left` pair with no `entered` pair before it — the walk is indented under a list item, which is why nothing had read it while the sentence beside it said *six* and *eleven* over a table yielding five and nine | **tool-checked** |
| **G6 Appendix A** | its stated counts against both row counts, and the **marker population of D2 and of D1** against the sections Appendix A cites from an upstream-attributed position. The recognizer is not the literal `D3` alone — it is `D3` **plus the render-directed phrasings upstream actually uses**: *rendered in the drill-down*, *the drill-down can say*, *visible in the drill-down*, *must render*, *renders as quiet*, *readable in its drill-down*. Grepping for `D3` alone is what let [D2 § 4.7](FLEET-STATE.md#47-which-clock-each-ceiling-is-measured-from) and [§ 4.8](FLEET-STATE.md#48-what-may-never-mint-a-state) place three render obligations this document neither listed nor discharged. **Each phrase is matched wrap-tolerantly, across line breaks**, and that is the load-bearing half rather than a nicety: the scan was line-scoped, [D1 § 12.2](EVENT-SCHEMA.md#122-error-responses) is typeset with its phrase broken over a wrap, and adding the phrase to a line-scoped list would have left the check clean over it exactly as before | **tool-checked**, with a stated limit: an obligation phrased in none of those forms is still not grep-derivable, so the tool prints the semantic remainder **row by row** rather than as a count |
| **G7 state and badge render closure** | **six** member sets — `render_state`, `unknown_reason` and the 18 badges from D2, `link_state` and `activity_state` from [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)'s bounds cells, and `api_error_type`'s twelve from [D1 § 6.4](EVENT-SCHEMA.md#64-turnend), which is where D2 sources it — each re-derived upstream and set-differenced against this document's tables in **both** directions: a member with no render, and a render for a member no input can select. The `link_state` half is what makes `disabled`'s absence from [§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim) impossible to leave in | **tool-checked** |
| **G8 desk-slot worked example** | the four hashes, their moduli and the assignment, re-computed from [§ 3.2](#32-the-desk-slot-function)'s stated function; and the collision example of [§ 3.3](#33-collision-displacement-and-why-a-desk-move-is-itself-an-event) | **tool-checked** |
| **G9 the delivery contract** | [D2 § 6.5](FLEET-STATE.md#65-the-fold)'s **ten** non-version-bearing members, re-derived from that section's own table, against every render row that sources one — **per member, not per row**: each member must carry a marker **legal for that member**, where `dark-only` is granted to `delivery.last_receipt_at` alone (re-derived from § 6.5's own carve-out sentence, not written into the tool) and `fetch-fresh` governs the rest; a row carrying `dark-only` must source that member; and a row of a table that renders on the **desk** — [§ 5.1](#51-the-desk) and [§ 7.1](#71-the-render-per-state), the two the column map flags as desk surfaces — must carry `dark-only` specifically for it, because on the desk that is the marker in force. The row-scoped test this replaces could be satisfied by a marker belonging to a **different surface** — § 5.1's receipt-age row survived deleting `dark-only` because the same row mentions `fetch-fresh` for the drill-down. Also: this document must cite § 6.5 at all. A field-existence check cannot see a delivery contract — all ten exist in § 8.2.1, which is why G2 was clean over a receipt age that freezes on every live desk. **And the rule's own statement of its scope is closed against the gate, both directions:** [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s marker-rule sentence enumerates the tables the rule holds over, which is a second home for this gate's column map and is the home that went false twice — five tables named while § 5.6 sat outside the gate, seven named while § 7.1 rendered the receipt age on the desk. Neither side is stored: the map is the tool's, the list is read out of the document. **The table population is DERIVED, not listed:** every markdown table in this document is found structurally, a table under a § 5 heading that the gate has no source column for **reds** rather than being skipped, and membership in that population is keyed on a row's **line number** rather than on its text, so a row byte-identical to a checked one cannot be pasted into an unchecked table and test as already-checked. A table row anywhere else naming one of the ten **reds** unless it declares itself **`named-not-rendered`** ([§ 2.4](#24-the-clock-and-every-age-on-the-page)) — a marker in such a row exempts nothing, and the only two rows entitled to carry one without rendering are found by **role**: the marker table's own rows, whose key cell *is* the marker, and this table's rows, found by this table's header | **tool-checked**, with **one** stated limit: **prose**. The gate held a list of five table headers until § 5.6 was added with six ten-sourcing rows and no marker — the list did not contain it, nothing reddened, and § 2.4 went on claiming the rule held over every § 5 row. A stored population does not fail visibly; it under-reads. Both halves of that are now inverted — the population is re-derived every run and the rows that used to be *announced* as outside it are **failures** unless the document declares them — and the second finding of the same shape, § 7.1's two desk renders of the receipt age, is why the outside-the-map rule no longer accepts a bare marker token: a token-presence test admits a row naming the marker for a surface it does not render on. What remains outside is a bookkeeping member reintroduced in **prose**, and every prose mention is printed **in full**, leaf spellings included. Not a capped sample: the residue printer used to print the first twelve of nineteen beside the true count, which reads as a complete list and is how the seven it hid stayed hidden |
| **G10 null-render closure** | [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)'s `Null? yes` column — all 36 members — set-differenced against [§ 5.6](#56-the-null-render-for-every-nullable-member)'s table in **both** directions: a nullable member with no stated null render, and a null render for a member D2 does not mark nullable. Plus § 12's own published count of that population against the column it counts | **tool-checked** |
| Whether a rendering is *good* | — | **hand-verified**, and it is a review question this document cannot mechanise: the tool checks that every rendered fact has a field and every animation has an event, never that the floor is legible |
| Whether a **Cited** number matches what D2 says | — | **hand-verified**: the tool checks the number's presence at its D3 home, not its truth at D2's |

**What the tool deliberately does not do.** It does not check that a **quotation is verbatim at its
source** — every span inside quotation marks in this document is **hand-verified** against
[D1](EVENT-SCHEMA.md) or [D2](FLEET-STATE.md), under [§ 1.3](#13-the-boundary-stated-as-a-rule)
corollary 3, and mechanising that check is a named gap rather than an oversight: it needs a recognizer
for which spans are quotations at all, and this document's quotation marks also carry rendered
strings, enum values and self-quotes. It does not render anything, it does not check prose for
meaning, and it does not verify that the client behaves as specified — that is
[§ 11](#11-acceptance-tests)'s job, at build time, against code that does not exist yet. Where a guard
class could not be mechanised without building the client, the tool implements the nearest checkable
invariant and **says so in its own output** rather than reporting a clean over a population it never
measured.

---

## 13. Decisions taken, revisable at review

This document contains no placeholders and no deferred decisions. Where a call was genuinely
contestable it was **made**, and it is listed here with the alternative and the cost of being wrong, so
review can reverse it deliberately rather than discover it later.

| # | Decision | Alternative considered | Why this one | Cost if wrong |
|---|---|---|---|---|
| 1 | **The client derives no state; the seven things it computes are enumerated as a closed list** ([§ 2.1](#21-the-seven-client-computed-values-closed)) | let the client compute what it needs and rely on review to catch the rest | A closed list is checkable against a candidate computation; "only presentation" is not. D2 already refuses a re-derived `render_state` for the same reason — a second copy of a precedence is free to drift, and the first thing it drifts on is `stale`-vs-`idle` | a genuinely-presentational computation someone wants is a review conversation instead of a commit. That is the cost, and it is the point |
| 2 | **The animation table is closed, and an animation without a row is a defect** ([§ 6.2](#62-the-animation-table--the-closed-set)) | state the honesty principle as a principle and trust it | A principle nobody can fail is a principle nobody keeps. A closed table plus the animation log makes the rule a test ([AT-D3-1](#at-d3-1-no-animation-without-its-event)) rather than an intention | every new effect costs a table row and a driving field. A flourish with no field is exactly what is being refused |
| 3 | **No motion that is neither held by a delivered field nor caused by a delivered message** — breathing, blinking, NPCs and moving scenery are the named examples, and they stay forbidden as examples ([§ 6.3](#63-forbidden-forms-named-so-they-cannot-be-written-in-good-faith)). **Amended 2026-08-27**, under the ratified art direction: this row read *no ambient life at all*, which forbade the operator-ratified blink-while-busy and sleeping-idle renders by naming two motions rather than the property that made them wrong | permit decorative motion that carries no state; or, at the amendment, carve the ratified motions out as named exceptions | Motion is the floor's vocabulary. A viewer cannot tell decorative motion from state-bearing motion at a glance, which is the range this screen is read at, so decoration would spend the vocabulary on nothing. **The property is what does that work**, and a name never did: a blink in every state is indistinguishable from a signal, and a blink held by `render_state == "working"` **is** a signal. An exception list would have said which motions were allowed rather than why, and the next one would have had to be argued from precedent | a still floor looks still. That is accepted: a still floor **is** a still fleet, which is the reading we want. **The amendment's own cost:** the rule is now a property a reviewer must apply rather than a list they can check, so the door is [§ 6.2](#62-the-animation-table--the-closed-set)'s closed table — a motion is admitted by being written into a row with its driving field, and by nothing else |
| 4 | **A state-held loop is permitted, at a fixed rate that encodes nothing** | fire an animation only on edges, never hold one | A `working` desk must look different from an `idle` one at a glance and across a room, and a pose alone is weaker at distance than a pose that moves. The rate is pinned to the coalescing tick so the loop cannot claim more than the feed can carry | a loop is running while the underlying claim is bounded only by D2's ceilings. That is why every loop stops the moment its state's currency is in doubt ([§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim)) |
| 5 | **A snapshot, poll, resync, insert or reconnect never animates** ([§ 6.5](#65-a-snapshot-never-animates)) | animate the difference between the old and new object | The difference between two client states is not a fact about a seat. Animating it would play an arrival at every desk on every reconnect and make the floor's motion mean "the network hiccupped" | a state change that arrives via a snapshot rather than a delta is not announced. It is rendered — just not narrated — and the drill-down and the log carry the detail |
| 6 | **The desk slot is a pure hash function of `(install_id, seat_id)`** ([§ 3.2](#32-the-desk-slot-function)) | sorted order; arrival order; a server-assigned slot | Sorted order shifts the whole floor when a seat is provisioned; arrival order is not a function of the rendered set, so two browsers disagree; a server slot is a field this document may not mint. The hash gives every client the same answer with no stored state at all | an arrival can displace an incumbent on a collision — bounded to the chain, rendered as a move, and with its frequency stated as `N/S` ([§ 3.3](#33-collision-displacement-and-why-a-desk-move-is-itself-an-event)) |
| 7 | **A desk move is an event and is animated as one** | re-lay the floor silently on the next render | The rule that governs everything else on this floor is that nothing moves without a cause. A silent re-layout would be the one exception, on the one occasion an operator is most likely to think they misread the screen | one more animation row, and one more thing that can be got wrong |
| 8 | **An unknown seat in a delta is FETCHED, never patched** ([§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold)) | apply the patch as an insert; or ignore the delta until the next snapshot | A patch is a shallow merge over an object the client may not hold, so the insert would be a seat object with holes, and a hole renders as *nothing is happening*. Ignoring it leaves a live seat invisible until a reconnect | one HTTP request per newly-seen seat, ever. [§ 14](#14-open-questions-for-the-review-loop) item 2 is the membership message that would remove even that |
| 9 | **Install membership is snapshot-only; the snapshot that discovers one is triggered by a rendered disagreement, never by a timer; and a discovered install is then ADMITTED rather than merely rendered** ([§ 4.1](#41-the-lobby--the-building-summary), [§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold), [§ 2.2](#22-connect-snapshot-deltas)'s `ADMIT`) | poll the snapshot on a timer; or leave discovery to a reconnect and the manual refresh alone | A discovery poll invents a cadence D2 does not state and fetches the whole fleet on a schedule. But `fleet.seats_total` already rides every heartbeat, so the client can **prove** its population is short within 15 s — and a floor that renders *the client holds 3 of 4 seats* and then does nothing about it is a floor that reports a defect it could have fixed with one request. Rendering *membership as of HH:MM:SS* keeps the staleness visible in the meantime | one snapshot fetch per distinct disagreement, **plus one ADMIT fetch per install ever admitted** — bounded by how often the fleet's own count moves and by how often an install is provisioned, not by a clock. An earlier draft of this row said a new install stays invisible until a reconnect or a manual refresh; that was contradicted by the discrepancy check two sections away, and the check is the half worth keeping. A later draft made the discrepancy fetch the discovery path and stopped there — **discovery without a subscription is a one-frame photograph**, and the per-distinct-`(N, M)` rule guaranteed there was no second chance at one, which is why the subscribe-then-fetch-then-drain ordering is now a named primitive every entry path cites rather than three steps living inside the connect sequence |
| 10 | **The removal of a desk happens only on a *full* snapshot apply — never on `ADMIT` (b)'s scoped read of one install** | remove on `render_state: "retired"`, or on any signal | A removal driven by an absence is the inference this design refuses everywhere else. Only a fresh, complete population can honestly say a seat is no longer in it | a seat retired more than 14 days ago lingers until the next snapshot. It renders as `retired` throughout, which is true |
| 11 | **The subagent array cap stays at 8** ([§ 8.1](#81-the-cap-stays-at-8--the-arithmetic-and-the-reason)) | raise it to 15, the largest value the 8 KiB bound admits | The drill-down reads the uncapped detail response, so the array's only consumer is the floor's side table, where 15 stools is D2's "a list, not a desk" at a smaller number; and the 2,080 B of spare is the margin the next field addition needs | a fleet that routinely runs more than 8 concurrent dispatches reads *+N more* on the floor and opens the panel for the detail. Both halves of what would change this are measurable after P3 |
| 12 | **`prefers-reduced-motion` is a first-class rendering with its own column** | disable animation and accept that some states collapse | Two states distinguished only by motion are one state in a screenshot and one state to any viewer with motion disabled — and screenshots are how most of this floor will be reviewed | every animation row owes a static form, which is one more column to keep true. It is checked by [AT-D3-13](#at-d3-13-every-state-is-legible-without-motion) for the rows a `render_state` selects, and by [AT-D3-6](#at-d3-6-the-feed-dying-is-visible-within-45-s)'s floor half for [A17](#62-the-animation-table--the-closed-set)'s room render, which no state selects and which no fixture of AT-D3-13 fires — **one test does not cover the column, and saying which covers what is what stops the uncovered row from being assumed** |
| 13 | **A null is rendered as *not reported*, never as a zero** ([§ 7.5](#75-what-a-degraded-desk-may-never-look-like)), and **[§ 5.6](#56-the-null-render-for-every-nullable-member) states the behaviour per member for all 36** rather than leaving the rule to be applied by guess | coalesce nulls to sensible defaults so the layout never shifts; or state the rule and leave each member's rendering to the implementer | A zeroed gauge is a measurement the wire never made; a placeholder task title is a claim nobody sent. `docs/KANBAN.md § G-1`'s clean zero is the same defect one layer out | the layout must accommodate absent elements, which is a design constraint on the desk rather than a rendering convenience — and 36 stated null renders are 36 more cells a change must keep true, which is what G10 is for. **The per-member table is the half that was missing**: the headline rule was stated and certified from R1, while two dozen members it governs had no stated behaviour, so the implementer reaching for the obvious default would have written the very zero it forbids |
| 14 | **The floor requires 1,280 × 800 and falls back to a list, not a scaled floor** | scale the map to the viewport | A floor whose nameplates and badges are unreadable shows state without letting anyone read it, which is worse than the honest list of the same facts | small viewports get no floor. The list carries every fact, and the number is re-derived once a desk has a measured width |
| 15 | **No framework, renderer or bundler is specified** | pin the stack so the implementer has one less decision | None of this document's properties depends on one, and a spec that pinned a stack would expire with it. What *is* pinned is the asset pipeline, because that is where a licence violation enters | two implementers could make different stack choices. Neither can make different **honesty** choices, which is what this document is for |
| 16 | **A seat's appearance is a pure function of the seat key, and every asset declares its origin** ([§ 10.2](#102-characters-the-munder-difflin-port), [§ 10.4](#104-the-art-direction-as-a-specification)). **Amended 2026-08-27:** this row read *character art is generated from the seat key, never vendored*, and mechanised the second clause as Gate 2's absence. The ratified direction ships original high-resolution art as files, so the absence is gone; **the identity property is not, and it is the half that was always load-bearing** | vendor a sprite sheet and map seats onto it; or, at the amendment, keep the absence and let the art land outside the asset trees | D-07 permits the generator (MIT) and forbids the upstream's commercial tilesets, which is untouched. Deriving appearance from `(install_id, seat_id)` is what makes a seat look the same on every browser with **nothing stored** — the same property the desk slot has — and that is independent of whether the drawing is code or a file. Keeping the absence would have pushed the art to a tree no gate watches, which is strictly worse than admitting it under a provenance row | Gate 2 no longer proves an absence, so it no longer refuses the shortcut for free — [§ 10.1](#101-the-manifest-and-the-two-gates) names in full what that costs and what stands in its place. **And the identity clause is now the one that can be broken quietly**: a special-cased seat looks correct on the machine where the special case lives, which is why [§ 10.4](#104-the-art-direction-as-a-specification) forbids it by name rather than by implication |
| 17 | **Provenance is a build gate, not a document** | keep `docs/ATTRIBUTION.md` current by discipline | An attribution file kept by discipline is one an asset can be added without. Gate 1 makes the missing row fail the build, which is the only moment it is free to fix | every asset addition costs a manifest row and a hash |
| 18 | **The status strip claims *live* only with a fresh feed message AND a REST response newer than the last `401`** | trust the socket, since an authorized handshake opened it | D2 refuses machine tokens on the socket precisely because an open connection has no revocation story — and the browser's session has the same property, which D2 does not address ([§ 9](#9-failure-paths-and-their-observables) F7) | the claim is slightly conservative on a client that has made no REST call recently. Erring toward *not live* is the correct direction for this product |
| 19 | **A verifier ships with this document** | leave it to the build phase | D1 and D2 both shipped one, and the classes it catches — an animation with no driver, a field this document renders that D2 does not send, a state member with no render, an arithmetic claim that drifted — are exactly the single-surface edits to multi-surface facts a set difference catches in milliseconds and a reader catches on the third pass, if ever | one more script to keep true, and every figure here is now a figure a change must move in all its homes at once |
| 20 | **The animation table carries two classes — `edge` and `held` — and the animation log records them under different causality rules, a `held` render's entry and exit paired by an `episode_id` rather than by the animation and seat.** The class split is [§ 6.2](#62-the-animation-table--the-closed-set)'s and the log schema is [§ 11](#11-acceptance-tests)'s; this row records the decision and states neither a second time | one schema for all seventeen rows: one *cause* column, one totality rule, one causality sentence — and, at an earlier revision, one schema for a held render's entry and its exit | Under one schema the halves contradict each other on this document's own headline fixture. [§ 6.1](#61-the-rule-and-what-a-loop-is-allowed-to-mean) rule 2 holds a loop for as long as a delivered field says so, and [D2 § 8.2.2](FLEET-STATE.md#822-worked-snapshot)'s snapshot delivers a `working` seat — so a correct client starts a loop where there is no message to record as its cause, and [AT-D3-1](#at-d3-1-no-animation-without-its-event)'s *every row has a cause* could not hold beside [§ 6.5](#65-a-snapshot-never-animates)'s *a snapshot fires nothing*. The split keeps the strict rule where it is true — an edge animation with no causing message is exactly the defect the honesty principle names — and gives held renders the rule that is true of them: held by a delivered field, logged with the `state_version` that delivered it | one more column in [§ 6.2](#62-the-animation-table--the-closed-set) and four more fields in the log (`phase`, `episode_id`, `at`, and `cause`'s per-phase rule), and a reviewer must decide which class each new row is. The alternative was an implementer choosing between a floor that goes static after every reconnect and a log whose totality claim no test could satisfy. **The `phase` half was added after the enter-and-leave rule re-opened that same unsatisfiability one class down**: an exit row is not held by anything and is drawn as nothing, so under one held-row schema [AT-D3-1](#at-d3-1-no-animation-without-its-event)'s *the hold condition holds in the cause object* was false for every exit row on a correct client — and repeating the entering version instead made two rows identical in every field, from which *for how long* was unrecoverable. **`episode_id` is the third such widening and the one that ends the sequence**, because it is the first to give the log an identity for the thing the questions are actually asked about. Each of the first two — the class split, then `phase` — fixed the shape of a row while leaving the log keyed on `(animation_id, install_id, seat_id)`, a triple that is not unique per episode on this document's own headline fixture: `fx-clear-trace` enters A4 twice on one seat, so *which exit ended which entry* and *for how long* had no answer the log could give. Adding a fourth field to the row was cheaper than the alternative on offer, which was to declare the fixture out of scope for the pairing predicate and leave the headline test asserting less than it claims |
| 21 | **The ratified wall clock and day/night sky advance on `feed.heartbeat`, so they stop when the feed does** ([§ 6.2](#62-the-animation-table--the-closed-set) A17). **Operator ruling, 2026-08-27, card#7341**, taken between three stated options | **(A)** ship them **static**, set once per render — what [§ 10.4](#104-the-art-direction-as-a-specification) required until this ruling; **(B)** carve an exception into [§ 6.3](#63-forbidden-forms-named-so-they-cannot-be-written-in-good-faith) for viewer-clock decoration, keeping the reference's 10 s interval | Option B is the widening the art amendment existed **not** to do, and it is not a small one: a timer-driven clock is a mover that **keeps moving after the feed dies**, so the page never goes still and [AT-D3-6](#at-d3-6-the-feed-dying-is-visible-within-45-s) loses the observable it asserts — a named acceptance test's instrument, spent on decoration. Option A is honest and costs the reference its sense of a place. **The heartbeat driver is neither a compromise nor a third-best**: the clock earns an ordinary [§ 6.2](#62-the-animation-table--the-closed-set) row driven by a message D2 declares, and **a stopped clock is A14's claim in the form every human reads instinctively**, so the element that would have destroyed the feed-down signal now carries it. The visual cost is near nil — a wall clock stepping every 15 s at floor zoom is indistinguishable from a continuous one, and the sky is a slow gradient | **The clock is wrong by up to 15 s and is stale by construction whenever the feed is down** — accepted, and it is why the clock carries no *as of* stamp and is never an authority on the time ([§ 5.5](#55-the-clients-own-narration)). The real cost is that a **frozen clock looks like a bug**, and the repair a maintainer reaches for is the interval this ruling refused; the whole of the mitigation is that the reasoning is written at [§ 6.2](#62-the-animation-table--the-closed-set), the driven-versus-read distinction at [§ 6.3](#63-forbidden-forms-named-so-they-cannot-be-written-in-good-faith), and a **RED for that exact edit** at [AT-D3-6](#at-d3-6-the-feed-dying-is-visible-within-45-s) |

---

## 14. Open questions for the review loop

Each names what it blocks, what this document does in the meantime, and what would close it. Items 1, 2,
5, 9, 10, 12, 13 and 14 are **D2 amendment needs**: this document does not edit D2
([§ 1.3](#13-the-boundary-stated-as-a-rule)), so they are written here as requests. In every one, D3
states a well-defined rule of its own and says which reading it took — an amendment need is never a
reason to leave two readings live.

1. **⇢ D2 — the drill-down's two endpoints carry no field tables.**
   `GET …/timeline` is declared with its parameters and ordering but its rows have no field table and
   no renderable-kind set ([D2 § 8.2](FLEET-STATE.md#82-rest): "the seat's renderable events"), and
   `GET …/seats/{i}/{s}`'s `detail` member is described in a sentence rather than a table
   ([D2 § 8.2.3](FLEET-STATE.md#823-the-seat-detail-response)). **These are one gap, not two** — one
   fix (field tables for the drill-down's read surfaces) discharges both, and filing them separately
   would be filing one class twice. **Blocks:** the fidelity of the drill-down, not its existence.
   **In the meantime:** the timeline renders `kind` and `event_time` — the
   [D1 § 4.3](EVENT-SCHEMA.md#43-common-per-event-fields) common fields every event of every kind
   carries — plus **`received_at`, which is D2's own field and not D1's**: D1 § 4.3's table declares
   `event_id`, `schema_version`, `kind`, `event_time`, `seq`, `install_id`, `seat_id`, `session_id`,
   `data` and `oversize` and **no `received_at`**, because a seat cannot stamp a server clock. It is
   [D2 § 6.4](FLEET-STATE.md#64-ddl)'s `events.received_at`, stored `NOT NULL` on every row this
   endpoint reads. The detail-dependent panel sections render *unavailable* when the response does not
   carry them ([§ 5.2](#52-the-drill-down), [§ 9](#9-failure-paths-and-their-observables) F11).
   **The reading this document took**, stated because this section's own preamble requires it:
   `detail`'s *"open call list in full"* is read as **every open call of the seat**, and the
   drill-down's intern list is the subset carrying `agent_scope == "subagent"` / a non-null
   `parent_call_id` — the intern join [D2 § 4.8](FLEET-STATE.md#48-what-may-never-mint-a-state) stores
   those labels for, never a state rule gated on them. If the field table answers otherwise, the list's
   selection predicate changes and nothing else in this document does.
   **Closes it:** two field tables in D2 § 8.2, in the shape § 8.2.1 already uses — the timeline's
   naming **`received_at`** explicitly, since it is the basis of every row's age and only D2 can
   promise it on that response; `detail`'s naming its members and the per-call fields each carries.

2. **⇢ D2 — the feed has no message for a seat or an install entering the population.**
   [D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed)'s message table has `seat.delta`,
   `feed.heartbeat`, `seat.retired`, `fleet.reload` and `fleet.health` — nothing for a seat's first
   appearance, and nothing at all for an install, which a connected client cannot learn about because it
   is not subscribed to a channel it does not know exists. Both states are reachable: a seat row exists
   from token-issue time. **Blocks:** nothing — [§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold)
   fetches the seat and renders the membership age for the install. **Closes it:** either a `seat.added`
   message carrying a full seat object, or a statement that the fetch is the intended path — the second
   is a one-sentence answer and would make this document's rule the contract rather than its workaround.

3. **⇢ Operator / review — where do `card#N` and `<repo>#N` resolve to?**
   [D2 § 4.9](FLEET-STATE.md#49-the-task-title-merge-and-what-is-not-specified-here) declares
   `task.ref`'s two shapes and no base URL exists anywhere in this repository. **Blocks:** the
   *"current task linked to card/thread"* half of `docs/PLAN.md § 2`'s drill-down requirement — the
   title renders, the link does not. **In the meantime:** a configured base URL per shape, and plain
   text when none is configured ([§ 5.2](#52-the-drill-down)); a guessed URL is a link that goes
   somewhere wrong. **Closes it:** two base URLs in the app's configuration, and a ruling on whether
   the board's is fleet-internal (it is a private kanban) — which is why this is an operator question
   and not an implementer's.

4. **⇢ Review / operator — the proposal's three-tier status fallback (carrying
   [D2 § 14](FLEET-STATE.md#14-open-questions-for-the-review-loop) item 3 forward).**
   The proposal is not in this repository and D2 declined to invent its tiers. This document renders
   whichever tier `task.source` names and does not invent them either. **Blocks:** tiers 1 and 2 of the
   task title — a floor built today shows telemetry-derived titles everywhere, which is *visibly* a
   floor whose board integration is dark rather than one that looks fine ([D2 § 4.9](FLEET-STATE.md#49-the-task-title-merge-and-what-is-not-specified-here)).
   **In the meantime:** tier 3 only, with `task.source` rendered so the tier is legible.
   **Closes it:** the proposal's text, plus D2's own ruling on where the two producers are designed.

5. **⇢ D2 — what happens to an open browser socket when the MFA session expires?**
   [D2 § 9](FLEET-STATE.md#9-read-side-authentication) refuses machine tokens on the socket because "a
   long-lived socket authenticated by a bearer token needs a revocation story *on an already-open
   connection*" — and then authorizes the browser's socket once, at the handshake, with no rule for
   what happens when its session expires. The argument that excludes one consumer applies unchanged to
   the one that was admitted. **Blocks:** nothing visible — but the residual is real: a client cannot
   detect expiry on an otherwise-silent socket. **In the meantime:**
   [§ 9](#9-failure-paths-and-their-observables) F7 — any `401` on any surface tears the socket down,
   and the *live* claim requires a REST response newer than the last `401`
   ([decision 18](#13-decisions-taken-revisable-at-review)). **Closes it:** a rule in D2 § 9 — periodic
   re-authorization on the channel, or an explicit statement that a session's expiry is enforced only
   at the next request and the socket may outlive it.

6. **⇢ Operator — is a floor an install, or a PM?**
   D-01 says *floor per PM, solo agents share a floor*; D2's channel, snapshot grouping and future ACL
   attachment point are all **per install**. The two agree exactly when installs are provisioned one per
   PM seat-group with solos sharing an install, and disagree the moment two installs' solo seats are
   meant to share one floor — which would need one floor to subscribe to two channels and would make the
   floor's identity something other than the `install_id`. **Blocks:** nothing today (aimla is one
   install). **In the meantime:** **one floor is one install**
   ([§ 3.1](#31-the-keys-and-why-they-are-the-only-ones)), and the mapping D-01 describes is a
   *provisioning* choice about `install_id`, which [D1 § 3.1](EVENT-SCHEMA.md#31-the-seat-config-file)
   already owns. **Closes it:** an operator ruling before a second install exists, because the answer
   changes what a floor route means.

7. **◑ HALF CLOSED — the generator's source is recorded; the tileset is still unnamed.**
   D-07 names *CC0 tilesets* and *munder-difflin's procedural generator*, and this item was opened
   because the repository recorded neither. **It is closed for the generator and open for the tileset,
   and it stays one item because it is one question — *which upstream art does D-07 mean* — asked of two
   assets.** Splitting it would file one class twice and would let the closed half's evidence read as
   though it settled the open one.

   **✅ The generator half, closed by card#7340 (2026-08-25).** The upstream repository
   (`https://github.com/chaitanyagiri/munder-difflin`), the **pinned commit**
   `eb3df9fa70b63b68495a965c45f158105e87b2e6`, the MIT licence and its reproduced notice are recorded
   in `resources/characters/LINEAGE.md` and `docs/ATTRIBUTION.md` — **in the repository, not in a
   message**, which is what this item asked for. That lineage file also records what was deliberately
   **not** taken and why: the LimeZu-bound sprite path, three ISC-derived files (ISC is permissive and
   MIT-compatible and is still **not** in [§ 10.1](#101-the-manifest-and-the-two-gates)'s closed
   allowlist, so taking them is an operator decision nobody has made), and The Office's cast identities.

   **⇢ The tileset half, still open, still an operator/review question.** No tileset is chosen and none
   is recorded. **Still blocks:** card #7341 (floor v1), and the 1,280 × 800 viewport floor still cannot
   be re-derived from a measured desk width ([§ 12](#12-every-number-and-where-it-comes-from)) —
   that derivation needs a tile size, which is the half that did not close. **In the meantime:** the
   licence allowlist, the manifest and both gates are specified, built and seen to fail
   ([§ 10](#10-art-and-assets--provenance-as-a-gate)), and the asset root is the repo-root `resources/`
   **entire**, so whichever directory the tileset lands in is covered by Gate 1 on the day it lands —
   there is no tree list to remember to extend first, and a tileset with no row fails the build.
   **Closes it:** the chosen CC0 tileset, recorded in `docs/ATTRIBUTION.md` with its source URL, author,
   SPDX identifier and hash — not in a message.

8. **✅ CLOSED — the `subagents` cap is 8.**
   [D2 § 14](FLEET-STATE.md#14-open-questions-for-the-review-loop) item 9 handed this to D3.
   [§ 8.1](#81-the-cap-stays-at-8--the-arithmetic-and-the-reason) decides it with the byte arithmetic
   re-derived from D2's measured figures: seven more elements fit and the cap **could** reach 15 at
   7,953 B, 16 breaches at 8,216 B, and the answer is still 8 because the drill-down reads the
   **uncapped** detail response, so the array's only consumer is the floor's side table. Nothing in D2
   changes; the item is answered, not amended.

9. **⇢ D2 — seven drill-down explanations D2 and D1 address to this document have no field on any
   read surface.** This is **one class, filed once**: upstream states each fact and says the drill-down
   renders or answers it, and none of them appears in
   [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)'s object or in
   [§ 8.2.3](FLEET-STATE.md#823-the-seat-detail-response)'s enumerated `detail` members, so the panel
   has nothing to render them from. Filing them separately would be filing one class six times; the
   fix is one edit to the same two tables.

   | The fact | Where D2 states the obligation | What it would answer |
   |---|---|---|
   | `sessions.compaction_open_since` | [§ 4.8](FLEET-STATE.md#48-what-may-never-mint-a-state): a compacting seat's quiet 40 s is *"still visible in the drill-down"* | *why is this desk quiet right now* — the one quiet case D2 says is explicable |
   | `calls.synthesized` | [§ 4.8](FLEET-STATE.md#48-what-may-never-mint-a-state): the flag *"is stored and rendered in the drill-down, so the anomaly is a visible flag rather than an absorbed one"* | a close that had no open — an anomaly the ledger absorbed |
   | `calls.match` (`lifo_tool_name`) | [§ 4.8](FLEET-STATE.md#48-what-may-never-mint-a-state): *"`match` is stored and rendered in the drill-down so an approximate attribution is legible as one"* | whether this call's id and duration are exact or a LIFO guess |
   | `duration_ms` / `duration_source` | [§ 4.7](FLEET-STATE.md#47-which-clock-each-ceiling-is-measured-from): *"durations rendered in the drill-down"* come from the event's own `duration_ms`, else `event_time` arithmetic, *"with `duration_source`"* | whether a rendered duration was measured or reconstructed |
   | `calls.close_source` | [§ 10](FLEET-STATE.md#10-worked-example-the-clear-trace-folded-end-to-end): `reap_session_boundary` exists *"so the drill-down can say the clear killed these, not these ended"* | whether a call ended or was killed |
   | `attention_requests.resolution` / `.resolution_source` | [§ 4.4](FLEET-STATE.md#44-activity-states-every-entry-and-exit-edge): the `server_ceiling` cause value *"exists so the drill-down can say the server cleared this"* | whether a *blocked* seat was answered or timed out |
   | the refused batch's `received_version` / `accepted_versions` | **[D1 § 12.2](EVENT-SCHEMA.md#122-error-responses)**, as required behaviour: the versions are *"readable in its drill-down"* | *which schema version this seat sent, and which this ingest accepts* — the one fact that tells an operator whether a refusing seat needs a reporter upgrade or the ingest needs an edit |

   **The seventh is D1's obligation and D2's fix**, which is why it is here and not in a table of its
   own: D1 places the render duty, D2 owns every read surface a panel can read, and
   `received_version` / `accepted_versions` appear **nowhere in D2** — not as a stored column, not as a
   `detail` member, not on the `batches_refused.<error>` counter rows that do reach the panel
   ([D2 § 7.1](FLEET-STATE.md#71-d1s-server-side-counters--where-they-live)). So this one needs D2 to
   **store** the pair and not merely to tabulate what it already has, which is the one respect in which
   it is a heavier ask than its six neighbours, and it is said here rather than buried in a count.

   **Blocks:** the drill-down's whole *why* half — every one of these is an anomaly or an
   approximation that upstream deliberately kept visible rather than absorbing, and a panel that cannot
   show them re-absorbs them at the last layer. **It is the same gap as item 1**, seen from the other
   end: `detail` has no field table, so *"the open call list in full"* neither promises nor forbids any
   of the per-call flags above. **In the meantime:** none of the seven is rendered, and this document
   renders no guess in their place — the refusal panel reads *the refused schema versions are not
   reported* ([§ 7.2](#72-badges-every-member-has-a-render)); the timeline shows `compaction.start` / `compaction.end` as events
   like any other, which is the after-the-fact reading rather than the current one, and the intern and
   action lines carry no exactness qualifier at all rather than implying one they cannot check.
   **Closes it:** naming the seven among § 8.2.3's `detail` members (and `compacting` or
   `compaction_open_since` on the seat object, which is the only one the **floor** would use) — with
   the schema-version pair needing a stored home in [D2 § 6.4](FLEET-STATE.md#64-ddl) first, on the
   `batches` row the refusal already writes.

10. **⇢ D2 — `fleet.reload.reason` has no declared vocabulary.**
    [D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed)'s table gives the message a `reason` payload
    field with no members and no bound. **Blocks:** nothing. **In the meantime:** the banner renders the
    raw string beside a fixed sentence, and renders the sentence alone when `reason` is absent
    ([§ 9](#9-failure-paths-and-their-observables) F8). **Closes it:** a member set, or a statement that
    it is free text for display.

11. **⇢ Review — the desk-slot function's displacement.**
    [§ 3.3](#33-collision-displacement-and-why-a-desk-move-is-itself-an-event) accepts that an arriving
    seat can displace an incumbent, because incumbency would require a tenure the wire does not carry.
    A `first_seen` timestamp on the seat object would remove it entirely — ordering the chain by tenure
    instead of by hash makes every incumbent permanent — at the cost of one field D2 would have to
    declare and this document may not mint. **Blocks:** nothing. **In the meantime:** the displacement
    is deterministic, bounded to the collision chain, animated, and its frequency is stated.
    **Closes it:** a review ruling on whether the field is worth it; the alternative is that the moves
    are rare enough to be a curiosity, which the first month of a real floor answers.

12. **⇢ D2 — the ten bookkeeping members have no delivery or refresh story, and two of the three ages
    are among them.**
    [D2 § 6.5](FLEET-STATE.md#65-the-fold) excludes ten members from the version-bearing set — the six
    `delivery` bookkeeping fields, `reporter.uptime_s`, and all three of `derivation` — so no delta
    ever carries them for their own sake, and a client's copy is frozen at the last full object it
    received. D2 states the consequence as a rule on this layer (*"every quantity … rendered from one
    of the ten is rendered from a value that cannot be moving at the moment it is read"*) and points at
    the fetch surfaces, but the two facts a floor most wants per seat live there: the **receipt age**
    (`delivery.last_receipt_at`) and the **fold lag** (`derivation.fold_lag_ms`). `derivation` is the
    sharper half — all three of its members are excluded, so the object is in **no** patch ever, while
    `delivery` is at least re-sent whole whenever `no_data_since` or `seq_epoch` moves
    ([D2 § 8.3.1](FLEET-STATE.md#831-worked-delta)'s shallow merge). **Blocks:** the two-age divergence
    [D2 § 3.3](FLEET-STATE.md#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by) calls the
    product, on the **desk**; the drill-down still has it. **In the meantime:**
    [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s two markers, whose table owns what each
    permits rather than this item restating it: the receipt age is `dark-only`, and every other
    rendered value among the ten is `fetch-fresh` (stamped *as of*, never ticked). The `fold_lag` render is
    [§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy)'s, including why the degradation
    still announces itself when its number does not move. **Closes it:** any one of —
    making `delivery.last_receipt_at` version-bearing on a coarse edge (it moves on every receipt, so
    the edge would have to be a bucket rather than the value); a periodic per-seat refresh message; or
    a stated snapshot cadence a client may rely on. The first is the one that would let a live desk
    render its receipt age, which is the reading operators will expect.

13. **⇢ D2 — `badge_first_seen` is stored and reaches no consumer, so no badge can be dated.**
    [D2 § 7.3](FLEET-STATE.md#73-how-the-reporters-own-counters-are-handled) item 0 keeps
    `seat_state.badge_first_seen`, a per-badge map, and derives `badges_since` as *the minimum of the
    values* — then says in terms that one timestamp *"cannot answer 'when did **this** badge appear',
    which is the only question `badges_since` is asked"*. The map is a column
    ([D2 § 6.4](FLEET-STATE.md#64-ddl)) and appears on neither read surface. **Blocks:** dating an
    individual badge — a `fold_lag` that began thirty seconds ago, on a seat whose sticky `lossy` has
    been up all day, has no onset a consumer can render. **In the meantime:** the panel carries **one**
    cluster-scoped line, *oldest badge since HH:MM*, and stamps no individual badge
    ([§ 7.2](#72-badges-every-member-has-a-render)); a per-badge *since* would be a misdated
    degradation on the panel whose job is to date degradations. **Closes it:** `badge_first_seen` among
    [§ 8.2.3](FLEET-STATE.md#823-the-seat-detail-response)'s `detail` members — the drill-down is where
    the per-badge line renders, so it need not ride the snapshot.

14. **⇢ D2 — a cold-start client cannot subscribe before it fetches, because it does not yet know what
    to subscribe to.**
    [D2 § 8.4](FLEET-STATE.md#84-snapshot-then-deltas) step 1 is *"connects, subscribes to
    private-fleet.<install>"* and the whole protocol's closure property rests on that being done
    first — but the install set is learned from the snapshot, so on a cold start steps 2–4 run with no
    channel open, and a delta emitted for a not-yet-known install in that window is **never received**
    rather than buffered-and-discarded. The per-seat watermark cannot recover it: it filters a buffer.
    **Blocks:** nothing permanently — the next delta for that seat trips the gap detector and
    [§ 9](#9-failure-paths-and-their-observables) F2 resyncs it — but a seat that changes inside the
    window and then goes quiet holds its pre-change state until a full snapshot, which is D2 § 8.4's
    own named failure. **The window is not cold-start-only**, which is why the remedy below is a
    primitive rather than a step: an install provisioned while a client is connected enters the
    rendered set mid-session and has exactly the same unsubscribed round trip in front of it.
    **In the meantime:** [§ 2.2](#22-connect-snapshot-deltas)'s **`ADMIT`** — subscribe, re-fetch,
    drain — run at step 6 over exactly the installs step 2 could not know about, at the cost of one
    extra snapshot on a cold start and none afterwards. The same primitive is what admits an install
    discovered mid-session by [§ 4.1](#41-the-lobby--the-building-summary)'s discrepancy fetch, so the
    cold-start window and the mid-session one are closed by one rule rather than two.
    **Closes it:** an installs-list endpoint the client can call before subscribing, or a fleet-wide
    bootstrap channel it can subscribe to without knowing any install id — either makes step 2 complete
    on a cold start and reduces step 6 to a no-op. **Neither retires `ADMIT` itself**, which is the
    half worth stating: an install provisioned while a client is connected enters the rendered set
    mid-session however step 2 is fixed, and that is the call-site D2 § 8.4 never had.

---

## Appendix A — every obligation addressed to this document

[D2](FLEET-STATE.md) addresses this document in **thirty-eight** places — a `D3` mention, a "renders"
that names an obligation rather than a pixel, a "the drill-down can say", a rule only the render layer
can keep. [D1](EVENT-SCHEMA.md) addresses it in **twelve** more, directly or through its
"constraining D2/D3" clause. All of them are
enumerated here, because an obligation a downstream document did not notice is indistinguishable from
one it declined. The two counts above and the two tables' row counts are checked against each other by
`tools/design/verify-floor.py`, so a row added to a table and not to a sentence reds the gate.

**The population has two halves and only one is machine-derivable**, exactly as D2 found for its own.
The mechanised half is no longer the literal `D3` alone — that recognizer reported clean while
[D2 § 4.7](FLEET-STATE.md#47-which-clock-each-ceiling-is-measured-from) and
[§ 4.8](FLEET-STATE.md#48-what-may-never-mint-a-state) placed three render obligations this document
neither listed nor discharged, because those sections say *"rendered in the drill-down"* and never say
`D3`. It is now `D3` **plus the render-directed phrasings D2 and D1 actually use** — *rendered in the
drill-down*, *the drill-down can say*, *visible in the drill-down*, *must render*, *renders as
quiet*, *readable in its drill-down* — over **both** upstream documents, and matched **across line
wraps**. The wrap half is what actually found the last one: [D1 § 12.2](EVENT-SCHEMA.md#122-error-responses)
places a render obligation in the words *"readable in its drill-down"* with the phrase broken over a
line break, so a line-scoped recognizer reported clean over it **whether or not the phrase was in the
list** — the list was the visible gap and the line scope was the reason widening it would not have
helped. The rest — a "renders" clause in none of those forms, a
field whose whole purpose is a rendering rule — were found by reading. The tool checks the mechanised
half's coverage in both documents and prints the remainder **row by row** rather than reporting a clean
over it.

### The obligations D2 places on this document

| # | D2 source | Obligation | Discharged in |
|---|---|---|---|
| T1 | § 1.2 | Everything rendered is D3's — desks, floors, sprites, animation, the identity→desk mapping; where D1 or D2 says "renders" it is naming an obligation, not a pixel | [§ 1.1](#11-what-this-document-owns), [§ 3](#3-identity-seat--desk-install--floor), [§ 5](#5-the-render-map--every-rendered-fact-and-its-d2-field), [§ 6](#6-the-honesty-principle--every-animation-and-its-driving-event) |
| T2 | § 2.3 | A seat badged `fold_lag`: **D3 must not present the seat's activity state as current** | [§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim), [§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy), [AT-D3-5](#at-d3-5-a-degraded-seat-is-visibly-degraded) |
| T3 | § 2.3 | `fleet.fold = "stalled"`: **D3 shows a fleet banner** | [§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy), [§ 5.3](#53-the-fleet-on-both-screens) |
| T4 | § 4.1 | D3 renders `render_state` and may use the components for the drill-down; it **never re-derives the collapse** | [§ 2.1](#21-the-seven-client-computed-values-closed), [§ 5.1](#51-the-desk), [§ 7.1](#71-the-render-per-state), [AT-D3-5](#at-d3-5-a-degraded-seat-is-visibly-degraded) |
| T5 | § 4.3 | `unknown` is one glyph with seven reasons; the diagnosis belongs in the drill-down, not in six more top-level states | [§ 7.1](#71-the-render-per-state) |
| T6 | § 8.2 | The timeline endpoint is D3's recent-activity window: newest first, `limit` ≤ 200, default 50 | [§ 4.3](#43-the-desk-drill-down-panel), [§ 5.2](#52-the-drill-down) |
| T7 | § 8.2.4 | D3 may compose a banner from `db`, `fold` and `sweep`; the wire keeps them apart and no aggregate rolls them into one | [§ 5.3](#53-the-fleet-on-both-screens), [§ 4.1](#41-the-lobby--the-building-summary) |
| T8 | § 14 item 9 | The `subagents` cap is D3's decision, closed by the drill-down design, and moving it moves the worst-case byte figure | [§ 8.1](#81-the-cap-stays-at-8--the-arithmetic-and-the-reason), [§ 14](#14-open-questions-for-the-review-loop) item 8 |
| T9 | § 4.10 | A retired seat is **rendered, not disappeared**, for 14 days, with `at`, `by` and `reason`; the axes keep deriving underneath | [§ 3.5](#35-retirement-and-the-only-removal), [§ 7.1](#71-the-render-per-state), [AT-D3-16](#at-d3-16-retirement-is-rendered-and-the-removal-is-explained) |
| T10 | § 3.3 | The browser's own clock is never used for an age; the client keeps `offset = server_time − browser_now`, refreshed on every feed heartbeat | [§ 2.4](#24-the-clock-and-every-age-on-the-page), [AT-D3-10](#at-d3-10-ages-come-from-the-server-clock) |
| T11 | § 3.4 | **Forbidden:** rendering an activity state without its currency label when the seat is `catching_up`, `stale`, `offline` or badged `fold_lag` | [§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim) |
| T12 | § 8.4 | Snapshot-then-deltas: subscribe, buffer, snapshot, discard at or below the per-seat watermark, then steady state | [§ 2.2](#22-connect-snapshot-deltas), [AT-D3-9](#at-d3-9-the-client-half-of-snapshot-then-deltas) |
| T13 | § 8.5 | Apply iff `version == local + 1`; on a gap resync **that one seat** with `?resync_from=`, which is the only write path for `feed_gap_detected` | [§ 9](#9-failure-paths-and-their-observables) F2, [AT-D3-7](#at-d3-7-a-delta-gap-resyncs-exactly-one-seat) |
| T14 | § 8.3 | A client that has seen no message of any kind for 45 s treats the feed as dead, renders an indicator and reconnects | [§ 9](#9-failure-paths-and-their-observables) F1, [AT-D3-6](#at-d3-6-the-feed-dying-is-visible-within-45-s) |
| T15 | § 2.2 | Reverb down, store up: REST still serves, the client polls at **10 s** and **must render a `feed_down` indicator** | [§ 9](#9-failure-paths-and-their-observables) F1 |
| T16 | § 8.1 | A client that sees an unknown `feed_version` stops applying deltas and tells the user to reload — no compatibility dance | [§ 9](#9-failure-paths-and-their-observables) F8, [§ 2.5](#25-what-re-renders-and-when) |
| T17 | § 8.2.1 | `subagents[].title` is `null` when the spawn was lost — an honest orphan, **never invented**; a later `subagent.spawn` for the same `call_id` fills it | [§ 8](#8-interns--subagent-rendering-and-the-cap), [AT-D3-4](#at-d3-4-the-subagent-cap-boundary) |
| T18 | § 8.2.1 | `subagents` is a stated reduction; `subagents_open` always carries the true count; the per-seat detail endpoint returns all of them | [§ 8](#8-interns--subagent-rendering-and-the-cap), [§ 8.1](#81-the-cap-stays-at-8--the-arithmetic-and-the-reason) |
| T19 | § 4.9 | `task.source` says which tier answered; `task.degraded` says a better one was dropped; a floor showing tier 3 everywhere is visibly a floor whose board integration is dark | [§ 5.1](#51-the-desk), [§ 5.2](#52-the-drill-down), [§ 14](#14-open-questions-for-the-review-loop) item 4 |
| T20 | § 7.3 | The reporter's `degraded` array is rendered **"since reporter start"** with `reporter.uptime_s` and the counter's value beside it — never as "now". This row restates D2's obligation and renders nothing itself (**`named-not-rendered`**); the render is § 7.2's and § 5.2's, where the member carries its marker | [§ 7.2](#72-badges-every-member-has-a-render), [§ 5.2](#52-the-drill-down) |
| T21 | § 7.1 | `lossy` renders with its number; `seq_gap` is D2's own badge and is **never** rendered as `lossy` — they are different failures with different fixes | [§ 7.2](#72-badges-every-member-has-a-render) |
| T22 | § 8.6 | The one outcome forbidden on the read surface — a `200` with an empty fleet — "renders as an empty office, which is indistinguishable from a fleet that has gone home" | [§ 9](#9-failure-paths-and-their-observables) F4–F6, [AT-D3-8](#at-d3-8-a-refusal-is-never-an-empty-office) |
| T23 | § 9 | MFA gates the page, the WebSocket handshake **and** the REST snapshot; the live feed is browser-only | [§ 4.4](#44-routes-and-what-each-one-fetches), [§ 9](#9-failure-paths-and-their-observables) F6 |
| T24 | § 4.5 | A seat is **never removed** for going quiet; `stale`/`offline` carry `no_data_since` so the render is "no data since 14:18" rather than a bare glyph; no row vanishes between two refreshes | [§ 5.1](#51-the-desk), [§ 7.1](#71-the-render-per-state), [§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold) |
| T25 | § 4.4 | An idle seat that goes quiet **stays `idle`** while it heartbeats and becomes `stale` when it stops; leaving `live` **masks** the activity state rather than clearing it | [§ 7.1](#71-the-render-per-state), [§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim) |
| T26 | § 8.3.1 | `patch` is a shallow merge — a nested object is replaced whole — and `changed[]` is what a client uses to decide **what to animate** | [§ 2.2](#22-connect-snapshot-deltas), [§ 6.2](#62-the-animation-table--the-closed-set) |
| T27 | § 4.2 | `render_state`'s ten members, and `retired` short-circuiting above both axes | [§ 7.1](#71-the-render-per-state), [§ 3.5](#35-retirement-and-the-only-removal) |
| T28 | § 8.2.3 | The seat-detail response "is the drill-down's source" and carries the open call list **in full, not capped at 8** | [§ 4.3](#43-the-desk-drill-down-panel), [§ 8](#8-interns--subagent-rendering-and-the-cap) |
| T29 | § 3.1 | A seat that only heartbeats is quiet and **renders as quiet**: its receipt age near zero, its activity age growing without bound, "**Both are** on the wire, separately, so no consumer has to guess which one it is holding" | [§ 2.4](#24-the-clock-and-every-age-on-the-page), [§ 5.1](#51-the-desk), [§ 5.2](#52-the-drill-down) — the quiet half is version-bearing and rides the desk; the receipt half is one of § 6.5's ten, so it is `dark-only` on the desk and `fetch-fresh` in the panel — what those two markers permit is [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s to say, and this row cites it rather than carrying a second copy. **The obligation's own seat is `live`**, which is where that split costs something: a heartbeat-only desk carries the quiet age alone and its receipt age is read in the panel, under a stamp, and [§ 14](#14-open-questions-for-the-review-loop) item 12 is the amendment that would close the `live` half |
| T30 | § 4.4 | `resolution` / `resolution_source` carry `server_ceiling` — the value "exists so the drill-down can say *the server cleared this*" | [§ 14](#14-open-questions-for-the-review-loop) item 9 — on no read surface; this document renders nothing in its place rather than implying the request was answered |
| T31 | § 4.7 | "durations **rendered in the drill-down**": the event's own `duration_ms`, else `event_time` arithmetic, **with `duration_source`** | [§ 14](#14-open-questions-for-the-review-loop) item 9 — neither field is on a read surface, so [§ 5.2](#52-the-drill-down) renders no duration it cannot source and no qualifier it cannot check |
| T32 | § 4.8 | A `tool.end` whose `match` is `synthesized` — a close with no open: the flag "is stored and **rendered in the drill-down**, so the anomaly is a visible flag rather than an absorbed one" | [§ 14](#14-open-questions-for-the-review-loop) item 9 |
| T33 | § 4.8 | A `tool.end` whose `match` is `lifo_tool_name`: "`match` is stored and **rendered in the drill-down** so an approximate attribution is legible as one" | [§ 14](#14-open-questions-for-the-review-loop) item 9 |
| T34 | § 4.8 | A compacting seat mints no activity state, and its quiet 40 s is "still visible in the drill-down" | [§ 14](#14-open-questions-for-the-review-loop) item 9; [§ 7.1](#71-the-render-per-state) has no compaction state, which is the other half of the same rule |
| T35 | § 4.8 | `agent_scope` and `parent_call_id` are **labels**, "stored for the intern join and never gate anything" — what is forbidden is a scope-dependent **state rule** | [§ 5.1](#51-the-desk), [§ 5.2](#52-the-drill-down), [§ 8](#8-interns--subagent-rendering-and-the-cap): the intern list selects on them, and no pose, currency label or badge reads them |
| T36 | § 6.5 | The **ten** non-version-bearing members: "every quantity this document says is *rendered* from one of the ten is rendered from a value that cannot be moving at the moment it is read" — the raw skew, spool depth, cursor and fold lag are "served fresh by § 8.2.3, the snapshot and § 8.2.4 rather than held by a client between deltas" | [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s `fetch-fresh` / `dark-only` markers — that section owns them, and § 2.4's own sentence names the tables they are carried on, so this row cites the rule rather than keeping a second list of its sites; [§ 14](#14-open-questions-for-the-review-loop) item 12 |
| T37 | § 6.7 | A provisioned seat that has never reported "**must render, not vanish**"; a retired seat drops out of the read surfaces by a query filter and not a deletion | [§ 3.4](#34-a-new-seats-first-appearance), [§ 3.5](#35-retirement-and-the-only-removal), [§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold) |
| T38 | § 10 | `close_source: reap_session_boundary` exists "so the drill-down can say *the clear killed these*, not *these ended*" | [§ 14](#14-open-questions-for-the-review-loop) item 9 |

### The obligations D1 addresses to the render layer

| # | D1 source | Obligation | Discharged in |
|---|---|---|---|
| U1 | § 9.1 "Rendering (constraining D2/D3)" | `stale` and `offline` are a **visibly degraded** desk of their own; an empty floor and a broken floor must never look alike | [§ 7.1](#71-the-render-per-state), [§ 7.5](#75-what-a-degraded-desk-may-never-look-like), [§ 9](#9-failure-paths-and-their-observables) F4 |
| U2 | § 3.1, § 3.4 | Identity is config-file resident and survives restarts, `/clear`, reboots and upgrades; it is never derived from the environment | [§ 3.1](#31-the-keys-and-why-they-are-the-only-ones), [AT-D3-3](#at-d3-3-identity-is-stable-across-a-restart) |
| U3 | § 6.7 | The intern's label is the dispatch's own `description`, carried as `title` — programmatic, sanitized, ≤ 120 B, sized for "the drill-down panel's one-line intern label" | [§ 8](#8-interns--subagent-rendering-and-the-cap) |
| U4 | § 6.8 | The title lives on `subagent.spawn` only; the consumer joins on `call_id`; a lost spawn yields a title-less stop, "an observable orphan… not a gap to paper over" | [§ 8](#8-interns--subagent-rendering-and-the-cap), [AT-D3-4](#at-d3-4-the-subagent-cap-boundary) |
| U5 | § 6.14 | `enabled: false` renders **disabled** — a seat that is off and a seat that is gone must not look alike | [§ 7.1](#71-the-render-per-state), [§ 5.1](#51-the-desk) |
| U6 | § 6.11 | `used_pct_source` keeps the harness and computed branches distinguishable rather than silently averaged | [§ 4.3](#43-the-desk-drill-down-panel), [§ 5.1](#51-the-desk) |
| U7 | § 9.3 | The `degraded` array is the badge source so a consumer never re-derives badges from raw counters; `lossy`'s **number is rendered** beside it | [§ 7.2](#72-badges-every-member-has-a-render) |
| U8 | § 10.1 | Never render a seat's timestamp as an absolute clock; the seat's clock is its own claim | [§ 2.4](#24-the-clock-and-every-age-on-the-page), [AT-D3-10](#at-d3-10-ages-come-from-the-server-clock) |
| U9 | § 1 | The scope boundary D1 draws, as its non-goals table states it — the row `\| **Anything rendered** \| D3 (docs/design/FLOOR.md). \|`. It is rendered here as a table row and not as a quoted phrase, because the dash-joined form *"Anything rendered — D3"* is accurate in substance and is not a verbatim span of D1 | [§ 1.1](#11-what-this-document-owns), [§ 1.2](#12-non-goals--stated-so-an-implementer-cannot-widen-scope-in-good-faith) |
| U10 | § 6.12 | `notification_kind` has exactly **three** members and no `other`, "so a render branch over a fourth is neither owed nor wanted" — a wire member no input can produce is a branch D2 and D3 would build and never reach | [§ 5.4](#54-what-is-never-rendered): this document renders no `notification_kind` at all — an attention request reaches the floor only as `blocked` ([§ 7.1](#71-the-render-per-state)), whose entry and exit D2 owns — and [§ 7.6](#76-the-three-remaining-member-sets-published-so-membership-is-testable) publishes the sets it *does* branch over, so an unreachable branch here is a set-difference failure rather than a reading |
| U12 | § 12.2 | The schema-version refusal, stated as **required behaviour and not merely a status code**: the seat *"renders **visibly degraded** on the floor with the received and accepted versions **readable in its drill-down**"* | Half is rendered and half is filed, and the split is stated rather than blurred. **The visibly-degraded half:** [§ 7.2](#72-badges-every-member-has-a-render)'s `batches_rejected` badge is on the desk's cluster and its count is in the panel — a badge is D1's visible degradation for a *past* refusal, not a currency treatment, so [§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim) is deliberately not widened for it ([§ 5.4](#54-what-is-never-rendered): the desk still renders `render_state`). **The version pair:** `received_version` and `accepted_versions` appear in D1's refusal body and **nowhere in D2 at all**, so no read surface carries them and this document renders no guess in their place — [§ 14](#14-open-questions-for-the-review-loop) item 9 carries it as the seventh member of that class |
| U11 | § 6.4 | `D2-MUST` #1's rendering half: `stalled` carries `api_error_type` "so the drill-down can say *which* error" — and D1 mints a **twelfth** member, `unrecognised`, precisely so the harness's own `unknown` is not overloaded as the coercion target | [§ 7.1](#71-the-render-per-state)'s `stalled` row, [§ 5.1](#51-the-desk), and [§ 7.6](#76-the-three-remaining-member-sets-published-so-membership-is-testable), which publishes all twelve with the two-way distinction spelled out |

**Nothing addressed to this document is undischarged.** **Thirteen** of the thirty-eight are
discharged with a stated gap in the upstream contract rather than by a rendering alone, and every one
is filed in [§ 14](#14-open-questions-for-the-review-loop) rather than absorbed silently: T6's timeline
has no field table and T28's `detail` has none either (item 1, one class filed once); the membership
case T24's "never vanishes" implies has no message (item 2); T23's MFA gate has no rule for an expiring
session on an open socket (item 5); T30, T31, T32, T33, T34 and T38 — **and U12**, which is D1's — are seven drill-down
explanations upstream names and no read surface carries (item 9, one class filed once); T29's receipt age and T36's fold lag
are among the ten members no delta carries (item 12); and T20's per-badge *since* has only a
cluster-scoped minimum to render (item 13).

**That share is larger than an earlier revision of this appendix reported, and D2 did not change.**
The population was derived by grepping D2 for the literal `D3`, and the three render obligations in
[D2 § 4.7](FLEET-STATE.md#47-which-clock-each-ceiling-is-measured-from) and
[§ 4.8](FLEET-STATE.md#48-what-may-never-mint-a-state) say *"rendered in the drill-down"* instead — so
a table that claimed to enumerate *all of them* was clean over a population its own recognizer could
not see. The recognizer now reads the phrasings upstream actually uses
([§ 12](#12-every-number-and-where-it-comes-from)'s G6 row), over both documents, and what it still
cannot reach is printed row by row on every run rather than counted.

---

## Appendix B — what an implementer builds from this

In dependency order, with the gate each must pass before the next is trusted. Cards #7340, #7341 and
#7342 (`docs/PLAN.md § 3`) are the whole of it; card #7339 (the fleet-state store, feed and REST
snapshot, from D2) is a prerequisite for everything from step 3 onward.

| Order | Artifact | Gate |
|---|---|---|
| 0 | `docs/ATTRIBUTION.md`, the asset manifest, and both **provenance gates** | **[AT-D3-12](#at-d3-12-asset-provenance-gates-bite)** **(manifest half)** RED on each of its planted defects, then GREEN — first, because an asset added before the gate exists is an asset nobody will go back and license |
| 1 | the **character generator port**, its **lineage file**, `resources/characters/LINEAGE.md`, and the **character tree** the port writes (card #7340) | **✅ LANDED 2026-08-25**, closing [§ 14](#14-open-questions-for-the-review-loop) item 7's generator half — the upstream repository and commit are recorded in the repository, the tree renders in a plain browser from the seat key alone, both clauses of Gate 2 hold, and [AT-D3-12](#at-d3-12-asset-provenance-gates-bite) **(lineage half)** — the half of that test with a file to read — is green. *(This cell read BLOCKED until 2026-08-27, three days after the block cleared; a gate cell that outlives its block is a build order nobody can trust.)* **What landed is the seed machinery plus INTERIM pixel art** ([§ 10.2](#102-characters-the-munder-difflin-port)): the ratified art direction ([§ 10.4](#104-the-art-direction-as-a-specification)) supersedes the drawing, not the step |
| 2 | the fixture harness and the **animation log** ([§ 11](#11-acceptance-tests)) | **[AT-D3-1](#at-d3-1-no-animation-without-its-event)** **(instrument half)** — its discriminating control, which reads the log and nothing else: a harness that records nothing must not be able to report clean |
| 3 | the **client protocol**: subscribe, buffer, snapshot, drain, apply, resync, insert ([§ 2](#2-the-client-end-to-end)) — and the **client's event record** ([§ 5.5](#55-the-clients-own-narration)), which the protocol writes as it acts and the lobby merely renders at step 9 | [AT-D3-9](#at-d3-9-the-client-half-of-snapshot-then-deltas) **(protocol half)**, [AT-D3-7](#at-d3-7-a-delta-gap-resyncs-exactly-one-seat) **(protocol half)**, [AT-D3-17](#at-d3-17-a-seat-the-client-does-not-hold-is-fetched-never-patched) **(protocol half)** |
| 4 | the clock offset and every **age readout** ([§ 2.4](#24-the-clock-and-every-age-on-the-page)) | [AT-D3-10](#at-d3-10-ages-come-from-the-server-clock) **(floor half)** |
| 5 | the **desk render**: the render map, the ten state renders, and the desk's **side table** ([§ 5.1](#51-the-desk), [§ 7.1](#71-the-render-per-state), [§ 8](#8-interns--subagent-rendering-and-the-cap)) | [AT-D3-5](#at-d3-5-a-degraded-seat-is-visibly-degraded), [AT-D3-14](#at-d3-14-a-null-is-never-drawn-as-a-zero) **(desk half)** |
| 6 | the **animation set** ([§ 6.2](#62-the-animation-table--the-closed-set)) | **[AT-D3-1](#at-d3-1-no-animation-without-its-event)** **(closed-set half)** and **[AT-D3-2](#at-d3-2-the-clear-trace-shows-no-idle-anywhere)** — the two hard gates on trusting the floor at all — plus [AT-D3-13](#at-d3-13-every-state-is-legible-without-motion), whose whole claim is about motion and is unobservable before there is any, and the render halves of [AT-D3-9](#at-d3-9-the-client-half-of-snapshot-then-deltas) **(render half)** and [AT-D3-17](#at-d3-17-a-seat-the-client-does-not-hold-is-fetched-never-patched) **(render half)** |
| 7 | the **floor layout**: the map, the slot function, overflow (card #7341). The map is what draws the room the desks stand in, **including its wall clock and its windows** — named here because a room element nobody schedules is a room element nobody builds. Step 6's set is what *moves* them ([§ 6.2](#62-the-animation-table--the-closed-set) A17); this step draws them and sets them on first render, which is not an animation ([§ 6.5](#65-a-snapshot-never-animates)) | [AT-D3-3](#at-d3-3-identity-is-stable-across-a-restart) |
| 8 | the **failure renders** and the **status strip** ([§ 9](#9-failure-paths-and-their-observables)) | [AT-D3-6](#at-d3-6-the-feed-dying-is-visible-within-45-s) **(floor half)**, [AT-D3-8](#at-d3-8-a-refusal-is-never-an-empty-office), [AT-D3-11](#at-d3-11-an-unrecognised-member-renders-as-unrecognised), and [AT-D3-7](#at-d3-7-a-delta-gap-resyncs-exactly-one-seat) **(strip half)** |
| 9 | the **lobby** ([§ 4.1](#41-the-lobby--the-building-summary)) | [AT-D3-15](#at-d3-15-the-lobby-never-invents-a-count) |
| 10 | the **drill-down**, and its **uncapped intern list** ([§ 8](#8-interns--subagent-rendering-and-the-cap)) (card #7342) | [AT-D3-4](#at-d3-4-the-subagent-cap-boundary), [AT-D3-16](#at-d3-16-retirement-is-rendered-and-the-removal-is-explained), and the panel halves of [AT-D3-6](#at-d3-6-the-feed-dying-is-visible-within-45-s) **(panel half)**, [AT-D3-10](#at-d3-10-ages-come-from-the-server-clock) **(panel half)** and [AT-D3-14](#at-d3-14-a-null-is-never-drawn-as-a-zero) **(panel half)** ([§ 11](#11-acceptance-tests)'s ordering rule) |

**Three of these are hard requirements before anything downstream may treat this floor as honest:**
**AT-D3-1** (no animation without its event — the operator's principle, made into a test),
**AT-D3-2** (the `/clear` trace shows no idle anywhere — the D3 half of the headline test both upstream
documents exist in this order to make possible), and **AT-D3-8** (a refusal is never an empty office —
because the failure render is the one a dashboard is judged by and the one nobody exercises by
accident).

**A note on order, and it is the rule [§ 11](#11-acceptance-tests) states rather than a preference.**
This table carries the build order and the gates; the rule over them is § 11's and is not restated
here. What this note records is what the rule found once it was enforced over **every** artifact
rather than over the drill-down alone. Three tests once asserted drill-down content while this table
gated them at steps 4, 5 and 8 — a gate on an artifact built at step 10 — and each was split. Widening
the check to every artifact this table names then found **eight more, none of them about the panel —
five resolved by splitting, one by re-gating and two by relocating the artifact they read**, and here
they are, so the figure is the list's length rather than a claim beside it:
[AT-D3-1](#at-d3-1-no-animation-without-its-event) split into an instrument half (step 2, the log
alone) and a closed-set half (step 6); [AT-D3-7](#at-d3-7-a-delta-gap-resyncs-exactly-one-seat) into a
protocol half (3) and a strip half (8), because *resyncs: N* is a status-strip readout;
[AT-D3-9](#at-d3-9-the-client-half-of-snapshot-then-deltas) and
[AT-D3-17](#at-d3-17-a-seat-the-client-does-not-hold-is-fetched-never-patched) into protocol halves (3)
and render halves (6), because *no `edge` row* and *without an arrival animation* are claims about the
animation set and a floor with no animations satisfies both for free; and
[AT-D3-12](#at-d3-12-asset-provenance-gates-bite) into a manifest half (0) and a lineage half (1),
because the lineage file is step 1's artifact.
That is the five. The sixth,
[AT-D3-13](#at-d3-13-every-state-is-legible-without-motion), was **re-gated** from 5 to 6 rather than
split: its whole claim is that no state is carried by motion alone, and there is no half of that
observable before there is any motion.
**The seventh and eighth were neither, because neither test was the defect:**
[AT-D3-2](#at-d3-2-the-clear-trace-shows-no-idle-anywhere), gated at step 6, and
[AT-D3-14](#at-d3-14-a-null-is-never-drawn-as-a-zero)'s **desk half**, gated at step 5, both read the
desk's **side table**, and an earlier revision of this table built the side table at step 10 with the
drill-down. Splitting either would have split a claim that is one claim, and re-gating them to 10
would have stood two desk assertions behind the panel; what was in the wrong place was the artifact,
so the **side table** moved to step 5 — the step that renders the desk — and step 10 kept the
drill-down's **uncapped intern list**. [§ 8](#8-interns--subagent-rendering-and-the-cap) owns that
split and states why it is not bookkeeping. That is the third mechanism, and it is the one to reach
for when a test reads the right artifact at the right moment and this table has that artifact in the
wrong row. Three tests asserting the client's **event record** at steps 3
and 8 are a different case and not the same defect: the record is the client protocol's artifact and
step 3 builds it; the lobby at step 9 is its renderer.

**A second note on order.** Steps 2 and 6 are separable and must stay so: the animation log is an
instrument of the renderer, not of the test suite. If it were built inside the harness, a renderer could start an
animation the harness never saw, and [AT-D3-1](#at-d3-1-no-animation-without-its-event)'s GREEN would
be a statement about the harness rather than about the floor.

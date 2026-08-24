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
   narration of what it did and saw ([§ 5.5](#55-the-clients-own-narration)), which is labelled as the
   client's own everywhere it renders. A client-side
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
9. **Assets carry their provenance as a gate, not as a footnote.** Floor art is CC0, characters are a
   port of munder-difflin's procedural generator under MIT with attribution, and the upstream's
   commercial tilesets are never vendored (D-07). [§ 10](#10-art-and-assets--provenance-as-a-gate)
   states the manifest, the licence allowlist and the two checks that fail the build.
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
| The render map: every rendered fact, its D2 field, its example value, its null render — and the one surface whose facts are the client's own ([§ 5.5](#55-the-clients-own-narration)) | [§ 5](#5-the-render-map--every-rendered-fact-and-its-d2-field) |
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
does not paraphrase it.** Two corollaries bind an implementer:

1. **No rendered fact without a named field.** Every row of
   [§ 5](#5-the-render-map--every-rendered-fact-and-its-d2-field) names the D2 field it reads. A number
   or a label with no field is a number the client invented, and
   `tools/design/verify-floor.py` reds when a source column names a field D2 does not declare.
2. **No server behaviour invented to meet a UI need.** Where the contract cannot answer, the answer is
   an entry in [§ 14](#14-open-questions-for-the-review-loop) plus a stated interim rendering — never a
   guessed endpoint, a guessed field or a hopeful default. This document **never edits D2**: an
   amendment is a request, not an edit.

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
| 2 | **Ages** — *nothing done for 4m 12s*, *no data for 4m 12s*, *this state is 117 s behind*: the three of [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s table and no fourth | a D2 timestamp minus the corrected clock | The timestamps are the wire's; the subtraction is a rendering of them, and D2 states which basis each age takes ([D2 § 3.3](FLEET-STATE.md#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by)) |
| 3 | **Desk slot** | `(install_id, seat_id)` and the map's slot count ([§ 3.2](#32-the-desk-slot-function)) | A layout function of identity. It reads no state field, so it cannot change when a seat's state does |
| 4 | **Animation selection** and its reduced-motion form | `render_state`, the delta's `changed[]`, and [§ 6.2](#62-the-animation-table--the-closed-set) | A pure function of a delivered field and a published table |
| 5 | **Per-floor counts** | the seat objects the client already holds for that install | The wire has no per-install count ([D2 § 8.2.4](FLEET-STATE.md#824-the-fleet-health-object)'s counts are fleet-wide), so this is the only place it can come from. It is labelled as a count of the seats the client holds, and [§ 4.1](#41-the-lobby--the-building-summary) requires the client to **render the disagreement** rather than pick a winner when the floors do not sum to `fleet.seats_total` |
| 6 | **Sort orders** | floors by `install_id` ascending; desks by slot; timeline as served | Deterministic ordering of received objects |
| 7 | **Client self-narration** — the feed-liveness verdict, the *live* claim, counters over the client's own events (*resyncs: N*), the lobby event log, the *membership as of* stamp, the overflow determination, and [§ 9](#9-failure-paths-and-their-observables) F9's once-per-distinct-value dedup | the client's own connection state, its own request outcomes, and the seat set it holds ([§ 5.5](#55-the-clients-own-narration)) | Every one is a fact about **the client**, not about a seat. It is labelled as the client's own wherever it renders, it is never drawn as a seat's field or mixed into a fleet number the wire carries, and it never becomes a desk's pose, currency label or badge. [§ 5.5](#55-the-clients-own-narration) is its render map and its honesty rule |

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
     whole-fleet response and leaves every other install's held state alone
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
| A seat the client holds is **absent** from a fresh snapshot | Remove it — but **only on a snapshot apply, never on a delta or a poll**, and write one line into the lobby's event log naming the seat and the reason (*retired more than 14 days ago*, [D2 § 4.10](FLEET-STATE.md#410-retirement-is-a-rendered-state)) | A removal driven by a delta would be a removal driven by an absence, which is the inference this whole design refuses. The only honest removal is a fresh, complete population telling the client the seat is no longer in it |

### 2.4 The clock, and every age on the page

`clock_offset_ms = server_time − browser_now`, refreshed on **every** message and response that carries
`server_time` — which is all of them ([D2 § 8.2](FLEET-STATE.md#82-rest),
[D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed)) — and applied to every age the page renders.
The `feed.heartbeat` at 15 s is what keeps it fresh on an otherwise-silent fleet.

- Ages re-render **every 1 s**, which is the unit the smallest age is rendered in: slower would show a
  second that has already passed, faster would repaint for nothing.
- An age is rendered from the field D2 assigns to it and no other, **and each has exactly one rendered
  form, stated here so that no second surface mints a second string for one fact**:

  | Age | Field | The string, verbatim | Where it may appear |
  |---|---|---|---|
  | **quiet age** | `activity.last_received_at` | ***nothing done for 4m 12s*** | desk and drill-down; version-bearing, so it ticks. On an `idle` desk it appears inside that state's label line as *finished — nothing done for 4m 12s* ([§ 7.1](#71-the-render-per-state)) — the same readout under the state's own sentence, never a second wording |
  | **receipt age** | `delivery.last_receipt_at` | ***no data for 4m 12s*** | the drill-down's transport block **only**, under that block's *as of* stamp, never ticked ([§ 5.1](#51-the-desk)'s `dark-only`) |
  | **derivation lag** | `derivation.fold_lag_ms` | ***this state is 117 s behind*** | desk and drill-down, under a stamp, never ticked ([§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy)) |

  **One rendered string on this page looks like an age and is not:** a `stale`/`offline` desk's
  ***no data since 14:18***, which is `delivery.no_data_since` rendered as a **timestamp**
  ([§ 5.1](#51-the-desk)). It is version-bearing where the receipt age is not, which is exactly why the
  desk carries it and not the age. Their differences are the point
  ([D2 § 3.3](FLEET-STATE.md#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by)); collapsing
  them into one "last seen" would destroy exactly the distinction the product is for. **Which surface
  may render which of the three is not a free choice**, because only one of them is refreshed by the
  feed — the rule is below, and it is D2's.
- A **seat-clock** timestamp — `action.started_at`, `context.sampled_at`, `activity.last_event_time`,
  `session.started_at` — is rendered **as the seat's own claim**, prefixed *seat clock*, and never as
  an age. D2 forbids the age reading directly
  ([D2 § 3.3](FLEET-STATE.md#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by), citing
  [D1 § 10.1](EVENT-SCHEMA.md#101-two-clocks-and-which-is-authoritative-for-what)), and the seat's
  `delivery.clock_skew_ms` is rendered beside it in the drill-down whenever it is non-null.

**Ten fields the feed never re-sends, and the two markers every render of one must carry.**
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
sake**, so no rendered value among them may be *ticked*; a value among them moves only when a whole
object arrives that carries it — a snapshot, a detail fetch, a resync, or (for a nested object alone)
a patch that touched a version-bearing sibling under the same key. D2 states the consequence as a
constraint on this layer, in that same section: *"Every quantity this document says is rendered from
one of the ten is rendered from a value that cannot be moving at the moment it is read."*

**The stamp rule, stated once and cited rather than repeated** (by
[§ 4.3](#43-the-desk-drill-down-panel) and [§ 5.2](#52-the-drill-down), which are the two surfaces that
render a stamped block):

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

Two markers carry that rule. **Every row of [§ 5](#5-the-render-map--every-rendered-fact-and-its-d2-field)
whose source is one of the ten carries one of them**, and `tools/design/verify-floor.py` reds when one
does not:

| Marker | What it permits | Why the value it renders is honest |
|---|---|---|
| **`fetch-fresh`** | rendered only from a response that has **just answered** — the snapshot apply, the drill-down's seat-detail fetch ([§ 4.3](#43-the-desk-drill-down-panel)), a resync fetch, or `fleet.health` / `feed.heartbeat` for the fleet object — stamped *as of HH:MM:SS*, and **never ticked as an age** between fetches | these are exactly the surfaces D2 names for the ten: *"served fresh by [§ 8.2.3], the snapshot and [§ 8.2.4] rather than held by a client between deltas"*. The stamp is what keeps the value a reading of a moment rather than a claim about now |
| **`dark-only`** | rendered only on a `stale` or `offline` seat | D2's own carve-out: such a seat *"by definition is receiving nothing, so its `last_receipt_at` is frozen"* at the server too, and the **transition** into `stale`/`offline` moves `render_state` and `delivery.no_data_since`, both version-bearing — so the client is told, and the age it then renders is exact rather than merely old |

**What that costs, stated rather than hidden.** A `live` desk renders **no receipt age**. The fact one
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
| snapshot applied | everything | no animation ([§ 6.5](#65-a-snapshot-never-animates)) |
| `seat.delta` applied | that desk only, and the drill-down if it is open on that seat | `changed[]` selects the animations ([§ 6.2](#62-the-animation-table--the-closed-set)); a delta that patches a field to the value it already held still counts as a change, which is what `changed[]` is for ([D2 § 8.3.1](FLEET-STATE.md#831-worked-delta)) |
| `fleet.health` / `feed.heartbeat` | the banner row, the fleet counts, the clock offset | the heartbeat is the liveness pulse's driver ([§ 6.2](#62-the-animation-table--the-closed-set) row A14) |
| `seat.retired` | that desk, immediately | D2 publishes it in the same transaction as the delta ([D2 § 4.10](FLEET-STATE.md#410-retirement-is-a-rendered-state)); the client may receive either first and both are idempotent |
| `fleet.reload` | a full-page banner; **delta application stops** | [D2 § 8.1](FLEET-STATE.md#81-two-surfaces-two-compatibility-postures): a client that sees an unknown `feed_version` stops applying deltas and tells the user to reload |
| 1 s tick | every age readout, and nothing else | not a state change; no animation may be driven by it ([§ 6.3](#63-forbidden-forms-named-so-they-cannot-be-written-in-good-faith)) |

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
| provisioned, never reported | the desk, the nameplate, **no character** — an empty chair and the label *no data yet* | `render_state: "offline"`, `unknown_reason: "no_data_yet"`, `delivery.last_receipt_at: null` |
| first batch lands | the character **walks in** and sits ([A1](#62-the-animation-table--the-closed-set)) | the delta whose `changed[]` carries `render_state`, leaving `offline` |
| first `tool.start` | the working loop begins ([A3](#62-the-animation-table--the-closed-set)) | `render_state: "working"` |
| the seat was inserted by [§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold)'s fetch | the desk appears **without** an arrival animation, and the lobby log records *seat added to the floor* | an insert is not a state change; the arrival animation is reserved for a seat leaving `offline`, which is a claim the wire actually made |

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
| 14 days later | the seat leaves the snapshot by D2's read filter, and [§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold)'s last row removes the desk **on a snapshot apply only**, with a log line |

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
| the event log | the client's own record of membership changes, resyncs and reconnects, newest first, capped at 200 lines | text only. It is the only place the client narrates itself, and it exists so that a desk that moved, appeared or vanished has a written cause |

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
**stamp rule**, which this section cites rather than restates. They change only when a fresh object
carrying them arrives: a re-fetch (the panel's refresh control, a re-open, or a resync of that seat), a
snapshot apply, or a delta whose shallow merge re-sends their nested object whole — which is
`delivery` when a delta moves one of its two version-bearing members (`no_data_since`, `seq_epoch`),
and `reporter` when a delta moves `version`, `platform` or `selftest_failed`
([D2 § 8.3.1](FLEET-STATE.md#831-worked-delta)). **No delta ever refreshes `derivation`** — all three
of its members are among the ten, so it has no version-bearing sibling to ride, and it is the one
block that moves on a snapshot or a fetch and on nothing else. **None of the ten is ever ticked as an
age**, on any block, however it arrived. This document states no polling
cadence for the panel — inventing one would be inventing a cadence D2 does not state
([§ 1.2](#12-non-goals--stated-so-an-implementer-cannot-widen-scope-in-good-faith)) — it states the
stamp instead, so a reader can always see which moment those numbers describe.
[§ 14](#14-open-questions-for-the-review-loop) item 12 is the amendment that would retire the stamp.

| Panel section | Contents | Source |
|---|---|---|
| **header** | seat name, floor, `render_state` with its plain-language line, the currency label if any | [§ 5.1](#51-the-desk) |
| **current task** | `task.title`, the tier that answered (`task.source`), the reference as a link when a base URL is configured for its shape ([§ 5.2](#52-the-drill-down)), *stale title dropped* when `task.degraded` | `task.*` |
| **current action** | `action.tool_name`, `action.descriptor`, the seat-clock start time, the elapsed time from `action.started_received_at`, `agent_scope`, `parent_call_id` | `action.*` |
| **context gauge** | the bar, the percentage to one decimal, `used_tokens / total_tokens` when non-null, the sample's own age, and `context.source` (`harness` or `computed`, never mixed — [D1 § 6.11](EVENT-SCHEMA.md#611-contextsample)) | `context.*` |
| **interns** | the subagent list — from the **detail** response, uncapped ([§ 8](#8-interns--subagent-rendering-and-the-cap)) | `detail`, `subagents_open` |
| **recent activity** | the timeline, newest first: `kind`, the seat-clock `event_time`, the receipt time, and the per-kind detail this document renders ([§ 5.2](#52-the-drill-down)) | the timeline endpoint |
| **transport** — **`fetch-fresh`**, one *as of* stamp | both ages, `no_data_since`, `clock_skew_ms`, `spool_lag_events`, `oldest_unsent_age_s`, `seq_epoch`, `last_seq` | `delivery.*` |
| **derivation** — **`fetch-fresh`**, one *as of* stamp | `computed_at`, `fold_lag_ms`, `cursor_event_id` — and the *this state is N s behind* line when the `fold_lag` badge is up, the badge being what drives the treatment ([§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy)) | `derivation.*` |
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
- **Capabilities the implementer must have, and nothing further:** a 2-D tile renderer able to draw the
  map and sprite frames at the floor's scale; a WebSocket client speaking the Pusher protocol
  (Reverb's, [D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed)); and `prefers-reduced-motion`
  support. No framework, bundler or state library is specified
  ([§ 1.2](#12-non-goals--stated-so-an-implementer-cannot-widen-scope-in-good-faith)).
- **Colour is never the only carrier of a fact.** Every state has a pose or glyph and a text label;
  every badge has a name in the drill-down. A palette is a rendering choice; a state legible only by
  hue is a state some viewers cannot read.

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
| the action's elapsed time | `action.started_received_at` | `"2026-08-23T14:23:14.201Z"` | the basis of the only honest elapsed time, because both ends are the server clock |
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
| *no data since …* | `delivery.no_data_since` | `null` | non-null only when `link_state ∈ {stale, offline}`; then the desk's label reads *no data since 14:18* rather than a bare glyph ([D2 § 4.5](FLEET-STATE.md#45-link-states)) |
| the receipt age, and why a live desk carries none | `delivery.last_receipt_at` | `"2026-08-23T14:23:14.201Z"` | **`dark-only`** — one of [D2 § 6.5](FLEET-STATE.md#65-the-fold)'s ten, so a held copy freezes on a live seat ([§ 2.4](#24-the-clock-and-every-age-on-the-page)). **The two rendered forms are different strings on different surfaces and neither is the other's shorthand:** the **desk** of a `stale`/`offline` seat renders ***no data since 14:18*** — a timestamp, from the version-bearing `delivery.no_data_since`, the row above — and the ***no data for N*** **age** form is rendered **only** in the drill-down's transport block, from this field, under that block's *as of* stamp and never ticked. No desk renders a receipt age in either form, and no surface renders both forms of one fact at once |
| the quiet age | `activity.last_received_at` | `"2026-08-23T14:23:14.201Z"` | drives *nothing done for N*. All three `activity` members are **version-bearing** — every activity event emits a delta ([D2 § 6.5](FLEET-STATE.md#65-the-fold)) — so this is the one age a live desk may render and tick. Its divergence from the receipt age is the product ([D2 § 3.3](FLEET-STATE.md#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by)), and the drill-down is where both are read under one stamp |
| the last thing the seat did, and when it says it did it | `activity.last_kind`, `activity.last_event_time` | `"tool.start"`, `"2026-08-23T14:23:09.882Z"` | the second is a seat-clock claim |
| the *replaying history* treatment | `link_state`, `delivery.oldest_unsent_age_s` | `"catching_up"`, `null` | the **treatment** is driven by `link_state` / `render_state`, which are version-bearing and therefore delivered; `oldest_unsent_age_s` is the input D2 derives them from (`> 300` ⇒ `catching_up`, [D2 § 4.5](FLEET-STATE.md#45-link-states)) and is one of the ten, so its **number** is **`fetch-fresh`** in the drill-down and never on the desk. The desk renders the drain, not the work |
| the *this state is N s behind* label | `badges`, `derivation.fold_lag_ms` | `["fold_lag"]`, `117` | the **treatment** — badge, hatched overlay, motion stops — is driven by the `fold_lag` **badge**, which is version-bearing, so a fold that has stopped still announces itself ([§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy)). The **number** is one of the ten and is **`fetch-fresh`**: it is rendered with its stamp, never ticked, and never on the desk. `fold_lag_ms` is never null ([D2 § 2.3](FLEET-STATE.md#23-a-frozen-fold-is-the-dangerous-degradation)) but it is computed at read time, so a held copy is a number that stopped when the fold did |
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
| the lobby event log, **200 lines** | membership changes, resyncs, reconnects, removals and F9's unrecognised values — newest first, one line each, carrying the moment **the client** saw it | text only, capped at 200 ([§ 12](#12-every-number-and-where-it-comes-from)). It is a narration and not a history: D2's own surfaces hold the durable record ([§ 1.2](#12-non-goals--stated-so-an-implementer-cannot-widen-scope-in-good-faith)) |
| ***membership as of HH:MM:SS*** | the moment of the last full snapshot **apply** | the age of the *membership* picture, rendered separately from the age of the *state* picture ([§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold)) |
| ***the client holds N of M seats — refreshing*** | the per-floor counts it holds, against `fleet.seats_total` | the only narration line that names a wire number, and it names it **as the wire's**: [§ 4.1](#41-the-lobby--the-building-summary) renders the disagreement rather than picking a winner ([AT-D3-15](#at-d3-15-the-lobby-never-invents-a-count)) |
| ***floor map is short N desks*** | the rendered seat count against `S`, the map's own slot count ([§ 3.2](#32-the-desk-slot-function)) | a fact about the map and this client's layout, not about any seat ([§ 9](#9-failure-paths-and-their-observables) F13) |

**None of these is a state, and none of them may become one.** A narration line never drives a desk's
pose, a currency label, a badge or an animation — the only effect the client's own connection state has
on a desk is [§ 9](#9-failure-paths-and-their-observables) F1's, which is *none*, beyond the ages
continuing to tick from the timestamps the client already holds. That is the same boundary
[§ 2.1](#21-the-seven-client-computed-values-closed) draws between presentation and state, applied to
the one surface where the client is allowed to talk about itself.

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
  [A16](#62-the-animation-table--the-closed-set). An edge animation **has a causing message**, and its
  row in the animation log ([§ 11](#11-acceptance-tests)) carries that message's id. An edge animation
  with no causing message is the defect the honesty principle exists to refuse.
- **`held`** — a render **held** for exactly as long as a delivered field has a value: the working,
  thinking, attention and replay loops, and the three states whose held render carries no motion at all
  (`idle`, `stalled`, `unknown`). A held render has **no causing message** — it is entered whenever the
  object the client holds says so, and that object may have arrived by delta, by snapshot, by resync or
  by fetch — so its log row carries the **`state_version` of the object it is held by**, and it writes
  **two** rows, one on entry and one on exit ([§ 11](#11-acceptance-tests)). A held render
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
| **A3** | `held` | `work` — typing at the keyboard, 4 fps loop | desk | `render_state` | `render_state == "working"` **and not** A4's condition — the two are exclusive, and stating it here is what makes *the held rows this table predicts* a single answer rather than two ([§ 7.1](#71-the-render-per-state)'s `working` row says the same thing in prose) | when it is not | a *working* pose, static, with the glyph | the seat is not working **now** |
| **A4** | `held` | `think` — leaning back, watching the monitor, 4 fps loop | desk | `open_calls`, `open_turn` | `render_state == "working"` **and** `open_calls == 0` **and** `open_turn == true` | when either fact changes | a *thinking* pose, static | there is an open call, so A3 runs instead |
| **A5** | `edge` | `tool-swap` — the monitor's glyph changes, one 250 ms cross-fade | desk monitor | `action.tool_name` | a delta whose `changed[]` contains `action` and whose `action.tool_name` differs from the held one | after one tick | the glyph changes with no fade | the action did not change |
| **A6** | `held` | `idle` — the chair turns from the desk, the monitor dims. **No loop.** | desk | `render_state` | `render_state == "idle"` | when it is not | identical — this state has no motion by design | the seat has not cleanly finished a turn |
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

**A14 is the only thing on the page that moves unconditionally, and it is driven by a message.** That is
deliberate: the one always-moving element is the one whose motion *is* the claim that the feed is alive,
so when the feed dies it stops, and the page's stillness becomes true rather than ambiguous.

### 6.3 Forbidden forms, named so they cannot be written in good faith

- **Ambient life.** No idle breathing, blinking, foot-tapping, coffee sipping, passing NPCs, flickering
  monitors, moving clouds or swaying plants. Every one of them is motion a viewer cannot distinguish at
  a glance from state-bearing motion, which is precisely what makes the floor readable-at-a-glance in
  the first place. The cost is accepted and stated: a still floor looks still, and a still floor **is**
  a still fleet.
- **Motion driven by a timer.** Nothing may be driven by the 1 s age tick, by a render loop's frame
  count, or by wall-clock time, except a state-held loop's own frames at the fixed rate of
  [§ 6.1](#61-the-rule-and-what-a-loop-is-allowed-to-mean) rule 2.
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
most of it will be reviewed.

### 6.5 A snapshot never animates

A snapshot, a poll response, a resync fetch, a per-seat insert and a reconnect all render the world **as
delivered**, with **no `edge`-class animation** ([§ 6.2](#62-the-animation-table--the-closed-set)).
Their arrival is not a claim that anything happened to any seat — it is a claim about what the client
knows. Animating them would put an arrival at every desk on every reconnect, and a fleet that appeared
to walk back in every time the network hiccupped would have made the floor's motion meaningless in
exactly one afternoon. [AT-D3-9](#at-d3-9-the-client-half-of-snapshot-then-deltas) asserts it.

**What a snapshot does do is render the states it delivers, held renders included, and that is not an
exception to the rule above but the other half of it.** A snapshot carrying a `working` seat renders a
working desk, loop and all: the loop is held by a delivered field, not started by a message, so its
animation-log row carries that object's `state_version` rather than a causing message
([§ 11](#11-acceptance-tests)). The rule is *no edge animation on a snapshot*, never *no motion after
a snapshot* — the second would make the floor go still on every reconnect, and
[§ 6.3](#63-forbidden-forms-named-so-they-cannot-be-written-in-good-faith) is explicit that a still
floor is read as a still fleet.

---

## 7. Degradation — how a degraded seat is unmistakable

### 7.1 The render per state

`render_state` has **ten** members ([D2 § 4.2](FLEET-STATE.md#42-render-precedence)) and every one has a
distinct render. The order below is the fixed order the lobby's per-floor summary uses.

| `render_state` | Desk | Label line | Animation | Never |
|---|---|---|---|---|
| `working` | character at the keyboard | the action's descriptor | A3, or A4 when the turn is open with no call | rendered without its currency treatment when the seat is not `live` |
| `idle` | chair turned, monitor dimmed, character present | *finished — nothing done for 4m 12s*, the quiet age in [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s one stated form | A6 (none) | rendered as absent. Idle is a **positive observation**, not a silence ([D2 § 4.4](FLEET-STATE.md#44-activity-states-every-entry-and-exit-edge)) |
| `blocked` | raised hand, marker above the desk | *waiting on a human since 14:31 (seat clock)* | A7 | shown as working, whatever `open_calls` says ([D2 § 4.3](FLEET-STATE.md#43-the-derivation-function): `blocked` outranks `working`) |
| `stalled` | head in hands | *API error — rate limit* | A8 | folded into `unknown`; `api_error_type` is always on the line |
| `unknown` | character present, question marker | one sentence per `unknown_reason` (below) | A9 | rendered as `idle`, and never as seven different desks |
| `catching_up` | character present, replay marker, desaturated | *replaying history — last event 3h 12m ago (seat clock)* | A15 | rendered as current work. This is [AT-D2-20](FLEET-STATE.md#at-d2-20-catching-up-is-not-current-and-not-stale)'s rule at the pixel layer |
| `stale` | **empty chair**, desk dimmed | *no data since 14:18* — the seat has been silent past 300 s | none | rendered as `idle`, ever ([D2](FLEET-STATE.md#42-render-precedence) `D2-MUST` #2) |
| `offline` | empty chair, desk dark | *no data since 13:52* — silent past 900 s. **When `delivery.no_data_since` is null** — the provisioned-never-reported seat [D2 § 4.5](FLEET-STATE.md#45-link-states) rule 1 mints, whose `last_receipt_at` is `NULL` — the line reads ***no data yet*** instead ([§ 3.4](#34-a-new-seats-first-appearance)), never *no data since null* | none (A2 played on the way in) | removed from the floor |
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
| `batches_rejected` | D1 | badge cluster | *N batches refused — last status and error code* |
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
| `catching_up` | the replay render (A15) | in the label only, as *was: working (3h 12m ago)* | desaturated, no working loop |
| `stale` / `offline` | the empty-chair render | in the drill-down only, under *when it went dark* | dimmed |
| badged `fold_lag` | its activity render, **with a hatched overlay and the lag line** | as the pose, explicitly labelled *N s behind* | motion **stops**: a loop implies *now*, and *now* is what the lag denies |
| badged `config_invalid` | its activity render, with the badge and *sending nothing* | as the pose | motion stops, for the same reason |
| `disabled` | the *reporting disabled* render ([§ 7.1](#71-the-render-per-state)) — character present, monitor off | in the label only, as *was: working (…)* | dimmed, motion **stops**; the seat is still heartbeating, which is how the flag is known at all, but it is sending no activity events, so everything under the label is older than the flag |

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

1. **Per seat**, the **`fold_lag` badge** — which D2 raises past 60 s of `derivation.fold_lag_ms`:
   the badge, the hatched overlay, the line *this state is N s behind — as of HH:MM:SS*, and **motion
   stops** — "D3 must not present the seat's activity state as current". **The treatment is driven by
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

**`link_state` — five members**, the ordered cascade of
[D2 § 4.5](FLEET-STATE.md#45-link-states), bounded at these five by
[D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object). It is the field the desk's **currency
treatment** is switched on ([§ 5.1](#51-the-desk)):

| `link_state` | What it says about the seat | Currency treatment |
|---|---|---|
| `live` | receipt within 300 s, enabled, not draining | none — the pose may be read as *now* ([§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim) row 1) |
| `catching_up` | the spool is draining: `oldest_unsent_age_s > 300` | the replay render, desaturated, activity state in the label only ([§ 7.1](#71-the-render-per-state), [§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim)) |
| `stale` | silent past 300 s | empty chair, dimmed, *no data since …* ([§ 7.1](#71-the-render-per-state)) |
| `offline` | silent past 900 s, or never reported | empty chair, dark, *no data since …* / *no data yet* ([§ 7.1](#71-the-render-per-state), [§ 3.4](#34-a-new-seats-first-appearance)) |
| `disabled` | `enabled == false` — reporting switched off, still heartbeating | *reporting disabled*, monitor off, motion stops ([§ 7.1](#71-the-render-per-state), [§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim)) |

**`activity_state` — five members**, bounded by [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)
and given their entry and exit edges by [D2 § 4.4](FLEET-STATE.md#44-activity-states-every-entry-and-exit-edge).
The desk never switches on this field — it switches on `render_state`, which collapses transport over
activity ([D2 § 4.2](FLEET-STATE.md#42-render-precedence)) — so what each member owes is a render
**under a currency label** when the seat is not `live`:

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

| Rendered | Source | Rule |
|---|---|---|
| one stool per open subagent | `subagents[]`, newest first ([D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)) | the array is a **reduction**, not the truth |
| the intern's label | `subagents[].title` | from the `subagent.spawn` event's `title` — the dispatch's own description, **programmatic**, sanitized at the reporter ([D1 § 6.7](EVENT-SCHEMA.md#67-subagentspawn)). ≤ 120 B, one line |
| a **title-less** intern | `subagents[].title == null` | renders **untitled**, with the `call_id` in the drill-down. The spawn was lost; D1 and D2 both call this an honest orphan and forbid inventing a title ([D1 § 6.8](EVENT-SCHEMA.md#68-subagentstop), [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)). **A later `subagent.spawn` for the same `call_id` fills it**, and the label appears then |
| the intern's type | `subagents[].subagent_type` | a small tag beside the label, e.g. `coder` |
| how long it has been running | `subagents[].started_at` | a seat-clock claim, labelled; the drill-down carries it in full |
| **+N more** | `subagents_open` minus the array's length | appears only when positive. The count is the wire's, never `subagents.length` |
| the full list | the seat-detail response's uncapped open-call list ([D2 § 8.2.3](FLEET-STATE.md#823-the-seat-detail-response)), **selected on `agent_scope == "subagent"` / a non-null `parent_call_id`** — the intern join those labels are stored for ([D2 § 4.8](FLEET-STATE.md#48-what-may-never-mint-a-state)), never a state rule gated on them | the drill-down's intern list is **not** `subagents[]`, is not capped at 8, and is not the seat's own open calls either ([§ 5.2](#52-the-drill-down), [§ 14](#14-open-questions-for-the-review-loop) item 1) |
| arrival and departure | `subagents` in a delta's `changed[]` | animation [A10](#62-the-animation-table--the-closed-set) |

### 8.1 The cap stays at 8 — the arithmetic, and the reason

[D2 § 14](FLEET-STATE.md#14-open-questions-for-the-review-loop) item 9 hands this decision to D3: *"It
is a rendering judgement made in a state document because it bounds the wire object. If D3 wants a
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
| F1 | **Feed silent** | no message of any kind for **45 s** ([D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed): 3 heartbeat intervals) | the status strip reads **feed down — polling**, [A14](#62-the-animation-table--the-closed-set)'s pulse has stopped, every desk keeps its last state and **its quiet age keeps growing** — an age is the corrected server clock minus a timestamp the client holds, so it ticks whether or not anything arrives, which is the point. The `fetch-fresh` values of [§ 2.4](#24-the-clock-and-every-age-on-the-page) do **not** tick: each 10 s poll **re-stamps** them | poll `GET /api/fleet/snapshot` every **10 s** ([D2 § 2.2](FLEET-STATE.md#22-fail-posture-per-path)) and attempt reconnect | claiming *live*. A dashboard that silently degrades from live to polled is one whose age nobody can trust |
| F2 | **Delta gap** — `state_version` jumped | `delta.state_version > local + 1` | no desk-level effect; the status strip's **resyncs: N** increments and the lobby log records the seat | `GET /api/fleet/seats/{i}/{s}?resync_from=<last applied>`, apply, continue. The parameter is required: it is the **only** write path for D2's `feed_gap_detected` counter ([D2 § 8.5](FLEET-STATE.md#85-gaps-reconnect-and-why-state_version-is-not-seq)) | applying the delta anyway. A silently divergent desk is permanently wrong on a quiet seat |
| F3 | **Connection closed `resync_required`** — backpressure | the close frame ([D2 § 8.5](FLEET-STATE.md#85-gaps-reconnect-and-why-state_version-is-not-seq)) | **reconnecting** in the status strip; the floor keeps rendering with growing ages | re-run [§ 2.2](#22-connect-snapshot-deltas) from step 1 | blanking the floor while reconnecting |
| F4 | **Snapshot `503 fleet_unavailable`** | the status code and body ([D2 § 2.2](FLEET-STATE.md#22-fail-posture-per-path)) | a full-width statement: **fleet state is unavailable — the store could not be read at 14:23:14**, over a floor that keeps its last state and is labelled *last known good*. On a cold start there is no floor to keep, and the screen says so in words | retry the snapshot with backoff; the socket stays open and will carry `fleet.health` | **an empty office.** This is [D2 § 8.6](FLEET-STATE.md#86-a-deliberately-invalid-exchange)'s forbidden outcome at the render layer |
| F5 | **`fleet.health` with `db: "down"` on connect** | the message D2 sends immediately on connect in that posture | the same statement as F4, and the socket's own indicator stays **connected** — the channel is up and is telling us why there is nothing | wait; D2 will send `fleet.health` again when `db` changes | rendering a connected socket as a healthy fleet |
| F6 | **Any read returns `401`** — session expired, or a token revoked/expired for an operator view | the status code and the `error` code ([D2 § 8.6](FLEET-STATE.md#86-a-deliberately-invalid-exchange)) | a blocking sign-in prompt over the floor; **the floor beneath is dimmed and labelled *not live since HH:MM:SS***, and the socket is closed by the client | re-authenticate, then re-run [§ 2.2](#22-connect-snapshot-deltas) from step 1 | leaving a live-looking floor behind a modal. A frozen floor that still animates is the lie this whole document is written against |
| F7 | **MFA session expires while the socket is open** | it cannot be detected on the socket alone — see the note below | the first REST call the client makes (the 10 s poll under F1, a drill-down open, or a resync) returns `401` and F6's render fires | as F6 | claiming *live* on the basis of a socket whose authorization the client can no longer verify |
| F8 | **`fleet.reload` — `feed_version` changed under a running client** | the message ([D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed)) | a full-width banner: **a new version was deployed — reload to continue**, and **delta application stops immediately** | the user reloads | attempting a compatibility dance. [D2 § 8.1](FLEET-STATE.md#81-two-surfaces-two-compatibility-postures): "it does not attempt a compatibility dance it cannot win" |
| F9 | **An unrecognised enum member** in any state or badge field | the value is not in this document's tables | the desk renders the **unrecognised** glyph carrying the raw string, is treated as not-current, and the lobby log records it once per distinct value | none needed; the value is displayed | mapping it to the nearest known member, or defaulting to a healthy-looking one |
| F10 | **Timeline request fails** (any non-200) | the status code | the drill-down's timeline area reads **could not load recent activity — HTTP N**; the rest of the panel renders | retry on the user's action | an empty timeline, which reads as *this seat did nothing* |
| F11 | **Seat-detail request fails** | the status code | the drill-down opens with the seat object it already holds and the sections that need `detail` read **unavailable**; the intern list falls back to `subagents[]` **and says it is capped** | retry | showing a capped list as if it were complete |
| F12 | **A delta names a seat the client does not hold** | the seat map | none — the client fetches it ([§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold)); the lobby log records *seat added to the floor* | — | applying a shallow-merge patch to a partial object |
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

### 10.1 The manifest, and the two gates

Every asset file in the repository has a row in **`ATTRIBUTION.md`**, and the row carries all of:

| Column | Example | Why it is required |
|---|---|---|
| path | `resources/floor/tiles/office-16.png` | the row's subject |
| source URL | `https://opengameart.org/content/…` | where it came from, checkable by a human |
| author | *(as the source states)* | the attribution obligation's subject |
| licence identifier | `CC0-1.0` | an SPDX identifier, not prose. "Free to use" is not a licence |
| retrieved | `2026-08-23` | a licence can change; the row records which one was accepted |
| SHA-256 of the file as vendored | `a3f1…` | so a later edit or replacement of the bytes is visible without re-reading the source |

**Gate 1 — every asset has a row.** The build fails when any file under the asset trees has no
`ATTRIBUTION.md` row, or when a row's SHA-256 does not match the file. A missing row is an asset whose
licence nobody recorded, which is the only way an incompatible asset ever ships.

**Gate 2 — no vendored character art.** The character sprites are **generated**, not vendored
([§ 10.2](#102-characters-the-munder-difflin-port)), so the assertion the gate makes is an **absence**:
this is the mechanised form of *"the upstream's commercial tilesets are never vendored"*. A denylist
can only refuse the copies someone thought to enumerate — and *"no raster or vector image file"* is a
denylist wearing a description, because the tree is **not** empty (it holds the ported source and
`LINEAGE.md`, [§ 10.2](#102-characters-the-munder-difflin-port)), so the gate must **classify** files,
and every classifier written as a list of image extensions passes a `.webp`, an `.avif`, a sprite sheet
named `.dat`, and base64 image data inside a `.ts`. So the gate is an **allowlist**, in two clauses,
and it fails on anything neither clause admits:

1. **File types.** Every file under the character tree carries one of **`.ts`, `.js`, `.md`** — the
   ported generator's source and its lineage file, and nothing else. Any other extension fails the
   build, named. An allowlist refuses the format nobody anticipated; that is the property the
   empty-tree argument was reaching for and the extension denylist gave away.
2. **Embedded bytes.** No file in that tree carries a `data:image/` URI or a single base64-shaped
   literal longer than **1,024 B**. Clause 1 cannot see image bytes pasted *inside* a file it admits,
   and that is the one route an implementer taking the shortcut would actually take.

**The residue is named rather than implied.** Neither clause can refuse a generator that *fetches*
upstream art at run time — nothing that inspects a tree can. That is refused by the lineage file's
*what was deliberately not taken and why* ([§ 10.2](#102-characters-the-munder-difflin-port)) and by
review, and it is said here so nobody reads Gate 2 as a proof that no upstream pixel can reach the
screen.

**The licence allowlist is closed: `CC0-1.0` and `MIT`.** Anything else — `CC-BY-*`, `CC-BY-SA-*`,
any `-NC` or `-ND` term, "free for personal use", or an asset with no stated licence — is refused by
Gate 1 and is an **operator decision to widen**, never an implementer's. The repository is MIT (D-02)
and public (`PupFuzz/mezzanine`), so an asset whose terms are stricter than the repository's is a term
the repository cannot honour.

### 10.2 Characters: the munder-difflin port

- **What is ported is the generator, not its art.** The upstream project ships a procedural character
  generator under MIT *and* commercial tilesets under terms that do not permit redistribution. This
  document's requirement is that the port takes the **algorithm and the MIT-licensed source only**, and
  that the character tree contains no image file at all ([§ 10.1](#101-the-manifest-and-the-two-gates)
  Gate 2).
- **The port carries a lineage file** — `resources/characters/LINEAGE.md` — recording the upstream
  repository URL, the **commit SHA** the port was taken from, the files ported, the MIT copyright line
  and licence text as required by MIT, and, explicitly, **what was deliberately not taken and why**.
  The last item is the one that makes a later reader able to tell a port from a fork.
- **The MIT notice ships with the distribution**, in `ATTRIBUTION.md` and in the lineage file. MIT's
  obligation is to reproduce the copyright notice and permission notice; a link is not a reproduction.
- **The seed is the seat's identity.** A character is generated from `(install_id, seat_id)`
  ([§ 3.1](#31-the-keys-and-why-they-are-the-only-ones)), so a seat looks the same on every browser and
  every reload without any stored appearance — the same property, and the same reasoning, as the desk
  slot function.
- **The upstream repository and commit are not recorded anywhere in this repository**, so the port
  cannot begin until they are ([§ 14](#14-open-questions-for-the-review-loop) item 7). Stating that is
  the point: an implementer holding only this document must not guess which project D-07 names.

### 10.3 The floor map

- **Tiled** (`.tmx`/`.tmj`) is the map format, per `docs/PLAN.md § 3`'s P3 acceptance line ("CC0 tiles;
  Tiled map"). It is the one format choice this document makes, and it is inherited from the plan
  rather than minted here.
- The map declares an **object layer named `desks`** whose objects are the slots of
  [§ 3.2](#32-the-desk-slot-function), and `S` is their count in `id` order. The shipped `aimla` map
  declares **12**.
- The map declares nothing about state. No slot is bound to a `seat_id`, because a map that named seats
  would be a second home for identity and would have to be edited every time a seat is provisioned.

---

## 11. Acceptance tests

Each test names **what to build, what to break to make it RED, and what GREEN asserts.** A test never
seen to fail is not evidence; it is a decoration that reports the harness ran.

**The harness.** A headless client — the real client code, with the real message-application path —
driven by **fixture scripts**: a snapshot object followed by an ordered list of feed messages and
simulated clock advances, with the HTTP surfaces stubbed to return stated responses. No server is
required and none of these tests needs one, which is deliberate: they gate the client's honesty, and
the state layer's honesty is already gated by D2's own twenty-three.

**The animation log is the instrument, and it is a build requirement, not a test fixture.** The
renderer **must** record, for every animation it starts, every held render it enters and every held
render it leaves, a row of
`(animation_id, install_id, seat_id, class, phase, cause, motion, at)`. The four variable fields take
their meaning from the row's **class** and, on a `held` row, from its **phase** — because a held
render's entry and its exit are opposite facts and a schema that gave them one shape made this
document's own headline test unsatisfiable on every exit row:

| Field | On an `edge` row | On a `held` row, `phase: entered` | On a `held` row, `phase: left` |
|---|---|---|---|
| `class` | `edge` | `held` | `held` |
| `phase` | **`fired`**, always — an edge animation is an instant, so it has exactly one row and no exit | `entered` | `left` |
| `cause` | the id of the **wire message that caused it** — a `seat.delta`'s `state_version`, a `feed.heartbeat`, a `seat.retired`, or the seat-set change of [A16](#62-the-animation-table--the-closed-set), recorded as the arriving seat's key. **An edge animation started with no causing message writes `null`**, which is what makes [AT-D3-1](#at-d3-1-no-animation-without-its-event) able to fail | the **`state_version` of the seat object the render is held by** — the object the client holds, whether it arrived by delta, snapshot, resync or per-seat fetch. **A held render entered against no held object writes `null`**, which is the same defect one class over: a render with nothing delivered behind it | the **`state_version` of the object that ENDED the hold** — the first object the client applied in which that row's hold condition is false. Never the entering version: two rows identical in every field are two rows from which *which states, and for how long* cannot be recovered, which is the whole reason the exit row is written |
| `motion` | `true`, or `false` when [§ 6.4](#64-reduced-motion-is-a-first-class-rendering-not-a-degradation)'s reduced-motion form is what was drawn | `true` while the loop runs; `false` when the held render is drawn static — the three states with no motion by design (`idle`, `stalled`, `unknown`), a loop stopped by a currency treatment ([§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim)), or reduced motion | **`false`**, always — nothing is drawn by a render that has been left, so an exit row is never evidence that motion ran |
| `at` | the **corrected server-clock instant** the row was written ([§ 2.4](#24-the-clock-and-every-age-on-the-page)'s offset, applied) — the client's own record of when it drew this, labelled as the client's own and rendered on no screen | as `edge` | as `edge` |

A **held** row is written when the render is **entered** and again when it is **left**, so the log
records which states a desk held and for how long — and *for how long* is
`left.at − entered.at` for the matching `(animation_id, install_id, seat_id)` pair, which is why `at`
is on the row at all. A version pair cannot answer it: `state_version` counts changes, not seconds.
**Why `motion: false` rather than no row at all:** a loop that was
stopped by a currency treatment and a render that was never entered are the same absence in a log that
records only starts, and they are opposite facts — the first is [§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim)
working, the second is a desk that never reached the state. A renderer that cannot produce this log
cannot be shown to obey the honesty principle, and the principle is the product's headline claim.

**Fixtures.** Nine, shared by the tests below:

| Fixture | Contents |
|---|---|
| `fx-snapshot-4` | [D2 § 8.2.2](FLEET-STATE.md#822-worked-snapshot)'s snapshot, extended to the four `aimla` seats of [§ 3.2](#32-the-desk-slot-function)'s worked assignment. **All four are `link_state: "live"`**, because D2's own `fleet` block in that snapshot reads `"seats_total": 4, "seats_live": 4` and [D2 § 8.2.4](FLEET-STATE.md#824-the-fleet-health-object) defines `seats_live` as `link_state == "live"` — so a non-live seat here would contradict the fixture's own fleet object. The three D2 does not publish are stated here rather than left to the builder, because [AT-D3-1](#at-d3-1-no-animation-without-its-event)'s control asserts an **exact** log over all four: `aimla-pm` is D2's published seat verbatim (`working`, `open_calls: 1`, `open_turn: true`, one subagent); **`aimla-impl-1`** is `working` with `open_calls: 1`, `open_turn: true`, `subagents: []`; **`aimla-impl-2`** is `idle` with `open_calls: 0`, `open_turn: false`, `action: null`; **`aimla-review`** is `blocked` with `open_calls: 0`, `open_turn: false`. All four carry `badges: []`, `enabled: true`, a non-null `context` and a non-null `session`; every `activity_state` equals its `render_state`. **No desk in this fixture renders a receipt age** — that readout is `dark-only` ([§ 5.1](#51-the-desk)) and every seat here is `live` |
| `fx-clear-trace` | `fx-snapshot-4`, then the **ten** deltas of [D2 § 10](FLEET-STATE.md#10-worked-example-the-clear-trace-folded-end-to-end)'s trace applied to `aimla-pm`, in order, in **both** hook orders D2 runs |
| `fx-degraded` | one seat per non-`live` render: `catching_up` (with `oldest_unsent_age_s` = 4,000), `stale`, `offline`, `disabled`, `retired`, plus a `live` seat badged `fold_lag` with `derivation.fold_lag_ms` = 117,000 |
| `fx-interns` | one seat whose `subagents` goes 0 → 8 → 8-with-`subagents_open`-9, including one element with `title: null` |
| `fx-collision` | `fx-snapshot-4`, then a delta for `aimla-impl-4` ([§ 3.3](#33-collision-displacement-and-why-a-desk-move-is-itself-an-event)) |
| `fx-membership` | **three legs.** (a) a delta for a seat absent from `fx-snapshot-4`; (b) a later snapshot missing a seat that was present; (c) **the mid-session install leg** — a `feed.heartbeat` whose `fleet.seats_total` is 6 against the four seats the client holds, then a snapshot carrying a **second install** `aimla-win` with two `live` seats (`aimla-win/win-1`, `aimla-win/win-2`), and a `seat.delta` for `aimla-win/win-1` emitted on that install's channel **during** the ADMIT (b) round trip, at `state_version` one above what (b) returns |
| `fx-gap` | `fx-snapshot-4`, then three deltas for one seat with the middle one dropped |
| `fx-refusals` | the four responses of [D2 § 8.6](FLEET-STATE.md#86-a-deliberately-invalid-exchange) and [§ 2.2](#22-connect-snapshot-deltas): `503 fleet_unavailable`, `401 token_revoked`, a `fleet.health` with `db: "down"`, and a `fleet.reload` |
| `fx-nulls` | **two** seats, because the **36** members [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)'s field table marks `Null? yes` cannot all be null on one object — nulling a container removes its children rather than exercising their null renders, and a fixture that claimed otherwise would overstate its own coverage sixfold. **`nulls-a`** — every nullable **container** null: `action`, `task`, `context`, `session`, `retired`, plus `unknown_reason`, `api_error_type`, `model_label`, `badges_since`, `enabled`, and `subagents: []`. **`nulls-b`** — every container **present** with every nullable member under it null: `action.descriptor` / `.agent_scope` / `.parent_call_id`; one `subagents[]` element with `title` and `subagent_type` null; `task.ref`; `context.used_tokens` / `.total_tokens`; `session.started_at` / `.source` / `.project_label` / `.harness_label`; all three `activity.*`; all eight `delivery.*` — `last_receipt_at` and `no_data_since` null being [§ 3.4](#34-a-new-seats-first-appearance)'s never-reported seat; all three nullable `reporter.*`. The two together cover all 36, and neither covers them alone |

### AT-D3-1 no animation without its event

*The honesty principle, mechanised. This is the headline test and the gate on trusting the floor at
all.*

- **Build:** replay `fx-snapshot-4`, `fx-clear-trace`, `fx-degraded` and `fx-interns` end to end;
  collect the animation log.
- **GREEN:** every `animation_id` in the log is a row of
  [§ 6.2](#62-the-animation-table--the-closed-set); **every `edge` row has a non-null `cause`** that is
  one of the four causing messages, and for each edge row the driving field named by that table appears
  in the causing delta's `changed[]` (or the row's driver is a message type, and the cause is a message
  of that type); **every `held` row's `cause` is a `state_version` the client holds for that seat**,
  and the two phases are asserted in **opposite** directions, which is what makes both satisfiable on a
  correct client: in a `phase: entered` row's object the fact its
  [§ 6.2](#62-the-animation-table--the-closed-set) row names **has** the value its hold condition
  states, and in a `phase: left` row's object it **does not** — an exit row whose object still
  satisfies the hold condition is a render the client stopped drawing while the wire still said to draw
  it. Every `entered` row has at most one matching `left` row for its
  `(animation_id, install_id, seat_id)`, `left.at > entered.at`, and every `left` row's `motion` is
  `false`. A predicate that asserted the hold condition on **both** phases would fail a correct client
  on `fx-clear-trace`, where A4 is entered at E0 and left at E1: at E1 `open_calls` is 1, so A4's
  condition is false in exactly the object that ended it. **The `changed[]` clause binds `edge` rows only**, because a held render
  hands off on whatever fact its condition reads and that is not always its own driver: in
  `fx-clear-trace`, A4 gives way to A3 at **E1** when `open_calls` becomes 1 while `render_state` never
  leaves `working`, and E1's `changed[]` is `{action, subagents, open_calls}`
  ([D2 § 10](FLEET-STATE.md#10-worked-example-the-clear-trace-folded-end-to-end),
  [D2 § 8.3.1](FLEET-STATE.md#831-worked-delta): `changed` is the patch's keys). A predicate demanding
  `render_state` in that delta would fail a correct client on the fixture it replays.
- **RED:** add an ambient idle-breathing loop to the character sprite — the single most natural thing to
  add to a pixel-art office — and re-run. The log gains rows whose `animation_id` has no row in
  [§ 6.2](#62-the-animation-table--the-closed-set) at all, and whose `cause` is `null` under either
  class's rule — there is no message that caused it and no delivered field holding it — and the test
  fails twice over.
  **Watch that once**: it is the whole difference between a floor that reports state and a floor that
  performs it.
- **Second RED:** drive the working loop's frame rate from `open_calls` — a "busier seats type faster"
  change that looks like a feature — and assert that the loop's frame interval is constant across every
  seat and every fixture. A rate that varies is a quantity the wire never sent.
- **Discriminating control:** a fixture with **no** deltas at all (`fx-snapshot-4` alone, then silence)
  → the log carries **no `edge` row at all**, **no `phase: left` row at all** (nothing ended, because
  nothing arrived), and carries **exactly** the `held` `entered` rows
  [§ 6.2](#62-the-animation-table--the-closed-set) predicts for the four states the fixture delivers —
  **A3 for `aimla-pm` and `aimla-impl-1`, A6 for `aimla-impl-2`, A7 for `aimla-review`**, four rows and
  no fifth — each with that seat's `state_version` as its cause. The control is two-sided on purpose: without it a log-writing bug that recorded nothing
  would pass the GREEN, and a client that fired arrivals on the snapshot would pass it too.

### AT-D3-2 the `/clear` trace shows no idle anywhere

*The D3 half of D1's and D2's headline test
([AT-D2-2](FLEET-STATE.md#at-d2-2-the-clear-trace-mints-no-idle)).*

- **Build:** replay `fx-clear-trace`, both hook orders, capturing the rendered `render_state` and the
  animation log at every applied delta.
- **GREEN:** the desk renders `working` from E0 through E6, `unknown` from E7 onward, and **never
  `idle` at any version**; the animation log contains **no** `idle` row (A6) and no `depart` (A2); the
  side table gains an intern at E1 (title-less), gains its title at E2, and empties at E5; `action`
  changes four times, which is four A5 rows and no more.
- **RED — the inferred finish:** add a *finished* render when `open_calls` reaches 0 — which the E5→E7
  window makes true while the turn is still open — and the desk animates a completion for a seat whose
  work was **killed**. That is the false idle, arriving through the render layer after D1 and D2 both
  removed it from theirs.
- **Discriminating control:** `fx-snapshot-4`'s ordinary seat driven to a clean `turn.end` **does**
  render `idle` and does log an **A6 `held` `entered` row** (`motion: false` — A6 has no motion by
  design), preceded by the **A3 `left` row** whose cause is that same `turn.end` delta's
  `state_version`, so the test measures the trace and not the absence of an idle render. Name the seat:
  `aimla-impl-1`, whose `fx-snapshot-4` state is `working`.

### AT-D3-3 identity is stable across a restart

- **Build:** apply `fx-snapshot-4`; record every desk's slot. Discard the client entirely and apply the
  same snapshot again (a reload). Then apply it in **reverse seat order**, and again with the seats
  shuffled.
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

- **Build:** replay `fx-interns`.
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

- **Build:** replay `fx-degraded`; capture each desk's render and the animation log.
- **GREEN:** all six desks are pairwise distinguishable by pose/glyph **and** by label line, per
  [§ 7.1](#71-the-render-per-state); the `catching_up` desk renders the replay treatment and its
  activity state appears **only** under a *was:* label; `stale` and `offline` render an empty chair with
  *no data since …* built from `delivery.no_data_since`; `disabled` renders a present character with the
  monitor off and is **not** the `offline` render; the `fold_lag` seat renders its pose with the hatched
  overlay, the *117 s behind* line, and **no motion** — assert the animation log's `held` row for that
  seat with **`phase: entered`** carries **`motion: false`**, which says the render was entered and
  drawn static; asserting the absence of a row instead would pass a client that never rendered the seat
  at all, and asserting it over *every* `held` row instead would be satisfied for free by the exit
  rows, whose `motion` is `false` by definition.
- **RED — render the axis, not the collapse:** switch the desk on `activity_state` instead of
  `render_state` → the `stale` and `offline` seats render `idle` (their activity state is preserved
  underneath), which is `D2-MUST` #2 broken at the last layer after two documents held it.
- **Second RED — the frozen fold:** drop the `fold_lag` treatment → a desk showing two-minute-old work
  beside a fresh receipt age, with every instrument on the page agreeing that everything is fine. This
  is [AT-D2-21](FLEET-STATE.md#at-d2-21-a-frozen-fold-cannot-look-healthy)'s defect at the render layer.
- **Discriminating control:** the `live` `working` seat of `fx-snapshot-4` renders full colour, with
  motion, and no currency label — so the test measures degradation and not a treatment applied to
  everything.

### AT-D3-6 the feed dying is visible within 45 s

- **Build:** apply `fx-snapshot-4`, deliver heartbeats for 60 s of simulated time, then deliver nothing
  for 60 s more.
- **GREEN:** at 45 s of silence the status strip reads **feed down — polling**, [A14](#62-the-animation-table--the-closed-set)'s
  pulse has stopped, a `GET /api/fleet/snapshot` is issued and repeats every 10 s, and **every desk's
  quiet-age readout has continued to grow throughout** — assert the rendered age strings, not the
  internal timestamps. Assert also that the drill-down's `fetch-fresh` block is **re-stamped** by each
  poll rather than ticked ([§ 2.4](#24-the-clock-and-every-age-on-the-page)): a transport block whose
  numbers moved between two polls would be rendering a value nothing delivered, which is the same
  defect as a frozen age wearing the opposite face.
- **RED — the frozen page:** stop the age tick when the feed stops → the floor freezes with every age at
  the value it held when the socket died, which is the most convincing lie this UI can tell: it looks
  exactly like a fleet where nothing has happened.
- **Second RED — the optimistic strip:** leave the indicator on *live* while polling → a polled floor
  claiming to be a live one.
- **Discriminating control:** deliver heartbeats every 15 s for the whole run → the indicator never
  leaves *live* and no poll is issued.

### AT-D3-7 a delta gap resyncs exactly one seat

- **Build:** replay `fx-gap`.
- **GREEN:** the client detects `state_version` jumping by 2, issues **exactly one**
  `GET /api/fleet/seats/aimla/aimla-pm?resync_from=<its last applied version>` — assert the query
  parameter is present and carries the last **applied** version, because it is the only write path for
  D2's `feed_gap_detected` counter ([D2 § 8.5](FLEET-STATE.md#85-gaps-reconnect-and-why-state_version-is-not-seq))
  — converges to the served object, and **no other seat is refetched**; the resync counter in the status
  strip increments by one and the lobby log records it.
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
  already holding `fx-snapshot-4`.
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

- **Build:** a run in which the subscribe is followed by a **forced 500 ms delay** before the snapshot
  response, with two deltas delivered inside that window — one below the snapshot's watermark for its
  seat, one above.
- **Build — the mid-session half:** `fx-membership` leg (c), with the same forced 500 ms delay on
  ADMIT (b)'s response. This half exists because [§ 2.2](#22-connect-snapshot-deltas)'s `ADMIT` claims
  the window is closed for an install entering the rendered set **at any time**, and a test that only
  ever admitted installs at connect time would leave the *at any time* half unexercised — which is
  precisely the half a client can get wrong without any test noticing.
- **GREEN:** the client's final seat map equals the server fixture's exactly; the below-watermark delta
  is **discarded** and the above-watermark one **applied**; the snapshot render fires **no `edge`-class
  animation** — assert the log gains **no `edge` row** across the snapshot apply, while the
  `held` `entered` rows the delivered states require **are** present, carrying the snapshot object's
  `state_version` as their cause ([§ 6.5](#65-a-snapshot-never-animates)); running the scenario 100 times yields 100 identical
  results.
- **GREEN — the mid-session half:** the client **subscribes** to `private-fleet.aimla-win` before
  issuing ADMIT (b), the delta emitted inside (b)'s window is **applied** at (c) rather than lost, and
  `aimla-win/win-1`'s rendered desk equals the fixture's post-delta object — not (b)'s object. Assert
  the **subscription** was opened, not merely that the desks appeared: a client that fetched and
  rendered without subscribing passes every render assertion on the first frame and is wrong forever
  after.
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

- **Build:** `fx-snapshot-4`, with the harness's browser clock set **+3 h** from the fixture's
  `server_time`, **and the drill-down opened on `aimla-pm`**. The drill-down is part of the build
  rather than an afterthought: every seat in `fx-snapshot-4` is `live`, so **no desk on that floor
  renders a receipt age at all** ([§ 5.1](#51-the-desk)'s `dark-only` marker), and the transport
  block's *both ages under one* as of *stamp* is the only surface on which the receipt half of this
  test's claim is observable.
- **GREEN:** every rendered age matches the age computed from `server_time` — **on the floor**, every
  desk's quiet age (*nothing done for N*) reads seconds, not three hours; **in the open drill-down**,
  the transport block's receipt age likewise reads seconds and carries its *as of* stamp;
  `clock_offset_ms` is applied to every readout; and every seat-clock
  timestamp (`action.started_at`, `context.sampled_at`, `activity.last_event_time`,
  `session.started_at`) renders as a labelled seat-clock claim and **not** as an age.
- **RED:** compute ages from `Date.now()` → **every desk on the floor reads *nothing done for 3h*** on
  a fleet that is reporting normally, and the open panel's receipt age reads *no data for 3h* beside a
  stamp naming a fetch three hours in its own past. The observable is named on the readout the fixture
  actually produces: an earlier revision of this RED named a desk receipt age, which
  [§ 5.1](#51-the-desk)'s `dark-only` marker had already removed from every desk in this fixture — so
  the failure it claimed to watch could not have appeared, and a RED nobody can see is the decoration
  [§ 11](#11-acceptance-tests)'s preamble refuses.
- **Second RED:** render `action.started_at` as an elapsed time → a seat skewed by +10 minutes shows a
  call that started in the future ([D2 § 3.3](FLEET-STATE.md#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by)).
- **Discriminating control:** the same fixture with the browser clock correct → identical output, so the
  test measures the offset and not the rendering.

### AT-D3-11 an unrecognised member renders as unrecognised

- **Build:** deliver a delta whose `render_state` is `"pondering"`, one whose `badges` contains
  `"quantum_flux"`, and one whose `unknown_reason` is `"reasons"`.
- **GREEN:** each renders the **unrecognised** glyph or badge carrying the raw string; the desk is
  treated as not-current; the lobby log records each distinct value **once**; nothing crashes and no
  other desk is affected.
- **RED — the nearest match:** map the unknown `render_state` to the closest known member → a seat in a
  state this client has never heard of renders as `working`, which is the most flattering possible
  guess and the one a fresh deploy would produce during a rolling upgrade.
- **Second RED — the healthy default:** default to `live`/`working` → the same defect with no guess at
  all.

### AT-D3-12 asset provenance gates bite

- **Build:** run the asset gates against the repository ([§ 10.1](#101-the-manifest-and-the-two-gates)).
- **GREEN:** every asset file has an `ATTRIBUTION.md` row; every row's SHA-256 matches its file; every
  licence identifier is in the allowlist; the character tree contains **no** image file; the lineage
  file names the upstream repository, the commit and the MIT notice.
- **RED — the unlisted asset:** add a tile with no row → Gate 1 fails naming the path. **Second RED —
  the swapped bytes:** replace a listed file's contents, leaving the row → the SHA-256 check fails.
  **Third RED — the vendored character, both clauses:** drop a `sprites.webp` into the character tree
  — a format an image-extension *denylist* would not have listed → Gate 2 clause 1 fails naming the
  path; then paste a 40 KB base64 PNG into `characters/atlas.ts`, a file clause 1 admits → clause 2
  fails on the embedded literal. Both halves are watched, because the first is what an earlier draft of
  this gate reduced to and the second is the shortcut that draft left open.
  **Fourth RED — the wrong licence:** set a row's identifier to `CC-BY-NC-4.0` → the allowlist check
  fails.
- **Discriminating control:** the clean tree passes all four, so the gates are known to be capable of
  reporting *provenance is complete*.

### AT-D3-13 every state is legible without motion

- **Build:** render `fx-snapshot-4`, `fx-degraded` and a seat in each remaining `render_state` member
  under `prefers-reduced-motion: reduce`; capture a static image of each desk. **The remainder is
  named rather than left to be worked out**, because *"each remaining member"* is only checkable
  against a stated partition: `fx-snapshot-4` delivers `working`, `idle` and `blocked`; `fx-degraded`
  delivers `catching_up`, `stale`, `offline`, `disabled` and `retired`; so the seats this test adds are
  **`stalled`** (with an `api_error_type` of `rate_limit`) and **`unknown`** (with an `unknown_reason`
  of `turn_killed_by_clear`) — two, and the ten are covered.
- **GREEN:** all **ten** `render_state` members are pairwise distinguishable from the static images
  alone, and each carries its label line; every animation row's reduced-motion form is what appears;
  the log gains **no `edge` row**, and every `held` row with **`phase: entered`** reads
  **`motion: false`** — which is the assertion that the reduced-motion form was *selected*, where an
  empty log would equally have reported a renderer that drew nothing at all. The phase scope is
  load-bearing rather than pedantic: a `left` row's `motion` is `false` by definition, so a predicate
  over *every* `held` row is satisfied in part by rows that prove nothing about reduced motion.
- **RED:** distinguish `working` from `idle` by motion alone — the same pose, one animated — and the two
  become one desk in a screenshot, which is how most of this floor will be reviewed and how all of it
  will be read by anyone who has motion disabled.
- **Discriminating control:** with motion enabled, the same fixtures produce the animation rows
  [§ 6.2](#62-the-animation-table--the-closed-set) predicts.

### AT-D3-14 a null is never drawn as a zero

- **Build:** render `fx-nulls`.
- **GREEN:** the context gauge reads **not reported** and the bar is absent — **not** 0 %; there is no
  task chip; the monitor shows the state line rather than a blank; `session` renders *no session open*;
  `model_label` is omitted rather than empty; the intern table is absent rather than showing zero
  stools; a `null` `counters` object on the health view reads **unreadable** rather than a column of
  zeros; and on `nulls-b`, whose `delivery.no_data_since` is null on an `offline` desk, the label reads
  ***no data yet*** rather than *no data since null* ([§ 7.1](#71-the-render-per-state),
  [§ 3.4](#34-a-new-seats-first-appearance)) — which is the render an implementer building the
  [§ 7.1](#71-the-render-per-state) switch from its `offline` row alone would get wrong.
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
  second, identical heartbeat.
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
  snapshot that omits the retired seat entirely.
- **GREEN — on the message alone:** the desk clears in place with the plate, the **reason** and the
  **time**, both of which the message carries, and with **no operator name** — [D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed)'s
  payload for `seat.retired` is `install_id`, `seat_id`, `reason`, `at`, and carries no `by`, so a name
  on the plate at that moment would be a name the wire never sent ([§ 5.1](#51-the-desk)).
- **GREEN — on the delta:** `retired.by` lands and the plate gains the operator; the result is
  identical in either arrival order and applying both twice changes nothing
  ([§ 2.5](#25-what-re-renders-and-when)); the drill-down still shows what the seat was doing and what
  its transport did afterwards; the desk is **still present** in the snapshot for 14 days; when a
  snapshot finally omits it, the desk is removed **and the lobby log carries a line naming the seat and
  the reason**.
- **RED — the vanishing desk:** remove the desk when `render_state` becomes `retired` → a desk that
  existed a second ago is simply gone, with no rendered state that says why
  ([D2 § 4.10](FLEET-STATE.md#410-retirement-is-a-rendered-state)).
- **Second RED — the removal on a delta:** remove a desk on any signal other than a snapshot apply →
  a missed delta or a resync can delete a live seat from the floor.
- **Third RED — the invented operator:** fill the plate's operator on the message-only render — from a
  default, from the fleet's operator, or from whatever the field last held — → a retirement attributed
  to a person the wire never named, on the one plate whose whole content is *who did this and why*.

### AT-D3-17 a seat the client does not hold is fetched, never patched

- **Build:** replay `fx-membership`.
- **GREEN:** the delta for the unknown seat triggers exactly one
  `GET /api/fleet/seats/{install}/{seat}`; deltas for that seat received while the fetch is in flight
  are buffered and drained against the fetched `state_version`; the inserted desk renders **without** an
  arrival animation ([§ 3.4](#34-a-new-seats-first-appearance)); the lobby log records *seat added to
  the floor*.
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
| Feed heartbeat | 15 s | **Cited** — [D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed) | [§ 2.4](#24-the-clock-and-every-age-on-the-page) |
| Feed presumed dead | 45 s | **Cited** — D2 § 8.3, three heartbeat intervals | [§ 9](#9-failure-paths-and-their-observables) |
| REST poll while the feed is down | 10 s | **Cited** — [D2 § 2.2](FLEET-STATE.md#22-fail-posture-per-path) | [§ 9](#9-failure-paths-and-their-observables) |
| Delta coalescing tick | 250 ms | **Cited** — D2 § 8.3, below the ~300 ms at which a human notices latency | [§ 6.1](#61-the-rule-and-what-a-loop-is-allowed-to-mean) |
| **Loop frame rate** | **4 fps** | **Derived** — one frame per 250 ms coalescing tick, so no loop on the floor can appear more informative than the fastest rate at which the wire can inform it. It is fixed across every loop and every seat, because a rate that varied would encode a quantity nothing sent | [§ 6.1](#61-the-rule-and-what-a-loop-is-allowed-to-mean) |
| **Gauge tween and glyph cross-fade** | **250 ms** | **Derived** — the coalescing tick again: a tween longer than the interval between two deltas would still be animating the previous value when the next arrives | [§ 6.2](#62-the-animation-table--the-closed-set) |
| **Age readout refresh** | **1 s** | **Chosen** — the unit the smallest rendered age uses. Slower shows a second that has passed; faster repaints for nothing | [§ 2.4](#24-the-clock-and-every-age-on-the-page) |
| Seat `stale` / `offline` thresholds | 300 s / 900 s | **Cited** — [D2 § 4.5](FLEET-STATE.md#45-link-states), D1's numbers | [§ 7.1](#71-the-render-per-state) |
| `catching_up` threshold | `oldest_unsent_age_s > 300` | **Cited** — D2 § 4.5 | [§ 5.1](#51-the-desk) |
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
| Gate 2's embedded-literal bound | **1,024 B** | **Chosen** — above any legitimate base64 literal in generator source (a seed table, a palette) and far below the smallest useful sprite sheet, so clause 2 cannot fire on the port and cannot miss a vendored sheet. **What re-derives it:** the longest literal the port actually contains, measurable the moment the port lands ([§ 14](#14-open-questions-for-the-review-loop) item 7) | [§ 10.1](#101-the-manifest-and-the-two-gates) |
| D2 § 8.2.1's nullable members | **36** | **Cited** — the rows [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)'s field table marks `Null? yes`; the population `fx-nulls` must cover, and the reason it is two seats rather than one | [§ 11](#11-acceptance-tests) |
| Lobby event-log length | **200 lines** | **Chosen** — enough to hold a reconnect storm's worth of membership and resync lines; it is a narration of the client, not a record, and D2's own surfaces hold the durable history. **What re-derives it:** the line count one measured reconnect storm writes — every line has a named producer in [§ 5.5](#55-the-clients-own-narration), so it is measurable as soon as a client exists, and a storm that fills the log is the trigger | [§ 4.1](#41-the-lobby--the-building-summary), [§ 5.5](#55-the-clients-own-narration) |

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
| **G2 source-field closure** | every field named in a source column of [§ 5](#5-the-render-map--every-rendered-fact-and-its-d2-field), [§ 6.2](#62-the-animation-table--the-closed-set) or [§ 7](#7-degradation--how-a-degraded-seat-is-unmistakable) against [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)'s field table and § 8.2.4's fleet object, plus the **residue** — D2 fields this document renders nowhere — printed rather than counted as a pass | **tool-checked** |
| **G3 cap arithmetic** | 6,112 / 8,192 / 263 / 2,080 / 7 / 15 / 7,953 / 8,216 / 24 re-computed from the **three** inputs (worst case, bound, per-element), and those three checked for **presence in D2** — anywhere in D2, not at a named statement, which is the narrower claim the tool can actually make and is why "is a Cited number true at its D2 home" stays on the hand-verified rows below | **tool-checked** |
| **G4 § 12 ↔ definition site** | each row's number as a whole numeric token at the section it cites, then **perturbed** to prove the match can fail for that row; the residue — numbers some other value would also have matched — printed individually | **tool-checked**, with its residue printed |
| **G5 acceptance-test closure** | every fixture named in a test against the fixture table, both directions; every test having a **RED**; the AT ids contiguous from 1 with no gaps or duplicates | **tool-checked** |
| **G6 Appendix A** | its stated counts against both row counts, and the **marker population of D2 and of D1** against the sections Appendix A cites from an upstream-attributed position. The recognizer is not the literal `D3` alone — it is `D3` **plus the render-directed phrasings upstream actually uses**: *rendered in the drill-down*, *the drill-down can say*, *visible in the drill-down*, *must render*, *renders as quiet*. Grepping for `D3` alone is what let [D2 § 4.7](FLEET-STATE.md#47-which-clock-each-ceiling-is-measured-from) and [§ 4.8](FLEET-STATE.md#48-what-may-never-mint-a-state) place three render obligations this document neither listed nor discharged | **tool-checked**, with a stated limit: an obligation phrased in none of those forms is still not grep-derivable, so the tool prints the semantic remainder **row by row** rather than as a count |
| **G7 state and badge render closure** | **six** member sets — `render_state`, `unknown_reason` and the 18 badges from D2, `link_state` and `activity_state` from [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)'s bounds cells, and `api_error_type`'s twelve from [D1 § 6.4](EVENT-SCHEMA.md#64-turnend), which is where D2 sources it — each re-derived upstream and set-differenced against this document's tables in **both** directions: a member with no render, and a render for a member no input can select. The `link_state` half is what makes `disabled`'s absence from [§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim) impossible to leave in | **tool-checked** |
| **G8 desk-slot worked example** | the four hashes, their moduli and the assignment, re-computed from [§ 3.2](#32-the-desk-slot-function)'s stated function; and the collision example of [§ 3.3](#33-collision-displacement-and-why-a-desk-move-is-itself-an-event) | **tool-checked** |
| **G9 the delivery contract** | [D2 § 6.5](FLEET-STATE.md#65-the-fold)'s **ten** non-version-bearing members, re-derived from that section's own table, against every render row that sources one: each must carry **`fetch-fresh`** or **`dark-only`** ([§ 2.4](#24-the-clock-and-every-age-on-the-page)), and this document must cite § 6.5 at all. A field-existence check cannot see a delivery contract — all ten exist in § 8.2.1, which is why G2 was clean over a receipt age that freezes on every live desk | **tool-checked**, with a stated limit: it reads the render tables and the panel table, so a bookkeeping member reintroduced in **prose** is outside it, and the tool prints those mentions as named residue |
| Whether a rendering is *good* | — | **hand-verified**, and it is a review question this document cannot mechanise: the tool checks that every rendered fact has a field and every animation has an event, never that the floor is legible |
| Whether a **Cited** number matches what D2 says | — | **hand-verified**: the tool checks the number's presence at its D3 home, not its truth at D2's |

**What the tool deliberately does not do.** It does not render anything, it does not check prose for
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
| 3 | **No ambient life at all** — no breathing, blinking, NPCs or moving scenery | permit decorative motion that carries no state | Motion is the floor's vocabulary. A viewer cannot tell decorative motion from state-bearing motion at a glance, which is the range this screen is read at, so decoration would spend the vocabulary on nothing | a still floor looks still. That is accepted: a still floor **is** a still fleet, which is the reading we want |
| 4 | **A state-held loop is permitted, at a fixed rate that encodes nothing** | fire an animation only on edges, never hold one | A `working` desk must look different from an `idle` one at a glance and across a room, and a pose alone is weaker at distance than a pose that moves. The rate is pinned to the coalescing tick so the loop cannot claim more than the feed can carry | a loop is running while the underlying claim is bounded only by D2's ceilings. That is why every loop stops the moment its state's currency is in doubt ([§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim)) |
| 5 | **A snapshot, poll, resync, insert or reconnect never animates** ([§ 6.5](#65-a-snapshot-never-animates)) | animate the difference between the old and new object | The difference between two client states is not a fact about a seat. Animating it would play an arrival at every desk on every reconnect and make the floor's motion mean "the network hiccupped" | a state change that arrives via a snapshot rather than a delta is not announced. It is rendered — just not narrated — and the drill-down and the log carry the detail |
| 6 | **The desk slot is a pure hash function of `(install_id, seat_id)`** ([§ 3.2](#32-the-desk-slot-function)) | sorted order; arrival order; a server-assigned slot | Sorted order shifts the whole floor when a seat is provisioned; arrival order is not a function of the rendered set, so two browsers disagree; a server slot is a field this document may not mint. The hash gives every client the same answer with no stored state at all | an arrival can displace an incumbent on a collision — bounded to the chain, rendered as a move, and with its frequency stated as `N/S` ([§ 3.3](#33-collision-displacement-and-why-a-desk-move-is-itself-an-event)) |
| 7 | **A desk move is an event and is animated as one** | re-lay the floor silently on the next render | The rule that governs everything else on this floor is that nothing moves without a cause. A silent re-layout would be the one exception, on the one occasion an operator is most likely to think they misread the screen | one more animation row, and one more thing that can be got wrong |
| 8 | **An unknown seat in a delta is FETCHED, never patched** ([§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold)) | apply the patch as an insert; or ignore the delta until the next snapshot | A patch is a shallow merge over an object the client may not hold, so the insert would be a seat object with holes, and a hole renders as *nothing is happening*. Ignoring it leaves a live seat invisible until a reconnect | one HTTP request per newly-seen seat, ever. [§ 14](#14-open-questions-for-the-review-loop) item 2 is the membership message that would remove even that |
| 9 | **Install membership is snapshot-only; the snapshot that discovers one is triggered by a rendered disagreement, never by a timer; and a discovered install is then ADMITTED rather than merely rendered** ([§ 4.1](#41-the-lobby--the-building-summary), [§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold), [§ 2.2](#22-connect-snapshot-deltas)'s `ADMIT`) | poll the snapshot on a timer; or leave discovery to a reconnect and the manual refresh alone | A discovery poll invents a cadence D2 does not state and fetches the whole fleet on a schedule. But `fleet.seats_total` already rides every heartbeat, so the client can **prove** its population is short within 15 s — and a floor that renders *the client holds 3 of 4 seats* and then does nothing about it is a floor that reports a defect it could have fixed with one request. Rendering *membership as of HH:MM:SS* keeps the staleness visible in the meantime | one snapshot fetch per distinct disagreement, **plus one ADMIT fetch per install ever admitted** — bounded by how often the fleet's own count moves and by how often an install is provisioned, not by a clock. An earlier draft of this row said a new install stays invisible until a reconnect or a manual refresh; that was contradicted by the discrepancy check two sections away, and the check is the half worth keeping. A later draft made the discrepancy fetch the discovery path and stopped there — **discovery without a subscription is a one-frame photograph**, and the per-distinct-`(N, M)` rule guaranteed there was no second chance at one, which is why the subscribe-then-fetch-then-drain ordering is now a named primitive every entry path cites rather than three steps living inside the connect sequence |
| 10 | **The removal of a desk happens only on a snapshot apply** | remove on `render_state: "retired"`, or on any signal | A removal driven by an absence is the inference this design refuses everywhere else. Only a fresh, complete population can honestly say a seat is no longer in it | a seat retired more than 14 days ago lingers until the next snapshot. It renders as `retired` throughout, which is true |
| 11 | **The subagent array cap stays at 8** ([§ 8.1](#81-the-cap-stays-at-8--the-arithmetic-and-the-reason)) | raise it to 15, the largest value the 8 KiB bound admits | The drill-down reads the uncapped detail response, so the array's only consumer is the floor's side table, where 15 stools is D2's "a list, not a desk" at a smaller number; and the 2,080 B of spare is the margin the next field addition needs | a fleet that routinely runs more than 8 concurrent dispatches reads *+N more* on the floor and opens the panel for the detail. Both halves of what would change this are measurable after P3 |
| 12 | **`prefers-reduced-motion` is a first-class rendering with its own column** | disable animation and accept that some states collapse | Two states distinguished only by motion are one state in a screenshot and one state to any viewer with motion disabled — and screenshots are how most of this floor will be reviewed | every animation row owes a static form, which is one more column to keep true and is checked by [AT-D3-13](#at-d3-13-every-state-is-legible-without-motion) |
| 13 | **A null is rendered as *not reported*, never as a zero** ([§ 7.5](#75-what-a-degraded-desk-may-never-look-like)) | coalesce nulls to sensible defaults so the layout never shifts | A zeroed gauge is a measurement the wire never made; a placeholder task title is a claim nobody sent. `docs/KANBAN.md § G-1`'s clean zero is the same defect one layer out | the layout must accommodate absent elements, which is a design constraint on the desk rather than a rendering convenience |
| 14 | **The floor requires 1,280 × 800 and falls back to a list, not a scaled floor** | scale the map to the viewport | A floor whose nameplates and badges are unreadable shows state without letting anyone read it, which is worse than the honest list of the same facts | small viewports get no floor. The list carries every fact, and the number is re-derived once a desk has a measured width |
| 15 | **No framework, renderer or bundler is specified** | pin the stack so the implementer has one less decision | None of this document's properties depends on one, and a spec that pinned a stack would expire with it. What *is* pinned is the asset pipeline, because that is where a licence violation enters | two implementers could make different stack choices. Neither can make different **honesty** choices, which is what this document is for |
| 16 | **Character art is generated from the seat key, never vendored** ([§ 10.2](#102-characters-the-munder-difflin-port)) | vendor a sprite sheet and map seats onto it | D-07 permits the generator (MIT) and forbids the upstream's commercial tilesets. Generating also makes a seat's appearance a function of its identity, so it survives reloads with no stored state — the same property the desk slot has | the generator must be ported before any character renders, and Gate 2 refuses the shortcut ([§ 10.1](#101-the-manifest-and-the-two-gates)) |
| 17 | **Provenance is a build gate, not a document** | keep `ATTRIBUTION.md` current by discipline | An attribution file kept by discipline is one an asset can be added without. Gate 1 makes the missing row fail the build, which is the only moment it is free to fix | every asset addition costs a manifest row and a hash |
| 18 | **The status strip claims *live* only with a fresh feed message AND a REST response newer than the last `401`** | trust the socket, since an authorized handshake opened it | D2 refuses machine tokens on the socket precisely because an open connection has no revocation story — and the browser's session has the same property, which D2 does not address ([§ 9](#9-failure-paths-and-their-observables) F7) | the claim is slightly conservative on a client that has made no REST call recently. Erring toward *not live* is the correct direction for this product |
| 19 | **A verifier ships with this document** | leave it to the build phase | D1 and D2 both shipped one, and the classes it catches — an animation with no driver, a field this document renders that D2 does not send, a state member with no render, an arithmetic claim that drifted — are exactly the single-surface edits to multi-surface facts a set difference catches in milliseconds and a reader catches on the third pass, if ever | one more script to keep true, and every figure here is now a figure a change must move in all its homes at once |
| 20 | **The animation table carries two classes — `edge` and `held`; the log records both under different causality rules; and a `held` render writes an `entered` row and a `left` row, each with its own `cause` rule** ([§ 6.2](#62-the-animation-table--the-closed-set), [§ 11](#11-acceptance-tests)) | one schema for all sixteen rows: one *cause* column, one totality rule, one causality sentence — and, at an earlier revision, one schema for a held render's entry and its exit | Under one schema the halves contradict each other on this document's own headline fixture. [§ 6.1](#61-the-rule-and-what-a-loop-is-allowed-to-mean) rule 2 holds a loop for as long as a delivered field says so, and [D2 § 8.2.2](FLEET-STATE.md#822-worked-snapshot)'s snapshot delivers a `working` seat — so a correct client starts a loop where there is no message to record as its cause, and [AT-D3-1](#at-d3-1-no-animation-without-its-event)'s *every row has a cause* could not hold beside [§ 6.5](#65-a-snapshot-never-animates)'s *a snapshot fires nothing*. The split keeps the strict rule where it is true — an edge animation with no causing message is exactly the defect the honesty principle names — and gives held renders the rule that is true of them: held by a delivered field, logged with the `state_version` that delivered it | one more column in [§ 6.2](#62-the-animation-table--the-closed-set) and three more fields in the log (`phase`, `at`, and `cause`'s per-phase rule), and a reviewer must decide which class each new row is. The alternative was an implementer choosing between a floor that goes static after every reconnect and a log whose totality claim no test could satisfy. **The `phase` half was added after the enter-and-leave rule re-opened that same unsatisfiability one class down**: an exit row is not held by anything and is drawn as nothing, so under one held-row schema [AT-D3-1](#at-d3-1-no-animation-without-its-event)'s *the hold condition holds in the cause object* was false for every exit row on a correct client — and repeating the entering version instead made two rows identical in all six fields, from which *for how long* was unrecoverable |

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

7. **⇢ Operator / review — the art sources are not named in this repository.**
   D-07 names *CC0 tilesets* and *munder-difflin's procedural generator* and this repository records no
   tileset, no upstream URL and no commit. **Blocks:** card #7340 (the character port) cannot start, and
   the 1,280 × 800 viewport floor cannot be re-derived from a measured desk width
   ([§ 12](#12-every-number-and-where-it-comes-from)). **In the meantime:** the licence allowlist, the
   manifest and both gates are specified and testable without knowing which assets will be listed
   ([§ 10](#10-art-and-assets--provenance-as-a-gate)). **Closes it:** the upstream repository and commit
   for the generator, and the chosen tileset — recorded in `ATTRIBUTION.md` and
   `resources/characters/LINEAGE.md`, not in a message.

8. **✅ CLOSED — the `subagents` cap is 8.**
   [D2 § 14](FLEET-STATE.md#14-open-questions-for-the-review-loop) item 9 handed this to D3.
   [§ 8.1](#81-the-cap-stays-at-8--the-arithmetic-and-the-reason) decides it with the byte arithmetic
   re-derived from D2's measured figures: seven more elements fit and the cap **could** reach 15 at
   7,953 B, 16 breaches at 8,216 B, and the answer is still 8 because the drill-down reads the
   **uncapped** detail response, so the array's only consumer is the floor's side table. Nothing in D2
   changes; the item is answered, not amended.

9. **⇢ D2 — six drill-down explanations D2 addresses to this document have no field on any read
   surface.** This is **one class, filed once**: D2 stores each fact and says the drill-down renders or
   answers it, and none of them appears in
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

   **Blocks:** the drill-down's whole *why* half — every one of these is an anomaly or an
   approximation that D2 deliberately kept visible rather than absorbing, and a panel that cannot show
   them re-absorbs them at the last layer. **It is the same gap as item 1**, seen from the other end:
   `detail` has no field table, so *"the open call list in full"* neither promises nor forbids any of
   the per-call flags above. **In the meantime:** none of the six is rendered, and this document
   renders no guess in their place; the timeline shows `compaction.start` / `compaction.end` as events
   like any other, which is the after-the-fact reading rather than the current one, and the intern and
   action lines carry no exactness qualifier at all rather than implying one they cannot check.
   **Closes it:** naming the six among § 8.2.3's `detail` members (and `compacting` or
   `compaction_open_since` on the seat object, which is the only one the **floor** would use).

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
    [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s two markers — the receipt age is `dark-only`
    (rendered on `stale`/`offline` from the version-bearing `delivery.no_data_since`, and on no other
    desk), and every other rendered value among the ten is `fetch-fresh` (stamped *as of*, never
    ticked). The `fold_lag` **treatment** is driven off the badge, which is version-bearing, so the
    degradation still announces itself even though its number does not move
    ([§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy)). **Closes it:** any one of —
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
can keep. [D1](EVENT-SCHEMA.md) addresses it in **eleven** more, directly or through its
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
quiet* — over **both** upstream documents. The rest — a "renders" clause in none of those forms, a
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
| T20 | § 7.3 | The reporter's `degraded` array is rendered **"since reporter start"** with `reporter.uptime_s` and the counter's value beside it — never as "now" | [§ 7.2](#72-badges-every-member-has-a-render), [§ 5.2](#52-the-drill-down) |
| T21 | § 7.1 | `lossy` renders with its number; `seq_gap` is D2's own badge and is **never** rendered as `lossy` — they are different failures with different fixes | [§ 7.2](#72-badges-every-member-has-a-render) |
| T22 | § 8.6 | The one outcome forbidden on the read surface — a `200` with an empty fleet — "renders as an empty office, which is indistinguishable from a fleet that has gone home" | [§ 9](#9-failure-paths-and-their-observables) F4–F6, [AT-D3-8](#at-d3-8-a-refusal-is-never-an-empty-office) |
| T23 | § 9 | MFA gates the page, the WebSocket handshake **and** the REST snapshot; the live feed is browser-only | [§ 4.4](#44-routes-and-what-each-one-fetches), [§ 9](#9-failure-paths-and-their-observables) F6 |
| T24 | § 4.5 | A seat is **never removed** for going quiet; `stale`/`offline` carry `no_data_since` so the render is "no data since 14:18" rather than a bare glyph; no row vanishes between two refreshes | [§ 5.1](#51-the-desk), [§ 7.1](#71-the-render-per-state), [§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold) |
| T25 | § 4.4 | An idle seat that goes quiet **stays `idle`** while it heartbeats and becomes `stale` when it stops; leaving `live` **masks** the activity state rather than clearing it | [§ 7.1](#71-the-render-per-state), [§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim) |
| T26 | § 8.3.1 | `patch` is a shallow merge — a nested object is replaced whole — and `changed[]` is what a client uses to decide **what to animate** | [§ 2.2](#22-connect-snapshot-deltas), [§ 6.2](#62-the-animation-table--the-closed-set) |
| T27 | § 4.2 | `render_state`'s ten members, and `retired` short-circuiting above both axes | [§ 7.1](#71-the-render-per-state), [§ 3.5](#35-retirement-and-the-only-removal) |
| T28 | § 8.2.3 | The seat-detail response "is the drill-down's source" and carries the open call list **in full, not capped at 8** | [§ 4.3](#43-the-desk-drill-down-panel), [§ 8](#8-interns--subagent-rendering-and-the-cap) |
| T29 | § 3.1 | A seat that only heartbeats is quiet and **renders as quiet**: its receipt age near zero, its activity age growing without bound, "both on the wire, separately, so no consumer has to guess which one it is holding" | [§ 2.4](#24-the-clock-and-every-age-on-the-page), [§ 5.1](#51-the-desk), [§ 5.2](#52-the-drill-down) — the quiet half is version-bearing and rides the desk; the receipt half is one of § 6.5's ten, so it is `dark-only` on the desk and `fetch-fresh` in the panel ([§ 14](#14-open-questions-for-the-review-loop) item 12) |
| T30 | § 4.4 | `resolution` / `resolution_source` carry `server_ceiling` — the value "exists so the drill-down can say *the server cleared this*" | [§ 14](#14-open-questions-for-the-review-loop) item 9 — on no read surface; this document renders nothing in its place rather than implying the request was answered |
| T31 | § 4.7 | "Durations **rendered in the drill-down**": the event's own `duration_ms`, else `event_time` arithmetic, **with `duration_source`** | [§ 14](#14-open-questions-for-the-review-loop) item 9 — neither field is on a read surface, so [§ 5.2](#52-the-drill-down) renders no duration it cannot source and no qualifier it cannot check |
| T32 | § 4.8 | A `tool.end` whose `match` is `synthesized` — a close with no open: the flag "is stored and **rendered in the drill-down**, so the anomaly is a visible flag rather than an absorbed one" | [§ 14](#14-open-questions-for-the-review-loop) item 9 |
| T33 | § 4.8 | A `tool.end` whose `match` is `lifo_tool_name`: "`match` is stored and **rendered in the drill-down** so an approximate attribution is legible as one" | [§ 14](#14-open-questions-for-the-review-loop) item 9 |
| T34 | § 4.8 | A compacting seat mints no activity state, and its quiet 40 s is "still visible in the drill-down" | [§ 14](#14-open-questions-for-the-review-loop) item 9; [§ 7.1](#71-the-render-per-state) has no compaction state, which is the other half of the same rule |
| T35 | § 4.8 | `agent_scope` and `parent_call_id` are **labels**, "stored for the intern join and never gate anything" — what is forbidden is a scope-dependent **state rule** | [§ 5.1](#51-the-desk), [§ 5.2](#52-the-drill-down), [§ 8](#8-interns--subagent-rendering-and-the-cap): the intern list selects on them, and no pose, currency label or badge reads them |
| T36 | § 6.5 | The **ten** non-version-bearing members: "every quantity this document says is *rendered* from one of the ten is rendered from a value that cannot be moving at the moment it is read" — the raw skew, spool depth, cursor and fold lag are "served fresh by § 8.2.3, the snapshot and § 8.2.4 rather than held by a client between deltas" | [§ 2.4](#24-the-clock-and-every-age-on-the-page)'s `fetch-fresh` / `dark-only` markers, applied in [§ 5.1](#51-the-desk), [§ 5.2](#52-the-drill-down), [§ 4.3](#43-the-desk-drill-down-panel) and [§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy); [§ 14](#14-open-questions-for-the-review-loop) item 12 |
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
| U9 | § 1 | The scope boundary D1 draws: **"Anything rendered — D3"** | [§ 1.1](#11-what-this-document-owns), [§ 1.2](#12-non-goals--stated-so-an-implementer-cannot-widen-scope-in-good-faith) |
| U10 | § 6.12 | `notification_kind` has exactly **three** members and no `other`, "so a render branch over a fourth is neither owed nor wanted" — a wire member no input can produce is a branch D2 and D3 would build and never reach | [§ 5.4](#54-what-is-never-rendered): this document renders no `notification_kind` at all — an attention request reaches the floor only as `blocked` ([§ 7.1](#71-the-render-per-state)), whose entry and exit D2 owns — and [§ 7.6](#76-the-three-remaining-member-sets-published-so-membership-is-testable) publishes the sets it *does* branch over, so an unreachable branch here is a set-difference failure rather than a reading |
| U11 | § 6.4 | `D2-MUST` #1's rendering half: `stalled` carries `api_error_type` "so the drill-down can say *which* error" — and D1 mints a **twelfth** member, `unrecognised`, precisely so the harness's own `unknown` is not overloaded as the coercion target | [§ 7.1](#71-the-render-per-state)'s `stalled` row, [§ 5.1](#51-the-desk), and [§ 7.6](#76-the-three-remaining-member-sets-published-so-membership-is-testable), which publishes all twelve with the two-way distinction spelled out |

**Nothing addressed to this document is undischarged.** **Thirteen** of the thirty-eight are
discharged with a stated gap in the upstream contract rather than by a rendering alone, and every one
is filed in [§ 14](#14-open-questions-for-the-review-loop) rather than absorbed silently: T6's timeline
has no field table and T28's `detail` has none either (item 1, one class filed once); the membership
case T24's "never vanishes" implies has no message (item 2); T23's MFA gate has no rule for an expiring
session on an open socket (item 5); T30, T31, T32, T33, T34 and T38 are six drill-down explanations D2
names and no read surface carries (item 9, one class filed once); T29's receipt age and T36's fold lag
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
| 0 | `ATTRIBUTION.md`, the asset manifest, and both provenance gates | **[AT-D3-12](#at-d3-12-asset-provenance-gates-bite)** RED on each of its four planted defects, then GREEN — first, because an asset added before the gate exists is an asset nobody will go back and license |
| 1 | the character generator port + `resources/characters/LINEAGE.md` (card #7340) | **BLOCKED on [§ 14](#14-open-questions-for-the-review-loop) item 7** — the upstream repository and commit are recorded nowhere in this repository, so the port cannot start and this step cannot be entered ([§ 10.2](#102-characters-the-munder-difflin-port)). Once it can: renders in a plain browser from the seat key alone; the lineage file is complete; both clauses of Gate 2 hold |
| 2 | the fixture harness and the **animation log** ([§ 11](#11-acceptance-tests)) | the log records a cause for every animation, and **[AT-D3-1](#at-d3-1-no-animation-without-its-event)**'s discriminating control passes — a harness that records nothing must not be able to report clean |
| 3 | the client protocol: subscribe, buffer, snapshot, drain, apply, resync, insert ([§ 2](#2-the-client-end-to-end)) | [AT-D3-9](#at-d3-9-the-client-half-of-snapshot-then-deltas), [AT-D3-7](#at-d3-7-a-delta-gap-resyncs-exactly-one-seat), [AT-D3-17](#at-d3-17-a-seat-the-client-does-not-hold-is-fetched-never-patched) |
| 4 | the clock offset and every age readout ([§ 2.4](#24-the-clock-and-every-age-on-the-page)) | [AT-D3-10](#at-d3-10-ages-come-from-the-server-clock) |
| 5 | the desk: the render map and the ten state renders ([§ 5.1](#51-the-desk), [§ 7.1](#71-the-render-per-state)) | [AT-D3-5](#at-d3-5-a-degraded-seat-is-visibly-degraded), [AT-D3-13](#at-d3-13-every-state-is-legible-without-motion), [AT-D3-14](#at-d3-14-a-null-is-never-drawn-as-a-zero) |
| 6 | the animation set ([§ 6.2](#62-the-animation-table--the-closed-set)) | **[AT-D3-1](#at-d3-1-no-animation-without-its-event)** and **[AT-D3-2](#at-d3-2-the-clear-trace-shows-no-idle-anywhere)** — the two hard gates on trusting the floor at all |
| 7 | the floor: the map, the slot function, overflow (card #7341) | [AT-D3-3](#at-d3-3-identity-is-stable-across-a-restart) |
| 8 | the failure renders and the status strip ([§ 9](#9-failure-paths-and-their-observables)) | [AT-D3-6](#at-d3-6-the-feed-dying-is-visible-within-45-s), [AT-D3-8](#at-d3-8-a-refusal-is-never-an-empty-office), [AT-D3-11](#at-d3-11-an-unrecognised-member-renders-as-unrecognised) |
| 9 | the lobby ([§ 4.1](#41-the-lobby--the-building-summary)) | [AT-D3-15](#at-d3-15-the-lobby-never-invents-a-count) |
| 10 | the drill-down and the side table (card #7342) | [AT-D3-4](#at-d3-4-the-subagent-cap-boundary), [AT-D3-16](#at-d3-16-retirement-is-rendered-and-the-removal-is-explained) |

**Three of these are hard requirements before anything downstream may treat this floor as honest:**
**AT-D3-1** (no animation without its event — the operator's principle, made into a test),
**AT-D3-2** (the `/clear` trace shows no idle anywhere — the D3 half of the headline test both upstream
documents exist in this order to make possible), and **AT-D3-8** (a refusal is never an empty office —
because the failure render is the one a dashboard is judged by and the one nobody exercises by
accident).

**A note on order.** Steps 2 and 6 are separable and must stay so: the animation log is an instrument
of the renderer, not of the test suite. If it were built inside the harness, a renderer could start an
animation the harness never saw, and [AT-D3-1](#at-d3-1-no-animation-without-its-event)'s GREEN would
be a statement about the harness rather than about the floor.

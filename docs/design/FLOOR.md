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
   [§ 5](#5-the-render-map--every-rendered-fact-and-its-d2-field). The six things the client computes
   for itself are enumerated as a **closed list** in [§ 2.1](#21-the-six-client-computed-values-closed),
   and every one of them is presentation — a clock offset, an age, a desk position, an animation
   selection, a per-floor count over the objects it already holds, and a sort order. A client-side
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
| The render map: every rendered fact, its D2 field, its example value, its null render | [§ 5](#5-the-render-map--every-rendered-fact-and-its-d2-field) |
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

### 2.1 The six client-computed values, closed

Everything the client computes for itself, and nothing else. The list is closed so that a reviewer can
check a candidate computation against it rather than against a feeling.

| # | Computed | From | Why it is presentation and not state |
|---|---|---|---|
| 1 | **`clock_offset_ms`** = `server_time − browser_now` | every REST response and every feed message ([D2 § 3.3](FLEET-STATE.md#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by)) | D2 **requires** it: "the browser's own clock is never used for an age either… it is the layer nobody controls" |
| 2 | **Ages** — "no data for 4m 12s", "quiet for 38s" | a D2 timestamp minus the corrected clock | The timestamps are the wire's; the subtraction is a rendering of them, and D2 states which basis each age takes ([D2 § 3.3](FLEET-STATE.md#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by)) |
| 3 | **Desk slot** | `(install_id, seat_id)` and the map's slot count ([§ 3.2](#32-the-desk-slot-function)) | A layout function of identity. It reads no state field, so it cannot change when a seat's state does |
| 4 | **Animation selection** and its reduced-motion form | `render_state`, the delta's `changed[]`, and [§ 6.2](#62-the-animation-table--the-closed-set) | A pure function of a delivered field and a published table |
| 5 | **Per-floor counts** | the seat objects the client already holds for that install | The wire has no per-install count ([D2 § 8.2.4](FLEET-STATE.md#824-the-fleet-health-object)'s counts are fleet-wide), so this is the only place it can come from. It is labelled as a count of the seats the client holds, and [§ 4.1](#41-the-lobby--the-building-summary) requires the client to **render the disagreement** rather than pick a winner when the floors do not sum to `fleet.seats_total` |
| 6 | **Sort orders** | floors by `install_id` ascending; desks by slot; timeline as served | Deterministic ordering of received objects |

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
 2. SUBSCRIBE to private-fleet.<install> for every install the client intends to render;
    on a cold start that set is unknown, so step 2 runs again after step 4  (see § 2.3)
 3. BUFFER every seat.delta from this moment
 4. GET /api/fleet/snapshot            -> installs[], each seat with its own state_version
 5. render the world as delivered      -> NO animation fires on this render (§ 6.5)
 6. subscribe to any install seen in step 4 and not yet subscribed; buffer as in step 3
 7. DRAIN the buffer: discard any delta whose state_version <= that seat's snapshot version,
    apply the rest in ascending order
 8. steady state: apply deltas as they arrive, iff delta.state_version == local + 1
      greater  -> resync THAT seat: GET /api/fleet/seats/<i>/<s>?resync_from=<last applied>
      <=       -> discard (duplicate or straggler)
      unknown seat -> the § 2.3 insert path, never a partial object
 9. no message of any kind for 45 s   -> feed presumed dead: indicator, poll at 10 s, reconnect
10. reconnect                          -> re-run from step 1
```

**Subscribing before fetching is what closes the window**, and the reason is D2's:
a state change during the snapshot query is in the buffer, and the per-seat `state_version` is the
watermark that says whether it is already included. Step 6 exists because the *first* snapshot is also
how the client learns which installs exist — see [§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold).

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
| A `seat.delta` arrives on a channel the client is not subscribed to | Cannot happen — the client receives only what it subscribed to. This is why an **install** entering the population is invisible until a snapshot | — |
| An install exists that the client has no channel for | It appears at the **next full snapshot** — on reconnect, or on the operator pressing the lobby's refresh control. The lobby therefore carries a **`membership as of HH:MM:SS`** readout ([§ 4.1](#41-the-lobby--the-building-summary)) beside the fleet counts, so the age of the *membership* picture is visible separately from the age of the *state* picture | Polling the snapshot on a timer to discover installs would invent a cadence D2 does not state and would fetch ~91 KB ([D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object) at 50 seats) to answer a question that changes when an operator provisions an install. Making the staleness visible costs one line of text and no requests, and [§ 14](#14-open-questions-for-the-review-loop) item 2 asks D2 for the membership message that would close it properly |
| A seat the client holds is **absent** from a fresh snapshot | Remove it — but **only on a snapshot apply, never on a delta or a poll**, and write one line into the lobby's event log naming the seat and the reason (*retired more than 14 days ago*, [D2 § 4.10](FLEET-STATE.md#410-retirement-is-a-rendered-state)) | A removal driven by a delta would be a removal driven by an absence, which is the inference this whole design refuses. The only honest removal is a fresh, complete population telling the client the seat is no longer in it |

### 2.4 The clock, and every age on the page

`clock_offset_ms = server_time − browser_now`, refreshed on **every** message and response that carries
`server_time` — which is all of them ([D2 § 8.2](FLEET-STATE.md#82-rest),
[D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed)) — and applied to every age the page renders.
The `feed.heartbeat` at 15 s is what keeps it fresh on an otherwise-silent fleet.

- Ages re-render **every 1 s**, which is the unit the smallest age is rendered in: slower would show a
  second that has already passed, faster would repaint for nothing.
- An age is rendered from the field D2 assigns to it and no other: the **receipt age** from
  `delivery.last_receipt_at`, the **quiet age** from `activity.last_received_at`, the **derivation
  lag** from `derivation.fold_lag_ms`. Their differences are the point
  ([D2 § 3.3](FLEET-STATE.md#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by)); collapsing
  them into one "last seen" would destroy exactly the distinction the product is for.
- A **seat-clock** timestamp — `action.started_at`, `context.sampled_at`, `activity.last_event_time`,
  `session.started_at` — is rendered **as the seat's own claim**, prefixed *seat clock*, and never as
  an age. D2 forbids the age reading directly
  ([D2 § 3.3](FLEET-STATE.md#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by), citing
  [D1 § 10.1](EVENT-SCHEMA.md#101-two-clocks-and-which-is-authoritative-for-what)), and the seat's
  `delivery.clock_skew_ms` is rendered beside it in the drill-down whenever it is non-null.

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
| the `seat.retired` message | the desk transitions immediately, with the reason; the delta that follows (or precedes) is idempotent |
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
| per-floor state summary | the seat objects the client holds for that install ([§ 2.1](#21-the-six-client-computed-values-closed) row 5) | a count per `render_state` member present, e.g. *2 working · 1 idle · 1 stale*, in [§ 7.1](#71-the-render-per-state)'s fixed member order |
| fleet totals | `fleet.seats_total`, `fleet.seats_live` | *4 seats · 4 live*, read from the wire and **never recounted** |
| the discrepancy check | the two above | when `Σ floors ≠ fleet.seats_total`, the lobby renders *the client holds N of M seats — refreshing*, and triggers one snapshot fetch. It never silently picks a winner ([AT-D3-15](#at-d3-15-the-lobby-never-invents-a-count)) |
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

While the panel is open, deltas for that seat patch it live, exactly as they patch the desk.

| Panel section | Contents | Source |
|---|---|---|
| **header** | seat name, floor, `render_state` with its plain-language line, the currency label if any | [§ 5.1](#51-the-desk) |
| **current task** | `task.title`, the tier that answered (`task.source`), the reference as a link when a base URL is configured for its shape ([§ 5.2](#52-the-drill-down)), *stale title dropped* when `task.degraded` | `task.*` |
| **current action** | `action.tool_name`, `action.descriptor`, the seat-clock start time, the elapsed time from `action.started_received_at`, `agent_scope`, `parent_call_id` | `action.*` |
| **context gauge** | the bar, the percentage to one decimal, `used_tokens / total_tokens` when non-null, the sample's own age, and `context.source` (`harness` or `computed`, never mixed — [D1 § 6.11](EVENT-SCHEMA.md#611-contextsample)) | `context.*` |
| **interns** | the subagent list — from the **detail** response, uncapped ([§ 8](#8-interns--subagent-rendering-and-the-cap)) | `detail`, `subagents_open` |
| **recent activity** | the timeline, newest first: `kind`, the seat-clock `event_time`, the receipt time, and the per-kind detail this document renders ([§ 5.2](#52-the-drill-down)) | the timeline endpoint |
| **transport** | both ages, `no_data_since`, `clock_skew_ms`, `spool_lag_events`, `oldest_unsent_age_s`, `seq_epoch`, `last_seq` | `delivery.*` |
| **derivation** | `computed_at`, `fold_lag_ms`, `cursor_event_id` — and the *this state is N s behind* line when the badge is up | `derivation.*` |
| **reporter** | `version`, `platform`, `uptime_s`, `selftest_failed`, `enabled` | `reporter.*`, `enabled` |
| **badges** | every member of `badges[]`, each with its meaning, its counter value from `detail`, and *since reporter start* framing for D1's array ([§ 7.2](#72-badges-every-member-has-a-render)) | `badges[]`, `badges_since`, `detail` |
| **session** | `session_id`, start (seat clock), `source`, `project_label`, `harness_label`, `model_label` | `session.*`, `model_label` |
| **retirement** | when `retired` is non-null: at, by, reason | `retired.*` |
| **raw** | `state_version` and the applied `(seq_epoch, last_seq)`, so a rendered state can be correlated with the wire | `state_version`, `delivery.*` |

### 4.4 Routes, and what each one fetches

| Route | Fetches on entry | Subscribes |
|---|---|---|
| `/` (lobby) | `GET /api/fleet/snapshot` | every install in the snapshot |
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
| the intern join key, and the *this is a subagent's call* marker | `action.call_id`, `action.agent_scope`, `action.parent_call_id` | `"01K3TA4E5F6G7H8J9K0M1N2P3Q"`, `"main"`, `null` | labels only — D2 forbids gating anything on them ([D2 § 4.8](FLEET-STATE.md#48-what-may-never-mint-a-state)) |
| the open-call count, when it exceeds 1 | `open_calls` | `1` | never null; `0` renders nothing rather than a zero |
| the *thinking* pose — a turn open with no call | `open_turn` | `true` | never null; read **with** `open_calls`, and both are D2's facts, not an inference ([§ 6.2](#62-the-animation-table--the-closed-set) row A4) |
| the side table's stools | `subagents`, `subagents[].title`, `subagents[].subagent_type`, `subagents[].started_at`, `subagents[].call_id` | `"draft the D1 event schema"`, `"coder"` | a null `title` renders **untitled** and never an invented one ([§ 8](#8-interns--subagent-rendering-and-the-cap)) |
| the *+N more* tag on the side table | `subagents_open` | `1` | never null; the tag appears only when it exceeds the array's length |
| the task chip | `task.title`, `task.source`, `task.ref`, `task.as_of`, `task.degraded` | `"ingest endpoint"`, `"board_card"`, `"card#7338"` | `task` null ⇒ **no chip**, never a placeholder title |
| the context gauge | `context.used_pct` | `73.2` | `context` null ⇒ the gauge renders as *not reported*, **never as 0 %** ([§ 7.5](#75-what-a-degraded-desk-may-never-look-like)) |
| the gauge's numerals and its own age | `context.used_tokens`, `context.total_tokens`, `context.source`, `context.sampled_at`, `context.sampled_received_at` | `146401`, `200000`, `"harness"` | tokens are nullable; the bar still renders from `used_pct`, which is not |
| the model label | `model_label` | `"claude-opus-5"` | null ⇒ omitted |
| badge cluster | `badges`, `badges_since` | `["lossy"]` | empty ⇒ nothing rendered; a badge appearing is animation [A11](#62-the-animation-table--the-closed-set) ([§ 7.2](#72-badges-every-member-has-a-render)) |
| the *reporting disabled* treatment | `enabled` | `true` | `null` before the first heartbeat, which is not the same as `false` and does not render as off |
| *no data since …* | `delivery.no_data_since` | `null` | non-null only when `link_state ∈ {stale, offline}`; then the desk's label reads *no data since 14:18* rather than a bare glyph ([D2 § 4.5](FLEET-STATE.md#45-link-states)) |
| the receipt age | `delivery.last_receipt_at` | `"2026-08-23T14:23:14.201Z"` | drives the *no data for N* readout |
| the quiet age | `activity.last_received_at` | `"2026-08-23T14:23:14.201Z"` | drives *nothing done for N*; it and the row above are rendered as **two** readouts, because their divergence is the product ([D2 § 3.3](FLEET-STATE.md#33-the-two-ages-and-the-arithmetic-each-one-is-computed-by)) |
| the last thing the seat did, and when it says it did it | `activity.last_kind`, `activity.last_event_time` | `"tool.start"`, `"2026-08-23T14:23:09.882Z"` | the second is a seat-clock claim |
| the *replaying history* treatment | `delivery.oldest_unsent_age_s` | `null` | `> 300` is what made `link_state` `catching_up` ([D2 § 4.5](FLEET-STATE.md#45-link-states)); the desk renders the drain, not the work |
| the *derivation is N s behind* label | `derivation.fold_lag_ms` | `117` | never null ([D2 § 2.3](FLEET-STATE.md#23-a-frozen-fold-is-the-dangerous-degradation)); the label appears at the `fold_lag` badge and the pose stops being read as current |
| the retirement plate | `retired.at`, `retired.by`, `retired.reason` | `null` | present for 14 days after retirement ([§ 3.5](#35-retirement-and-the-only-removal)) |

### 5.2 The drill-down

Everything in [§ 5.1](#51-the-desk), at full fidelity, plus:

| Rendered element | Source | Example | Rule |
|---|---|---|---|
| heartbeat freshness | `delivery.last_heartbeat_at` | `"2026-08-23T14:23:00.412Z"` | rendered beside the receipt age, because a heartbeat-only seat is the case the two readings separate ([AT-D2-4](FLEET-STATE.md#at-d2-4-a-heartbeat-only-seat-never-looks-busy)) |
| clock skew | `delivery.clock_skew_ms` | `412` | rendered whenever non-null, beside **every** seat-clock timestamp in the panel, so a narrative time is never read as an absolute one |
| spool state | `delivery.spool_lag_events`, `delivery.oldest_unsent_age_s` | `0`, `null` | — |
| wire provenance | `delivery.seq_epoch`, `delivery.last_seq`, `state_version`, `derivation.cursor_event_id`, `derivation.computed_at` | `"01K3T0000A5N7M2X9V4B6D0FGH"`, `48211`, `48219`, `9912837` | the correlation D2 provides for exactly this panel ([D2 § 8.5](FLEET-STATE.md#85-gaps-reconnect-and-why-state_version-is-not-seq)) |
| session block | `session.session_id`, `session.started_at`, `session.source`, `session.project_label`, `session.harness_label` | `"a7f2c918-…"`, `"clear"`, `"mezzanine"`, `"claude-code/2.1.240"` | `session` null ⇒ *no session open*, which is a fact, not a blank |
| reporter block | `reporter.version`, `reporter.platform`, `reporter.uptime_s`, `reporter.selftest_failed` | `"0.1.0"`, `"linux"`, `401150`, `[]` | a non-empty `selftest_failed` is rendered as a list of named checks, up to its bound of 8 ([D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)) |
| counters | `detail`'s `seat_counters` rows and the reporter's `heartbeat_counters` / `heartbeat_predicates` snapshots | — | the reporter's are labelled **since reporter start** with `reporter.uptime_s` beside them, per [D2 § 7.3](FLEET-STATE.md#73-how-the-reporters-own-counters-are-handled) — never as *now* |
| the intern list, uncapped | `detail`'s full open-call list | — | [§ 8](#8-interns--subagent-rendering-and-the-cap) |
| the recent-activity timeline | the timeline endpoint | — | see the rule below |
| the task reference as a link | `task.ref` | `"card#7338"` | rendered as a link **only** when a base URL is configured for that reference shape (`card#N` and `<repo>#N` are the two shapes [D2 § 4.9](FLEET-STATE.md#49-the-task-title-merge-and-what-is-not-specified-here) declares); with no configured base it renders as plain text. A guessed URL is a link that goes somewhere wrong, which is worse than no link — [§ 14](#14-open-questions-for-the-review-loop) item 3 |

**The timeline renders only fields that provably exist.** [D2 § 8.2](FLEET-STATE.md#82-rest) declares
the endpoint, its parameters and its ordering, and describes its rows as "the seat's renderable events"
without a field table or a kind list. So this document renders, per row, **only D1's common per-event
fields** — `kind`, `event_time` (seat clock, labelled), `received_at` (server clock, the basis of the
row's age) — which [D1 § 4.3](EVENT-SCHEMA.md#43-common-per-event-fields) declares on every event of
every kind and [D2 § 6.4](FLEET-STATE.md#64-ddl) stores verbatim, plus the `data` members this document
already renders elsewhere for that kind where the response carries them. **It renders no per-kind
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
   ([§ 6.5](#65-a-snapshot-never-animates)).

### 6.2 The animation table — the closed set

**Every animation, its driver, and what its absence means.** "Edge" is the exact condition on an
applied delta; "reduced-motion form" is what replaces the motion under
[§ 6.4](#64-reduced-motion-is-a-first-class-rendering-not-a-degradation), and it carries the same fact.

| # | Animation | Where | Driving fact (D2) | Edge that starts it | Ends | Reduced-motion form | Its absence means |
|---|---|---|---|---|---|---|---|
| **A1** | `arrive` — the character walks in and sits | desk | `render_state` | a delta whose `changed[]` contains `render_state` and whose new value leaves `offline` | on arrival at the desk | the character is simply present | the seat has not left `offline` |
| **A2** | `depart` — the character stands and walks out, leaving the chair empty | desk | `render_state` | a delta whose new `render_state` is `offline` | at the door | the chair is empty and labelled | the seat is still reporting |
| **A3** | `work` — typing at the keyboard, 4 fps loop | desk | `render_state` | `render_state == "working"` | when it is not | a *working* pose, static, with the glyph | the seat is not working **now** |
| **A4** | `think` — leaning back, watching the monitor, 4 fps loop | desk | `open_calls`, `open_turn` | `render_state == "working"` **and** `open_calls == 0` **and** `open_turn == true` | when either fact changes | a *thinking* pose, static | there is an open call, so A3 runs instead |
| **A5** | `tool-swap` — the monitor's glyph changes, one 250 ms cross-fade | desk monitor | `action.tool_name` | a delta whose `changed[]` contains `action` and whose `action.tool_name` differs from the held one | after one tick | the glyph changes with no fade | the action did not change |
| **A6** | `idle` — the chair turns from the desk, the monitor dims. **No loop.** | desk | `render_state` | `render_state == "idle"` | when it is not | identical — this state has no motion by design | the seat has not cleanly finished a turn |
| **A7** | `attention` — a raised hand and a marker above the desk, 4 fps loop | desk | `render_state` | `render_state == "blocked"` | when it is not | a static raised-hand pose and the marker | no `attention.request` is open ([D2 § 4.4](FLEET-STATE.md#44-activity-states-every-entry-and-exit-edge)) |
| **A8** | `stalled` — head in hands, static, with the `api_error_type` line | desk | `render_state`, `api_error_type` | `render_state == "stalled"` | when it is not | identical | no `turn.end(api_error)` is standing |
| **A9** | `unknown` — a question marker over an occupied desk | desk | `render_state`, `unknown_reason` | `render_state == "unknown"` | when it is not | identical | the seat's last turn record supports a positive reading |
| **A10** | `intern-arrive` / `intern-leave` — a stool at the side table fills or empties | side table | `subagents` | a delta whose `changed[]` contains `subagents` and whose array gains or loses a `call_id` | on arrival / at the door | the stool is simply occupied or empty | the subagent set did not change |
| **A11** | `badge-raise` — a badge appears with a single 250 ms fade | desk | `badges` | a delta whose `changed[]` contains `badges` and whose array gains a member | after one tick | the badge is simply present | no new badge |
| **A12** | `gauge` — the context bar eases to its new value over 250 ms | desk, drill-down | `context.used_pct` | a delta whose `changed[]` contains `context` | at the new value | the bar jumps to the value | no new sample; **the bar never drifts between samples** |
| **A13** | `retire` — the desk clears, the chair pushes in, the plate is stamped | desk | `render_state`, `seat.retired` | `render_state == "retired"`, or the `seat.retired` message | when the plate is set | the desk is simply cleared and stamped | the seat is not retired |
| **A14** | `feed-pulse` — a one-frame pulse on the feed indicator | status strip | `feed.heartbeat` | each `feed.heartbeat` message received | after one frame | a *last message HH:MM:SS* readout that updates instead | **no message has arrived** — which at 45 s is the feed-down condition itself ([§ 9](#9-failure-paths-and-their-observables)) |
| **A15** | `catching-up` — a replay marker sweeps the monitor, 4 fps loop | desk | `render_state`, `delivery.oldest_unsent_age_s` | `render_state == "catching_up"` | when it is not | a static replay marker and the *replaying* label | the seat's spool is not draining |
| **A16** | `desk-move` — a displaced character walks to its new desk | floor | the rendered seat set | a seat entering the set displaces an incumbent ([§ 3.3](#33-collision-displacement-and-why-a-desk-move-is-itself-an-event)) | on arrival | the desk appears in its new slot on the next render | no arrival collided |

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
delivered**, with no edge-triggered animation. Their arrival is not a claim that anything happened to
any seat — it is a claim about what the client knows. Animating them would put an arrival at every desk
on every reconnect, and a fleet that appeared to walk back in every time the network hiccupped would
have made the floor's motion meaningless in exactly one afternoon.
[AT-D3-9](#at-d3-9-the-client-half-of-snapshot-then-deltas) asserts it.

---

## 7. Degradation — how a degraded seat is unmistakable

### 7.1 The render per state

`render_state` has **ten** members ([D2 § 4.2](FLEET-STATE.md#42-render-precedence)) and every one has a
distinct render. The order below is the fixed order the lobby's per-floor summary uses.

| `render_state` | Desk | Label line | Animation | Never |
|---|---|---|---|---|
| `working` | character at the keyboard | the action's descriptor | A3, or A4 when the turn is open with no call | rendered without its currency treatment when the seat is not `live` |
| `idle` | chair turned, monitor dimmed, character present | *finished — quiet for 4m 12s* | A6 (none) | rendered as absent. Idle is a **positive observation**, not a silence ([D2 § 4.4](FLEET-STATE.md#44-activity-states-every-entry-and-exit-edge)) |
| `blocked` | raised hand, marker above the desk | *waiting on a human since 14:31 (seat clock)* | A7 | shown as working, whatever `open_calls` says ([D2 § 4.3](FLEET-STATE.md#43-the-derivation-function): `blocked` outranks `working`) |
| `stalled` | head in hands | *API error — rate limit* | A8 | folded into `unknown`; `api_error_type` is always on the line |
| `unknown` | character present, question marker | one sentence per `unknown_reason` (below) | A9 | rendered as `idle`, and never as seven different desks |
| `catching_up` | character present, replay marker, desaturated | *replaying history — last event 3h 12m ago (seat clock)* | A15 | rendered as current work. This is [AT-D2-20](FLEET-STATE.md#at-d2-20-catching-up-is-not-current-and-not-stale)'s rule at the pixel layer |
| `stale` | **empty chair**, desk dimmed | *no data since 14:18* — the seat has been silent past 300 s | none | rendered as `idle`, ever ([D2](FLEET-STATE.md#42-render-precedence) `D2-MUST` #2) |
| `offline` | empty chair, desk dark | *no data since 13:52* — silent past 900 s | none (A2 played on the way in) | removed from the floor |
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
| `index_overflow` | D1 | badge cluster | *the seat passed its open-call or open-session index cap* |
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
that flusher's life. The panel renders each with `badges_since`, the counter's value, and
`reporter.uptime_s` — a sticky badge drawn as a current condition would make a seat that had one bad
minute look permanently broken. D2's own seven are current conditions and are drawn as such.

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

**`config_invalid` is this document's own addition to D2's four, and it is named as one.** D2 lists
`catching_up`, `stale`, `offline` and `fold_lag`; `config_invalid` is a D1 badge meaning the reporter
"keeps spooling and sends nothing" ([D1 § 9.3](EVENT-SCHEMA.md#93-degradation-counters)), so everything
on that desk is by construction older than the badge — the same reading the other four get, arriving
through the reporter instead of through the transport or the fold. It is an addition on top of a D2
rule, so [§ 1.3](#13-the-boundary-stated-as-a-rule) obliges the sentence that adds it; it needs no
amendment from D2, because it constrains only what this document renders.

### 7.4 The frozen fold is the one that could look healthy

[D2 § 2.3](FLEET-STATE.md#23-a-frozen-fold-is-the-dangerous-degradation) names this as the single
degradation that can present as a healthy floor: receipts keep arriving, so the ages look fine, while
every desk shows what it was doing when derivation stopped. Three renders, and they are obligations D2
places on this document by name:

1. **Per seat**, past 60 s of `derivation.fold_lag_ms`: the `fold_lag` badge, the hatched overlay, the
   line *this state is N s behind*, and **motion stops** — "D3 must not present the seat's activity
   state as current".
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
| the full list | the seat-detail response's uncapped open-call list ([D2 § 8.2.3](FLEET-STATE.md#823-the-seat-detail-response)) | the drill-down's intern list is **not** `subagents[]` and is not capped at 8 |
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
| F1 | **Feed silent** | no message of any kind for **45 s** ([D2 § 8.3](FLEET-STATE.md#83-the-websocket-delta-feed): 3 heartbeat intervals) | the status strip reads **feed down — polling**, [A14](#62-the-animation-table--the-closed-set)'s pulse has stopped, every desk keeps its last state and **every age keeps growing** | poll `GET /api/fleet/snapshot` every **10 s** ([D2 § 2.2](FLEET-STATE.md#22-fail-posture-per-path)) and attempt reconnect | claiming *live*. A dashboard that silently degrades from live to polled is one whose age nobody can trust |
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
there is no raster or vector image file under the character tree at all. This is the mechanised form of
*"the upstream's commercial tilesets are never vendored"* — a denylist of file hashes could only refuse
the copies someone thought to enumerate, while an empty tree refuses every copy, including the one
nobody anticipated.

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
renderer **must** record, for every animation it starts, a row of
`(animation_id, install_id, seat_id, cause)`, where `cause` is the id of the wire message that caused it
— a `seat.delta`'s `state_version`, a `feed.heartbeat`, a `seat.retired`, or the seat-set change of
[A16](#62-the-animation-table--the-closed-set). **An animation started with no cause writes `null`**,
which is what makes [AT-D3-1](#at-d3-1-no-animation-without-its-event) able to fail. A renderer that
cannot produce this log cannot be shown to obey the honesty principle, and the principle is the
product's headline claim.

**Fixtures.** Nine, shared by the tests below:

| Fixture | Contents |
|---|---|
| `fx-snapshot-4` | [D2 § 8.2.2](FLEET-STATE.md#822-worked-snapshot)'s snapshot, extended to the four `aimla` seats of [§ 3.2](#32-the-desk-slot-function)'s worked assignment |
| `fx-clear-trace` | `fx-snapshot-4`, then the **ten** deltas of [D2 § 10](FLEET-STATE.md#10-worked-example-the-clear-trace-folded-end-to-end)'s trace applied to `aimla-pm`, in order, in **both** hook orders D2 runs |
| `fx-degraded` | one seat per non-`live` render: `catching_up` (with `oldest_unsent_age_s` = 4,000), `stale`, `offline`, `disabled`, `retired`, plus a `live` seat badged `fold_lag` with `derivation.fold_lag_ms` = 117,000 |
| `fx-interns` | one seat whose `subagents` goes 0 → 8 → 8-with-`subagents_open`-9, including one element with `title: null` |
| `fx-collision` | `fx-snapshot-4`, then a delta for `aimla-impl-4` ([§ 3.3](#33-collision-displacement-and-why-a-desk-move-is-itself-an-event)) |
| `fx-membership` | a delta for a seat absent from `fx-snapshot-4`; and a later snapshot missing a seat that was present |
| `fx-gap` | `fx-snapshot-4`, then three deltas for one seat with the middle one dropped |
| `fx-refusals` | the four responses of [D2 § 8.6](FLEET-STATE.md#86-a-deliberately-invalid-exchange) and [§ 2.2](#22-connect-snapshot-deltas): `503 fleet_unavailable`, `401 token_revoked`, a `fleet.health` with `db: "down"`, and a `fleet.reload` |
| `fx-nulls` | one seat with **every** nullable member of [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object) null — no `task`, no `context`, no `action`, no `session`, `model_label` null, `enabled` null, `subagents` empty |

### AT-D3-1 no animation without its event

*The honesty principle, mechanised. This is the headline test and the gate on trusting the floor at
all.*

- **Build:** replay `fx-snapshot-4`, `fx-clear-trace`, `fx-degraded` and `fx-interns` end to end;
  collect the animation log.
- **GREEN:** **every** row of the log has a non-null `cause`; every `animation_id` in the log is a row
  of [§ 6.2](#62-the-animation-table--the-closed-set); and for each row the driving field named by that
  table appears in the causing delta's `changed[]` (or the row's driver is a message type, and the
  cause is a message of that type).
- **RED:** add an ambient idle-breathing loop to the character sprite — the single most natural thing to
  add to a pixel-art office — and re-run. The log gains rows with `cause: null` and the test fails.
  **Watch that once**: it is the whole difference between a floor that reports state and a floor that
  performs it.
- **Second RED:** drive the working loop's frame rate from `open_calls` — a "busier seats type faster"
  change that looks like a feature — and assert that the loop's frame interval is constant across every
  seat and every fixture. A rate that varies is a quantity the wire never sent.
- **Discriminating control:** a fixture with **no** deltas at all (`fx-snapshot-4` alone, then silence)
  → the log is empty. Without this control, a log-writing bug that recorded nothing would pass the
  GREEN.

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
  render `idle` and does log A6, so the test measures the trace and not the absence of an idle render.

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
  overlay, the *117 s behind* line, and **no motion** — assert the animation log has no loop row for
  that seat.
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
  age readouts have continued to grow throughout** — assert the rendered age strings, not the internal
  timestamps.
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
- **GREEN:** the client's final seat map equals the server fixture's exactly; the below-watermark delta
  is **discarded** and the above-watermark one **applied**; the snapshot render fires **no** animation
  (assert the animation log is empty across the snapshot apply); running the scenario 100 times yields
  100 identical results.
- **RED — order:** fetch the snapshot before subscribing → the delta made in the window is in neither,
  and on a quiet desk the divergence is permanent.
- **Second RED — no watermark:** apply every buffered delta → construct the visible case D2 names, a
  patch that clears `action` followed by a snapshot that already has it cleared and then a newer delta
  that sets it.
- **Third RED — the animating snapshot:** fire edge animations on the snapshot apply → every desk on the
  floor plays an arrival on every reconnect ([§ 6.5](#65-a-snapshot-never-animates)).

### AT-D3-10 ages come from the server clock

- **Build:** `fx-snapshot-4`, with the harness's browser clock set **+3 h** from the fixture's
  `server_time`.
- **GREEN:** every rendered age matches the age computed from `server_time` — the receipt age reads
  seconds, not three hours; `clock_offset_ms` is applied to every readout; and every seat-clock
  timestamp (`action.started_at`, `context.sampled_at`, `activity.last_event_time`,
  `session.started_at`) renders as a labelled seat-clock claim and **not** as an age.
- **RED:** compute ages from `Date.now()` → every desk reads *no data for 3h* on a fleet that is
  reporting normally, and the operator is sent to look at a healthy fleet.
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
  **Third RED — the vendored character:** drop any `.png` into the character tree → Gate 2 fails.
  **Fourth RED — the wrong licence:** set a row's identifier to `CC-BY-NC-4.0` → the allowlist check
  fails.
- **Discriminating control:** the clean tree passes all four, so the gates are known to be capable of
  reporting *provenance is complete*.

### AT-D3-13 every state is legible without motion

- **Build:** render `fx-snapshot-4`, `fx-degraded` and a seat in each remaining `render_state` member
  under `prefers-reduced-motion: reduce`; capture a static image of each desk.
- **GREEN:** all **ten** `render_state` members are pairwise distinguishable from the static images
  alone, and each carries its label line; every animation row's reduced-motion form is what appears;
  the animation log is empty.
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
  stools; and a `null` `counters` object on the health view reads **unreadable** rather than a column of
  zeros.
- **RED:** coalesce nulls to zero anywhere on the page → a seat that has never reported a context sample
  renders a full, empty gauge reading 0 %, which is a measurement the wire never made. This is
  `docs/KANBAN.md § G-1`'s clean zero with a progress bar around it.
- **Discriminating control:** a seat with `context.used_pct: 0.0` — a real zero — **does** render a bar
  at 0 %, so the test distinguishes a measured zero from an absent measurement.

### AT-D3-15 the lobby never invents a count

- **Build:** `fx-snapshot-4`, then drop one seat from the client's map without a snapshot (simulating a
  missed insert).
- **GREEN:** the fleet counts render `fleet.seats_total` / `fleet.seats_live` verbatim; the per-floor
  summary is labelled as a count of held seats; when the two disagree the lobby renders *the client
  holds 3 of 4 seats — refreshing* and issues one snapshot fetch, after which they agree.
- **RED:** compute the fleet counts by counting desks → the lobby confidently reports 3 seats on a
  4-seat fleet, and the missing desk is invisible precisely because the count agrees with the floor.
- **Discriminating control:** the intact fixture renders no discrepancy notice and issues no fetch.

### AT-D3-16 retirement is rendered, and the removal is explained

- **Build:** `fx-degraded`'s retired seat; then deliver `seat.retired` for a live seat; then a later
  snapshot that omits the retired seat entirely.
- **GREEN:** on the message, the desk clears in place with the plate, the reason, the operator and the
  time, and the drill-down still shows what the seat was doing and what its transport did afterwards;
  the desk is **still present** in the snapshot for 14 days; when a snapshot finally omits it, the desk
  is removed **and the lobby log carries a line naming the seat and the reason**.
- **RED — the vanishing desk:** remove the desk when `render_state` becomes `retired` → a desk that
  existed a second ago is simply gone, with no rendered state that says why
  ([D2 § 4.10](FLEET-STATE.md#410-retirement-is-a-rendered-state)).
- **Second RED — the removal on a delta:** remove a desk on any signal other than a snapshot apply →
  a missed delta or a resync can delete a live seat from the floor.

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
| Lobby event-log length | **200 lines** | **Chosen** — enough to hold a reconnect storm's worth of membership and resync lines; it is a narration of the client, not a record, and D2's own surfaces hold the durable history | [§ 4.1](#41-the-lobby--the-building-summary) |

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
| **G3 cap arithmetic** | 6,112 / 8,192 / 263 / 2,080 / 7 / 15 / 7,953 / 8,216 / 24 re-computed from the two inputs, and the three inputs checked against their statements in D2 | **tool-checked** |
| **G4 § 12 ↔ definition site** | each row's number as a whole numeric token at the section it cites, then **perturbed** to prove the match can fail for that row; the residue — numbers some other value would also have matched — printed individually | **tool-checked**, with its residue printed |
| **G5 acceptance-test closure** | every fixture named in a test against the fixture table, both directions; every test having a **RED**; the AT ids contiguous from 1 with no gaps or duplicates | **tool-checked** |
| **G6 Appendix A** | its stated counts against its row counts, and every D2 section carrying the literal `D3` against the sections Appendix A cites from a D2-attributed position | **tool-checked**, with a stated limit: an obligation D2 addresses to "a consumer" without the marker is not grep-derivable, so the tool reports the marker population and names the manual remainder |
| **G7 state and badge render closure** | D2's `render_state`, `link_state`, `activity_state` and `unknown_reason` members and the 18 badges, **re-derived from D2** and set-differenced against this document's render tables in both directions — a member with no render, and a render for a member that does not exist | **tool-checked** |
| **G8 desk-slot worked example** | the four hashes, their moduli and the assignment, re-computed from [§ 3.2](#32-the-desk-slot-function)'s stated function; and the collision example of [§ 3.3](#33-collision-displacement-and-why-a-desk-move-is-itself-an-event) | **tool-checked** |
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
| 1 | **The client derives no state; the six things it computes are enumerated as a closed list** ([§ 2.1](#21-the-six-client-computed-values-closed)) | let the client compute what it needs and rely on review to catch the rest | A closed list is checkable against a candidate computation; "only presentation" is not. D2 already refuses a re-derived `render_state` for the same reason — a second copy of a precedence is free to drift, and the first thing it drifts on is `stale`-vs-`idle` | a genuinely-presentational computation someone wants is a review conversation instead of a commit. That is the cost, and it is the point |
| 2 | **The animation table is closed, and an animation without a row is a defect** ([§ 6.2](#62-the-animation-table--the-closed-set)) | state the honesty principle as a principle and trust it | A principle nobody can fail is a principle nobody keeps. A closed table plus the animation log makes the rule a test ([AT-D3-1](#at-d3-1-no-animation-without-its-event)) rather than an intention | every new effect costs a table row and a driving field. A flourish with no field is exactly what is being refused |
| 3 | **No ambient life at all** — no breathing, blinking, NPCs or moving scenery | permit decorative motion that carries no state | Motion is the floor's vocabulary. A viewer cannot tell decorative motion from state-bearing motion at a glance, which is the range this screen is read at, so decoration would spend the vocabulary on nothing | a still floor looks still. That is accepted: a still floor **is** a still fleet, which is the reading we want |
| 4 | **A state-held loop is permitted, at a fixed rate that encodes nothing** | fire an animation only on edges, never hold one | A `working` desk must look different from an `idle` one at a glance and across a room, and a pose alone is weaker at distance than a pose that moves. The rate is pinned to the coalescing tick so the loop cannot claim more than the feed can carry | a loop is running while the underlying claim is bounded only by D2's ceilings. That is why every loop stops the moment its state's currency is in doubt ([§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim)) |
| 5 | **A snapshot, poll, resync, insert or reconnect never animates** ([§ 6.5](#65-a-snapshot-never-animates)) | animate the difference between the old and new object | The difference between two client states is not a fact about a seat. Animating it would play an arrival at every desk on every reconnect and make the floor's motion mean "the network hiccupped" | a state change that arrives via a snapshot rather than a delta is not announced. It is rendered — just not narrated — and the drill-down and the log carry the detail |
| 6 | **The desk slot is a pure hash function of `(install_id, seat_id)`** ([§ 3.2](#32-the-desk-slot-function)) | sorted order; arrival order; a server-assigned slot | Sorted order shifts the whole floor when a seat is provisioned; arrival order is not a function of the rendered set, so two browsers disagree; a server slot is a field this document may not mint. The hash gives every client the same answer with no stored state at all | an arrival can displace an incumbent on a collision — bounded to the chain, rendered as a move, and with its frequency stated as `N/S` ([§ 3.3](#33-collision-displacement-and-why-a-desk-move-is-itself-an-event)) |
| 7 | **A desk move is an event and is animated as one** | re-lay the floor silently on the next render | The rule that governs everything else on this floor is that nothing moves without a cause. A silent re-layout would be the one exception, on the one occasion an operator is most likely to think they misread the screen | one more animation row, and one more thing that can be got wrong |
| 8 | **An unknown seat in a delta is FETCHED, never patched** ([§ 2.3](#23-membership-a-seat-or-an-install-the-client-does-not-hold)) | apply the patch as an insert; or ignore the delta until the next snapshot | A patch is a shallow merge over an object the client may not hold, so the insert would be a seat object with holes, and a hole renders as *nothing is happening*. Ignoring it leaves a live seat invisible until a reconnect | one HTTP request per newly-seen seat, ever. [§ 14](#14-open-questions-for-the-review-loop) item 2 is the membership message that would remove even that |
| 9 | **Install membership is snapshot-only, and its age is rendered** | poll the snapshot on a timer to discover installs | A discovery poll invents a cadence D2 does not state and fetches the whole fleet to answer a question that changes when an operator provisions an install. Rendering *membership as of HH:MM:SS* makes the staleness visible for one line of text and no requests | a new install is invisible until a reconnect or a manual refresh, and the readout is what stops that being a surprise |
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

---

## 14. Open questions for the review loop

Each names what it blocks, what this document does in the meantime, and what would close it. Items 1, 2,
5, 9 and 10 are **D2 amendment needs**: this document does not edit D2
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
   **In the meantime:** the timeline renders only [D1 § 4.3](EVENT-SCHEMA.md#43-common-per-event-fields)'s
   common per-event fields, which every event carries and D2 stores verbatim, and the detail-dependent
   panel sections render *unavailable* when the response does not carry them
   ([§ 5.2](#52-the-drill-down), [§ 9](#9-failure-paths-and-their-observables) F11). **Closes it:** two
   field tables in D2 § 8.2, in the shape § 8.2.1 already uses.

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

9. **⇢ D2 — no compaction fact reaches a consumer.**
   D2 stores `sessions.compaction_open_since`, gives it a 15-minute ceiling and a counter, and states
   that a compacting seat's quiet 40 seconds is answered "in the drill-down"
   ([D2 § 4.8](FLEET-STATE.md#48-what-may-never-mint-a-state)) — but the fact appears in neither
   [D2 § 8.2.1](FLEET-STATE.md#821-the-seat-state-object)'s object nor
   [§ 8.2.3](FLEET-STATE.md#823-the-seat-detail-response)'s enumerated detail members, so the drill-down
   has nothing to render it from. **Blocks:** answering *why is this desk quiet right now* for the one
   quiet case D2 says is explicable. **In the meantime:** no compaction render on the floor or the
   panel; the timeline shows `compaction.start` / `compaction.end` as events like any other, which is
   the after-the-fact reading rather than the current one. **Closes it:** a `compacting` boolean or a
   `compaction_open_since` on the seat object, or naming compaction among the detail members.

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

---

## Appendix A — every obligation addressed to this document

[D2](FLEET-STATE.md) addresses this document in **twenty-eight** places — a `D3` mention, a "renders"
that names an obligation rather than a pixel, a rule only the render layer can keep. [D1](EVENT-SCHEMA.md)
addresses it in **eight** more, directly or through its "constraining D2/D3" clause. All of them are
enumerated here, because an obligation a downstream document did not notice is indistinguishable from
one it declined. The two counts above and the two tables' row counts are checked against each other by
`tools/design/verify-floor.py`, so a row added to a table and not to a sentence reds the gate.

**The population has two halves and only one is machine-derivable**, exactly as D2 found for its own:
the obligations whose D2 section carries the literal marker `D3` are re-derivable by grep, and the rest
— a "renders" clause, a field whose whole purpose is a rendering rule — were found by reading D2. The
tool checks the marker half's coverage and prints the size of the remainder rather than reporting a
clean over it.

### The obligations D2 places on this document

| # | D2 source | Obligation | Discharged in |
|---|---|---|---|
| T1 | § 1.2 | Everything rendered is D3's — desks, floors, sprites, animation, the identity→desk mapping; where D1 or D2 says "renders" it is naming an obligation, not a pixel | [§ 1.1](#11-what-this-document-owns), [§ 3](#3-identity-seat--desk-install--floor), [§ 5](#5-the-render-map--every-rendered-fact-and-its-d2-field), [§ 6](#6-the-honesty-principle--every-animation-and-its-driving-event) |
| T2 | § 2.3 | A seat badged `fold_lag`: **D3 must not present the seat's activity state as current** | [§ 7.3](#73-currency-labels-what-a-non-live-desk-may-claim), [§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy), [AT-D3-5](#at-d3-5-a-degraded-seat-is-visibly-degraded) |
| T3 | § 2.3 | `fleet.fold = "stalled"`: **D3 shows a fleet banner** | [§ 7.4](#74-the-frozen-fold-is-the-one-that-could-look-healthy), [§ 5.3](#53-the-fleet-on-both-screens) |
| T4 | § 4.1 | D3 renders `render_state` and may use the components for the drill-down; it **never re-derives the collapse** | [§ 2.1](#21-the-six-client-computed-values-closed), [§ 5.1](#51-the-desk), [§ 7.1](#71-the-render-per-state), [AT-D3-5](#at-d3-5-a-degraded-seat-is-visibly-degraded) |
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

**Nothing addressed to this document is undischarged.** Five obligations are discharged with a stated
gap in the upstream contract rather than by a rendering alone, and each is filed in
[§ 14](#14-open-questions-for-the-review-loop) rather than absorbed silently: T6's timeline has no field
table (item 1); T28's `detail` has none either (item 1, the same class); the membership case T24's
"never vanishes" implies has no message (item 2); T23's MFA gate has no rule for an expiring session on
an open socket (item 5); and D2 § 4.8's compaction explanation has no field to render (item 9).

---

## Appendix B — what an implementer builds from this

In dependency order, with the gate each must pass before the next is trusted. Cards #7340, #7341 and
#7342 (`docs/PLAN.md § 3`) are the whole of it; card #7339 (the fleet-state store, feed and REST
snapshot, from D2) is a prerequisite for everything from step 3 onward.

| Order | Artifact | Gate |
|---|---|---|
| 0 | `ATTRIBUTION.md`, the asset manifest, and both provenance gates | **[AT-D3-12](#at-d3-12-asset-provenance-gates-bite)** RED on each of its four planted defects, then GREEN — first, because an asset added before the gate exists is an asset nobody will go back and license |
| 1 | the character generator port + `resources/characters/LINEAGE.md` (card #7340) | renders in a plain browser from the seat key alone; the lineage file is complete; Gate 2 holds |
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

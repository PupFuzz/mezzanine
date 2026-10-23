# D5 — the coordination store: receipt binding, store, backfill, and the build plan

**Status: DESIGN, awaiting the operator's approval (card#12239). Nothing in this document is built.**
A new mechanism's design goes to the operator before any code (`USER-GATING.md § 2g`); the questions
that gate the build are in [§ 10](#10-operator-questions).

> **What this document is, and what it is not.** D1 § 18 owns the coordination-event **producer** — the
> receipt route's authentication, validation order and the derivation of `coord.thread` / `coord.round`
> from a GitHub delivery. D2 § 8.3.3 owns the two objects' **read surface** on the feed, and D3 § 5.7 owns
> their **render**. All three were ratified and all three left one slice to *"the slice that designs the
> coordination store"* ([D1 § 18.13](EVENT-SCHEMA.md#1813-what-this-section-does-not-establish) rows 8–9,
> [D2 § 14](FLEET-STATE.md#14-open-questions-for-the-review-loop) item 14, [D2 § 13](FLEET-STATE.md#13-decisions-taken-revisable-at-review)
> row 40). **This is that slice.** It owns what no ratified document owns — the hook **binding** and its
> provisioning, the fleet-specific findings, the failure-mode table, the implementation plan, the
> sandbox acceptance and the operator questions — and it **amends** D1, D2 and D3 in place wherever the
> design changes a claim they publish ([§ 12](#12-the-amendment-ledger) lists every edit and its reason).
> The store's own rules live in **[D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)**, because D2
> owns the store; this document points at them and does not restate them.

---

## 0. Overview

1. **The client is finished and the server is empty.** `server/public/js/coord/coord-model.js`,
   `server/public/js/wire/fleet-client.js` (`#holdCoord`, `COORD_CAP`) and
   `server/public/js/floor/floor-screen.js` (`#coordinate`, A18–A20) consume `coord.thread` /
   `coord.round` envelopes; nothing on the server produces one. `grep -rln 'coord\.' server/app
   server/routes server/database` returns only `server/app/Feed/FeedMessage.php` (a docblock) and the two
   migrations that mention the words in an ENUM and a comment — read 2026-10-23 on `dev` @ `fa3ce80f`.
2. **The producer is Mezzanine's own GitHub webhook receipt, per D1 § 18.1** — not the bridge, on
   `docs/PLAN.md` D-10. GitHub delivers every hook registered on a repository; the roundtable already
   has four consumers registered this way ([§ 2.2](#22-the-coordination-repository-population-on-this-fleet)).
3. **One hook binding per `(install_id, repository)`, selected by a path segment, verified by its own
   secret**, written synchronously in the receipt request into five tables, with the feed envelopes
   enqueued as the last statement before `COMMIT` ([D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)).
4. **A backfill on connect**: `GET /api/fleet/coord` serves the client exactly what a client connected
   since forever would hold under D3's 1,000-envelope cap, so a page that has just loaded draws the
   open threads instead of nothing ([D2 § 8.2](FLEET-STATE.md#82-rest), [D3 § 2.2](FLOOR.md#22-connect-snapshot-deltas)).
5. ⛔ **On this fleet the per-install population of coordination repositories is EMPTY** — both
   installs are solo seats, and the only coordination traffic is the cross-install roundtable, which the
   card sequence puts on card#12240. Built exactly as ratified, this slice animates nothing on the
   sandbox by itself; the binding is designed so that card#12240 adds one binding **kind** rather than a
   second route. That is [§ 10](#10-operator-questions) Q1, and it is the first thing the operator should read.

---

## 1. The problem, in the product's terms

The operator's request (card#12239, 2026-10-23): *when one agent posts to another, an envelope travels
from the sender's desk to the receiver's, a thread line joins them while the thread is open, and a
message to everyone sends a ring out from the sender.* The floor can draw all three — D3 § 6.2 rows
A18, A19, A20 are built and tested (`server/tests/Feature/Coordination/CoordRendersTheThreadLineTest.php`,
`TheCoordinationLineResolvesOrRendersUnresolvedTest.php`) — and the server has never sent it one message
to draw.

**Evidence, read on `dev` @ `fa3ce80f` (2026-10-23):**

| Claim | How it was read |
|---|---|
| No route receives a GitHub delivery | `server/routes/ingest.php` declares `/api/ingest/events` and `/api/ingest/health` and nothing else; `grep -rn github server/routes server/app` → 0 hits outside docblocks |
| No table holds a thread or a post | `ls server/database/migrations` — no migration names a coordination table; `SHOW TABLES` on the sandbox store (read-only, 2026-10-23) lists none |
| The feed can carry the objects but nothing writes them | `feed_outbox.t` ENUM carries `coord.thread`, `coord.round` (`2026_09_13_000100_create_feed_outbox_table.php`); on the sandbox `SELECT t, COUNT(*) FROM feed_outbox GROUP BY t` returns `seat.delta` and `feed.heartbeat` only |
| The client holds and renders them | `fleet-client.js:1393–1410` dispatches both types to `#holdCoord`; `floor-screen.js:1411` draws per room |

---

## 2. The population this design must cover — and what it found

### 2.1 Installs and seats on the sandbox

Read from the sandbox store on 2026-10-23 (`SELECT … FROM seats JOIN installs … LEFT JOIN seat_state`,
read-only, credentials never printed):

| install_id | seat_id | `protocol_agent_name` | check | role |
|---|---|---|---|---|
| `kanban` | `kanban-solo` | `kanban` | `checked` | `solo` |
| `mezzanine` | `mezzanine-solo` | `mezzanine` | `checked` | `solo` |

Both seats **declare** a name and both declarations are `checked` — card#9296's server half and
card#9375's reporter half are live on this fleet (this box's reporter config declares
`protocol_agent_name: "mezzanine"`; the flusher's crontab line passes `COORD_CONFIG`). So D3 § 5.7's
resolve arm is **reachable** here, for any coordination object that names `kanban` or `mezzanine` with
the matching `install_id`.

### 2.2 The coordination-repository population on this fleet

**Zero per-install coordination repositories.** `~/.config/coord/coordination.config.json` on this box
has `coordination_repo: null` and a one-member roster (`mezzanine`, role `solo`); the `kanban` install is
kanban-solo's and is the same shape (its seat declares role `solo`). A solo install has no coordination
repo by the framework's own definition (this repository's `CLAUDE.md` § Explicitly absent). D1 § 18's
binding — one hook per `(install_id, coordination repository)` — therefore has **nothing to bind on this
fleet today.**

**One cross-install coordination surface: `PupFuzz/agent-roundtable`.** Read via the roundtable
credential this seat holds (`~/.config/coord/credentials.ini` → `github.roundtable_file`; the token
passed to `gh` by environment, never printed), 2026-10-23 — the default `gh` identity on this box
answers `404` for that repository:

```
repository                : PupFuzz/agent-roundtable, private; this identity has admin
issues                    : 641 total, 95 open; 8 opened in the last 7 days
comments, last 7 days     : 700
busiest threads           : #283 (2,010 comments, open), #599 (948, open), #8 (114, closed)
from:/to: label vocabulary: from:{aimla-pm, kanban-solo, mezzanine-solo, sola-pm}
                            to:{aimla-pm, all, kanban-solo, mezzanine-solo, sola-pm}
body form                 : every sampled issue and comment opens with `FROM: <name>` / `TO: <name>`
title form                : `[PREFIX] from:x, to:y — subject` on every sampled issue
hooks registered          : 4 — solapm.uroshape.com (issues, issue_comment, push), the sandbox bridge,
                            bridge-prod.sandboxkanban, aimlapm.aimla.org (each issues, issue_comment)
```

Three consequences, each load-bearing:

1. **The roundtable is the between-rooms case.** Its participants are four installs' PM/solo seats;
   a thread between `mezzanine-solo` and `kanban-solo` spans two rooms, and D3 § 5.7 clause 3 draws a
   round *"in the ROOM its own `install_id` names and in no other"* — a line between rooms *"needs an
   object D2 does not publish"*. That object is card#12240's. This card's binding carries an
   `install_id`; binding the roundtable to one install would draw every roundtable thread inside one room
   with three of four participants unresolved — honest per clause 1, and misleading in placement.
2. **The roundtable identities are not the declared names.** The seat declares `mezzanine`; it posts
   as `mezzanine-solo` (`cross_project.identity`). D2 § 8.3.3 rule 3 forbids any fallback join, so even
   under card#12240 a roundtable participant `mezzanine-solo` resolves to **nothing** on a seat declaring
   `mezzanine`. Named here because it decides whether the operator's stated want ever draws a line on this
   fleet ([§ 10](#10-operator-questions) Q3).
3. **A single thread exceeds the client's cap.** D3 § 12 sized the 1,000-envelope hold against *"a
   thread with 100+ posts … observed on this fleet's own roundtable"*; the roundtable now holds one with
   2,010 and one with 948. D3's *N+* rule already covers it on the client; the backfill must serve the
   **same** truncation ([D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)).

**What a real delivery contains — observed, not vendored.** With admin on the roundtable, the sandbox
bridge's hook (`id 676600858`) exposes its recent deliveries through GitHub's deliveries API. Read
2026-10-23, keys and sizes only:

```
last 40 deliveries (one page): issue_comment.created 16 · issues.labeled 11 · issues.unlabeled 13
all 40 answered 200; redelivery flag false on all 40
headers: Content-Type, User-Agent, X-GitHub-Delivery, X-GitHub-Event, X-GitHub-Hook-ID,
         X-GitHub-Hook-Installation-Target-ID, X-GitHub-Hook-Installation-Target-Type,
         X-Hub-Signature, X-Hub-Signature-256
issue_comment.created payload: 13,821 B; top-level {action, comment, issue, repository, sender}
  issue   carries number, title, body, state, state_reason, closed_at, created_at, updated_at,
          labels[] (each with name), user — none missing
  comment carries id, body, created_at, updated_at, user, issue_url — none missing
issues.labeled payload: 11,925 B; same issue keys present
```

This closes D1 § 18.13 rows 1 and 2 from DOCS-CITED to **observed on a live hook** — short of a
vendored fixture, which is [§ 10](#10-operator-questions) Q2 — and it establishes one fact the ratified
design did not have: **every `issue_comment` delivery carries the whole `issue` object**, state, labels,
body and timestamps included. [D2 § 6.12](FLEET-STATE.md#612-the-coordination-store) builds on it twice.
⚠ No `issues.opened` or `issues.closed` delivery was in the window read (none occurred in those 40); their
`issue` object is the same documented object (GitHub REST *Get an issue* schema, read 2026-10-23), so
the claim for them is DOCS-CITED, and the capture of one is still owed.

### 2.3 GitHub's side of the hop, read at the vendor's documentation

Read 2026-10-23 at docs.github.com (DOCS-CITED in D1 § 18.2's sense); each closes or bounds a D1 § 18.13
row:

| Fact | Source sentence | Consequence here |
|---|---|---|
| **No automatic retry** | *"GitHub does not automatically redeliver failed deliveries."* | a refused or failed delivery is lost unless an operator redelivers it; the receipt's write must be cheap and must not refuse what it can store |
| **10 s response timeout** | *"if your server is down or takes longer than 10 seconds to respond, GitHub will record the delivery as a failure"* | the receipt transaction's wall-clock budget is 10 s minus transport; it takes no seat lock and performs three small writes |
| **3-day redelivery window** | *"you can redeliver webhook deliveries from the past 3 days"*; *"All deliveries from the past 3 days will be listed."* | the digest store's retention must exceed 3 days — it is **14 days** ([D2 § 6.7](FLEET-STATE.md#67-retention-and-purge)); and a thread opened more than 3 days before the hook existed cannot be redelivered into existence, which is why the store derives a thread from the first comment it sees ([D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)) |
| **25 MB payload cap** | *"If an event generates a larger payload, GitHub will not deliver a payload for that webhook event."* | D1 § 18.8's 1 MiB body cap is ~25× below the sender's; the largest body measured on a live coordination repo is 31,087 B (D1 § 18.13 row 4) and a whole observed delivery is ~14 KB, so the 1 MiB cap is a guard, not a path |
| **Hook creation** | the REST reference states admin for deletion; creation is exercised on this fleet by an admin credential | registering Mezzanine's hook is an operator act on another repository ([§ 10](#10-operator-questions) Q1) |

---

## 3. Where posts come from, and how they reach Mezzanine

### 3.1 The binding, and why it is selected by a path segment

D1 § 18.8 publishes `POST /api/ingest/github` and binds `install_id` to *"the secret the delivery
verified against — one secret per registered hook"*. With **more than one** hook registered, the request
carries nothing inside the HMAC that says which secret to try: `X-GitHub-Hook-ID` is outside the
signature, and `repository.full_name` is inside the body, which D1's validation order parses at step 5,
two steps after the signature is verified. The three ways to pick a secret:

| Shape | Mechanism | Cost |
|---|---|---|
| **try every active secret** | HMAC the body once per binding | CPU linear in bindings on an unauthenticated 1 MiB body — the exact budget D1 § 18.8's step-3 limit exists to bound |
| **look the secret up by `repository.full_name`** | parse the body before verifying it | reorders D1's validation (parse before auth) and routes a key lookup on an unverified claim; D1 § 3.3's rule says the claim is *validated for equality, never used to route* |
| **a path segment names the binding** — `POST /api/ingest/github/{hook}` | `{hook}` is a public, random identifier issued with the binding; it selects the row whose secret is tried, and the secret decides | one URL per binding, which GitHub's hook config already holds; no parse before auth; an unknown `{hook}` is a step-3 refusal |

**Chosen: the path segment.** `{hook}` is `mzh_` + 22 base64url characters (128 bits from a CSPRNG), a
greppable prefix in the family of `mzn_` / `mzr_`, **not a secret** — it appears in GitHub's hook
settings page and in the access log — and nothing authenticates on it: an unknown `{hook}` is `401`
inside D1 § 18.8 step 3 (no secret to verify against ⇒ the signature cannot verify), counted
`coord_signature_invalid`, in the same per-`(route, source IP)` bucket. This is D1 § 18.8's amendment
A1 ([§ 12](#12-the-amendment-ledger)).

### 3.2 Provisioning — one operator act, three things issued

`php artisan mezzanine:coord-hook:issue <install_id> <repository> --roster=<a,b,c> --shared-identity=<true|false> --by=<who>`

creates, in one transaction: the binding row (`coord_hooks`: install, repository, the install-facts
input D1 § 18.3.1 declares — `roster[]`, `shared_identity`, and the revision/date the operator copied
them at), one secret row (`coord_hook_secrets`, ciphertext at rest under the application key — an HMAC
key must be recoverable, so it cannot be hashed like a bearer token; the bridge keeps the same kind of
secret in a 0600 file, and this repository's store-with-encryption is chosen so the secret has the same
backup, rotation and audit shape as every other row), and prints **once**: the URL to register
(`https://<APP_URL>/api/ingest/github/mzh_…`), the secret, and the subscription to tick (`issues`,
`issue_comment`; content type `application/json`). Mirrors `mezzanine:ingest-token:issue` in every
discipline (`App\Console\SecretLine`; plaintext printed once, stored nowhere).

`--hook=<mzh_…>` on the same command adds a **second** secret to an existing binding (rotation with
overlap, the shape D1 § 3.3 gives tokens); `mezzanine:coord-hook:revoke <mzh_…> [--secret=<prefix>]`
revokes a secret or the binding. Verification tries every unrevoked secret of the one binding, which is
at most the two a rotation holds. **Mezzanine holds no GitHub credential for any of this** — the hook is
registered by the operator on GitHub's side, which keeps D-10 (*"Mezzanine is an observer and stands
alone"*) true of this producer too.

### 3.3 What crosses which boundary (canon #7)

| Hop | Guarantee | DECLARED by | CHECKED by | NAMED as unverifiable |
|---|---|---|---|---|
| GitHub → Mezzanine, the delivery | body bytes are the ones signed under the binding's secret; `X-GitHub-Event` names the event | GitHub's documented delivery contract ([§ 2.3](#23-githubs-side-of-the-hop-read-at-the-vendors-documentation)) | D1 § 18.8 steps 3–6, run by this route | delivery **ordering** (GitHub documents none; the store orders by `issue.updated_at` and names arrival order as the fallback, [D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)); whether a **redelivered** payload is byte-identical to the original (the digest absorbs it only if it is; a differing re-serialization re-derives, and is then absorbed by `post_ref` / `thread_ref` identity at the client) |
| coordination repo config → the binding's install-facts copy | `roster[]`, `shared_identity` | the operator, at `coord-hook:issue`, with the revision copied | `coord_roster_unknown_name` on the first post by an unknown name (D1 § 18.3.1's guard) | a removal from the upstream roster (one-directional guard, D1 § 18.3.1) |
| seat declaration → coordination name | the name a seat declares equals the name it posts under | the seat (D1 § 3.1) | the seat's own roster check (`protocol_agent_name_check`) | **that the posting identity on a given repository is the declared name at all** — false today on the roundtable ([§ 2.2](#22-the-coordination-repository-population-on-this-fleet) consequence 2); no end can check it, because the roundtable identity is a `cross_project` field the reporter does not read |
| Mezzanine → the browser | the objects, the backfill, the feed | D2 § 8.3.3, § 8.2 | the client's dedupe by identity; AT-D2-26 | nothing new: one deployment, no skew axis (D2 § 8.1) |

---

## 4. Storage, write path and feed — the shape

The rules are [D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)'s; the DDL is
[D2 § 6.4](FLEET-STATE.md#64-ddl)'s; this is the shape, stated once so a reader has the picture before
the rules:

- **Five tables**: `coord_hooks` (the binding), `coord_hook_secrets`, `coord_deliveries` (the signed-body
  digest — the idempotency key — plus the delivery header as an operator label), `coord_threads` (one row
  per thread, **current state**), `coord_posts` (one row per post).
- **No fold daemon and no `events` row.** A delivery is not a seat event: D1 § 18.9 enumerates the nine
  batch invariants it fails, `events.seat_ref` is `NOT NULL` and its dedup key is per seat. The receipt
  request derives, stores and enqueues in **one** `App\Feed\Outbox::transaction()`, which is D2 § 6.5's
  per-writer rule applied to a writer that is a request: the digest, the thread upsert, the post insert
  and the feed rows commit together or not at all — D1 § 18.8 step 9's *"steps 7 and 9 commit together"*
  made structural by the primitive the fold already uses.
- **The wire object is per delivery; the stored thread is current state.** `coord.thread` on the feed
  carries that delivery's `lifecycle` verbatim (D1 § 18.6); `coord_threads.lifecycle` is moved by the
  delivery with the greatest `issue.updated_at` and not by arrival order, so a `reopened` that arrives
  after its `closed` cannot leave a thread *"rendered open forever"* (D1 § 18.13 row 2(b)'s named hazard).
- **A thread the store has never seen is derived from the first comment that names it**, and so is its
  opening post — from the `issue` object every `issue_comment` delivery carries ([§ 2.2](#22-the-coordination-repository-population-on-this-fleet)).
  Measured reason: 95 threads are open on the roundtable today, and GitHub redelivers 3 days back; a
  producer that derived threads from `issues.opened` alone would draw none of them until each closed.
- **A comment whose `issue.state` contradicts the held lifecycle reconciles it** and emits a
  `coord.thread`, counted — the lost-`closed` repair that needs no credential.
- **Retention**: `coord_deliveries` 14 days (the digest store; chain: GitHub's 3-day redelivery window
  < 14 days); open threads and their posts never; a closed thread and its posts 14 days after the close
  was received; all in `App\Sweep\Purge`'s pass with a retention index each.
- **Feed**: `App\Feed\CoordThread` and `App\Feed\CoordRound` implement `FeedMessage` with
  `installId()` = the binding's install; D2 § 8.3's table is unchanged in shape and gains one trigger
  clause.
- **Counters**: D1 § 18.8.1's eleven live in `global_counters` and are enrolled in D1 § 12.7 / D2 § 7.1
  / D2 § 8.2.4 (`GET /api/fleet/health` `counters`); D2 § 7.2 gains four of the store's own.

---

## 5. Backfill on connect

`GET /api/fleet/coord` (session + MFA only; browser-only like the timeline, so no consumer upgrades
independently of the server and D1 § 18.7's enum-classification trigger — *publication across a deploy
boundary* — does not fire) returns the envelopes a client connected since forever would hold under D3
§ 12's cap: whole threads, newest-received first, up to **1,000** envelopes, and for one thread that alone
exceeds the cap its `coord.thread` plus its newest rounds with the thread named in `truncated_threads[]`
— the selection rule is stated once at [D2 § 6.12](FLEET-STATE.md#612-the-coordination-store), and the
client's own eviction (`fleet-client.js` `#holdCoord`) is the same rule from the other end. The client's
protocol gains a buffer, a fetch and a drain rule ([D3 § 2.2](FLOOR.md#22-connect-snapshot-deltas)
steps 2c, 3c and 5c; [D2 § 8.4](FLEET-STATE.md#84-snapshot-then-deltas)): open the stream, buffer
coordination envelopes, fetch the backfill, apply it **without animation** (D3 § 6.5 — a backfill is a
snapshot, journalled `backfilled`), then drain the buffer discarding a round whose `post_ref` is held
and a thread whose `received_at` is not later than the held one's. D2 § 14 item 14 closes.

---

## 6. Attribution and the agent-name → desk join

Nothing changes, and that is the design: every name on both objects stays a protocol agent name, the
objects gain no desk reference, the consumer resolves against the seat population by D2 § 8.3.3's three
rules in that order, and D3 § 5.7 clause 1 renders what does not resolve as unresolved. The
**producer** writes `from`, `opened_by`, `to`, `targets`, `participants` exactly as D1 § 18.6 / § 18.7
derive them, with `targets` expanded against the binding's copied `roster[]` and against nothing else.
The one new fact is the finding in [§ 2.2](#22-the-coordination-repository-population-on-this-fleet)
consequence 2: on the only coordination surface this fleet has, the posting identity differs from the
declared name, so the join as ruled resolves nothing there. That is reported, not patched: a producer
that mapped `mezzanine-solo` → `mezzanine` would be the guessed join card#7957 was filed to prevent.

---

## 7. Failure modes — what the floor shows, and what recovers it

| Failure | What the floor shows | Detectable? | Recovery |
|---|---|---|---|
| **a `coord.round` delivery lost** (GitHub timeout, outage, refusal) | one bead fewer; the thread's bead count is a lower count of a real population and nothing says so — D2 § 8.3.3: *"a lost coordination message is not detectable"* | not from the wire. GitHub records the failure in its delivery list | operator Redeliver within 3 days; the digest is absent, so the redelivery derives |
| **`issues.opened` lost** | no line until the thread's first comment, which derives the thread and its opening post from the embedded `issue` ([D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)) | `coord_thread_from_post` rises | the next post; or Redeliver |
| **`issues.closed` lost** | the line stays drawn until the thread's next delivery of any kind: a comment carrying `issue.state: closed` reconciles it | `coord_thread_reconciled` rises | the next post; or Redeliver; a thread that goes silent after a lost close stays drawn **open** — the named residual |
| **`issues.reopened` lost** | the ended treatment until the next comment (state `open` on a held `closed` ⇒ `reopened`) | as above | as above |
| **duplicate delivery** (GitHub retry, Redeliver inside 14 days) | nothing — absorbed at the digest, `coord_delivery_duplicate` | yes | — |
| **Redeliver after 14 days** | the delivery re-derives; the client draws nothing new (identity dedupe by `post_ref`; the thread upsert is LWW on `issue.updated_at`) — bounded now by GitHub's 3-day window, so this path is reachable only by a payload held outside GitHub | `coord_delivery_received` without a client-visible change | none needed |
| **out-of-order lifecycle** (`reopened` arrives before `closed`) | the store keeps the newer `issue.updated_at`; the feed carries both deliveries in arrival order and the client's last-held `coord.thread` is the **arrival**-last one — ⚠ a connected client can hold the stale lifecycle until the backfill on its next connect; the store is right | `coord_thread_stale_lifecycle` | the client's next connect; named rather than closed — the fix (a `state_at` the client compares) is one field on D2 § 8.3.3 and is deferred to review ([§ 11](#11-what-this-design-does-not-establish)) |
| **a comment for a thread first seen via that comment, then the original `issues.opened` arrives late** | the late `opened` carries an older `issue.updated_at` ⇒ it does not move the stored lifecycle; its `coord.thread{opened}` is still emitted (per delivery) and the client applies it as a newer arrival — same residual as the row above | as above | as above |
| **a repository the install does not own** | `403` at step 6, nothing derived; `coord_signature_invalid` for an unknown `{hook}` | yes | an operator reads the hook's delivery list |
| **a secret leaked** | forged threads on one install's floor until revoked | `coord_delivery_received.<install_id>` spikes; forged content is attributable to the binding | `coord-hook:issue --hook` + `revoke` (overlap rotation) |
| **label churn** (~60 % of observed deliveries are `labeled`/`unlabeled`) | nothing — ignored at step 8, no digest, counted `coord_delivery_ignored.<event_type>` | yes | — |
| **the store down during a delivery** | `503 server_error`, nothing committed, no digest; GitHub records a failure | yes — and `fleet.health.db` | Redeliver within 3 days |
| **the binding's roster drifts** | a new seat's broadcasts omit it from `targets` until re-provisioned | `coord_roster_unknown_name` on its first post | `coord-hook:issue --hook` with the new roster |
| **a thread with more posts than the cap** (#283: 2,010) | its newest rounds, bead count *N+* | `truncated_threads[]` on the backfill; `coordTruncated` on the client | by design |

**States deliberately not defended against:** a GitHub-side ordering guarantee (none is documented;
the design orders by the payload's own `updated_at`); a body over 1 MiB (25× the measured maximum and
`413` is loud); concurrent identical deliveries (the `UNIQUE` digest makes the loser a duplicate);
convergence (not derivable — D1 § 18.5 finding B, unchanged).

---

## 8. Rejected alternatives, with the mechanism each turns on

| Alternative | Mechanism | Why not |
|---|---|---|
| **ride `events` and the fold** | insert a synthetic event with a nullable `seat_ref` and a minted `seq`; let `mezzanine:fold` project it | widens the primitive every seat event depends on (D2 § 6.5's seat lock, D2-MUST #3's per-seat dedup, `ix_fold`) to admit a producer that satisfies none of D1 § 18.9's nine invariants — one weaker path for both callers |
| **a fold step: receipt writes raw deliveries, a daemon derives** | a `coord_deliveries.payload` column and a cursor | a second daemon (supervision row, lag badge, frozen-fold class) for a derivation that is one pure function over one JSON object; D2 § 2.3's *frozen fold* hazard imported for nothing. The raw body is also the one thing D1 § 18.10 says must never be stored or logged |
| **a `threads[]` member on `GET /api/fleet/snapshot`** | serve the backfill inside the snapshot | the snapshot is the watchdog's machine interface (`mzr_` token) and is sized at ~91 KB; 1,000 envelopes at ~400 B is 400 KB on every 5-minute poll; and a REST consumer upgrading on its own schedule is exactly the publication that fires D1 § 18.7's enum obligation |
| **a GitHub REST backfill at hook issue** | read open threads through the API with a repo credential | Mezzanine would hold a credential for a repository it only observes (D-10), and the default `gh` identity on this box cannot even see the roundtable (`404`) — the credential would be a new provisioning surface. The first-comment derivation reaches the same threads with no credential |
| **a `converged` flag now that `issue.state_reason` is observed** | `state_reason: completed` ⇒ converged | `completed` is GitHub's default close reason, set by any close; the protocol's convergence is a quorum (D1 § 18.5 finding B) and `state_reason` carries nothing about it |
| **bind the roundtable to one install now** | one binding, `install_id: mezzanine` | draws every roundtable thread in one room with three of four participants unresolved; card#12240's object is the honest answer ([§ 10](#10-operator-questions) Q1) |

---

## 9. Implementation plan

Dependency order. Every test is seen RED once before it is trusted; the plant is named with it. D1 § 16
rows 8–10 and D2 Appendix B rows 12–13 are the ratified build rows this plan discharges.

| # | Artifact | Gate (RED plant → GREEN) |
|---|---|---|
| 0 | **Migration**: `coord_hooks`, `coord_hook_secrets`, `coord_deliveries`, `coord_threads`, `coord_posts` per D2 § 6.4; `App\Sweep\Purge::PLAN` gains the three retention rows, and its guard gains the digest's floor (3 days) | the purge's existing index-order test (D2 § 6.7: *"its test derives each key from `information_schema.STATISTICS` and reds when a declaration stops matching it"*) reds on a declared order the new indexes do not carry; AT-D2-17 extended with the digest chain, seen red by purging at 2 days |
| 1 | **Provisioning**: `mezzanine:coord-hook:issue` / `revoke`, `App\Coord\HookBinding`, `App\Coord\HookSecret` (encrypt/decrypt, overlap) | a command test: the plaintext secret appears in stdout once and in no column; `--hook` adds a second secret and verification accepts either until revoke; a revoked secret is `401` |
| 2 | **Receipt route** `routes/coord.php` (empty middleware list, like `routes/ingest.php`; `CoordRouteIsolationTest` reads the router), `App\Coord\ReceiptPipeline` in D1 § 18.8's order with the `{hook}` selector, the per-`(route, IP)` failed-auth bucket — `App\Ingest\RateLimiter::hitFailedAuth()` today takes the IP alone, so it gains a route key rather than a sibling (D1 § 12.3: *each route gets a bucket of its own*) | **AT-23**, all three REDs — flipped body, flipped signature, replay under a fresh header against a header-keyed receiver, wrong repository; plus the unknown-`{hook}` `401` and the 61st bad signature from one IP → `429` with 60 from distinct IPs as the control |
| 3 | **Derivation** `App\Coord\Derive` — pure functions over `(issue, comment, labels, binding)`: `thread()`, `openingPost()`, `commentPost()`, body `FROM:`/`TO:` lines, the membership union, `carrier`, `[CLOSE]` anchored, `targets` expansion, `App\Support\Slug::AGENT_NAME` validation, the `coord.subject` redaction profile (D1 § 7.3) | **AT-24** (close names nobody — plant the `from:` label fallback), **AT-25** (labels vs union — plant body-only and label-only), **AT-26**'s per-post half (plant the phrase in place of the token); the redaction fixtures 14–17 of D1 § 7.5 |
| 4 | **Store write** `App\Coord\Receipt` — one `Outbox::transaction()`: digest, thread upsert (LWW on `state_at`), post insert, reconcile, enqueue `CoordThread` / `CoordRound` | **AT-D2-27** atomicity (plant: commit the digest before the derivation and make the derivation throw → the Redeliver is absorbed); **AT-D2-28** LWW (plant: arrival order → a late `reopened` leaves `closed`); **AT-D2-29** first-seen-via-comment (plant: skip the embedded `issue` → no thread row, no opening post) |
| 5 | **Counters**: the eleven in `global_counters`; `App\Read\FleetHealth::COUNTERS` gains them (families enumerated from `coord_hooks` and from the subscribed event set); D2 § 7.2's four | `FleetHealthCountersMatchTheDocumentTest` (extend the existing document-match pattern) reds when a § 7.1/§ 7.2 fleet-health counter is missing from the constant; `verify-fleet-state.py` G8 is the doc-side half |
| 6 | **Backfill** `GET /api/fleet/coord`, `App\Read\CoordBackfill` with the selection rule | **AT-D2-26**: the backfill equals the set a client that applied every envelope since the store's first row would hold under `COORD_CAP` (drive both through the same fixture; plant: newest-first by `posted_at` instead of `received_at`, and the over-cap thread truncated oldest-first) |
| 7 | **Client**: `fleet-client.js` step 3c + the drain rule; `floor-screen.js` journals backfilled envelopes with outcome `backfilled` so A19/A20 do not fire on them | **AT-D3-29** through `coord-probe.mjs` and the fleet-client harness: a backfilled round animates nothing (plant: journal it `applied`); a buffered duplicate after the backfill draws nothing new; a buffered older `coord.thread` does not regress the held lifecycle |
| 8 | **Doc-sync in the same PR**: README § operating (hook issue runbook), CHANGELOG bullet, `tools/design/README.md` D5 row, the verifier runs | the four verifiers + `verify-design-docs.selftest.py` green at the head |

**Acceptance on the sandbox** (the sandbox is this seat's to operate; nothing below touches `main` or
production):

1. Deploy the build to the sandbox; `php artisan migrate` adds the five tables; `GET /api/fleet/health`
   carries every new counter at `0` (seen: `counters` keys diffed against D2 § 8.2.4's list).
2. Issue a binding for install `mezzanine` against a repository decided by Q1; register the printed URL
   and secret on that repository (**operator act**); GitHub's *ping* is answered `202` and counted
   `coord_delivery_ignored.ping`.
3. **Replay**: `tests/roundtrip/coord-roundtrip.py` signs the delivery fixtures under the sandbox
   binding's secret and posts them to the sandbox route; then `bin/feed-stream-check.sh` shows
   `coord.thread` / `coord.round` on the stream, `GET /api/fleet/coord` returns them, and the UAT
   screenshot tooling (`~/.config/coord/uat`, `login.mjs` / `shot.mjs`) captures the floor with the
   thread line between `mezzanine-solo`'s desk and a second declaring seat — which on the sandbox means
   the fixture's participants are the two declared names, `mezzanine` and `kanban`, on **one** install;
   that is a replay, and it is said to be one.
4. **Live**: the first real post on the bound repository animates on the sandbox floor without a page
   reload; a page loaded afterwards shows the thread through the backfill; `coord_delivery_received`
   moves on `/api/fleet/health`.
5. Redeliver one delivery from GitHub's list: `coord_delivery_duplicate` moves, the floor draws nothing new.

What acceptance step 4 can show on this fleet depends entirely on Q1; without a bound repository it is
step 3 alone, and this document says so rather than letting a replay stand in for the product.

**`Built:` for the build PR** is recorded on the card at each dispatch, per `built-line.md`.

---

## 10. Operator questions

Each carries context, an example per option, and a recommendation.

**Q1 — Which repository does the first binding point at, given that no install on this fleet has a
coordination repository?** Context: [§ 2.2](#22-the-coordination-repository-population-on-this-fleet).
The ratified design binds one hook per `(install_id, coordination repository)`; the sandbox's two
installs are solo seats with none, and the only coordination traffic is the roundtable, whose threads
span rooms (card#12240).
- *(a) Build the producer as ratified; accept it on the sandbox by signed replay; bind nothing live
  until card#12240 lands or a multi-agent install (one with a coordination repo) reports in.* Example:
  the plan's acceptance step 3 runs against the sandbox; step 4 waits. The floor animates nothing live
  until then. Cost: the operator's stated want is not visible on this fleet after this card alone.
- *(b) Bind the roundtable to the `mezzanine` install now, as a stopgap.* Example: a `[QUERY]` from
  `sola-pm` to `mezzanine-solo` draws in the mezzanine room with `sola-pm` unresolved and
  `mezzanine-solo` unresolved too (Q3), so **no line is drawn and no envelope travels** — it would show
  beads and labels only, in the wrong room. Cost: a misleading placement the roundtable card then has
  to unwind.
- *(c) Fold card#12240's between-rooms object into this card.* Example: a binding with scope `fleet`,
  `install_id` nullable on both objects, D3 § 5.7 clause 3 and § 4.6's between-rooms line designed and
  built together. Cost: a second design round across all three documents before anything is built.
- **Recommendation: (a)**, with the binding row carrying a `scope` column fixed to `install` now so
  that card#12240 adds the value `fleet` and no second route — and with one real `issues.opened` /
  `issues.closed` delivery **captured** on the way (Q2), because the build's fixtures otherwise rest on
  documentation for those two actions.

**Q2 — May real roundtable deliveries be vendored as test fixtures?** Context: D1 § 18.13 row 1's
closing act is *"capture one real delivery … and vendor it as a fixture"*; the deliveries API exposes
them read-only (this identity has admin); the roundtable is a **private** repository and
`PupFuzz/mezzanine` is **public** (`gh api repos/PupFuzz/mezzanine --jq .private` → `false`). Vendoring
moves the fleet's own coordination prose into a public repository. `USER-GATING.md § 2h` makes this
the operator's call, by checkable reference.
- *(a) Vendor two deliveries verbatim* (one `issue_comment.created`, one `issues.opened`). Example: the
  AT-23 fixture is the real body and the real headers less the signature. Cost: fleet prose published.
- *(b) Vendor the **shape**: the real key structure with every free-text value replaced by synthetic
  protocol-conformant text, labelled as shape-captured / text-synthetic.* Example: the fixture carries
  the observed 5 top-level keys, every `issue` and `comment` key, real label objects, and a body reading
  `FROM: pm\nTO: all\n\n[ANNOUNCE] synthetic`. Cost: D1 § 18.2's MEASURED is a vendored capture; this is a
  weaker basis and is marked as such.
- **Recommendation: (b)**, recorded on the card as the operator's authorization for the specific
  fixture files, with the deliveries' GitHub ids noted so the capture is re-derivable by anyone with the
  credential.

**Q3 — Should a seat's declared name be the identity it posts under on the roundtable?** Context:
[§ 2.2](#22-the-coordination-repository-population-on-this-fleet) consequence 2 — `mezzanine` declared,
`mezzanine-solo` posting; D2 § 8.3.3 rule 3 forbids mapping one to the other. This decides whether
card#12240 can ever draw a roundtable line to this seat's desk; it is asked now because it is a
provisioning convention and *"the first provisioning act is where the convention gets set"* (card#9296).
- *(a) Declare the roundtable identity as a second optional member — `protocol_agent_name` stays the
  roster name, a new `cross_project_identity` rides the heartbeat and joins roundtable-bound objects.*
  Example: this seat declares both `mezzanine` and `mezzanine-solo`; a roundtable `FROM: mezzanine-solo`
  resolves. Cost: a D1 § 3.1 / § 6.14 / D2 § 8.2.1 amendment and a reporter release (card#12240's scope).
- *(b) Rule that the roster name and the roundtable identity are one name, and align the config.*
  Example: `cross_project.identity` becomes `mezzanine`; every roundtable label and `FROM:` line changes
  for this seat. Cost: a fleet-wide convention change on a shared repository — fleet agreement, not this
  seat's call.
- **Recommendation: (a)**, decided on card#12240; this card builds nothing for it and needs nothing
  from it.

**Q4 — Hook secret at rest: encrypted in the store under `APP_KEY` (this design) or a 0600 file as the
bridge does?** Context: [§ 3.2](#32-provisioning--one-operator-act-three-things-issued). Example of the
cost either way: a store backup carries the ciphertext and `APP_KEY` is in `.env`; a file is outside the
store's backup and rotation shape. **Recommendation: the store**, for one shape across every credential
this application holds; a reviewer may overturn it cheaply before step 1 of the plan.

---

## 11. What this design does not establish

| Not established | Cost if wrong | Closing act |
|---|---|---|
| **that a connected client's held lifecycle equals the store's after an out-of-order pair** — the feed carries per-delivery objects in arrival order ([§ 7](#7-failure-modes--what-the-floor-shows-and-what-recovers-it)) | a line drawn open or ended wrongly until the next connect | add `state_at` (the payload's `updated_at`) to `coord_thread` on D2 § 8.3.3 and let the client keep the newer — one field, deferred to the design review because it changes a published object |
| **the `issues.opened` / `issues.closed` payload shape by capture** — DOCS-CITED only; the comment delivery is observed | the derivation reads `null` on a key the documentation names and the delivery omits | Q2's capture |
| **that any multi-agent install will ever report to this Mezzanine** — the per-install binding's whole population is empty today | the producer serves the roundtable case only, via card#12240 | an operator decision outside this card |
| **what a redelivered payload is, byte for byte** — GitHub's documentation is silent | a redelivery outside the digest's absorption derives twice; absorbed at the client by identity | observe one Redeliver on the sandbox (acceptance step 5) |
| **the `coord_delivery_received` family's member set on `GET /api/fleet/health`** is the set of bindings at request time — a revoked binding's member stays (monotonic counters are never removed) | a stale member reads `0`-moving forever | stated, not closed: D2 § 7.2's never-reset rule applies |

---

## 12. The amendment ledger

Every edit this slice makes to a ratified document, with the claim it changes and why. Each is a diff
to the owning document; none is a parallel restatement.

| Doc · section | What changed | Why |
|---|---|---|
| D1 § 18.8 (A1) | the route is `POST /api/ingest/github/{hook}`; an unknown `{hook}` is a step-3 `401` | [§ 3.1](#31-the-binding-and-why-it-is-selected-by-a-path-segment): N bindings and no selector inside the signature |
| D1 § 18.8 step 7 (A2) | *"this document mints no retention number"* now points at D2 § 6.7's 14 days | the store exists; D1 § 18.13 row 9's named decision is taken |
| D1 § 18.6 / § 18.7 (A3) | the triggers gain the first `issue_comment.created` on an unheld thread (thread + opening post from the embedded `issue`) and a comment whose `issue.state` contradicts the held lifecycle | observed: every comment delivery carries the whole `issue`; measured: 95 open threads predate any hook |
| D1 § 12.7 (A4) | the eleven coordination counters are enrolled by pointer rows; the closing paragraph is replaced | the plane that stores them now exists; D1 § 18.13 row 8's closing act |
| D1 § 18.13 (A5) | rows 1–5, 8, 9 updated with the observed delivery, GitHub's documented facts, and the two closures | the rows' own closing acts, performed |
| D1 § 18.2 (A6) | the bridge checkout row notes the re-read at v0.106.0 | SOURCE-READ's axis is the revision; the bridge moved 29 releases |
| D1 § 15 (A7) | decision 55: the binding selector | a decision taken, recorded where decisions live |
| D2 § 2.1 / § 2.2 (B1) | the **coordination receipt** process row and its fail posture | add a process, add a row |
| D2 § 6.4 (B2) | five tables | the store |
| D2 § 6.7 (B3) | three retention rows and the digest chain | retention stated where retention lives |
| D2 § 6.12 (B4) | **new** — the store's rules | the owning document gains the section the slice owes |
| D2 § 7.1 / § 7.2 / § 8.2.4 (B5) | eleven rows, four rows, the fleet-health list, the two counts | counter closure (verifier G8) |
| D2 § 8.2 (B6) | `GET /api/fleet/coord`; the *no fifth endpoint* paragraph reversed | [§ 5](#5-backfill-on-connect); row 40's reason expired |
| D2 § 8.3 / § 8.3.3 / § 8.4 (B7) | the `coord.thread` trigger cell; the *no snapshot member* paragraph; the snapshot-then-deltas asymmetry paragraph | the backfill exists |
| D2 § 12 / § 13 / § 14 / AT / App. B (B8) | numbers, decisions 52–55, item 14 closed, AT-D2-26…29, build rows 12–13 | bookkeeping the design owes |
| D3 § 2.2 / § 5.7 / § 6.5 / § 14 / AT (C1) | step 3c and the drain rule; the *no-snapshot* consequence paragraph; a backfill is a snapshot; item 40; AT-D3-29 | the client half of the backfill |
| `tools/design/README.md` | D5 declared under no gate, as D4 is | a document under no gate says so |

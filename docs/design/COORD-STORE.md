# D5 — the coordination store: receipt binding, store, backfill, the between-rooms render, and the build plan

**Status: DESIGN, revised to the operator's answers of 2026-10-23 (card#12239); the build awaits the
operator's go on the questions in [§ 10](#10-operator-questions). Nothing in this document is built.**

> **What this document is, and what it is not.** D1 § 18 owns the coordination-event **producer** — the
> receipt route's authentication, validation order and the derivation of `coord.thread` / `coord.round`
> from a GitHub delivery. D2 § 8.3.3 owns the two objects' **read surface** on the feed, and D3 § 5.7 owns
> their **render**. All three were ratified and all three left one slice to *"the slice that designs the
> coordination store"* ([D1 § 18.13](EVENT-SCHEMA.md#1813-what-this-section-does-not-establish) rows 8–9,
> [D2 § 14](FLEET-STATE.md#14-open-questions-for-the-review-loop) item 14, [D2 § 13](FLEET-STATE.md#13-decisions-taken-revisable-at-review)
> row 40). **This is that slice**, and — by the operator's answer to Q1 — it also folds in card#12240's
> between-rooms object and render, and — by the answer to Q2 — the seat's roundtable-name declaration.
> It owns what no ratified document owns: the hook **binding** and its provisioning, the fleet-specific
> findings, the synthetic-fixture guard, the failure-mode table, the implementation plan, the sandbox
> acceptance and the operator questions. It **amends** D1, D2 and D3 in place wherever the design
> changes a claim they publish ([§ 12](#12-the-amendment-ledger) lists every edit and its reason). The
> store's own rules live in **[D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)**, because D2 owns
> the store; this document points at them and does not restate them.

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
3. **One hook binding per repository, with a `scope`**: `install` — a coordination repository that
   belongs to one install (a PM and its impl agents, one room); or `fleet` — a repository whose threads
   span installs (the roundtable). Both are served by **one** producer, one route, one derivation and
   one store; the scope decides only which `install_id` the objects carry (one, or none) and which
   declaration the floor joins a name through ([§ 3](#3-where-posts-come-from-and-how-they-reach-mezzanine),
   [§ 6](#6-attribution-and-the-agent-name--desk-join)). **The first live binding is the roundtable,
   `PupFuzz/agent-roundtable`, at `fleet` scope** (operator, 2026-10-23).
4. **Written synchronously in the receipt request** into five tables, with the feed envelopes enqueued as
   the last statement before `COMMIT` ([D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)).
5. **A backfill on connect**: `GET /api/fleet/coord` serves the client exactly what a client connected
   since forever would hold under D3's 1,000-envelope cap ([§ 5](#5-backfill-on-connect)).
6. **Between rooms**: a fleet-scope thread draws D3 § 4.6's form — the line when both ends are on the
   floor being viewed, a call marker at each end otherwise ([§ 6.1](#61-the-between-rooms-render-card12240-folded-in)).
   The two animation rows it needs join D3 § 6.2 **with the module that draws them**, because the PHP
   suite set-differences that table against the shipped module in both directions.
7. **A seat may declare its roundtable name** beside its protocol agent name — `roundtable_agent_name`,
   checked against the same config's `cross_project.identity` — and a fleet-scope object joins a name
   to a desk through that declaration and nothing else ([§ 3.4](#34-the-roundtable-name-declaration-q2)).
8. **Fixtures are synthetic, and a gate says so**: a CI guard over every coordination fixture, a capture
   tool that cannot write inside a repository, and a synthesizer that copies a capture's key shape and
   none of its values ([§ 3.5](#35-synthetic-fixtures-mechanically-q3)).

---

## 1. The problem, in the product's terms

The operator's request (card#12239, 2026-10-23): *when one agent posts to another, an envelope travels
from the sender's desk to the receiver's, a thread line joins them while the thread is open, and a
message to everyone sends a ring out from the sender.* The floor can draw all three — D3 § 6.2 rows
A18, A19, A20 are built and tested (`server/tests/Feature/Coordination/CoordRendersTheThreadLineTest.php`,
`TheCoordinationLineResolvesOrRendersUnresolvedTest.php`) — and the server has never sent it one message
to draw. The operator's answers of the same day add: the first messages to animate are the roundtable's,
between a PM and a solo agent in different rooms; messages inside one room (a PM and its impl agents on
their own coordination repository) must be served by the same producer, not deferred.

**Evidence, read on `dev` @ `fa3ce80f` (2026-10-23):**

| Claim | How it was read |
|---|---|
| No route receives a GitHub delivery | `server/routes/ingest.php` declares `/api/ingest/events` and `/api/ingest/health` and nothing else; `grep -rn -i github server/routes server/app` → 0 hits outside docblocks |
| No table holds a thread or a post | `ls server/database/migrations` — no migration names a coordination table; `SHOW TABLES` on the sandbox store (read-only, 2026-10-23) lists 31 tables, none coordination-named |
| The feed can carry the objects but nothing writes them | `feed_outbox.t` ENUM carries `coord.thread`, `coord.round` (`2026_09_13_000100_create_feed_outbox_table.php`); on the sandbox `SELECT t, COUNT(*) FROM feed_outbox GROUP BY t` returns `seat.delta` and `feed.heartbeat` only |
| The client holds and renders them | `fleet-client.js:1393–1410` dispatches both types to `#holdCoord`; `floor-screen.js:1411` draws per room |
| The suite holds D3 § 6.2 and the animation module equal | `server/tests/Feature/Floor/ReadsTheAnimationTable.php` walks the table by its header; `TheAnimationSetIsTheDocumentsClosedSetTest.php` set-differences it against `wire/animation-set.js` **in both directions** — a design-only PR cannot add a § 6.2 row |

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
`protocol_agent_name: "mezzanine"`; the flusher's crontab line passes `COORD_CONFIG`). The sandbox's
building layout (`building_layout.document`, read-only) composes **one** floor, *Mezzanine*, holding the
`mezzanine` room alone; the `kanban` room is on no authored floor and takes a floor of its own
(D3 § 4.6). So on the sandbox as laid out today, the two seats are on **different floors** — which is
the call-marker case of [§ 6.1](#61-the-between-rooms-render-card12240-folded-in), and the line case is
one admin-console act away ([§ 10](#10-operator-questions) NQ3).

### 2.2 The coordination-repository population on this fleet

**Zero per-install coordination repositories.** `~/.config/coord/coordination.config.json` on this box
has `coordination_repo: null` and a one-member roster (`mezzanine`, role `solo`); the `kanban` install is
kanban-solo's and is the same shape (its seat declares role `solo`). A solo install has no coordination
repo by the framework's own definition (this repository's `CLAUDE.md` § Explicitly absent). The
`install`-scope binding therefore has **nothing to bind on this fleet today**; it is designed, built and
tested by replay all the same, because the operator's answer is that it must be supported and not
deferred, and because the producer is the same code whichever scope a binding carries.

**One cross-install coordination surface: `PupFuzz/agent-roundtable`.** Read via the roundtable
credential this seat holds (`~/.config/coord/credentials.ini` → `github.roundtable_file`; the token
passed to `gh` by environment, never printed; `xproj-gh` is the same read-only route), 2026-10-23 — the
default `gh` identity on this box answers `404` for that repository. **Counts and shapes only; no
message text, no thread number and no delivery identifier is copied here or anywhere in this repository**
([§ 3.5](#35-synthetic-fixtures-mechanically-q3)):

```
repository                : private; this seat's roundtable identity holds admin
issues                    : 641 total, 95 open; 8 opened in the last 7 days
comments, last 7 days     : 700
busiest open threads      : 2,010 and 948 comments — each alone above the client's 1,000-envelope cap
from:/to: label vocabulary: four identities of the form <install>-pm / <install>-solo, plus to:all
body form                 : every sampled issue and comment opens with `FROM: <name>` / `TO: <name>`
title form                : `[PREFIX] from:x, to:y — subject` on every sampled issue
hooks registered          : 4 — one per fleet bridge, each subscribed to issues + issue_comment
```

Three consequences, each load-bearing:

1. **The roundtable is the between-rooms case, and by the operator's answer it is the FIRST case.**
   Its participants are four installs' PM/solo seats; a thread between this seat and kanban-solo spans
   two rooms. D3 § 5.7 clause 3 draws an install-scope round *"in the ROOM its own `install_id` names and
   in no other"*; a fleet-scope object has **no** room ([§ 3](#3-where-posts-come-from-and-how-they-reach-mezzanine)),
   and D3 § 4.6 already states the form it is drawn in. That form is now built for, not deferred.
2. **The roundtable identities are not the declared names.** The seat declares `mezzanine`; it posts
   as `mezzanine-solo` (`cross_project.identity`). D2 § 8.3.3 rule 3 forbids any fallback join. The
   operator's answer to Q2 is the second declaration ([§ 3.4](#34-the-roundtable-name-declaration-q2)).
3. **A single thread exceeds the client's cap.** D3 § 12 sized the 1,000-envelope hold against *"a
   thread with 100+ posts … observed on this fleet's own roundtable"*; two open threads now exceed the
   cap alone. D3's *N+* rule covers it on the client; the backfill serves the **same** truncation
   ([D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)).

**What a real delivery contains — observed, not vendored.** With admin on the roundtable, the sandbox
bridge's hook exposes its recent deliveries through GitHub's deliveries API. Read 2026-10-23, keys and
sizes only — the capture tool of [§ 3.5](#35-synthetic-fixtures-mechanically-q3) is the only sanctioned
way to read one in future, and it writes outside the repository:

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
vendored fixture, which by the operator's answer to Q3 will never be a real one — and it establishes one
fact the ratified design did not have: **every `issue_comment` delivery carries the whole `issue` object**,
state, labels, body and timestamps included. [D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)
builds on it twice. ⚠ No `issues.opened` or `issues.closed` delivery was in the window read; their
`issue` object is the same documented object (GitHub REST *Get an issue* schema, read 2026-10-23), so
the claim for them is DOCS-CITED, and the first live `opened` on the roundtable binding is where it is
observed.

### 2.3 GitHub's side of the hop, read at the vendor's documentation

Read 2026-10-23 at docs.github.com (DOCS-CITED in D1 § 18.2's sense); each closes or bounds a D1 § 18.13
row:

| Fact | Source sentence | Consequence here |
|---|---|---|
| **No automatic retry** | *"GitHub does not automatically redeliver failed deliveries."* | a refused or failed delivery is lost unless an operator redelivers it; the receipt's write must be cheap and must not refuse what it can store |
| **10 s response timeout** | *"if your server is down or takes longer than 10 seconds to respond, GitHub will record the delivery as a failure"* | the receipt transaction's wall-clock budget is 10 s minus transport; it takes no seat lock and performs three small writes |
| **3-day redelivery window** | *"you can redeliver webhook deliveries from the past 3 days"*; *"All deliveries from the past 3 days will be listed."* | the digest store's retention must exceed 3 days — it is **14 days** ([D2 § 6.7](FLEET-STATE.md#67-retention-and-purge)); and a thread opened more than 3 days before the hook existed cannot be redelivered into existence, which is why the store derives a thread from the first comment it sees ([D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)) |
| **25 MB payload cap** | *"If an event generates a larger payload, GitHub will not deliver a payload for that webhook event."* | D1 § 18.8's 1 MiB body cap is ~25× below the sender's; the largest body measured on a live coordination repo is 31,087 B (D1 § 18.13 row 4) and a whole observed delivery is ~14 KB, so the 1 MiB cap is a guard, not a path |
| **Hook creation** | the REST reference states admin for deletion; creation is exercised on this fleet by an admin credential | registering Mezzanine's hook is an outward act on another repository ([§ 10](#10-operator-questions) NQ1) |

### 2.4 What the operator decided on 2026-10-23, verbatim on the card

| Q | Answer | What it changes in this document |
|---|---|---|
| Q1 | *"(a) roundtable first, but messages between PM and impl agents must be supported too"* | the first live binding is the roundtable at `fleet` scope; card#12240's object and render are folded in ([§ 3.1](#31-the-binding-its-scope-and-why-it-is-selected-by-a-path-segment), [§ 6.1](#61-the-between-rooms-render-card12240-folded-in)); the `install` scope is built and replay-tested in the same slice |
| Q2 | *"yes"* | the optional `roundtable_agent_name` declaration end to end ([§ 3.4](#34-the-roundtable-name-declaration-q2)) |
| Q3 | *"Yes, you must be careful that no real messages enter the repo"* | synthetic fixtures only, with a mechanical guard and a capture tool that writes outside the repo ([§ 3.5](#35-synthetic-fixtures-mechanically-q3)) |
| Q4 | seat ruling, reversible | hook secrets are encrypted at rest under `APP_KEY` ([§ 3.2](#32-provisioning--one-operator-act-three-things-issued)) |

---

## 3. Where posts come from, and how they reach Mezzanine

### 3.1 The binding, its scope, and why it is selected by a path segment

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

**The binding's `scope` — `install` or `fleet` — is the one fact that distinguishes a room's repository
from the fleet's.** It is set at issue, never inferred from a payload, and it decides three things and
nothing else:

| | `scope: install` | `scope: fleet` |
|---|---|---|
| the binding names | one `install_id` and one repository | one repository and **no** install |
| the objects carry | `scope: "install"`, `install_id: "<the binding's>"` | `scope: "fleet"`, `install_id: null` |
| the install-facts input (D1 § 18.3.1) | that install's roster and `shared_identity` | the fleet's **roundtable identity set** — the `from:`/`to:` vocabulary of the repository, copied at issue — and `shared_identity` |
| `targets` for `to: all` | the install's roster less the author | the roundtable identity set less the author |
| the floor joins a name through | `protocol_agent_name`, within the install (D2 § 8.3.3 rule 1) | `roundtable_agent_name`, across every install ([§ 3.4](#34-the-roundtable-name-declaration-q2)) |
| the render | D3 § 5.7, in the one room | D3 § 4.6's between-rooms form ([§ 6.1](#61-the-between-rooms-render-card12240-folded-in)) |

A fleet-scope object's `install_id: null` rides `feed_outbox.install_id NULL`, which D2 § 6.4 already
reserves for *"a fleet-wide message"*; D2 § 9's per-subscriber filter admits everything today, so a
fleet-scope envelope reaches every client as `feed.heartbeat` does. The ACL that § 14 item 7 says will
attach there one day has a question to answer for fleet-scope objects, and it is named rather than
decided here ([§ 11](#11-what-this-design-does-not-establish)).

### 3.2 Provisioning — one operator act, three things issued

`php artisan mezzanine:coord-hook:issue <repository> --scope=install --install=<install_id> --roster=<a,b,c> --shared-identity=<true|false> --by=<who>`
`php artisan mezzanine:coord-hook:issue <repository> --scope=fleet --roster=<identity,…> --shared-identity=<true|false> --by=<who>`

creates, in one transaction: the binding row (`coord_hooks`: scope, install or none, repository, the
install-facts input D1 § 18.3.1 declares — `roster[]`, `shared_identity`, and the revision/date the
operator copied them at), one secret row (`coord_hook_secrets`, ciphertext at rest under the application
key — an HMAC key must be recoverable, so it cannot be hashed like a bearer token; **seat ruling 2026-10-23
on Q4, reversible**: the store rather than a 0600 file, for one shape across every credential this
application holds), and prints **once**: the URL to register (`https://<APP_URL>/api/ingest/github/mzh_…`),
the secret, and the subscription to tick (`issues`, `issue_comment`; content type `application/json`).
Mirrors `mezzanine:ingest-token:issue` in every discipline (`App\Console\SecretLine`; plaintext printed
once, stored nowhere).

`--hook=<mzh_…>` on the same command adds a **second** secret to an existing binding (rotation with
overlap, the shape D1 § 3.3 gives tokens) or replaces its roster copy; `mezzanine:coord-hook:revoke
<mzh_…> [--secret=<prefix>]` revokes a secret or the binding. Verification tries every unrevoked secret
of the one binding, at most the two a rotation holds. **Mezzanine holds no GitHub credential for any of
this** — the hook is registered by the operator on GitHub's side (or by this seat with the operator's
go, NQ1), which keeps D-10 (*"Mezzanine is an observer and stands alone"*) true of this producer too.

### 3.3 What crosses which boundary (canon #7)

| Hop | Guarantee | DECLARED by | CHECKED by | NAMED as unverifiable |
|---|---|---|---|---|
| GitHub → Mezzanine, the delivery | body bytes are the ones signed under the binding's secret; `X-GitHub-Event` names the event | GitHub's documented delivery contract ([§ 2.3](#23-githubs-side-of-the-hop-read-at-the-vendors-documentation)) | D1 § 18.8 steps 3–6, run by this route | delivery **ordering** (GitHub documents none; the store orders by `issue.updated_at` and names arrival order as the fallback, [D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)); whether a **redelivered** payload is byte-identical to the original |
| coordination repo config → the binding's install-facts copy | `roster[]` (an install's roster, or the fleet's roundtable identity set), `shared_identity` | the operator, at `coord-hook:issue`, with the revision copied | `coord_roster_unknown_name` on the first post by an unknown name (D1 § 18.3.1's guard) | a removal from the upstream set (one-directional guard, D1 § 18.3.1) |
| seat declaration → coordination name, per scope | the name a seat declares equals the name it posts under on repositories of that scope | the seat (D1 § 3.1: `protocol_agent_name` for the install's repository, `roundtable_agent_name` for the fleet's) | the seat's own check — against the roster for the first, against `cross_project.identity` of the same config for the second (`protocol_agent_name_check`, `roundtable_agent_name_check`) | that a repository bound at `install` scope is the one the roster describes, and that a repository bound at `fleet` scope is the one `cross_project.repo` names — the binding is written by an operator and the seat's config is another operator act; nothing compares them |
| another seat's declaration (kanban-solo's) → this floor's join | that seat declares its roundtable name and runs a reporter that sends it | that seat's operator | that seat's own check | from here, nothing — the join renders the seat unresolved until its heartbeat carries the name ([§ 10](#10-operator-questions) NQ2) |
| Mezzanine → the browser | the objects, the backfill, the feed | D2 § 8.3.3, § 8.2 | the client's dedupe by identity; AT-D2-26 | nothing new: one deployment, no skew axis (D2 § 8.1) |

### 3.4 The roundtable-name declaration (Q2)

**The gap.** `protocol_agent_name` is the name a seat posts under on its **own install's** coordination
repository, checked against that install's roster. On the roundtable the same seat posts under
`cross_project.identity` — `mezzanine-solo` for `mezzanine` — a different string from a different key of
the same `coordination.config.json`. A fleet-scope object names the second; no declaration carries it;
D2 § 8.3.3 rule 3 forbids guessing the first from the second.

**The declaration, end to end — the same shape as card#9296 / card#9375, one key over, and nothing
guesses a desk:**

| Surface | What changes | Owner |
|---|---|---|
| the seat config (D1 § 3.1) | one optional row, `roundtable_agent_name`, slug ≤ 48 B, the identity this seat posts under on the fleet's cross-install repository | D1 amendment A8 |
| the check (D1 § 3.1) | at flusher start and on `selftest`, against **`cross_project.identity`** of the config `$COORD_CONFIG` resolves (the same resolution order as the roster's): `checked` when equal; `disagreed` when the config carries a `cross_project.identity` and it differs; `unchecked` when no config is readable **or** the config carries no `cross_project` block (the seat is not on a roundtable this box knows of — nothing to compare against); `undeclared` when the key is absent, null or malformed. The four states are `protocol_agent_name_check`'s, produced by the same table applied to a different key | D1 amendment A8 |
| the heartbeat (D1 § 6.14) | `roundtable_agent_name` and `roundtable_agent_name_check`, beside the three existing declaration members; the two counters `roundtable_agent_name_unchecked` / `_disagreed` beside their siblings in § 9.3 | D1 amendment A8 |
| the ingest registry, the fold, the store (D2 § 6.4), the seat object (D2 § 8.2.1), the delta map | two columns, two members, two entries in `SeatDelta`'s map, both version-bearing | D2 amendment B9; build step 5 |
| the join (D2 § 8.3.3 rule 1) | **a second arm, selected by the object's `scope`**: an install-scope name resolves as today; a fleet-scope name resolves to the ONE seat **of any install the client holds** whose `roundtable_agent_name` equals it with check `checked` or `unchecked`, provided no other seat of any install declares it in any state but `undeclared` — the same two unresolved reasons, `no_declaring_seat` and `duplicate_declaration`. A roundtable identity is unique across the fleet by the roundtable's own construction, which is why the duplicate arm spans installs | D2 amendment B9 |
| the render (D3 § 5.7 clause 1) | the resolve arm reads the arm the object's `scope` selects; nothing else changes — unresolved is still first-class, `unchecked` still carries its word | D3 amendment C2 |
| the `selftest` member | `roundtable_agent_name_matches`, the sibling of `protocol_agent_name_in_roster` — ⚠ **added by the build and not by this design**: D1 § 6.14's `selftest` object is a capped object whose serialized bound `tools/design/verify-event-schema.py` re-derives from the member table, and a member added on paper moves a figure the gate holds; it lands with the reporter change, as card#9375 landed its own | build step 5 |

**What it does not do.** It does not join the two names to each other: a seat declaring
`protocol_agent_name: mezzanine` and `roundtable_agent_name: mezzanine-solo` has two declarations,
each resolving objects of its own scope, and a fleet-scope `FROM: mezzanine` (a name written in the
wrong vocabulary) resolves nothing. It is not an input to the token binding, the character seed or
`config_fingerprint`, for D1 § 3.1's four reasons unchanged. Omitting it is a supported state: the seat's
end of every roundtable thread renders unresolved, which is exactly today's render.

### 3.5 Synthetic fixtures, mechanically (Q3)

**The rule.** No real coordination message — body, title, label vocabulary, thread number, comment id,
delivery id, login — enters this repository, in a fixture or in a document. Counts and shapes may. The
operator asked for a mechanism, not a convention; this is the mechanism, in three parts, each with the
plant that proves it fails.

**(1) The guard — `bin/coord-fixture-guard.py`, a CI job on every PR (`.github/workflows/coord-fixture-guard.yml`,
the shape of `asset-provenance.yml`, which is this repository's existing provenance gate).** Its
population is every file under `server/tests/fixtures/coord/`, plus every `.json` a test under
`server/tests/Feature/Coordination/` or `server/tests/Feature/Ingest/` loads from any path (the guard
greps those tests for fixture paths and refuses one outside the population — a fixture read from
elsewhere is the hole a population-scoped guard has, closed from the reader's side). Every fixture must
satisfy all of:

| # | Rule | Why this predicate |
|---|---|---|
| R1 | a top-level `_provenance` object: `{"synthetic": true, "shape_of": "<event>.<action>", "authored_by": "<tool or person>", "shape_source": "<capture id, outside the repo, or 'hand'>"}` | a fixture says what it is; `shape_source` points at a capture file in `~/.cache`, never at content |
| R2 | `repository.full_name` is `synthetic-org/coordination-fixture`, `repository.owner.login` starts with `synthetic-`, and every `*.html_url` / `*.url` host is `example.invalid` | the reserved names cannot collide with a real organisation of this fleet, and `.invalid` is reserved by the DNS standard |
| R3 | `issue.number` ∈ [900000, 999999]; `comment.id` ≥ 9 000 000 000 000; every `*.id` either 0 or ≥ 9 000 000 000 000; `X-GitHub-Delivery` matches `00000000-0000-4000-8000-[0-9a-f]{12}` | real identifiers on this fleet are below 1,000 (threads) and around 6.4 × 10⁹ (comments); the reserved ranges are disjoint by three orders of magnitude, and a real id pasted in fails by range rather than by a list |
| R4 | `user.login` and `sender.login` are `synthetic-user`; `sender.id` is 0 | — |
| R5 | every `issue.body` and `comment.body` is the protocol preamble (`FROM:` / `TO:` lines) followed by lines **each of which contains the token `[synthetic]`**, and the whole body is ≤ 600 B; every `issue.title` matches `^\[[A-Z]+\] from:[^,]+, to:[^—]+ — \[synthetic\] ` | prose that was typed to carry the token is prose somebody wrote for the fixture; a pasted paragraph fails on its first line without the token |
| R6 | the file's bytes match **none** of the deny patterns: `agent-roundtable`, the real organisation's name, the four live roundtable identities and the two live protocol agent names of this fleet (a fixed list in the guard, which is a list of **names**, not of text), `github\.com/`, `c-\d{9,}`, `#\d{2,4}\b` inside a body, any run of 9+ digits outside the fields R3 reserves | the list catches a real identifier that slipped past R2–R5 under a synthetic wrapper |
| R7 | label names are drawn from `from:`/`to:` × `{pm, coder, helper, reviewer, all}` — D3 § 11's own synthetic vocabulary | the fixtures and the floor's fixtures speak one synthetic language |
| R8 | **docs leg**: no file under `docs/` matches `agent-roundtable#\d+`, `/issues/\d+`, `c-\d{9,}`, or a delivery GUID | the operator's rule covers documents too; this document's own figures are counts and shapes and pass it |

The guard's selftest plants, in a temp copy, one violation per rule and requires its own RED, and runs
the correct fixture set and requires GREEN — the pattern every gate in `bin/` and `tools/design/` follows.

**(2) The capture tool — `tools/coord/capture-delivery.py --hook <id> --delivery <id>`.** Reads one
recorded delivery through `xproj-gh api` (read-only), writes it **only** to
`${XDG_CACHE_HOME:-~/.cache}/coord/captures/<repository>/<delivery>.json` with mode 0600, prints the
key paths and byte sizes and **no value**, and refuses any `--out` whose directory answers
`git rev-parse --is-inside-work-tree` — a capture cannot be written into any checkout, this repository's
or another's. The selftest plants an `--out` inside a temp git repository and requires the refusal.

**(3) The synthesizer — `tools/coord/synthesize-fixture.py <capture> --shape-of <event.action> --out server/tests/fixtures/coord/<name>.json`.**
Copies the capture's **key structure and JSON types only**; every string becomes the synthetic value R2–R5
and R7 prescribe (a body keeps its **line count** and nothing else; a title keeps its prefix token and
nothing else; a label keeps its `from:`/`to:` half and is renamed into R7's vocabulary), every number
becomes its reserved-range value, `_provenance.shape_source` records the capture's id, and the output
is run through the guard **before** it is written — a synthesizer that could emit a failing fixture
would be a second guard that disagrees with the first.

**What this cannot catch, named:** real prose retyped by hand with `[synthetic]` appended to each line;
a real identity spelled in a form the deny list does not hold; a real label vocabulary mirrored under
R7's names; a capture read by a test through a path the grep leg does not recognise (an `include` built
at runtime); and the captures themselves, which live outside the repository in `~/.cache` and are a
disclosure if that directory is copied. The guard makes the slip **mechanical to detect** where a
mechanism can reach it, and this paragraph is the rest.

---

## 4. Storage, write path and feed — the shape

The rules are [D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)'s; the DDL is
[D2 § 6.4](FLEET-STATE.md#64-ddl)'s; this is the shape, stated once so a reader has the picture before
the rules:

- **Five tables**: `coord_hooks` (the binding, with `scope` and a nullable install), `coord_hook_secrets`,
  `coord_deliveries` (the signed-body digest — the idempotency key — plus the delivery header as an
  operator label), `coord_threads` (one row per thread, **current state**, `scope` and nullable install),
  `coord_posts` (one row per post).
- **No fold daemon and no `events` row.** A delivery is not a seat event: D1 § 18.9 enumerates the nine
  batch invariants it fails, `events.seat_ref` is `NOT NULL` and its dedup key is per seat. The receipt
  request derives, stores and enqueues in **one** `App\Feed\Outbox::transaction()`, which is D2 § 6.5's
  per-writer rule applied to a writer that is a request: the digest, the thread upsert, the post insert
  and the feed rows commit together or not at all — D1 § 18.8 step 9's *"steps 7 and 9 commit together"*
  made structural by the primitive the fold already uses.
- **The wire object is per delivery; the stored thread is current state.** `coord.thread` on the feed
  carries that delivery's `lifecycle` verbatim (D1 § 18.6); `coord_threads.lifecycle` is moved by the
  delivery with the greatest `issue.updated_at` and not by arrival order.
- **A thread the store has never seen is derived from the first comment that names it**, and so is its
  opening post — from the `issue` object every `issue_comment` delivery carries.
- **A comment whose `issue.state` contradicts the held lifecycle reconciles it** and emits a
  `coord.thread`, counted.
- **Retention**: `coord_deliveries` 14 days; open threads and their posts never; a closed thread and its
  posts 14 days after the close was received.
- **Feed**: `App\Feed\CoordThread` and `App\Feed\CoordRound` implement `FeedMessage` with
  `installId()` = the binding's install, or `null` for a fleet-scope binding.
- **Counters**: D1 § 18.8.1's eleven live in `global_counters`; `coord_delivery_received` is keyed per
  binding as `coord_delivery_received.<install_id>` for an install binding and
  `coord_delivery_received.fleet` for the fleet's; D2 § 7.2 gains four of the store's own.

---

## 5. Backfill on connect

`GET /api/fleet/coord` (session + MFA only; browser-only like the timeline, so no consumer upgrades
independently of the server and D1 § 18.7's enum-classification trigger — *publication across a deploy
boundary* — does not fire) returns the envelopes a client connected since forever would hold under D3
§ 12's cap: whole threads, newest-received first, up to **1,000** envelopes, fleet-scope and install-scope
alike, and for one thread that alone exceeds the cap its `coord.thread` plus its newest rounds with the
thread named in `truncated_threads[]` — the selection rule is stated once at
[D2 § 6.12](FLEET-STATE.md#612-the-coordination-store). The client's protocol gains a buffer, a fetch and
a drain rule ([D3 § 2.2](FLOOR.md#22-connect-snapshot-deltas) steps 2c, 3c and 5c;
[D2 § 8.4](FLEET-STATE.md#84-snapshot-then-deltas)): open the stream, buffer coordination envelopes,
fetch the backfill, apply it **without animation** (D3 § 6.5 — a backfill is a snapshot, journalled
`backfilled`), then drain the buffer discarding a round whose `post_ref` is held and a thread whose
`received_at` is not later than the held one's. D2 § 14 item 14 closes.

---

## 6. Attribution and the agent-name → desk join

Every name on both objects stays a protocol-level name — an install's agent name on an install-scope
object, a roundtable identity on a fleet-scope one — the objects gain no desk reference, the consumer
resolves against the seat population by D2 § 8.3.3's rules, in order, through the arm the object's
`scope` selects ([§ 3.4](#34-the-roundtable-name-declaration-q2)), and D3 § 5.7 clause 1 renders what
does not resolve as unresolved. The **producer** writes `from`, `opened_by`, `to`, `targets`,
`participants` exactly as D1 § 18.6 / § 18.7 derive them, with `targets` expanded against the binding's
copied set and against nothing else. A producer that mapped `mezzanine-solo` → `mezzanine` would be the
guessed join card#7957 was filed to prevent; the second declaration is what makes the mapping the
seat's own statement instead.

### 6.1 The between-rooms render (card#12240, folded in)

D3 § 4.6 already states the form, in the operator's own terms: *on one floor, it is § 5.7's line; across
floors, a call marker at each end that resolves.* What it lacked was an object without a single
`install_id` and the rows that drive the marker. The object is the fleet-scope `coord.thread` /
`coord.round` ([§ 3.1](#31-the-binding-its-scope-and-why-it-is-selected-by-a-path-segment)); the render
rule, per floor being viewed, is:

1. Resolve every name on the object through the fleet arm — to a desk **anywhere in the building**.
2. **Line**: the resolved ends that are on this floor, if two or more, are joined by D3 § 6.2's A18, held
   while the thread is open — rooms may differ; the floor is one drawing. A19's envelope travels between
   an origin and a destination that are both on this floor; A20's ring expands from an origin on this
   floor. Unchanged rows, a wider population.
3. **Marker**: a resolved end on this floor whose thread has at least one resolved end on **another**
   floor carries the **call marker** while the thread is open, with the thread's `thread_ref` and the far
   participant's name as written beside it; a fleet-scope post whose origin or destination is that desk
   and whose counterpart is on another floor **rings** the marker once. Two resolved ends on one floor
   collapse into the line (D3 § 4.6: *the line is a pair of markers that happen to share a screen*).
4. An end that resolves to nothing is unresolved, first-class, as on any object; an object with no
   resolved end on this floor draws nothing here and is held by the client all the same (it may resolve
   on another floor of the same page's building, and the backfill serves it everywhere).

**The two rows the build adds to D3 § 6.2** — specified here, ids reserved, and **not** written into
§ 6.2 by this design, because `server/tests/Feature/Floor/TheAnimationSetIsTheDocumentsClosedSetTest.php`
holds that table and `wire/animation-set.js` equal in both directions and a design PR must not red the
suite. Columns are § 6.2's own:

| # | Class | Animation | Where | Driving fact (D2) | Edge that starts it, or the fact it is held by | Ends | Reduced-motion form | Its absence means |
|---|---|---|---|---|---|---|---|---|
| **A21** (reserved) | `held` | `call-marker` — a marker at a desk on this floor that is a resolved end of an open fleet-scope thread whose other resolved end is on another floor, carrying the `thread_ref` and the far participant's name as written | desk | `coord_thread.lifecycle`, `coord_thread.scope`, `coord_thread.participants` | the last `coord.thread` held for that `thread_ref` has `scope: "fleet"` and a lifecycle other than `closed`, this desk resolves one of its participants, and at least one other participant resolves to a desk **not** on the floor being viewed | when a `coord.thread` with lifecycle `closed` arrives, when the far end stops resolving, or when both ends come onto one floor and the line takes over | the marker is drawn static | no open fleet-scope thread has one end here and another end elsewhere |
| **A22** (reserved) | `edge` | `call-ring` — the call marker rings once at a desk on this floor when a fleet-scope post's origin or destination is that desk and the counterpart end is on another floor | desk | `coord.round`, `coord_round.from`, `coord_round.targets`, `coord_round.scope` | one `coord.round` applied with `scope: "fleet"`, this desk resolving as its origin or as a member of its `targets`, and the counterpart end — a destination for an origin here, the origin for a destination here — resolving to a desk on another floor | after one ring | the marker carries a static *new post* mark for that post | no fleet-scope post reached across floors to or from a desk here |

The **look** of the marker, the ring and the line between rooms is card#12241's, as the look of A18–A20
is; these rows fix the facts and the triggers and nothing about the drawing.

---

## 7. Failure modes — what the floor shows, and what recovers it

| Failure | What the floor shows | Detectable? | Recovery |
|---|---|---|---|
| **a `coord.round` delivery lost** (GitHub timeout, outage, refusal) | one bead fewer; the thread's bead count is a lower count of a real population and nothing says so — D2 § 8.3.3: *"a lost coordination message is not detectable"* | not from the wire. GitHub records the failure in its delivery list | operator Redeliver within 3 days; the digest is absent, so the redelivery derives |
| **`issues.opened` lost** | no line until the thread's first comment, which derives the thread and its opening post from the embedded `issue` ([D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)) | `coord_thread_from_post` rises | the next post; or Redeliver |
| **`issues.closed` lost** | the line (or the markers) stay drawn until the thread's next delivery of any kind: a comment carrying `issue.state: closed` reconciles it | `coord_thread_reconciled` rises | the next post; or Redeliver; a thread that goes silent after a lost close stays drawn **open** — the named residual |
| **`issues.reopened` lost** | the ended treatment until the next comment (state `open` on a held `closed` ⇒ `reopened`) | as above | as above |
| **duplicate delivery** (GitHub retry, Redeliver inside 14 days) | nothing — absorbed at the digest, `coord_delivery_duplicate` | yes | — |
| **Redeliver after 14 days** | the delivery re-derives; the client draws nothing new (identity dedupe by `post_ref`; the thread upsert is LWW on `issue.updated_at`) — bounded now by GitHub's 3-day window, so this path is reachable only by a payload held outside GitHub | `coord_delivery_received` without a client-visible change | none needed |
| **out-of-order lifecycle** (`reopened` arrives before `closed`) | the store keeps the newer `issue.updated_at`; the feed carries both deliveries in arrival order and the client's last-held `coord.thread` is the **arrival**-last one — ⚠ a connected client can hold the stale lifecycle until the backfill on its next connect; the store is right | `coord_thread_stale_lifecycle` | the client's next connect; named rather than closed ([§ 11](#11-what-this-design-does-not-establish)) |
| **a repository the binding does not name** | `403` at step 6, nothing derived; `coord_signature_invalid` for an unknown `{hook}` | yes | an operator reads the hook's delivery list |
| **a secret leaked** | forged threads on one install's floor — or, for the fleet binding, forged markers on every floor — until revoked | `coord_delivery_received.<install_id or fleet>` spikes; forged content is attributable to the binding | `coord-hook:issue --hook` + `revoke` (overlap rotation) |
| **two seats of different installs declare one roundtable name** | that name resolves to nothing on every fleet-scope object, rendered *declared by more than one seat* | the client reports `duplicate_declaration`; no server counter (D2 § 8.3.3: the consumer is the only party that can see it) | the operator corrects one seat's config |
| **a fleet-scope object with no resolved end on this floor** | nothing on this floor; the object is held and may draw on another floor | — | by design |
| **label churn** (~60 % of observed deliveries are `labeled`/`unlabeled`) | nothing — ignored at step 8, no digest, counted `coord_delivery_ignored.<event_type>` | yes | — |
| **the store down during a delivery** | `503 server_error`, nothing committed, no digest; GitHub records a failure | yes — and `fleet.health.db` | Redeliver within 3 days |
| **the binding's roster drifts** | a new identity's broadcasts omit it from `targets` until re-provisioned | `coord_roster_unknown_name` on its first post | `coord-hook:issue --hook` with the new set |
| **a thread with more posts than the cap** | its newest rounds, bead count *N+* | `truncated_threads[]` on the backfill; `coordTruncated` on the client | by design |
| **a real message in a fixture** | — (not a floor failure) | `coord-fixture-guard.py` reds the PR by rule number | the author re-synthesizes |

**States deliberately not defended against:** a GitHub-side ordering guarantee (none is documented;
the design orders by the payload's own `updated_at`); a body over 1 MiB (25× the measured maximum and
`413` is loud); concurrent identical deliveries (the `UNIQUE` digest makes the loser a duplicate);
convergence (not derivable — D1 § 18.5 finding B, unchanged); a floor composed so that one desk is on
two floors (D3 § 4.6 forbids it).

---

## 8. Rejected alternatives, with the mechanism each turns on

| Alternative | Mechanism | Why not |
|---|---|---|
| **ride `events` and the fold** | insert a synthetic event with a nullable `seat_ref` and a minted `seq`; let `mezzanine:fold` project it | widens the primitive every seat event depends on (D2 § 6.5's seat lock, D2-MUST #3's per-seat dedup, `ix_fold`) to admit a producer that satisfies none of D1 § 18.9's nine invariants — one weaker path for both callers |
| **a fold step: receipt writes raw deliveries, a daemon derives** | a `coord_deliveries.payload` column and a cursor | a second daemon (supervision row, lag badge, frozen-fold class) for a derivation that is one pure function over one JSON object; D2 § 2.3's *frozen fold* hazard imported for nothing. The raw body is also the one thing D1 § 18.10 says must never be stored or logged |
| **a `threads[]` member on `GET /api/fleet/snapshot`** | serve the backfill inside the snapshot | the snapshot is the watchdog's machine interface (`mzr_` token) and is sized at ~91 KB; 1,000 envelopes at ~400 B is 400 KB on every 5-minute poll; and a REST consumer upgrading on its own schedule is exactly the publication that fires D1 § 18.7's enum obligation |
| **a GitHub REST backfill at hook issue** | read open threads through the API with a repo credential | Mezzanine would hold a credential for a repository it only observes (D-10), and the default `gh` identity on this box cannot even see the roundtable (`404`) — the credential would be a new provisioning surface. The first-comment derivation reaches the same threads with no credential |
| **a second message type for the fleet's objects** (`coord.fleet_thread`) | a parallel pair of objects with no `install_id` | two formats for one fact — the defect D1 § 18.1 correction 2 refuses; `scope` on the existing objects costs one slug field and a nullable `install_id`, and `feed_outbox` already carries `NULL` for a fleet-wide message |
| **a separate route or producer for the roundtable** | `POST /api/ingest/roundtable` | the same HMAC, the same validation order, the same derivation, the same store — D1 § 18.11's one-producer rule; a binding's `scope` is the whole difference and it is data |
| **map `mezzanine-solo` → `mezzanine` at the producer or the client** | strip a `-solo`/`-pm` suffix, or read `cross_project.identity` into a table | the guessed join card#7957 was filed to prevent; the seat declares, nobody infers |
| **a `converged` flag now that `issue.state_reason` is observed** | `state_reason: completed` ⇒ converged | `completed` is GitHub's default close reason, set by any close; the protocol's convergence is a quorum (D1 § 18.5 finding B) and `state_reason` carries nothing about it |
| **the lobby draws the line between plaques** | endpoints are floors | rejected at D3 § 4.6 already — a plaque is a floor and the fact is about agents |
| **a convention, not a gate, for synthetic fixtures** | a README rule | the operator asked for a mechanism; a rule nobody runs is the defect canon #16 names |

---

## 9. Implementation plan

Dependency order. Every test is seen RED once before it is trusted; the plant is named with it. D1 § 16
rows 8–10 and D2 Appendix B rows 12–13 are the ratified build rows this plan discharges. **The order
puts the guard first and the roundtable live last**, because every fixture the middle steps author is
judged from its first commit, and the live binding is an outward act that waits on NQ1.

| # | Artifact | Gate (RED plant → GREEN) |
|---|---|---|
| 0a | **The synthetic-fixture guard** (`bin/coord-fixture-guard.py` + `.selftest.py`, the CI workflow, the capture tool, the synthesizer — [§ 3.5](#35-synthetic-fixtures-mechanically-q3)); `server/tests/fixtures/coord/` created empty with the guard green over it | the selftest plants one violation per rule R1–R8 and requires each RED; a capture to a temp git repository is refused; the synthesizer's output passes the guard and a synthesizer edited to copy one string value fails it |
| 0b | **Migration**: `coord_hooks` (with `scope`), `coord_hook_secrets`, `coord_deliveries`, `coord_threads`, `coord_posts` per D2 § 6.4; `App\Sweep\Purge::PLAN` gains the three retention rows, and its guard gains the digest's floor (3 days) | the purge's existing index-order test (D2 § 6.7: *"its test derives each key from `information_schema.STATISTICS` and reds when a declaration stops matching it"*) reds on a declared order the new indexes do not carry; AT-D2-17 extended with the digest chain, seen red by purging at 2 days |
| 1 | **Provisioning**: `mezzanine:coord-hook:issue` / `revoke`, `App\Coord\HookBinding`, `App\Coord\HookSecret` (encrypt/decrypt, overlap) — both scopes | a command test: the plaintext secret appears in stdout once and in no column; `--hook` adds a second secret and verification accepts either until revoke; a revoked secret is `401`; `--scope=fleet` with `--install` is refused, and `--scope=install` without it |
| 2 | **Receipt route** `routes/coord.php` (empty middleware list, like `routes/ingest.php`; `CoordRouteIsolationTest` reads the router), `App\Coord\ReceiptPipeline` in D1 § 18.8's order with the `{hook}` selector, the per-`(route, IP)` failed-auth bucket — `App\Ingest\RateLimiter::hitFailedAuth()` today takes the IP alone, so it gains a route key rather than a sibling (D1 § 12.3: *each route gets a bucket of its own*) | **AT-23**, all three REDs — flipped body, flipped signature, replay under a fresh header against a header-keyed receiver, wrong repository; plus the unknown-`{hook}` `401` and the 61st bad signature from one IP → `429` with 60 from distinct IPs as the control |
| 3 | **Derivation** `App\Coord\Derive` — pure functions over `(issue, comment, labels, binding)`: `thread()`, `openingPost()`, `commentPost()`, body `FROM:`/`TO:` lines, the membership union, `carrier`, `[CLOSE]` anchored, `targets` expansion against the binding's set, `App\Support\Slug::AGENT_NAME` validation, the `coord.subject` redaction profile (D1 § 7.3), `scope` and `install_id` from the binding | **AT-24** (close names nobody — plant the `from:` label fallback), **AT-25** (labels vs union — plant body-only and label-only), **AT-26**'s per-post half (plant the phrase in place of the token); the redaction fixtures 14–17 of D1 § 7.5; a fleet binding yields `scope: "fleet"`, `install_id: null` (plant: copy the first install) |
| 4 | **Store write** `App\Coord\Receipt` — one `Outbox::transaction()`: digest, thread upsert (LWW on `state_at`), post insert, reconcile, enqueue `CoordThread` / `CoordRound` (`installId()` null for fleet scope) | **AT-D2-27** atomicity (plant: commit the digest before the derivation and make the derivation throw → the Redeliver is absorbed); **AT-D2-28** LWW (plant: arrival order → a late `reopened` leaves `closed`); **AT-D2-29** first-seen-via-comment (plant: skip the embedded `issue` → no thread row, no opening post); a fleet-scope row reaches `feed_outbox` with `install_id NULL` and the stream delivers it to a client holding neither install |
| 5 | **The roundtable-name declaration** ([§ 3.4](#34-the-roundtable-name-declaration-q2)): `fleet-reporter.js` reads `roundtable_agent_name`, checks it against `cross_project.identity` at flusher start and on `selftest`, emits both heartbeat members and the two counters, adds the `selftest` member and re-derives D1 § 6.14's capped bound; `KindRegistry`, `Projector`, `SeatFacts`, `SeatDelta`'s map, the migration (two `seat_state` columns), `SeatObject` | the reporter selftest's new cases, each red first — the four states driven by four configs (equal; different; config without `cross_project`; no config); `SeatObjectMatchesTheDocumentTest`'s delta-map leg reds on a version-bearing member the map lacks; `verify-event-schema.py` green on the re-derived bound |
| 6 | **The join's fleet arm** in `floor/coord-join.js` (`buildJoin` over every install's seats, keyed by `roundtable_agent_name`, duplicates counted across installs), selected by `scope` in `coord-model.js` | `TheCoordinationLineResolvesOrRendersUnresolvedTest` extended: a fleet-scope thread naming `mezzanine-solo` resolves to the seat declaring it in the `mezzanine` install and not to the seat declaring `mezzanine` (plant: fall through to the install arm); two installs declaring one roundtable name → `duplicate_declaration` (plant: last writer wins) |
| 7 | **Counters**: the eleven in `global_counters` (`coord_delivery_received.<install_id>` and `.fleet`); `App\Read\FleetHealth::COUNTERS` gains them; D2 § 7.2's four | `FleetHealthCountersMatchTheDocumentTest` (extend the existing document-match pattern) reds when a § 7.1/§ 7.2 fleet-health counter is missing from the constant; `verify-fleet-state.py` G8 is the doc-side half |
| 8 | **Backfill** `GET /api/fleet/coord`, `App\Read\CoordBackfill` with the selection rule, fleet-scope envelopes included | **AT-D2-26**: the backfill equals the set a client that applied every envelope since the store's first row would hold under `COORD_CAP` (plant: newest-first by `posted_at`; the over-cap thread truncated oldest-first) |
| 9 | **Client**: `fleet-client.js` steps 2c/3c/5c; `floor-screen.js` journals backfilled envelopes `backfilled`; the between-rooms render ([§ 6.1](#61-the-between-rooms-render-card12240-folded-in)) — the fleet arm's floor-wide resolution, the marker and the ring in `floor-screen.js`, and **the two rows added to D3 § 6.2 and to `wire/animation-set.js` in the same commit** | **AT-D3-29** through `coord-probe.mjs` and the fleet-client harness (a backfilled round animates nothing; a buffered duplicate draws nothing new); a new AT-D3-30 written with the rows: a fleet-scope thread with ends on two floors draws the marker at each and the line on neither (plant: draw the line to the off-floor desk's slot), and with both ends composed onto one floor draws the line and no marker; `TheAnimationSetIsTheDocumentsClosedSetTest` green only when the module and § 6.2 gained the rows together (it reds on either half alone — the control) |
| 10 | **Doc-sync in the same PR**: README § operating (hook issue runbook, the two declarations), CHANGELOG bullet, `tools/design/README.md` D5 row, the verifier runs, D1 § 6.14's selftest member | the four verifiers + `verify-design-docs.selftest.py` green at the head; the fixture guard green |

**Acceptance on the sandbox** (the sandbox is this seat's to operate; nothing below touches `main` or
production; every outward act is marked):

1. Deploy the build to the sandbox; `php artisan migrate` adds the five tables and the two columns;
   `GET /api/fleet/health` carries every new counter at `0` (seen: `counters` keys diffed against D2
   § 8.2.4's list).
2. This seat's reporter: `roundtable_agent_name: "mezzanine-solo"` in `~/.config/fleet-reporter/config.json`,
   artifact replaced, flusher restarted; the next heartbeat's seat object carries the name with
   `checked` (the sandbox store, read-only).
3. **Replay, both scopes**: `tests/roundtrip/coord-roundtrip.py` signs the synthetic fixtures under a
   sandbox `install` binding and under a sandbox `fleet` binding and posts them; `bin/feed-stream-check.sh`
   shows `coord.thread` / `coord.round` on the stream with and without `install_id`; `GET /api/fleet/coord`
   returns them; the UAT screenshot tooling (`~/.config/coord/uat`, `login.mjs` / `shot.mjs`) captures
   the floor. Because the fixtures are synthetic, their names resolve to no sandbox seat: what this step
   shows is beads, labels, unresolved participants and the backfill — and it says so.
4. **Live, the roundtable** (NQ1 — outward act): a `fleet` binding is issued on the sandbox and the hook
   registered on `PupFuzz/agent-roundtable`; GitHub's *ping* is `202`, counted
   `coord_delivery_ignored.ping`. The next post on the roundtable naming `mezzanine-solo` — this seat's own
   next `coord-post --cross`, so no synthetic traffic is sent to the fleet's channel — animates on the
   sandbox floor without a page reload: this seat's desk shows the **call marker** (the counterpart is on
   another floor, or unresolved until NQ2), and a page loaded afterwards shows the thread through the
   backfill; `coord_delivery_received.fleet` moves on `/api/fleet/health`.
5. **Live, the line** (NQ2 and NQ3): once kanban-solo's seat declares its roundtable name and the operator
   composes the `kanban` room onto the *Mezzanine* floor in the admin console, the next post between the
   two draws the line between the two desks across the two rooms and the envelope along it; with the rooms
   on separate floors it draws a marker on each floor and rings it.
6. Redeliver one delivery from GitHub's list: `coord_delivery_duplicate` moves, the floor draws nothing new.
7. The `install` scope has no live repository on this fleet; step 3 is its acceptance, and this document
   says so rather than letting a replay stand in for the product ([§ 10](#10-operator-questions) NQ4).

**`Built:` for the build PR** is recorded on the card at each dispatch, per `built-line.md`.

---

## 10. Operator questions

**Answered 2026-10-23** — Q1 *(a) with per-install support kept*, Q2 *yes*, Q3 *yes, synthetic only with a
mechanical guard*; Q4 ruled by the seat (encrypted under `APP_KEY`, reversible). [§ 2.4](#24-what-the-operator-decided-on-2026-10-23-verbatim-on-the-card)
records the answers and what each changed. **New questions the revision raises**, each with context,
an example per option, and a recommendation:

**NQ1 — Who registers Mezzanine's hook on `PupFuzz/agent-roundtable`, and when?** Context: an outward
act on a repository this seat does not own; the roundtable credential this seat holds has admin, so the
seat *can* do it; it will not without the operator's go (canon #14).
- *(a) This seat registers it, pointed at the sandbox (`https://sandboxmezzanine.neeba.com/api/ingest/github/mzh_…`),
  as acceptance step 4, with the secret issued on the sandbox.* Example: the hook appears as a fifth
  consumer beside the four bridges; a bad deploy is removed by deleting the hook. Cost: one more hook on
  a shared repository, visible to every fleet with admin.
- *(b) The operator registers it.* Example: the seat prints the URL and secret once at issue; the
  operator pastes both into GitHub's hook form. Cost: a secret crosses a human's clipboard.
- **Recommendation: (a)** for the sandbox, recorded on the card as the operator's authorization for that
  one hook; production's hook is a separate act when production exists.

**NQ2 — kanban-solo's end of the line.** Context: the join resolves a roundtable name only to a seat
that **declares** it; kanban-solo's seat config and reporter are kanban-solo's to change
([§ 3.3](#33-what-crosses-which-boundary-canon-7)'s fourth hop). Until it declares, every roundtable
thread between the two seats renders this seat's end resolved and kanban-solo's unresolved — honest,
and the marker at this end still draws.
- *(a) Ask kanban-solo, on the roundtable, to add `roundtable_agent_name: "kanban-solo"` and take the
  reporter release that carries the field.* Example: an `[FR]` from `mezzanine-solo` to `kanban-solo`
  after the build ships. Cost: a roundtable post and a wait.
- *(b) Accept one resolved end on this fleet for now.* Example: the marker at this seat's desk; no line.
  Cost: the operator's stated want (a line between the two) is not visible until (a) happens anyway.
- **Recommendation: (a)**, after the reporter release — a post is this seat's routine coordination
  traffic, and the operator's answer to Q1 named this pair.

**NQ3 — Compose the `kanban` room onto the *Mezzanine* floor?** Context: the sandbox layout holds one
authored floor with one room ([§ 2.1](#21-installs-and-seats-on-the-sandbox)); with the rooms on separate
floors the roundtable thread renders as markers, and the **line** between rooms needs them on one floor.
An admin-console act, the operator's.
- *(a) Compose them now.* Example: the layout gains the `kanban` room on the *Mezzanine* floor; the first
  live thread draws the line across the two rooms. Cost: the marker form is then unexercised on the
  sandbox until a third room exists.
- *(b) Leave them apart until the marker form is seen, then compose.* Example: acceptance step 4 shows
  the marker, step 5 the line. Cost: two acceptance passes.
- **Recommendation: (b)** — both forms are the product, and the sandbox is the one place both can be
  seen before production.

**NQ4 — The `install` scope has no live repository here.** Context: by Q1 it is built and replay-tested;
no PM-and-impl install reports to this Mezzanine, so step 7 of the acceptance is a replay.
- *(a) Accept the install scope by replay now; a multi-agent install's onboarding is a separate operator
  act when one wants a floor here.* Example: the fixture-driven round trip under an `install` binding.
  Cost: the first live install-scope thread is the first time the scope meets real traffic.
- *(b) Onboard one of the fleet's multi-agent installs to the sandbox first.* Example: an install whose
  bridge already hooks the roundtable registers a second hook for its own coordination repository pointed
  at the sandbox, and its seats add `sites[]` entries for the sandbox (card#12180). Cost: another fleet's
  operator act, and credentials for its seats on this Mezzanine.
- **Recommendation: (a)**; (b) is a fleet question, not this card's.

---

## 11. What this design does not establish

| Not established | Cost if wrong | Closing act |
|---|---|---|
| **that a connected client's held lifecycle equals the store's after an out-of-order pair** — the feed carries per-delivery objects in arrival order ([§ 7](#7-failure-modes--what-the-floor-shows-and-what-recovers-it)) | a line or marker drawn open or ended wrongly until the next connect | add `state_at` (the payload's `updated_at`) to `coord_thread` on D2 § 8.3.3 and let the client keep the newer — one field, deferred to the design review because it changes a published object |
| **the `issues.opened` / `issues.closed` payload shape by capture** — DOCS-CITED only; the comment delivery is observed | the derivation reads `null` on a key the documentation names and the delivery omits | the first live `opened` on the roundtable binding, read through the capture tool into `~/.cache` and compared by key, never vendored |
| **what a per-install ACL on the stream does with a fleet-scope object** — D2 § 14 item 7 says fleet-read is all-or-nothing for now | a client scoped to one install would, under a naive filter, lose every roundtable object or see all of them | decided when that ACL is designed: the honest default is that a fleet-scope object is visible to a reader who may see **any** of its resolved ends' installs |
| **that `cross_project.identity` is where every seat's roundtable name lives** — true of this install's config; the framework owns the key | a seat on a differently-shaped config checks against nothing and reports `unchecked` — a supported state, not a wrong one | carried to the framework's owner with D1 § 3.1's existing resolution-order contract (D1 § 18.13 row 6 already owes that post) |
| **what a redelivered payload is, byte for byte** — GitHub's documentation is silent | a redelivery outside the digest's absorption derives twice; absorbed at the client by identity | observe one Redeliver on the sandbox (acceptance step 6) |
| **the `coord_delivery_received` family's member set on `GET /api/fleet/health`** is the set of bindings at request time plus `fleet` — a revoked binding's member stays (monotonic counters are never removed) | a stale member reads `0`-moving forever | stated, not closed: D2 § 7.2's never-reset rule applies |
| **that the guard's deny list names every real identifier** ([§ 3.5](#35-synthetic-fixtures-mechanically-q3)) | a real name under a spelling the list lacks passes | the list is the four identities and the two agent names of this fleet today; a fleet change re-opens it, and R5's `[synthetic]` token is the independent leg |
| **the seat object's worst-case headroom is now 15 bytes** — the two declaration members move D2 § 8.2.1's worst-case delta to 6,862 B, so D3 § 8.1's intern-cap arithmetic puts the worst case at a cap of 13 at **8,177 B against the 8,192 B bound** (re-derived by `verify-fleet-state.py` G3 and `verify-floor.py` G3 on this revision; it was 8,005 B before). The cap of 8 is untouched, and the cap-of-13 ceiling still holds, by 15 bytes | the next nullable member anyone adds to the seat object breaks D3 § 8.1's *could reach 13* arithmetic at once, and a member of ~250 B or more breaks the 8 KiB message bound at the cap of 8 | stated here so the next card that touches the seat object reads it before its own design review; the structural answer — a per-message reduction rule for the declaration members, or a bound raise — is that card's, not this one's |

---

## 12. The amendment ledger

Every edit this slice makes to a ratified document, with the claim it changes and why. Each is a diff
to the owning document; none is a parallel restatement.

| Doc · section | What changed | Why |
|---|---|---|
| D1 § 18.8 (A1) | the route is `POST /api/ingest/github/{hook}`; an unknown `{hook}` is a step-3 `401`; a binding has a `scope` | [§ 3.1](#31-the-binding-its-scope-and-why-it-is-selected-by-a-path-segment) |
| D1 § 18.8 step 7 (A2) | *"this document mints no retention number"* now points at D2 § 6.7's 14 days | the store exists; D1 § 18.13 row 9's named decision is taken |
| D1 § 18.6 / § 18.7 (A3) | the triggers gain the first `issue_comment.created` on an unheld thread and a comment whose `issue.state` contradicts the held lifecycle; both field tables gain `scope` and a nullable `install_id` | observed: every comment delivery carries the whole `issue`; measured: 95 open threads predate any hook; the operator's Q1 |
| D1 § 12.7 (A4) | the eleven coordination counters are enrolled by pointer rows | the plane that stores them now exists |
| D1 § 18.13 (A5) | rows 1–5, 8, 9 updated with the observed delivery, GitHub's documented facts, and the two closures | the rows' own closing acts, performed |
| D1 § 18.2 (A6) | the bridge checkout row notes the re-read at v0.106.0 | SOURCE-READ's axis is the revision |
| D1 § 15 (A7) | decisions 55 (the selector) and 56 (the scope) | decisions taken, recorded where decisions live |
| D1 § 3.1 / § 6.0 / § 6.14 / § 9.3 / § 18.3.1 / § 18.12 (A8) | the `roundtable_agent_name` declaration, its check, its heartbeat members and counters; the install-facts input per scope; the broadcast anti-requirement per scope | the operator's Q2 and Q1 |
| D2 § 2.1 / § 2.2 (B1) | the **coordination receipt** process row and its fail posture | add a process, add a row |
| D2 § 6.4 (B2) | five tables, with `scope` and a nullable install | the store |
| D2 § 6.7 (B3) | three retention rows and the digest chain | retention stated where retention lives |
| D2 § 6.12 (B4) | **new** — the store's rules | the owning document gains the section the slice owes |
| D2 § 7.1 / § 7.2 / § 8.2.4 (B5) | eleven rows, four rows, the fleet-health list, the two counts | counter closure (verifier G8) |
| D2 § 8.2 (B6) | `GET /api/fleet/coord`; the *no fifth endpoint* paragraph reversed | [§ 5](#5-backfill-on-connect); row 40's reason expired |
| D2 § 8.3 / § 8.3.3 / § 8.4 (B7) | the `coord.thread` trigger cell; `scope` on both objects and `install_id` nullable; the fleet arm of the join; the *no snapshot member* paragraph; the snapshot-then-deltas asymmetry paragraph | the backfill exists; the operator's Q1 and Q2 |
| D2 § 12 / § 13 / § 14 / AT / App. B (B8) | numbers, decisions 52–57, item 14 closed, AT-D2-26…29, build rows 12–13 | bookkeeping the design owes |
| D2 § 6.4 / § 6.5 / § 8.2.1 (B9) | `roundtable_agent_name` and its check: two columns, the version-bearing row, two seat-object rows | the operator's Q2 |
| D3 § 2.2 / § 5.7 / § 6.5 / § 14 / AT (C1) | steps 2c/3c/5c and the drain rule; the *no-snapshot* consequence paragraph; a backfill is a snapshot; item 40; AT-D3-29 | the client half of the backfill |
| D3 § 4.6 / § 5.7 (C2) | the between-rooms form is built for: the object is the fleet-scope pair; clause 1 reads the arm the scope selects; clause 3 and the room row scope to install-scope objects; the two § 6.2 rows are reserved here and land with the module | the operator's Q1; the suite's two-way guard on § 6.2 |
| `tools/design/README.md` | D5 declared under no gate, as D4 is | a document under no gate says so |

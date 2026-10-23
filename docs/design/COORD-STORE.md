# D5 — the coordination-post producer: receipt binding, the roundtable-name declaration, the message animations, and the build plan

**Status: DESIGN, revision r2 — re-scoped to the operator's ruling of 2026-10-23 and folding design
review r1 ([§ 13](#13-design-review-r1--disposition-per-finding)); the build awaits the operator's go on
the questions in [§ 10](#10-operator-questions). Nothing in this document is built.**

> **What this document is, and what it is not.** D1 § 18 owns the coordination-event **producer** — the
> receipt route's authentication, validation order and the derivation of `coord.thread` / `coord.round`
> from a GitHub delivery. D2 § 8.3.3 owns the objects' **read surface** on the feed, and D3 § 5.7 owns
> their **render**. All three were ratified and all three left one slice to *"the slice that designs the
> coordination store"* ([D1 § 18.13](EVENT-SCHEMA.md#1813-what-this-section-does-not-establish) rows 8–9,
> [D2 § 14](FLEET-STATE.md#14-open-questions-for-the-review-loop) item 14). **This is that slice**, and by
> the operator's answers it also carries card#12240's between-rooms render — now transient — and the
> seat's roundtable-name declaration. It owns what no ratified document owns: the hook **binding** and
> its provisioning, the fleet-specific findings, the synthetic-fixture guard, the failure-mode table, the
> implementation plan, the sandbox acceptance and the operator questions. It **amends** D1, D2 and D3 in
> place wherever the design changes a claim they publish ([§ 12](#12-the-amendment-ledger)). The store's
> rules live in **[D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)** and the render rules in
> **[D3 § 5.7](FLOOR.md#57-the-coordination-thread-line)**; this document points at them and does not
> restate them. The drawing — the bird, the envelope, the elevator departure and arrival — is
> card#12241's designer's, and nothing here draws it.

---

## 0. Overview

1. **The operator's ruling of 2026-10-23, verbatim, is the scope:** *"I do not want to show the open
   connection because there are too many open connections. I only want to show the message animations.
   I choose the bird carrying the envelope."* So the floor renders **per-message edges and nothing
   held**: D3 § 6.2's A19, the traveller (the bird, from the sender's desk to **each** resolved
   recipient's desk), A20, the broadcast ring, and — for a fleet-scope post whose other end is on another
   floor — a transient cross-floor form at each end. The thread line (A18), its label, beads, carrier tag
   and *ended* treatment, and the held call marker r1 called A21, are **removed from the design**
   ([§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does)).
2. **Re-derived from that, the producer, store, feed and client shrink** ([§ 4](#4-what-must-still-be-carried-and-what-goes)):
   one object crosses to the floor, `coord.round`; `coord.thread` is specified and **not published**;
   the store holds bindings, secrets and delivery digests and **no thread, post or membership**; there
   is **no backfill**, because a snapshot never animates and every coordination render is an animation;
   the client holds nothing but the identities it has drawn.
3. **The producer is Mezzanine's own GitHub webhook receipt, per D1 § 18.1** — not the bridge. **One
   hook binding per repository, with a `scope`**: `install` (a room's own coordination repository) or
   `fleet` (the roundtable, whose threads span rooms). One route, one derivation, one store; the scope
   decides which `install_id` the object carries (one, or none) and which declaration the floor joins a
   name through. **The first live binding is the roundtable at `fleet` scope** ([§ 3](#3-where-posts-come-from-and-how-they-reach-mezzanine)).
4. **A seat may declare its roundtable name** beside its protocol agent name — `roundtable_agent_name`,
   checked against the same config's `cross_project.identity` — and a fleet-scope post joins a name to a
   desk through that declaration and nothing else ([§ 3.4](#34-the-roundtable-name-declaration-q2)).
5. **Fixtures are synthetic, and a gate says so**: a CI guard over every test source and fixture
   directory, a capture tool that cannot write inside a repository and strips signatures, a synthesizer
   that copies a capture's key shape and none of its values, and a docs leg that applies to new content
   over a baseline the operator can shrink ([§ 3.5](#35-synthetic-fixtures-mechanically-q3-f4-f5-f9f12)).
6. ⛔ **On this fleet the per-install population of coordination repositories is EMPTY**; the
   `install` scope is designed, built and replay-tested all the same, by the operator's answer that it
   must be supported ([§ 2.2](#22-the-coordination-repository-population-on-this-fleet)).

---

## 1. The problem, in the product's terms

The operator's request (card#12239, 2026-10-23): *when one agent posts to another, an envelope travels
from the sender's desk to the receiver's … and a message to everyone sends a ring out from the sender*;
then the ruling above, dropping the open-connection line the first request also named. The floor can
draw the two surviving animations — D3 § 6.2 rows A19 and A20 are built and tested
(`server/tests/Feature/Coordination/CoordRendersTheThreadLineTest.php`,
`TheCoordinationLineResolvesOrRendersUnresolvedTest.php`) — and the server has never sent it one message
to draw. The operator's other answers of the day add: the first messages to animate are the
roundtable's, between a PM and a solo agent in different rooms; messages inside one room (a PM and its
impl agents on their own coordination repository) must be served by the same producer, not deferred.

**Evidence, read on `dev` @ `fa3ce80f` (2026-10-23):**

| Claim | How it was read |
|---|---|
| No route receives a GitHub delivery | `server/routes/ingest.php` declares `/api/ingest/events` and `/api/ingest/health` and nothing else; `grep -rn -i github server/routes server/app` → 0 hits outside docblocks |
| No table holds a coordination fact | `ls server/database/migrations` — no migration names one; `SHOW TABLES` on the sandbox store (read-only, 2026-10-23) lists 31 tables, none coordination-named |
| The feed can carry the objects but nothing writes them | `feed_outbox.t` ENUM carries `coord.thread`, `coord.round` (`2026_09_13_000100_create_feed_outbox_table.php`); on the sandbox `SELECT t, COUNT(*) FROM feed_outbox GROUP BY t` returns `seat.delta` and `feed.heartbeat` only |
| The client holds and renders them | `fleet-client.js:1393–1410` dispatches both types to `#holdCoord`; `floor-screen.js:1411` draws per room — the hold and the line it serves are what the build removes |
| The suite holds D3 § 6.2 and the animation module equal | `server/tests/Feature/Floor/ReadsTheAnimationTable.php` walks the table by its header; `TheAnimationSetIsTheDocumentsClosedSetTest.php` set-differences it against `wire/animation-set.js` **in both directions**, on the Animation and Reduced-motion cells — a design-only PR can neither add nor remove a row |

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
(D3 § 4.6). So on the sandbox as laid out today the two seats are on **different floors**, which is the
cross-floor case of [§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does) — the bird leaving
toward the elevator here, arriving from it there — and the same-floor traveller is one admin-console act
away ([§ 10](#10-operator-questions) NQ3).

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
message text, thread number, login or delivery identifier is copied here or anywhere in this
repository** ([§ 3.5](#35-synthetic-fixtures-mechanically-q3-f4-f5-f9f12)):

```
repository                : private; this seat's roundtable identity holds admin
issues                    : 641 total, 95 open; 8 opened in the last 7 days
comments, last 7 days     : 700
busiest open threads      : 2,010 and 948 comments
from:/to: label vocabulary: four identities of the form <install>-pm / <install>-solo, plus to:all
body form                 : every sampled issue and comment opens with `FROM: <name>` / `TO: <name>`
title form                : `[PREFIX] from:x, to:y — subject` on every sampled issue
hooks registered          : 4 — one per fleet bridge, each subscribed to issues + issue_comment
```

Three consequences, each load-bearing:

1. **The roundtable is the between-rooms case, and by the operator's answer it is the FIRST case.** Its
   participants are four installs' PM/solo seats; a post between this seat and kanban-solo spans two
   rooms. A fleet-scope `coord.round` has **no** room ([§ 3](#3-where-posts-come-from-and-how-they-reach-mezzanine))
   and animates where its ends resolve (D3 § 4.6, § 5.7 property 3).
2. **The roundtable identities are not the declared names.** The seat declares `mezzanine`; it posts
   as `mezzanine-solo` (`cross_project.identity`). D2 § 8.3.3 rule 3 forbids any fallback join. The
   operator's answer to Q2 is the second declaration ([§ 3.4](#34-the-roundtable-name-declaration-q2)).
3. **~100 posts a day cross the roundtable**, so a floor with one resolved roundtable seat sees a bird
   several times an hour, and an unwatched floor misses them — by the ruling, a message is an
   animation and an animation is not replayed ([§ 7](#7-failure-modes--what-the-floor-shows-and-what-recovers-it)).

**What a real delivery contains — observed, not vendored.** With admin on the roundtable, the sandbox
bridge's hook exposes its recent deliveries through GitHub's deliveries API. Read 2026-10-23, keys and
sizes only — the capture tool of [§ 3.5](#35-synthetic-fixtures-mechanically-q3-f4-f5-f9f12) is the only
sanctioned way to read one in future, and it writes outside the repository:

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
vendored fixture, which by the operator's answer to Q3 will never be a real one. ⚠ No `issues.opened`
was in the window read; its `issue` object is the same documented object (GitHub REST *Get an issue*
schema, read 2026-10-23), the derivation of the opening post reads it, and the first live `opened` on
the roundtable binding is where it is observed.

### 2.3 GitHub's side of the hop, read at the vendor's documentation

Read 2026-10-23 at docs.github.com (DOCS-CITED in D1 § 18.2's sense); each closes or bounds a D1 § 18.13
row:

| Fact | Source sentence | Consequence here |
|---|---|---|
| **No automatic retry** | *"GitHub does not automatically redeliver failed deliveries."* | a refused or failed delivery is lost unless an operator redelivers it; the receipt's write must be cheap and must not refuse what it can store |
| **10 s response timeout** | *"if your server is down or takes longer than 10 seconds to respond, GitHub will record the delivery as a failure"* | the receipt transaction's wall-clock budget is 10 s minus transport; it takes no seat lock and is one insert and the outbox rows |
| **3-day redelivery window** | *"you can redeliver webhook deliveries from the past 3 days"*; *"All deliveries from the past 3 days will be listed."* | the digest store's retention must exceed 3 days — it is **14 days** ([D2 § 6.7](FLEET-STATE.md#67-retention-and-purge)) |
| **25 MB payload cap** | *"If an event generates a larger payload, GitHub will not deliver a payload for that webhook event."* | D1 § 18.8's 1 MiB body cap is ~25× below the sender's; a whole observed delivery is ~14 KB, so the 1 MiB cap is a guard, not a path |
| **Hook creation** | the REST reference states admin for deletion; creation is exercised on this fleet by an admin credential | registering Mezzanine's hook is an outward act on another fleet's repository ([§ 10](#10-operator-questions) NQ1) |

### 2.4 What the operator decided on 2026-10-23, verbatim on the card

| Q | Answer | What it changes in this document |
|---|---|---|
| Q1 | *"(a) roundtable first, but messages between PM and impl agents must be supported too"* | the first live binding is the roundtable at `fleet` scope; card#12240's render is folded in; the `install` scope is built and replay-tested in the same slice |
| Q2 | *"yes"* | the optional `roundtable_agent_name` declaration end to end ([§ 3.4](#34-the-roundtable-name-declaration-q2)) |
| Q3 | *"Yes, you must be careful that no real messages enter the repo"* | synthetic fixtures only, with a mechanical guard, a capture tool that writes outside the repo, and a docs leg over new content ([§ 3.5](#35-synthetic-fixtures-mechanically-q3-f4-f5-f9f12)) |
| Q4 | seat ruling, reversible | hook secrets are encrypted at rest under `APP_KEY` ([§ 3.2](#32-provisioning--one-operator-act-three-things-issued)) |
| render scope | *"I do not want to show the open connection because there are too many open connections. I only want to show the message animations. I choose the bird carrying the envelope."* | every held coordination render is removed; the producer, store, feed and client are re-derived from the per-message edges ([§ 4](#4-what-must-still-be-carried-and-what-goes), [§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does)); r1's F1 and F7 lose their subject ([§ 13](#13-design-review-r1--disposition-per-finding)) |

⚠ The card's own comment c13244 numbers the first three questions Q1/Q3/Q2; this document numbers them
as the operator answered them (r1 hygiene).

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
| **a path segment names the binding** — `POST /api/ingest/github/{hook}` | `{hook}` is a public, random identifier issued with the binding; it selects the row whose secrets are tried, and the secret decides | one URL per binding, which GitHub's hook config already holds; no parse before auth; an unknown `{hook}` is a step-3 refusal |

**Chosen: the path segment.** `{hook}` is `mzh_` + 22 base64url characters (128 bits from a CSPRNG), a
greppable prefix in the family of `mzn_` / `mzr_`, **not a secret** — it appears in GitHub's hook
settings page and in the access log — and nothing authenticates on it: an unknown `{hook}` is `401`
inside D1 § 18.8 step 3, counted `coord_signature_invalid`, in the same per-`(route, source IP)` bucket.
(r1 verified this selector, the failed-auth bucket and AT-23 as correct.)

**The binding's `scope` — `install` or `fleet`** is set at issue, never inferred from a payload, and
decides:

| | `scope: install` | `scope: fleet` |
|---|---|---|
| the binding names | one `install_id` and one repository | one repository and **no** install |
| the object carries | `scope: "install"`, `install_id: "<the binding's>"` | `scope: "fleet"`, `install_id: null` — riding `feed_outbox`'s `NULL` for a fleet-wide message |
| the install-facts input (D1 § 18.3.1) | that install's roster and `shared_identity` | the fleet's **roundtable identity set** — the `from:`/`to:` vocabulary of the repository, copied at issue — and `shared_identity` |
| `targets` for `to: all` | the install's roster less the author | the roundtable identity set less the author |
| the floor joins a name through | `protocol_agent_name`, within the install (D2 § 8.3.3 rule 1) | `roundtable_agent_name`, across every install ([§ 3.4](#34-the-roundtable-name-declaration-q2)) |
| the render | A19/A20 in the one room (D3 § 5.7 property 3) | A19/A20 where the ends resolve; the transient cross-floor form across floors ([§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does)) |

D2 § 9's per-subscriber filter admits everything today, so a fleet-scope envelope reaches every client
as `feed.heartbeat` does; what a future per-install ACL does with one is named at [§ 11](#11-what-this-design-does-not-establish).

### 3.2 Provisioning — one operator act, three things issued

`php artisan mezzanine:coord-hook:issue <repository> --scope=install --install=<install_id> --roster=<a,b,c> --shared-identity=<true|false> --by=<who>`
`php artisan mezzanine:coord-hook:issue <repository> --scope=fleet --roster=<identity,…> --shared-identity=<true|false> --by=<who>`

creates, in one transaction: the binding row (`coord_hooks`: scope, install or none, repository, the
install-facts input D1 § 18.3.1 declares — `roster[]`, `shared_identity`, and the revision/date the
operator copied them at), one secret row (`coord_hook_secrets`, ciphertext at rest under the application
key — an HMAC key must be recoverable, so it cannot be hashed like a bearer token; **seat ruling on Q4,
reversible**), and prints **once**: the URL to register (`https://<APP_URL>/api/ingest/github/mzh_…`),
the secret, and the subscription to tick (`issues`, `issue_comment`; content type `application/json`).
Mirrors `mezzanine:ingest-token:issue` in every discipline (`App\Console\SecretLine`; plaintext printed
once, stored nowhere).

`--hook=<mzh_…>` adds a **second** secret to an existing binding (rotation with overlap) or replaces its
roster copy, and **refuses a third while two are unrevoked** (r1 F16; AT-D2-29);
`mezzanine:coord-hook:revoke <mzh_…> [--secret=<prefix>]` revokes a secret or the binding. Verification
tries every unrevoked secret of the one binding, at most two. **Mezzanine holds no GitHub credential for
any of this** — the hook is registered on GitHub's side by the operator, or by this seat with the
operator's go (NQ1), which keeps D-10 true of this producer too.

### 3.3 What crosses which boundary (canon #7)

| Hop | Guarantee | DECLARED by | CHECKED by | NAMED as unverifiable |
|---|---|---|---|---|
| GitHub → Mezzanine, the delivery | body bytes are the ones signed under the binding's secret; `X-GitHub-Event` names the event | GitHub's documented delivery contract ([§ 2.3](#23-githubs-side-of-the-hop-read-at-the-vendors-documentation)) | D1 § 18.8 steps 3–6, run by this route | delivery **ordering** (irrelevant now that nothing is held: each post is its own edge); whether a **redelivered** payload is byte-identical to the original |
| coordination repo config → the binding's install-facts copy | `roster[]`, `shared_identity` | the operator, at `coord-hook:issue`, with the revision copied | `coord_roster_unknown_name` on the first post by an unknown name (D1 § 18.3.1's guard) | a removal from the upstream set (one-directional guard) |
| seat declaration → coordination name, per scope | the name a seat declares equals the name it posts under on repositories of that scope | the seat (D1 § 3.1: `protocol_agent_name` for the install's repository, `roundtable_agent_name` for the fleet's) | the seat's own check — against the roster for the first, against `cross_project.identity` of the same config for the second | that a repository bound at `install` scope is the one the roster describes, and one bound at `fleet` scope is the one `cross_project.repo` names — two operator acts nothing compares |
| another seat's declaration (kanban-solo's) → this floor's join | that seat declares its roundtable name and runs a reporter that sends it | that seat's operator | that seat's own check | from here, nothing — a post to or from that seat animates nothing until its heartbeat carries the name (NQ2) |
| Mezzanine → the browser | the message, the feed | D2 § 8.3.3, § 8.3 | the client's dedupe by identity; AT-D3-29 | nothing new: one deployment, no skew axis (D2 § 8.1) |

### 3.4 The roundtable-name declaration (Q2)

**The gap.** `protocol_agent_name` is the name a seat posts under on its **own install's** coordination
repository, checked against that install's roster. On the roundtable the same seat posts under
`cross_project.identity` — `mezzanine-solo` for `mezzanine` — a different string from a different key of
the same `coordination.config.json`. A fleet-scope post names the second; no declaration carries it;
D2 § 8.3.3 rule 3 forbids guessing the first from the second.

**The declaration, end to end — the same shape as card#9296 / card#9375, one key over, and nothing
guesses a desk:**

| Surface | What changes | Owner |
|---|---|---|
| the seat config (D1 § 3.1) | one optional row, `roundtable_agent_name`, slug ≤ 48 B | D1 amendment A8 |
| the check (D1 § 3.1) | at flusher start and on `selftest`, against **`cross_project.identity`** of the config `$COORD_CONFIG` resolves: `checked` when equal; `disagreed` when the config carries one and it differs; `unchecked` when no config is readable **or** it carries no `cross_project` block; `undeclared` when the key is absent, null or malformed | D1 amendment A8 |
| the heartbeat (D1 § 6.14) | `roundtable_agent_name` and `roundtable_agent_name_check`; the counters `roundtable_agent_name_unchecked` / `_disagreed` (§ 9.3) | D1 amendment A8 |
| the ingest registry, the fold, the store (D2 § 6.4), the seat object (D2 § 8.2.1), the delta map | two columns, two members, two entries in `SeatDelta`'s map, both version-bearing | D2 amendment B9; build step 5 |
| the join (D2 § 8.3.3 rule 1) | **a second arm, selected by the object's `scope`**: a fleet-scope name resolves to the ONE seat **of any install the client holds** whose `roundtable_agent_name` equals it with check `checked` or `unchecked`, provided no other seat of any install declares it in any state but `undeclared` — the same two unresolved reasons. The arms never cross | D2 amendment B9 |
| the render (D3 § 5.7 property 1) | the resolve arm reads the arm the object's `scope` selects; an unresolved end gets no bird and is logged, *unchecked* rides the log line | D3 amendment C2 |
| the `selftest` member | `roundtable_agent_name_matches`, the sibling of `protocol_agent_name_in_roster` — added by the build (D1 § 6.14's capped `selftest` bound is re-derived by the gate) | build step 5 |

**What it does not do.** It does not join the two names to each other; a fleet-scope `FROM: mezzanine`
(a name written in the wrong vocabulary) resolves nothing. It is not an input to the token binding, the
character seed or `config_fingerprint`. Omitting it is a supported state: a post to or from the seat on
the roundtable animates nothing at its desk, which is exactly today's render.

### 3.5 Synthetic fixtures, mechanically (Q3; r1 F4, F5, F9–F12)

**The rule.** No real coordination message — body, title, label vocabulary, thread number, comment id,
delivery id, login — enters this repository, in a fixture, a test source or a document. Counts and
shapes may. The mechanism has three parts, each with the plant that proves it fails.

**(1) The guard — `bin/coord-fixture-guard.py`, a CI job on every PR (`.github/workflows/coord-fixture-guard.yml`,
the shape of `asset-provenance.yml`).** Three populations, each stated so the guard cannot be clean over
the wrong one (r1 F4):

- **P1, delivery-shaped fixtures**: every JSON file under any `fixtures/` directory below `server/tests/`
  — `server/tests/fixtures/coord/`, `server/tests/Feature/Floor/fixtures/` (`fx-coord.json` and its
  siblings) and any added later — whose top level carries `repository` and `issue` or `comment`, or
  whose top level is a feed envelope with `t` of `coord.round` / `coord.thread`. Rules R1–R5 and R7.
- **P2, every test source**: every `.php`, `.js`, `.mjs`, `.py` and `.json` under `server/tests/`,
  `tools/`, `bin/` and `fleet-reporter/` — **inline literals included**, since the deny leg reads text
  and does not parse. Rule R6.
- **P3, documents**: every `.md` under `docs/` and the repository root. Rule R8.

| # | Rule | Population | Why this predicate |
|---|---|---|---|
| R1 | a top-level `_provenance` object: `{"synthetic": true, "shape_of": "<event>.<action>" or "<t>", "authored_by": "<tool or person>", "shape_source": "<capture id outside the repo, or 'hand'>"}` — four predicates | P1 | a fixture says what it is; `shape_source` names a capture file in `~/.cache`, never content |
| R2 | `repository.full_name` is `synthetic-org/coordination-fixture`; `repository.owner.login` starts with `synthetic-`; every `*.html_url` / `*.url` / `issue_url` host is `example.invalid` — three predicates | P1 | the reserved names cannot collide with a real organisation of this fleet, and `.invalid` is reserved by the DNS standard |
| R3 | `issue.number` ∈ [900000, 999999]; `comment.id` ≥ 9 000 000 000 000; every other `*.id` is 0 or ≥ 9 000 000 000 000; `X-GitHub-Delivery` matches `00000000-0000-4000-8000-[0-9a-f]{12}` — four predicates | P1 | real identifiers on this fleet are below 1,000 (threads) and around 6.4 × 10⁹ (comments); the reserved ranges are disjoint by three orders of magnitude |
| R4 | `user.login` and `sender.login` are `synthetic-user`; `sender.id` is 0 — two predicates | P1 | — |
| R5 | every `issue.body` and `comment.body` is the protocol preamble (`FROM:` / `TO:` lines) followed by lines **each containing the token `[synthetic]`**, and ≤ 600 B; every `issue.title` matches `^\[[A-Z]+\] from:[^,]+, to:[^—]+ — \[synthetic\] ` — three predicates | P1 | prose typed to carry the token is prose somebody wrote for the fixture; a pasted paragraph fails on its first line |
| R6 | the file's **tokens** (split on anything outside `[a-z0-9_-]`, lowercased) include none whose SHA-256 is in `bin/coord-fixture-guard.deny.sha256` — a hashed list of this fleet's real repository names, organisation name, roundtable identities and protocol agent names, generated by `--hash-deny` from an operator-held plaintext at `~/.config/coord/coord-fixture-deny.txt` (r1 F12: the repository publishes no real login); **and** no `github.com/` host outside `synthetic-org/`; **and** no `c-\d{9,}`; **and**, in P1 only, no run of 9+ digits that does not begin `9000` — outside the fields the guard derives rather than reads: `post_ref`, `thread_ref`, `delivery_digest`, `digest`, `_provenance.shape_source` (r1 F9) — four predicates | P1, P2 | a real identifier under a synthetic wrapper fails by hash, by host or by range; a hash catches the exact spelling and nothing else, which is stated below |
| R7 | label names are drawn from `from:`/`to:` × `{pm, coder, helper, reviewer, all}` and, for fleet-scope fixtures, `{pm-solo, coder-solo}` — D3 § 11's synthetic vocabulary extended by two | P1 | one synthetic language across the floor's fixtures and the receipt's |
| R8 | no line matches `agent-roundtable#\d+`, `/issues/\d+`, `c-\d{9,}` or a delivery GUID — **unless the `(path, line-pattern)` is in `bin/coord-fixture-guard.exemptions`**, a baseline written once by `--baseline` from the tree at the time of the build and never regenerated by CI; an exemption the tree no longer matches **reds**, so the list can only shrink — four predicates | P3 | the operator's rule covers documents; the existing occurrences (real roundtable thread numbers in public docs and tooling, and D2 § 3.1's quoted maxim with its issue link) are the operator's to scrub or keep and are **not edited on this branch** — the dispatching seat has asked (r1 F5; NQ5) |

The guard's selftest plants **one violation per predicate** (the counts above sum to the plant count
it prints, r1 F10), requires each RED, and runs the correct fixture set and requires GREEN.

**(2) The capture tool — `tools/coord/capture-delivery.py --hook <id> --delivery <id>`.** Reads one
recorded delivery through `xproj-gh api` (read-only), **strips every `X-Hub-Signature*` header** before
anything is written (r1 F11: a kept signature plus the binding's secret would be a replayable delivery),
writes **only** to `${XDG_CACHE_HOME:-~/.cache}/coord/captures/<repository>/<delivery>.json` with mode
0600, prints the key paths and byte sizes and **no value**, and **fails closed** on the worktree check:
it writes only when `git -C <dir> rev-parse --is-inside-work-tree` exits 128 with the not-a-repository
message — any other outcome (exit 0, another error, `git` missing) refuses. The selftest plants an
`--out` inside a temp git repository and a missing `git` and requires both refusals.

**(3) The synthesizer — `tools/coord/synthesize-fixture.py <capture> --shape-of <event.action> --out <P1 path>`.**
Copies the capture's **key structure and JSON types only**; every string becomes the synthetic value
R2–R5 and R7 prescribe (a body keeps its **line count** and nothing else; a title keeps its prefix
token and nothing else; a label keeps its `from:`/`to:` half and is renamed into R7's vocabulary),
every number becomes its reserved-range value, `_provenance.shape_source` records the capture's id,
and the output is run through the guard **before** it is written.

**What this cannot catch, named:** real prose retyped by hand with `[synthetic]` appended to each line;
a real identity spelled in a form the hashed list does not hold; a real label vocabulary mirrored under
R7's names; a capture read by a test through a path built at runtime; and the captures themselves,
which live outside the repository in `~/.cache` and are a disclosure if that directory is copied.

---

## 4. What must still be carried, and what goes

Re-derived from the render rule — *every coordination render is an edge fired by one `coord.round` on
arrival* — surface by surface:

| Surface | Needed | Not needed, and removed from the design |
|---|---|---|
| **the floor** | `coord_round.scope`, `install_id`, `post_ref` (draw once), `from` + `attribution` (origin), `to` (the ring's member), `targets` (destinations); the join through the seat objects' two declarations; a bounded memory of drawn `post_ref`s | any `coord.thread` member; lifecycle; participants; subject; carrier; beads; `declares_close`; both timestamps as renders |
| **the feed** | `coord.round` | `coord.thread` — **specified and not published** (D2 § 8.3's row); the outbox ENUM member stays so publishing it again is code, not a migration |
| **the producer** | D1 § 18.7's derivation from `issues.opened` (the opening post) and `issue_comment.created`; `scope`/`install_id` from the binding | D1 § 18.6's derivation — stands as specification, derived from nothing; `issues.closed`/`reopened` received and ignored (counted) |
| **the store** | `coord_hooks`, `coord_hook_secrets`, `coord_deliveries` (digest, 14 days) | `coord_threads`, `coord_posts`, any lifecycle or membership rule, any retention beyond the digest |
| **the backfill** | **none** — a snapshot never animates (D3 § 6.5) | `GET /api/fleet/coord`, the client's thread/round holds and their caps, steps 2c/3c/5c |
| **the client** | the `post_ref` memory (1,000, D3 § 12); the fleet arm of `buildJoin`; the A19/A20 triggers reading `scope`; the event-log line for an unresolved end | `#holdCoord`, `COORD_CAP`'s eviction, `coordModel`'s threads, A18, the held call marker |

The receipt's write path, concurrency primitive and fault discipline are [D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)'s
(r1 F3 folded there); the store that remains is three tables, written in one `Outbox::transaction()`
with `INSERT … ON DUPLICATE KEY UPDATE` on the digest, so two byte-identical deliveries in flight both
answer `202` and two distinct ones never contend.

---

## 5. Backfill on connect — none, and why that is derived

A page that has just loaded draws no coordination fact. Not because it has not been told, but because a
snapshot never animates ([D3 § 6.5](FLOOR.md#65-a-snapshot-never-animates)) and, under the ruling, every
coordination render *is* an animation: there is nothing a backfill could carry that the page would draw.
The same holds for a reconnect and a resync. D2 § 14 item 14 closes on that; D2 § 13 row 53 records the
first draft's endpoint as withdrawn. The one state the client keeps is the `post_ref`s it has drawn,
bounded at 1,000 (D3 § 12's repurposed row): a redelivery inside that memory fires nothing, and one
older than it animates again — accepted and stated, because the alternative is the store the ruling
removed.

---

## 6. Attribution and the agent-name → desk join

Every name on the object stays a protocol-level name — an install's agent name on an `install`-scope
post, a roundtable identity on a `fleet`-scope one — the object gains no desk reference, the consumer
resolves against the seat population by D2 § 8.3.3's rules through the arm the object's `scope` selects
([§ 3.4](#34-the-roundtable-name-declaration-q2)), and an end that does not resolve gets no bird and is
reported in the client's event log line for the post (D3 § 5.7 property 1). The **producer** writes
`from`, `to`, `targets` exactly as D1 § 18.7 derives them, with `targets` expanded against the binding's
copied set and against nothing else. A producer that mapped `mezzanine-solo` → `mezzanine` would be the
guessed join card#7957 was filed to prevent; the second declaration is what makes the mapping the
seat's own statement instead. (r1 verified the fleet arm as correct.)

### 6.1 What the floor renders, and what it no longer does

**Removed by the ruling**, from D3 § 5.7, § 4.6 and § 6.2: the thread line A18 and everything hung on
it — its label (`subject`), its carrier tag, its beads and bead count, the *ended* treatment, the
participant list — and the held call marker r1 called A21. A18's § 6.2 row stays with a trigger cell
that says it never fires, and leaves with its `wire/animation-set.js` entry in the build, because the
suite holds the two equal in both directions.

**Kept, per message**, with D3 § 6.2 as the owner of the rows and this section as the per-floor rule:

1. Resolve the post's origin and every destination through the arm its `scope` selects — to a desk
   **anywhere in the building** for a fleet-scope post, within the room for an install-scope one.
2. **A19, the traveller** — card#12241 draws it as the bluebird carrying the envelope — fires from a
   resolved origin desk **on this floor** to **each** destination that resolves to a desk **on this
   floor**, one traveller per destination; rooms may differ. An unresolved end gets none and is logged.
3. **A20, the broadcast** — fires from a resolved origin desk on this floor when `to` carries the literal
   `all`; the ring is drawn on that floor alone.
4. **The transient cross-floor form** — for a fleet-scope post, an end on this floor whose counterpart
   resolves to a desk on **another** floor: a resolved **origin** sends the traveller from its desk
   **toward the floor's elevator** and it leaves; a resolved **destination** receives it **arriving from
   the elevator** at its desk. Once, per post, per end; nothing stays. Its § 6.2 row is **reserved**
   here — id **A22**, class `edge` — and lands with the module that draws it:

| # | Class | Animation | Where | Driving fact (D2) | Edge that starts it, or the fact it is held by | Ends | Reduced-motion form | Its absence means |
|---|---|---|---|---|---|---|---|---|
| **A22** (reserved) | `edge` | `cross-floor-traveller` — the traveller leaves a resolved origin desk on this floor toward the floor's elevator, or arrives from the elevator at a resolved destination desk on this floor, for a fleet-scope post whose counterpart end resolves to a desk on another floor | desk, floor | `coord.round`, `coord_round.scope`, `coord_round.from`, `coord_round.targets` | one `coord.round` applied with `scope: "fleet"`, this desk resolving as its origin or as a member of its `targets`, and the counterpart end — a destination for an origin here, the origin for a destination here — resolving to a desk on another floor; one edge per such end on this floor | at the elevator (departure) or at the desk (arrival) | the envelope is simply present at the elevator or at the desk for the edge's duration, with no travel | no fleet-scope post crossed floors to or from a desk here |

5. A post drawn once is not drawn again (`post_ref` memory); a post nobody was watching is not drawn
   later, by the ruling — the client's event log is the record.

The **look** of the bird, the envelope, the elevator departure and arrival, the ring, their sizes and
frames is card#12241's, as the look of A19–A20 always was; these rules fix what fires, from which desk
to which, and what absence means, and nothing about the drawing.

---

## 7. Failure modes — what the floor shows, and what recovers it

| Failure | What the floor shows | Detectable? | Recovery |
|---|---|---|---|
| **a delivery lost** (GitHub timeout, outage, refusal) | one bird that never flew; nothing later shows it | not from the wire; GitHub's delivery list records the failure | operator Redeliver within 3 days; the digest is absent, so the redelivery derives and the bird flies then |
| **`issues.opened` lost** | the opening post's bird never flew; every later comment on the thread still animates | as above | as above |
| **duplicate delivery** (GitHub retry, Redeliver inside 14 days) | nothing — absorbed at the digest, `coord_delivery_duplicate` | yes | — |
| **Redeliver after 14 days** | the delivery re-derives; the client fires nothing if the `post_ref` is in its 1,000-deep memory, and a second bird if it is not | `coord_delivery_received` without a client-visible change | accepted; bounded by GitHub's 3-day reach, so reachable only by a payload held outside GitHub |
| **concurrent identical deliveries** | one bird | both answer `202`; one counts `coord_delivery_duplicate` | — |
| **a viewer not watching** | nothing, ever, for that post | the client's event log on the page that was open | by ruling: a message is an animation |
| **origin unresolved** (the seat declares no name of the arm's kind, or two seats declare one) | no bird and no ring; the event log names the reason | the log line; `duplicate_declaration` is reported by the client alone (D2 § 8.3.3) | a declaration on the seat |
| **a destination unresolved** | the other destinations' birds fly; that one's does not; logged | the log line | a declaration on the seat |
| **ends on different floors** | the cross-floor form at each end (A22, reserved) | — | by design; composing the rooms onto one floor turns it into the desk-to-desk bird (NQ3) |
| **a repository the binding does not name** | `403` at step 6, nothing derived; `coord_signature_invalid` for an unknown `{hook}` | yes | an operator reads the hook's delivery list |
| **a secret leaked** | forged birds on one install's floor — or, for the fleet binding, on every floor — until revoked | `coord_delivery_received.<hook>` spikes; forged content is attributable to the binding | `coord-hook:issue --hook` + `revoke` (overlap rotation, at most two) |
| **label churn** (~60 % of observed deliveries are `labeled`/`unlabeled`) and `closed`/`reopened` | nothing — ignored at step 8, no digest, counted `coord_delivery_ignored.<event_type>` | yes | — |
| **the store down during a delivery** | `503 server_error`, nothing committed, no digest; GitHub records a failure | yes — and `fleet.health.db` | Redeliver within 3 days |
| **the binding's roster drifts** | a new identity's broadcasts omit it from `targets` until re-provisioned | `coord_roster_unknown_name` on its first post | `coord-hook:issue --hook` with the new set |
| **a real message in a fixture or a document** | — (not a floor failure) | `coord-fixture-guard.py` reds the PR by rule and predicate | the author re-synthesizes; a document occurrence is the operator's (NQ5) |

**States deliberately not defended against:** delivery ordering (each post is its own edge); a body
over 1 MiB (25× the measured maximum and `413` is loud); convergence, lifecycle and membership (not
rendered — D1 § 18.5 finding B unchanged, and the ruling); a thread deleted or transferred (r1 F15 —
nothing held means nothing to close; its later comments, if any, still animate).

---

## 8. Rejected alternatives, with the mechanism each turns on

| Alternative | Mechanism | Why not |
|---|---|---|
| **hold thread state anyway, for a future render** | the first draft's `coord_threads` / `coord_posts`, lifecycle rule and backfill | the operator ruled out every render that would read it; machinery with no consumer, and r1 F1 showed the client half losing lines for good under eviction — a defect class that exists only while something is held |
| **ride `events` and the fold** | a synthetic event with a nullable `seat_ref` | widens the primitive every seat event depends on to admit a producer that fails all nine of D1 § 18.9's invariants |
| **a raw-delivery table drained by a daemon** | a `payload` column and a cursor | a daemon for a derivation that is one pure function, and it stores the raw body D1 § 18.10 forbids |
| **a second message type for the fleet's posts** (`coord.fleet_round`) | a parallel object with no `install_id` | two formats for one fact; `scope` on the existing object costs one slug field and a nullable `install_id` |
| **a separate route for the roundtable** | `POST /api/ingest/roundtable` | the same HMAC, validation, derivation and store — D1 § 18.11's one-producer rule; a binding's `scope` is the whole difference and it is data |
| **map `mezzanine-solo` → `mezzanine`** | strip a suffix, or read `cross_project.identity` into a table | the guessed join card#7957 refused; the seat declares, nobody infers |
| **a held call marker for the cross-floor case** (r1's A21) | a marker held while the thread is open | a held render of an open connection — the thing the ruling removed; the transient form at each end says the same event happened and leaves nothing on screen |
| **a GitHub REST read at hook issue** | a repo credential | Mezzanine would hold a credential for a repository it only observes (D-10), and nothing held would use what it read |
| **a convention, not a gate, for synthetic fixtures** | a README rule | the operator asked for a mechanism |

---

## 9. Implementation plan

Dependency order. Every test is seen RED once before it is trusted; the plant is named with it. D1 § 16
rows 8–10 and D2 Appendix B rows 12–13 are the ratified build rows this plan discharges. **The guard is
first and the roundtable live last**, because every fixture the middle steps author is judged from its
first commit, and the live binding is an outward act that waits on NQ1.

| # | Artifact | Gate (RED plant → GREEN) |
|---|---|---|
| 0a | **The synthetic-fixture guard** ([§ 3.5](#35-synthetic-fixtures-mechanically-q3-f4-f5-f9f12)): `bin/coord-fixture-guard.py` + `.selftest.py`, the hashed deny list generated from the operator-held plaintext, the baseline exemption file written once from the tree, the CI workflow, the capture tool, the synthesizer; `server/tests/fixtures/coord/` created with the guard green over all three populations | the selftest plants one violation per **predicate** and requires each RED; a stale exemption reds; a capture to a temp git repository and with `git` missing is refused; a capture written keeps no `X-Hub-Signature*`; the synthesizer's output passes the guard and a synthesizer edited to copy one string value fails it |
| 0b | **Migration**: `coord_hooks` (with `scope`, `install_key`), `coord_hook_secrets`, `coord_deliveries` per D2 § 6.4; `App\Sweep\Purge::PLAN` gains the digest row, and its guard the 3-day floor | the purge's existing index-order test (D2 § 6.7) reds on a declared order the new index does not carry; AT-D2-17 extended with the digest chain, seen red by purging at 2 days |
| 1 | **Provisioning**: `mezzanine:coord-hook:issue` / `revoke`, `App\Coord\HookBinding`, `App\Coord\HookSecret` (encrypt/decrypt, overlap, the two-secret refusal) — both scopes | a command test: the plaintext secret appears in stdout once and in no column; `--hook` adds a second secret and verification accepts either until revoke; a third is refused by name (AT-D2-29); `--scope=fleet` with `--install` is refused, and `--scope=install` without it |
| 2 | **Receipt route** `routes/coord.php` (empty middleware list, like `routes/ingest.php`; `CoordRouteIsolationTest` reads the router), `App\Coord\ReceiptPipeline` in D1 § 18.8's order with the `{hook}` selector and step 8's two-member derive set, the per-`(route, IP)` failed-auth bucket — `App\Ingest\RateLimiter::hitFailedAuth()` gains a route key rather than a sibling | **AT-23**, all three REDs; the unknown-`{hook}` `401`; the 61st bad signature from one IP → `429` with 60 from distinct IPs as the control; `issues.closed` answered `202` with no digest and `coord_delivery_ignored.issues` moved (AT-D2-26) |
| 3 | **Derivation** `App\Coord\Derive` — pure functions over `(issue, comment, labels, binding)` for the **round** alone: `openingPost()`, `commentPost()`, body `FROM:`/`TO:` lines, `to` with the label fallback, `targets` against the binding's set, `carrier`, `[CLOSE]` anchored, `App\Support\Slug::AGENT_NAME` validation, the `coord.subject` profile is **not** needed (no subject crosses), `scope` and `install_id` from the binding | **AT-24**'s round half (the comment's `from` from its body; plant the `from:` label), **AT-25**'s `to` fallback half, **AT-26**'s per-post half; a fleet binding yields `scope: "fleet"`, `install_id: null` (plant: copy the first install) |
| 4 | **Receipt write** `App\Coord\Receipt` — one `Outbox::transaction(…, attempts: 3)`: the digest `ON DUPLICATE KEY UPDATE` with its affected count, the `CoordRound` enqueue(s) (`installId()` null for fleet scope), `App\Coord\StoreFault` carrying SQLSTATE + digest only | **AT-D2-27** atomicity and the concurrent pair (plant: a plain insert behind the pre-check → a `503` on `1062` and bindings in the log); **AT-D2-28** fleet scope end to end (plant: borrow the first install) |
| 5 | **The roundtable-name declaration** ([§ 3.4](#34-the-roundtable-name-declaration-q2)): `fleet-reporter.js` reads `roundtable_agent_name`, checks it against `cross_project.identity`, emits both heartbeat members and the two counters, adds the `selftest` member and re-derives D1 § 6.14's capped bound; `KindRegistry`, `Projector`, `SeatFacts`, `SeatDelta`'s map, the migration (two `seat_state` columns), `SeatObject` | the reporter selftest's four-state cases, each red first; `SeatObjectMatchesTheDocumentTest`'s delta-map leg reds on a version-bearing member the map lacks; `verify-event-schema.py` green on the re-derived bound |
| 6 | **The join's fleet arm** in `floor/coord-join.js` (`buildJoin` over every install's seats, keyed by `roundtable_agent_name`, duplicates counted across installs), selected by `scope` in `coord-model.js` | the join test extended with **synthetic** names (r1 F4): a fleet-scope post naming `pm-solo` resolves to the seat declaring it in install `aimla` and not to the seat declaring `pm` (plant: fall through to the install arm); two installs declaring `coder-solo` → `duplicate_declaration` (plant: last writer wins) |
| 7 | **Counters**: the eleven in `global_counters` (`coord_delivery_received.<hook>`, r1 F13); `App\Read\FleetHealth::COUNTERS` gains them, the `<hook>` family enumerated from `coord_hooks` | `FleetHealthCountersMatchTheDocumentTest` reds when a § 7.1 fleet-health counter is missing from the constant; `verify-fleet-state.py` G8 is the doc-side half |
| 8 | **Client**: remove `#holdCoord`, `COORD_CAP`'s eviction and `coordModel`'s thread assembly; add the `post_ref` memory (1,000) and the event-log line for an unresolved end; A19/A20 triggers read `scope`; the cross-floor form A22 drawn per [§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does); **A18 removed from D3 § 6.2 and `wire/animation-set.js` in the same commit, A22 added to both in the same commit**; AT-D3-18's line assertions removed; `CoordDrawsNoLineWithoutAJoinTest` retargeted to *no bird without a join*; the drawing itself per card#12241's proposal | **AT-D3-29** through `coord-probe.mjs` and the fleet-client harness (its four REDs); `TheAnimationSetIsTheDocumentsClosedSetTest` green only when the table and the module moved together (it reds on either half alone — the control); `NoAnimationFiresWithoutItsEventTest` green with A18 gone |
| 9 | **Doc-sync in the same PR**: README § operating (hook issue runbook, the two declarations), CHANGELOG bullet, `tools/design/README.md` D5 row, the verifier runs, D1 § 6.14's selftest member, D3 § 6.2's A18 removal and A22 row, D3 § 5.7's reserved-row sentence | the four verifiers + `verify-design-docs.selftest.py` green at the head; the fixture guard green |

**Acceptance on the sandbox** (the sandbox is this seat's to operate; nothing below touches `main` or
production; every outward act is marked and waits on its question):

1. Deploy the build to the sandbox; `php artisan migrate` adds the three tables and the two columns;
   `GET /api/fleet/health` carries every new counter at `0`.
2. This seat's reporter: `roundtable_agent_name: "mezzanine-solo"` in `~/.config/fleet-reporter/config.json`,
   artifact replaced, flusher restarted; the next heartbeat's seat object carries the name with `checked`.
3. **Replay, both scopes**: `tests/roundtrip/coord-roundtrip.py` signs the synthetic fixtures under a
   sandbox `install` binding and under a sandbox `fleet` binding and posts them; `bin/feed-stream-check.sh`
   shows `coord.round` on the stream with and without `install_id`, and **no** `coord.thread`; the
   sandbox store gained digest rows and nothing else. Because the fixtures are synthetic, their names
   resolve to no sandbox seat: this step shows the feed and the store, not a bird, and says so.
4. **Live, the roundtable — the broadcast** (after NQ1 and the fleet's agreement — outward act): a
   `fleet` binding is issued on the sandbox and the hook registered; GitHub's *ping* is `202`, counted
   `coord_delivery_ignored.ping`. This seat's own next `to:all` post on the roundtable fires **A20** from
   its desk on the sandbox floor without a page reload; `coord_delivery_received.<hook>` moves. A direct
   post to or from kanban-solo fires **nothing** at this desk until step 5 — A19 and A22 both need the
   far end to resolve, and kanban-solo's seat declares no roundtable name yet (r1 F6).
5. **Live, the traveller** (after NQ2): once kanban-solo's seat declares its roundtable name and runs the
   reporter that sends it, this seat's next direct post to kanban-solo fires A22 at this desk (toward
   the elevator) and, on the `kanban` floor, A22 arriving; after NQ3 composes the two rooms onto one
   floor, the same post fires A19 desk to desk.
6. Redeliver one delivery from GitHub's list: `coord_delivery_duplicate` moves, nothing flies.
7. The `install` scope has no live repository on this fleet; step 3 is its acceptance (NQ4).

**`Built:` for the build PR** is recorded on the card at each dispatch, per `built-line.md`.

---

## 10. Operator questions

**Answered 2026-10-23** — Q1 *(a) with per-install support kept*, Q2 *yes*, Q3 *yes, synthetic only with
a mechanical guard*, the render scope *message animations only, the bird carrying the envelope*; Q4
ruled by the seat. **Open questions, each with context, an example per option, and a recommendation:**

**NQ1 — Registering Mezzanine's hook on `PupFuzz/agent-roundtable`: what the fleet is asked to accept,
and who acts.** Context (r1 F14): the roundtable is a **shared private repository** of four fleets.
Under this design Mezzanine would receive every delivery and retain, of other fleets' threads,
**a 64-byte digest per delivery for 14 days, and nothing else** — no subject, no body, no membership,
no timing beyond the digest row's own receipt stamp; and it would put on its feed, **transiently (60 s
in `feed_outbox`) and to every signed-in viewer of this Mezzanine**, each post's addressing — the
sender's and recipients' roundtable identities, the thread reference and the post reference. That is
less than the first draft would have held, and still other fleets' coordination metadata on a server
they do not operate; it needs their agreement, not only the operator's. Registering is also an outward
act with an admin credential this seat holds.
- *(a) Announce first, then register on the sandbox.* Example: an `[ANNOUNCE]`/`[FR]` from
  `mezzanine-solo` to `sola-pm` and `all` stating exactly the paragraph above and the hook's URL
  pattern; on no objection within the roundtable's follow-up window, this seat registers the hook
  pointed at the sandbox. Cost: a wait of days; a fleet may decline, in which case the roundtable is
  not bound and the install scope carries the design until a PM-and-impl install reports here.
- *(b) Register on the sandbox now and announce after.* Example: the hook appears as a fifth consumer
  today; the fleet learns of it from the post. Cost: the fleet's metadata reaches a server it did not
  agree to before it could object — the wrong order for an outward act on a shared surface.
- *(c) Announce, and offer a narrower binding.* Example: the same post offering that Mezzanine's fleet
  binding carry `from`/`to` identities only and drop `thread_ref`/`post_ref` to opaque digests on the
  feed. Cost: `post_ref` is the client's dedupe key; it would become a hash, which works, and the
  event log would name no thread, which is a loss the viewer would notice.
- **Recommendation: (a)**, recorded on the card as the operator's authorization for that one hook on
  the sandbox URL; production's hook is a separate act when production exists.

**NQ2 — kanban-solo's end of the traveller.** Context: the join resolves a roundtable name only to a
seat that **declares** it; kanban-solo's seat config and reporter are kanban-solo's to change. Until it
declares, a direct post between the two seats fires **nothing at this desk** (r1 F6): A19 and A22 both
need the far end to resolve to a desk, and an undeclared far end resolves to nothing — the honest
render is no bird, and the event log line names the unresolved end. Broadcasts from this seat still ring.
- *(a) Ask kanban-solo, on the roundtable, to add `roundtable_agent_name: "kanban-solo"` and take the
  reporter release that carries the field.* Example: an `[FR]` from `mezzanine-solo` to `kanban-solo`
  after the build ships, which can ride NQ1's announcement. Cost: a post and a wait.
- *(b) Accept no traveller between the two for now.* Example: only this seat's broadcasts animate.
  Cost: the operator's stated want — the bird between the two — is not visible until (a) happens anyway.
- **Recommendation: (a)**, with NQ1's post.

**NQ3 — Compose the `kanban` room onto the *Mezzanine* floor?** Context: the sandbox layout holds one
authored floor with one room ([§ 2.1](#21-installs-and-seats-on-the-sandbox)). With the rooms on
separate floors a post between the two seats fires the cross-floor form at each end (the bird leaving
toward the elevator, arriving from it); with the rooms composed onto one floor it fires the desk-to-desk
bird. An admin-console act, the operator's.
- *(a) Compose them now.* Example: the first live traveller flies desk to desk across the two rooms.
  Cost: the cross-floor form is unexercised on the sandbox until a third room exists.
- *(b) Leave them apart until the cross-floor form is seen, then compose.* Example: acceptance step 5
  shows the elevator form first, then the desk-to-desk bird. Cost: two acceptance passes.
- **Recommendation: (b)** — both forms are the product, and the sandbox is the one place both can be
  seen before production.

**NQ4 — The `install` scope has no live repository here.** Context: by Q1 it is built and replay-tested;
no PM-and-impl install reports to this Mezzanine, so step 7 of the acceptance is a replay.
- *(a) Accept the install scope by replay now; a multi-agent install's onboarding is a separate operator
  act when one wants a floor here.* Cost: the first live install-scope post is the first time the scope
  meets real traffic.
- *(b) Onboard one of the fleet's multi-agent installs to the sandbox first.* Example: an install whose
  bridge already hooks the roundtable registers a second hook for its own coordination repository
  pointed at the sandbox, and its seats add `sites[]` entries (card#12180). Cost: another fleet's
  operator act, and credentials for its seats on this Mezzanine.
- **Recommendation: (a)**; (b) is a fleet question.

**NQ5 — The existing references in docs and tooling (r1 F5), pending.** Context: real roundtable thread
numbers already appear in public docs and tooling, and D2 § 3.1 quotes a roundtable maxim verbatim with
its issue link. The guard's docs leg R8 is designed to apply to **new** content over a baseline
exemption list the operator can shrink; nothing on this branch edits an existing occurrence. The
dispatching seat has put the question to the operator; this document records it as open and takes
whichever answer comes — scrub (the list shrinks to empty), keep (the list stands), or narrow (the
patterns change) — as a change to the exemption file and nothing else.

---

## 11. What this design does not establish

| Not established | Cost if wrong | Closing act |
|---|---|---|
| **the `issues.opened` payload shape by capture** — DOCS-CITED only; the comment delivery is observed | the opening post's derivation reads `null` on a key the documentation names and the delivery omits | the first live `opened` on the roundtable binding, read through the capture tool into `~/.cache` and compared by key, never vendored |
| **what a per-install ACL on the stream does with a fleet-scope post** — D2 § 14 item 7 says fleet-read is all-or-nothing for now | a client scoped to one install would, under a naive filter, lose every roundtable bird or see all of them | decided when that ACL is designed: the honest default is that a fleet-scope post is visible to a reader who may see **any** of its resolved ends' installs |
| **that `cross_project.identity` is where every seat's roundtable name lives** — true of this install's config; the framework owns the key | a seat on a differently-shaped config checks against nothing and reports `unchecked` — a supported state | carried to the framework's owner with D1 § 3.1's existing resolution-order contract |
| **what a redelivered payload is, byte for byte** — GitHub's documentation is silent | a redelivery outside the digest's absorption derives twice; the client's `post_ref` memory absorbs it inside 1,000 posts | observe one Redeliver on the sandbox (acceptance step 6) |
| **the `coord_delivery_received.<hook>` family's member set on `GET /api/fleet/health`** is the set of bindings at request time — a revoked binding's member stays | a stale member reads `0`-moving forever | stated, not closed: D2 § 7.2's never-reset rule applies |
| **that the guard's hashed deny list names every real identifier** | a real name under a spelling the list lacks passes by hash; R5's `[synthetic]` token is the independent leg | the plaintext list is the operator's; a fleet change re-opens it |
| **the seat object's worst-case headroom is 15 bytes** — the two declaration members move D2 § 8.2.1's worst-case delta to 6,862 B, so D3 § 8.1's intern-cap arithmetic puts the worst case at a cap of 13 at **8,177 B against the 8,192 B bound** (re-derived by both gates on this revision; r1 verified it as real headroom, not a latent defect at cap 8) | the next nullable member anyone adds to the seat object breaks D3 § 8.1's *could reach 13* arithmetic at once | stated here so the next card that touches the seat object reads it before its own design review |
| **the fleet's agreement to the roundtable binding** | the hook is registered on a shared repository whose other users did not agree | NQ1's announcement, before any registration |

---

## 12. The amendment ledger

Every edit this slice makes to a ratified document, with the claim it changes and why. Each is a diff
to the owning document; none is a parallel restatement. Entries superseded within the card's own
rounds are marked.

| Doc · section | What changed | Why |
|---|---|---|
| D1 § 18.8 (A1) | the route is `POST /api/ingest/github/{hook}`; an unknown `{hook}` is a step-3 `401`; a binding has a `scope`; step 8 derives from `issues.opened` and `issue_comment.created` only | [§ 3.1](#31-the-binding-its-scope-and-why-it-is-selected-by-a-path-segment); the render-scope ruling |
| D1 § 18.8 step 7 (A2) | *"this document mints no retention number"* points at D2 § 6.7's 14 days | the digest store exists |
| D1 § 18.6 / § 18.7 (A3) | both field tables gain `scope` and a nullable `install_id`; § 18.6 is marked specified-and-not-published; the r1-era comment-carried triggers are withdrawn | the operator's Q1 and the render-scope ruling |
| D1 § 12.7 (A4) | the eleven coordination counters are enrolled by pointer rows | the plane that stores them now exists |
| D1 § 18.13 (A5) | rows 1–5, 8, 9 updated with the observed delivery, GitHub's documented facts and the two closures; row 2's hazard noted as having no consumer | the rows' own closing acts |
| D1 § 18.2 (A6) | the bridge checkout row notes the re-read at v0.106.0 | SOURCE-READ's axis is the revision |
| D1 § 15 (A7) | decisions 55 (the selector), 56 (the scope), 57 (`coord.thread` not published) | decisions taken |
| D1 § 3.1 / § 6.0 / § 6.14 / § 9.3 / § 18.3.1 / § 18.11 / § 18.12 (A8) | the `roundtable_agent_name` declaration end to end; the install-facts input per scope; the join's consumer named as the traveller | the operator's Q2 and Q1 |
| D2 § 2.1 / § 2.2 (B1) | the **coordination receipt** process row and its fail posture | add a process, add a row |
| D2 § 6.4 (B2) | three tables (bindings with `scope`, secrets, digests) | the store |
| D2 § 6.7 (B3) | the digest retention row and chain | retention stated where retention lives |
| D2 § 6.12 (B4) | **new** — the store's rules: the write path and its concurrency primitive, the fault discipline, what is deliberately not held | the owning document gains the section the slice owes; r1 F3 |
| D2 § 7.1 / § 8.2.4 (B5) | eleven rows (`coord_delivery_received.<hook>`), the fleet-health list, the two counts | counter closure; r1 F13 |
| D2 § 8.2 (B6) | the *no fifth endpoint* paragraph records the first draft's endpoint as withdrawn | the render-scope ruling |
| D2 § 8.3 / § 8.3.3 / § 8.4 (B7) | `coord.thread` marked not published; `scope` on both objects and `install_id` nullable; the fleet arm of the join; the no-snapshot paragraphs restored on the new reason | the ruling; the operator's Q1 and Q2 |
| D2 § 12 / § 13 / § 14 / AT / App. B (B8) | numbers; decisions 52–58 (54, 55, 58 withdrawn); item 14 closed; AT-D2-26…29; build rows 12–13 | bookkeeping the design owes |
| D2 § 6.4 / § 6.5 / § 8.2.1 (B9) | `roundtable_agent_name` and its check: two columns, the version-bearing row, two seat-object rows; the worked examples' size figures re-measured | the operator's Q2 |
| D3 § 2.2 (C1) | the r1-era steps 2c/3c/5c withdrawn; the list stands as before | no backfill |
| D3 § 4.6 / § 5.7 / § 6.2 / § 6.5 / § 12 / § 14 / AT (C2) | the between-rooms form made transient; § 5.7 rewritten as `coord.round`'s render map under the ruling; A18's trigger cell retired, A19/A20's triggers read `scope`; § 12's envelope hold repurposed as the `post_ref` memory; items 24, 25, 40; AT-D3-18 annotated, AT-D3-29 rewritten | the render-scope ruling; the suite's two-way guard on § 6.2 |
| D3 § 5.6 (C3) | null-render rows for the two declaration members | G10 |
| `tools/design/README.md` | D5 declared under no gate, as D4 is | a document under no gate says so |

---

## 13. Design review r1 — disposition per finding

Reviewer's report: `~/.cache/coord/card-12239/review-r1.md`, on `2a46dcc4` + `cb5485c7`. The operator's
render-scope ruling arrived between r1 and this revision; several findings lose their subject to it and
are dispositioned as such rather than folded into machinery that no longer exists.

| # | Severity | Disposition | Where |
|---|---|---|---|
| F1 | BLOCKER | **Moot by ruling.** Its subject — a held thread line surviving eviction on a long-lived page, and membership refresh feeding it — is a held render; none is held now. Nothing of (a) or (b) is built. The lesson is recorded: a held coordination render needs state outside the envelope cap, which is why the first draft's design is withdrawn whole rather than trimmed | D2 § 13 rows 53, 58; [§ 4](#4-what-must-still-be-carried-and-what-goes) |
| F2 | MAJOR | **Moot by ruling** — no lifecycle, no membership, no `state_at`, no stale/duplicate counters on a thread. The one surviving piece — a post must not be counted twice — is the digest's `ON DUPLICATE KEY` write | D2 § 6.12 |
| F3 | MAJOR | **Folded.** The primitive is named (`INSERT … ON DUPLICATE KEY UPDATE` on the digest with the affected count read; `Outbox::transaction(…, attempts: 3)`); two distinct deliveries share no row; the concurrent-pair test is in AT-D2-27; `App\Coord\StoreFault` carries SQLSTATE + digest only, and no `QueryException` with bindings reaches `storage/logs` | D2 § 6.12, AT-D2-27 |
| F4 | MAJOR | **Folded.** Three populations stated — P1 (every `fixtures/` directory under `server/tests/`, `fx-coord.json` included), P2 (every test source, inline literals included), P3 (docs); build step 6 uses synthetic names (`pm-solo`, `coder-solo`), added to R7's vocabulary | [§ 3.5](#35-synthetic-fixtures-mechanically-q3-f4-f5-f9f12); [§ 9](#9-implementation-plan) step 6 |
| F5 | MAJOR | **Folded as directed.** R8 applies to new content over a baseline exemption list written once from the tree; a stale exemption reds, so the list only shrinks; no existing occurrence is edited on this branch; the operator has been asked | [§ 3.5](#35-synthetic-fixtures-mechanically-q3-f4-f5-f9f12) R8; NQ5 |
| F6 | MAJOR | **Folded as directed.** The render rule is unchanged (an end must resolve); acceptance step 4 is the broadcast only and the direct post waits for NQ2; NQ2 (b)'s cost reads *nothing at this desk* | [§ 9](#9-implementation-plan) steps 4–5; NQ2 |
| F7 | MAJOR | **Moot by ruling** — no held lifecycle means no out-of-order residual; the § 11 row is gone | — |
| F8 | MINOR | **Folded.** D1 § 18.6's lifecycle row is true again under the ruling (the comment-carried triggers are withdrawn), and the row says the object is derived from none of the three today | D1 § 18.6 |
| F9 | MINOR | **Folded.** R6's digit rule exempts `post_ref`, `thread_ref`, `delivery_digest`, `digest`, `_provenance.shape_source` by name and ignores runs beginning `9000` | [§ 3.5](#35-synthetic-fixtures-mechanically-q3-f4-f5-f9f12) R6 |
| F10 | MINOR | **Folded.** One plant per predicate; the predicate counts are stated per rule and the selftest prints the total | [§ 3.5](#35-synthetic-fixtures-mechanically-q3-f4-f5-f9f12) |
| F11 | MINOR | **Folded.** The capture tool strips `X-Hub-Signature*` and its worktree refusal fails closed (writes only on the explicit not-a-repository outcome) | [§ 3.5](#35-synthetic-fixtures-mechanically-q3-f4-f5-f9f12) (2) |
| F12 | MINOR | **Folded.** The deny list is SHA-256 hashes of tokens, generated from an operator-held plaintext outside the repository | [§ 3.5](#35-synthetic-fixtures-mechanically-q3-f4-f5-f9f12) R6 |
| F13 | MINOR | **Folded.** `coord_delivery_received.<hook>` | D2 § 7.1, § 8.2.4; [§ 9](#9-implementation-plan) step 7 |
| F14 | MINOR | **Folded, and the cost re-derived under the ruling.** NQ1 states what Mezzanine retains of other fleets' threads (a digest for 14 days; transient addressing on the feed) and that the fleet's agreement is needed; the recommendation is an `[ANNOUNCE]`/`[FR]` to sola-pm before registering | NQ1; D2 § 6.12 |
| F15 | MINOR | **Moot by ruling** — nothing is held, so a deleted or transferred thread leaves nothing drawn; stated under *not defended against* | [§ 7](#7-failure-modes--what-the-floor-shows-and-what-recovers-it) |
| F16 | MINOR | **Folded.** `coord-hook:issue --hook` refuses a third unrevoked secret; AT-D2-29 | D2 § 6.12, AT-D2-29; [§ 9](#9-implementation-plan) step 1 |
| hygiene | — | **Noted.** The card comment's numbering swaps Q2/Q3; this document follows the operator's answers | [§ 2.4](#24-what-the-operator-decided-on-2026-10-23-verbatim-on-the-card) |

**Removed from the design by the ruling, for the record:** the thread line A18 (row retired in place,
removed with its module), its label, beads, carrier tag and ended treatment; the held call marker (r1's
A21); `coord.thread` as a published message; the thread/post store, its lifecycle and membership rules
and counters; the backfill endpoint and the client's envelope hold, caps and eviction; D2 § 8.4's
coordination steps and D3 § 2.2's steps 2c/3c/5c.

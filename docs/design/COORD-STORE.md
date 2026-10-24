# D5 — the coordination-post producer: receipt binding, the roundtable-name declaration, the message animations, and the build plan

**Status: DESIGN, revision r5 — folds design review r4's measured fixes exactly
([§ 16](#16-design-review-r4--disposition-per-finding)) and nothing else; r4 was run by the operator's
re-opening of 2026-10-23 after round 3 stopped under the stopping rule and folds reviews r1–r3
([§ 13](#13-design-review-r1--disposition-per-finding), [§ 14](#14-design-review-r2--disposition-per-finding),
[§ 15](#15-design-review-r3--disposition-per-finding)) and the operator's answers of the same day. The build awaits the operator's go on the questions in
[§ 10](#10-operator-questions). Nothing in this document is built. Rebased onto `origin/dev` at
card#12212 (no conflicts).**

> **What this document is, and what it is not.** D1 § 18 owns the coordination-event **producer** — the
> receipt route's authentication, validation order and the derivation of `coord.thread` / `coord.round`
> from a GitHub delivery. D2 § 8.3.3 owns the objects' **read surface** on the feed, and D3 § 5.7 owns
> their **render**. All three were ratified and all three left one slice to *"the slice that designs the
> coordination store"* ([D1 § 18.13](EVENT-SCHEMA.md#1813-what-this-section-does-not-establish) rows 8–9,
> [D2 § 14](FLEET-STATE.md#14-open-questions-for-the-review-loop) item 14). **This is that slice**, and by
> the operator's answers it also carries card#12240's between-rooms render — transient, through the
> floor's bird portal — and the seat's roundtable-name declaration. It owns what no ratified document
> owns: the hook **binding** and its provisioning, the fleet-specific findings, the fixture generator and
> the narrow lint, the failure-mode table, the implementation plan, the sandbox acceptance and the
> operator questions. It **amends** D1, D2 and D3 in place wherever the design changes a claim they
> publish ([§ 12](#12-the-amendment-ledger)). The store's rules live in **[D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)**
> and the render rules in **[D3 § 5.7](FLOOR.md#57-the-coordination-thread-line)**; this document points
> at them and does not restate them. The drawing — the bird, the envelope, the birdhouse — is
> card#12241's designer's, and nothing here draws it.
>
> ⛔ **No real roundtable reference and no live roundtable identity appears in this document** (operator,
> 2026-10-23; r3 MINOR-8): the cross-install repository is written `<roundtable repository>`, this
> fleet's two seats are written `<this seat>` and `<the kanban seat>` where their roundtable identities
> would otherwise appear, every example uses the generator spec's synthetic names (`pm`, `pm-solo`,
> `coder`, `coder-solo`, `helper`, `reviewer`), and the real binding lives only in the store and the
> operator's config. card#12260 scrubs the repository's existing references; its predicate does not
> cover identities, which is why this document holds the identity rule on its own.

---

## 0. Overview

1. **The operator's rulings of 2026-10-23 are the scope** ([§ 2.4](#24-what-the-operator-decided-on-2026-10-23-verbatim-on-the-card)):
   message animations only, nothing held — *"I choose the bird carrying the envelope"*; a `to:all` post
   is the broadcast ring and nothing else — no traveller, no portal departure; **a message from an agent
   not housed in this building draws nothing**; a post between two seats of this building on different
   floors flies through the floor's **bird portal — the birdhouse on a bracket, to the right of the
   clock** (card#12241 places and draws it); reduced motion is a still envelope that disappears after a
   time. The thread line (A18), its label, beads, carrier tag and *ended* treatment, and the held call
   marker r1 called A21, are **removed from the design**.
2. **Re-derived from that, the producer, store, feed and client shrink** ([§ 4](#4-what-must-still-be-carried-and-what-goes)):
   one object crosses to the floor, `coord.round`; `coord.thread` is specified and **not published**;
   the store holds bindings, secrets and — per derived delivery — one row of four columns (a row id, the
   binding, the digest, the receipt time); there is **no backfill**; the client holds nothing but the
   identities it has drawn.
3. **The producer is Mezzanine's own GitHub webhook receipt, per D1 § 18.1** — not the bridge. **One
   hook binding per repository, with a `scope`**: `install` (a room's own coordination repository) or
   `fleet` (the `<roundtable repository>`, whose threads span rooms). **The first live binding is the
   `<roundtable repository>` at `fleet` scope.**
4. **A seat may declare its roundtable name** beside its protocol agent name — `roundtable_agent_name`,
   checked against the same config's `cross_project.identity` — and a fleet-scope post joins a name to a
   desk through that declaration and nothing else ([§ 3.4](#34-the-roundtable-name-declaration-q2)).
5. **Generation is the guard, and the lint is narrow** ([§ 3.5](#35-synthetic-fixtures-mechanically)):
   every coordination fixture is produced by a deterministic synthesizer from one small committed spec;
   CI regenerates and byte-compares; a lint reds only on the unmistakable signs of a captured real
   message — real delivery headers and `FROM:` / `TO:` header lines — and its predicate was run over
   `origin/dev` as part of this design: three hits, all the PR-body attribution rule's own material,
   exempted by path with their reason.
6. **The hook secret never passes through a seat command's stdout or argv** ([§ 3.2](#32-provisioning--one-operator-terminal-act)):
   issue and register are operator-terminal acts; the secret is written to a 0600 file and read by
   `gh api --input`.
7. ⛔ **On this fleet the per-install population of coordination repositories is EMPTY**; the
   `install` scope is designed, built and replay-tested all the same, by the operator's answer that it
   must be supported ([§ 2.2](#22-the-coordination-repository-population-on-this-fleet)).

---

## 1. The problem, in the product's terms

The operator's request (card#12239, 2026-10-23): *when one agent posts to another, an envelope travels
from the sender's desk to the receiver's … and a message to everyone sends a ring out from the sender*;
then the rulings above. The floor can draw the two surviving animations — D3 § 6.2 rows A19 and A20 are
decided by `server/public/js/coord/coord-model.js`'s `roundAnimations()` and exercised through
AT-D3-18's harness (`server/tests/Feature/Floor/DrivesTheFloorScreen.php`) — and the server has never
sent it one message to draw. The operator's other answers add: the first messages to animate are the
roundtable's, between a PM and a solo agent in different rooms; messages inside one room (a PM and its
impl agents on their own coordination repository) must be served by the same producer, not deferred.

**Evidence, read on `dev` (2026-10-23; the branch is since rebased twice, onto card#12187 and
card#12212, neither of which touched these):**

| Claim | How it was read |
|---|---|
| No route receives a GitHub delivery | `server/routes/ingest.php` declares `/api/ingest/events` and `/api/ingest/health` and nothing else; `grep -rn -i github server/routes server/app` → 0 hits outside docblocks |
| No table holds a coordination fact | `ls server/database/migrations` — no migration names one; `SHOW TABLES` on the sandbox store (read-only) lists 31 tables, none coordination-named |
| The feed can carry the objects but nothing writes them | `feed_outbox.t` ENUM carries `coord.thread`, `coord.round`; on the sandbox `SELECT t, COUNT(*) FROM feed_outbox GROUP BY t` returns `seat.delta` and `feed.heartbeat` only |
| The client holds and renders them | `fleet-client.js` dispatches both types to `#holdCoord`; `floor-screen.js` draws per room — the hold and the line it serves are what the build removes |
| The suite holds D3 § 6.2 and the animation module equal | `server/tests/Feature/Floor/ReadsTheAnimationTable.php` walks the table by its header; `TheAnimationSetIsTheDocumentsClosedSetTest.php` set-differences it against `wire/animation-set.js` **in both directions**, on the Animation and Reduced-motion cells — a design-only PR can neither add nor remove a row, nor change those two cells |

---

## 2. The population this design must cover — and what it found

### 2.1 Installs and seats on the sandbox

Read from the sandbox store on 2026-10-23 (read-only, credentials never printed). Seat ids are written
as placeholders because each equals a live roundtable identity:

| install_id | seat | `protocol_agent_name` | check | role |
|---|---|---|---|---|
| `kanban` | `<the kanban seat>` | `kanban` | `checked` | `solo` |
| `mezzanine` | `<this seat>` | `mezzanine` | `checked` | `solo` |

Both seats **declare** a name and both declarations are `checked` — card#9296's server half and
card#9375's reporter half are live on this fleet. The sandbox's building layout (`building_layout.document`,
read-only) composes **one** floor, *Mezzanine*, holding the `mezzanine` room alone; the `kanban` room is
on no authored floor and takes a floor of its own (D3 § 4.6). So on the sandbox as laid out today the
two seats are on **different floors**, which is the portal case of [§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does)
once both declare, and the desk-to-desk bird is one admin-console act away ([§ 10](#10-operator-questions) NQ3).

### 2.2 The coordination-repository population on this fleet

**Zero per-install coordination repositories.** `~/.config/coord/coordination.config.json` on this box
has `coordination_repo: null` and a one-member roster (`mezzanine`, role `solo`); the `kanban` install is
the other solo seat's and is the same shape. A solo install has no coordination repo by the framework's
own definition (this repository's `CLAUDE.md` § Explicitly absent). The `install`-scope binding
therefore has **nothing to bind on this fleet today**; it is designed, built and tested by replay all the
same.

**One cross-install coordination surface: the `<roundtable repository>`** (named in this seat's
`cross_project.repo`; private, shared by more than one fleet). Read via the roundtable credential this
seat holds (`~/.config/coord/credentials.ini`, the token passed to `gh` by environment and never
printed; `xproj-gh` is the same read-only route), 2026-10-23. **Shapes only** — r4 MINOR-5: the
repository is private and shared, so its activity figures (thread and comment counts and rates, the
busiest threads, the number of identities and of registered hooks, who holds which permission) are
**on the card** (card#12239, the r5 comment), where its design was argued, and not in this public
repository:

```
from:/to: label vocabulary: identities of the form <install>-pm / <install>-solo, plus to:all
body form                 : every sampled issue and comment opens with a FROM: line and a TO: line
title form                : `[PREFIX] from:x, to:y — subject` on every sampled issue
hooks                     : each fleet's bridge registers its own, subscribed to issues + issue_comment
```

Three consequences, each load-bearing:

1. **The roundtable is the between-rooms case, and by the operator's answer it is the FIRST case.** Its
   participants are several installs' PM/solo seats, two of them housed in this building. A post between
   this building's two seats spans two rooms and, today, two floors; a post from or to a PM this building
   does not house has an end that resolves to no desk — and by the operator's answer of this round it
   **draws nothing** on any floor here ([§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does)).
2. **The roundtable identities are not the declared names.** The seat declares its protocol agent name;
   it posts on the roundtable under `cross_project.identity`, a different string from a different key
   of the same config. D2 § 8.3.3 rule 3 forbids any fallback join. The operator's answer to Q2 is the
   second declaration ([§ 3.4](#34-the-roundtable-name-declaration-q2)).
3. **Most roundtable posts are between seats this building does not house** (the share is on the
   card); on this fleet's floor a bird is therefore the exception, not the rule, and an unwatched
   floor misses the ones there are — by the ruling, a message is an animation and an animation is not
   replayed.

**What a real delivery contains — observed, not vendored.** The hook the sandbox bridge registered on
the `<roundtable repository>` exposes its recent deliveries through GitHub's deliveries API to a
credential that may read the hook. Read 2026-10-23, keys and sizes only (the page's event mix and
response codes are on the card with the other activity figures) — the capture tool of
[§ 3.5](#35-synthetic-fixtures-mechanically) is the only sanctioned way to read one in future, and it
writes outside the repository:

```
one page of recent deliveries: issue_comment.created, issues.labeled, issues.unlabeled; redelivery flag false
headers: Content-Type, User-Agent, and GitHub's delivery, event, hook-id, installation-target and
         two signature headers
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
the fleet binding is where it is observed.

### 2.3 GitHub's side of the hop, read at the vendor's documentation

Read 2026-10-23 at docs.github.com (DOCS-CITED in D1 § 18.2's sense); each closes or bounds a D1 § 18.13
row:

| Fact | Source sentence | Consequence here |
|---|---|---|
| **No automatic retry** | *"GitHub does not automatically redeliver failed deliveries."* | a refused or failed delivery is lost unless an operator redelivers it; the receipt's write must be cheap and must not refuse what it can store |
| **10 s response timeout** | *"if your server is down or takes longer than 10 seconds to respond, GitHub will record the delivery as a failure"* | the receipt transaction's budget is 10 s minus transport; it takes no seat lock and is one insert and the outbox rows |
| **3-day redelivery window** | *"you can redeliver webhook deliveries from the past 3 days"* | the digest store's retention must exceed 3 days — it is **14 days** ([D2 § 6.7](FLEET-STATE.md#67-retention-and-purge)) |
| **25 MB payload cap** | *"If an event generates a larger payload, GitHub will not deliver a payload for that webhook event."* | D1 § 18.8's 1 MiB body cap is ~25× below the sender's; a whole observed delivery is ~14 KB |
| **Hook creation** | the REST reference states admin for deletion; creation is an act of the repository's administering credential, which the operator holds and this seat's tooling never does | registering Mezzanine's hook is an outward act on another fleet's repository, performed at the **operator's** terminal ([§ 3.2](#32-provisioning--one-operator-terminal-act), NQ1) |

### 2.4 What the operator decided on 2026-10-23, verbatim on the card

| Q | Answer | What it changes in this document |
|---|---|---|
| Q1 | *"(a) roundtable first, but messages between PM and impl agents must be supported too"* | the first live binding is the `<roundtable repository>` at `fleet` scope; card#12240's render is folded in; the `install` scope is built and replay-tested in the same slice |
| Q2 | *"yes"* | the optional `roundtable_agent_name` declaration end to end ([§ 3.4](#34-the-roundtable-name-declaration-q2)) |
| Q3 | *"Yes, you must be careful that no real messages enter the repo"* | synthetic fixtures only; generation is the guard, with a narrow lint ([§ 3.5](#35-synthetic-fixtures-mechanically)) |
| Q4 | seat ruling, reversible | hook secrets are encrypted at rest under `APP_KEY` |
| render scope | *"I do not want to show the open connection because there are too many open connections. I only want to show the message animations. I choose the bird carrying the envelope."* | every held coordination render is removed; the chain is re-derived from the per-message edges ([§ 4](#4-what-must-still-be-carried-and-what-goes), [§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does)) |
| NQ6 (a) broadcast | **confirmed**: the ring | A20 stands as the broadcast's render; r4: a `to:all` post sends **no** A19 traveller and **no** A22 departure (r3 MINOR-2 settled by the operator) |
| NQ6 (c) no desk | *"draw nothing"* | an end that resolves to no desk anchors nothing — and, by the r4 answer below, that includes every agent not housed in this building |
| NQ6 (b) cross-floor | *"Create a bird portal somewhere that is always open where birds can fly in and out of."* | A22 is the transient bird to or from the floor's bird portal |
| r4 (1), A | *"A"* — a roundtable message from an agent **not housed in this building draws nothing**; A22 is **only** for cross-floor messages between seats in this building whose **both** ends resolve | *"outside the building takes the portal"* — a seat reading r3 called MAJOR-1 — is removed everywhere; D3 § 5.7 property 1 and D2 § 8.3.3 rule 3 stand as they are; AT-D3-29 and A22's trigger are fixed ([§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does)) |
| r4 (2) | *"do as you recommended"* — keep the generator check; replace the broad lint with a narrow one | [§ 3.5](#35-synthetic-fixtures-mechanically): the predicate, its measured hits on `origin/dev`, the exemptions, and r3 MINOR-1's rule that helpers and Derive tests take spec keys |
| r4 (3) | the portal is **the birdhouse on a bracket, to the right of the clock**; card#12241's designer owns its placement and drawing | [§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does); D3 § 4.6 |
| reduced motion | *"a still envelope that disappears after some time"* | A22's reduced form now, and A19's with the build (its cell is held equal to the module) |
| NQ5 | *"No real roundtable references should be in repo"* | no issue number, link, quote, private repository name or live identity in this card's text; card#12260 scrubs the existing references and owns the repo-wide guard |

⚠ The card's own comment c13244 numbers the first three questions Q1/Q3/Q2; this document numbers them
as the operator answered them (r1 hygiene).

---

## 3. Where posts come from, and how they reach Mezzanine

### 3.1 The binding, its scope, and why it is selected by a path segment

D1 § 18.8 publishes `POST /api/ingest/github` and binds `install_id` to *"the secret the delivery
verified against — one secret per registered hook"*. With **more than one** hook registered, the request
carries nothing inside the HMAC that says which secret to try: GitHub's hook-id header is outside the
signature, and `repository.full_name` is inside the body, which D1's validation order parses at step 5,
two steps after the signature is verified. The three ways to pick a secret:

| Shape | Mechanism | Cost |
|---|---|---|
| **try every active secret** | HMAC the body once per binding | CPU linear in bindings on an unauthenticated 1 MiB body — the budget D1 § 18.8's step-3 limit exists to bound |
| **look the secret up by `repository.full_name`** | parse the body before verifying it | reorders D1's validation and routes a key lookup on an unverified claim |
| **a path segment names the binding** — `POST /api/ingest/github/{hook}` | `{hook}` is a public, random identifier issued with the binding; it selects the row whose secrets are tried, and the secret decides | one URL per binding; no parse before auth; an unknown `{hook}` is a step-3 refusal |

**Chosen: the path segment.** `{hook}` is `mzh_` + 22 base64url characters, a greppable prefix in the
family of `mzn_` / `mzr_`, **not a secret**, and nothing authenticates on it: an unknown `{hook}` is `401`
inside D1 § 18.8 step 3, counted `coord_signature_invalid`, in the same per-`(route, source IP)` bucket.
(Three reviews verified the selector, the bucket and AT-23.)

**The binding's `scope` — `install` or `fleet`** is set at issue, never inferred from a payload, and
decides:

| | `scope: install` | `scope: fleet` |
|---|---|---|
| the binding names | one `install_id` and one repository | one repository and **no** install |
| the object carries | `scope: "install"`, `install_id: "<the binding's>"` | `scope: "fleet"`, `install_id: null` — riding `feed_outbox`'s `NULL` for a fleet-wide message |
| the install-facts input (D1 § 18.3.1) | `shared_identity` — **no roster**: the roster member is specified and not provisioned, since its one consumer, the `all` expansion, is not derived (r4 MAJOR-2, fix (a)) | `shared_identity`, likewise — no copy of the roundtable's identity set is held |
| `targets` for `to: all` | `null` — the expansion is **specified, not derived** (D1 § 18.7): a broadcast is the ring and enumerates no destination | `null`, the same rule |
| the floor joins a name through | `protocol_agent_name`, within the install (D2 § 8.3.3 rule 1) | `roundtable_agent_name`, across every install ([§ 3.4](#34-the-roundtable-name-declaration-q2)) |
| the render | A19/A20 in the one room (D3 § 5.7 property 3) | A19/A20 where the ends resolve on one floor; A22 through the viewed floor's portal where both ends resolve to desks of this building on different floors; nothing for an end that resolves to no desk ([§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does)) |

D2 § 9's per-subscriber filter admits everything today; what a future per-install ACL does with a
fleet-scope post is named at [§ 11](#11-what-this-design-does-not-establish).

### 3.2 Provisioning — one operator-terminal act

Issuing a binding and registering its hook is an **operator-terminal act**, and the secret never passes
through a seat command's stdout or argv (r2 MAJOR-5; r3 MINOR-7 corrects the stronger claim this section
made — a seat with `APP_KEY` and the database could decrypt any secret the store holds, so what this
discipline buys is **transcript and argv hygiene**, not secrecy from the seat). At the operator's own
terminal:

```
php artisan mezzanine:coord-hook:issue <repository> --scope=fleet   --shared-identity=<true|false> \
    --by=<who> --secret-out <path> --hook-request-out <path>
php artisan mezzanine:coord-hook:issue <repository> --scope=install --install=<install_id> …   (same options)
gh api repos/<owner>/<repo>/hooks --input <hook-request path>          # the operator's admin credential
```

`issue` creates, in one transaction, the binding row (`coord_hooks`: scope, install or none, repository,
`shared_identity` — and no roster, r4 MAJOR-2) and one secret row (`coord_hook_secrets`, ciphertext at rest under the application key — an HMAC key
must be recoverable; **seat ruling on Q4, reversible**). It prints **the URL and nothing secret**. The
secret goes to `--secret-out`, mode 0600, and the complete GitHub hook-creation body — URL, content
type, the `issues` + `issue_comment` subscription, the secret — to `--hook-request-out`, mode 0600, so
`gh api --input` reads it without the secret ever appearing in a command line; **both writers refuse a
path inside any git worktree, failing closed exactly as the capture tool does**. The runbook (README
§ operating, build step 9) says: run both commands yourself, then `shred` the two files.

`--hook=<mzh_…>` adds a **second** secret to an existing binding (rotation with overlap) and **refuses a
third while two are unrevoked** (r1 F16; AT-D2-29). **One unrevoked binding per repository, at any
scope** (r4 MINOR-2): `issue` refuses a repository that already has one — two bindings would be two
messages on the server for one post, and one bird on the client decided by arrival order — and the key
is `UNIQUE (repository, active_key)`, which counts only unrevoked bindings, so a binding **revoked** may
be re-issued for the same repository (r3 MINOR-4; D2 § 6.4). `mezzanine:coord-hook:revoke <mzh_…> [--secret=<prefix>]` revokes a secret or the binding.
Verification tries every unrevoked secret of the one binding, at most two. **Mezzanine holds no GitHub
credential for any of this.**

### 3.3 What crosses which boundary (canon #7)

| Hop | Guarantee | DECLARED by | CHECKED by | NAMED as unverifiable |
|---|---|---|---|---|
| GitHub → Mezzanine, the delivery | body bytes are the ones signed under the binding's secret; the event header names the event | GitHub's documented delivery contract ([§ 2.3](#23-githubs-side-of-the-hop-read-at-the-vendors-documentation)) | D1 § 18.8 steps 3–6, run by this route | whether a **redelivered** payload is byte-identical to the original; ordering is irrelevant now that each post is its own edge |
| seat declaration → coordination name, per scope | the name a seat declares equals the name it posts under on repositories of that scope | the seat (D1 § 3.1: `protocol_agent_name` for the install's repository, `roundtable_agent_name` for the fleet's) | the seat's own check — against the roster for the first, against `cross_project.identity` of the same config for the second | that a repository bound at `install` scope is the one the roster describes, and one bound at `fleet` scope is the one `cross_project.repo` names — two operator acts nothing compares |
| the other seat's declaration (`<the kanban seat>`) → this floor's join | that seat declares its roundtable name and runs a reporter that sends it | that seat's operator | that seat's own check | from here, nothing — a post to or from that seat animates nothing anywhere until its heartbeat carries the name (NQ2) |
| Mezzanine → the browser | the message, the feed | D2 § 8.3.3, § 8.3 | the client's dedupe by identity; AT-D3-29 | nothing new: one deployment, no skew axis (D2 § 8.1) |

### 3.4 The roundtable-name declaration (Q2)

**The gap.** `protocol_agent_name` is the name a seat posts under on its **own install's** coordination
repository, checked against that install's roster. On the roundtable the same seat posts under
`cross_project.identity` — in the spec's vocabulary, `pm-solo` beside `pm` — a different string from a
different key of the same `coordination.config.json`. A fleet-scope post names the second; no
declaration carries it; D2 § 8.3.3 rule 3 forbids guessing the first from the second.

**The declaration, end to end — the same shape as card#9296 / card#9375, one key over:**

| Surface | What changes | Owner |
|---|---|---|
| the seat config (D1 § 3.1) | one optional row, `roundtable_agent_name`, slug, bounded as the protocol agent name is | D1 amendment A8 |
| the check (D1 § 3.1) | at flusher start and on `selftest`, against **`cross_project.identity`** of the config `$COORD_CONFIG` resolves: `checked` when equal; `disagreed` when the config carries one and it differs; `unchecked` when no config is readable **or** it carries no `cross_project` block; `undeclared` when the key is absent, null or malformed | D1 amendment A8 |
| the heartbeat (D1 § 6.14) | `roundtable_agent_name` and `roundtable_agent_name_check`; the counters `roundtable_agent_name_unchecked` / `_disagreed` (§ 9.3) | D1 amendment A8 |
| the ingest registry, the fold, the store (D2 § 6.4), the seat object (D2 § 8.2.1), the delta map | two columns, two members, two entries in `SeatDelta`'s map, both version-bearing | D2 amendment B9; build step 5 |
| the join (D2 § 8.3.3 rule 1) | **a second arm, selected by the object's `scope`**: a fleet-scope name resolves to the ONE seat **of any install the client holds** whose `roundtable_agent_name` equals it with check **`checked` — and `checked` alone** (r4 MINOR-6: an `unchecked` roundtable name is a string any seat of any install can type with no roster on its box to refuse it, so resolving it would let one install claim another's identity; it still counts toward the duplicate test), provided no other seat of any install declares it in any state but `undeclared`. The install arm keeps `unchecked`, which can only claim a desk inside its own install. The arms never cross | D2 amendment B9 |
| the render (D3 § 5.7 property 1) | the resolve arm reads the arm the object's `scope` selects; an unresolved end gets no bird and is logged, *unchecked* rides the log line | D3 amendment C2 |
| the `selftest` member | `roundtable_agent_name_matches`, the sibling of `protocol_agent_name_in_roster` — added by the build (D1 § 6.14's capped `selftest` bound is re-derived by the gate) | build step 5 |

**The bound has one figure and pointers everywhere else** (r2 MINOR-7; r3 MINOR-6). D1 § 6.14's row
carries the figure; every other home says *the protocol agent name's bound*. How many homes there are is
not written here: `tools/design/verify-event-schema.py` prints them on every run under *protocol agent
name and role bound per home*, and that printed set — not a count in prose — is the population. **Since
r5 the gate already holds `roundtable_agent_name`'s document homes** (D1 § 6.14's row, D2 § 6.4's
`VARCHAR`, D2 § 8.2.1's row — r4 MINOR-3), so a figure edited in one of them reds today; the build's
step 5 adds its registry and migration homes to the same loop once they exist.

**What it does not do.** It does not join the two names to each other; a fleet-scope post written in the
install vocabulary (`FROM: pm` on the roundtable) resolves nothing. It is not an input to the token
binding, the character seed or `config_fingerprint`. Omitting it is a supported state: a post to or
from the seat on the roundtable animates nothing at its desk, which is exactly today's render.

### 3.5 Synthetic fixtures, mechanically

(Q3; r1 F4, F9–F12; r2 MAJOR-1, MAJOR-2; r3 MAJOR-2, MINOR-1; the operator's r4 answer (2): *"do as you
recommended"*.) **The rule:** no real coordination message — body, title, label vocabulary, thread
number, comment id, delivery id, login — enters this repository in a fixture or a test. The documents are
card#12260's.

**The principle: generation is the guard.** A fixture nobody can hand-write cannot carry pasted content.

1. **One small committed spec** — `server/tests/fixtures/coord/spec.json` — holds every synthetic
   value the fixtures may contain, enumerated, and **is the only list of them** (r4 MINOR-4: this
   document names none, so the two cannot drift): the agent names (install-vocabulary and
   roundtable-vocabulary names, `all`, and the unhoused name AT-D3-29's `F4` posts under), the synthetic
   installs and their seats — never a seat id that equals a live label — the repository (`synthetic-org/coordination-fixture`, owner `synthetic-org`,
   host `example.invalid`), the id bases (thread numbers from 900000, comment ids from 9 000 000 000 000,
   delivery GUIDs from a counter), the login `synthetic-user`, a dozen short synthetic sentences, the
   **body-line keys** a scenario may reference (`from-line`, `to-line`, `close-token`, `plain`), and the
   **scenarios** — each a list of posts by name, scope, `from`, `to`, which body-line keys and labels
   they carry, including today's `fx-coord`, `fx-elevator`'s A19 variant and `fx-refusals`' coordination
   content, and the receipt's delivery payloads for AT-23–AT-26. The spec is small enough to read whole
   in a review, and reviewed.
2. **One deterministic synthesizer** — `tools/coord/synthesize-fixtures.py` — reads the spec and
   writes **every** coordination fixture into `server/tests/fixtures/coord/generated/`: delivery
   payloads (full GitHub shape, keys and types as observed on a live hook, header arrays included), feed
   envelopes (`coord.round` objects), and the floor fixtures' coordination message arrays. No randomness,
   no clock, no network. Fixtures that are mostly not coordination (`fx-elevator`, `fx-refusals`)
   **reference** their coordination arrays by name (`"coord": {"generated": "<scenario>"}`) and the
   harness loads them from the generated directory.
3. **Helpers and derivation tests take spec KEYS, never free strings** (r3 MINOR-1 — the blind spot a
   lint over literals cannot close). `DrivesTheCoordClient::roundMessage()` and its kin load a
   generated base object and accept overrides whose values must be members of the spec's enumerations
   (a name from the names list, a body built from body-line keys); an override outside them **throws**,
   so a free string cannot enter a message through a helper. The `App\Coord\Derive` unit tests — the
   likeliest place for a pasted body — build their inputs the same way: `spec()->body('from-line',
   'to-line', 'plain')`, `spec()->title('BRIEF', <from-key>, <to-key>)`, never a heredoc. **The binding
   is mechanical, not a convention** (r4 MAJOR-3): `spec()` is the only `FROM:`/`TO:` writer outside the
   generated directory that carries an exemption, and the `Derive` test files are in the lint's
   population with none — so a heredoc body is the bare arm's hit and a one-line `"FROM: …"` literal
   is the quoted arm's; what neither arm reaches is named under *cannot catch*.
4. **CI regenerates and byte-compares** — `synthesize-fixtures.py --check` writes to a temp directory
   and diffs it against the committed `generated/` tree: any hand edit, any stale file, any file the
   spec no longer names, and any real content reds, because real content cannot come out of a generator
   whose only inputs are the spec's enumerated values.
5. **One NARROW lint** — `synthesize-fixtures.py --lint`. Population: every `.php`, `.js`, `.mjs`,
   `.py`, `.json`, `.txt`, `.md` and `.sh` file under `server/tests/`, `tools/`, `bin/` and
   `fleet-reporter/`, outside the generated directory and the spec — as a command, so the figure is
   re-printed and never restated (r4 MINOR-1):

   ```
   git ls-tree -r --name-only HEAD | grep -E '^(server/tests/|tools/|bin/|fleet-reporter/)' \
     | grep -E '\.(php|js|mjs|py|json|txt|md|sh)$'
   ```

   Predicate — the unmistakable signs of a captured real message, and nothing else, **pinned as three
   case-sensitive regular expressions** (a case-insensitive reading reds four more files for prose
   `from:`/`to:`, and the backtick before `FROM:` in `bin/pr-body-lint.py` L112 is what keeps that
   docstring out):

   | Rule | Regex (Python `re`, no `IGNORECASE`) | What it catches |
   |---|---|---|
   | `header` | `X-GitHub-Delivery\|X-Hub-Signature` | a real delivery header name, GitHub's delivery-id header or either signature header, anywhere in the file |
   | `bare` | `^(FROM\|TO):\s` with `MULTILINE` | a `FROM:` or `TO:` **header line** at the start of a line |
   | `quoted` | `(["']\|\\n\|\\r\\n)(FROM\|TO):\s` | the same line inside a quoted or escaped string — a one-line literal, or a capture's JSON body form (r4 MAJOR-3) |

   (In the table a `\|` is markdown's escape for a regex `|`.) No host, digit, name or `coord.*` sniffing. **Exemptions are by `(path, rule)`** in
   `tools/coord/lint-exemptions.txt` — one pair per line with a reason — so an exemption silences one
   rule in one file and never the others (r4 MAJOR-3); a pair whose file no longer matches that rule
   **reds** (the list only shrinks).

   **The predicate was run over `origin/dev` (`5c47001b`) as part of this design** — the command above
   with `origin/dev` for `HEAD` — and every hit is listed; none is coordination material and nothing
   migrates into the generator:

   | File | Rule | Match | Disposition |
   |---|---|---|---|
   | `bin/pr-body-lint.selftest.py` | `bare` | a planted PR body with a `FROM:` and a `TO:` line (L245–246) | **exempt** `(path, bare)` — the attribution-line rule's RED fixture |
   | `bin/pr-body-lint.selftest.py` | `quoted` | the same shape inside string literals (L551, L563, L581, L607, L613, L614, L909, L1101 — the pinned regex over `origin/dev:bin/pr-body-lint.selftest.py` prints them) | **exempt** `(path, quoted)` — the same fixture's one-line forms |
   | `bin/pr-body-lint-fixtures/fw891-v0.51.0-body-2026-09-10T140853Z.md.txt` | `bare` | a `FROM:` line (L1) | **exempt** `(path, bare)` — a recorded PR body the linter's fixtures hold |
   | `bin/change-pr-body.selftest.py` | `quoted` | a `"FROM: …"` literal (L239) | **exempt** `(path, quoted)` — the PR-body generator's attribution test; its `bare` rule stays live |
   | — | `header` | no file | — |

   `bin/pr-body-lint.py` L112 is **not** a hit under the pinned case-sensitive `bare` rule (a backtick
   precedes `FROM:`), and r4's reading that it was exempt is withdrawn with it. The reviewer's r3 hits
   (`At15BackpressureTest`, `At25ConcurrentWriterTest`, `VisiblePrefixTest`, `coord-cap-probe.mjs`) were
   hits of the **broad** lint's `coord.*` sniffing; the narrow predicate does not reach them, which is
   the point of narrowing. **Exemptions this design names for files the build adds**, each as the pair
   it needs and no wider: `tools/coord/synthesize-fixtures.py` (`header`, `bare`, `quoted` — it writes
   all three from the spec), `tools/coord/synthesize-fixtures.selftest.py` (the same three — it plants
   them), `tools/coord/capture-delivery.py` (`header` — it strips the signature headers by name),
   `server/tests/roundtrip/coord-roundtrip.py` (`header` — it signs). **The receipt tests under
   `server/tests/Feature/Coord/` carry no exemption** (r4 MAJOR-3): their header arrays are built from
   `spec()->headers(...)`, which the generated directory's delivery payloads already carry, so a header
   name typed into a test file is a hit and a defect. Every other file the build adds is in the
   population without exemption.
6. **The capture tool keeps two rules.** `tools/coord/capture-delivery.py` strips every signature header
   before writing, writes only under `${XDG_CACHE_HOME:-~/.cache}/coord/captures/` with mode 0600,
   prints key paths and sizes and no value, and **fails closed** on the worktree check — it writes only
   when `git -C <dir> rev-parse --is-inside-work-tree` exits 128 with the not-a-repository message;
   exit 0, any other error, or `git` missing refuses. A capture informs a human editing the **spec's
   shape**; nothing copies a capture's values anywhere.

**The selftest** plants: one edited byte in a generated file (→ `--check` red); one generated file
deleted and one added by hand (→ red, both directions); a `FROM:` header line in a temp PHP test, a one-line
`"TO: …"` literal in a second, and a signature header name in a temp JS test (→ `--lint` red, one per
rule); a stale `(path, rule)` pair, and a pair for a file that matches the other rule only (→ red); a helper override
with a name outside the spec (→ throws); a capture `--out` inside a temp git repository and one with
`git` absent (→ refused); a capture written with a signature header present in the source (→ absent in
the file). Each is seen RED, then the committed tree GREEN — and the committed tree's GREEN is a
measured one, because the predicate's hits over `origin/dev` are listed above and each is exempted by
name.

**What this cannot catch, named:** a real name, id or sentence **typed into the spec** by a human — the
spec is the one place content enters, which is why it is small, enumerated and reviewed; a pasted body
whose `FROM:`/`TO:` lines were stripped first; a literal whose `FROM:`/`TO:` token is split or
assembled by concatenation at runtime (`'FR' . 'OM: '`), which no regex over source reaches and which
`spec()`'s binding does not forbid in a `Derive` test that bypasses it (r4 MAJOR-3 — listed, not
closed); a test
that reads a capture from `~/.cache` by an absolute path; the captures themselves, outside the repository
and a disclosure if that directory is copied; and anything under `server/app`, `resources` or the
seeders, which the population deliberately omits because no fixture lives there — a coordination
literal in application code is a different defect, reviewed as code.

---

## 4. What must still be carried, and what goes

Re-derived from the render rule — *every coordination render is an edge fired by one `coord.round` on
arrival* — surface by surface:

| Surface | Needed | Not needed, and removed from the design |
|---|---|---|
| **the floor** | `coord_round.scope`, `install_id`, `post_ref` (draw once), `from` + `attribution` (origin), `to` (the ring's member), `targets` (destinations); the join through the seat objects' two declarations; a bounded memory of drawn `post_ref`s; the floor's portal — the birdhouse right of the clock | any `coord.thread` member; lifecycle; participants; subject; carrier; beads; `declares_close`; both timestamps as renders |
| **the feed** | `coord.round` | `coord.thread` — **specified and not published** (D2 § 8.3's row); the outbox ENUM member stays so publishing it again is code, not a migration |
| **the producer** | D1 § 18.7's derivation from `issues.opened` (the opening post) and `issue_comment.created`, PR comments included; `scope`/`install_id` from the binding; `to`'s fallback = the union of `from:`/`to:` labels less the author | D1 § 18.6's derivation — stands as specification, derived from nothing; `issues.closed`/`reopened` received and ignored (counted); the two participant counters, specified not derived |
| **the store** | `coord_hooks`, `coord_hook_secrets`, `coord_deliveries` — **four columns: a row id, the binding, the digest, the receipt time; `UNIQUE (hook_ref, digest)`** | `coord_threads`, `coord_posts`, any lifecycle or membership rule; the delivery GUID, the event and action names, `coord_hooks.last_delivery_at`; a global digest key (r3 MINOR-4; at r5 the reason is a re-issued binding: its first deliveries must not be absorbed by the revoked binding's digests) |
| **the backfill** | **none** — a snapshot never animates (D3 § 6.5) | `GET /api/fleet/coord`, the client's holds and caps |
| **the client** | the `post_ref` memory (1,000, D3 § 12); the fleet arm of `buildJoin`; A19/A20/A22 triggers reading `scope`; the event-log line for an unresolved end | `#holdCoord`, `COORD_CAP`'s eviction, `coordModel`'s threads, `coord-members.js`'s thread member set, A18, the held call marker |

The receipt's write path, concurrency primitive and fault discipline are [D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)'s:
three tables, one `Outbox::transaction(…, attempts: 3)`, `INSERT … ON DUPLICATE KEY UPDATE` on
`(hook_ref, digest)` so two byte-identical deliveries to one binding both answer `202` and a re-issued
binding is not absorbed by its revoked predecessor's digests, `503` for a store fault and `500 internal` for any other, and a
`StoreFault` that carries the SQLSTATE, the driver code and the digest with no `previous`.

---

## 5. Backfill on connect — none, and why that is derived

A page that has just loaded draws no coordination fact: a snapshot never animates
([D3 § 6.5](FLOOR.md#65-a-snapshot-never-animates)) and, under the ruling, every coordination render
*is* an animation. The same holds for a reconnect and a resync. D2 § 14 item 14 closes on that; D2 § 13
row 53 records the first draft's endpoint as withdrawn. The one state the client keeps is the
`post_ref`s it has drawn, bounded at 1,000 (D3 § 12's repurposed row).

---

## 6. Attribution and the agent-name → desk join

Every name on the object stays a protocol-level name — an install's agent name on an `install`-scope
post, a roundtable identity on a `fleet`-scope one — the object gains no desk reference, the consumer
resolves against the seat population by D2 § 8.3.3's rules through the arm the object's `scope` selects
([§ 3.4](#34-the-roundtable-name-declaration-q2)), and an end that does not resolve anchors nothing and
is reported in the client's event log line for the post (D3 § 5.7 property 1, **unchanged** by r4's
answer). The **producer** writes `from`, `to`, `targets` exactly as D1 § 18.7 derives them, with
`targets` expanded against the binding's copied set and against nothing else. A producer that mapped a
roundtable identity onto a protocol agent name by its suffix would be the guessed join card#7957 was
filed to prevent.

### 6.1 What the floor renders, and what it no longer does

**Removed by the rulings**, from D3 § 5.7, § 4.6 and § 6.2: the thread line A18 and everything hung on
it — its label, carrier tag, beads and bead count, the *ended* treatment, the participant list — and the
held call marker r1 called A21; and, at r4, the seat's reading that *outside the building takes the
portal*. A18's § 6.2 row stays with a trigger cell that says it never fires, and leaves with its
`wire/animation-set.js` entry in the build, because the suite holds the two equal in both directions.

**Kept, per message — every form below is the operator's answer, none the seat's reading**
([§ 2.4](#24-what-the-operator-decided-on-2026-10-23-verbatim-on-the-card)); D3 § 5.7 and § 6.2 own the
rows, this section is the per-floor rule:

1. **Resolve** the post's origin and every destination through the arm its `scope` selects — to a desk
   **anywhere in the building** for a fleet-scope post, within the room for an install-scope one. Each
   end is then one of two things: **a desk** (on this floor, or on another floor of this building) or
   **no desk** (D3 § 5.7 property 1's unresolved arm, whatever the reason — undeclared, `disagreed`, a
   duplicate declaration, an `unchecked` roundtable declaration (the fleet arm resolves `checked` alone,
   r4 MINOR-6), the wrong vocabulary, or an agent this building does not house). **An end with
   no desk anchors nothing**, and no rule below reads why it has none: on the fleet arm
   `no_declaring_seat` covers an undeclared seat inside the building and another fleet's PM alike, and a
   rule that drew one and not the other would be the uncomputable reading r3 MAJOR-1 named.
2. **A19, the traveller** — card#12241 draws it as the bluebird carrying the envelope — fires from an
   origin desk **on this floor** to **each** destination desk **on this floor**, one traveller per
   destination; rooms may differ. **Not for a `to:all` post** (r3 MINOR-2, settled by the operator): a
   broadcast is the ring and sends no traveller.
3. **A20, the broadcast** — confirmed: fires from an origin desk on this floor when `to` carries the
   literal `all`; one ring, on that floor alone; no bird per recipient and **no portal departure**.
4. **A22, the portal bird** — *"Create a bird portal somewhere that is always open where birds can fly
   in and out of"*; the portal is **the birdhouse on a bracket, to the right of the clock** — card#12241's
   designer owns its placement and drawing; this rule assumes only that each floor has one, beside the
   clock D3's A17 already puts on every floor's back wall. **Only for a post whose BOTH ends resolve to
   desks of this building on different floors**, and whose `to` is not `all`:
   - on the **origin's** floor, the bird leaves the origin desk for **that floor's** portal — **once per
     post** however many far desks it reaches (one envelope leaves; the event log names each far desk);
   - on each **destination's** floor, the bird arrives from **that floor's** portal at the destination
     desk — once per such destination on that floor;
   - a destination on the origin's own floor takes A19, not the portal, on that floor;
   - **which floor's portal**: always the viewed floor's — the one the local resolved end is on; nothing
     is ever drawn toward another floor's portal;
   - **a floor with no portal spot cannot occur**: the portal is a property of every floor, placed by
     card#12241 beside the clock and defaulted when a map places none, exactly as a desk slot is; a
     build in which a floor could lack one is red against AT-D3-29;
   - **an end with no desk never uses the portal** — a message from or to an agent this building does
     not house **draws nothing** (operator, r4: *"A"*), and so does one whose far end is undeclared,
     duplicated or mis-vocabularied; the local end draws nothing either, because A22 needs **both** ends.
5. **Reduced motion** — *"a still envelope that disappears after some time"*: for A19 a still envelope
   at the destination desk, for A22 at the portal (departure) or at the desk (arrival), shown for the
   moving form's own duration (card#12241 fixes the figure) and then gone — transient, never held. A19's
   § 6.2 reduced-motion cell changes to this form **with the build**, because the suite holds that cell
   equal to the module's; A22's reserved row carries it now.
6. A post drawn once is not drawn again (`post_ref` memory); a post nobody was watching is not drawn
   later, by the ruling — the client's event log is the record.

**The A22 row the build adds to D3 § 6.2** — specified here with its id reserved, and not written into
§ 6.2 by this design (the suite's two-way guard):

| # | Class | Animation | Where | Driving fact (D2) | Edge that starts it, or the fact it is held by | Ends | Reduced-motion form | Its absence means |
|---|---|---|---|---|---|---|---|---|
| **A22** (reserved) | `edge` | `portal-bird` — the bird carries the envelope from a resolved origin desk on this floor to the floor's bird portal and leaves, or arrives from the portal at a resolved destination desk on this floor, for a post whose other end resolves to a desk on another floor of this building | desk, floor | `coord.round`, `coord_round.scope`, `coord_round.from`, `coord_round.targets` | one `coord.round` applied whose `to` does not carry `all`, with this desk resolving as its origin or as a member of its `targets`, and the counterpart end resolving to a desk **of this building on another floor**; one departure per post for an origin here, one arrival per resolved destination here. A counterpart that resolves to **no** desk fires nothing ([§ 5.7](#57-the-coordination-thread-line) property 1) | at the portal (departure) or at the desk (arrival) | a still envelope at the portal or at the desk for the moving form's duration, then gone | no post crossed floors between two desks of this building to or from a desk here |

The **look** of the bird, the envelope, the birdhouse and its bracket, the ring, their sizes, placements
and frames is card#12241's, as the look of A19–A20 always was.

---

## 7. Failure modes — what the floor shows, and what recovers it

| Failure | What the floor shows | Detectable? | Recovery |
|---|---|---|---|
| **a delivery lost** (GitHub timeout, outage, refusal) | one bird that never flew; nothing later shows it | not from the wire; GitHub's delivery list records the failure | operator Redeliver within 3 days; the digest is absent, so the redelivery derives and the bird flies then |
| **`issues.opened` lost** | the opening post's bird never flew; every later comment on the thread still animates | as above | as above |
| **duplicate delivery to one binding** (GitHub retry, Redeliver inside 14 days) | nothing — absorbed at `(hook_ref, digest)`, `coord_delivery_duplicate` | yes | — |
| **a second binding requested for a repository that has one** | nothing — refused at issue, at any scope (r4 MINOR-2: one repository, one unrevoked binding) | the command's refusal | `revoke` the first, then `issue` |
| **Redeliver after 14 days** | the delivery re-derives; the client fires nothing if the `post_ref` is in its 1,000-deep memory, and a second bird if it is not | `coord_delivery_received` without a client-visible change | accepted; bounded by GitHub's 3-day reach |
| **a viewer not watching** | nothing, ever, for that post | the client's event log on the page that was open | by ruling: a message is an animation |
| **an end with no desk** — undeclared, `disagreed`, duplicated, mis-vocabularied, or an agent this building does not house | nothing anchored at that end; for A19 the other destinations still fly; for A22 nothing at either end; the event log names the reason | the log line; `duplicate_declaration` is reported by the client alone (D2 § 8.3.3) | a declaration on the seat, where there is a seat |
| **ends on different floors, both resolved** | A22 at each end through each floor's own portal | — | by design; composing the rooms onto one floor turns it into the desk-to-desk bird (NQ3) |
| **a `to:all` post** | the ring from the origin desk, and nothing else | — | by design |
| **a reply with no `TO:` line** | addressed to the thread's other labelled members (the union fallback) | `coord_to_from_labels` counts the fallback | — ; the share of such posts is unmeasured ([§ 11](#11-what-this-design-does-not-establish)) |
| **a repository the binding does not name** | `403` at step 6, nothing derived; `coord_signature_invalid` for an unknown `{hook}` | yes | an operator reads the hook's delivery list |
| **a secret leaked** | forged birds on one install's floor — or, for the fleet binding, on every floor — until revoked | `coord_delivery_received.<hook>` spikes | `coord-hook:issue --hook` + `revoke` (overlap rotation, at most two), at the operator's terminal |
| **label churn** and `closed`/`reopened` | nothing — ignored at step 8, no digest, counted `coord_delivery_ignored.<event_type>` | yes | — |
| **the store down during a delivery** | `503 server_error`, nothing committed, no digest; GitHub records a failure | yes — and `fleet.health.db` | Redeliver within 3 days |
| **a real message in a fixture or a test** | — (not a floor failure) | `synthesize-fixtures.py --check` reds the PR on the first differing byte; `--lint` reds a `FROM:`/`TO:` header line, bare or quoted, or a delivery header name outside the generated directory and the `(path, rule)` exemptions; a helper throws on a free string | the author edits the spec and regenerates |
| **a real value typed into the spec** | — | not mechanically: the spec is the one entry point for content, small, enumerated and reviewed | review |
| **a real roundtable reference or identity in a document** | — | card#12260's repo-wide guard for references; identities are a review rule this document holds on itself | card#12260; review |

**States deliberately not defended against:** delivery ordering (each post is its own edge); a body
over 1 MiB (25× the measured maximum and `413` is loud); convergence, lifecycle and membership (not
rendered — D1 § 18.5 finding B unchanged, and the ruling); a thread deleted or transferred (r1 F15 —
nothing held means nothing to close; its later comments, if any, still animate).

---

## 8. Rejected alternatives, with the mechanism each turns on

| Alternative | Mechanism | Why not |
|---|---|---|
| **hold thread state anyway, for a future render** | the first draft's `coord_threads` / `coord_posts`, lifecycle rule and backfill | the operator ruled out every render that would read it; machinery with no consumer, and r1 F1 showed the client half losing lines for good under eviction |
| **ride `events` and the fold** | a synthetic event with a nullable `seat_ref` | widens the primitive every seat event depends on to admit a producer that fails all nine of D1 § 18.9's invariants |
| **a raw-delivery table drained by a daemon** | a `payload` column and a cursor | a daemon for a derivation that is one pure function, and it stores the raw body D1 § 18.10 forbids |
| **a second message type for the fleet's posts** | a parallel object with no `install_id` | two formats for one fact; `scope` on the existing object costs one slug field and a nullable `install_id` |
| **a separate route for the roundtable** | `POST /api/ingest/roundtable` | the same HMAC, validation, derivation and store — D1 § 18.11's one-producer rule |
| **map a roundtable identity onto a protocol agent name** | strip a suffix, or read `cross_project.identity` into a table | the guessed join card#7957 refused; the seat declares, nobody infers |
| **a held call marker for the cross-floor case** (r1's A21) | a marker held while the thread is open | a held render of an open connection — the thing the ruling removed |
| **the elevator as the cross-floor form** (r2's A22) | the bird leaves toward or arrives from the elevator | the operator chose a birdhouse that is *always open*; the elevator is the viewer's ride and has doors that close |
| **"outside the building takes the portal"** (r3's A22) | an end that resolves to no desk on the fleet arm sends or receives the portal bird | the operator's r4 answer: a message from an agent not housed here draws nothing; and the reading was not computable — the fleet arm's `no_declaring_seat` cannot tell an outsider from an undeclared seat inside (r3 MAJOR-1) |
| **a traveller per recipient for `to:all`**, or a portal departure for a broadcast | N birds beside the ring | the operator: the ring only (r3 MINOR-2) |
| **a GitHub REST read at hook issue** | a repo credential | Mezzanine would hold a credential for a repository it only observes (D-10), and nothing held would use what it read |
| **a content-scanning fixture guard** (r1/r2's rules) and **a broad `coord.*` lint** (r3's) | predicates over populations of files | each round found a population one member short, and the broad lint hit four unrelated tests on day one (r3 MAJOR-2); the narrow lint's predicate was run over `origin/dev` in this document and its three hits are named |
| **a convention, not a gate, for synthetic fixtures** | a README rule | the operator asked for a mechanism |

---

## 9. Implementation plan

Dependency order. Every test is seen RED once before it is trusted; the plant is named with it. D1 § 16
rows 8–10 and D2 Appendix B rows 12–13 are the ratified build rows this plan discharges. **The guard is
first and the roundtable live last**, because every fixture the middle steps author is judged from its
first commit, and the live binding is an outward act that waits on NQ1.

| # | Artifact | Gate (RED plant → GREEN) |
|---|---|---|
| 0a | **Generation is the guard** ([§ 3.5](#35-synthetic-fixtures-mechanically)): `server/tests/fixtures/coord/spec.json` (names, the synthetic installs `one`/`two` and their seats, body-line keys, scenarios); `tools/coord/synthesize-fixtures.py` with `--check` and `--lint`, `tools/coord/lint-exemptions.txt` seeded with the `(path, rule)` pairs [§ 3.5](#35-synthetic-fixtures-mechanically) item 5 lists — the `origin/dev` hits and the build-time writers — each with its reason; the selftest; the generated directory committed; `fx-coord`, `fx-elevator` and `fx-refusals` moved to reference their coordination arrays by name; `DrivesTheCoordClient`'s helpers loading the generated base object and **throwing on an override outside the spec**; `App\Coord\Derive`'s tests building inputs from spec keys; the CI workflow (`coord-fixtures.yml`, the shape of `asset-provenance.yml`); the capture tool with its two rules | the selftest's plants, each RED then GREEN ([§ 3.5](#35-synthetic-fixtures-mechanically)); `--lint` GREEN over the tree with exactly the pairs listed and RED when one is removed, and RED when a pair is widened to a rule its file does not match; the floor suite green with the fixtures loaded by reference |
| 0b | **Migration**: `coord_hooks` (with `scope`, `active_key`, `UNIQUE (repository, active_key)`), `coord_hook_secrets`, `coord_deliveries` (`id`, `hook_ref`, `digest`, `received_at`; `UNIQUE (hook_ref, digest)`) per D2 § 6.4; `App\Sweep\Purge::PLAN` gains the digest row, and its guard the 3-day floor | the purge's existing index-order test (D2 § 6.7) reds on a declared order the new index does not carry; AT-D2-17 extended with the digest chain, seen red by purging at 2 days |
| 1 | **Provisioning**: `mezzanine:coord-hook:issue` with `--secret-out` / `--hook-request-out` (0600; refuse inside a worktree, fail-closed) / `revoke`, `App\Coord\HookBinding`, `App\Coord\HookSecret` (encrypt/decrypt, overlap, the two-secret refusal); re-issue after revoke allowed; the runbook in README § operating | a command test: the secret appears in **no** stream and in no column, only in the 0600 file; the hook-request file is the body GitHub's create endpoint accepts; an `--secret-out` inside a temp git repository is refused; `--hook` adds a second secret and verification accepts either until revoke; a third is refused by name (AT-D2-29); a revoked binding's repository can be issued again (plant: a key that counts revoked rows); a second unrevoked binding for a bound repository is refused at either scope (plant: r3's `install_key` in the key); `--scope=fleet` with `--install` is refused, and `--scope=install` without it |
| 2 | **Receipt route** `routes/coord.php` (empty middleware list, like `routes/ingest.php`; `CoordRouteIsolationTest` reads the router), `App\Coord\ReceiptPipeline` in D1 § 18.8's order with the `{hook}` selector and step 8's two-member derive set, the per-`(route, IP)` failed-auth bucket — `App\Ingest\RateLimiter::hitFailedAuth()` gains a route key rather than a sibling | **AT-23**, all three REDs; the unknown-`{hook}` `401`; the 61st bad signature from one IP → `429` with 60 from distinct IPs as the control; `issues.closed` answered `202` with no digest and `coord_delivery_ignored.issues` moved (AT-D2-26) |
| 3 | **Derivation** `App\Coord\Derive` — pure functions over `(issue, comment, labels, binding)` for the **round** alone: `openingPost()`, `commentPost()`, body `FROM:`/`TO:` lines, `to` with the **union** label fallback less the author, `targets` = `to` less the author, `null` when `to` carries `all` (the expansion specified, not derived), `carrier`, `[CLOSE]` anchored, `App\Support\Slug::AGENT_NAME` validation, `scope` and `install_id` from the binding; PR comments derived as any post; its tests built from spec keys | **AT-24**'s round half (plant the `from:` label), **AT-25**'s `to` fallback half with the reply case (plant: `to:` labels alone → the addressee's reply yields `targets: []`), **AT-26**'s per-post half; a fleet binding yields `scope: "fleet"`, `install_id: null` (plant: copy the first install); a `to: ["all"]` body yields `targets: null` (plant: expand it against the seats) |
| 4 | **Receipt write** `App\Coord\Receipt` — one `Outbox::transaction(…, attempts: 3)`: the `(hook_ref, digest)` `ON DUPLICATE KEY UPDATE` with its affected count, the `CoordRound` enqueue(s) (`installId()` null for fleet scope), `App\Coord\StoreFault` carrying SQLSTATE + driver code + digest and **no `previous`**; `503` for a store fault, `500 internal` for any other | **AT-D2-27** atomicity, the concurrent pair, the one-binding refusal, and the leak leg planted on the **outbox** insert; **AT-D2-28** fleet scope asserted on the stream's frames (plant: fill a fleet row's `install_id` from the first install) |
| 5 | **The roundtable-name declaration** ([§ 3.4](#34-the-roundtable-name-declaration-q2)): `fleet-reporter.js` reads `roundtable_agent_name`, checks it against `cross_project.identity`, emits both heartbeat members and the two counters, adds the `selftest` member and re-derives D1 § 6.14's capped bound; `KindRegistry`, `Projector`, `SeatFacts`, `SeatDelta`'s map, the migration (two `seat_state` columns), `SeatObject`; **`verify-event-schema.py`'s `roundtable_agent_name` loop gains the registry and migration homes** (its three document homes are held since r5) | the reporter selftest's four-state cases, each red first; `SeatObjectMatchesTheDocumentTest`'s delta-map leg reds on a version-bearing member the map lacks; `verify-event-schema.py` green on the re-derived bound and red when one of the printed homes is edited to 47 |
| 6 | **The join's fleet arm** in `floor/coord-join.js` (`buildJoin` over every install's seats, keyed by `roundtable_agent_name`, duplicates counted across installs), selected by `scope` in `coord-model.js` | the join test extended with **spec** names and seats: a fleet-scope post naming `pm-solo` resolves to `one-pm` and not to the seat declaring `pm` (plant: fall through to the install arm); `one-coder` and `two-reviewer` both declaring `coder-solo` → `duplicate_declaration` (plant: last writer wins); an `unchecked` roundtable declaration resolves nothing on the fleet arm and still counts as a duplicate (plant: resolve it as the install arm would) |
| 7 | **Counters**: D1 § 18.8.1's **derived** set in `global_counters` — every row there not marked *specified, not derived*, with `coord_delivery_received.<hook>`; `App\Read\FleetHealth::COUNTERS` gains them, the `<hook>` family enumerated from `coord_hooks` | `FleetHealthCountersMatchTheDocumentTest` reds when a § 7.1 fleet-health counter is missing from the constant; `verify-fleet-state.py` G8 is the doc-side half |
| 8 | **Client** — the removal, listed in full: `fleet-client.js` loses `#holdCoord`, `COORD_CAP`, `coordTruncated`, `coordMessages` and the `coord.thread` case (and `coord.thread` leaves `KNOWN_TYPES`, so the default branch counts it as unknown); `coord/coord-model.js` loses `threadRender`, `threadAnimations` and the thread assembly, keeping `roundRender`/`roundAnimations` with the `to:all` rule (A20 only) and the both-ends rule for A22; `coord/coord-members.js` loses the thread member set (r3 MINOR-5); `coord/main.js`'s `renderCoordLayer` and its page go; `floor/floor-screen.js` `#coordinate` draws per round, gains the `post_ref` memory (1,000), the event-log line for an unresolved end, the floor's **portal spot** (card#12241's birdhouse beside the clock; defaulted when a map places none) and A22 to and from it; tests removed or retargeted: `TheCoordinationEnvelopesAreHeldToTheirCapTest`, `CoordRendersTheThreadLineTest`, `TheCoordinationLineResolvesOrRendersUnresolvedTest`, `CoordDrawsNoLineWithoutAJoinTest` (→ *no bird without a join*), `CoordMemberSetMatchesTheDocumentTest`, `CoordModuleWiringTest`, `coord-probe.mjs`, `coord-cap-probe.mjs` and `fleet-client-probe.mjs`'s hold legs, `FeedSurfaceTest`'s `$scoped` coordination leg, and the generated fixtures' `coord.thread` content; **A18 removed from D3 § 6.2 and `wire/animation-set.js`, A22 added to both, A19's reduced-motion cell changed to the still-envelope form in both — all in one commit**; AT-D3-18's line assertions removed; the drawing per card#12241's proposal | **AT-D3-29** through the probes and the fleet-client harness (its REDs, the portal legs included); `TheAnimationSetIsTheDocumentsClosedSetTest` green only when the table and the module moved together (it reds on either half alone — the control); `NoAnimationFiresWithoutItsEventTest` green with A18 gone |
| 9 | **Doc-sync and the round trip in the same PR**: README § operating (the operator-terminal runbook for `coord-hook:issue` and `gh api --input`, the two declarations), CHANGELOG bullet, `tools/design/README.md` D5 row, the verifier runs, D1 § 6.14's selftest member, D3 § 6.2's A18 removal and A22 row, D3 § 5.7's reserved-row sentence, D3 § 4.6's portal placement; `server/tests/roundtrip/coord-roundtrip.py` (signs the generated fixtures under a binding's secret file and posts them; exempt from the lint by name) | the four verifiers + `verify-design-docs.selftest.py` green at the head; `synthesize-fixtures.py --check` and `--lint` green |

**Acceptance on the sandbox** (the sandbox is this seat's to operate; nothing below touches `main` or
production; every outward act is marked and waits on its question):

1. Deploy the build to the sandbox; `php artisan migrate` adds the three tables and the two columns;
   `GET /api/fleet/health` carries every new counter at `0`; `--check` and `--lint` are green in CI.
2. This seat's reporter: `roundtable_agent_name` set to this seat's `cross_project.identity` in
   `~/.config/fleet-reporter/config.json`, artifact replaced, flusher restarted; the next heartbeat's seat
   object carries the name with `checked`.
3. **Replay, both scopes**: the operator issues a sandbox `install` binding and a sandbox `fleet`
   binding at their terminal (`--secret-out`), and `server/tests/roundtrip/coord-roundtrip.py`, run by
   the operator with those files, signs the generated fixtures and posts them; `bin/feed-stream-check.sh`
   shows `coord.round` on the stream with and without `install_id`, and **no** `coord.thread`; the
   sandbox store gained digest rows and nothing else. The fixtures' names resolve to no sandbox seat, so
   this step shows the feed and the store, not a bird, and says so.
4. **Live, the roundtable — the broadcast** (after NQ1's announcement and the fleet's agreement; the
   operator issues the `fleet` binding and registers the hook at their terminal): GitHub's *ping* is
   `202`, counted `coord_delivery_ignored.ping`. This seat's own next `to:all` post fires **A20** from its
   desk on the sandbox floor without a page reload — and nothing else: no traveller, no portal
   departure; `coord_delivery_received.<hook>` moves. A post from a PM this building does not house
   **draws nothing** (its origin resolves to no desk; the event log names it); a direct post to or from
   `<the kanban seat>` draws nothing until NQ2 (an undeclared far end is no desk).
5. **Live, the portal bird** (after NQ2): once `<the kanban seat>` declares its roundtable name and runs
   the reporter that sends it, this seat's next direct post to it fires **A22** — a departure to the
   birdhouse on the *Mezzanine* floor, an arrival from the birdhouse on the `kanban` floor; after NQ3
   composes the two rooms onto one floor, the same post fires A19 desk to desk.
6. Redeliver one delivery from GitHub's list: `coord_delivery_duplicate` moves, nothing flies.
7. The `install` scope has no live repository on this fleet; step 3 is its acceptance (NQ4).

**`Built:` for the build PR** is recorded on the card at each dispatch, per `built-line.md`.

---

## 10. Operator questions

**Answered 2026-10-23** — Q1–Q4, the render scope, NQ5, NQ6, and the two r4 answers (*"A"*; *"do as you
recommended"*; the birdhouse). **Open:**

**NQ1 — Registering Mezzanine's hook on the `<roundtable repository>`: what the fleet is asked to
accept, and who acts.** Context (r1 F14, r2 MAJOR-4/5, r3 MINOR-3): the `<roundtable repository>` is a
**shared private repository** of more than one fleet. Under this design Mezzanine would retain, of the fleet's
traffic, **exactly this** — read it against [D2 § 6.4](FLEET-STATE.md#64-ddl), which is what makes it
true:
- in `coord_deliveries`, **one row per derived delivery, four columns**: a row id, the binding's id, a
  64-byte SHA-256 of the signed body, and the time it was received — for 14 days; no event name, action,
  delivery GUID, subject, body or name;
- in `coord_hooks`, the binding row: the repository's name, the scope and a `shared_identity` flag —
  **no list of the fleet's agent identities** (r4 MAJOR-2 removed the roster copy with the `all`
  expansion it served);
- in `coord_hook_secrets`, the hook's secret, encrypted;
- on the feed, **transiently**, each post's addressing — the sender's and recipients' roundtable
  identities, the thread reference and the post reference — delivered to every signed-in viewer's open
  stream within seconds, and resident in `feed_outbox` **until the hourly purge removes rows older than
  60 s — so for up to about an hour** ([D2 § 6.7](FLEET-STATE.md#67-retention-and-purge)), not 60 s as
  r2 said.
That is other fleets' coordination metadata and timing on a server they do not operate; it needs their
agreement, not only the operator's. **Issuing the binding and registering the hook is the operator's own
terminal act** ([§ 3.2](#32-provisioning--one-operator-terminal-act)).
- *(a) Announce first; then the operator issues and registers on the sandbox.* Example: an
  `[ANNOUNCE]`/`[FR]` from this seat to the fleet's PMs (`to:all`) stating the list above and the hook's
  URL pattern; on no objection within the roundtable's follow-up window, the operator runs the two
  commands of [§ 3.2](#32-provisioning--one-operator-terminal-act) at their terminal. Cost: a wait of
  days; a fleet may decline, in which case the roundtable is not bound and the install scope carries the
  design until a PM-and-impl install reports here.
- *(b) Register on the sandbox now and announce after.* Cost: the fleet's metadata reaches a server it
  did not agree to before it could object — the wrong order for an outward act on a shared surface.
- *(c) Announce, and offer a narrower feed.* Example: the same post offering that the fleet binding's
  feed carry opaque digests in place of `thread_ref`/`post_ref`. Cost: the event log would name no
  thread; the store's row is already the minimum.
- **Recommendation: (a)** — this seat posts the announcement (its routine coordination traffic); the
  operator issues and registers at their terminal once the window closes, recorded on the card.

**NQ2 — `<the kanban seat>`'s end of the bird.** Context: the join resolves a roundtable name only to a
seat that **declares** it; that seat's config and reporter are its own operator's to change. Until it
declares, its end is **no desk**: a direct post between the two seats draws **nothing** at either end
(A22 needs both ends; the event log names the undeclared end). Broadcasts from this seat ring.
- *(a) Ask that seat, on the roundtable, to declare its `roundtable_agent_name` and take the reporter
  release that carries the field.* Example: an `[FR]` from this seat to it that can ride NQ1's
  announcement. Cost: a post and a wait.
- *(b) Accept no bird between the two for now.* Cost: the operator's stated want — the bird between the
  two — is not visible until (a) happens anyway.
- **Recommendation: (a)**, with NQ1's post.

**NQ3 — Compose the `kanban` room onto the *Mezzanine* floor?** Context: the sandbox layout holds one
authored floor with one room ([§ 2.1](#21-installs-and-seats-on-the-sandbox)). With the rooms on
separate floors a post between the two declared seats fires A22 at each end through each floor's
birdhouse; with the rooms composed onto one floor it fires the desk-to-desk bird. An admin-console act,
the operator's.
- *(a) Compose them now.* Example: the first live traveller flies desk to desk across the two rooms.
  Cost: the portal form is unexercised on the sandbox until a third room exists — and under r4's
  answer no other fleet's post exercises it either, so (a) leaves A22 unseen here.
- *(b) Leave them apart until the portal form is seen between the two, then compose.* Cost: two
  acceptance passes.
- **Recommendation: (b)** — restored from r2: with *outside the building* drawing nothing, these two
  rooms on two floors are the one place the birdhouse can be seen working on the sandbox.

**NQ4 — The `install` scope has no live repository here.** Context: by Q1 it is built and replay-tested;
no PM-and-impl install reports to this Mezzanine, so step 7 of the acceptance is a replay.
- *(a) Accept the install scope by replay now; a multi-agent install's onboarding is a separate operator
  act when one wants a floor here.* Cost: the first live install-scope post is the first time the scope
  meets real traffic.
- *(b) Onboard one of the fleet's multi-agent installs to the sandbox first.* Cost: another fleet's
  operator act, and credentials for its seats on this Mezzanine.
- **Recommendation: (a)**; (b) is a fleet question.

---

## 11. What this design does not establish

| Not established | Cost if wrong | Closing act |
|---|---|---|
| **the `issues.opened` payload shape by capture** — DOCS-CITED only; the comment delivery is observed | the opening post's derivation reads `null` on a key the documentation names and the delivery omits | the first live `opened` on the fleet binding, read through the capture tool into `~/.cache` and compared by key, never vendored |
| **what a per-install ACL on the stream does with a fleet-scope post** — D2 § 14 item 7 says fleet-read is all-or-nothing for now | a client scoped to one install would, under a naive filter, lose every roundtable bird or see all of them | decided when that ACL is designed. The server resolves no name (D2 § 13 row 49), so a filter can read only the binding, never the ends; the options are therefore all-or-nothing **per fleet binding** |
| **that `cross_project.identity` is where every seat's roundtable name lives** — true of this install's config; the framework owns the key | a seat on a differently-shaped config checks against nothing and reports `unchecked` — a supported state | carried to the framework's owner with D1 § 3.1's existing resolution-order contract |
| **what a redelivered payload is, byte for byte** — GitHub's documentation is silent | a redelivery outside the digest's absorption derives twice; the client's `post_ref` memory absorbs it inside 1,000 posts | observe one Redeliver on the sandbox (acceptance step 6) |
| **the `coord_delivery_received.<hook>` family's member set on `GET /api/fleet/health`** is the set of bindings at request time — a revoked binding's member stays | a stale member reads `0`-moving forever | stated, not closed: D2 § 7.2's never-reset rule applies |
| **that the spec holds only synthetic values** — the one place content enters the fixtures | a real name or id typed into the spec is generated into every fixture | review of the spec, which is small and enumerated by design; no mechanism reads intent |
| **what fraction of label-fallback posts are the addressee's reply** (r2 MAJOR-7) | the corrected fallback changes `targets` on an unknown share of the posts with no `TO:` line (the share of such posts, measured at r2, is on the card — r4 MINOR-5) | **unmeasured**, by rule: this repository holds no replay corpus of coordination posts and the design stores no body; the live count is `coord_to_from_labels` |
| **the seat object's worst-case headroom is 15 bytes** — the two declaration members move D2 § 8.2.1's worst-case delta to 6,862 B, so D3 § 8.1's intern-cap arithmetic puts the worst case at a cap of 13 at **8,177 B against the 8,192 B bound** (re-derived by both gates; three reviews verified it as real headroom) | the next nullable member anyone adds to the seat object breaks D3 § 8.1's *could reach 13* arithmetic at once | stated here so the next card that touches the seat object reads it before its own design review |
| **the fleet's agreement to the roundtable binding** | the hook is registered on a shared repository whose other users did not agree | NQ1's announcement, before any registration |
| **the birdhouse's placement on a floor** — a render fact, card#12241's; this design requires only that every floor has one, beside its clock | a floor whose clock position leaves no bracket room would have a bird with nowhere to go | card#12241's placement rule; AT-D3-29 holds that a floor rendered from any map has a portal spot |
| **that D3's own long-standing fixture seat ids are not live labels** — `fx-snapshot-4`'s seats equal live roundtable identities, and card#12260's predicate does not cover identities (r3 MINOR-8) | the repository's existing fixtures name live agents | handed to card#12260; this card's own tests use the spec's `one`/`two` installs and never those ids |

---

## 12. The amendment ledger

Every edit this slice makes to a ratified document, with the claim it changes and why. Each is a diff
to the owning document; none is a parallel restatement. Entries superseded within the card's own
rounds are marked.

| Doc · section | What changed | Why |
|---|---|---|
| D1 § 18.8 (A1) | the route is `POST /api/ingest/github/{hook}`; an unknown `{hook}` is a step-3 `401`; a binding has a `scope`; step 8 derives from `issues.opened` and `issue_comment.created` only; the delivery header's value is **not stored** | [§ 3.1](#31-the-binding-its-scope-and-why-it-is-selected-by-a-path-segment); the render-scope ruling; r2 MAJOR-4 |
| D1 § 18.8 step 7 (A2) | *"this document mints no retention number"* points at D2 § 6.7's 14 days | the digest store exists |
| D1 § 18.6 / § 18.7 (A3) | both field tables gain `scope` and a nullable `install_id`; § 18.6 is marked specified-and-not-published; the r1-era comment-carried triggers are withdrawn | the operator's Q1 and the render-scope ruling |
| D1 § 12.7 (A4) | the coordination route's **derived** counters enrolled by pointer rows; at r5 the two roster counters leave the table as specified-not-derived | the plane that stores them now exists; r2 MAJOR-3; r4 MAJOR-2 |
| D1 § 18.13 (A5) | rows 1–5, 8, 9 updated with the observed delivery, GitHub's documented facts and the two closures; row 2's hazard noted as having no consumer | the rows' own closing acts |
| D1 § 18.2 (A6) | the bridge checkout row notes the re-read at v0.106.0 | SOURCE-READ's axis is the revision |
| D1 § 15 (A7) | decisions 55 (the selector), 56 (the scope), 57 (`coord.thread` not published), 58 (the fallback union), 59 (PR comments) | decisions taken |
| D1 § 3.1 / § 6.0 / § 6.14 / § 9.3 / § 18.3.1 / § 18.11 / § 18.12 (A8) | the `roundtable_agent_name` declaration end to end (its § 3.1 example in the spec's vocabulary, r4); the install-facts input per scope, its roster member marked specified-not-provisioned at r5; the join's consumer named as the traveller; § 18.12's reach sentence rewritten for a `null` broadcast fan-out | the operator's Q2 and Q1; r3 MINOR-8; r4 MAJOR-2 |
| D1 § 18.7 / § 18.8.1 (A9) | `to`'s label fallback is the union of `from:`/`to:` labels less the author; a PR comment is derived as any post; the two participant counters marked *specified, not derived*; at r5 the `all` expansion likewise (`targets: null` on every broadcast) with `coord_targets_unresolved` / `coord_roster_unknown_name`, and decision 60 | r2 MAJOR-7, MAJOR-3 and the PR-comment residual; r4 MAJOR-2 |
| D2 § 2.1 / § 2.2 (B1) | the **coordination receipt** process row and its fail posture (`503` store fault, `500 internal`) | add a process, add a row; r2 MINOR-4 |
| D2 § 6.4 (B2) | three tables (bindings with `scope` and `active_key`, keyed `UNIQUE (repository, active_key)` at r5 — `install_key` and the roster columns withdrawn; secrets; digests — `id`, `hook_ref`, `digest`, `received_at`, `UNIQUE (hook_ref, digest)`) | the store; r2 MAJOR-4; r3 MINOR-4; r4 MAJOR-2, MINOR-2 |
| D2 § 6.7 (B3) | the digest retention row and chain | retention stated where retention lives |
| D2 § 6.12 (B4) | **new** — the store's rules: the write path and its concurrency primitive keyed per binding, the fault discipline, what is deliberately not held, exactly what is retained (r4: the four columns, the outbox's hour; r5: no roster) | r1 F3; r2 MAJOR-4; r3 MINOR-3, MINOR-4; r4 MAJOR-2 |
| D2 § 7.1 / § 8.2.4 (B5) | the derived set's rows (`coord_delivery_received.<hook>`), the fleet-health list, the two counts the gate re-derives (two rows fewer at r5) | counter closure; r1 F13; r2 MAJOR-3; r4 MAJOR-2 |
| D2 § 8.2 (B6) | the *no fifth endpoint* paragraph records the first draft's endpoint as withdrawn | the render-scope ruling |
| D2 § 8.3 / § 8.3.3 / § 8.4 (B7) | `coord.thread` marked not published; `scope` on both objects and `install_id` nullable; the fleet arm of the join, resolving `checked` alone at r5; `to`'s union fallback; `targets` `null` on a broadcast; the no-snapshot paragraphs on the new reason | the ruling; the operator's Q1 and Q2; r2 MAJOR-7; r4 MINOR-6 |
| D2 § 12 / § 13 / § 14 / AT / App. B (B8) | numbers; decisions 52–58 in order; item 14 closed; AT-D2-26…29 (27 gains the two-bindings case at r4 and trades it for the one-binding refusal at r5; 28's `targets` follow the r5 rule); build rows 12–13 | bookkeeping; r2 MINOR-1, MINOR-4, MINOR-5; r3 MINOR-4; r4 MINOR-2 |
| D2 § 6.4 / § 6.5 / § 8.2.1 (B9) | `roundtable_agent_name` and its check: two columns, the version-bearing row, two seat-object rows; the worked examples' size figures re-measured | the operator's Q2 |
| D3 § 2.2 (C1) | the r1-era steps 2c/3c/5c withdrawn; the list stands as before | no backfill |
| D3 § 4.6 / § 5.7 / § 6.2 / § 6.5 / § 12 / § 14 / AT (C2) | the between-rooms form made transient and routed through the bird portal — at r4 the birdhouse beside the clock, for both-ends-resolved cross-floor posts only, with *outside the building* drawing nothing and a broadcast sending no traveller and no departure; § 5.7 rewritten as `coord.round`'s render map; A18's trigger cell retired, A19/A20's triggers read `scope` and the `to:all` rule; § 12's envelope hold repurposed as the `post_ref` memory; items 24, 25, 40; AT-D3-18 annotated, AT-D3-29 rewritten over the spec's seats and, at r5, over two floors — the portal leg clears `one-coder`'s declaration so the cross-floor case is cross-floor, with the same-floor control and two REDs more; § 5.7's fleet arm resolves `checked` alone; § 11's `fx-coord-seats` row corrected to match | the rulings; r3 MAJOR-1, MINOR-2, MINOR-8; r4 MAJOR-1, MINOR-6; the suite's two-way guard on § 6.2 |
| D3 § 5.6 (C3) | null-render rows for the two declaration members | G10 |
| D3 § 8.1 / § 12 (C4) | `⌊1,330 ÷ 263⌋` | r2 MINOR-10 |
| `tools/design/verify-fleet-state.py` | the two counter-count regexes capture hyphenated number words the gate's own `WORD` map already held; the map gains the word the r5 count needs | a gate whose map and regex disagreed could never accept a hyphenated count |
| `tools/design/verify-event-schema.py` | the per-home bound loop holds `roundtable_agent_name`'s three document homes (D1 § 6.14, D2 § 6.4, D2 § 8.2.1); the registry and migration homes join at build step 5 | r4 MINOR-3: a 48 in two documents with no gate is a figure, not a claim |
| `tools/design/README.md` | D5 declared under no gate, as D4 is | a document under no gate says so |

---

## 13. Design review r1 — disposition per finding

Reviewer's report: `~/.cache/coord/card-12239/review-r1.md`, on the first two commits (pre-rebase ids).
⚠ Three r1 dispositions were found incomplete at r2 — F4, F5 and F14 — and are re-dispositioned at
[§ 14](#14-design-review-r2--disposition-per-finding); the rows below stand as r1's record. The
operator's render-scope ruling arrived between r1 and r2; several findings lose their subject to it.

| # | Severity | Disposition | Where |
|---|---|---|---|
| F1 | BLOCKER | **Moot by ruling.** Its subject — a held thread line surviving eviction on a long-lived page, and membership refresh feeding it — is a held render; none is held now | D2 § 13 rows 53, 58; [§ 4](#4-what-must-still-be-carried-and-what-goes) |
| F2 | MAJOR | **Moot by ruling** — no lifecycle, no membership, no `state_at` | D2 § 6.12 |
| F3 | MAJOR | **Folded.** The primitive is named; two distinct deliveries share no row; the concurrent-pair test is in AT-D2-27; `StoreFault` carries SQLSTATE + driver code + digest, no `previous` | D2 § 6.12, AT-D2-27 |
| F4 | MAJOR | **Re-dispositioned at r2 (MAJOR-1)** — the r2 fold left nested fixtures outside the population; r3 removed the population: every coordination fixture is generated | [§ 3.5](#35-synthetic-fixtures-mechanically) |
| F5 | MAJOR | **Re-dispositioned at r2 (MAJOR-2) and answered by the operator (NQ5)** — no docs leg in this design; card#12260 owns the scrub and the repo-wide guard | [§ 2.4](#24-what-the-operator-decided-on-2026-10-23-verbatim-on-the-card) |
| F6 | MAJOR | **Folded as directed.** The render rule is unchanged (an end must resolve); the acceptance waits for NQ2 for the two-ended render | [§ 9](#9-implementation-plan); NQ2 |
| F7 | MAJOR | **Moot by ruling** — no held lifecycle | — |
| F8 | MINOR | **Folded.** D1 § 18.6's lifecycle row is true under the ruling | D1 § 18.6 |
| F9–F12 | MINOR | **Moot by re-derivation** — the rules they corrected no longer exist; the capture tool keeps F11's two rules | [§ 3.5](#35-synthetic-fixtures-mechanically) |
| F13 | MINOR | **Folded.** `coord_delivery_received.<hook>` | D2 § 7.1, § 8.2.4 |
| F14 | MINOR | **Re-dispositioned at r2 (MAJOR-4) and r3 (MINOR-3)** — the retained data is now stated column by column, and NQ1 says so | NQ1; D2 § 6.12 |
| F15 | MINOR | **Moot by ruling** — nothing held, nothing to close | [§ 7](#7-failure-modes--what-the-floor-shows-and-what-recovers-it) |
| F16 | MINOR | **Folded.** The two-secret refusal; AT-D2-29 | D2 § 6.12, AT-D2-29 |
| hygiene | — | **Noted.** The card comment's numbering swaps Q2/Q3; this document follows the operator's answers | [§ 2.4](#24-what-the-operator-decided-on-2026-10-23-verbatim-on-the-card) |

---

## 14. Design review r2 — disposition per finding

Reviewer's report: `~/.cache/coord/card-12239/review-r2.md`, on the r2 commit (pre-rebase id). The
tripwire — three findings recurring r1 classes, two in the fixture guard — made r3 the last round under
the stopping rule, and the guard was re-derived rather than patched.

| # | Severity | Disposition | Where |
|---|---|---|---|
| MAJOR-1 | recurs r1 F4 | **Re-derived.** No population to select: every coordination fixture is generated from the spec, including `fx-coord` / `fx-elevator` / `fx-refusals`' coordination content, which the fixtures reference by name; CI byte-compares | [§ 3.5](#35-synthetic-fixtures-mechanically) |
| MAJOR-2 | recurs r1 F5 | **Re-derived; the lint narrowed again at r4** after r3 MAJOR-2 showed the broad `coord.*` lint red on day one | [§ 3.5](#35-synthetic-fixtures-mechanically); [§ 15](#15-design-review-r3--disposition-per-finding) |
| MAJOR-3 | — | **Folded.** The two participant counters are marked *specified, not derived* at D1 § 18.8.1 and removed from D1 § 12.7, D2 § 7.1, § 8.2.4 and the build | D1 § 18.8.1, § 12.7; D2 § 7.1 |
| MAJOR-4 | recurs r1 F14 | **Folded; the disclosure corrected again at r3 MINOR-3** | D2 § 6.4, § 6.12; NQ1 |
| MAJOR-5 | — | **Folded.** `coord-hook:issue --secret-out` / `--hook-request-out` and `gh api --input`, at the operator's terminal; the claim narrowed at r3 MINOR-7 to transcript/argv hygiene | [§ 3.2](#32-provisioning--one-operator-terminal-act); NQ1 |
| MAJOR-6 | — | **Folded, answered by the operator; the outside-building reading corrected at r4** | [§ 2.4](#24-what-the-operator-decided-on-2026-10-23-verbatim-on-the-card); [§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does) |
| MAJOR-7 | — | **Folded.** The fallback is the union of `from:`/`to:` labels less the author; the reply-case fraction is **unmeasured** | D1 § 18.7; [§ 11](#11-what-this-design-does-not-establish) |
| MINOR-1 … MINOR-10 | — | **Folded or moot as r3's report records**, with MINOR-2's `to:all` disagreement settled by the operator at r4 (ring only) | [§ 15](#15-design-review-r3--disposition-per-finding) |

---

## 15. Design review r3 — disposition per finding

Reviewer's report: `~/.cache/coord/card-12239/review-r3.md`, on the r3 commit. The round was not clean
and the card stopped; the operator answered the two open questions and re-opened it as round 4.

| # | Severity | Disposition | Where |
|---|---|---|---|
| MAJOR-1 | recurs r2 MAJOR-6 | **Answered by the operator — *"A"*: a message from an agent not housed in this building draws nothing; A22 is only for cross-floor posts between seats of this building whose both ends resolve.** *Outside the building takes the portal* is removed everywhere; D3 § 5.7 property 1 and D2 § 8.3.3 rule 3 stand; A22's trigger says *both ends resolve to desks of this building*; AT-D3-29's contradiction is gone (a fleet-scope post from an unhoused agent fires nothing) | [§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does); D3 § 4.6, § 5.7, AT-D3-29 |
| MAJOR-2 | recurs r2 MAJOR-2 / r1 F4 | **Answered by the operator — *"do as you recommended"*.** The generator check stays; the lint is narrowed to delivery headers and `FROM:`/`TO:` header lines; its predicate was run over `origin/dev`: the hits were the PR-body attribution rule's material, exempted with reasons — *re-measured and re-keyed at r5 (r4 MAJOR-3, MINOR-1)*; the reviewer's four hits were the broad lint's and are not reached; build-time exemptions named | [§ 3.5](#35-synthetic-fixtures-mechanically) |
| MINOR-1 | — | **Folded.** Helpers throw on an override outside the spec; `Derive` tests build inputs from spec keys; `server/app`, `resources` and seeders are named as outside the population, with the reason | [§ 3.5](#35-synthetic-fixtures-mechanically) item 3 and the cannot-catch list |
| MINOR-2 | — | **Settled by the operator.** A19 does not fire for a `to:all` post; a broadcast sends no A22 departure; D3 § 6.2's A19 trigger cell and A22's reserved row say so | [§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does); D3 § 5.7, § 6.2 |
| MINOR-3 | recurs F14 / r2 M4 | **Folded.** NQ1 now lists the four columns of `coord_deliveries`, the binding row (its roster copy withdrawn at r5), the secret, and the outbox's up-to-an-hour residency | NQ1; D2 § 6.12 |
| MINOR-4 | — | **Folded.** `UNIQUE (hook_ref, digest)`; the pre-check and the `ON DUPLICATE KEY` are per binding; a revoked binding does not block re-issue (`active_key`); AT-D2-27 gains the two-bindings case — *superseded at r5 by r4 MINOR-2: one unrevoked binding per repository* | D2 § 6.4, § 6.12, AT-D2-27 |
| MINOR-5 | — | **Folded.** `coord-roundtrip.py` (step 9) and `coord-members.js` (step 8) are in the build list | [§ 9](#9-implementation-plan) |
| MINOR-6 | — | **Folded.** *five homes* replaced by the derivation: the set `verify-event-schema.py` prints per run | [§ 3.4](#34-the-roundtable-name-declaration-q2) |
| MINOR-7 | — | **Folded.** *this seat holds no secret* reworded to transcript and argv hygiene | [§ 3.2](#32-provisioning--one-operator-terminal-act) |
| MINOR-8 | — | **Folded.** D5 carries no live roundtable identity (`<this seat>`, `<the kanban seat>`, spec names); AT-D3-29 uses the spec's `one`/`two` installs and seats; D1 § 3.1's example uses the spec's vocabulary; D3's own fixture ids are handed to card#12260 | this document; D3 AT-D3-29; D1 § 3.1; [§ 11](#11-what-this-design-does-not-establish) |

**Removed from the design by the rulings, for the record:** the thread line A18 (row retired in place,
removed with its module), its label, beads, carrier tag and ended treatment; the held call marker (r1's
A21); the elevator form (r2's A22) and the outside-building portal reading (r3's A22); `coord.thread` as
a published message; the thread/post store, its lifecycle and membership rules and counters; the
backfill endpoint and the client's envelope hold; D2 § 8.4's coordination steps and D3 § 2.2's steps
2c/3c/5c; the content-scanning fixture guard, its docs leg, and the broad `coord.*` lint.

---

## 16. Design review r4 — disposition per finding

Reviewer's report: `~/.cache/coord/card-12239/review-r4.md`, on `f6532886`. The round was not clean; the
dispatcher's instruction for r5 was to fold **exactly** the fixes it measured and nothing else, so each
row below names the measured fix and where it landed. A narrow confirmation review checks these rows
alone.

| # | Severity | Disposition | Where |
|---|---|---|---|
| MAJOR-1 | — | **Folded as measured.** The portal leg clears **`one-coder`'s** roundtable declaration, so `coder-solo` resolves to `two-reviewer` on floor `two` and `F1` is a departure from `one-pm`'s desk into floor `one`'s birdhouse plus an arrival from floor `two`'s at `two-reviewer`'s desk, with no A19 on either floor; clearing `two-reviewer`'s instead is kept as the same-floor control (A19, no portal edge). Two REDs added: a departure toward a destination that resolves to no desk (variant (i)), and a floor rendered from a map with no birdhouse and no default placement. D3 § 11's `fx-coord-seats` row (the line the review named) now states the two floors, the two declarations and which clearing gives which case | D3 AT-D3-29, § 11 |
| MAJOR-2 | — | **Folded, fix (a).** The `all` expansion is specified and not derived: `targets` is `null` on every post whose `to` carries `all` and `to` less the author otherwise. Withdrawn with it: `coord_hooks.roster` and `roster_copied_from`, `--roster`, `coord_targets_unresolved`, `coord_roster_unknown_name` (both marked specified-not-derived in D1 § 18.8.1 and removed from D1 § 12.7, D2 § 7.1 / § 8.2.4 and build step 7), D1 § 18.3.1's roster member (specified, not provisioned), § 7's roster-drift row, § 3.3's roster hop row; NQ1 discloses a flag and no identity list. `shared_identity` stays. D1 § 18.12's reach sentence and D1 decision 60 record it | D1 § 18.7, § 18.3.1, § 18.8.1, § 12.7, § 18.12, § 15; D2 § 6.4, § 6.12, § 7.1, § 8.2.4, § 8.3.3; D3 § 5.7; [§ 3.1](#31-the-binding-its-scope-and-why-it-is-selected-by-a-path-segment), [§ 3.2](#32-provisioning--one-operator-terminal-act), [§ 3.3](#33-what-crosses-which-boundary-canon-7), [§ 7](#7-failure-modes--what-the-floor-shows-and-what-recovers-it), [§ 9](#9-implementation-plan), NQ1 |
| MAJOR-3 | recurs the fixture-guard class | **Folded as measured.** Exemptions are `(path, rule)` pairs; the receipt tests under `server/tests/Feature/Coord/` carry none (their headers come from `spec()->headers(...)`); the `quoted` arm `(["']\|\\n\|\\r\\n)(FROM\|TO):\s` is added and re-measured over `origin/dev`: it hits `bin/pr-body-lint.selftest.py` (already exempt for `bare`, now also for `quoted`) and `bin/change-pr-body.selftest.py` L239 (exempt for `quoted` only), nothing else. `Derive` inputs are spec-bound mechanically — `spec()` is the one exempt writer and the `Derive` tests are in the population unexempted — and the split or concatenated literal that bypasses both is listed under *cannot catch* | [§ 3.5](#35-synthetic-fixtures-mechanically) items 3 and 5, the selftest and the cannot-catch list |
| MINOR-1 | — | **Folded.** The figure is gone; the population is the `git ls-tree` command, which prints it. The three regexes are pinned case-sensitive in § 3.5's table; `bin/pr-body-lint.py` L112 is recorded as **not** a hit (the backtick) and its r4 exemption withdrawn | [§ 3.5](#35-synthetic-fixtures-mechanically) item 5 |
| MINOR-2 | — | **Folded — the key, not the client memory.** `UNIQUE (repository, active_key)`: one unrevoked binding per repository at any scope; `install_key` dropped; `issue` refuses a second; AT-D2-27's two-bindings case becomes the one-binding refusal with r3's key as its RED; § 7's row and § 4's reason for the per-binding digest key rewritten | D2 § 6.4, AT-D2-27; [§ 3.2](#32-provisioning--one-operator-terminal-act), [§ 4](#4-what-must-still-be-carried-and-what-goes), [§ 7](#7-failure-modes--what-the-floor-shows-and-what-recovers-it) |
| MINOR-3 | — | **Folded.** `verify-event-schema.py`'s bound loop now reads `roundtable_agent_name`'s three document homes (D1 § 6.14's row, D2 § 6.4's `VARCHAR`, D2 § 8.2.1's row) into the same equality check, without requiring the registry or migration homes the build adds; the gate is green at 48 and the loop prints the homes | `tools/design/verify-event-schema.py`; [§ 3.4](#34-the-roundtable-name-declaration-q2); build step 5 |
| MINOR-4 | — | **Folded.** § 3.5 item 1 points at `spec.json` as the only list of synthetic names and seats and restates none (the unhoused name included, by role) | [§ 3.5](#35-synthetic-fixtures-mechanically) item 1 |
| MINOR-5 | — | **Folded.** The private repository's activity figures — thread and comment counts and rates, the busiest threads, the identity count, the hook count, the permission held, the delivery page's event mix and response codes, the r2 no-`TO:` share — are moved to the card (card#12239, the r5 comment); § 2.2, § 2.3, § 11 and NQ1 keep shapes only | [§ 2.2](#22-the-coordination-repository-population-on-this-fleet), [§ 2.3](#23-githubs-side-of-the-hop-read-at-the-vendors-documentation), [§ 11](#11-what-this-design-does-not-establish), NQ1 |
| MINOR-6 | — | **Folded — resolve only `checked`.** On the fleet arm an `unchecked` roundtable declaration resolves nothing and still counts toward the duplicate test; the install arm keeps `unchecked`. The fixture's `one-coder` declares `coder-solo` `checked` so AT-D3-29's resolutions hold, and the test plants an `unchecked` one that must resolve nothing | D2 § 8.3.3 rules 1–2; D3 § 5.7 property 1 and its join row, § 11, AT-D3-29; [§ 3.4](#34-the-roundtable-name-declaration-q2), [§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does), build step 6 |

**Not changed at r5, by instruction:** no new mechanism; the client memory stays keyed on `post_ref`
(MINOR-2's alternative was not taken because the key closes the case upstream); the lint population
and the `header` rule are as r4 had them.

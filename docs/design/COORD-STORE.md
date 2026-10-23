# D5 — the coordination-post producer: receipt binding, the roundtable-name declaration, the message animations, and the build plan

**Status: DESIGN, revision r3 — the last round under the stopping rule — re-scoped to the operator's
rulings of 2026-10-23 and folding design reviews r1 and r2 ([§ 13](#13-design-review-r1--disposition-per-finding),
[§ 14](#14-design-review-r2--disposition-per-finding)); the build awaits the operator's go on the
questions in [§ 10](#10-operator-questions). Nothing in this document is built. Rebased onto `origin/dev`
at card#12187 (no conflicts).**

> **What this document is, and what it is not.** D1 § 18 owns the coordination-event **producer** — the
> receipt route's authentication, validation order and the derivation of `coord.thread` / `coord.round`
> from a GitHub delivery. D2 § 8.3.3 owns the objects' **read surface** on the feed, and D3 § 5.7 owns
> their **render**. All three were ratified and all three left one slice to *"the slice that designs the
> coordination store"* ([D1 § 18.13](EVENT-SCHEMA.md#1813-what-this-section-does-not-establish) rows 8–9,
> [D2 § 14](FLEET-STATE.md#14-open-questions-for-the-review-loop) item 14). **This is that slice**, and by
> the operator's answers it also carries card#12240's between-rooms render — now transient, through a
> bird portal — and the seat's roundtable-name declaration. It owns what no ratified document owns: the
> hook **binding** and its provisioning, the fleet-specific findings, the fixture generator that is the
> fixture guard, the failure-mode table, the implementation plan, the sandbox acceptance and the operator
> questions. It **amends** D1, D2 and D3 in place wherever the design changes a claim they publish
> ([§ 12](#12-the-amendment-ledger)). The store's rules live in **[D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)**
> and the render rules in **[D3 § 5.7](FLOOR.md#57-the-coordination-thread-line)**; this document points
> at them and does not restate them. The drawing — the bird, the envelope, the portal — is card#12241's
> designer's, and nothing here draws it.
>
> ⛔ **No real roundtable reference appears in this document** (operator, 2026-10-23, verbatim: *"No
> real roundtable references should be in repo"*): the cross-install repository is written
> `<roundtable repository>`, no thread number, link, quote, delivery id or other fleet's identity is
> copied, and the real binding lives only in the store and the operator's config. card#12260 scrubs the
> repository's existing references and owns the repo-wide guard; this document is written to pass it.

---

## 0. Overview

1. **The operator's rulings of 2026-10-23 are the scope** ([§ 2.4](#24-what-the-operator-decided-on-2026-10-23-verbatim-on-the-card)):
   message animations only, nothing held — *"I choose the bird carrying the envelope"*; a `to:all`
   post is the broadcast ring; an end with no desk draws nothing; a post whose other end is on another
   floor, or outside the building, flies through **a bird portal that is always open**; reduced motion
   is a still envelope that disappears after a time. The thread line (A18), its label, beads, carrier
   tag and *ended* treatment, and the held call marker r1 called A21, are **removed from the design**.
2. **Re-derived from that, the producer, store, feed and client shrink** ([§ 4](#4-what-must-still-be-carried-and-what-goes)):
   one object crosses to the floor, `coord.round`; `coord.thread` is specified and **not published**;
   the store holds bindings, secrets and — per derived delivery — a digest and its receipt time, and
   **nothing else**; there is **no backfill**; the client holds nothing but the identities it has drawn.
3. **The producer is Mezzanine's own GitHub webhook receipt, per D1 § 18.1** — not the bridge. **One
   hook binding per repository, with a `scope`**: `install` (a room's own coordination repository) or
   `fleet` (the `<roundtable repository>`, whose threads span rooms). One route, one derivation, one
   store. **The first live binding is the `<roundtable repository>` at `fleet` scope.**
4. **A seat may declare its roundtable name** beside its protocol agent name — `roundtable_agent_name`,
   checked against the same config's `cross_project.identity` — and a fleet-scope post joins a name to a
   desk through that declaration and nothing else ([§ 3.4](#34-the-roundtable-name-declaration-q2)).
5. **Generation is the guard** ([§ 3.5](#35-synthetic-fixtures-mechanically-q3-r1-f4-f9f12-r2-major-1-major-2)):
   every coordination fixture is produced by a deterministic synthesizer from one small committed spec of
   synthetic names, ids and text; CI regenerates and byte-compares; a narrow lint forbids delivery /
   `coord.*` literals outside the generated directory and the spec. The documents are card#12260's.
6. **The hook secret never passes through this seat** ([§ 3.2](#32-provisioning--one-operator-terminal-act)):
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

**Evidence, read on `dev` (2026-10-23; the branch is since rebased onto card#12187, which touched none
of these):**

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

Read from the sandbox store on 2026-10-23 (read-only, credentials never printed):

| install_id | seat_id | `protocol_agent_name` | check | role |
|---|---|---|---|---|
| `kanban` | `kanban-solo` | `kanban` | `checked` | `solo` |
| `mezzanine` | `mezzanine-solo` | `mezzanine` | `checked` | `solo` |

Both seats **declare** a name and both declarations are `checked` — card#9296's server half and
card#9375's reporter half are live on this fleet. The sandbox's building layout (`building_layout.document`,
read-only) composes **one** floor, *Mezzanine*, holding the `mezzanine` room alone; the `kanban` room is
on no authored floor and takes a floor of its own (D3 § 4.6). So on the sandbox as laid out today the
two seats are on **different floors**, which is the portal case of [§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does),
and the desk-to-desk bird is one admin-console act away ([§ 10](#10-operator-questions) NQ3).

### 2.2 The coordination-repository population on this fleet

**Zero per-install coordination repositories.** `~/.config/coord/coordination.config.json` on this box
has `coordination_repo: null` and a one-member roster (`mezzanine`, role `solo`); the `kanban` install is
kanban-solo's and is the same shape. A solo install has no coordination repo by the framework's own
definition (this repository's `CLAUDE.md` § Explicitly absent). The `install`-scope binding therefore
has **nothing to bind on this fleet today**; it is designed, built and tested by replay all the same.

**One cross-install coordination surface: the `<roundtable repository>`** (named in this seat's
`cross_project.repo`; private; this seat's roundtable identity holds admin). Read via the roundtable
credential this seat holds (`~/.config/coord/credentials.ini`, the token passed to `gh` by environment
and never printed; `xproj-gh` is the same read-only route), 2026-10-23. **Counts and shapes only:**

```
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
   rooms, and a post from another fleet's PM has an origin on no floor of this building. A fleet-scope
   `coord.round` has **no** room and animates where its ends resolve — through the portal where one end
   is elsewhere ([§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does)).
2. **The roundtable identities are not the declared names.** The seat declares `mezzanine`; it posts
   as `mezzanine-solo` (`cross_project.identity`). D2 § 8.3.3 rule 3 forbids any fallback join. The
   operator's answer to Q2 is the second declaration ([§ 3.4](#34-the-roundtable-name-declaration-q2)).
3. **~100 posts a day cross the roundtable**, so a floor with one resolved roundtable seat sees a bird
   several times an hour, and an unwatched floor misses them — by the ruling, a message is an
   animation and an animation is not replayed.

**What a real delivery contains — observed, not vendored.** With admin on the `<roundtable
repository>`, the sandbox bridge's hook exposes its recent deliveries through GitHub's deliveries API.
Read 2026-10-23, keys and sizes only — the capture tool of [§ 3.5](#35-synthetic-fixtures-mechanically-q3-r1-f4-f9f12-r2-major-1-major-2)
is the only sanctioned way to read one in future, and it writes outside the repository:

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
| **Hook creation** | the REST reference states admin for deletion; creation is exercised on this fleet by an admin credential | registering Mezzanine's hook is an outward act on another fleet's repository, performed at the **operator's** terminal with a secret this seat never reads ([§ 3.2](#32-provisioning--one-operator-terminal-act), NQ1) |

### 2.4 What the operator decided on 2026-10-23, verbatim on the card

| Q | Answer | What it changes in this document |
|---|---|---|
| Q1 | *"(a) roundtable first, but messages between PM and impl agents must be supported too"* | the first live binding is the `<roundtable repository>` at `fleet` scope; card#12240's render is folded in; the `install` scope is built and replay-tested in the same slice |
| Q2 | *"yes"* | the optional `roundtable_agent_name` declaration end to end ([§ 3.4](#34-the-roundtable-name-declaration-q2)) |
| Q3 | *"Yes, you must be careful that no real messages enter the repo"* | synthetic fixtures only; at r3, generation is the guard ([§ 3.5](#35-synthetic-fixtures-mechanically-q3-r1-f4-f9f12-r2-major-1-major-2)) |
| Q4 | seat ruling, reversible | hook secrets are encrypted at rest under `APP_KEY` |
| render scope | *"I do not want to show the open connection because there are too many open connections. I only want to show the message animations. I choose the bird carrying the envelope."* | every held coordination render is removed; the chain is re-derived from the per-message edges ([§ 4](#4-what-must-still-be-carried-and-what-goes), [§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does)) |
| NQ6 (a) broadcast | **confirmed**: the ring | A20 stands as the broadcast's render |
| NQ6 (c) no desk | *"draw nothing"* | an end that resolves to no desk anchors nothing; [§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does) decides the case against the portal explicitly |
| NQ6 (b) cross-floor | *"Create a bird portal somewhere that is always open where birds can fly in and out of."* | the cross-floor form is **not** the elevator: A22 is the transient bird to or from the floor's bird portal; card#12241 designs the portal's look; [§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does) says which floor's portal, what a floor with no portal spot does, and that a post from outside the building uses the portal too |
| reduced motion | *"a still envelope that disappears after some time"* | A22's reduced form now, and A19's with the build (its cell is held equal to the module) |
| NQ5 | *"No real roundtable references should be in repo"* — stricter than the exemption list r2 proposed | no issue number, link, quote or private repository name in D5/D1/D2/D3's text of this card; card#12260 scrubs the existing references and owns the repo-wide guard; the docs leg and the exemption list are dropped from this design |

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
| **try every active secret** | HMAC the body once per binding | CPU linear in bindings on an unauthenticated 1 MiB body — the budget D1 § 18.8's step-3 limit exists to bound |
| **look the secret up by `repository.full_name`** | parse the body before verifying it | reorders D1's validation and routes a key lookup on an unverified claim |
| **a path segment names the binding** — `POST /api/ingest/github/{hook}` | `{hook}` is a public, random identifier issued with the binding; it selects the row whose secrets are tried, and the secret decides | one URL per binding; no parse before auth; an unknown `{hook}` is a step-3 refusal |

**Chosen: the path segment.** `{hook}` is `mzh_` + 22 base64url characters, a greppable prefix in the
family of `mzn_` / `mzr_`, **not a secret**, and nothing authenticates on it: an unknown `{hook}` is `401`
inside D1 § 18.8 step 3, counted `coord_signature_invalid`, in the same per-`(route, source IP)` bucket.
(r1 and r2 verified the selector, the bucket and AT-23.)

**The binding's `scope` — `install` or `fleet`** is set at issue, never inferred from a payload, and
decides:

| | `scope: install` | `scope: fleet` |
|---|---|---|
| the binding names | one `install_id` and one repository | one repository and **no** install |
| the object carries | `scope: "install"`, `install_id: "<the binding's>"` | `scope: "fleet"`, `install_id: null` — riding `feed_outbox`'s `NULL` for a fleet-wide message |
| the install-facts input (D1 § 18.3.1) | that install's roster and `shared_identity` | the fleet's **roundtable identity set** — the `from:`/`to:` vocabulary of the repository, copied at issue — and `shared_identity` |
| `targets` for `to: all` | the install's roster less the author | the roundtable identity set less the author |
| the floor joins a name through | `protocol_agent_name`, within the install (D2 § 8.3.3 rule 1) | `roundtable_agent_name`, across every install ([§ 3.4](#34-the-roundtable-name-declaration-q2)) |
| the render | A19/A20 in the one room (D3 § 5.7 property 3) | A19/A20 where the ends resolve; A22 through the portal where one end is on another floor or outside the building ([§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does)) |

D2 § 9's per-subscriber filter admits everything today; what a future per-install ACL does with a
fleet-scope post is named at [§ 11](#11-what-this-design-does-not-establish).

### 3.2 Provisioning — one operator-terminal act

Issuing a binding and registering its hook is an **operator-terminal act**, and the seat never sees the
secret (r2 MAJOR-5: a secret printed to stdout on a seat-run command lands in a transcript, and one
pasted into a `gh` call lands in `argv`). At the operator's own terminal:

```
php artisan mezzanine:coord-hook:issue <repository> --scope=fleet   --roster=<identity,…> --shared-identity=<true|false> \
    --by=<who> --secret-out <path> --hook-request-out <path>
php artisan mezzanine:coord-hook:issue <repository> --scope=install --install=<install_id> --roster=<a,b,c> …   (same options)
gh api repos/<owner>/<repo>/hooks --input <hook-request path>          # the operator's admin credential
```

`issue` creates, in one transaction, the binding row (`coord_hooks`: scope, install or none, repository,
D1 § 18.3.1's install-facts input — `roster[]`, `shared_identity`, the revision/date they were copied
at) and one secret row (`coord_hook_secrets`, ciphertext at rest under the application key — an HMAC key
must be recoverable; **seat ruling on Q4, reversible**). It prints **the URL and nothing secret**. The
secret goes to `--secret-out`, mode 0600, and the complete GitHub hook-creation body — URL, content
type, the `issues` + `issue_comment` subscription, the secret — to `--hook-request-out`, mode 0600, so
`gh api --input` reads it without the secret ever appearing in a command line; **both writers refuse a
path inside any git worktree, failing closed exactly as the capture tool does**. The runbook (README
§ operating, build step 9) says: run both commands yourself, then `shred` the two files.

`--hook=<mzh_…>` adds a **second** secret to an existing binding (rotation with overlap) or replaces its
roster copy, and **refuses a third while two are unrevoked** (r1 F16; AT-D2-29);
`mezzanine:coord-hook:revoke <mzh_…> [--secret=<prefix>]` revokes a secret or the binding. Verification
tries every unrevoked secret of the one binding, at most two. **Mezzanine holds no GitHub credential for
any of this**, and this seat holds no secret for it.

### 3.3 What crosses which boundary (canon #7)

| Hop | Guarantee | DECLARED by | CHECKED by | NAMED as unverifiable |
|---|---|---|---|---|
| GitHub → Mezzanine, the delivery | body bytes are the ones signed under the binding's secret; `X-GitHub-Event` names the event | GitHub's documented delivery contract ([§ 2.3](#23-githubs-side-of-the-hop-read-at-the-vendors-documentation)) | D1 § 18.8 steps 3–6, run by this route | whether a **redelivered** payload is byte-identical to the original; ordering is irrelevant now that each post is its own edge |
| coordination repo config → the binding's install-facts copy | `roster[]`, `shared_identity` | the operator, at `coord-hook:issue`, with the revision copied | `coord_roster_unknown_name` on the first post by an unknown name (D1 § 18.3.1's guard) | a removal from the upstream set (one-directional guard) |
| seat declaration → coordination name, per scope | the name a seat declares equals the name it posts under on repositories of that scope | the seat (D1 § 3.1: `protocol_agent_name` for the install's repository, `roundtable_agent_name` for the fleet's) | the seat's own check — against the roster for the first, against `cross_project.identity` of the same config for the second | that a repository bound at `install` scope is the one the roster describes, and one bound at `fleet` scope is the one `cross_project.repo` names — two operator acts nothing compares |
| another seat's declaration (kanban-solo's) → this floor's join | that seat declares its roundtable name and runs a reporter that sends it | that seat's operator | that seat's own check | from here, nothing — a post to or from that seat animates nothing at its end until its heartbeat carries the name (NQ2) |
| Mezzanine → the browser | the message, the feed | D2 § 8.3.3, § 8.3 | the client's dedupe by identity; AT-D3-29 | nothing new: one deployment, no skew axis (D2 § 8.1) |

### 3.4 The roundtable-name declaration (Q2)

**The gap.** `protocol_agent_name` is the name a seat posts under on its **own install's** coordination
repository, checked against that install's roster. On the roundtable the same seat posts under
`cross_project.identity` — `mezzanine-solo` for `mezzanine` — a different string from a different key of
the same `coordination.config.json`. A fleet-scope post names the second; no declaration carries it;
D2 § 8.3.3 rule 3 forbids guessing the first from the second.

**The declaration, end to end — the same shape as card#9296 / card#9375, one key over:**

| Surface | What changes | Owner |
|---|---|---|
| the seat config (D1 § 3.1) | one optional row, `roundtable_agent_name`, slug, bounded as the protocol agent name is | D1 amendment A8 |
| the check (D1 § 3.1) | at flusher start and on `selftest`, against **`cross_project.identity`** of the config `$COORD_CONFIG` resolves: `checked` when equal; `disagreed` when the config carries one and it differs; `unchecked` when no config is readable **or** it carries no `cross_project` block; `undeclared` when the key is absent, null or malformed | D1 amendment A8 |
| the heartbeat (D1 § 6.14) | `roundtable_agent_name` and `roundtable_agent_name_check`; the counters `roundtable_agent_name_unchecked` / `_disagreed` (§ 9.3) | D1 amendment A8 |
| the ingest registry, the fold, the store (D2 § 6.4), the seat object (D2 § 8.2.1), the delta map | two columns, two members, two entries in `SeatDelta`'s map, both version-bearing | D2 amendment B9; build step 5 |
| the join (D2 § 8.3.3 rule 1) | **a second arm, selected by the object's `scope`**: a fleet-scope name resolves to the ONE seat **of any install the client holds** whose `roundtable_agent_name` equals it with check `checked` or `unchecked`, provided no other seat of any install declares it in any state but `undeclared` — the same two unresolved reasons. The arms never cross | D2 amendment B9 |
| the render (D3 § 5.7 property 1) | the resolve arm reads the arm the object's `scope` selects; an unresolved end gets no bird and is logged, *unchecked* rides the log line | D3 amendment C2 |
| the `selftest` member | `roundtable_agent_name_matches`, the sibling of `protocol_agent_name_in_roster` — added by the build (D1 § 6.14's capped `selftest` bound is re-derived by the gate) | build step 5 |

**The 48 B bound has one figure and four pointers** (r2 MINOR-7). D1 § 6.14's row carries the figure;
D1 § 3.1, D2 § 6.4's column comment, D2 § 8.2.1 and the ingest registry say *the protocol agent name's
bound*; `tools/design/verify-event-schema.py` holds `protocol_agent_name`'s five homes equal today and
gains `roundtable_agent_name` in its loop at build step 5, when the migration and registry homes exist
for it to read — until then the pointers are the guard.

**What it does not do.** It does not join the two names to each other; a fleet-scope `FROM: mezzanine`
(a name written in the wrong vocabulary) resolves nothing. It is not an input to the token binding, the
character seed or `config_fingerprint`. Omitting it is a supported state: a post to or from the seat on
the roundtable animates nothing at its desk, which is exactly today's render.

### 3.5 Synthetic fixtures, mechanically (Q3; r1 F4, F9–F12; r2 MAJOR-1, MAJOR-2)

**Re-derived at r3** (seat ruling after review r2's tripwire: two guard findings recurred r1's classes,
so the guard is not patched again but rebuilt from one principle). **The rule:** no real coordination
message — body, title, label vocabulary, thread number, comment id, delivery id, login — enters this
repository in a fixture or a test. The documents are card#12260's (NQ5, answered).

**The principle: generation is the guard.** A fixture nobody can hand-write cannot carry pasted content.

1. **One small committed spec** — `server/tests/fixtures/coord/spec.json` — holds every synthetic
   value the fixtures may contain, enumerated: the names (`pm`, `coder`, `helper`, `reviewer`, `pm-solo`,
   `coder-solo`, `all`), the repository (`synthetic-org/coordination-fixture`, owner `synthetic-org`,
   host `example.invalid`), the id bases (thread numbers from 900000, comment ids from 9 000 000 000 000,
   delivery GUIDs `00000000-0000-4000-8000-` + a counter), the login `synthetic-user`, a dozen short
   synthetic sentences, and the **scenarios** — each a list of posts by name, scope, `from`, `to` and
   which body lines/labels they carry, including today's `fx-coord`, `fx-elevator`'s A19 variant and
   `fx-refusals`' coordination content, and the receipt's delivery payloads for AT-23–AT-26. The spec
   is small enough to read whole in a review, and reviewed.
2. **One deterministic synthesizer** — `tools/coord/synthesize-fixtures.py` — reads the spec and
   writes **every** coordination fixture into `server/tests/fixtures/coord/generated/`: delivery
   payloads (full GitHub shape, keys and types as observed on a live hook), feed envelopes
   (`coord.round` objects), and the floor fixtures' coordination message arrays. No randomness, no
   clock, no network: the same spec yields the same bytes. Fixtures that are mostly not coordination
   (`fx-elevator`, `fx-refusals`) **reference** their coordination arrays by name
   (`"coord": {"generated": "<scenario>"}`) and the harness loads them from the generated directory;
   the PHP helpers that build messages inline today (`DrivesTheCoordClient::roundMessage()` and its
   kin) load the generated base object and override fields — they do not construct one.
3. **CI regenerates and byte-compares** — `synthesize-fixtures.py --check` writes to a temp directory
   and diffs it against the committed `generated/` tree: any hand edit, any stale file, any file the
   spec no longer names, and any real content reds, because real content cannot come out of a generator
   whose only inputs are the spec's enumerated values.
4. **One narrow lint** — `synthesize-fixtures.py --lint` — forbids, in every file under `server/tests/`,
   `tools/`, `bin/` and `fleet-reporter/` **outside** the generated directory and the spec, a
   **webhook-delivery literal** (a JSON object or PHP/JS array literal carrying `X-Hub-Signature-256` or
   `X-GitHub-Delivery`, or top-level `repository` beside `issue` or `comment`) and a **`coord.*` payload
   literal** (`"t": "coord.round"` / `"coord_round":` / `"coord_thread":` or their PHP/JS spellings). It
   does not read prose, hosts, digits or names.
5. **The capture tool keeps two rules.** `tools/coord/capture-delivery.py` strips every
   `X-Hub-Signature*` header before writing, writes only under `${XDG_CACHE_HOME:-~/.cache}/coord/captures/`
   with mode 0600, prints key paths and sizes and no value, and **fails closed** on the worktree check —
   it writes only when `git -C <dir> rev-parse --is-inside-work-tree` exits 128 with the not-a-repository
   message; exit 0, any other error, or `git` missing refuses. A capture informs a human editing the
   **spec's shape** (which keys a payload carries); nothing copies a capture's values anywhere.

**Dropped from the r1/r2 design, by ruling:** the hashed deny list, the host and digit-run sniffing, the
source scan, the docs leg and its baseline exemption file, the per-field reserved-range rules and the
`[synthetic]` body token — content scans over populations that each round found one member short of.
What replaces them is not a wider scan but the absence of any path by which a fixture can carry content
the spec does not enumerate.

**The selftest** plants: one edited byte in a generated file (→ `--check` red); one generated file
deleted and one added by hand (→ red, both directions); a delivery literal in a temp PHP test and a
`coord_round` literal in a temp JS test (→ `--lint` red); a capture `--out` inside a temp git repository
and one with `git` absent (→ refused); a capture written with a signature header present in the source
(→ absent in the file). Each is seen RED, then the committed tree GREEN.

**What this cannot catch, named:** a real name, id or sentence **typed into the spec** by a human — the
spec is the one place content enters, which is why it is small, enumerated and reviewed; a literal the
lint's patterns do not recognise (a payload assembled by concatenation at runtime); a test that reads a
capture from `~/.cache` by an absolute path; and the captures themselves, outside the repository and a
disclosure if that directory is copied. The documents are outside it by ruling and are card#12260's.

---

## 4. What must still be carried, and what goes

Re-derived from the render rule — *every coordination render is an edge fired by one `coord.round` on
arrival* — surface by surface:

| Surface | Needed | Not needed, and removed from the design |
|---|---|---|
| **the floor** | `coord_round.scope`, `install_id`, `post_ref` (draw once), `from` + `attribution` (origin), `to` (the ring's member), `targets` (destinations); the join through the seat objects' two declarations; a bounded memory of drawn `post_ref`s; the floor's portal spot | any `coord.thread` member; lifecycle; participants; subject; carrier; beads; `declares_close`; both timestamps as renders |
| **the feed** | `coord.round` | `coord.thread` — **specified and not published** (D2 § 8.3's row); the outbox ENUM member stays so publishing it again is code, not a migration |
| **the producer** | D1 § 18.7's derivation from `issues.opened` (the opening post) and `issue_comment.created`, PR comments included; `scope`/`install_id` from the binding; `to`'s fallback = the union of `from:`/`to:` labels less the author | D1 § 18.6's derivation — stands as specification, derived from nothing; `issues.closed`/`reopened` received and ignored (counted); the two participant counters, specified not derived |
| **the store** | `coord_hooks`, `coord_hook_secrets`, `coord_deliveries` — **the digest and its receipt time, 14 days, and no other column** | `coord_threads`, `coord_posts`, any lifecycle or membership rule; and (r2 MAJOR-4) the delivery GUID, the event and action names, and `coord_hooks.last_delivery_at`, which nothing read |
| **the backfill** | **none** — a snapshot never animates (D3 § 6.5) | `GET /api/fleet/coord`, the client's holds and caps |
| **the client** | the `post_ref` memory (1,000, D3 § 12); the fleet arm of `buildJoin`; the A19/A20 triggers reading `scope`; A22 to and from the portal; the event-log line for an unresolved end | `#holdCoord`, `COORD_CAP`'s eviction, `coordModel`'s threads, A18, the held call marker |

The receipt's write path, concurrency primitive and fault discipline are [D2 § 6.12](FLEET-STATE.md#612-the-coordination-store)'s
(r1 F3, r2 MINOR-4 folded there): three tables, one `Outbox::transaction(…, attempts: 3)`,
`INSERT … ON DUPLICATE KEY UPDATE` on the digest so two byte-identical deliveries in flight both answer
`202`, `503` for a store fault and `500 internal` for any other, and a `StoreFault` that carries the
SQLSTATE, the driver code and the digest with no `previous`.

---

## 5. Backfill on connect — none, and why that is derived

A page that has just loaded draws no coordination fact: a snapshot never animates
([D3 § 6.5](FLOOR.md#65-a-snapshot-never-animates)) and, under the ruling, every coordination render
*is* an animation. The same holds for a reconnect and a resync. D2 § 14 item 14 closes on that; D2 § 13
row 53 records the first draft's endpoint as withdrawn. The one state the client keeps is the
`post_ref`s it has drawn, bounded at 1,000 (D3 § 12's repurposed row): a redelivery inside that memory
fires nothing, and one older than it animates again — accepted and stated.

---

## 6. Attribution and the agent-name → desk join

Every name on the object stays a protocol-level name — an install's agent name on an `install`-scope
post, a roundtable identity on a `fleet`-scope one — the object gains no desk reference, the consumer
resolves against the seat population by D2 § 8.3.3's rules through the arm the object's `scope` selects
([§ 3.4](#34-the-roundtable-name-declaration-q2)), and an end that does not resolve anchors no bird and
is reported in the client's event log line for the post (D3 § 5.7 property 1). The **producer** writes
`from`, `to`, `targets` exactly as D1 § 18.7 derives them, with `targets` expanded against the binding's
copied set and against nothing else. A producer that mapped `mezzanine-solo` → `mezzanine` would be the
guessed join card#7957 was filed to prevent.

### 6.1 What the floor renders, and what it no longer does

**Removed by the ruling**, from D3 § 5.7, § 4.6 and § 6.2: the thread line A18 and everything hung on
it — its label, carrier tag, beads and bead count, the *ended* treatment, the participant list — and the
held call marker r1 called A21. A18's § 6.2 row stays with a trigger cell that says it never fires, and
leaves with its `wire/animation-set.js` entry in the build, because the suite holds the two equal in
both directions.

**Kept, per message — the operator's answers of 2026-10-23 confirm each form** ([§ 2.4](#24-what-the-operator-decided-on-2026-10-23-verbatim-on-the-card));
D3 § 5.7 and § 6.2 own the rows, this section is the per-floor rule:

1. **Resolve** the post's origin and every destination through the arm its `scope` selects — to a desk
   **anywhere in the building** for a fleet-scope post, within the room for an install-scope one. Each
   end is then one of three things: **on this floor** (a desk here), **elsewhere** (a desk on another
   floor, or — fleet scope only — no desk in this building at all: a participant of another fleet,
   outside the building), or **unresolved** (install scope: a name no seat of the room declares, a
   duplicate declaration, a name in the wrong vocabulary).
2. **A19, the traveller** — card#12241 draws it as the bluebird carrying the envelope — fires from an
   origin **on this floor** to **each** destination **on this floor**, one traveller per destination;
   rooms may differ.
3. **A20, the broadcast** — confirmed: fires from an origin on this floor when `to` carries the literal
   `all`; one ring, on that floor alone, and no bird per recipient.
4. **A22, the portal bird** — *"Create a bird portal somewhere that is always open where birds can fly
   in and out of."* Every floor has **one portal spot**, always open; card#12241 designs its look and
   its default placement. For a fleet-scope post:
   - an origin **on this floor** whose destination is **elsewhere** sends the bird from its desk **to
     this floor's portal** and it leaves — once per such destination? **No: once per post** — a bird
     that leaves the building carries the envelope for everyone it cannot reach here, so a post to three
     far recipients is one departure, not three (the event log names all three);
   - a destination **on this floor** whose origin is **elsewhere** receives the bird **arriving from
     this floor's portal** at its desk — one arrival per such destination on this floor;
   - **which floor's portal**: always the portal of the **floor being viewed** — the one the local
     resolved end is on; no bird is ever drawn to another floor's portal, and the far floor draws its own
     half from its own portal when it has a resolved end;
   - **outside the building** — a counterpart that resolves to **no** desk on any floor of this
     Mezzanine, which on a fleet-scope repository is another fleet's participant — **uses the portal
     exactly as a far floor does**: the bird leaves for it, or arrives from it. That is the ruling's
     *"fly in and out"*, and it is where a roundtable message from a PM this building does not house is
     seen at all;
   - **a floor with no portal spot** cannot happen by rule: the portal is a property of every floor,
     placed by the floor's room map or the building layout when authored and by card#12241's default
     placement otherwise, exactly as a desk slot is; a map that places none gets the default, and a
     build in which a floor could lack one is red against AT-D3-29;
   - **the unresolved case is decided against the portal**: an end that resolves to **no desk because
     no seat of the arm's population declares it** — install scope's unresolved, and a fleet-scope
     name no seat in the building declares — draws **nothing** (*"draw nothing"*), **except** that on a
     **fleet**-scope post such a name IS *outside the building* and takes the portal. So the two rules
     meet without overlap: install scope, unresolved ⇒ nothing; fleet scope, no desk here ⇒ portal;
     fleet scope, **duplicate declaration** ⇒ nothing (two desks claim it, so it is not outside — it is
     a misconfiguration, logged as such); and the **local** end must always be a resolved desk on this
     floor, or there is nothing to anchor and nothing is drawn.
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
| **A22** (reserved) | `edge` | `portal-bird` — the bird carries the envelope from a resolved origin desk on this floor to the floor's bird portal and leaves, or arrives from the portal at a resolved destination desk on this floor, for a fleet-scope post whose counterpart end is elsewhere — on another floor, or outside the building | desk, floor | `coord.round`, `coord_round.scope`, `coord_round.from`, `coord_round.targets` | one `coord.round` applied with `scope: "fleet"` and this desk resolving as its origin or as a member of its `targets`, where the counterpart end resolves to a desk on another floor or to no desk in the building; one departure per post for an origin here, one arrival per resolved destination here | at the portal (departure) or at the desk (arrival) | a still envelope at the portal or at the desk for the moving form's duration, then gone | no fleet-scope post crossed the building's edge to or from a desk here |

The **look** of the bird, the envelope, the portal, the ring, their sizes, placements and frames is
card#12241's, as the look of A19–A20 always was.

---

## 7. Failure modes — what the floor shows, and what recovers it

| Failure | What the floor shows | Detectable? | Recovery |
|---|---|---|---|
| **a delivery lost** (GitHub timeout, outage, refusal) | one bird that never flew; nothing later shows it | not from the wire; GitHub's delivery list records the failure | operator Redeliver within 3 days; the digest is absent, so the redelivery derives and the bird flies then |
| **`issues.opened` lost** | the opening post's bird never flew; every later comment on the thread still animates | as above | as above |
| **duplicate delivery** (GitHub retry, Redeliver inside 14 days) | nothing — absorbed at the digest, `coord_delivery_duplicate` | yes | — |
| **Redeliver after 14 days** | the delivery re-derives; the client fires nothing if the `post_ref` is in its 1,000-deep memory, and a second bird if it is not | `coord_delivery_received` without a client-visible change | accepted; bounded by GitHub's 3-day reach |
| **concurrent identical deliveries** | one bird | both answer `202`; one counts `coord_delivery_duplicate` | — |
| **a viewer not watching** | nothing, ever, for that post | the client's event log on the page that was open | by ruling: a message is an animation |
| **the local end unresolved** (the seat declares no name of the arm's kind, or two seats declare one) | nothing at that desk — no bird, no ring, no portal; the event log names the reason | the log line; `duplicate_declaration` is reported by the client alone (D2 § 8.3.3) | a declaration on the seat |
| **an install-scope far end unresolved** | the other destinations' birds fly; that one's does not; logged | the log line | a declaration on the seat |
| **a fleet-scope far end outside the building** | the portal bird, by rule | — | by design |
| **ends on different floors** | A22 at each end through each floor's own portal | — | by design; composing the rooms onto one floor turns it into the desk-to-desk bird (NQ3) |
| **a reply with no `TO:` line** | addressed to the thread's other labelled members (the union fallback); before r3 it addressed nobody and drew nothing | `coord_to_from_labels` counts the fallback | — ; the share of such posts is unmeasured ([§ 11](#11-what-this-design-does-not-establish)) |
| **a repository the binding does not name** | `403` at step 6, nothing derived; `coord_signature_invalid` for an unknown `{hook}` | yes | an operator reads the hook's delivery list |
| **a secret leaked** | forged birds on one install's floor — or, for the fleet binding, on every floor — until revoked | `coord_delivery_received.<hook>` spikes | `coord-hook:issue --hook` + `revoke` (overlap rotation, at most two), at the operator's terminal |
| **label churn** and `closed`/`reopened` | nothing — ignored at step 8, no digest, counted `coord_delivery_ignored.<event_type>` | yes | — |
| **the store down during a delivery** | `503 server_error`, nothing committed, no digest; GitHub records a failure | yes — and `fleet.health.db` | Redeliver within 3 days |
| **the binding's roster drifts** | a new identity's broadcasts omit it from `targets` until re-provisioned | `coord_roster_unknown_name` on its first post | `coord-hook:issue --hook` with the new set |
| **a real message in a fixture or a test** | — (not a floor failure) | `synthesize-fixtures.py --check` reds the PR on the first differing byte; `--lint` reds a literal outside the generated directory | the author edits the spec and regenerates |
| **a real value typed into the spec** | — | not mechanically: the spec is the one entry point for content, small, enumerated and reviewed | review |
| **a real roundtable reference in a document** | — | card#12260's repo-wide guard | card#12260 |

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
| **a second message type for the fleet's posts** | a parallel object with no `install_id` | two formats for one fact; `scope` on the existing object costs one slug field and a nullable `install_id` |
| **a separate route for the roundtable** | `POST /api/ingest/roundtable` | the same HMAC, validation, derivation and store — D1 § 18.11's one-producer rule |
| **map `mezzanine-solo` → `mezzanine`** | strip a suffix, or read `cross_project.identity` into a table | the guessed join card#7957 refused; the seat declares, nobody infers |
| **a held call marker for the cross-floor case** (r1's A21) | a marker held while the thread is open | a held render of an open connection — the thing the ruling removed |
| **the elevator as the cross-floor form** (r2's A22) | the bird leaves toward or arrives from the elevator | the operator chose a portal that is *always open*; the elevator is the viewer's ride and has doors that close, and a post from outside the building has no elevator to arrive by |
| **a bird per far recipient through the portal** | N departures for a post to N far recipients | N identical birds through one portal say nothing N times; one departure carries the post out, and the event log names the recipients |
| **a GitHub REST read at hook issue** | a repo credential | Mezzanine would hold a credential for a repository it only observes (D-10), and nothing held would use what it read |
| **a content-scanning fixture guard** (r1/r2's rules R1–R8) | predicates over populations of files | each round found a population one member short (nested fixtures; dependency hosts; the product's own name in 119 files) — a scan is only as complete as its last enumeration, and two rounds tripped the stopping rule on it; a generator with enumerated inputs has no population to miss |
| **a convention, not a gate, for synthetic fixtures** | a README rule | the operator asked for a mechanism |

---

## 9. Implementation plan

Dependency order. Every test is seen RED once before it is trusted; the plant is named with it. D1 § 16
rows 8–10 and D2 Appendix B rows 12–13 are the ratified build rows this plan discharges. **The guard is
first and the roundtable live last**, because every fixture the middle steps author is judged from its
first commit, and the live binding is an outward act that waits on NQ1.

| # | Artifact | Gate (RED plant → GREEN) |
|---|---|---|
| 0a | **Generation is the guard** ([§ 3.5](#35-synthetic-fixtures-mechanically-q3-r1-f4-f9f12-r2-major-1-major-2)): `server/tests/fixtures/coord/spec.json`; `tools/coord/synthesize-fixtures.py` with `--check` and `--lint` and its selftest; the generated directory committed; `fx-coord`, `fx-elevator` and `fx-refusals` moved to reference their coordination arrays by name, `DrivesTheCoordClient`'s helpers loading the generated base object; the CI workflow (`coord-fixtures.yml`, the shape of `asset-provenance.yml`); the capture tool with its two rules | the selftest's plants, each RED then GREEN: an edited byte, a deleted and an added generated file, a delivery literal and a `coord_round` literal outside the directory, a capture inside a temp git repository and with `git` absent, a capture with a signature header; the floor suite green with the fixtures loaded by reference |
| 0b | **Migration**: `coord_hooks` (with `scope`, `install_key`), `coord_hook_secrets`, `coord_deliveries` (digest, `received_at`) per D2 § 6.4; `App\Sweep\Purge::PLAN` gains the digest row, and its guard the 3-day floor | the purge's existing index-order test (D2 § 6.7) reds on a declared order the new index does not carry; AT-D2-17 extended with the digest chain, seen red by purging at 2 days |
| 1 | **Provisioning**: `mezzanine:coord-hook:issue` with `--secret-out` / `--hook-request-out` (0600; refuse inside a worktree, fail-closed) / `revoke`, `App\Coord\HookBinding`, `App\Coord\HookSecret` (encrypt/decrypt, overlap, the two-secret refusal) — both scopes; the runbook in README § operating | a command test: the secret appears in **no** stream and in no column, only in the 0600 file; the hook-request file is the body GitHub's create endpoint accepts; an `--secret-out` inside a temp git repository is refused; `--hook` adds a second secret and verification accepts either until revoke; a third is refused by name (AT-D2-29); `--scope=fleet` with `--install` is refused, and `--scope=install` without it |
| 2 | **Receipt route** `routes/coord.php` (empty middleware list, like `routes/ingest.php`; `CoordRouteIsolationTest` reads the router), `App\Coord\ReceiptPipeline` in D1 § 18.8's order with the `{hook}` selector and step 8's two-member derive set, the per-`(route, IP)` failed-auth bucket — `App\Ingest\RateLimiter::hitFailedAuth()` gains a route key rather than a sibling | **AT-23**, all three REDs; the unknown-`{hook}` `401`; the 61st bad signature from one IP → `429` with 60 from distinct IPs as the control; `issues.closed` answered `202` with no digest and `coord_delivery_ignored.issues` moved (AT-D2-26) |
| 3 | **Derivation** `App\Coord\Derive` — pure functions over `(issue, comment, labels, binding)` for the **round** alone: `openingPost()`, `commentPost()`, body `FROM:`/`TO:` lines, `to` with the **union** label fallback less the author, `targets` against the binding's set, `carrier`, `[CLOSE]` anchored, `App\Support\Slug::AGENT_NAME` validation, `scope` and `install_id` from the binding; PR comments derived as any post | **AT-24**'s round half (plant the `from:` label), **AT-25**'s `to` fallback half with the reply case (plant: `to:` labels alone → the addressee's reply yields `targets: []`), **AT-26**'s per-post half; a fleet binding yields `scope: "fleet"`, `install_id: null` (plant: copy the first install) |
| 4 | **Receipt write** `App\Coord\Receipt` — one `Outbox::transaction(…, attempts: 3)`: the digest `ON DUPLICATE KEY UPDATE` with its affected count, the `CoordRound` enqueue(s) (`installId()` null for fleet scope), `App\Coord\StoreFault` carrying SQLSTATE + driver code + digest and **no `previous`**; `503` for a store fault, `500 internal` for any other | **AT-D2-27** atomicity, the concurrent pair and the leak leg planted on the **outbox** insert; **AT-D2-28** fleet scope asserted on the stream's frames (plant: fill a fleet row's `install_id` from the first install) |
| 5 | **The roundtable-name declaration** ([§ 3.4](#34-the-roundtable-name-declaration-q2)): `fleet-reporter.js` reads `roundtable_agent_name`, checks it against `cross_project.identity`, emits both heartbeat members and the two counters, adds the `selftest` member and re-derives D1 § 6.14's capped bound; `KindRegistry`, `Projector`, `SeatFacts`, `SeatDelta`'s map, the migration (two `seat_state` columns), `SeatObject`; **`verify-event-schema.py`'s per-home bound loop gains `roundtable_agent_name`** | the reporter selftest's four-state cases, each red first; `SeatObjectMatchesTheDocumentTest`'s delta-map leg reds on a version-bearing member the map lacks; `verify-event-schema.py` green on the re-derived bound and red when one of the five homes is edited to 47 |
| 6 | **The join's fleet arm** in `floor/coord-join.js` (`buildJoin` over every install's seats, keyed by `roundtable_agent_name`, duplicates counted across installs), selected by `scope` in `coord-model.js` | the join test extended with **synthetic** names: a fleet-scope post naming `pm-solo` resolves to the seat declaring it in install `aimla` and not to the seat declaring `pm` (plant: fall through to the install arm); two installs declaring `coder-solo` → `duplicate_declaration` (plant: last writer wins) |
| 7 | **Counters**: D1 § 18.8.1's **derived** set in `global_counters` — every row there not marked *specified, not derived*, with `coord_delivery_received.<hook>`; `App\Read\FleetHealth::COUNTERS` gains them, the `<hook>` family enumerated from `coord_hooks` | `FleetHealthCountersMatchTheDocumentTest` reds when a § 7.1 fleet-health counter is missing from the constant; `verify-fleet-state.py` G8 is the doc-side half |
| 8 | **Client** — the removal, listed in full (r2 MINOR-2): `fleet-client.js` loses `#holdCoord`, `COORD_CAP`, `coordTruncated`, `coordMessages` and the `coord.thread` case (and `coord.thread` leaves `KNOWN_TYPES`, so the default branch counts it as unknown — r2 MINOR-3); `coord/coord-model.js` loses `threadRender`, `threadAnimations` and the thread assembly, keeping `roundRender`/`roundAnimations`; `coord/main.js`'s `renderCoordLayer` and its page go; `floor/floor-screen.js` `#coordinate` draws per round, gains the `post_ref` memory (1,000), the event-log line for an unresolved end, the floor's **portal spot** (from the map or layout, else card#12241's default) and A22 to and from it; tests removed or retargeted: `TheCoordinationEnvelopesAreHeldToTheirCapTest`, `CoordRendersTheThreadLineTest`, `TheCoordinationLineResolvesOrRendersUnresolvedTest`, `CoordDrawsNoLineWithoutAJoinTest` (→ *no bird without a join*), `CoordMemberSetMatchesTheDocumentTest`, `CoordModuleWiringTest`, `coord-probe.mjs` and `fleet-client-probe.mjs`'s hold legs, `FeedSurfaceTest`'s `$scoped` coordination leg, and the generated fixtures' `coord.thread` content; A19/A20 triggers read `scope`; **A18 removed from D3 § 6.2 and `wire/animation-set.js`, A22 added to both, A19's reduced-motion cell changed to the still-envelope form in both — all in one commit**; AT-D3-18's line assertions removed; the drawing per card#12241's proposal | **AT-D3-29** through the probes and the fleet-client harness (its REDs, the portal legs included); `TheAnimationSetIsTheDocumentsClosedSetTest` green only when the table and the module moved together (it reds on either half alone — the control); `NoAnimationFiresWithoutItsEventTest` green with A18 gone |
| 9 | **Doc-sync in the same PR**: README § operating (the operator-terminal runbook for `coord-hook:issue` and `gh api --input`, the two declarations), CHANGELOG bullet, `tools/design/README.md` D5 row, the verifier runs, D1 § 6.14's selftest member, D3 § 6.2's A18 removal and A22 row, D3 § 5.7's reserved-row sentence, D3 § 4.6's portal placement | the four verifiers + `verify-design-docs.selftest.py` green at the head; `synthesize-fixtures.py --check` and `--lint` green |

**Acceptance on the sandbox** (the sandbox is this seat's to operate; nothing below touches `main` or
production; every outward act is marked and waits on its question):

1. Deploy the build to the sandbox; `php artisan migrate` adds the three tables and the two columns;
   `GET /api/fleet/health` carries every new counter at `0`; `--check` and `--lint` are green in CI.
2. This seat's reporter: `roundtable_agent_name: "mezzanine-solo"` in `~/.config/fleet-reporter/config.json`,
   artifact replaced, flusher restarted; the next heartbeat's seat object carries the name with `checked`.
3. **Replay, both scopes**: the operator issues a sandbox `install` binding and a sandbox `fleet`
   binding at their terminal (`--secret-out`), and `tests/roundtrip/coord-roundtrip.py`, run by the
   operator with those files, signs the generated fixtures and posts them; `bin/feed-stream-check.sh`
   shows `coord.round` on the stream with and without `install_id`, and **no** `coord.thread`; the
   sandbox store gained digest rows and nothing else. The fixtures' names resolve to no sandbox seat, so
   this step shows the feed and the store, not a bird, and says so.
4. **Live, the roundtable — the broadcast and the portal** (after NQ1's announcement and the fleet's
   agreement; the operator issues the `fleet` binding and registers the hook at their terminal):
   GitHub's *ping* is `202`, counted `coord_delivery_ignored.ping`. This seat's own next `to:all` post
   fires **A20** from its desk on the sandbox floor without a page reload, and — every other recipient
   being elsewhere — **one A22 departure** to the *Mezzanine* floor's portal; the next post **to** this
   seat from another fleet's PM fires A22 arriving from the portal at this desk;
   `coord_delivery_received.<hook>` moves. A direct post to or from kanban-solo fires A22 here too
   (kanban-solo is *elsewhere* — undeclared, it is *outside the building* to the fleet arm) — and once
   NQ2 lands it fires A22 at both ends through each floor's portal.
5. **Live, the traveller** (after NQ3 composes the two rooms onto one floor, and NQ2): the same direct
   post fires A19 desk to desk.
6. Redeliver one delivery from GitHub's list: `coord_delivery_duplicate` moves, nothing flies.
7. The `install` scope has no live repository on this fleet; step 3 is its acceptance (NQ4).

**`Built:` for the build PR** is recorded on the card at each dispatch, per `built-line.md`.

---

## 10. Operator questions

**Answered 2026-10-23** — Q1 *(a) with per-install support kept*, Q2 *yes*, Q3 *yes, synthetic only with
a mechanical guard*, the render scope, NQ5 (*no real roundtable references in the repo*; card#12260
owns the scrub and the guard), NQ6 (*the ring; draw nothing; a bird portal; a still envelope under
reduced motion*); Q4 ruled by the seat. **Open:**

**NQ1 — Registering Mezzanine's hook on the `<roundtable repository>`: what the fleet is asked to
accept, and who acts.** Context (r1 F14, r2 MAJOR-4 and MAJOR-5): the `<roundtable repository>` is a
**shared private repository** of four fleets. Under this design Mezzanine would receive every delivery
and retain, of other fleets' threads, **exactly one row per derived delivery — a 64-byte SHA-256 of the
signed body and the time it was received — for 14 days**, which is a record that *something was posted,
and when*, and no other column ([D2 § 6.4](FLEET-STATE.md#64-ddl) `coord_deliveries`); and it would put
on its feed, **transiently (60 s in `feed_outbox`) and to every signed-in viewer of this Mezzanine**,
each post's addressing — the sender's and recipients' roundtable identities, the thread reference and
the post reference. That is other fleets' coordination metadata and timing on a server they do not
operate; it needs their agreement, not only the operator's. **Issuing the binding and registering the
hook is the operator's own terminal act** ([§ 3.2](#32-provisioning--one-operator-terminal-act)).
- *(a) Announce first; then the operator issues and registers on the sandbox.* Example: an
  `[ANNOUNCE]`/`[FR]` from `mezzanine-solo` to the fleet's PMs (`to:all`) stating the paragraph above
  and the hook's URL pattern; on no objection within the roundtable's follow-up window, the operator
  runs the two commands of [§ 3.2](#32-provisioning--one-operator-terminal-act) at their terminal.
  Cost: a wait of days; a fleet may decline, in which case the roundtable is not bound and the install
  scope carries the design until a PM-and-impl install reports here.
- *(b) Register on the sandbox now and announce after.* Cost: the fleet's metadata reaches a server it
  did not agree to before it could object — the wrong order for an outward act on a shared surface.
- *(c) Announce, and offer a narrower feed.* Example: the same post offering that the fleet binding's
  feed carry opaque digests in place of `thread_ref`/`post_ref`. Cost: the event log would name no
  thread; the store's row is already the minimum.
- **Recommendation: (a)** — this seat posts the announcement (its routine coordination traffic); the
  operator issues and registers at their terminal once the window closes, recorded on the card.

**NQ2 — kanban-solo's end of the traveller.** Context: the join resolves a roundtable name only to a
seat that **declares** it; kanban-solo's seat config and reporter are kanban-solo's to change. Until it
declares, kanban-solo is *outside the building* to the fleet arm: a direct post between the two seats
fires A22 at **this** desk through this floor's portal and nothing on the `kanban` floor; the event log
line names the undeclared end. Broadcasts from this seat ring.
- *(a) Ask kanban-solo, on the roundtable, to add `roundtable_agent_name: "kanban-solo"` and take the
  reporter release that carries the field.* Example: an `[FR]` from `mezzanine-solo` to `kanban-solo`
  that can ride NQ1's announcement. Cost: a post and a wait.
- *(b) Accept the one-ended render for now.* Cost: the operator's stated want — the bird between the
  two — is not visible until (a) happens anyway.
- **Recommendation: (a)**, with NQ1's post.

**NQ3 — Compose the `kanban` room onto the *Mezzanine* floor?** Context: the sandbox layout holds one
authored floor with one room ([§ 2.1](#21-installs-and-seats-on-the-sandbox)). With the rooms on
separate floors a post between the two seats fires A22 at each end through each floor's portal; with
the rooms composed onto one floor it fires the desk-to-desk bird. An admin-console act, the operator's.
- *(a) Compose them now.* Example: the first live traveller flies desk to desk across the two rooms.
  Cost: the portal form between these two is unexercised on the sandbox until a third room exists —
  though posts from other fleets' PMs exercise it regardless.
- *(b) Leave them apart until the portal form is seen between the two, then compose.* Cost: two
  acceptance passes.
- **Recommendation: (a)** — unlike r2's reasoning, the portal form no longer needs these two rooms
  apart to be seen (every other fleet's post arrives through it), so the sandbox can show the
  desk-to-desk bird sooner.

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
| **what a per-install ACL on the stream does with a fleet-scope post** — D2 § 14 item 7 says fleet-read is all-or-nothing for now | a client scoped to one install would, under a naive filter, lose every roundtable bird or see all of them | decided when that ACL is designed. The server resolves no name (D2 § 13 row 49: the join is the consumer's), so a filter can read only the binding, never the ends; the options are therefore all-or-nothing **per fleet binding** (r2 MINOR-6) |
| **that `cross_project.identity` is where every seat's roundtable name lives** — true of this install's config; the framework owns the key | a seat on a differently-shaped config checks against nothing and reports `unchecked` — a supported state | carried to the framework's owner with D1 § 3.1's existing resolution-order contract |
| **what a redelivered payload is, byte for byte** — GitHub's documentation is silent | a redelivery outside the digest's absorption derives twice; the client's `post_ref` memory absorbs it inside 1,000 posts | observe one Redeliver on the sandbox (acceptance step 6) |
| **the `coord_delivery_received.<hook>` family's member set on `GET /api/fleet/health`** is the set of bindings at request time — a revoked binding's member stays | a stale member reads `0`-moving forever | stated, not closed: D2 § 7.2's never-reset rule applies |
| **that the spec holds only synthetic values** — the one place content enters the fixtures | a real name or id typed into the spec is generated into every fixture | review of the spec, which is small and enumerated by design; no mechanism reads intent |
| **what fraction of label-fallback posts are the addressee's reply** (r2 MAJOR-7) | the corrected fallback changes `targets` on an unknown share of the 30.1 % of posts with no `TO:` line | **unmeasured**, by rule: this repository holds no replay corpus of coordination posts (`server/tests/roundtrip/` replays seat events only) and the design stores no body; the live count is `coord_to_from_labels` |
| **the seat object's worst-case headroom is 15 bytes** — the two declaration members move D2 § 8.2.1's worst-case delta to 6,862 B, so D3 § 8.1's intern-cap arithmetic puts the worst case at a cap of 13 at **8,177 B against the 8,192 B bound** (re-derived by both gates; r1 and r2 verified it as real headroom) | the next nullable member anyone adds to the seat object breaks D3 § 8.1's *could reach 13* arithmetic at once | stated here so the next card that touches the seat object reads it before its own design review |
| **the fleet's agreement to the roundtable binding** | the hook is registered on a shared repository whose other users did not agree | NQ1's announcement, before any registration |
| **the portal's placement on a floor** — a render fact, card#12241's; this design requires only that every floor has one | a floor whose map and layout name no spot and whose default is unplaced would have a bird with nowhere to go | the rule in [§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does) item 4 — a default placement per floor is a condition of the build, held by AT-D3-29 |

---

## 12. The amendment ledger

Every edit this slice makes to a ratified document, with the claim it changes and why. Each is a diff
to the owning document; none is a parallel restatement. Entries superseded within the card's own
rounds are marked.

| Doc · section | What changed | Why |
|---|---|---|
| D1 § 18.8 (A1) | the route is `POST /api/ingest/github/{hook}`; an unknown `{hook}` is a step-3 `401`; a binding has a `scope`; step 8 derives from `issues.opened` and `issue_comment.created` only; the delivery header's value is **not stored** (r3) | [§ 3.1](#31-the-binding-its-scope-and-why-it-is-selected-by-a-path-segment); the render-scope ruling; r2 MAJOR-4 |
| D1 § 18.8 step 7 (A2) | *"this document mints no retention number"* points at D2 § 6.7's 14 days | the digest store exists |
| D1 § 18.6 / § 18.7 (A3) | both field tables gain `scope` and a nullable `install_id`; § 18.6 is marked specified-and-not-published; the r1-era comment-carried triggers are withdrawn | the operator's Q1 and the render-scope ruling |
| D1 § 12.7 (A4) | the coordination route's **derived** counters enrolled by pointer rows | the plane that stores them now exists; r2 MAJOR-3 |
| D1 § 18.13 (A5) | rows 1–5, 8, 9 updated with the observed delivery, GitHub's documented facts and the two closures; row 2's hazard noted as having no consumer | the rows' own closing acts |
| D1 § 18.2 (A6) | the bridge checkout row notes the re-read at v0.106.0 | SOURCE-READ's axis is the revision |
| D1 § 15 (A7) | decisions 55 (the selector), 56 (the scope), 57 (`coord.thread` not published), 58 (the fallback union), 59 (PR comments) | decisions taken |
| D1 § 3.1 / § 6.0 / § 6.14 / § 9.3 / § 18.3.1 / § 18.11 / § 18.12 (A8) | the `roundtable_agent_name` declaration end to end; the install-facts input per scope; the join's consumer named as the traveller | the operator's Q2 and Q1 |
| D1 § 18.7 / § 18.8.1 (A9, r3) | `to`'s label fallback is the union of `from:`/`to:` labels less the author; a PR comment is derived as any post; the two participant counters marked *specified, not derived* | r2 MAJOR-7, MAJOR-3 and the PR-comment residual |
| D2 § 2.1 / § 2.2 (B1) | the **coordination receipt** process row and its fail posture (`503` store fault, `500 internal`) | add a process, add a row; r2 MINOR-4 |
| D2 § 6.4 (B2) | three tables (bindings with `scope`, secrets, digests — the digest table reduced to digest + receipt time, r3) | the store; r2 MAJOR-4 |
| D2 § 6.7 (B3) | the digest retention row and chain | retention stated where retention lives |
| D2 § 6.12 (B4) | **new** — the store's rules: the write path and its concurrency primitive, the fault discipline, what is deliberately not held, exactly what is retained | r1 F3; r2 MAJOR-4 |
| D2 § 7.1 / § 8.2.4 (B5) | the derived set's rows (`coord_delivery_received.<hook>`; the two participant counters removed, r3), the fleet-health list, the two counts the gate re-derives | counter closure; r1 F13; r2 MAJOR-3 |
| D2 § 8.2 (B6) | the *no fifth endpoint* paragraph records the first draft's endpoint as withdrawn | the render-scope ruling |
| D2 § 8.3 / § 8.3.3 / § 8.4 (B7) | `coord.thread` marked not published; `scope` on both objects and `install_id` nullable; the fleet arm of the join; `to`'s union fallback; the no-snapshot paragraphs on the new reason | the ruling; the operator's Q1 and Q2; r2 MAJOR-7 |
| D2 § 12 / § 13 / § 14 / AT / App. B (B8) | numbers; decisions 52–58 in order (40 restored; 54, 55, 58 withdrawn); item 14 closed; AT-D2-26…29 (27's leak leg on the outbox insert; 28 on the stream's frames); build rows 12–13 | bookkeeping; r2 MINOR-1, MINOR-4, MINOR-5 |
| D2 § 6.4 / § 6.5 / § 8.2.1 (B9) | `roundtable_agent_name` and its check: two columns, the version-bearing row, two seat-object rows; the worked examples' size figures re-measured | the operator's Q2 |
| D3 § 2.2 (C1) | the r1-era steps 2c/3c/5c withdrawn; the list stands as before | no backfill |
| D3 § 4.6 / § 5.7 / § 6.2 / § 6.5 / § 12 / § 14 / AT (C2) | the between-rooms form made transient and, at r3, routed through the bird portal; § 5.7 rewritten as `coord.round`'s render map under the ruling; A18's trigger cell retired, A19/A20's triggers read `scope`; § 12's envelope hold repurposed as the `post_ref` memory; items 24, 25, 40; AT-D3-18 annotated, AT-D3-29 rewritten | the render-scope ruling; NQ6's answers; the suite's two-way guard on § 6.2 |
| D3 § 5.6 (C3) | null-render rows for the two declaration members | G10 |
| D3 § 8.1 / § 12 (C4, r3) | `⌊1,330 ÷ 263⌋` | r2 MINOR-10 |
| `tools/design/verify-fleet-state.py` (r3) | the two counter-count regexes capture hyphenated number words the gate's own `WORD` map already held | a gate whose map and regex disagreed could never accept *twenty-eight* |
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
| F4 | MAJOR | **Re-dispositioned at r2 (MAJOR-1)** — the r2 fold left nested fixtures outside the population; r3 removed the population: every coordination fixture is generated | [§ 3.5](#35-synthetic-fixtures-mechanically-q3-r1-f4-f9f12-r2-major-1-major-2) |
| F5 | MAJOR | **Re-dispositioned at r2 (MAJOR-2) and answered by the operator (NQ5)** — no docs leg in this design; card#12260 owns the scrub and the repo-wide guard | [§ 2.4](#24-what-the-operator-decided-on-2026-10-23-verbatim-on-the-card) |
| F6 | MAJOR | **Folded as directed.** The render rule is unchanged (an end must resolve); the acceptance waits for NQ2 for the two-ended render | [§ 9](#9-implementation-plan); NQ2 |
| F7 | MAJOR | **Moot by ruling** — no held lifecycle | — |
| F8 | MINOR | **Folded.** D1 § 18.6's lifecycle row is true under the ruling | D1 § 18.6 |
| F9–F12 | MINOR | **Moot by re-derivation** — the rules they corrected no longer exist; the capture tool keeps F11's two rules | [§ 3.5](#35-synthetic-fixtures-mechanically-q3-r1-f4-f9f12-r2-major-1-major-2) |
| F13 | MINOR | **Folded.** `coord_delivery_received.<hook>` | D2 § 7.1, § 8.2.4 |
| F14 | MINOR | **Re-dispositioned at r2 (MAJOR-4)** — the retained data is now exactly the digest and its receipt time, and NQ1 says so | NQ1; D2 § 6.12 |
| F15 | MINOR | **Moot by ruling** — nothing held, nothing to close | [§ 7](#7-failure-modes--what-the-floor-shows-and-what-recovers-it) |
| F16 | MINOR | **Folded.** The two-secret refusal; AT-D2-29 | D2 § 6.12, AT-D2-29 |
| hygiene | — | **Noted.** The card comment's numbering swaps Q2/Q3; this document follows the operator's answers | [§ 2.4](#24-what-the-operator-decided-on-2026-10-23-verbatim-on-the-card) |

---

## 14. Design review r2 — disposition per finding

Reviewer's report: `~/.cache/coord/card-12239/review-r2.md`, on the r2 commit (pre-rebase id). The
tripwire — three findings recurring r1 classes, two in the fixture guard — made this the last round
under the stopping rule, and the guard was re-derived rather than patched.

| # | Severity | Disposition | Where |
|---|---|---|---|
| MAJOR-1 | recurs r1 F4 | **Re-derived.** No population to select: every coordination fixture is generated from the spec, including `fx-coord` / `fx-elevator` / `fx-refusals`' coordination content, which the fixtures reference by name; CI byte-compares | [§ 3.5](#35-synthetic-fixtures-mechanically-q3-r1-f4-f9f12-r2-major-1-major-2) |
| MAJOR-2 | recurs r1 F5 | **Re-derived, and the docs answered by the operator.** No content scan over sources or docs; the lint forbids delivery / `coord.*` literals outside the generated directory and the spec, and nothing else; the documents are card#12260's, and this card's text carries no real roundtable reference | [§ 3.5](#35-synthetic-fixtures-mechanically-q3-r1-f4-f9f12-r2-major-1-major-2); [§ 2.4](#24-what-the-operator-decided-on-2026-10-23-verbatim-on-the-card) |
| MAJOR-3 | — | **Folded.** The two participant counters are marked *specified, not derived* at D1 § 18.8.1 and removed from D1 § 12.7, D2 § 7.1, § 8.2.4 and the build; the counts are stated as derivations (the two words D2 must carry are re-derived by the gate) | D1 § 18.8.1, § 12.7; D2 § 7.1 |
| MAJOR-4 | recurs r1 F14 | **Folded.** `coord_deliveries` is digest + `received_at` only; `coord_hooks.last_delivery_at` dropped; D1 § 18.8 no longer stores the header's value; NQ1 states exactly what is retained | D2 § 6.4, § 6.12; NQ1 |
| MAJOR-5 | — | **Folded.** `coord-hook:issue --secret-out` / `--hook-request-out` (0600, refuse inside a worktree, fail-closed) and `gh api --input`, at the operator's terminal; the seat never sees the secret; runbook in build step 9; NQ1 names the actor | [§ 3.2](#32-provisioning--one-operator-terminal-act); NQ1 |
| MAJOR-6 | — | **Folded, and answered by the operator the same day.** The three interpretations were put as NQ6 with options and recommendations; the operator confirmed the ring, ruled *draw nothing* for an end with no desk, and replaced the elevator with a **bird portal**; [§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does) states the portal rule (which floor's, no-spot, outside the building, the unresolved case against the portal) and the reduced-motion form | [§ 2.4](#24-what-the-operator-decided-on-2026-10-23-verbatim-on-the-card); [§ 6.1](#61-what-the-floor-renders-and-what-it-no-longer-does); D3 § 4.6, § 5.7 |
| MAJOR-7 | — | **Folded.** The fallback is the union of `from:`/`to:` labels less the author (D1 § 18.7, D2 § 8.3.3); the reply-case fraction is **unmeasured** — no replay corpus exists in this repository — and bounded by the 30.1 % fallback rate | D1 § 18.7; [§ 11](#11-what-this-design-does-not-establish) |
| MINOR-1 | — | **Folded.** Row 40 restored (stands, with its history); row 58 moved after 57 | D2 § 13 |
| MINOR-2 | — | **Folded.** Build step 8 lists every module and test the removal touches; § 1 cites `roundAnimations()` and AT-D3-18's harness | [§ 9](#9-implementation-plan) step 8; [§ 1](#1-the-problem-in-the-products-terms) |
| MINOR-3 | — | **Folded.** `coord.thread` leaves the client's `KNOWN_TYPES` and its case | [§ 9](#9-implementation-plan) step 8 |
| MINOR-4 | — | **Folded.** The leak leg plants on the outbox insert; `StoreFault` has no `previous`; `500 internal` vs `503` store faults | D2 § 6.12, § 2.2, AT-D2-27 |
| MINOR-5 | — | **Folded.** AT-D2-28 asserts on the stream's frames for a client holding no install; the RED fills a fleet row's `install_id` from the first install | D2 AT-D2-28 |
| MINOR-6 | — | **Folded.** The ACL row says the server resolves no name, so a filter reads only the binding | [§ 11](#11-what-this-design-does-not-establish) |
| MINOR-7 | — | **Folded.** One figure, four pointers, and the gate's per-home loop gains the field at build step 5 | [§ 3.4](#34-the-roundtable-name-declaration-q2) |
| MINOR-8 | — | **Moot by re-derivation** | [§ 3.5](#35-synthetic-fixtures-mechanically-q3-r1-f4-f9f12-r2-major-1-major-2) |
| MINOR-9 | — | **Moot by re-derivation and NQ5's answer** — nothing hashes an identity; the documents carry none | [§ 2.4](#24-what-the-operator-decided-on-2026-10-23-verbatim-on-the-card) |
| MINOR-10 | — | **Folded.** `⌊1,330 ÷ 263⌋` at D3 § 8.1 and § 12, and the G3 row | D3 § 8.1, § 12 |
| residual: contention | — | **Folded.** *never contend* narrowed to *share no row; InnoDB's own gap-lock `1213` is what the three attempts absorb* | D2 § 6.12 |
| residual: PR comments | — | **Decided.** A comment on a pull request is derived as any post; `thread_ref` unchanged | D1 § 18.7, § 15 row 59 |
| residual: behind `dev` | — | **Done.** Rebased onto `origin/dev` at card#12187 with no conflicts; the three gates pass on the rebased tree | — |
| residual: selftest | — | The slow selftest passed on the pre-rebase r2 tree (71 plants caught); it is re-run on the r3 commit | report |

**Removed from the design by the rulings, for the record:** the thread line A18 (row retired in place,
removed with its module), its label, beads, carrier tag and ended treatment; the held call marker (r1's
A21); the elevator form (r2's A22, replaced by the portal); `coord.thread` as a published message; the
thread/post store, its lifecycle and membership rules and counters; the backfill endpoint and the
client's envelope hold; D2 § 8.4's coordination steps and D3 § 2.2's steps 2c/3c/5c; the content-scanning
fixture guard and its docs leg.

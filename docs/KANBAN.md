# Kanban automation

How this repo talks to its kanban board, what an operator must set up before any of it works,
and the failure modes that are **silent** if you skip a step.

The board is **board 14** on the fleet kanban. Its numeric id lives in exactly one place —
`.release-pr.json` → `promote.board_id` — and is not restated anywhere else in the repo.

---

## Wiring state — measured 2026-08-23

The bridge half of this is **already done**; the sections below describe the whole chain, so
read them as reference, not as a to-do list. What is true right now:

| Piece | State | How it was checked |
|---|---|---|
| board 14 structure | ✅ live | 8 stages, 5 card types, 10 custom fields, 4 swimlanes; 11 cards seeded |
| bridge `writeback.json` mapping | ✅ deployed | validated through the bridge's own loader in a temp config dir **before** deploying (G-4 makes a bad edit fail every repo closed), with two negative controls proven to throw; `bridge:check` green afterwards |
| bridge webhook HMAC secret | ✅ written | `<secret_dir>/github/webhook-secret-scope-PupFuzz%2Fmezzanine`, mode 0600 |
| `KANBAN_WRITEBACK_TOKEN` + `KANBAN_EXPECTED_HOST` | ✅ set, and the token's **identity is now checked every run** | the secret's *value* is still unreadable from an agent seat, but it no longer has to be: the job's identity preflight prints which kanban user it authenticates as and fails the run if that is not the account `.release-pr.json` declares (G-17). It had held the **wrong account** since the chain was wired |
| **repo webhook** | ✅ live | created with **Pull requests + Pushes** (G-13); `ping`, `pull_request` and `push` deliveries all observed `200 OK` from the bridge |
| the mover, against board 14 | ✅ dry-run proven **both ways** | a Backlog card reports `⊘ not a Shipped-class source stage — SKIPPED`; the same card staged to *Shipped to dev* reports `→ would move 107 → 108`. A one-directional check would not have distinguished a working guard from a broken mover. |
| a successful live promote | ✅ run, not a dry run | run `34674965476` (2026-09-12, `DRY_RUN: false`) moved three real cards — `✓ card#7334: moved 107 → 108` … `3 moved, 0 already-released, 0 stage-guarded, 0 no-card, 0 failed` — and the board reads 108 for all three. It had never happened before 2026-09-09 because of G-17 |

⚠ **The bridge's writeback token is a different credential from `KANBAN_WRITEBACK_TOKEN`.**
`bridge:check` proving the former sees board 14 says nothing about the latter. That used to
leave G-1 open for the CI token until a dispatch reported a non-zero census; it no longer
does — the job's identity preflight names the CI token's user on **every** run (G-17), which
is a stronger answer than a census, because a census cannot tell a member from the *right*
member.

---

## What ships here

| File | What it is |
|---|---|
| `.github/workflows/card-token-lint.yml` | PR gate. Rejects a card-token spelling the correlators cannot parse. **Needs no credential — live as soon as it lands.** |
| `.github/workflows/release-promote-cards.yml` | On a push to `main` (a release landing) or a manual dispatch, promotes the board cards named in the released range. **Inert until the secret and variables below exist — it fails loudly, it does not skip.** |
| `bin/promote-cards-by-token` | The mover the release workflow runs. **Vendored** from `PupFuzz/agent-board-framework`; its header carries the provenance and the re-vendor recipe. `--help` prints the full contract, including the exit table. |
| `bin/promote-cards-by-token.selftest.sh` | The mover's hermetic acceptance suite — stubbed `curl`, fixture git repo, no network, no board. Runs in the release workflow before any write. |
| `bin/card-token-lint.py` | The lint the PR gate runs. Extracts the accept grammar from the mover at run time; it does not carry its own copy. |
| `bin/card-token-lint.selftest.py` | The lint's RED fixtures plus a meta-control. Runs in the PR gate. |
| `.release-pr.json` | Board id, the released stage, and the shipped-stage source set. Read by the mover — and, for `tag_format` only, by `bin/release-pr-guard.py` (card#8174), so that key has two readers and this table is not the whole list. Its own `_note` is. |

---

## Operator setup — what must be set, by name

Two GitHub Actions **variables** and one **secret**, on `PupFuzz/mezzanine`:

```
gh secret   set KANBAN_WRITEBACK_TOKEN --repo PupFuzz/mezzanine     # a kanban API token
gh variable set KANBAN_API_BASE        --repo PupFuzz/mezzanine --body 'https://<kanban-host>/api/v3'
gh variable set KANBAN_EXPECTED_HOST   --repo PupFuzz/mezzanine --body '<kanban-host>'
```

| Name | Kind | Why it is that kind |
|---|---|---|
| `KANBAN_WRITEBACK_TOKEN` | **secret** | The bearer token the mover sends. Its kanban user must be a **member of board 14 with move permission** — see G-1. Never printed by any workflow here. |
| `KANBAN_API_BASE` | **variable** | The real API base. The committed `.release-pr.json` value is a host-scrubbed **placeholder**; the real one arrives out-of-band, so a PR editing that file cannot redirect the token by itself. |
| `KANBAN_EXPECTED_HOST` | **variable** | The **only** host the token may be sent to, checked before any request. It has **no baked default**: unset ⇒ the mover refuses and the token is never sent. |

⚠ **Which account that secret must be is declared in the repo, not left to trust.**
`.release-pr.json` → `.promote.writeback_user_id` holds the expected numeric kanban user id,
and the job refuses to promote unless the secret authenticates as exactly that user (G-17).
It is **not** a fourth thing to set by hand — it is committed board state, re-read from
`GET /api/v3/boards/14/members.json` if the writeback account ever changes. Unlike `api_base`
it addresses no host and can send a credential nowhere, which is why it lives in the
PR-editable file and the other three do not.

**Why `KANBAN_EXPECTED_HOST` is a variable and not a literal in the workflow.** The guard
exists to catch an `api_base` edit redirecting the writeback token to an attacker host. If the
expected host were a literal in `release-promote-cards.yml`, ONE pull request editing both that
file and `.release-pr.json` to a lookalike host (`kanban.example.com.evil.test`) would satisfy
the guard and hand over the token — the workflow file is exactly as PR-editable as the config
file. A repo variable takes repo-settings write, which is outside the code-review plane.
Any host written in this repo's prose is **documentation of the current value, never the
constraint**. The constraint is whatever `vars.KANBAN_EXPECTED_HOST` holds, and changing it is
a credential-scope change.

There is a third, **out-of-repo** half to the chain: the webhook bridge, which moves a card as
its PR opens and merges. It needs a repo webhook and a `PupFuzz/mezzanine` mapping in the
bridge's `writeback.json`. Neither is configured by anything in this repo — see G-13 and G-4.

---

## The card token

A PR is correlated to its card by a `card#<id>` token in the **PR title or head branch**, and
a release is correlated by the same token in the **squash-commit subjects** it carries onto
`main`. Practical rule: put `card-7343` in your branch name and `card#7343` in your PR title.

This doc deliberately **does not restate the grammar** — a doc that states a grammar drifts
from the code silently, and this whole page exists because of silent drift. The accept-set is
owned upstream by `PupFuzz/agent-board-framework`
`plugins/coord/docs/BRIDGE-WRITEBACK.md § The card#<task-id> convention`; the one machine copy
in this repo is `CARD_RE` in `bin/promote-cards-by-token`, which the lint reads at run time.
If you want the verdict on a specific spelling, ask the lint:

```
python3 bin/card-token-lint.py --branch 'feat/card-7343-thing' --title 'floor camera (card#7343)'
```

---

## Gotchas that bite THIS setup

Numbering follows the fleet's kanban↔GitHub chain report, so a reader can cross-reference.

### G-1 — a token whose user is not a board member fails *silently and positively*
The kanban scopes card lookups to the token-user's accessible boards, so a **non-member gets
HTTP 200 with empty data**. Every correlation resolves to "no card" and every move no-ops. It
does not look like an auth failure; it looks like a release that named no cards.
**Before trusting a promote run, confirm the token's user is a member of board 14 with move
permission.** The mover's own residue report will say `UNAVAILABLE` rather than "0 stranded"
on a blind read, which is the signal to check membership.

⚠ **G-1 is NOT what actually bit this repo — see G-17.** The observed failure was the opposite
shape: the cards resolved fine and the *move* was refused `HTTP 403`. Reading G-1 as the
complete statement of "wrong token" is what sent card#9146 looking at the board's permission
model three times. **A token can be a full board member and still be the wrong account.**

### G-17 — the secret can hold the WRONG ACCOUNT'S token, and everything else looks fine
This is what broke every promote run from 2026-08-24 to 2026-09-09.
`secrets.KANBAN_WRITEBACK_TOKEN` held a token for kanban **user 10** — a real, current member
of board 14 with `board_custom {create, update, move}` — instead of this repo's writeback
account, **user 15**. Every preflight passed (the secret was non-empty), every card resolved
(the account can read board 14), and then every single `PATCH /tasks/<id>.json` came back
`HTTP 403`, which the mover reports as `✗ card#NNNN: move failed (HTTP 403) — left in place`
with no attribution. Measured across the one variable, on the workflow itself:

| run | the secret authenticates as | the move |
|---|---|---|
| `34417338664` (2026-09-09 23:31) | user 10 | `✗ card#9077: move failed (HTTP 403)` |
| `34417760754` (2026-09-09 23:37, after the secret was re-set at `23:37:31Z`) | user 15 | `✓ card#9078: moved 107 → 108` |

⛔ **Do not read "user 10 is refused" as a rule about roles or destination stages.** Both
accounts are `board_custom` with identical `custom_permissions` today
(`GET /api/v3/boards/14/members.json`), so the board role does not explain it and **this repo
has not established why user 10 is refused** — that question belongs to that account's owner.
What is established, and all that this repo needs, is that **the identity is the discriminator**.

**The guard.** `release-promote-cards.yml` now asks `GET /users/current.json` before the mover
runs and fails the job unless `.data.id` equals `.promote.writeback_user_id` in
`.release-pr.json`. It prints the two ids and nothing else from that response. A mis-rotated
secret is now one named error line instead of a board-permission investigation.

**Seen to fail before it was trusted.** Run `34674557403` declared `writeback_user_id: 99`:
`::error::the writeback secret authenticates as kanban user 15, but .release-pr.json declares
the writeback account is user 99` — job red, promote step never reached. Runs `34674802211`
and `34674965476` then promoted real cards with the declaration correct; `34674965476` is the
one that ran the bytes this repo ships.

⚠ **The backlog the 403 created is only partly drained.** Cards that shipped while the chain
was broken were never promoted and the mover cannot resurrect them from a later release's
range — the token that named them is behind the base. Those two runs promoted `card#7455`,
`7456`, `7457`, `7521`, `7334`, `7335` and `7337`, each re-verified as a `card#<id>`-tokened
commit reachable from `main`. **The rest are not done** — re-derive what is left from
`kbcard --board mezzanine list --column shipped_to_dev` against `git log origin/main`, and
verify each against `main` before promoting it: `--cards` skips range derivation, not the
Shipped-class stage guard, so the guard will stop a backlog card but not a wrong-but-shipped
one.

### G-2 — an un-derivable range is REFUSED, never guessed; `--base` is the escape
The mover never sweeps full history. It takes the released range from a base it can defend,
most-authoritative first: on an automatic `push: main` run, the **push payload's `before` sha**
— *measured*, so `before..after` is exactly the set that push added; on `workflow_dispatch`,
which carries no payload, the **previous release tag** reachable from `HEAD^` (`^`, so the tag
the auto-tagger just minted on this very merge cannot collapse the range to empty). With
neither it **exits 2 and writes nothing**, naming `--base` / `--cards` as the escape — because
an omitted base resolves to `HEAD..HEAD`, which is EMPTY, so falling through would print
"nothing to do" and exit 0 over cards that genuinely shipped.

**The first-ever-release case is SPENT — do not pass `base` on an ordinary release.** It was
real until `v0.1.0` was tagged: with no previous tag the fallback had nothing to derive from,
so the first promote had to be dispatched with an explicit `base`. That is history. The repo
now carries release tags and the CI checkout fetches them (`fetch-depth: 0`, `fetch-tags: true`
in `release-promote-cards.yml`), so a push run and a dispatch both derive a base on their own.

`--base` still has live uses, all of them abnormal, and each is a case the mover NAMES rather
than guessing through:

- **`main` was force-pushed or rewritten** — the push `before` sha is either absent from the
  checkout or present but *not an ancestor* of the head, so `before..head` is not the set this
  push added. Two separate refusals, both exit 2.
- **A shallow or tagless clone**, which is a local-run hazard; CI's checkout closes it.
- **Re-promoting an OLDER release deliberately** — the tag fallback resolves to the LAST
  release, so reaching an earlier range takes `--base`/`--head` (or `--cards`).

It is never a bug when a run refuses — it is the guard working.

### G-4 — bridge writeback config fails **closed, for every repo in the file**
A malformed or unrecognized entry in the bridge's `writeback.json` is a config error that
disables **every** mapping in that file, not just the edited one. So adding the
`PupFuzz/mezzanine` mapping is not a local change: deploy and reload the bridge first, run
`bridge:check` and see it green, **then** edit the mapping, then `bridge:check` again.

### G-13 — a PR-only webhook silently never fires the "started" move
The repo webhook must subscribe to **Pull requests AND Pushes** — pick "Let me select
individual events" and tick both. The branch-create → *In Progress* move rides on the **push**
event; a Pull-requests-only webhook fires the open/merge moves and silently never fires that
one. Two more halves must both be present in the mapping: `stages.started` **and**
`started_from_stages`. Exactly one of the two leaves the trigger inert, and with
`started_from_stages` absent the move is refused outright.

### G-16 — `workflow_dispatch` only resolves from the DEFAULT branch
**A `workflow_dispatch` does not exist until its file is on the default branch (`main`)** — a
`pull_request` workflow registers from the PR head, a `workflow_dispatch` one does not. While
the file is only on `dev`, `actions/workflows` does not list it and the API answers:

```
HTTP 404: workflow release-promote-cards.yml not found on the default branch
```

**For `release-promote-cards.yml` this is SPENT.** It reached `main` in `1f86523`
(2026-08-22), before `v0.1.0`, so its dry-run dispatch resolves today and **no seeding merge is
owed**. The rule is kept because it binds **every workflow added from here on**: a new
`workflow_dispatch` stays invisible to the API until its file reaches `main`, which for
ordinary feature work means the next release.

**The release path is never blocked by this.** A `push: main` trigger fires from the pushed
commit's own tree, so a workflow arrives and runs on the very merge that lands it. Only
*rehearsing* ahead of time is blocked.

### G-5 — `.release-pr.json` `promote.api_base` is a credential-exfiltration surface
It is PR-editable, and it is where `KANBAN_WRITEBACK_TOKEN` gets sent. That is why the
committed value is a scrubbed placeholder and the real base is a repo variable. **Treat any
`api_base` change in a PR as a credential-scope change and review it as one.**

### G-14 — a wrong-but-resolving stage id moves cards to the wrong column, silently
The stage ids in `.release-pr.json` are **board state, not config**. Re-read them from the
board (`GET /api/v3/boards/14.json`) after any board restructure; a stale id that still
resolves produces a successful-looking move to the wrong column.

### Anti-resurrection: `shipped_stage_ids` is REQUIRED here, not optional
"Released to main" is a **terminal** stage. A card matched by a token is promoted only if its
**current** stage is in `promote.shipped_stage_ids`; anything else is skipped and logged.
Without it, a stale token in an old commit subject could drag a Backlog or Won't-Do card into
a terminal column. The set is `107` ("Shipped to dev") alone — **In Review (106) is excluded
deliberately**: a card still sitting in review when its release lands was never marked
shipped, and it will visibly NOT promote. That is fail-closed and inspectable, which beats
silently dragging an un-shipped card to terminal. Do not widen the set to make a run go green.

### Release PRs into `main` must land as MERGE COMMITS
A squash or rebase merge of the release PR collapses the per-PR subjects the mover correlates
on. The mover detects a non-merge tip and exits **4** — it still promotes whatever tokens
survived, then says the set may be SHORT and to verify by hand. `docs/VERSIONING.md § Branch model`
already requires merge commits into `main`; this is the machine that notices when it does not
happen.

### GitHub delivers each webhook exactly once, with no retry
If the bridge is down when a PR event fires, that move is **lost** and nothing re-drives it.
The backstop is the bridge's `reconcile` command, which recomputes the expected stage from
GitHub ground truth. Nothing in this repo can detect the loss.

---

## What is NOT wired here

- **No scheduled GitHub↔board reconciliation.** There is no polling sync for this repo; the
  chain is PR-time (bridge) plus release-time (this repo's workflow).
- **No DL numbers.** This repo mints none, and nothing here reads a `DL-` token. Board 14 is
  card-first, and `card#<id>` is the only correlation key **anything in this repo reads** — not
  the only one its cards carry: they also carry `payload.pr_number`. Re-derive both populations
  with `kbcard --board mezzanine list` (print the stderr denominator: `--swimlane 14` matches
  nothing, because 14 is the BOARD id); `DL-` is genuinely zero, `pr_number` is not. Whether a
  released range should be correlated on that key instead is an OPEN choice — `card#9146` holds
  the live disposition and `bin/promote-cards-by-token`'s header carries the measurements.
- **No board-side card creation from CI.** Cards are created by humans and agents on the
  board; CI only moves them.

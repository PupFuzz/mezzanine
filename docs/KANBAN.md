# Kanban automation

How this repo talks to its kanban board, what an operator must set up before any of it works,
and the failure modes that are **silent** if you skip a step.

The board is **board 14** on the fleet kanban. Its numeric id lives in exactly one place —
`.release-pr.json` → `promote.board_id` — and is not restated anywhere else in the repo.

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
| `.release-pr.json` | Board id, the released stage, and the shipped-stage source set. Read by the mover. |

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

### G-2 — the FIRST-EVER release must pass `--base`
The mover derives the released range from the previous release tag. **This repo has no release
tag yet**, and the mover refuses a full-history sweep rather than guessing one. So the first
release promote must be run via **workflow_dispatch with the `base` input set** to an explicit
tag or sha. It is not a bug when the first automatic run refuses — it is the guard working.

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
survived, then says the set may be SHORT and to verify by hand. `README.md § Branch model`
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
  card-first; the `card#<id>` token is the only correlation key.
- **No board-side card creation from CI.** Cards are created by humans and agents on the
  board; CI only moves them.

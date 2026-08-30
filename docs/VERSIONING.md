# Versioning policy

How Mezzanine is versioned, released, tagged and deployed — and, kept deliberately separate,
how the **`fleet-reporter` → ingest wire contract** is versioned, because this repo's SemVer
does not describe that contract and cannot.

Adapted from `agent-board-toolkit`'s versioning policy: the branch model, the release-PR
shape and the pre-1.0 bump sizing are deliberately the same, so a reader who knows the
fleet's other release flow already knows most of this one. It lives in `docs/` rather than at
the repo root — a deliberate divergence: in this repo the root is reserved for AI-parsed
files and human-readable prose lives here. `VERSION` itself stays at the root, because it is
a data file that tooling reads, not documentation. Everything from
[§ Deploy is not a tag](#deploy-is-not-a-tag--and-mezzanine-has-two-targets) onward is
specific to Mezzanine and has no counterpart there.

> **Status — re-measured 2026-08-30, cutting `v0.2.0`.** `VERSION` is `0.2.0`. **One tag
> exists**: `v0.1.0`, minted by `auto-tag-version` on `556ac3f` — the scaffolding seed merge
> (PR #11, 2026-08-24), which is exactly the bootstrap case the ⚠ under
> [§ Release flow](#release-flow) warns about, not a release anybody reviewed as one. It is
> immutable and is never moved; `v0.2.0` is the first tag this flow actually produces.
> **Nothing is deployed**: `bin/deploy.sh` does not exist and `docs/PLAN.md § 5` records the
> prod host as unprovisioned (D-08), so both target verdicts in
> [§ Deploy is not a tag](#deploy-is-not-a-tag--and-mezzanine-has-two-targets) are still
> *first install*, never *upgrade*. `docs/CHANGELOG.md` exists and is written to per PR
> (`docs/PLAN.md § 4`, which owns its format); **`fleet-reporter/` now exists too** — the
> ⚠ inside [§ Wire compatibility](#wire-compatibility--the-reporter-to-ingest-contract-has-its-own-version-line)
> records what that section's rules bind to today.

---

## The core rules

1. **`VERSION` at the repo root is the single source of truth for the repo's version** — one
   semver string, one trailing newline, nothing else. Consumers read it with
   `tr -d '\n' < VERSION`. Today exactly one thing reads it:
   [`.github/workflows/auto-tag-version.yml`](../.github/workflows/auto-tag-version.yml).
   [`.release-pr.json`](../.release-pr.json) deliberately does **not** declare a `version_file`
   or an `artifacts` set — read that file's own `_note` before adding one. It carries only
   keys something in this repo actually reads, and a second statement of where the version
   lives would be free to drift with nothing binding it.
2. **Version bumps happen in a dedicated release PR, never on a feature PR.** The bump plus
   its changelog entry *is* the release act; a feature PR that also moves `VERSION` has
   quietly cut a release nobody reviewed as one.
3. **Every tag `v<version>` owes a changelog entry** describing the bundle of PRs it carries.
   The changelog lives at [`docs/CHANGELOG.md`](CHANGELOG.md). **This policy owns the
   obligation and not the format**: roundtable #344 settled headings, ordering and per-PR
   versus at-release authorship, and `docs/PLAN.md § 4` is where that answer was adopted,
   including the card-level entry rule and the size gate this project added to it. Read § 4
   before writing an entry; it is deliberately not restated here.
4. **Tags are minted by CI on `main`. Nobody hand-tags.** After a human merges the release PR
   into `main`, [`auto-tag-version.yml`](../.github/workflows/auto-tag-version.yml) fires on the
   push, reads `VERSION`, and puts a lightweight tag `v<VERSION>` on the merge commit — so
   the tag's sha *is* the merge commit's sha. It is **tag-only**: no GitHub Release, no
   artifact upload, no deploy. It is idempotent (the tag already on this exact commit is a
   no-op re-run), and it **fails loud** when `v<VERSION>` already exists at a *different*
   commit, because that means the release PR did not bump `VERSION`.
5. **Back-merge `main` → `dev` after every release**, on a `sync/main-to-dev-post-v<version>`
   branch, merged with a **merge commit — never squashed**. Squashing a back-merge copies
   the content without the ancestry, so `main`'s tip never becomes an ancestor of `dev` and
   the *next* release PR's three-dot diff re-shows the previous release's `VERSION` bump as
   an incoming change. See the ⚠ under [§ Branch model](#branch-model): today `dev`'s ruleset
   mechanically forbids this, and that has to be resolved before the first release.

---

## Branch model

Two long-lived branches: **`main`** (releases only; the repo default) and **`dev`**
(integration). All feature work branches off `dev` and PRs back into `dev`. Only a human
merges into `main`, and only a release PR or a scaffolding seed targets it.

**The merge method is enforced by rulesets here, not left to convention** — a genuine
difference from the repo this policy is adapted from, which relies on the author picking the
right button on a control that remembers the *last* choice. Measured on the live repo
2026-08-23 (`GET /repos/PupFuzz/mezzanine/rulesets`):

| Branch | `allowed_merge_methods` | Also enforced |
|---|---|---|
| `main` | `merge` only | PR required, no deletion, no force-push |
| `dev` | `squash` only | PR required, no deletion, no force-push |

So a release PR into `main` *cannot* be squashed and a feature PR into `dev` *cannot* be
merge-committed: the buttons for the wrong method are not offered. That matters beyond
tidiness — `docs/KANBAN.md § Release PRs into main must land as MERGE COMMITS` explains what
a squashed release would cost the card mover (it collapses the per-PR subjects the mover
correlates on).

**No ruleset requires a status check** (also measured 2026-08-23; neither branch has classic
protection either). "Wait for CI" in the release flow below is therefore a *process*
obligation with nothing mechanical behind it. Do not read a green-looking merge button as CI
having passed.
>
> ⚠ **Updated 2026-08-23:** `card-token-lint` **is** now a required status check on both
> branches, so that one check is mechanically enforced. Everything else in CI still is not —
> a workflow that is added later is not automatically required, and a required check that
> never runs (a path-filtered workflow producing no run at all) reads as *pending*, not
> *passed*. Re-read this section whenever a workflow is added.
>
> ⚠ **Re-read 2026-08-30 on adding `release-pr-guard` (card#8174), as that instruction
> requires. Measured live that morning: both rulesets still required exactly
> `["card-token-lint"]`, so the new gate ran but did NOT block.** It is deliberately safe to
> require: it carries **no `branches:` filter**, precisely so it produces a completed run on
> every PR rather than the no-run-reads-as-pending deadlock the paragraph above describes; on a
> PR that does not target `main` it reports *NOT APPLICABLE* and exits 0.
>
> ✅ **SUPERSEDED the same day — re-measured 2026-08-30 while cutting `v0.2.0`
> (`GET /repos/PupFuzz/mezzanine/rulesets`), and the ruleset edit HAS landed.** Both branches
> now require **`["card-token-lint", "release-pr-guard"]`**, by the *job* id — which is what a
> ruleset matches, never the workflow's display name. The gate now blocks. **The paragraph
> above is kept rather than deleted because it is the reason the requirement was safe to add;
> read it as history, and this block as the state.**

**Two more rulesets exist that the 2026-08-23 table never measured** — both found live on
2026-08-30 and both load-bearing on the release flow:

| Ruleset | Target | What it does | Bypass |
|---|---|---|---|
| *Release tags — v\* immutable after creation* | `refs/tags/v*` | blocks `update` and `deletion` | **none** |
| *Release review — main requires an approval or a deliberate admin bypass* | `refs/heads/main` | `pull_request`: 1 approving review, `dismiss_stale_reviews_on_push`, `merge` only | `RepositoryRole`, mode `always` |

The tag ruleset is the mechanical backstop for § Anti-patterns' *"Don't reuse or move a tag"*
and core rule 4's *"never moves an existing tag"*, and it has **no bypass actor at all** — the
strictest rule in this repo. ⚠ **It blocks `update` and `deletion` but NOT `creation`, which is
exactly right and must stay that way**: a `creation` rule here would silently brick
`auto-tag-version`, whose entire job is to create `v<VERSION>` on a push to `main`.

⛔ **The review ruleset does NOT close step 9's actor gap, and must not be read as doing so.**
It requires an approval, but the bypass actor is a *repository role* in mode `always`, and
`bin/release-pr-guard.py` records why that is unavoidable here: **one GitHub identity is shared
by the agent and the operator**, so any actor-based control either blocks the operator or admits
the agent. What the ruleset buys is a **deliberate act** — approve, or knowingly bypass as
admin — rather than a silent merge. That is friction on the path PR #38 took, not a wall across
it, and it is why the release gate asks *what* is merged rather than *who* merges it.

> ✅ **Resolved 2026-08-23 — `dev` now allows `squash` AND `merge`.** It was briefly
> squash-only, which made core rule 5 unsatisfiable: a `sync/main-to-dev-post-v<version>` PR
> could not land as a merge commit, so `main`'s tip would never have become an ancestor of
> `dev` and every subsequent release PR's three-dot diff would have re-shown the previous
> `VERSION` bump. A ruleset cannot scope allowed methods by *head* branch, so the choice was
> "allow both on `dev`" or "a bypass actor for the sync branch"; allowing both is simpler and
> its failure mode is cosmetic (a feature PR merged with the wrong button), whereas a bypass
> actor is a standing hole. **Squash for feature PRs is therefore convention here, not
> enforcement — the enforced half is that `main` takes merge commits only.**

---

## Bump sizing

Mezzanine is pre-1.0, and `0.x` is the honest description: the floor, the feed and the event
payloads are all still moving.

- **Patch** (`x.y.Z+1`) — bug fixes, refactors, docs, CI and other internal-only changes. No
  new user-visible surface.
- **Minor** (`x.Y+1.0`) — anything a user or an operator can see or must act on: a new
  dashboard view or floor behaviour, a new event kind, a new `fleet-reporter` flag or install
  step, a new HTTP route, a changed accepted wire-schema set.
- **Major** (`X+1.0.0`) — reserved for post-1.0 breaking changes. Pre-1.0, a breaking change
  is a **minor** with the break stated loudly in the release notes.

A release mixing a feature with fixes leans minor; a release that is only fixes, refactors or
docs is a patch. When it is genuinely arguable, state the reasoning in the release PR — the
reasoning is what the next release reads, not the number.

**The wire schema is not sized here.** A repo version and a wire-schema version are different
version lines with different consumers (see
[§ Wire compatibility](#wire-compatibility--the-reporter-to-ingest-contract-has-its-own-version-line)).
Moving the accepted schema set makes a release *at least* minor, but the schema's own number
is not derived from the repo's and never tracks it.

---

## The release-PR head hazard — never PR `dev` directly into `main`

**The head of a release PR is always a throwaway `release/v<version>` branch, never `dev`.**
This repo has `delete_branch_on_merge` **enabled** (verified 2026-08-23), which means GitHub
deletes the PR's head branch the moment it merges. A `dev`-headed release PR therefore
deletes `dev` on merge — the integration branch, every open PR's base, gone as a side effect
of a successful release.

The ruleset's `deletion` rule on `dev` is a real backstop, but do not lean on it: "the ruleset
probably catches it" is a guess about an interaction, and the throwaway branch costs one
command. The rule is cheap; the failure is not recoverable in the moment you notice it.

> ⛔ **This is no longer hypothetical. The path was taken on 2026-08-30**, when PR #38 merged
> `dev` → `main` and `dev` survived. Read that survival carefully: what is *observed* is that
> `dev` still exists at `7139cd7` with `delete_branch_on_merge` enabled and a `deletion` rule
> on the branch (all three re-verified live 2026-08-30). What is *inferred* is that the rule is
> what refused the delete — no refusal was witnessed, so this is one data point that the
> backstop holds, not a demonstration of it, and the sentence above stands unchanged.
>
> ✅ **Since card#8174 the head is also checked mechanically**, by
> [`release-pr-guard`](../.github/workflows/release-pr-guard.yml) — see the note under
> [§ Release flow](#release-flow) for exactly which steps it covers and which it does not.
> It refuses any head that is not the release branch, generically: `delete_branch_on_merge`
> deletes *whatever* branch heads the PR, and `dev` is only the most expensive instance.

---

## Release flow

1. **Pick the next version** per [§ Bump sizing](#bump-sizing) (`tr -d '\n' < VERSION` for the
   current one).
2. **Branch `release/v<version>` off `dev`.**
3. **Bump `VERSION`** to the new semver.
4. **Retitle `## [Unreleased]` in [`docs/CHANGELOG.md`](CHANGELOG.md) and open a fresh empty
   one** (core rule 3; `docs/PLAN.md § 4` owns the format). The release collects entries that
   the feature PRs already wrote — it does not author them.
5. **State the deploy verdict for BOTH targets** — see
   [§ Deploy is not a tag](#deploy-is-not-a-tag--and-mezzanine-has-two-targets). A release
   that says nothing about a target has not said "nothing to do" about it.
6. **State the wire verdict** if the accepted schema set moved — see
   [§ Wire compatibility](#wire-compatibility--the-reporter-to-ingest-contract-has-its-own-version-line).
7. **Open the release PR `release/v<version>` → `main`** with full notes. Head is the release
   branch, never `dev` ([§ hazard](#the-release-pr-head-hazard--never-pr-dev-directly-into-main)).
8. **Wait for every CI check to complete and pass.** Nothing enforces this mechanically here
   (see [§ Branch model](#branch-model)) — the wait is yours.
9. **A human merges it, with "Create a merge commit."** It is the only method `main` offers,
   and it is a deliberate human gate: an agent does not merge a `main`-targeted PR.
10. **CI takes it from there on the push to `main`:** `auto-tag-version.yml` mints
    `v<version>`, and
    [`release-promote-cards.yml`](../.github/workflows/release-promote-cards.yml) promotes the
    board-14 cards named in the released range. Neither is done by hand. ⚠ **The first-ever
    release is different** — the card mover derives its range from the previous release tag
    and there is none yet, so it must be run via `workflow_dispatch` with an explicit `base`
    (`docs/KANBAN.md § G-2`; `§ G-16` explains why that dispatch only exists once the
    workflow is on `main`).
11. **Open the back-merge `sync/main-to-dev-post-v<version>` → `dev`** and merge it with a
    merge commit (core rule 5, and the ⚠ blocking it today).
12. **Deploy** what the release actually requires deploying, then exercise it for real. A tag
    is not a deploy — next section.

> ✅ **Four of these steps are now mechanically checked** — added by card#8174 after PR #38
> merged on 2026-08-30 breaking three documented rules at once and merging green.
> [`bin/release-pr-guard.py`](../bin/release-pr-guard.py), on every PR whose base is `main`,
> asserts **step 2/7's head branch**, **step 3's `VERSION` bump** (strictly greater than
> `main`'s) and **step 4's changelog section**. That file's docstring is the contract; this
> list stays the authority, and where the two disagree **this document wins and the guard is
> the defect**.
>
> ⛔ **Steps 5, 6, 8, 9, 11 and 12 remain unenforced, and deliberately so.** The deploy and
> wire verdicts are human judgement stated in prose — a gate that grepped for a phrase would
> report having checked a judgement when it had checked a string. "Wait for CI" is about other
> checks; "a human merges it" cannot be enforced at all here, because one GitHub identity is
> shared by the agent and the operator, and that is the whole reason card#8174 gates *what* is
> merged rather than *who* merges it. **Nor is the bump SIZE checked** — nothing mechanical can
> tell a patch from a minor ([§ Bump sizing](#bump-sizing) is yours). Read the guard's green as
> covering exactly the four steps named above and nothing else.

> ⚠ **One-time, at the first push that carries `auto-tag-version.yml` onto `main`.** The
> workflow tags on *any* push to `main`, which includes the scaffolding seed merge
> `docs/KANBAN.md § G-16` recommends cutting before the first release PR. Whatever `VERSION`
> reads at that moment is what gets minted — so a seed merge carrying `0.1.0` mints `v0.1.0`
> on a scaffolding commit, and the first real release PR then **cannot** also ship `0.1.0`:
> the workflow refuses to move an existing tag and reds the release, correctly. Decide which
> commit is `v0.1.0` before pushing either one.

---

## Deploy is not a tag — and Mezzanine has two targets

A green tag means the bits are *released*. It says nothing about what is *running*, and
Mezzanine has two entirely separate things that run, on different machines, upgraded by
different acts:

| Target | What it is | Where it runs | Who upgrades it |
|---|---|---|---|
| **The Laravel app** | dashboard, ingest endpoint, websocket feed | one server | whoever deploys, in one act |
| **`fleet-reporter`** | the Claude Code hook bundle that POSTs the events | every agent machine, Linux **and** Windows | each seat's owner, on their own schedule |

The second one is why this section is not a footnote. `fleet-reporter` is installed per seat
and upgrades **independently of the server**, so a release that changes it is not "deployed"
when the server is — it lands seat by seat, over days, on machines whose owners have no
reason to know a release happened.

**A release is not complete until the release notes state, for each of the two targets,
whether that release requires redeploying it — including the explicit "no redeploy required"
line where it does not.** Omission must never be how a release says *nothing to do*, because
omission is also how a release says *nothing at all*, and the reader cannot tell the two
apart. The same reasoning applies as in the repo this policy is adapted from, where exactly
this went stale twice by depending on someone remembering.

**When a release touches both, deploy the server first.** The ordering is not taste — it
falls out of the compatibility rule below. An upgraded ingest accepts both the new schema and
the previous one, so old reporters keep working through the transition; an upgraded reporter
posting a schema the old ingest never learned is refused. Server-first degrades to nothing;
reporter-first degrades to rejected events from every seat that got there early.

Then **exercise the real surface**: a floor that renders is the check, not a green workflow.

---

## Wire compatibility — the reporter-to-ingest contract has its own version line

> ⚠ **Both ends now exist — re-measured 2026-08-30, cutting `v0.2.0`.** This section was
> written before either, because the compatibility rule is much cheaper to honour from commit
> one than to retrofit onto a fleet of already-installed reporters. It is no longer a contract
> for unwritten code: `fleet-reporter/fleet-reporter.js` (card#7335) is the producer and
> `POST /api/ingest/events` (card#7338) is the receiver. Per **rule 2**, the accepted set is
> declared in exactly one machine-readable place —
> [`server/app/Ingest/SchemaVersions.php`](../server/app/Ingest/SchemaVersions.php), reported
> by `GET /api/ingest/health` — and **this document deliberately does not restate it**; read it
> there, or ask a running ingest. Read the rules below as binding on live code from `v0.2.0`.

**Why the repo's SemVer cannot cover this.** `fleet-reporter` runs on seats that upgrade
independently of the server. At any given moment an *old* reporter is POSTing to a *new*
ingest — that is the steady state, not an edge case. The repo version describes what a
release contains; it says nothing about which payloads a running ingest will accept from a
reporter installed six weeks ago. That needs its own, explicit version.

### The rules

1. **Every event carries an explicit `schema_version`** in its envelope — a monotonically
   incrementing integer, not a semver, because there is exactly one dimension of
   compatibility here and a three-part number would invite arguments about which part to
   move. A payload without it is invalid input, not a legacy payload to guess at.
2. **The ingest declares which schema versions it accepts**, in exactly one machine-readable
   place in the code, and reports that set on its health surface. An operator must be able to
   ask a *running* ingest what it accepts, rather than infer it from a deployed tag. Docs
   point at that declaration; they do not restate it (the same discipline
   `docs/KANBAN.md § The card token` applies to the card grammar, for the same reason).
3. **Adding an optional field is backward-compatible** and needs no bump: an old ingest
   ignores it, a new ingest defaults it. Nothing else is, **except the two other additive
   cases rule 7 names**. Note the asymmetry with the fail-loud rule below, because it is easy
   to read as a contradiction: an unknown **field** at a known schema version is ignored, an
   unknown **version** is refused. Ignoring the field is what makes additive change possible
   at all; refusing the version is what stops a payload nobody understands from being
   accepted as if it were understood.
4. **Removing a field, renaming one, changing its type, or changing what an existing field
   MEANS requires a schema-version bump** plus a stated support window. The meaning change is
   the dangerous member of that list and the reason it is named explicitly: it passes every
   structural validator ever written, so nothing catches it except this rule.
5. **The support window is stated policy, not an accident: the ingest accepts the current
   schema version and the one immediately before it (`N` and `N-1`).** A reporter at `N-2` is
   refused. A window that is merely *what happens to still work* is one nobody can plan
   against and that ends silently on some unrelated deploy; a stated one gives an un-upgraded
   seat a defined grace period and gives a release something to check itself against.
6. **Dropping support is its own release act, announced one release ahead.** The release that
   ships `N+1` states, in its notes, that `N-1` leaves the window — and the release that
   actually narrows the accepted set says so as a user-visible change (minor, per
   [§ Bump sizing](#bump-sizing)).
7. **Additive change is backward-compatible, and the receiver absorbs it rather than
   refusing it. Two cases, and only these two: a new event `kind`, and a new member of a
   closed enum field.** Neither needs a schema bump, because neither can break a consumer
   that never knew about it — they are the `kind`-level and the value-level analogue of
   rule 3's added optional field. The receiver's obligation is stated as behaviour, because
   "compatible" is only true if the receiving side actually does this:
   - **an unknown `kind` is ignored and counted**, never a rejection of the payload
     carrying it;
   - **an unrecognised value in a closed enum field is coerced to that field's designated
     unknown member and counted**, never passed through and never a rejection. A field that
     has no unknown member is not a closed enum for this purpose — **and adding a member to
     such a field is therefore a rule-4 change: a bump plus a stated window.** That
     fall-through is stated rather than left implicit because it is the whole cost of
     omitting an unknown member, and the omission is sometimes right: a value a *producer*
     mints out of its own logic (rather than passing through from an upstream system) has no
     benign unknown case, so an unknown member there would silently absorb a producer bug
     that should be loud. What must not happen is for such a field to be treated as covered
     by this rule — under atomic ingest, an upgraded producer sending a new member to a
     not-yet-upgraded receiver would take a rejection for the whole payload, and independent
     upgrade is the steady state, not an edge case;
   - **both counts are surfaced per seat**, so a producer running ahead of its receiver is a
     visible state rather than a silent one.

   The reason this is a rule and not an implementation detail is the blast radius of the
   alternative. Payloads are ingested atomically — one invalid event refuses its whole
   batch — so a receiver that treated an unrecognised kind or enum value as *invalid* would
   convert a single additive change upstream into the permanent loss of every good event
   beside it, which is precisely the quiet, unrecoverable failure
   [§ the failure direction](#the-failure-direction-must-be-safe--reject-loudly-never-drop-quietly)
   exists to forbid. **Removing** a kind or an enum member, or changing what either *means*,
   is rule 4's business and needs a bump like anything else.

### The failure direction must be safe — reject loudly, never drop quietly

**An ingest that receives a schema version it does not accept — unknown, newer, or aged out —
MUST reject it visibly. It must never return success and discard the event.** Concretely:

- Refuse with an explicit error status (a 4xx), and a machine-readable body naming the
  received version and the accepted set, so the answer to "why is this seat missing?" is in
  the response rather than in someone's inference.
- Count the refusals per seat and surface them on the dashboard as a **visibly degraded**
  seat — a distinct rendered state, not an absent one.
- Make the reporter surface the refusal locally too, rather than swallowing it. The hook runs
  on somebody's machine; that somebody is the only person who can fix it.

**Why this is written as a MUST.** A dashboard that is quietly stale or quietly empty is
worse than one that is visibly broken, because *nobody investigates a floor that merely looks
quiet* — and quiet is exactly what this product renders when agents are idle. A silently
dropped event stream is indistinguishable from a calm fleet, so the failure hides inside the
normal appearance of the thing being displayed, for as long as nobody happens to check.

This is not a hypothetical class. It is the shape this fleet keeps hitting: a call that
returns 200 with nothing in it and reads as a clean zero. `docs/KANBAN.md § G-1` is the same
defect in this very repo's kanban chain — a non-member token gets HTTP 200 with empty data,
every correlation resolves to "no card", and it looks like a release that named no cards. The
wire protocol is where we get to decide, before writing it, that we will not build another
one.

---

## Anti-patterns

- **Don't hand-tag.** CI owns tags. A hand-cut tag on the right commit is at best a
  no-op the workflow repeats; on the wrong one it is a mistake the workflow then
  refuses to correct, because it never moves an existing tag.
- **Don't reuse or move a tag.** Tags are immutable; a broken release ships `vX.Y.Z+1`.
- **Don't tag `dev`.** Only `main` is tagged.
- **Don't bump `VERSION` on a feature PR.** That is a release act.
- **Don't PR `dev` directly into `main`** — see
  [§ the head hazard](#the-release-pr-head-hazard--never-pr-dev-directly-into-main).
- **Don't squash a release PR or a back-merge.** Both need real merge commits;
  only `dev`-targeted PRs are squashed.
- **Don't call a tag a deploy**, and don't call a server deploy a fleet deploy.
- **Don't widen the ingest's accepted schema set to make a complaining seat go green.** That
  is the wire-protocol spelling of loosening a constraint to silence a failure — upgrade the
  seat, or bump the schema deliberately with a stated window.

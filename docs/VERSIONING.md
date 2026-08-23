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

> **Status.** This repo is scaffolding. `VERSION` is `0.1.0`, and **nothing has been
> released** — no tag, no changelog, no deployed artifact. The rules below are written to
> bind from the first release, not to describe a history that already exists. Two of them
> name a thing that does not exist yet (`docs/CHANGELOG.md`, `fleet-reporter/`); each says so
> where it is stated, rather than linking to it as if it were there.

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
   The changelog will live at `docs/CHANGELOG.md`. **It does not exist yet and this policy
   does not create it:** its format is an open question with kanban-solo on roundtable #344 —
   headings, ordering, and whether entries are authored per-PR or collected at release time
   are #344's to settle, and this doc deliberately does not pre-empt any of it. What is
   settled is the obligation. Until #344 lands, the release PR's own body carries the notes,
   so no release ships undescribed in the meantime.
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

The ruleset's `deletion` rule on `dev` is a real backstop and would likely refuse it, but do
not lean on that: it has never been exercised on this path, "the ruleset probably catches it"
is a guess about an interaction, and the throwaway branch costs one command. The rule is
cheap; the failure is not recoverable in the moment you notice it.

---

## Release flow

1. **Pick the next version** per [§ Bump sizing](#bump-sizing) (`tr -d '\n' < VERSION` for the
   current one).
2. **Branch `release/v<version>` off `dev`.**
3. **Bump `VERSION`** to the new semver.
4. **Write the changelog entry** (core rule 3 — `docs/CHANGELOG.md` once #344 settles its
   format; the PR body until then).
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

> **None of this is built yet.** `fleet-reporter/` and the ingest endpoint do not exist in
> this repo today. This section is the contract they are built to, written before the first
> line of either, because the compatibility rule is much cheaper to honour from commit one
> than to retrofit onto a fleet of already-installed reporters.

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
   ignores it, a new ingest defaults it. Nothing else is. Note the asymmetry with the
   fail-loud rule below, because it is easy to read as a contradiction: an unknown **field**
   at a known schema version is ignored, an unknown **version** is refused. Ignoring the
   field is what makes additive change possible at all; refusing the version is what stops a
   payload nobody understands from being accepted as if it were understood.
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

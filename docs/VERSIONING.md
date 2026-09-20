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

> **Status — read it at its sources; this note states no version and counts no tags.** The
> released version is the root `VERSION` file on `main` (`git show origin/main:VERSION`). The
> releases are the tags (`git tag --list 'v*'`), each immutable and never moved, and each release's
> notes are its section of `docs/CHANGELOG.md` — or, once [step 13](#release-flow) has moved it,
> the whole of `docs/changelog/v<version>.md` — written to per PR (`docs/PLAN.md § 4` owns its
> format and the archive layout). The first tag, `v0.1.0`, is the bootstrap case the ⚠ under
> [§ Release flow](#release-flow) records, not a release anybody reviewed as one.
> **Deploy state is `docs/PLAN.md § 5`'s to record** — its `bin/deploy.sh` bullet says whether the
> script has run against a host. Until a first deploy has run, both target verdicts in
> [§ Deploy is not a tag](#deploy-is-not-a-tag--and-mezzanine-has-two-targets) are
> *first install*, never *upgrade*. **Both ends of the wire contract exist** — the
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
   The changelog lives at [`docs/CHANGELOG.md`](CHANGELOG.md), which holds `## [Unreleased]` and
   the latest released section; **every released section older than the latest lives at
   [`docs/changelog/`](changelog/)`v<version>.md`, one file per tag**, moved there verbatim by
   [step 13](#release-flow). The tag is the archive's unit precisely because this rule makes the
   tag the unit that owes the entry. **This policy owns the obligation and not the format**:
   roundtable #344 settled headings, ordering and per-PR
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
   an incoming change. `dev` is squash-only for everyone, and its merge-method ruleset carries an
   **admin bypass that exists solely so this merge commit can land**: the admin identity merges
   the back-merge with `gh pr merge <N> --merge`, never with `solo-self-merge`, which always
   squashes. [§ Branch model](#branch-model) carries why that bypass exists and the command
   that re-derives it.

---

## Branch model

Two long-lived branches: **`main`** (releases only; the repo default) and **`dev`**
(integration). All feature work branches off `dev` and PRs back into `dev`. Only a human
merges into `main`, and only a release PR or a scaffolding seed targets it.

**The merge method is enforced by rulesets here, not left to convention** — a genuine
difference from the repo this policy is adapted from, which relies on the author picking the
right button on a control that remembers the *last* choice. A release PR into `main` lands as a
merge commit and a feature PR into `dev` lands as a squash, because the button for the wrong
method is not offered — except to the admin role on `dev`, whose bypass exists for core rule 5's
back-merge alone (the ✅ block at the end of this section). That matters beyond
tidiness — `docs/KANBAN.md § Release PRs into main must land as MERGE COMMITS` explains what
a squashed release would cost the card mover (it collapses the per-PR subjects the mover
correlates on).

⛔ **WHICH CONTEXTS A PR MUST PASS, AND WHICH MERGE METHOD A BRANCH OFFERS, ARE REPOSITORY-SETTINGS
FACTS. A DOCUMENT CANNOT VERIFY THEM; ONLY THE API CAN.** This section is the one home of **where
to read them**, and **it carries no list of required contexts and no merge-method table** — that is
the load-bearing half, because those two are what a reader acts on and what moved under the old
copies. Settings ARE stated elsewhere in this section — which method each branch offers, the `dev`
bypass, the two release rulesets — and each is stated as the POLICY it must satisfy, with the
command that reads the live setting beside it, because the reasoning is what no API call can give
you. Treat any of them as a claim to re-derive, never as the state. The lists used to be here
too: a stack of dated
re-readings, each added because the previous one had gone stale, and each stale again before the
next card read it — a settings value copied into prose has no reader that re-derives it, which is
the whole defect. Copies of it in two other files (`.github/workflows/asset-provenance.yml` and
`docs/ATTRIBUTION.md`) told reviewers that a red `asset-provenance` did not block a merge for as
long as it had been required. **Re-derive it, never relay it** — what a PR into `dev` must pass:

```
gh api repos/PupFuzz/mezzanine/rules/branches/dev --jq \
  '[.[] | select(.type=="required_status_checks") | .parameters.required_status_checks[].context] | sort'
```

and every rule in force on that branch, with the ruleset each one comes from:

```
gh api repos/PupFuzz/mezzanine/rules/branches/dev --jq '.[] | {type, ruleset_id, parameters}'
```

Substitute `main` for `dev` to read the release branch; the two branches are free to differ and
have differed. `rules/branches/<branch>` reads **the rules in force on the branch from every
active ruleset**, rather than looping over ruleset ids, because a per-id loop cannot see a ruleset
created after the loop was written. ⚠ **It AGGREGATES, so one rule type can appear MORE THAN ONCE
with different parameters, and every occurrence binds** — read all the lines the second command
prints, never the first match. It has already happened here: on 2026-09-20 `main` returned two
`pull_request` rules in one response whose `required_approving_review_count` differed, and a
reader that stopped at one of them would have reported the other's requirement as absent. Any
`4xx` means the read did not happen, not that nothing is required.

**A required context is the name of the CHECK RUN, never the workflow file's name** — so a second
job added to an existing workflow file is a second, independently requirable context, and that is
why two jobs in one file can be required separately. In this repo that name is the JOB id, because
no job declares a `name:` — run this from the repo root, where an empty result is a result and not
a wrong directory:

```
grep -n '^    name:' .github/workflows/*.yml || echo 'no job declares a name: — the context IS the job id'
```

⚠ **A
job that gains a `name:` is renaming its context**, and a ruleset would go on requiring the old
one and wait for a check that never reports.

✅ **CLASSIC BRANCH PROTECTION IS GONE FROM BOTH BRANCHES, BY DESIGN — card#9746.** Rulesets are
the single source of truth, so the commands above read all of it, and
`gh api repos/PupFuzz/mezzanine/branches/dev/protection` answering `404 Branch not protected` —
for `main` too — is the intended end state rather than a failed read or drift to raise. The
retired configuration is backed up at `~/.cache/coord/protection-backup/`. ⇒ **`solo-self-merge`
printing `protection=404` is CORRECT and must not be "fixed"**, confirmed empirically on
`PupFuzz/mezzanine#184`, which resolved its required contexts and merged.

⚠ **Adding a workflow does not make it required, and a required check that never runs reads as
*pending*, not *passed*.** Requiring a context is a repository-settings act and the operator's to
perform; until it is performed the lane runs and blocks nothing. The pending trap is the reason
no workflow meant to be requirable filters its `pull_request:` trigger by `branches:` or
`paths:`: a filtered workflow produces no run at all on a PR outside the filter, and a ruleset
requiring that context then waits forever for a check that will never report. ⚠ Workflows here
DO carry `paths:` on `pull_request:`, and those are exactly the lanes that must never be
required — read a lane's `on:` block before requiring it. The pending trap is also why
`release-pr-guard` narrowed with `if: github.event.pull_request.state == 'open'` rather than a
`types:` narrowing when `edited` fired on a merged PR and re-judged one into a permanent red
(card#9732): every PR that can still merge is open, so the job still produces a completed run
under the same context, and being required is unaffected.

**Two rulesets beyond the two branch rulesets are load-bearing on the release flow**, and what
each is FOR is the part this document owns — their contents are settings, read the same way as
everything above. The list, and then one of them by id:

```
gh api repos/PupFuzz/mezzanine/rulesets --jq '.[] | {id,name,target,enforcement}'
gh api repos/PupFuzz/mezzanine/rulesets/<id> --jq \
  '{conditions: .conditions.ref_name.include, bypass_actors, rules: [.rules[].type]}'
```

- ***Release tags — v\* immutable after creation***, targeting the `v*` tags: it must block
  `update` and `deletion`, and it must carry no bypass actor.
- ***Release review — main requires an approval or a deliberate admin bypass***, targeting
  `main`: it must require an approving review, and its bypass is a repository role.

The tag ruleset is the mechanical backstop for § Anti-patterns' *"Don't reuse or move a tag"*
and core rule 4's *"never moves an existing tag"*, and **no bypass actor at all** is what makes
it the strictest rule in this repo — a bypass here would give the strictest rule the weakest
enforcement. ⚠ **Its rules must stay `update` and `deletion` and must never grow `creation`,
which is exactly right and not an oversight**: a `creation` rule here would silently brick
`auto-tag-version`, whose entire job is to create `v<VERSION>` on a push to `main`.

⛔ **The review ruleset does NOT close step 9's actor gap, and must not be read as doing so.**
It requires an approval, but the bypass actor is a *repository role* in mode `always`, and
`bin/release-pr-guard.py` records why that is unavoidable here: **one GitHub identity is shared
by the agent and the operator**, so any actor-based control either blocks the operator or admits
the agent. What the ruleset buys is a **deliberate act** — approve, or knowingly bypass as
admin — rather than a silent merge. That is friction on the path PR #38 took, not a wall across
it, and it is why the release gate asks *what* is merged rather than *who* merges it.

> ✅ **THE MERGE-METHOD STATE ON `dev`: squash-only for everyone, plus an admin bypass for the
> back-merge.** Read on 2026-09-13 from `GET /repos/PupFuzz/mezzanine/rulesets/21953633`: ruleset
> `21953633` "dev — merge method (squash only)" (created 2026-08-31, `enforcement: active`,
> `refs/heads/dev`) allows only `squash`. Its one bypass actor is `RepositoryRole` `actor_id` 5, the
> admin role, in mode `always`, and the shared identity reads `current_user_can_bypass: always`.
> **The bypass exists solely so core rule 5's `main` → `dev` back-merge lands as a merge commit.**
> This is deliberately the model sola-pm uses (PupFuzz/agent-roundtable#385/#386).
>
> - **How the back-merge lands:** the admin identity merges the `sync/main-to-dev-post-v<version>`
>   PR with `gh pr merge <N> --merge`. **Not `solo-self-merge`**: it always squashes, which is what
>   rule 5 forbids. The post-`v0.3.0` back-merge (PR #76, merge commit `834a638`, 2026-09-09) landed
>   as a merge commit after the ruleset existed, which only the bypass admits.
> - **What the bypass does not skip:** it bypasses this ruleset only. The required status checks
>   are a rule of a DIFFERENT ruleset, so a back-merge still waits for every required check —
>   **and that conclusion holds only while that other ruleset grants no bypass of its own.** Read
>   both halves before relying on it: the `{type, ruleset_id, parameters}` command above says
>   which ruleset the `required_status_checks` rule on `dev` comes from, and
>   `gh api repos/PupFuzz/mezzanine/rulesets/<that id> --jq '{bypass_actors,current_user_can_bypass}'`
>   says whether this identity can step past it. A bypass added there would make the sentence
>   above false without touching this block. ⚠ **And
>   no rule on `dev` may require linear history** — that one refuses a merge commit from anyone
>   its own ruleset does not exempt, and would make core rule 5 unsatisfiable; the same command
>   lists every rule type in force, which is where to check it.
> - **The cost, stated:** one identity is shared by the agent and the operator (above), so that
>   identity *can* merge-commit any PR into `dev`, not only a back-merge. For it, squash on a feature
>   PR is convention, and `solo-self-merge` is the path that keeps it.
> - **Re-derive it, never relay it:**
>   `gh api repos/PupFuzz/mezzanine/rulesets/21953633 --jq '{bypass_actors,current_user_can_bypass,rules}'`.
>
> ⛔ **SUPERSEDED on 2026-08-31 by that ruleset — read as history.** An earlier reading of these
> settings, recorded here on 2026-08-23, read:
> *"✅ Resolved 2026-08-23 — `dev` now allows `squash` AND `merge`."* It was briefly
> squash-only, which made core rule 5 unsatisfiable: a `sync/main-to-dev-post-v<version>` PR
> could not land as a merge commit, so `main`'s tip would never have become an ancestor of
> `dev` and every subsequent release PR's three-dot diff would have re-shown the previous
> `VERSION` bump. A ruleset cannot scope allowed methods by *head* branch, so the choice was
> "allow both on `dev`" or "a bypass actor for the sync branch"; that block chose allowing both and
> called a bypass actor a standing hole. Ruleset `21953633` took the other branch, with the bypass
> on the admin role rather than on a head branch, and the cost bullet above is that hole, named.

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
   Where the verdict states what a host must satisfy, **derive the floors from the gates that
   enforce them — never copy a number into the notes, and never restate a mapping.**
   `bin/deploy.sh` declares the bash floor and reads its own declaration; A12 owns the mapping
   from a lockfile format to an npm floor and is the only thing that should ever state that
   floor; `tools/verify-php-floor.py` is the PHP one's reader. Run from the repo root of the tree
   being released:

   ```
   ( . ./bin/deploy.sh >/dev/null
     echo "bash floor declared by this tree: $(bash_floor_declared < bin/deploy.sh)"
     gate_a12_asset_lockfile HEAD "$(npm --version)" )
   python3 tools/verify-php-floor.py
   ```

   A12's line names the npm floor and the lockfile version it came from, so **the note quotes that
   line rather than a number**. ⚠ `npm_lockfile_version` is NOT that floor — it prints the
   lockfile's own `lockfileVersion`, a file-format number, and a note that carries it as a host
   requirement asks for an npm that does not exist. Pass a release's sha in place of `HEAD` to ask
   about that release; the gate reads the tree out of git, so it needs a checkout with the commit,
   and it needs an `npm` on the machine only to have a version to compare against.

   ⛔ **Keep stderr. Never `2>&1` or `2>/dev/null` here**, and read the block as FAILED unless
   every labelled line printed. `bin/deploy.sh` sources `bin/supervision.sh` from beside itself,
   so a copy of the script without its sibling — or a run from the wrong directory — fails at that
   source, and stderr is the only place that says so. Sourcing runs no deploy: the script's own
   `main` function runs only when the file is executed (§ library mode), which is what lets a
   checker call one gate without deploying anything. git is the exception with no floor to print —
   A3b PROBES the host instead, by design. What the host must satisfy is `bin/deploy.sh`'s header
   block *"⚑ WHAT THIS HOST'S TOOLS MUST BE"* to state; this step only says the notes must carry
   it.
6. **State the wire verdict** if the accepted schema set moved — see
   [§ Wire compatibility](#wire-compatibility--the-reporter-to-ingest-contract-has-its-own-version-line).
7. **Open the release PR `release/v<version>` → `main`** with full notes. Head is the release
   branch, never `dev` ([§ hazard](#the-release-pr-head-hazard--never-pr-dev-directly-into-main)).
8. **Wait for every CI check to complete and pass — and read WHICH of them GitHub will hold the
   merge on.** A subset of the lanes is required on `main`, and GitHub holds the merge until
   exactly that subset has passed, alongside the branch's other rules; every lane outside the
   subset runs, reports, and blocks nothing. So a wall of ticks is evidence only for the lanes
   where a red was possible, and counting them is how a release gets read as better-checked than
   it is. **Derive the subset — do not count ticks, and do not take a list from this document,
   which carries none:**

   ```
   gh api repos/PupFuzz/mezzanine/rules/branches/main --jq \
     '[.[] | select(.type=="required_status_checks") | .parameters.required_status_checks[].context] | sort'
   gh pr checks <N>
   ```

   The first prints what blocks the merge, the second what ran. A lane in the second and not in
   the first is information about the release, never a gate on it; waiting for those is still
   yours, and so is judging a red one ([§ Branch model](#branch-model) owns how the required set
   is read, and why no copy of it lives in prose).
9. **A human merges it, with "Create a merge commit."** It is the only method `main` offers,
   and it is a deliberate human gate: an agent does not merge a `main`-targeted PR.
10. **CI takes it from there on the push to `main`:** `auto-tag-version.yml` mints
    `v<version>`, and
    [`release-promote-cards.yml`](../.github/workflows/release-promote-cards.yml) promotes the
    board-14 cards named in the released range. Neither is done by hand, and **an ordinary
    release passes no `base`** — both a push run and a dispatch derive the range themselves.
    When a range genuinely cannot be derived the mover exits 2 rather than guessing, and
    `--base` is the escape; `docs/KANBAN.md § G-2` owns the three cases where that happens.
    **Do not restate them here** — a second copy of that rule is what went stale last time.
11. **Open the back-merge `sync/main-to-dev-post-v<version>` → `dev`** and merge it with a
    merge commit — never a squash (core rule 5). `dev` is squash-only, so the admin identity
    lands it through the ruleset's admin bypass with `gh pr merge <N> --merge`
    ([§ Branch model](#branch-model)); not with `solo-self-merge`, which always squashes.
12. **Deploy** what the release actually requires deploying, then exercise it for real. A tag
    is not a deploy — next section.
13. **Archive the previous release.** Once step 11's back-merge has landed on `dev`, move the
    released section that is no longer the latest out of
    [`docs/CHANGELOG.md`](CHANGELOG.md) into `docs/changelog/v<previous version>.md`, **verbatim**,
    in a **tokenless** docs PR into `dev` (branch and title carrying no `card#NNNN`, so R4 is NOT
    APPLICABLE by its trigger exactly as a back-merge is) and self-merged with `solo-self-merge`.
    The live file is then `## [Unreleased]` plus the release just cut, and nothing else. The move
    is verbatim in the byte sense — `cmp` the section against the base, extracting the archive
    file's body with `tail -n +6`, since an `awk` range re-emits a trailing newline and cannot see
    a missing one. `docs/PLAN.md § 4` owns the layout and the reason the archive files need no
    size gate beyond R7's backstop. **`release-pr-guard` R7 enforces this step** on the NEXT
    release PR — see the note below.
    ⚠ **Do it BEFORE the next release branch is cut.** An archive PR that lands on `dev` after
    the cut makes `docs/changelog/*.md` R6a residue on that release PR, because the release head
    will not have the files `dev` has just gained. The answer is the refresh from `dev` that R6
    demands anyway — never a `Release-excludes:` line, which would declare an exclusion that is
    not one.

> ✅ **The steps named here are mechanically checked** — added by card#8174 after PR #38
> merged on 2026-08-30 breaking three documented rules at once and merging green.
> [`bin/release-pr-guard.py`](../bin/release-pr-guard.py), on every PR whose base is `main`,
> asserts **step 2/7's head branch**, **step 3's `VERSION` bump** (strictly greater than
> `main`'s) and **step 4's changelog section**. That file's docstring is the contract; this
> list stays the authority, and where the two disagree **this document wins and the guard is
> the defect**.
>
> ✅ **And since card#9707, step 2's "off `dev`" is checked as a statement about CONTENT, not
> only about where the branch was cut.** The same guard's **R6** asserts that the release head
> carries what `dev` carries — every path differing from `origin/dev` outside `VERSION` and
> `docs/CHANGELOG.md`, and every card `dev` bullets under `## [Unreleased]` that the head's
> changelog dropped, is residue. Residue is allowed only when the PR body says so exactly, in a
> `Release-excludes: <tokens> — <reason>` line, because **deliberately shipping without recent
> work is legitimate and shipping without noticing is not**. `docs/changelog/` is residue to R6a
> like any other path — `RELEASE_ARTIFACT_PATHS` is `VERSION` and `docs/CHANGELOG.md` and nothing
> else — which is why step 13 lands its move on `dev` first rather than riding the release
> branch. The rule, the escape hatch and the reason it is a tree comparison rather than an
> ancestry test are the guard docstring's; they are not restated here. What this document adds is the measurement that bought it: PR #176
> (v0.5.0) sat open for roughly a day at a head four merges behind `dev`, every check green,
> and merging it would have shipped a release omitting four cards.
>
> ✅ **And since card#9814, step 13 is enforced — on the release PR that follows it.** The same
> guard's **R7** refuses a release PR whose `docs/CHANGELOG.md` carries more than TWO released
> sections (the one it mints and the previous latest), naming the `docs/changelog/<tag>.md` file
> each excess section belongs in, and any `docs/changelog/*.md` past R5's contents-API cliff. The
> fix it names is step 13 done the way step 13 says — on `dev`, then a refresh of the release
> branch from `dev` — never the move made on the release branch. Off the release path R7 only
> warns: a feature PR's author did not skip step 13. Before R7, a skipped step 13 was caught only
> by R5, on some later PR by some other author, and not at all in the fortnight after an archive,
> when R5's threshold is the whole cliff.
>
> ⛔ **The guard checks none of steps 5, 6, 8, 9, 11 and 12, and deliberately so.** The deploy and
> wire verdicts are human judgement stated in prose — a gate that grepped for a phrase would
> report having checked a judgement when it had checked a string. Step 8 is enforced for the
> contexts the `main` ruleset requires and for no others, which is why step 8 says to derive that
> set rather than count green ticks — it is a repository setting, not something the guard could
> assert; "a human merges it" cannot be enforced at all here, because one GitHub identity is
> shared by the agent and the operator, and that is the whole reason card#8174 gates *what* is
> merged rather than *who* merges it. **Nor is the bump SIZE checked** — nothing mechanical can
> tell a patch from a minor ([§ Bump sizing](#bump-sizing) is yours). Read the guard's green as
> covering exactly the steps named above and nothing else. **Step 11's back-merge in particular
> is NOT what R6 checks** — R6 asks whether this release carries `dev`'s content, and says
> nothing about whether any past release was merged back. On this repo an ancestry test would
> answer that one falsely anyway: the v0.5.0 back-merge was squashed, so the release line is not
> an ancestor of `dev` while the two trees are identical.

> ⚠ **The bootstrap trap — it FIRED, and what it left is immutable.** `auto-tag-version` tags
> on *any* push to `main`, not only a release PR, using whatever `VERSION` reads at that
> commit. The scaffolding seed merge (PR #11, 2026-08-24) carried `0.1.0`, so it minted
> `v0.1.0` on `556ac3f` — a scaffolding commit nobody reviewed as a release. The `refs/tags/v*`
> ruleset blocks `update` and `deletion` with **no bypass actor**, so that tag stays where it
> is; `v0.2.0` is the first tag this flow produced deliberately.
>
> **The rule is not spent, only its first instance is.** Any non-release push to `main` mints
> `v<VERSION>` at that commit, and a later release PR shipping that same version then
> **cannot** tag it — the workflow refuses to move an existing tag and reds, correctly. So
> bump `VERSION` before pushing anything to `main` that is not the release it names.

---

## Deploy is not a tag — and Mezzanine has two targets

A green tag means the bits are *released*. It says nothing about what is *running*, and
Mezzanine has two entirely separate things that run, on different machines, upgraded by
different acts:

| Target | What it is | Where it runs | Who upgrades it |
|---|---|---|---|
| **The Laravel app** | dashboard, ingest endpoint, websocket feed | one server | whoever deploys, in one act |
| **`fleet-reporter`** | the Claude Code hook bundle that POSTs the events | every agent machine, Linux **and** Windows | each seat's owner, on their own schedule |

The server's "one act" is **`bin/deploy.sh`** and nothing else (D-13): it refuses to start unless
the host is in a deployable state, opens a maintenance window, migrates forward-only, rebuilds the
caches in the order that matters, restarts the long-lived daemons that are still holding the
previous release's code in memory, and stays **down** for operator review if any of it fails.
`docs/PLAN.md § 5` owns the description; the script's own header owns the reasoning per step.

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

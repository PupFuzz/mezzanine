# Changelog

Every PR whose title or branch carries a `card#NNNN` token owes a line-initial
`- **card#NNNN** — …` bullet under `## [Unreleased]`, **in the same PR**. A PR that names no
card owes nothing. `docs/PLAN.md § 4` owns that rule and the reasoning behind it, including
why the bullet must be line-initial; `docs/VERSIONING.md` owns when a release collects these
entries and retitles the section.

⛔ **Enforced since 2026-08-30 by `bin/release-pr-guard.py` R4**, on every PR — for seven days
before that it was prose, and six cards' work merged with no entry at all. The two cases that
owe nothing are a PR into `main` (the release retitles this section) and a PR that REMOVES a
card's bullet (a revert of unreleased work); both are argued in that file's docstring. **R5**
holds this file's SIZE under `1 MiB − the bytes it grew in the last 14 days`, so it reds while
there is still time to archive released sections rather than after the contents API has begun
returning it empty. **R6** (card#9707) gives this section a third reader: on a release PR it
compares the cards bulleted here on `dev` against the head's changelog, so a release cannot
quietly ship with fewer cards than `dev` holds. **R7** (card#9814) refuses a release PR whose
copy of this file carries more than two released sections, and so enforces the archiving below.

Sections are newest-first: `[Unreleased]` collects what has landed on `dev` since the last
release, and a release retitles it (`docs/VERSIONING.md § Release flow` step 4).

This file holds `[Unreleased]` and the latest released section only. Every older release is one
file per tag under [`docs/changelog/`](changelog/) — `ls docs/changelog/` lists them — moved there
verbatim by release flow step 13 (`docs/VERSIONING.md`), which R7 enforces. R5 gates this file's
size; `docs/PLAN.md § 4` says why the archive files need no gate of their own beyond R7's backstop.

## [Unreleased]

- **card#9801** — **A PR into `dev` now has a body generator, and the shape it emits is the fleet
  standard's.** `bin/change-pr-body.py` writes the scope line, `## Highlights`, optionally
  `## Upgrade warnings`, the `Built:` / `**Coordinated in:**` machine lines and the attribution
  trailer, and leaves the judgement sections as `<!-- AUTHOR: … -->` markers. Until it existed the
  author of a non-release PR had two options and both broke a rule: hand-write the body against the
  standing "generate it" rule, or force `--version` onto `release-pr-body` — which exits 2 with
  `could not resolve version` on a `dev` PR because it is structurally a RELEASE generator — and
  emit a body asserting a release that is not happening. The fleet helper is unchanged: it is
  owned outside this repository and a `--change` mode there is a fleet proposal, not a local edit.
  **The shape question the generator answers is now written down too.** `skills/release-pr/SKILL.md
  § PR body` governs *every* PR body an agent writes, not only a release one — two of the four
  sections it admits carry `Release PRs only` in its own IN table, and the set is an allowlist
  rather than a required list — so `CLAUDE.md § PR bodies are judged against the fleet standard`
  now carries what a change PR looks like and a `change-pr-body:house-map` block saying where each
  of this repository's former house sections goes instead. **That map is graded, not asserted:**
  `bin/change-pr-body.selftest.py` drives every left-hand heading through `bin/pr-body-lint.py` and
  requires it to still red, requires every `## X` destination to be one that linter admits AND
  passes, and pipes the generator's real output into it — then mutates that output once per rule
  so the green is shown to discriminate. **Once per rule is itself derived, not counted:** the
  rule ids are read out of the linter's own source and the mutation set is required to cover
  them, so re-vendoring a linter that adds a rule reds the suite for under-coverage instead of
  leaving a coverage sentence that has quietly stopped being true. The suite runs in the
  `pr-body-lint` job and can fail it.
  **What has NOT changed is what the repository rejects.** The lane's verdict on a PR body is still
  report-only and `pr-body-lint` is still required by no ruleset; the flip to blocking is the
  operator's act, gated on the verdict being RE-DERIVED over the recent merged bodies rather than
  on any figure written down — `CLAUDE.md` carries the loop that prints it.
  **A false claim about that lane is corrected, and the sweep that finds it is written down
  instead of being asserted complete.** Every surface describing the `pr-body-lint` job said the
  JOB prints its verdict and exits 0 — in the words "nothing in this job can fail a pull request",
  and in "the contract this job makes is that it exits 0". That was never true: the job's
  vendored-byte pin and the vendored linter's own selftest shipped in its first commit and both
  red on a bad checkout. ⚠ "Corrected everywhere" is exactly the claim that goes stale at the next
  copy and that nothing re-checks, so the instrument is recorded rather than the verdict — re-run
  it before adding a sentence about this lane, because it caught two copies that narrower greps
  and two review passes missed:
  `git grep -n -i -E "exits? 0|can fail|cannot fail|never block|never fails" -- CLAUDE.md docs/ .github/workflows/ | grep -i "body\|this job\|this step\|lane"`.
  The promise is, and always was, about the BODY: no PR body can fail this job. The
  generator's selftest now runs in the same job and can red it too, and it is ordered AFTER the
  report step so that a broken generator can never suppress the body verdict the author reads.
- **card#9984** — **`bin/deploy.sh`'s version comparison refuses operands it cannot read, so a
  `BASH_FLOOR` that is not a version is refused by name instead of certified.** Until this change
  the comparison behind every one of phase A's version floors — A1's bash floor, A6's PHP floor,
  A6b's floor for the release being deployed, A12's npm floor — fell through to zeros on an operand
  that was not a version: every field read 0, 0 is neither greater nor less than 0, and the answer
  was *"at least"*. None of them asks it under `set -e`, so nothing stopped. **What that cost:
  edit `BASH_FLOOR=` at the top of your serving copy of `bin/deploy.sh` to anything that is not a
  `<major>.<minor>` — blank it, or write `v4.4` — and the next `bin/deploy.sh` run printed no
  complaint about it, enforced no bash floor at all, and went on to open the maintenance
  window.** It now stops in phase A, before anything is touched.
  **A mistyped SEPARATOR is caught too, and it is the likelier typo.** `BASH_FLOOR=4,4`, `4.x`,
  `4-4`, `4x`, `4` and `"4 4"` all begin with a digit, so the comparison used to run, read as far
  as it parsed, take the floor to be `4.0` and report it met — a host running bash 4.0 deploying
  past a floor of 4.4. What a floor IS is now one test — `<digits>.<digits>`, exactly two fields —
  and A1 holds your copy's declaration to it, A6b holds the release's to it, and the `bash-floor`
  CI job holds the tree it measures to it. **That test is stricter than the one A6b used to
  apply:** a release declaring `BASH_FLOOR=4.x.5` or `4.4x` used to pass, and the first was
  enforced as the floor `4.0.5`, which is not the floor that release declared. Both are refused
  now. **And a copy whose `BASH_FLOOR=` line has been DELETED is refused as that** — it used to
  end the run with `BASH_FLOOR: unbound variable` and exit 1, with none of the `⛔ REFUSED`
  banner or the *"Nothing was changed. The previous release is still serving."* promise that
  tells you a deploy stopped on purpose rather than broke. A blank `BASH_FLOOR=` takes the same
  refusal.
  **The same tightening reaches the host's own `npm --version`.** A1c validated it with the
  same loose pattern, so an npm reporting something like `9.x.5` was compared as `9.0.5` — a
  number your host never reported. It is refused now. **A prerelease npm still deploys**:
  `9.2.0-pre.1` is read as its release, which is long-standing deliberate behaviour and is
  covered by a case so it stays that way. What is refused is a version with any dot-field that
  does not start with a digit. Among the first three fields — the ones the comparison reads —
  that is the shape that was being silently read as `0`; beyond them the check is deliberately
  stricter than the comparison needs — npm's published versions were swept for this, and none
  of them is affected.
  **Every comparison between two well-formed versions answers exactly as it did before** —
  measured field by field against the previous implementation. What changed is only what
  happens to an operand that is not one.
  **If a deploy of yours starts refusing with *"declares BASH_FLOOR='…', which is not a
  version"*, the fix is the line at the top of `bin/deploy.sh`:** it reads
  `BASH_FLOOR=<major>.<minor>`, alone on its line, at column 0, unquoted or quoted, and the
  comment above it says how the number is arrived at and that it moves by re-running the
  measurement rather than by being retyped.
  **If instead it refuses with *"a version comparison this deploy cannot perform"*, read the two
  operands it prints** — one is this host's own version (`$BASH_VERSINFO`, `php -r 'echo
  PHP_VERSION;'`, `npm --version`) and the other is a floor declared by a release (`require.php`
  in `server/composer.json`, `lockfileVersion` in `server/package-lock.json`), and whichever of
  them is not a version is what to fix. The commonest way to reach it is a host `php` that answers
  `php -r 'echo PHP_VERSION;'` with something other than a version: each floor gate validates the
  FLOOR it read out of the release, and the HOST value it is handed is not validated anywhere
  else.
  The same change drops the here-strings that split those operands. A here-string is a temporary
  file on every bash below 5.1, which is above the floor `BASH_FLOOR` declares, so on a supported
  host a temp-file failure reached that same fall-through with no bad input at all; the split is
  now parameter expansion, which needs no file, no pipe and no subshell.

- **card#9803** — **`docs/design/FLEET-STATE.md` § 6.2 owns the suite's store-isolation pin values
  and carries them as a verbatim XML block; the suite now checks that block against
  `server/phpunit.xml`.** Nothing compared the two, so the document that OWNS the pins could
  disagree with the file that implements them, in either direction, with every check green. The cost
  is in the correction rather than in the disagreement: someone who finds the file contradicting the
  section that owns the values edits the FILE to match it, and those pins decide which database the
  suite rebuilds destructively on every run and which Redis index it flushes — on a shared host,
  someone else's.
  **`Tests\Feature\DatabasePinTest` reads § 6.2's block through the same reader it already used for
  `phpunit.xml`** — one implementation of what a pin is, rather than a second parser inside a check
  whose whole subject is two copies disagreeing — and reds naming the key and what each side
  declares.
  **It compares every pin, and its population is the pin set rather than a list anyone maintains** —
  the key sets are read from the two files and compared in both directions, and then, over
  `phpunit.xml`'s own set read at run time, each pin's `<env>` value, its `force="true"` and its
  `<server>` value are compared. A pin added to `phpunit.xml` is compared on the run that adds it,
  with no second decision for anyone to remember. `force` is compared because § 6.2 finding 1 makes
  it as load-bearing as the value: a block whose `force="true"` has been dropped isolates nothing
  once copied into the file, and an exported variable then beats the pin.
  **What the check is worth differs by key, and that was established key by key.** A drifted
  `DB_DATABASE`, `REDIS_DB` or `REDIS_CACHE_DB` copied into `phpunit.xml` also moves a resolved value
  `Tests\TestCase` asserts by name and ABORTS the run on, so for those the new check is the earlier
  and clearer red rather than the only one. `REDIS_URL` is asserted by nothing else:
  Laravel's `RedisManager` takes the index from the URL's path when it BUILDS a connection, so
  `config('database.redis.default.database')` goes on reporting the pinned index and no check sees
  the difference. And `DB_URL`'s existing guard compares the database NAME alone, while the URL also
  replaces the driver, host, port, username and password — measured with a `DB_URL` of
  `mysql://127.0.0.1:3399/mezzanine_test` pinned in `phpunit.xml`, where every `config()` pin passed,
  the connection-name guard passed, and the run went on to open a connection to that port.
  **The bootstrap guard's abort message was corrected in the same change.** It named an exported
  environment variable as the usual cause, and in this card's sequence that sends the reader to
  update the guard's own expected values — making the drift green and the store somebody else's. It
  now names the drifted-pin cause beside the export and says to check both copies before editing
  either.
  **Which red you get depends on which copy moved, and it is worth knowing before you read one.** A
  drift in § 6.2 — of any pin, in a value or in `force` — reaches this check and nothing else, and
  you get its message naming the key and both copies. A drift in `phpunit.xml` reaches this check
  for `DB_URL` and `REDIS_URL`, whose values no other check reads. For `DB_DATABASE`, `REDIS_DB` and
  `REDIS_CACHE_DB` a file-side drift moves a resolved value, so every test errors in the bootstrap
  guard before this check runs and you get that abort instead — safe, and pointed at the right copy
  by the clause below.
  The check was watched failing in each of those shapes before it was trusted, and each red was read
  rather than counted.
  **There is nothing to do on any host.** No application code, no configuration and no deploy path
  changes, and the check runs with the rest of the PHP suite. It matters when you EDIT either copy:
  move the pins in `server/phpunit.xml` and § 6.2's block in the same commit, and read that section's
  guard bullets before deciding which copy drifted.

- **card#9832** — **`bin/deploy.sh` refuses a `MEZZ_REMOTE` that is not the NAME of a remote of the
  checkout, in phase A, before anything is touched — and without printing the value.** The variable
  has always been documented as a git remote NAME, but `git fetch` takes a URL just as readily, and
  a URL can carry a credential: `MEZZ_REMOTE=https://user:token@host/org/repo` is an ordinary thing
  for an operator deploying a private checkout to set, and git accepts it. Every later mention of
  the value then put that credential on the operator's screen and in the deploy log — measured on
  the previous tree, five times over in one refused run, in the *"Fetching …"* step line and in four
  lines of the fetch refusal that followed. **git's own redaction is not a backstop:** measured, git
  2.53.0, an `https://` URL is reported with the credential stripped and a `git://` one verbatim, so
  it is per-transport, it is not this script's to rely on, and either message is on screen before the
  script sees it. Refusing the value at the boundary is therefore the fix and redaction is the weaker
  half.
  **The test is MEMBERSHIP in `git remote`, never a pattern match for `://` or `@`.** `backup@nas`
  and a bare `@` are legal remote names — a remote name is a refname component — so a pattern would
  refuse a host that is configured exactly right.
  **What membership does NOT close, said at the size it was measured.** No name `git remote add`
  will CREATE can be a URL: a refname may not contain `:`, and every URL git fetches from carries
  one. But `git config` writes a section name straight into `.git/config` with no such check —
  measured, git 2.53.0, `git config 'remote.https://user:secret@host/o/r.git.url' <url>` exits 0,
  `git remote` then lists that URL as a NAME, and this gate passes it. That is a configured remote
  of the checkout, so the claim is the narrow one and not "no URL can be a remote's name".
  ⚠ **On a checkout like that the credential is already on screen whenever the remotes are listed,
  this gate's refusal included** — the list it prints is `git remote`'s own output, so a credential
  someone wrote into `.git/config` AS A REMOTE NAME appears under a headline saying the value is not
  printed. What is withheld there is `MEZZ_REMOTE`; the list is your checkout's configuration, which
  the deploy reports and does not author. **If any remote of your deploy checkout is named with a
  URL, rename it** (`git -C <deploy root> remote rename <the URL> <a name>`): a credential belongs
  in `remote.<name>.url`, never in the name, and while it is the name it is printed by every tool
  that lists your remotes, not only by this one.
  A multi-line `MEZZ_REMOTE` equal to two or more ADJACENT names joined by newlines is refused
  before the list test, since that test is applied to the value and the value is not a name.
  **The refusal does not echo what it rejected**, which is the whole of its point: it names the
  VARIABLE, says outright that the value is withheld, and lists the remotes this checkout HAS, which
  are names — `git remote` with no options prints no `remote.<name>.url`. The cost is paid
  knowingly: an operator who
  merely mistyped a name does not get the typo echoed back, and the list of names that would have
  worked is what makes it findable. `git remote`'s own status is read too, so a `git remote` that
  FAILED is refused as *"whether `MEZZ_REMOTE` names a remote is NOT established"* rather than as a
  value that names nothing.
  **What to do if you set `MEZZ_REMOTE` to a URL:** add the remote to the deploy checkout once
  (`git -C <deploy root> remote add <name> <url>`) and set `MEZZ_REMOTE` to that NAME. Hosts that
  leave `MEZZ_REMOTE` unset are unaffected — `origin` is a remote of any checkout this deploy runs
  on — and a host that had set it to a configured remote's name is unaffected as well.
  `bin/deploy.selftest.sh` gains the credential-bearing URL refused with the value absent from the
  output, a positive twin that observes the same string PRESENT so the absence is a measurement
  rather than a needle that could never appear, `backup@nas` deploying (which is what reds the
  pattern match), a configured non-default remote deploying, the default `origin` path unchanged, a
  checkout with no remotes at all, a value joining two adjacent remote names with a newline beside
  each half deploying alone, and a `git remote` made to fail by the suite's git shim — a
  status nothing can make a fixture produce, since a config git cannot read fails A3 first.

- **card#9616** — **`bin/deploy.sh` refuses a host whose `bash`, `git` or `npm` is too old — by name,
  in phase A, before anything is touched.** Until now A1 asked only whether those binaries were
  PRESENT; PHP alone had a version gate. So a host that was too old got partway in: `npm ci` failed in
  phase B with the site already down, a git without `:(literal)` pathspec magic failed every read of
  the release and was reported as a release missing its files, and a bash below the floor did not
  refuse at all — it DIED, at A7's refusal, on an empty array expanded under `set -u`, exiting 1 with
  no `⛔ REFUSED` banner and no *"Nothing was changed"* promise, which is exactly the code the exit
  table reserves for "refused, nothing was touched".
  **The bash floor is MEASURED, and `BASH_FLOOR` at the top of `bin/deploy.sh` is its one home.** A
  construct scan cannot find it — it sees `mapfile` (4.0) and `exec {fd}<` (4.1) and stops a full minor
  short of the truth — so nothing scans: `.github/workflows/deploy-selftest.yml` gains a `bash-floor`
  job that builds GNU bash at the declared floor and at the minor below it, from the release tarballs
  pinned by sha256, and runs the whole self-test under each. The floor must PASS **and the minor below
  must FAIL**; the below-floor run lowers `BASH_FLOOR` in its own copy of the tree so that the gate is
  not what stops it, and the job reds if that run passes, because a floor whose control cannot fail is
  a version that works rather than a floor. It reads the floor through `bin/deploy.sh`'s own reader, so
  CI cannot measure a floor the gate would not see.
  **Both copies of the script are held to a floor.** After `artisan down` the deploy re-execs the
  TARGET release's `bin/deploy.sh`, which never runs A1 — so A6b reads that release's own `BASH_FLOOR`
  out of git and holds this host's bash to it, beside the gates that already read the release (A6,
  A10–A13). A release that declares none predates this card: that is said out loud and is not a
  refusal, since refusing would make every rollback undeployable for want of a line it could not have
  carried.
  **git is PROBED, never version-parsed** (A3b, after A3 has established that git can open the
  repository): `git ls-tree HEAD -- VERSION` first, so that a failure of the magic form differs from it
  by one thing, then the same read with `:(literal)`, which must print exactly `VERSION`. The OUTPUT is
  required and not the status, because a magic-less git can also exit 0 having listed nothing — which
  downstream is indistinguishable from a release that does not carry the file.
  **npm is compared against the TARGET tree's `lockfileVersion`** (A12), the release that moves to a
  newer lockfile format being exactly the one the serving checkout says nothing about. The mapping is
  npm's own documentation, quoted at the gate and marked documented-not-measured; a lockfile version
  the gate cannot map is refused rather than guessed at.
  `bin/deploy.selftest.sh` carries a case for each refusal, every one asserting the banner and the
  promise as well as the exit code — in this class an exit-code assertion catches nothing, because the
  death being refused already exits 1 — plus the controls one variable away: the release's floor set to
  this host's bash, npm exactly at the lockfile's floor, and a git that fails the PLAIN probe, which
  must not be blamed on the pathspec magic it never reached. `BASH_VERSINFO` cannot be faked inside a
  running bash, so the comparison is a predicate of its own, driven at the floor and at the floor minus
  one.
  `bin/deploy-gate-inputs.sh`'s classification table moves with the gates, as it is built to: A12's
  read of `server/package-lock.json` becomes a CONTENT read rather than a presence one, and
  `bin/deploy.sh` joins the table as A6b's input — each row's function and disposition digest DERIVED
  by that check and pasted from its own output, never hand-computed. `bin/shell-lint.baseline.tsv`
  grows by a typed `--accept-new`, in two classes this file already carries as debt: SC2317 on the
  new fixture mutators, which ShellCheck cannot see `mkfix` invoke, and one SC2016 on a
  single-quoted `$BASH_FLOOR` that must reach the sourced shell unexpanded. Annotating only the new
  ones while their siblings stay unannotated would put two conventions in one file; discharging the
  whole class is its own round.
  **Review round 2 found the backstop had a denominator of one, and that is the substantive fix in
  it.** Only ONE empty-array site — A7's `ref_note` — was reached by any fixture, so guarding that
  one site would have turned the below-floor control green and had the job report that the floor
  could be lowered, while a first deploy on an older host still met `checkout_lock_holders` in the
  window. The suite gains two scenarios it had never covered: a **FIRST deploy** (D-08 — the prod
  host has never been deployed to, so it is the one run certain to happen), which is where that
  array is empty, and a **`server/.env` with no lines**. A `no_shell_death` tripwire rides on those
  and on the widest existing runs, because the first-deploy site does not change what the deploy
  DECIDES — its expansion is inside a `$( )`, so on an old bash the subshell dies and the parent
  reads an empty answer — and no verdict-shaped assertion can see that. The declared population of
  such sites is now stated as a HAND AUDIT that nothing re-derives, with both of its past errors
  recorded: one direction called three guarded sites hazards, the other added `ENV_LINES`, which
  measurement removed (`env_lines_load` splits with `<<<`, so a zero-byte `.env` is one empty line
  and the array is never `()` where the loops read it). The control's own assertions were two
  decorations: it now asserts that the `sed` lowering `BASH_FLOOR` in the below-tree actually
  applied — without it both gates refuse every fixture and the suite reds for the gate's reason,
  which is what lowering the floor exists to prevent — and it requires the suite's own
  `N assertions, M FAILED` line rather than accepting any non-zero exit, so a run that broke for an
  unrelated reason cannot pass for a measurement. **The maintenance window now runs the interpreter
  the gates measured**: the re-exec hands over `$BASH` rather than going through the deployed
  release's `#!/usr/bin/env bash`, which closes the gap where `somebash bin/deploy.sh` passed both
  floors and then died in the window on PATH's older shell.
  **Review round 3 gave that behaviour change the control it was missing, and found its sibling.**
  Every ordinary case starts the deploy through its shebang, so the invoking shell and PATH's shell
  are one process and reverting the hand-over reds nothing — the claim was wider than anything in
  the suite could support, which is a lower bar than this change applies everywhere else in itself.
  The self-test now starts one deploy through an EXPLICIT interpreter with a recording pass-through
  standing in for PATH's `bash`, and requires that neither the maintenance window nor A13 appears in
  its log; a positive twin asserts the recorder was really on PATH first, so the two absence
  assertions cannot pass having observed nothing. The sibling: `gate_a13_target_plan` ran the target
  release's `bin/supervision.sh` under a bare `bash -c` — PATH's shell again, the one no gate reads —
  and its refusal says the crontab block *"could not be installed here"*, blaming the RELEASE for
  this host's PATH `bash`. That is the misattribution class this card exists to end, being committed
  by one of its own gates; it now uses `$BASH` too, and has its own assertion in the same case.

- **card#9814** — **`release-pr-guard` R7 refuses a release PR that skipped archiving the previous
  release (release flow step 13), so the red lands on the release that owes the step.** Before it,
  a skipped step 13 surfaced only as R5's size red on some later feature PR by an author who could
  not fix it, and not at all in the fortnight after an archive, when R5's threshold is the whole
  cliff. On a PR into `main`, `docs/CHANGELOG.md` at the head may carry at most two released
  sections — the one the release mints and the previous latest — and the refusal names the
  `docs/changelog/<tag>.md` file each excess section belongs in, with the fix routed through `dev`
  as step 13 says. The same rule refuses any `docs/changelog/*.md` past R5's own contents-API cliff,
  the backstop for a post-hoc edit to a released section. Off the release path both clauses warn
  and never refuse, and R7 opens no authority file there, so the archive file is named from the
  version instead of composed from `tag_format`, and the one state that can still stop a feature
  PR through R7 is git failing to list `docs/changelog/` — which the guard's earlier read of
  `docs/CHANGELOG.md` already exits 2 on, and which stays the only one after this round's decode
  fix. The archive listing recurses and asks git not to quote the paths, so a file in a
  `docs/changelog/` subdirectory, or one named outside plain ASCII, is counted rather than
  reported as none; an archive filename that is not valid UTF-8 at all is read and printed
  lossily rather than refused, because a filename is not this repo's to validate and every git
  read now survives undecodable bytes. R7 counts released sections with R3's own reading of the
  headings, so a `###` heading is not one. The self-test adds a control, the third-section plant,
  the demoted-heading control, an archive plant with its at-the-cliff control, and the controls
  that a feature PR meets no authority file in R7, that a nested archive is seen, that a
  non-ASCII archive name is seen, and that a version-less heading is named with an instruction
  that can be followed; each red was seen to fail before the code that answers it, and raising
  the limit to three or doubling the archive limit reds the plants.

- **card#9816** — **`bin/deploy.sh`'s three phase-A scratch-file failures are now refused as
  themselves, with the `⛔ REFUSED` banner and the "Nothing was changed. The previous release is
  still serving." promise.** Three places this script creates a temporary file inside phase A had no
  unified error path: A13's work directory for its isolation check, and git_ref_oid's and
  git_commit_of's stderr files for capturing git's diagnostics on a failed read. When `mktemp`
  failed — measured with TMPDIR pointing at a directory that does not exist, the one condition the
  fixtures produce; a FULL filesystem is UNTESTED and is a DIFFERENT failure, because `mktemp` can
  SUCCEED on one and what then fails is the write of git's stderr into the file it made — two of
  them refused with a WRONG CAUSE because the failure went undetected: git_ref_oid and git_commit_of
  are called from inside an `if` or `||`, where `set -e` does not apply, so the script carried on
  with an empty path and refused on a cause it never established ("'main' does not resolve to a
  commit on origin" for a ref that is in the checkout, "git could not resolve the tag …" for a tag
  git never got to peel). A13's failure was worse: `mktemp`'s failure exits the phase with status 1
  — the code the exit table reserves for a REFUSED — but no banner fired, so the operator saw only
  the exit code with no description. All three now route through `not_established`, the one exit
  that reads the phase (A REFUSES with the banner and promise, B takes the in-window failure path
  and warns with the cause named); `scratch_file` and `scratch_dir` wrap `mktemp` and call
  `not_established` on failure, naming the scratch file and its purpose rather than the git step
  that would have run with an empty path. `not_established` and the scratch helpers join
  `git_read_call_site`'s family, so `failed_line:` in the in-window marker still names the caller.
  **Each change was seen to red on the old code** — the three fixtures exercise a real-mktemp
  pass-through that fails after N calls, and the whole run with TMPDIR gone to a missing directory,
  generating the three refused headlines and the empty-path false causes that the old code carried
  through.

- **card#9831** — **`bin/deploy-gate-inputs.sh` answers a mistyped command line with exit 2 and its
  `⛔` banner, which is its word for "this check could not run".** `--ref` with no value, or with an
  empty one, used to fail inside bash's own `${2:?}` expansion: a shell error that the script's ERR
  trap never sees, so the shell printed one line of its own and exited 1 — the code the script's exit
  table reserves for "a required input is MISSING", a verdict about the release. The option's value is
  now tested before it is used and the failure leaves through `die`, and an unknown argument leaves the
  same way instead of through a banner-less `printf`. The exit table names the command line among the
  causes of exit 2, and `bin/deploy-gate-inputs.selftest.sh` asserts the banner as well as the code for
  each case, because the shell's message carries the same words as the banner's.

- **card#9610** — **a `server/.env` that OPENS and cannot be read to its end is now refused as itself,
  and after the maintenance window it leaves the deploy UNVERIFIED by name rather than reported as an
  unset key.** bash's `read` returns the same status at end-of-file and on a read ERROR, so the loader
  could not tell a file the kernel refused mid-read from an empty one: `env_file_scan` certified a file
  nothing had read, and A5 refused on `APP_ENV is 'unset'` — a deploy stopped on a cause nothing
  established, with a `.env` that was right there and correct. What discriminates is bash's own
  DIAGNOSTIC, not its status and not the file's size: a read error prints and an end-of-file is silent,
  which is the rule the git reads in this script already use. A size or length test was measured and
  rejected — it counts characters against bytes under a UTF-8 locale, asks the filesystem a second
  question whose answer can have changed since the first, and reads every `/proc`-style file as empty.
  The read's stderr is captured to be READ rather than hidden: every byte of it is printed back before
  anything is decided, and it is safe to print by construction — `read`'s diagnostic names a file
  descriptor and an errno and carries no byte of what the file holds. Nothing read partway is used.
  The shape no userland reader can see is stated in the script instead of assumed away: an I/O error the
  kernel reports AS an end-of-file. **The status is what crosses the subshell.** Both readers that want a
  value call `env_get` inside a `$(…)`, so a flag set by the loader dies with that subshell — `env_get`
  answers **3** for a file that was not read, printing nothing, and 3 is never "unset". `env_read`
  refuses on it, which is what stops A5's `env_read … || true` from swallowing it back into `APP_ENV is
  'unset'`; A10b refuses on it rather than listing 53 keys the host "does not set"; and phase B, where
  no refusal is allowed because the new release is already serving, **warns with the cause named** and
  says the deploy is UNVERIFIED. The suite gains the fixtures that discriminate: an EIO on a regular
  file with no root (`/proc/self/mem` — the only one that reds on all three wrong fixes, including the
  `[ -d ]` one a directory fixture would pass and the size one that reads it as empty), the directory
  beside it, `env_get`'s status read through a subshell, a `.env` made unreadable after A5's scan, and
  one made unreadable inside the window. Each was seen to red against the previous `bin/deploy.sh`
  first. One condition is named rather than fixtured: a phase-A or phase-B READ failure on a real
  `server/.env` needs a filesystem that answers EIO on demand, which this runner cannot produce — a
  symlink to `/proc/self/mem` is refused earlier by A5's mode check, which reads the LINK's mode.
- **card#9646** — **every exit `bin/deploy.sh` takes in its precondition phase is now a refusal that
  says so, and names only the cause the run established.** Four commands ran without their status
  being read, so the script died on them instead of refusing: `--ref` with no value (bash's own
  `${2:?}`), `git fetch`, `git status --porcelain`, and — for its cause rather than its status —
  `git rev-parse --git-dir`. Under `set -Eeuo pipefail` a death exits with THAT command's status,
  which is exit 1 for a ref this checkout cannot read and 128 for a remote it cannot reach: 1 is the
  code the script's own exit table says means *refused, nothing was touched*, and 128 is a code the
  table does not list at all. The operator's only discriminator was the absence of the `⛔ REFUSED`
  banner and the `Nothing was changed. The previous release is still serving.` promise — two lines
  nothing told them to look for, and the exit table now says so. A3's refusal also asserted one cause
  for a status that carries several: `rev-parse --git-dir` exits 128 for every way it cannot open a
  repository, and `$DEPLOY_ROOT is not a git checkout` was stated for all of them with git's own
  message thrown away, so a prod checkout restored from backup or rsynced — `detected dubious
  ownership`, which git prints its own `safe.directory` repair line for — sent its operator looking
  for a checkout that was right there. git's wording is the discriminator now, git's message is
  printed, and an unrecognised wording gets an honest generic refusal rather than a false specific
  one. **The suite could not see any of it, which is the finding under the finding**:
  `bin/deploy.selftest.sh`'s `run_refusal` asserted an exit code and a needle, and a banner-less
  death satisfies both, so it now asserts the banner and the promise as well — one edit that upgrades
  every call site it has rather than one case at a time. The new cases each name the mutant they
  catch, and the two asymmetric ones are marked: a fetch fix that handles git's exit 1 and lets its
  128 escape passes every fetch case but the one run with the remote gone, and an A3 fix that matches
  `dubious ownership` and defaults everything else back to *not a git checkout* passes all five
  preserved shapes and reds only on a `.git/config` git cannot parse. `git status`'s status is read
  because the precondition is live and was measured to be: on a checkout whose `.git/index` is mode
  000, A3 exits 0 — it never opens the index — and A4 exits 128, and the empty result a failed
  `status` hands back reads exactly like a clean tree. **And a condition the runner cannot produce is
  now named rather than skipped**: every fixture that manufactures a state — a file this user cannot
  open, an object at mode 000, a checkout git treats as another user's — asserts that state before it
  asserts anything about the refusal, and where the state depends on the runner's own git build or
  configuration the suite prints `⚠ NOT VERIFIED HERE` with what that runner answered and what would
  have to be true, repeats the count in its summary, and does not fail. That is not hypothetical: the
  dubious-ownership fixture was green locally and red in CI, because `GIT_TEST_ASSUME_DIFFERENT_OWNER`
  only forces git past the uid check and `ensure_valid_ownership` then consults `safe.directory` — so
  a `safe.directory = *` in the system or global gitconfig turns its 128 back into a 0. The fixture is
  hermetic against both now, and it reported the absence by name rather than certifying a refusal that
  never happened.

- **card#9813** — **every released section but the latest now lives in its own file, so the
  changelog the contents API returns stays a fraction of the truncation cliff.** `[0.2.0]`,
  `[0.3.0]` and `[0.4.0]` moved verbatim to `docs/changelog/v0.2.0.md`, `v0.3.0.md` and
  `v0.4.0.md`; this file keeps `[Unreleased]` and `[0.5.0]`. Archiving is release flow **step
  13** (`docs/VERSIONING.md`) rather than a periodic act, and the archive files carry no size
  gate of their own because R5 already bounded every byte in them before it was archived —
  `docs/PLAN.md § 4` states that invariant and the immutability of a released section that
  rests on it.

- **card#9707** — **`release-pr-guard` R6 refuses a release that ships less than `dev` holds unless
  the PR body says exactly what it leaves out.** Nothing compared a release head to the integration
  branch, and the cost was measured rather than imagined: PR #176 (v0.5.0) sat open for roughly a day
  at head `e5c1d9e` while four PRs merged to `dev` behind it, every check green the whole time, and
  merging it in that state would have shipped a release omitting four cards with no automated surface
  saying so — the tag, the changelog section and the green wall all describe the BRANCH, while the harm
  is the gap between the branch and `dev`. A human reading the branch caught it, which is not a
  mechanism. **Two measurements compose into one verdict.** R6a is content: every path where the head
  differs from `origin/dev` outside the release's own two edits (`VERSION`, `docs/CHANGELOG.md`) is
  residue — symmetric on purpose, since a non-artifact difference is either `dev` moving after the cut
  or a feature edit riding the release branch, and neither is a release. R6b is cards: R6a excuses the
  changelog, which is precisely where "four fewer cards than `dev` held" hides, so every card bulleted
  under `dev`'s `## [Unreleased]` must appear bulleted somewhere in the head's changelog — not scoped to
  `[Unreleased]` at the head, because release flow step 4 has just retitled that section and a scoped
  rule would red every correct release. **The hatch is a declaration, not an opt-out:** a line-initial
  `Release-excludes: <paths and/or card#NNNN> — <reason>` in the PR body, read through a new
  `--body`/`--body-file` pair, and it must EQUAL the measured residue — a hatch satisfied by any
  non-empty acknowledgement would be a checkbox, and a declaration written for yesterday's exclusion
  must not silently cover drift that arrived after it, so a partial declaration reds naming both sides.
  **⛔ It is a TREE comparison and may never become an ancestry test.** `git merge-base --is-ancestor`
  answers FALSE on this repo's correctly back-merged v0.5.0 — PR #180 was squashed, so the release line
  is not an ancestor of `dev` and never will be, while `git diff --stat origin/main origin/dev` is
  empty. An ancestry R6 would have been born false here, and the natural response to a false red is to
  weaken the rule. **Ancestry is wrong in BOTH directions and the selftest pins both**: a head that
  reaches `dev`'s content through unrelated history must PASS (the false-RED direction), and a head
  that is a true DESCENDANT of `dev` — which is what the normal release path produces, since the
  branch is cut FROM `dev` — must still RED when it carries a feature edit of its own (the false-PASS
  direction, and R6a's second half). Only the first was pinned when this was first written, and a
  hybrid that short-circuited on ancestry everywhere except the unrelated-history case passed the
  whole suite while asserting nothing on the common path; the descendant fixture is what closed it.
  **Fail-closed where it
  cannot measure:** an unresolvable `origin/dev` is exit 2 rather than a green (the workflow gained the
  integration-branch fetch, with no `--depth` for the measured reason the base fetch carries, and the
  selftest now asserts that of EVERY fetch line rather than the first), and a run never given the PR
  body exits 2 rather than refusing a PR for a declaration it was not shown — an empty body is a real
  state and reds. **Every arm was seen to fail first**, each mutation below producing a targeted red
  with none uncaught: R6 replaced by an ancestry test, R6 *short-circuited* on ancestry with the tree
  diff kept only for unrelated history (the one that reached review passing, and the reason the
  descendant fixture exists), the hatch reduced to non-empty, R6b dropped, R6a's residue emptied, an
  unresolvable `dev` passing, the integration fetch deleted, the body no longer passed, and a
  `--depth` restored on a fetch line.
  **The same card's second finding, fixed in the same PR: `.release-pr.json` now declares
  `card_token_regex`, so `release-pr-body`'s shipped-cards manifest is no longer empty.** With the key
  absent the generic helper could correlate nothing and
  emitted a `## Correlation gaps` section on every release body; card promotion was unaffected (the
  mover carries its own `CARD_RE`), but the one cross-check on the mover could never run. Measured over
  `v0.4.0..v0.5.0`: absent, `--card-manifest` printed nothing; declared, it prints exactly the id set
  the mover's own sweep of that range prints, and the gaps section is gone. The value is the mover's
  `CARD_RE` verbatim rather than the obvious `card#[0-9]+`, which would silently drop the `card-NNNN`
  and glued `cardNN` spellings the correlators accept — and because the helper reads JSON and cannot
  extract the mover's bash, the copy is GUARDED: `bin/card-token-lint.selftest.py` now asserts the two
  are byte-identical and reds if either moves. `ref_token_regex` stays undeclared deliberately: its
  numeric part correlates against a card's `payload.dl_number` and this repo has no decision-log id
  space.
- **card#9742** — **`DatabasePinTest` asserts the key set it guards against the key set `phpunit.xml`
  declares, so a pin the file gains and the test does not is reported as UNGUARDED instead of skipped.**
  The test iterates a hand-written constant, and nothing asserted that constant still described the file:
  a forced pin added to `phpunit.xml` and not to `PAIRED_KEYS` was simply never visited, and the
  suite stayed green reporting on a subset it no longer defined — with the NEWEST pin, the one most
  likely to be wrong, the one it could silently omit. The sets agreed when this landed, so this closes a
  LATENT gap rather than a live divergence; what it protects is store isolation on a shared host, where
  `mezzanine_test` is rebuilt destructively by `RefreshDatabase` on every run and a neighbouring tenant's
  data is what a silent shrink eventually costs. The shape is kanban-solo's, published on rt#506 after
  they hit the identical defect in their own copy of this guard, and taken as offered rather than
  re-derived. **Set equality, in both directions**: a key the file pins and `PAIRED_KEYS` does not name
  reds as `UNGUARDED`, a key `PAIRED_KEYS` names and the file no longer pins reds as `GUARDED BUT
  ABSENT` — the second is the same defect pointed the other way, a pairing asserted for a pin that
  isolates nothing. A key claimed by EITHER half of the pair — a forced `<env>` or a `<server>` — enters
  the file's set, because a half-written pin is exactly what the pairing assertion exists to catch and
  must be judged rather than fall out of the population; the unforced `<env>` entries (`DB_CONNECTION`
  and the defaults above it) claim nothing, since an exported value beats them. **Seen to fail in both
  directions on a FIXTURE COPY of `phpunit.xml`** — staging the control in the real file would change
  the isolation of the run performing it — reporting `UNGUARDED: phpunit.xml pins REDIS_SESSION_DB,
  which PAIRED_KEYS does not name` and `GUARDED BUT ABSENT: PAIRED_KEYS names REDIS_URL, which
  phpunit.xml no longer pins`. `docs/design/FLEET-STATE.md` § 6.2 records the leg and AT-D2-14 carries
  the fixture control as its fourth RED.
- **card#9767** — **this repository now runs the FLEET's PR-body linter on every open PR, and its
  verdict on a body REPORTS rather than blocks.** `bin/pr-body-lint.py` is upstream's own program — the one every coord
  install's CI runs and the review path spawns — vendored byte-for-byte under a `#` provenance header
  that records the source commit and plugin version, because the upstream repository is private and a
  public runner cannot clone it. The new **`pr-body-lint` job** in
  `.github/workflows/card-token-lint.yml` prints its whole verdict and **the step that judges the
  body exits 0 whatever it finds**. ⚠ The JOB is not thereby incapable of failing — its pin and
  selftest steps judge the CHECKOUT and do red — and it never was: those steps shipped with it.
  ⛔ **THE REPORT-ONLY WIRING IS THE DECISION, NOT AN UNFINISHED STAGING STEP.** Run over this
  repository's recent merged bodies, most of them FAIL the standard — and **those reds are correct**:
  the standard governs every PR body an agent writes and is ratified twice, and this repo is genuinely
  non-compliant with it, principally because its house sections (`## What is in it`, `## What you must
  do`, `## What you will see change`, `## Evidence`) are none of them in the standard's closed allowed
  set. A gate that reds ordinary correct-looking work on its first day teaches the people it governs
  to route around it, so this lane makes the findings visible without minting that habit. ⚠ **It is a
  WINDOW, not a destination** — adopting the body shape, and then a dated flip to blocking, are
  tracked on card#9767 and are the operator's calls; requiring the job as a ruleset context is
  likewise the operator's, and **would not by itself make the BODY VERDICT block**, because that step
  exits 0 — though it would make the job's pin and selftest steps blocking, which is the intent.
  **The lane existing is not the class being handled.** ⭐ **WHAT IT REPLACED, AND WHY THAT IS THE POINT**
  — `bin/pr-body-fields.py` and `bin/coord_audit_field.py`, a mezzanine-local two-field presence guard
  built around `review-prep.py`'s `_audit_field`, are **deleted**. That function has MOVED upstream and
  now lives inside this very linter, so the local pair was a copy of something that was no longer
  where it had been copied from, and keeping it would have shipped a second divergent implementation
  of a capability the framework already owns. Upstream's is a strict superset of it: the same two
  presence rules, plus the installer-POV narration rules, plus `attribution-line` — the rule that
  refuses the `FROM:` line the 2026-09-17 operator directive removed from PR bodies fleet-wide.
  **BOTH VENDORED FILES ARE PINNED** in `bin/vendor-pin-check.sh`, whose `--selftest` control arm
  needed the `#` provenance header to exist at all (it requires line 2 of a manifest file to be a
  comment and upstream's line 2 is the docstring; a comment before a module's first string statement
  does not displace `__doc__`). The vendored fixtures carry no manifest row and need none —
  **upstream's own selftest pins each of them by sha256**, which checks the copy against the SOURCE
  rather than against this repo's last declaration, and that selftest runs in the job.
- **card#9754** — **the suite refuses by name unless it can READ `server/.env`, and `php-tests` runs it
  with no `.env` on every PR.** No CI lane had ever run the suite without a `.env`: `php-tests.yml` copies
  `.env.example` to `.env` before the only step that executes it, and `server/.env` is gitignored — so the
  tree CI tested always had one and the tree every fresh worktree and every first contributor has never
  did. A defect that manifests only in that configuration was therefore invisible to the green wall BY
  CONSTRUCTION, and card#9687 is the instance already paid for: the suite errored with
  `ReflectionException: Method ...::test_dummy() does not exist`, naming whichever class the directory
  iterator reached first, while CI stayed green throughout. THE BEHAVIOUR WAS DECIDED BEFORE THE LANE WAS
  BUILT, because a lane built first would only have pinned whatever happened to occur: the suite cannot run
  without an `.env` — `phpunit.xml` pins the test database and deliberately no credentials, since
  credentials are per-host and a committed file must never carry them — so with no file
  `config/database.php` falls back to `env('DB_USERNAME', 'root')` with an empty password, and the run
  reports a cause that is TRUE AND NOT ACTIONABLE. Measured on this host at `dev` `90f9663`: 612 of 977
  tests error with `Access denied for user 'root'@'localhost' (using password: NO)`, which sends a first
  contributor to fix MariaDB grants when the remedy is to copy `.env.example`. `server/tests/bootstrap.php`
  now refuses on READABILITY, with a named message on STDERR and `exit(1)` — **two states, two messages**,
  because one sentence cannot honestly say both *there is no file* and *there is a file you cannot open*,
  and their remedies differ. **The second is the one a presence check would have waved through, and waving
  it through would have re-minted the class this card exists to close:** `file_exists()` is true for a
  `.env` this process cannot open, phpdotenv reads it through `@file_get_contents()`, and
  `Dotenv::safeLoad()` SWALLOWS the `InvalidPathException` that follows — so an unreadable file reaches the
  suite exactly as an absent one does, on the same fall-back credentials, having raised the suppressed
  bootstrap warning that is card#9687's whole mechanism. Measured on this host as a non-root user: on a
  mode-000 `.env`, `file_exists()` is true while `is_readable()`, `fopen()` and `@file_get_contents()` are
  all false, and the pre-guard tree RAN the suite against it and errored with the access-denied. `bin/deploy.sh`
  already refuses both states one layer up, in two refusals with two messages — A5's `does not exist` and
  `env_file_scan`'s `exists but cannot be read by the user this deploy runs as`, which card#9605 named
  "the I/O sibling of the two cases above, and the one that was missing" — and the suite's guard is now
  that same shape. It EXTENDS the card#9499 autoload guard already in that file rather than siblinging it,
  and it is there rather than in `Tests\TestCase` because that guard runs per test and after the framework
  has booted — and booting is what raises the diagnostic card#9687 traced. There is deliberately NO *unless
  the credentials are exported* branch: nothing in this repository runs the suite that way, and in the new
  lane, where the job exports `DB_*`, such a branch would make the lane pass on variables the configuration
  under test does not have. **What no bootstrap-time guard covers is named in the guard's own comment
  rather than left to be inferred:** a readable `.env` carrying a WRONG credential — the template copied
  with `DB_PASSWORD` left unset — reaches the same access-denied, and seeing that requires opening a
  connection, which is not bootstrap's job. **The lane asserts the MESSAGE, never the exit code**, in the genuine no-`.env` window between
  `Install dependencies` and `Create .env` — no extra runner, no second `composer install`. Both states of
  that window exit non-zero (pre-guard 2, post-guard 1) and it has no `APP_KEY` either, so an exit-code
  assertion would pass on all of them and discriminate between none. **Seen to fail, so the green is
  evidence:** the lane's own command, re-extracted from the workflow file and run on this tree with
  `bootstrap.php` restored to its `90f9663` content, exits 1 and prints the access-denied output it got
  instead; with the guard it exits 0 on the refusal. The unreadable state was watched both ways too, on a
  mode-000 `.env` asserted to be genuinely unopenable by this non-root user: the pre-guard tree ran the
  test and errored on access-denied (exit 2), and this one refuses by name before any test runs (exit 1).
  The lane pins the first line of the no-file refusal — the guard's label and the clause naming the state,
  and no path, so the pin does not silently depend on the server directory being named `server`; rewording
  that line reds the lane by design. `README.md` § Running the server locally states both refusals and the
  state neither covers. The full suite passes 977 of 977 with 11268 assertions on PHP 8.5 against the host
  MariaDB with a readable `.env` in place, which is the run this guard leaves untouched.

- **card#9687** — **the full-suite gate no longer errors on a checkout that has no `server/.env`, naming a
  class that has nothing to do with it.** `FixedWindowPinCoverageTest` probes each class that asserts a
  `429` by constructing it and invoking its `setUp()`, and it passed the constructor a placeholder name
  rather than the name of a test method that exists. PHPUnit's constructor argument is not a label: the
  runner's global error handler resolves it by REFLECTION, at a moment the probe does not choose. Any PHP
  diagnostic raised while the probe's instance is on the call stack reaches
  `Event\Code\TestMethodBuilder::fromCallStack()`, which takes the nearest `TestCase` — the probe's, not
  the running one — and prettifies its name through `new ReflectionMethod($class, $case->name())`. A
  placeholder therefore throws `ReflectionException: Method ...::<placeholder>() does not exist`, and it
  names whichever class the directory iterator reached first, so the message points nowhere near the line
  that caused it. THE DIAGNOSTIC THAT FIRES IS THE MISSING `.env`: the probed class's `setUp()` boots the
  application, phpdotenv reads the absent file through `@file_get_contents()`, and a SUPPRESSED warning
  still reaches PHPUnit's handler. `.github/workflows/php-tests.yml` copies `.env.example` to `.env`
  before it runs the suite, which is why the wall stayed green while a fresh worktree — which has no
  `.env` — reddened, and why the PHP version the lane pins is not the discriminator it was first read as:
  measured on this host on PHP 8.5, the same tree errors without an `.env` and passes with one. The probe
  now derives the name by reflection from the class's own public test-prefixed methods, and a covered
  class with none fails LOUDLY by name instead of re-minting a placeholder.

- **card#9644** — **`bin/deploy.sh`'s phase-A gates that judge the RELEASE are callable one at a time, and
  the file can be sourced without running a deploy.** A6, A10, A10b, A11, A12 and A13 were written inline in
  `phase_a` — one straight-line function with no per-gate entry point — and the bottom of the file read
  `main "$@"` unguarded, so the only way to reach a gate's CONTENT predicate was to run a whole deploy, and
  that refuses at A5 without a production `server/.env`. Three of the refusals behind that wall are
  unconditional properties of the target tree and fire on every deploy: a `server/composer.json` with no
  `require.php`, a PHP constraint the floor check will not evaluate, and a target `bin/supervision.sh` that
  defines no `supervision_install_plan`. That is the shape which let a missing `server/package-lock.json`
  refuse every deploy for days while the suite stayed green against fixtures that carried one (card#9631,
  card#9637). Each gate is now a top-level function taking the commit being deployed —
  `gate_a6_php_floor`, `gate_a10_migration_algorithm`, `gate_a10b_config_drift`,
  `gate_a11_trusted_proxies`, `gate_a12_asset_lockfile`, `gate_a13_target_plan` and
  `gate_a13_supervision` — called by `phase_a` in the order they refuse in, and callable one at a time by
  anything else. Each header states what the gate touches besides git: four read the object database and
  the arguments they are handed and nothing else, and the two whose whole job is a comparison with THIS
  host say so — A10b against its `.env`, A13 against its crontab and the serving release's lock paths —
  which is why A13's host-free half, reading the target's `bin/supervision.sh` and running that release's
  own install plan in a bash process of its own, is `gate_a13_target_plan`. `main "$@"` runs when the file
  is RUN; sourcing it defines the functions and returns, and what sourcing DOES do is named at the guard
  (`set -Eeuo pipefail` in the caller's shell, `bin/supervision.sh` sourced beside it, `php` run for the
  host version, and the caller's `$@` cleared so it is never parsed as this deploy's arguments).

  **The behaviour is unchanged, and that is the whole of the claim.** `bin/deploy.selftest.sh` passes every
  assertion before and after, and the two runs' output agrees line for line except for the fixture COMMIT
  IDS the harness prints when it blinds an object: those fixture trees carry `bin/deploy.sh` itself and
  their commits pin no date, so those ids move between any two runs of any tree — measured against a second
  run of the unchanged one.

  **`bin/deploy-gate-inputs.sh` derives three of its classification fields out of `bin/deploy.sh`'s source
  text, so moving the reads moved them.** Its `fn` column now names each gate instead of `phase_a`, and
  A13's disposition digest changed because that read no longer sits last in `phase_a`, where its region ran
  on through A14's refusal and the `Ready:` block; every other digest is unchanged. Both rows are the ones
  that check derived and printed for pasting. Its own selftest passes unchanged.

  **The seam is the half that landed.** The reads are still DERIVED from `bin/deploy.sh`'s source text
  rather than enumerated by it, so every escape shape in that lane's `NOT PROVED BY A GREEN` block is as
  wide as it was, and the lane calls no gate yet. Both are stated where those claims are made.

- **card#9732** — **`release-pr-guard` judges a pull request only while it is still OPEN, so a release
  that shipped correctly no longer collects a permanent red check.** `edited` fires on a pull request
  that has already MERGED: editing PR #176's body after v0.5.0 went out re-ran the gate and failed it on
  R2 — *"VERSION is '0.5.0' at the head and '0.5.0' on main — UNCHANGED"* (run 35186872206). The rule
  was right and the context was wrong. R2 asks whether a release is about to merge WITHOUT a bump, so
  head == base is a missing bump while that release is pending and the CORRECT terminal state once it
  has landed — `main` carries 0.5.0 precisely because the release went out — and nothing in the two
  trees the rule reads tells those apart. The difference is whether the PR is still open, so the test
  lives where that difference is: the job now carries `if: github.event.pull_request.state == 'open'`
  and **no rule changed**. R2 still refuses an open release PR whose `VERSION` has not moved, which is
  the 2026-08-30 defect — the merge lands, `auto-tag-version` then finds the tag on another commit, and
  the only remedy is a second release PR — that this gate was built to stop. Being a required status
  check is unaffected, and that is why the test is a job condition rather than a `branches:` or `types:`
  narrowing: every PR that can still merge is open, so an open PR produces the same completed run under
  the same job id, where a filtered-away run would read as pending forever
  (`docs/VERSIONING.md § Branch model`). What is removed is only the run on a PR whose merge button is
  already gone. `bin/release-pr-guard.selftest.py § 12` holds both halves — the condition read
  structurally off the JOB, so a condition on a step, on another job, or on a field that does not exist
  all read as absent, and the very tree run 35186872206 judged still going red on R2 and on R2 alone
  when the PR is open. What that section cannot exercise is named in it: GitHub evaluates the `if:` and
  produces the skip, so the end-to-end behaviour was measured on the real surface instead.

- **card#9611** — **`bin/deploy.sh`'s ref resolve (A7) and release check (A8) name the cause they
  actually established.** Both refused correctly and then stated a cause that had never been
  established, which is canon #10's wrong-but-specific cause and costs an operator the debugging
  path rather than the deploy: A7 said *"'<ref>' does not resolve to a commit on origin"* and A8
  *"<sha> is not contained in origin/main"* on a checkout whose OBJECT STORE was what had failed.
  **The status of the call A7 was making cannot discriminate**, and that is measured rather than
  argued (git 2.53.0, packed and loose): `rev-parse --verify --quiet <ref>^{commit}` exits **1** for
  a ref that is not there AND for every object-store failure tried — pack chmod 000, idx chmod 000,
  pack deleted, one byte flipped mid-pack, loose object chmod 000 — and dropping `--quiet` gives
  both **128** with the same `fatal: Needed a single revision`. So the fix is not a status threshold
  on that call: resolving a NAME reads the refs alone and never opens the object store (measured on
  a broken checkout, `rev-parse --verify --quiet refs/remotes/origin/main` still answers), and
  whether the object it names is a readable commit is a SECOND question, asked of `cat-file -t`,
  where a non-zero status can only be a failed read. `git_commit_of` asks them apart, peels an
  annotated tag in a third step, and hands a failed read to card#9608's existing refusal helper
  (`git_read_unusable`), which is the one place the PHASE decides whether a refusal may say
  *"nothing was changed"*. A8 keeps `--is-ancestor`'s statuses apart the same way — 1 is *"it is not
  an ancestor"* — and drops the `2>/dev/null` that hid git's own error. **And neither the status 1
  nor the status 128 is ONE condition**, which is the second half of the same defect: `--ref` is the
  operator's own string, so rev syntax (`main~2`) or an abbreviated id makes the resolve walk into
  the object store, where a failed read also comes back as 1 — told apart now by git's own SILENCE,
  which an absent name answers with (and the shapes that silence CANNOT see are named to the
  operator rather than assumed away: on a checkout whose pack is unreadable, every candidate that
  needs the pack answers silently); and
  `--is-ancestor` exits 128 for a `origin/main` THAT IS NOT THERE on a completely healthy store,
  exactly as for a graph it could not read, so the release branch is resolved by NAME first.
  **Three behaviour changes beyond the wording**: a git failure at either site now prints git's
  error above the refusal; `--allow-unreleased` no longer carries a deploy past an ancestry that
  could not be read — it waives the FINDING that a commit is unreleased, and this run has no finding
  to waive — while it DOES still waive a `origin/main` that does not exist, where the question is
  answered (nothing is released, so neither is this commit) and where the old reading had left the
  in-window recovery deploy (`--ref <sha> --allow-unreleased`) with no override and no next step;
  and an ancestry that genuinely could not be read is refused with the repair named. Every assertion
  this card adds to `bin/deploy.selftest.sh` was seen to fail against the commit before it and pass
  with the fix, each paired with the other direction over the SAME broken store (a ref that is
  genuinely absent still refuses as absent) and with a control one variable away on a readable one.
  **The `NEXT STEP` an unreadable ancestry prints is a repair INSIDE the deploy root's own `.git`,
  and it is one that WORKS** (r4): `fsck` names the object and says whether it is unreadable or gone;
  an unreadable one is restored in place with `chmod`; one that is GONE is replaced by swapping
  `.git` alone out of a `git clone --no-checkout` and running `checkout --force`; `repack -a -d`
  CONFIRMS afterwards, because it refuses outright if anything reachable cannot be read. ⛔ **It also
  says what does NOT work, because the advice it replaces looked like it had**: `git fetch`
  negotiates from REFS, and this refusal is only reached after the refs resolved and `$SHA` was read,
  so the object that cannot be read is an INTERIOR one this checkout's refs already claim and the
  remote is never asked for it — measured, git 2.53.0: with the object unreadable `fetch --prune`
  exits 0 having transferred nothing and the object is still unreadable, and with it DELETED it exits
  0 too and the object is still gone. The prescribed step therefore returned exit 0 and no output to
  an operator with the app down, who read that as the repair having run and met the identical
  refusal. It still says outright not to re-clone the deploy root: `server/.env` is created on the
  host and is in no commit, so its `APP_KEY` and `DB_PASSWORD` exist nowhere else, and a fresh clone
  also takes `server/storage/` and the `.deploy-failed` marker — the logs and the marker the failure
  banner sends that same operator to. The `.git`-only swap reaches the same place with no
  preservation list to get wrong under pressure, which is why it is the last resort named.
  **And a status that is neither 0 nor 1 is split by that same silence**: `rev-parse`
  exits 128 having printed NOTHING for `@{…}` reflog syntax on a completely healthy store, and
  `refs/remotes/origin/HEAD` exists in every clone with a reflog that gets one entry and never grows
  on a deploy root — so `--ref HEAD@{1}` refused with *"the REFS could not be read"*, under a line
  saying git's error was above it, on a host where nothing was wrong. That refusal now states the
  status, claims no failed read where git printed none, and says what to deploy from instead; a LOUD
  non-zero is still named as the failed read it is. The coverage that missed it is fixed at the
  contract rather than at the case: `bin/deploy.selftest.sh` carries one case per ANSWER
  `git_ref_oid` declares — `0`, `1`-silent, `1`-loud, other-silent, other-loud — four of them DRIVEN
  through the script, and the fifth (`other-loud`) named UNREACHABLE through it, with both halves of
  that asserted rather than skipped: git really does answer that way with `packed-refs` unreadable,
  and A7's own `git fetch` really does meet it first. The branch no case exercised was the branch
  still over-reading. Also: a `--ref` beginning with `-` is
  classified as the NAME it is (`check-ref-format` parsed it as an option and the note then called
  it rev syntax; that command accepts neither `--end-of-options` nor `--`, measured, so the leading
  dashes are stripped for the classification instead). **And the rev-syntax test is POSITIVE now,
  because a `check-ref-format` failure was never evidence of rev syntax** (r4, the class the dash
  fix was one input of): `--allow-onelevel` exits 1 for around a dozen rules, measured exit 1 and NOT
  rev syntax for `a b`, `main..dev`, `foo.lock`, `ab[c`, `.foo`, `foo//bar`, `ab*c`. So an ordinary
  typo — `--ref 'release 1.2'` — was told *"it carries rev syntax, so resolving it walked the commit
  graph"* and pointed at a possibly-damaged object store: three false statements in one note, on a
  completely healthy host, where the fault is a space. Measured for that same input, resolving the
  name exits 1 with EMPTY stderr — a lookup in the refs that never opens an object. Rev syntax is
  detected now by the metacharacters that ARE it (`~`, `^`, `:`, `@{`), and a name git simply refuses
  gets the note that is TRUE of it, which is the more useful answer anyway. **And `1`-loud is not one
  condition either**: a peel to a type the object is not is LOUD at status 1 on a COMPLETELY HEALTHY
  store — `--ref 'main^{blob}'` gives `error: …: expected blob type, but the object dereferences to
  tree type` — so that branch told an operator git's error named what it could not read while every
  read had succeeded. **And the same claim was live at a SECOND site, found by grepping the tree for
  this round's own false claim rather than by the review that named the first one**:
  `git_commit_of`'s tag branch has said since r2 that one of its two cases is git peeling the tag
  perfectly well and arriving somewhere this deploy cannot use — and it still refused through the
  wrapper whose fixed line states that git's error names what it could not read, so an annotated tag
  over a TREE was called a failed read on a store where `git fsck` exits 0 (measured, git 2.53.0:
  128, `error: …: expected commit type, but the object dereferences to tree type`). Both sites now
  ask ONE function, `git_peel_mismatch`, and it keys on git's MESSAGE and not on its status, because
  the same wording arrives at 1 from the `--quiet` call site and at 128 from the one without it — a
  status rule would have had to be re-derived per caller and would have been wrong at the next one.
  That call site captures git's stderr now instead of letting it go straight past, because the
  message IS the discriminator.
- **card#9693** — **`bin/deploy-gate-inputs.sh` rules on each name in `bin/deploy.sh`'s reader family by
  what that function's own body does, instead of reading the family list as the population.** The lane
  stopped at exit 2 over a healthy `bin/deploy.sh` and blocked PR #178, and it was RIGHT to stop: it
  refuses to report green over a read it cannot classify, which is the defect card#9637 built it to end.
  What it could not classify was the deploy's own family DEFINITION. `git_read_call_site`'s `case` list
  is the deploy's frame-walking family — a different population from "functions that read a path out of
  the release" — and card#9611 grew it past one line, where this derivation only ever read a case label
  written on one. From that single cause the lane failed in both directions at once: the reader names it
  lost stopped being exempt, so the readers' own plumbing and the case list that defines them read as
  calls to a reader; and `git_ls_at` fell out of the LOOSE half of the derivation while the STRICT half
  went on matching it, so a read written exactly as the lane's own remedy prescribes — `git_ls_at <var>
  "$SHA" <path>`, on one line — was reported as a shape the derivation does not match, over advice
  telling its author to write it the way it already was. A gate whose advice does not apply to the lines
  it fires on trains people to ignore it, so that text is fixed in the same change: it names both shapes
  `READ_RE` matches and prints `READ_RE` itself beside them, and `bin/deploy-gate-inputs.selftest.sh`
  matches the shapes it prints against that pattern, so the advice cannot drift from what is accepted.
  A case label is now joined across its `\` continuations and is never read as a call site; the family is
  derived from that list, from every function whose body reads a path, and from every function that
  DELEGATES to one (`git_ls_at`'s whole body is a call to `_git_ls_at`, so it was in the family only
  because the list happened to name it). **Being in that family is no longer being a reader of the
  release tree.** Each candidate is ruled from its own body and the ruling is PRINTED on every run beside
  the name: a git invocation naming a path (a `--` pathspec or a `rev:path` argument — how git's CLI
  names a path inside a tree, which is a property of git rather than a restatement of the deploy) is a
  read the gate is about; `cat-file -t "$oid"` reads an object by bare id and `rev-parse` resolves a ref,
  neither of which can hand a caller a file out of the tree; a refusal helper runs no git at all.
  Ambiguity resolves toward READER, so the error the rule can make is a stop and never a pass — and a
  family member running a subcommand this check has no reading for (`archive` puts paths on disk), or a
  name in the list this file defines no function for, stops the check rather than being assumed
  harmless. Both of those exited 0 before this change. What a green here still does not prove is
  enumerated, as it has been since card#9637, in the `NOT PROVED BY A GREEN` block the check PRINTS on
  every run — including the one shape this ruling adds, a reader whose path arrives already assembled
  inside a variable — rather than in a copy here that would go stale.

## [0.5.0] — 2026-09-16

- **card#9635** — **`bin/shell-lint.sh` and the `Shell lint` CI lane: every shell script this repo
  tracks is analysed on every PR, by a ShellCheck whose version is named.** Both halves of the gap
  were open at once. A pinned ShellCheck 0.9.0 had been on the agent box the whole time, installed
  as `~/.local/bin/_shellcheck-pinned` — `which shellcheck` finds nothing, and the underscore reads
  as private, so three separate rounds concluded the analyser was absent and skipped the lint. And
  `shellcheck` appeared nowhere under `.github/`, so nothing ran it at PR time either; closing only
  the first half would have helped the rounds that remember to look, which is the same failure with
  better odds. Meanwhile `bin/deploy.sh` — the script that deploys production — grew by roughly 400
  lines in one cycle with no analyser run over it once. The wrapper resolves the analyser as
  `$SHELLCHECK`, then the pinned build, then PATH, and **refuses to run under any version other than
  the one the baseline was measured under**. The CI lane INSTALLS that version — a sha256-verified
  download of the upstream 0.9.0 release, whose binary is byte-identical to the pinned build the
  baseline was measured under — rather than taking whatever the runner image ships, so the refusal
  fires on a decision of this project's instead of on GitHub's image schedule: two ShellCheck
  versions genuinely disagree at ERROR severity over the same file, so a verdict from an unnamed
  program is the thing `agent-board-toolkit/.shellcheck-version` exists to stop. The version AND the
  release digest are written once, in `bin/shell-lint.analyser.pin`, which the workflow and the
  wrapper both read — so the build CI installs and the build the refusal asserts cannot drift apart
  by one of them being edited alone. That upstream pin file is unreadable from a CI runner; where it
  IS readable the wrapper cross-checks both values against it, and where it is not, it says so in
  the log by name rather than implying a check it could not do. The
  file population is DERIVED from the checkout — every tracked `*.sh` plus every tracked file with a
  shell shebang, which is how `bin/promote-cards-by-token` (1.4k lines of bash, no extension) is in
  the set that a `bin/*.sh` glob would have missed silently — and the lane prints it, because a lane
  that lints nothing passes exactly like one that lints everything. The lane starts green against
  `bin/shell-lint.baseline.tsv`, a per-(file, ShellCheck code) debt ledger regenerated by
  `bin/shell-lint.sh --update-baseline` and first measured at `578e1b3`: it is a ledger and not an
  approval, nothing in it has been judged correct, and a count is kept there rather than in prose
  precisely because prose copies go stale. Keyed by count and not by line so that edits above a
  finding do not red a PR that changed nothing about it. **A finding count is evidence about a
  MEASUREMENT, and that measurement has three inputs — the file population, the analyser version,
  and the analyser's effective CONFIGURATION — so the ledger records all three and every narrowing
  it can SEE takes one typed acceptance.** That is narrower than every narrowing there is: the two
  it cannot see are named below, measured, and left open as a decision on this card. Guarding the population alone leaves the cheapest
  input of the three completely open, and it is the only one that narrows coverage without touching
  a single file the ledger names: measured on this tree, a one-line `.shellcheckrc` at the repo root
  plus a real `rm -rf $MEZZ_DOCROOT` added to `bin/supervision.sh` printed *"every finding the
  ledger carried for the pair is fixed"*, exited 0, and asked for the narrowed ledger to be
  committed; `disable=SC2317,SC2016` took the same tree from 114 findings to 31 the same way; a
  file-scope `# shellcheck disable=` did it with no new file at all, and a `SHELLCHECK_OPTS` in the
  environment did it with no file at all. So the ledger's `#cfg` block now records the analyser
  version, the exact invocation, every `# shellcheck` directive inside a population file (count-keyed,
  like the findings), and the set of codes the analyser actually emits over a fixed probe script the
  run writes and throws away — the control that catches an analyser gone quiet about a code THE
  PROBE CARRIES, which no restatement of intent can, and which says nothing about a code it does not
  carry. `--norc` makes every `.shellcheckrc` inert and an emptied `SHELLCHECK_OPTS` makes that
  channel inert, both recorded rather than merely done; a file-scope disable is REFUSED at exit 2 by
  file and line, because it blinds a whole class at once and this gate would read the blinding as an
  improvement. **What a green run proves, at its measured width:** the rc channel and the
  environment channel are closed and closed visibly, since both flags ride in the `#cfg invocation`
  row and dropping either is a narrowing that takes a typed `--accept-shrink`; and IN CI the
  analyser's identity is closed as well, because the lane installs it as a sha256-verified download
  of the release the pin names instead of asking a program on PATH what it is — which is what makes
  a CI green stronger than a local one. **Two narrowings stay open, both measured on this tree and
  both left open deliberately.** The ANALYSER BINARY is a channel no `#cfg` row closes and the probe
  is blind to it by construction: a wrapper reporting 0.9.0 truthfully while passing
  `--exclude=SC2317` — a code the probe does not carry — exits 0, prints the ledger's whole SC2317
  class, 60 of its 114 findings, as gone entirely, and calls the run clean; a probe notices only a
  code it contains, so widening it moves that frontier rather than closing it. And DIRECTIVE SCOPE
  lives inside the files unrecorded: the `#cfg directive` rows are keyed by (file, directive) with a
  count, so a directive APPEARING is caught while an EXISTING one RELOCATING is not — a new SC2016
  inside `bin/deploy.sh`'s `previous_stream_pids()` reds at exit 1 (`SC2016 baseline 3 -> now 4`),
  and with that file's own `# shellcheck disable=SC2016` lifted off the line it annotates and
  re-seated over that function the same tree is exit 0 clean, with `--update-baseline` re-deriving
  the ledger byte-identical (`bf8b271c…` either side). Closing either is a WON'T-DO recorded on
  card#9635 — identifying the analyser locally the way CI does is the shape that would close the
  first — and card#9645 owns the count-keying expiry. **The asymmetry this closes was exact and
  inverted: adding debt to the ledger has always taken a typed `--accept-new`, and removing coverage
  from it took nothing at all** — including through `--update-baseline`, which accepted an arbitrary
  shrink and is the command the population-loss error instructs you to run. That error is the right
  failure mode and is unchanged; its remedy is now gated by `--accept-shrink`, the one typed
  acceptance for every narrowing: a file leaving the population, a directive appearing, the pin
  moving, the flag vector changing, the analyser going quiet. A decrease still never reds, so a
  cleanup PR still never argues with the gate; an unexplained one is now emitted as a `::warning`
  and appended to the job summary, because a step that exits 0 renders collapsed in the GitHub UI
  and the text was reaching nobody, and the run's last line then says so instead of saying "clean".
  The residual is named rather
  than implied: a swap INSIDE one (file, code) pair — one finding out, one of the same code in the
  same file in — nets to zero silently, and that is the price of keying by count, paid for the
  reason above. Every ShellCheck line must also land in exactly one count: the run asserts that the
  two numbers it already computed agree, and refuses rather than report a narrower measurement as a
  clean one. **The existing findings are reported, not fixed here** — a lint fix inside
  `bin/deploy.sh` is a behaviour change on the production deploy path and belongs in its own
  reviewed change. Seen to fail before trusted (canon #9): a synthetic unquoted expansion injected
  into a tracked script turned the gate red naming the file, the code, the baseline-to-now counts
  and the offending lines, and removing it turned it green again — locally and on this PR's own CI
  runs, with the tree byte-identical either side. The version assert, the pin cross-check and the
  no-analyser path were each exercised the same way. Every branch of the decrease rule was then
  measured against the tree it guards: a fixed baselined finding alongside an injected `rm -rf
  $target` reds at exit 1 as an unexplained decrease plus a new finding and the re-baseline that
  used to be instructed there is refused at exit 2 with the ledger byte-unchanged; a script that
  loses its shebang, and with it its place in the population, is exit 2 naming the file rather than
  two classes "improved"; two findings removed and one of the same code added back reports the
  decrease and prints the finding that survived, which is the injected one; and a class that
  genuinely reaches zero is the one case that still asks for the re-baseline. The parser assert was
  seen to fire by putting a colon in a tracked script's name: the pre-assert code called that tree
  clean at exit 0 with the unquoted expansion inside it dropped unread, and the assert makes it exit
  2 naming both lost lines. Each configuration channel the ledger RECORDS was then re-run against
  the pinned ledger and each is closed: the root `.shellcheckrc` is inert and the `rm -rf
  $MEZZ_DOCROOT` under it reds at exit 1 by name; the file-scope directive is exit 2 at its file and line; the broad rc and the
  exported `SHELLCHECK_OPTS` both leave the measurement at the ledger's whole population and 114
  findings where they used
  to leave it at 31. The probe row was seen to discriminate the way a control must: an analyser
  wrapper that reports version 0.9.0 truthfully and passes `--exclude=SC2086` underneath is exit 2
  naming the code that stopped being reported, and the identical wrapper without the exclusion is
  green. `--update-baseline` over a tree that lost `bin/promote-cards-by-token` is refused at exit 2
  with the ledger byte-identical (sha256 compared either side), and the same command with
  `--accept-shrink` writes it while naming what left. The finding parser was defeated by a path
  containing a SPACE — `awk`'s default splitting put the ShellCheck code in a field that was
  discarded, so every code for that path collapsed onto one key while the totals still agreed and
  the sum assert passed — and a tracked `bin/lint probe.sh` now reports its own code, its own count
  and only its own lines. A conflicted index, which `git ls-files` reports once per stage, is exit 2
  rather than a file counted three times; and a `#pop` line deleted by hand with its count rows left
  behind is exit 2 rather than a routine addition.

  The ledger's first measurement now covers `bin/deploy-gate-inputs.sh` and `bin/deploy-gate-inputs.selftest.sh`. They reached `dev` through card#9637 while this lane was still on a branch, so they entered the tree ahead of the analyser that would have read them; merging `dev` here brought them under the gate for the first time and it reported every finding in them as new, which is the lane doing its job. Each was read as a suspected defect first and disposed of on its own line. The SC2034 names in `bin/deploy-gate-inputs.sh` are positional field sinks that hold a tab-separated row's shape open: the last name in a `read` absorbs everything left on the line, so each unread name is what keeps the field beside it intact, and the annotations name that field and the corruption that follows from dropping it — one of them guards `fn`, which the guard below it compares against the pinned table. In the selftest, `eq` now branches, which leaves `[ "$2" = "$3" ]` as the one thing deciding a case; the chained `A && ok || bad` it replaces gave the reporter's own exit status a second route to `bad`, so a `printf` failing under a CI lane's redirection could turn a case that passed into a FAIL for a reason that is not about the check. The rest carry the reason each single-quoted string has to reach its destination as literal text: they are the shell source a case injects into the fixture's `bin/deploy.sh` and the needles quoting the checker's own output, where a `$SHA` or a backquote expanding here would leave the case measuring something else. Every directive is line-scope with its reason on it, and the ledger's `#cfg directive` rows record them, which is what makes each one a decision somebody typed.

  The same first measurement now covers `bin/env-mirror-diff.sh` and `bin/env-mirror-diff.mirror.sh`. They reached `dev` through card#9591 after this lane's baseline was measured, so the analyser had never read them, and merging `dev` here brought them under the gate for the first time — which is the lane doing its job. Each finding was read as a suspected defect before anything was written down, and the ledger carries no finding row for either file: it records the annotations, because every finding was either fixed or respelled so the analyser can see the intent. The two that can produce a silently WRONG VALUE rather than a crash came first. `SC2178` and `SC2128` said `classify` expands an array without an index: ShellCheck keeps one namespace per FILE, so that function's scalar `o` and `check_loopback_sets`'s unrelated `local -a o` are one variable to it, while bash holds them apart (measured: a scalar `local o=` called from inside a frame that holds `local -a o` is still `declare --`, and the `read -a` below it still yields every field). So the rows are named `orow` and `mrow` rather than annotated — a disable would have pinned a claim that is false and left those two lines blind to a real `SC2178` later, where distinct names make the analyser's model TRUE and keep the check live. `SC2209` asked whether `REPLY=unset` and `ca=set` meant the builtins. They are the words this encoding carries for a key that is absent and for a CA that is present, compared against `bin/env-mirror-diff.oracle.php`'s own text, and they are now QUOTED — the spelling ShellCheck's own message offers for a literal, which likewise leaves the check live on every other assignment in the file. Measured both ways: `REPLY="$(unset)"` answers every absent key with the empty string and reds the scan differential, and `$(set)` is a variable dump, which in a real process would put the environment into a cell this script PRINTS. `SC2317` named the bodies of the `refuse` functions the two fixture subshells define. Those bodies run: they are called from the deploy.sh reader text this script extracts and sources at run time, which ShellCheck cannot follow, and a fixture whose `.env` holds a NUL answers `refuse` in scan mode and `refuse:scan` in locality mode. `SC2034` said `locality_one`'s `ENV_FILE` is unused; deleting it leaves every locality cell EMPTY with the mirror still exiting 0, which is the silent answer that finding would be naming if it were true. `SC2016` is the single-quoted strings that have to arrive somewhere else as literal text — a fixture line that must still hold `${DB_DATABASE}` for phpdotenv to interpolate, and the sed programs of the mutation controls, whose patterns are `bin/deploy.sh`'s and the oracle's own source; measured, each expanded spelling matches nothing. `SC1112` is a typographic apostrophe in two headings, now inside double quotes, which is how the analyser is told a unicode quote is literal. Each annotation was seen to bite: with the directives stripped, exactly those findings come back and the respelled ones do not. The differential itself prints byte-identical output before and after, cell for cell, every control still red when mutated. `bin/env-mirror-diff.mirror.sh`'s `SC1091` directive — the one in this tree that carried no reason — now says that the file it sources is written by this script at run time, out of whichever `bin/deploy.sh` the caller named.

- **card#9591** — **the differential that found card#9561's round-4 blocker is now a committed test, run on
  every PR.** `bin/deploy.sh` cannot ask PHP what `server/.env` means at phase A — the config cache is stale
  by construction and the host may have no working app — so it mirrors, in bash, the part of
  vlucas/phpdotenv that decides where a line ENDS, whether a line is a SETTING, and what the app then
  RECEIVES for it. Every one of those is a claim about somebody else's code, and a false one already cost a
  BLOCKER: a lone `\r` made a `.env` two lines to `Dotenv\Parser\Parser` and one to the reader that decides,
  so Laravel connected to a remote store in plaintext while A5 read `DB_HOST` as unset and exempted the
  store from TLS. That was found by a differential built in a scratchpad and thrown away — four review
  rounds running, four rebuilds of the same harness. **`bin/env-mirror-diff.sh` + `bin/env-mirror-diff.mirror.sh`
  + `bin/env-mirror-diff.oracle.php`** are that harness, landed as a second job in the existing
  `deploy-selftest` lane (parallel with the self-test, so it adds nothing to a PR's critical path while it
  finishes inside it). Two differentials: the **scan** (`env_file_scan` / `env_lines_load` / `env_get`
  against the vendored parser — does phpdotenv parse the file at all, and does the script read each key to
  the same value it holds) and the **locality** (A5's store verdict against where `server/config/database.php`,
  `ConfigurationUrlParser` and `MySqlConnector::getDsn()` actually send the app, and against the cache driver
  `server/config/cache.php` resolves). **Nothing in it is a re-implementation** — `server/vendor/` is the
  oracle, installed from the committed lock with the same `--no-dev` a deploy uses, and the mirror side runs
  `bin/deploy.sh`'s own text, extracted at run time. **Both AXES are derived on every run rather than listed in the
  harness, and they are held to their sources by DIFFERENT strengths — the LINE-ENDING axis is read from both
  sides and a narrowing on either side reds, while the KEY axis has a floor whose reach is one key, stated below
  and on card#9591.** The line-ending axis is read out of `Parser::parse`'s own split
  regex AND out of `env_lines_load`'s own `content="${content//…}"` normalisation statements, and the two
  sets are held against each other in BOTH directions: a phpdotenv release that adds a terminator adds
  cells, and one that DROPS a terminator `bin/deploy.sh` still splits on reds instead of silently deleting
  the axis that would have caught it — card#9561 round 4 with the arrow reversed. The keys compared are the
  union of the three idioms that read a key from `.env` — `env_read VAR KEY`, a literal-key `env_get KEY`
  (phase B's only smoke check is `url="$(env_get APP_URL)"`) and `server/.env.example`'s key list, which
  A10b loops `env_get` over — held against a floor derived from A5's own refusal text. **What that floor
  reaches is ONE key, and the header says so rather than claiming a refactor cannot narrow the
  population**: the floor's power is the refused keys no other leg covers, which today is
  `MYSQL_ATTR_SSL_CA` alone — every other refused key is in `server/.env.example` too and survives any
  rename through that leg. Measured the other way as well: rename every `env_read` site EXCEPT the
  `MYSQL_ATTR_SSL_CA` one and the harness runs green with `DB_SOCKET` — a key A5's store verdict turns on,
  named by no refusal text and by no `.env.example` — silently gone from the compared keys. The
  `.env.example` leg is likewise a SECOND TYPING of A10b's key grep, over the working tree's copy where
  A10b reads the target release's, with nothing binding the two. Both are recorded on card#9591 as
  won't-do with those measurements, and both are now stated where the derivation is written, in the shape
  `bin/env-mirror-diff.sh`'s own key-floor control already used. What IS
  written down is the fixture bases: `scan_bases` and `locality_bases` enumerate the `.env` shapes by hand,
  and that list is the half a new shape must be ADDED to. The cell count is counted as the run emits cells
  and printed — a recorded count would be a quoted authority that outlives the run that falsified it. **Every differential carries a control seen to fail**: after the clean run the
  harness mutates a *copy* of `bin/deploy.sh` — the `\n`-only splitter that was the blocker, a scan that
  certifies everything, the NUL refusal cut out, a CA judged by its text rather than the value the app
  receives, a `store_locality` blind to `DB_URL`, any `DB_SOCKET` text taken for a socket — and **fails
  unless each one reds the differential**, naming the cell. The oracle carries one too: it runs the whole
  population in one process, so it re-runs it in reverse and requires every cell to answer identically, with
  a deliberately leaky variant proving that check discriminates, and each of the two derivations above
  carries one as well — a `Parser::parse` narrowed to `/(\r\n|\n)/`, an `env_lines_load` that stops
  normalising a lone `\r`, and a `bin/deploy.sh` whose every `env_read` call site has been renamed away must
  each red the check that exists to report it. **`bin/env-mirror-diff.mirror.sh` reads a `.env` only under
  the fixture root its driver exports** (`MEZZ_ENV_MIRROR_WORK`): it takes fixture directories on stdin and
  PRINTS the values it reads, so unbounded it is a general-purpose `.env` value printer for any directory a
  caller names — one hand run pointed at a live host's `server/` puts that host's secrets in a terminal or a
  CI log. It now dies naming the file it would have read, in every mode, and the refusal is watched on every
  run by three legs: the same fixture answered INSIDE the root, refused outside it, and refused with the
  variable unset. Each leg was seen to fail against a deliberately weakened copy. The root bounds a
  DEFAULT, not a privilege: a caller who sets the variable at a real `.env` is reading a file they could
  already `cat`, and the script's header says exactly that. `bin/deploy.sh` is read and **not changed** by
  this card.

- **card#9637** — **CI now asks whether this repository satisfies `bin/deploy.sh`'s own phase-A
  gates, so a release that the deploy would refuse reds at PR time instead of in a maintenance
  window.** `bin/deploy-gate-inputs.sh` + `.github/workflows/deploy-gate-inputs.yml`. The class it
  closes is not the lockfile (card#9631 committed that): it is that **the suite's fixtures were more
  complete than the repository.** `bin/deploy.selftest.sh` runs the gates against FIXTURE repos and
  its fixtures mint a lockfile, so it proved the gate behaves correctly given a well-formed release
  while saying nothing about whether this repo is one — which is how A12 refused every real deploy
  from the day it landed, through three fix rounds and four adversarial reviews. The new check
  **derives** its population, every run, from the `bin/deploy.sh` at the commit under test — its
  `git_read_at`/`git_ls_at` call sites against `"$SHA"` — rather than carrying a written list of
  required paths, because a written list is the restatement that drifts the moment a gate is added.
  What is written down is the far smaller judgement the source text cannot answer: whether an absent
  path makes that gate refuse, warn or pass. **And that derivation STOPS on what it sees rather than
  only finding what it matches:** a derivation that only ever FINDS reads protects nothing,
  because a read written in a shape its pattern misses is absent from the derived set AND from the
  table it is compared against — the two sides agree and the run is GREEN over a gate nobody
  checked. So the strict pattern is paired with a deliberately loose SUPERSET of lines that could be
  a read (every call to a member of the reader family, every line naming `$SHA` in any quoting), and
  **every superset line the strict pattern does not match stops the check at exit 2.** The reader
  family is itself derived two ways — the names `bin/deploy.sh` lists in `git_read_call_site`'s own
  `case`, plus any function that runs `git_at ls-tree|show|cat-file` on a rev it was handed — so a
  third reader added tomorrow is found rather than walked past. **That stop is bounded by the
  superset and is not totality, and the check says so in its own output:** shapes the superset does
  not SEE still run green, they have been measured, and they are enumerated in ONE place — the `NOT
  PROVED BY A GREEN` block the script prints on every run, which is the surface to read rather than
  this entry, the script's header or the workflow's. That list was restated on four surfaces and was
  incomplete on all four; it now has one home that the run being trusted carries. **The remaining
  gap is a recorded decision on card#9637, not an oversight** — widening the derivation was tried
  and declined, because each widening is one more pattern over the same source text; what makes the
  population total is card#9644's seam in `bin/deploy.sh`, its target-tree gates exposed as callable
  units so the reads are enumerated by the deploy instead of pattern-matched out of it. **Each row
  also pins the DISPOSITION, not just the path** — the function the read sits in, and a digest of
  the `refuse`/`warn`/`say` lines the gate reaches from it — so a `warn` that becomes a `refuse`
  stops the check with the new lines printed, instead of leaving a table that quietly describes
  something the deploy no longer does. An empty derivation is still refused rather than reported as
  a pass. A required input that is present but is a SYMLINK is now a finding too, quoting
  `git_read_at`'s own word for it: every mode but `100644`/`100755` is refused by name in the
  window, so presence alone was never the question. **Seen to fail against the one
  natural regression this check will ever have:** exit 1 at `057e051`, naming `server/package-lock.json
  (A12)`, and exit 0 at `578e1b3`, the commit that committed it — one variable, and `bin/deploy.sh`'s
  read set is byte-identical at both. **And `bin/deploy-gate-inputs.selftest.sh` now ships beside it
  and runs FIRST in the same lane**, because a check whose discrimination nobody re-tests is one
  whose pattern can be tightened tomorrow with the lane staying green forever: it builds fixture
  repositories from this repository's own tree, mutates ONE thing in each, and watches the check
  refuse — every escape shape above (an unquoted `$SHA`, a braced `${SHA}`, a different rev
  variable, a third reader function, a wrapper around a reader, a call split over two lines with
  `\`, a path assembled at run time, a raw `git show "$SHA:…"` with no reader involved), a gate's
  disposition changed, a classified read deleted, a derivation that finds nothing, an absent reader
  family, no `bin/deploy.sh` at all, a tool the check needs failing (exit 2, never the exit 1 that
  means a finding), an absent input, an empty one, and one committed as a symlink. Every case
  asserts on the MESSAGE and not on the exit code alone, against one control — the repository as it
  is — and the mutation point is derived from the checker's own pattern rather than written down.
  **A green means less than the card's title and the run says so in its own output:**
  it covers the files phase A reads out of the target tree *that this derivation sees*, and names
  every excluded gate with its reason (A0–A5, A7–A9 and A14 need the deploy HOST; A6's version half
  compares against the runner's php; A10b's comparison half and A13's crontab half need `.env` and
  a crontab). The gates' content
  predicates — A6's constraint shape, A10's `ALGORITHM=`, A11's `trustProxies('*')`, A13's crontab
  render — stay uncovered because they live inline in `phase_a`, which runs only as a whole and
  refuses at A5 without a production `server/.env`; reaching them needs a seam in `bin/deploy.sh`
  that exposes its target-tree gates as callable units — card#9644, filed rather than carved here.
  Restating them would drift from the gate, which is the shape of the defect card#9203 filed and
  which `bin/deploy.sh`'s own A6 comment names. `bin/deploy.sh` is untouched, and neither the check
  nor its selftest needs a host, database, network, checkout or credential: git and bash over the
  object database, with every fixture under one temp dir.

- **card#9631** — **`server/package-lock.json` is committed, so a real deploy reaches phase B for the
  first time.** `bin/deploy.sh`'s A12 gate reads the lockfile out of the TARGET tree and refuses
  unconditionally when it is absent; the file had never been committed, at `dev`, at `main` or at
  `v0.4.0`, and no `.gitignore` rule ever mentioned it. So every `bin/deploy.sh --ref <any>` refused
  at phase A and the prod host (D-08) could not be deployed to at all, while `bin/deploy.selftest.sh`
  stayed green — its fixtures MINT a lockfile (`printf '{"lockfileVersion":3}'`), so the one thing
  that was broken was the one thing no fixture had. **The gate is untouched and was never the
  defect**: `docs/PLAN.md § 5`'s *"What the deploy refuses on"* already names a missing npm lockfile,
  and `server/package.json` floats every range it declares, so a lockfile-less prod build can ship
  different JavaScript from the same commit on two consecutive days with nothing in the repo
  recording which. The lockfile records those ranges' own resolution under node v22.22.1 / npm 9.2.0
  and **no declared range moved** — narrowing one decides what prod builds and is an operator
  decision, not a side effect of this card. `lockfileVersion` is 3, which npm 7 and newer read; the
  prod host's npm version is unknown (D-08) and is a provisioning check, not something this commit
  can establish. Proved on the real surface rather than by the selftest: `npm ci` into an empty
  `server/node_modules` from the committed file, then `npm run build` (vite 8.3.0, 3 modules, into
  the git-ignored `server/public/build/`), then `bin/deploy.sh --dry-run` against this branch
  reaching A13 and A14 where the same command against the parent commit refuses at A12 — one
  discriminating pair, the gate seen to fail and then to pass.
- **card#9608** — **`bin/deploy.sh` refuses a read of the target release that git could not complete,
  where it used to read the failure as a finding about the release.** Phase A judges the release being
  deployed before it is checked out, so it reads that release's files out of git — and it read them as
  `git_at show … 2>/dev/null || true`, which silences git's own error AND discards its status. *"There is no
  such path at this commit"* and *"git could not read it"* both arrived as an empty string, and three gates
  read that empty as a fact about the tree: the `§ 6.9` migration gate printed `ok — no undeclared ALTER on
  events` over a file list it never got; A11 could not reach its `trustProxies('*')` refusal at all and
  emitted `no trustProxies() configured` — a positive statement about a file it had never opened, and
  `*` is what makes D1 `§ 12.3`'s failed-auth limit forgeable, so the gate that exists to stop that shipping
  was the one silenced; A10b compared zero keys. The fix is ONE reader, `git_read_at`/`git_ls_at`, that the
  seven target-tree reads now go through: presence is established with `git ls-tree`, whose status
  discriminates (0 with no output is an honest *"not at this commit"*; non-zero is a failed read — measured,
  git 2.53.0), content is read only after that, git's stderr is no longer silenced, and a failed read refuses
  by name instead of returning a status a caller could drop. That reader judges an entry by its **mode**, not
  its type: `ls-tree` calls a SYMLINK's type `blob` exactly as it does a regular file's, and a symlink's blob
  is the path it points at — so a type check passed one through, `git show` printed `../app.real.php`, and A11
  grepped that path string and again emitted `no trustProxies() configured` about a file it had never opened.
  `100644` and `100755` are read; a tree, a symlink, a submodule and any other mode are refused by name. And
  the refusal's own promise — *"Nothing was changed. The previous release is still serving."* — is now
  structural rather than asserted in a comment: a read that fails once the window is open takes the in-window
  failure path instead, so that sentence can never print with the app down.
  **Installer action: make sure the release you deploy carries `server/bootstrap/app.php`** — a release
  without it is now REFUSED before the window, where it used to be warned about and deployed. `server/artisan`
  line 14 is `$app = require_once __DIR__.'/bootstrap/app.php';`, so every artisan command of such a release
  fails, the first being `php artisan optimize:clear` INSIDE the maintenance window with the app already down
  and recovery a human act; this refusal is that failure moved to before anything is touched, as the PHP floor
  and a missing `bin/supervision.sh` already are. For a healthy release nothing else changes: the read-failure
  refusal fires only on a git read that genuinely failed (an unreadable or corrupt object, a rev that will not
  resolve), where the deploy previously passed while certifying files it had not read; a release that does not
  carry `server/.env.example` is still warned about by name; one that ships no migration at all still passes,
  saying that rather than claiming a list it read; and a migration whose NAME is not ASCII is now read rather
  than refused as absent from the tree it was just listed in (`core.quotePath=false`, so the name `ls-tree`
  prints is the name it will match). `bin/deploy.selftest.sh` produces every condition for real — a loose
  object of its own fixture at mode 000 with the path still in the tree, a symlinked `bootstrap/app.php` in a
  release that DOES `trustProxies('*')`, a hand-written `160000` entry, a deployed release mutated to re-read
  the tree after the checkout — and asserts per case that the false statement is gone, not only that the exit
  code changed.

- **card#9605** — **`bin/deploy.sh` refuses a `server/.env` it cannot read, instead of certifying it and
  stopping on the wrong cause.** A `.env` that exists, is a regular file and whose mode's other-digit is `0`
  passes A5's two file checks and can still be one the deploy user cannot OPEN — written by another user when
  the host was stood up and left mode 640 to an owner and group this script is in neither of. The loader
  silenced exactly that: its `2>/dev/null` was written BEFORE the input redirect, so stderr was already gone
  when the OPEN failed, and a failed open returns the same status 1 that a complete read to EOF returns. The
  file came back EMPTY, `env_file_scan` certified a file it had never read, and the deploy stopped on
  `APP_ENV is 'unset'` — a cause that is not the real one, with no stderr at all. That is the failure
  card#9561's NUL refusal exists to end, reproduced on a different input. The open is now a step of its own,
  with a status of its own (`ENV_LINES_UNREADABLE`), and A5 refuses by name: it says the file is there and
  that opening it for reading is what failed, that ownership is the usual cause, and that nothing was read
  out of it — printing no line and no value. It is an I/O refusal like the missing-file one beside it, and
  NOT a fourth thing this reader is narrower than phpdotenv about: it judges nothing about what the file
  contains, because nothing was read. **Installer action:** none beyond what card#9561 already asks — leave
  `server/.env` owned by the account the deploy runs as, mode 640. `bin/deploy.selftest.sh` covers it beside
  the same file made openable again, and ASSERTS that its fixture really is unopenable by the user running
  the suite rather than skipping when it is not (root opens every mode, and would otherwise certify nothing
  while looking green).

- **card#9561** — **`bin/deploy.sh` requires a TLS CA only for a store on another host.** A5 refused
  every host whose `server/.env` left `MYSQL_ATTR_SSL_CA` unset, which refused production's local
  MariaDB. It now reads where the `mysql` connection goes, the way Laravel resolves it: a set
  `DB_SOCKET`, or an effective host (`DB_URL`'s host when it names one, else `DB_HOST`, else
  `127.0.0.1`) of `localhost`, `127.0.0.1` or `::1`, is a store on this host, and A5 passes it and
  prints why. Any other host, and a `DB_URL` A5 does not follow, still needs the CA. A5 decides from
  `server/.env` alone; a variable set in the PHP-FPM or process environment, which Laravel prefers, is
  outside what it reads. Every key A5 reads is refused by name when `.env` writes it in a form other than
  plain `KEY=value` (an `export` prefix, whitespace around `=`, a `$` outside single quotes, an inline comment, a bare key, or a
  second definition), because Laravel reads those differently; the refusal prints no value. A5 also refuses,
  before it reads any key, a `.env` that Laravel's own parser does not read as the lines it is written in:
  a `KEY="` value that its line does not close swallows the lines below it (a `DB_SOCKET=` line inside one is
  never defined, and a value nothing closes is discarded with everything it swallowed), and one line the
  parser rejects fails the WHOLE file, so Laravel reads nothing from it and every request dies at boot. Both
  were certified as readable before; the refusal names the line's NUMBER and never its text. A `.env` carrying
  a NUL byte is refused too, and for the opposite reason: Laravel reads it and boots, while nothing in this
  script can — `bash`'s `read` stops at the first NUL, so no line below that byte is loaded and every key
  defined below it came back "unset", and the deploy stopped on `APP_ENV`, naming a cause that was not the
  real one.
  **A line ends where Laravel's parser ends it, in every reader.** `.env` is split once, on `\r\n`, `\n` or a
  lone `\r` alike, exactly as `vlucas/phpdotenv` splits it, and both the whole-file check and the key reader
  work from that one split. They did not before: the key reader used `grep`, whose line terminator is `\n`
  only, so a `.env` written with Windows (CRLF) line endings — which Laravel boots on perfectly — was ONE
  line to it and every key was reported as written "in a form this deploy does not read", never naming the
  line endings as the cause; and a lone `\r` anywhere in the file HID the line after it, so a `.env` ending
  `# note\rDB_HOST=db.internal` sent Laravel to another host in plaintext while the deploy read `DB_HOST` as
  unset, took the local default and certified the store as being on this host. A CRLF `.env` now simply
  works, as it does for Laravel, and a value hidden behind a `\r` is read and judged like any other.
  Every A5 check whose answer could differ decides on the value the app RECEIVES rather than the text of the
  line — `APP_DEBUG=FALSE`, `False` and `(false)` are each debug OFF to Laravel and now pass, where the text
  compare refused them. Where A5 still compares text (`APP_ENV`, `DB_CONNECTION`, a `/`-prefixed `DB_SOCKET`,
  the loopback host names) no text it ACCEPTS resolves to another value, and a text that does — `DB_HOST=null`
  is a host of null — falls on the refusing side. Which host a `DB_URL` names is decided inside the `php` that
  parses the URL, and only its verdict is read back: a host carrying a `%00` was read as `localhost` when the
  host itself crossed that boundary, because a command substitution deletes NUL bytes — a store on another
  host, certified as this one and deployed with no TLS. A CA is UNSET whenever Laravel
  resolves it to a value PHP treats as false — `server/config/database.php` wraps the option in
  `array_filter`, which drops every falsy value, so such a line is a connection with no TLS at all, and a
  store on another host carrying one is refused; `bin/deploy.sh`'s `env_app_falsy` states which values those
  are. An `APP_KEY` the app receives as a falsy value is no key, and is refused the way an empty one is. A
  `CACHE_STORE` the app receives as PHP null is the DISCARD store, which keeps nothing — the login path's
  user-enumeration oracle — and is refused beside `array`. No
  value from `DB_URL` is printed. A same-host store with the CA set passes with a warning: pdo_mysql then
  requires TLS over the socket too, and a store that offers none refuses every connection
  (measured against the sandbox host's MariaDB). `docs/design/FLEET-STATE.md § 6.1` and
  `docs/PLAN.md` D-15 record the operator's 2026-09-14 ruling, and `bin/deploy.selftest.sh` covers
  each case. **Installer action:** write every key A5 reads (`APP_ENV`, `APP_DEBUG`, `APP_KEY`,
  `DB_CONNECTION`, `CACHE_STORE`, `MYSQL_ATTR_SSL_CA`, `DB_URL`, `DB_SOCKET`, `DB_HOST`) once, as plain
  `KEY=value`, and write a key that has no value by leaving it empty (`MYSQL_ATTR_SSL_CA=`) rather than as
  `null` or any other value Laravel resolves to a falsy one. Keep every value on the line that opens it: a
  `"` that its own line does not close now refuses the deploy, and so does any line phpdotenv cannot parse,
  and so does a NUL byte anywhere in the file, whichever key it belongs to. Line endings need no attention:
  LF, CRLF and a lone CR are each read the way Laravel reads them, so a `.env` edited on Windows deploys
  without being converted first. `APP_DEBUG` must SAY off —
  `false` in any capitalisation, or `(false)`; `0`, `null` and an empty value are refused even though PHP
  casts them to false. The warning about keys `.env.example` names and this host's `.env` does not is asked
  through the same reader as every other check now, so a key written `export KEY=…` or `"KEY"=…` — which IS
  that key to Laravel — is no longer reported as one the host does not set; it is reported as one whose
  value is not established, which is a different instruction. An install whose store is on the same host may leave
  `MYSQL_ATTR_SSL_CA` unset, and leaves it unset when that store serves no TLS. An install whose store is on another host keeps it set.
- **card#9559** — **The app's `.htaccess` now redirects plain HTTP to HTTPS, with the ACME challenge
  exempt.** `server/public/.htaccess` sends a plain-HTTP request to `https://` on the same host name when
  Apache terminates TLS itself. A request carrying `X-Forwarded-Proto` passes through unredirected, so the
  rule is inert behind a proxy, which owns the scheme there, and it cannot loop. Requests under
  `/.well-known/acme-challenge/` stay on plain HTTP, so Let's Encrypt's HTTP-01 challenge can issue a first
  certificate on a fresh host. `server/.gitignore` ignores `server/public/.well-known/`, where a document root
  symlinked to `server/public` receives those challenge files, so a renewal leaves the tree clean for
  `bin/deploy.sh`. `docs/PLAN.md § 5` states the document-root layout. Verified on a throwaway non-root Apache
  2.4.66 executing the file: plain HTTP 301 to HTTPS on the requested host, the challenge path 200 over HTTP,
  `X-Forwarded-Proto` 200, HTTPS 200, and the stock file 200 as the control. **Installer action:** point the
  vhost's document root at `server/public` as one symlink (`~/public_html` on Virtualmin). A host whose
  document root held its own copy of `.htaccess` switches to the symlink, and the tracked file's redirect
  supersedes any host-specific redirect that copy carried. A host where Apache serves the app directly
  obtains its certificate first, because every other plain-HTTP request now redirects to HTTPS.
- **card#9543** — **A production install now marks the session cookie Secure without a `.env`
  key.** `server/config/session.php` defaults `session.secure` to `true` when `APP_ENV` is
  `production`, including an unset `APP_ENV`, and `SESSION_SECURE_COOKIE` still overrides it. The
  default was Laravel's null, which sets the flag only on a request PHP sees as HTTPS, so behind a
  TLS-terminating proxy the app does not trust, or on a request reaching PHP over plain HTTP, the
  session cookie went out without it. Outside production the default is unchanged. `server/.env.example`
  documents the key, and `docs/PLAN.md § 5` states the obligation. New test:
  `SessionCookieSecureDefaultTest`. **Installer action:** none on an HTTPS production host. A production
  host whose `.env` sets `SESSION_SECURE_COOKIE=true` can keep or remove it. A production host served
  over plain HTTP sets `SESSION_SECURE_COOKIE=false` before deploying, because its browsers stop
  returning a Secure cookie over HTTP and sign-in stops working.
- **card#9500** — **A `ca_file` the seat cannot read, or one that is not an absolute path, now stops
  the seat's requests and fails `selftest` by name.** The reporter caught the read failure and sent the
  request with the default trust store. The seat then trusted every publicly trusted CA instead of the
  one file its config pins, and nothing logged it. An empty-string `ca_file` counted as unset and widened
  the trust the same way, and a relative one was read against the process's working directory, which a
  flusher inherits from whatever starts it: the agent's project directory from a hook, the account's
  home directory from cron. Against a private-CA ingest every request failed verification, and `selftest`
  reported `tls_verify` `fail`, which points at the certificate rather than the file. One read,
  `readCaFile`, now serves both the config check and each request. The config check adds
  `ca_file unreadable at <path>: <errno>` to `config_readable`'s errors, so `selftest` exits 1 naming
  the path and runs no probe, and a flusher started on that config spools and sends nothing, as for an
  `http://` `ingest_url`, while the file stays unreadable. It re-reads the file on each pass, and from
  the first pass that reads it the same process probes, sends, logs `ca_file readable at <path>` and
  heartbeats `config_readable` `pass`, with no restart. A `ca_file` that is a string but not an absolute path, the empty string
  included, is the same config error, `ca_file must be an absolute path or null (§ 3.1), not "<value>"`:
  only `null` or an absent key leaves it unset. A file that becomes unreadable while the flusher runs ends that request before
  any socket opens: the batch is `refused` (counted in `config_invalid`, never `batches_retried` and
  never quarantined), the log names the path and the errno, and the spooled events are delivered once
  the file is readable again. D1 § 3.5 (a row for the refused `ca_file`), § 6.14's `config_readable`
  row, § 9.3's `config_invalid` row, `INSTALL-LINUX.md` Step 6 and the reporter's transport comments
  now say so. The acceptance suite's block 1 drives `selftest` and a flusher start on a missing
  `ca_file`, on an empty-string one and on a relative one, and a flusher pass whose `ca_file` is removed
  between the health probe and the batch, and a running flusher started on a missing `ca_file` that is
  then created, with
  the stub's certificate added to Node's default store (`NODE_EXTRA_CA_CERTS`) so that a fallback
  shows up as a delivery. It carries REDs of the request that falls back, of the config check that
  never reads the file, of a check that reads `""` as unset, of one that reads a relative path, and of
  a flusher that checks the file only at start. **Installer action:** none beyond the ordinary artifact update
  (`INSTALL-LINUX.md` Step 1). A seat whose `ca_file` is unreadable, empty or relative, and that reached
  its ingest through the system store or a file found from its working directory, stops sending after
  the update. Its `selftest` names the file or the rule. Once the file is readable the running flusher
  resumes by itself. After setting an absolute path or `null`, stop the flusher with `stop-flusher.js`
  (`INSTALL-LINUX.md` Step 6) so the next start reads the corrected config.
- **card#9464** — **A draining seat's fold window now releases the seat within a time budget, and a
  fold's deltas are computed from what the seat held when its window took the lock, under any session
  isolation settings.** A fold window held its seat's `seat_state` lock until it had applied up to `Fold::BATCH`
  events, so during a drain every post for that seat could wait out its lock bound and answer `503`
  over and over. `App\Fold\Fold` now stops a window at `Fold::BATCH` or at `Fold::WINDOW_BUDGET_MS`,
  whichever it reaches first, and advances the cursor to the last event it applied. The budget is
  derived from the ingest's session bounds, `IngestPipeline::IDLE_TRANSACTION_TIMEOUT_S` less
  `IngestPipeline::PROCESSING_TARGET_MS` (now public), so a post queued behind a window is granted the
  lock inside its own wait. The window's first statement is now the seat's `seat_state` row lock,
  `FOR UPDATE SKIP LOCKED`, before it reads events or samples the fingerprint a delta is computed from,
  so a same-seat writer cannot commit between them; a seat another transaction holds is yielded for the
  pass with nothing written. The poison-event rule's one-event attempts and its quarantine take the
  same lock the same way. The `mysql` connection now sets the session time zone to `+00:00` on every
  connection through its `timezone` key, so the `TIMESTAMP` columns of the auth tables and
  `failed_jobs` read and write UTC whatever the store host's zone. D2 § 2.2, § 6.1, § 6.3, § 6.5 and
  § 12 state it. New tests:
  `FoldLockFirstTest` and `FoldWindowDurationTest` on real MariaDB connections, `FoldWindowTimeBoundTest`,
  `FoldWindowBudgetSourcesTest`, and a session time-zone assertion in `DatabasePinTest`.
  **Installer action:** check the store's time zone. There is no migration, and the deploy's daemon
  restart puts the fold on the new code. Before deploying, run
  `SELECT @@global.time_zone, @@system_time_zone;` on the store. The store is safe when the global zone
  is `+00:00` or `UTC`, or when it is `SYSTEM` and the system zone is UTC; the system zone alone misses a
  global zone set explicitly. On any other store, the `TIMESTAMP` values the auth tables and
  `failed_jobs` already hold were written through that zone, and they read back shifted by its offset
  once the connection is pinned: on a store four hours west of UTC, a `12:30:00` written before the
  deploy reads back as `16:30:00` after it. `TwoFactorReset::request()` writes a code's `expires_at` and
  `TwoFactorReset::consume()` compares it with `now()`, so on a store west of UTC an unconsumed
  two-factor reset code issued within `TwoFactorReset::TTL_MINUTES` plus that offset before the deploy
  is valid for the offset longer than its lifetime, and a code among them that had already expired
  becomes valid again. On such a store, delete the unconsumed codes during the deploy, once the new
  code is serving: `DELETE FROM two_factor_reset_tokens WHERE consumed_at IS NULL;`. A user who still
  needs a reset requests a new code.
- **card#9466** — **Rebuild, retirement and the sweep take the seat's `seat_state` lock first, and every
  purge table has a retention index.** `mezzanine:rebuild` locks the seat before it deletes its
  projections and retries the whole replay on a lock timeout, deadlock or changed row
  (`RebuildCommand::REPLAY_LOCK_ATTEMPTS`). Retirement locks the seat before it samples the state it
  announces, waits at most `SeatRetirement::LOCK_WAIT_TIMEOUT_S` for any one blocked statement on its own
  session and restores that session's wait afterwards, and retries up to `SeatRetirement::LOCK_ATTEMPTS`
  attempts; a seat still busy then is answered as busy with nothing changed — a message on the console's
  agents page, and a sentence with exit `1` from `mezzanine:retire`. The sweep locks each seat
  `FOR UPDATE SKIP LOCKED`, skips a seat another writer holds, treats a concurrency error on a
  downstream row as contention, and counts both per seat as `sweep_seat_contended`, kept out of the
  pass's failed seats and `sweep_seat_error`. `Outbox::transaction()` takes an attempt count and clears
  its queued messages at the start of each attempt. A new migration adds `ix_purge` on the retention
  column of `events`, `batches`, `sessions` and `seat_state_transitions`, each with
  `ALGORITHM=INPLACE, LOCK=NONE`. The purge deletes every table in the order of its retention index's
  key (`received_at, id` on `events`; `closed_at, orphan_due_at, id` on `calls`) instead of by `id`, so a
  backlog drains in batches read off that index with no sort of the expired range. D2 § 2.2, § 6.4,
  § 6.5, § 6.6, § 6.7, § 6.8, § 7.2 and § 12 state all of it. **Installer action:**
  none beyond the deploy, which runs the migration; each `ALTER` waits for open transactions on its
  table before it starts and before it finishes.
- **card#7341** — **The animation log records every claim-bearing episode, under its own gate
  (`docs/design/FLOOR.md` Appendix B step 2).** `server/public/js/wire/animation-log.js` is the one
  entry point a renderer starts a § 6.2 animation through: `edge` writes a `fired` row, `enterHeld`
  opens a held episode and returns its fresh `episode_id`, `leaveHeld` writes that episode's `left`
  row with `motion: false`, and `rows` reads every row in call order as § 11's tuple. The module
  records what it is given, reads no clock and no environment, and throws `AnimationLogRefusal` on
  a `leaveHeld` for an episode that is not open and on any call without an `at`, including a call
  with no argument object at all or `null` in its place, and it exports that class and
  `createAnimationLog` only. § 11 now states that call surface and the contract bound by bound, and `Tests\Feature\Floor\TheAnimationLogRecordsEveryClaimBearingEpisodeTest`
  and `Tests\Feature\Floor\AnimationLogClassPopulationMatchesTheDocumentTest` drive the shipped file
  under `node` against each bound, with a planted control for each. No renderer calls the module
  yet; that is steps 5 and 6. FLOOR.md also re-gates
  AT-D3-1 whole at step 6 (its instrument half reads the harness, the client protocol and the
  animation set), names the harness as step 3's artifact, states that the log is the one entry
  point for claim-bearing motion and that motion bypassing it is NOT MECHANIZED, and adds § 14
  items 21–23 on the unstated parts of `fx-clear-trace`, `fx-snapshot-4` and `fx-degraded`, each
  blocking the lowest Appendix B gate of a test that replays the fixture or a fixture built on it.
  `tools/design/verify-floor.py`'s G5 now requires **the harness** in the `Reads:` clause of every
  Build bullet of a test that names a § 11 fixture or the harness, whatever else the clause names; a
  test that names no fixture and not the harness may instead name an instrument (an Appendix B gate)
  when the Appendix B row that builds that instrument also gates the test, any other bullet reds, and
  a backticked name beginning `fx` that the fixture table does not declare reds as a control. G5
  catches a test the harness drives that forgets the harness; it cannot prove a `Reads:` clause
  true, so a deliberately false declaration stays a review question. Every such bullet in FLOOR.md
  now lists the harness, AT-D3-12's lineage half lists the provenance gates it runs, and no gate
  moved. `tools/design/verify-design-docs.selftest.py` plants each red: the harness dropped, the
  harness swapped for a gate in a test that replays a fixture and in one that names none, an
  undeclared fixture name carrying a suffix, and one whose first hyphen is an underscore.
  **Step 3 lands the client protocol and the fixture harness** (`docs/design/FLOOR.md` Appendix B
  step 3). `server/public/js/wire/fleet-client.js` opens the feed, buffers the connect window,
  applies the snapshot, drains, applies every delta through ONE per-seat primitive, resyncs a gap
  from the last version it applied, inserts a seat it does not hold by fetching it, discovers an
  install a `fleet.seats_total` disagreement points at, holds § 2.4's clock offset, and writes
  § 5.5's record newest-first at 200 lines. `server/public/js/wire/discrepancy-budget.js` is § 4.1's
  budget, hoisted out of `lobby-model.js` at its second caller and gaining the `refund` a failed
  discovery needs; `lobby-model.js` re-exports it, so every lobby import is unchanged.
  `server/tests/Feature/Support/scripted-fetch.mjs` is the lobby probe's own fake transport, hoisted
  the same way and given a scheduling hook, and `server/tests/Feature/Floor/fleet-client-probe.mjs`
  drives the shipped module under `node` on a scenario clock with a fake `EventSource` that replays
  nothing, exactly as D2 does not. Four checked-in fixture files carry every byte the tests replay —
  `fx-snapshot-4`, `fx-gap`, `fx-membership` and `fx-confirm` — and six test classes assert the three
  step-3 acceptance halves, the determinism bound, the record's cap, the confirmation signal and the
  fixtures' own agreement with `docs/design/FLEET-STATE.md § 8.2.1`, each with planted controls that
  were run and seen to red. The protocol also reports what it CANNOT confirm: a held seat whose own
  read has failed twice consecutively is `missing` in `readStatus()`, and `discrepancyState()` says
  whether a check for the lobby's disagreement can still run — data only, drawn by nobody until
  Appendix B step 10. FLOOR.md now says discovery IS admission, carries § 2.3's new row 5 for the
  unconfirmed desk, scopes `idle`'s and `disabled`'s Never cells and Appendix A's U5 to what the
  client can confirm, states the lobby's notice in the office's own nouns, and FLEET-STATE § 8.2.3
  names the seat response's REST envelope, which `FeedSurfaceTest` now asserts in order.
  **Installer action:** none; no migration.
- **card#9322** — **A layout whose `floors` is `{}` is refused by name, and a floor's hallway is
  served with every `{}` it was authored with.** The layout reader decoded the document
  associatively, where `{}` and `[]` are one PHP value: `"floors": {}` was accepted as § 4.6's empty
  building with no error, an authored `{}` inside a hallway came back from `GET /api/building` as
  `[]`, and `rooms` written as a list was placed as rooms named by their positions.
  `App\Building\BuildingLayout` and the store's per-request read in `App\Building\Layouts` now decode
  in object mode, the decode card#9295 gave the ingest, so each member is checked against its own
  shape. `"floors": []` is the empty building; `"floors": {}` is refused with a sentence that gives
  `"floors": []` as the empty building's spelling; `rooms` as a list is refused naming the mapping it
  is. `App\Floor\FloorMap` reads room maps and hallways in the same mode, so for both a `layers` (a
  `group` layer's included), `tilesets`, tile layer `data` or `desks` `objects` that is not a JSON
  array, and a layer, tileset entry or desk object that is not a JSON object, is refused naming the
  shape it has (an absent or `null` `tilesets`, or a `group` layer's absent or `null` `layers`, is
  read as empty, as before); a document that is `[]` is refused as a JSON array. `GET /api/building` writes
  the same bytes as before for a layout with no empty object in it, pinned against the pre-change
  output for an all-digit `install_id` layout and a planned, labelled floor with a hallway.
  `AuthoredDocument::isJsonObject` is removed, and the migration seeding `config/building.php`
  validates the text it seeds through `BuildingLayout::fromJson()`, which also measures it against
  the console's write bound. `docs/design/FLOOR.md` § 4.6 (the floor and rooms rows, the empty
  building, the shape contract) and § 10.3 (the `tilesets[]`, `layers[]` and `desks` rows) state it.
  New tests in `BuildingLayoutTest`, `TheBuildingSurfaceTest`, `FloorMapTest` and
  `TheDeployRefusesAStoredDocumentTheReadersRefuseTest`. **Installer action:** none for a store these
  readers accept. The release carries a migration that adds no schema and changes no data: it reads
  the current building layout and every current room map through this release's readers. A current
  document in a shape the previous release accepted and stored and this one refuses fails
  `php artisan migrate`. Those shapes are: a layout whose `floors` is `{}` or keyed `"0"`, `"1"`, …,
  or with a floor whose `rooms` is a non-empty list; a room map or hallway whose `tilesets` is `{}` or
  keyed, whose `layers` is keyed (or, in a hallway, `{}`), with a `group` layer whose `layers` is
  `{}`, keyed or a scalar, with a tile layer whose `data` is `{}` or keyed, or with a JSON array (`[]`
  or any other) in place of a layer or a tileset entry; and a room map whose `desks` layer's `objects`
  is keyed or holds a JSON array.
  The migration fails with a message naming each such document by kind, subject and
  revision beside the reader's own sentence, so `bin/deploy.sh` stops inside its maintenance window
  with exit 2 and the app down, before these readers serve anything. To fix it: review and remove the
  failure marker, deploy the commit the marker names as `from_commit` to bring the console back,
  re-author each named document or restore a revision of it this release accepts, then deploy this
  release again; the migration runs again because a failed migration is not recorded. A superseded
  revision is left out of the check: restoring one this release refuses is refused on the console's
  revisions page.
- **card#9499** — **The PHP suite runs only against the `app/` of the tree under test.** Composer
  computes the `App\` base from the autoloader's own location, resolved through symlinks, so a
  `server/vendor` linked in from another checkout ran that checkout's `app/` under this tree's tests.
  `server/phpunit.xml` now bootstraps `server/tests/bootstrap.php`, which reads Composer's PSR-4 map
  and exits `1` before any test runs when an `App\` base resolves outside the directory holding
  `phpunit.xml`, naming both paths and the fix: `server/vendor` must be a real directory inside the
  tree, installed with `composer install`. `composer test`, `php artisan test` and `vendor/bin/phpunit`
  all load that bootstrap. The README's local-run section states the requirement. **Installer
  action:** none; no migration.
- **card#9473** — **A seat whose egress requires `proxy_url` now delivers its batches and reads
  healthy.** The reporter made its requests through two HTTPS clients. The health probe had no proxy
  leg, so it went direct and measured nothing: `selftest` read `not_measured` (rc 2), the heartbeat
  carried `tls_verify` and `schema_version_accepted` as `fail`, and since card#9373 the flusher
  re-probed every heartbeat interval. The sender asked the proxy for a tunnel and then sent the batch
  over a direct connection, because Node answers `agent: false` with a fresh agent that ignores
  `createConnection`, so its batches reached the ingest only where direct egress also worked. Both now
  go through one primitive, `ingestRequest`, which owns the route (the `proxy_url` tunnel, or direct on
  the keep-alive agent), the TLS options (`ca_file`, host verification, SNI for host names only) and
  both § 3.5 deadlines. The connect deadline, `K.CONNECT_MS` (5 s), runs from the start of a request
  to a verified TLS session, the proxy's `CONNECT` answer included, so a proxy that accepts TCP and
  never answers holds a flusher pass for 5 s; the probe had no connect deadline and waited out the
  15 s request deadline. The sender's connect deadline was a socket idle timer that also ended a
  request whose server took more than 5 s to answer; such an answer now has the 15 s request deadline.
  D1 § 3.5 (connection reuse, connect deadline, proxies) and § 6.14 (the probe's route, and the
  `tls_verify` results through a proxy), `fleet-reporter/README.md` and `INSTALL-LINUX.md` Step 6 now
  say so. The acceptance suite's block 1 puts a `CONNECT` proxy stub in front of the ingest stub on an
  address the seat cannot reach directly, and drives the one-shot and the heartbeat through it, the
  sender through it, a seat with no `proxy_url` direct, and a proxy that never answers, with REDs of a
  probe and a sender that ignore `proxy_url` and of a primitive with no connect deadline.
  The AT-15 source lint also refuses `checkServerIdentity`, so a copy that overrides host-name
  verification reads `tls_verify` `fail` on both routes. D1 § 3.5 and the reporter's transport
  comments now say that `ca_file` is passed as the TLS `ca` option, which replaces the default trust
  store.
  **Installer action:** none beyond the ordinary artifact update (`INSTALL-LINUX.md` Step 1); a running
  flusher keeps the code it started with until it restarts. A seat with `proxy_url` set re-runs Step 6
  after the update to read its network checks.
- **card#9465** — **A failed ingest write now answers `503 server_error` and is counted, and a hung
  ingest transaction no longer holds its seat.** A store failure while `POST /api/ingest/events` ran
  surfaced as Laravel's default error body and incremented nothing, so an operator could not see a
  write-failure rate. It now answers D1 § 12.2's shape — `503`, `error: server_error`, `detail`
  `store_contended` or `store_failed`, never the exception's text — and still reports the exception. A
  defect in the server takes the same shape as a `500` with `detail: internal`. Each one counts the new
  `batches_failed.<detail>` on the seat, or under the same key in `global_counters` when the fault comes
  before the token resolves, where `GET /api/fleet/health` reads it. It is not a refusal:
  `batches_refused.<error>` stays 4xx refusals only and `unattributed_refusals` refusals before identity,
  because the reporter retries every `5xx` unchanged and a retry commits once. The ingest request now
  sets `innodb_lock_wait_timeout` = 11 s and `idle_transaction_timeout` = 10 s on its own database
  session, derived in D2 § 2.2 from the reporter's 15 s request deadline, so when the application stops
  talking to the store mid-write the server ends that transaction and frees its seat's lock within 10 s,
  instead of that seat's fold and ingest staying frozen until the connection drops. D1 § 12.1's
  attribution table and § 12.7's `unattributed_refusals` row now say that counter also counts step 4's
  refusals, as the ingest always has. D1 § 12.1, § 12.2, § 12.7 and D2 § 2.2, § 6.5, § 7.1, § 8.2.4,
  § 12 state it. New tests: `IngestServerErrorTest`, and `IngestWriteBoundTest` on real MariaDB
  connections. **Installer action:** none; no migration. The store must be MariaDB with
  `idle_transaction_timeout` (present on 11.8.6, the version floor).
- **card#9374** — **A new seat is no longer badged `epoch_reset`.** The flusher counted a missing
  `state.json` as a state reset, so every first start counted `state_reset` and, because the badge is
  derived from a running total, every new seat carried `epoch_reset` for good. `loadState` now counts
  a reset only for a `state.json` that exists and cannot be used: a read error other than a missing
  file, or empty, truncated, unparseable, or the wrong shape. A missing file is a first start, or state
  lost with the file. Both mint a new `seq_epoch` and re-send the spool from its oldest bucket, as
  before, and count nothing; the flusher log names the missing file. A hook spools its event before it
  respawns the flusher, so a first start usually finds data waiting, and the spool cannot tell a first
  start from lost state. Lost state on a seat that has already reported is still badged, by the
  server's `seq_epoch_change`. D1 § 9.3, § 10.2, § 11.4 (retitled *a missing or unreadable
  `state.json`*), § 12.7 and AT-17 now say so, and D2 § 7.2 says which cause each side's badge
  observes. The selftest drives a crontab first start, a hook first start and a deleted `state.json`
  through a start and a restart, and keeps the empty, truncated, unparseable, wrong-shaped and
  unreadable cases as resets, each with a RED planted on a copy. § 19's fresh-seat baseline, which
  asserted `["epoch_reset"]`, now asserts an empty `degraded`. This corrects the limitation 0.4.0's
  card#9368 entry names. **Installer action:** none for a new seat. A seat installed from an earlier
  build keeps its badge, because the total lives in its `state.json`; `fleet-reporter/INSTALL-LINUX.md`
  says why neither replacing the artifact nor deleting `state.json` clears it, and card#9491 owns
  whether it should.
- **card#9208** — **The lobby fetches the building layout from `GET /api/building`, and the page
  no longer carries it** (`docs/design/FLOOR.md` Appendix B row 13, build slice 3). The lobby
  fetches the snapshot, then the layout, then renders; the `#lobby-layout` JSON island and the
  dashboard route's layout read are gone, so a saved layout reaches a viewer on Refresh or reload.
  A failed layout request is § 9 F17: the lobby says *the building layout could not be loaded —
  HTTP N* and composes no building, listing each install as a room with no floor claimed on a cold
  start and keeping the floors it held, labelled *last known layout*, after one. A `200` whose body
  is not a layout and a request that never reaches the server are failures too, never the empty
  layout. New `server/public/js/wire/building.js` holds the layout and each room's map by version:
  a room is fetched only when the map held is not at the version the server reported, a `room.map`
  for a rendered room re-fetches that room alone and returns one event-log line, a
  `building.layout` naming a new version re-fetches the layout, and a failed map request keeps what
  was held with the failure beside it and never the shipped default (§ 9 F16). No page calls the
  room-map half yet: rooms are entered by the floor route (Appendix B step 7) and messages arrive on
  the client stream (step 3), and both are unbuilt, as are the room re-render, the written event
  record and F17's backoff retry. A stored layout the reader refuses is now `GET /api/building`'s
  `500` and the lobby's F17 statement, where it was an exception on `/dashboard`.

- **card#9320** — **G8 sees a counter written the way D2's pseudocode fences write one.**
  `verify-fleet-state.py`'s G8 holds every counter a rule writes against § 7.1 / § 7.2, and in the
  other direction requires every § 7.2 counter to have a writer. Its writing idiom required the
  counter name in backticks and had no imperative verb, so § 8.3's handler fence —
  `count feed_resync_required; return`, the line an implementer builds from — was invisible to both
  directions: a counter renamed or invented there passed with no row, and a § 7.2 counter written
  only there would red as "nothing increments it". The idiom now also reads the fences' spelling: the
  imperative `count`, and a **bare** name that is snake_case — it carries a `_`, which no English word
  does, so "count" used as ordinary English (all over D2, fences included) stays out of the
  population. How many writes the widening adds on D2 is printed on G8's summary line
  (`counter writes spelled BARE…`) rather than written here. Two further changes close what the
  plant exposed: the forward check had forgiven any suffix on a declared name (`tok.startswith(c)`),
  which let the ordinary shape of a rename pass as declared — it now matches the declared name, or a
  declared family's base name; and a new **G8 CONTROL** reds if no counter write in D2 is spelled
  bare, so the widening cannot quietly decay into the old idiom. Declared rather than closed, on the
  tool and in § 12's row: a fence's `x += 1` (§ 6.5 writes `state_version += 1`, a field, in the
  same form), a bare name with no `_`, and a family member written by its dotted path in either
  spelling (`count x_y.member`).
  `verify-design-docs.selftest.py` gains a fenced `rename` plant for G8, a `backtick` plant that
  sees the new G8 CONTROL fire, and a second verdict, **holds** — correct forms that must not red,
  and on which the verifier must neither crash nor exit above the control — with two kinds
  (`imperative`, `noun`) holding the idiom from both sides, each declaring either that its mutation
  only adds text or the plant that proves its premise; `tools/design/README.md` and the verifier
  workflow describe the holds.

- **card#9208** — **The building surface is served: `GET /api/building` and
  `GET /api/building/rooms/{install_id}/map`** (D2 § 8.7, `docs/design/FLOOR.md` Appendix B row 12,
  build slice 2). `/api/building` answers the layout the lobby page already inlines
  (`layout_version` `0` with empty `floors` when none was ever saved) and `rooms[]`, each authored
  room's `map_version` and `updated_at`. The map endpoint answers a room's authored Tiled document
  with `source: "authored"`, or the shipped `resources/floor/default.tmj` with `source: "default"`
  and null version for a room with no current map (never authored, never reported, or removed); an
  `install_id` outside D1 § 3.1's slug is `404`. Both are browser-only behind the read plane's gate:
  a `mzr_` token is refused `401 unauthenticated` exactly as on the timeline, and a store that
  cannot be read is `503 fleet_unavailable` with no document. The map is decoded to objects before
  it is re-serialised, so an authored `{}` comes back as `{}`. The `room.map` and `building.layout`
  messages were already committed by the store with the revision they announce (card#9300); a new
  test fails each of the five writes at its outbox INSERT and asserts neither the revision nor the
  message survives. `FleetController`'s fail-closed body build moves to
  `App\Http\Controllers\Concerns\ServesAClosedRead`, shared by both controllers, and D1 § 3.1's slug
  patterns move from `mezzanine:ingest-token:issue` to `App\Support\Slug`. The lobby page still
  inlines the layout; its fetch is Appendix B row 13.
- **card#9467** — **A viewer's floor no longer misses a delta when two feed writers stamp and insert in
  opposite orders.** The live feed read `feed_outbox` rows older than a 2 s lag and moved each stream's
  cursor to the highest id it read; the outbox has several independent writers (the fold, the sweeper,
  retirement, the heartbeat, the console's room-map and layout saves, the deploy's reload), and a
  writer that stamped its row later but inserted it first left a lower id with a later stamp, which the
  read skipped while it was young and never delivered — a seat change, retirement or layout change a
  viewer did not see until the next snapshot. The read is now a visible prefix: it stops below the
  lowest id still inside the lag and delivers it, in id order, once it ages (`App\Feed\VisiblePrefix`;
  the lag constant moves from `Fold` to `Outbox::VISIBILITY_LAG_S`). A stream's connect cursor is the
  highest committed id below that bound. Two new fleet-health counters on `GET /api/fleet/health`:
  `feed_prefix_future` counts each read held by a row stamped in the reader's future (a clock ahead or
  stepped back), and `feed_outbox_boundary_stalled` counts a sweep pass that finds a row outside the
  prefix one lag short of the outbox's purge age. D2 § 2.1, § 2.2, § 6.1 (a new application-clock
  requirement, not verified: the sandbox host ran 162 s fast on 2026-09-13/14), § 6.4, § 6.7, § 7.2,
  § 8.2, § 8.2.4, § 8.3, AT-D2-25, § 12 and Appendix B state the property and its conditions. New
  tests: AT-D2-25's reversed-stamp leg on real MariaDB connections and `VisiblePrefixTest`.
  **Installer action:** none; no migration. Keep every app host's clock synchronized: a host ahead of
  another holds every open stream's delivery for the difference, and `feed_prefix_future` rising says
  so.
- **card#9471** — **A signed-in account with a confirmed second factor can move to a new
  authenticator again, and keeps its current one until the new one is confirmed.**
  `/two-factor/recovery-codes` (behind `auth` + `mfa` + `password.confirm`) carries a **Move to a
  new authenticator** button, under a paragraph stating that the current authenticator keeps working
  until the new one is confirmed, and that confirming stops the old entry and every current recovery
  code working. The button starts a move at `/two-factor/move`, under the same three gates
  (`App\Http\Controllers\Auth\TwoFactorMoveController`). A new secret, generated the way Fortify's
  enable action generates one, is held encrypted in the session with the id of the account that
  started the move, and the page shows its QR code under the issuer the enrolment page uses, and its
  setup key. Another account signed in on that session sees no move in progress and cannot confirm
  it. A code from the new authenticator, checked with Fortify's provider and throttled per account
  by the new `two-factor-move` limiter, then writes the new secret, replaces the recovery codes
  through Fortify's `GenerateNewRecoveryCodes` and stamps `two_factor_confirmed_at`, in one
  transaction, and the browser lands on the codes page showing the new set. Only that confirmation
  writes to the users table: a wrong code keeps the pending move, and a move left unfinished leaves
  the account as it was. The move never passes through Fortify's `DELETE
  /user/two-factor-authentication`, which clears the second factor at once and would leave an
  abandoned move signing in on the password alone; that route stays registered, and no page shown to
  a confirmed account links it. Card#9445 removed "Start over" from the enrolment page for confirmed
  accounts, and that button had been the only in-app way to enrol a replacement device, so a user
  who replaced their phone or signed in with a recovery code spent one code per sign-in until none
  were left. The enrolment page and the move page draw their QR codes through one renderer,
  `App\Models\User::twoFactorQrCodeSvgFor()`. The dashboard link, the enrolment page's confirmed
  state and the two-factor challenge page point at the move.
  `Tests\Feature\TwoFactorMoveAuthenticatorTest` drives the pages' own forms through a whole move
  (the old authenticator still signs in while it is pending; afterwards the old code and an old
  recovery code are refused and the new ones accepted), an abandoned move, a wrong code, a
  confirmation with no move in progress, the password-confirmation and guest refusals, the routes'
  gates and throttle, a start that leaves the users row unchanged, and a second account in the same
  session that is neither shown nor able to confirm the first account's move. `README.md § Losing
  your authenticator` describes the move.
- **card#9375** — **`fleet-reporter` now sends the seat's declared protocol agent name, and fails an
  act when the coordination roster disagrees.** card#9296 built the server half; the reporter read
  neither the key nor the roster, so no desk join could resolve. At flusher start and on `selftest`,
  and never per flush or in a hook, the reporter reads `protocol_agent_name` from its config and
  resolves the roster by D1 § 3.1's order. Every heartbeat now carries `protocol_agent_name` and
  `protocol_agent_name_check` (`checked`, `unchecked`, `disagreed` or `undeclared`), and counts
  `protocol_agent_name_unchecked` / `protocol_agent_name_disagreed`. `selftest` gains
  `protocol_agent_name_in_roster`, which fails on `disagreed` (exit 1) and names the roster file and
  its names in `detail`. A missing, unreadable or malformed roster file is `unchecked`. A malformed name
  declares nothing and the seat keeps sending: the heartbeat carries `undeclared` and `null`, never a
  value the ingest would refuse, `config_readable` passes and `config_invalid` is not counted, and
  `protocol_agent_name_in_roster` fails with the value in `detail` (a non-string by its type). D1 § 3.1's
  state table and § 6.14's check row now state that case. The acceptance suite's § 19 builds AT-27 cases A–G with each RED; case F runs
  `INSTALL-LINUX.md`'s crontab line, read out of the runbook, under `sh -c` from an environment carrying
  no `COORD_CONFIG`. `server/tests/roundtrip/ingest-roundtrip.py` sends all four states through the real
  ingest and fold, and a check value outside the set is refused. D1 § 3.1's CHECKED leg and § 18.13
  row 6 now say the reporter half is built; the runbook's Step 6 and closing section, and the
  fleet-reporter README, follow. D2 and D3, which credited card#9296 with the whole of the declaration,
  now credit card#9375 with the reporter half and say a seat sends the name only on a build that
  includes it. **Installer action:** on each seat, replace
  `fleet-reporter.js` (runbook Step 1) and restart the flusher (Step 5), or its heartbeat keeps carrying
  no name. A seat that declares a name needs `protocol_agent_name` in its config (Step 2 already writes
  it). Nothing changes on the server.
- **card#9373** — **`fleet-reporter selftest` now exits 0 on a correctly configured seat.** The one-shot
  command never asked the ingest, so `schema_version_accepted` read `fail` on every seat and the
  install-time verification exited 1; its `tls_verify` read `pass` from a check of the source alone.
  The command now runs the flusher's own health probe (`refreshHealth`: same TLS path, `ca_file` and
  deadline) once, on a config that passes validation. D1 § 6.14 gains the rule for a check the
  subcommand could not measure: each network check is `pass`, `fail` or `not_measured` (ingest
  unreachable, or an answer with no accepted set, such as a `401`), and the exit code is `0` when every
  check passes, `1` when any fails, and `2` when none fails and one is unmeasured. A TLS handshake that
  fails against a reached host is a `tls_verify` fail. `tls_verify` is the source posture and
  reachability together in one place, so a probe answer never turns a failing posture into a pass;
  that also holds for the heartbeat, where a successful send used to set it. The heartbeat's
  `selftest` object keeps its two values, and the flusher keeps each network check at its last measured
  value: a probe that measures nothing for a check (a deadline, a dropped connection, an answer with no
  set) leaves the previous value in place, so it never sends a false `fail`. A check no probe has
  measured yet rides the heartbeat as `fail`, as before. While a check is unmeasured the flusher probes
  again no sooner than one heartbeat interval after that probe began, instead of at its ordinary
  `K.HEALTH_MS` cadence.
  `fleet-reporter/INSTALL-LINUX.md` Step 6 and the fleet-reporter README follow. The acceptance suite's
  § 1 drives an accepting stub, a stub whose set lacks the version, an unreachable ingest, a `401`, and
  a seat with no `ca_file`, with a RED plant of the one-shot that never probes. It also drives a
  long-lived flusher whose probes time out after a measured pass and then meet a refusal, on a copy
  with its intervals scaled in the reporter's own order, and asserts the retry's lower and upper bounds,
  with REDs of a flusher that lets the timeout overwrite on a fixed cadence, of one that never replaces
  a measured value, and of one that re-probes on every pass. **Installer action:**
  none beyond the ordinary artifact update (`INSTALL-LINUX.md` Step 1).
- **card#9398** — **Two overlapping posts for one seat can no longer leave events permanently
  unfolded.** The fold read `events` behind a 2 s lag on `received_at`, and the ingest stamped
  `received_at` when the request arrived — before validation and before its transaction — so a post
  that arrived first but inserted second could hold a lower id than a batch the fold had already read
  past, and those events were never folded and nothing reported it. The ingest transaction now takes
  the seat's `seat_state` row lock as its first statement and stamps `received_at` after it, so for one
  seat id order and commit order are the same order, and the fold reads `events` by id alone, with no
  lag. `clock_skew_ms` still measures the request's arrival against `sent_at` (D1 § 10.1), so a post
  that waits for the lock does not badge `clock_skew`. The fold now treats MariaDB's concurrency errors
  (`1020`, `1205`, `1213`) as transient: the pass yields that seat and retries it whole on the next pass,
  where before, contention on both attempts could quarantine an innocent event as poison
  (`fold_error`, `derivation_error`). D2 § 6.5, AT-D2-22 and the number and decision tables restate
  the property and its conditions; the feed outbox keeps its own 2 s lag (card#9467). New tests drive
  the overlap on real MariaDB connections (`At22LockFirstIngestTest`) and the transient path at both
  transaction depths, and `EventsHaveOneWriterTest` fails on any write to `events` outside the
  ingest's `BatchWriter`, the condition the lock argument rests on. **Installer action:** none; no
  migration. A post for a seat whose row another transaction holds — the fold's window, or an
  overlapping post for the same seat — now waits for it before inserting anything rather than at its
  final `seat_state` update, bounded as before by the store connection's `innodb_lock_wait_timeout`.
- **card#9146** — **The promote mover's header records the rt#444 ruling: this repo keeps its
  `card#<id>` token mover.** `bin/promote-cards-by-token` § WHY THIS MOVER states the ruling, leaves
  the body's provenance and pin unchanged, carries runnable commands that measure both correlation
  keys on v0.1.0..v0.2.0 and v0.3.0..v0.4.0, and names the condition that reopens it.
  `docs/KANBAN.md` points at the ruling.
- **card#9445** — **Two-factor enrolment now says what happened to the code you entered.** A
  rejected code shows Fortify's message (*"The provided two factor authentication code was
  invalid."*) above the form, and an accepted code lands on the dashboard under *"Two-factor
  authentication is on. Each sign-in now asks for a code from your authenticator app."* Before, a
  correct code returned to `/two-factor-enroll`, which still drew the QR code, the recovery codes,
  the code form and "Start over" beneath the raw status key `two-factor-authentication-confirmed`,
  and a wrong code returned to the same page with no message; the first real sign-in read the
  success as a failure. Three changes carry it: `layouts/app.blade.php` renders the messages of
  every error bag (Fortify reports this rejection in `confirmTwoFactorAuthentication`, and the
  layout read only `default`); `auth/two-factor-enroll.blade.php` branches on
  `hasCompletedTwoFactorEnrolment()` first and shows a confirmed account a confirmed state with
  links to the floor and to `/two-factor/recovery-codes`; and
  `App\Http\Responses\TwoFactorConfirmedResponse` is bound over Fortify's contract so a browser
  lands on the dashboard, with the JSON response kept as Fortify's.
  `Tests\Feature\TwoFactorEnrolmentStatesTest` drives both answers through Fortify's routes and
  asserts on the page the browser lands on. A confirmed account's recovery codes are now shown only
  at `/two-factor/recovery-codes`, behind a password re-entry: `/two-factor-enroll` carries only
  `auth`, and it used to show a confirmed account's recovery codes and the QR code that encodes its
  authenticator secret to any signed-in session of that account.

- **card#9393** — **A flusher that loses ownership of `state.json` now stops sending and exits, as
  D1 § 2.3 requires; before, it detected the loss and kept posting from its in-memory `seq`.** The
  reporter's `saveState` refused the write and every caller ignored the refusal, so on a slow ingest
  two live flushers could emit overlapping seqs for one seat. What ships in `fleet-reporter.js`: one
  ownership check (`assertOwner`) guards each `state.json` and snapshot write, each lock touch and
  each request, and runs again on resuming from each awaited request, so a flusher taken over while
  it waited on the ingest does not go on to dispose of the answered batch, spool a heartbeat, drop
  spool buckets or delete counter buckets the new owner has not folded. A loss is thrown, not
  returned. The flusher loop catches it, counts
  `flusher_lost_ownership` once into the counter sink the new owner folds, logs once and exits 0.
  The flusher's own counters (`count()`) that it folded into `state.json`'s totals but never
  managed to save go to that sink on exit too, so a spool-bucket drop counted by a pass whose save
  failed still reaches the
  heartbeat.
  The lock is renewed before every request as well as at the start of every pass, so a live
  flusher's lock ages by at most one request plus one flush interval, not by a whole pass of POSTs.
  An exiting flusher removes `flusher.lock` only while the lock still names it; before, an ex-owner
  deleted the new owner's lock and let a third flusher start. D1 § 2.3 and decision 18, and
  `fleet-reporter/INSTALL-LINUX.md`'s restart derivation, now state the touch cadence and the exit
  behaviour. The acceptance suite's new block 18 drives each of these against a slow ingest stub,
  with a RED plant for each. **Installer action:** none beyond the ordinary artifact update
  (`INSTALL-LINUX.md` Step 1); a running flusher keeps the code it started with until it restarts.

- **card#9419** — **A seat that finishes a turn cleanly no longer renders *blocked* a minute
  later.** Claude Code's `Notification` hook fires `notification_type: "idle_prompt"` when *"Claude
  finished responding about 60 seconds ago and you haven't typed since"* — a timer on human ABSENCE,
  not a request for a human. `fleet-reporter` mapped it to `attention.request` with
  `notification_kind: input_awaited`, and D2 § 4.3's rule 1 renders any open attention request
  `blocked` ahead of every other rule, so every seat flipped *idle → blocked* about a minute after
  it went quiet and stayed there until its next event. On one seat in one day that produced 19
  `input_awaited` requests against 3 real `permission_required` ones. ⛔ **Installer action, on every
  seat that reports — the fleet-reporter runs on the agent machine, not on the server, and nothing
  in `bin/supervision.sh` touches it:** copy the new `fleet-reporter/fleet-reporter.js` over that
  seat's install location (`fleet-reporter/INSTALL-LINUX.md` Step 1) and then stop that seat's
  running flusher with the runbook's `stop-flusher.js` helper (Step 5 saves it, Step 8 item 3 shows
  the invocation), because a flusher already running keeps the code it started with; cron's next
  minute boundary starts it on the new artifact. Any *blocked* a seat is already stranded in clears
  at D2's 60-minute ceiling without help. What ships: `idle_prompt` moves to the no-emit row of
  D1 § 6.12's gate table and into the reporter's `NOTIFICATION_NOT_ATTENTION` list, so it takes the
  counted suppression path (`notification_not_attention.idle_prompt`) and is NOT also counted as an
  undeclared type; `agent_needs_input` keeps `input_awaited`, being a genuine wait. D1 § 12
  constraint 5's premise ("every member of `notification_kind` *is* a wait on a human, because the
  gate emits nothing for anything else") was FALSE for `idle_prompt` and is rewritten to say what it
  actually rests on: a per-row judgement in D1's table that no check establishes, instrumented by
  the `input_awaited`-to-`permission_required` ratio rather than gated. D2 § 4.4's `blocked` enter
  row, D1 § 6.0 rule 2's carve-out, AT-20's and AT-D2-5's discriminating controls and D1 § 15
  decision 29 carry the same correction. The fleet-reporter acceptance suite gains the case (seen
  RED on dev head) with a `permission_prompt` control. Also fixed, because the doc edit exposed it:
  `tools/design/verify-harness-facts.py`'s `table` locator derived a value set from *any*
  two-or-more run anywhere in the table, so a one-member row (which `agent_needs_input` now is)
  silently dropped that member and the gate would have reported the DOCUMENT as missing a member it
  plainly states — it now reads the column its locator names, which is also stricter.

Older releases: `docs/changelog/<tag>.md`, one per tag.

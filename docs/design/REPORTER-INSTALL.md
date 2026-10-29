# D6 — the one-command reporter install on Linux, Windows and macOS

**Status: BUILT, from revision r5 — r3 re-derived the installer at the upstream layer after design review
r2 tripped the loop's stop rule; r4 folded review r3's coverage findings; r5 folds review r4's findings
on r4's own additions ([§ 13](#13-where-the-review-findings-stand)). The operator's rulings of
2026-10-29 are recorded in [§ 10](#10-operator-rulings). Built in card#12571's installer change:
`fleet-reporter/install.js`, every step and subcommand of § 3, the version guard's population of § 3.3, the
suites of § 7 (R-srv-1 excepted), and § 11's ledger rows other than row 16. Not built there: § 4.2's *Save
token file* button and its R-srv-1, and ledger row 16, the Seat tokens page's text — card#12571's server
change, a separate PR. Not measured: the macOS and Windows legs on a real seat, which their CI runners and
the first real installs measure.** Card#12571; the macOS leg was added to the scope by the operator on
2026-10-29 (*"We will also onboard a macos claude agent. We need a working mezzanine reporter for
claude on macos"*).

> **What this document is.** The design for one install command that puts `fleet-reporter` on a
> Linux, Windows or macOS agent seat when the user asks their Claude Code agent for it in one sentence,
> asking the user for **one thing**: where they saved or copied the seat's token file. It owns the
> installer's shape, the token-file contract, the per-OS supervision, the acceptance tests the build
> must pass, and the operator's rulings. It owns **no rule D1 already owns**: the per-OS paths are
> [D1 § 2.1](EVENT-SCHEMA.md#21-one-file-four-subcommands) and [§ 3.1](EVENT-SCHEMA.md#31-the-seat-config-file),
> the two flusher starts are [D1 § 2.3](EVENT-SCHEMA.md#23-the-flusher-must-be-alive-whenever-the-seat-is).
> Where the design changes a claim a ratified document publishes, [§ 11](#11-the-amendment-ledger)
> names the diff; the build lands it in the owning document, in the same PR as the code.
>
> No seat, user or coordination identity is named here: the first Windows seat is written *the first
> Windows seat* and its record is card#12335's.

---

## 0. Overview

1. **The user says one sentence** — *"Install the Mezzanine reporter from
   https://github.com/PupFuzz/mezzanine"* — on any of the three OSes. The agent reads the README's
   Install section, which is short, agent-addressed and points at one entry point.
2. **`fleet-reporter/install.js` is the implementation.** One file, Node ≥ 18, no dependencies; it
   does every step in node on all three OSes — paths from a platform table, `fs` for files and
   modes, the existing ACL helper for Windows owner-only, `crypto` for digests, `spawn` for
   `crontab`/`launchctl`/the flusher, JSON for the settings file, the config writer as a function.
   **No bash block is executed.** r1 and r2 designed a node runner for the runbook's bash blocks and
   the review found the same layer re-minting defects three times over (block state passing, helper
   ordering, per-OS block splits); the maintainer's ruling re-derived the installer here
   ([§ 13](#13-where-the-review-findings-stand)). What that removes by construction: the installer's own
   use of `stat -c`, `sha256sum`, `md5sum`, `shred` and `readlink -f`, `$HOME`-versus-`WIN_*`,
   `B`/`SEAT_*` passing between shells, anchor suffixes, the bash-3.2 lint, Git Bash resolution, block
   ordering and the two-copy drift guard. `flock` and `crontab` stay Linux prerequisites: the crontab
   line the installer writes runs the flusher under `flock -n`, unchanged.
3. **The agent asks one question — where the user saved or copied the token file** (the operator:
   *"token file may be copied from another machine"*) — and passes that path and nothing else. The
   installer parses and validates the file, writes the config owner-only, and removes the file once
   the config is verified ([§ 4](#4-the-token-file-contract)). An argument that is really the token
   is refused by name and the token treated as exposed.
4. **The Seat tokens page gains a *Save token file* button** writing the v2 file client-side with the
   ingest URL, install id and seat id, so the installer derives the identity and asks nothing else.
5. **The same end state on every OS, idempotent; one command installs, re-checks and upgrades;
   `--uninstall` reverses it.** Supervision per OS: the user crontab on Linux, byte-identical to
   today's block; a user `launchd` agent on macOS for a seat at a GUI login; nothing on Windows
   (Option A), where the installer starts the flusher once. An existing Linux or Windows install
   upgrades in place ([§ 3.6](#36-upgrading-an-existing-seat-in-place)).
6. **The runbook stops owning commands.** `INSTALL-LINUX.md` becomes `INSTALL.md`: the reference for
   what each step does and why, with the by-hand fallback stated as `node install.js <step>`
   subcommands ([§ 3.2](#32-what-the-runbook-becomes)).

## 1. The problem, in the product's terms

**The operator, 2026-10-29, verbatim:** *"All the installation instructions need to be put into the
readme so that a user can just claude to install the mezzanine repo at http://gitubhub.com/.... on
either linux or windows, and then the setup does the rest and asks the user for the location of the
credentials file. The setup for fred required too much instructions"*.

What a user meets today, read from the tree at `origin/dev` `188e5fce`:

- **The README's install paragraph is Linux-only and is not an Install section** (`README.md:339-346`,
  inside *The admin console*); `grep -n 'Windows' README.md` prints the architecture row and nothing
  about installing.
- **The Linux route is three runs and a hidden prompt the agent cannot run**: `install.sh` exits `3`
  on a seat with no config (`fleet-reporter/install.sh:293-301`), the user runs `--set-token` at a
  terminal of their own because the agent's shell has no `/dev/tty` (`install.sh:239-241`,
  `AGENT-INSTALL.md:89-93`), then the agent runs the install.
- **The Windows route is by hand, one Bash tool call per block** of `INSTALL-LINUX.md`'s Windows
  section, re-exporting a values block each time (`INSTALL-LINUX.md:2197-2199`), the token carried
  by the operator over RDP (`:2470-2473`). The first real Windows install (card#12335's record,
  2026-10-29) worked that way — reporter 0.4.0, `win32`, the desk on the floor — at the instruction
  count the operator names.
- **There is no macOS route**: `INSTALL-LINUX.md:254` requires `flock` and `crontab`, and `flock(1)`
  does not exist on macOS ([§ 6](#6-macos)).
- **The Seat tokens page shows the token once and offers no file**
  (`server/resources/views/console/tokens/index.blade.php:14-15`); copying it into an editor is where
  a carriage return gets in (`INSTALL-LINUX.md:2491-2492`).

## 2. What the install must produce, on every OS

The end state is D1's and is not redesigned here. Each row is what today's Linux runbook and Windows
section produce; the installer produces the same from node:

| Produced | Linux | Windows | macOS |
|---|---|---|---|
| artifact + fixtures, owner-only | `~/.local/share/fleet-reporter/` (0700/0600) | `%LOCALAPPDATA%\fleet-reporter\` (owner-only ACL) | as Linux (D1 § 2.1) |
| config, owner-only, multi-site form, `descriptors` `"full"` | `~/.config/fleet-reporter/config.json` 0600/0700 | `%APPDATA%\fleet-reporter\config.json`, ACL | as Linux (D1 § 3.1) |
| board identity, to-do tools (0.5.0, `fleet-reporter/UPDATES.md`) | the same logic, in node | the profile from `os.homedir()` | as Linux |
| hooks in `~/.claude/settings.json`, the user's own entries kept | as `wire-hooks.js` does | path quoted | as Linux |
| supervised start carrying `$COORD_CONFIG` | the crontab block, byte-identical to today's | **none** (Option A); one start by the installer ([§ 3.4](#34-supervision-per-os)) | a user `launchd` agent ([§ 3.4](#34-supervision-per-os)) |
| selftest, health, arrival | the reporter's own `selftest`, the health and arrival checks in node | same | same |
| upgrade: install first, stop second, holder check | [§ 3.6](#36-upgrading-an-existing-seat-in-place) | same | same |
| uninstall | [§ 3.5](#35-uninstall) | same | same |

## 3. The installer

### 3.1 `fleet-reporter/install.js`

**Shape.** One file; its parts are functions, not blocks, and each step is also a subcommand so the
by-hand fallback and the installer are the same code:

| Part | What it holds |
|---|---|
| platform table | keyed on `process.platform`: artifact, config, spool, attempt-directory and settings paths — D1 § 2.1/3.1's values, with `os.homedir()` as the profile on every OS (Git Bash's `$HOME` is never read, `INSTALL-LINUX.md:2236`); the supervisor kind; the owner-only mechanism (`chmod` 0700/0600 on POSIX, the ACL helper on `win32` — today's `acl-only-me.js`, carried into `install.js` as a function) |
| `token-file` parser | **one** parser for v1 and v2 ([§ 4.2](#42-the-page-writes-the-file-v2)), used by the pre-read of `--token-file` and by the config write — the only reader of the file's bytes, and it never prints them |
| config writer | today's `write-config.js` logic (`INSTALL-LINUX.md:568-758`) as a function: first write, `set-token`, `add-site`, `remove-site`, `set-descriptors`, the statusLine wrap's `wrapped_statusline`; every result validated with **the checkout's** `fleet-reporter.js` `loadConfig` (`fleet-reporter.js:5303` exports it) — the build about to be installed, never the artifact already on the seat, which an upgrade is replacing |
| atomic write | **the reporter's own `atomicWrite`** (`fleet-reporter.js:833-862`: a unique temp beside the target, `wx` 0600, optional `fsync`, `rename`), **extended and exported** rather than a second primitive beside it: it gains a `keepMode` option (the target's existing mode, read before the write, applied to the temp) and, on `win32`, a bounded retry of the `rename` on `EPERM`/`EBUSY`, which an antivirus or indexer holding the target raises. The installer uses it for the settings file, the artifact, the config and the plist, writing beside `realpath`'s answer so a symlinked settings file stays a symlink. Today's blocks each spell the temp-and-rename on their own — the derivation is `grep -c 'renameSync' fleet-reporter/INSTALL-LINUX.md`, every hit a copy (the to-do key's removal at `INSTALL-LINUX.md:2104` and its set at `:1324` among them) — and the reporter's own `win32` state writes go through `atomicWrite` with **no** such retry today, so an indexer holding `state.json` loses that pass's save: a sibling of the same shape, named here for the build (canon #7), not fixed by this design |
| exports | `cronLine(platform, inputs)`, `launchdPlist(inputs)`, `hookCommand(platform, artifactPath)`, `writeConfig(…)`, `parseTokenFile(bytes)`, `parseIssueOutput(text)`, `siteKey(url)`, `atomicWrite` — for the reporter's acceptance suite and the version guard ([§ 3.3](#33-what-the-old-guards-protected-preserved-by-other-means)); **`main` runs only under a `require.main === module` guard**, so a `require` of the file runs nothing |
| board identity, to-do tools | today's `board-identity.js` and `wire-hooks.js --todo-tools` logic as functions |
| hook wiring | today's `wire-hooks.js` and `check-preserved.js` as functions: derive the hook set from the installed fixtures, append after the user's entries, hold every other key unchanged. **The canonical command string is pinned per OS**: `node <abs path> hook <Hook>` on POSIX; on `win32` `node "<C:/…/fleet-reporter.js>" hook <Hook>` — **forward slashes, double-quoted** — which is what the first Windows seat's `settings.json` holds today (`wire-hooks.js` refuses a backslash path and quotes, `INSTALL-LINUX.md:1141`, `:2502-2503`), and what D1 § 2.1's table will say ([§ 11](#11-the-amendment-ledger) row 14; its backslash example is the wrong side). `path.join` on `win32` yields backslashes, so **an entry is recognised by parsing, never by string equality**: the command is split into `node`, the quoted-or-bare path and `hook <Hook>`, and the path compared by `path.win32.resolve` lower-cased on `win32` (`stop-flusher.js`'s own comparison, `INSTALL-LINUX.md:1438`), `path.resolve` elsewhere. Dedupe at install and removal at uninstall use that one comparison, so the existing Windows seat is neither double-wired nor left with entries naming a deleted artifact; **the same parse recognises `node "<path>" statusline`**, so a wrapped statusLine is found and removed by the same rule; and dedupe **collapses** forward-slash and backslash variants of one artifact path to the canonical form, writing the file only when an entry changed |
| supervisor | `crontab` block write/compare/remove (Linux), `launchd` plist write/compare/bootstrap/bootout (macOS), one bounded flusher start (Windows) — [§ 3.4](#34-supervision-per-os) |
| flusher control | stop by pid with the artifact check (today's `stop-flusher.js`: `/proc` on Linux, `Get-CimInstance` on Windows; on macOS `ps -o command= -p <pid>` under `LC_ALL=C`, then — after the pid check — the lock's own `started_at` as the start time, so no `lstart` is parsed), the holder-started-after-artifact check, and the per-OS ancestry check, which is **informational as today** (`install.sh:346`: reported, never judged) — **superseded in the build** (card#12571 review round 2): the holder is identified by the lock's own `started_at` against the process's start time, never by a path, because a hook-respawned flusher runs its realpath; `fleet-reporter/INSTALL.md` § `holder` and `restart` owns the rule |
| checks | `selftest` (spawns the reporter), health (`GET /api/ingest/health` per site), arrival (the site's `state.json` entry, bounded wait) — today's blocks' logic |
| attempt directory | `<state>/fleet-reporter-install/<UTC stamp>/`, owner-only, holding the backups of the settings file, the crontab or plist, the artifact and the config; **removed by `--uninstall`** because the config backup holds the token |

**Steps and modes.** Every step is `node install.js <step>` and prints the same marker lines the
installer reads (`already wired: nothing applied`, `crontab already current`, `board identity:
unchanged`, `: unchanged`, `selftest rc=`, `health rc=`, `arrival rc=`), so a by-hand run and the
installer are one code path:

| Invocation | Steps, in order |
|---|---|
| `node install.js --token-file <path> [--site <url> --seat-id <id> [--install-id <id>]] [--agent-name] [--ca-file] [--no-board]` | `prereqs`, `backup`, `values` (from the file, or the flags for a v1 file), `resolver`, `identity`, `board-identity`, `token-take` ([§ 4.3](#43-the-take-rule)), `token-config`, `config-check`, then the install below. With a config present it is the **rotation** ([§ 4.5](#45-rotation)) |
| `node install.js [values]` | `prereqs`, `backup`, `artifact` (compare, then install), `resolver`, `board-identity`, `hooks`, `todo-tools`, `supervisor`, `holder`/`restart` when owed, `config-check`, `selftest`, `health`, `arrival`, `ancestry`. With no config: refuses, naming `--token-file` |
| `node install.js <step> [args]` | that step alone, the by-hand fallback `INSTALL.md` describes |
| `node install.js add-site --token-file <path> [--site <url>] [--ca-file <path>] [--site-name <n>]` · `remove-site <name>` | today's *Adding a site* and *Removing one site* (`INSTALL-LINUX.md:794-844`, `:2148-2168`). `add-site` runs **`token-take` and the default removal exactly as the main invocation does** ([§ 4.3](#43-the-take-rule)); a v2 file's identity must equal the config's and its `ingest_url` must match no configured site; a v1 file **requires `--site`**, since it carries none; `--ca-file` sets the new site's `ca_file` as the main invocation's does; then the writer's `add-site` mode and the flusher restart. `remove-site` refuses the last site and restarts |
| `node install.js statusline [--wrap <command>]` · `set-descriptors full\|paths\|none` | Step 4 (b)'s wrap (`INSTALL-LINUX.md:1090-1110`, with the cost the runbook measured) and Step 3's one-key rewrite (`:1075-1088`); each restarts the flusher as the runbook says. **On `win32` the wrap is unmeasured** (W4: *"not applied on Windows … nothing here measured it"*, `:2504-2505`) and `statusline` **refuses** there, naming that |
| `node install.js issue-file --out <path> [--prompt]` | the two by-hand **v1 producers** for a seat with no browser download. **Extraction is today's writer's** (`INSTALL-LINUX.md:684-705`, `TOKEN`/`SHAPE` at `:573`): the input must hold **exactly one** line matching `^\s*token\s+(mzn_[A-Za-z0-9_-]{43})\s*$`; every other line is echoed with every token-shaped substring replaced by `<redacted>`; zero or two token lines refuse. Without `--prompt` the input is stdin, where the issuer pipes `php artisan mezzanine:ingest-token:issue` (today's `FR_TOKEN_OUT`, `:910-925`) — **and a stdin that is a terminal is refused** (*"pipe the issue command in, or use --prompt"*), so a token is never typed into an echoing terminal. `--prompt` reads from `/dev/tty` with echo off (today's (a′), `:1023-1034`), **restores the terminal mode on every exit path** (success, refusal, signal), refuses when there is no terminal, and is **Linux and macOS only**: on `win32` there is no `/dev/tty`, and the subcommand refuses by name. The file is written `0600`, `wx`, one line; the prefix alone is printed. `parseIssueOutput` is exported and AT-28 Case P's stdin leg (D1 `EVENT-SCHEMA.md:8047`) drives it |
| `node install.js --uninstall` | [§ 3.5](#35-uninstall) |
| `node install.js --paths` | the resolved platform table, which R-all-1 holds against D1 |

Exit codes stay `install.sh`'s: `0` installed or nothing to change · `1` refused or a check failed ·
`2` installed and a check not measured. **Every argument is scanned for the token shape before
parsing** ([§ 4.4](#44-a-path-that-carries-the-token-is-refused)). A run that changes nothing writes
nothing, restarts nothing and removes its own attempt directory, as today (`INSTALL-LINUX.md:124-132`).

**What is deleted with the bash layer:** `install.sh`'s runner and `--check-runbook`; the runbook's
`run`/`skip` anchors; the `WIN_*` values block; the Windows section's one-block-per-call procedure;
the Step 1 ordering probe (it spools an `INVALID_TOOL_NAME` pair whenever a config exists, and the
installer proves nothing by it: hooks are wired after the artifact in one process); the helper
heredocs (`write-config.js`, `wire-hooks.js`, `check-preserved.js`, `coord-config.js`,
`board-identity.js`, `stop-flusher.js`, `flusher-ancestry.js`, `acl-only-me.js`), which become
functions of `install.js`.

### 3.2 What the runbook becomes

`fleet-reporter/INSTALL-LINUX.md` is renamed **`INSTALL.md`** (`git mv`; a one-paragraph
`INSTALL-LINUX.md` stub points at it and at the Windows and macOS sections of it, because the README,
the changelog and card records cite the old name). It is the **reference** for the installer: per
step, what `install.js` does on each OS, why (the measured history the runbook carries today is kept
where it still explains a decision — the upgrade order of `INSTALL-LINUX.md:286-321`, the cron
rationale of `:155-198`, the Windows findings of card#12335), what the step prints, and what each exit
means. **The by-hand fallback is `node install.js <step>`**, never a bash copy of the step; a reader
who cannot run node has no reporter to install. `AGENT-INSTALL.md` shrinks to the agent's five moves
([§ 5](#5-the-user-visible-flow)); `fleet-reporter/README.md`'s install paragraph and the root README's
point at it.

### 3.3 What the old guards protected, preserved by other means

- **`--check-runbook`** existed because there were two copies of every command (card#7336). One
  copy needs no drift guard: it is deleted, with its planted-divergence suite.
- **`bin/reporter-version-guard.py`'s population** moves from the runbook's `install` lines and
  crontab entry to `install.js`, in two parts. **The blob population stays what it is**: one marked
  region, `// reporter-version-guard: BEGIN artifact` … `END`, holds the list of files the `artifact`
  step installs (`fleet-reporter.js`, `fixtures/hooks/*.json`), and the guard reads that list as it
  reads the `install` lines today, so a changed artifact byte or a new fixture still maps to the
  `artifact`/`fixtures`/`hooks` touches. **The start definitions and the hook wiring are compared by
  output, not by text**: `node install.js --render-shipping` prints the crontab block, the plist and
  the hook command set **for every platform**, and the guard runs it at the base and at the head and
  diffs — so a helper edited anywhere in the file that changes what a seat installs reds, whether or
  not a region encloses it, mapping to the `supervisor` touch (replacing `cron`; `launchd` is the same
  row) or `hooks`. The render's rules, each with a RED:
  - **The input is a stable cross-rev contract**: one JSON object on stdin — a home, a `node` path, a
    `COORD_CONFIG`, the fixture names — and **unknown keys are ignored**, so a head that reads a new
    key still renders at a base that does not know it.
  - **The platform is an argument, never the environment.** Every render function takes `platform`
    explicitly and builds paths with `path.posix` or `path.win32`; the render reads neither
    `process.platform`, nor `process.env`, nor `os.homedir()`. The guard runs on `ubuntu-latest` and
    must see an edit to the `win32` hook branch alone (R-all-20).
  - **The render has no side effects**: it spawns nothing and writes nothing; an unknown flag beside
    `--render-shipping` **refuses** rather than falling through to an install. The guard runs it with
    a temporary `HOME` and a `PATH` holding no `crontab` or `launchctl`, so a regression in either
    rule is a visible failure, not a crontab written on a CI runner (R-all-21).
  - **A base with no `install.js`** — which is the build PR itself, and `reporter-version` is a
    required check on `dev` — is **not** exit 2: the base side of the diff is today's runbook
    population, the `install` lines and the crontab entry read as the guard reads them now, so the
    head either reproduces them (no touch owed) or differs (the touch owed, and the ledger row
    satisfies it). Exit 2 — never a pass, as today's unmeasurable runbook is
    (`bin/reporter-version-guard.py:20-23`) — is reserved for a missing or duplicated `artifact`
    region at a rev that has `install.js`, and for a render that fails at a rev that has one.
  Its selftest gains a plant per touch, one for a helper edit outside the region (R-all-14), and the
  base-without-`install.js` case (R-all-19).
- **The reporter's own acceptance suite reads the runbook today**, and D1 ratifies two of those
  reads: AT-27 case F runs the crontab `LINE` read out of `INSTALL-LINUX.md`
  (`fleet-reporter.selftest.py:4194-4232`; D1 `EVENT-SCHEMA.md:1326-1327`), AT-28 Case P re-extracts
  `write-config.js` from its fenced block (`:6311-6317`; D1 `:8044-8046`), and § 24 runs the runbook's
  blocks under the agent-shell condition (`:8689-8905`, card#12334). With no blocks to read, the build
  **retargets them at `install.js`'s exports**: case F runs `cronLine(inputs)`'s result under `sh -c`
  as it runs the extracted line today; Case P drives `writeConfig` through the same refusals; § 24's
  fail-stop property becomes *each subcommand exits non-zero at its first failed step and writes
  nothing after it*, driven the same way. The D1 sentences move with them ([§ 11](#11-the-amendment-ledger)
  row 15), in the same PR.
- **The install suite** (`install.selftest.py`) drives `install.js` in a throwaway `HOME`
  (`APPDATA`/`LOCALAPPDATA` on Windows) against a TLS ingest stub and a stub board, with `crontab`
  (Linux) and `launchctl` (macOS) stubbed on `PATH` as today's `crontab` is, on **`ubuntu-latest`,
  `windows-latest` and `macos-latest`** (every job runs on `ubuntu-latest` today —
  `grep -rh 'runs-on' .github/workflows/*.yml | sort -u` prints one value). The strace leg (the token
  in no argv or environment of any spawned process) runs on Linux; the other two runners record it
  *not measured*.

### 3.4 Supervision, per OS

**Linux — the user crontab, unchanged.** `install.js` writes the same marked block
(`# BEGIN fleet-reporter-flusher` … `# END`) with **the same four lines, byte for byte**: the marker,
the comment line (`# D1 § 2.3 supervised start, no root: …`, kept verbatim, including its reference
to `INSTALL-LINUX.md`, so an existing seat's crontab stays byte-equal and the file it names still
exists as the redirect stub), `* * * * *` and `@reboot` with `flock -n`, `COORD_CONFIG=` on the line
when the resolver finds one, `node` by its resolved path (`INSTALL-LINUX.md:1539-1552`); it replaces
an existing block, refuses unpaired markers, writes nothing when byte-equal, and stops the running
flusher when it wrote. **The read predicate is card#12334's** (`INSTALL-LINUX.md:83-87`): `crontab -l`
exiting `1` with stderr matching `^no crontab for ` is the empty crontab; **any other non-zero exit, or
a `1` with other stderr, is a read failure and the step refuses** — it never writes. `spawnSync`
reports status `1` for both, so the stderr test is the whole distinction; a port that read status
alone would install a crontab holding the reporter's block and nothing else, which on the sandbox
would delete `bin/supervision.sh`'s block. The same predicate guards the uninstall's removal. A seat
installed by `install.sh` reads `crontab already current`. The ancestry check is today's: the holder's
parents are `flock` and `cron`, reported and not judged.

**macOS — a user `launchd` agent** (the operator's ruling, Q1). `~/Library/LaunchAgents/com.pupfuzz.fleet-reporter.plist`,
0600, `ProgramArguments` = the path `command -v node` resolves to (the stable symlink, not a Homebrew
`Cellar` realpath) and `<artifact> flusher`; `RunAtLoad true`; `StartInterval 60`, not `KeepAlive`
(which relaunches a lock loser every throttle interval); `EnvironmentVariables.COORD_CONFIG` when the
resolver finds one; stdout/err to `/dev/null`. **The step reads the loaded state first**:
`launchctl print gui/<uid>/com.pupfuzz.fleet-reporter` — not loaded, loaded, or loaded from a plist
that differs from the staged one — and does the one thing that state needs: `bootstrap`; nothing
(`launchd agent already current`); or `bootout`, write, `bootstrap`. **The restart a changed start
definition or a new artifact owes is `launchctl kickstart -k gui/<uid>/com.pupfuzz.fleet-reporter`**
— launchd stops the running instance and starts one on the new definition — after the flusher stop
by pid, so the lock is released cleanly first. **The ancestry check passes only when `launchctl
print` reports a `pid` equal to `flusher.lock`'s** — a hook-started flusher is also reparented to pid
1, so "parent is launchd" cannot fail — and, as on Linux, it is reported, not judged. **A seat with no `gui/<uid>` domain is refused by name** (the operator confirmed the Mac
agent is used at the Mac, at a GUI login: *"(a)"*): *"no gui launchd domain for this user: the
reporter's macOS install supports a GUI login with Claude Code in a Terminal there; an SSH-only seat
is not supported"*. The macOS facts this rests on are [§ 6](#6-macos)'s, each marked sourced or recall.

**Windows — no supervisor** (Option A, the operator's ruling of 2026-10-25, card#12335). A hook fires
around a Claude Code tool call, not inside one, so inside one `install.js` run no flusher would start
and `arrival` would wait its whole window for nothing. The `supervisor` step on `win32` therefore
spawns `node <artifact> flusher` **unconditionally**, with the reporter's own spawn options
(`detached`, `stdio: 'ignore'`, `windowsHide`, `unref` — D1 P-7) and `COORD_CONFIG` from the
resolver: the flusher's `acquireLock` makes a loser exit 0 at once (`fleet-reporter.js:3516`,
`:4409`), so the installer carries **no copy of `maybeRespawnFlusher`'s lock logic** and cannot drift
from it. It **then waits, bounded by `K.LOCK_STALE_MS` read from the artifact, for a lock naming a
live pid** before it reports `flusher started pid N` (or `flusher already running pid N`), or
`NO FLUSHER` and exit 1. It is one start, as
a hook's is; D1 § 2.3's Windows sentence stands. The ancestry check on Windows is today's `--upgrade`
leg: the holder is live, fresh, and started after the artifact.

### 3.5 Uninstall

`--uninstall`, one process, Step 8's order (`INSTALL-LINUX.md:2085-2088`) with W8's one refinement:
**hooks first, removed structurally** — every hook entry whose command **parses** as the installer's
command for this artifact ([§ 3.1](#31-fleet-reporterinstalljs)'s comparison: the path resolved and,
on `win32`, lower-cased; never a substring, never a byte-equality that a slash direction defeats), the
`statusLine` key deleted when its command is the reporter's and the config's `wrapped_statusline` is
`null`, or set back to `wrapped_statusline` when it is not, the to-do key removed only when
`todo-tools-set` says this install set it and it still holds `"1"`; every other byte of meaning kept,
checked by the same preserved-keys comparison the install uses. **Never a restore from a backup**: a
backup is months old by then and restoring it deletes every hook anyone added since. Then **the
config is removed before the flusher is stopped** (W8's order, `INSTALL-LINUX.md:2608-2612`): a hook
still in flight then finds no config and exits instead of starting a flusher; then the supervisor —
the crontab block removed under the read predicate of [§ 3.4](#34-supervision-per-os) (a crontab the
step cannot read is never rewritten), with the `flock` file beside the spool removed; on macOS
`bootout`, then the plist deleted; nothing on Windows — then the flusher stopped by pid, the spool
and the artifact removed. **The config is removed as a directory**, `~/.config/fleet-reporter/` and
its Windows counterpart whole, so the `todo-tools-set` marker and any token file go with it.
**Attempt directories**: every file under `fleet-reporter-install/` **whose content matches the token
shape** is removed from every attempt directory — config backups and token files by that test, not
by name, so a copy under another name is caught too; the non-secret backups of this run (the settings
file, the crontab or plist) are kept, so the operator can see what the uninstall undid. **The revoke
prefixes are read from the config before anything is removed**, and printed last, one line per
configured site: *revoke prefix `<mzn_xxxxxxxx>` on `<ingest host>`'s Seat tokens page* (the prefix
is not secret: `AGENT-INSTALL.md:88`). Revoking is the operator's act.

### 3.6 Upgrading an existing seat, in place

Two layouts exist today and both are D1's paths, so the installer upgrades them with no migration:

- **A Linux seat installed by `install.sh`**: artifact, config, spool, attempt directories, the
  `todo-tools-set` marker and the crontab block are at the paths and in the forms `install.js` writes.
  The first `install.js` run on such a seat compares the artifact, installs it when it differs,
  reads `crontab already current` when the block is byte-equal, re-wires nothing when the hook set is
  unchanged, and ends `ALREADY CURRENT` or `INSTALLED` as the artifact decides. The attempt
  directories the old script made are kept until `--uninstall`.
- **The first Windows seat (card#12335's hand-run layout)**: `%LOCALAPPDATA%\fleet-reporter`,
  `%APPDATA%\fleet-reporter\config.json`, `%LOCALAPPDATA%\fleet-reporter-state`,
  `%LOCALAPPDATA%\fleet-reporter-install\<stamp>` — the platform table's `win32` row — and a
  `settings.json` whose hook commands are the quoted forward-slash form ([§ 3.1](#31-fleet-reporterinstalljs)).
  The first run compares, installs, finds every hook already wired by the parsed comparison and
  leaves `settings.json` **byte-equal**, re-checks the ACLs, and starts the flusher as
  [§ 3.4](#34-supervision-per-os) says. R-win-3 holds that file byte-equal through an upgrade.

The upgrade order is the ratified one (`INSTALL-LINUX.md:286-321`): artifact first, then the stop,
then the supervisor's or the installer's start, then the holder-started-after-artifact check. R-all-7
holds both layouts.

**`install.sh` becomes a shim** (Q5): it `exec`s `node install.js "$@"`, except that `--set-token` is
refused naming `--token-file`, because the mode's meaning changed. It ships with the reporter minor
that ships `install.js` — `0.6.0` if no other minor lands first (`REPORTER_VERSION` reads `0.5.0` at
`188e5fce`, `fleet-reporter.js:70`, and the `supervisor` touch makes this a minor) — and is removed in
the reporter minor after it; the shipping version's `UPDATES.md` row names both numbers.

## 4. The token-file contract

### 4.1 Routes

A token file reaches the seat one of two ways, and **both are first-class on every OS** (the
operator: *"token file may be copied from another machine"*): the browser on the seat saves it, or a
browser elsewhere saves it and the user copies it to the seat. The agent asks *"where did you save or
copy the token file?"* and passes the path. The runbook's hidden-prompt route (`INSTALL-LINUX.md:1023-1034`)
and the issue-command pipe become `install.js issue-file` ([§ 3.1](#31-fleet-reporterinstalljs)), the
by-hand ways to produce a **v1** file for a seat with no browser download; the installer takes either
file by path.

### 4.2 The page writes the file: v2

The issue response alone carries the plaintext (`SeatTokenController.php`, *SHOW-ONCE IS THE POST'S
OWN 200*). The button is in `seat-token.js`, builds a `Blob` of type `application/octet-stream` from
the page's own text (no second request, URL, session row or log line), and downloads it as
`mezzanine-reporter-token`. **It is rendered only on an `https:` page**, and the `pageshow` handler
that blanks the token on a back/forward-cache restore **also removes the button**, so a restored page
can neither show nor save it. The ingest URL is rendered server-side into `data-ingest-url` from
`config('app.url')` — the precedent `IssueCoordHook.php:99` sets — never from `url()` or
`window.location`, which are request-derived.

```
token mzn_<43 chars>
ingest_url https://<host>/api/ingest/events
install_id <slug>
seat_id <slug>
```

LF line endings, no carriage return, no trailing whitespace. **v1** is today's bare one-line token
(`INSTALL-LINUX.md:889-901`). **One parser** accepts both: v1 by the writer's `FILE_TOKEN` rule; v2 as
exactly those four keys, once each, in any order, `\r` anywhere refused by name, `ingest_url` an
`https://` URL ending `/api/ingest/events`, the slugs by D1 § 3.1; anything else refused by name,
content never printed. The installer derives the identity from a v2 file and refuses a flag that
disagrees with it. The producer and the consumer are in this repository: the contract is declared
here and in D1 § 3.1 ([§ 11](#11-the-amendment-ledger) row 2) and checked by one test that drives the
button's blob through the parser (R-srv-1).

### 4.3 The take rule

`--token-file <path>` must name a regular file this account owns, not a symlink. **Reachability is
computed per principal, over the file and its directory chain**: on POSIX, for each of group and
other, the file's read bit **and** every ancestor's traverse bit; on Windows, the ACL split into the
owner's own entries and foreign ACEs (SYSTEM and Administrators counted as root is, as
`acl-only-me.js` does today). **The operator's ruling is *accept with warning*** (Q4), for a saved
and a copied file alike: a file some other principal could have read is **taken, with one warning**
naming the directory, the window — from the file's `birthtime` where the platform has it, else
`ctime`, never `mtime`, which a copy preserves — the principals that could read it (the local
accounts for POSIX group/other, the foreign ACEs on Windows), and *"revoke this prefix on the Seat
tokens page and issue again if you are unsure"*. A file nobody else could reach is taken with no
warning. This loosens Step 2 (c)'s *treat as exposed* rule for a file given by path, and
[§ 11](#11-the-amendment-ledger) row 12 records it.

The take: the config directory is made owner-only; **when the path already is the named file in the
config directory** (the hand-delivery route), nothing moves; otherwise on POSIX the file is renamed
or copied in, then **`chmod 0600`** before the config write (a rename keeps the source's mode); on
Windows it is copied, not renamed, so it inherits the directory's ACL (card#12335 measured the
inherited case, `INSTALL-LINUX.md:2424-2426`), checked, and the source removed. The config write reads
the file through the same parser; the file is then removed — **by default, no flag to keep it** (Q3)
— `unlinked; overwrite best-effort`, since snapshots and backups keep what an unlink does not reach
([§ 8](#8-risks-by-name)). A run that fails between take and remove leaves the token in exactly one
place, the owner-only config directory.

### 4.4 A path that carries the token is refused

1. **Any argument** matching `/mzn_[A-Za-z0-9_-]{43}/`: *"an argument looks like a seat token, not a
   path: NOTHING RUN. That token is now in this shell's history and transcript: revoke its prefix on
   the Seat tokens page and issue another"*; the prefix is not printed back.
2. **The agent's rule** (`AGENT-INSTALL.md`): a reply holding a `mzn_` string means the token has
   passed through the agent (D1 P-6); the agent runs nothing and says so.
3. **The file's content** is validated by the one parser, which never echoes it; a refused file's
   token is treated as exposed, Step 2's rule unchanged (`INSTALL-LINUX.md:1004-1008`).

### 4.5 Rotation

With a config present, `--token-file` rotates. The file's `ingest_url` is the page's exact rendering
and is not normalised; **each configured site's `ingest_url` is reduced by the exported `siteKey`**
— scheme and host lower-cased, a trailing `/` dropped, `/api/ingest/events` appended to a bare origin
— and the file's URL, passed through the same `siteKey`, must equal exactly one of them, with
`install_id` and `seat_id` equal to the config's; that site is rotated, and `--site-name`, if given,
must name it. **A mismatch is refused with a listing**: the file's identity and every configured
site's name and URL, and the pointer to `install.js add-site` — because writing site B's token into
site A's entry has A refuse every batch and quarantine them (D1 § 11.5). A v1 file rotates
`--site-name`, `default` when unset. Then `config-check`, the OS's flusher restart, and the reminder
to revoke the old prefix. The suite imports `siteKey` rather than re-spelling it (R-all-9).

## 5. The user-visible flow

**Linux**

```
User:  Install the Mezzanine reporter from https://github.com/PupFuzz/mezzanine
Agent: One thing I need from you: on the site, open Admin → Seat tokens, issue a token for this seat —
       type install id "<account name, as a slug>" and seat id "<host name, lower-cased>" unless you
       want other names — click "Save token file", and tell me where you saved or copied the file on
       this machine (usually ~/Downloads/mezzanine-reporter-token). Don't paste the token itself; the
       file is removed once the token is in the config.
User:  ~/Downloads/mezzanine-reporter-token
Agent: (runs) git clone --depth 1 https://github.com/PupFuzz/mezzanine ~/.local/src/mezzanine
       (runs in the background, output to a file) node ~/.local/src/mezzanine/fleet-reporter/install.js --token-file ~/Downloads/mezzanine-reporter-token
       … token-take: WARNING: ~/Downloads/mezzanine-reporter-token could be read by other local accounts for 41 s
         (group: none; other: none found); revoke this prefix and issue again if you are unsure · taken (mode 600)
       … wrote ~/.config/fleet-reporter/config.json (mode 600) · token file unlinked; overwrite best-effort
       … board user <id> on https://<kanban host> · CLAUDE_CODE_ENABLE_TODO_TOOLS=1: set · crontab: installed
       … selftest rc=0 · health rc=0 · arrival rc=0
       install.js: INSTALLED: the server holds this build current and every site has accepted a batch; the desk is on the site's floor
Agent: Done. Your desk is on the floor as <install>/<seat>. Start a new Claude Code session to see tool calls appear.
```

**Windows (Git Bash)** — the same words; `C:\Users\me\Downloads\mezzanine-reporter-token` is passed
with forward slashes, the take says `(ACL: owner-only)`, the supervisor line reads `flusher started
pid 4120`, and the agent's closing line adds that nothing supervises the background process on
Windows, so the desk reads offline when no session is open.

**macOS** — the same words; the agent adds *"save or copy the file into your home folder rather than
Downloads, Desktop or Documents; if it is in one of those and macOS asks whether the terminal may
access that folder, allow it"*, and the supervisor line reads `launchd agent bootstrapped in gui/501;
launchctl reports pid 8812 = flusher.lock`.

**Upgrade and re-check:** *"Upgrade the Mezzanine reporter"* → `git -C ~/.local/src/mezzanine pull
--ff-only` and the same command with no arguments; `ALREADY CURRENT` on a current seat. **Uninstall:**
`--uninstall`, then the operator revokes the prefixes it prints.

**`AGENT-INSTALL.md`** states the five moves and three mechanical facts. **The identity the agent
proposes**: the install id is the account name as a slug (`install.sh:195`'s derivation) and the
seat id the host name lower-cased, offered for the user to type on the Seat tokens page, since the
page is where the pair is first fixed. **The run time**, derived from the installer's own bounds:
`restart`'s wait for the supervisor (70 s today, `INSTALL-LINUX.md:1937`) + `arrival`'s window
(`FR_WAIT_S=150`, `:1760`) + one 15 s request timeout per site for `selftest`'s probe and one for
`health` (`:2001`, D1 § 3.5) — over four minutes on one site before the artifact copy and the hook
spawn — which exceeds the agent's Bash tool default timeout (120 s, maximum 600 s per call, read from
the harness's own tool description on 2026-10-29). **So the rule is: run the installer in the
background with its output to a file, and read the file's last lines**; a foreground call is a
truncated install. **Retrying after the token file is taken**: the file is in the config by then, so
a re-run is `node install.js` with no `--token-file` — the agent is told this, because a second
`--token-file` run would find the path gone and refuse. **The README** gets a short, agent-addressed
`## Install the reporter on an agent machine` section after *Architecture*: the sentence, the file
the agent reads, the command, the same command for upgrade, `--uninstall`, `INSTALL.md` as the
reference; `README.md:339-346` and the architecture row's wording (`:48`) become pointers to it.

## 6. macOS

No macOS machine is available to this seat. With the installer in node, the BSD-versus-GNU question
reduces to what `install.js` spawns: `crontab` (not used on macOS), `launchctl`, `ps`, and the
reporter itself. What remains recall or sourced:

| Fact | Status | Source |
|---|---|---|
| no `flock(1)`, no `shred(1)` on macOS | sourced; **no longer load-bearing** — nothing in `install.js` runs either | Apple's man-page index, `https://keith.github.io/xcode-man-pages/`, read 2026-10-29 |
| BSD `stat`, `sha256sum` present on macOS 14 | sourced; not load-bearing — digests and modes are node's | `stat.1`, `sha256sum.1` on the same index |
| per-user agents run while the user is logged in, are user-owned in `~/Library/LaunchAgents`, get `SIGTERM` at logout | sourced | Apple, *Creating Launch Daemons and Agents* |
| no `/proc` on Darwin; `ps -o lstart=,ppid=,command= -p <pid>` is the flusher-control read | recall; R-mac-3 and the first install | — |
| a `StartInterval` job whose previous instance still runs is skipped, not stacked | recall; if it stacks, the reporter's own lock makes the extra exit 0 (churn, not correctness); R-mac-2 | — |
| `launchctl bootstrap gui/<uid>` works in a `macos-latest` runner session | recall; decides whether R-mac-2 runs in CI or only on the seat | — |
| no `gui/<uid>` domain for an SSH-only login | recall; the refusal of [§ 3.4](#34-supervision-per-os) is the behaviour either way | — |
| `~/Downloads`, `~/Desktop`, `~/Documents` are privacy-protected: a terminal process reading them prompts once, a denial is `EPERM` | recall; `token-take` reads `EPERM` there as *"macOS refused the read: allow the terminal access to this folder, or put the file in your home folder, and run again"*, and the agent steers the save or copy destination away from them ([§ 5](#5-the-user-visible-flow)) | — |
| `rosterSite()` reads the home-path roster on `linux` only (`fleet-reporter.js:522`), so on macOS the roster is `$COORD_CONFIG` or none | read from the code; D1 says it for Windows and is silent on macOS — [§ 11](#11-the-amendment-ledger) row 4 | — |

**Reporter code change for darwin: none identified.** `configPath()` (`fleet-reporter.js:293-300`),
`platformName()` (`:3826`) and the respawn (`:2972-2984`) are platform-neutral or already admit
`darwin`; `REPORTER_VERSION` moves for the `supervisor` touch, not the artifact.

**The first real macOS install** (the Mac seat's own agent, Q6) reports, by name: the login kind
check (`launchctl print gui/$(id -u)` succeeds; over `ssh` to the same Mac it fails and the installer
refuses); the privacy prompt when the file is in `~/Downloads` and what the installer printed on a
deliberate denial; the hooks firing from a session and `node` resolving under the hooks' PATH; the
agent bootstrapped and `launchctl` reporting the lock's pid; recovery after `kill -9` within
`K.LOCK_STALE_MS` + 60 s with `launchctl` reporting the new pid; a log-out/log-in (`SIGTERM`, lock
removed, started again at login); sleep/wake with no `seq_collision`; `read_via` `$COORD_CONFIG` when
a coordination config exists; `selftest`/`health`/`arrival` rc 0 and `reporter_platform: darwin`;
the upgrade as a sequence and `--uninstall`.

## 7. Acceptance tests — the REDs the build must pass

Each is seen to fail once before it counts (canon #9). The suite drives `install.js` as [§ 3.3](#33-what-the-old-guards-protected-preserved-by-other-means)
says; **all** = the three runners, **lin/win/mac** = that runner, **seat** = the first real install.

| # | RED | Where |
|---|---|---|
| R-all-1 | `--paths` equals D1 § 2.1/3.1's rows for the running OS, read out of D1 by the test | all |
| R-all-2 | an argument matching the token shape ⇒ exit 1, nothing written, no token printed; a two-token, empty, CR-bearing, fifth-key, repeated-key, non-https, bad-slug file ⇒ refused by name, content never printed; a v2 file disagreeing with a flag ⇒ refused; a v1 file with no `--site`/`--seat-id` ⇒ refused naming both | all |
| R-all-3 | a symlink, a directory, a file another account owns ⇒ refused before any read | all |
| R-all-4 | the take rule: a file nobody else could reach ⇒ no warning; group-readable in a traversable chain ⇒ warning naming group, the window from `birthtime`/`ctime` (a copy with an old `mtime` still reports the copy's age), the accounts; a file already at the named path ⇒ not moved; after every take the named file is 0600 in 0700 (POSIX) or owner-only (win), and the config write passes; the file is gone afterwards | all |
| R-all-5 | the token in no stdout, stderr, argv or environment of any spawned process — strace on lin; *not measured* recorded on win/mac | lin (+ recorded elsewhere) |
| R-all-6 | second run on a current seat: owned files keep bytes and mtimes, no restart, attempt directory removed, `ALREADY CURRENT` | all |
| R-all-7 | upgrade in place from a seat laid out by today's `install.sh` (lin) and by card#12335's hand-run section (win): artifact replaced first, flusher stopped second, the holder-after-artifact check PASSes; the crontab block reads `already current` on lin; a run against the Windows layout re-checks the ACLs and starts the flusher | lin win |
| R-win-3 | a `settings.json` fixture in the documented quoted forward-slash form (`INSTALL-LINUX.md:2502-2503`): an upgrade leaves it **byte-equal** (no second entry per hook); `--uninstall` removes exactly those entries; a backslash-form entry planted beside them is recognised as the reporter's too | win |
| R-all-13 | the crontab read: stderr `no crontab for <user>` with status 1 ⇒ empty, the block written; any other failure (a stub exiting 1 with other text, or exiting 2) ⇒ the step refuses and `crontab` is never called with a file, at install and at uninstall | lin |
| R-all-14 | `reporter-version-guard.py`: a helper edited **outside** the `artifact` region that changes `--render-shipping`'s crontab, plist or hook output ⇒ FAIL naming `supervisor` or `hooks`; a missing or duplicated region at a rev that has `install.js`, or a render failing at such a rev ⇒ exit 2 | all |
| R-all-19 | the guard on the build PR's own shape: a base **without** `install.js` (today's runbook) and a head with it plus the ledger row ⇒ PASS; the same head without the row ⇒ FAIL naming the touches the runbook population and the render differ on; never exit 2 for the absent base | all |
| R-all-20 | the render's platform is an argument: an edit to the `win32` hook-command branch alone, run on `ubuntu-latest`, ⇒ FAIL naming `hooks`; a render input carrying an unknown key ⇒ rendered unchanged | lin |
| R-all-21 | the render has no side effects: run with a temporary `HOME` and a `PATH` without `crontab`/`launchctl`, the trace shows no spawn and the `HOME` is untouched afterwards; `--render-shipping` with any other flag ⇒ refused, nothing run; a plant that makes the render spawn `crontab` is caught by the empty `PATH` and the trace | lin |
| R-all-22 | `issue-file`: a piped issue output with one token line ⇒ the file written `0600`, the other lines echoed with every token-shaped substring `<redacted>`, the prefix printed; zero or two token lines ⇒ refused; a stdin that is a terminal without `--prompt` ⇒ refused; `--prompt` on `win32` ⇒ refused by name; `--prompt` under a pty on lin/mac ⇒ the token read with echo off and the terminal mode restored, also after a planted failure mid-read and after `SIGINT` | all (the pty case lin mac) |
| R-all-15 | `install.sh --set-token …` ⇒ refused naming `--token-file`, nothing run; `install.sh` with any other arguments ⇒ `install.js` runs with them | lin mac |
| R-win-4 | a roaming profile (stub `roaming true`) ⇒ refused by name before anything is written, as W1 does today (`INSTALL-LINUX.md:2401-2402`) | win |
| R-all-8 | hooks: the user's entries and every other key survive byte-equal; the path quoted on win; `todo-tools` set/kept with its marker; board identity from the stub board | all |
| R-all-9 | rotation: a v2 file matching no site ⇒ refused with the listing, config byte-equal; matching B with `--site-name A` ⇒ refused; matching B alone, or by a URL differing only in case or a trailing slash ⇒ B rotated and A untouched; mismatched identity ⇒ refused | all |
| R-all-10 | `--uninstall` with a foreign hook entry added after the install, a `statusLine` the user set later, and (lin) a foreign crontab line added outside the block after the install: the reporter's entries (by the parsed comparison) and its to-do key gone, the foreign entry and the user's `statusLine` kept; the config removed before the stop; the crontab holds the foreign line and no block, and the `flock` file is gone (lin); `bootout` called and the plist file absent (mac); every config backup and token file gone from every attempt directory while this run's settings and crontab/plist backups remain; one prefix line per site | all |
| R-all-11 | `reporter-version-guard.py`: a file added to or changed in the `artifact` region's list, or a `--render-shipping` output that differs base-versus-head, with `REPORTER_VERSION` unmoved ⇒ FAIL naming the touch (`artifact`/`fixtures`/`hooks`/`supervisor`); moved with the row ⇒ PASS | all |
| R-all-12 | `AGENT-INSTALL.md` holds verbatim the `mzn_` rule and the timeout sentence (prose rules no guard sees otherwise) | all |
| R-lin-1 | the crontab block written once, byte-identical to `INSTALL-LINUX.md:1543`'s line at `188e5fce` for the same inputs, under `flock -n` with `COORD_CONFIG=`; the cron thread starts the flusher; ancestry PASS | lin |
| R-win-1 | the supervisor step starts the flusher and waits for a live lock; with the start disabled it ends `NO FLUSHER` exit 1 and `arrival` is never reached; ACLs on config, token directory and artifact pass | win + seat |
| R-win-2 | a profile path with a space: the hook command quoted; the installer's own paths handled | win |
| R-mac-1 | the plist: 0600, `command -v node`'s path, `StartInterval 60`, `RunAtLoad`, `COORD_CONFIG`; the stub `launchctl`'s three loaded states each get their one action; an equal plist ⇒ `already current`, nothing called; a restart owed ⇒ `kickstart -k` on the label after the stop by pid | mac |
| R-all-16 | the atomic write: a settings file that is a symlink keeps being a symlink and its target gets the bytes; the mode survives; a write interrupted before the rename leaves the target untouched; on win a planted `EBUSY` on the first rename is retried and succeeds | all |
| R-all-17 | the config is validated with the **checkout's** reporter: a checkout whose `loadConfig` refuses a key the installed artifact accepts ⇒ the write is refused, the config byte-equal | all |
| R-all-18 | `add-site`, `remove-site`, `statusline`, `set-descriptors` and `issue-file` each drive the runbook's refusals (a site that already exists, the last site, a statusLine that is neither the reporter nor the prior command, a descriptor outside the three, no terminal for `--prompt`) and their success path, with the flusher restarted where the runbook says; `add-site` with a **v1 file and no `--site`** ⇒ refused naming it, with `--site` ⇒ the site added and the file taken and removed as the main invocation does; with **`--ca-file`** ⇒ the new site's `ca_file` set and the probe trusting it; `statusline` on `win32` ⇒ refused naming the unmeasured wrap; a wrapped statusLine in backslash form on `win32` ⇒ recognised and removed by `--uninstall` | all |
| R-mac-2 | ancestry: a planted lock pid that `launchctl print` does not report ⇒ FAIL; reported ⇒ PASS; on the seat: `kill -9` recovery and no stacking under `StartInterval` | mac + seat |
| R-mac-3 | flusher control on darwin: a reused pid refused, a holder started before the artifact ⇒ FAIL, a stand-in not running the artifact refused | mac |
| R-mac-4 | no `gui/<uid>` domain (stub) ⇒ refused by name before anything is written | mac |
| R-srv-1 | the Save button: absent on an `http:` page; present on `https:`, its blob is v2 with the `app.url`-rendered ingest URL, LF only, `application/octet-stream`, parsed by the installer's parser; absent after a `pageshow` restore; the plaintext in no URL, session row or log line (the existing `seat-token.browser.mjs` walk gains the clicks) | server |

## 8. Risks, by name

- **Learned on card#12335**: backslashes and `/c/` rewriting (the installer passes paths only through
  node and argv); `install -d -m` and modes on Windows (owner-only is the ACL helper); no supervisor
  (the agent's closing line says so); roaming or redirected `%APPDATA%` (refused on a roaming profile,
  redirection not detectable — unchanged, `INSTALL-LINUX.md:2654-2659`); whether a session-fired hook
  on Windows carries the settings' `env` block (unmeasured; `read_via` is the observable).
- **macOS**: every row of [§ 6](#6-macos) marked recall; a `node` whose symlink target moves on upgrade
  (the plist names the symlink; `prereqs` compares and the supervisor step rewrites).
- **The file lands in a synced folder** (iCloud Desktop/Documents, OneDrive, Dropbox): the installer
  warns on a known sync root — a heuristic, and it says so — and the token's exposure there is not
  verifiable; the remedy is revocation.
- **The removed file's bytes may survive** in APFS/NTFS snapshots, Time Machine, File History, VSS and
  cloud version history; `unlinked; overwrite best-effort` says so; revocation is the remedy.
- **The agent opens the file with its Read tool**: forbidden in `AGENT-INSTALL.md`, enforced by nothing;
  R-all-12 keeps the sentence present.
- **Three runners** triple the reporter workflow's wall time (`fleet-reporter.yml:60-62` records the
  measured run time); the repository is public, so the minutes are not billed; the latency gate stays
  Linux-only.
- **The two-layout upgrade** rests on the paths being D1's on both existing seats, which the runbook
  and the Windows section state and R-all-7 checks against fixtures of each layout, not against the
  live seats.

## 9. What this design deliberately does not do

- It does not keep a bash copy of any step: the by-hand fallback is `node install.js <step>`.
- It does not add a Windows supervisor (the ruling of 2026-10-25 stands) and does not support an
  SSH-only or headless Mac (refused by name).
- It does not refuse a token file others could have read (the operator ruled *accept with warning*).
- It does not restore `settings.json` from a backup, ever.
- It does not touch `settings.local.json`, a project's `.claude/settings.json` or managed settings.

## 10. Operator rulings

All of 2026-10-29, recorded as decided; nothing gates the build.

| Question | Answer (verbatim) | Where it lands |
|---|---|---|
| Q1 macOS supervision: (A) user `launchd` agent, (B) crontab without `flock`, (C) hooks only | **A** | [§ 3.4](#34-supervision-per-os) |
| Q2 the Seat tokens page gains *Save token file* and keeps showing the plaintext | **yes, both** | [§ 4.2](#42-the-page-writes-the-file-v2) |
| Q3 the token file is removed by default | **yes, by default** | [§ 4.3](#43-the-take-rule); no keep flag |
| Q4 a token file other accounts could have read | **accept with warning** — covers a saved and a copied file alike | [§ 4.3](#43-the-take-rule); ledger row 12 |
| Q5 `install.sh` becomes a shim for one release | **yes** | [§ 3.6](#36-upgrading-an-existing-seat-in-place) |
| Q6 the Mac seat's own agent performs the first install | **yes** | [§ 6](#6-macos) |
| Is the Mac agent used at the Mac (a GUI login, Claude in a Terminal there) or over SSH? | **"(a)"** | [§ 3.4](#34-supervision-per-os): the GUI-login seat is the supported case; SSH-only refused by name |
| Where is the browser? | **"token file may be copied from another machine"** | [§ 4.1](#41-routes): the carried file is first-class on every OS; the question is *where did you save or copy the token file?*; the v2 lines drive the identity; macOS destinations steered away from the privacy-protected folders |
| The installer's layer (the maintainer's seat ruling after review r2, reversible) | `install.js` is the implementation; no bash blocks | [§ 3](#3-the-installer), [§ 13](#13-where-the-review-findings-stand) |

`COORD_CONFIG` on macOS needs no decision: the resolver reads the settings file and the plist carries
what it prints.

## 11. The amendment ledger

Diffs to ratified documents the build lands in the same PR as the code. Row 10 was applied by the design
PR; every other row but row 16 is applied by the installer change, and row 16, which names the page's
*Save token file* button, lands with that button in the server change.

| # | Document and place | Change | Why |
|---|---|---|---|
| 1 | D1 § 2.1 and § 3.1's path tables | no path change; one sentence: the installer's platform table is these rows, read from `install.js --paths` | who sets the per-OS values |
| 2 | D1 § 3.1, the carried-file sentence | the token-file contract: v1 and v2, the four keys, LF only; the page's Save button as the producer; the take rule | the hop declared where the consumer reads D1 |
| 3 | D1 § 2.3 item 1 | *on macOS, a user `launchd` agent with `StartInterval 60` and `RunAtLoad`, for a seat at a GUI login* (Q1, "(a)"); `COORD_CONFIG` by `EnvironmentVariables`; the Windows sentence gains *the installer starts one flusher at install and upgrade* | § 2.3 names the Linux and Windows starts; macOS is a third |
| 4 | D1 § 3.1, the roster resolution paragraph | *the home path is Linux's; on Windows and macOS `$COORD_CONFIG` is the whole resolution* | `rosterSite()` already does this |
| 5 | D1 § 15 row 9 | the macOS column: supervised, a user `launchd` agent | the per-OS decision |
| 6 | D1 § 16's install precondition | a macOS leg, as the Windows leg reads (`EVENT-SCHEMA.md:8557-8565`) | same shape |
| 7 | `fleet-reporter/INSTALL-LINUX.md` → `INSTALL.md` | renamed; the reference of [§ 3.2](#32-what-the-runbook-becomes); every `run`/`skip` anchor, the Windows values block and the helper heredocs removed; a redirect stub at the old name; the sentence *"install.sh runs this runbook's blocks"* and § Running it with `install.sh` replaced | the runbook stops owning commands |
| 8 | `fleet-reporter/AGENT-INSTALL.md`, `fleet-reporter/README.md`, `README.md` | [§ 5](#5-the-user-visible-flow) | the user-facing surfaces |
| 9 | `docs/PLAN.md § 3`, the installer row | *Linux, Windows and macOS: `fleet-reporter/install.js`* | the work-breakdown map |
| 10 | `tools/design/README.md` | this document declared under no gate — applied by this PR | the convention |
| 11 | `docs/VERSIONING.md § The reporter's own version`, `fleet-reporter/UPDATES.md`'s touch table, `bin/reporter-version-guard.py` and its selftest | the population is the `artifact` region's file list plus `--render-shipping`'s output for every platform, diffed base-versus-head; `cron` → `supervisor`, covering the plist; a base without `install.js` reads as today's runbook population; the plants of R-all-11, R-all-14, R-all-19, R-all-20, R-all-21 | [§ 3.3](#33-what-the-old-guards-protected-preserved-by-other-means) |
| 12 | `INSTALL.md`'s Step 2 (c) failure rule (today `INSTALL-LINUX.md:1004-1008`) and D1 § 3.1 | **the operator's ruling *accept with warning* loosens** *treat the token as exposed rather than repair the mode and go on* for a file the installer takes by path, saved or copied: taken, mode repaired, warning printed | recorded, not applied silently |
| 13 | `fleet-reporter/README.md`'s file table and `.github/workflows/fleet-reporter.yml` | `install.js` and `INSTALL.md` replace `install.sh`, `INSTALL-LINUX.md` and `install.selftest.py`'s runbook-guard section; the three-runner matrix | the directory's own index and the suite's home |
| 14 | D1 § 2.1's install-path table, the Windows row | the settings command on `win32` is `node "C:/Users/<u>/AppData/Local/fleet-reporter/fleet-reporter.js" hook <Hook>` — forward slashes, double-quoted; the row's backslash example is replaced | the first Windows seat holds that form, written by `wire-hooks.js`, which refuses a backslash path (`INSTALL-LINUX.md:1141`); D1's example was the wrong side |
| 15 | D1 § 13 AT-27 case F (`EVENT-SCHEMA.md:1326-1327`) and AT-28 Case P (`:8044-8046`); `fleet-reporter.selftest.py` § 24 | the crontab line comes from `install.js`'s exported `cronLine`, the writer from its exported `writeConfig`, and § 24's fail-stop property is each subcommand's | the suite stops reading a runbook that no longer holds blocks |
| 16 | `server/resources/views/console/tokens/index.blade.php:15` | *"the token file the install guide names (`fleet-reporter/INSTALL-LINUX.md`, Step 2)"* → *"save it with the button below, or see `fleet-reporter/INSTALL.md`"* | the page names a file that becomes a stub |
| 17 | every D1 sentence naming `INSTALL-LINUX.md` | re-pointed at `INSTALL.md`, or at the `install.js` step that now owns the behaviour, one by one; **the population is derived, not listed**: `grep -n INSTALL-LINUX docs/design/EVENT-SCHEMA.md` prints every hit, and the build's reviewer re-runs it at the head and expects the stub's name to remain only where history is cited | a rename that leaves a ratified document naming a stub is the drift the stub exists to catch |

## 12. What this design does not establish

- Every macOS row of [§ 6](#6-macos) marked recall; the first install and R-mac-* measure them.
- That a session-fired hook carries the settings' `env` block on Windows or macOS (D1 § 3.1's open item).
- That the file lies where the user says, that no sync client copied it, or that the account
  enumeration sees every principal who could read it (a network-mounted home is outside it, and the
  warning says so).
- That the live Linux and Windows seats hold exactly the layouts R-all-7's fixtures model.

## 13. Where the review findings stand

**r1** (VERDICT REVISE on `25a7c455`, 1 blocker, 7 major, 11 minor) was folded in r2. **r2** (VERDICT
REVISE on `c6c76e87`: N1–N20) found r1's fixes re-minting defects in the layer they patched — block
state passing (N1), helper ordering and naming (N2), a second parser (N4), the per-OS block splits —
which is the loop's pre-committed non-convergence observable (canon #19), so the maintainer declined
an r3 patch round and ruled the re-derivation of [§ 0](#0-overview) item 2 (canon #2/#15). This
revision is that re-derivation. Where each finding stands:

| Finding | Standing |
|---|---|
| r1 F1 (per-platform block set), F3 (block state passing), F12 (bash lint), F13 (the probe), F19 (Git Bash), r2 N1 (`$B` collision, backup twice), N2 (helper order and names), N10 (dead probe), N11 (anchor collisions), N12 (selector blind spots), N15 (`B` leakage), N16 (multi-line `SEAT_WRAPPED`), N20 (line cites into blocks) | **Removed by construction**: there are no bash blocks, anchors, helpers, `B`/`SEAT_*` exports, selectors or runbook cites to get wrong; the probe is deleted |
| r1 F2 (win32 flusher start) | carried, with N14: the start **waits, bounded, for a live lock** ([§ 3.4](#34-supervision-per-os)); R-win-1 |
| r1 F4 (launchd pid == lock) | carried ([§ 3.4](#34-supervision-per-os)); R-mac-2 |
| r1 F5 (SSH, carried token, TCC) | carried: GUI-only refusal, the carried route first-class, the privacy-folder steer and `EPERM` message ([§ 3.4](#34-supervision-per-os), [§ 4.1](#41-routes), [§ 6](#6-macos)) |
| r1 F6 (rotation) + N7 (URL normalisation) | carried with normalised matching and a listing on refusal ([§ 4.5](#45-rotation)); R-all-9 |
| r1 F7 + N8 (per-principal reachability), N9 (`ctime`/`birthtime`; source == destination; Windows owner-versus-foreign split) | carried ([§ 4.3](#43-the-take-rule)); R-all-4 |
| r1 F8 (version guard) | carried, re-homed to `install.js`: the `artifact` region's file list for the blobs, `--render-shipping` output diffed base-versus-head for the start definitions and hook wiring, `supervisor` replacing `cron` ([§ 3.3](#33-what-the-old-guards-protected-preserved-by-other-means)); R-all-11, R-all-14, R-all-19 to R-all-21 |
| r1 F9 + N6 (attempt directories, prefix per site) + the exact-command match and `statusLine` deletion | carried ([§ 3.5](#35-uninstall)); R-all-10 |
| r1 F10 + N5 (loaded state) | carried: the step reads the loaded state and acts once ([§ 3.4](#34-supervision-per-os)); R-mac-1 |
| r1 F11 (symlink path), F14 (shim), F15 (no keep flag), F16 (unlinked; best-effort), F17 (ledger row 12), F18 + N3 (`config('app.url')`, octet-stream, https-only, bfcache), F20 (figures), N4 (one parser), N13 (darwin roster: D1 row 4), N17 (uninstall predicate: exact match), N18 (Save button after a bfcache restore: removed with the token), N19 (the Bash tool timeout in `AGENT-INSTALL.md`) | carried as stated in the sections each names |
| N1's second half (backup twice), the by-hand route | the by-hand route is `node install.js <step>`; `backup` is one step, run once per invocation |
| r1 F3's state passing, r2 N1/N15 | there is no state passing between processes: one node process holds the values |
| r2's false counts and off cites | every figure in this revision is a command or a cited line of the tree at `188e5fce`; no claim is made about the cites' survival across the rename — the build re-cites at the rename and the reviewer of that PR checks them |

**r3** (VERDICT REVISE on `effbf846`: the re-derivation holds; coverage gaps and one false upgrade
claim) is folded in r4:

| Finding | Disposition |
|---|---|
| F1 MAJOR — the Windows seat's hook commands are quoted forward-slash; `path.join` would double-wire and strand them | **Fixed.** § 3.1 pins the canonical `win32` form, recognises entries by parsing (`path.win32.resolve`, lower-cased), § 3.6 corrects the upgrade claim, ledger row 14 amends D1 § 2.1; R-win-3 |
| F2 MAJOR — the crontab read predicate | **Fixed.** § 3.4 names card#12334's predicate (status 1 **and** `^no crontab for ` ⇒ empty; anything else ⇒ refuse, never write), for install and uninstall; R-all-13 |
| F3 MAJOR — the reporter's suite reads the runbook; D1 ratifies two reads | **Fixed.** § 3.3: `install.js` exports `cronLine`, `writeConfig`, `siteKey`, `parseTokenFile`, `hookCommand`, `launchdPlist` behind a `require.main` guard; AT-27 F, AT-28 P and § 24 retargeted; ledger row 15 |
| F4 MAJOR — operations with no subcommand | **Fixed, all kept**: `add-site`, `remove-site`, `statusline`, `set-descriptors`, `issue-file` (`--prompt` and the stdin pipe) in § 3.1; § 4.1 and § 4.5 point at them; R-all-18 |
| F5 MAJOR — the version guard's population | **Fixed.** § 3.3: the `artifact` region keeps the blob population; supervisor and hooks compared by `--render-shipping` output base-versus-head; missing/duplicated region or failing render ⇒ exit 2; R-all-14 |
| F6 ancestry informational; macOS restart | **Fixed**: § 3.1 and § 3.4 (`kickstart -k` after the stop by pid); R-mac-1 |
| F7 `flock`/`crontab` stay Linux prerequisites | **Fixed**: § 0 corrected |
| F8 unconditional win32 spawn with the reporter's options | **Fixed**: § 3.4; no copy of the lock logic |
| F9 exported `siteKey`, config side normalised | **Fixed**: § 4.5; R-all-9 |
| F10 one atomic write primitive | **Fixed**: § 3.1; R-all-16 |
| F11 uninstall order and residue | **Fixed**: § 3.5 (config before the stop, `flock` file, plist after `bootout`, secrets out of every attempt directory, this run's non-secret backups kept); R-all-10 |
| F12 validate with the checkout's reporter | **Fixed**: § 3.1; R-all-17 |
| F13 the agent proposes the install id and seat id | **Fixed**: § 5 |
| F14 foreign crontab line; shim and roaming REDs | **Fixed**: R-all-10, R-all-15, R-win-4 |
| F15 run time derived; background rule; retry after the take | **Fixed**: § 5 |
| F16 darwin: lock's `started_at` after the pid check; `LC_ALL=C` | **Fixed**: § 3.1 |
| F17 the Seat tokens page's text | **Fixed**: ledger row 16 |
| F18 off cites / self-coverage claim | **Fixed**: the claim dropped (the row above) |
| hygiene — the crontab comment line verbatim | **Fixed**: § 3.4 says so and why |

**r4** (VERDICT REVISE on `570a9cfc`: r3's F1, F2 and F6–F17 closed; the open items are r4's own
additions) is folded in r5:

| Finding | Disposition |
|---|---|
| 1 GATING — the render guard would exit 2 forever on the build PR (no `install.js` at the base) | **Fixed.** § 3.3: a base without `install.js` reads as today's runbook population, never exit 2; the render input is one JSON object on stdin with unknown keys ignored; R-all-19 |
| 2 GATING — the render must not depend on the platform it runs on | **Fixed.** § 3.3: `platform` is an explicit argument, `path.posix`/`path.win32`, no `process.platform`/`env`/`os.homedir()`; R-all-20 |
| 3 GATING — `add-site`'s shape | **Fixed.** § 3.1: `--token-file`, `--site` (required for v1), `--ca-file`, `--site-name`; `token-take` and the default removal as the main invocation; R-all-18's v1 and CA cases |
| 4 GATING — `issue-file` security | **Fixed.** § 3.1: today's extraction (exactly one `token` line, every other line echoed with the shape redacted), a terminal stdin refused without `--prompt`, the terminal mode restored on every exit path, `--prompt` lin/mac only and refused on `win32`, `parseIssueOutput` exported and Case P's stdin leg retargeted; R-all-22 |
| 5 GATING — render side effects | **Fixed.** § 3.3: spawns nothing, writes nothing, an unknown flag refuses; the guard runs it with a temporary `HOME` and a `PATH` without `crontab`/`launchctl`; R-all-21 |
| r4-F5 uninstall: the config directory; token-shaped files in attempt dirs; prefixes read first | **Fixed**: § 3.5 |
| r4-F6 extend the reporter's `atomicWrite`; the false count; the win32 sibling | **Fixed**: § 3.1's row — extended and exported, the derivation in place of the count, the reporter's own retry-less `win32` state writes named as a sibling for the build |
| r4-F7 restate ledger row 11, R-all-11 and the r1-F8 row | **Fixed** |
| r4-F8 statusline in the parsed compare; win32 wrap unmeasured; slash-variant dedupe | **Fixed**: § 3.1's hook-wiring and `statusline` rows; R-all-18 |
| r4-F9 the two cites; the "every cite verified" claim | **Fixed**: `install.sh:195`, `INSTALL-LINUX.md:2104`; the claim dropped |
| r4-F10 a derived ledger row for D1's `INSTALL-LINUX` mentions | **Fixed**: ledger row 17 |
| hygiene — `main` under the guard, not the exports | **Fixed**: § 3.1's exports row |

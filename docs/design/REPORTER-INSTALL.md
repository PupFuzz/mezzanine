# D6 — the one-command reporter install on Linux, Windows and macOS

**Status: DESIGN, revision r2 — folds design review r1's findings ([§ 13](#13-design-review-r1--disposition-per-finding))
and the operator's answers of 2026-10-29 to r1's six questions ([§ 10](#10-operator-questions)); one
question, the Mac seat's login kind, is being asked separately. Nothing in this document is built.** Card#12571; the macOS leg was added to the scope by the operator on 2026-10-29 (*"We will also
onboard a macos claude agent. We need a working mezzanine reporter for claude on macos"*).

> **What this document is.** The design for one install command that puts `fleet-reporter` on a
> Linux, Windows or macOS agent seat when the user asks their Claude Code agent for it in one sentence,
> asking the user for **one thing**: where they saved the seat's token file. It owns the installer's
> shape, the token-file contract, the per-OS supervision choice, the acceptance tests the build must
> pass, and the operator questions. It owns **no rule D1 already owns**: the per-OS paths are
> [D1 § 2.1](EVENT-SCHEMA.md#21-one-file-four-subcommands) and [§ 3.1](EVENT-SCHEMA.md#31-the-seat-config-file),
> the two flusher starts are [D1 § 2.3](EVENT-SCHEMA.md#23-the-flusher-must-be-alive-whenever-the-seat-is),
> and every install command is `fleet-reporter/INSTALL-LINUX.md`'s. Where the design changes a claim a
> ratified document publishes, [§ 11](#11-the-amendment-ledger) names the diff; the build lands it in
> the owning document, in the same PR as the code.
>
> No seat, user or coordination identity is named here: the first Windows seat is written *the first
> Windows seat* and its record is card#12335's.

---

## 0. Overview

1. **The user says one sentence** — *"Install the Mezzanine reporter from
   https://github.com/PupFuzz/mezzanine"* — on any of the three OSes. The agent reads the README's
   Install section, which is short, agent-addressed and points at one entry point.
2. **One entry point, `fleet-reporter/install.js`**, runs on all three OSes under the `node` the
   reporter already requires. It keeps the ratified rule that **the runbook owns every command and
   the installer runs its blocks** ([§ 3.2](#32-why-the-runbook-stays-the-owner-of-the-commands)
   says why that is kept and not replaced): it is `install.sh`'s order, conditions and outcome-reading
   ported to node, plus an explicit per-platform block table ([§ 3.3](#33-the-per-platform-block-set-derived)),
   with the Windows section's `WIN_*` values set by the installer instead of by a pasted values block.
3. **The agent asks the user one question — the path of the token file** saved from the site's Seat
   tokens page — and passes the installer that path and nothing else. The installer takes the file into
   the owner-only config directory, validates it, writes the config, and removes the file once the
   config is verified ([§ 4](#4-the-token-file-contract)). A path that is really the token is refused
   by name, and the token is then treated as exposed ([§ 4.4](#44-a-path-that-carries-the-token-is-refused)).
4. **The Seat tokens page gains a *Save token file* button** that writes the file client-side, with the
   ingest URL, install id and seat id in it, so the installer derives those three and the user is asked
   nothing else, and no token is ever copied into an editor ([§ 4.2](#42-the-page-writes-the-file-v2)).
5. **The same end state on every OS, idempotent, one command for install, re-check and upgrade**, and
   `--uninstall` for the reverse. Supervision stays per OS: the user crontab on Linux, nothing on
   Windows (Option A, the operator's ruling of 2026-10-25; the installer itself starts the flusher
   once, [§ 3.6](#36-starting-the-flusher-on-windows)), and on macOS a user `launchd` agent (the
   operator's answer of 2026-10-29, [§ 10](#10-operator-questions)), designed for a seat used at a GUI
   login; an SSH-only seat is a named refusal until the operator answers the open question ([§ 6](#6-macos)).
6. **A fresh install goes from three runs to one**: today's exit-3 run, `--set-token` run and install
   run become one invocation with `--token-file`.

## 1. The problem, in the product's terms

**The operator, 2026-10-29, verbatim:** *"All the installation instructions need to be put into the
readme so that a user can just claude to install the mezzanine repo at http://gitubhub.com/.... on
either linux or windows, and then the setup does the rest and asks the user for the location of the
credentials file. The setup for fred required too much instructions"*.

What a user meets today, read from the tree at `origin/dev` `188e5fce`:

- **The README's install paragraph is Linux-only and is not an Install section.** `README.md:339-346`
  sits inside *The admin console*, under the Seat tokens paragraph, and says the agent has the user
  *"type it at a hidden prompt"*. There is no Windows sentence in the README at all
  (`grep -n 'Windows' README.md` prints the architecture table's row 34 and nothing about installing).
- **The Linux route is three runs and a hidden prompt the agent cannot run.** `install.sh` exits `3`
  on a seat with no config (`fleet-reporter/install.sh:293-301`), the user runs `--set-token` at a
  terminal of their own because the agent's shell has no `/dev/tty` (`install.sh:239-241`,
  `AGENT-INSTALL.md:89-93`), then the agent runs the install. `wc -l fleet-reporter/AGENT-INSTALL.md`
  prints the guide's length, and its longest section is the token step.
- **The Windows route is by hand, block by block.** `install.sh` refuses to run there
  (`AGENT-INSTALL.md:30-32`); the agent runs the Windows section of `INSTALL-LINUX.md` one Bash tool
  call per block, re-exporting a values block before each (`INSTALL-LINUX.md:2197-2199`), and the token
  is carried by the operator as a file copied over RDP (`INSTALL-LINUX.md:2470-2473`). The first real
  Windows install (card#12335's record, 2026-10-29) ran W0–W7 that way, one block per call, and it
  worked: reporter 0.4.0, platform `win32`, the desk drawn on the floor. The cost was the instruction
  count the operator names.
- **There is no macOS route.** `fleet-reporter/README.md:35-44` names Linux and Windows;
  `INSTALL-LINUX.md`'s prerequisites line requires `flock` and `crontab`
  (`INSTALL-LINUX.md:254`), and `flock(1)` does not exist on macOS ([§ 6.1](#61-what-macos-lacks-and-what-it-has)).
- **The Seat tokens page shows the token once and offers no file.** `server/resources/views/console/tokens/index.blade.php:14-15`
  renders the plaintext and says *"Put it into the seat's reporter config"*; the page's one script
  blanks it on a back/forward restore (`server/public/js/console/seat-token.js`). The user copies it
  into an editor and saves a file, which is where a carriage return gets in — the hazard the Windows
  section's W2 names (`INSTALL-LINUX.md:2491-2492`).

## 2. What the install must produce, on every OS — the population

The end state is D1's and is not redesigned here. The installer must produce, on each OS, the same
set the Linux runbook and the Windows section produce today, read from their blocks:

| Produced | Linux (runbook step) | Windows (W-step) | macOS (this design) |
|---|---|---|---|
| artifact + fixtures, owner-only | Step 1 → `~/.local/share/fleet-reporter/` | W1 → `%LOCALAPPDATA%\fleet-reporter\` | as Linux (D1 § 2.1: *Linux / macOS*) |
| config, owner-only, multi-site form, `descriptors` `"full"` | Step 2 → `~/.config/fleet-reporter/config.json` 0600/0700 | W2 → `%APPDATA%\fleet-reporter\config.json`, owner-only ACL | as Linux (D1 § 3.1) |
| board identity, to-do tools | Step 2 `board-identity`, Step 4 (c) `todo-tools` (0.5.0, `fleet-reporter/UPDATES.md`) | the same blocks, with the profile from `WIN_UP` | the same blocks as written |
| hooks in the account's `~/.claude/settings.json`, user's own entries kept | Step 4 (a) | W4 (path quoted) | Step 4 (a) |
| supervised flusher start carrying `$COORD_CONFIG` | Step 5, user crontab + `flock -n` | **none** (Option A); the installer starts one flusher ([§ 3.6](#36-starting-the-flusher-on-windows)) | [§ 6.3](#63-supervision-the-choice-and-the-recommendation) |
| selftest, health, arrival checks | Step 6, U8, Step 7 | W6, W7 | the same blocks |
| upgrade: install first, stop second, holder check | U1–U8 | W-upgrade, U6 | U1–U8 with the macOS supervisor |
| uninstall | Step 8, by hand | W8, one tool call | `--uninstall` on every OS ([§ 3.5](#35-uninstall)) |

The blocks the installer runs are exactly the runbook's `run` anchors, and that list is derived, not
written. With the platform suffix of [§ 3.4](#34-the-runbook-guard-extended):

```
sed -n 's/^<!-- install\.sh: run \([a-z0-9-]*\)\( · [a-z0-9 ]*\)\{0,1\} -->$/\1\2/p' fleet-reporter/INSTALL-LINUX.md
```

prints it, and `--check-runbook` holds it to the installer's table both ways, as `install.sh` holds
today's list to `MZI_BLOCKS` (`install.sh:37-39`).

## 3. The installer

### 3.1 Shape: `fleet-reporter/install.js`

One file, no npm dependencies, Node ≥ 18 (the reporter's own floor, D1 § 2.1). It is not installed on
the seat and ships no bytes to it, so `bin/reporter-version-guard.py`'s population changes only by the
`launchd` touch [§ 6.3](#63-supervision-the-choice-and-the-recommendation) adds. What it owns is what
`install.sh` owns today (`install.sh:5-13`): **the order, the conditions under which a block runs, and
the reading of each block's outcome** — plus four things `install.sh` does not have:

1. **A platform table**, keyed on `process.platform`, that sets the values the blocks read. On
   `win32` it exports exactly the Windows values block's `WIN_UP`, `WIN_I`, `WIN_C`, `WIN_SPOOL`,
   `WIN_B_ROOT` (`INSTALL-LINUX.md:2233-2241`), computed in node from `os.homedir()`, `%APPDATA%`
   and `%LOCALAPPDATA%` in forward-slash form, so no `cygpath` call and no backslash ever enters the
   text a block runs. On `linux` and `darwin` it exports none of them, and every block takes its Linux
   path, which D1 § 2.1 and § 3.1 give macOS as well.
2. **The state the runbook passes between blocks, owned by the installer.** The runbook has two lines
   that export into the *current* shell — Step 0's `export B=…` and Step 2's values block
   (`INSTALL-LINUX.md:66-71`), which is why `install.sh` `eval`s a block in its own shell. The installer
   runs every block as `bash <file>`, a file it writes under `$B` with mode `0600`, so a block cannot
   export into it. Instead: the installer computes `B` as Step 0's line does and exports it to every
   block; Step 0's line becomes `export B=${B:-$HOME/.local/state/fleet-reporter-install/$(date -u +%Y%m%dT%H%M%SZ)}`,
   which by hand still mints one and under the installer keeps the one it was given. The `values`
   block is run with its placeholders filled by references as today (`install.sh:55-63`), and its own
   last line, `env | grep '^SEAT_' | sort`, is what the installer reads: those `SEAT_*=` lines become
   the environment of every later block. `board-identity`'s trailing export line reads
   `$B/board-identity.vals`, and the installer reads the same two lines from that file. The resolver's
   printed value is read as `install.sh` reads it. So every value a block needs arrives in its
   environment, and a block run by hand still exports as the runbook says.
3. **One token mode** that is part of the install run, `--token-file <path>` ([§ 4](#4-the-token-file-contract)),
   replacing the exit-3 / `--set-token` / install sequence with one invocation.
4. **A `bash` locator.** Linux and macOS: `/bin/bash`. Windows: the `bash.exe` beside Git's own
   install, found from `git --exec-path` (`<Git>/mingw64/libexec/git-core` → `<Git>/bin/bash.exe`), and
   refused unless that bash's `uname -o` prints `Msys` — `C:\Windows\System32\bash.exe` is WSL's and would
   run the blocks on Linux against the wrong home. The Claude Code tool on Windows is Git Bash already
   (`INSTALL-LINUX.md:2172`), so the common case finds it at once.

Modes, and what each runs (the Linux order is `install.sh:288-359`'s; the names are the anchors; the
per-platform selection is [§ 3.3](#33-the-per-platform-block-set-derived)'s table):

| Invocation | What it does |
|---|---|
| `node install.js --token-file <path> [--site <url> --seat-id <id> [--install-id <id>]] [--agent-name] [--ca-file] [--no-board]` | **first install**: `prereqs`, `backup`, `writer`, `values`, `resolver`, `identity`, `board-identity`, **`token-take`** (new, [§ 4.3](#43-how-the-installer-takes-the-file-token-take)), `token-config`, `token-shred`, `config-check`, then straight on into the install below. With a config already present it is the **rotation** ([§ 4.6](#46-rotation-with-a-token-file)), as `--set-token` is today (`install.sh:231-286`) |
| `node install.js [values]` | **install, re-check, upgrade** — the same run: `prereqs`, `backup`, `u1`/`artifact`, `probe` (first install only), `resolver`, `board-identity`, `hooks-scripts`, `hooks-apply`, `todo-tools`, the OS's supervisor blocks, `holder` and `restart` when owed, `config-check`, `selftest`, `health`, `arrival`, the OS's ancestry check. With no config: refuses, naming `--token-file` |
| `node install.js --uninstall` | [§ 3.5](#35-uninstall) |
| `node install.js --check-runbook [<runbook>]` | the guard, ported: every ```` ```bash ```` block anchored, every run block present once, every message read present in its block (`install.sh:88-116`), plus the checks [§ 3.4](#34-the-runbook-guard-extended) names |
| `node install.js --paths` | prints the resolved paths, which R20 holds against D1 § 2.1/3.1 |

Exit codes stay `install.sh`'s (`0` installed or nothing to change · `1` refused or a check failed · `2`
installed and a check not measured); `3` goes away, because the token step is no longer a separate run.
Markers the installer reads in block output stay `MZI_MARKERS`'s set (`install.sh:40-54`), so the
runbook's messages do not move; the new blocks add theirs to the same list and the guard holds them.

### 3.2 Why the runbook stays the owner of the commands

**Chosen: port the runner, keep the blocks.** The alternative the dispatch asked to weigh — one node
script that *is* the installer, with the runbook demoted to prose — was rejected on the mechanism:

- The runbook (`wc -l fleet-reporter/INSTALL-LINUX.md`) is measured steps, each carrying what its run
  showed on a real seat. Its blocks are already mostly node programs in heredocs (`write-config.js`,
  `board-identity.js`, `wire-hooks.js`, `check-preserved.js`, `coord-config.js`, `stop-flusher.js`,
  `flusher-ancestry.js`, `acl-only-me.js`), wrapped in a few lines of shell. Moving them into
  `install.js` moves bytes, not behaviour, and leaves the runbook a restatement with no guard (canon #16)
  or deletes the by-hand procedure the first Windows install relied on.
- `--check-runbook` is the thing that keeps the document and the code from drifting; it exists because
  they did (card#7336). A code-owned installer has nothing to check against.
- `bin/reporter-version-guard.py` reads the runbook's `install` lines as the shipped population
  (`bin/reporter-version-guard.py:13-23`). Moving them into code moves the guard too.
- `bash` is on all three seats: Git Bash is Claude Code's shell tool on Windows, `/bin/bash` 3.2 is
  macOS's (recall; R14 measures), `/bin/bash` is Linux's.

**Rejected: `install.sh` plus a Windows twin plus a macOS twin.** Three copies of the order,
conditions and outcome-reading (canon #5); a PowerShell twin adds a second language with no runbook
guard, and card#12335 already showed most Linux blocks run under Git Bash with `WIN_*` set
(`INSTALL-LINUX.md:2219-2224`), so a twin would be a copy of `install.sh` with a values block pasted
in. Cost of the chosen shape against it: one `node` process per run plus one `bash` per block, which
`install.sh` already pays per block.

**What happens to `install.sh`** (the operator's answer to r1's Q5: yes, a shim for one release): it
becomes a shim that `exec`s `node install.js "$@"`, with one exception: `--set-token` is refused with
a line naming `--token-file` and [§ 4.5](#45-what-replaces---set-tokens-hidden-prompt), because the
mode's meaning changed and a silent pass-through would run a rotation the caller did not ask for. The
shim ships in the reporter minor that ships `install.js` — `0.6.0` if no other minor lands first, since
`grep -o "^const REPORTER_VERSION = '[^']*'" fleet-reporter/fleet-reporter.js` reads `0.5.0` at
`188e5fce` and the `launchd` touch makes this a minor — and is removed in the reporter minor after it;
the shipping version's `UPDATES.md` row names both numbers, so the removal is keyed to a release and not
to a date. What else calls `install.sh` is unverifiable
from here: this repository's own references are `grep -rn 'install.sh' --include=*.md .`, and a seat's
private notes are not in it.

**What happens to `AGENT-INSTALL.md`:** it shrinks to the agent's five moves ([§ 5](#5-the-user-visible-flow)):
check `node`, clone or pull, ask the one question, run the one command, relay the last lines. The
failure table stays, shortened to the rows the new flow can reach. Its *"the user enters it at a hidden
prompt"* section goes: the token is a file on every OS.

### 3.3 The per-platform block set, derived

A block runs on one platform only where it touches a supervisor, a GNU coreutils tool, a Linux path
with no Windows value, or `install -d -m` (which fails on Windows, `INSTALL-LINUX.md:2420-2423`). The
population of blocks that need attention is **derived**, not listed, by one walk over every `run`
block at `188e5fce`:

```
awk '/^<!-- install\.sh: run /{name=$4} /^<!-- install\.sh: skip/{name=""} /^```bash/{inb=1;next} /^```/{inb=0}
     inb && name { for (t in T) if ($0 ~ T[t]) u[name]=u[name]" "t; if ($0 ~ /\$HOME/ && $0 !~ /WIN_/) u[name]=u[name]" HOME-noWIN" }
     BEGIN { T["stat-c"]="stat -c"; T["sha256sum"]="sha256sum"; T["md5sum"]="md5sum"; T["shred"]="shred"; T["install-d-m"]="install -d -m";
             T["readlink-f"]="readlink -f"; T["flock"]="flock"; T["crontab"]="crontab"; T["/proc"]="/proc/" }
     END { for (n in u) print n ":" u[n] }' fleet-reporter/INSTALL-LINUX.md | sort
```

What it prints at `188e5fce`, and what the build does with each (every other `run` block already
takes its path from `WIN_*` when set and from the Linux path when not — the Windows section's own
convention, `INSTALL-LINUX.md:2219-2224` — and runs on all three unchanged):

| Block | Printed | Disposition |
|---|---|---|
| `backup` | `stat -c`, `md5sum`, `crontab`, `HOME-noWIN` | split: `backup` (settings, artifact, config — paths from `WIN_UP`/`WIN_I`/`WIN_C` with Linux fallbacks, owner-only via `owner-only.js`) runs on all three; `backup-cron · linux` copies the crontab; `backup-launchd · darwin` copies the plist. W0's block becomes `backup`'s `win32` half: with `WIN_B_ROOT` set it makes `$B` and runs `acl-only-me.js prep`, as W0 does today |
| `artifact`, `u1` | `sha256sum`, `install -d -m`, `HOME-noWIN` (`artifact`) | one `artifact` block for all three: `install -d` then `owner-only.js prep` where W1 does today, `install -m 0600` lines unchanged (the version guard's population), digests by `digest.js`. The ordering probe leaves it ([F13](#13-design-review-r1--disposition-per-finding)) |
| `probe` (new) | — | W1's guarded probe, for all three: refuses when a config exists or `FLEET_REPORTER_CONFIG` is set, because `PostToolUse` on `{}` with a config present spools a `tool.start`/`tool.end` pair for `INVALID_TOOL_NAME` (`INSTALL-LINUX.md:2426-2429`). Today's Linux `artifact` block runs that probe unconditionally, so every Linux upgrade spools that pair — a live defect this design removes, reported in § 13 |
| `token-prompt` | `stat -c`, `install -d -m`, `HOME-noWIN` | becomes a **`skip`** anchor: the by-hand terminal route ([§ 4.5](#45-what-replaces---set-tokens-hidden-prompt)); the installer never runs it |
| `token-config`, `token-rotate`, `config-check` | `stat -c` (`token-rotate` also `HOME-noWIN`) | `owner-only.js check` in place of `stat -c` and of the `WIN_C` branch; `token-rotate` takes `${WIN_C:-…}` and `${WIN_I:-…}` as `token-config` does |
| `token-shred` | `shred`, `readlink -f` | `shred.js` ([§ 4.3](#43-how-the-installer-takes-the-file-token-take)) |
| `todo-tools` | `HOME-noWIN` | `S=${WIN_UP:-$HOME}/.claude/settings.json` and the marker under `${WIN_C:-$HOME/.config/fleet-reporter}` — the one fix the first Windows install did not need because W4 skipped (c) |
| `identity` | `HOME-noWIN` (one line, the Linux home-path roster) | unchanged: that line is the Linux-only fallback D1 § 3.1 states, guarded by `WIN_UP` today |
| `selftest`, `holder`, `restart` | `HOME-noWIN` | `selftest` folds W6: paths from `${WIN_UP:-$HOME}`, `${WIN_I:-…}`. `holder` takes `${WIN_SPOOL:-…}`, `${WIN_I:-…}`. `restart` splits: `restart · linux darwin` (stop, wait for the supervisor's next start, ancestry `--upgrade`) and `restart · win32` (stop, `flusher-start`, ancestry `--upgrade`) |
| `prereqs` | `readlink -f`, `flock`, `crontab` | `prereqs` (node ≥ 18, `bash`, `git`) on all three; `prereqs · linux` adds `flock`, `crontab`; `prereqs · darwin` adds `launchctl` and the plist's `node` comparison; `prereqs · win32` is W1's first block (roaming check, `node` receives `WIN_I` unchanged) |
| `cron-helpers` | `/proc`, `flock` | renamed `flusher-helpers`, all three: saves `stop-flusher.js`, `flusher-ancestry.js` (with a `darwin` leg reading `ps -o lstart=,ppid=,command= -p <pid>`), `flusher-start.js` ([§ 3.6](#36-starting-the-flusher-on-windows)), `owner-only.js`, `digest.js`, `shred.js` |
| `cron-write`, `cron-ancestry` | `flock`, `readlink -f`, `crontab`, `md5sum`, `HOME-noWIN` | `· linux`, unchanged but for `digest.js` in place of `md5sum`; `launchd-write · darwin`, `launchd-ancestry · darwin` ([§ 6.3](#63-supervision-the-choice-and-the-recommendation)); `flusher-check · win32` is W5's lock check |

The Windows section's other blocks become `run` anchors with a `· win32` suffix: W2(b) `token-dir · win32`
(`prep` of the config directory), W8 `uninstall-files · win32`. Their `skip — the Windows section;
install.sh runs on Linux only` reasons are false once the installer runs them, and every such anchor is
rewritten (ledger row 7).

**The replacements, and why each is a node helper and not a port of the tool** — BSD `stat` has no `-c`
and prints the mode as `%Lp`, the owner as `%Su`; there is no `shred(1)` or `flock(1)` on macOS; `readlink
-f` there is unverified from here ([§ 6.1](#61-what-macos-lacks-and-what-it-has)); macOS 14 ships
GNU-compatible `sha256sum`/`md5sum`, earlier versions unverified; two spellings of one check is the
defect (canon #5):

| Tool | Replacement |
|---|---|
| `stat -c '%a %U'`, `[ -O ]` | `node "$B/owner-only.js" check <path>…` — `acl-only-me.js` generalised: on `win32` the ACL check and `prep` it is today, on POSIX the owner-and-mode check `write-config.js` already carries in node (`INSTALL-LINUX.md:659-681`), plus `prep <dir>` = `chmod 0700` |
| `sha256sum`, `md5sum` | `node "$B/digest.js" sha256\|md5 <file>…`, `crypto.createHash`, GNU output format |
| `shred -u` | `node "$B/shred.js" <file>`: refuses a symlink, overwrites the file's length with random bytes, `fsync`, unlinks; prints `unlinked; overwrite best-effort` — the runbook already states `shred`'s overwrite is not assured on journaling file systems (`INSTALL-LINUX.md:981-983`), and snapshots and backups are named in [§ 8](#8-risks-by-name) |
| `readlink -f` | `fs.realpathSync` inside the helpers; for `node` itself, the path `command -v node` prints ([§ 6.3](#63-supervision-the-choice-and-the-recommendation) says why not its realpath on macOS) |

After the build lands, the same `awk` must print `flock`, `crontab`, `/proc` only for blocks with a `· linux`
suffix, `HOME-noWIN` only for `identity`'s one line and the `· linux` / `· darwin` blocks, and nothing
for `stat -c`, `shred`, `md5sum`, `sha256sum`, `readlink -f`, `install -d -m`. R13 and R14 hold it.

### 3.4 The runbook guard, extended

`--check-runbook` keeps `install.sh`'s three checks and adds three:

- **Platform anchors.** `<!-- install.sh: run <name> · linux -->`, `· darwin`, `· win32`, or a
  space-separated set; no suffix means every platform. The check holds the suffix set to the installer's
  table both ways, and refuses a platform the installer's table does not know.
- **No doubled backslash in any run block** — the Windows section's rule (`INSTALL-LINUX.md:2199-2201`),
  as a grep over the blocks.
- **A bash-portability lint**, which is a denylist and says so: `mapfile`, `readarray`, `${x,,}`,
  `${x^^}`, `declare -A`, `|&`, `;;&`, `coproc`, `&>>`, and `"${arr[@]}"` on an array that may be
  empty — which `bash` 3.2 reads as unbound under `set -u`. It catches what it names; **the real check
  is R14, the suite run under `/bin/bash` 3.2 on `macos-latest`.** Prose rules the guard cannot see are
  the runbook's own stated gap (`INSTALL-LINUX.md:150-153`).

The anchor prefix stays `install.sh:` so every existing anchor is valid as it is.

### 3.5 Uninstall

`--uninstall` runs Step 8's items in Step 8's order, in **one process** — hooks first, because every
tool call respawns the flusher while they are wired; the token last (`INSTALL-LINUX.md:2085-2088`;
W8 runs in one tool call for the same reason, `INSTALL-LINUX.md:2608-2610`). The hooks are removed
**structurally, always, and never by restoring a backup**: `wire-hooks.js --remove` deletes every hook
entry whose command names the installed artifact's path, sets `statusLine` back to the config's
`wrapped_statusline` when the current command is the reporter's, removes the to-do key only when
`todo-tools-set` says this install set it and it still holds `"1"`, and leaves every other byte of
meaning alone; `check-preserved.js --remove` holds that between the two files only those entries
differ. A backup written at install time is months old by uninstall time and restoring it deletes
every hook anyone added since — the state R17 plants. Then: the supervisor's definition (Linux: the
crontab block, staged and diffed as item 2; macOS: `launchctl bootout` and the plist; Windows:
nothing); stop the flusher by pid; `shred.js` on a token file left in the config directory; remove
config, spool and artifact; print `gone` per path. Revoking the token stays the operator's act on the
site, and the installer prints the prefix the config's token starts with (the prefix is not secret:
`AGENT-INSTALL.md:88`) so they can. The runbook gains the anchors `uninstall-hooks`,
`uninstall-cron · linux`, `uninstall-launchd · darwin`, `uninstall-files`, each the existing Step 8
text made a block, and W8's block becomes `uninstall-files · win32`.

### 3.6 Starting the flusher on Windows

On Windows nothing supervises the flusher and only a hook starts it (Option A). A hook fires around a
Claude Code tool call, not inside one, so **inside a single `install.js` run no flusher ever starts**:
the `arrival` block would wait its whole window and print `NOT ARRIVED`, and an upgrade's `restart`
would stop the old flusher and find no new one. The installer therefore does on Windows what a hook
does: `flusher-start.js` (saved by `flusher-helpers`) checks `flusher.lock`'s freshness exactly as
`maybeRespawnFlusher` does (`fleet-reporter.js:2972-2984`) and, finding none fresh, spawns
`node <artifact> flusher` detached, `stdio: 'ignore'`, `windowsHide`, with `COORD_CONFIG` set to the
resolver's value when it printed one. It is one start, not a supervisor: the flusher it starts lives as
the hook-started one does, and D1 § 2.3's Windows sentence stands. On Linux and macOS the installer does
**not** start the flusher: waiting for cron's or `launchd`'s start is what proves the supervisor works,
and `restart · linux darwin` keeps its wait. R8 and R12 assert the Windows start.

## 4. The token-file contract

### 4.1 Today's file, v1

`~/.config/fleet-reporter/mezzanine-reporter-token` (Windows: `%APPDATA%\fleet-reporter\`): one line,
`mzn_` + 43 characters of `[A-Za-z0-9_-]`, optional trailing `\n`, mode `0600` in a `0700` directory
the seat account owns, or the owner-only ACL on Windows (`INSTALL-LINUX.md:889-901`; the writer's
`FILE_TOKEN = /^mzn_[A-Za-z0-9_-]{43}\n?$/`, `INSTALL-LINUX.md:574`, and its checks at `:659-681`).
It is a hop the operator carries by hand. **v1 stays accepted**, so the hand delivery and the
hidden-prompt runbook route keep working; a v1 file carries no site, install id or seat id, so with
one the installer needs `--site` and `--seat-id` (and takes `--install-id` or the account name as
today, `install.sh:184-187`), and the agent asks for them.

### 4.2 The page writes the file: v2

The Seat tokens page's issue response, which alone carries the plaintext
(`SeatTokenController.php`, *SHOW-ONCE IS THE POST'S OWN 200*), gains a **Save token file** button in
`seat-token.js`. It builds the file **in the browser** from what the page already shows — a `Blob` of
type `application/octet-stream` (so no browser appends `.txt`) and an
`<a download="mezzanine-reporter-token">` — so no second request, URL, session value or log ever
carries the plaintext, which is the property the controller's docblock defends. The page keeps showing
the token, blanked on restore as now, so a Linux operator at a terminal still has the runbook's
hidden-prompt route.

The file, **LF line endings, no carriage return, no trailing whitespace**:

```
token mzn_<43 chars>
ingest_url https://<host>/api/ingest/events
install_id <slug>
seat_id <slug>
```

`ingest_url` is **rendered server-side** into a `data-ingest-url` attribute on the issue response —
`url('/api/ingest/events')` from the server's own `APP_URL` — not read from `window.location`, which
behind a proxy or an alternate hostname names an address the ingest may not answer at; it is the value
`config.json` will hold, so the installer applies no derivation to it. The `token <value>` line is the
shape the writer already parses from the issue command's stdout
(`TOKEN = /^\s*token\s+(mzn_[A-Za-z0-9_-]{43})\s*$/`, `INSTALL-LINUX.md:573`). **v2 gets its own
parser in the writer**, beside `FILE_TOKEN` rather than widening it: exactly those four keys, once each,
in any order, LF-terminated lines, `\r` anywhere refused by name, `ingest_url` an `https://` URL (D1
§ 3.5) and the two slugs matching D1 § 3.1's patterns; anything else — a fifth key, a repeated key, a
second token — is refused by name without printing the content. **The installer derives the site, the
install id and the seat id from a v2 file**, and refuses a flag that disagrees with the file rather
than preferring either.

**The hop (canon #7).** The producer of the file is the page's script; the consumer is the writer in
the runbook. Both live in this repository, so the contract is DECLARED once, here and in D1 § 3.1
([§ 11](#11-the-amendment-ledger) row 2), and CHECKED by one test that drives the blob builder's output
through the writer's v2 parser (R16): a change to either end reds it. What neither end can verify:
where the browser put the file, who else can read that directory, and whether a sync client copies it
([§ 8](#8-risks-by-name)).

### 4.3 How the installer takes the file: `token-take`

The agent passes the **path**. `--token-file <path>` must name a regular file this account owns and
not a symlink. The installer asks one question of the file as it lies: **could another principal have
read it?** — computed from effective reachability, not from the file's mode alone: on POSIX the file
is reachable by others only if its mode grants group or other read **and** every ancestor directory
grants that same class traverse (`x`); on Windows `acl-only-me.js check` already answers it from the
ACL. **The operator's ruling of 2026-10-29 is *accept with warning*** (Q4): a reachable file is
**taken, with one printed warning** that names the exposure window — how long the file lay there,
read from its `mtime`, and in which directory — lists the other local accounts on this machine that
could have read it (`/etc/passwd` entries with a uid at or above `UID_MIN` and a shell in `/etc/shells`
on Linux, `dscl . -list /Users UniqueID` at or above 501 on macOS, the ACL's principals on Windows, or
*none found*), and ends *"revoke this prefix on the Seat tokens page and issue again if you are
unsure"*. A file not reachable by others — a `0644` file in macOS's `0700` `~/Downloads`, any file on
a `0700` home, a Windows profile folder with the default ACL — is taken with no warning.

**The ruling covers a carried file too.** Step 2 (c)'s rule for the named file is *treat the token as
exposed rather than repair the mode and go on* (`INSTALL-LINUX.md:1004-1008`); the operator ruled on
wording that made no distinction between a browser's save and a carried file, and none can be made from
the file, so a carried file passed by `--token-file` that lay `0644` in a `0755` directory is taken with
the same warning. That is a loosening of a ratified rule, recorded as such in [§ 11](#11-the-amendment-ledger)
row 12, and `token-config`'s check of the named file after the take is unchanged.

The take itself: `owner-only.js prep` makes the config directory owner-only; on POSIX the file is
`rename`d where the two are on one file system, copied and `shred.js`-removed otherwise, and then
**`chmod 0600` before `token-config` runs** — a rename keeps the source's mode, and `token-config`'s
check still demands `0600` in `0700`; on Windows the file is **copied**, not renamed, into the
owner-only directory, because a copy inherits the directory's ACL where a move keeps the source's
(card#12335 measured the inherited case, `INSTALL-LINUX.md:2424-2426`), then `acl-only-me.js check`
confirms the copy and the source is removed. Then `token-config` reads the named file under its
unchanged rule, `token-shred` removes it, and `config-check` runs. A run that fails between take and
shred leaves the token in exactly one place, the owner-only config directory, which is today's
hand-delivery guarantee (`INSTALL-LINUX.md:1000-1008`). **The file is removed by default and there is
no flag to keep it** (the operator's answer to r1's Q3): the token's home is the config, and a user who
wants to re-run issues again.

### 4.4 A path that carries the token is refused

Three refusals, each before anything is written, each naming exposure where there is one:

1. **Any argv token-shaped.** `install.js` scans its whole `argv` for `/mzn_[A-Za-z0-9_-]{43}/` before
   parsing; a hit is *"an argument looks like a seat token, not a path: NOTHING RUN. That token is now in
   this shell's history and transcript: revoke its prefix on the Seat tokens page and issue another, and
   save the new one with the page's Save button"*. The token's own prefix is **not** printed back.
2. **The agent's rule, in `AGENT-INSTALL.md`:** if the user's reply holds a `mzn_` string, the agent runs
   nothing and says the same: the token has passed through the agent, which D1 P-6 and the guide forbid,
   and the remedy is revoke-and-reissue, not an install.
3. **The file's content** is validated by the writer, which never echoes it (`INSTALL-LINUX.md:659-681`),
   and Step 2's rule stands unchanged: a file the writer refuses for its type, its access or its content
   means the token is treated as exposed (`INSTALL-LINUX.md:1004-1008`).

### 4.5 What replaces `--set-token`'s hidden prompt

**File-only in the installer.** The hidden prompt needs a `/dev/tty` the agent's shell never has, which
is why a Linux install is three runs today; the first Windows install was file-only already; and one
route on three OSes is the point. The runbook's `token-prompt` block (a′) **stays**, as the by-hand
route for a human at a Linux or macOS terminal who issued the token without a browser download — a
runbook step the installer never runs, so its anchor becomes `skip`, and it writes the same v1 file
`token-take` then accepts.

### 4.6 Rotation with a token file

With a config already present, `--token-file` is a rotation (`token-rotate`), and the file decides
**which site** it rotates: a v2 file's `ingest_url` must equal exactly one configured site's
`ingest_url`, and its `install_id` and `seat_id` must equal the config's; that site is the one rotated,
and `--site-name`, if given, must name the same site. A v2 file whose `ingest_url` matches no
configured site is **refused**, naming the runbook's *Adding a site*: writing site B's token into site
A's entry would have A refuse every batch with `401` and quarantine them (D1 § 11.5), which is the
state R22 plants. A v1 file carries no site, so with one `--site-name` selects as `--set-token` does
today, `default` when unset (`install.sh:231-286`). After the write: `config-check`, then the OS's
`restart`. Revoking the old prefix on the site comes after, as the runbook says.

## 5. The user-visible flow

What the user sees. Paths and names are illustrative; the lines the installer prints are the runbook's
markers, which do not move.

**Linux**

```
User:  Install the Mezzanine reporter from https://github.com/PupFuzz/mezzanine
Agent: I'll install it from the README's Install section. One thing I need from you: on the site, open
       Admin → Seat tokens, issue a token for this seat, click "Save token file", and tell me where the
       browser saved it (usually ~/Downloads/mezzanine-reporter-token). Don't paste the token itself;
       the file is removed once the token is in the config.
User:  ~/Downloads/mezzanine-reporter-token
Agent: (runs) git clone --depth 1 https://github.com/PupFuzz/mezzanine ~/.local/src/mezzanine
       (runs) node ~/.local/src/mezzanine/fleet-reporter/install.js --token-file ~/Downloads/mezzanine-reporter-token
       … install.js: ── INSTALL-LINUX.md block `token-take` … WARNING: ~/Downloads/mezzanine-reporter-token was readable by
         other accounts on this machine for 41 s (other accounts here: none found); revoke this prefix on the Seat tokens page and
         issue again if you are unsure · taken into ~/.config/fleet-reporter (mode 600)
       … read the token from …/mezzanine-reporter-token (mode 600) · wrote …/config.json (mode 600) · token file unlinked; overwrite best-effort
       … board user <id> on https://<kanban host> · CLAUDE_CODE_ENABLE_TODO_TOOLS=1: set · installed: <crontab digest>
       … selftest rc=0 · health rc=0 · arrival rc=0
       install.js: INSTALLED: the server holds this build current and every site has accepted a batch; the desk is on the site's floor
Agent: Done. Your desk is on the floor as <install>/<seat>. Start a new Claude Code session to see tool calls appear.
```

**Windows (Git Bash)** — the same words; the paths differ and nothing else the user sees:

```
User:  C:\Users\me\Downloads\mezzanine-reporter-token
Agent: (runs) node ~/.local/src/mezzanine/fleet-reporter/install.js --token-file "C:/Users/me/Downloads/mezzanine-reporter-token"
       … `token-take` … copied into C:/Users/me/AppData/Roaming/fleet-reporter (ACL: owner-only) … token file unlinked
       … `flusher-start`: started pid 4120 · `flusher-check`: flusher.lock names pid 4120, running, renewed 2 s ago
       … selftest rc=0 · health rc=0 · arrival rc=0
       install.js: INSTALLED …
Agent: Done. On Windows nothing supervises the reporter's background process (the operator's ruling):
       it runs while a Claude Code session is open, so the desk reads offline when none is.
```

The agent converts the user's backslash path to forward slashes before passing it; `install.js` also
accepts either and never hands a backslash to a block.

**macOS** — the same words again; the agent adds one sentence before running — *"If macOS asks whether
the terminal may access your Downloads folder, allow it; or save the file in your home folder instead"*
([§ 6.4](#64-tcc-the-downloads-folder-and-the-login-kind)) — and the lines that differ:

```
       … `launchd-write`: ~/Library/LaunchAgents/com.pupfuzz.fleet-reporter.plist written; bootstrapped in gui/501
       … `launchd-ancestry`: PASS: launchctl reports pid 8812 for com.pupfuzz.fleet-reporter, and flusher.lock names pid 8812
```

**Upgrade and re-check, every OS:** *"Upgrade the Mezzanine reporter"* → `git -C ~/.local/src/mezzanine
pull --ff-only` and the same `node …/install.js` with no arguments; on a current seat it ends `ALREADY
CURRENT: nothing changed, and every check passed`. **Uninstall:** `node …/install.js --uninstall`, then the
operator revokes the prefix it prints.

**The README** gets a `## Install the reporter on an agent machine` section after *Architecture*, short
and agent-addressed: the one sentence a user says, the one file the agent reads
(`fleet-reporter/AGENT-INSTALL.md`), the one command, the same command for upgrade, `--uninstall`, and
the runbook as the reference. The paragraph at `README.md:339-346` and the architecture row's *"Linux
install runbook"* wording (`README.md:48`) are replaced by pointers to it.

## 6. macOS

No macOS machine is available to this seat; everything in this section is read from sources named
here or marked unverified, and [§ 6.5](#65-the-first-real-macos-install-what-it-must-measure) is the
list the first real install turns into measurements.

### 6.1 What macOS lacks, and what it has

Read from Apple's shipped man-page index (`https://keith.github.io/xcode-man-pages/`, read
2026-10-29) and the pages it links:

| Tool the Linux blocks use | On macOS | Source |
|---|---|---|
| `flock(1)` | **absent** — no section-1 page | the index |
| `shred(1)` | **absent** | the index |
| `stat -c` | **absent**: BSD `stat` takes `-f <format>`, mode `%Lp`, owner `%Su` | `stat.1` |
| `sha256sum`, `md5sum` | **present** as GNU-compatible wrappers (*"command-line options and an output format that match those of their similarly named GNU utilities"*), page rendered for macOS 14; the version that introduced them is not stated | `sha256sum.1` |
| `readlink -f` | **unverified**: the index lists `readlink.1` but the page did not load | — |
| `crontab(1)`, `launchctl(1)` | present | the index |
| `/proc` | **absent** on Darwin — recall, not sourced from here; the first install confirms (`[ -d /proc ]`) | — |
| `install -d -m`, `date -u +%Y%m%dT%H%M%SZ`, `cmp`, `diff -r`, `mkdir -m`, `id -un`, `cut`, `tr` | BSD forms accept the options the blocks use — recall; R14's CI leg on `macos-latest` is the check | — |
| `bash` | `/bin/bash` 3.2 — recall; R14 is the check | — |

**What actually needs `flock`:** only the crontab line (`cron-write`, `INSTALL-LINUX.md:1543`) and the
Linux ancestry check that expects it as the holder's parent (`:1522-1525`). The reporter's own
`flusher.lock` is a node `O_EXCL` file (D1 § 2.3) and needs nothing from the OS; `flock -n` exists so
cron does not start a `node` every minute that only reads the lock and exits (`INSTALL-LINUX.md:178-181`).
A supervisor that starts one instance per label needs no `flock`.

### 6.2 Paths and the reporter's code on `darwin`

Nothing to change in `fleet-reporter.js` was found. `configPath()` returns
`~/.config/fleet-reporter/config.json` for every platform but `win32` (`fleet-reporter.js:293-300`),
which is D1 § 3.1's *Linux/macOS* row; `platformName()` already admits `darwin` (`:3826`); the flusher
respawn is platform-neutral (`:2972-2984`). `rosterSite()` consults the home-path roster on `linux`
only (`:522`), so on macOS the roster is `$COORD_CONFIG` or none, as on Windows — D1 § 3.1 says so for
Windows and is silent on macOS ([§ 11](#11-the-amendment-ledger) row 4). The spool default
`~/.local/state/fleet-reporter` and the artifact `~/.local/share/fleet-reporter` are D1's Linux/macOS
values. **Reporter code change needed for darwin: none identified; `REPORTER_VERSION` does not move for
the artifact.** The runbook helpers (`stop-flusher.js`, `flusher-ancestry.js`) need a `darwin` leg
([§ 3.3](#33-the-per-platform-block-set-derived)), and they are saved into `$B`, not installed.

### 6.3 Supervision: the choice, and the recommendation

D1 § 2.3 wants a supervised start on a seat that can have one, so that *heartbeat absent* means
*offline* and not *quiet*. Three candidates, each with the mechanism it turns on:

| Option | Mechanism | What it costs |
|---|---|---|
| **A. A user `launchd` agent** (decided; for a seat used at a GUI login) | `~/Library/LaunchAgents/com.pupfuzz.fleet-reporter.plist`, owned by the user, `launchctl bootstrap gui/$(id -u) <plist>`; `ProgramArguments` = the `node` that `command -v node` prints — the stable symlink, `/opt/homebrew/bin/node` or `/usr/local/bin/node`, **not** its realpath, which on Homebrew names a `Cellar/node/<version>/` directory that goes away at the next `brew upgrade` — and the artifact's `flusher`; `RunAtLoad true`; **`StartInterval 60`**, not `KeepAlive`; `EnvironmentVariables` carries `COORD_CONFIG` when the resolver prints one — D1 § 3.1's delivery contract; stdout/err to `/dev/null` as the crontab line does. No root: Apple's launchd guide says per-user agents are owned by the user and loaded by the per-user `launchd` started *"when a user logs in"*, and *"execute only while that user is logged in"*; at logout launchd sends them `SIGTERM`, which is the flusher's clean stop (lock removed) | runs only while the user is logged in at the GUI — the `gui/<uid>` domain exists for a GUI session, and a seat operated **over SSH alone** has none (recall; Q3 asks and [§ 6.4](#64-tcc-the-downloads-folder-and-the-login-kind) says what follows); a flusher that loses the lock to a hook-started one exits 0 and is started again a minute later and exits again, one `node` start per minute while that lasts, the same cost cron pays without `flock`. `StartInterval` rather than `KeepAlive` because `KeepAlive true` relaunches a job that exited 0 after its throttle, several times a minute, for the same lock loser |
| B. The user crontab, as Linux | `* * * * *` + `@reboot` with no `flock` | Apple ships `cron` but its own `crontab(1)` says its functionality *"has been absorbed by launchd"* (recall of the page's text; unverified from here); since macOS 10.15 `cron` needs a Full Disk Access grant to touch protected folders, a per-machine click in System Settings (recall, unverified from here) — none of this install's paths is protected, so it may not fire, but the first failure mode would be silent; and the Linux `cron-ancestry` check expects `flock` and `CRON` in the chain, so it needs its own macOS variant anyway. **Not chosen**; it is what an SSH-only seat would need, if the operator's open answer says there is one |
| C. Option A as on Windows — hooks only | the ruling card#12335 took for Windows, nothing to build | the idle/offline distinction D1 § 15 row 9 names is lost on the seat; taken on Windows because Windows has no no-root supervisor the operator accepted, which is not macOS's situation |

**Decided: A** (the operator's answer to r1's Q1, 2026-10-29). A is Apple's supported no-root
mechanism, delivers the environment by a declared key rather than a crontab line's leading assignment,
and its single-instance-per-label start replaces `flock`. **The design is for a seat used at a GUI
login.** An SSH-only seat has no `gui/<uid>` domain to bootstrap into: until the operator answers the
login-kind question (being asked separately, [§ 10](#10-operator-questions)), `prereqs · darwin`
**refuses** such a seat by name — *"no gui launchd domain for this user: the reporter's macOS install
needs a GUI login; an SSH-only seat is not yet supported"* — rather than silently installing with no
supervisor. B is kept in the table as the mechanism that answer would turn on, and is not designed
further here.

**`launchd-write` is idempotent**: it stages the plist in `$B`, compares it with the installed one, and
prints `launchd agent already current: nothing written, nothing restarted` when they are byte-equal
(the marker the installer reads); when they differ it runs `launchctl bootout gui/$(id -u)/<label>`,
writes the file `0600`, and `bootstrap`s; when none is installed it writes and `bootstrap`s. The stop of
the running flusher that a changed start definition owes (D1 § 3.1's rewrite-and-restart) is the same
`stop-flusher.js` call `cron-write` makes.

**`launchd-ancestry` PASSes only when `launchctl print gui/$(id -u)/com.pupfuzz.fleet-reporter` reports
a `pid` equal to the pid `flusher.lock` names.** *The holder's parent is launchd* would be a check that
cannot fail: a hook-started flusher is detached, and an orphan is reparented to launchd (pid 1) on macOS
too, so every flusher on the seat has `ppid` 1. R11 plants a hook-started holder and sees `FAIL: the lock
holder was not started by launchd`, which is what the Linux check says about a hook's flusher
(`INSTALL-LINUX.md:1621-1623`).

**The version guard gains the `launchd` touch.** `bin/reporter-version-guard.py`'s population is the
runbook's `install` lines and the crontab entry (`docs/VERSIONING.md § The reporter's own version`); the
plist's template, which `launchd-write` writes from a heredoc, is a start definition a seat installs
just as the crontab line is, and a change to it must move `REPORTER_VERSION` and carry a ledger row. The
guard reads the heredoc under `launchd-write`'s anchor as it reads the crontab entry under `cron-write`'s,
`UPDATES.md`'s touch table gains the row `launchd` — *re-run the macOS supervisor block, because the
plist it writes changed* — and `reporter-version-guard.py`'s selftest gains the case *plist changed,
version not moved ⇒ FAIL* ([§ 11](#11-the-amendment-ledger) row 11).

What is **recall, not sourced**, and R11 must see: that a `StartInterval` job whose previous instance
still runs is skipped rather than stacked (if it stacks, the reporter's own lock still makes the extra
instance exit 0 at once, so the cost is churn, not correctness); and that `launchctl bootstrap gui/<uid>`
works in a GitHub `macos-latest` runner session, which decides whether R11 runs in CI or only on the seat.

**On Windows the ruling stands**: no supervisor; the installer runs `flusher-start` and `flusher-check`
in place of a supervisor block ([§ 3.6](#36-starting-the-flusher-on-windows)) and prints the sentence the
agent relays ([§ 5](#5-the-user-visible-flow)).

### 6.4 TCC, the Downloads folder, and the login kind

Two macOS facts shape the one question and the supervisor, both recall and both for Q3 and the first
install to settle:

- **`~/Downloads`, `~/Desktop` and `~/Documents` are privacy-protected folders.** The first time a
  terminal-launched process reads a file under one, macOS asks the user whether that terminal
  application may access the folder, once per application; a denial is `EPERM` to the process. So
  `token-take` reading `~/Downloads/mezzanine-reporter-token` from the agent's shell can prompt. The
  agent's message names the prompt and the alternative (save into the home folder), and `token-take`
  reads `EPERM` on a protected path as *"macOS refused the read: allow the terminal access to this
  folder in System Settings → Privacy, or save the file in your home folder, and run the command
  again"* rather than as a missing file. A `cron`- or `launchd`-started process never reads that folder:
  the token lives in `~/.config/fleet-reporter` by then.
- **A seat operated over SSH has no `gui/<uid>` launchd domain**, so a LaunchAgent cannot be
  bootstrapped there and would not run; the user crontab does run under `cron`, which needs no session.
  A seat whose browser is on another machine also means the token file is **carried**, not downloaded,
  and arrives by the hand-delivery route; `--token-file` names its path and [§ 4.3](#43-how-the-installer-takes-the-file-token-take)'s
  take rule applies.

The login kind is the one question still open ([§ 10](#10-operator-questions)), asked of the operator
separately. Until it is answered the installer does not guess: `prereqs · darwin` checks
`launchctl print gui/$(id -u)` and refuses an SSH-only seat by name ([§ 6.3](#63-supervision-the-choice-and-the-recommendation)).

### 6.5 The first real macOS install: what it must measure

As card#12335's record did for Windows, **the macOS seat's own agent performs the first install** (the
operator's answer to r1's Q6) from the merged build and reports, by name, each of these — none can be
measured from here:

- `/bin/bash --version` (3.2 expected) and that every block ran as written under it.
- `[ -d /proc ]` false; `stop-flusher.js` and `flusher-ancestry.js` take their `darwin` leg; a stand-in
  process named by a scratch lock is refused as *not the flusher* (the control from `INSTALL-LINUX.md:1584-1586`).
- `install -d -m 0700` creates the directory with mode 700 (BSD `install`).
- Whether reading the token file in `~/Downloads` raised the privacy prompt, and what `token-take`
  printed when it was denied once (the agent denies it deliberately on a throwaway file first, then
  allows it).
- `launchctl print gui/$(id -u)` succeeds at the GUI login (and, run once over `ssh` to the same Mac,
  fails — which is the refusal `prereqs · darwin` gives an SSH-only seat).
- The hooks fire from a **session**, not by hand: the settings command `node <abs path> hook X` resolves
  `node` under the PATH Claude Code's hooks get on macOS (a GUI-launched terminal's PATH can differ from
  the login shell's); the first tool call spools a `tool.start`.
- `launchd-write` bootstraps; `launchctl print gui/$(id -u)/com.pupfuzz.fleet-reporter` shows a pid
  equal to the lock's; after `kill -9` of the flusher the lock goes stale and a new holder appears within
  `K.LOCK_STALE_MS` + 60 s with launchctl reporting its pid (the recovery measurement Step 5 made for
  cron, `INSTALL-LINUX.md:1627-1644`); with a hook-started flusher holding the lock, the launchd start
  exits 0 each minute, the floor shows one flusher, and `launchd-ancestry` prints `FAIL`.
- A log-out / log-in: the flusher gets `SIGTERM`, removes its lock, and is started again at login;
  the desk reads `offline` between, which is the cost A names.
- Sleep/wake: after a lid-closed hour the lock's `mtime` is an hour old; the next hook or launchd start
  takes over and the old flusher, on waking, finds another owner and exits (D1 § 2.3's ownership rule);
  the server shows no `seq_collision`.
- `COORD_CONFIG` delivery: with a coordination config on the box, the heartbeat's `read_via` is
  `$COORD_CONFIG` for the launchd-started flusher.
- `selftest rc=0`, `health rc=0`, `arrival rc=0`; the server's seat shows `reporter_platform: darwin`.
- The upgrade as a sequence (U1–U8 with `launchd` in place of cron) and `--uninstall`, which Windows
  has still not run (card#12335's record).

**CI:** a `macos-latest` job in `.github/workflows/fleet-reporter.yml` runs the offline selftest checks,
`fleet-reporter.selftest.py` (its `/proc` sweep prints `SKIP` and is listed *not measured*,
`fleet-reporter.selftest.py:10013`; AT-27 case F skips without `flock`, `:4222`), and the install suite
([§ 7](#7-acceptance-tests--the-reds-the-build-must-pass)). Every job in this repository runs on
`ubuntu-latest` today — derivation: `grep -rh 'runs-on' .github/workflows/*.yml | sort -u` prints one
value — and card#12335 asked for a `windows-latest` reporter job that does not exist; the build adds both
OS jobs beside the Linux one.

## 7. Acceptance tests — the REDs the build must pass

Each row is a check that must be seen to fail once (canon #9) before it counts. Platforms: **L**inux,
**W**indows, **M**acOS; **CI** where the hermetic suite can drive it, **seat** where only a real install
can. The suite is `install.selftest.py` driving `install.js`: a throwaway `HOME` (and, on Windows,
throwaway `APPDATA`/`LOCALAPPDATA`; on macOS a stub `launchctl` on `PATH` as the stub `crontab` is
today, `install.selftest.py:32-37`), a TLS ingest stub, a stub board.

| # | RED | Where |
|---|---|---|
| R1 | an argv matching the token shape ⇒ exit 1, `NOTHING RUN`, no file written, the message names exposure and prints no token | L W M · CI |
| R2 | `--token-file` naming: a two-token file; an empty file; a file with a CR (v1 and v2); a v2 file with a fifth key, a repeated key, a non-https `ingest_url` or a slug outside D1 § 3.1 ⇒ refused by name, content never printed, no config | L W M · CI |
| R3 | a symlink, a directory, a file another account owns ⇒ refused before any read | L M · CI (W: the ACL check, as card#12335's suite) |
| R4 | a v2 file whose `ingest_url`/`install_id`/`seat_id` disagrees with a flag ⇒ refused, neither preferred | L W M · CI |
| R5 | a v1 file with no `--site`/`--seat-id` ⇒ refused, naming the two flags | L W M · CI |
| R6 | after `INSTALLED`, the token file is gone from its source path and from the config directory; the token appears in no stdout, stderr, argv or environment of any process the run spawned — the strace leg on Linux (`install.selftest.py:39`), a `node`-shim-on-PATH trace on W and M (weaker: it sees PATH execs only, and says so) | L W M · CI |
| R7 | a second run on a current seat: every owned file keeps bytes and mtime, no restart, the attempt directory removed, `ALREADY CURRENT`; `launchd-write` prints `already current` (M, stub) | L W M · CI |
| R8 | upgrade: artifact replaced first, flusher stopped second; on L/M the supervisor's start is awaited and `flusher-ancestry.js --upgrade` PASSes on the new build; on W `flusher-start` starts one and the same check PASSes, and a run with `flusher-start` disabled ends `NOT ARRIVED` (the control) | L W M · CI |
| R9 | the user's own hook entries and keys survive; `todo-tools` set/kept in the profile `WIN_UP` names on W; `board-identity` written from the stub board — today's suite sections, unchanged | L W M · CI |
| R10 | Linux: the crontab block written once, under `flock -n`, carrying `COORD_CONFIG=`; the cron thread starts the flusher; `cron-ancestry` PASS | L · CI |
| R11 | macOS: the plist is written owner-only with the `command -v node` path, `StartInterval 60`, `RunAtLoad`, `EnvironmentVariables.COORD_CONFIG`; `launchctl bootstrap` called once, `bootout`+`bootstrap` on a changed plist, nothing on an equal one, `bootout` on uninstall (stub); `launchd-ancestry` FAILs on a planted hook-started holder whose pid launchctl does not report, and PASSes when the stub reports the lock's pid; on the seat: a `kill -9` is followed by a new holder launchctl reports within `K.LOCK_STALE_MS` + 60 s, and a `StartInterval` fire while an instance runs stacks nothing | M · CI (stub) + seat |
| R12 | Windows: no supervisor block runs; `flusher-start` starts the flusher inside the run and `flusher-check` finds it; `token-take` copies, and the config and token directory pass `owner-only.js check`; `todo-tools` writes the profile's settings and the `%APPDATA%` marker | W · CI + seat |
| R13 | `--check-runbook`: each planted divergence of `install.sh`'s suite reds, plus a run block with no platform suffix that the table marks Linux-only, a `· darwin` block the runbook lacks, an unknown platform word, a doubled backslash in a block, each denylisted bash construct in a block | L W M · CI |
| R14 | every run block runs under `/bin/bash` 3.2 and BSD coreutils: the `macos-latest` job runs the suite; the `awk` of § 3.3 prints no GNU tool and no `HOME-noWIN` outside the blocks § 3.3 allows | M · CI |
| R15 | `stop-flusher.js` / `flusher-ancestry.js` `darwin` leg: a lock naming a reused pid ⇒ `NOTHING SENT`; a holder started before the artifact ⇒ `FAIL … previous build`; a stand-in process not running the artifact ⇒ refused | M · CI |
| R16 | the Seat tokens page: the Save button's blob is v2 with the server-rendered `ingest_url`, LF only, `application/octet-stream`, passes the writer's v2 parser; the plaintext appears in no URL, no session row and no log line (the existing `seat-token.browser.mjs` walk gains the click, and a unit test drives the blob builder through the parser) | server · CI |
| R17 | `--uninstall`: order hooks → supervisor → stop → files; with a **foreign hook entry added after the install**, the reporter's entries and its to-do key are gone and the foreign entry and every other key survive byte-equal; crontab digest back to Step 0's (L); plist gone and `bootout` called (M); W8's `gone` ×3 (W); a token file in the config directory removed; the prefix printed | L W M · CI |
| R18 | a profile or checkout path with a space: hook commands quoted on Windows (`wire-hooks.js`, as today); every `install` line receives the path quoted | W · CI |
| R19 | `token-take`'s verdicts: a `0644` file in a `0700` directory ⇒ taken, no warning; a `0644` file in a `0755` chain ⇒ taken with the warning naming the directory, the window from the file's `mtime`, the other accounts a stub `passwd` plants (or *none found*) and *revoke … if you are unsure*; a `0600` file in a `0755` chain ⇒ no warning (reachability, not mode, decides); after every take the named file is `0600` in a `0700` directory (POSIX) or passes the ACL check (W) before `token-config` runs, and `token-config` passes | L M (W for the last) · CI |
| R20 | `install.js --paths` equals D1 § 2.1 and § 3.1's rows for the running OS, read out of the document by the test | L W M · CI |
| R21 | the `AGENT-INSTALL.md` rule: a prompt containing `mzn_` + 43 chars is not a path — a test over the guide's own text that the sentence is present verbatim (a prose rule the guard cannot otherwise see) | CI |
| R22 | rotation: a v2 file whose `ingest_url` matches no configured site ⇒ refused, config byte-equal, naming *Adding a site*; one matching site B with `--site-name A` ⇒ refused; one matching site B alone ⇒ B's token replaced and A's untouched; a v2 whose `install_id`/`seat_id` differ from the config's ⇒ refused | L W M · CI |
| R23 | `probe` runs on a first install only: with a config present (an upgrade) it is not run and the spool holds no `INVALID_TOOL_NAME` line afterwards; the control is today's `artifact` block run over a config, which spools one | L W M · CI |
| R24 | Windows `bash` resolution: a planted `bash.exe` whose `uname -o` is not `Msys` ⇒ refused by name; Git's own `bash.exe` accepted | W · CI |
| R25 | `reporter-version-guard.py`: the plist heredoc under `launchd-write` changed with `REPORTER_VERSION` unmoved ⇒ FAIL; moved with a ledger row naming `launchd` ⇒ PASS | CI |

## 8. Risks, by name

**Learned on card#12335 (Windows), each with the check that catches it:**

- **Backslash doubling.** The Windows Bash tool turns `\\` into `\` in text it is given
  (`INSTALL-LINUX.md:2199-2201`). The installer passes every path through argv or the environment, never
  through block text, and runs blocks from files; R13 reds a doubled backslash in any block.
- **`/c/…` versus `C:/…`.** Git Bash rewrites `/c/…` arguments before `node` sees them; the Windows values
  are `cygpath -m` form (`:2192-2193`). `install.js` computes them in node, so no `cygpath`; W1's probe that
  `node` receives `WIN_I` unchanged (`:2392-2394`) stays in `prereqs · win32`.
- **ACL, not mode.** `install -d -m 0700` fails on Windows (`:2420-2423`); every mode check is
  `owner-only.js` ([§ 3.3](#33-the-per-platform-block-set-derived)), and `token-take` copies rather than
  renames so the file inherits the directory's ACL.
- **No supervisor** (Option A): the desk reads `offline` with no session; the agent's closing sentence
  says so ([§ 5](#5-the-user-visible-flow)), and the README's Install section names it.
- **The wrong `bash`.** WSL's `C:\Windows\System32\bash.exe` runs Linux; the installer takes Git's
  (`§ 3.1` item 4, R24).
- **Roaming or redirected `%APPDATA%`** copies the config and token to a profile server; W1 stops on a
  roaming profile and cannot see redirection (`:2654-2659`); unchanged.
- **The hook's environment on Windows** — whether a session-fired hook carries the settings' `env`
  block, so the flusher reads `COORD_CONFIG` — was not measured by card#12335 (D1 § 3.1); the first
  Windows install's record does not say either. Named, not closed; the heartbeat's `read_via` is the
  observable.

**macOS:** everything in § 6.1, § 6.3 and § 6.4 marked recall or unverified; `launchd`'s
single-instance behaviour; the PATH a session's hook runs with; the privacy prompt; the login kind;
sleep/wake and lock staleness; a `node` whose symlink target moves on upgrade is covered by writing the
symlink path, and `prereqs · darwin` compares the plist's `node` with `command -v node` and re-runs
`launchd-write` on a difference, as `cron-write` re-resolves `node` on Linux (`INSTALL-LINUX.md:1361`).

**Common to all three:**

- **The token file lands in a synced folder** — iCloud Drive's `~/Desktop`/`~/Documents` when Desktop
  & Documents sync is on, OneDrive's `Downloads` redirection on Windows, Dropbox — and the token leaves
  the machine before the take. `token-take` prints a warning when the path is under a known sync root
  (`~/Library/Mobile Documents`, a path containing `OneDrive`, `Dropbox`, `iCloud`), which is a
  heuristic and says so. It cannot verify a sync client's behaviour; that is named, not closed.
- **The removed file's bytes may survive**: `shred.js` says `overwrite best-effort`, because APFS and
  NTFS snapshots, Time Machine, File History, Volume Shadow Copies and a cloud client's version history
  keep a copy the unlink does not reach. The remedy for a token that must not survive is revocation on
  the site, which the uninstall and every refusal name; nothing here claims erasure.
- **The one question is still a question.** A user who issues the token on the page but closes it before
  saving cannot save: the page says *"revoke this prefix below and issue another"*
  (`index.blade.php:15`). The agent's message tells them to click Save before leaving the page.
- **The agent reads the token anyway**, by opening the file with its Read tool. `AGENT-INSTALL.md` forbids
  it in the same sentence as pasting, and the installer cannot stop it. The residual is the agent's
  conduct; R21 keeps the sentence present.
- **Three CI runners** triple the reporter workflow's wall time (`fleet-reporter.yml:60-62` records the
  measured run time per suite). The repository is public, so the minutes are not billed; the latency gate
  stays Linux-only, because P-5's budget is derived for the seats the project runs.

## 9. What this design deliberately does not do

- It does not re-implement the runbook in code ([§ 3.2](#32-why-the-runbook-stays-the-owner-of-the-commands)).
  `REPORTER_VERSION` moves for this design only where the `launchd` touch says it must.
- It does not add a Scheduled Task or any Windows supervisor: the operator's ruling of 2026-10-25
  stands; `flusher-start` is one start, as a hook's is.
- It does not make the installer ask for the site URL, install id or seat id when the file carries them,
  and does not make it guess them when it does not: a v1 file needs the flags, and the agent asks.
- It does not touch `settings.local.json`, a project's `.claude/settings.json`, or managed settings:
  the user-level file is the one the install owns (`INSTALL-LINUX.md:1337-1342`).
- It does not restore `settings.json` from a backup at uninstall, ever ([§ 3.5](#35-uninstall)).
- It does not refuse a token file other accounts could have read: the operator ruled *accept with
  warning*, and the warning is what the design owes ([§ 4.3](#43-how-the-installer-takes-the-file-token-take)).
- It does not cover an SSH-only or headless Mac: `prereqs · darwin` refuses one by name until the
  operator's open answer says whether such a seat exists ([§ 6.3](#63-supervision-the-choice-and-the-recommendation)).

## 10. Operator questions

### 10.1 Decided — the operator's answers of 2026-10-29 to r1's questions

Verbatim: *"1. A / 2. yes, both / 3. yes, by default / 4. accept with warning / 5. yes / 6. yes"*.
Each is folded where it applies:

| r1 question | Answer | Where it lands |
|---|---|---|
| Q1 — macOS supervision: (A) user `launchd` agent, (B) crontab without `flock`, (C) hooks only | **A** | [§ 6.3](#63-supervision-the-choice-and-the-recommendation); designed for a GUI-login seat, an SSH-only seat refused by name until § 10.2's question is answered |
| Q2 — the Seat tokens page gains *Save token file* and keeps showing the plaintext | **yes, both** | [§ 4.2](#42-the-page-writes-the-file-v2) |
| Q3 — the token file is removed by default once the config is verified | **yes, by default** | [§ 4.3](#43-how-the-installer-takes-the-file-token-take); `--keep-token-file` dropped (F15), the default stands |
| Q4 — a token file other accounts could have read | **accept with warning** | [§ 4.3](#43-how-the-installer-takes-the-file-token-take): the ruling was on wording that drew no line between a browser's save and a carried file, so it covers both; the warning is computed from effective reachability, names the window and the accounts, and ends *revoke and issue again if you are unsure*; `chmod 0600` / ACL reset after the move and before `token-config`; ledger row 12 records the loosening of Step 2's carried-file rule |
| Q5 — `install.sh` becomes a shim for one release | **yes** | [§ 3.2](#32-why-the-runbook-stays-the-owner-of-the-commands); removal keyed to the reporter minor after the one that ships `install.js`, both numbers in that release's `UPDATES.md` row |
| Q6 — the macOS seat's own agent performs the first real install | **yes** | [§ 6.5](#65-the-first-real-macos-install-what-it-must-measure) |

### 10.2 Open

- **How is the Mac seat used: at a GUI login with the browser on the seat, or over SSH with the
  browser elsewhere?** Being asked of the operator separately. Until answered, the design is for the
  GUI-login seat and `prereqs · darwin` refuses an SSH-only one by name ([§ 6.3](#63-supervision-the-choice-and-the-recommendation),
  [§ 6.4](#64-tcc-the-downloads-folder-and-the-login-kind)). **Recommendation if the answer is SSH:**
  the user crontab (B) for that seat, with its own `cron-ancestry` variant, and the token carried into
  the named file as on Windows; a wrong assumption costs a refusal and a re-run, not a silent seat.
  Example: a MacBook the operator sits at — GUI, A, the browser's download; a Mac mini in a rack
  reached by `ssh` — B, a carried file.
- **`COORD_CONFIG` on macOS** needs no decision: the resolver reads the settings file and the plist
  carries what it prints, which is correct whether or not the seat runs the coordination framework.

## 11. The amendment ledger

Diffs to ratified documents the build lands in the same PR as the code, each with its reason. None is
applied by this design PR except row 10; a reader who finds an owning document disagreeing with this one
before the build should trust the owning document.

| # | Document and place | Change | Why |
|---|---|---|---|
| 1 | D1 § 2.1's install-path table and § 3.1's path table | no path changes: the *Linux / macOS* rows already give macOS `~/.local/share/fleet-reporter/` and `~/.config/fleet-reporter/`. One sentence is added beside them: the installer sets the Windows section's `WIN_*` values on `win32` only, and on `darwin` the Linux/macOS rows apply unchanged | the rows cover macOS; what D1 lacks is the statement of who sets the per-OS values |
| 2 | D1 § 3.1, after the carried-file sentence | the token-file contract of § 4: v1 and v2, the four keys, LF only; the page's Save button as the producer; the take rule of § 4.3 | the hop is declared where the consumer reads D1 |
| 3 | D1 § 2.3 item 1 | *on macOS, a user `launchd` agent with `StartInterval 60` and `RunAtLoad`, which runs while the user is logged in at the GUI* (the operator's ruling of 2026-10-29); an SSH-only seat is refused until § 10.2's answer; the contract's `$COORD_CONFIG` delivery by `EnvironmentVariables` | § 2.3 names the Linux and Windows starts; macOS is a third |
| 4 | D1 § 3.1, the roster resolution paragraph | *the home path is Linux's; on Windows and macOS `$COORD_CONFIG` is the whole resolution* | `rosterSite()` already does this (`fleet-reporter.js:522`); D1 says it for Windows only |
| 5 | D1 § 15 row 9 | the macOS column: supervised, a user `launchd` agent (the operator's ruling of 2026-10-29) | the row records the per-OS decision |
| 6 | D1 § 16's install precondition | a macOS leg: *a procedure exists once this design is built; a real install is owed*, as the Windows leg reads today (`EVENT-SCHEMA.md:8557-8565`) | same shape as the Windows precondition |
| 7 | `fleet-reporter/INSTALL-LINUX.md` | title and intro: *on a Linux, Windows or macOS seat*; § Running it with `install.sh` → `install.js`, including the state-passing rule of § 3.1 item 2 and Step 0's `${B:-…}` form; the platform anchors; every `skip — the Windows section; install.sh runs on Linux only` reason rewritten, because the installer now runs those blocks; `token-prompt`'s anchor to `skip`; the `probe` block and the `artifact` block's probe line removed; the blocks § 3.3's table disposes; the macOS section (`prereqs · darwin`, `launchd-write`, `launchd-ancestry`, backup/uninstall legs), written like the Windows section: only what differs | the runbook owns the commands |
| 8 | `fleet-reporter/AGENT-INSTALL.md`, `fleet-reporter/README.md`, `README.md` | § 3.2 and § 5 | the user-facing surfaces |
| 9 | `docs/PLAN.md § 3`, the installer row | *Linux, Windows and macOS: `fleet-reporter/install.js` runs the runbook's blocks* | the work-breakdown map |
| 10 | `tools/design/README.md` | this document declared under no gate, as D4 and D5 are — applied by this PR | the verifier README's convention |
| 11 | `docs/VERSIONING.md § The reporter's own version`, `fleet-reporter/UPDATES.md`'s touch table, `bin/reporter-version-guard.py` and its selftest | the `launchd` touch: the plist heredoc under `launchd-write` joins the population; a row and a selftest case | a start definition a seat installs ships as the crontab line does |
| 12 | `fleet-reporter/INSTALL-LINUX.md` Step 2 (c)'s failure rule (`:1004-1008`), and D1 § 3.1's carried-file sentence | **the operator's ruling of 2026-10-29, *accept with warning*, loosens the ratified rule** *treat the token as exposed rather than repair the mode and go on* for a file the installer takes by path: a file other principals could have read is taken, its mode repaired to `0600` (the ACL reset on Windows), and a warning printed that names the window, the accounts and the revoke-and-reissue remedy. The rule for the named file `token-config` reads is unchanged | the ruling was given on wording covering a browser's save and a carried file alike, and the design records the loosening rather than applying it silently |

## 12. What this design does not establish

- That the macOS facts marked recall in § 6.1, § 6.3 and § 6.4 are true: `/proc` absent, BSD
  `install -d -m`, `bash` 3.2, `launchd` single-instance per label, `launchctl bootstrap` in a CI runner
  session, the absence of a `gui` domain over SSH, the privacy prompt on `~/Downloads`. § 6.5, R11 and
  R14 are where each becomes a measurement.
- That a session-fired hook carries the settings' `env` block on Windows or macOS (D1 § 3.1's open
  item); the heartbeat's `read_via` is the observable on the first real install of each.
- That the browser puts the file where the user says, that no sync client copied it, or that the
  account enumeration of § 4.3 sees every principal who could read a file (a network-mounted home is
  outside it, and says so in the printed line) ([§ 8](#8-risks-by-name)).
- That the agent does not open the file: a conduct rule, kept present by R21, enforced by nothing.

## 13. Design review r1 — disposition per finding

Round 1 on `25a7c455`: VERDICT REVISE, 1 blocker, 7 major, 11 minor. Every finding is folded as
below; none is declined.

| # | Finding | Disposition |
|---|---|---|
| F1 | BLOCKER — run blocks hard-coding `$HOME` with no `WIN_*` branch; the Windows replacements are all `skip` anchors; the block set assumed | **Fixed.** § 3.3 derives the set with the walk widened to `$HOME`-without-`WIN_` and `install -d -m`, prints what it found at `188e5fce`, and disposes every block: `WIN_*` fallbacks where the block is otherwise identical (`todo-tools`, `token-rotate`, `selftest`, `holder`), splits where the platforms differ (`backup`, `restart`, `prereqs`, supervisor blocks), the W-blocks as `· win32` run anchors, `token-prompt` to `skip`. § 2's `sed` reads the suffix |
| F2 | MAJOR — no flusher starts inside one `install.js` run on Windows | **Fixed.** § 3.6: `flusher-start.js` on `win32`, one detached start as a hook's; `restart · win32` uses it; R8 (with the disabled-start control) and R12 assert it |
| F3 | MAJOR — "each block is its own bash process" contradicts the two exporting lines | **Fixed.** § 3.1 item 2: the installer owns `B` and `SEAT_*`; Step 0's line becomes `${B:-…}`; the values block's own `env | grep '^SEAT_'` line is what the installer reads; board-identity's values from `$B/board-identity.vals`; blocks run as `bash <file>` under `$B` |
| F4 | MAJOR — `ppid` 1 cannot fail on macOS | **Fixed.** § 6.3: PASS only when `launchctl print` reports the lock's pid; R11 plants a hook-started holder |
| F5 | MAJOR — SSH-operated Mac, carried token, TCC on `~/Downloads` | **Fixed.** § 6.4 and § 10.2's open question (asked of the operator separately); the design is for the GUI-login seat, and `prereqs · darwin` refuses an SSH-only seat by name until the answer; the agent's message names the privacy prompt; § 6.5 expects it |
| F6 | MAJOR — rotation with another site's v2 file corrupts site A | **Fixed.** § 4.6: the file's `ingest_url` selects the site and must match exactly one; mismatch refused naming *Adding a site*; R22 |
| F7 | MAJOR, security — warn-and-continue loosens the carried-file rule; `0600` after rename | **Fixed as the operator ruled.** The operator answered r1's Q4 *accept with warning* on wording covering carried files too, so the design keeps acceptance and states the loosening (§ 4.3, ledger row 12) instead of refusing; the mechanics the finding asked for are all in: the warning is computed from effective reachability over the directory chain, names the window, the other accounts and the revoke-and-reissue remedy; `chmod 0600` (ACL reset on Windows, by copy-not-rename) after the move and before `token-config`; R19 |
| F8 | MAJOR — the plist outside the version guard's population | **Fixed.** § 6.3 and ledger row 11: `launchd` touch, guard reads the heredoc under `launchd-write`, ledger row, selftest case; R25 |
| F9 | MAJOR, safety — restoring an old `settings.json` deletes others' hooks | **Fixed.** § 3.5: structural removal always (`wire-hooks.js --remove`, `check-preserved.js --remove`), never a restore; § 9 says so; R17 plants a foreign edit |
| F10 | launchd-write idempotence | **Fixed.** § 6.3: compare → `already current` / `bootout`+write+`bootstrap` / write+`bootstrap`; R7, R11 |
| F11 | Cellar realpath in the plist | **Fixed.** § 6.3: the path `command -v node` prints; `prereqs · darwin` compares and rewrites; § 3.3's `readlink -f` row |
| F12 | the bash-4 grep is a denylist | **Fixed.** § 3.4 names it a lint and R14 the real check; the empty-array-under-`set -u` hazard added |
| F13 | the Linux probe spools `INVALID_TOOL_NAME` with a config present | **Fixed.** § 3.3: one guarded `probe` block for all three, first install only; the live defect in today's `artifact` block named; R23 with today's block as the control |
| F14 | the shim | **Fixed.** § 3.2: `--set-token` refused naming `--token-file`; removal in the reporter minor after the shipping one, named in its `UPDATES.md` row; out-of-repo references stated unverifiable |
| F15 | drop `--keep-token-file` | **Fixed.** § 4.3; the operator's answer to r1's Q3 (remove by default) is recorded in § 10.1 and the flag is gone |
| F16 | shred wording; snapshots and backups | **Fixed.** § 3.3's `shred.js` row prints `unlinked; overwrite best-effort`; § 8 names APFS/NTFS snapshots, Time Machine, File History, VSS, cloud versions |
| F17 | § 4.4 item 3 narrowed the ratified exposure rule | **Fixed.** § 4.4 item 3's narrowing deleted and the writer's rule kept as ratified; the one loosening the operator's Q4 ruling does make, to Step 2 (c)'s rule for a file taken by path, is a ledger row (12) and not a silent change |
| F18 | `window.location.origin`; `.txt` suffix; v2 parser | **Fixed.** § 4.2: server-rendered `data-ingest-url` from `APP_URL`, `application/octet-stream`, a separate v2 parser refusing `\r` by name; the key is `ingest_url` |
| F19 | WSL's `bash.exe` | **Fixed.** § 3.1 item 4: Git's `bash.exe` from `git --exec-path`, `uname -o` must be `Msys`; R24 |
| F20 | bare figures | **Fixed.** 173, 2,733, "ten lines", "25-minute" deleted; the commands kept |
| — | hygiene: `install.sh` cites, ledger row 7's skip reasons, § 2's `sed` | **Fixed.** `install()` `288-359`, `set_token()` `231-286`, exit `3` at `293-301`; row 7 names the rewritten reasons; § 2's `sed` reads `· platform` |

# D6 — the one-command reporter install on Linux, Windows and macOS

**Status: DESIGN, revision r1 — awaiting the operator's answers in [§ 10](#10-operator-questions).
Nothing in this document is built.** Card#12571; the macOS leg was added to the scope by the operator
on 2026-10-29 (*"We will also onboard a macos claude agent. We need a working mezzanine reporter for
claude on macos"*).

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
   ported to node, plus a platform table, with the Windows section's `WIN_*` values set by the installer
   instead of by a pasted values block.
3. **The agent asks the user one question — the path of the token file** saved from the site's Seat
   tokens page — and passes the installer that path and nothing else. The installer reads and validates
   the file, writes the config owner-only, and removes the file once the config is verified
   ([§ 4](#4-the-token-file-contract)). A path that is really the token is refused by name, and the
   token is then treated as exposed ([§ 4.4](#44-a-path-that-carries-the-token-is-refused)).
4. **The Seat tokens page gains a *Save token file* button** that writes the file client-side, with the
   site URL, install id and seat id in it, so the installer derives those three and the user is asked
   nothing else, and no token is ever copied into an editor ([§ 4.2](#42-the-page-writes-the-file-v2)).
5. **The same end state on every OS, idempotent, one command for install, re-check and upgrade**, and
   `--uninstall` for the reverse. Supervision stays per OS: the user crontab on Linux, nothing on
   Windows (Option A, the operator's ruling of 2026-10-25), and on macOS a user `launchd` agent is
   recommended ([§ 6](#6-macos)); the choice is [§ 10](#10-operator-questions) Q1.
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
  on a seat with no config (`fleet-reporter/install.sh:303-311`), the user runs `--set-token` at a
  terminal of their own because the agent's shell has no `/dev/tty` (`install.sh:239-241`,
  `AGENT-INSTALL.md:89-93`), then the agent runs the install. `AGENT-INSTALL.md` is 173 lines.
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
| board identity, to-do tools | Step 2 `board-identity`, Step 4 (c) `todo-tools` (0.5.0, `fleet-reporter/UPDATES.md`) | the same blocks as written | the same blocks as written |
| hooks in the account's `~/.claude/settings.json`, user's own entries kept | Step 4 (a) | W4 (path quoted) | Step 4 (a) |
| supervised flusher start carrying `$COORD_CONFIG` | Step 5, user crontab + `flock -n` | **none** (Option A) | [§ 6.3](#63-supervision-the-choice-and-the-recommendation) |
| selftest, health, arrival checks | Step 6, U8, Step 7 | W6, W7 | the same blocks |
| upgrade: install first, stop second, holder check | U1–U8 | W-upgrade, U6 in two tool calls | U1–U8 with the macOS supervisor |
| uninstall | Step 8, by hand | W8, one tool call | `--uninstall` on every OS ([§ 3.5](#35-uninstall)) |

The blocks the installer runs are exactly the runbook's `run` anchors, and that list is derived, not
written: `sed -n 's/^<!-- install\.sh: run \([a-z0-9-]*\) -->$/\1/p' fleet-reporter/INSTALL-LINUX.md`
prints it, and `install.sh --check-runbook` holds it to `MZI_BLOCKS` (`install.sh:37-39`) both ways.
The build adds anchors for the supervisor and uninstall blocks it brings and nothing else.

## 3. The installer

### 3.1 Shape: `fleet-reporter/install.js`

One file, no npm dependencies, Node ≥ 18 (the reporter's own floor, D1 § 2.1). It is not installed on
the seat and ships no bytes to it, so `bin/reporter-version-guard.py` reads its population unchanged
(`docs/VERSIONING.md § The reporter's own version`: the runbook's Step 1 `install` lines and the
crontab entry). What it owns is what `install.sh` owns today (`install.sh:5-13`): **the order, the
conditions under which a block runs, and the reading of each block's outcome** — plus three things
`install.sh` does not have:

1. **A platform table**, keyed on `process.platform`, that sets the values the blocks read. On
   `win32` it exports exactly the Windows values block's `WIN_UP`, `WIN_I`, `WIN_C`, `WIN_SPOOL`,
   `WIN_B_ROOT` (`INSTALL-LINUX.md:2233-2241`), computed in node from `os.homedir()`, `%APPDATA%`
   and `%LOCALAPPDATA%` in forward-slash form, so no `cygpath` call and no backslash ever enters the
   text a block runs. On `linux` and `darwin` it exports none of them, and every block takes its Linux
   path, which D1 § 2.1 and § 3.1 give macOS as well. **No block is edited for paths.**
2. **One token mode** that is part of the install run, `--token-file <path>` ([§ 4](#4-the-token-file-contract)),
   replacing the exit-3 / `--set-token` / install sequence with one invocation.
3. **A `bash` locator**: `/bin/bash` on Linux and macOS, the Git for Windows `bash` on `PATH` on
   Windows — the shell Claude Code's tool already is there (`INSTALL-LINUX.md:2172`). Each block runs as
   its own `bash -euo pipefail` process with the block as stdin, which is how the runbook already
   specifies a block (`INSTALL-LINUX.md:66-71`), with `B` and the seat values in its environment and
   `<placeholders>` filled by variable references, never pasted values (`install.sh:55-63`).

Modes, and what each runs (the Linux order is `install.sh:298-358`'s; the names are the anchors):

| Invocation | What it does |
|---|---|
| `node install.js --token-file <path> [--site <url> --seat-id <id> [--install-id <id>]] [--agent-name] [--ca-file] [--no-board]` | **first install**: `prereqs` (per OS), `backup`, `writer`, `values`, `resolver`, `identity`, `board-identity`, **`token-take`** (new, § 4.3), `token-config`, `token-shred`, `config-check`, then straight on into the install below. With a config already present it is the **rotation** (`token-rotate` for `--site-name`, `default` when unset, then `restart`), as `--set-token` is today (`install.sh:229-294`) |
| `node install.js [values]` | **install, re-check, upgrade** — the same run: `prereqs`, `backup`, `u1`/`artifact`, `resolver`, `board-identity`, `hooks-scripts`, `hooks-apply`, `todo-tools`, the OS's supervisor blocks (§ 3.3), `holder` and `restart` when owed, `config-check`, `selftest`, `health`, `arrival`, the OS's ancestry check. With no config: refuses, naming `--token-file` |
| `node install.js --uninstall` | § 3.5 |
| `node install.js --check-runbook [<runbook>]` | the guard, ported: every ```` ```bash ```` block anchored, every run block present once, every message read present in its block (`install.sh:88-116`), plus the two new checks § 3.4 names |
| `node install.js --paths` | prints the resolved paths, which R20 holds against D1 § 2.1/3.1 |

Exit codes stay `install.sh`'s (`0` installed or nothing to change · `1` refused or a check failed · `2`
installed and a check not measured); `3` goes away, because the token step is no longer a separate run.
Markers the installer reads in block output stay `MZI_MARKERS`'s set (`install.sh:40-54`) so the
runbook's messages do not move.

### 3.2 Why the runbook stays the owner of the commands

**Chosen: port the runner, keep the blocks.** The alternative the dispatch asked to weigh — one node
script that *is* the installer, with the runbook demoted to prose — was rejected on the mechanism:

- The runbook is 2,733 lines (`wc -l fleet-reporter/INSTALL-LINUX.md`) of measured steps, each carrying
  what its run showed on a real seat. Its blocks are already mostly node programs in heredocs
  (`write-config.js`, `board-identity.js`, `wire-hooks.js`, `check-preserved.js`, `coord-config.js`,
  `stop-flusher.js`, `flusher-ancestry.js`, `acl-only-me.js`), wrapped in a few lines of shell. Moving
  them into `install.js` moves bytes, not behaviour, and leaves the runbook a restatement with no guard
  (canon #16) or deletes the by-hand procedure the first Windows install relied on.
- `--check-runbook` is the thing that keeps the document and the code from drifting; it exists because
  they did (card#7336). A code-owned installer has nothing to check against.
- `bin/reporter-version-guard.py` reads the runbook's `install` lines as the shipped population
  (`bin/reporter-version-guard.py:13-23`). Moving them into code moves the guard too.
- `bash` is on all three seats: it is Claude Code's shell tool on Windows, `/bin/bash` 3.2 on macOS,
  `/bin/bash` on Linux. The blocks use no bash-4 construct — derivation:
  `grep -nE 'mapfile|readarray|\$\{[a-zA-Z_]+(,,|\^\^)|declare -A|\|&|;;&|coproc|&>>' fleet-reporter/INSTALL-LINUX.md`
  prints nothing at `188e5fce`.

**Rejected: `install.sh` plus a Windows twin plus a macOS twin.** Three copies of the order,
conditions and outcome-reading (canon #5); a PowerShell twin adds a second language with no runbook
guard, and card#12335 already showed the Linux blocks run under Git Bash with `WIN_*` set
(`INSTALL-LINUX.md:2219-2224`), so a twin would be a copy of `install.sh` with a values block pasted
in. Cost of the chosen shape against it: one `node` process per run plus one `bash` per block, which
`install.sh` already pays per block.

**What happens to `install.sh`:** it becomes a two-line shim, `exec node "$(dirname "$0")/install.js"
"$@"`, for one reporter release, so a seat whose notes name it still works, then goes. Its header's
`--set-token` and exit `3` go with the mode. `install.selftest.py` drives `install.js` instead
(§ 7).

**What happens to `AGENT-INSTALL.md`:** it shrinks to the agent's five moves (§ 5): check `node`,
clone or pull, ask the one question, run the one command, relay the last lines. The failure table stays,
shortened to the rows the new flow can reach. Its *"the user enters it at a hidden prompt"* section goes:
the token is a file on every OS.

### 3.3 Per-OS blocks: supervision and the GNU tools

The blocks are platform-neutral except where they touch a supervisor or a GNU coreutils tool. Which
blocks, derived at `188e5fce` by walking every `run` block for `stat -c`, `sha256sum`, `md5sum`,
`shred`, `readlink -f`, `flock`, `crontab` and `/proc/`:

```
awk '/^<!-- install\.sh: run /{name=$4} /^<!-- install\.sh: skip/{name=""} /^```bash/{inb=1;next} /^```/{inb=0}
     inb && name { for (t in T) if ($0 ~ T[t]) u[name]=u[name]" "t }
     BEGIN { T["stat-c"]="stat -c"; T["sha256sum"]="sha256sum"; T["md5sum"]="md5sum"; T["shred"]="shred";
             T["readlink-f"]="readlink -f"; T["flock"]="flock"; T["crontab"]="crontab"; T["/proc"]="/proc/" }
     END { for (n in u) print n ":" u[n] }' fleet-reporter/INSTALL-LINUX.md | sort
```

It prints: `artifact`, `u1` (sha256sum) · `backup` (stat -c, md5sum, crontab) · `config-check`,
`token-config`, `token-prompt`, `token-rotate` (stat -c) · `token-shred` (shred, readlink -f) ·
`prereqs` (readlink -f, flock, crontab) · `cron-helpers` (/proc, flock) · `cron-write` (flock,
readlink -f, crontab, md5sum). Every other run block already runs on Windows as written, and so runs on
macOS. The build changes those twelve this way, each a small edit inside the block it names, with the
anchor and the read messages unchanged:

| Tool | Where it goes | Why that and not a port of the tool |
|---|---|---|
| `stat -c '%a %U'` | `node "$B/owner-only.js" check <path>…` — `acl-only-me.js` generalised: on `win32` the ACL check it is today, on POSIX the owner-and-mode check `write-config.js` already carries in node (`INSTALL-LINUX.md:628-644`). The `if [ -n "${WIN_C:-}" ]` branches collapse to one call | BSD `stat` has no `-c` and prints the mode as `%Lp`, the owner as `%Su` ([§ 6.1](#61-what-macos-lacks-and-what-it-has)); two spellings of one check is the defect |
| `sha256sum`, `md5sum` | `node "$B/digest.js" sha256\|md5 <file>…`, `crypto.createHash`, GNU output format | macOS 14 ships GNU-compatible `sha256sum`/`md5sum` ([§ 6.1](#61-what-macos-lacks-and-what-it-has)); which earlier version first did is unverified, and the run block must not depend on it |
| `shred -u` | `node "$B/shred.js" <file>`: refuse a symlink, overwrite the file's length with random bytes, `fsync`, unlink — the runbook already states `shred`'s overwrite is not assured on journaling file systems (`INSTALL-LINUX.md:981-983`), so node's is the same guarantee | no `shred(1)` on macOS |
| `readlink -f` | `node -p 'require("fs").realpathSync(process.argv[1])' -- <path>`; for `node` itself, `process.execPath` | `readlink -f` on macOS is unverified from here (§ 6.1) |
| `flock`, `crontab`, `/proc` | stay, in Linux-only blocks: `prereqs-linux`, `cron-helpers`, `cron-write`, `cron-ancestry`, and the Linux legs of `stop-flusher.js` / `flusher-ancestry.js`. macOS gets `prereqs-darwin`, `launchd-write`, `launchd-ancestry` (§ 6.3) and a `darwin` leg in the two helpers that reads `ps -o lstart=,ppid=,command= -p <pid>` where Linux reads `/proc` | there is no `/proc` on Darwin, and no `flock(1)` |
| `backup`'s crontab lines | split: `backup` keeps the settings/artifact/config copies; `backup-cron` (Linux) and `backup-launchd` (macOS) copy the supervisor's definition | the Windows section already runs Step 0 *"without the crontab half"* (`INSTALL-LINUX.md:2245`) and today does it by not running the block |

The installer selects blocks by platform from one table; a block the table names for an OS and the
runbook lacks is a `--check-runbook` finding (§ 3.4).

### 3.4 The runbook guard, extended

`--check-runbook` keeps `install.sh`'s three checks and adds two:

- **Platform anchors.** A block the installer runs on one OS only carries it in its anchor:
  `<!-- install.sh: run cron-write · linux -->`, `<!-- install.sh: run launchd-write · darwin -->`;
  no suffix means every OS. The check holds the suffix set to the installer's table both ways.
- **No bash-4 construct and no doubled backslash in any run block** — the two rules the Windows section
  states in prose (`INSTALL-LINUX.md:2199-2201`) and § 3.2 derives, as a grep over the blocks. Prose
  rules the guard cannot see are the runbook's own stated gap (`INSTALL-LINUX.md:150-153`).

The anchor prefix stays `install.sh:` so every existing anchor is valid as it is.

### 3.5 Uninstall

`--uninstall` runs Step 8's items in Step 8's order, in **one process** — hooks first, because every
tool call respawns the flusher while they are wired; the token last (`INSTALL-LINUX.md:2085-2088`;
W8 runs in one tool call for the same reason, `INSTALL-LINUX.md:2608-2610`): restore `settings.json`
from the attempt directory the hooks predate, or strip the reporter's entries when it has changed
since; remove the to-do tools key only when `todo-tools-set` says this install set it; remove the
supervisor's definition (Linux: the crontab block, staged and diffed as item 2; macOS: `launchctl
bootout` and the plist; Windows: nothing); stop the flusher by pid; shred a token file left in the
config directory; remove config, spool and artifact; print `gone` per path. Revoking the token stays
the operator's act on the site, and the installer prints the prefix the config's token starts with
(the prefix is not secret: `AGENT-INSTALL.md:88`) so they can. The runbook gains the anchors
`uninstall-hooks`, `uninstall-cron · linux`, `uninstall-launchd · darwin`, `uninstall-files`, each the
existing Step 8 text made a block.

## 4. The token-file contract

### 4.1 Today's file, v1

`~/.config/fleet-reporter/mezzanine-reporter-token` (Windows: `%APPDATA%\fleet-reporter\`): one line,
`mzn_` + 43 characters of `[A-Za-z0-9_-]`, optional trailing `\n`, mode `0600` in a `0700` directory
the seat account owns, or the owner-only ACL on Windows (`INSTALL-LINUX.md:889-901`; the writer's
`FILE_TOKEN = /^mzn_[A-Za-z0-9_-]{43}\n?$/`, `INSTALL-LINUX.md:574`, and its checks at `:628-644`).
It is a hop the operator carries by hand. **v1 stays accepted**, so the hand delivery and the
hidden-prompt runbook route keep working; a v1 file carries no site, install id or seat id, so with
one the installer needs `--site` and `--seat-id` (and takes `--install-id` or the account name as
today, `install.sh:184-187`), and the agent asks for them.

### 4.2 The page writes the file: v2

The Seat tokens page's issue response, which alone carries the plaintext
(`SeatTokenController.php`, *SHOW-ONCE IS THE POST'S OWN 200*), gains a **Save token file** button in
`seat-token.js`. It builds the file **in the browser** from what the page already shows — a `Blob`
and an `<a download="mezzanine-reporter-token">` — so no second request, URL, session value or log
ever carries the plaintext, which is the property the controller's docblock defends. The page keeps
showing the token, blanked on restore as now, so a Linux operator at a terminal still has the
runbook's hidden-prompt route.

The file, **LF line endings, no carriage return, no trailing whitespace**:

```
token mzn_<43 chars>
site https://<host>
install_id <slug>
seat_id <slug>
```

The `token <value>` line is the shape the writer already parses from the issue command's stdout
(`TOKEN = /^\s*token\s+(mzn_[A-Za-z0-9_-]{43})\s*$/`, `INSTALL-LINUX.md:573`), so v2 is the issue
output's grammar with three keyed lines added. The writer's `FILE_TOKEN` widens to *v1 or v2*: v2 is
exactly those four keys, once each, in any order, with `site` an `https://` URL (D1 § 3.5) and the two
slugs matching D1 § 3.1's patterns; anything else — a fifth key, a repeated key, a CR, a second token —
is refused by name without printing the content. **The installer derives `--site`, `--install-id` and
`--seat-id` from a v2 file**, and refuses a flag that disagrees with the file rather than preferring
either. `site` is the address the operator opened the page at (`window.location.origin`), which the
installer turns into the ingest URL as `install.sh:179-182` does.

**The hop (canon #7).** The producer of the file is the page's script; the consumer is the writer in
the runbook. Both live in this repository, so the contract is DECLARED once, here and in D1 § 3.1
(§ 11 row 2), and CHECKED by one test that drives the blob builder's output through the writer's
regex (R16): a change to either end reds it. What neither end can verify: where the browser put the
file, who else can read that directory, and whether a sync client copies it (§ 8).

### 4.3 How the installer takes the file: `token-take`

The agent passes the **path**. `--token-file <path>` must name a regular file this account owns and
not a symlink; the installer **moves** it into the config directory under the named v1/v2 file —
`rename` where the two are on one file system, copy-then-shred otherwise — after `owner-only.js prep`
has made the directory owner-only. It then runs `token-config`, which reads the named file as today,
then `token-shred`, which removes it, then `config-check`. So the file's life on the seat ends in the
same run, and a run that fails between take and shred leaves the token in exactly one place, the
owner-only config directory, which is today's hand-delivery guarantee (`INSTALL-LINUX.md:1000-1008`).

**Mode of the browser's file.** A download lands in `~/Downloads` at the account's umask — `0644` on a
default Linux or macOS account, owner-only by the profile's inherited ACL on Windows. The carried-file
rule refuses anything but `0600` in `0700` because, for a file that crossed hosts, a wrong mode means
the seat cannot show it arrived unread (`INSTALL-LINUX.md:1004-1008`). A file the user just saved on
the seat itself is a different case: the exposure is to other accounts on this machine, between the
save and the take. The design **reports it and continues**: `token-take` prints the mode it found and,
when group or world could read it, one line naming that window (*"readable by other accounts on this
machine for N s: if any exist, revoke this prefix and issue again"*), then moves the file into the
owner-only directory. Refusing would hand the user a `chmod` instruction, the thing this design exists
to remove. This is a new route with its own rule, not a loosening of the carried-file check, which
`token-config` still applies to the moved file. **Q4 asks the operator to confirm it.**

`--keep-token-file` skips the shred and leaves the moved file in the config directory, for an operator
who wants to re-run; the default removes it, and the agent says so before running (Q3).

### 4.4 A path that carries the token is refused

Three refusals, each before anything is written, each naming exposure where there is one:

1. **Any argv token-shaped.** `install.js` scans its whole `argv` for `/mzn_[A-Za-z0-9_-]{43}/` before
   parsing; a hit is *"an argument looks like a seat token, not a path: NOTHING RUN. That token is now in
   this shell's history and transcript: revoke its prefix on the Seat tokens page and issue another, and
   save the new one with the page's Save button"*. The token's own prefix is **not** printed back.
2. **The agent's rule, in `AGENT-INSTALL.md`:** if the user's reply holds a `mzn_` string, the agent runs
   nothing and says the same: the token has passed through the agent, which D1 P-6 and the guide forbid,
   and the remedy is revoke-and-reissue, not an install.
3. **The file's content** is validated by the writer, which never echoes it (`:628-644`); a file that is
   not v1 or v2 is refused with the reason and the token in it is treated as exposed only when the file
   was also readable by others (§ 4.3).

### 4.5 What replaces `--set-token`'s hidden prompt

**File-only in the installer.** The hidden prompt needs a `/dev/tty` the agent's shell never has, which
is why a Linux install is three runs today; the first Windows install was file-only already; and one
route on three OSes is the point. The runbook's `token-prompt` block (a′) **stays**, as the by-hand
route for a human at a Linux or macOS terminal who issued the token without a browser download — it is
a runbook step, not an installer mode, and it writes the same v1 file `token-take` then accepts.

## 5. The user-visible flow

What the user sees. Paths and names are illustrative; the lines the installer prints are the runbook's
markers, which do not move.

**Linux**

```
User:  Install the Mezzanine reporter from https://github.com/PupFuzz/mezzanine
Agent: I'll install it from the README's Install section. One thing I need from you: on the site, open
       Admin → Seat tokens, issue a token for this seat, click "Save token file", and tell me where the
       browser saved it (usually ~/Downloads/mezzanine-reporter-token). Don't paste the token itself.
       The file is removed once the token is in the config; say so if you want it kept.
User:  ~/Downloads/mezzanine-reporter-token
Agent: (runs) git clone --depth 1 https://github.com/PupFuzz/mezzanine ~/.local/src/mezzanine
       (runs) node ~/.local/src/mezzanine/fleet-reporter/install.js --token-file ~/Downloads/mezzanine-reporter-token
       … install.js: ── INSTALL-LINUX.md block `token-take` … moved into ~/.config/fleet-reporter (mode 600)
       … read the token from …/mezzanine-reporter-token (mode 600) · wrote …/config.json (mode 600) · token file gone
       … board user 14 on https://<kanban host> · CLAUDE_CODE_ENABLE_TODO_TOOLS=1: set · installed: <crontab md5>
       … selftest rc=0 · health rc=0 · arrival rc=0
       install.js: INSTALLED: the server holds this build current and every site has accepted a batch; the desk is on the site's floor
Agent: Done. Your desk is on the floor as <install>/<seat>. Start a new Claude Code session to see tool calls appear.
```

**Windows (Git Bash)** — the same words; the paths differ and nothing else the user sees:

```
User:  C:\Users\me\Downloads\mezzanine-reporter-token
Agent: (runs) node ~/.local/src/mezzanine/fleet-reporter/install.js --token-file "C:/Users/me/Downloads/mezzanine-reporter-token"
       … `token-take` … moved into C:/Users/me/AppData/Roaming/fleet-reporter (ACL: owner-only) … token file gone
       … W5: flusher.lock names pid 4120, started_at …, running, renewed 2 s ago
       … selftest rc=0 · health rc=0 · arrival rc=0
       install.js: INSTALLED …
Agent: Done. On Windows nothing supervises the reporter's background process (the operator's ruling):
       it runs while a Claude Code session is open, so the desk reads offline when none is.
```

The agent converts the user's backslash path to forward slashes before passing it; `install.js` also
accepts either and never hands a backslash to a block.

**macOS** — the same words again; the one line that differs:

```
       … `launchd-write`: ~/Library/LaunchAgents/com.pupfuzz.fleet-reporter.plist bootstrapped in gui/501
       … `launchd-ancestry`: PASS: the lock holder's parent is launchd (pid 1)
```

**Upgrade and re-check, every OS:** *"Upgrade the Mezzanine reporter"* → `git -C ~/.local/src/mezzanine
pull --ff-only` and the same `node …/install.js` with no arguments; on a current seat it ends `ALREADY
CURRENT: nothing changed, and every check passed`. **Uninstall:** `node …/install.js --uninstall`, then the
operator revokes the prefix it prints.

**The README** gets a `## Install the reporter on an agent machine` section after *Architecture*, ten
lines, agent-addressed: the one sentence a user says, the one file the agent reads
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
| `bash` | `/bin/bash` 3.2 — recall; § 3.2's grep shows the blocks need nothing newer | — |

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
Windows and is silent on macOS (§ 11 row 4). The spool default `~/.local/state/fleet-reporter` and the
artifact `~/.local/share/fleet-reporter` are D1's Linux/macOS values. **Reporter code change needed for
darwin: none identified; `REPORTER_VERSION` does not move for this design.** The two runbook helpers
(`stop-flusher.js`, `flusher-ancestry.js`) do need a `darwin` leg (§ 3.3), and they are saved into `$B`,
not installed.

### 6.3 Supervision: the choice, and the recommendation

D1 § 2.3 wants a supervised start on a seat that can have one, so that *heartbeat absent* means
*offline* and not *quiet*. Three candidates, each with the mechanism it turns on:

| Option | Mechanism | What it costs |
|---|---|---|
| **A. A user `launchd` agent** (recommended) | `~/Library/LaunchAgents/com.pupfuzz.fleet-reporter.plist`, owned by the user, `launchctl bootstrap gui/$(id -u) <plist>`; `ProgramArguments` = the resolved `node` and the artifact's `flusher`; `RunAtLoad true`; **`StartInterval 60`**, not `KeepAlive`; `EnvironmentVariables` carries `COORD_CONFIG` when the resolver prints one — D1 § 3.1's delivery contract; stdout/err to `/dev/null` as the crontab line does. No root: Apple's launchd guide says per-user agents are owned by the user and loaded by the per-user `launchd` started *"when a user logs in"*, and *"execute only while that user is logged in"*; at logout launchd sends them `SIGTERM`, which is the flusher's clean stop (lock removed) | runs only while the user is logged in — which is when a macOS seat is a seat, since Claude Code runs in its terminal; a flusher that loses the lock to a hook-started one exits 0 and is started again a minute later and exits again, one `node` start per minute while that lasts, the same cost cron pays without `flock`. `StartInterval` rather than `KeepAlive` because `KeepAlive true` relaunches a job that exited 0 after its throttle (10 s), six times the churn, for the same lock loser |
| B. The user crontab, as Linux | `* * * * *` + `@reboot` with no `flock` | Apple ships `cron` but its own `crontab(1)` says its functionality *"has been absorbed by launchd"* (recall of the page's text; unverified from here); since macOS 10.15 `cron` needs a Full Disk Access grant to touch protected folders, a per-machine click in System Settings — none of this install's paths is protected, so it may not fire, but the first failure mode would be silent; and the Linux `cron-ancestry` check expects `flock` and `CRON` in the chain, so it needs its own macOS variant anyway |
| C. Option A as on Windows — hooks only | the ruling card#12335 took for Windows, nothing to build | the idle/offline distinction D1 § 15 row 9 names is lost on the seat; taken on Windows because Windows has no no-root supervisor the operator accepted, which is not macOS's situation |

**Recommendation: A.** It is Apple's supported no-root mechanism, it delivers the environment by a
declared key rather than a crontab line's leading assignment, its single-instance-per-label start
replaces `flock`, and `launchd-ancestry` has a crisp PASS: the lock holder's `ppid` is `1`. What is
**recall, not sourced**, and R11 must see: that a `StartInterval` job whose previous instance still runs
is skipped rather than stacked (if it stacks, the reporter's own lock still makes the extra instance
exit 0 at once, so the cost is churn, not correctness); and that `launchctl bootstrap gui/<uid>` works
in a GitHub `macos-latest` runner session, which decides whether R11 runs in CI or only on the seat.

**On Windows the ruling stands**: no supervisor; the installer runs W5's lock check in place of a
supervisor block and prints the sentence the agent relays (§ 5).

### 6.4 BSD-versus-GNU, inside the installer's own shell use

The installer runs blocks through `bash`; it runs no `sed -i`, `date -d` or `stat` of its own —
every value it computes it computes in node (`os.homedir()`, `fs.realpathSync`, `crypto`,
`process.execPath`). The blocks' GNU uses are the twelve of § 3.3, each replaced by a node helper or
moved to a Linux-only block. The sanity grep for the build: after it lands, § 3.3's `awk` must print
no block without a `· linux` suffix for `flock`, `crontab`, `/proc`, and nothing at all for `stat -c`,
`shred`, `md5sum`, `sha256sum`, `readlink -f`.

### 6.5 The first real macOS install: what it must measure

As card#12335's record did for Windows, the macOS seat's agent runs the installer from the merged
build and reports, by name, each of these — none can be measured from here:

- `/bin/bash --version` (3.2 expected) and that every block ran as written under it.
- `[ -d /proc ]` false; `stop-flusher.js` and `flusher-ancestry.js` take their `darwin` leg; a stand-in
  process named by a scratch lock is refused as *not the flusher* (the control from `INSTALL-LINUX.md:1584-1586`).
- `install -d -m 0700` creates the directory with mode 700 (BSD `install`).
- The hooks fire from a **session**, not by hand: the settings command `node <abs path> hook X` resolves
  `node` under the PATH Claude Code's hooks get on macOS (a GUI-launched terminal's PATH can differ from
  the login shell's); the first tool call spools a `tool.start`.
- `launchd-write` bootstraps; `launchctl print gui/$(id -u)/com.pupfuzz.fleet-reporter` shows a pid;
  after `kill -9` of the flusher the lock goes stale and a new holder appears within
  `K.LOCK_STALE_MS` + 60 s with `ppid` 1 (the recovery measurement Step 5 made for cron,
  `INSTALL-LINUX.md:1627-1644`); with a hook-started flusher holding the lock, the launchd start exits 0
  each minute and the floor shows one flusher.
- A log-out / log-in: the flusher gets `SIGTERM`, removes its lock, and is started again at login;
  the desk reads `offline` between, which is the cost A names.
- Sleep/wake: after a lid-closed hour the lock's `mtime` is an hour old; the next hook or launchd start
  takes over and the old flusher, on waking, finds another owner and exits (D1 § 2.3's ownership rule);
  the server shows no `seq_collision`.
- `COORD_CONFIG` delivery: with a coordination config on the box, the heartbeat's `read_via` is
  `$COORD_CONFIG` for the launchd-started flusher.
- Whether macOS asked for any permission (a TCC prompt) during the install; expected none.
- `selftest rc=0`, `health rc=0`, `arrival rc=0`; the server's seat shows `reporter_platform: darwin`.
- The upgrade as a sequence (U1–U8 with `launchd` in place of cron) and `--uninstall`, which Windows
  has still not run (card#12335's record).

**CI:** a `macos-latest` job in `.github/workflows/fleet-reporter.yml` runs the offline selftest checks,
`fleet-reporter.selftest.py` (its `/proc` sweep prints `SKIP` and is listed *not measured*,
`fleet-reporter.selftest.py:10013`; AT-27 case F skips without `flock`, `:4222`), and the install suite
(§ 7). Every job in this repository runs on `ubuntu-latest` today — derivation:
`grep -rh 'runs-on' .github/workflows/*.yml | sort -u` prints one value — and card#12335 asked for a
`windows-latest` reporter job that does not exist; the build adds both OS jobs beside the Linux one.

## 7. Acceptance tests — the REDs the build must pass

Each row is a check that must be seen to fail once (canon #9) before it counts. Platforms: **L**inux,
**W**indows, **M**acOS; **CI** where the hermetic suite can drive it, **seat** where only a real install
can. The suite is `install.selftest.py` driving `install.js`: a throwaway `HOME` (and, on Windows,
throwaway `APPDATA`/`LOCALAPPDATA`; on macOS a stub `launchctl` on `PATH` as the stub `crontab` is
today, `install.selftest.py:32-37`), a TLS ingest stub, a stub board.

| # | RED | Where |
|---|---|---|
| R1 | an argv matching the token shape ⇒ exit 1, `NOTHING RUN`, no file written, the message names exposure and prints no token | L W M · CI |
| R2 | `--token-file` naming: a two-token file; an empty file; a file with a CR; a v2 file with a fifth key, a repeated key, a non-https `site` or a slug outside D1 § 3.1 ⇒ refused by name, content never printed, no config | L W M · CI |
| R3 | a symlink, a directory, a file another account owns ⇒ refused before any read | L M · CI (W: the ACL check, as card#12335's suite) |
| R4 | a v2 file whose `site`/`install_id`/`seat_id` disagrees with a flag ⇒ refused, neither preferred | L W M · CI |
| R5 | a v1 file with no `--site`/`--seat-id` ⇒ refused, naming the two flags | L W M · CI |
| R6 | after `INSTALLED`, the token file is gone (default) or present only in the config directory with `--keep-token-file`; the token appears in no stdout, stderr, argv or environment of any process the run spawned — the strace leg on Linux (`install.selftest.py:39`), a `node`-shim-on-PATH trace on W and M (weaker: it sees PATH execs only, and says so) | L W M · CI |
| R7 | a second run on a current seat: every owned file keeps bytes and mtime, no restart, the attempt directory removed, `ALREADY CURRENT` | L W M · CI |
| R8 | upgrade: artifact replaced first, flusher stopped second, `flusher-ancestry.js --upgrade` PASS on the new build | L W M · CI |
| R9 | the user's own hook entries and keys survive; `todo-tools` set/kept; `board-identity` written from the stub board — today's suite sections, unchanged | L W M · CI |
| R10 | Linux: the crontab block written once, under `flock -n`, carrying `COORD_CONFIG=`; the cron thread starts the flusher; `cron-ancestry` PASS | L · CI |
| R11 | macOS: the plist is written owner-only with the resolved `node`, `StartInterval 60`, `RunAtLoad`, `EnvironmentVariables.COORD_CONFIG`; `launchctl bootstrap` is called once and `bootout` on uninstall (stub); on the seat: a `kill -9` is followed by a new holder with `ppid` 1 within `K.LOCK_STALE_MS` + 60 s, and a `StartInterval` fire while an instance runs stacks nothing | M · CI (stub) + seat |
| R12 | Windows: no supervisor block runs; W5's check finds the hook-started flusher; config and token directory pass `owner-only.js check` | W · CI + seat |
| R13 | `--check-runbook`: each planted divergence of `install.sh`'s suite reds, plus a run block with no platform suffix that the table marks Linux-only, a `· darwin` block the runbook lacks, a doubled backslash in a block, a `mapfile` in a block | L W M · CI |
| R14 | every run block runs under `bash` 3.2 and BSD coreutils: the `macos-latest` job runs the suite; § 3.3's awk prints no GNU tool outside a `· linux` block | M · CI |
| R15 | `stop-flusher.js` / `flusher-ancestry.js` `darwin` leg: a lock naming a reused pid ⇒ `NOTHING SENT`; a holder started before the artifact ⇒ `FAIL … previous build`; a stand-in process not running the artifact ⇒ refused | M · CI |
| R16 | the Seat tokens page: the Save button's blob is v2, LF only, passes the writer's regex; the plaintext appears in no URL, no session row and no log line (the existing `seat-token.browser.mjs` walk gains the click, and a unit test drives the blob builder through the writer's regex) | server · CI |
| R17 | `--uninstall`: order hooks → supervisor → stop → files; `settings.json` restored byte-equal; crontab md5 back to Step 0's (L); plist gone and `bootout` called (M); W8's `gone` ×3 (W); the token file shredded; the prefix printed | L W M · CI |
| R18 | a profile or checkout path with a space: hook commands quoted on Windows (`wire-hooks.js`, as today); every `install` line receives the path quoted | W · CI |
| R19 | a `0644` token file in a `0755` directory on POSIX ⇒ one warning line naming the exposure window, the file moved, the config written (if Q4 is answered as recommended; else refused) | L M · CI |
| R20 | `install.js --paths` equals D1 § 2.1 and § 3.1's rows for the running OS, read out of the document by the test | L W M · CI |
| R21 | the `AGENT-INSTALL.md` rule: a prompt containing `mzn_` + 43 chars is not a path — a test over the guide's own text that the sentence is present verbatim (a prose rule the guard cannot otherwise see) | CI |

## 8. Risks, by name

**Learned on card#12335 (Windows), each with the check that catches it:**

- **Backslash doubling.** The Windows Bash tool turns `\\` into `\` in text it is given
  (`INSTALL-LINUX.md:2199-2201`). The installer passes every path through argv or the environment, never
  through block text; R13 reds a doubled backslash in any block.
- **`/c/…` versus `C:/…`.** Git Bash rewrites `/c/…` arguments before `node` sees them; the Windows values
  are `cygpath -m` form (`:2192-2193`). `install.js` computes them in node, so no `cygpath`; W1's probe that
  `node` receives `WIN_I` unchanged (`:2392-2394`) stays in `prereqs-win32`.
- **ACL, not mode.** `install -d -m 0700` fails on Windows (`:2420-2423`); every mode check is
  `owner-only.js` (§ 3.3), which on Windows is `acl-only-me.js`'s check and `prep`.
- **No supervisor** (Option A): the desk reads `offline` with no session; the agent's closing sentence
  says so (§ 5), and the README's Install section names it.
- **Roaming or redirected `%APPDATA%`** copies the config and token to a profile server; W1 stops on a
  roaming profile and cannot see redirection (`:2654-2659`); unchanged.
- **The hook's environment on Windows** — whether a session-fired hook carries the settings' `env`
  block, so the flusher reads `COORD_CONFIG` — was not measured by card#12335 (D1 § 3.1); the first
  Windows install's record does not say either. Named, not closed; the heartbeat's `read_via` is the
  observable.

**macOS:** everything in § 6.1 marked recall or unverified; `launchd`'s single-instance behaviour
(§ 6.3); the PATH a session's hook runs with; TCC prompts; sleep/wake and lock staleness; a Homebrew
or `nvm` `node` whose path changes on a `node` upgrade and leaves the plist's `ProgramArguments` naming
a file that is gone — the `health`/`arrival` checks catch it on the next run, and `prereqs-darwin`
compares the plist's `node` with `process.execPath` and re-runs `launchd-write` on a difference, as
`cron-write` re-resolves `node` on Linux (`INSTALL-LINUX.md:1361`).

**Common to all three:**

- **The token file lands in a synced folder** — iCloud Drive's `~/Desktop`/`~/Documents` when Desktop
  & Documents sync is on, OneDrive's `Downloads` redirection on Windows, Dropbox — and the token leaves
  the machine before the take. The shred default narrows the window to the seconds between save and
  install; `token-take` prints a warning when the path is under a known sync root (`~/Library/Mobile
  Documents`, a path containing `OneDrive`, `Dropbox`, `iCloud`), which is a heuristic and says so. It
  cannot verify a sync client's behaviour; that is named, not closed.
- **The one question is still a question.** A user who issues the token on the page but closes it before
  saving cannot save: the page says *"revoke this prefix below and issue another"*
  (`index.blade.php:15`). The agent's message tells them to click Save before leaving the page.
- **The agent reads the token anyway**, by opening the file with its Read tool. `AGENT-INSTALL.md` forbids
  it in the same sentence as pasting, and the installer cannot stop it. The residual is the agent's
  conduct; R21 keeps the sentence present.
- **Three CI runners** triple the reporter workflow's wall time (two 25-minute suite runs per OS,
  `fleet-reporter.yml:60-62`). The repository is public, so the minutes are not billed; the latency
  gate stays Linux-only, because P-5's budget is derived for the seats the project runs.

## 9. What this design deliberately does not do

- It does not re-implement the runbook in code (§ 3.2) and does not change what a reporter update
  ships, so `REPORTER_VERSION` does not move for it.
- It does not add a Scheduled Task or any Windows supervisor: the operator's ruling of 2026-10-25 stands.
- It does not make the installer ask for the site URL, install id or seat id when the file carries them,
  and does not make it guess them when it does not: a v1 file needs the flags, and the agent asks.
- It does not touch `settings.local.json`, a project's `.claude/settings.json`, or managed settings:
  the user-level file is the one the install owns (`INSTALL-LINUX.md:1337-1342`).
- It does not cover macOS seats that are headless (no logged-in user): a user `launchd` agent does not
  run there, and neither would Claude Code.

## 10. Operator questions

Each with the seat's recommendation; the build waits on Q1, Q2 and Q4, and proceeds on the
recommendation for the rest unless told otherwise.

- **Q1 — macOS supervision.** (A) a user `launchd` agent, `StartInterval 60`; (B) the user crontab
  without `flock`; (C) hooks only, as Windows. **Recommend A** (§ 6.3): Apple's supported no-root
  mechanism, the environment delivered by a declared key, a crisp ancestry check, and the idle/offline
  distinction kept. Example of the cost: a Mac whose user logs out shows the desk `offline` until they
  log in again; under (C) it shows `offline` whenever no Claude Code session is open.
- **Q2 — the Seat tokens page gains a *Save token file* button** writing the v2 file client-side, and
  keeps showing the plaintext as now. **Recommend yes**, both halves: the button removes the
  copy-into-an-editor step and the carriage return with it, and costs one function in `seat-token.js`
  and one regex widening in the writer; keeping the display keeps the runbook's terminal route for an
  operator without a browser on the seat. Example: on Windows the user clicks Save, the browser writes
  `C:\Users\me\Downloads\mezzanine-reporter-token`, and that path is the whole of what they tell the agent.
- **Q3 — the token file is removed by default** once the config is verified; `--keep-token-file` keeps
  it. **Recommend yes**: it is the hand-delivery route's (d) applied automatically; the agent says so
  before running. The alternative, keep-by-default, leaves a credential in `~/Downloads` on every seat
  whose user forgets.
- **Q4 — a group- or world-readable token file is taken with a warning, not refused** (§ 4.3). **Recommend
  warn**: a refusal gives the user a `chmod` instruction, and the exposure is the seconds between the
  browser's save and the install on the user's own machine. Example: on a Linux desktop with other
  accounts, the warning names the window and tells the user to revoke and reissue if that matters.
- **Q5 — `install.sh` becomes a shim for one release, then goes.** Recommend yes; no dependency on
  it was found outside this repository (`grep -rn 'install.sh' --include=*.md .` names the runbook, the
  guide, the READMEs and the changelog), and a shim costs nothing.
- **Q6 — who performs the first real macOS install** and reports § 6.5's list. Recommend: the macOS
  seat's own agent, from the merged build, as the first Windows seat did; routed by the operator.
- **Q7 — `COORD_CONFIG` on macOS.** Will the macOS seat run the coordination framework? If so, the
  installer carries the settings' `env.COORD_CONFIG` into the plist as on Linux's crontab line; if not,
  the roster is `unchecked` and nothing else changes. Recommend: no decision needed now — the
  resolver reads the settings file and the plist carries what it prints, which is correct either way.

## 11. The amendment ledger

Diffs to ratified documents the build lands in the same PR as the code, each with its reason. None is
applied by this design PR except row 10; a reader who finds an owning document disagreeing with this one
before the build should trust the owning document.

| # | Document and place | Change | Why |
|---|---|---|---|
| 1 | D1 § 2.1's install-path table and § 3.1's path table | add `macOS` where they read *Linux / macOS* only by implication in the heading: the config directory `~/.config/fleet-reporter/` and the artifact `~/.local/share/fleet-reporter/`, as stated; no new row | the rows already cover macOS; the amendment is the sentence that says the Windows values block's `WIN_*` are set by the installer on `win32` only |
| 2 | D1 § 3.1, after the carried-file sentence | the token-file contract of § 4: v1 and v2, the four keys, LF only; the page's Save button as the producer | the hop is declared where the consumer reads D1 |
| 3 | D1 § 2.3 item 1 | *on macOS, a user `launchd` agent with `StartInterval 60` and `RunAtLoad`, which runs while the user is logged in* — if Q1 = A; the contract's `$COORD_CONFIG` delivery by `EnvironmentVariables` | § 2.3 names the Linux and Windows starts; macOS is a third |
| 4 | D1 § 3.1, the roster resolution paragraph | *the home path is Linux's; on Windows and macOS `$COORD_CONFIG` is the whole resolution* | `rosterSite()` already does this (`fleet-reporter.js:522`); D1 says it for Windows only |
| 5 | D1 § 15 row 9 | the macOS column: supervised (A) or hook-respawn only (C), per Q1 | the row records the per-OS decision |
| 6 | D1 § 16's install precondition | a macOS leg: *a procedure exists once this design is built; a real install is owed*, as the Windows leg reads today (`EVENT-SCHEMA.md:8557-8565`) | same shape as the Windows precondition |
| 7 | `fleet-reporter/INSTALL-LINUX.md` | title and intro: *on a Linux, Windows or macOS seat*; § Running it with `install.sh` → `install.js`; the platform anchors; the twelve blocks of § 3.3; the macOS section (`prereqs-darwin`, `launchd-write`, `launchd-ancestry`, backup/uninstall legs), written like the Windows section: only what differs | the runbook owns the commands |
| 8 | `fleet-reporter/AGENT-INSTALL.md`, `fleet-reporter/README.md`, `README.md` | § 3.2 and § 5 | the user-facing surfaces |
| 9 | `docs/PLAN.md § 3`, the installer row | *Linux, Windows and macOS: `fleet-reporter/install.js` runs the runbook's blocks* | the work-breakdown map |
| 10 | `tools/design/README.md` | this document declared under no gate, as D4 and D5 are — applied by this PR | the verifier README's convention |

## 12. What this design does not establish

- That the macOS facts marked recall in § 6.1 and § 6.3 are true: `/proc` absent, BSD `install -d -m`,
  `bash` 3.2, `launchd` single-instance per label, `launchctl bootstrap` in a CI runner session, TCC
  behaviour. § 6.5 and R11/R14 are where each becomes a measurement.
- That a session-fired hook carries the settings' `env` block on Windows or macOS (D1 § 3.1's open
  item); the heartbeat's `read_via` is the observable on the first real install of each.
- That the browser puts the file where the user says, or that no sync client copied it (§ 8).
- That the agent does not open the file: a conduct rule, kept present by R21, enforced by nothing.

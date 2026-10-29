# Installing `fleet-reporter` on a seat — the reference for `install.js`

[`install.js`](install.js) installs, re-checks, upgrades and uninstalls the reporter on a Linux, Windows or
macOS agent seat, with no root (card#12571; the design is
[`docs/design/REPORTER-INSTALL.md`](../docs/design/REPORTER-INSTALL.md), D6). It is one node file and the
only implementation: every step below is a function of it and a subcommand, `node install.js <step>`, so
the by-hand route and the installer are one code path, and no command on this page is a copy of a step.
[`AGENT-INSTALL.md`](AGENT-INSTALL.md) is the guide a Claude Code agent follows to run it. This page says,
per step, what `install.js` does on each OS, why, what it prints, and what each exit means.

[`docs/design/EVENT-SCHEMA.md`](../docs/design/EVENT-SCHEMA.md) (D1) is the contract and this page restates
none of it: the config keys are § 3.1, the hook set § 6.0, the install directory § 2.1, the flusher's two
starts § 2.3.

**A seat that runs the reporter applies every reporter update** ([README § Updating a seat](README.md#updating-a-seat--every-update-is-required)),
with [Upgrading an existing seat](#upgrading-an-existing-seat). What each version brings is its row in
[`UPDATES.md`](UPDATES.md). New functionality ships enabled, with no per-seat opt-in.

> **This page was `INSTALL-LINUX.md`** until card#12571, which replaced the runbook's bash blocks with
> `install.js`; the old name is a redirect stub, because the crontab line every Linux seat carries names it.
> The measured history below keeps the runbook's step names (Step 5, U6) where it quotes what a step printed.

## The command

| Invocation | What it does, in order |
|---|---|
| `node install.js --token-file <path> [--site <url> --seat-id <id> [--install-id <id>]] [--agent-name <n>] [--ca-file <path>] [--no-board]` | the first install: `prereqs`, `values` (from the file, or the flags for a v1 file), `backup`, `resolver`, `identity`, `board-identity`, `token-take`, `token-config`, `config-check`, then the install below. **With a config present it is the [rotation](#rotating-a-sites-token)** |
| `node install.js [--site <url>] [--seat-id <id>] [--install-id <id>] [--no-board]` | re-check and upgrade: `prereqs`, `backup`, `artifact`, `resolver`, `board-identity`, `hooks`, `todo-tools`, `supervisor`, `holder` and `restart` when owed, `config-check`, `selftest`, `health`, `arrival`, `ancestry` when the run changed anything. With no config it refuses, naming `--token-file`; a flag naming another seat or site than the config's refuses before anything runs |
| `node install.js add-site --token-file <path> [--site <url>] [--ca-file <path>] [--site-name <n>]` · `remove-site <name>` | [Adding a site](#adding-a-site) and [removing one](#removing-one-site) |
| `node install.js statusline [--wrap <command>]` · `set-descriptors full\|paths\|none` | [the statusLine wrap](#the-statusline-wrap) and [the `descriptors` key](#descriptors) |
| `node install.js issue-file --out <path> [--prompt]` | [a token file for a seat with no browser download](#a-token-file-without-a-browser-issue-file) |
| `node install.js <step>` | that step alone ([By hand](#by-hand)) |
| `node install.js --uninstall` | [Uninstall](#uninstall) |
| `node install.js --paths` | the platform table for this OS, as JSON |
| `node install.js --render-shipping` | the start definitions and hook commands for every platform, from one JSON object on stdin — the version guard's ([§ The version guard](#what-the-version-guard-reads)) |

**Exit codes.** `0`: installed and every check passed, or nothing needed changing. `1`: refused, or a step or
a check failed; the last `install.js:` line says which. `2`: installed, and a check could not be measured (a
site that did not answer, or no batch within the arrival wait).

**Every argument is scanned for the token shape before anything is parsed.** An argument holding
`mzn_` and 43 characters of `[A-Za-z0-9_-]` refuses with *"an argument looks like a seat token, not a path:
NOTHING RUN"*, and the token is treated as exposed: it is in that shell's history and the agent's transcript.

**A run that changes nothing writes nothing**: the artifact step prints `NO-OP`, the board step `board
identity: unchanged`, the hooks step `already wired: nothing applied`, the to-do step `…: unchanged`, the
supervisor step `crontab already current` or `launchd agent already current`, so `settings.json`, the
crontab or plist, the artifact and the config keep their bytes and mtimes and the flusher is not restarted.
The checks still run, and the run removes the attempt directory it made. It ends `ALREADY CURRENT`.

**Every step stops the run at its first failure, and writes nothing after it.** A step refuses by raising,
and nothing catches a refusal but the outermost handler, which prints it and exits. Under an agent's shell
tool, which runs a command as `eval '<command>' && …`, the exit code is the node process's own, so the
card#12334 failure — a bash subshell block that carried on past a failed `crontab -l` and installed a
crontab holding the reporter's block alone — has no layer to recur in. `fleet-reporter.selftest.py` § 24
holds that property against a planted carry-on.

**Every line `install.js` prints passes a filter that replaces a token-shaped string with `<redacted>`**,
its children's output included (the reporter's `selftest`, a `crontab` error). Nothing prints a token on
purpose; the filter is the boundary.

## What it produces, per OS

| | Linux | Windows | macOS |
|---|---|---|---|
| artifact + fixtures | `~/.local/share/fleet-reporter/`, `0700`/`0600` | `%LOCALAPPDATA%\fleet-reporter\`, owner-only ACL | as Linux |
| config, multi-site form, `descriptors` `"full"` | `~/.config/fleet-reporter/config.json`, `0600` in `0700` | `%APPDATA%\fleet-reporter\config.json`, ACL | as Linux |
| spool | `~/.local/state/fleet-reporter` | `%LOCALAPPDATA%\fleet-reporter-state` | as Linux |
| attempt directories | `~/.local/state/fleet-reporter-install/<UTC stamp>/` | `%LOCALAPPDATA%\fleet-reporter-install\<stamp>\` | as Linux |
| hooks | in `~/.claude/settings.json`, after the user's entries | in the profile's `.claude\settings.json`, path quoted | as Linux |
| supervised start | the user crontab ([§ Linux](#linux-the-user-crontab)) | none (Option A); one start by the installer ([§ Windows](#windows-no-supervisor)) | a user `launchd` agent ([§ macOS](#macos-a-user-launchd-agent)) |

**The platform table is these rows**, and `node install.js --paths` prints it for the OS it runs on; the
install suite holds it to D1 § 2.1 and § 3.1, read out of D1 (R-all-1). The profile is `os.homedir()` on
every OS: Git Bash's `$HOME` on Windows can be another directory, and nothing reads it.

Owner-only on POSIX is mode `0700`/`0600`. On Windows it is an ACL, set and checked by the ACL functions
`install.js` carries (card#12335's `acl-only-me.js`): **owner-only** means the owner is this account or
Administrators, and every access rule, explicit or inherited, allows this account, SYSTEM or Administrators
and no one else — SYSTEM and Administrators are what root is to a Linux `0600`. They name SIDs only, never
account names, which are localised, and this account's own SID as `<me>`. PowerShell runs by argument array
with each path in its environment, and the profile's `PSModulePath` is dropped for it, because PowerShell 5.1
given a PowerShell 7 module path can fail to load its own modules.

## The token file

### The two forms

The site's *Seat tokens* page saves the token as a file, `mezzanine-reporter-token`; that is **v2**:

```
token mzn_<43 chars>
ingest_url https://<host>/api/ingest/events
install_id <slug>
seat_id <slug>
```

Exactly those four keys, once each, in any order, one space between key and value, LF line endings and no
trailing whitespace; `ingest_url` an `https://` URL of at most 256 B with no userinfo, ending
`/api/ingest/events`; the slugs by D1 § 3.1. **v1** is the bare token on one line, with an optional line feed
(what `issue-file` writes, and what the runbook's hand delivery carried). One parser, `parseTokenFile`, reads
both — the pre-read of `--token-file`, the config write, the rotation and `add-site` all use it — and refuses
anything else **by name**: a line number or a key, never the content. A v2 file gives the installer the seat's
identity and site, and a flag that disagrees with it is refused; a v1 file needs `--site` and `--seat-id`, and
`--install-id` defaults to this account's name as a slug.

### The take rule

`--token-file <path>` must name a regular file this account owns, not a symlink; that is checked before any
read. Then:

- **Who else could have read it** is computed per principal over the file and its directory chain: on POSIX,
  for group and for other, the file's read bit and every ancestor's traverse bit (a group member traverses
  through either bit, so the computation errs toward the warning); on Windows, the file's foreign ACEs.
- **A file another principal could have read is taken, with one warning** (the operator's ruling of
  2026-10-29, *accept with warning*, for a saved and a copied file alike), naming the directory, the window
  from the file's `birthtime` when it is later than the `mtime`, else its `ctime` — never its `mtime`, which a
  copy keeps; and on macOS a copy that keeps an older `mtime` moves the file's birth time back to it (APFS,
  measured on `macos-latest`), so a birth time at or before the `mtime` is read as that clamp — the principals that could read it (the local accounts in its group, and other accounts, from
  `/etc/group` and `/etc/passwd`), and *revoke this prefix on the Seat tokens page and issue again if you are
  unsure*. A file nobody else could reach is taken with no warning. ⚠ The account enumeration sees local
  files only: a network-mounted home's other readers are outside it. A path under a known sync root
  (iCloud, OneDrive, Dropbox, Google Drive) gets a second warning, which says it is a heuristic.
- **The take**: the config directory is made owner-only; when the path already is
  `<config dir>/mezzanine-reporter-token`, nothing moves; otherwise on POSIX the file is renamed in (copied
  across a filesystem boundary) and set `0600`, and on Windows it is copied into a new file, so it inherits the directory's
  ACL, checked, and the source removed. A token file already at the named path from an earlier run refuses
  a second `--token-file`: run with no `--token-file` to use it, or delete it.
- **The removal**: once the config holding it is written and checked, the file is overwritten with zeros,
  synced and unlinked, and the step prints `token file unlinked; overwrite best-effort` — APFS and NTFS
  snapshots, Time Machine, File History and cloud version history keep what an unlink does not reach, and
  revocation is the remedy for any doubt. There is no flag to keep the file (the operator's ruling, Q3).
- A run that fails between the take and the removal leaves the token in one place, the owner-only config
  directory, and the next run, with no `--token-file`, uses it from there.

⚠ **This loosens the runbook's Step 2 (c) rule** (*treat the token as exposed rather than repair the mode
and go on*) for a file the installer takes by path: such a file is taken, its mode repaired, and the
warning printed (D6 § 11 row 12, the operator's ruling). **A file whose CONTENT is refused** — the wrong
shape, a carriage return, a fifth key — is still treated as exposed: revoke its prefix, issue another.

On macOS, `~/Downloads`, `~/Desktop` and `~/Documents` are privacy-protected, so a terminal reading a file
there prompts once; a denial is `EPERM`, which the installer reads as *macOS refused the read: allow the
terminal access to this folder, or put the file in your home folder* (recall, not measured: the first macOS
install is the measurement).

### A token file without a browser: `issue-file`

For a seat with no browser download, `issue-file --out <path>` writes a **v1** file, `0600`, opened `wx`,
one line, and prints the prefix alone:

- **From the issue command**, on the Mezzanine host:
  `php artisan mezzanine:ingest-token:issue <install_id> <seat_id> --by=<who> | node install.js issue-file --out <path>`.
  The input must hold **exactly one** line matching `^\s*token\s+(mzn_[A-Za-z0-9_-]{43})\s*$`; every other
  line is echoed with every token-shaped substring `<redacted>`, so the prefix line stays on screen. Zero
  token lines refuse, and **two refuse too, which is new with `install.js`**: the runbook's writer took the
  last of several. A stdin that is a terminal is refused (*pipe the issue command in, or use --prompt*), so a
  token is never typed into an echoing terminal — measured on POSIX; on Windows under mintty a terminal can
  read as a pipe, which is unmeasured.
- **At a hidden prompt**, `--prompt`: a bare token (the v1 form) read from `/dev/tty` with echo off, the
  terminal's mode restored on every exit path — success, refusal, `SIGINT`, a failure mid-read. Linux and
  macOS only: Windows has no `/dev/tty`, and the flag refuses there by name. With no terminal it refuses.

The file is then carried to the seat, by a copy that keeps the bytes, and given to `--token-file`.

## The steps

### `prereqs`

`node` 18 or later (D1 § 2.1), named with its path. **Linux**: `flock` and `crontab` on `PATH`, each named, or
the step refuses. **macOS**: `launchctl`, and **a `gui/<uid>` launchd domain** — over `ssh`, or on a headless
login, there is none, and the step refuses by name: *no gui launchd domain for this user: the reporter's macOS
install supports a GUI login with Claude Code in a Terminal there; an SSH-only seat is not supported* (the
operator's answer, *"(a)"*). **Windows**: the profile must not roam — `%APPDATA%`, the config's home, is
copied to a profile server at logoff on a roaming profile, the token with it — read from
`Win32_UserProfile.RoamingConfigured`; a redirected `%APPDATA%` (folder redirection to a share) is not
detected. Every refusal here comes before anything is written.

### `backup` and the attempt directory

A new owner-only directory per invocation, `<state>/fleet-reporter-install/<UTC stamp>/` (a suffix when two
runs fall in one second), holding copies of `settings.json` (created as `{}` first when the account has none,
which is how Claude Code reads a missing file), the crontab (Linux, through [the read
predicate](#linux-the-user-crontab): a crontab this run cannot read stops it here) or the plist (macOS), the
artifact with its fixtures, and the config. The config backup holds the token, so it is owner-only, and
`--uninstall` removes it. A run that changes nothing removes its own directory.

### `artifact`

The checkout's `fleet-reporter.js` and `fixtures/hooks/*.json` — the list `install.js` names in its marked
`artifact` region — compared with the installed ones byte for byte. Equal: `NO-OP: this seat already runs
this checkout's artifact and fixtures`. Different: each file is written through the reporter's own
`atomicWrite` (a unique temp file beside the target, renamed over it), fixtures first and the script last,
`0600` in `0700` directories (on Windows the directory gets the owner-only ACL first and the files inherit
it), a fixture the checkout no longer ships is removed, and the step prints the script's `sha256` and
`fixtures-match`. The fixtures ship with the script because `selftest` reads them on the seat, and the hook
set is derived from their names (D1 § 2.1).

### `resolver`

`$COORD_CONFIG` as the seat's sessions see it: `env.COORD_CONFIG` of `~/.claude/settings.json`, then of
`~/.claude/settings.local.json`, the last that sets it winning — Claude Code's settings precedence puts
project-local `settings.local.json` over `settings.json` (<https://code.claude.com/docs/en/settings>,
*Settings precedence*), and the seat's sessions run with `$HOME` as the project. Empty when none sets it,
and § 3.1 then says to deliver nothing. The value goes on the crontab line, into the plist and into the
Windows start's environment, because a supervised start inherits nothing from the harness.

### `identity`

With `--agent-name`, the name must be a member of the roster the reporter reads — `$COORD_CONFIG`, and on
Linux the home path `~/.config/coord/coordination.config.json` (D1 § 3.1; on Windows and macOS
`$COORD_CONFIG` is the whole resolution) — or the step refuses: `disagreed` would join the desk to no post.
Without it the seat is *undeclared*, a supported state.

### `board-identity` (card#12447)

The operator ruled that linking an agent to its board user happens automatically at setup, so the step asks
the board *who am I* with the seat's own kanban credential and writes the answer into the config; the seat
declares it on every heartbeat, and the site's board poll checks it before it maps the desk
(`docs/design/BOARD-TASK.md § 4.4`). The API base is `kanban.base_url` of the coordination config the
resolver found (else the home path), joined to `api/v3` with one `/`; the credential is the store's
(`$COORD_CREDENTIALS`, else `~/.config/coord/credentials.ini`) `[kanban] api_token_file` pointer — never the
kanban read variable a seat exports into its sessions, which the board refuses on this endpoint. It prints
no token, pointer or response body. It runs on every install and upgrade:

| It finds | It prints | The config |
|---|---|---|
| a `200` from `GET /users/current.json` with an integer `data.id` | `board user <id> on <scheme://host>`, then `: board identity: unchanged`, `: written to …` or `: the config writer takes it` | gains or keeps `board_user_id` and `board_api_base` |
| no coordination config, no `kanban.base_url`, no store, or a store read whole that names no pointer | `undeclared: this seat has no board credential (<why>)` | both keys removed when present |
| anything else — unreachable, a non-`200`, no integer `data.id`, an unreadable store | `kept: <why> — …: board identity: unchanged` | unchanged: an outage does not undeclare a seat |

`--no-board` declares nothing, as the second row. A rewrite goes through [the config
writer](#token-take-token-config-and-the-config-writer), and the flusher reads the config only when it starts, so a
rewrite owes the [restart](#holder-and-restart).

### `token-take`, `token-config` and the config writer

`token-take` is [the take rule](#the-take-rule). `token-config` reads the named file through the parser and
writes the config with **the config writer**, `writeConfig` — the runbook's `write-config.js` as a function.
A fresh config is D1 § 3.1's multi-site form with one site named `default`: `install_id`, `seat_id`,
`spool_dir`, `wrapped_statusline` (the seat's current `statusLine.command`, recorded for [the
wrap](#the-statusline-wrap)), `protocol_agent_name`, `descriptors` `"full"`, `enabled`, the board pair, and
`sites: [{ name, ingest_url, token, ca_file, proxy_url }]`, `ca_file` from `--ca-file` (an absolute path, or
`null`), `proxy_url` `null` (a hand edit, because a proxy URL can carry a password). It refuses a config that
already exists. Its other modes — `add-site`, `set-token`, `remove-site`, `set-descriptors`, the board pair
and `wrapped_statusline` — read the config, lift a single-site file written before card#12180 into `sites`
under `default`, change one thing, and write the result; `add-site` and `set-token` change `sites` and nothing
else and refuse a seat key passed beside them. **Every result is validated with the checkout's own reporter**
(`parseConfig`, the rules `loadConfig` applies) **before it reaches the disk** — the build about to be
installed, never the artifact already on the seat, which an upgrade is replacing — and is then written by the
reporter's `atomicWrite` with `fsync`: a temp file beside the config, renamed into place, so a refused or
failed write leaves the config as it was (a file-size limit below the config's length leaves it
byte-identical; AT-28 Case P drives that, and a planted in-place writer that truncates it). It prints the site
names and nothing of any value. `atomicWrite` returns `false` on any failure and never throws; every caller
checks it, and it opens its temp file `wx` only on the `fsync` path, the config's.

### `config-check`

The config directory's and file's modes (the ACL check on Windows), then every key, a site's token shown only
as `<well-formed>` or `<MALFORMED>` and a URL carrying a user or password with that part as `<userinfo>`, then
the reporter's own validation. **`harness_label` is left unset, on purpose.** D1 § 6.1 wants
`claude-code/<version>`, and the reporter can only read it from this key; Claude Code updates itself, so a
version written once stops being true at the next update and the desk would show a wrong version as a fact.
Unset, the field is an honest `null` and the `harness_label_unset` counter records the gap, raising no badge.

### `descriptors`

`node install.js set-descriptors full|paths|none` rewrites the one key through the writer, its mode kept. Every
hook reads the config when it fires, so the next tool call uses the new value and nothing restarts:

| Value | What the floor shows for a tool call |
|---|---|
| `"full"`, or the key absent | the sanitized label: `Bash: composer test`, `Read: ~/…/docs/PLAN.md`, and a subagent's title |
| `"paths"` | the file path for `Read`, `Write`, `Edit` and `Glob`; the tool name alone for everything else, and no subagent title |
| `"none"` | the tool name alone, for every tool, and no subagent title |

Under `"paths"` and `"none"` the seat also sends no console link (D1 § 6.3). Any other value is refused before
anything is written.

### `hooks`

**The hook set is derived, not listed**: one hook per name in the installed artifact's `fixtures/hooks/*.json`,
one file per hook D1 § 6.0 subscribes (§ 2.1), so a hook added to § 6.0 arrives with its fixture and the next
run wires it. A hook whose fixture payloads all carry `tool_name` gets matcher `"*"`, every other hook `""`.
Each entry goes to the **end** of that hook's array; nothing else is removed, reordered or rewritten.

**The command is pinned per OS**: `node <abs path> hook <Hook>` on POSIX; on Windows
`node "C:/Users/<u>/AppData/Local/fleet-reporter/fleet-reporter.js" hook <Hook>` — forward slashes,
double-quoted, so a profile folder with a space cannot split it and the JSON needs no escaping; that is what
the first Windows seat's `settings.json` holds. **An existing entry is recognised by parsing, never by string
equality**: the command is split into `node`, the quoted-or-bare path and `hook <Hook>`, and the path compared
resolved, and lower-cased on Windows. So a seat wired by the runbook reads `already wired: nothing applied`,
an entry in the backslash form is recognised and rewritten to the canonical form, a second entry for one hook
is removed, and the file is written only when an entry changed.

**The preserved-keys check runs before the write**: with the reporter's own entries taken out of both, the file
before and the file after are equal — every top-level key other than `hooks`, and every existing entry, in
place — and every reporter command left is in the canonical form; anything else is `NOT PRESERVED` and
nothing is written. The write is `atomicWrite` onto `realpath`'s answer, its mode kept, so a `settings.json`
that is a symlink stays one and its target gets the bytes.

What was measured when the hooks were first wired on the sandbox seat (card#9368, 2026-09-13): the derived set
was D1 § 6.0's subscribed set, `Notification PermissionDenied(*) PermissionRequest(*) PostCompact
PostToolUse(*) PostToolUseFailure(*) PreCompact PreToolUse(*) SessionEnd SessionStart Stop StopFailure
SubagentStart SubagentStop UserPromptSubmit`, and the seat's `tool.start` and `tool.end` events have arrived
since.

### `todo-tools` (card#12447)

**The operator's ruling, verbatim (2026-10-27): *"this should be checked and set as part of the mezzanine
reporter installation."*** The desk's thought bubble shows the agent's in-progress to-do when no board card
answers (D1 § 6.14's `todo.progress`), and the harness provides its task-tracking tools by default only on some
models; on every other model *"Claude Code leaves the tools out unless you opt in"*, and the opt-in is
**`CLAUDE_CODE_ENABLE_TODO_TOOLS=1`** (the `env-vars` reference, read 2026-10-27: *"Set to `1` to get the
task-tracking tools on every model … Requires Claude Code v2.1.233 or later"*). The step reads the key in
`~/.claude/settings.json`'s `env`: absent is `set` (to `"1"`, recorded in `<config dir>/todo-tools-set`, so the
uninstall knows the key was this install's), `"1"` is `already set: unchanged`, any other value is `kept … :
unchanged` — an operator who set `0` chose to. An `env` that is present and not an object is refused by name.
The same preserved-keys check runs: only that key may be added, so a step that touched anything else, or
overwrote the user's value, is refused. ⚠ It cannot see the other settings files: a managed entry, a project's
`.claude/settings.json` or a `.claude/settings.local.json` `env` block overrides the user-level value in that
project. ⚠ A session started before the step keeps the tool set it started with. ⚠ **Not measured, and owed:**
that a settings file's `env` block alone provides the tools; until it is, a seat on a current model whose desk
never shows a to-do title is the observable.

### The statusLine wrap

`node install.js statusline [--wrap <command>]` points `statusLine` at the reporter, which samples the context
gauge (D1 § 6.11) and passes the prior command's output through. The prior command is `--wrap`'s value, or the
config's `wrapped_statusline`; the step refuses unless the live `statusLine` is the reporter already or that
prior command, so the config and the live file cannot disagree. Nothing restarts. **It is optional, and refused
on Windows**, where nothing measured it (card#12335's W4). What it costs, measured on the sandbox host on
2026-09-13 under a load average of 9 to 11 on 4 cores:

| Invocation | Wall time, 10 runs |
|---|---|
| `hook PreToolUse` on the vendored fixture | 247–297 ms, rc 0 every run, 0 B on stdout |
| the current statusLine alone (`context-sensor.sh`) | 42–51 ms |
| `statusline` wrapping that same command | 309–505 ms, rc 0, byte-identical output (`ctx 42%`) |

Claude Code runs a hook event's commands in parallel, so a tool call waits for its slowest hook; that cost the
seat already pays. **The statusLine is the cost the wrap adds**: the wrapped command's file is written later,
and under rapid renders Claude Code cancels a status-line script, so the coord framework's context sensor can
lag.

### `supervisor`

#### Linux: the user crontab

D1 § 2.3 needs the flusher to run whenever the seat does, and gives it two starts: a supervised start, and a
respawn from every hook that finds the lock stale. The supervised start carries the load: an idle seat fires no
hooks, so without a supervisor its heartbeat stops and the desk renders `offline` while the agent is only quiet.
**A `systemd --user` unit cannot supervise on this project's seats**: without lingering (`loginctl show-user
<u> | grep Linger`) the user manager starts at the first login and stops at the last logout, and the seats run
on Virtualmin sub-server accounts with no sudo and no lingering, by operator ruling (`docs/PLAN.md § 5`). **The
user crontab has none of those limits**: it runs as the seat account, needs no root, fires `@reboot` whether or
not anyone logs in, and survives logout; `bin/supervision.sh` supervises the server's own daemons the same way.

The step writes one marked block, **byte-identical to the one `install.sh` wrote**, so such a seat reads
`crontab already current`:

```
# BEGIN fleet-reporter-flusher
# D1 § 2.3 supervised start, no root: fleet-reporter/INSTALL-LINUX.md in PupFuzz/mezzanine. flock -n makes each start a no-op while one runs.
* * * * * [COORD_CONFIG=<resolved> ]flock -n ~/.local/state/fleet-reporter-flusher.flock <node> ~/.local/share/fleet-reporter/fleet-reporter.js flusher >/dev/null 2>&1
@reboot <the same line>
# END fleet-reporter-flusher
```

(paths expanded; the comment line names the old file on purpose, and the stub at that name points here). `node`
is the real path of the `node` on `PATH`, because cron's `PATH` is not the login shell's; `COORD_CONFIG=` is on
the line itself when the resolver finds one (D1 § 3.1's delivery contract); output goes to `/dev/null` because
the flusher's diagnostics are its own redacted log; the `flock` file sits beside the spool, not in it, because D1
§ 11.1 owns the spool's layout. A path holding whitespace or `%` is refused, because cron turns `%` into a
newline. The step replaces an existing block and never appends a second one, refuses `BEGIN`/`END` markers that
do not pair, writes nothing when the result is byte-equal, and when it does write it stops the running flusher,
whose environment is the old one, so the next start runs with the new values. The block sits outside
`bin/supervision.sh`'s `# BEGIN/END mezzanine-supervision` block, which it never edits, and holds no `artisan`,
so that script's refusal does not fire on it.

**The read predicate is card#12334's.** `crontab -l` exiting `1` with stderr matching `^no crontab for ` is an
empty crontab; any other non-zero exit, or a `1` with other text, is a read failure and the step refuses and
never writes. `spawnSync` reports status `1` for both, so the stderr test is the whole distinction: a read of the
status alone would install a crontab holding the reporter's block and nothing else, which on the sandbox would
delete `bin/supervision.sh`'s block. The backup and the uninstall read through the same predicate.
`fleet-reporter.selftest.py` § 24 holds it, against a planted status-only read.

**Two locks, and they are not redundant.** `flock -n` stops cron stacking a new `node` on its own running copy
every minute; the reporter's own `flusher.lock` (D1 § 2.3) makes the flusher exclusive across every start,
cron's and the hooks'. A copy that loses that lock exits 0 at once.

**How long an unclean death costs, derived.** The flusher touches `flusher.lock` at the start of every pass and
before every request (`renewLock`), passes run `K.FLUSH_MS` apart, and a start treats the lock as held until it
is `K.LOCK_STALE_MS` old. A flusher killed uncleanly leaves the lock, so no start takes over until
`K.LOCK_STALE_MS` after its last touch; then the next cron minute, or a sooner hook, wins. A clean stop removes
the lock. **Measured on the first install (card#9368)**: a `SIGKILL` at 19:37:56, with the lock last touched at
19:37:52 and no hooks wired, was followed by cron's 19:38 and 19:39 starts each logging `another flusher owns
the lock; exiting`, and the 19:40 start, the first after the lock went stale, took over; its parents were
`node` ← `flock -n …` ← `/bin/sh -c COORD_CONFIG=… flock -n …` ← `CRON`.

#### macOS: a user launchd agent

`~/Library/LaunchAgents/com.pupfuzz.fleet-reporter.plist`, `0600`: `ProgramArguments` the path `command -v node`
gives (the stable symlink, not a Homebrew `Cellar` realpath, which moves on upgrade), the artifact and
`flusher`; `RunAtLoad`; `StartInterval 60`, not `KeepAlive`, which relaunches a lock loser every throttle
interval; `EnvironmentVariables.COORD_CONFIG` when the resolver finds one; stdout and stderr to `/dev/null` (the
operator's ruling, Q1). **The step reads the loaded state first**, with `launchctl print
gui/<uid>/com.pupfuzz.fleet-reporter`, and does the one thing that state needs: not loaded — write the plist
if it differs, `bootstrap`; loaded with the plist on disk equal to the staged one — nothing (`launchd agent
already current`); loaded with a differing plist — `bootout`, write, `bootstrap`. ⚠ A plist rewritten by hand
while loaded reads as current until it differs from the staged one. A per-user agent runs while the user is
logged in and gets `SIGTERM` at logout (Apple, *Creating Launch Daemons and Agents*); the rest of what this rests
on is recall — a `StartInterval` job whose previous instance still runs is skipped, not stacked (if it stacked,
the lock makes the extra exit 0) — and the first macOS install measures it.

#### Windows: no supervisor

**Nothing supervises the flusher on Windows** (Option A, the operator's ruling of 2026-10-25, card#12335): no
Scheduled Task, no cron analogue. The reporter's own hooks are the flusher's start — every hook that finds no
fresh lock spawns one (`maybeRespawnFlusher`, D1 § 2.3) — so once the flusher stops, at logoff or when the
session that started it ends (unmeasured), the seat reads `offline` until a session's next hook starts another.
A hook fires around a tool call, not inside one, so inside one `install.js` run no flusher would start, and the
step therefore spawns `node <artifact> flusher` **unconditionally**, with the reporter's own spawn options
(`detached`, `stdio: 'ignore'`, `windowsHide`, `unref`, D1 P-7) and `COORD_CONFIG` from the resolver; the
flusher's own `acquireLock` makes a loser exit 0, so the installer carries no copy of the respawn's lock logic.
It then waits, bounded by the artifact's `K.LOCK_STALE_MS`, for a lock naming a live flusher of this artifact (the stale-lock test below), and prints `flusher
started pid N`, `flusher already running pid N`, or `NO FLUSHER` and exits 1.

### `holder` and `restart`

`holder` asks, read-only, whether the RUNNING flusher runs the installed build: `PASS: the lock holder runs the
artifact installed at …`, or `FAIL: … before the artifact was installed …: it runs the previous build`, which owes
the restart, or `no live flusher holds the lock`, which owes nothing (its next start runs the installed build).
The holder's start time is the lock's own `started_at`, which the flusher writes from its own millisecond clock
as it takes the lock, read only after the pid is confirmed to run this artifact's `flusher`, on every OS; no
`ps lstart` is parsed. The restart is owed when the holder runs the
previous build or the board step rewrote the config.

**A lock names a flusher only when its pid runs `<artifact> flusher`** (`/proc/<pid>/cmdline` on Linux;
`ps -o command= -p <pid>` under `LC_ALL=C` on macOS; on Windows one `Get-CimInstance Win32_Process` query, the
process started no later than the lock's `started_at`, the command line compared as Windows paths and never
printed). One test answers that for the holder check, the stop, the restart's wait, the Windows start and the
ancestry report. A lock that fails it is **stale** — its flusher died, a reboot say, and its pid, if alive, is now
another process's — and names no flusher: nothing is signalled, the step says `… the lock is stale: no flusher
to stop, nothing signalled`, and it goes on, as the reporter's own `acquireLock` takes such a lock over once it is
`K.LOCK_STALE_MS` old. (Before card#12571's review, the stop alone refused that lock, which left an uninstall
unable to finish.)

`restart` stops the flusher **by pid, with that test**: it sends `SIGTERM` (on Windows the termination is immediate, and D1 § 2.3 absorbs it: state is saved by temp file
and rename) and waits a bound derived from the artifact's constants, `K.FLUSH_MS + K.REQUEST_MS × (1 + 2 ×
laneDrain's round cap)`, or ends `NOT STOPPED`. Then the OS's start: cron's next minute (or a sooner hook) on
Linux, waited for up to 70 s; `launchctl kickstart -k gui/<uid>/com.pupfuzz.fleet-reporter` on macOS; the Windows
start above. A holder that took the lock in that window running the previous build — a start that began before
the artifact was replaced and had not written its lock when the stop looked — is stopped again; the restart ends
with `holder`'s `PASS`, or refuses.

**Why the order is artifact first, then the stop** (D1 § 3.1, card#12180). A hook starts a flusher whenever it
finds no live one, and it runs the path the hook was given, so stopping first lets a hook that fires before the
install start the **old** artifact, which then runs until stopped again. Installing first leaves only the seconds
between the two, in which a hook already runs the new artifact beside the old flusher; a per-site count a hook
takes then is folded past by the old flusher and lost, uncounted, and nothing else is. The runbook read the
holder's start from `/proc`, which reads a second or two early — `/proc/stat`'s `btime` is whole seconds and the
wall clock drifts from the boot clock (0.9 s and 2.1 s on the sandbox) — so a holder started within two seconds of
the install read as older; on `ubuntu-latest` that made a no-op run restart a flusher cron had started a second
after the install, which is why `install.js` reads the lock's `started_at` instead (card#12571; `/proc/uptime`,
the other boot clock, is virtualized under a container and was 53 s off on the sandbox host). **Measured on the sandbox seat** (2026-10-23, attempt
`20261023T015956Z-upgrade-12180`): run before the stop, the check printed `FAIL: the lock holder started
2026-10-22T02:29:59.050Z, before the artifact was installed at 2026-10-23T02:00:54.912Z`; the stop printed
`flusher pid 901 exited 5 s after SIGTERM`; cron's 02:01 start took the lock and the check printed `PASS`. The new
flusher adopted the old `state.json` (cursors carried, a new `seq_epoch`, shown once as `epoch_reset`), the old
epoch's last event was seq 203284 at 02:00:50 and the new epoch's first heartbeat seq 1 at 02:01:01, and no
`seq_gap` followed.

### The checks

**`selftest`** runs the installed reporter's `selftest` with `COORD_CONFIG` as the flusher has it, and prints
its report and `selftest rc=`. The command probes each site's `ingest_url` with that site's `ca_file`, through its
`proxy_url` when it sets one, and reports one block per site in `sites` (D1 § 6.14):

- **`rc=0`**: every check passed.
- **`rc=1`**: a check failed; `checks` names it, `sites` the site, `detail` why. A `tls_verify` fail with an empty
  `forbidden_spellings_present` is a failed TLS handshake, usually a `ca_file` that does not trust the
  certificate. A `token_accepted` fail is a site that answered `401`: the token does not resolve there
  (mistyped, another site's, revoked) — [rotate it](#rotating-a-sites-token). ⚠ A token issued for another
  seat's pair passes this check and is refused on the first batch, which `arrival` shows. `config_readable`
  failing names each rule the config breaks; the reporter never falls back to the system trust store for an
  unreadable `ca_file` (D1 § 3.5). `protocol_agent_name_in_roster` failing is a declared name the roster does not
  hold (`detail.…read_via` says which roster was read); `rt_name_ok` failing is a `roundtable_agent_name` other
  than the coordination config's `cross_project.identity`.
- **`rc=2`**: nothing failed and something is `not_measured`: a site that did not answer, or answered without its
  accepted set. Run again once it answers.

**`health`** asks each site's `GET /api/ingest/health` with that site's token in the header and nowhere else, and
compares `current_reporter_version` with the installed `REPORTER_VERSION`: `installed <v>, server current <v>:
current` and `health rc=0`; `NOT CURRENT` (`rc=1`): an installed build older than the server's is behind it and
keeps the `update_required` badge — pull and re-run — and a newer one means the server's operator deploys; `NOT
MEASURED` (`rc=2`): no version read (`HTTP 401` is a token the site does not resolve; a site with a `proxy_url` is
not asked, because this check sends nothing through a proxy). The badge clears at the first folded event of a
batch the new build sent, about a minute after the restart; a counter-raised badge such as `epoch_reset` stays its
24 h window.

**`arrival`** waits up to 150 s — long enough for cron's next minute after a stop — for every site's entry in the
flusher's `state.json` to count an accepted batch (`batches_ok`, D1 § 9.3): `<site>: <n> batch(es) accepted:
events arrive` and `arrival rc=0`; `a batch was REFUSED` (`rc=1`), whose `REJECTED.<site>.txt` carries the status
(a `401`, or a `403 identity_mismatch` naming the pair the token was issued for); `NOT ARRIVED` (`rc=2`): no
flusher ran, or the site did not answer.

**`ancestry`**, after a run that changed anything, says who started the lock holder — **informational**, reported
and never judged, because a hook's flusher holds the same lock and heartbeats as well. Linux: the holder's process
chain, and `PASS` when its parent is `flock` running this artifact with `cron` among its ancestors; a `FAIL` there
means cron's line is unverified, not broken (a hook usually wins the race after a stop). macOS: `PASS` when
`launchctl print` reports a `pid` equal to `flusher.lock`'s — a hook-started flusher is reparented to pid 1 too, so
"parent is launchd" could not fail. Windows: the holder is live, its lock fresh, started after the artifact.

## Upgrading an existing seat

Pull the checkout (`git -C ~/.local/src/mezzanine pull --ff-only`, or a fresh clone at the commit the update's
announcement names) and run `node install.js` from it with no arguments. The run is the order above — artifact
first, then the stop, then the OS's start, then the holder check — and it applies every touch an
[`UPDATES.md`](UPDATES.md) row can name except `config`, whose row names its own change. A seat several versions
behind applies the union of the rows newer than the one it runs; one installed before `0.2.0` reads `0.1.0`. The
upgrade's evidence is the run's own lines: `PASS: the lock holder runs the artifact installed at …`, `selftest
rc=0`, and each site's `installed <v>, server current <v>: current`. Reply to the announcement with those
(`UPDATES.md` § Announcing an update).

**The two layouts that exist upgrade with no migration**, because both are D1's paths:

- **A Linux seat installed by `install.sh`**: artifact, config, spool, attempt directories, the `todo-tools-set`
  marker and the crontab block are where and in the forms `install.js` writes. The first run compares and installs
  the artifact, reads `crontab already current` and `already wired: nothing applied`, and ends `INSTALLED` or
  `ALREADY CURRENT` as the artifact decides. The old attempt directories stay until `--uninstall`.
- **The first Windows seat (card#12335's hand-run layout)**: `%LOCALAPPDATA%\fleet-reporter`,
  `%APPDATA%\fleet-reporter\config.json`, `%LOCALAPPDATA%\fleet-reporter-state`, a `settings.json` in the quoted
  forward-slash form. The first run compares and installs, finds every hook wired by the parsed comparison and
  leaves `settings.json` byte-equal, re-checks the ACLs and starts the flusher.

**`install.sh` is a shim for one release** (the operator's ruling, Q5): it runs `install.js` with its arguments,
and refuses `--set-token`, naming `--token-file`, because that mode's meaning changed. It ships with reporter
`0.6.0` and is removed in `0.7.0`.

## Rotating a site's token

With a config present, `--token-file` rotates, in D1 § 3.3's order with the refusable step first: the user
**issues** the new token on the site and saves its file; `node install.js --token-file <path>` **writes** it; the
run **restarts** the flusher, because a running one keeps presenting the token it started with; and only then the
user **revokes** the old prefix — issuing revokes nothing, so both resolve until then, and a revoked token is a
`401` that stops the site's sending. A v2 file names its site: its `ingest_url` and each configured site's are
reduced by `siteKey` — scheme and host lower-cased, a trailing `/` dropped, the ingest path appended to a bare
origin, a default port dropped — and must match exactly one, with the file's `install_id` and `seat_id` equal to
the config's; `--site-name`, if given, must name that site. **A mismatch is refused with a listing** of the file's
identity and every configured site, and the pointer to `add-site`, because writing site B's token into site A's
entry has A refuse every batch and quarantine them (D1 § 11.5). A v1 file rotates `--site-name`, `default` when
unset. The same route recovers a stopped site: a `401` (the token does not resolve there) or a `403
identity_mismatch` (issued for another pair) in `REJECTED.<site>.txt`; the site keeps every event and resends the
request it was refused first.

## Adding a site

A second Mezzanine site (D1 § 3.1, *Sites*), in the order in which a refusal fails safe: the user issues a token on
the new site **for the seat's existing pair** and saves its file — a pair mistyped there is a `403
identity_mismatch` on the first batch, which `selftest` cannot see; then `node install.js add-site --token-file
<path>` takes the file as the main invocation does, requires a v2 file's identity to equal the config's and its
`ingest_url` to match no configured site, requires `--site` for a v1 file, sets `--ca-file` on the new site, names
it `--site-name` (else from its host name), writes it with the writer's `add-site` mode (which refuses a name or a
site key the config already has), checks the config, removes the file and restarts the flusher. Then run
`node install.js` to see the new site accept a batch.

The new site receives the spool's whole history, up to 8 days, paced under that site's event limit (D1 § 11.5): a
full spool takes about 3.4 hours, during which its floor shows the desk *catching up*; the sites already reporting
see nothing of it — each has its own cursor, requests and backoff. **Measured on the sandbox seat** (2026-10-23,
the runbook's writer, against a stub second site): the stub received its first batch at 02:03:48 and was current at
02:06:05, 15,162 events under one `seq_epoch`; over the same minutes the first site's heartbeats arrived 60 s apart,
contiguous, its token and URL unchanged.

## Removing one site

`node install.js remove-site <name>` rewrites the config without it through the writer (it refuses the last site:
uninstall instead) and restarts the flusher; the next start drops the site's `state.json` entry (D1 § 3.1 rule 4),
and that site sees the seat go `stale`, then `offline`, until its operator retires the desk there. Revoke the
site's token after the restart.

## Uninstall

`node install.js --uninstall`, one process, in this order:

1. **The revoke prefixes are read from the config and printed first**, one line per configured site — *revoke
   prefix `<mzn_xxxxxxxx>` on `<host>`'s Seat tokens page* — before anything is removed, so a run that fails at a
   later step has still shown them; and the crontab is read (Linux), so a crontab it cannot read refuses before
   anything is removed. The prefix is not secret. Revoking is the operator's act; to take the desk off the floor
   too, the site's operator runs `php artisan mezzanine:retire --seat=<install_id>/<seat_id> --by=<operator>
   --reason=<why>` (FLEET-STATE § 4.10).
2. **The hooks, removed structurally**: every entry whose command parses as the reporter's for this artifact, the
   `statusLine` deleted when it is the reporter's and the config's `wrapped_statusline` is `null`, or set back to
   it when it is not, and the to-do key removed only when `todo-tools-set` says this install set it and it still
   holds `"1"`; every other byte of meaning kept, by the same preserved-keys check. **Never a restore from a
   backup**: a backup is months old by then, and restoring it deletes every hook anyone added since.
3. **The config directory, whole** — before the flusher is stopped, so a hook in flight finds no config and exits
   instead of starting a flusher. The `todo-tools-set` marker and any token file go with it.
4. **The supervisor**: the crontab block removed (a crontab holding nothing else is removed with `crontab -r`) and
   the `flock` file beside the spool; on macOS `bootout`, then the plist deleted; nothing on Windows.
5. **The flusher stopped by pid**, then the spool and the artifact removed.
6. **Every file under `fleet-reporter-install/` whose content carries the token shape** removed from every attempt
   directory — config backups and token files by that test, not by name, so a copy under another name goes too;
   this run's own backups of `settings.json` and the crontab or plist stay, so the operator can see what it undid.

## By hand

Every step is `node install.js <step>`: `prereqs`, `backup`, `artifact`, `resolver`, `identity`, `board-identity`,
`token-take --token-file <path>`, `token-config`, `config-check`, `hooks`, `todo-tools`, `supervisor`, `holder` (exit
1 when a restart is owed), `restart`, `selftest`, `health`, `arrival`, `ancestry`. Each prints the same lines the
installer reads. There is no bash copy of a step: a reader who cannot run node has no reporter to install.

**A run that seems stuck**: set `FLEET_REPORTER_INSTALL_TRACE=1` for it, and every step and every PowerShell or
`icacls` call it makes is reported on stderr with the milliseconds since the run began and the path it ran on
(never content). That is how card#12571 found a Windows test environment in which each PowerShell start took 23 s.

## What the version guard reads

`bin/reporter-version-guard.py` holds a PR that changes what an update ships to a `REPORTER_VERSION` bump and an
`UPDATES.md` row. Its population is `install.js`'s `artifact` region (the files) and `--render-shipping`'s output for
every platform, diffed between the merge base and the head (the start definitions and the hook commands); its
docstring owns the rules. The render's input is one JSON object on stdin whose keys are append-only: unknown keys
are ignored, and removing one takes two PRs.

## Windows: what card#12335 learned

A Windows agent seat is Claude Code on Windows, whose shell tool is Git Bash. `install.js` passes paths only through
node and argv, so Git Bash's `/c/…` rewriting, a values block re-exported in every tool call, and two backslashes
turned into one by the tool never reach a path. Owner-only is the ACL, never a mode: `install -d -m 0700` fails on
Windows outright (*cannot change permissions … Permission denied*, every directory tried on the seat), while a file
written into an owner-only directory inherits its rules and passes the check — which is why the token file is copied
in, not renamed. Whether a hook Claude Code runs on Windows carries the settings' `env` block, and so
`COORD_CONFIG`, is unmeasured; the heartbeat's `read_via` says which roster the flusher read. **The first real
install** (card#12335's record, 2026-10-29: reporter `0.4.0`, `win32`, the desk on the floor) was by the runbook's
Windows section, by hand; `install.js`'s Windows legs are measured by the `windows-latest` install suite in CI and by
the next real install.

## macOS: what is sourced and what is recall

No macOS machine was available to card#12571. With the installer in node, what `install.js` spawns on macOS is
`launchctl`, `ps` and the reporter. Sourced: no `flock(1)` and no `shred(1)` on macOS — no longer load-bearing,
nothing here runs either (Apple's man-page index, <https://keith.github.io/xcode-man-pages/>, read 2026-10-29);
per-user agents run while the user is logged in, are user-owned in `~/Library/LaunchAgents` and get `SIGTERM` at
logout (Apple, *Creating Launch Daemons and Agents*). Recall, measured by the `macos-latest` suite and the first
install: `ps -o command= -p <pid>`; `StartInterval` skipping a still-running instance; `launchctl bootstrap
gui/<uid>` in a runner session; no `gui/<uid>` domain over SSH; the privacy prompt on `~/Downloads`. `rosterSite()`
reads the home-path roster on Linux only, so on macOS the roster is `$COORD_CONFIG` or none (D1 § 3.1). **The first
real macOS install reports**, by name: the login-kind check over `ssh` (refused) and at the Mac (passes); the privacy
prompt and a deliberate denial's message; hooks firing from a session with `node` resolving under the hooks' `PATH`;
`launchctl` reporting the lock's pid; recovery after `kill -9` within `K.LOCK_STALE_MS` + 60 s; a log-out and log-in;
sleep and wake with no `seq_collision`; `read_via` `$COORD_CONFIG`; `selftest`, `health`, `arrival` rc 0 with
`reporter_platform: darwin`; the upgrade and `--uninstall`.

## What this install does not give you, by name

- **No supervisor on Windows** (Option A): a Windows seat with no Claude Code session running reads `offline`.
- **No Windows install on a roaming profile**, and a folder-redirected `%APPDATA%` is not detected.
- **No macOS install over SSH or on a headless login**: refused by name.
- **No statusLine wrap on Windows**, and none anywhere unless asked for: no context gauge until then.
- **No restore of `settings.json` from a backup, ever.** The uninstall removes the reporter's entries structurally.
- **No edit of `settings.local.json`, a project's `.claude/settings.json` or managed settings.**
- **`harness_label` stays unset** ([`config-check`](#config-check) says why).
- **`roundtable_agent_name` is a hand edit** of the config (D1 § 3.1): set it to `cross_project.identity` of the
  coordination config `$COORD_CONFIG` names, then run `node install.js restart`. Only a `checked` name joins the
  desk to the fleet's cross-install posts.
- **A seat's console link, subagents across a `/clear`, the to-do bubble and the multi-site lanes** each arrive only
  with the reporter build that carries them; [`UPDATES.md`](UPDATES.md)'s rows say which, and an upgrade's restart
  is what puts a running seat on it.

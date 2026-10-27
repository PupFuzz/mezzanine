# Installing `fleet-reporter` on a Linux seat, without root

This runbook is how a Linux agent seat starts reporting to a Mezzanine server, and it owns every
command that does it. [`install.sh`](install.sh), beside it, runs those commands for you: it reads each
block it needs out of this file and runs it unchanged
([Running it with `install.sh`](#running-it-with-installsh), card#7336), and
[`AGENT-INSTALL.md`](AGENT-INSTALL.md) is the guide a Claude Code agent follows to run it. The runbook
was written by performing it on the sandbox host on 2026-09-13 (card#9368), for the seat `mezzanine` /
`mezzanine-solo`, and the seat has reported since.

**What is live on that seat, and what is not:**

- **Live:** the artifact, the config, the crontab supervision ([Step 5](#step-5--supervise-the-flusher-from-the-user-crontab)),
  and the hooks. The hooks were wired into the live `~/.claude/settings.json` with procedure (a) of
  [Step 4](#step-4--wire-the-hooks-and-optionally-the-statusline), hooks only, with the operator's approval.
- **Not applied, by operator decision:** the statusLine wrap, procedure (b) of Step 4. It is deferred until
  desks are visible, so the seat's status line is still its own.
- **Not run:** the destructive half of [Step 8](#step-8--uninstall-and-roll-back), because it would
  take down the seat this runbook exists to connect.

The first install ran every command below as it stood then. Card#9368's review round 2 then rewrote
some of them: staging moved out of the shared `/tmp`, Step 5 now replaces its block, and it restarts
the flusher and checks who started it. The rewritten Step 4 and Step 5 were run against **copies**,
with a stubbed `crontab`, and each step says what that run showed. The only other differences from
the seat's own run are the checkout path, written `<checkout>`, and the attempt directory, written `$B`.

[`docs/design/EVENT-SCHEMA.md`](../docs/design/EVENT-SCHEMA.md) (D1) is the contract, and this runbook
restates none of it. The config keys are § 3.1, the hook set is § 6.0, the install directory is § 2.1,
and the flusher's two starts are § 2.3.

**A seat that runs the reporter applies every reporter update**, with
[Upgrading an existing seat](#upgrading-an-existing-seat). An update is the artifact plus any new hook
fixtures, hook wiring, config keys or crontab line it brings, and what each version brings is its row in
[`UPDATES.md`](UPDATES.md). New functionality ships enabled, with no per-seat opt-in.

## The values to substitute

| What | On the sandbox seat | What constrains it |
|---|---|---|
| seat account, home | `mezzanine`, `/home/mezzanine` | no root and no sudo anywhere; every path below sits under this home |
| `install_id` | `mezzanine` | D1 § 3.1's slug pattern |
| `seat_id` | `mezzanine-solo` | D1 § 3.1's slug pattern. The token is bound to the pair (§ 3.3), so a second seat is a second token |
| `protocol_agent_name` | `mezzanine` | D1 § 3.1: the name must be a member of the roster at `$COORD_CONFIG`, or the check is `disagreed` and the name resolves to no desk. This seat's roster holds `mezzanine` and not `mezzanine-solo`, which is why the name is not the `seat_id` ([Step 2](#step-2--issue-the-seat-token-into-the-config-on-this-host-or-through-a-carried-file)) |
| `roundtable_agent_name` (optional) | the value of `cross_project.identity` in the file `$COORD_CONFIG` names — read it there, never copied from this page | D1 § 3.1's *`roundtable_agent_name`* (card#12239): equal to that value ⇒ `checked`, different ⇒ `disagreed`, no `cross_project` block on the box ⇒ `unchecked`. Only a `checked` name joins this desk to the fleet's cross-install coordination posts on the floor. Add the key to the config by hand after Step 2 and restart the flusher (Step 5); a config without it keeps working and joins no such post |
| `$COORD_CONFIG` | `/home/mezzanine/.config/coord/coordination.config.json`, read from `~/.claude/settings.local.json` | resolved by Step 2's `coord-config.js`, never typed. Claude Code's settings precedence puts project-local `settings.local.json` over `settings.json` (<https://code.claude.com/docs/en/settings>, *Settings precedence*). The seat's sessions run with `$HOME` as the project, so the two files are `~/.claude/settings.local.json` and `~/.claude/settings.json`. An empty result means none is set, and § 3.1 then says to deliver nothing |
| `node` | `/usr/bin/node` | `readlink -f "$(command -v node)"`, resolved when Step 5 runs. Cron's `PATH` is not the login shell's, so the crontab names `node` absolutely |
| ingest URL | `https://sandboxmezzanine.neeba.com/api/ingest/events` | `https://` only (§ 3.5) |
| server checkout | `/home/mezzanine/mezzanine/server` | where `php artisan` runs. On the sandbox it is the same host; on another host, the token is issued there into a `0600` file and carried by hand to the seat as `~/.config/fleet-reporter/mezzanine-reporter-token` (Step 2, [hand delivery](#when-the-server-is-on-another-host-carry-the-token-in-one-named-file)). With no shell on the server, none is needed: an operator issues the token on the site's *Seat tokens* page and the seat takes it into that same file (Step 2, [from the site](#when-the-token-is-issued-on-the-sites-seat-tokens-page)) |
| artifact | `/home/mezzanine/.local/share/fleet-reporter/` | § 2.1's Linux install directory |
| config | `/home/mezzanine/.config/fleet-reporter/config.json` | § 3.1: mode `0600`, directory `0700` |
| spool | `/home/mezzanine/.local/state/fleet-reporter` | § 3.1's example location |
| attempt directory, `$B` | `/home/mezzanine/.local/state/fleet-reporter-install/<UTC timestamp>/` | one per install attempt, mode `0700`, owned by the seat account. It holds the backups **and every staged file**. Nothing is staged in `/tmp`: that directory is world-writable, so another account on the host can create a file there first, under the same name |

Paths are written out in full because the settings file and the crontab both need them expanded.
D1 § 2.1 explains why an unexpanded `~` in a settings command is the quietest failure a reporter can have.
No path that goes into the crontab may contain whitespace or `%`, because cron turns an unescaped `%`
into a newline. Step 5 refuses such a path.

**Run the steps in one shell, and paste each block whole.** Step 0 sets and exports `$B`, and
[Step 2's values block](#step-2--issue-the-seat-token-into-the-config-on-this-host-or-through-a-carried-file)
exports the seat's own values. A later shell, which includes every new call of an agent's shell tool,
runs `export B=<the attempt directory>` and that values block again before it continues. Each block that writes anything but the current shell's own variables is its
own `bash -euo pipefail <<'SH'` … `SH` process (the two that set those, Step 0's first line and Step 2's
values block, run in the current shell because they export into it), so it stops at its first failed command, and a staged write that fails stops the block before
anything runs or installs the file. The block reads `$B` and the seat's values from the environment: a `B` that
is set and not exported is unset there, and the block stops with `B: unbound variable` before it does
anything; a seat value that is unset stops it with a line naming that value. A block takes nothing
after its last line, so it runs the same pasted, or saved to a file and run with `bash <file>`. The
block is that `bash`'s standard input, so no command inside one reads input of its own; the issue
commands read `/dev/null`.

**Why a separate `bash`, and not a `( set -euo pipefail … )` subshell** (card#12334). bash ignores
`set -e` in a command that runs as the left side of `&&` or `||`, as an `if` or `while` condition, or
after `!`, and in every command that a subshell or function in that position runs, even one that sets
`-e` itself (bash(1), the `-e` option of `set`). Claude Code's Bash tool runs the command it is given
as `eval '<command>' && pwd -P …`, which is the tool's own command line, read from `/proc` on
2026-10-25. A subshell block pasted there carries on past a failed command and exits 0:
`( set -e; false; echo continued ); echo rc=$?` prints `continued` and `rc=0`. A new `bash` process
starts with its own `-e` in force, whatever runs it.

**A seat with no crontab is an empty crontab.** `crontab -l` exits 1 with `no crontab for <user>`
on an account that has never installed one. Every block that reads the crontab does it through
`read_crontab`, defined on the block's first lines, which reads exactly that case as empty and fails on
any other `crontab -l` failure, the predicate `bin/supervision.sh` uses for the same read. A crontab
this runbook cannot read is never replaced.

## Running it with `install.sh`

[`install.sh`](install.sh) runs this runbook's blocks for you, from the checkout that holds it. Every
` ```bash ` block below carries an anchor on the line above it: `<!-- install.sh: run <name> -->` for a
block the script runs, or `<!-- install.sh: skip — <reason> -->` for one it does not, with the reason.
The script reads each block it names out of this file and runs it **unchanged**, with each `<placeholder>`
filled by a reference to a shell variable rather than by a pasted value. What the script itself owns is
the order the blocks run in, when each runs, and how it reads what each printed. [`AGENT-INSTALL.md`](AGENT-INSTALL.md)
is the guide a Claude Code agent follows to run it, and states what the agent asks the user.

**The three runs of a fresh install.** The token is written first, because every later step needs a
config to read, and nothing is installed until it is:

1. `install.sh --site <url> --seat-id <id> [--install-id <id>]` with no config yet runs the
   prerequisites line, installs nothing, prints the command for step 2 with this run's values, and exits
   `3`. `<url>` is the site's address, `https://<host>`, or its ingest URL; the script appends
   `/api/ingest/events` to an address. `--install-id` defaults to this account's name, as a slug.
2. `install.sh --set-token` with the same values: Step 0, Step 2's values block and writer, then
   [(a′)'s hidden prompt](#when-the-token-is-issued-on-the-sites-seat-tokens-page), (c) and (d), then
   Step 3. A token file already at `~/.config/fleet-reporter/mezzanine-reporter-token`, which
   [the hand delivery](#when-the-server-is-on-another-host-carry-the-token-in-one-named-file) carries, is
   used as it is and no prompt is shown. `--agent-name` declares `protocol_agent_name` and runs Step 2's
   identity check first, refusing a name the roster does not hold; `--ca-file` sets the site's `ca_file`.
   With no terminal to read from, it refuses before it writes anything, and names the command to run in
   one.
3. `install.sh` with the same values, or none: Step 0; U1, and Step 1 when the seat's artifact is absent
   or differs from the checkout's; Step 4 (a); Step 5's block; the read-only check under U6 of what the
   running flusher runs, and U6 when it runs an older build than the installed artifact, so a run that
   installed the artifact and failed before U6 is finished by the next; Step 3; Step 6, U8, and [Step 7's arrival check](#step-7--verify-that-events-arrive);
   then, when the run changed anything, Step 5's check of who started the flusher, which it reports and
   does not judge (a flusher a hook started holds the same lock, as that check says).

**A later run is the upgrade, and the re-check.** Run from a checkout of the ref an update names (U0's
clone, or a `git pull` of the checkout), run 3 is [Upgrading an existing seat](#upgrading-an-existing-seat)
U0 to U8 for the touches `artifact`, `fixtures`, `hooks` and `cron`. A row's `config` touch is still
applied by hand, with the command the row names. A run that changes nothing writes nothing: U1 prints
`NO-OP`, Step 4 (a) prints `already wired: nothing applied` and Step 5 prints `crontab already current`,
so `settings.json`, the crontab, the artifact and the config keep their bytes and their mtimes and the
flusher is not restarted. The checks still run, and the run then removes the attempt directory its Step 0
made, which holds copies of a state it did not change. A run whose config names another `install_id`,
`seat_id` or site than the ones given refuses before it changes anything.

**`--set-token` over an existing config replaces the token.** It runs
[Rotating a site's token](#rotating-a-sites-token)'s write for the site `--site-name` names, `default`
when it is unset, then (d) and U6. Revoking the old token on the site comes after, as that section says.

**Exit codes.** `0`: every check passed, or nothing needed changing. `1`: the script refused, a block
failed, or a check failed; the block's own lines say which. `2`: installed, and a check could not be
measured (a site that did not answer, or no flusher within the arrival check's wait). `3`: the token
comes first, and the output names its command.

**The script and this runbook cannot drift apart unseen.** Before it runs anything, `install.sh` checks
this file against itself, and `install.sh --check-runbook` runs that check alone. It refuses unless every
` ```bash ` block here carries an anchor, every block it runs has exactly one `run` anchor, every `run`
anchor names a block it runs, and every message it reads in a block's output is in that block's own
source. So a step added here without an anchor, or a reworded message the script would misread, stops the
script and reds CI. [`install.selftest.py`](install.selftest.py) plants each of those divergences and
watches the check fail, then installs, re-runs and upgrades a seat in a throwaway home, against a TLS
ingest stub and a stub `crontab`. ⚠ The check sees an anchor, a block and a message; it does not see
prose. A new manual step written here in prose alone is invisible to it, as it is to
`bin/reporter-version-guard.py`.

## Why cron supervises the flusher, and not a `systemd --user` unit

D1 § 2.3 needs the flusher to run whenever the seat does, and gives it two starts: a supervised start,
and a respawn from every hook that finds the lock stale. The supervised start carries the load. An idle
seat fires no hooks, so without a supervisor its heartbeat stops and the desk renders `offline` while
the agent is only quiet.

**A `systemd --user` unit cannot supervise on this project's seats.** `man loginctl` says lingering is
what makes "a user manager spawned for the user at boot and kept around after logouts". Without it, the
unit starts at the account's first login and stops at its last logout. This account reports `Linger=no`
(`loginctl show-user mezzanine | grep Linger`). The seats run on the project's hosts, which are
Virtualmin sub-server accounts with no sudo and no lingering user manager, by operator ruling
(`docs/PLAN.md § 5`, *No root, no sudo, no systemd*). A unit there would supervise only while someone
happened to be logged in. A seat elsewhere that **has** lingering may use a `systemd --user` unit
instead (D1 § 2.3). This runbook does not cover one.

**The user crontab has none of those limits, and this host already trusts it.** It runs as the seat
account, needs no root, fires `@reboot` at boot whether or not anyone logs in, and survives logout.
`bin/supervision.sh` already supervises the server's own daemons this way, so one host has one
supervision pattern instead of two. The entry runs every minute under `flock -n`, so a minute's start
does nothing while the previous copy still runs, and a flusher that exited is started again at the
next minute boundary.

**Two locks, and they are not redundant.** `flock -n` stops cron from stacking a new `node` process on
top of its own running copy every minute. The reporter's own `flusher.lock` (§ 2.3) is what makes the
flusher exclusive across *all* starts, cron's and the hooks'. A copy that loses that lock exits 0
straight away.

**How long an unclean death costs, derived rather than quoted.** `fleet-reporter.js` touches
`flusher.lock` at the start of every flush pass and before every request it sends (`renewLock`), and passes run
`K.FLUSH_MS` apart. A start treats the lock as held until it is `K.LOCK_STALE_MS` old. A flusher killed uncleanly (SIGKILL, OOM) leaves the
lock behind, so no start takes over until `K.LOCK_STALE_MS` after its last touch. After that, the first
start wins: the next cron minute boundary, or any hook that fires sooner. So an idle seat loses up to
`K.LOCK_STALE_MS` plus one cron minute plus a `node` start. [Step 5](#step-5--supervise-the-flusher-from-the-user-crontab)'s
kill test measured one such recovery. A clean stop (SIGTERM) removes the lock, so the next start
takes over without waiting for it to go stale.

**The start carries `$COORD_CONFIG` itself**, because D1 § 3.1's delivery contract requires it: a
flusher started by cron inherits nothing from the harness's settings, and on a healthy seat that
flusher is the one that heartbeats. The crontab entry assigns the variable on its own command line.
If the resolved value changes, re-run Step 5. It replaces the block and stops the running flusher,
so the next start runs with the new value. That is § 3.1's rewrite and restart.

---

## Step 0 — back up what the install touches, and make the attempt directory

The install edits two things other software depends on. `~/.claude/settings.json` holds this account's
Claude Code hooks, which every session on the account runs, including the coord framework's own. The
crontab runs the server's daemons. An upgrade also replaces the reporter's own files: the artifact and
its fixtures (Step 1), and, when an update says so, the config (Step 2). Copy all of them into a new
attempt directory before anything else:

<!-- install.sh: run backup -->
```bash
export B=$HOME/.local/state/fleet-reporter-install/$(date -u +%Y%m%dT%H%M%SZ)
bash -euo pipefail <<'SH'
  mkdir -p $HOME/.local/state/fleet-reporter-install
  mkdir -m 0700 "$B"
  echo "B=$B"
  { [ -O "$B" ] && [ "$(stat -c %a "$B")" = 700 ]; } || { echo "\$B is not a new 0700 directory of the seat's own: NOTHING COPIED" >&2; exit 1; }
  read_crontab() { crontab -l 2> "$B/crontab.err" || grep -q '^no crontab for ' "$B/crontab.err" || { cat "$B/crontab.err" >&2; return 1; }; }
  if [ ! -e $HOME/.claude/settings.json ]; then mkdir -p $HOME/.claude; ( umask 077; printf '{}\n' > $HOME/.claude/settings.json ); echo "no settings.json yet: created it as {}"; fi
  cp -p $HOME/.claude/settings.json "$B/settings.json"
  read_crontab > "$B/crontab.txt"
  chmod 0600 "$B/crontab.txt"
  [ -s "$B/crontab.txt" ] || echo "no crontab installed: the crontab backup is empty"
  cmp $HOME/.claude/settings.json "$B/settings.json"
  if [ -d $HOME/.local/share/fleet-reporter ]; then
    cp -pr $HOME/.local/share/fleet-reporter "$B/artifact"
    diff -r $HOME/.local/share/fleet-reporter "$B/artifact"; echo "artifact and fixtures copied"
  else echo "no artifact installed yet: nothing to copy"; fi
  if [ -f $HOME/.config/fleet-reporter/config.json ]; then
    cp -p $HOME/.config/fleet-reporter/config.json "$B/config.json"
    cmp $HOME/.config/fleet-reporter/config.json "$B/config.json"; echo "config copied, mode $(stat -c %a "$B/config.json")"
  else echo "no config written yet: nothing to copy"; fi
  # record the md5 it prints: Step 8 must return the crontab to exactly this
  md5sum < "$B/crontab.txt"
SH
```

`mkdir -m 0700` without `-p` fails if the directory already exists, so an attempt never reuses
another's directory. An account on which Claude Code has never written a `~/.claude/settings.json` gets
one holding `{}`, which is how Claude Code reads a missing file, so Step 4 has a file to merge into and
Step 8's restore puts back that same empty file. On a seat with no crontab the block prints `no crontab installed`, and the md5
is the empty file's, `d41d8cd98f00b204e9800998ecf8427e`. The config holds the seat's token, so `cp -p` keeps its `0600` mode, inside a
directory only the seat account can enter. To put a backed-up artifact back, run Step 1's `install`
lines with `<checkout>/fleet-reporter` replaced by `$B/artifact`, then restart the flusher with
[the upgrade's](#upgrading-an-existing-seat) step U6. On the sandbox, the first attempt was `20260913T193245Z`, and the crontab's md5
was `bcd71d369c6c73ed8e61c819e3e2fb4d`. The hooks were applied later, in attempt `20260913T203739Z-hooks`,
which backed up `settings.json` alone.

## Step 1 — prerequisites and the artifact

You need `node` 18 or later (D1 § 2.1), plus `flock` and `crontab`, on the seat account's PATH. The
sandbox had `v22.22.1`, `/usr/bin/flock` and `/usr/bin/crontab`, and `node` resolved to `/usr/bin/node`:

<!-- install.sh: run prereqs -->
```bash
node --version && command -v node flock crontab && readlink -f "$(command -v node)" && node -e 'const v = process.versions.node; if (Number(v.split(".")[0]) < 18) { console.error("node " + v + " is older than 18 (D1 § 2.1): NOTHING INSTALLED"); process.exit(1); }'
```

Each command prints what it found, and a missing one ends the line there with a non-zero exit, so a
`node` older than 18 is named here, before anything is installed.

The artifact is two things from a checkout of PupFuzz/mezzanine: `fleet-reporter/fleet-reporter.js`, and
`fleet-reporter/fixtures/hooks/*.json`. The fixtures ship with the script for two reasons. `selftest`
reads them on the seat (README, D1 § 2.1). And Step 4 derives the hook set from their names, because
D1 § 2.1 vendors one file per subscribed hook. The block's lines start in the first column, because
`bin/reporter-version-guard.py` reads its `install` lines from that column. The sandbox used a
checkout of `dev` at `4ce0a19`:

<!-- install.sh: run artifact -->
```bash
bash -euo pipefail <<'SH'
install -d -m 0700 $HOME/.local/share/fleet-reporter $HOME/.local/share/fleet-reporter/fixtures $HOME/.local/share/fleet-reporter/fixtures/hooks
install -m 0600 <checkout>/fleet-reporter/fleet-reporter.js $HOME/.local/share/fleet-reporter/fleet-reporter.js
install -m 0600 <checkout>/fleet-reporter/fixtures/hooks/*.json $HOME/.local/share/fleet-reporter/fixtures/hooks/
sha256sum <checkout>/fleet-reporter/fleet-reporter.js $HOME/.local/share/fleet-reporter/fleet-reporter.js
diff -r <checkout>/fleet-reporter/fixtures $HOME/.local/share/fleet-reporter/fixtures; echo fixtures-match
printf '{}' | node $HOME/.local/share/fleet-reporter/fleet-reporter.js hook PostToolUse; echo "rc=0"
SH
```

The last line proves something about ordering. With the artifact in place and no config yet, a hook
exits 0 and prints nothing (`rc=0`; a hook that exits otherwise stops the block with its own code). So hooks may be wired before the config exists. The reverse order
is not safe. A settings entry whose script path does not exist yet makes `node` exit non-zero on every
tool call, and the harness puts that error in the transcript.

**Upgrading a seat that already reports: install the artifact first, then stop the flusher** (D1 § 3.1,
card#12180). [Upgrading an existing seat](#upgrading-an-existing-seat) is the whole procedure, in order:
the `install` lines above run over the running seat in its step U3, and the stop, the wait and the
`flusher-ancestry.js --upgrade` check that the lock holder started after the artifact was installed are
its step U6. This paragraph owns why the order is that one, and what the check's `FAIL` means.

The order is load-bearing. A hook starts a flusher whenever it finds no live one, and it runs the path
the hook was given, so stopping first lets a hook that fires before the install start the **old**
artifact, which then runs until it is stopped again. Installing first leaves only the seconds between
the two commands, in which a hook already runs the new artifact beside the old flusher; a per-site count
a hook takes in those seconds is folded past by the old flusher and lost, uncounted, and nothing else
is. Step U6's `flusher-ancestry.js --upgrade` prints `FAIL: … before the artifact was installed` when the flusher holding the lock
started before the new artifact did (one the stop missed, or one a hook started before the install); stop
it again. The start time it reads from `/proc` can come out a second or two early, because `/proc/stat`'s
`btime` is whole seconds and the wall clock drifts from the boot clock: on the sandbox it read 0.9 s and
2.1 s before the flusher's own `started_at` in `flusher.lock`. So a holder started within two seconds of
the install can also read as older, and stopping it again is right in that case too. The first start of
the new build adopts the old `state.json`: it keeps the delivery cursor and mints a new `seq_epoch`, which
the server shows once as `epoch_reset`.

**What the upgrade showed on the sandbox seat**, on 2026-10-23, from the build of card#11817 (`4d7f317e`)
to card#12180's first build (`e62ccfc4`), in attempt directory `20261023T015956Z-upgrade-12180`. The
`install` lines ran at 02:00:54 UTC. Run before the stop, `flusher-ancestry.js` printed the running
flusher, started by cron the previous day, and `FAIL: the lock holder started 2026-10-22T02:29:59.050Z,
before the artifact was installed at 2026-10-23T02:00:54.912Z: it runs the previous build`. The stop
printed `flusher pid 901 exited 5 s after SIGTERM`. Cron's 02:01 start took the lock, and
`flusher-ancestry.js` printed `node` ← `flock -n …` ← `/bin/sh -c COORD_CONFIG=… flock -n …` ← `CRON`,
then `the lock holder started 2026-10-23T02:01:00.250Z, after the artifact's 2026-10-23T02:00:54.912Z` and
`PASS: the lock holder was started by cron through flock`. The new flusher's log opened with `site default:
adopted the state.json of a build before card#12180 — cursors carried, new seq_epoch (§ 3.1 rule 3)`. In
the server's store, the old epoch's last event was seq 203284, received at 02:00:50; the new epoch's
first heartbeat was seq 1 at 02:01:01, and its heartbeats then arrived 60 s apart with contiguous seqs.
The snapshot's `badges` for the seat read `["epoch_reset"]`, and nothing else: the old build's last batch
had been answered, so no `seq_gap` followed. Step 6 and Step 7 below record the checks that followed. After
the site steps below, the artifact was replaced by the build of card#12180's closing PR in the same order, in
attempt `20261023T023534Z-upgrade-12180-final`: installed at 02:35:35, the stop took 1 s, a hook's start held
the lock at 02:35:54 and the start-time check read it as after the artifact, `state.json`'s `default` entry
kept its `seq_epoch` (one build of this card to the next adopts nothing), and Step 6 exited `rc=0` again.

## Step 2 — issue the seat token into the config, on this host or through a carried file

On a seat on the Mezzanine server's own host, the issue command pipes straight into the config. When
the server is on another host, the token reaches the seat in one named file the operator carries
([the hand delivery](#when-the-server-is-on-another-host-carry-the-token-in-one-named-file)). With no
shell on the server at all, an operator issues the token on the site's *Seat tokens* page
(`/admin/tokens`), and the seat takes it into that same named file at a hidden prompt
([from the site](#when-the-token-is-issued-on-the-sites-seat-tokens-page)). All three routes use the
same resolver, identity check and writer, which come first.

**Set the seat's values first.** Every block of this step reads them from here, so no block below is
edited by hand. Fill each `<…>` from [the values to substitute](#the-values-to-substitute); the
sandbox's values are in the comments. `SEAT_AGENT` may be empty, for a seat that declares no
`protocol_agent_name`. `SEAT_WRAPPED` is read from the seat's settings, not typed, and a settings file it cannot read leaves it
unset. When the server is on
another host, run the block on that host too: the hand delivery's step (a) reads `SEAT_INSTALL_ID`,
`SEAT_ID`, `SEAT_BY` and `SEAT_SERVER` there.

<!-- install.sh: run values -->
```bash
export SEAT_INSTALL_ID=<install_id>           # sandbox: mezzanine
export SEAT_ID=<seat_id>                      # sandbox: mezzanine-solo
export SEAT_AGENT=<protocol_agent_name>       # sandbox: mezzanine
export SEAT_URL=<ingest_url>                  # sandbox: https://sandboxmezzanine.neeba.com/api/ingest/events
export SEAT_BY=<issued_by>                    # who issues the token, for the server's record; sandbox: card-9368
export SEAT_SERVER=<server_checkout>          # where php artisan runs; sandbox: /home/mezzanine/mezzanine/server
export SEAT_WRAPPED; SEAT_WRAPPED="$(node -e 'let s = {}; try { s = JSON.parse(require("fs").readFileSync(process.env.HOME + "/.claude/settings.json", "utf8")); } catch (e) { if (e.code !== "ENOENT") throw e; } console.log((s.statusLine && s.statusLine.command) || "")')" || unset SEAT_WRAPPED
env | grep '^SEAT_' | sort
```

A `<…>` left unfilled is a syntax error. Pasted whole, or saved and run with `bash <file>`, the block
then runs no line at all and sets no value; typed into a terminal line by line, that line alone fails and
its value stays unset. Either way the blocks below stop on the value that is missing.

**Resolve `$COORD_CONFIG` the way the seat's sessions see it.** Save the resolver. It reads each
settings file in precedence order, lowest first, so the last file that sets the value wins. It prints
the value, or an empty line when no file sets it:

<!-- install.sh: run resolver -->
```bash
bash -euo pipefail <<'SH'
  cat > "$B/coord-config.js" <<'JS'
const fs = require("fs");
if (process.argv.length < 3) { console.error("usage: node coord-config.js <settings file>...: NOTHING READ"); process.exit(2); }
let v = "";
for (const f of process.argv.slice(2)) {
  let s;
  try { s = JSON.parse(fs.readFileSync(f, "utf8")); } catch (e) { if (e.code === "ENOENT") continue; throw e; }
  if (s.env && typeof s.env.COORD_CONFIG === "string") v = s.env.COORD_CONFIG;
}
console.log(v);
JS
  node "$B/coord-config.js" $HOME/.claude/settings.json $HOME/.claude/settings.local.json
SH
```

On the sandbox it printed `/home/mezzanine/.config/coord/coordination.config.json`, from
`settings.local.json`, since `settings.json` sets no `COORD_CONFIG`.

**Check the identity.** `install_id` and `seat_id` must match D1 § 3.1's patterns, and the issue
command refuses a value that does not. `protocol_agent_name` must be a member of the roster the
reporter reads. D1 § 3.1 reads `$COORD_CONFIG` first and, on Linux, the home path second. This prints
the names that roster holds, and what `SEAT_AGENT` is against it:

<!-- install.sh: run identity -->
```bash
bash -euo pipefail <<'SH'
  : "${SEAT_AGENT?is not set: run the values block at the top of Step 2 first}"
  CC=$(node "$B/coord-config.js" $HOME/.claude/settings.json $HOME/.claude/settings.local.json)
  export ROSTER="${CC:-$HOME/.config/coord/coordination.config.json}"
  node -e 'const f = process.env.ROSTER, a = process.env.SEAT_AGENT; const c = JSON.parse(require("fs").readFileSync(f, "utf8")); const names = (c.roster || []).map((r) => r.name); for (const n of names) console.log(n); console.log(a === "" ? "SEAT_AGENT is empty: undeclared" : "SEAT_AGENT " + a + (names.includes(a) ? " is a member: checked" : " is not a member: disagreed"))'
SH
```

On the sandbox it printed `mezzanine`. The seat declares `mezzanine`, so the check is `checked`.
Declaring `mezzanine-solo` would have been `disagreed`.

**The token is a credential, and it must never reach a terminal, an argv, a shell history or a log.**
`mezzanine:ingest-token:issue` prints the plaintext once and stores only its SHA-256 (the site's *Seat
tokens* page performs the same act and shows it once, in the browser). So the issue
command is piped straight into a small writer. On the same host the writer takes the token off the pipe,
writes the config with mode `0600`, and prints every other line of the command's output, with the token
line replaced. It receives the token, so it lives in `$B` and nowhere another account can write. Save
it:

<!-- install.sh: run writer -->
```bash
bash -euo pipefail <<'SH'
  cat > "$B/write-config.js" <<'JS'
const fs = require("fs"), path = require("path");
const e = process.env, out = [], TOKEN = /^\s*token\s+(mzn_[A-Za-z0-9_-]{43})\s*$/, SHAPE = /mzn_[A-Za-z0-9_-]{43}/g;
const FILE_TOKEN = /^mzn_[A-Za-z0-9_-]{43}\n?$/;
const DESCRIPTORS = e.FR_DESCRIPTORS || "full";
const TRANSPORT = ["ingest_url", "token", "ca_file", "proxy_url"];
const SEAT_KEYS = ["FR_INSTALL", "FR_SEAT", "FR_SPOOL", "FR_AGENT", "FR_WRAPPED", "FR_DESCRIPTORS"];
const MODE = e.FR_MODE || "";
const die = (why) => { console.error(why + ": NOTHING WRITTEN"); process.exit(1); };
if (!["", "add-site", "set-token"].includes(MODE)) die("FR_MODE must be add-site or set-token, or unset");
if (!["full", "paths", "none"].includes(DESCRIPTORS)) die("FR_DESCRIPTORS must be full, paths or none");
for (const k of ["FR_TOKEN_FILE", "FR_TOKEN_OUT"]) if (k in e && !e[k]) die(k + " is set but empty");
if (e.FR_TOKEN_FILE && e.FR_TOKEN_OUT) die("FR_TOKEN_FILE and FR_TOKEN_OUT are two different steps; set one");
if (e.FR_CA_FILE && !path.isAbsolute(e.FR_CA_FILE)) die("FR_CA_FILE must be an absolute path");
if (MODE) {
  // The two modes change `sites` and nothing else: the seat's keys come from the file.
  for (const k of SEAT_KEYS) if (k in e) die(k + " is set, and FR_MODE=" + MODE + " takes the seat's keys from the file");
  if (e.FR_TOKEN_OUT) die("FR_TOKEN_OUT is the issuer's step; FR_MODE writes a config");
  if (!e.FR_REPORTER) die("FR_REPORTER must name the installed fleet-reporter.js, whose loadConfig validates the result");
  if (!e.FR_SITE_NAME) die("FR_MODE=" + MODE + " needs FR_SITE_NAME");
  if (MODE === "add-site" && !e.FR_URL) die("FR_MODE=add-site needs FR_URL");
  if (MODE === "set-token") for (const k of ["FR_URL", "FR_CA_FILE"]) if (k in e) die(k + " is set, and FR_MODE=set-token changes one token and nothing else");
  if (!e.FR_CONFIG) die("FR_CONFIG must name the config file FR_MODE=" + MODE + " changes");
  if (!fs.existsSync(e.FR_CONFIG)) die("FR_MODE=" + MODE + " changes a config that exists, and " + e.FR_CONFIG + " does not");
}
const mode = (st) => (st.mode & 0o777).toString(8);
const siteOf = (name, url, token) => ({ name, ingest_url: url, token, ca_file: e.FR_CA_FILE || null, proxy_url: null });
function writeConfig(token) {
  // A fresh install writes the multi-site form, one site, and never the single-site form (D1 § 3.1).
  for (const k of ["FR_CONFIG", "FR_INSTALL", "FR_SEAT", "FR_URL", "FR_SPOOL"]) if (!e[k]) die(k + " is not set, and a fresh config needs it");
  const cfg = {
    install_id: e.FR_INSTALL, seat_id: e.FR_SEAT, spool_dir: e.FR_SPOOL,
    wrapped_statusline: e.FR_WRAPPED || null, protocol_agent_name: e.FR_AGENT || null,
    descriptors: DESCRIPTORS, enabled: true,
    sites: [siteOf(e.FR_SITE_NAME || "default", e.FR_URL, token)],
  };
  fs.mkdirSync(path.dirname(e.FR_CONFIG), { recursive: true, mode: 0o700 });
  fs.chmodSync(path.dirname(e.FR_CONFIG), 0o700);
  const fd = fs.openSync(e.FR_CONFIG, "wx", 0o600);
  fs.writeSync(fd, JSON.stringify(cfg, null, 2) + "\n"); fs.closeSync(fd);
  console.log("wrote " + e.FR_CONFIG + " (mode " + mode(fs.statSync(e.FR_CONFIG)) + ")");
}
function changeSites(token) {
  // Read, lift a single-site file into `sites` under FR_LIFT_NAME, change the one site, then write a 0600
  // temp file beside the config, validate it with the reporter's own loadConfig, and rename it into place.
  let c;
  try { c = JSON.parse(fs.readFileSync(e.FR_CONFIG, "utf8")); } catch (err) { die("cannot read " + e.FR_CONFIG + " as JSON (" + (err.code || "not JSON") + ")"); }
  if (!c || typeof c !== "object" || Array.isArray(c)) die(e.FR_CONFIG + " is not a JSON object");
  if (!("sites" in c)) {
    const lifted = { name: e.FR_LIFT_NAME || "default" };
    for (const k of TRANSPORT) { lifted[k] = k in c ? c[k] : null; delete c[k]; }
    c.sites = [lifted];
  }
  if (!Array.isArray(c.sites)) die(e.FR_CONFIG + "'s sites is not an array");
  const names = c.sites.map((x) => (x && typeof x.name === "string" ? x.name : "<unnamed>"));
  if (MODE === "add-site") c.sites.push(siteOf(e.FR_SITE_NAME, e.FR_URL, token));
  else {
    const at = names.indexOf(e.FR_SITE_NAME);
    if (at < 0) die("FR_SITE_NAME " + e.FR_SITE_NAME + " is not a site in " + e.FR_CONFIG + ", whose sites are: " + names.join(", "));
    c.sites[at].token = token;
  }
  const tmp = path.join(path.dirname(e.FR_CONFIG), "." + path.basename(e.FR_CONFIG) + "." + process.pid + ".tmp");
  try {
    const fd = fs.openSync(tmp, "wx", 0o600);
    try { fs.writeSync(fd, JSON.stringify(c, null, 2) + "\n"); fs.fsyncSync(fd); } finally { fs.closeSync(fd); }
  } catch (err) { try { fs.unlinkSync(tmp); } catch (e2) {} die("could not write " + tmp + " (" + err.code + ")"); }
  let errors;
  try { const m = require(path.resolve(e.FR_REPORTER)); const l = m.loadConfig(tmp); errors = m.allConfigErrors(l); }
  catch (err) { errors = ["cannot load the reporter at " + e.FR_REPORTER + " (" + (err.code || err.message) + ")"]; }
  if (errors.length) { fs.unlinkSync(tmp); for (const x of errors) console.error("config error: " + x); die("the result does not validate"); }
  try { fs.renameSync(tmp, e.FR_CONFIG); } catch (err) { try { fs.unlinkSync(tmp); } catch (e2) {} die("could not rename " + tmp + " over " + e.FR_CONFIG + " (" + err.code + ")"); }
  console.log("wrote " + e.FR_CONFIG + " (mode " + mode(fs.statSync(e.FR_CONFIG)) + "), sites: " + c.sites.map((x) => x.name).join(", "));
  if (MODE === "set-token") console.log("token replaced: " + e.FR_SITE_NAME);
}
const place = (token) => { try { (MODE ? changeSites : writeConfig)(token); } catch (err) { die("could not write " + e.FR_CONFIG + " (" + err.code + ")"); } };
if (e.FR_TOKEN_FILE) {
  // The seat end of a hand delivery: the token comes from the carried file, never from stdin.
  let st;
  try { st = fs.lstatSync(e.FR_TOKEN_FILE); } catch (err) { die("cannot read the token file " + e.FR_TOKEN_FILE + " (" + err.code + ")"); }
  if (!st.isFile()) die("the token file " + e.FR_TOKEN_FILE + " is not a regular file");
  if (st.uid !== process.getuid()) die("the token file " + e.FR_TOKEN_FILE + " is not owned by this account");
  if ((st.mode & 0o777) !== 0o600) die("the token file " + e.FR_TOKEN_FILE + " has mode " + mode(st) + ", not 600");
  const dir = path.dirname(e.FR_TOKEN_FILE), dst = fs.lstatSync(dir);
  if (!dst.isDirectory() || dst.uid !== process.getuid()) die("the token file's directory " + dir + " is not a directory owned by this account");
  if ((dst.mode & 0o777) !== 0o700) die("the token file's directory " + dir + " has mode " + mode(dst) + ", not 700");
  const body = fs.readFileSync(e.FR_TOKEN_FILE, "utf8");
  if (!FILE_TOKEN.test(body)) die("the token file " + e.FR_TOKEN_FILE + " does not hold exactly one well-formed mzn_ token");
  console.log("read the token from " + e.FR_TOKEN_FILE + " (mode 600)");
  place(body.trimEnd());
} else {
  let raw = ""; process.stdin.setEncoding("utf8");
  process.stdin.on("data", (d) => { raw += d; });
  process.stdin.on("end", () => {
    let token = null;
    const where = e.FR_TOKEN_OUT ? "the token file" : "the config";
    for (const line of raw.split("\n")) {
      const m = TOKEN.exec(line);
      if (m) { token = m[1]; out.push("  token       <written to " + where + ", not shown>"); } else out.push(line.replace(SHAPE, "<redacted>"));
    }
    console.log(out.join("\n"));
    if (!token) die("no token line in the issue output");
    if (e.FR_TOKEN_OUT) {
      // The issuer end of a hand delivery: the token alone, in a 0600 file, for the operator to carry.
      try {
        const fd = fs.openSync(e.FR_TOKEN_OUT, "wx", 0o600);
        fs.writeSync(fd, token + "\n"); fs.closeSync(fd);
      } catch (err) { die("could not write " + e.FR_TOKEN_OUT + " (" + err.code + ")"); }
      console.log("wrote " + e.FR_TOKEN_OUT + " (mode " + mode(fs.statSync(e.FR_TOKEN_OUT)) + ")");
    } else place(token);
  });
}
JS
  node --check "$B/write-config.js" && echo writer-saved
SH
```

Before this ran for real, the writer was driven three ways: with a fabricated issue output, which wrote
the file at mode `600` in a `700` directory; against a config that already existed, which refused
(`'wx'`) and wrote nothing; and with output that had no token line, which exited 1 and wrote nothing.

**Since card#12180 the writer writes D1 § 3.1's multi-site form, and only that form**: `install_id`,
`seat_id`, `spool_dir` and the seat's other keys at the top level, and a `sites` list holding the one
site, `{ name, ingest_url, token, ca_file, proxy_url }`. `FR_SITE_NAME` names the site and defaults to
`default`, so a seat with one site never has to choose one; `FR_CA_FILE` sets its `ca_file` (an absolute
path; unset, `null`); `proxy_url` stays `null`, a hand edit as before, because a proxy URL can carry a
password. A config the reporter wrote before card#12180, with the four keys at the top level, keeps
working unchanged. The writer has two modes, which are the only ways it touches a file that exists:
`FR_MODE=add-site` ([Adding a site](#adding-a-site)) and `FR_MODE=set-token`
([Rotating a site's token](#rotating-a-sites-token)). Each reads the config, lifts a file written before
card#12180 into the `sites` form under `FR_LIFT_NAME` (default `default`), changes one site, writes the
result to a `0600` temp file beside the config and flushes it to disk, checks it with the installed
reporter's own `loadConfig` (`FR_REPORTER` names that file), and renames it into place only when it has no
error; on any error, a failed rename included, it removes the temp file, prints each error and leaves the
config as it was. Each refuses `FR_INSTALL`,
`FR_SEAT`, `FR_SPOOL`, `FR_AGENT`, `FR_WRAPPED` and `FR_DESCRIPTORS`, because a mode changes `sites` and
nothing else, and prints the site names and nothing of any value. The acceptance suite re-extracts this
block and drives it (AT-28 Case P): the fresh form, a lift, every refusal, both token routes, and a
file-size limit that the temp-and-rename write survives with the config byte-identical.
The writer reads the token from one of two places and writes it to one of two places. On the same host,
which is the pipeline below, it reads the issue command's output on stdin and writes the config.
When the server is on another host, the token makes two hops through the same writer. `FR_TOKEN_OUT`
writes the token alone to a file on the Mezzanine host, and `FR_TOKEN_FILE` reads it from that file on
the seat; [the hand delivery](#when-the-server-is-on-another-host-carry-the-token-in-one-named-file)
below runs both, and says what card#11331's scratch run of them showed.

`FR_DESCRIPTORS` is how much of each tool call the seat sends as its descriptor: `full` (the default when
it is unset), `paths` or `none`. D1 § 3.1 defines the three values, and Step 3 says how to change it later.
The writer refuses any other value before it writes anything.

`FR_WRAPPED` is the statusLine command the seat has **now**, `statusLine.command` in
`~/.claude/settings.json`, which the values block reads into `SEAT_WRAPPED`. On the sandbox it was the coord framework's context sensor. It is recorded
even though the sandbox has not applied the wrap: D1 § 6.11's passthrough needs it only if Step 4(b)
is applied, and (b) refuses unless the live statusLine still equals it.

Then issue the token and write the config in one pipeline. The first line refuses unless `$B` is
still the seat account's own `0700` directory, so the pipeline never hands the token to a writer
anyone else could have placed. The second line refuses unless `node` is on `PATH`: without it the
pipe would issue a token that nothing receives, and the prefix line would never be printed, leaving
nothing to revoke it by. That guard was driven on copies, with a stub `php` on `PATH`. With `$B`
pointed at a `0755` directory it printed `NOTHING RUN`, and `php` was never called. With the real `$B`,
`php` was called. Card#11331 drove the `node` line the same way: with no `node` on `PATH` it printed
`NOTHING RUN`, and the stub `php` was never called:

<!-- install.sh: skip — the issue command runs on the Mezzanine host; install.sh takes the token from the Seat tokens page or a carried file -->
```bash
bash -euo pipefail <<'SH'
  { [ -O "$B" ] && [ "$(stat -c %a "$B")" = 700 ] && [ -f "$B/write-config.js" ] && [ -O "$B/write-config.js" ]; } || { echo "\$B is not the seat's own 0700 directory holding its writer: NOTHING RUN" >&2; exit 1; }
  command -v node >/dev/null || { echo "no node on PATH, so the writer cannot take the token: NOTHING RUN" >&2; exit 1; }
  for v in SEAT_INSTALL_ID SEAT_ID SEAT_URL SEAT_BY SEAT_SERVER; do [ -n "${!v:-}" ] || { echo "$v is not set: run the values block at the top of Step 2 first: NOTHING RUN" >&2; exit 1; }; done
  : "${SEAT_AGENT?is not set: run the values block at the top of Step 2 first}" "${SEAT_WRAPPED?is not set: run the values block at the top of Step 2 first}"
  cd "$SEAT_SERVER"
  php artisan mezzanine:ingest-token:issue "$SEAT_INSTALL_ID" "$SEAT_ID" --by="$SEAT_BY" < /dev/null 2>&1 | FR_CONFIG=$HOME/.config/fleet-reporter/config.json FR_INSTALL="$SEAT_INSTALL_ID" FR_SEAT="$SEAT_ID" FR_URL="$SEAT_URL" FR_SPOOL=$HOME/.local/state/fleet-reporter FR_AGENT="$SEAT_AGENT" FR_WRAPPED="$SEAT_WRAPPED" node "$B/write-config.js"
SH
echo "pipeline rc=$?"
```

What it printed on the sandbox (the first install ran the same pipeline with the writer at a path
since retired):

```
 seat mezzanine / mezzanine-solo (seat_ref 1)
 prefix mzn_YTRScmlE

  token       <written to the config, not shown>
 ...
wrote /home/mezzanine/.config/fleet-reporter/config.json (mode 600)
pipeline rc=0
```

Keep the **prefix** (`mzn_YTRScmlE` here). It is not the credential: `mezzanine:ingest-token:revoke`
takes it, and Step 8 needs it.

- **If the pipeline fails after the token is issued**, for example because the config already exists,
  the token is lost by design, since nothing stored it. Revoke it by its prefix, fix the cause, and run
  the pipeline again.
- **If the server is on another host**, the pipe cannot cross between machines. Carry the token in the
  one named file the next section describes.
- **Rotation.** [Rotating a site's token](#rotating-a-sites-token), below, runs D1 § 3.3's order.

#### Adding a site

A second Mezzanine site (D1 § 3.1, *Sites*) is added in the order in which a refusal fails safe:

1. **Issue the token on the new site for the seat's existing pair**, `php artisan mezzanine:ingest-token:issue
   <install_id> <seat_id>`, on that site's host, or on that site's *Seat tokens* page, and read back the
   `seat <install_id> / <seat_id>` line it prints (the page's heading names the pair the same way): a pair
   mistyped here is a `403 identity_mismatch` on the first batch, which `selftest` cannot see
   (D1 § 6.14). A site on another host carries the token by [the hand delivery](#when-the-server-is-on-another-host-carry-the-token-in-one-named-file),
   steps (a) and (b); a token from the page reaches the seat by [the hidden prompt](#when-the-token-is-issued-on-the-sites-seat-tokens-page).
2. **Write the site with the writer**, by the carried file or by piping the issue command into it:
   `FR_MODE=add-site FR_SITE_NAME=<name> FR_URL=<the site's ingest URL> FR_CA_FILE=<optional> FR_REPORTER=$HOME/.local/share/fleet-reporter/fleet-reporter.js FR_CONFIG=$HOME/.config/fleet-reporter/config.json FR_TOKEN_FILE=<the carried file> node "$B/write-config.js"`.
   It refuses a name or a URL the file already has (two spellings of one server are one URL), and prints
   the site names.
3. **Check the file** with Step 3, then **run Step 6**, reading the new site's block in `sites`.
4. **Restart the flusher** with Step 5's `stop-flusher.js`, and let cron's next minute or a hook start it.
5. **Verify with Step 7** against the new site's store, then shred the token file with step (d).

The new site receives the spool's whole history, up to 8 days of it, paced under that site's event limit
(D1 § 11.5): a full spool takes about 3.4 hours, during which the new site's floor shows the desk
*catching up*. The sites already reporting see nothing of it: each site has its own cursor, requests and
backoff, and a site that is slow, down or refusing delays no other. To take a site off a seat without
uninstalling, see Step 8.

**What adding a site showed on the sandbox seat**, on 2026-10-23, right after the upgrade in Step 1. The
second site was a stub ingest, not a second Mezzanine deployment: a TLS server on `127.0.0.1:18443` with a
self-signed certificate, named as the site's `ca_file`, which answers the health probe with the accepted
set, answers every batch `202`, resolves a bearer token by its SHA-256, and logs each request's envelope
and seq range and never the token. Step 1 of the list above was the stub's: the token was minted into the
named carried file, mode `0600`, and its hash given to the stub. Two refusals ran first against the live
config: `FR_SITE_NAME=default` printed `config error: sites[default].name is shared by sites[0] and
sites[1] — a name keys one site (§ 3.1)` and the live site's URL printed `config error:
sites[default].ingest_url names the same server as sites[1] — one server is one site (§ 3.1)`, each with
`the result does not validate: NOTHING WRITTEN`, rc 1 and the config's md5 unchanged. Then the add printed
`read the token from …/mezzanine-reporter-token (mode 600)` and `wrote …/config.json (mode 600), sites:
default, stub`. Step 3 printed `700` and `600`, the seat's keys, and `sites[default].…` and `sites[stub].…`
with both tokens `<well-formed>`. Step 6 exited `rc=0`, `sites.default` and `sites.stub` each `pass` on all
four checks. After the restart, which a hook won (so `flusher-ancestry.js` printed its `FAIL` for a holder
cron did not start, as Step 5 describes), the stub received its first batch at 02:03:48 and was current at
02:06:05: 15,162 events in about 2 min 20 s, under one `seq_epoch` with no break in its seqs, from the
oldest bucket that held anything but heartbeats. The spool's older buckets, 2026-10-15 to 2026-10-18,
held only heartbeats the previous build had spooled for `default`, and the new site skipped them, as
D1 § 3.1 rule 1 states. That backlog fitted inside one hour's event limit, so no pacing showed; a spool at
its byte bound is what the 3.4 hours above is for. ⚠ The stub was restarted once during that drain to add
seq logging; the flusher's next request continued at the next seq. **The first site saw nothing of it:**
over the same minutes `default`'s heartbeats arrived 60 s apart, its seqs stayed contiguous under the
epoch Step 1 started, and its `seq_epoch`, token and URL were unchanged by every write in this section.
⚠ A stub renders no floor, so what the new site's floor shows during the drain, *catching up*, was not
observed here. Step (d) then printed `token file gone`.

#### Rotating a site's token

Rotate in D1 § 3.3's order, with the refusable step first: **issue** the new token on the site; **write**
it with `FR_MODE=set-token FR_SITE_NAME=<name> FR_REPORTER=… FR_CONFIG=…` and either token route, which
replaces that site's token and nothing else and prints `token replaced: <name>` (on a file written before
card#12180 the site is `default`); **restart** the flusher (Step 5's `stop-flusher.js`, then cron or a
hook), because a running flusher keeps presenting the token it started with; and only then **revoke** the
old token by its prefix. The revoke comes after the restart because issuing revokes nothing, so both
tokens resolve until it, and a revoked token is a `401` that stops the site's sending. The same route is
the recovery from that stop: `REJECTED.<name>.txt` and the log name the site and the status, a `401`
meaning the token does not resolve there (mistyped, swapped with another site's, revoked) and a `403
identity_mismatch` meaning it was issued for another pair, whose `expected_install_id` and
`expected_seat_id` the line carries. The stopped site sends nothing until the flusher restarts on a
corrected token; it keeps every event, and resends the request it was refused first.

**The write, from the token file.** With the new token in the named file of
[the hand delivery](#when-the-server-is-on-another-host-carry-the-token-in-one-named-file), carried there or
written at [the hidden prompt](#when-the-token-is-issued-on-the-sites-seat-tokens-page), this block
replaces the token of the site `SEAT_SITE` names, `default` when it is unset. Its guard is (c)'s, and the
writer's own checks of the file are the ones (c) states. Run (d) after it, then restart the flusher with
[the upgrade's](#upgrading-an-existing-seat) step U6:

<!-- install.sh: run token-rotate -->
```bash
bash -euo pipefail <<'SH'
  { [ -O "$B" ] && [ "$(stat -c %a "$B")" = 700 ] && [ -f "$B/write-config.js" ] && [ -O "$B/write-config.js" ]; } || { echo "\$B is not the seat's own 0700 directory holding its writer: NOTHING RUN" >&2; exit 1; }
  FR_MODE=set-token FR_SITE_NAME="${SEAT_SITE:-default}" FR_TOKEN_FILE=$HOME/.config/fleet-reporter/mezzanine-reporter-token FR_REPORTER=$HOME/.local/share/fleet-reporter/fleet-reporter.js FR_CONFIG=$HOME/.config/fleet-reporter/config.json node "$B/write-config.js"
SH
echo "pipeline rc=$?"
```

On success it prints `read the token from …/mezzanine-reporter-token (mode 600)`, `wrote …/config.json
(mode 600), sites: …`, `token replaced: <name>` and `pipeline rc=0`.

**What rotation showed on the sandbox seat**, on 2026-10-23, against the stub site the section above added.
The new token was issued (minted into the carried file, its hash given to the stub beside the old one). Two
refusals ran first: with `FR_URL` set it printed `FR_URL is set, and FR_MODE=set-token changes one token and
nothing else: NOTHING WRITTEN`, and with `FR_SITE_NAME=nosuch` it printed `FR_SITE_NAME nosuch is not a site in
…/config.json, whose sites are: default, stub: NOTHING WRITTEN`, each rc 1 with the config's md5 unchanged.
The write printed `wrote …/config.json (mode 600), sites: default, stub` and `token replaced: stub`, and the
`default` site's token and URL compared equal, by hash, to the config backed up before the upgrade.
`stop-flusher.js` printed `flusher pid 959448 exited 1 s after SIGTERM`, a hook started the next flusher, and
only then was the old token's hash removed from the stub. Every request the stub received after that
resolved, so the running flusher presents the new token, and step (d) printed `token file gone`.

#### When the server is on another host, carry the token in one named file

The token makes two hops through Step 2's writer, and between them it is a file the operator carries by
hand. The file has one name on every seat, so every seat's runbook, rollback and clean-up look in the
same place:

```
~/.config/fleet-reporter/mezzanine-reporter-token      mode 0600, in the 0700 directory of the config it feeds
```

It holds the token and nothing else, one line, `mzn_` and 43 characters of `[A-Za-z0-9_-]`. It lives
only from (a), which writes it, to (d), which shreds it on both hosts.

**(a) On the Mezzanine host, issue the token into a 0600 file.** Make an attempt directory there the
way Step 0 makes `$B`, with Step 0's first line and the first three lines of its block, and the issuing
account's home in place of the seat's,
save the writer into it with the block above, and run the values block at the top of this step. Then pipe the issue command into the writer with
`FR_TOKEN_OUT` set. The writer prints the issue output with the token line replaced, so the **prefix**
stays on screen, and writes the token alone to the file with mode `0600`. It refuses a file that
already exists. The guard on the first line is the same-host pipeline's:

<!-- install.sh: skip — step (a) runs on the Mezzanine host -->
```bash
bash -euo pipefail <<'SH'
  { [ -O "$B" ] && [ "$(stat -c %a "$B")" = 700 ] && [ -f "$B/write-config.js" ] && [ -O "$B/write-config.js" ]; } || { echo "\$B is not this account's own 0700 directory holding its writer: NOTHING RUN" >&2; exit 1; }
  command -v node >/dev/null || { echo "no node on PATH, so the writer cannot take the token: NOTHING RUN" >&2; exit 1; }
  for v in SEAT_INSTALL_ID SEAT_ID SEAT_BY SEAT_SERVER; do [ -n "${!v:-}" ] || { echo "$v is not set: run the values block at the top of Step 2 first: NOTHING RUN" >&2; exit 1; }; done
  cd "$SEAT_SERVER"
  php artisan mezzanine:ingest-token:issue "$SEAT_INSTALL_ID" "$SEAT_ID" --by="$SEAT_BY" < /dev/null 2>&1 | FR_TOKEN_OUT="$B/mezzanine-reporter-token" node "$B/write-config.js"
SH
echo "pipeline rc=$?"
```

Keep the prefix: Step 8 needs it, and so does every failure below.

**(b) Carry the file to the seat by hand.** The means is the operator's, provided nothing on the way
records the file's contents: a copy between two accounts the operator controls, over SSH, is one such
means. It arrives at `~/.config/fleet-reporter/mezzanine-reporter-token` in the seat account's home,
with mode `0600`, in a directory with mode `0700` that holds no config yet. On the seat, make the
directory first if it is not there:

<!-- install.sh: skip — the carry is the operator's; (a′) makes this directory, and (c) checks the file and its directory -->
```bash
bash -euo pipefail <<'SH'
  install -d -m 0700 $HOME/.config/fleet-reporter
  stat -c '%a %U %n' $HOME/.config/fleet-reporter $HOME/.config/fleet-reporter/mezzanine-reporter-token
SH
```

Both lines must show the seat account, `700` on the directory and `600` on the file.

**(c) On the seat, write the config from the file.** Make `$B` with Step 0, save the writer into it
with the block above and run the values block at the top of this step, as on a same-host seat. The pipeline is the same-host one with the issue command
taken out: `FR_TOKEN_FILE` names the carried file, and the writer reads the token from it rather than
from stdin. It writes the config exactly as the same-host pipeline does, with the same keys and mode
`0600`. Before it writes anything, it confirms that the file is a regular file the seat account owns,
that its mode is exactly `0600`, that its directory is one the seat account owns with mode exactly
`0700`, and that it holds one well-formed token and nothing else. Its messages
name the file and never print what it holds:

<!-- install.sh: run token-config -->
```bash
bash -euo pipefail <<'SH'
  { [ -O "$B" ] && [ "$(stat -c %a "$B")" = 700 ] && [ -f "$B/write-config.js" ] && [ -O "$B/write-config.js" ]; } || { echo "\$B is not the seat's own 0700 directory holding its writer: NOTHING RUN" >&2; exit 1; }
  for v in SEAT_INSTALL_ID SEAT_ID SEAT_URL; do [ -n "${!v:-}" ] || { echo "$v is not set: run the values block at the top of Step 2 first: NOTHING RUN" >&2; exit 1; }; done
  : "${SEAT_AGENT?is not set: run the values block at the top of Step 2 first}" "${SEAT_WRAPPED?is not set: run the values block at the top of Step 2 first}"
  FR_TOKEN_FILE=$HOME/.config/fleet-reporter/mezzanine-reporter-token FR_CONFIG=$HOME/.config/fleet-reporter/config.json FR_INSTALL="$SEAT_INSTALL_ID" FR_SEAT="$SEAT_ID" FR_URL="$SEAT_URL" FR_SPOOL=$HOME/.local/state/fleet-reporter FR_AGENT="$SEAT_AGENT" FR_WRAPPED="$SEAT_WRAPPED" node "$B/write-config.js"
SH
echo "pipeline rc=$?"
```

On success it prints two lines, `read the token from …/mezzanine-reporter-token (mode 600)` and
`wrote …/config.json (mode 600)`, and `pipeline rc=0`.

**(d) Shred the file, on the seat and on the Mezzanine host, and confirm both are gone.** Run this on
the seat once (c) printed `pipeline rc=0`, with `F` as written. Run it on the Mezzanine host with
`F="$B/mezzanine-reporter-token"`, `$B` being (a)'s attempt directory. It shreds `F` only when `F` is a
regular file and not a symlink, because `shred` follows a link and overwrites whatever it points at.
For a symlink it removes the link alone and names the file it pointed at, which may hold the token
and is the operator's to inspect and shred. It ends by printing `gone` or `PRESENT` for `F`:

<!-- install.sh: run token-shred -->
```bash
bash -euo pipefail <<'SH'
  F=$HOME/.config/fleet-reporter/mezzanine-reporter-token
  if [ -L "$F" ]; then echo "$F is a symlink to $(readlink -f -- "$F"): the link is removed and that file is left for you to inspect and shred" >&2; rm -- "$F"
  elif [ -f "$F" ]; then shred -u -- "$F"; fi
  if [ -e "$F" ] || [ -L "$F" ]; then echo "token file PRESENT: $F"; else echo "token file gone: $F"; fi
SH
```

Both hosts must print `gone`. `shred -u` overwrites the file and then removes it. `man shred` names file
systems on which the overwrite is not assured, journaling and snapshotting ones among them, so the
file's short life in a `0700` directory is what protects the token, and the shred is the clean-up.

If a step fails:

- **(a) fails after the token is issued**, for example because the token file already exists: nothing
  holds the token, so revoke it by the prefix (a) printed, fix the cause, and run (a) again.
- **(c) refuses because the config already exists**, or fails for any other reason after it has read
  the file: the token is still whole and still only in the two `0600` files. Fix the cause and run (c)
  again. Run (d) only after (c) succeeds.
- **(c) refuses the file or its directory** — its type, owner, mode or content: the seat cannot show
  that the file reached it unread, or that it holds the token (a) issued. Treat the token as exposed
  rather than repair the mode and go on. Run (d) on both hosts, revoke the token by its prefix on the
  Mezzanine host, and start again at (a).

**What card#11331's scratch run showed.** The writer was extracted from this page and driven in a
scratch directory with a fabricated token of the right shape, standing in for the issue command's
output. (a) wrote the token file at mode `600` holding the token alone, and printed the prefix line.
Run again, it refused the existing file with a one-line message. (c) refused a `0644` token file, a
`0600` token file in a `0755` directory, a malformed token, a file holding two tokens, a token with
other text on its line, a symlink, a missing file and an empty `FR_TOKEN_FILE`, and wrote no config in
each case. It then wrote the config at mode `600` in a `700` directory, with the same keys and
token as the same-host pipeline writes from the same input. Run again, it refused the existing config
and left the token file in place for the retry. (d) left no file behind, and run against a symlink it
removed the link and left the file it pointed at intact, byte for byte. In every case the token
appeared on neither stdout nor stderr. ⚠ The run used neither the real issue command nor a carry
between two hosts: the first real hand delivery exercises both.

#### When the token is issued on the site's Seat tokens page

No shell on the Mezzanine host is needed. An operator signs in to the site, opens the admin console's
*Seat tokens* page (`/admin/tokens`, which asks for the password again), types the seat's `install_id`
and `seat_id` from [the values to substitute](#the-values-to-substitute), and issues. A new pair creates
the install and the seat. The page shows the token once, with its **prefix**: keep the prefix, as the
hand delivery's step (a) does, because Step 8 and every failure below need it. The token then takes the
hand delivery's path from step (c) on, through the same named file:

**(a′) On the seat, write the token into the named file at a hidden prompt.** The token is read from
the terminal with echo off, so it reaches neither the screen nor an argv nor the shell history, and it
is written with mode `0600` into the `0700` directory. The block refuses a file that already exists:

<!-- install.sh: run token-prompt -->
```bash
bash -euo pipefail <<'SH'
  install -d -m 0700 $HOME/.config/fleet-reporter
  F=$HOME/.config/fleet-reporter/mezzanine-reporter-token
  if [ -e "$F" ] || [ -L "$F" ]; then echo "$F already exists: NOTHING WRITTEN" >&2; exit 1; fi
  printf 'Paste the token from the Seat tokens page (it is not shown), then press Enter: ' >/dev/tty
  IFS= read -rs T </dev/tty; printf '\n' >/dev/tty
  umask 077; set -o noclobber; printf '%s\n' "$T" > "$F"
  stat -c '%a %U %n' $HOME/.config/fleet-reporter "$F"
SH
```

Both lines must show the seat account, `700` on the directory and `600` on the file. Then run the hand
delivery's **(c)**, which checks that the file holds one well-formed token and nothing else before it
writes the config, and **(d)** on the seat only: this route leaves no file on the Mezzanine host. If (c)
refuses the content, the paste was not the token: run (d), then (a′) again. If the token was not copied
before the page was left, it cannot be shown again: revoke its prefix on the same page and issue another.

## Step 3 — check the config file

<!-- install.sh: run config-check -->
```bash
bash -euo pipefail <<'SH'
  export FR_CONFIG=$HOME/.config/fleet-reporter/config.json
  stat -c '%a %n' "$(dirname "$FR_CONFIG")" "$FR_CONFIG"
  node -e 'const f = process.env.FR_CONFIG; if (!f) { console.error("FR_CONFIG must name the config file: NOTHING READ"); process.exit(2); } const c=JSON.parse(require("fs").readFileSync(f,"utf8")); const show=(p,o)=>{for (const [k,v] of Object.entries(o)) { if (k==="sites" && Array.isArray(v)) { v.forEach((x,i)=>show("sites["+(x&&x.name||i)+"].",x||{})); continue; } const u=(x)=>{try{const w=new URL(x);if(w.username||w.password){w.username="USER";w.password="";return w.href.replace("USER@","<userinfo>@");}}catch(e){}return x;}; console.log(p+k, k==="token" ? "<" + (/^mzn_[A-Za-z0-9_-]{43}$/.test(v) ? "well-formed" : "MALFORMED") + ">" : JSON.stringify(typeof v==="string" && /_url$/.test(k) ? u(v) : v)); }}; show("",c)'
SH
```

On the sandbox this printed `700` and `600`, and then every key with the token shown only as
`<well-formed>`. Since card#12180 the printer also descends into `sites` and masks every site's token
the same way, printing each site's keys as `sites[<name>].<key>`, and prints a URL that carries a user or a
password (an `ingest_url` or a `proxy_url` with `user:pass@`) with that part shown only as `<userinfo>`.

**Choose what the descriptor carries: the `descriptors` key.** Each tool call reaches the floor with a
short label built from its arguments, after the reporter has replaced the credential, host-name, IP,
email and home-path shapes D1 § 7.3 lists (§ 7.3 also states the shapes it does not catch). `descriptors` decides how much of that label is sent at all:

| Value | What the floor shows for a tool call |
|---|---|
| `"full"`, or the key absent | the sanitized label: `Bash: composer test`, `Read: ~/…/docs/PLAN.md`, and a subagent's title |
| `"paths"` | the file path for `Read`, `Write`, `Edit` and `Glob`; the tool name alone for everything else, and no subagent title |
| `"none"` | the tool name alone, for every tool, and no subagent title |

Under `"paths"` and `"none"` the seat also sends no console link (D1 § 6.3): an operator's drill-down
offers **Open console on claude.ai** only for a seat on `"full"`.

Any other value is a config error: `selftest`'s `config_readable` fails and the flusher sends nothing
until the value is fixed. The events spooled meanwhile are built as under `"none"`. To change the value on an installed seat, rewrite the one key with
the config's mode kept. This reads and writes the file without printing it:

<!-- install.sh: skip — an optional later change; install.sh writes the default, full -->
```bash
FR_CONFIG=$HOME/.config/fleet-reporter/config.json FR_DESCRIPTORS=none node -e 'const fs=require("fs"),f=process.env.FR_CONFIG,v=process.env.FR_DESCRIPTORS; if (!f) { console.error("FR_CONFIG must name the config file: NOTHING WRITTEN"); process.exit(2); } if(!["full","paths","none"].includes(v)) { console.error("FR_DESCRIPTORS must be full, paths or none: NOTHING WRITTEN"); process.exit(2); } const c=JSON.parse(fs.readFileSync(f,"utf8")); c.descriptors=v; fs.writeFileSync(f, JSON.stringify(c,null,2)+"\n", {mode:0o600}); console.log("descriptors =", v)'
```

Every hook reads the config when it fires, so the next tool call uses the new value. Nothing needs a
restart.

**`harness_label` is left unset, on purpose.** D1 § 6.1 wants `claude-code/<version>`, and the reporter
can only read that from this config key (see the note in the README's open-questions table). Claude Code
updates itself, so a version written here once stops being true at the next update, and the desk would
then show a wrong version as a fact. Unset, the field is an honest `null` and the
`harness_label_unset` counter records the gap. That counter raises no badge: an unset key is a correct
install.

## Step 4 — wire the hooks, and optionally the statusLine

There are two procedures, and they use one script:

- **(a) Hooks only.** This is what is applied on the sandbox seat. It adds one entry per hook and
  leaves `statusLine` alone. The desk gets every activity event D1 defines except `context.sample`.
- **(b) Hooks plus the statusLine wrap.** It does (a), then points `statusLine` at the reporter. The
  desk also gets its context gauge, and the seat pays the cost measured below. This is optional, and on
  the sandbox it is deferred.

**The hook set is derived, not listed.** The script reads the names of the installed artifact's
`fixtures/hooks/*.json`, one file per hook D1 § 6.0 subscribes (§ 2.1). A hook added to § 6.0 arrives with
its fixture, so a re-run wires it and cannot silently leave it out. A hook whose fixture payloads all carry
`tool_name` gets matcher `"*"`, and every other hook gets `""`, the convention the file already uses.
Each entry goes to the **end** of that hook's array. The script removes, reorders and rewrites nothing
else. It is idempotent. With a fourth argument, the prior statusLine command, it also wraps the
statusLine, and it refuses to write if the current statusLine is neither the reporter nor that command.
So the config's `wrapped_statusline` and the live file cannot disagree.

A second script checks the result against the original: every top-level key other than `hooks` and
`statusLine` is unchanged, every existing hook entry is still in place, every added entry is the
reporter's, and a changed statusLine can only be the reporter.

<!-- install.sh: run hooks-scripts -->
```bash
bash -euo pipefail <<'SH'
  cat > "$B/wire-hooks.js" <<'JS'
const fs = require("fs"), path = require("path");
const [src, dst, js, prior] = process.argv.slice(2);
if (!src || !dst || !js) { console.error("usage: node wire-hooks.js <settings.json> <output> <fleet-reporter.js> [<prior statusLine command>]: NOTHING WRITTEN"); process.exit(2); }
const fixtures = path.join(path.dirname(js), "fixtures", "hooks");
const set = fs.readdirSync(fixtures).filter((f) => f.endsWith(".json")).sort().map((f) => {
  const shapes = JSON.parse(fs.readFileSync(path.join(fixtures, f), "utf8")).shapes;
  return { hook: f.slice(0, -5), tool: shapes.every((p) => typeof p.tool_name === "string") };
});
const s = JSON.parse(fs.readFileSync(src, "utf8"));
s.hooks = s.hooks || {};
const added = [];
for (const { hook, tool } of set) {
  const command = "node " + js + " hook " + hook;
  const list = (s.hooks[hook] = s.hooks[hook] || []);
  if (list.some((e) => (e.hooks || []).some((x) => x.command === command))) continue;
  list.push({ matcher: tool ? "*" : "", hooks: [{ type: "command", command }] });
  added.push(hook);
}
if (prior !== undefined) {
  const sl = "node " + js + " statusline";
  const current = s.statusLine && s.statusLine.command ? s.statusLine.command : "";
  if (current !== sl) {
    if (current !== prior) {
      console.error("statusLine is [" + current + "], not the prior command given [" + prior + "]: NOTHING WRITTEN");
      process.exit(1);
    }
    s.statusLine = Object.assign({}, s.statusLine, { type: "command", command: sl });
    added.push("statusLine (was: " + current + ")");
  }
}
console.log("hook set, from " + fixtures + ": " + set.map((h) => h.hook + (h.tool ? "(*)" : "")).join(" "));
// Nothing to add: the output is the input byte for byte, so the apply step finds nothing to copy.
if (added.length) fs.writeFileSync(dst, JSON.stringify(s, null, 2) + "\n"); else fs.copyFileSync(src, dst);
JSON.parse(fs.readFileSync(dst, "utf8"));
console.log("valid JSON written to " + dst + "; added: " + (added.join(", ") || "nothing"));
JS
  cat > "$B/check-preserved.js" <<'JS'
const fs = require("fs");
const [before, after, js] = process.argv.slice(2);
if (!before || !after || !js) { console.error("usage: node check-preserved.js <before.json> <after.json> <fleet-reporter.js>: NOTHING CHECKED"); process.exit(2); }
const a = JSON.parse(fs.readFileSync(before, "utf8")), b = JSON.parse(fs.readFileSync(after, "utf8"));
const same = (x, y) => JSON.stringify(x) === JSON.stringify(y);
const bad = [];
for (const k of new Set([...Object.keys(a), ...Object.keys(b)])) {
  if (k === "hooks") continue;
  if (k === "statusLine" && !same(a[k], b[k])) {
    if (b[k].command !== "node " + js + " statusline") bad.push("statusLine changed to something other than the reporter");
    else console.log("statusLine: wrapped (was: " + (a[k] && a[k].command) + ")");
  } else if (!same(a[k], b[k])) bad.push("top-level key " + k + " changed");
}
const ah = a.hooks || {}, bh = b.hooks || {};
let kept = 0, added = 0;
for (const h of Object.keys(ah)) {
  if (!same((bh[h] || []).slice(0, ah[h].length), ah[h])) bad.push("hook " + h + ": an existing entry was removed, reordered or rewritten");
  else kept += ah[h].length;
}
for (const h of Object.keys(bh)) {
  for (const e of bh[h].slice((ah[h] || []).length)) {
    if (!same(e.hooks, [{ type: "command", command: "node " + js + " hook " + h }])) bad.push("hook " + h + ": an added entry is not the reporter's");
    else added += 1;
  }
}
console.log("existing hook entries kept in place: " + kept + "; reporter entries added: " + added);
if (bad.length) { console.error("NOT PRESERVED:\n  " + bad.join("\n  ")); process.exit(1); }
console.log("every other key and every existing hook entry is unchanged");
JS
  node --check "$B/wire-hooks.js" && node --check "$B/check-preserved.js" && echo scripts-saved
SH
```

**(a) Hooks only**, staged in `$B`, checked, and only then copied over the live file:

<!-- install.sh: run hooks-apply -->
```bash
bash -euo pipefail <<'SH'
  node "$B/wire-hooks.js" $HOME/.claude/settings.json "$B/settings.wired.json" $HOME/.local/share/fleet-reporter/fleet-reporter.js
  node "$B/check-preserved.js" $HOME/.claude/settings.json "$B/settings.wired.json" $HOME/.local/share/fleet-reporter/fleet-reporter.js
  if cmp -s "$B/settings.wired.json" $HOME/.claude/settings.json; then echo "already wired: nothing applied"
  else cp "$B/settings.wired.json" $HOME/.claude/settings.json && echo applied; fi
SH
```

When `wire-hooks.js` adds nothing it copies the file byte for byte, so on a seat already wired the block
prints `already wired: nothing applied` and leaves the live file untouched, its bytes and its mtime.

**(b) Hooks plus the statusLine wrap.** This is the same block with the prior statusLine command as a
fourth argument to `wire-hooks.js`. On the sandbox that argument would be `"bash ~/.local/bin/context-sensor.sh ''"`,
Step 2's `FR_WRAPPED`. Over a file (a) has already wired, it adds only the statusLine.

The sandbox's live hooks were applied on 2026-09-13 by the operator's session, with an additive
hooks-only script. The attempt directory is `20260913T203739Z-hooks`, it wired D1 § 6.0's set, and the
seat's `tool.start` and `tool.end` events have arrived since. The scripts above were then run against
**copies**, and this is what they showed:

- **(a) on a copy of that attempt's pre-hooks backup** printed the derived set,
  `Notification PermissionDenied(*) PermissionRequest(*) PostCompact PostToolUse(*) PostToolUseFailure(*)
  PreCompact PreToolUse(*) SessionEnd SessionStart Stop StopFailure SubagentStart SubagentStop
  UserPromptSubmit`, which is D1 § 6.0's subscribed set. It added one entry for each.
  `check-preserved.js` passed. The result equals the live `settings.json` in every value and every
  array order, which a canonical comparison showed. Only the order of the newly added hook keys differs,
  and JSON object key order carries no meaning (RFC 8259 § 4).
- **(a) run a second time** over its own output printed `added: nothing` and wrote a byte-identical
  file. Run over a copy of the live file, it also added nothing.
- **(b) on the same backup** added every hook and then the statusLine, and `check-preserved.js`
  passed. A second run added nothing and wrote a byte-identical file. Over (a)'s output, (b) added
  only the statusLine, and the result equalled (b) run from scratch.
- **(b) naming a different prior statusLine** exited 1, printed `NOTHING WRITTEN`, and created no file.
- **The control.** `check-preserved.js`, given a copy with one existing `PreToolUse` entry removed,
  exited 1 and named that hook.

**What (b) costs the seat, measured on the sandbox host on 2026-09-13.** These are measurements, not
derived figures. The host was under load, with a load average of 9 to 11 on 4 cores. The run used a
throwaway config and spool, so nothing reached the server or the live context-sensor file:

| Invocation | Wall time, 10 runs |
|---|---|
| `hook PreToolUse` on the vendored fixture | 247–297 ms, rc 0 every run, 0 B on stdout |
| the current statusLine alone (`context-sensor.sh`) | 42–51 ms |
| `statusline` wrapping that same command | 309–505 ms, rc 0, byte-identical output (`ctx 42%`) |

The reporter's hook time is mostly `node` starting up; the README's latency section covers that.
Claude Code runs a hook event's commands in parallel, so a tool call waits for its slowest hook, not
the sum of them. That cost is (a)'s, and the seat already pays it. **The statusLine is the cost (b)
adds.** Wrapped, the context sensor writes its percentage later than it does today, by the wrapper's
time minus the sensor's time in the table. Claude Code cancels a status-line script when a new render
arrives, so under rapid renders the sensor's file can lag, and the coord framework's context budget
reads that file.

## Step 5 — supervise the flusher from the user crontab

This step writes a marked block **outside** `bin/supervision.sh`'s `# BEGIN/END mezzanine-supervision`
block, and never edits that one. It is also the step you re-run when a value in the start line
changes, such as `$COORD_CONFIG` or `node`. It does five things:

- It **replaces** any existing `fleet-reporter-flusher` block, with the same `sed` as Step 8's
  rollback, and never appends a second one.
- It refuses a crontab whose `BEGIN` and `END` markers do not pair up, because the `sed` range would
  otherwise delete everything after an unterminated `BEGIN`.
- It re-resolves `node` and `$COORD_CONFIG` each run.
- When the result equals the installed crontab byte for byte, it writes nothing and restarts nothing.
- When it does write, it **stops the running flusher**, because a running flusher keeps the
  environment it started with. The next start, at cron's next minute boundary or from a sooner hook,
  then runs with the new values.

First save the two helpers it uses. Step 8 uses them too. `stop-flusher.js` sends SIGTERM to the pid
`flusher.lock` names, but only if that pid is running this artifact's `flusher`. A stale lock's pid may
have been reused by an unrelated process. The script then polls until the process exits, and derives
how long to wait from the artifact's own constants rather than from a figure written here. After
SIGTERM every loop of the process finishes the pass it is in, and its `K.FLUSH_MS` sleep ends at once.
Since card#12180 the slowest of those is one site's lane, whose pass awaits one health request and
`laneDrain`'s rounds, one `postBatch` each (a `413` is answered by the next round's smaller request),
and the lanes run at the same time; every request is bounded by `K.REQUEST_MS`. The bound the script
computes still allows two requests a round, as it did for the build before, so it is an upper bound
with a round's margin. `flusher-ancestry.js` walks the lock holder's parents and says whether cron's
start runs it; since card#12180 it also says whether the holder started after the artifact was
installed, which is the check Step 1's upgrade order ends with. Given `--upgrade` as a third argument
it asks only that second question, and passes whoever started the holder (card#11818), because after
an upgrade's stop a hook usually starts the flusher before cron does:

<!-- install.sh: run cron-helpers -->
```bash
bash -euo pipefail <<'SH'
  cat > "$B/stop-flusher.js" <<'JS'
const fs = require("fs"), path = require("path");
const [spool, js] = process.argv.slice(2);
if (!spool || !js) { console.error("usage: node stop-flusher.js <spool dir> <fleet-reporter.js>: NOTHING SENT"); process.exit(2); }
const src = fs.readFileSync(js, "utf8");
const num = (re, what) => { const m = re.exec(src); if (!m) { console.error("cannot read " + what + " from " + js + ": no bound, NOTHING SENT"); process.exit(2); } return Number(m[1]); };
const FLUSH_MS = num(/\bFLUSH_MS:\s*(\d+)/, "K.FLUSH_MS"), REQUEST_MS = num(/\bREQUEST_MS:\s*(\d+)/, "K.REQUEST_MS");
const ROUNDS = num(/for \(let round = 0; round < (\d+);/, "laneDrain's round cap");
const boundMs = FLUSH_MS + REQUEST_MS * (1 + 2 * ROUNDS);
let pid;
try { pid = JSON.parse(fs.readFileSync(path.join(spool, "flusher.lock"), "utf8")).pid; } catch (e) { console.log("no readable flusher.lock: no flusher to stop"); process.exit(0); }
let cmd = null;
try { cmd = fs.readFileSync("/proc/" + pid + "/cmdline", "utf8").split("\0").join(" "); } catch (e) { /* not running */ }
if (cmd === null) { console.log("flusher.lock names pid " + pid + ", which is not running: the lock is stale, no flusher to stop"); process.exit(0); }
if (!cmd.includes(js + " flusher")) { console.error("flusher.lock names pid " + pid + ", which runs [" + cmd.trim() + "], not " + js + " flusher: NOTHING SENT"); process.exit(1); }
process.kill(pid, "SIGTERM");
const t0 = Date.now();
const poll = setInterval(() => {
  if (!fs.existsSync("/proc/" + pid)) { clearInterval(poll); console.log("flusher pid " + pid + " exited " + Math.round((Date.now() - t0) / 1000) + " s after SIGTERM"); process.exit(0); }
  if (Date.now() - t0 > boundMs) { clearInterval(poll); console.error("flusher pid " + pid + " still running " + boundMs / 1000 + " s after SIGTERM, past K.FLUSH_MS + K.REQUEST_MS x (1 + 2 x laneDrain rounds): NOT STOPPED"); process.exit(1); }
}, 1000);
JS
  cat > "$B/flusher-ancestry.js" <<'JS'
const fs = require("fs"), path = require("path");
const [spool, js, mode] = process.argv.slice(2);
if (!spool || !js) { console.error("usage: node flusher-ancestry.js <spool dir> <fleet-reporter.js> [--upgrade]"); process.exit(2); }
if (mode !== undefined && mode !== "--upgrade") { console.error("unknown mode [" + mode + "]: the only one is --upgrade"); process.exit(2); }
let pid;
try { pid = JSON.parse(fs.readFileSync(path.join(spool, "flusher.lock"), "utf8")).pid; } catch (e) { console.log("FAIL: no readable flusher.lock, so no flusher holds the lock"); process.exit(1); }
const chain = [];
for (let p = pid; p > 1;) {
  let stat, cmd;
  try { stat = fs.readFileSync("/proc/" + p + "/stat", "utf8"); cmd = fs.readFileSync("/proc/" + p + "/cmdline", "utf8").split("\0").join(" ").trim(); } catch (e) { break; }
  const r = stat.lastIndexOf(")");
  chain.push({ p, comm: stat.slice(stat.indexOf("(") + 1, r), cmd });
  p = Number(stat.slice(r + 2).split(" ")[1]);
}
for (const c of chain) console.log(String(c.p).padStart(8) + "  " + c.comm.padEnd(8) + " " + c.cmd.slice(0, 150));
if (!chain.length || !chain[0].cmd.includes(js + " flusher")) { console.log("FAIL: flusher.lock names pid " + pid + ", which is not a running " + js + " flusher"); process.exit(1); }
// The holder's start (field 22 of /proc/<pid>/stat, clock ticks since boot) against the artifact's mtime: a
// holder older than the artifact runs the build that was there before it (card#12180, Step 1).
const ticks = Number(fs.readFileSync("/proc/" + pid + "/stat", "utf8").replace(/^.*\) /s, "").split(" ")[19]);
const btime = Number(/^btime (\d+)$/m.exec(fs.readFileSync("/proc/stat", "utf8"))[1]);
const hz = Number(require("child_process").execFileSync("getconf", ["CLK_TCK"]).toString().trim());
const startedMs = (btime + ticks / hz) * 1000, builtMs = fs.statSync(js).mtimeMs;
if (startedMs < builtMs) { console.log("FAIL: the lock holder started " + new Date(startedMs).toISOString() + ", before the artifact was installed at " + new Date(builtMs).toISOString() + ": it runs the previous build"); process.exit(1); }
console.log("the lock holder started " + new Date(startedMs).toISOString() + ", after the artifact's " + new Date(builtMs).toISOString());
// --upgrade asks only whether the flusher runs the new build. Who started it is Step 5's question: after a stop, a
// hook's start usually wins the race to the lock before cron's next minute, and that is correct (card#11818).
if (mode === "--upgrade") { console.log("PASS: the lock holder runs the artifact installed at " + new Date(builtMs).toISOString() + "; who started it is not checked in --upgrade mode (Step 5's check is the one for cron's line)"); process.exit(0); }
const flock = chain[1] && chain[1].comm === "flock" && chain[1].cmd.includes(js);
const cron = chain.some((c) => /^cron$/i.test(c.comm));
if (flock && cron) { console.log("PASS: the lock holder was started by cron through flock"); process.exit(0); }
console.log("FAIL: the lock holder's parents include " + (flock ? "flock" : "no flock") + " and " + (cron ? "cron" : "no cron") + ". A flusher cron did not start holds the lock (a hook's, or one run by hand), so cron's line is NOT verified");
process.exit(1);
JS
  node --check "$B/stop-flusher.js" && node --check "$B/flusher-ancestry.js" && echo helpers-saved
SH
```

Then write the block:

<!-- install.sh: run cron-write -->
```bash
bash -euo pipefail <<'SH'
  JS=$HOME/.local/share/fleet-reporter/fleet-reporter.js
  SPOOL=$HOME/.local/state/fleet-reporter
  FLOCK=$HOME/.local/state/fleet-reporter-flusher.flock
  NODE=$(readlink -f "$(command -v node)")
  CC=$(node "$B/coord-config.js" $HOME/.claude/settings.json $HOME/.claude/settings.local.json)
  case "$JS$FLOCK$NODE$CC" in *[[:space:]%]*) echo "a crontab path holds whitespace or %: NOTHING WRITTEN" >&2; exit 1 ;; esac
  LINE="${CC:+COORD_CONFIG=$CC }flock -n $FLOCK $NODE $JS flusher >/dev/null 2>&1"
  read_crontab() { crontab -l 2> "$B/crontab.err" || grep -q '^no crontab for ' "$B/crontab.err" || { cat "$B/crontab.err" >&2; return 1; }; }
  read_crontab > "$B/crontab.before"
  begins=$(grep -c '^# BEGIN fleet-reporter-flusher$' "$B/crontab.before" || true)
  ends=$(grep -c '^# END fleet-reporter-flusher$' "$B/crontab.before" || true)
  [ "$begins" = "$ends" ] || { echo "BEGIN/END fleet-reporter-flusher markers do not pair ($begins/$ends): NOTHING WRITTEN" >&2; exit 1; }
  sed '/^# BEGIN fleet-reporter-flusher$/,/^# END fleet-reporter-flusher$/d' "$B/crontab.before" > "$B/crontab.new"
  printf '%s\n' '# BEGIN fleet-reporter-flusher' \
    '# D1 § 2.3 supervised start, no root: fleet-reporter/INSTALL-LINUX.md in PupFuzz/mezzanine. flock -n makes each start a no-op while one runs.' \
    "* * * * * $LINE" "@reboot $LINE" '# END fleet-reporter-flusher' >> "$B/crontab.new"
  if cmp -s "$B/crontab.before" "$B/crontab.new"; then echo "crontab already current: nothing written, nothing restarted"; exit 0; fi
  diff "$B/crontab.before" "$B/crontab.new" || true
  crontab "$B/crontab.new"
  crontab -l | cmp - "$B/crontab.new"
  echo "installed: $(crontab -l | md5sum)"
  node "$B/stop-flusher.js" "$SPOOL" "$JS"
SH
```

On the first install, the block was appended by hand with the same lines, and the md5 went from
`bcd71d369c6c73ed8e61c819e3e2fb4d` to `ed4cc42653b11607b1201a73b22e693e`.

**What the rewritten block showed**, run on copies with a stub `crontab` on `PATH` that read and wrote
a scratch file and logged every call. The spool was a scratch directory, so no real flusher was ever
signalled. Pre-install means Step 0's backup of the crontab, and live means a copy of the crontab as
installed:

- **Pre-install:** it appended the block, and the result was byte-identical to the live crontab. A
  second run printed `crontab already current` and made no install call, and the crontab stayed
  byte-identical.
- **Live, with the seat's current values:** `crontab already current`, no install call, and no
  restart.
- **A changed `$COORD_CONFIG`**, given by a copy of `settings.local.json`: the diff swapped the two
  entry lines, and the installed crontab held exactly one `fleet-reporter-flusher` block, carrying the
  new value. A stand-in flusher process named by the scratch `flusher.lock` got SIGTERM and exited. A
  second run with the same value printed `crontab already current`.
- **A crontab with a `BEGIN` and no `END`:** it refused, and the stub logged no install call.
- **A staged write that fails,** with `$B/crontab.new` pre-created as a directory: the block stopped at
  the failed write, and the stub logged `crontab -l` and no install call.
- **A lock naming a live process that is not the flusher:** `stop-flusher.js` exited 1, printed
  `NOTHING SENT`, and the process kept running. **A stand-in that ignores SIGTERM,** under a stand-in
  artifact with small constants: it exited 1 with `NOT STOPPED` once the derived bound had passed.

- **`COORD_CONFIG=` is set on the command line itself**, which is D1 § 3.1's delivery contract (see
  above). When no settings file sets it, the resolver prints nothing and the line carries no
  assignment. § 3.1 says to deliver nothing then.
- **Output goes to `/dev/null`**, because the flusher's diagnostics live in its own log,
  `<spool>/log/`, where secrets are redacted (P-6). A raw stderr capture has no such guarantee.
- **The flock file sits beside the spool, not inside it**, because D1 § 11.1 owns the spool's layout.

**`bin/supervision.sh install` still accepts this crontab.** Its refusal fires on an `artisan` command
it supervises that sits outside its block, and the flusher block contains no `artisan`. The plan was
run as that script would run it, which writes nothing. A control then fed it one hand-staged `artisan
mezzanine:fold` line, to show the refusal can fire:

<!-- install.sh: skip — whether bin/supervision.sh still accepts the crontab, which matters on the server's own host -->
```bash
cd <checkout> && source bin/supervision.sh
( supervision_install_plan /home/mezzanine/mezzanine /usr/bin/php8.5 > /dev/null ); echo "plan rc=$?"        # -> plan rc=0
( crontab() { command crontab -l; echo '* * * * * cd /x/server && php artisan mezzanine:fold'; }
  supervision_install_plan /home/mezzanine/mezzanine /usr/bin/php8.5 > /dev/null ); echo "control rc=$?"   # -> refused, rc=1
```

**Check that cron started it, and not a hook.** It is not enough that *some* flusher runs. A flusher a
hook spawned holds the same lock and heartbeats just as well, so it can hide a cron line that never
works. After the next minute boundary:

<!-- install.sh: run cron-ancestry -->
```bash
node "$B/flusher-ancestry.js" $HOME/.local/state/fleet-reporter $HOME/.local/share/fleet-reporter/fleet-reporter.js
```

`PASS` needs the lock holder's parent to be `flock` running this artifact, and `cron` among its
ancestors. Before its verdict it prints the lock holder's process chain, one line per process with its pid,
the flusher first, then whether the holder started after the artifact was installed. On the sandbox, read-only on 2026-09-13, it printed `node` ← `flock -n …` ←
`/bin/sh -c COORD_CONFIG=… flock -n …` ← `CRON`, and `PASS`. Its control was a stand-in `node … flusher`
started from a shell and named by a scratch lock, and it printed `FAIL`.

A `FAIL` whose lock holder has no `flock` and `cron` parents means cron's line is unverified, not that it
is broken.
While hooks are wired, a hook spawns a flusher whenever it finds no fresh lock. So on a seat whose
sessions are working, a hook usually wins the race after a stop, before cron's next minute. Check
again after running `stop-flusher.js` at a moment when no session on the account is doing work.

**Recovery, measured.** The first install's test sent the flusher SIGKILL, the unclean death, which
leaves `flusher.lock` behind. Hooks were not wired then, so only cron could take over. It polled until a
new pid held the lock, and walked that pid's parents:

```
19:37:56 killing flusher pid 662767 with SIGKILL; lock mtime 19:37:52
19:40:08 new flusher pid 665727 holds the lock (lock mtime 19:40:03); ancestry:
 665727  665725 node   /usr/bin/node /home/mezzanine/.local/share/fleet-reporter/fleet-reporter.js flusher
 665725  665722 flock  flock -n /home/mezzanine/.local/state/fleet-reporter-flusher.flock /usr/bin/node …
 665722  665707 sh     /bin/sh -c COORD_CONFIG=/home/mezzanine/.config/coord/coordination.config.json flock -n …
 665707     403 cron   /usr/sbin/CRON -f -P
```

The flusher's own log agrees with the derivation above. The 19:38 and 19:39 starts each logged
`another flusher owns the lock; exiting`, because the dead flusher's lock, last touched at 19:37:52,
was still younger than `K.LOCK_STALE_MS`. The 19:40 start was the first minute boundary after the lock
went stale, and it took over. It kept the existing `state.json`, so no second state reset followed.

## Step 6 — selftest

<!-- install.sh: run selftest -->
```bash
node $HOME/.local/share/fleet-reporter/fleet-reporter.js selftest; echo "selftest rc=$?"
```

The command measures the network checks itself, with the flusher's own health probe against each site's
`ingest_url` and `ca_file` (card#9373), through that site's `proxy_url` when it sets one, the route the
batches take (card#9473). Since card#12180 the report carries a `sites` object, one block per site keyed
by its name (`default` for a config written before card#12180), with that site's `config_readable`,
`tls_verify`, `schema_version_accepted` and `token_accepted`, and `detail.sites.<name>` with its errors and
its probe's `reached`, `probe_error`, `http_status` and accepted set. `checks` aggregates the four: `fail`
when any site fails, else `not_measured` when any site is unmeasured, else `pass`. Read its exit code by
D1 § 6.14:

- **`rc=0`** — every check passed. The install is verified.
- **`rc=1`** — a check failed; `checks` names it, `sites` names the site, and `detail` says why. A
  `tls_verify` fail with an empty `detail.tls_verify.forbidden_spellings_present` means a TCP connection
  was made and the TLS handshake failed; `detail.sites.<name>.probe_error` names the error, and the usual
  cause is a `ca_file` that does not trust the ingest's certificate. A `token_accepted` fail is a site
  that answered the probe `401`: its token does not resolve there, mistyped, swapped with another site's,
  or revoked; rotate it ([Rotating a site's token](#rotating-a-sites-token)). ⚠ A token the site issued
  for another seat's pair passes this check (the probe compares no identity) and is refused on the first
  batch; Step 7 is where that shows.
- **`rc=1` with `config_readable` failing** — `detail.config_readable.errors` names each rule the
  config breaks, and the command runs no probe. `ca_file unreadable at <path>: <errno>` means the seat
  cannot read the CA file its config pins: it was moved, its permissions changed, or the path has a
  typo. `ca_file must be an absolute path or null (§ 3.1), not "<value>"` means the config holds an
  empty string or a relative path: set the file's absolute path, or `null` for a seat that trusts the
  system store. The reporter never falls back to the system trust store (D1 § 3.5): the flusher sends
  nothing and keeps spooling, and logs the same error. Fix the file or the config and re-run this step.
  `ingest_url is not a parseable URL (§ 3.1)`, `proxy_url must be an absolute http:// or https:// URL,
  or null (§ 3.1)` and `spool_dir must be an absolute path (§ 3.1)` mean the value breaks its D1 § 3.1
  format: a space or a missing host in a URL, a proxy written without its `http://` scheme, the empty
  string for "no proxy" (write `null`), or a relative spool path. A seat whose `spool_dir` is not absolute
  records nothing at all until it is fixed: no spool, no log, and no flusher. The URL errors deliberately leave the
  value out, because it may hold a proxy password; read the config file itself.
  A running flusher re-reads `ca_file` on each pass: once the file is readable it probes and sends the
  spooled events, logs `ca_file readable at <path>`, and needs no restart; a file that becomes
  unreadable stops its sending, and its heartbeat's `config_readable` reads `fail`, from the next pass. The flusher
  reads its config only when it starts, so after a config change (an absolute path, or `null`) stop it
  with Step 5's `stop-flusher.js`, as Step 8 item 3 runs it:
  `node "$B/stop-flusher.js" $HOME/.local/state/fleet-reporter $HOME/.local/share/fleet-reporter/fleet-reporter.js`.
  A clean stop removes its lock, so the next start, at cron's next minute boundary or from a sooner
  hook, reads the corrected config.
- **`rc=1` with `protocol_agent_name_in_roster` failing** — the declared `protocol_agent_name` is not
  a member of the roster the command read, which D1 § 3.1 calls `disagreed`.
  `detail.protocol_agent_name_in_roster` names the roster file (`roster`), which site it came from
  (`read_via`: `$COORD_CONFIG` or `home`) and the names that roster holds (`roster_names`). When
  `detail.protocol_agent_name_in_roster.malformed_declaration` is not `null`, the check failed for
  another reason: the declared value is not a valid name by D1 § 3.1, and that field shows the value,
  or its type for a non-string. The seat still reports, as `undeclared`, until the config is fixed.
  The same detail's `roster_role` shows the role the seat relays from its roster entry (card#11144),
  or why it relays `null`: *no role: the entry carries none*, *not a slug: …*, or *ambiguous: N
  entries named X* when two roster entries share the declared name. None of those fails the command.
- **`rc=1` with `rt_name_ok` failing** — the declared `roundtable_agent_name` differs from
  `cross_project.identity` in the coordination config the command read, or the key holds a value that is
  not a valid name (card#12239). `detail.rt_name_ok` names the config (`config`), the identity it found
  (`cross_project_identity`) and, for an invalid value, `malformed_declaration`. Copy the identity into
  the reporter config, or remove the key; the seat keeps reporting either way.
- **`rc=2`** — no check failed and at least one is `not_measured`. A site's probe reached no ingest
  (`detail.sites.<name>.probe_error`), or the ingest answered without its accepted set
  (`detail.sites.<name>.http_status`): an outage page, or a `401`/`403` something in front of the ingest
  gave rather than the ingest itself. A `401` from the ingest is `rc=1`, above. Re-run once the ingest
  answers.

**The roster check reads the environment the command runs in** (D1 § 3.1). The flusher gets
`$COORD_CONFIG` from Step 5's crontab line, and a shell that has not exported it makes `selftest` read
the home path instead. `detail.protocol_agent_name_in_roster.read_via` says which it read. To check
the roster the flusher reads, run the command with the value Step 5 resolved in the environment.

**On the sandbox seat, 2026-10-23**, run with `COORD_CONFIG` set to Step 5's value, around Step 1's upgrade.
The build of card#11817 (`4d7f317e`) exited `rc=0` with seven checks `pass`: `config_readable`,
`tls_verify`, `schema_version_accepted`, `sanitizer_fixtures`, `predicate_discrimination`,
`harness_payload_keys` and `protocol_agent_name_in_roster`, and no `sites` member. Card#12180's build, on
the same config, exited `rc=0` with the same seven `pass` and `token_accepted` `pass` beside them, and
`sites.default` holding `config_readable`, `tls_verify`, `schema_version_accepted` and `token_accepted`, each
`pass`; `detail.sites.default` read `reached: true`, `probe_error: null`, `http_status: 200`,
`accepted_schema_versions: [1]`. With the stub site added ([Adding a site](#adding-a-site)) it exited `rc=0`
with `sites.stub` the same. No output carried a token.

Before card#9373 the one-shot was exercised against the acceptance suite's TLS ingest stub only
(`fleet-reporter.selftest.py` § 1). The card#9368 run on the sandbox used the build before card#9373. There five checks passed: `config_readable`, `tls_verify`,
`sanitizer_fixtures`, `predicate_discrimination` and `harness_payload_keys`, the last for every hook in
the fixture set. `schema_version_accepted` was `fail` and the command exited `rc=1`, because that build's
one-shot never probed the ingest and so failed the check on every seat. Its `tls_verify` pass checked
the source alone. Step 7 reads the heartbeat's `selftest` object, which the flusher measures against the
real host, and on the sandbox every check there was `pass`.

## Step 7 — verify that events arrive

**Run this step for each site**, against that site's own store. Since card#12180 it is also the check for
the one token mistake Step 6 cannot see, a token issued for another seat's pair: such a site shows no
events from this seat, and the seat's `REJECTED.<name>.txt` holds a `403 identity_mismatch` line.

**On the seat.** The spool fills and drains, and the flusher logs to its own directory, each site's
lines prefixed `flusher[<name>]`:

<!-- install.sh: skip — install.sh reads the same state with the arrival block below -->
```bash
ls -la $HOME/.local/state/fleet-reporter/ && tail -n 5 $HOME/.local/state/fleet-reporter/log/*.log
```

The count is written `-n 5`: this host's `tail` is uutils coreutils 0.10.0, which refuses the older `-5`
spelling once the glob names more than one log file (`error: unexpected argument '-5' found`).

**Whether each site has accepted a batch, from the seat** (card#7336). Every site's entry in the
flusher's `state.json` counts the batches that site answered as accepted (`batches_ok`, D1 § 9.3), and a
site that refused one is named by `REJECTED.<name>.txt` in the spool (D1 § 11.5). This reads both, for
every site of the config, through the artifact's own config reader. It waits up to `FR_WAIT_S` seconds,
long enough for cron's next minute to start a flusher after Step 5 stopped one, and prints no token:

<!-- install.sh: run arrival -->
```bash
( FR_REPORTER=$HOME/.local/share/fleet-reporter/fleet-reporter.js FR_CONFIG=$HOME/.config/fleet-reporter/config.json FR_WAIT_S=150 node -e '
const fs = require("fs"), path = require("path");
const js = process.env.FR_REPORTER, cfg = process.env.FR_CONFIG, wait = Number(process.env.FR_WAIT_S);
if (!js || !cfg || !(wait >= 0)) { console.log("FR_REPORTER, FR_CONFIG and FR_WAIT_S must each be set: NOT MEASURED"); process.exit(2); }
const { spool, sites } = require(js).loadConfig(cfg);
if (!spool || !sites.length) { console.log("the config names no spool or no site: NOT MEASURED, Step 6 names why"); process.exit(2); }
const t0 = Date.now();
const state = () => { try { return JSON.parse(fs.readFileSync(path.join(spool, "state.json"), "utf8")); } catch (e) { return null; } };
const look = () => {
  const st = state(), lines = [];
  let waiting = 0, refused = 0;
  for (const s of sites) {
    const name = s.name || "sites[" + s.index + "]";
    const e = st && st.sites && s.name && Object.prototype.hasOwnProperty.call(st.sites, s.name) ? st.sites[s.name] : null;
    const ok = (e && e.counters && e.counters.batches_ok) || 0;
    if (s.name && fs.existsSync(path.join(spool, "REJECTED." + s.name + ".txt"))) { refused += 1; lines.push(name + ": a batch was REFUSED; REJECTED." + s.name + ".txt in " + spool + " names the status"); }
    else if (ok) lines.push(name + ": " + ok + " batch(es) accepted: events arrive");
    else { waiting += 1; lines.push(name + ": no batch accepted yet: NOT ARRIVED"); }
  }
  if (!refused && waiting && Date.now() - t0 < wait * 1000) return setTimeout(look, 5000);
  for (const l of lines) console.log(l);
  process.exit(refused ? 1 : waiting ? 2 : 0);
};
look();
'; echo "arrival rc=$?" )
```

*Check:* every site prints `batch(es) accepted: events arrive`, and the block ends `arrival rc=0`. `rc=1`
is a site that refused a batch: the file it names carries the status, and [Rotating a site's
token](#rotating-a-sites-token) reads a `401` and a `403 identity_mismatch`. `rc=2` is a site that has
accepted nothing within the wait: no flusher ran, or the site did not answer. The log above, and
[Step 5](#step-5--supervise-the-flusher-from-the-user-crontab)'s check of who started the flusher, say which.

**In the server's store** (read-only). ⚠ **This runs on the server's host only**: `$HOME/mezzanine/server`
is the sandbox's server checkout and `mezzanine` / `mezzanine-solo` are its `install_id` and `seat_id`
([The values to substitute](#the-values-to-substitute)). A seat on another host has no such checkout; its
reading is the seat-side check above and [the upgrade's U8](#upgrading-an-existing-seat), which asks the
server for the build it holds current, and the server's operator runs this query for it:

<!-- install.sh: skip — the store query runs on the server's host -->
```bash
cd $HOME/mezzanine/server && php artisan tinker --execute='
$s = DB::table("seats")->join("installs", "installs.id", "=", "seats.install_ref")->where("installs.install_id", "mezzanine")->where("seats.seat_id", "mezzanine-solo")->first(["seats.id", "seats.created_at"]);
echo "seat_ref {$s->id} created {$s->created_at}", PHP_EOL;
foreach (DB::table("events")->where("seat_ref", $s->id)->select("kind", DB::raw("count(*) as n"), DB::raw("max(received_at) as last"))->groupBy("kind")->get() as $r) echo "  {$r->kind} n={$r->n} last={$r->last}", PHP_EOL;
foreach (DB::table("events")->where("seat_ref", $s->id)->where("kind", "reporter.heartbeat")->orderByDesc("id")->limit(3)->get(["seq", "event_time", "data"]) as $h) { $d = json_decode($h->data, true); echo "  heartbeat seq={$h->seq} at {$h->event_time} degraded=" . json_encode($d["degraded"]) . " selftest=" . json_encode($d["selftest"]), PHP_EOL; }
'
```

On the sandbox at 19:47, before the hooks were wired, this printed `seat_ref 1`, then
`reporter.heartbeat n=10`, then heartbeats with seq 8, 9 and 10 at 19:45:04, 19:46:04 and 19:47:04,
each with every `selftest` member `pass`. Heartbeats arrive 60 s apart (D1 § 9.1). The one gap in that
run is Step 5's kill test. At that point the snapshot below showed the seat `link_state: live` and
`render_state: unknown` with `unknown_reason: no_data_yet`.

**Since the hooks were wired**, a session that does work adds activity rows. The seat's own spool
shows them from 20:37:56 UTC on 2026-09-13: `tool.start`, `tool.end`, `turn.start`, `turn.end`,
`subagent.spawn`, `subagent.stop`, `attention.request` and `attention.resolved`. A later heartbeat's
counters had `events_sent` equal to `events_emitted`, with `batches_rejected` at 0 (on that build: since
card#12180 the heartbeat is no spool line, so `events_sent` exceeds `events_emitted` by the heartbeats sent). No `session.start`
has appeared yet, because the session running then had started before the hooks were wired. That
reading is from the seat. The store query above was not re-run for the hook events.

**Per site, on 2026-10-23**, after Step 1's upgrade. For `default`, the store query above printed every
kind with its latest arrival, and the three newest heartbeats, seq 149, 157 and 168, 60 s apart, each with
`degraded=[]` and every `selftest` member `pass`, `token_accepted` among them; the seat's log names no
refusal and the spool holds no `REJECTED.*.txt`. The stub site of [Adding a site](#adding-a-site) has no
store, so its request log stood in for this query, as that section reports.

**The snapshot**, on the server's host as well. The REST route `GET /api/fleet/snapshot` serves `App\Read\Snapshot::build`
(`FleetController::snapshot`). It accepts a `mzr_` fleet_read token or an MFA session (FLEET-STATE
§ 9). This install issued no read credential, so it read the same object directly:

<!-- install.sh: skip — the snapshot read runs on the server's host -->
```bash
cd $HOME/mezzanine/server && php artisan tinker --execute='
$b = App\Read\Snapshot::build((int) floor(microtime(true) * 1000));
echo json_encode(["fleet" => $b["fleet"], "seats" => array_merge(...array_map(fn ($i) => $i["seats"], $b["installs"]))], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
'
```

⚠ **The HTTP leg was not exercised by this install**: the route's authentication, and the JSON as sent.
Checking it takes a read credential from `mezzanine:feed-token:issue`, which is a separate grant.

## Upgrading an existing seat

**Every seat running the reporter applies every reporter update** ([README § Updating a seat](README.md#updating-a-seat--every-update-is-required)).
An update is the artifact plus whatever it brings with it: new hook fixtures, hook wiring, config keys or a
crontab line. Its new functionality ships enabled, so there is nothing to opt in to. What one version
touches is its row in [`UPDATES.md`](UPDATES.md); a seat several versions behind applies the union of the
rows newer than the version it runs. The steps below run in this order, in one shell, from a checkout of
the commit the update's announcement names, written `<checkout>` as in the rest of this runbook; U0 makes
it. Each says what proves it worked.

**U0 — back up, and clone the target ref.** Run [Step 0](#step-0--back-up-what-the-install-touches-and-make-the-attempt-directory)'s
block. It makes `$B` and copies the settings, the crontab, the artifact with its fixtures, and the config.
*Check:* it prints `artifact and fixtures copied`, `config copied, mode 600` and the crontab's md5, and
exits 0. Then clone the repository into `$B` at the commit the announcement names, `<commit>` (a
commit, which `git clone --branch` cannot take, hence the detached checkout):

<!-- install.sh: skip — install.sh runs from the checkout that holds it, so the checkout is the target ref -->
```bash
git clone https://github.com/PupFuzz/mezzanine "$B/checkout" && git -C "$B/checkout" checkout --detach <commit> && git -C "$B/checkout" rev-parse HEAD
```

*Check:* the SHA it prints last equals the announcement's `<commit>`. From here `<checkout>` is
`$B/checkout`.

**U1 — read what this update touches, and whether the seat already has it:**

<!-- install.sh: run u1 -->
```bash
( C=<checkout>/fleet-reporter; I=$HOME/.local/share/fleet-reporter
  v() { grep -o "^const REPORTER_VERSION = '[^']*'" "$1" | cut -d"'" -f2; }
  echo "installed $(v "$I/fleet-reporter.js"), this checkout $(v "$C/fleet-reporter.js")"
  sha256sum "$C/fleet-reporter.js" "$I/fleet-reporter.js"
  if cmp -s "$C/fleet-reporter.js" "$I/fleet-reporter.js" && diff -r "$C/fixtures" "$I/fixtures" > /dev/null
  then echo "NO-OP: this seat already runs this checkout's artifact and fixtures"
  else echo "UPDATE NEEDED: apply the UPDATES.md rows newer than the installed version"; fi )
```

*Check:* `NO-OP` ends the upgrade here; skip to U8, which confirms the server agrees, and U9's reply. Every change to what an update ships moves
`REPORTER_VERSION` (`bin/reporter-version-guard.py` holds a PR to that), so a seat whose artifact and
fixtures equal the checkout's has nothing else to apply, provided it applied every step of the update
that installed them. Otherwise note the rows' touches: they decide
whether U4 and U5 run. A seat installed before `0.2.0` reads `0.1.0`, which names every build before
it, so it applies every step.

**U2 — extract the helpers, without re-running the install.** Steps 2, 4 and 5 save their helpers from
blocks of this runbook. This copies each one out of the checkout's runbook into `$B`, byte for byte as
the block would write it, and refuses a name the runbook does not hold exactly once:

<!-- install.sh: skip — install.sh saves each helper by running the block that saves it -->
```bash
bash -euo pipefail <<'SH'
  for h in coord-config.js wire-hooks.js check-preserved.js stop-flusher.js flusher-ancestry.js; do
    start="cat > \"\$B/$h\" <<'JS'"
    awk -v s="$start" 'on && $0 == "JS" { on = 0; n++; next } on { print; next } { t = $0; sub(/^ +/, "", t) } t == s { on = 1 } END { exit n == 1 ? 0 : 1 }' \
      <checkout>/fleet-reporter/INSTALL-LINUX.md > "$B/$h" || { echo "$h: not exactly one block in the runbook: NOT EXTRACTED" >&2; exit 1; }
    node --check "$B/$h"
  done
  echo helpers-extracted
SH
```

*Check:* it prints `helpers-extracted`. Add `write-config.js` to the list when a row's `config` touch
names Step 2's writer.

**U3 — the artifact and its fixtures.** Run [Step 1](#step-1--prerequisites-and-the-artifact)'s
`install` lines over the running seat; that step says why they come before the flusher stops.
*Check:* Step 1's `sha256sum` prints one hash twice, and its `diff -r` prints `fixtures-match`.

**U4 — the hook wiring, when a row lists `hooks`.** The fixture set changed, so the hook set does.
Run [Step 4](#step-4--wire-the-hooks-and-optionally-the-statusline) procedure (a)'s block; it adds the new
hooks and changes no existing entry. *Check:* `wire-hooks.js` names the new hooks after `added:`,
`check-preserved.js` prints `every other key and every existing hook entry is unchanged`, and the block
prints `applied`. Run without a `hooks` touch it prints `added: nothing` and `already wired: nothing
applied`, which is the same check.

**U5 — the crontab, when a row lists `cron`, and the config, when a row lists `config`.** For `cron`, run
[Step 5](#step-5--supervise-the-flusher-from-the-user-crontab)'s write block. *Check:* it prints the
diff and `installed: <md5>`, or `crontab already current`. For `config`, make the change the row names
with the command it names (Step 2's writer or Step 3's one-key rewrite). *Check:* Step 3's printer shows
the new value, and [Step 6](#step-6--selftest) passes `config_readable`. The flusher reads its config only when it
starts, so U6's restart applies the change.

**U6 — restart the flusher on the new build.** When U5 wrote a new crontab its block already stopped and
restarted the flusher; U6's stop is then a second stop, expected and harmless. Run it, because its check
is what proves the lock holder runs the installed artifact:

<!-- install.sh: run restart -->
```bash
bash -euo pipefail <<'SH'
  node "$B/stop-flusher.js" $HOME/.local/state/fleet-reporter $HOME/.local/share/fleet-reporter/fleet-reporter.js
  sleep 70
  node "$B/flusher-ancestry.js" $HOME/.local/state/fleet-reporter $HOME/.local/share/fleet-reporter/fleet-reporter.js --upgrade
SH
```

*Check:* the last line prints `PASS: the lock holder runs the artifact installed at …`. Before it,
`stop-flusher.js` prints `flusher pid <pid> exited <n> s after SIGTERM`, naming the old flusher (or says
there was no flusher to stop), and `flusher-ancestry.js` prints the lock holder's process chain, one line
per process with its pid, the new flusher first and its parents after it, then `the lock holder started
…, after the artifact's …`. Those lines are the check's evidence, not errors. The check's `FAIL: …
before the artifact was installed` means the old build still holds the lock: [Step 1](#step-1--prerequisites-and-the-artifact)
says why, and the fix is to run the block again. A stop that fails, `NOT STOPPED` or `NOTHING SENT`,
ends the block there, before the wait.

**Whether U6 is owed, asked without stopping anything.** An upgrade that stopped after U3 and before U6
leaves the new artifact installed under a flusher still running the old build, and U1 then reads `NO-OP`,
because it compares files and not the running process. This asks the running process instead, read-only:
`FAIL: … before the artifact was installed …: it runs the previous build` means U6 is owed. Any other
`FAIL` is no live flusher holding the lock, which cron's next minute or a hook starts on the installed
build, so nothing is owed:

<!-- install.sh: run holder -->
```bash
node "$B/flusher-ancestry.js" $HOME/.local/state/fleet-reporter $HOME/.local/share/fleet-reporter/fleet-reporter.js --upgrade
```

**A `FAIL: … no flock and no cron` here is expected, and benign.** It comes from a copy of the helper
saved before card#11818, or from running it without `--upgrade`. The helper then goes on to ask
[Step 5](#step-5--supervise-the-flusher-from-the-user-crontab)'s question, whether cron's line started the
lock holder, and on a seat whose sessions are working a hook usually starts the flusher in the seconds
after the stop, before cron's next minute. That flusher runs the same artifact and holds the same lock,
so the upgrade is done when the line above the `FAIL` reads `the lock holder started …, after the
artifact's …`. `--upgrade` stops there. Cron's line needs its own check only when U5 changed it: run
Step 5's check after a `stop-flusher.js` at a moment when no session on the account is working.

**U7 — selftest.** Run [Step 6](#step-6--selftest)'s command, with `COORD_CONFIG` set to Step 5's value.
*Check:* `selftest rc=0`. Step 6 reads every other code.

**U8 — confirm that the server sees this build as current.** The server publishes the reporter build it
was built with as `current_reporter_version` on `GET /api/ingest/health`, which answers a valid seat token
with `accepted_schema_versions`, `server_time`, `min_reporter_version` and `current_reporter_version`
([D1 § 4.1](../docs/design/EVENT-SCHEMA.md#41-endpoints)). This block asks each site for it and compares
it with the installed artifact's `REPORTER_VERSION`, read as U1 reads it. It reads each site's
`ingest_url`, `token` and `ca_file` through the artifact's own config reader, puts the token in the
request's header and nowhere else, and prints no token:

<!-- install.sh: run health -->
```bash
( I=$HOME/.local/share/fleet-reporter
  v() { grep -o "^const REPORTER_VERSION = '[^']*'" "$1" | cut -d"'" -f2; }
  FR_REPORTER="$I/fleet-reporter.js" FR_CONFIG=$HOME/.config/fleet-reporter/config.json FR_WANT="$(v "$I/fleet-reporter.js")" node -e '
const https = require("https"), fs = require("fs");
const js = process.env.FR_REPORTER, cfg = process.env.FR_CONFIG, want = process.env.FR_WANT;
if (!js || !cfg) { console.log("FR_REPORTER and FR_CONFIG must each be set: NOT MEASURED"); process.exit(2); }
if (!want) { console.log("no installed REPORTER_VERSION was read: NOT MEASURED"); process.exit(2); }
const { sites } = require(js).loadConfig(cfg);
if (!sites.length) { console.log("the config holds no site: NOT MEASURED, Step 6 names why"); process.exit(2); }
let behind = false, unmeasured = false;
const one = (s) => new Promise((done) => {
  let said = false;
  const say = (m, rc) => { if (said) return; said = true; if (rc === 1) behind = true; if (rc === 2) unmeasured = true; console.log((s.name || "sites[" + s.index + "]") + ": " + m); done(); };
  if (s.errors.length) return say("the site has a config error: NOT MEASURED, Step 6 names it", 2);
  if (s.proxy_url !== null) return say("the site routes through proxy_url, which this command does not: NOT MEASURED", 2);
  const u = new URL(s.ingest_url); u.pathname = u.pathname.replace(/\/events$/, "/health");
  const req = https.get(u, { headers: { Authorization: "Bearer " + s.token, Accept: "application/json" }, ca: s.ca_file === null ? undefined : fs.readFileSync(s.ca_file), timeout: 15000 }, (r) => {
    let b = ""; r.setEncoding("utf8"); r.on("data", (d) => { b += d; });
    r.on("end", () => {
      let j = null; try { j = JSON.parse(b); } catch (e) { /* not JSON: an outage page, or something in front of the ingest */ }
      const cur = r.statusCode === 200 && j && typeof j.current_reporter_version === "string" ? j.current_reporter_version : null;
      if (cur === null) return say("HTTP " + r.statusCode + (j && typeof j.error === "string" ? " " + j.error : "") + " with no current_reporter_version: NOT MEASURED", 2);
      say("installed " + want + ", server current " + cur + (cur === want ? ": current" : ": NOT CURRENT"), cur === want ? 0 : 1);
    });
  });
  req.on("timeout", () => req.destroy(new Error("no answer within 15 s")));
  req.on("error", (e) => say("no answer (" + (e.code || e.message) + "): NOT MEASURED", 2));
});
(async () => { for (const s of sites) await one(s); process.exit(behind ? 1 : unmeasured ? 2 : 0); })();
'; echo "health rc=$?" )
```

*Check:* each site prints `installed <version>, server current <version>: current`, and the block ends
`health rc=0`. The other codes:

- **`rc=1`** — a site printed `NOT CURRENT`: the two builds differ. An installed build lower than the
  server's means the seat is still behind it, and its desk keeps the `update_required` badge: run U1 again
  against the announcement's commit. An installed build higher than the server's means the server runs a
  build older than that commit. The seat is then not badged, because the badge marks only a seat behind
  the server ([`docs/design/FLEET-STATE.md` § 7.2](../docs/design/FLEET-STATE.md#72-this-planes-own-counters-and-badges)),
  and the server's operator deploys the newer build.
- **`rc=2`** — a site printed `NOT MEASURED`: no version was read from it. `HTTP 401 unauthenticated` is a
  token the site does not resolve ([Rotating a site's token](#rotating-a-sites-token)); a config error is
  [Step 6](#step-6--selftest)'s to name; a site with a `proxy_url` is not asked, because this block sends
  no request through a proxy. Step 6's `selftest` asks the same route through the proxy, and its report
  carries no `current_reporter_version`.

**When the `update_required` badge clears.** It is a comparison, not a windowed counter: the server
judges it again at every recompute of the seat's state, from the `reporter_version` of the batch the seat's
last folded event arrived in, so it clears when the fold applies the first event of a batch the new build
sent. The flusher sends a heartbeat every 60 s (D1 § 9.1), so on a seat that reports this is about a
minute after U6's restart. The badges a counter raises are different: each one stays on the desk for
24 h after its counter last rose (FLEET-STATE § 7.2 lists them), so one of those still showing after the
upgrade, `epoch_reset` for example, is its window running out and says nothing about the build.

**How the seat sees the badge.** The badge is drawn on the floor and in the seat's drill-down, which take a
floor login with MFA, and it is in the snapshot, which also takes a `mzr_` fleet read token; the seat's own
token opens none of them. From the seat, U8's `current` together
with [Step 7](#step-7--verify-that-events-arrive)'s seat-side check, a log that names no refusal and no
`REJECTED.*.txt`, shows the condition the badge is judged on: the seat sends the build the server holds
current, and the server accepts it. Reading the badge itself is for someone holding one of those, usually
the server's operator, and U9's reply carries U8's lines for them.

**U9 — reply.** Answer the update's announcement ([`UPDATES.md` § Announcing an update](UPDATES.md#announcing-an-update))
with the seat, the installed hash (`sha256sum $HOME/.local/share/fleet-reporter/fleet-reporter.js`),
U7's `selftest rc=` line, naming every check that is not `pass`, and U8's lines with its `health rc=`.

**What was run for card#11818**, on the sandbox host on 2026-10-24, without changing the live seat:

- **U2** against this runbook: each helper U2's list names came out byte-identical (`cmp`) to what its block
  writes when run, and a list naming a helper the runbook lacks printed `NOT EXTRACTED` and exited 1.
- **U1** against the live install printed `installed 0.1.0, this checkout 0.2.0`, two different hashes and
  `UPDATE NEEDED`; pointed at Step 0's copy of an install, it printed `NO-OP`.
- **U6's check, read-only, against the live flusher**, which a hook had started after the previous
  upgrade's stop. Without `--upgrade` it printed `the lock holder started 2026-10-23T02:35:54.070Z, after
  the artifact's 2026-10-23T02:35:35.433Z` and then `FAIL: the lock holder's parents include no flock and
  no cron`, exit 1: the expected case above. With `--upgrade` it printed the same first line and `PASS:
  the lock holder runs the artifact installed at …`, exit 0. An unknown mode exited 2.
- **U0**, Step 0's block with its paths pointed at a scratch home: with an artifact and a config in place
  it printed both `copied` lines and `config.json` kept mode `600`; with neither, it printed both
  `nothing to copy` lines. Both runs exited 0.

**The whole sequence**, U0 to U7, ran on the sandbox seat on 2026-10-25, from `0.1.0` to `0.3.0`, and
every step's check passed; the floor then showed no `update_required` on its desk.

**U8, run for card#12329** on the sandbox seat on 2026-10-25, after that upgrade: it printed `default:
installed 0.3.0, server current 0.3.0: current` and `health rc=0`. With the expected version swapped for
`0.2.0` it printed `NOT CURRENT` and `health rc=1`, and against a copy of the config holding a token the
site does not resolve it printed `HTTP 401 unauthenticated … NOT MEASURED` and `health rc=2`. No output
carried a token.

## Step 8 — uninstall and roll back

Do these in this order. Hooks go first, because while they stay wired every tool call respawns the
flusher (§ 2.3). The token goes last, because revoking it first leaves a running flusher posting with a
dead credential, which § 11.5 quarantines as permanent. Before each item, run `export B=` with the attempt
directory that holds what the item restores. On the sandbox that is `20260913T203739Z-hooks` for item 1,
the pre-hooks `settings.json`, and `20260913T193245Z` for item 2, the crontab. Item 3 uses Step 5's
`stop-flusher.js`. If the attempt directory does not hold it, save it first with Step 5's helper block.

1. **Unwire the hooks.** If nothing else has changed `settings.json` since the hooks were applied,
   restore that attempt's backup, then check it is valid JSON:
   `cp -p "$B/settings.json" "$HOME/.claude/settings.json" && node -e 'JSON.parse(require("fs").readFileSync(process.env.HOME + "/.claude/settings.json","utf8")); console.log("valid JSON")'`.
   If something else has changed it, remove only the entries whose command contains
   `share/fleet-reporter/fleet-reporter.js`. If Step 4(b) was applied, also set `statusLine.command`
   back to the config's `wrapped_statusline`.
2. **Remove the crontab block**, and nothing else, with the block below. It is staged in `$B` and
   installed only if the `diff` against Step 0's backup is empty. A result with no line left removes the
   crontab (`crontab -r`), which is the state of a seat that had none at Step 0; a seat that has no
   crontab at all by then has nothing to remove. The md5 it prints last must be Step 0's.

<!-- install.sh: skip — uninstall is by hand -->
```bash
bash -euo pipefail <<'SH'
  read_crontab() { crontab -l 2> "$B/crontab.err" || grep -q '^no crontab for ' "$B/crontab.err" || { cat "$B/crontab.err" >&2; return 1; }; }
  read_crontab > "$B/crontab.current"
  sed '/^# BEGIN fleet-reporter-flusher$/,/^# END fleet-reporter-flusher$/d' "$B/crontab.current" > "$B/crontab.rollback"
  diff "$B/crontab.txt" "$B/crontab.rollback"
  if [ -s "$B/crontab.rollback" ]; then crontab "$B/crontab.rollback"
  elif ! grep -q '^no crontab for ' "$B/crontab.err"; then crontab -r
  else echo "no crontab installed: nothing to remove"; fi
  read_crontab | md5sum
SH
```

3. **Stop the flusher cleanly**, so it removes its own lock, and wait until it has exited:
   `node "$B/stop-flusher.js" $HOME/.local/state/fleet-reporter $HOME/.local/share/fleet-reporter/fleet-reporter.js`.
   It prints how long the exit took, or `NOT STOPPED` once the bound derived from the artifact's
   constants has passed. Do not go on to item 4 while a flusher runs. It checks the pid rather than
   pattern-matching process names, so it cannot match its own shell.
4. **Revoke each site's token on the server that issued it**, by its prefix, on that site's Mezzanine host:
   `php artisan mezzanine:ingest-token:revoke <prefix>` (the sandbox's prefix is
   `mzn_YTRScmlE`). To take the desk off the floor as well, run
   `php artisan mezzanine:retire --seat="$SEAT_INSTALL_ID/$SEAT_ID" --by=<operator> --reason=<why>`
   on each site (FLEET-STATE § 4.10). Revocation leaves the seat's rows, and retirement is what hides the desk.
5. **Remove the seat's files**:
   `rm -rf $HOME/.config/fleet-reporter $HOME/.local/state/fleet-reporter $HOME/.local/share/fleet-reporter $HOME/.local/state/fleet-reporter-flusher.flock`.
   If the token was carried from another host, also run Step 2 (d)'s block on the Mezzanine host with
   `F` set to `mezzanine-reporter-token` in the attempt directory that issued it. It must print `gone`.

**Removing one site without uninstalling** (card#12180): rewrite the config without that site — the command
below writes the result to a `0600` temp file beside the config and flushes it to disk, checks it with the
installed reporter's own `loadConfig`, and renames it into place only when it has no error, so a failed write
or rename leaves the config as it was and removes the temp file — then restart the flusher with item 3's command and let cron or a hook start it; the next start
drops that site's `state.json` entry (D1 § 3.1 rule 4), and that site sees the seat go `stale`, then
`offline`, until its operator retires the desk there. Revoke that site's token by item 4 after the restart.
The acceptance suite re-extracts the command and drives it (AT-28 Case P):

<!-- install.sh: skip — removing a site is by hand -->
```bash
FR_CONFIG=$HOME/.config/fleet-reporter/config.json FR_SITE_NAME=<name> FR_REPORTER=$HOME/.local/share/fleet-reporter/fleet-reporter.js node -e 'const fs=require("fs"),path=require("path"),f=process.env.FR_CONFIG,n=process.env.FR_SITE_NAME,js=process.env.FR_REPORTER; if (!f || !n || !js) { console.error("FR_CONFIG, FR_SITE_NAME and FR_REPORTER must each be set: NOTHING WRITTEN"); process.exit(2); } const t=path.join(path.dirname(f),"."+path.basename(f)+"."+process.pid+".tmp"); let c; try { c=JSON.parse(fs.readFileSync(f,"utf8")); if (!Array.isArray(c.sites) || !c.sites.some((x)=>x.name===n)) throw new Error("no site named "+n); if (c.sites.length===1) throw new Error("the last site: uninstall instead"); c.sites=c.sites.filter((x)=>x.name!==n); const d=fs.openSync(t,"wx",0o600); try { fs.writeSync(d, JSON.stringify(c,null,2)+"\n"); fs.fsyncSync(d); } finally { fs.closeSync(d); } const m=require(path.resolve(js)), e=m.allConfigErrors(m.loadConfig(t)); if (e.length) throw new Error(e.join("; ")); fs.renameSync(t,f); } catch (err) { try { fs.unlinkSync(t); } catch (e2) {} console.error(String(err.message||err)+": NOTHING WRITTEN"); process.exit(1); } console.log("sites:", c.sites.map((x)=>x.name).join(", "))'
```

**What removing a site showed on the sandbox seat**, on 2026-10-23, for the stub site of [Adding a
site](#adding-a-site). With `stub` as the name it printed `sites: default`, the config stayed mode `600`, and
no temp file was left; that run used the command as it stood one edit earlier, when its two refusals threw
before the error handler and printed a stack trace. Run later with `default`, the last site, the command
above printed `the last site: uninstall instead: NOTHING WRITTEN` and exited 1 with the config's md5
unchanged. After the removal, `stop-flusher.js` and a hook's start, the
log read `site stub: no longer configured — its state entry is dropped (§ 3.1 rule 4)`, `state.json` held the
entry `default` alone under the `seq_epoch` Step 1 started, and the stub received no request after that
start. The stub's last token needs no revoke, because no server issued it.

⚠ **As of card#9368, only the non-destructive part of this rollback has been run.** On the first
install, item 2's `sed` ran into a file. The `diff` against Step 0's backup printed nothing, the
file's md5 was `bcd71d369c6c73ed8e61c819e3e2fb4d`, and the file was not installed. Item 1 was not run
even though the hooks are now live, and neither were items 3 to 5, because all of them would take down
the seat this runbook exists to connect. Item 3's helper was exercised only against stand-in
processes, as Step 5 reports.

## What this install does not give you, by name

- **`protocol_agent_name` is sent only by a build that includes card#9375.** That build reads the key
  and the roster, and sends the name and `protocol_agent_name_check` on every heartbeat. The sandbox's
  install used a checkout of `4ce0a19` (Step 1), which is older. A seat running an older build sends no
  name, and the snapshot shows `protocol_agent_name: null`, until Step 1's artifact is replaced and
  Step 5's flusher restarted.
- **`protocol_agent_role` is sent only by a build that includes card#11144.** That build relays the
  roster entry's `role` beside the name. A seat running an older build sends no role, and the
  snapshot shows `protocol_agent_role: null`, until Step 1's artifact is replaced and Step 5's flusher
  restarted.
- **The console link is sent only by a build that includes card#9416.** That build reads each
  session's console address from its transcript and sends it on every `turn.start`. A seat running an
  older build sends none, and an operator's drill-down offers no **Open console** link for it, until
  Step 1's artifact is replaced. Every hook loads the reporter when it fires, so the next prompt
  after the replacement carries the link; no flusher restart is needed for it.
- **A seat installed from a build before card#9374 was badged `epoch_reset` at its first start.** That
  build counted the first start's missing `state.json` as D1 § 11.4's state reset, so the first
  heartbeat carried `state_reset: 1`. A build that includes card#9374 counts no reset on a first
  start, so a seat installed from it starts with an empty `degraded`. Deleting `state.json` to clear
  the badge starts a new `seq_epoch`, and the server badges the seat `epoch_reset` from its own
  `seq_epoch_change`, so leave it in place.
- **A degraded badge clears one day after its counter last rose, on a build that includes
  card#9491.** The counters are running totals kept in `state.json` across flusher restarts, and a
  member of `degraded` now means *a counter that raises it rose within the last 24 h* (D1 § 9.3). A
  seat running an older build keeps every member it ever raised, through any number of restarts,
  until Step 1's artifact is replaced and Step 5's flusher restarted; the first heartbeat after that
  dates every non-zero total as rising then, so the badges clear 24 h later unless a counter rises
  again. The server's own counter-derived badges (`epoch_reset`, `seq_gap`, `seq_collision`,
  `reporter_ahead`) follow the same window once the server includes card#9491, with no reporter
  change.
- **A seat installed from a build before card#11330 is badged `harness_contract_moved`.** That build
  counted the unset `harness_label` from Step 3 as `payload_key_missing.harness_label`, which raises the
  badge whose line is *the harness payload moved under this reporter*, so every seat installed by this
  runbook carried it. A build that includes card#11330 counts the gap as `harness_label_unset`, which
  raises nothing. Its flusher also moves the total an older build saved in `state.json` to the new
  name. The badge then clears at the first heartbeat after Step 1's artifact is replaced and Step 5's
  flusher restarted, unless a missing harness payload key also raises it. The restart is needed
  because a running flusher keeps the code it started with.
- **A seat that applied Step 4(b) with a build before card#11544 is badged `harness_contract_moved`
  at every session start.** The statusLine payload carries no usable `context_window` in the first
  seconds of a session (D1 § 6.11), and that build counted the gap as
  `payload_key_missing.context_window`, which raises the same badge as a moved harness payload. Each
  new session counted it again, so the badge's 24 h window never ran out on an active seat. A build
  that includes card#11544 counts the gap as `context_window_unavailable`, which raises nothing, and
  its flusher moves the total an older build saved in `state.json` to the new name. The badge then
  clears at the first heartbeat after Step 1's artifact is replaced and Step 5's flusher restarted,
  unless a missing harness payload key also raises it. A seat that applied only Step 4(a) never
  counted the gap.
- **A seat spools its events in `event_time` order only on a build that includes card#11563.** That
  build has every writer stamp its events under a lock file, `write.lock` in the spool directory
  (D1 § 11.2), so the flusher delivers them oldest-first. An older build can spool a hook's event
  behind a newer one, most often behind the flusher's 90-minute `inferred_silence` close, and the
  server's guards (FLEET-STATE § 6.5) handle that order. Hooks load the reporter when they fire, so
  they stamp this way as soon as Step 1's artifact is replaced. The flusher's own events, its
  heartbeat and its inferred close, follow once Step 5's flusher is restarted, because a running
  flusher keeps the code it started with. Step 8's item 5 removes `write.lock` with the rest of the
  spool directory.
- **A seat's subagents appear on the floor only on a build that includes card#11799.** The harness
  runs a dispatched subagent as a background task, so the dispatch tool returns as soon as the
  subagent starts. An older build closed the dispatch call then, and the floor draws an intern only
  for an open dispatch call, so it drew none. A build that includes card#11799 keeps the call open
  until the subagent's own `SubagentStop` (D1 § 8.5). Hooks load the reporter when they fire, so the
  next dispatch after Step 1's artifact is replaced appears. Restart Step 5's flusher too: a running
  flusher keeps the code it started with, and it is the flusher that writes the call index snapshot
  the hooks read.
- **A subagent stays on the floor across a `/clear` only on a build that includes card#11817.** The
  harness keeps a running subagent alive through a `/clear` and moves it to the new session; an older
  build closed its dispatch call at the clear, so the intern left the floor while the subagent ran.
  The same build stops a `/clear` in one terminal from ending another terminal's session on the same
  seat (card#11824). **Restart Step 5's flusher after replacing the artifact:** until it restarts, the
  old flusher's every pass writes the call index snapshot without the records this build adds, so a
  subagent carried across a `/clear` in that window stays under its old session and closes at its own
  `SubagentStop` or the server's 60-minute dispatch ceiling. The restart itself closes every call open
  at that moment as `reporter_restart` (D1 § 8.3's last row), carried ones included. Install the
  server change first: a server without it closes a carried call at the `session.end`, as before.
- **One seat reports to several sites only on a build that includes card#12180** (D1 § 3.1, *Sites*). An
  older build reads only the single-site form and refuses a `sites` list. Upgrade with Step 1's order,
  install first and stop second. The upgrade shows on the floor as one `epoch_reset` per seat; no event is
  lost or doubled. **To repoint the
  existing site at upgrade, name it anything but `default`**: a `state.json` from an older build records no
  URL, so a `default` repointed in the same edit carries a cursor the new server never earned (D1 § 3.1
  rule 3). The same holds while the upgraded flusher has only ever started under an `ingest_url` that does
  not parse: it records the URL at its first start under one that does, so fix such a typo to the same
  server, and repoint by a new name. Downgrading is not offered; an older build reads the new `state.json` as unusable, takes D1 § 11.4's
  reset and re-sends. The marker and the rejected-batch quarantine are named per site from this build on
  (`REJECTED.default.txt`, `quarantine/rejected.default.jsonl`); an older build's `REJECTED.txt` and
  `quarantine/rejected.jsonl` stay as history until Step 8 removes the spool directory.
- **No context gauge** while Step 4(b) is not applied, which is the sandbox's state.
- **No Windows procedure exists yet.** One is owed when the Windows agent seat onboards, a real
  Windows machine. By operator ruling on 2026-09-13, D1 § 13's Windows validation is not required
  before then, and a validated Linux install (this runbook, card#9368) satisfies that precondition
  for the present. D1 § 3.1 names the Windows route owed at that onboarding.

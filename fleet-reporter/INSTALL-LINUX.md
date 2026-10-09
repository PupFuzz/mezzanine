# Installing `fleet-reporter` on a Linux seat, by hand and without root

There is no installer. Card #7336 is won't-do, so this runbook is how a Linux agent seat starts
reporting to a Mezzanine server. It was written by performing it on the sandbox host on 2026-09-13
(card#9368), for the seat `mezzanine` / `mezzanine-solo`, and the seat has reported since.

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

## The values to substitute

| What | On the sandbox seat | What constrains it |
|---|---|---|
| seat account, home | `mezzanine`, `/home/mezzanine` | no root and no sudo anywhere; every path below sits under this home |
| `install_id` | `mezzanine` | D1 § 3.1's slug pattern |
| `seat_id` | `mezzanine-solo` | D1 § 3.1's slug pattern. The token is bound to the pair (§ 3.3), so a second seat is a second token |
| `protocol_agent_name` | `mezzanine` | D1 § 3.1: the name must be a member of the roster at `$COORD_CONFIG`, or the check is `disagreed` and the name resolves to no desk. This seat's roster holds `mezzanine` and not `mezzanine-solo`, which is why the name is not the `seat_id` ([Step 2](#step-2--issue-the-seat-token-into-the-config-on-this-host-or-through-a-carried-file)) |
| `$COORD_CONFIG` | `/home/mezzanine/.config/coord/coordination.config.json`, read from `~/.claude/settings.local.json` | resolved by Step 2's `coord-config.js`, never typed. Claude Code's settings precedence puts project-local `settings.local.json` over `settings.json` (<https://code.claude.com/docs/en/settings>, *Settings precedence*). The seat's sessions run with `$HOME` as the project, so the two files are `~/.claude/settings.local.json` and `~/.claude/settings.json`. An empty result means none is set, and § 3.1 then says to deliver nothing |
| `node` | `/usr/bin/node` | `readlink -f "$(command -v node)"`, resolved when Step 5 runs. Cron's `PATH` is not the login shell's, so the crontab names `node` absolutely |
| ingest URL | `https://sandboxmezzanine.neeba.com/api/ingest/events` | `https://` only (§ 3.5) |
| server checkout | `/home/mezzanine/mezzanine/server` | where `php artisan` runs. On the sandbox it is the same host; on another host, the token is issued there into a `0600` file and carried by hand to the seat as `~/.config/fleet-reporter/mezzanine-reporter-token` (Step 2, [hand delivery](#when-the-server-is-on-another-host-carry-the-token-in-one-named-file)) |
| artifact | `/home/mezzanine/.local/share/fleet-reporter/` | § 2.1's Linux install directory |
| config | `/home/mezzanine/.config/fleet-reporter/config.json` | § 3.1: mode `0600`, directory `0700` |
| spool | `/home/mezzanine/.local/state/fleet-reporter` | § 3.1's example location |
| attempt directory, `$B` | `/home/mezzanine/.local/state/fleet-reporter-install/<UTC timestamp>/` | one per install attempt, mode `0700`, owned by the seat account. It holds the backups **and every staged file**. Nothing is staged in `/tmp`: that directory is world-writable, so another account on the host can create a file there first, under the same name |

Paths are written out in full because the settings file and the crontab both need them expanded.
D1 § 2.1 explains why an unexpanded `~` in a settings command is the quietest failure a reporter can have.
No path that goes into the crontab may contain whitespace or `%`, because cron turns an unescaped `%`
into a newline. Step 5 refuses such a path.

**Run the steps in one shell.** Step 0 sets `$B`. Each block that stages a file and then uses it
runs in a `( set -euo pipefail … )` subshell, so a staged write that fails stops the block before
anything runs or installs the file. A later shell sets `B=` to the attempt directory again before it
continues.

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
crontab runs the server's daemons. Copy both into a new attempt directory before anything else:

```bash
B=/home/mezzanine/.local/state/fleet-reporter-install/$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p /home/mezzanine/.local/state/fleet-reporter-install && mkdir -m 0700 "$B" && echo "B=$B"
( set -euo pipefail
  { [ -O "$B" ] && [ "$(stat -c %a "$B")" = 700 ]; } || { echo "\$B is not a new 0700 directory of the seat's own: NOTHING COPIED" >&2; exit 1; }
  cp -p /home/mezzanine/.claude/settings.json "$B/settings.json"
  crontab -l > "$B/crontab.txt"
  chmod 0600 "$B/crontab.txt"
  cmp /home/mezzanine/.claude/settings.json "$B/settings.json"
  crontab -l | md5sum )                       # record it: Step 8 must return the crontab to exactly this
```

`mkdir -m 0700` without `-p` fails if the directory already exists, so an attempt never reuses
another's directory. On the sandbox, the first attempt was `20260913T193245Z`, and the crontab's md5
was `bcd71d369c6c73ed8e61c819e3e2fb4d`. The hooks were applied later, in attempt `20260913T203739Z-hooks`,
which backed up `settings.json` alone.

## Step 1 — prerequisites and the artifact

You need `node` 18 or later (D1 § 2.1), plus `flock` and `crontab`, on the seat account's PATH. The
sandbox had `v22.22.1`, `/usr/bin/flock` and `/usr/bin/crontab`, and `node` resolved to `/usr/bin/node`:

```bash
node --version && command -v node flock crontab && readlink -f "$(command -v node)"
```

The artifact is two things from a checkout of PupFuzz/mezzanine: `fleet-reporter/fleet-reporter.js`, and
`fleet-reporter/fixtures/hooks/*.json`. The fixtures ship with the script for two reasons. `selftest`
reads them on the seat (README, D1 § 2.1). And Step 4 derives the hook set from their names, because
D1 § 2.1 vendors one file per subscribed hook. The sandbox used a checkout of `dev` at `4ce0a19`:

```bash
install -d -m 0700 /home/mezzanine/.local/share/fleet-reporter /home/mezzanine/.local/share/fleet-reporter/fixtures /home/mezzanine/.local/share/fleet-reporter/fixtures/hooks
install -m 0600 <checkout>/fleet-reporter/fleet-reporter.js /home/mezzanine/.local/share/fleet-reporter/fleet-reporter.js
install -m 0600 <checkout>/fleet-reporter/fixtures/hooks/*.json /home/mezzanine/.local/share/fleet-reporter/fixtures/hooks/
sha256sum <checkout>/fleet-reporter/fleet-reporter.js /home/mezzanine/.local/share/fleet-reporter/fleet-reporter.js
diff -r <checkout>/fleet-reporter/fixtures /home/mezzanine/.local/share/fleet-reporter/fixtures && echo fixtures-match
printf '{}' | node /home/mezzanine/.local/share/fleet-reporter/fleet-reporter.js hook PostToolUse; echo "rc=$?"
```

The last line proves something about ordering. With the artifact in place and no config yet, a hook
exits 0 and prints nothing (`rc=0`). So hooks may be wired before the config exists. The reverse order
is not safe. A settings entry whose script path does not exist yet makes `node` exit non-zero on every
tool call, and the harness puts that error in the transcript.

## Step 2 — issue the seat token into the config, on this host or through a carried file

On a seat on the Mezzanine server's own host, the issue command pipes straight into the config. When
the server is on another host, the token reaches the seat in one named file the operator carries
([the hand delivery](#when-the-server-is-on-another-host-carry-the-token-in-one-named-file)). Both
routes use the same resolver, identity check and writer, which come first.

**Resolve `$COORD_CONFIG` the way the seat's sessions see it.** Save the resolver. It reads each
settings file in precedence order, lowest first, so the last file that sets the value wins. It prints
the value, or an empty line when no file sets it:

```bash
( set -euo pipefail
  cat > "$B/coord-config.js" <<'JS'
const fs = require("fs");
let v = "";
for (const f of process.argv.slice(2)) {
  let s;
  try { s = JSON.parse(fs.readFileSync(f, "utf8")); } catch (e) { if (e.code === "ENOENT") continue; throw e; }
  if (s.env && typeof s.env.COORD_CONFIG === "string") v = s.env.COORD_CONFIG;
}
console.log(v);
JS
  node "$B/coord-config.js" /home/mezzanine/.claude/settings.json /home/mezzanine/.claude/settings.local.json )
```

On the sandbox it printed `/home/mezzanine/.config/coord/coordination.config.json`, from
`settings.local.json`, since `settings.json` sets no `COORD_CONFIG`.

**Check the identity.** `install_id` and `seat_id` must match D1 § 3.1's patterns, and the issue
command refuses a value that does not. `protocol_agent_name` must be a member of the roster the
reporter reads. D1 § 3.1 reads `$COORD_CONFIG` first and, on Linux, the home path second. This prints
the names that roster holds:

```bash
( set -euo pipefail
  CC=$(node "$B/coord-config.js" /home/mezzanine/.claude/settings.json /home/mezzanine/.claude/settings.local.json)
  node -e 'const c=JSON.parse(require("fs").readFileSync(process.argv[1],"utf8")); for (const r of c.roster||[]) console.log(r.name)' "${CC:-/home/mezzanine/.config/coord/coordination.config.json}" )
```

On the sandbox it printed `mezzanine`. The seat declares `mezzanine`, so the check is `checked`.
Declaring `mezzanine-solo` would have been `disagreed`.

**The token is a credential, and it must never reach a terminal, an argv, a shell history or a log.**
`mezzanine:ingest-token:issue` prints the plaintext once and stores only its SHA-256. So the issue
command is piped straight into a small writer. On the same host the writer takes the token off the pipe,
writes the config with mode `0600`, and prints every other line of the command's output, with the token
line replaced. It receives the token, so it lives in `$B` and nowhere another account can write. Save
it:

```bash
( set -euo pipefail
  cat > "$B/write-config.js" <<'JS'
const fs = require("fs"), path = require("path");
const e = process.env, out = [], TOKEN = /^\s*token\s+(mzn_[A-Za-z0-9_-]{43})\s*$/, SHAPE = /mzn_[A-Za-z0-9_-]{43}/g;
const FILE_TOKEN = /^mzn_[A-Za-z0-9_-]{43}\n?$/;
const DESCRIPTORS = e.FR_DESCRIPTORS || "full";
const die = (why) => { console.error(why + ": NOTHING WRITTEN"); process.exit(1); };
if (!["full", "paths", "none"].includes(DESCRIPTORS)) die("FR_DESCRIPTORS must be full, paths or none");
for (const k of ["FR_TOKEN_FILE", "FR_TOKEN_OUT"]) if (k in e && !e[k]) die(k + " is set but empty");
if (e.FR_TOKEN_FILE && e.FR_TOKEN_OUT) die("FR_TOKEN_FILE and FR_TOKEN_OUT are two different steps; set one");
const mode = (st) => (st.mode & 0o777).toString(8);
function writeConfig(token) {
  const cfg = {
    install_id: e.FR_INSTALL, seat_id: e.FR_SEAT, ingest_url: e.FR_URL, token,
    spool_dir: e.FR_SPOOL, ca_file: null, proxy_url: null,
    wrapped_statusline: e.FR_WRAPPED || null, protocol_agent_name: e.FR_AGENT || null,
    descriptors: DESCRIPTORS, enabled: true,
  };
  fs.mkdirSync(path.dirname(e.FR_CONFIG), { recursive: true, mode: 0o700 });
  fs.chmodSync(path.dirname(e.FR_CONFIG), 0o700);
  const fd = fs.openSync(e.FR_CONFIG, "wx", 0o600);
  fs.writeSync(fd, JSON.stringify(cfg, null, 2) + "\n"); fs.closeSync(fd);
  console.log("wrote " + e.FR_CONFIG + " (mode " + mode(fs.statSync(e.FR_CONFIG)) + ")");
}
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
  try { writeConfig(body.trimEnd()); } catch (err) { die("could not write " + e.FR_CONFIG + " (" + err.code + ")"); }
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
    } else {
      try { writeConfig(token); } catch (err) { die("could not write " + e.FR_CONFIG + " (" + err.code + ")"); }
    }
  });
}
JS
  node --check "$B/write-config.js" && echo writer-saved )
```

Before this ran for real, the writer was driven three ways: with a fabricated issue output, which wrote
the file at mode `600` in a `700` directory; against a config that already existed, which refused
(`'wx'`) and wrote nothing; and with output that had no token line, which exited 1 and wrote nothing.
The writer reads the token from one of two places and writes it to one of two places. On the same host,
which is the pipeline below, it reads the issue command's output on stdin and writes the config.
When the server is on another host, the token makes two hops through the same writer. `FR_TOKEN_OUT`
writes the token alone to a file on the Mezzanine host, and `FR_TOKEN_FILE` reads it from that file on
the seat; [the hand delivery](#when-the-server-is-on-another-host-carry-the-token-in-one-named-file)
below runs both, and says what card#11331's scratch run of them showed.

`FR_DESCRIPTORS` is how much of each tool call the seat sends as its descriptor: `full` (the default when
it is unset), `paths` or `none`. D1 § 3.1 defines the three values, and Step 3 says how to change it later.
The writer refuses any other value before it writes anything.

`FR_WRAPPED` is the statusLine command the seat has **now**, read from `statusLine.command` in
`~/.claude/settings.json`. On the sandbox it was the coord framework's context sensor. It is recorded
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

```bash
( set -euo pipefail
  { [ -O "$B" ] && [ "$(stat -c %a "$B")" = 700 ] && [ -f "$B/write-config.js" ] && [ -O "$B/write-config.js" ]; } || { echo "\$B is not the seat's own 0700 directory holding its writer: NOTHING RUN" >&2; exit 1; }
  command -v node >/dev/null || { echo "no node on PATH, so the writer cannot take the token: NOTHING RUN" >&2; exit 1; }
  cd /home/mezzanine/mezzanine/server
  php artisan mezzanine:ingest-token:issue mezzanine mezzanine-solo --by=card-9368 2>&1 | FR_CONFIG=/home/mezzanine/.config/fleet-reporter/config.json FR_INSTALL=mezzanine FR_SEAT=mezzanine-solo FR_URL=https://sandboxmezzanine.neeba.com/api/ingest/events FR_SPOOL=/home/mezzanine/.local/state/fleet-reporter FR_AGENT=mezzanine FR_WRAPPED="bash ~/.local/bin/context-sensor.sh ''" node "$B/write-config.js" ); echo "pipeline rc=$?"
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
- **Rotation.** D1 § 3.3's order applies: issue the new token, write it into the config, and only then
  revoke the old one.

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
way Step 0 makes `$B`, with its first two lines and the issuing account's home in place of the seat's,
and save the writer into it with the block above. Then pipe the issue command into the writer with
`FR_TOKEN_OUT` set. The writer prints the issue output with the token line replaced, so the **prefix**
stays on screen, and writes the token alone to the file with mode `0600`. It refuses a file that
already exists. The guard on the first line is the same-host pipeline's:

```bash
( set -euo pipefail
  { [ -O "$B" ] && [ "$(stat -c %a "$B")" = 700 ] && [ -f "$B/write-config.js" ] && [ -O "$B/write-config.js" ]; } || { echo "\$B is not this account's own 0700 directory holding its writer: NOTHING RUN" >&2; exit 1; }
  command -v node >/dev/null || { echo "no node on PATH, so the writer cannot take the token: NOTHING RUN" >&2; exit 1; }
  cd <server checkout>
  php artisan mezzanine:ingest-token:issue <install_id> <seat_id> --by=<operator> 2>&1 | FR_TOKEN_OUT="$B/mezzanine-reporter-token" node "$B/write-config.js" ); echo "pipeline rc=$?"
```

Keep the prefix: Step 8 needs it, and so does every failure below.

**(b) Carry the file to the seat by hand.** The means is the operator's, provided nothing on the way
records the file's contents: a copy between two accounts the operator controls, over SSH, is one such
means. It arrives at `~/.config/fleet-reporter/mezzanine-reporter-token` in the seat account's home,
with mode `0600`, in a directory with mode `0700` that holds no config yet. On the seat, make the
directory first if it is not there:

```bash
install -d -m 0700 /home/mezzanine/.config/fleet-reporter
stat -c '%a %U %n' /home/mezzanine/.config/fleet-reporter /home/mezzanine/.config/fleet-reporter/mezzanine-reporter-token
```

Both lines must show the seat account, `700` on the directory and `600` on the file.

**(c) On the seat, write the config from the file.** Make `$B` with Step 0 and save the writer into it
with the block above, as on a same-host seat. The pipeline is the same-host one with the issue command
taken out: `FR_TOKEN_FILE` names the carried file, and the writer reads the token from it rather than
from stdin. It writes the config exactly as the same-host pipeline does, with the same keys and mode
`0600`. Before it writes anything, it confirms that the file is a regular file the seat account owns,
that its mode is exactly `0600`, that its directory is one the seat account owns with mode exactly
`0700`, and that it holds one well-formed token and nothing else. Its messages
name the file and never print what it holds:

```bash
( set -euo pipefail
  { [ -O "$B" ] && [ "$(stat -c %a "$B")" = 700 ] && [ -f "$B/write-config.js" ] && [ -O "$B/write-config.js" ]; } || { echo "\$B is not the seat's own 0700 directory holding its writer: NOTHING RUN" >&2; exit 1; }
  FR_TOKEN_FILE=/home/mezzanine/.config/fleet-reporter/mezzanine-reporter-token FR_CONFIG=/home/mezzanine/.config/fleet-reporter/config.json FR_INSTALL=mezzanine FR_SEAT=mezzanine-solo FR_URL=https://sandboxmezzanine.neeba.com/api/ingest/events FR_SPOOL=/home/mezzanine/.local/state/fleet-reporter FR_AGENT=mezzanine FR_WRAPPED="bash ~/.local/bin/context-sensor.sh ''" node "$B/write-config.js" ); echo "pipeline rc=$?"
```

On success it prints two lines, `read the token from …/mezzanine-reporter-token (mode 600)` and
`wrote …/config.json (mode 600)`, and `pipeline rc=0`.

**(d) Shred the file, on the seat and on the Mezzanine host, and confirm both are gone.** Run this on
the seat once (c) printed `pipeline rc=0`, with `F` as written. Run it on the Mezzanine host with
`F="$B/mezzanine-reporter-token"`, `$B` being (a)'s attempt directory. It shreds `F` only when `F` is a
regular file and not a symlink, because `shred` follows a link and overwrites whatever it points at.
For a symlink it removes the link alone and names the file it pointed at, which may hold the token
and is the operator's to inspect and shred. It ends by printing `gone` or `PRESENT` for `F`:

```bash
F=/home/mezzanine/.config/fleet-reporter/mezzanine-reporter-token
if [ -L "$F" ]; then echo "$F is a symlink to $(readlink -f -- "$F"): the link is removed and that file is left for you to inspect and shred" >&2; rm -- "$F"
elif [ -f "$F" ]; then shred -u -- "$F"; fi
if [ -e "$F" ] || [ -L "$F" ]; then echo "token file PRESENT: $F"; else echo "token file gone: $F"; fi
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

## Step 3 — check the config file

```bash
stat -c '%a %n' /home/mezzanine/.config/fleet-reporter /home/mezzanine/.config/fleet-reporter/config.json
node -e 'const c=JSON.parse(require("fs").readFileSync(process.argv[1],"utf8")); for (const [k,v] of Object.entries(c)) console.log(k, k==="token" ? "<" + (/^mzn_[A-Za-z0-9_-]{43}$/.test(v) ? "well-formed" : "MALFORMED") + ">" : JSON.stringify(v))' /home/mezzanine/.config/fleet-reporter/config.json
```

On the sandbox this printed `700` and `600`, and then every key with the token shown only as
`<well-formed>`.

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

```bash
node -e 'const fs=require("fs"),f=process.argv[1],v=process.argv[2]; if(!["full","paths","none"].includes(v)) throw new Error("full, paths or none"); const c=JSON.parse(fs.readFileSync(f,"utf8")); c.descriptors=v; fs.writeFileSync(f, JSON.stringify(c,null,2)+"\n", {mode:0o600}); console.log("descriptors =", v)' /home/mezzanine/.config/fleet-reporter/config.json none
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

```bash
( set -euo pipefail
  cat > "$B/wire-hooks.js" <<'JS'
const fs = require("fs"), path = require("path");
const [src, dst, js, prior] = process.argv.slice(2);
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
fs.writeFileSync(dst, JSON.stringify(s, null, 2) + "\n");
JSON.parse(fs.readFileSync(dst, "utf8"));
console.log("valid JSON written to " + dst + "; added: " + (added.join(", ") || "nothing"));
JS
  cat > "$B/check-preserved.js" <<'JS'
const fs = require("fs");
const [before, after, js] = process.argv.slice(2);
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
  node --check "$B/wire-hooks.js" && node --check "$B/check-preserved.js" && echo scripts-saved )
```

**(a) Hooks only**, staged in `$B`, checked, and only then copied over the live file:

```bash
( set -euo pipefail
  node "$B/wire-hooks.js" /home/mezzanine/.claude/settings.json "$B/settings.wired.json" /home/mezzanine/.local/share/fleet-reporter/fleet-reporter.js
  node "$B/check-preserved.js" /home/mezzanine/.claude/settings.json "$B/settings.wired.json" /home/mezzanine/.local/share/fleet-reporter/fleet-reporter.js
  cp "$B/settings.wired.json" /home/mezzanine/.claude/settings.json && echo applied )
```

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
SIGTERM the loop finishes its current pass and then its `K.FLUSH_MS` sleep. A pass awaits one health
request and `drainOnce`'s rounds, each round at most two `postBatch` calls (the 413 retry), and every
request is bounded by `K.REQUEST_MS`. `flusher-ancestry.js` walks the lock holder's parents and says
whether cron's start runs it:

```bash
( set -euo pipefail
  cat > "$B/stop-flusher.js" <<'JS'
const fs = require("fs"), path = require("path");
const [spool, js] = process.argv.slice(2);
const src = fs.readFileSync(js, "utf8");
const num = (re, what) => { const m = re.exec(src); if (!m) { console.error("cannot read " + what + " from " + js + ": no bound, NOTHING SENT"); process.exit(2); } return Number(m[1]); };
const FLUSH_MS = num(/\bFLUSH_MS:\s*(\d+)/, "K.FLUSH_MS"), REQUEST_MS = num(/\bREQUEST_MS:\s*(\d+)/, "K.REQUEST_MS");
const ROUNDS = num(/for \(let round = 0; round < (\d+);/, "drainOnce's round cap");
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
  if (Date.now() - t0 > boundMs) { clearInterval(poll); console.error("flusher pid " + pid + " still running " + boundMs / 1000 + " s after SIGTERM, past K.FLUSH_MS + K.REQUEST_MS x (1 + 2 x drainOnce rounds): NOT STOPPED"); process.exit(1); }
}, 1000);
JS
  cat > "$B/flusher-ancestry.js" <<'JS'
const fs = require("fs"), path = require("path");
const [spool, js] = process.argv.slice(2);
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
const flock = chain[1] && chain[1].comm === "flock" && chain[1].cmd.includes(js);
const cron = chain.some((c) => /^cron$/i.test(c.comm));
if (flock && cron) { console.log("PASS: the lock holder was started by cron through flock"); process.exit(0); }
console.log("FAIL: the lock holder's parents include " + (flock ? "flock" : "no flock") + " and " + (cron ? "cron" : "no cron") + ". A flusher cron did not start holds the lock (a hook's, or one run by hand), so cron's line is NOT verified");
process.exit(1);
JS
  node --check "$B/stop-flusher.js" && node --check "$B/flusher-ancestry.js" && echo helpers-saved )
```

Then write the block:

```bash
( set -euo pipefail
  JS=/home/mezzanine/.local/share/fleet-reporter/fleet-reporter.js
  SPOOL=/home/mezzanine/.local/state/fleet-reporter
  FLOCK=/home/mezzanine/.local/state/fleet-reporter-flusher.flock
  NODE=$(readlink -f "$(command -v node)")
  CC=$(node "$B/coord-config.js" /home/mezzanine/.claude/settings.json /home/mezzanine/.claude/settings.local.json)
  case "$JS$FLOCK$NODE$CC" in *[[:space:]%]*) echo "a crontab path holds whitespace or %: NOTHING WRITTEN" >&2; exit 1 ;; esac
  LINE="${CC:+COORD_CONFIG=$CC }flock -n $FLOCK $NODE $JS flusher >/dev/null 2>&1"
  crontab -l > "$B/crontab.before"
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
  node "$B/stop-flusher.js" "$SPOOL" "$JS" )
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

```bash
cd <checkout> && source bin/supervision.sh
( supervision_install_plan /home/mezzanine/mezzanine /usr/bin/php8.5 > /dev/null ); echo "plan rc=$?"        # -> plan rc=0
( crontab() { command crontab -l; echo '* * * * * cd /x/server && php artisan mezzanine:fold'; }
  supervision_install_plan /home/mezzanine/mezzanine /usr/bin/php8.5 > /dev/null ); echo "control rc=$?"   # -> refused, rc=1
```

**Check that cron started it, and not a hook.** It is not enough that *some* flusher runs. A flusher a
hook spawned holds the same lock and heartbeats just as well, so it can hide a cron line that never
works. After the next minute boundary:

```bash
node "$B/flusher-ancestry.js" /home/mezzanine/.local/state/fleet-reporter /home/mezzanine/.local/share/fleet-reporter/fleet-reporter.js
```

`PASS` needs the lock holder's parent to be `flock` running this artifact, and `cron` among its
ancestors. On the sandbox, read-only on 2026-09-13, it printed `node` ← `flock -n …` ←
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

```bash
node /home/mezzanine/.local/share/fleet-reporter/fleet-reporter.js selftest; echo "selftest rc=$?"
```

The command measures both network checks itself, with the flusher's own health probe against the
config's `ingest_url` and `ca_file` (card#9373), through `proxy_url` when the config sets one, the
route the batches take (card#9473). Read its exit code by D1 § 6.14:

- **`rc=0`** — every check passed. The install is verified.
- **`rc=1`** — a check failed; `checks` names it and `detail` says why. A `tls_verify` fail with an
  empty `detail.tls_verify.forbidden_spellings_present` means a TCP connection was made and the TLS
  handshake failed; `detail.tls_verify.probe_error` names the error, and the usual cause is a
  `ca_file` that does not trust the ingest's certificate.
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
  string for "no proxy" (write `null`), or a relative spool path. The URL errors deliberately leave the
  value out, because it may hold a proxy password; read the config file itself.
  A running flusher re-reads `ca_file` on each pass: once the file is readable it probes and sends the
  spooled events, logs `ca_file readable at <path>`, and needs no restart; a file that becomes
  unreadable stops its sending, and its heartbeat's `config_readable` reads `fail`, from the next pass. The flusher
  reads its config only when it starts, so after a config change (an absolute path, or `null`) stop it
  with Step 5's `stop-flusher.js`, as Step 8 item 3 runs it:
  `node "$B/stop-flusher.js" /home/mezzanine/.local/state/fleet-reporter /home/mezzanine/.local/share/fleet-reporter/fleet-reporter.js`.
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
- **`rc=2`** — no check failed and at least one is `not_measured`. The probe reached no ingest
  (`detail.tls_verify.probe_error`), or the ingest answered without its accepted set
  (`detail.schema_version_accepted.http_status`; a `401` is the ingest refusing the config's token,
  D1 § 4.1). Re-run once the ingest answers.

**The roster check reads the environment the command runs in** (D1 § 3.1). The flusher gets
`$COORD_CONFIG` from Step 5's crontab line, and a shell that has not exported it makes `selftest` read
the home path instead. `detail.protocol_agent_name_in_roster.read_via` says which it read. To check
the roster the flusher reads, run the command with the value Step 5 resolved in the environment.

⚠ **This corrected command has not yet been run on the sandbox seat.** It is exercised against the
acceptance suite's TLS ingest stub (`fleet-reporter.selftest.py` § 1). The card#9368 run on the sandbox
used the build before card#9373. There five checks passed: `config_readable`, `tls_verify`,
`sanitizer_fixtures`, `predicate_discrimination` and `harness_payload_keys`, the last for every hook in
the fixture set. `schema_version_accepted` was `fail` and the command exited `rc=1`, because that build's
one-shot never probed the ingest and so failed the check on every seat. Its `tls_verify` pass checked
the source alone. Step 7 reads the heartbeat's `selftest` object, which the flusher measures against the
real host, and on the sandbox every check there was `pass`.

## Step 7 — verify that events arrive

**On the seat.** The spool fills and drains, and the flusher logs to its own directory:

```bash
ls -la /home/mezzanine/.local/state/fleet-reporter/ && tail -5 /home/mezzanine/.local/state/fleet-reporter/log/*.log
```

**In the server's store** (read-only):

```bash
cd /home/mezzanine/mezzanine/server && php artisan tinker --execute='
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
counters had `events_sent` equal to `events_emitted`, with `batches_rejected` at 0. No `session.start`
has appeared yet, because the session running then had started before the hooks were wired. That
reading is from the seat. The store query above was not re-run for the hook events.

**The snapshot.** The REST route `GET /api/fleet/snapshot` serves `App\Read\Snapshot::build`
(`FleetController::snapshot`). It accepts a `mzr_` fleet_read token or an MFA session (FLEET-STATE
§ 9). This install issued no read credential, so it read the same object directly:

```bash
cd /home/mezzanine/mezzanine/server && php artisan tinker --execute='
$b = App\Read\Snapshot::build((int) floor(microtime(true) * 1000));
echo json_encode(["fleet" => $b["fleet"], "seats" => array_merge(...array_map(fn ($i) => $i["seats"], $b["installs"]))], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
'
```

⚠ **The HTTP leg was not exercised by this install**: the route's authentication, and the JSON as sent.
Checking it takes a read credential from `mezzanine:feed-token:issue`, which is a separate grant.

## Step 8 — uninstall and roll back

Do these in this order. Hooks go first, because while they stay wired every tool call respawns the
flusher (§ 2.3). The token goes last, because revoking it first leaves a running flusher posting with a
dead credential, which § 11.5 quarantines as permanent. Before each item, set `B=` to the attempt
directory that holds what the item restores. On the sandbox that is `20260913T203739Z-hooks` for item 1,
the pre-hooks `settings.json`, and `20260913T193245Z` for item 2, the crontab. Item 3 uses Step 5's
`stop-flusher.js`. If the attempt directory does not hold it, save it first with Step 5's helper block.

1. **Unwire the hooks.** If nothing else has changed `settings.json` since the hooks were applied,
   restore that attempt's backup, then check it is valid JSON:
   `cp -p "$B/settings.json" /home/mezzanine/.claude/settings.json && node -e 'JSON.parse(require("fs").readFileSync("/home/mezzanine/.claude/settings.json","utf8"))'`.
   If something else has changed it, remove only the entries whose command contains
   `share/fleet-reporter/fleet-reporter.js`. If Step 4(b) was applied, also set `statusLine.command`
   back to the config's `wrapped_statusline`.
2. **Remove the crontab block**, and nothing else. It is staged in `$B` and installed only if the
   `diff` against Step 0's backup is empty:
   `( set -euo pipefail; crontab -l | sed '/^# BEGIN fleet-reporter-flusher$/,/^# END fleet-reporter-flusher$/d' > "$B/crontab.rollback"; diff "$B/crontab.txt" "$B/crontab.rollback"; crontab "$B/crontab.rollback"; crontab -l | md5sum )`.
   The md5 must be Step 0's.
3. **Stop the flusher cleanly**, so it removes its own lock, and wait until it has exited:
   `node "$B/stop-flusher.js" /home/mezzanine/.local/state/fleet-reporter /home/mezzanine/.local/share/fleet-reporter/fleet-reporter.js`.
   It prints how long the exit took, or `NOT STOPPED` once the bound derived from the artifact's
   constants has passed. Do not go on to item 4 while a flusher runs. It checks the pid rather than
   pattern-matching process names, so it cannot match its own shell.
4. **Revoke the token on the server**, by prefix, on the Mezzanine host:
   `php artisan mezzanine:ingest-token:revoke mzn_YTRScmlE --reason='uninstall'`. To take the desk off the
   floor as well, run `php artisan mezzanine:retire --seat=mezzanine/mezzanine-solo --by=<operator> --reason=<why>`
   (FLEET-STATE § 4.10). Revocation leaves the seat's rows, and retirement is what hides the desk.
5. **Remove the seat's files**:
   `rm -rf /home/mezzanine/.config/fleet-reporter /home/mezzanine/.local/state/fleet-reporter /home/mezzanine/.local/share/fleet-reporter /home/mezzanine/.local/state/fleet-reporter-flusher.flock`.
   If the token was carried from another host, also run Step 2 (d)'s block on the Mezzanine host with
   `F` set to `mezzanine-reporter-token` in the attempt directory that issued it. It must print `gone`.

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
- **No context gauge** while Step 4(b) is not applied, which is the sandbox's state.
- **No Windows procedure exists yet.** One is owed when the Windows agent seat onboards, a real
  Windows machine. By operator ruling on 2026-09-13, D1 § 13's Windows validation is not required
  before then, and a validated Linux install (this runbook, card#9368) satisfies that precondition
  for the present. D1 § 3.1 names the Windows route owed at that onboarding.

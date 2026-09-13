# Installing `fleet-reporter` on a Linux seat, by hand and without root

There is no installer. Card #7336 is won't-do, so this runbook is how a Linux agent seat starts
reporting to a Mezzanine server. It was written by performing it on the sandbox host on 2026-09-13
(card#9368), for the seat `mezzanine` / `mezzanine-solo`. **Every command below ran there, with that
seat's values in place.** The only differences are the scratch directory the temporary files went to
and the checkout path, written here as `<checkout>`. Two parts were not applied live, and each says so
where it happens: the settings edit in [Step 4](#step-4--wire-the-hooks-and-the-statusline), and the
destructive half of [Step 8](#step-8--uninstall-and-roll-back).

[`docs/design/EVENT-SCHEMA.md`](../docs/design/EVENT-SCHEMA.md) (D1) is the contract, and this runbook
restates none of it. The config keys are § 3.1, the hook set is § 6.0, the install directory is § 2.1,
and the flusher's two starts are § 2.3.

## The values to substitute

| What | On the sandbox seat | What constrains it |
|---|---|---|
| seat account, home | `mezzanine`, `/home/mezzanine` | no root and no sudo anywhere; every path below sits under this home |
| `install_id` | `mezzanine` | D1 § 3.1's slug pattern |
| `seat_id` | `mezzanine-solo` | D1 § 3.1's slug pattern. The token is bound to the pair (§ 3.3), so a second seat is a second token |
| `protocol_agent_name` | `mezzanine` | D1 § 3.1: the name must be a member of the roster at `$COORD_CONFIG`, or the check is `disagreed` and the name resolves to no desk. This seat's roster holds `mezzanine` and not `mezzanine-solo`, which is why the name is not the `seat_id` ([Step 2](#step-2--issue-the-seat-token-straight-into-the-config)) |
| ingest URL | `https://sandboxmezzanine.neeba.com/api/ingest/events` | `https://` only (§ 3.5) |
| server checkout | `/home/mezzanine/mezzanine/server` | where `php artisan` runs. On the sandbox it is the same host; on another host, run Step 2 there and move the token by hand (see that step) |
| artifact | `/home/mezzanine/.local/share/fleet-reporter/` | § 2.1's Linux install directory |
| config | `/home/mezzanine/.config/fleet-reporter/config.json` | § 3.1: mode `0600`, directory `0700` |
| spool | `/home/mezzanine/.local/state/fleet-reporter` | § 3.1's example location |
| backups | `/home/mezzanine/.local/state/fleet-reporter-install/<UTC timestamp>/` | one directory per install attempt |

Paths are written out in full because the settings file and the crontab both need them expanded.
D1 § 2.1 explains why an unexpanded `~` in a settings command is the quietest failure a reporter can have.

## Why cron supervises the flusher, and not a `systemd --user` unit

D1 § 2.3 needs the flusher to run whenever the seat does, and gives it two starts: a supervised start,
and a respawn from every hook that finds the lock stale. The supervised start carries the load. An idle
seat fires no hooks, so without a supervisor its heartbeat stops and the desk renders `offline` while
the agent is only quiet.

**A `systemd --user` unit cannot supervise on these hosts.** `man loginctl` says lingering is what makes
"a user manager spawned for the user at boot and kept around after logouts". Without it, the unit
starts at the account's first login and stops at its last logout. This account reports `Linger=no`
(`loginctl show-user mezzanine | grep Linger`). The hosts are Virtualmin sub-server accounts with no
sudo and no lingering user manager, by operator ruling (`docs/PLAN.md § 5`, *No root, no sudo, no
systemd*). A unit there would supervise only while someone happened to be logged in.

**The user crontab has none of those limits, and this host already trusts it.** It runs as the seat
account, needs no root, fires `@reboot` at boot whether or not anyone logs in, and survives logout.
`bin/supervision.sh` already supervises the server's own daemons this way, so one host has one
supervision pattern instead of two. The entry runs every minute under `flock -n`, so a minute's start
does nothing while the previous copy still runs, and a flusher that exited is started again within a
minute.

**Two locks, and they are not redundant.** `flock -n` stops cron from stacking a new `node` process on
top of its own running copy every minute. The reporter's own `flusher.lock` (§ 2.3) is what makes the
flusher exclusive across *all* starts, cron's and the hooks'. A copy that loses that lock exits 0
straight away. One consequence was measured in Step 5: a flusher killed uncleanly leaves its
`flusher.lock` fresh, so no start takes over until the lock is 90 s stale (§ 2.3). Recovery therefore
took about two minutes, not one. A clean stop (SIGTERM) removes the lock, and the next minute restarts
the flusher.

**The start carries `$COORD_CONFIG` itself**, because D1 § 3.1's delivery contract requires it: a
flusher started by cron inherits nothing from the harness's settings, and on a healthy seat that
flusher is the one that heartbeats. The crontab entry assigns the variable on its own command line. If
the coordination config moves, re-run Step 5 with the new path; § 3.1 names that rewrite as owed.

---

## Step 0 — back up what the install touches

The install edits two things other software depends on. `~/.claude/settings.json` holds this account's
Claude Code hooks, which every session on the account runs, including the coord framework's own. The
crontab runs the server's daemons. Copy both, with a timestamp, before anything else:

```bash
date -u +%Y%m%dT%H%M%SZ                       # -> 20260913T193245Z, the directory name below
mkdir -p -m 0700 /home/mezzanine/.local/state/fleet-reporter-install/20260913T193245Z
cp -p /home/mezzanine/.claude/settings.json /home/mezzanine/.local/state/fleet-reporter-install/20260913T193245Z/settings.json
crontab -l > /home/mezzanine/.local/state/fleet-reporter-install/20260913T193245Z/crontab.txt
chmod 0600 /home/mezzanine/.local/state/fleet-reporter-install/20260913T193245Z/crontab.txt
sha256sum /home/mezzanine/.claude/settings.json /home/mezzanine/.local/state/fleet-reporter-install/20260913T193245Z/settings.json
crontab -l | md5sum                           # record it: Step 8 must return the crontab to exactly this
```

On the sandbox the two sha256 values matched, and the crontab's md5 was `bcd71d369c6c73ed8e61c819e3e2fb4d`.

## Step 1 — prerequisites and the artifact

You need `node` 18 or later (D1 § 2.1), plus `flock` and `crontab`, on the seat account's PATH. The
sandbox had `v22.22.1`, `/usr/bin/flock` and `/usr/bin/crontab`:

```bash
node --version && which node flock crontab
```

The artifact is two things from a checkout of PupFuzz/mezzanine: `fleet-reporter/fleet-reporter.js`, and
`fleet-reporter/fixtures/hooks/*.json`. The fixtures ship with the script because `selftest` reads them
on the seat (README, D1 § 2.1). The sandbox used a checkout of `dev` at `4ce0a19`:

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

## Step 2 — issue the seat token, straight into the config

**Check the identity first.** `install_id` and `seat_id` must match D1 § 3.1's patterns, and the issue
command refuses a value that does not. `protocol_agent_name` must be a member of the roster that
`$COORD_CONFIG` points at. This prints the names that roster holds:

```bash
node -e 'const c=JSON.parse(require("fs").readFileSync(process.argv[1],"utf8")); for (const r of c.roster||[]) console.log(r.name)' /home/mezzanine/.config/coord/coordination.config.json
```

On the sandbox it printed `mezzanine`. The seat declares `mezzanine`, so the check is `checked`.
Declaring `mezzanine-solo` would have been `disagreed`.

**The token is a credential, and it must never reach a terminal, an argv, a shell history or a log.**
`mezzanine:ingest-token:issue` prints the plaintext once and stores only its SHA-256. So the issue
command is piped straight into a small writer. The writer takes the token off the pipe, writes the
config with mode `0600`, and prints every other line of the command's output, with the token line
replaced. First save the writer. It takes no secret, so it can sit anywhere:

```bash
cat > /tmp/fleet-reporter-write-config.js <<'JS'
const fs = require("fs"), path = require("path");
const e = process.env, out = [], TOKEN = /^\s*token\s+(mzn_[A-Za-z0-9_-]{43})\s*$/, SHAPE = /mzn_[A-Za-z0-9_-]{43}/g;
let raw = ""; process.stdin.setEncoding("utf8");
process.stdin.on("data", (d) => { raw += d; });
process.stdin.on("end", () => {
  let token = null;
  for (const line of raw.split("\n")) {
    const m = TOKEN.exec(line);
    if (m) { token = m[1]; out.push("  token       <written to the config, not shown>"); } else out.push(line.replace(SHAPE, "<redacted>"));
  }
  console.log(out.join("\n"));
  if (!token) { console.error("no token line in the issue output: NOTHING WRITTEN"); process.exit(1); }
  const cfg = {
    install_id: e.FR_INSTALL, seat_id: e.FR_SEAT, ingest_url: e.FR_URL, token,
    spool_dir: e.FR_SPOOL, ca_file: null, proxy_url: null,
    wrapped_statusline: e.FR_WRAPPED || null, protocol_agent_name: e.FR_AGENT || null, enabled: true,
  };
  fs.mkdirSync(path.dirname(e.FR_CONFIG), { recursive: true, mode: 0o700 });
  fs.chmodSync(path.dirname(e.FR_CONFIG), 0o700);
  const fd = fs.openSync(e.FR_CONFIG, "wx", 0o600);
  fs.writeSync(fd, JSON.stringify(cfg, null, 2) + "\n"); fs.closeSync(fd);
  console.log("wrote " + e.FR_CONFIG + " (mode " + (fs.statSync(e.FR_CONFIG).mode & 0o777).toString(8) + ")");
});
JS
```

Before this ran for real, the writer was driven three ways: with a fabricated issue output, which wrote
the file at mode `600` in a `700` directory; against a config that already existed, which refused
(`'wx'`) and wrote nothing; and with output that had no token line, which exited 1 and wrote nothing.

`FR_WRAPPED` is the statusLine command the seat has **now**. D1 § 6.11's passthrough runs it and
relays its output, so the seat keeps its status line. Read it from `statusLine.command` in
`~/.claude/settings.json`. On the sandbox it was the coord framework's context sensor.

Then issue the token and write the config in one pipeline:

```bash
set -o pipefail; cd /home/mezzanine/mezzanine/server && php artisan mezzanine:ingest-token:issue mezzanine mezzanine-solo --by=card-9368 2>&1 | FR_CONFIG=/home/mezzanine/.config/fleet-reporter/config.json FR_INSTALL=mezzanine FR_SEAT=mezzanine-solo FR_URL=https://sandboxmezzanine.neeba.com/api/ingest/events FR_SPOOL=/home/mezzanine/.local/state/fleet-reporter FR_AGENT=mezzanine FR_WRAPPED="bash ~/.local/bin/context-sensor.sh ''" node /tmp/fleet-reporter-write-config.js; echo "pipeline rc=$?"
```

What it printed on the sandbox:

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
- **If the server is on another host**, the pipe cannot cross between machines. Run the issue command in
  a terminal nothing records, and type the token into the config with an editor, never on a command
  line. Then set the mode to `0600`.
- **Rotation.** D1 § 3.3's order applies: issue the new token, write it into the config, and only then
  revoke the old one.

## Step 3 — check the config file

```bash
stat -c '%a %n' /home/mezzanine/.config/fleet-reporter /home/mezzanine/.config/fleet-reporter/config.json
node -e 'const c=JSON.parse(require("fs").readFileSync(process.argv[1],"utf8")); for (const [k,v] of Object.entries(c)) console.log(k, k==="token" ? "<" + (/^mzn_[A-Za-z0-9_-]{43}$/.test(v) ? "well-formed" : "MALFORMED") + ">" : JSON.stringify(v))' /home/mezzanine/.config/fleet-reporter/config.json
```

On the sandbox this printed `700` and `600`, and then every key with the token shown only as
`<well-formed>`.

**`harness_label` is left unset, on purpose.** D1 § 6.1 wants `claude-code/<version>`, and the reporter
can only read that from this config key (see the note in the README's open-questions table). Claude Code
updates itself, so a version written here once stops being true at the next update, and the desk would
then show a wrong version as a fact. Unset, the field is an honest `null` and the
`payload_key_missing.harness_label` counter records the gap.

## Step 4 — wire the hooks and the statusLine

> ⚠ **NOT APPLIED to the sandbox seat's live settings file.** The agent performing this install was
> fenced away from editing `~/.claude/settings.json`, which this account also marks ask-first in its
> own permissions. The script below was run against a **copy** of that file, and that run is what is
> reported here. Applying it to the live file, and Step 7's hook-event check, are still owed by the
> operator or a session the permission prompt can reach.

This adds one entry per hook in D1 § 6.0's subscribed set to the **end** of that hook's array. The
tool-matching hooks get matcher `"*"`, and every other hook gets `""`, the convention the file already
uses. It then points `statusLine` at the reporter. It removes, reorders and rewrites nothing else. It
is idempotent, and it refuses to write if the current statusLine is not the command Step 2 recorded as
`wrapped_statusline`, so the two cannot disagree.

```bash
cat > /tmp/fleet-reporter-wire-hooks.js <<'JS'
const fs = require("fs");
const [src, dst, js, prior] = process.argv.slice(2);
const TOOL_HOOKS = ["PreToolUse", "PostToolUse", "PostToolUseFailure", "PermissionRequest", "PermissionDenied"];
const OTHER_HOOKS = ["SessionStart", "SessionEnd", "UserPromptSubmit", "Stop", "StopFailure", "SubagentStart",
  "SubagentStop", "PreCompact", "PostCompact", "Notification"];
const s = JSON.parse(fs.readFileSync(src, "utf8"));
s.hooks = s.hooks || {};
const added = [];
for (const h of [...TOOL_HOOKS, ...OTHER_HOOKS]) {
  const command = "node " + js + " hook " + h;
  const list = (s.hooks[h] = s.hooks[h] || []);
  if (list.some((e) => (e.hooks || []).some((x) => x.command === command))) continue;
  list.push({ matcher: TOOL_HOOKS.includes(h) ? "*" : "", hooks: [{ type: "command", command }] });
  added.push(h);
}
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
fs.writeFileSync(dst, JSON.stringify(s, null, 2) + "\n");
JSON.parse(fs.readFileSync(dst, "utf8"));
console.log("valid JSON written to " + dst + "; added: " + (added.join(", ") || "nothing"));
JS
cp /home/mezzanine/.claude/settings.json /tmp/settings.copy.json
node /tmp/fleet-reporter-wire-hooks.js /tmp/settings.copy.json /tmp/settings.wired.json /home/mezzanine/.local/share/fleet-reporter/fleet-reporter.js "bash ~/.local/bin/context-sensor.sh ''"
diff /tmp/settings.copy.json /tmp/settings.wired.json | grep '^<'     # the ONLY removed line must be the old statusLine command
```

What the run against the copy showed:

- It printed `added: PreToolUse, PostToolUse, PostToolUseFailure, PermissionRequest, PermissionDenied,
  SessionStart, SessionEnd, UserPromptSubmit, Stop, StopFailure, SubagentStart, SubagentStop, PreCompact,
  PostCompact, Notification, statusLine`.
- The diff removed exactly one line, the old statusLine command. Every existing hook stayed byte-identical
  and in its original order.
- A second run over its own output printed `added: nothing` and wrote a byte-identical file.
- A run naming the wrong prior statusLine exited 1 and wrote nothing.

**To apply it to the live file**, with Step 0's backup in hand:

```bash
node /tmp/fleet-reporter-wire-hooks.js /home/mezzanine/.claude/settings.json /home/mezzanine/.claude/settings.json /home/mezzanine/.local/share/fleet-reporter/fleet-reporter.js "bash ~/.local/bin/context-sensor.sh ''"
```

**What it costs the seat, measured on the sandbox host.** The host was under load, with a load average
of 9 to 11 on 4 cores. The measurement used a throwaway config and spool, so nothing reached the server
or the live context-sensor file:

| Invocation | Wall time, 10 runs |
|---|---|
| `hook PreToolUse` on the vendored fixture | 247–297 ms, rc 0 every run, 0 B on stdout |
| the current statusLine alone (`context-sensor.sh`) | 42–51 ms |
| `statusline` wrapping that same command | 309–505 ms, rc 0, byte-identical output (`ctx 42%`) |

The reporter's hook time is mostly `node` starting up; the README's latency section covers that.
Claude Code runs a hook event's commands in parallel, so a tool call waits for its slowest hook, not
the sum of them. **The statusLine is the cost to weigh.** Wrapped, the context sensor writes its
percentage about 300 ms later than it does today. Claude Code cancels a status-line script when a new
render arrives, so under rapid renders the sensor's file, which the coord framework's context budget
reads, can lag. The hooks alone give every activity event D1 defines except `context.sample`. Dropping
the statusLine half means a desk without a context gauge. Keeping it is this trade-off.

## Step 5 — supervise the flusher from the user crontab

Append a marked block. It goes **outside** `bin/supervision.sh`'s `# BEGIN/END mezzanine-supervision`
block, and that block is never edited:

```bash
crontab -l > /tmp/crontab.new
cat >> /tmp/crontab.new <<'CRON'
# BEGIN fleet-reporter-flusher
# D1 § 2.3 supervised start, no root: fleet-reporter/INSTALL-LINUX.md in PupFuzz/mezzanine. flock -n makes each start a no-op while one runs.
* * * * * COORD_CONFIG=/home/mezzanine/.config/coord/coordination.config.json flock -n /home/mezzanine/.local/state/fleet-reporter-flusher.flock /usr/bin/node /home/mezzanine/.local/share/fleet-reporter/fleet-reporter.js flusher >/dev/null 2>&1
@reboot COORD_CONFIG=/home/mezzanine/.config/coord/coordination.config.json flock -n /home/mezzanine/.local/state/fleet-reporter-flusher.flock /usr/bin/node /home/mezzanine/.local/share/fleet-reporter/fleet-reporter.js flusher >/dev/null 2>&1
# END fleet-reporter-flusher
CRON
diff /home/mezzanine/.local/state/fleet-reporter-install/20260913T193245Z/crontab.txt /tmp/crontab.new   # additions only
crontab /tmp/crontab.new && crontab -l | md5sum
```

The md5 went from `bcd71d369c6c73ed8e61c819e3e2fb4d` to `ed4cc42653b11607b1201a73b22e693e`.

- **`COORD_CONFIG=` is set on the command line itself**, which is D1 § 3.1's delivery contract (see
  above). Leave the assignment out if the seat has no `$COORD_CONFIG` in its Claude Code settings.
  § 3.1 says to deliver nothing then.
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

**Watch cron start it, and see who started it.** Within a minute:

```bash
cat /home/mezzanine/.local/state/fleet-reporter/flusher.lock; echo
pgrep -u mezzanine -af 'share/fleet-reporter/fleet-reporter.js flusher'
```

On the sandbox, at 19:36:01, the chain was `/bin/sh -c COORD_CONFIG=… flock -n …` → `flock -n …` →
`node … flusher`. `flusher.lock` named that `node` pid.

**Recovery, measured.** The test sent the flusher SIGKILL, the unclean death, which leaves
`flusher.lock` behind. It then polled until a new pid held the lock, and walked that pid's parents:

```
19:37:56 killing flusher pid 662767 with SIGKILL; lock mtime 19:37:52
19:40:08 new flusher pid 665727 holds the lock (lock mtime 19:40:03); ancestry:
 665727  665725 node   /usr/bin/node /home/mezzanine/.local/share/fleet-reporter/fleet-reporter.js flusher
 665725  665722 flock  flock -n /home/mezzanine/.local/state/fleet-reporter-flusher.flock /usr/bin/node …
 665722  665707 sh     /bin/sh -c COORD_CONFIG=/home/mezzanine/.config/coord/coordination.config.json flock -n …
 665707     403 cron   /usr/sbin/CRON -f -P
```

The flusher's own log shows why this took three cron minutes and not one. The 19:38 and 19:39 starts
each logged `another flusher owns the lock; exiting`, because the dead flusher's lock was still under
§ 2.3's 90 s. The 19:40 start found it stale and took over. It kept the existing `state.json`, so no
second state reset followed. **So an unclean death costs up to about two minutes of heartbeats.** A
clean stop, meaning SIGTERM, removes the lock, and the next minute takes over.

## Step 6 — selftest

```bash
node /home/mezzanine/.local/share/fleet-reporter/fleet-reporter.js selftest; echo "selftest rc=$?"
```

On the sandbox, five checks passed: `config_readable`, `tls_verify`, `sanitizer_fixtures`,
`predicate_discrimination` and `harness_payload_keys`. `harness_payload_keys` passed for all 15 hooks.
**`schema_version_accepted` was `fail`, so the command exited `rc=1`.** This build fails that check on
every seat. The one-shot `selftest` never probes the ingest; its detail says `read from GET
/api/ingest/health by the flusher; unprobed here`. So a non-zero exit from this command alone does not
mean the install is broken. What says so is the heartbeat's `selftest` object, which the flusher
refreshes against the real host. Step 7 reads it, and on the sandbox every check there was `pass`.
Anything *other* than `schema_version_accepted` failing here is a real failure.

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

On the sandbox at 19:47 this printed `seat_ref 1`, then `reporter.heartbeat n=10`, then heartbeats with
seq 8, 9 and 10 at 19:45:04, 19:46:04 and 19:47:04, each with every `selftest` member `pass`. Heartbeats
arrive 60 s apart (D1 § 9.1). The one gap in that run is Step 5's kill test. Once the hooks are wired,
a session that does work also adds `session.start`, `turn.start`, `tool.start` and `tool.end` rows.
Until then there are none, and the snapshot below shows the seat `link_state: live` and
`render_state: unknown` with `unknown_reason: no_data_yet`.

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
dead credential, which § 11.5 quarantines as permanent.

1. **Unwire the hooks.** If nothing else has changed `settings.json` since Step 0, restore the backup,
   then check it is valid JSON:
   `cp -p /home/mezzanine/.local/state/fleet-reporter-install/20260913T193245Z/settings.json /home/mezzanine/.claude/settings.json && node -e 'JSON.parse(require("fs").readFileSync("/home/mezzanine/.claude/settings.json","utf8"))'`.
   If something else has changed it, remove only the entries whose command contains
   `share/fleet-reporter/fleet-reporter.js`, and set `statusLine.command` back to the config's
   `wrapped_statusline`.
2. **Remove the crontab block**, and nothing else:
   `crontab -l | sed '/^# BEGIN fleet-reporter-flusher$/,/^# END fleet-reporter-flusher$/d' > /tmp/crontab.rollback && diff /home/mezzanine/.local/state/fleet-reporter-install/20260913T193245Z/crontab.txt /tmp/crontab.rollback && crontab /tmp/crontab.rollback && crontab -l | md5sum`.
   The `diff` must print nothing, and the md5 must be Step 0's.
3. **Stop the flusher cleanly**, so it removes its own lock:
   `kill -TERM "$(node -e 'console.log(JSON.parse(require("fs").readFileSync(process.argv[1],"utf8")).pid)' /home/mezzanine/.local/state/fleet-reporter/flusher.lock)"`.
   `pgrep -af 'share/fleet-reporter/fleet-reporter.js flusher'` must then print nothing.
4. **Revoke the token on the server**, by prefix:
   `php artisan mezzanine:ingest-token:revoke mzn_YTRScmlE --reason='uninstall'`. To take the desk off the
   floor as well, run `php artisan mezzanine:retire --seat=mezzanine/mezzanine-solo --by=<operator> --reason=<why>`
   (FLEET-STATE § 4.10). Revocation leaves the seat's rows, and retirement is what hides the desk.
5. **Remove the seat's files**:
   `rm -rf /home/mezzanine/.config/fleet-reporter /home/mezzanine/.local/state/fleet-reporter /home/mezzanine/.local/share/fleet-reporter /home/mezzanine/.local/state/fleet-reporter-flusher.flock`.

⚠ **As of card#9368, only the non-destructive part of this rollback has been run.** Item 2's `sed`
ran into a file. The `diff` against Step 0's backup printed nothing, and the file's md5 was
`bcd71d369c6c73ed8e61c819e3e2fb4d`. The file was not installed. Item 1 (the hooks were never applied)
and items 3 to 5 were not run, because they would take down the seat this runbook exists to connect.

## What this install does not give you, by name

- **`protocol_agent_name` is written and not yet sent.** `fleet-reporter.js` does not yet read the key
  or the roster (D1 § 3.1 says so). The heartbeat therefore carries no name, the snapshot shows
  `protocol_agent_name: null`, and `protocol_agent_name_in_roster` is not among the selftest checks.
  The config already declares the name a future build will send.
- **Every fresh seat is badged `epoch_reset`, and the badge stays.** The flusher's first start finds
  no `state.json`, and the reporter counts that as § 11.4's *unreadable or corrupt* state reset. The
  first heartbeat carries `state_reset: 1`. On the sandbox the badge was still on heartbeat seq 10,
  eleven minutes later and after a flusher restart, because the counter is a running total.
- **No Windows procedure exists.** D1 § 13 still names a real Windows install, card #7336, as a hard
  requirement before downstream treats this telemetry as true, and that card is won't-do.

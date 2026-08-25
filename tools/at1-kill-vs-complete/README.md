# AT-1 — the kill-vs-complete rig

Drives [`docs/design/EVENT-SCHEMA.md` AT-1](../../docs/design/EVENT-SCHEMA.md#at-1-kill-vs-complete-the-headline-test)
against a **real** Claude Code session: dispatch work, `/clear` it while a tool call is in
flight, and read what the reporter emitted. **D1 owns what this proves and D2 owns what a
consumer may do with it; this directory restates neither.** What lives here is the mechanics
those documents cannot state in prose — how a `/clear` reaches a real TUI, how the rig knows
the call was open when it landed, and how the same kill is replayed against a deliberately
defective reporter so a GREEN means something.

| File | What it is |
|---|---|
| `drive.py` | starts a throwaway session, waits for the target call to be **open in the reporter's own index**, then types `/clear` |
| `capture.py` | raw hook-payload capture, wired beside the reporter — evidence about the *harness* that does not depend on the reporter under test |
| `analyze.py` | D1 § 8.6's call ledger: every close each call received, and the final outcome under the amended rule and under the pre-amendment control |
| `derive.py` | D2 § 4.3's `derive_activity`: what a floor would render, under the amended rule and two controls |
| `plant.py` | makes the deliberately defective reporter copy AT-1's RED calls for; **raises** if the defect fails to apply |
| `stream.py` | the shared spool reader |
| `selftest.py` | hermetic — no credential, no session, no network. Asserts the rig still discriminates |

## What a run is evidence of, and what it is not

**A pass is evidence only because the failing outcome was reachable on the same input.** Every
reading in `derive.py` and every ledger rule in `analyze.py` ships beside the control that must
come out the other way, and `selftest.py` fails if they ever stop disagreeing. Run it first:

```
python3 tools/at1-kill-vs-complete/selftest.py
```

**If `drive.py` prints `NO TARGET CALL OBSERVED`, that run proves nothing — do not read its
stream.** The `/clear` is timed off the reporter's index journal precisely so that a clear
arriving after the call ended cannot be mistaken for a clear that killed it.

## ⛔ The credential prerequisite — operator-run, never scripted

Driving a real session needs a real credential. **The rig will not fetch one.** `drive.py`
raises `MissingCredentialError` and exits 3 if the scratch config directory holds none, rather
than reaching into `~/.claude/.credentials.json` — a public-repo script must not be the thing
that decides to copy a live token somewhere new.

The operator does this by hand, and undoes it by hand:

```bash
SCRATCH=~/at1-scratch                       # anywhere OUTSIDE any live seat's tree
mkdir -p "$SCRATCH/cc" && chmod 700 "$SCRATCH"
install -m 600 ~/.claude/.credentials.json "$SCRATCH/cc/.credentials.json"   # step in
#   ... run the rig ...
shred -u "$SCRATCH/cc/.credentials.json"                                      # step out
```

Two facts worth checking before the copy, because they are what make it bounded:

- **Check the token's expiry first and keep the run inside it.** A copy that never refreshes
  cannot rotate the live credential out from under the seats using it; a copy that *does*
  refresh might. `expiresAt` in that file is a ms epoch.
- **Never print the file.** Nothing in this rig reads its contents, and nothing should.

## Setup

One template config for the reporter under test, at `$SCRATCH/reporter-config.json`. Its
`spool_dir` is overwritten per run; everything else is yours:

```json
{ "install_id": "at1-scratch", "seat_id": "at1-scratch-seat",
  "ingest_url": "https://127.0.0.1:9/api/ingest", "token": "mzn_<43 base64url chars>",
  "spool_dir": "/replaced/per/run", "enabled": true,
  "harness_label": "claude-code/<the version you are about to run>" }
```

`ingest_url` points at a closed loopback port on purpose: the flusher retries forever, nothing
leaves the machine, and **every event stays in the spool where the rig can read it**. The token
is a throwaway that authenticates nothing.

⚠ **`harness_label` is a value you write, not a measurement.** It is whatever the installer put
there, so it can silently disagree with the binary that actually ran — which is exactly how a
mid-experiment auto-update went unnoticed once already (card #7337). **Read the version out of
the run's own `tty.log` banner** rather than trusting this field or a `claude --version` taken
before the run.

## Running it

```bash
R=path/to/fleet-reporter/fleet-reporter.js

# GREEN — the real reporter, a subagent's call in flight when the clear lands
python3 tools/at1-kill-vs-complete/drive.py --scratch "$SCRATCH" --tag green1 --reporter "$R"

# RED — AT-1's first RED: the § 8.3 reap disabled
python3 tools/at1-kill-vs-complete/plant.py --reporter "$R" \
        --out "$SCRATCH/red-reporter/fleet-reporter.js" --defect reap-disable
python3 tools/at1-kill-vs-complete/drive.py --scratch "$SCRATCH" --tag red1 \
        --reporter "$SCRATCH/red-reporter/fleet-reporter.js" --mode main

python3 tools/at1-kill-vs-complete/analyze.py "$SCRATCH/runs/green1" "$SCRATCH/runs/red1"
python3 tools/at1-kill-vs-complete/derive.py  "$SCRATCH/runs/green1" "$SCRATCH/runs/red1"
```

`--mode main` puts the **main agent's own** call in flight instead of a subagent's. Both are
worth running and they exercise different reap rows — and note that a `/clear` typed while the
main agent is running its own tool call is **queued** by the TUI rather than delivered, so that
mode's kill is precipitated by the interrupt that precedes the clear. The subagent mode is the
one that reproduces a clear landing mid-call, which is the case AT-1 is about.

**Between runs, kill the flusher whose `FLEET_REPORTER_CONFIG` is under your scratch tree —
and only that one.** It outlives the session, and deleting a spool directory out from under a
live flusher lets the next run start a second one against the same path:

```bash
for p in $(pgrep -f "fleet-reporter.js flusher"); do
  tr '\0' '\n' < /proc/$p/environ | grep -q "^FLEET_REPORTER_CONFIG=$SCRATCH" && kill $p
done
```

⛔ Never widen that filter. Other flushers on the box belong to other work.

## ⛔ Isolation — the contract this rig runs under

Live agent seats run on the same machine. A stray `/clear` or a shared spool path corrupts real
work, so:

- Everything is written under `--scratch`: the harness runs with `CLAUDE_CONFIG_DIR` pointed at
  `$SCRATCH/cc`, so it reads none of the operator's settings, hooks, MCP config, project list or
  session history, and writes none of them either.
- The reporter runs with `FLEET_REPORTER_CONFIG` pointed at a per-run config whose `spool_dir`
  is per-run. **The default config path (`~/.config/fleet-reporter/config.json`) is never
  created and never read.**
- The driver scrubs the outer agent's `CLAUDE_CODE_*` variables, so a nested session cannot
  bind itself to the parent's session or messaging socket.
- `--strict-mcp-config` is passed, because MCP config discovery otherwise walks up to the
  operator's home and opens an approval modal the driver would type into.

**Verify the paths before driving, not after.** `ls ~/.config/fleet-reporter` must still fail,
and the live `~/.claude/settings.json` mtime must be unchanged, when the run is over.

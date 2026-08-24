# `fleet-reporter` — the seat telemetry producer

The producer half of [`docs/design/EVENT-SCHEMA.md`](../docs/design/EVENT-SCHEMA.md) (D1). One
zero-dependency Node ≥ 18 file, installed on every agent machine, invoked by Claude Code hooks
and by the statusLine integration. **D1 is the contract; this directory implements it and
restates none of it.** Where a rule is cited below it is cited by section, never paraphrased.

| File | What it is |
|---|---|
| `fleet-reporter.js` | the whole producer — four subcommands, no npm dependencies |
| `fixtures/hooks/<HookEventName>.json` | D1 § 17's captured payloads, **part of the installed artifact** (§ 2.1), because `selftest` runs at install time on the seat and its `harness_payload_keys` check needs them there |
| `fleet-reporter.selftest.py` | the hermetic acceptance suite — no network, no credential, no board |

## The four subcommands (§ 2.1)

```
node fleet-reporter.js hook <HookName>   # one process per hook fire: read stdin, append, exit 0
node fleet-reporter.js statusline        # sample context, pass the seat's status line through
node fleet-reporter.js flusher           # one long-lived process per seat: POST batches, heartbeat
node fleet-reporter.js selftest          # the six checks § 6.14 declares; exits non-zero on any fail
```

The reporter's contract with the harness is narrow and stable: **it is invoked with the hook
name as `argv[2]` and the hook's JSON payload on stdin.** Hook wiring, the per-OS install path,
and the service registration for the flusher belong to the installer (card #7336).

## Configuration

One file, written at install time, and the **only** source of the reporter's identity (§ 3.1) —
never inferred from hostname, cwd, username, process tree, or any harness variable (§ 3.4).
Linux/macOS `~/.config/fleet-reporter/config.json` (0600), Windows
`%APPDATA%\fleet-reporter\config.json`.

`FLEET_REPORTER_CONFIG` overrides the **path** and nothing else — no value in the config comes
from the environment, so a wrong path is a loud `config_readable` failure rather than a silently
different identity.

Two keys are additions to § 3.1's table and are marked as such in the code:
`harness_label` (see "What D1 left open", below) and nothing else.

## Running the acceptance suite

```
python3 fleet-reporter/fleet-reporter.selftest.py
```

Python 3 stdlib plus `node` and `openssl` on PATH. It takes a few minutes: it drives the real
script as real subprocesses several thousand times, and stands up a TLS ingest stub on
127.0.0.1 with a throwaway self-signed certificate, trusted through the reporter's **own**
`ca_file` key — so the transport path runs with certificate verification ON rather than being
proven by turning it off.

**Every safety property is driven twice**: once against a deliberately defective copy of the
reporter, which must go RED, and once against the real one. A plant that matches nothing raises
rather than passing, because a RED that has quietly become a GREEN is worse than no test. The
suite prints a per-property RED/GREEN evidence block at the end.

## What D1 left open, and what was chosen

Each of these is marked `D1-SILENT` at its site in the source, with the reasoning. They are
listed here so a reviewer can find them without reading the file.

| Gap | Choice |
|---|---|
| `harness_label`'s source (§ 6.1 mandates `claude-code/<version>` but names no source; no hook payload carries one and no `CLAUDE_CODE_VERSION` exists in a hook-visible environment) | read from an installer-written `harness_label` config key; honestly `null` plus a counter until the installer writes it |
| The index journal has no record for sessions, turns or compactions, but § 8.2's 16-session cap, § 8.4's superseded-session rule, § 6.2's `turns` and § 6.4's `duration_ms` all need them | four reporter-internal record kinds added (`session_open`/`session_close`, `turn_open`/`turn_close`, `compaction_open`/`compaction_close`), plus `prompt_id` on `open` and `outcome` on `close`. None reaches the wire, so none costs a schema version |
| A hook with multiple captured payload shapes (§ 17 reproduces 3 for `PreToolUse`) cannot be "the payload verbatim" in one file | the fixture is `{"_source": …, "shapes": [ …verbatim payloads… ]}`, and the key check asserts against the union |
| Which of the two `/clear` signals emits the boundary events when both fire | whichever reaps first emits them; the second finds the session tombstoned, counts `reap_noop_second_signal`, and emits nothing — so no call is closed twice |
| Rule 6's rejoin separator on Windows | `/` for `~`, `.` and root-relative tokens (D1's own `~/…/design/…` output), `\` for a `X:` root (D1's own named root prefix) |
| Order of `attention.resolved` vs `turn.start` on `UserPromptSubmit` | resolution first, matching every close-before-trigger ordering in § 8.3 |

## What is NOT built here

The ingest endpoint, the server-side call ledger, orphan timeouts, staleness and the predicate
alarm — D1 § 16 artifacts 5–7, which belong to D2 and to the ingest card. This directory is
artifacts 0–4.

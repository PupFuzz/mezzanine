# D1 — the wire event schema

**`fleet-reporter` → Mezzanine ingest.** The contract every seat POSTs and the server accepts.

> **Status: Draft — pending design review.** Owner: aimla-pm. Gate: [`docs/PLAN.md § 2`](../PLAN.md#2-design-first-gates--the-order-is-the-plan)
> (P0 design, board 14). Written to the **standalone-implementer standard (D-14)**: an agent holding
> only this file must be able to build both ends. Nothing here is built yet — `fleet-reporter/` and
> the ingest route do not exist in this repo. Every number below carries its derivation; where a
> derivation rests on a value nobody has measured yet, it says so and names what to measure. Every
> **harness** fact carries one of three states — MEASURED, DOCS-CITED, UNVERIFIED — against payloads
> captured from Claude Code **2.1.240** and reproduced verbatim in
> [§ 17](#17-appendix--the-captured-harness-payloads).
> Decisions a reviewer is most likely to contest are collected in [§ 15](#15-decisions-taken-revisable-at-review),
> not scattered — and none is left as a placeholder: each is **decided**, and review may reverse it.

---

## 0. Overview

1. `fleet-reporter` is one zero-dependency Node.js file (Node ≥ 18) installed on every agent machine,
   Linux and Windows, invoked by Claude Code **hooks** and by the **statusLine** integration.
2. A hook invocation does exactly three things: read stdin, **append** — one line per event to the
   spool, one record to the call-index journal, one line to the counter sink
   ([§ 11.1](#111-layout)), every one of them an `O_APPEND` `writeSync` — and exit 0. It never opens
   a socket, never blocks, never exits non-zero, and never rewrites a file another process may be
   writing.
3. A separate long-lived **flusher** process on the same machine reads the spool and POSTs batches
   over **HTTPS** to the ingest — the server is on a different physical host, always ([§ 3.5](#35-transport-is-wan-always)).
4. The payload is **minimized at the reporter** (D-06): tool name plus a 200-byte sanitized
   descriptor, turn boundaries, context percentage, subagent task titles. Arguments and outputs
   never transit, so a misbehaving server cannot be handed a secret it was never sent.
5. Fourteen event kinds ([§ 6](#6-event-kinds)) cover session, turn, tool, subagent, compaction,
   context, attention (both edges) and reporter-liveness. [§ 6](#6-event-kinds) has fifteen
   sub-headings for them: § 6.0 is the field conventions, not a kind.
6. Every tool call is an explicit **open/close ledger entry** — that is how a `/clear`-killed call
   stays distinguishable from a completed one, which is the requirement everything else bends around
   ([§ 8](#8-call-lifecycle--the-kill-vs-complete-contract)).
7. The batch envelope carries one `schema_version`; the ingest declares its accepted set and
   **rejects an unaccepted version loudly**, per [`docs/VERSIONING.md § Wire compatibility`](../VERSIONING.md#wire-compatibility--the-reporter-to-ingest-contract-has-its-own-version-line).
8. Identity is `install_id` + `seat_id` from an install-time config file — never inferred from
   harness environment markers ([§ 3.4](#34-why-identity-never-comes-from-the-environment)) — and the
   server trusts the **bearer token's** binding, never the payload's claim.
9. Delivery is best-effort with **bounded, counted** loss: at-least-once with server-side dedup, a
   32 MiB spool, drop-oldest overflow, and a counter for every discarded event.
10. A silent reporter is the failure this design fears most, so a 60 s **heartbeat** plus a 300 s
    server-side staleness alarm turns "gone dark" into a rendered state instead of a quiet floor.
11. **Nothing here restates another product's schema without a stated basis and a check that reds when
    it moves.** Every harness key name, enum value and firing condition is MEASURED against a captured
    payload ([§ 17](#17-appendix--the-captured-harness-payloads)), DOCS-CITED with its source and
    date, or UNVERIFIED with its cost — and the reporter's `selftest` asserts its own expectations
    against those fixtures ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read),
    [AT-21](#at-21-the-harness-fact-drift-guard)). The check has **two** bindings, because the first
    one alone let a wrong value set through three reviews: key names are bound to the installed
    build's payload schema, and every harness enum's **value set** is bound to the build's own
    declaration, at every place this document states it.

```
  Claude Code seat (Linux or Windows)                    │ WAN, TLS 1.2+ │   Mezzanine host
  ─────────────────────────────────────────────────────  │               │  ────────────────
  SessionStart/End ─┐                                    │               │
  UserPrompt/Stop  ─┤                                    │               │
  StopFailure      ─┤                                    │               │
  Pre/PostToolUse  ─┤                                    │               │
  PostToolUseFail  ─┼─▶ fleet-reporter.js hook <Name>    │               │
  Subagent Start/Stop│     · read stdin JSON             │               │
  Pre/PostCompact  ─┤     · sanitize (allowlist+redact)  │               │
  Permission Req/Den┤     · ONE append per record        │               │
  Notification     ─┤     · exit 0, always, ≤ 250 ms     │               │
  statusLine       ─┘              │                     │               │
                                   ▼                     │               │
                     spool/<YYYYMMDDHH>.jsonl  ──▶  fleet-reporter.js flusher
                     index/ counters/ sample/           · batch ≤ 200 ev / 256 KiB
                                                        · POST ───────────▶ POST /api/ingest/events
                                                        · retry 2s→120s jitter        │
                                                        · heartbeat every 60 s        ▼
                                                                              202 / 400 / 401 / 403
                                                                              413 / 422 / 429 / 5xx
```

---

## 1. Non-goals

Stated so an implementer cannot widen scope in good faith. Each is a decision, not an omission.

| Not in this contract | Why |
|---|---|
| **Full tool arguments or outputs** | D-06, binding. The descriptor is a label, not a record. A telemetry stream that can carry a command's arguments will eventually carry a secret; the only reliable prevention is never building the path. |
| **Any server → reporter channel** | The wire is one-way. No config push, no remote disable, no "run this". An ingest that can command seats is a fleet-wide remote-execution surface, and the dashboard gains nothing from it. Reporter configuration changes by editing the seat's config file. |
| **Any dependency on the webhook bridge** | D-10: Mezzanine is an observer and stands alone. The reporter POSTs to Mezzanine directly; the bridge is neither in the path nor a fallback. |
| **PII beyond `install_id` / `seat_id`** | No prompt text, no file contents, no OS usernames, no hostnames, no email addresses, no IP addresses. Usernames leak through absolute paths, which is why [§ 7.3 rule 6](#73-redaction-rules-applied-in-this-order) rewrites them. |
| **The storage schema, retention, and state model** | D2 (`docs/design/FLEET-STATE.md`). This doc says what arrives and what it *means*; D2 says what is kept. Where D1 constrains D2 it is marked **`D2-MUST`** and there are exactly five such constraints ([§ 12.6](#126-the-five-d2-must-constraints)). |
| **Anything rendered** | D3 (`docs/design/FLOOR.md`). |
| **Human authentication** | Seat tokens authenticate machines. MFA gates the browser plane and never touches this endpoint (`docs/PLAN.md § 3`: "seat-token ingest is separate and never browser-facing"). |
| **Guaranteed delivery** | Best-effort, at-least-once, with bounded loss that is always counted and surfaced. A durable-queue guarantee would put a broker on every agent machine to protect a dashboard. |
| **OpenTelemetry / a generic tracing pipeline** | Rejected deliberately: its defaults carry rich attributes — exactly what D-06 forbids — and it adds a collector to every seat to move ~500-byte events at ~6 requests/minute. |
| **Log shipping** | The reporter's own log stays local. It is a diagnostic for the seat's owner, not a stream. |

---

## 2. The producer — process model

### 2.1 One file, four subcommands

`fleet-reporter.js` is a single file with no npm dependencies (a seat must be installable by copying
one file plus one config; a dependency tree is a supply-chain surface on every agent machine).

| Invocation | Runs as | Job |
|---|---|---|
| `node fleet-reporter.js hook <HookName>` | one-shot, one process per hook fire | parse stdin, build ≤ 1 event, append to spool, exit 0 |
| `node fleet-reporter.js statusline` | one-shot, fires on every status-line render | sample context ([§ 6.11](#611-contextsample)), write the session's last-sample state, **pass the wrapped status line through to stdout**, exit 0 |
| `node fleet-reporter.js flusher` | long-lived, one per seat | own the spool cursor, POST batches, emit heartbeats |
| `node fleet-reporter.js selftest` | one-shot, run by the installer and by CI | assert config, TLS reachability, accepted schema set, sanitizer fixtures, predicate discrimination, and **`harness_payload_keys`** — the captured-fixture assertion required by [§ 6.0](#60-conventions-and-how-harness-payloads-are-read)'s `SELFTEST-MUST` |

Hook wiring lives in the seat's Claude Code settings; the complete set of hooks this design
subscribes to, and what each one produces, is
[§ 6.0](#60-conventions-and-how-harness-payloads-are-read). The shape below is illustrative, and it is
the shape that was actually used to capture
[§ 17](#17-appendix--the-captured-harness-payloads)'s fixtures — so it is a working configuration at
2.1.240, not a sketch. The reporter's own contract is narrower and stable: *it is invoked with the
hook name as `argv[2]` and the hook's JSON payload on stdin.*

**The reporter ships the fixtures beside itself.** `fixtures/hooks/<HookEventName>.json` is part of
the installed artifact, not a test-only asset, because `selftest` runs at install time on the seat and
the `harness_payload_keys` check needs them there
([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)). One file per subscribed hook; each
carries [§ 17](#17-appendix--the-captured-harness-payloads)'s payload for that hook verbatim, plus
one added key — `"_source": "capture"` or `"_source": "docs-cited-stub"`, which is how the vendored
file records whether it is a measurement or a declaration
([§ 17.1](#171-docs-cited-stubs--the-five-hooks-that-could-not-be-driven)). The appendix does not show
that key, because there it is the surrounding prose that says which a payload is.

```json
{
  "hooks": {
    "PreToolUse": [
      { "matcher": "*", "hooks": [
        { "type": "command", "command": "node /home/agent/.local/share/fleet-reporter/fleet-reporter.js hook PreToolUse" } ] }
    ]
  },
  "statusLine": {
    "type": "command",
    "command": "node /home/agent/.local/share/fleet-reporter/fleet-reporter.js statusline"
  }
}
```

The path above is **expanded**, deliberately: the table below makes an unexpanded `~` in a settings
file the quietest possible reporter failure, and a worked example that shows the forbidden form is
what an implementer copies.

**The install path is per-OS, and the settings file needs it expanded.** The `~/.local/share/…` form
above is a Linux/macOS convention with no Windows meaning, and `~` is not expanded for a command
string on Windows — a settings file carrying it produces a command that silently never runs, which is
the quietest possible reporter failure:

| OS | Install directory | What the installer writes into settings |
|---|---|---|
| Linux / macOS | `~/.local/share/fleet-reporter/` | the expanded absolute path (`/home/<u>/.local/share/…`), never relying on `~` being expanded for it |
| Windows | `%LOCALAPPDATA%\fleet-reporter\` | the **expanded** absolute path (`C:\Users\<u>\AppData\Local\fleet-reporter\fleet-reporter.js`) — never `~`, never an unexpanded `%VAR%` |

### 2.2 Rules that protect the seat

These are absolute. A violation of any of them is a defect even if telemetry is perfect.

| # | Rule | Mechanism | Failure if broken |
|---|---|---|---|
| P-1 | **Always exit 0**, on every path including a crash | top-level `try/catch` around everything; `process.exit(0)` in a `finally` | exit **2** is the harness's *block* signal: a `PreToolUse` exiting 2 blocks the tool call outright and feeds the reporter's stderr to the model. Any other non-zero is a non-blocking error whose stderr still reaches the transcript. Only exit 0 leaves the seat untouched |
| P-2 | **Emit no JSON on stdout from a `hook` invocation** | the `hook` subcommand writes nothing to stdout, ever | hook stdout is harness control input — and on `SessionStart` and `UserPromptSubmit` it is **added to the model's context**, so stray output is not merely a behaviour change: it is text the model reads as if it were part of the session |
| P-3 | **No network in the hook path** | the `hook` subcommand contains no HTTP client call | a WAN round-trip inside a hook adds ≥ 100 ms to every tool call on the seat |
| P-4 | **Synchronous appends only, then exit** | every write is `fs.writeSync` on a descriptor opened `'a'`; no `await` between the first write and the exit | an event-loop hang holds the seat. A hook writes at most: one spool line per event it emits (its own, plus any reap closes), one index-journal record per ledger mutation ([§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)), and one counter line ([§ 11.1](#111-layout)) — each an independent `O_APPEND` write, never a read-modify-write of a shared file |
| P-5 | **p99 hook wall time < 250 ms** | measured by AT-3 | see AT-3 |
| P-6 | **Never print a token or a raw payload** | the config's `token` is redacted in every diagnostic path; raw hook stdin is never logged | a transcript, a log or an `argv` is a secret-exfiltration surface |
| P-7 | **The flusher is spawned detached and windowless** | `spawn(…, { detached: true, stdio: 'ignore', windowsHide: true }).unref()` | the hook would wait on the flusher's lifetime — and without `windowsHide` every respawn flashes a console window on a Windows seat, which is a visible disturbance of the seat the reporter exists to stay invisible to |

**Budget derivation for P-5 (250 ms).** Node 18 cold start on a modern machine is 30–60 ms and
dominates; the reporter's own work is one `JSON.parse` of a payload under 1 MiB, a few regexes over
≤ 2 KiB of text, a fold of the call index ([§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open))
— a snapshot of ≤ 128 records plus at most one flush interval of journal tail — and its appends.

**The append count is dominated by the reap, not by the ordinary case, and the budget is derived
against the reap.** An ordinary `PreToolUse` writes three to six small appends. A session-boundary
hook at the 64-open-call cap writes far more: up to 64 `tool.end` spool lines plus a `subagent.stop`
for each dispatch call among them, plus `turn.end`, plus `session.end`, plus one index record per
ledger mutation and one counter line — **~130 appends from one hook process**. At an `O_APPEND`
`writeSync` of a sub-4-KiB buffer costing ~20–40 µs on a warm page cache, 130 appends is ~3–5 ms, so
the reap stays inside the budget and the 30–60 ms Node cold start still dominates. That is the
arithmetic the 250 ms is chosen against: ~4× the expected worst case *including* the reap, and under
the ~300 ms at which a human notices added latency between tool calls. An earlier draft derived the
same number against "three to six small appends" and simply did not consider the reap — the number
survived the correction, the derivation did not.
**This is a budget to verify, not a measurement**: AT-3 measures it on both platforms — including a
reap-at-cap invocation, which is the worst case and therefore the one the p99 must be taken over — and
if a real seat exceeds it, the fix is in the reporter, not in the number.

### 2.3 The flusher must be alive whenever the seat is

The heartbeat is only a liveness signal if its absence means something. Two mechanisms, both required:

1. **Supervised start** — the installer registers the flusher with the OS: a `systemd --user` unit on
   Linux, a Scheduled Task at logon on Windows. (Mechanics belong to the installer card, #7336; the
   *contract* is here.)
2. **Opportunistic respawn** — every `hook` invocation checks `spool/flusher.lock`. If the lock is
   absent, or its `mtime` is older than **90 s**, the hook spawns a detached flusher (`windowsHide`,
   per P-7). Derivation of 90 s: the flusher touches the lock once per heartbeat (60 s), so 90 s = 1.5
   heartbeat intervals — long enough that a flusher busy in a 15 s POST is never declared dead,
   and under two heartbeat intervals, so a crashed flusher is replaced by the next hook fire.

**Flusher exclusivity is mandatory, not advisory.** `flusher.lock` is created with
`O_CREAT|O_EXCL` (`fs.openSync(path, 'wx')`), which is atomic on both platforms: exactly one process
can win it. It holds `{"pid":…,"started_at":…,"seq_epoch":…}`, and its `mtime` is touched every
heartbeat. A starting flusher that loses the create reads the lock: if the `mtime` is newer than 90 s
it **exits 0 immediately**; if it is older it re-stats, unlinks the lock **only if the `mtime` it
read is still the one on disk**, and retries the exclusive create exactly once. Losing that retry is
also an immediate exit 0.

**The lock is not the correctness mechanism, though — ownership is.** `state.json` carries
`owner_pid` and `owner_started_at`. Before every write of `state.json` the flusher re-reads it and
writes only if it still names itself as owner; one that finds another owner increments
`flusher_lost_ownership`, logs, and exits 0 without writing. Two flushers overlapping is therefore
**not** a tolerated state. It was, in an earlier draft, on the grounds that server-side dedup absorbs
the duplicate events — but dedup absorbs *events*, not the `seq` counter. Two flushers each reading
`next_seq = X` produce either a gap (and the seat renders `lossy` from nothing) or two events sharing
one `(seq_epoch, seq)` — the ordering key `D2-MUST` #4 makes load-bearing. The residual window is the
microseconds between that re-read and the `rename`, and even that is not assumed away: the server
treats a repeated `(seq_epoch, seq)` carrying two different `event_id`s as `seq_collision`, counted
and badged ([§ 10.2](#102-ordering-seq-and-gap-detection)).

**An idle seat still heartbeats.** That is the point: *heartbeat present + no activity = idle*
(honest, rendered as a quiet desk); *heartbeat absent = stale* (rendered as visibly degraded, never
as idle). A powered-off machine is correctly offline.

---

## 3. Identity, configuration and transport

### 3.1 The seat config file

Written once, at install time, by the installer. It is the **only** source of the reporter's identity.

| Path | Mode |
|---|---|
| Linux/macOS | `~/.config/fleet-reporter/config.json`, `0600`, directory `0700` |
| Windows | `%APPDATA%\fleet-reporter\config.json`, ACL restricted to the installing user |

| Key | Type | Null? | Bounds | Example |
|---|---|---|---|---|
| `install_id` | slug | no | `^[a-z0-9][a-z0-9-]{1,31}$` | `"aimla"` |
| `seat_id` | slug | no | `^[a-z0-9][a-z0-9-]{1,47}$` | `"aimla-pm"` |
| `ingest_url` | string | no | absolute `https://` URL, ≤ 256 B | `"https://mezzanine.example.org/api/ingest/events"` |
| `token` | string | no | `mzn_` + 43 base64url chars | `"mzn_kQ7…"` *(never printed, never logged, never echoed)* |
| `spool_dir` | string | no | absolute path, ≤ 256 B | `"/home/agent/.local/state/fleet-reporter"` |
| `ca_file` | string | **yes** | absolute path or `null` | `null` (set only for a sandbox host with a private CA) |
| `proxy_url` | string | **yes** | absolute `https://`/`http://` proxy URL or `null` | `null` |
| `wrapped_statusline` | string | **yes** | the seat's previously configured statusLine command, ≤ 512 B, or `null` | `"/home/agent/bin/my-statusline.sh"` |
| `enabled` | bool | no | — | `true` |

`enabled: false` is the **only** switch that stops emission, and it is explicit, local, and visible in
the heartbeat's last transmission. There is no other kill switch and no environment variable that
disables the reporter ([§ 3.4](#34-why-identity-never-comes-from-the-environment)).

**Identity stability rule.** `install_id` and `seat_id` are file-resident, so they survive session
restarts, `/clear`, reboots, host renames, and harness upgrades. They change only when a human edits
the file or re-runs the installer — which is a re-identification of the desk and is intended to be a
deliberate act. Neither is ever derived from hostname, cwd, username, process tree, or any harness
variable.

**Why slugs and not UUIDs.** These strings appear in operator conversation, dashboards, logs and
support questions ("is `impl-2` reporting?"). A random identifier makes every such exchange require a
lookup table. Uniqueness is not enforced by the format — it is enforced by the **token binding**
([§ 3.3](#33-authentication-and-the-identity-binding-rule)): a token is issued to exactly one
`(install_id, seat_id)` pair, so two seats claiming one identity is a provisioning error caught at
token-issue time, not a silent merge of two desks.

### 3.2 Session identity

`session_id` is taken **verbatim** from the harness hook payload and never parsed, normalised, or
interpreted. It is opaque, ≤ 128 bytes, `^[A-Za-z0-9._:-]{1,128}$`; a value failing that pattern is
replaced with `null` and counted as `bad_session_id` (see [§ 9.3](#93-degradation-counters)) — the
event is still emitted, because an event with an unknown session is worth more than no event.

`session_id` is `null` on exactly one kind, `reporter.heartbeat`, which is produced by the flusher and
belongs to no session.

### 3.3 Authentication and the identity-binding rule

- Header: `Authorization: Bearer mzn_<43 base64url chars>`. `mzn_` is a fixed prefix so the string is
  greppable in a config sweep and matchable by a secret scanner.
- Entropy: 32 random bytes from a CSPRNG → 43 base64url characters. Derivation: 256 bits is the
  standard floor for a bearer credential with no rate-limit-independent guessing defence.
- **Server storage: SHA-256 of the token, never the plaintext.** A token table an app can read is a
  fleet-wide credential dump the first time any read primitive leaks.
- The token row binds exactly one `(install_id, seat_id)`.

> **The binding rule (MUST).** The server derives the authoritative `(install_id, seat_id)` **from the
> token**. The batch's claimed `install_id`/`seat_id` are validated for *equality* with the binding
> and are never used to route, create, or attribute a record. A mismatch is `403 identity_mismatch`,
> the whole batch is rejected, the refusal is counted against the **token's** binding — never
> against the identity the body claimed, including for the refusals that happen before this check
> runs ([§ 12.1](#121-validation-order)) — and that seat renders degraded.
> A payload cannot name itself into another desk, nor name another desk into a degraded state.

**Rotation order (the refusable step first).** Issue and activate the new token **server-side first**
(old and new both valid for a 7-day overlap), *then* write the new token into the seat config, *then*
revoke the old one. The reverse order leaves a seat holding a credential the server never learned if
the server-side step is refused or fails — a dark seat with nothing to roll back to. 7 days is the
overlap window because seats are upgraded by their own owners on their own schedule
([`docs/VERSIONING.md § Deploy is not a tag`](../VERSIONING.md#deploy-is-not-a-tag--and-mezzanine-has-two-targets)),
and a week spans one weekend plus slack.

### 3.4 Why identity never comes from the environment

**Measured in this fleet, 2026-08-23.** A seat-detection predicate keyed on the undocumented harness
variable `CLAUDE_CODE_CHILD_SESSION` went silently constant when Claude Code ≥ 2.1.219 began setting
it on top-level seats as well as children. Two production consumers were dark for **30 days**, failing
closed with no log line. Three consequences bind this design:

1. **No emission is gated on any undocumented harness environment marker.** Identity is config-file
   resident ([§ 3.1](#31-the-seat-config-file)). Environment variables may be *read* for labels, never
   for a decision about whether to emit.
2. **Every predicate in the reporter that classifies anything reports both branch counts in the
   heartbeat**, and the server alarms when a branch goes constant ([§ 9.4](#94-the-predicate-constant-alarm)).
   Classification predicates *label*; they never suppress. The one exception is statusLine sampling
   ([§ 6.11](#611-contextsample)), whose suppressions are counted individually.
3. **The heartbeat plus the staleness alarm is the structural backstop** ([§ 9](#9-liveness-heartbeat-staleness-and-the-predicate-alarm)).
   The incident's real cost was not the wrong predicate — it was that "wrong" and "working" looked
   identical from outside for a month. Liveness must be asserted continuously by the producer and
   alarmed on by the consumer, so silence is a state rather than an appearance.

The same lesson shapes two smaller choices elsewhere: the hook name arrives **twice** (as `argv[2]`
and as the payload's `hook_event_name`) and disagreements are counted ([§ 9.3](#93-degradation-counters));
and `/clear` is detected by **two independent signals** ([§ 8.4](#84-detecting-a-clear-with-two-independent-signals)).

### 3.5 Transport is WAN, always

**Operator ruling, 2026-08-23: the Mezzanine server runs on a physically separate host from every
agent seat.** There is no loopback deployment mode, and no part of this design may assume same-host
anything — no unix socket, no shared filesystem, no "it's local so a retry is cheap".

| Requirement | Value | Derivation / reason |
|---|---|---|
| Scheme | `https://` only | the bearer token is in the header; cleartext is a credential broadcast |
| A `http://` `ingest_url` | **refused** at install (`selftest` fails) and at runtime (flusher refuses to send, sets `config_invalid`, keeps spooling) | fail closed, loudly, on the client's own surface |
| TLS | ≥ 1.2, certificate verification **always on** | — |
| Disabling verification | **forbidden**: no `rejectUnauthorized:false`, no `NODE_TLS_REJECT_UNAUTHORIZED=0` | a sandbox host with a private CA is supported by `ca_file` → `NODE_EXTRA_CA_CERTS`. Loosening verification to make a sandbox work is the classic constraint-weakening fix, and it ships to production seats |
| Connection reuse | keep-alive, ≤ 2 sockets | a TLS handshake is 2 RTTs; at 6 flushes/min a fresh handshake each time is ~12 avoidable RTTs/min/seat |
| Total request deadline | **15 s** | 256 KiB on a 1 Mbit/s uplink is 2.1 s; plus TLS setup (~1 s pathological) plus server processing (target < 500 ms) ≈ 4 s worst realistic case. 15 s ≈ 3.5× that — past it, retrying beats waiting |
| Connect deadline | **5 s** | a cross-continent TLS connect is ~300 ms typical, ~2 s pathological; 5 s ≈ 2.5× pathological. *Enforceable only via `https.request` + `socket.setTimeout`; with global `fetch` only the 15 s total deadline is enforceable. Either implementation is acceptable — the binding requirement is the 15 s ceiling.* |
| Proxies | `config.proxy_url` only; `HTTP(S)_PROXY` environment variables are **ignored** | § 3.4 rule 1 — no transport decision from ambient environment |
| Compression | `Content-Encoding: gzip` permitted, at the flusher's discretion, when the body exceeds 8 KiB | below 8 KiB gzip's CPU and header cost outweighs the saving on a WAN; the server must accept both |

---

## 4. The envelope

### 4.1 Endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `POST` | `/api/ingest/events` | `Authorization: Bearer` | submit one batch |
| `GET` | `/api/ingest/health` | `Authorization: Bearer` | report the accepted schema-version set and server time |

- `Content-Type: application/json; charset=utf-8` is **required** on the POST; any other value is
  `415`.
- The endpoint accepts **no cookies and no session**, and sets no CORS headers. It is not
  browser-facing; a browser that reaches it gets nothing useful.
- **The path carries no version.** The schema version lives in the body, where the policy put it. A
  path version would be a second, silently divergent version line for one contract.
- `GET /api/ingest/health` requires a valid seat token — the accepted-version set is fleet-internal
  and every party who needs it (a seat, an operator debugging a seat) already holds a token. It
  returns `{"accepted_schema_versions":[…],"server_time":"…","min_reporter_version":"…"}`, read from
  the single machine-readable declaration required by
  [`docs/VERSIONING.md § Wire compatibility` rule 2](../VERSIONING.md#the-rules) — this document
  deliberately does not restate the accepted set anywhere.

### 4.2 Batch envelope fields

| Field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `schema_version` | int | — | no | ≥ 1 | `1` |
| `batch_id` | ULID | — | no | 26 chars | `"01K3T8ZQ5N7M2X9V4B6D0FGHJK"` |
| `install_id` | slug | — | no | ≤ 32 B | `"aimla"` |
| `seat_id` | slug | — | no | ≤ 48 B | `"aimla-pm"` |
| `reporter_version` | semver | — | no | ≤ 24 B | `"0.1.0"` |
| `reporter_platform` | enum | — | no | `linux` \| `win32` \| `darwin` \| `other` — `other` is the unknown member for any `process.platform` outside the three ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)) | `"linux"` |
| `runtime_version` | string | — | no | ≤ 24 B | `"v22.11.0"` |
| `seq_epoch` | ULID | — | no | 26 chars | `"01K3T0000A5N7M2X9V4B6D0FGH"` |
| `sent_at` | rfc3339_ms | UTC | no | — | `"2026-08-23T14:07:11.482Z"` |
| `events` | array | — | no | 1…200 elements | see [§ 4.5](#45-worked-batch-example) |

`schema_version` rides **both** the batch and every event ([§ 4.3](#43-common-per-event-fields)),
and the server enforces equality between them exactly as it does for identity. The batch copy is what
the ingest branches on before it looks at a single event; the per-event copy is what makes a stored,
forwarded or pasted event self-describing — the same argument identity is repeated on, and what the
policy's rule 1 asks for literally. They cannot drift: a batch is produced by one reporter process at
one version, so a mixed-version batch cannot arise on the wire, and an event whose `schema_version`
differs from its batch's is `422 invalid_event`. The one case that could
produce mixed data — a reporter upgraded while events from the older build are still spooled — is
handled inside the spool, which stores the version per line and makes the flusher split batches at
version boundaries ([§ 11.2](#112-spool-line-format)). That split is exactly what the `N`/`N-1`
support window exists to absorb.

### 4.3 Common per-event fields

Present on **every** event of every kind.

| Field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `event_id` | ULID | — | no | 26 chars | `"01K3T8ZQ6P2R4S8T0VWXYZ1234"` |
| `schema_version` | int | — | no | ≥ 1, **must equal the batch's** | `1` |
| `kind` | string | — | no | ≤ 32 B, `^[a-z]+\.[a-z_]+$`; the 14 currently-defined kinds are listed in [§ 6](#6-event-kinds), and an **unknown kind is accepted, ignored and counted** ([§ 5](#5-compatibility--what-this-document-owes-the-policy)), never a rejection | `"tool.start"` |
| `event_time` | rfc3339_ms | UTC, seat clock | no | — | `"2026-08-23T14:07:03.118Z"` |
| `seq` | int | — | no | 1…2^53−1, monotonic per `seq_epoch` | `48211` |
| `install_id` | slug | — | no | ≤ 32 B, **must equal the batch's** | `"aimla"` |
| `seat_id` | slug | — | no | ≤ 48 B, **must equal the batch's** | `"aimla-pm"` |
| `session_id` | string | — | **yes** (null only on `reporter.heartbeat`) | ≤ 128 B | `"e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913"` |
| `data` | object | — | no | kind-specific, ≤ 3 KiB serialized | `{ … }` |
| `oversize` | bool | — | **yes** | present and `true` **only** when the event exceeded the 4 KiB cap and was truncated at `data.descriptor` ([§ 4.4](#44-size-caps-and-their-derivations)); absent (⇒ `null`, [§ 6.0](#60-conventions-and-how-harness-payloads-are-read)) on every ordinary event, which is why the worked examples do not carry it | `true` |

**Why identity repeats on every event when the batch already carries it.** The batch is a transport
frame that is dissolved at ingest; the event is the durable unit and gets stored, forwarded, replayed
in a test fixture, and pasted into a bug report on its own. A self-describing event costs ~60 bytes
(≈ 12 % of a 500 B event) and removes an entire class of "which desk was this?" ambiguity downstream.
`schema_version` rides every event for the same reason and at ~20 bytes more: the one field that tells
a reader what all the others *mean* is the last one that should be left behind on a transport frame.
The duplication cannot drift because the server enforces equality with the batch header **and** the
token binding, and a mismatch rejects the whole batch. The alternative — batch-level only, stamped
onto each event at ingest — is defensible and is recorded in [§ 15](#15-decisions-taken-revisable-at-review)
as the runner-up.

`seq` is assigned by the **flusher**, not by the hook, because the flusher is the single writer of the
spool cursor and needs no lock to count ([§ 10.2](#102-ordering-seq-and-gap-detection)). A hook-side
counter would need cross-process locking inside the latency budget P-5 buys.

### 4.4 Size caps and their derivations

| Cap | Value | Derivation |
|---|---|---|
| One serialized event | **4 KiB** | typical events are ~500 B ([§ 14](#14-every-number-and-where-it-comes-from)); 4 KiB is 8× headroom and equals the conventional atomic-small-write floor (`PIPE_BUF` on Linux), which keeps one event = one `write()`. An event that would exceed it is truncated at `data.descriptor` and flagged `oversize:true` |
| Events per batch | **200** | at the ~500 B typical size a full batch is ~100 KB; 200 also bounds the blast radius of the atomic-batch rule ([§ 12.4](#124-batches-are-atomic)) |
| Batch body, uncompressed | **256 KiB** | binds before the 200-event cap in the worst case (200 × 4 KiB = 800 KiB). 256 KiB is 4× under the tightest common default in a Laravel stack — nginx `client_max_body_size` 1 MiB (PHP's `post_max_size` default 8 MiB is looser) — so a stock reverse proxy never silently `413`s a healthy seat. **The deploy host's actual value is unverified** (the host is not provisioned yet, `docs/PLAN.md` D-08); read it at first deploy and move this number if it is tighter |
| `data.descriptor` | **200 B** | [§ 7.4](#74-truncation) |
| `data.title` (subagent) | **120 B** | a dispatch description is 3–8 words; 120 B holds ~18 English words and fits the drill-down panel's one-line intern label |
| Total spool on disk | **32 MiB** | [§ 11.3](#113-rotation-and-the-overflow-policy) |

### 4.5 Worked batch example

A real minute on a PM seat: the prompt lands, a `Grep` runs and completes, a subagent is dispatched.

```json
{
  "schema_version": 1,
  "batch_id": "01K3T8ZQ5N7M2X9V4B6D0FGHJK",
  "install_id": "aimla",
  "seat_id": "aimla-pm",
  "reporter_version": "0.1.0",
  "reporter_platform": "linux",
  "runtime_version": "v22.11.0",
  "seq_epoch": "01K3T0000A5N7M2X9V4B6D0FGH",
  "sent_at": "2026-08-23T14:07:11.482Z",
  "events": [
    {
      "event_id": "01K3T8ZQ6P2R4S8T0VWXYZ1234",
      "schema_version": 1,
      "kind": "turn.start",
      "event_time": "2026-08-23T14:06:58.004Z",
      "seq": 48209,
      "install_id": "aimla",
      "seat_id": "aimla-pm",
      "session_id": "e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913",
      "data": { "prompt_chars": 412, "project_label": "mezzanine" }
    },
    {
      "event_id": "01K3T8ZQ7Q3S5T9V1WXYZ23456",
      "schema_version": 1,
      "kind": "tool.start",
      "event_time": "2026-08-23T14:07:01.771Z",
      "seq": 48210,
      "install_id": "aimla",
      "seat_id": "aimla-pm",
      "session_id": "e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913",
      "data": {
        "call_id": "01K3T8ZQ7Q3S5T9V1WXYZ23457",
        "tool_name": "Grep",
        "descriptor": "Grep: schema_version",
        "descriptor_truncated": false,
        "agent_scope": "main",
        "parent_call_id": null,
        "harness_call_ref": "toolu_01A9F3kQ2mZ",
        "open_calls_before": 0
      }
    },
    {
      "event_id": "01K3T8ZQ8R4T6V0W2XYZ345678",
      "schema_version": 1,
      "kind": "tool.end",
      "event_time": "2026-08-23T14:07:03.118Z",
      "seq": 48211,
      "install_id": "aimla",
      "seat_id": "aimla-pm",
      "session_id": "e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913",
      "data": {
        "call_id": "01K3T8ZQ7Q3S5T9V1WXYZ23457",
        "tool_name": "Grep",
        "outcome": "completed",
        "abort_reason": null,
        "duration_ms": 1347,
        "close_source": "post_tool_use",
        "match": "harness_ref"
      }
    },
    {
      "event_id": "01K3T8ZQ9S5V7W1X3YZ4567890",
      "schema_version": 1,
      "kind": "subagent.spawn",
      "event_time": "2026-08-23T14:07:09.902Z",
      "seq": 48212,
      "install_id": "aimla",
      "seat_id": "aimla-pm",
      "session_id": "e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913",
      "data": {
        "call_id": "01K3T8ZQ9S5V7W1X3YZ4567891",
        "title": "draft the D1 event schema",
        "subagent_type": "coder",
        "title_truncated": false
      }
    }
  ]
}
```

### 4.6 Successful response

```
HTTP/1.1 202 Accepted
Content-Type: application/json
```
```json
{
  "batch_id": "01K3T8ZQ5N7M2X9V4B6D0FGHJK",
  "accepted": 4,
  "duplicates": 0,
  "ignored_unknown_kinds": 0,
  "coerced_enum_values": 0,
  "server_time": "2026-08-23T14:07:11.981Z"
}
```

`202`, not `200`: the server has durably accepted the batch for processing, and state derivation is
asynchronous. The reporter treats `202` as "these events are safely somebody else's problem" and
advances its cursor; nothing else in any response changes reporter behaviour except
[§ 12.2](#122-error-responses).

---

## 5. Compatibility — what this document owes the policy

[`docs/VERSIONING.md § Wire compatibility`](../VERSIONING.md#wire-compatibility--the-reporter-to-ingest-contract-has-its-own-version-line)
**owns the versioning and compatibility policy**, including the additive-change rule that a new event
`kind` and a new closed-enum member need no version bump. None of that policy is restated here. This
section records only how the fields above comply, rule by rule.

**The left column cites; it does not paraphrase.** Each row links the rule by number and says only how
D1 complies. The rule *text* lives in `VERSIONING.md` and is not restated here even in summary — a
paraphrase beside the thing it paraphrases is a second copy free to drift, and this section exists
because that exact shape already cost this document one finding.

| Policy rule | How D1 complies |
|---|---|
| [rule 1](../VERSIONING.md#the-rules) | [§ 4.3](#43-common-per-event-fields) puts `schema_version` in the per-event common fields and [§ 4.2](#42-batch-envelope-fields) on the batch, with server-enforced equality between them; a batch without it is `400 malformed_body` — invalid input, not a legacy payload to guess at |
| [rule 2](../VERSIONING.md#the-rules) | `GET /api/ingest/health` reports that declaration ([§ 4.1](#41-endpoints)); **this doc names no accepted set**, deliberately ([§ 15](#15-decisions-taken-revisable-at-review)) |
| [rule 3](../VERSIONING.md#the-rules) | the server ignores unknown `data` keys at a known version and counts them in `ignored_unknown_fields` ([§ 12.7](#127-server-side-counters)); the reporter defaults absent optional fields to `null` ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)) |
| [rule 4](../VERSIONING.md#the-rules) | binding on every future edit of [§ 6](#6-event-kinds), and invoked twice by name in this document: adding a member to a **reporter-minted** enum ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)), and the `used_pct` fallback that would otherwise re-mean one field ([§ 6.11](#611-contextsample)) |
| [rule 5](../VERSIONING.md#the-rules) | the spool holds an event for at most 8 days ([§ 11.3](#113-rotation-and-the-overflow-policy)), and the window is what lets a reporter upgraded mid-spool drain its older lines cleanly ([§ 11.2](#112-spool-line-format)) |
| [rule 6](../VERSIONING.md#the-rules) | nothing in this document narrows an accepted set; a release that does states it |
| [rule 7](../VERSIONING.md#the-rules) | two mechanics implement it: [§ 12.1](#121-validation-order) step 10 (an unknown `kind` skips per-kind validation, is ignored, and is counted in `ignored_unknown_kinds`) and [§ 6.0](#60-conventions-and-how-harness-payloads-are-read) rule 4 (an unrecognised **harness-sourced** enum value is coerced to the field's unknown member and counted, at the reporter *and* again at the ingest). Both are counted per seat and render the seat `reporter_ahead` — informational, and never a batch rejection. **Reporter-minted enums are outside rule 7 by its own terms** and are governed by rule 4 instead ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)) |
| [§ the failure direction](../VERSIONING.md#the-failure-direction-must-be-safe--reject-loudly-never-drop-quietly) — an unknown or aged-out **version** is refused loudly | [§ 12.2](#122-error-responses) `400 unsupported_schema_version`, naming the received version and the accepted set in the body; counted against the **token's** binding ([§ 12.1](#121-validation-order)); the seat renders degraded; the reporter writes `REJECTED.txt` and quarantines |

Note the asymmetry the policy's rule 3 already flags, because the two neighbouring rows above look
like a contradiction: an unknown **field**, **kind** or **enum member** at a *known* version is
absorbed and counted, while an unknown **version** is refused outright. Absorbing the smaller
additions is what makes additive change possible at all; refusing the version is what stops a payload
nobody understands from being accepted as if it were understood.

**Why the additive rule is in the policy and not here.** An earlier draft of this section minted the
new-`kind` rule in D1 and appended a conditional pointer — *if review reads this as policy, it belongs
in `docs/VERSIONING.md`*. That is one rule with two homes and nothing binding them, which is the
drift shape this fleet keeps paying for. It is policy: it governs what any producer and any receiver
of this wire may do without a bump, and it applies to the enum case identically. So it was moved,
extended to cover enum members, and D1 keeps only the mechanics and the cite.

---

## 6. Event kinds

### 6.0 Conventions, and how harness payloads are read

**Type vocabulary.** `slug` = lowercase `[a-z0-9-]`; `ULID` = 26-char Crockford base32, 48-bit
timestamp + 80 random bits, lexicographically sortable by mint time, generated inline from
`crypto.randomBytes` (no dependency); `rfc3339_ms` = `YYYY-MM-DDTHH:MM:SS.sssZ`, UTC, always three
fractional digits; `enum` = a closed set given per field; all string bounds are **bytes** of UTF-8,
NFC-normalised; all integers fit in a JS safe integer.

**Missing vs null.** A missing key and an explicit `null` are the same thing. The server normalises
missing → `null` before validation. Producers should send `null` explicitly for legibility.

**One table classifies every enum field on this wire, and a tool re-derives its population.** The
population is every row typed `enum` or `array\<enum\>` in [§ 4.2](#42-batch-envelope-fields) and
[§ 6](#6-event-kinds) — **23 fields** — and `tools/design/verify-event-schema.py` re-derives that
population from the field tables on every run and fails if any row is missing here. The check is the
point, not a nicety: an earlier draft carried *two* hand-written lists asserted to partition this same
population, each claiming completeness, and between them they omitted five fields — which therefore
inherited neither of the two rules below. A partition asserted by prose decays silently; a partition a
tool re-derives cannot.

**Which side a field falls on decides its cross-version rule, and that is the only reason the
classification exists:**

- **Harness-sourced** — the value passes through from a harness payload, so the harness may add a
  member without telling anyone. Every such field carries an **unknown member**, an unrecognised value
  is coerced to it and counted (rule 4 below), and adding a member is free under
  [`docs/VERSIONING.md § Wire compatibility` rule 7](../VERSIONING.md#the-rules).
- **Reporter-minted** — the value comes from this reporter's own logic, so a value outside the set is a
  reporter *bug* rather than a harness change. These fields carry **no unknown member** (one stated
  exception below), and the paragraph after the table is what that costs.

| Wire enum field | Minted by | Unknown member | Value set owned by |
|---|---|---|---|
| `reporter_platform` | reporter — Node's `process.platform` | `other` | [§ 4.2](#42-batch-envelope-fields) |
| `session.start.source` | harness — `SessionStart.source` | `unknown` | [§ 6.1](#61-sessionstart) |
| `session.end.end_reason` | harness — `SessionEnd.reason`, plus the reporter-added `inferred_silence` | `other` | [§ 6.2](#62-sessionend) |
| `turn.end.end_reason` | reporter | — | [§ 6.4](#64-turnend) |
| `turn.end.api_error_type` | harness — `StopFailure.error` | `unrecognised` | [§ 6.4](#64-turnend) |
| `tool.start.agent_scope` | reporter | — | [§ 6.5](#65-toolstart) |
| `tool.end.outcome` | reporter | — | [§ 6.6](#66-toolend) |
| `tool.end.abort_reason` | reporter | — | [§ 6.6](#66-toolend) |
| `tool.end.duration_source` | reporter | — | [§ 6.6](#66-toolend) |
| `tool.end.close_source` *(the six tool-close values)* | reporter | — | [§ 6.6](#66-toolend) |
| `tool.end.match` | reporter | — | [§ 6.6](#66-toolend) |
| `subagent.stop.outcome` | reporter | — | [§ 6.6](#66-toolend), by reference — never restated |
| `subagent.stop.abort_reason` | reporter | — | [§ 6.6](#66-toolend), by reference |
| `subagent.stop.close_source` | reporter | — | [§ 6.6](#66-toolend), by reference |
| `compaction.start.trigger` | harness — `PreCompact.trigger` | `unknown` | [§ 6.9](#69-compactionstart) |
| `compaction.end.close_source` *(a **different** set of three)* | reporter | — | [§ 6.10](#610-compactionend) |
| `context.sample.used_pct_source` | reporter | — | [§ 6.11](#611-contextsample) |
| `context.sample.sample_reason` | reporter | — | [§ 6.11](#611-contextsample) |
| `attention.request.source` | reporter | — | [§ 6.12](#612-attentionrequest) |
| `attention.request.notification_kind` | reporter — a lookup on `notification_type` | — | [§ 6.12](#612-attentionrequest) |
| `attention.resolved.resolution` | reporter | — | [§ 6.13](#613-attentionresolved) |
| `attention.resolved.resolution_source` | reporter | — | [§ 6.13](#613-attentionresolved) |
| `reporter.heartbeat.degraded` *(`array\<enum\>`)* | reporter | — | [§ 9.3](#93-degradation-counters) |

Three of those rows are decisions a reader would otherwise re-open, so they are settled here:

- **`reporter_platform` is reporter-minted and still carries an unknown member** — the one exception,
  and it is not a hedge. Its source is Node's `process.platform`, an open set outside this document's
  control, so `other` is a genuine unknown case rather than a swallowed reporter bug. It is on the
  reporter side because the harness re-capture obligation below does not reach it: no hook payload
  carries it, and a harness upgrade cannot move it. An earlier draft listed it as harness-sourced,
  which would have obliged a re-capture that could never observe it.
- **`turn.end.api_error_type`'s unknown member is `unrecognised`, not `unknown`.** The harness's own
  `StopFailure.error` set already contains a literal `unknown` member, so coercing to `unknown` would
  make *"the API said `unknown`"* and *"we did not recognise what the API said"* the same wire value,
  and `enum_value_unknown.turn.end.api_error_type` — the counter that is supposed to announce the
  second — would be read against a field that cannot distinguish them. A distinct coercion member
  costs one enum value and keeps two different facts apart.
- **`attention.request.notification_kind` is reporter-minted**, because it is produced by a lookup
  table in [§ 6.12](#612-attentionrequest) and read from no payload. What is harness-sourced is the
  `notification_type` that lookup reads; its unrecognised case is carried by the counter
  `enum_value_unknown.notification_type` rather than by any wire value, because the unrecognised case
  emits no event at all ([§ 6.12](#612-attentionrequest)).

A value outside a **reporter-minted** set is a reporter bug, not a harness change, and the ingest
refuses it as `422 invalid_event`. That refusal is deliberate, and it carries a cost that has to be
paid out loud rather than discovered: because those fields have no unknown member,
[`docs/VERSIONING.md § Wire compatibility` rule 7](../VERSIONING.md#the-rules) **does not cover
them** — rule 7 says in terms that "a field that has none is not a closed enum for this purpose".
**So adding a member to any reporter-minted row above is a rule-4 change: a schema-version bump plus
a stated support window** — and that obligation now attaches to every reporter-minted row with no
unknown member, including the five fields an earlier draft's two lists left off between them.
Say it here because [§ 6.9](#69-compactionstart) treats exactly such an addition as routine
("the reap list gains a row — a mechanical change"), and a new reap row adds both an `abort_reason`
and a `close_source` member. Seats upgrade independently of the server
([`docs/VERSIONING.md § Deploy is not a tag`](../VERSIONING.md#deploy-is-not-a-tag--and-mezzanine-has-two-targets)),
so an upgraded reporter posting the new member to an un-upgraded ingest is the *steady state* of any
rollout: it would take `422`, [§ 12.4](#124-batches-are-atomic) would reject all 200 events in the
batch, and [§ 11.5](#115-retry-and-backoff)'s poison-pill rule would quarantine them permanently, per
batch, per seat, for the length of the skew. The alternative — giving these fields unknown members
too — was considered and rejected: a reporter-minted unknown really *is* a reporter bug and should be
loud. What must not happen is for it to be loud **and** undeclared.

#### Harness facts: three states, one measurement of record

**No harness fact appears in this document without a stated basis, and there are exactly three bases
it may have.** That rule exists because this document has already paid for its absence twice. The
first review found two hand-transcribed hook facts wrong. The fix corrected those two instances — and
built new designs on five more hand-transcribed facts, which the second review found wrong or absent.
Correcting instance N without binding the transcription to a source leaves instance N+1 to be minted
by the very next edit, which is what happened. **So the fix is the binding, not the corrections**: a
restatement of another product's schema with neither a pointer nor a guard is the defect, and this
section is the guard.

| State | What it means | What backs it |
|---|---|---|
| **MEASURED** | read out of a real payload captured from a running harness on an instrumented seat | a verbatim fixture in [§ 17](#17-appendix--the-captured-harness-payloads), plus the harness version it was captured at |
| **DOCS-CITED** | not drivable on the capture seat; read from the vendor reference, or from the installed harness binary's own payload schema | the URL or the binary, with the date it was read |
| **UNVERIFIED** | neither of the above | what it costs if it is wrong, and the named act that closes it |

**The measurement of record.** The fixtures in [§ 17](#17-appendix--the-captured-harness-payloads)
were captured on **2026-08-23** from **Claude Code 2.1.240** on Linux (`claude --version`), by wiring
every hook in the subscription table below to a command that appends its raw stdin to a capture file
and then driving real sessions headlessly (`claude -p`) down each path: startup, resume, `/clear`, a
prompt, a succeeding tool call, two genuinely failing tool calls, a subagent dispatch with its own
inner tool call, and `/compact`. **56 payloads across 10 hook events**, of which
[§ 17](#17-appendix--the-captured-harness-payloads) reproduces the **16** distinct payload shapes that
every MEASURED row below is read from. **Read the two numbers differently, because only one of them is
checkable.** The 16 and their 10 hooks are re-derived from the appendix by
`tools/design/verify-harness-facts.py` on every run and every key of every one is asserted against the
installed binary. The 56 is provenance for the capture *run*: the raw capture files are not committed
— they carry a real seat's session ids, working directories and prompt text — so no reviewer and no
tool can falsify a per-hook count taken over them, and this document therefore states its facts
against the reproduced 16 rather than against a sample size nobody can check. Committing a sanitized
capture set would make the 56 checkable too, and is the act that would close this. Two further sources are used
where a path could not be driven on that seat: the vendor hooks reference, and the installed
harness binary's own payload schema declarations, which name every hook's fields and every closed
matcher set verbatim and are therefore a *stronger* source than the reference page for key names.
Facts from those two are DOCS-CITED, never MEASURED, and say which.

**Every MEASURED fact in this document is versioned to 2.1.240 — a harness upgrade re-runs the
capture.** This is not procedural tidiness; it is the same lesson
[§ 3.4](#34-why-identity-never-comes-from-the-environment) is written about. That incident was a
predicate keyed to a harness marker whose *meaning* changed at 2.1.219, silently, and stayed silently
wrong for 30 days. A payload key that is renamed or dropped in 2.2.x fails exactly the same way —
[§ 6.0](#60-conventions-and-how-harness-payloads-are-read) rule 1 turns it into `null`, the signal it
fed reads zero forever, and nothing reds. The obligations, both binding:

1. **The reporter declares the harness version it was measured against**, and its `selftest`
   subcommand asserts the fixtures in [§ 17](#17-appendix--the-captured-harness-payloads) against the
   payload keys the reporter actually reads — see the `harness_payload_keys` MUST below. That is the
   guard, and it runs in CI and at install rather than only in review.
2. **A harness minor-version change re-runs the capture and re-marks this table**, and the diff is a
   change to this document. The capture rig is ~20 lines (a settings file wiring every hook to
   `cat >> file`, plus `claude -p` prompts); re-running it is minutes, which is why this is a
   requirement and not an aspiration.

> **`SELFTEST-MUST` — the drift guard survives into the code, or it does not exist.**
> `fleet-reporter.js selftest` MUST include a check named `harness_payload_keys` that, for **every**
> hook in the subscription table, loads that hook's fixture from
> [§ 17](#17-appendix--the-captured-harness-payloads) (vendored beside the reporter as
> `fixtures/hooks/<HookEventName>.json`) and asserts that **every payload key this reporter reads for
> that hook is present in the fixture**, and that every closed-enum value the reporter recognises for
> that hook's enum fields is a member of the set this document declares.
>
> **Every subscribed hook has a fixture, but five of them are stubs, and the difference is on the
> fixture.** Ten hooks have a real captured payload. The five that could not be driven on the capture
> seat — `StopFailure`, `Notification`, `PermissionRequest`, `PermissionDenied`, `PostCompact` — carry
> a **DOCS-CITED stub** instead: the exact key set the installed build's own payload schema declares,
> with placeholder values, labelled as a stub in [§ 17](#17-appendix--the-captured-harness-payloads)
> and carrying `"_source": "docs-cited-stub"` in the vendored file. A stub is a weaker fact than a
> capture and must never be mistaken for one — but it is a much stronger fact than a skip, because it
> still asserts the reporter's expectations against a stated contract, and it fails the same way when
> the reporter reaches for a key nothing declares. **A missing fixture is a `fail`, never a skip**; a
> stub is what makes that rule satisfiable for a hook nobody can drive. Replacing a stub with a real
> capture is the closure act named in
> [§ 6.0](#60-conventions-and-how-harness-payloads-are-read)'s table for each of those five rows. It reports
> `"pass"`/`"fail"` in [§ 6.14](#614-reporterheartbeat)'s `selftest` object, so a seat running against
> a harness whose payload has moved is visible on the floor rather than silently emitting nulls.
> **The check must be seen to fail** ([AT-21](#at-21-the-harness-fact-drift-guard)): rename one key in
> one fixture and it goes RED for that hook and no other. A guard that has never failed is a
> decoration — and a `payload_key_missing.<key>` counter is *not* this guard, because it only speaks
> after the seat is already deployed and already wrong.

**The facts this design rests on.** One row per fact, carrying the **verbatim key name** and its
state. A row's key name is what an implementer types; where a MEASURED row disagrees with the vendor
reference, the measurement wins and the row says so.

| Fact this design rests on | Verbatim key | State |
|---|---|---|
| `session_id`, `transcript_path`, `cwd` on every hook payload | `session_id` | **MEASURED** — present on every one of the **16** payloads [§ 17](#17-appendix--the-captured-harness-payloads) reproduces, and on every payload of the capture run behind them |
| `hook_event_name` on every hook payload | `hook_event_name` | **MEASURED** — present on all **16** reproduced payloads, and matched `argv[2]` on every payload of the capture run |
| `prompt_id` — one UUID correlating a prompt with every event until the next prompt | `prompt_id` | **MEASURED** — present on `UserPromptSubmit`, `PreToolUse`, `PostToolUse`, `PostToolUseFailure`, `SubagentStart`, `SubagentStop`, `Stop`, `SessionEnd`, `PreCompact`; **absent on `SessionStart`** and absent before the first prompt of a process. This is the turn key [§ 6.4](#64-turnend) uses |
| `permission_mode` on the tool- and turn-scoped hooks | `permission_mode` | **MEASURED** — e.g. `"acceptEdits"`; read as a label only, never gated on |
| `tool_input.*` (`.command`, `.file_path`, `.pattern`, `.url`, `.query`, `.description`) | `tool_input` | **MEASURED for `.command`/`.description` (Bash) and `.file_path` (Read/Write)**; `.pattern`/`.url`/`.query` **DOCS-CITED** — the capture seat drove no `Grep`/`Glob`/`WebFetch`/`WebSearch` call and the reference enumerates `tool_input` only for `Bash`. Cost if wrong: those four tools' descriptors are `null` and `payload_key_missing.tool_input.<key>` counts it — a label lost, never an event lost. Closed by one capture per tool |
| `tool_use_id` present on `PreToolUse`, `PostToolUse` **and** `PostToolUseFailure` | `tool_use_id` | **MEASURED** — identical value across the open/close pair on every pair [§ 17](#17-appendix--the-captured-harness-payloads) reproduces, and on every pair of the capture run, so `harness_call_ref` should be present on ~100 % of closes ([§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)) |
| `PostToolUse` fires on a tool call that **succeeded**; a failed one fires **`PostToolUseFailure`** instead | — | **MEASURED** — `Bash: exit 3` and `Read` of a missing path both fired `PostToolUseFailure` and no `PostToolUse`. Note the boundary, which is not obvious: a Bash command that *runs* and exits non-zero **inside** a compound command (`false; echo $?`) exits 0 overall and fires `PostToolUse`. The discriminator is the tool call's own success, not the shell's |
| `PostToolUseFailure` carries `error` (string) and `is_interrupt` (bool) | `error`, `is_interrupt` | **MEASURED** — `{"error":"Exit code 3","is_interrupt":false}`. `is_interrupt` is the harness's own kill-vs-fail discriminator and [§ 6.6](#66-toolend) uses it |
| `PostToolUse` / `PostToolUseFailure` carry the harness's own `duration_ms` | `duration_ms` | **MEASURED** — 251 ms, 260 ms, 18 ms. Documented as excluding permission-prompt and hook time; [§ 6.6](#66-toolend) prefers it to the reporter's own clock difference |
| `SessionEnd` exists, with `reason` ∈ `clear` \| `resume` \| `logout` \| `prompt_input_exit` \| `other` | **`reason`** | **MEASURED** — `"reason":"clear"` and `"reason":"other"` captured. The key is `reason`. The full value set is **DOCS-CITED** from the installed binary's own enum declaration, read 2026-08-23 |
| `SessionStart.source` ∈ `startup` \| `resume` \| `clear` \| `compact` \| `fork` | **`source`** | **MEASURED** — `startup`, `resume` and `clear` captured verbatim; the key is `source`. `compact` and `fork` are **DOCS-CITED** from the binary's enum declaration. *A review round asserted this key is `session_start_reason`; that string does not occur anywhere in the installed 2.1.240 binary, and the three captures carry `source`. The measurement wins* |
| `SessionStart` carries **no** predecessor-session key | `previous_session_id` — **does not exist** | **MEASURED** — the `source == "clear"` capture's complete key set is `{cwd, hook_event_name, session_id, source, transcript_path}`. The binary contains `previous_session_id` only in an internal analytics event, never in a hook payload. [§ 6.1](#61-sessionstart) and [§ 8.4](#84-detecting-a-clear-with-two-independent-signals) are designed against this, not around it |
| A `/clear` fires **both** `SessionEnd(reason=clear)` on the outgoing session **and** `SessionStart(source=clear)` under a **new** `session_id` | — | **MEASURED** — `SessionEnd(clear)` on `d867abf5…` at `T`, `SessionStart(clear)` on `d8f4ac95…` at `T+144 ms`. `SessionEnd` first, by 144 ms, in the one ordering captured |
| A `resume` **keeps** the same `session_id`; a `/clear` **changes** it | — | **MEASURED** — resume fired `SessionStart(source=resume)` under the identical id |
| `PreCompact.trigger` ∈ `manual` \| `auto`; `PostCompact` exists with the same key | **`trigger`** | `trigger` and `"manual"` **MEASURED**; `"auto"` and `PostCompact` **DOCS-CITED** (binary enum declaration, 2026-08-23) — neither is drivable on a scratch session. `PreCompact` also carries `custom_instructions` (nullable), which **never transits** ([§ 6.9](#69-compactionstart)) |
| `PostCompact` carries `compact_summary` — the whole conversation summary | `compact_summary` | **DOCS-CITED** (binary payload schema, 2026-08-23). It is model-authored prose about the session and is **never read and never transits** ([§ 6.10](#610-compactionend)) — naming it here is what stops a later editor treating it as a free descriptor source |
| `agent_id` and `agent_type` are common fields **present only inside a subagent**; `SubagentStart` exists | `agent_id`, `agent_type` | **MEASURED** — the subagent's own `PreToolUse`/`PostToolUse` carried `agent_id`; the main agent's did not. `agent_id` is a 17-hex-character opaque string on this build (**not** the `subagent_xyz789` shape the reference example shows) — [§ 3.2](#32-session-identity)'s opacity rule is why that costs nothing |
| `SubagentStop` carries `agent_id` **and** `agent_type` | `agent_id` | **MEASURED** — settles what an earlier draft parked as unverified. It also carries `agent_transcript_path`, `last_assistant_message`, `stop_hook_active`, `background_tasks`, `session_crons`, and **no error indicator of any kind** — which is the stated reason [§ 6.6](#66-toolend) reports `completed` |
| `SubagentStart` / `SubagentStop` carry **no** reference to the parent tool call | `tool_use_id` / `parent_tool_use_id` — **do not exist** | **MEASURED** — `SubagentStart`'s complete key set is `{session_id, transcript_path, cwd, prompt_id, agent_id, agent_type, hook_event_name}`. [§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call) is built on that, and no longer carries a binding rule that cannot execute |
| **`Stop` does not fire inside a subagent** — a subagent's completion fires `SubagentStop` only | — | **MEASURED** — a turn that dispatched one subagent produced exactly one `Stop`, after the subagent's `SubagentStop` and after the dispatching call's `PostToolUse`. This was the document's highest-cost unverified fact: if `Stop` *had* fired per subagent, [§ 8.3](#83-the-reap-rules)'s reap would have aborted the parent's own in-flight calls and [§ 6.4](#64-turnend) would have minted a false idle per subagent. [§ 8.3](#83-the-reap-rules) is still `agent_id`-scoped, because the scoping is free and fails safe if this ever changes |
| The subagent-dispatch tool's `tool_name` is **`"Agent"`** on this build | `tool_name` | **MEASURED** — `{"tool_name":"Agent","tool_input":{"description":…,"prompt":…}}`. The model-facing name is `Task`; the *hook payload* says `Agent`. [§ 6.7](#67-subagentspawn) matches the set `{"Agent","Task"}` and counts which fired, because a design keyed on `"Task"` alone would emit no `subagent.spawn` on this build at all |
| `Stop` carries `stop_hook_active` (bool), `last_assistant_message`, `background_tasks`, `session_crons` | `stop_hook_active` | **MEASURED** — `false` on the reproduced `Stop` capture and on every `Stop` of the capture run; settles what an earlier draft parked as unverified. `background_tasks` is **DOCS-CITED** as distinguishing "session is done" from "session is paused waiting on background work" — [§ 6.4](#64-turnend) reads it |
| **`StopFailure`** fires instead of `Stop` when a turn ends on an API error, with `error` ∈ `rate_limit` \| `overloaded` \| `server_error` \| `authentication_failed` \| `billing_error` \| `invalid_request` \| `model_not_found` \| `max_output_tokens` \| `oauth_org_not_allowed` \| `account_on_hold` \| `unknown` | `error`, `error_details` | **DOCS-CITED** (binary enum declaration + reference, 2026-08-23). **Not drivable** — driving it means provoking a real rate-limit or outage. Cost if the shape is wrong: `turn.end` is still emitted from the reap path with `end_reason: "api_error"` and a null `api_error_type`, so *stalled* stays reachable and only the sub-classification is lost. Closed by the first real rate-limited turn on an instrumented seat, which `enum_value_unknown.turn.end.api_error_type` will announce. The eleven values are the harness's; [§ 6.4](#64-turnend) adds a twelfth, `unrecognised`, as the coercion target, because this set's own `unknown` is a real harness member and the two must not collide ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)) |
| `Notification` carries `notification_type` (string), `message` (string) and `title` (optional) | `notification_type` | **DOCS-CITED** (binary payload schema, 2026-08-23) — **not drivable headlessly**; a notification needs an interactive surface. Note two things the schema settles: a `message` field **does** exist, and `notification_type` is typed as a plain **string**, not a closed enum — so the wire contract is an **open set**: [§ 6.12](#612-attentionrequest) emits nothing for a type it does not recognise and counts it as `enum_value_unknown.notification_type`, rather than coercing it onto a wire value, because the suppression happens before rule 4 could reach it. There is **no** `notification_metadata` object on this build, so a notification cannot name a tool call |
| The `notification_type` value set this build declares — the **16** members of the `Notification` hook's own matcher metadata; the set itself is owned by [§ 6.12](#612-attentionrequest)'s lookup table and is not restated here | `notification_type` | **DOCS-CITED** (the binary's `Notification` matcher declaration, read 2026-08-23, and re-read on every run of `tools/design/verify-harness-facts.py` — see the enum-source table below). Treated as an **open** set on the wire, because the payload types the field as a plain string: [§ 6.12](#612-attentionrequest) classifies the attention-bearing members and coerces everything else, so a value the declared set misses costs one label, never an event. *An earlier draft transcribed **14** values here, omitting `elicitation_dialog` and `elicitation_url_dialog` — the two the `elicitation` branch depends on — and no guard could see it, because the guard bound key names and not value sets* |
| `PermissionRequest` fires when a tool call needs approval, carrying `tool_name`, `tool_input` and `permission_suggestions` — **and no `tool_use_id`** | `tool_name` | **DOCS-CITED** (binary payload schema, 2026-08-23) — **not drivable headlessly** (`-p` cannot show a prompt). The missing `tool_use_id` is why [§ 6.12](#612-attentionrequest)'s `call_id` is a sole-open heuristic rather than an exact key; that is a measured absence, not an oversight |
| `PermissionDenied` fires when **auto mode** denies a tool call, carrying `tool_name`, `tool_input`, `tool_use_id` and `reason` | `tool_use_id`, `reason` | **DOCS-CITED** (reference lifecycle table + binary payload schema + the binary's **call site**, which invokes it only under `decisionReason.classifier === "auto-mode"`, read 2026-08-23). The **auto-mode scoping is the load-bearing part**: a *human* clicking "no" on an interactive prompt does **not** fire it, so [§ 6.13](#613-attentionresolved) carries the interactive path as its own row rather than pretending `denied` covers it |
| A tool call **refused by the permission layer** fires `PreToolUse` and then **no close hook at all** | — | **MEASURED** — a `Write` blocked under `--permission-mode default` produced `PreToolUse` → `Stop`, with no `PostToolUse`, no `PostToolUseFailure`, no `PermissionDenied` and no `Notification` on this headless seat. The ledger entry therefore survives to its scope's turn reap ([§ 8.3](#83-the-reap-rules)) — `Stop` in the main agent, `SubagentStop` inside a subagent, where `Stop` does not fire — and closes as `aborted`/`turn_boundary`. Correct, and [§ 6.6](#66-toolend) names it so an implementer does not read it as a lost close |
| statusLine payload: `context_window.{used_percentage, total_input_tokens, total_output_tokens, context_window_size, current_usage, remaining_percentage}` | `context_window` | **DOCS-CITED** (binary, 2026-08-23) — **not drivable headlessly**: `claude -p` renders no status line, so no statusLine payload was captured. The binary's own builder computes `total_input_tokens = input_tokens + cache_creation_input_tokens + cache_read_input_tokens` and `used_percentage = round(that / context_window_size × 100)`, clamped to 0…100 — **input-only, output tokens excluded**, which is what makes [§ 6.11](#611-contextsample)'s two branches mean the same thing. `used_percentage` is `null` while `current_usage` is null (early session, and after a `/compact` until the next API call) |
| statusLine is **event-driven** with a ~300 ms debounce; an in-flight status-line script is **cancelled** when a new trigger arrives; a timed re-render happens only under `refreshInterval` | — | **DOCS-CITED** (reference, 2026-08-23) — why [§ 6.11](#611-contextsample) states a ceiling rather than a reduction ratio, and why statusLine-side counters are a floor ([§ 9.3](#93-degradation-counters)) |
| hook exit codes: **2 blocks** the operation and feeds stderr to the model; any other non-zero is a non-blocking error; `SessionStart` and `UserPromptSubmit` stdout is added to the model's context | — | **DOCS-CITED** (reference, 2026-08-23) — the mechanism behind P-1 and P-2 ([§ 2.2](#22-rules-that-protect-the-seat)). The per-event sections add that several hooks ignore exit codes and JSON output entirely; P-1 and P-2 are unaffected because they only ever require exit 0 and silence |
| `UserPromptSubmit.source` ∈ `user` \| `sdk` \| `system` \| `loop_wakeup` \| `schedule_wakeup` \| `poll_event` | `source` | **DOCS-CITED** (binary payload schema, 2026-08-23). **Deliberately not read**: [§ 6.3](#63-turnstart) does not branch on who authored a prompt, and reading it would add a harness-sourced enum with no consumer. Recorded so a future editor knows it exists |
| The harness offers further hook events this design does **not** subscribe: `PostToolBatch`, `Setup`, `UserPromptExpansion`, `TeammateIdle`, `TaskCreated`, `TaskCompleted`, `Elicitation`, `ElicitationResult`, `ConfigChange`, `InstructionsLoaded`, `WorktreeCreate`, `WorktreeRemove`, `FileChanged`, `DirectoryAdded`, `MessageDisplay`, `CwdChanged` | — | **DOCS-CITED** — all 31 hook events the installed build declares, read from the binary 2026-08-23, minus the 15 subscribed above. Listed because "we did not subscribe it" and "we did not know it existed" are different states, and only the first is a decision |
| the `Bash` tool's 10-minute timeout ceiling | — | **UNVERIFIED** against the installed build — the 15-minute orphan timeout ([§ 12.5](#125-late-completions-and-orphan-timeouts)) is derived from it and moves with it. Cost if wrong: a long `Bash` call is orphan-closed server-side before it returns, then re-opened by its late close. Closed by reading the installed build's Bash timeout |
| nginx `client_max_body_size` on the actual deploy host | — | **UNVERIFIED** — the host is not provisioned yet (`docs/PLAN.md` D-08). Cost if wrong: a `413` on every batch over the real limit, retried once at half size ([§ 11.5](#115-retry-and-backoff)), so a tighter limit degrades throughput rather than losing events. Read it at first deploy ([§ 4.4](#44-size-caps-and-their-derivations)) |
| `tool_response`'s per-tool schema | — | **UNVERIFIED**, and **not needed**: which hook closed the call carries the error fact ([§ 6.6](#66-toolend)) and `PostToolUseFailure.error` carries the detail. Nothing to close |

#### Harness enum value sets are bound to the binary, not transcribed

The table above binds **key names** to the installed build. It did not bind **value sets**, and that
one rung is where this document's next defect lived: a `notification_type` row transcribed with 14 of
the build's 16 declared members, omitting exactly the two that [§ 6.12](#612-attentionrequest)'s
`elicitation` branch rests on, surviving three review rounds because every guard in the design checked
that a *key* still existed. Correcting the row would have been instance N+1 of the shape
[decision 27](#15-decisions-taken-revisable-at-review) already names. So the binding is extended one
rung down instead:

**Every harness-sourced enum's value set is re-derived from the installed binary on every run of
`tools/design/verify-harness-facts.py`, and every place this document states that set is asserted
against it.** The table below is that check's input — not a copy of the sets, which would be the
defect again, but a map from each set to the binary declaration it is read from and the places this
document states it. Adding a harness-sourced enum without a row here fails
`tools/design/verify-event-schema.py`, because that tool re-derives the classification table's
population independently.

| Harness enum set | Binary declaration | This document states it at | Members this reporter adds |
|---|---|---|---|
| `SessionStart.source` | `SessionStart.source` | § 6.0 › `SessionStart.source` | — |
| `SessionStart.source` | `SessionStart.source` | § 6.1 › `source` | `unknown` |
| `SessionEnd.reason` | `SessionEnd.reason` | § 6.0 › `SessionEnd` | — |
| `SessionEnd.reason` | `SessionEnd.reason` | § 6.2 › `end_reason` | `inferred_silence` |
| `PreCompact.trigger` | `PreCompact.trigger` | § 6.0 › `PreCompact.trigger` | — |
| `PreCompact.trigger` | `PreCompact.trigger` | § 6.9 › `trigger` | `unknown` |
| `StopFailure.error` | `StopFailure.error` | § 6.0 › `StopFailure` | — |
| `StopFailure.error` | `StopFailure.error` | § 6.4 › `api_error_type` | `unrecognised` |
| `Notification.notification_type` | `Notification.notification_type` | § 6.12 › table `notification_type` | — |

**Why four of those sets are stated twice and one is stated once.** A set stated in two places is a
restatement free to drift, and the ordinary fix is to delete one copy and point at the other. That is
what was done for `notification_type`: its members carry no per-member provenance, so the fact row
above now names the *count and the source* and points at [§ 6.12](#612-attentionrequest), which owns
the set because the lookup needs it member by member. The other four cannot be collapsed that way —
their [§ 6.0](#60-conventions-and-how-harness-payloads-are-read) rows carry a **per-member state**
(which members were MEASURED in a capture and which are DOCS-CITED from the binary), which the field
tables do not and should not carry. Deleting either copy would delete a fact. So they keep both
copies **and a guard**, which is the case where a guard is the right instrument rather than the lazy
one: the two copies plus the binary must agree on every run, and a hand-edit to either reds.

**What the check reaches, stated so nobody over-reads it.** It asserts that the *declared* value set
in this build equals the set this document states. It does not assert that every declared member is
ever *emitted* — a matcher set is what the build will accept and route, and a member with no live emit
site would still pass. That distinction is why the `notification_type` row above states the declared
set rather than an emit-site count: the count was the part that could not be checked, and it is the
part that was wrong.

**The hook set this design subscribes to.** Everything else the harness offers is deliberately not
wired: an unsubscribed hook costs nothing, a subscribed one costs latency on the seat.

| Hook | What the reporter does with it | Events |
|---|---|---|
| `SessionStart` | when `source == "clear"`, reap the seat's other live session ([§ 8.4](#84-detecting-a-clear-with-two-independent-signals)) | `session.start` |
| `SessionEnd` | reap that session's open calls | `tool.end`(s), `turn.end` if open, `session.end` |
| `UserPromptSubmit` | resolve an open attention request | `turn.start`, maybe `attention.resolved` |
| `Stop` | reap that session's open calls | `tool.end`(s), `turn.end` |
| `StopFailure` | reap that session's open calls — a turn that ended on an API error | `tool.end`(s), `turn.end` (`api_error`) |
| `PreToolUse` | open a ledger entry | `tool.start`, plus `subagent.spawn` when `tool_name ∈ {"Agent","Task"}` |
| `PostToolUse` | close it as succeeded | `tool.end` (`completed`), maybe `subagent.stop`, maybe `attention.resolved` |
| `PostToolUseFailure` | close it as failed, or as aborted when `is_interrupt` | `tool.end` (`failed` \| `aborted`), maybe `subagent.stop`, maybe `attention.resolved` |
| `SubagentStart` | bind the subagent's `agent_id` to the open dispatch call as its `child_agent_id` | *(none — a binding, not an event)* |
| `SubagentStop` | reap that subagent's own open calls ([§ 8.3](#83-the-reap-rules)), then close the bound dispatch call if it is still open | `tool.end`(s), `subagent.stop` |
| `PreCompact` | — | `compaction.start` |
| `PostCompact` | — | `compaction.end` |
| `PermissionRequest` | open an attention request, unambiguously | `attention.request` |
| `PermissionDenied` | close it | `attention.resolved` (`denied`) |
| `Notification` | open an attention request **when its type is an attention type**; otherwise count and emit nothing ([§ 6.12](#612-attentionrequest)) | `attention.request`, or none |
| statusLine *(integration, not a hook)* | sample context; write the seat's last-sample state | `context.sample`, sampled |

**Reading the harness payload — defensively, always.** The binding rules:

1. A missing or unexpected key yields `null` in the event and increments
   `payload_key_missing.<key>` ([§ 9.3](#93-degradation-counters)). **It never suppresses the event.**
2. **No branch of any payload read decides *whether* to emit — only *what to label*.** One hook is
   carved out of this rule, explicitly, and it is the only one: `Notification` fires for events that
   are not requests for human attention at all (`auth_success`, `agent_completed`, the
   `quota_auto_resume_*` family), and emitting an `attention.request` for those would put every seat
   into a false *blocked* — the exact mirror of the false-idle defect this document exists to
   prevent. [§ 6.12](#612-attentionrequest) gates that hook on its `notification_type` and **counts
   every suppressed type individually** as `notification_not_attention.<type>`, which is what
   [§ 3.4](#34-why-identity-never-comes-from-the-environment) actually requires: not that nothing is
   ever suppressed, but that no suppression is ever silent. The distinction the rule turns on is
   whether the payload read is a *classification* (label it, never gate it) or a *subscription
   filter* (a hook that legitimately fires for reasons outside this design's subject). Adding a
   second carve-out is a review-blocking change.
3. The hook name arrives twice (`argv[2]` and `hook_event_name`). The reporter uses `argv[2]`, and a
   disagreement increments `hook_name_mismatch`. This is a free discriminating check on the assumption
   that the payload's own labelling is what we think it is. *(It agreed on every payload of the capture
   run, so the counter's healthy value is 0 and any non-zero is real.)*
4. **An unrecognised value in a closed-enum field is coerced to that field's unknown member and
   counted as `enum_value_unknown.<wire field>`. The raw value never reaches the wire.**
   **The counter-name grammar, stated once because three different spellings of it appeared in an
   earlier draft:** every open-ended counter family is `<family>.<wire field>`, and `<wire field>` is
   the field's **full dotted name** — the `kind` and the `data` key, exactly as
   [§ 6](#6-event-kinds)'s tables and the enum classification table above name it. So it is
   `enum_value_unknown.session.start.source` and `value_clamped.turn.end.aborted_call_ids`, never a
   kind spelled with an underscore and never a bare field name. **One exception exists and it is the
   only one:** `enum_value_unknown.notification_type` names a *harness payload key* rather than a wire
   field, because the unrecognised case there suppresses the event entirely
   ([§ 6.12](#612-attentionrequest)) so no wire field ever carries the value. A second such counter
   would be a review-blocking change, for the same reason a second carve-out to rule 2 is: an
   exception nobody can enumerate is a grammar nobody can implement. The same grammar governs
   `payload_key_missing.<key>` — which names a **payload key**, since that is its subject — and
   `value_clamped.<wire field>`, `data_truncated.<wire field>`,
   `notification_not_attention.<type>` and `dispatch_tool_name.<name>`, whose subjects their own rows
   in [§ 9.3](#93-degradation-counters) name. This is not
   tidiness. The enums in this document are exactly what the ingest validates, so one new harness
   value passed through verbatim makes its event invalid, [§ 12.4](#124-batches-are-atomic) rejects
   all 200 events in its batch, and [§ 11.5](#115-retry-and-backoff)'s poison-pill rule quarantines
   them permanently — an unannounced harness change would delete a seat's telemetry rather than
   mislabel one field. The coercion happens at the **reporter**; the ingest applies the same rule
   again on receipt ([§ 12.1](#121-validation-order) step 10) so a *newer* reporter's added member
   cannot poison an *older* server either. Both ends implement one policy rule:
   [`docs/VERSIONING.md § Wire compatibility`](../VERSIONING.md#the-rules) rule 7.
5. **A numeric value outside its stated bound is clamped to the nearer bound, emitted, and counted
   `value_clamped.<wire field>`; an object over its stated serialized cap is reduced by the
   deterministic rule its own field table states, and counted `data_truncated.<wire field>`
   (the grammar is rule 4's).** This rule exists because
   [§ 12.1](#121-validation-order) step 9 rejects an out-of-bounds integer or an over-cap `data` as
   `422 invalid_event`, [§ 12.4](#124-batches-are-atomic) then rejects all 200 events in the batch,
   and [§ 11.5](#115-retry-and-backoff) quarantines them permanently — so without a producer-side
   rule, *any* bound overrun is an unrecoverable loss of 200 good events. Every bound in
   [§ 6](#6-event-kinds) is therefore a clamp at the reporter and a validation at the ingest, and the
   two agree by construction. The one object with a cap that a real seat can actually reach is
   [§ 6.14](#614-reporterheartbeat)'s `counters`, and its reduction rule is stated there with its own
   `counters_omitted` field. A clamp is a mislabelled field; a rejection is 200 deleted events.
6. An unknown `kind` is treated the same way and for the same reason — accepted, ignored, counted,
   never a rejection ([§ 5](#5-compatibility--what-this-document-owes-the-policy)).

**The kind table. This table owns the volume estimate** — [§ 14](#14-every-number-and-where-it-comes-from)
cites its sum instead of carrying a second figure that could drift from it.

| Kind | Trigger | Emitted by | Typical volume, busy seat-day |
|---|---|---|---|
| `session.start` | `SessionStart` hook | hook | 5–40 |
| `session.end` | `SessionEnd` hook; or the flusher after 90 min of session silence | hook / flusher | 5–40 |
| `turn.start` | `UserPromptSubmit` hook | hook | 200–600 |
| `turn.end` | `Stop` hook, or a session boundary with a turn open | hook | 200–600 |
| `tool.start` | `PreToolUse` hook | hook | 1,000–3,000 |
| `tool.end` | `PostToolUse`, `PostToolUseFailure`, or a reap ([§ 8.3](#83-the-reap-rules)) | hook | 1,000–3,000 |
| `subagent.spawn` | `PreToolUse` where `tool_name ∈ {"Agent","Task"}` ([§ 6.7](#67-subagentspawn)) | hook | 5–60 |
| `subagent.stop` | the Task call's close, from any close source | hook | 5–60 |
| `compaction.start` | `PreCompact` hook | hook | 2–20 |
| `compaction.end` | `PostCompact` hook, or `SessionStart(source=compact)` | hook | 2–20 |
| `context.sample` | statusLine render, **sampled** | statusline | ≤ 1,440 |
| `attention.request` | `PermissionRequest` hook, or `Notification` hook | hook | 0–50 |
| `attention.resolved` | the request's exit edge ([§ 6.13](#613-attentionresolved)) | hook / flusher | 0–50 |
| `reporter.heartbeat` | flusher timer, every 60 s | flusher | 1,440 |

Volumes are **estimates for sizing, not measurements** — no seat has been instrumented yet. The
ceiling column sums to **10,420 events/seat/day**, which at the ~500 B typical event size is
**~5.2 MB/day**. That pair is the only volume figure this document uses: it feeds the spool bound,
the residency cap and the rate limits, and the first week of live data re-derives it. The same
column's midpoint is ~7,100 events/day (~3.6 MB/day); sizing is done at the ceiling, and the tightest
bound derived from it — the spool's 6.4-day residency ([§ 11.3](#113-rotation-and-the-overflow-policy))
— still satisfies the "survives the server being down for days" requirement with room, while the rate
limits sit 46× above it.

### 6.1 `session.start`

**Trigger:** the `SessionStart` hook, unconditionally. The payload key is **`source`** and the value
set is MEASURED at 2.1.240 ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)). When
`source == "clear"` the hook runs the second `/clear` reap
([§ 8.4](#84-detecting-a-clear-with-two-independent-signals)) before emitting, so any calls the clear
killed are already closed as aborted and appear *earlier* in the spool. A `/clear` also fires
`SessionEnd(reason: "clear")` on the **outgoing** session, which reaps the same set; both reap, the
reap is idempotent, and whichever runs second finds nothing open and increments
`reap_noop_second_signal` — the counter that says both signals are alive.

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `source` | enum | — | no | `startup` \| `resume` \| `clear` \| `compact` \| `fork` \| `unknown` | `"clear"` |
| `project_label` | string | — | yes | ≤ 48 B, sanitized basename of cwd | `"mezzanine"` |
| `harness_label` | string | — | yes | ≤ 32 B, `^[A-Za-z0-9._-]+$` | `"claude-code/2.1.240"` |
| `previous_session_id` | string | — | **yes** | ≤ 128 B; **reporter-derived, not harness-supplied** — see below | `"e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913"` |

`source` is `unknown` when the payload key is absent **or carries a value this reporter does not
know** ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read) rule 4) — never silently
`startup`, because `startup`-vs-`clear` is load-bearing for
[§ 8](#8-call-lifecycle--the-kill-vs-complete-contract) and a wrong-but-plausible default would hide
exactly the case this design exists to catch. `fork` is in the set because the harness declares it;
a fork is not a kill, so it reaps nothing.

**`harness_label` is the version pin, on the wire.** Every MEASURED fact in
[§ 6.0](#60-conventions-and-how-harness-payloads-are-read) is versioned to the harness build the
capture was taken from, so the fleet needs to be able to *see* when a seat has moved off it. This
field carries `claude-code/<version>` from the harness the reporter is running under, which lets an
operator answer "which seats are on a build this document has never been measured against" from the
stream instead of from a survey.

**`previous_session_id` names the session this reporter just reaped — it is not a payload field.**
The `SessionStart` payload carries **no** predecessor-session key of any kind: the captured
`source == "clear"` payload's complete key set is
`{cwd, hook_event_name, session_id, source, transcript_path}`
([§ 6.0](#60-conventions-and-how-harness-payloads-are-read), MEASURED). An earlier draft read one
from the payload, which would have made this field `null` forever and the `/clear` reap keyed on it
structurally dead. The reporter does not need the harness for this, because it already holds the
seat's live-session set in its own call index: `previous_session_id` is the session
[§ 8.4](#84-detecting-a-clear-with-two-independent-signals)'s rule selected and reaped, or `null`
when that rule selected none. It is therefore a **reporter-derived** value, subject to
[§ 6.0](#60-conventions-and-how-harness-payloads-are-read)'s reporter-minted discipline rather than
to the payload-reading rules.

```json
{ "event_id":"01K3TA1B2C3D4E5F6G7H8J9K0M","schema_version":1,"kind":"session.start",
  "event_time":"2026-08-23T14:22:40.201Z","seq":48310,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"source":"clear","project_label":"mezzanine","harness_label":"claude-code/2.1.240",
          "previous_session_id":"e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913"} }
```

### 6.2 `session.end`

**Trigger:** the `SessionEnd` hook — an **observation**, not an inference. The payload key is
**`reason`** (MEASURED at 2.1.240, [§ 6.0](#60-conventions-and-how-harness-payloads-are-read));
`end_reason` is that value passed through, coerced to `other` if it is one this reporter does not
know ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read) rule 4).

Before emitting, the hook reaps every call still open **in that session** and emits a `turn.end` if a
turn was open, so the spool order at a session boundary is: aborted `tool.end`s (and their
`subagent.stop`s), then `turn.end`, then `session.end`.

| `end_reason` | Where it comes from |
|---|---|
| `clear` | `SessionEnd(reason: "clear")` — the `/clear` path, the one [§ 8](#8-call-lifecycle--the-kill-vs-complete-contract) is about |
| `resume` | `SessionEnd(reason: "resume")` |
| `logout` | `SessionEnd(reason: "logout")` |
| `prompt_input_exit` | `SessionEnd(reason: "prompt_input_exit")` |
| `other` | any other `reason`, including one this reporter does not recognise. **This is a common value, not a residue**: a non-interactive (`claude -p`) session ends with `reason: "other"` — MEASURED, and the majority of the capture run's `SessionEnd`s, one of which [§ 17](#17-appendix--the-captured-harness-payloads) reproduces beside the `clear` one. A consumer must not read `other` as a degradation signal |
| `inferred_silence` | the one **inferred** member: the flusher has seen no event for that session for 90 min |

| `data` field | Type | Null? | Bounds | Example |
|---|---|---|---|---|
| `end_reason` | enum | no | `clear` \| `resume` \| `logout` \| `prompt_input_exit` \| `other` \| `inferred_silence` | `"clear"` |
| `duration_ms` | int | yes | ≥ 0; `null` if the start was not observed | `938204` |
| `turns` | int | yes | ≥ 0, the reporter's count for this session | `14` |
| `aborted_calls` | int | no | ≥ 0, calls reaped as aborted at this boundary | `1` |

**Why the inferred member is 90 minutes and not six hours.** A hard reboot, a power cut or a
`SIGKILL`ed harness produces no `SessionEnd`, so silence must still close a session eventually. What
changed is the cost of being early. When `session.end` was the *only* signal, closing early deleted a
desk that was merely thinking, so the number had to be generous enough to cover an operator's lunch.
Now the desk's liveness is the heartbeat's job
([§ 9](#9-liveness-heartbeat-staleness-and-the-predicate-alarm)), this event closes a *session row*
rather than a seat, and an early close is **reversible**: an event arriving for a session already
closed by `inferred_silence` re-opens it server-side and counts `session_reopened`. So the number is
derived from the longest legitimate silence *inside a live session* — a session with an open `Task`
call can legitimately emit nothing until that call's 60-minute orphan ceiling
([§ 12.5](#125-late-completions-and-orphan-timeouts)) — and 90 min is 1.5× that. `session_reopened`
is the observable that re-derives it from real data: a non-zero count means 90 min is too tight.

**What is deliberately gone: the `superseded` inference.** An earlier draft minted `session.end`
whenever a hook arrived carrying a `session_id` different from the index's current one. Two terminals
open on one seat is an ordinary state — `open_sessions` is bounded at 16 for exactly that reason —
and under that rule each session's hooks would have declared the other one ended, aborting healthy
calls in both, making [`D2-MUST` #1](#64-turnend)'s *idle* unreachable on both, and minting a
`session.end` storm. Sessions are now tracked independently, keyed by `session_id`, and nothing about
session B is ever inferred from a hook belonging to session A.

```json
{ "event_id":"01K3TA1B1A2B3C4D5E6F7G8H9J","schema_version":1,"kind":"session.end",
  "event_time":"2026-08-23T14:22:40.198Z","seq":48309,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913",
  "data":{"end_reason":"clear","duration_ms":938204,"turns":14,"aborted_calls":1} }
```

### 6.3 `turn.start`

**Trigger:** the `UserPromptSubmit` hook.

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `prompt_chars` | int | UTF-16 code units | yes | 0…1,000,000 | `412` |
| `project_label` | string | — | yes | ≤ 48 B | `"mezzanine"` |

**The prompt text never transits** — only its length, which is a size, not content, and is what lets
the floor distinguish a one-line nudge from a pasted brief. If review reads a character count as
content-adjacent, deleting the field is a compatible change.

A `UserPromptSubmit` also resolves any attention request open in that session, because a human typing
is a human present ([§ 6.13](#613-attentionresolved)).

```json
{ "event_id":"01K3TA2C3D4E5F6G7H8J9K0M1N","schema_version":1,"kind":"turn.start",
  "event_time":"2026-08-23T14:23:02.660Z","seq":48311,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"prompt_chars":412,"project_label":"mezzanine"} }
```

### 6.4 `turn.end`

**Trigger:** the `Stop` hook (`end_reason: "stop_hook"`), the **`StopFailure`** hook
(`end_reason: "api_error"`), or a session boundary reached with a turn still open —
`session_cleared` when that boundary is a `/clear`, `session_ended` for every other `SessionEnd`
reason. **This is the event a consumer reads to mint "idle", and the only one.**

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `end_reason` | enum | — | no | `stop_hook` \| `api_error` \| `session_cleared` \| `session_ended` | `"stop_hook"` |
| `api_error_type` | enum | — | **yes** | `null` unless `end_reason == "api_error"`; then `rate_limit` \| `overloaded` \| `server_error` \| `authentication_failed` \| `billing_error` \| `invalid_request` \| `model_not_found` \| `max_output_tokens` \| `oauth_org_not_allowed` \| `account_on_hold` \| `unknown` \| `unrecognised` | `null` |
| `duration_ms` | int | ms | yes | ≥ 0; `null` if no `turn.start` was seen | `41880` |
| `open_calls_at_end` | int | — | no | 0…64; counted **before** the reap closed them, over the same scope the reap used — `(session_id, agent_scope_id ?? "main")` ([§ 8.3](#83-the-reap-rules)) | `0` |
| `aborted_call_ids` | array\<ULID\> | — | no | 0…64 elements; see the size note below | `[]` |
| `stop_hook_active` | bool | — | yes | `null` when the payload does not carry it | `false` |
| `background_tasks_open` | int | — | no | ≥ 0, length of the payload's `background_tasks` array | `0` |
| `tool_calls` | int | — | no | ≥ 0, calls started in this turn. **Reporter-minted**, despite sharing a name with the unsubscribed `PostToolBatch` hook's `tool_calls` array — the collision is noted so a harness-fact sweep disposes of it rather than re-opening it each round | `6` |
| `failed_calls` | int | — | no | ≥ 0, calls closed `failed` in this turn | `1` |

`aborted_call_ids` names exactly the calls `open_calls_at_end` counted, so the two never disagree —
the reap ([§ 8.3](#83-the-reap-rules)) emits their closes immediately before this event. **The
invariant is only evaluable because both are stated over one scope**, and that scope is the reap's,
not the session's: a `turn.end` is emitted only where no `agent_id` is present
([§ 8.3](#83-the-reap-rules)), so in practice the scope is `(session_id, "main")` and calls open
inside a subagent are neither counted here nor named here. They are closed by the `SubagentStop` reap
under their own scope, and an earlier draft that left this field's scope unstated made the invariant
above unfalsifiable in exactly the case the two readings differ on.
(`session_cleared` here is the same boundary `session.end` reports as `clear`: the two enums differ
because `session.end` passes the harness's own `reason` through verbatim while this field names the
boundary from the turn's point of view.)

**A turn stays bound to its calls by `prompt_id`, not by wall-clock adjacency.** The harness supplies
`prompt_id` — one UUID correlating a prompt with every hook that follows it until the next prompt —
on `UserPromptSubmit`, on every `PreToolUse`/`PostToolUse`/`PostToolUseFailure`, and on `Stop`
(MEASURED, [§ 6.0](#60-conventions-and-how-harness-payloads-are-read)). `tool_calls`, `failed_calls`
and `duration_ms` are therefore counted over the calls sharing this turn's `prompt_id` rather than
over "calls seen since the last `turn.start`", which is what makes them correct on a session with two
terminals interleaving. `prompt_id` itself does **not** transit — it is a harness identifier and
[§ 1](#1-non-goals) keeps those local; it is a join key inside the reporter only.

**`background_tasks_open` is why a clean `Stop` is not always a finished seat.** The `Stop` payload
carries a `background_tasks` array of in-flight background work, declared as the field that lets a
hook distinguish *"session is done"* from *"session is paused waiting for background work to wake
it"* ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read), DOCS-CITED). Only its **length**
transits — the entries name commands, which [§ 1](#1-non-goals) excludes.

**The `aborted_call_ids` size note.** At the 64-call index cap the array holds 64 × 26 = 1,664 B of
ULIDs; serialized as JSON each element also carries two quotes and a comma, so 64 × 29 = 1,856 B
≈ **1.8 KiB**. (An earlier draft showed the 26 and the 1.8 KiB without the quoting step between them,
which does not compute: 64 × 26 is 1.6 KiB. The conclusion was right and the shown arithmetic was
not.) That is inside the 3 KiB `data` cap but not far inside it, and there is no `descriptor` here to
truncate. It is governed by [§ 6.0](#60-conventions-and-how-harness-payloads-are-read) rule 5 like
every other bound: past 64 entries the array is clamped to the 64 oldest and
`value_clamped.turn.end.aborted_call_ids` is counted. `open_calls_at_end` still carries the true
count, so the disagreement between the two is itself the signal — and it can only arise on a seat
that has already tripped `open_call_index_overflow`.

> **`D2-MUST` #1 — the idle rule.** A consumer may mint an *idle* transition **only** from a
> `turn.end` with `end_reason == "stop_hook"` **and** `aborted_call_ids == []`. Every other
> combination means the turn stopped for a reason other than the agent finishing, and the seat's
> state is `unknown`, never `idle` — **except `end_reason == "api_error"`, which is its own rendered
> state, `stalled`.** A rate-limited or overloaded fleet is a thing an operator acts on, and
> collapsing it into the same `unknown` a killed subagent produces would hide it. `stalled` carries
> `api_error_type` so the drill-down can say *which* error.
>
> **`stalled` has a bounded exit, and all three of its exits are wire events this document already
> emits.** A consumer clears `stalled` on the **first** of: that session's next `turn.start`; that
> session's `session.end` — including the flusher's 90-minute `inferred_silence` close
> ([§ 6.2](#62-sessionend)), which is what bounds the state without inventing a second timer; or the
> seat leaving live state (`stale` at 300 s, `offline` at 900 s). Past a `session.end` with no new
> turn the seat renders **`unknown`**, never `idle` and never `stalled` — the API refused the last
> thing this seat tried and nothing since has said otherwise, which is an honest unknown and not a
> quiet desk. Saying this is not tidiness: the flusher heartbeats every 60 s regardless of session
> activity ([§ 6.14](#614-reporterheartbeat)), so a `stalled` seat never crosses `stale` or `offline`
> on its own, and an entry edge with no stated exit would have rendered one transient rate-limit as
> `stalled` for the rest of the day on a perfectly healthy machine. This document calls that shape a
> **one-way trapdoor** where it appears for *blocked* ([§ 6.12](#612-attentionrequest)) and gives
> *blocked* a whole acceptance test for it ([AT-20](#at-20-blocked-has-an-exit)); `stalled` was minted
> in the same round with the same defect and is bounded here for the same reason.
>
> This one constraint is what the kill-vs-complete machinery in
> [§ 8](#8-call-lifecycle--the-kill-vs-complete-contract) exists to make checkable.

**Why `StopFailure` is subscribed, and what it costs not to be.** The harness fires `StopFailure`,
**not** `Stop`, when a turn ends on an API error ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read),
DOCS-CITED — the value set is read from the installed build's own enum declaration). A design
subscribing `Stop` alone emits no `turn.end` at all on that path: no reap runs, every open call sits
open to the 15- or 60-minute orphan timeout, `session.end.turns` under-counts, and the desk renders
*working* for up to an hour after the agent stopped — on exactly the busiest seats, because those are
the seats that get rate-limited. So `StopFailure` runs the same reap as `Stop` and emits the same
`turn.end`, with `end_reason: "api_error"` and the error type in `api_error_type`. It does **not**
mint *idle*: an agent that stopped because the API refused it did not finish its work, and rendering
that as a quiet desk is the false-idle defect in a different hat.

**A *failed* tool call does not block idle, and that is why `PostToolUseFailure` is subscribed.**
`aborted_call_ids` names calls the harness never closed — killed or lost. A call that ran and errored
is closed by `PostToolUseFailure` with `outcome: "failed"` ([§ 6.6](#66-toolend)): its lifecycle
completed, the agent read the error and carried on, and the turn that follows is an ordinary finish.
Subscribing only to `PostToolUse` — which fires **only on success** — would leave every failed call's
ledger entry open until the `Stop` reap aborted it, so `aborted_call_ids` would be non-empty and
`D2-MUST` #1 would forbid *idle* on any turn containing a single failed `Bash`, `Read` or `Edit`. On
a real seat that is most turns: the seat would sit permanently in `unknown` and the floor would never
render an honest idle again. `failed_calls` rides this event so the ratio is visible rather than
inferred.

```json
{ "event_id":"01K3TA3D4E5F6G7H8J9K0M1N2P","schema_version":1,"kind":"turn.end",
  "event_time":"2026-08-23T14:23:44.540Z","seq":48325,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"end_reason":"stop_hook","api_error_type":null,"duration_ms":41880,"open_calls_at_end":0,
          "aborted_call_ids":[],"stop_hook_active":false,"background_tasks_open":0,
          "tool_calls":6,"failed_calls":1} }
```

### 6.5 `tool.start`

**Trigger:** the `PreToolUse` hook, for every tool without exception — including the subagent-dispatch
tool (`Agent` at 2.1.240, [§ 6.7](#67-subagentspawn)), which *also* produces a `subagent.spawn`
sharing the same `call_id`.

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `call_id` | ULID | — | no | 26 chars, minted by the reporter | `"01K3TA4E5F6G7H8J9K0M1N2P3Q"` |
| `tool_name` | string | — | no | ≤ 64 B, `^[A-Za-z0-9_.-]{1,64}$`, else the literal `"INVALID_TOOL_NAME"` and `invalid_tool_name` is incremented | `"Bash"` |
| `descriptor` | string | — | **yes** | ≤ 200 B, sanitized ([§ 7](#7-sanitization-at-the-reporter)); `null` when the tool is not on the descriptor allowlist | `"Bash: composer test"` |
| `descriptor_truncated` | bool | — | no | — | `false` |
| `agent_scope` | enum | — | **yes** | `main` \| `subagent` \| `null` | `"main"` |
| `parent_call_id` | ULID | — | **yes** | the `call_id` of the dispatch call this call runs inside; `null` in the main agent or when the binding is unresolved | `null` |
| `harness_call_ref` | string | — | yes | ≤ 64 B, opaque | `"toolu_01A9F3kQ2mZ"` |
| `open_calls_before` | int | — | no | 0…64 | `1` |

**`agent_scope` is labelled from the harness's own `agent_id` field, and from nothing else.**
`agent_id` and `agent_type` are common input fields present **only** inside a subagent — MEASURED at
2.1.240 ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)): in the captured dispatch the
subagent's own `PreToolUse` and `PostToolUse` carried `agent_id`, and the main agent's carried
neither. A hook invocation carrying `agent_id` is running in a subagent, one without it is running in
the main agent.
That is a **payload field**, which is the distinction
[§ 3.4](#34-why-identity-never-comes-from-the-environment) actually draws — the 30-day outage was an
*undocumented environment variable* whose meaning changed under a harness upgrade with nothing
watching it. This label is watched: both branches ride the heartbeat as the `agent_scope_subagent`
predicate, and the predicate-constant alarm ([§ 9.4](#94-the-predicate-constant-alarm)) fires if the
harness ever starts sending `agent_id` everywhere (constant `subagent`) or stops sending it at all
(constant `main`). `agent_scope` is `null` only when the payload could not be read, it is never
inferred from an environment variable, and **nothing in the pipeline gates on it** — it labels.

**`parent_call_id` is the intern join key, and the harness's `agent_id` never transits.** The
reporter resolves it locally: `SubagentStart` binds an `agent_id` to the open dispatch call as that
call's `child_agent_id` ([§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call)), and every later
hook carrying that `agent_id` stamps the bound call's `call_id` here — *and* records the same
`agent_id` as its own call's `agent_scope_id`, which is what the turn reap keys on
([§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)). The two uses
are separate index fields on purpose: this one names a **parent**, the reap's names a **scope**.
A consumer therefore knows which intern ran which tool
without ever receiving a second opaque identifier, and `agent_scope == "subagent"` with
`parent_call_id == null` is the honest rendering of "inside a subagent, which one is unresolved" —
counted as `agent_bind_unresolved`, never guessed.

`harness_call_ref` is recorded **when present** and is the preferred close-matching key
([§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)). Note the distinction from the rule above: this is not a
gate — the event is emitted identically whether the ref is present or absent; only the match
*quality* changes, and the quality is itself reported in `tool.end.match`.

```json
{ "event_id":"01K3TA4E5F6G7H8J9K0M1N2P3R","schema_version":1,"kind":"tool.start",
  "event_time":"2026-08-23T14:23:09.882Z","seq":48312,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"call_id":"01K3TA4E5F6G7H8J9K0M1N2P3Q","tool_name":"Bash",
          "descriptor":"Bash: composer test","descriptor_truncated":false,
          "agent_scope":"main","parent_call_id":null,
          "harness_call_ref":"toolu_01A9F3kQ2mZ","open_calls_before":0} }
```

### 6.6 `tool.end`

**Trigger:** one of four, and the trigger *is* the outcome:

| Closing hook | `outcome` | `close_source` |
|---|---|---|
| `PostToolUse` — fires when the call **succeeded** | `completed` | `post_tool_use` |
| `PostToolUseFailure` with `is_interrupt == false` — the call ran and errored | `failed` | `post_tool_use_failure` |
| `PostToolUseFailure` with `is_interrupt == true` — the call was **interrupted**, not errored | `aborted` | `post_tool_use_failure` |
| a reap ([§ 8.3](#83-the-reap-rules)) — no harness close was ever observed | `aborted` | `reap_session_boundary` \| `reap_turn_boundary` \| `reap_reporter_restart` |
| `SubagentStop`, for a dispatch call still open ([§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call)) | `completed` | `subagent_stop_hook` |

**`is_interrupt` is the harness's own kill-vs-fail discriminator, and this design uses it rather than
re-deriving one.** `PostToolUseFailure` carries `{error, is_interrupt, duration_ms}` — MEASURED at
2.1.240 ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)). An interrupted call did not
*fail*; it stopped existing, which is [§ 8.1](#81-the-problem-restated)'s subject exactly, so it maps
to `aborted` with `abort_reason: "interrupted"` and **does** block *idle* under `D2-MUST` #1, while
an ordinary error maps to `failed` and does not. Reading it costs nothing and the alternative is
mislabelling every interrupted call as an ordinary failure — which would let a `/clear` that happens
to produce a `PostToolUseFailure` mint the false idle the whole ledger exists to prevent. When the key
is absent the field is `null`, which is treated as `false` and counted
`payload_key_missing.is_interrupt`; that default is the safe direction only because the reap is the
backstop for a genuinely killed call.

**A call refused by the permission layer closes on the reap, and that is correct, not a lost close.**
MEASURED at 2.1.240: a `Write` blocked by permissions fired `PreToolUse` and then **no close hook of
any kind** — no `PostToolUse`, no `PostToolUseFailure`, no `PermissionDenied`
([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)). Its ledger entry therefore survives to
the turn reap **of the scope it was opened in** and closes `aborted` / `turn_boundary`: the `Stop`
reap in the main agent, and the `SubagentStop` reap inside a subagent, where `Stop` does not fire at
all ([§ 8.3](#83-the-reap-rules)). Naming the scope matters here rather than being pedantry — a
refusal inside a subagent is the common case on a fleet running dispatches under approval, and an
earlier draft's `Stop`-only wording left exactly that call with no close rule until its session ended.
That is the honest outcome — the call never ran — and it is named here so an implementer does not read
the missing close as a defect and "fix" it by inferring a completion.

The `SubagentStop` row reports `completed` for a stated reason rather than an unknown one: its
payload is MEASURED at 2.1.240 and carries `agent_id`, `agent_type`, `agent_transcript_path`,
`last_assistant_message`, `stop_hook_active`, `background_tasks` and `session_crons` — and **no error
indicator of any kind**. So the hook genuinely cannot distinguish a subagent that succeeded from one
that failed, and reporting the transition it *does* observe is the only honest option.
`close_source` is what tells a consumer that this close came from the secondary signal rather than
from the call's own `PostToolUse`, so the two are never conflated.

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `call_id` | ULID | — | no | 26 chars; `"UNMATCHED"` is **not** permitted — see below | `"01K3TA4E5F6G7H8J9K0M1N2P3Q"` |
| `tool_name` | string | — | no | ≤ 64 B | `"Bash"` |
| `outcome` | enum | — | no | `completed` \| `failed` \| `aborted` | `"aborted"` |
| `abort_reason` | enum | — | **yes** | `session_cleared` \| `session_ended` \| `turn_boundary` \| `api_error` \| `interrupted` \| `reporter_restart`; `null` unless `outcome == "aborted"` | `"session_cleared"` |
| `duration_ms` | int | ms | **yes** | ≥ 0; the harness's own `duration_ms` when the closing payload carries one, else end-minus-start from the index; `null` if neither is available | `27411` |
| `duration_source` | enum | — | no | `harness` \| `index` \| `none` | `"harness"` |
| `close_source` | enum | — | no | `post_tool_use` \| `post_tool_use_failure` \| `reap_session_boundary` \| `reap_turn_boundary` \| `reap_reporter_restart` \| `subagent_stop_hook` | `"reap_session_boundary"` |
| `match` | enum | — | no | `harness_ref` \| `sole_open` \| `lifo_tool_name` \| `agent_id` \| `tombstone_ref` \| `synthesized` \| `reap` | `"reap"` |

`match` records how the close found its call: the five **match orders** in
[§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open) when a harness
close had to be matched — this enum has seven members because two of them name closes that needed no
matching — `agent_id` when a bound `SubagentStop` named the call outright
([§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call)), and `reap` when the reporter closed its
own index entry and no matching was involved.

**There is no `is_error` field, deliberately.** An earlier draft carried one, `null` by default and
set "only from an unambiguous harness error indicator" — a field whose value depended on a payload
shape nobody had verified, and which restated in a second place what `outcome` already says. Which
hook closed the call **is** the error indicator, and it is an observation rather than an inspection:
`completed` from `PostToolUse`, `failed` or `aborted` from `PostToolUseFailure` per `is_interrupt`,
`aborted` from a reap. One fact, one home, and no dependency on `tool_response`'s per-tool schema —
which stays UNVERIFIED in [§ 6.0](#60-conventions-and-how-harness-payloads-are-read) precisely
because nothing reads it.

**`duration_ms` prefers the harness's own measurement to the reporter's clock arithmetic.**
`PostToolUse` and `PostToolUseFailure` both carry `duration_ms` (MEASURED at 2.1.240: 251 ms, 260 ms,
18 ms), documented as the tool's execution time excluding permission-prompt and hook time. That is a
strictly better number than end-wall-clock minus start-wall-clock across two processes, and it is
immune to the NTP step below. `duration_source` says which was used, so the two are never conflated
in an aggregate. Where the reporter must compute it — a reap, a `SubagentStop` close, a harness build
that stops sending the key — an NTP step mid-call can make the difference negative, in which case the
reporter sends `null`, sets `duration_source: "none"`, and counts `negative_duration`.

**A close that matches no open call.** If a `PostToolUse` or `PostToolUseFailure` matches neither an
open entry nor a tombstone (the open was lost to spool overflow, or the reporter was installed
mid-call), the reporter **synthesizes** the pair: it emits a `tool.start` with a fresh `call_id`,
`descriptor: null`, `event_time` equal to the close time, and `data.synthesized: true`, immediately
followed by the `tool.end` with `match: "synthesized"`. The ledger therefore never contains a close
without an open — a rule that keeps every consumer's open-call arithmetic total and makes the anomaly
a visible flag rather than a silent negative count.

**A close that arrives after its call was reaped** is *not* that case, and must not be turned into
one. It matches the reaped entry's **tombstone**, so it carries the original `call_id` and reports
`match: "tombstone_ref"` — which is both the fact that the close is late and what lets the server's
late-completion override
([§ 12.5](#125-late-completions-and-orphan-timeouts)) fire on the very path it exists for. Minting a
fresh `call_id` there, as an earlier draft did, meant the override could never fire and
`late_completion` could never leave zero: the instrument that was supposed to tell us the `Stop` reap
is too eager was structurally incapable of reporting it.

```json
{ "event_id":"01K3TA5F6G7H8J9K0M1N2P3Q4S","schema_version":1,"kind":"tool.end",
  "event_time":"2026-08-23T14:22:40.121Z","seq":48307,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913",
  "data":{"call_id":"01K3T9ZZ1A2B3C4D5E6F7G8H9J","tool_name":"Bash","outcome":"aborted",
          "abort_reason":"session_cleared","duration_ms":27411,"duration_source":"index",
          "close_source":"reap_session_boundary","match":"reap"} }
```

### 6.7 `subagent.spawn`

**Trigger:** the `PreToolUse` hook where `tool_name ∈ {"Agent", "Task"}`, emitted **immediately
after** that call's `tool.start`, sharing its `call_id`.

**The dispatch tool's payload `tool_name` is `"Agent"` on this build, not `"Task"` — MEASURED at
2.1.240** ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)). The captured dispatch is
`{"tool_name":"Agent","tool_input":{"description":"…","prompt":"…"}}`. `Task` is the *model-facing*
name; the hook payload carries `Agent`. A design matching `"Task"` alone would emit **no**
`subagent.spawn` on any seat running this build, bind no `agent_id`
([§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call)), and render every dispatch as an
ordinary tool call with no interns on the floor — a whole feature reading zero forever, from one
transcribed string. Both names are matched, because both are live in the wild across harness
versions and matching one costs a feature while matching two costs nothing. Which one fired is
counted as `dispatch_tool_name.<name>`, so the fleet reports which name the harness is actually
sending instead of this document guessing again.

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `call_id` | ULID | — | no | 26 chars, equals the Task `tool.start`'s | `"01K3TA6G7H8J9K0M1N2P3Q4R5T"` |
| `title` | string | — | **yes** | ≤ 120 B, sanitized, from `tool_input.description`; `null` if the payload has no description | `"draft the D1 event schema"` |
| `title_truncated` | bool | — | no | — | `false` |
| `subagent_type` | string | — | yes | ≤ 32 B, `^[A-Za-z0-9_-]+$` | `"coder"` |

**Why both `tool.start` and `subagent.spawn` for one call.** Making the dispatch tool an exception in
the call ledger would create a second lifecycle path through the abort machinery — and the subagent case is
the *one* the kill-vs-complete requirement is actually about, so it is the last place to want a
special case. Instead the call is ordinary, and `subagent.spawn` is a second projection of the same
fact carrying only what the ledger has no field for (the title and the type). The shared `call_id` is
the join key. Cost: ~120 bytes on an event class that fires tens of times a day.

**The title is the one free-text field with a human author**, so it passes the full sanitizer
([§ 7](#7-sanitization-at-the-reporter)) exactly like a descriptor. A dispatch description saying
`use token ghp_…` must not reach the wire.

```json
{ "event_id":"01K3TA6G7H8J9K0M1N2P3Q4R5V","schema_version":1,"kind":"subagent.spawn",
  "event_time":"2026-08-23T14:23:31.004Z","seq":48320,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"call_id":"01K3TA6G7H8J9K0M1N2P3Q4R5T","title":"draft the D1 event schema",
          "title_truncated":false,"subagent_type":"coder"} }
```

### 6.8 `subagent.stop`

**Trigger:** the close of the dispatch call — from `PostToolUse`, from `PostToolUseFailure`, from a
reap, or from the `SubagentStop` hook ([§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call)).
Emitted immediately after that call's `tool.end`, sharing the `call_id`.

**Observed ordering at 2.1.240 (MEASURED).** For one dispatch the harness fired, in order:
`PreToolUse(Agent)` → `SubagentStart` → the subagent's own `PreToolUse`/`PostToolUse` →
`SubagentStop` → `PostToolUse(Agent)` → `Stop`. So `SubagentStop` arrives **before** the dispatch
call's own `PostToolUse`, and it is `SubagentStop` that normally closes the call while `PostToolUse`
finds it already closed — the reverse of what "the primary close" suggests. Neither ordering is
relied on: both close the same `call_id`, the second finds nothing open, and `close_source` records
which one won.

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `call_id` | ULID | — | no | 26 chars | `"01K3TA6G7H8J9K0M1N2P3Q4R5T"` |
| `outcome` | enum | — | no | as [§ 6.6](#66-toolend) | `"aborted"` |
| `abort_reason` | enum | — | yes | as [§ 6.6](#66-toolend) | `"session_cleared"` |
| `duration_ms` | int | ms | yes | ≥ 0, as [§ 6.6](#66-toolend) | `184992` |
| `close_source` | enum | — | no | as [§ 6.6](#66-toolend) | `"reap_session_boundary"` |

Every enum here is [§ 6.6](#66-toolend)'s, by reference and never restated — this event is a second
projection of that call's close, so a value set written twice is a value set free to drift.

The title is **not** repeated here. One fact, one home: the consumer joins on `call_id`. If the spawn
was lost to spool overflow the stop is title-less — an observable orphan, which is the honest outcome,
not a gap to paper over with a second copy that could disagree with the first.

```json
{ "event_id":"01K3TA7H8J9K0M1N2P3Q4R5T6W","schema_version":1,"kind":"subagent.stop",
  "event_time":"2026-08-23T14:26:36.001Z","seq":48338,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"call_id":"01K3TA6G7H8J9K0M1N2P3Q4R5T","outcome":"aborted",
          "abort_reason":"session_cleared","duration_ms":184992,
          "close_source":"reap_session_boundary"} }
```

### 6.9 `compaction.start`

**Trigger:** the `PreCompact` hook. The payload key is **`trigger`** (MEASURED at 2.1.240 —
`"trigger":"manual"` captured; `"auto"` is DOCS-CITED from the installed build's enum declaration,
[§ 6.0](#60-conventions-and-how-harness-payloads-are-read)).

**`custom_instructions` never transits.** The `PreCompact` payload also carries
`custom_instructions` — the operator's free text for `/compact` (MEASURED: present, `null` on the
capture). It is human-authored prose about the session's content, which [§ 1](#1-non-goals) excludes
from the wire outright. It is named here so that a later editor reaching for "a bit of context on why
this compaction happened" finds the ruling instead of the field.

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `trigger` | enum | — | no | `auto` \| `manual` \| `unknown` | `"auto"` |
| `context_used_pct` | float | percent | yes | 0.0…100.0; the last statusLine sample for this session, `null` if none or if it is stale | `92.4` |
| `context_used_pct_age_s` | int | s | yes | 0…300; age of that sample at emission, `null` when `context_used_pct` is `null` | `41` |
| `open_calls` | int | — | no | 0…64 | `0` |

**Where `context_used_pct` comes from — the one cross-process read in the hook path.** Context
percentages appear **only in the statusLine payload**; no hook input carries them, so a hook cannot
read one directly and a field specified as if it could would be `null` on every event forever. The
statusLine process writes its last sample to the seat's sample store
([§ 11.1](#111-layout)) and this hook reads it. A sample older than **300 s** is not used:
`context_used_pct` is `null` and `context_sample_stale` is incremented.

**Derivation of 300 s — stated correctly, because an earlier draft derived it from a rate that does
not exist.** That draft read "5× the 60 s sampling cadence, so a session rendering its status line at
all has a fresh sample". The 60 s is the reporter's own *emission floor*, not a render rate: statusLine
is event-driven with no timer unless `refreshInterval` is configured, and the triggers go quiet when
the session is idle ([§ 6.11](#611-contextsample), [§ 6.0](#60-conventions-and-how-harness-payloads-are-read)).
So a session can render its status line and still hold a sample far older than 300 s, and the old
derivation asserted a freshness guarantee nothing supplies. The honest basis is a **tolerance**, not a
guarantee: 300 s is the age past which a context percentage stops being worth showing next to a
compaction — a seat compacts because its context filled, so a five-minute-old percentage is describing
a different context. The bound is unchanged and still safe, because the failure direction is an honest
`null` plus `context_sample_stale`, never a stale number rendered as current.
`context_used_pct_age_s` ships the age so a consumer never has to assume freshness.

**Compaction does not reap.** It rewrites context; it does not kill a running tool process, so a call
open across a compaction still receives its `PostToolUse`. The open count is recorded rather than
acted on. (If a future harness version makes compaction kill in-flight calls, the reap list in
[§ 8.3](#83-the-reap-rules) gains a row — and note what that costs before making it: a new reap row
adds an `abort_reason` **and** a `close_source` member, both reporter-minted enums with no unknown
member, so it is a **rule-4 change requiring a schema bump and a stated window**, not a one-line
edit ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)). The orphan timeout is the backstop
until it is made.)

```json
{ "event_id":"01K3TA8J9K0M1N2P3Q4R5T6W7X","schema_version":1,"kind":"compaction.start",
  "event_time":"2026-08-23T15:02:18.774Z","seq":48402,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"trigger":"auto","context_used_pct":92.4,"context_used_pct_age_s":41,"open_calls":0} }
```

### 6.10 `compaction.end`

**Trigger:** the `PostCompact` hook, **or** a `SessionStart` with `source == "compact"` arriving with
a compaction open — two independent signals for one transition, so a change in either does not lose
the event. Whichever arrives first closes it; the second is counted (`compaction_double_close`) and
dropped. If neither arrives within **10 min** the flusher closes it with
`close_source: "timeout"`. Derivation of 10 min: compaction is a single model call over a large
context; a minute is typical and 10 min is ~10× that, chosen so the timeout never fires on a slow
compaction, only on a lost signal.

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `duration_ms` | int | ms | yes | ≥ 0 | `43112` |
| `close_source` | enum | — | no | `post_compact` \| `session_start_compact` \| `timeout` | `"post_compact"` |

**`compact_summary` never transits.** The `PostCompact` payload carries `compact_summary` — the whole
model-authored summary of the conversation ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read),
DOCS-CITED). It is the single largest and most content-bearing field the harness offers any hook, and
it is exactly what D-06 and [§ 1](#1-non-goals) forbid: a summary of the session's contents, which can
name files, quote commands, and repeat anything the operator pasted. The reporter does not read it,
does not sanitize it, and does not log it. Naming it here is the point — a field this useful-looking
gets picked up by the next editor unless the ruling is written next to it.

**There is no post-compaction percentage on this event, deliberately.** An earlier draft carried
`context_used_pct_after`, and it could not have been populated: the harness's `current_usage` is
documented as **null after a `/compact` until the next API call**, so the statusLine sample this
event would have read is exactly the sample that does not exist yet. The post-compaction number
arrives on its own, seconds later, as the next `context.sample` — which is where it belongs, since
that kind already owns context percentages. Carrying it here would have been a field that is always
`null` in the one situation it was added for.

```json
{ "event_id":"01K3TA9K0M1N2P3Q4R5T6W7X8Y","schema_version":1,"kind":"compaction.end",
  "event_time":"2026-08-23T15:03:01.886Z","seq":48403,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"duration_ms":43112,"close_source":"post_compact"} }
```

### 6.11 `context.sample`

**Trigger:** the statusLine integration — **sampled, never streamed.**

The statusLine command is invoked far more often than this data is worth storing. The reporter emits
a `context.sample` only when one of three conditions holds; every other invocation increments
`statusline_suppressed` and emits nothing.

| Condition | `sample_reason` | Rule |
|---|---|---|
| ≥ 60 s since the last sample for this session | `cadence` | wall-clock, from the sample store ([§ 11.1](#111-layout)) |
| the 5-percentage-point bucket changed | `threshold_cross` | `floor(pct/5)` differs from the last emitted |
| no sample yet for this session | `first_of_session` | — |

**Derivations, and what the sampling actually guarantees.** 5 points: a human reads a context gauge
at roughly that resolution, so a finer step buys nothing anyone can see and a coarser one hides the
approach to auto-compaction. 60 s: it matches the heartbeat cadence, so a seat's two periodic signals
stay in step.

What the pair guarantees is a **ceiling, not a reduction ratio**: at most 1,440 cadence samples per
session-day plus one per 5-point bucket crossing (≤ 20 for a full traverse from empty to full, and
the traverse is monotonic between compactions). That ceiling is what
[§ 14](#14-every-number-and-where-it-comes-from) sizes against. It is deliberately *not* stated as a
multiple of the render rate, because the render rate is not a rate: statusLine is **event-driven**
with a ~300 ms debounce, an in-flight script is **cancelled** when a new trigger arrives, and a timed
re-render happens only when `refreshInterval` is configured. So renders come in bursts tied to
activity, a busy minute produces far more than a quiet hour, and any "60× reduction" claim would be
arithmetic over an invented constant. `statusline_suppressed` measures the real ratio per seat, which
is the honest instrument and the one that re-derives this if the ceiling ever binds.

**The passthrough obligation.** The statusLine command's stdout *is* the rendered status line, so a
seat that already has one must not lose it. The installer records the previously configured command
as `config.wrapped_statusline`; the reporter spawns it with the same stdin, prints its stdout
**verbatim** to its own stdout, and prints nothing of its own there. Failure behaviour: a wrapped
command that exits non-zero or exceeds **1 s** → the reporter prints whatever bytes it produced (or
nothing), increments `wrapped_statusline_failures`, and exits 0. The seat's status line degrades to
blank; the seat itself never breaks. 1 s: a status line is re-rendered on every trigger, so a slower
command is already broken for its own reasons — and 1 s keeps the reporter's own timeout *below* the
harness's cancellation of a slow status-line script, which is what allows the failure to be counted
at all (see the caveat on statusLine counters in [§ 9.3](#93-degradation-counters): a cancelled
render writes nothing, so `wrapped_statusline_failures` is a floor, not a census).

| `data` field | Source key in the statusLine payload | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|---|
| `used_pct` | `context_window.used_percentage`, or the fallback below | float | percent | no | 0.0…100.0, one decimal | `73.2` |
| `used_tokens` | **`context_window.total_input_tokens`** | int | tokens | yes | 0…10,000,000 | `146401` |
| `total_tokens` | **`context_window.context_window_size`** | int | tokens | yes | 1…10,000,000 | `200000` |
| `used_pct_source` | — *(reporter-minted)* | enum | — | no | `harness` \| `computed` | `"harness"` |
| `model_label` | `model.display_name` | string | — | yes | ≤ 48 B, sanitized | `"claude-opus-5"` |
| `sample_reason` | — *(reporter-minted)* | enum | — | no | `cadence` \| `threshold_cross` \| `first_of_session` | `"threshold_cross"` |

**The fallback must produce the same number the primary does, and that is a `used_tokens` question,
not a rounding one.** `used_pct` is read from `context_window.used_percentage`, which is nullable
early in a session and after a `/compact` until the next API call. When it is null, `used_pct` is
computed as `total_input_tokens / context_window_size × 100` — **input tokens only, output tokens
excluded**. That is not a stylistic choice: the harness's own builder computes
`total_input_tokens = input_tokens + cache_creation_input_tokens + cache_read_input_tokens` and
`used_percentage = round(total_input_tokens / context_window_size × 100)`, clamped to 0…100
([§ 6.0](#60-conventions-and-how-harness-payloads-are-read), DOCS-CITED from the installed build).
Computing the fallback from `total_input_tokens + total_output_tokens` instead — the obvious reading,
and what an implementer would write unprompted — yields a systematically **larger** number, so one
wire field would carry two different meanings depending on which branch produced it. That is
[`docs/VERSIONING.md § Wire compatibility` rule 4](../VERSIONING.md#the-rules)'s re-meaning case,
which the policy singles out as *"the dangerous member of that list … it passes every structural
validator ever written"*. It would also corrupt this section's own sampler: `threshold_cross` fires
when `floor(pct/5)` differs from the last emitted, so a single fallback sample computed the wrong way
mints a spurious bucket crossing on the way in and another on the way out.

`used_pct_source` records which branch produced the number, so the two are distinguishable in an
aggregate rather than silently averaged — and a fleet-wide drift between the branches is visible
instead of inferred.

If `used_percentage` is null **and** `total_input_tokens`/`context_window_size` are absent too, **no
event is emitted** and `payload_key_missing.context_window` is incremented. That is the only
suppression in the design driven by payload shape, it is expected to be non-zero on every seat during
the first seconds of a session, and it is counted precisely because
[§ 3.4](#34-why-identity-never-comes-from-the-environment) says a silent one is how a signal dies
unnoticed.

**The fixture that keeps the two branches honest.** A unit test feeds the documented shape
`{"total_input_tokens": 15500, "context_window_size": 200000, "used_percentage": 8}` down both
branches and asserts they agree to within 1 percentage point (`15500/200000 = 7.75 %`, rounding to
`8`). Its RED is computing the fallback with `total_output_tokens` added: the branches diverge and the
test fails. Without that RED the agreement is an assumption, and it is exactly the assumption that
was wrong.

Every invocation that produces a usable percentage — emitted or suppressed — updates the session's
entry in the sample store, which is what [§ 6.9](#69-compactionstart) reads and what the cadence rule
above compares against.

```json
{ "event_id":"01K3TB0M1N2P3Q4R5T6W7X8Y9Z","schema_version":1,"kind":"context.sample",
  "event_time":"2026-08-23T14:41:00.310Z","seq":48366,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"used_pct":73.2,"used_tokens":146401,"total_tokens":200000,"used_pct_source":"harness",
          "model_label":"claude-opus-5","sample_reason":"threshold_cross"} }
```

### 6.12 `attention.request`

**Trigger:** the `PermissionRequest` hook, or the `Notification` hook — the opening edge of the
*blocked* state `docs/PLAN.md § 7` requires the floor to render. The closing edge is
[§ 6.13](#613-attentionresolved); neither ships without the other, because a state with an entry
event and no exit event is not a state, it is a one-way trapdoor.

| Source | `notification_kind` | How it is decided |
|---|---|---|
| `PermissionRequest` hook | `permission_required` | **observed** — the hook's identity says what it is |
| `Notification` hook | mapped from `notification_type` | **observed** — a table lookup on a harness-supplied field, not a classifier |

| `data` field | Type | Null? | Bounds | Example |
|---|---|---|---|---|
| `request_id` | ULID | no | 26 chars; the join key [§ 6.13](#613-attentionresolved) closes on | `"01K3TB1N2P3Q4R5T6W7X8Y9Z0B"` |
| `source` | enum | no | `permission_request_hook` \| `notification_hook` | `"permission_request_hook"` |
| `notification_kind` | enum | no | `permission_required` \| `input_awaited` \| `elicitation` — **reporter-minted**, three members, no unknown member; see below | `"permission_required"` |
| `call_id` | ULID | yes | the open call the request is for, when exactly one is open; else `null` — see below | `"01K3TA4E5F6G7H8J9K0M1N2P3Q"` |
| `open_calls` | int | no | 0…64 | `1` |

**At most one attention request is open per session.** The harness may well fire both
`PermissionRequest` and `Notification` for one prompt; a second request while one is open is dropped
and counted (`attention_request_duplicate`) rather than minting a second *blocked* the floor would
have to reconcile. The counter is also the discriminating signal for whether the two hooks overlap on
this build, which nobody has measured.

**The message text never transits** — not truncated, not sanitized, not at all. Only the mapped enum
does. The `Notification` payload *does* carry a `message` field, and a `title`
([§ 6.0](#60-conventions-and-how-harness-payloads-are-read), DOCS-CITED), and neither is read.

**There is no notification classifier, and there must not be one.** An earlier draft matched the
harness's English notification wording — `/permission|approve|allow|grant/i` → `permission_required`,
`/waiting|idle|input/i` → `input_awaited`, else `other` — and called itself "knowingly fragile,
instrumented rather than trusted". Instrumenting a fragile classifier is the wrong move when the
field that removes the need for one is sitting in the same payload: `Notification` carries
**`notification_type`**, a harness-supplied string naming the kind directly. So the regexes are
deleted and `notification_kind` is a table lookup:

| `notification_type` | Emits? | `notification_kind` |
|---|---|---|
| `permission_prompt`, `worker_permission_prompt` | yes | `permission_required` |
| `idle_prompt`, `agent_needs_input` | yes | `input_awaited` |
| `elicitation_dialog`, `elicitation_url_dialog` | yes | `elicitation` |
| `auth_success`, `agent_completed`, `elicitation_complete`, `elicitation_response`, `push_notification`, `computer_use_enter`, `computer_use_exit`, `quota_auto_resume_fired`, `quota_auto_resume_disabled`, `quota_auto_resume_stale` | **no** | — |
| anything else | **no** | — |

**Most `Notification` types are not attention requests, and emitting for them would mint a false
*blocked* on every seat.** `D2-MUST` #5 makes `attention.request` the *only* source of *blocked*, so
an unconditional emission would put a desk into *blocked* every time the harness fired
`auth_success` or `agent_completed` — a seat that just **finished** a task would render as waiting on
a human, and would stay there until the next tool close, the next prompt, or the 60-minute ceiling.
That is the exact mirror of the false-idle defect this document exists to prevent, so the gate is on
the emitting side. It is the one carve-out to
[§ 6.0](#60-conventions-and-how-harness-payloads-are-read) rule 2, stated there with this hook named
as the reason, and **every suppressed type is counted individually** as
`notification_not_attention.<type>` — which is what
[§ 3.4](#34-why-identity-never-comes-from-the-environment) actually requires: not that nothing is
suppressed, but that no suppression is silent, and that the counters say which types a real fleet
produces.

**The table above is this document's one home for the `notification_type` value set, and a guard binds
it to the binary.** Its sixteen members are the build's own `Notification` matcher declaration, and
`tools/design/verify-harness-facts.py` re-derives that declaration from the installed binary on every
run and fails if this table's union differs
([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)). That binding is not decoration: an
earlier draft transcribed **14** of the sixteen into [§ 6.0](#60-conventions-and-how-harness-payloads-are-read)
and dropped `elicitation_dialog` and `elicitation_url_dialog` — the only two that produce the
`elicitation` row above — so the section that used them had a basis the measurement of record denied,
and three review rounds passed over it because every guard in the design checked *key names* and no
guard checked a *value set*.

**On the wire the field is still an open set, so the last row is a coercion, not a hole.** The payload
types `notification_type` as a plain **string**
([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)); a matcher declaration says what this
build routes, not what every future build will send. An unrecognised type therefore emits nothing and
is counted `notification_not_attention.<type>` alongside the known non-attention ones, and
`enum_value_unknown.notification_type` counts it separately so "a type we chose not to emit for" and
"a type we have never seen" are never the same number. A new attention-bearing type would show up as
a rising `enum_value_unknown.notification_type` on real seats, which is the edit's trigger. **The
`PermissionRequest` path is still preferred**, because it needs no lookup at all: the hook's identity
says what it is.

**`notification_kind` is reporter-minted and has no `other` member, because no path could ever emit
one.** An earlier draft declared `other` as this field's harness-sourced unknown member, and it was
structurally unreachable: the lookup's last row emits **nothing** for an unrecognised type, so the
coercion rule ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read) rule 4) never gets an event
to put the coerced value on — the rule-2 carve-out suppresses the event first. A wire member that no
input can produce is a render branch D2 and D3 would build and never reach, on a quarter of a
four-member field's surface, so it is deleted rather than documented. Note where the unknown case
actually lives, since it does have to live somewhere: it is the counter
`enum_value_unknown.notification_type`, which is a **harness payload key** and not a wire field —
the one place this document's counter grammar
([§ 6.0](#60-conventions-and-how-harness-payloads-are-read) rule 4) names a payload key rather than a
dotted wire field, and it is named there as the single exception for exactly this reason. The field is
reporter-minted for the same reason: it is produced by the table above, not read from any payload
([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)).

**`call_id` is a heuristic here for a measured reason, not for want of trying.** Neither hook names a
tool call: `PermissionRequest`'s payload carries `tool_name`, `tool_input` and
`permission_suggestions` and **no `tool_use_id`**, and `Notification`'s carries no tool reference at
all and no `notification_metadata` object on this build
([§ 6.0](#60-conventions-and-how-harness-payloads-are-read), DOCS-CITED). So `call_id` is filled only
when exactly one call is open and is `null` otherwise — and [§ 6.13](#613-attentionresolved)'s second
resolution row exists precisely to keep the `granted` edge reachable when it is `null`.

```json
{ "event_id":"01K3TB1N2P3Q4R5T6W7X8Y9Z0A","schema_version":1,"kind":"attention.request",
  "event_time":"2026-08-23T14:44:12.007Z","seq":48371,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"request_id":"01K3TB1N2P3Q4R5T6W7X8Y9Z0B","source":"permission_request_hook",
          "notification_kind":"permission_required",
          "call_id":"01K3TA4E5F6G7H8J9K0M1N2P3Q","open_calls":1} }
```

### 6.13 `attention.resolved`

**Trigger:** the first exit edge to arrive for an open `attention.request`. Four of the six are
direct observations; the two that are inferences — the no-`call_id` close and the timeout — are named
as inferences below and both are bounded.

| First of these to arrive, in the same session | `resolution` | `resolution_source` |
|---|---|---|
| the `PermissionDenied` hook — **auto-mode denials only**, see below | `denied` | `permission_denied_hook` |
| the request's `call_id` closing `completed` or `failed` — the tool ran, so permission was given | `granted` | `call_close` |
| where the request carries **no** `call_id`: the next `tool.end` in that session with any outcome other than `aborted` — the agent is running tools again, so it is no longer waiting on a human | `granted` | `call_close` |
| a `UserPromptSubmit` — a human typing is a human present. **This is also the edge a human-refused permission takes**, see below | `human_input` | `user_prompt_submit` |
| that session's `SessionEnd`, or any reap of it | `session_ended` | `session_end` |
| **60 min** with none of the above | `timeout` | `timeout` |

**`denied` means *auto mode* denied it — a human clicking "no" does not take this edge.**
`PermissionDenied` fires when auto mode denies a tool call, including denials with no classifier
verdict ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read), DOCS-CITED); it is not the
interactive refusal hook. On a fleet running interactive approvals — which is most seats — a human
refusal produces no `PermissionDenied` at all. The request instead resolves as `human_input` when the
operator types their next instruction, or as `timeout` if they walk away, and the refused call itself
closes on the turn's `Stop` reap ([§ 6.6](#66-toolend): a permission-refused call fires **no** close
hook, MEASURED). So `resolution` is honest about what it observed rather than about what happened:
`denied` is a narrow, auto-mode-only fact, and the distribution across a fleet reads accordingly.
Reading a low `denied` share as "few refusals" would be wrong; it means "few *auto-mode* refusals".

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `request_id` | ULID | — | no | the `attention.request` this closes | `"01K3TB1N2P3Q4R5T6W7X8Y9Z0B"` |
| `resolution` | enum | — | no | `granted` \| `denied` \| `human_input` \| `session_ended` \| `timeout` | `"granted"` |
| `resolution_source` | enum | — | no | as the table above | `"call_close"` |
| `waited_ms` | int | ms | no | ≥ 0, from the request's `event_time` | `18402` |

**Why the second row exists.** A `PermissionRequest` can arrive before the tool call it is about is
open — the hook order is not documented — so `attention.request.call_id` is `null` whenever no single
open call could be named. Without that row the `granted` edge would be unreachable in exactly that
case and an ordinary approved permission would sit *blocked* until the 60-minute ceiling. Resolving on
the session's next non-aborted tool close is an inference, and it is labelled as one: it shares
`resolution_source: "call_close"`, and `waited_ms` shows how long the seat was rendered blocked.

**Derivation of 60 min.** The timeout is the weakest member, and it is reachable only when every
earlier edge failed to arrive — so it is a *fallback for a broken signal*, not the normal
path, and the asymmetry decides the number: a desk that shows *blocked* slightly too long is
misleading in a way an operator can see and correct, while a desk that silently un-blocks itself
while the agent is still waiting on a human is the false-idle defect of
[§ 8.1](#81-the-problem-restated) wearing a different hat. So err long, and reuse the number the same
argument already produced — the 60-minute `Task` orphan ceiling
([§ 12.5](#125-late-completions-and-orphan-timeouts)) — so that a seat can never render *blocked*
after every call it was blocked on has already been reaped.

**The predicate that watches this, and what it can and cannot see.** `attention_resolved_by_hook`
counts observed resolutions (`true`) against timeouts (`false`). If *every* exit edge stops arriving
the branch goes constant-`false` and the predicate-constant alarm
([§ 9.4](#94-the-predicate-constant-alarm)) says so. Be precise about its reach, though, because an
over-claimed instrument is worse than a missing one: on a fleet running interactive approvals the
`true` branch is carried by `call_close` and `user_prompt_submit`, **not** by the permission hooks, so
a death of `PermissionRequest`/`PermissionDenied` alone would not move this predicate. The instrument
that *would* see that is the `attention.request.source` distribution — a `permission_request_hook`
share falling to zero on a seat that is still being blocked — which is why
[§ 9.4](#94-the-predicate-constant-alarm) carries `attention_source_permission_hook` as its own
predicate rather than folding it in here.

> **`D2-MUST` #5 — the blocked rule.** A consumer may mint *blocked* only from an
> `attention.request`, and must clear it on the matching `attention.resolved` (joined by
> `request_id`), on the seat leaving live state (`stale`, `offline`), or on that session ending —
> whichever comes first. A seat may never render *blocked* for longer than the 60-minute ceiling
> without a matching `attention.resolved`, because past that the reporter has already emitted one.

```json
{ "event_id":"01K3TB1P3Q4R5T6W7X8Y9Z0A1C","schema_version":1,"kind":"attention.resolved",
  "event_time":"2026-08-23T14:44:30.409Z","seq":48372,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"request_id":"01K3TB1N2P3Q4R5T6W7X8Y9Z0B","resolution":"granted",
          "resolution_source":"call_close","waited_ms":18402} }
```

### 6.14 `reporter.heartbeat`

**Trigger:** the flusher, every **60 s**, whether or not anything else is queued. `session_id` is
`null`. This is the event that makes reporter silence a *state* rather than an appearance.

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `uptime_s` | int | s | no | ≥ 0 | `86213` |
| `spool_bytes` | int | bytes | no | 0…33,554,432 | `18422` |
| `spool_files` | int | — | no | 0…400 | `2` |
| `spool_lag_events` | int | — | no | ≥ 0, unsent events behind the cursor | `0` |
| `oldest_unsent_age_s` | int | s | yes | ≥ 0; `null` when the spool is drained | `null` |
| `last_hook_at` | rfc3339_ms | UTC | yes | `null` if no hook since flusher start | `"2026-08-23T14:44:12.007Z"` |
| `open_calls` | int | — | no | 0…64, **enforced** by the index cap ([§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)) | `1` |
| `open_sessions` | int | — | no | 0…16, **enforced** by the session cap ([§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)) | `1` |
| `open_attention` | int | — | no | 0…16, requests awaiting an `attention.resolved`; one per session, so the session cap bounds it | `0` |
| `enabled` | bool | — | no | `config.enabled` ([§ 3.1](#31-the-seat-config-file)) | `true` |
| `degraded` | array\<enum\> | — | no | 0…12 elements, one per member of the set [§ 9.3](#93-degradation-counters) declares; no duplicates, ordered as [§ 9.3](#93-degradation-counters) lists them | `["batches_rejected"]` |
| `counters` | object | — | no | ≤ 1.5 KiB serialized, all monotonic since flusher start; reduction rule below | see below |
| `counters_omitted` | int | — | no | ≥ 0, counters dropped to fit the cap | `0` |
| `predicates` | object | — | no | ≤ 512 B, `{name:{true:int,false:int}}` | see below |
| `selftest` | object | — | no | ≤ 256 B, `{name:"pass"\|"fail"}` | see below |
| `config_fingerprint` | string | — | no | 16 hex chars = SHA-256 of `install_id\|seat_id\|ingest_url`, **token excluded** | `"9f2c41a7be03d518"` |

**`enabled` rides the heartbeat so a deliberately-disabled seat is distinguishable from a dead one.**
[§ 3.1](#31-the-seat-config-file) calls `enabled: false` "explicit, local, and visible in the
heartbeat's last transmission" — which was only true if the heartbeat carried it, and it did not. It
does now. Note what `enabled: false` actually means here: the **hooks** stop emitting, and the flusher
keeps heartbeating with `enabled: false` so the desk renders *disabled* rather than sliding through
`stale` into `offline` and looking broken. A seat that is off and a seat that is gone must not look
alike, for the same reason an empty floor and a broken floor must not.

`config_fingerprint` deliberately excludes the token: a fingerprint that covered the secret would let
anyone holding the event stream confirm a guessed token by comparing hashes. It exists so an operator
can tell "this seat was reconfigured" from "this seat is a different seat".

The `counters` object carries the always-present delivery counters below plus **every** counter in
[§ 9.3](#93-degradation-counters) that is non-zero; the flusher folds them from the counter sink
([§ 11.1](#111-layout)) rather than computing them, because most of them are incremented in hook and
statusLine processes it never shares memory with.

**The 1.5 KiB cap has a reduction rule, because "most of them are zero on a healthy seat" is not a
bound.** [§ 9.3](#93-degradation-counters) defines ~30 named counters plus the open-ended
`payload_key_missing.<key>`, `enum_value_unknown.<wire field>`, `value_clamped.<wire field>`,
`notification_not_attention.<type>` and `dispatch_tool_name.<name>` families. At ~32 B per entry a
seat with many of them non-zero is at or past 1.5 KiB — and a *degraded* seat is exactly the seat
where several of these are non-zero at once, so the mechanism that makes a broken seat visible would
be the first thing to break on a broken seat. Under
[§ 6.0](#60-conventions-and-how-harness-payloads-are-read) rule 5 that would otherwise be a
`422 invalid_event`, hence a rejected 200-event batch, hence a permanently quarantined one — the
liveness backstop going dark on the seats that need it most.

So the rule is stated and deterministic: **serialize the always-present delivery counters first, then
the remaining non-zero counters in descending value order, breaking ties by name ascending; stop
before the entry that would exceed 1.5 KiB; set `counters_omitted` to the number not written.**
Descending value keeps the loudest signals, name-ascending makes the output identical for identical
input (a fixture can assert it), and `counters_omitted > 0` is itself a `degraded` condition — a seat
with too many kinds of trouble to report is reporting that fact.

```json
{ "event_id":"01K3TB2P3Q4R5T6W7X8Y9Z0A1B","schema_version":1,"kind":"reporter.heartbeat",
  "event_time":"2026-08-23T14:45:00.000Z","seq":48374,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":null,
  "data":{
    "uptime_s":401150,"spool_bytes":18422,"spool_files":2,"spool_lag_events":0,
    "oldest_unsent_age_s":null,"last_hook_at":"2026-08-23T14:44:12.007Z",
    "open_calls":1,"open_sessions":1,"open_attention":0,"enabled":true,"degraded":[],
    "counters":{"events_emitted":48374,"events_sent":48373,"spool_dropped_events":0,
                "spool_corrupt_lines":0,"batches_ok":1611,"batches_retried":4,
                "batches_rejected":0,"events_rejected_dropped":0,"statusline_suppressed":51882,
                "sanitizer_redactions":37,"sanitizer_truncations":12,
                "hook_name_mismatch":0,"negative_duration":0,"tombstone_late_close":1,
                "enum_value_unknown.session.start.source":0,"agent_bind_unresolved":0,
                "reap_noop_second_signal":11,"context_sample_stale":0,
                "payload_key_missing.session_id":0,"wrapped_statusline_failures":0},
    "counters_omitted":0,
    "predicates":{"attention_source_permission_hook":{"true":19,"false":6},
                  "descriptor_allowlisted":{"true":2841,"false":93},
                  "clear_reap_by_session_end":{"true":11,"false":11},
                  "agent_scope_subagent":{"true":412,"false":2522},
                  "attention_resolved_by_hook":{"true":25,"false":0}},
    "selftest":{"sanitizer_fixtures":"pass","harness_payload_keys":"pass",
                "config_readable":"pass","tls_verify":"pass"},
    "config_fingerprint":"9f2c41a7be03d518"} }
```

**The example's own arithmetic is checkable, and is meant to be checked.** `uptime_s` 401,150 s is
4.64 days, so `events_emitted` 48,374 is ~10,420 events/day — exactly the
[§ 6.0](#60-conventions-and-how-harness-payloads-are-read) kind-table ceiling this document sizes
everything against, which is what a *maximally* busy seat looks like. An earlier draft showed the same
48,374 events against a one-day uptime, i.e. 4.6× a ceiling the same document called a ceiling — a
worked example quietly refuting the number it was illustrating. The cross-checks inside the example
hold too: `descriptor_allowlisted` 2,841 + 93 = 2,934 = `agent_scope_subagent` 412 + 2,522 (both are
per-`tool.start`), and `attention_resolved_by_hook` 25 + 0 = `attention_source_permission_hook`
19 + 6 = 25.

---

## 7. Sanitization, at the reporter

**D-06 is binding: the payload is minimized, and the minimization happens on the client.** The reason
is stated as a property, not a preference — *a secret that is never sent cannot be leaked by the
server*. If sanitization lived at the ingest, every secret in every seat's tool arguments would cross
the WAN and land in a request log, an APM trace, or a stack trace before any rule ran. So the wire
carries a label, and the raw text never leaves the machine that produced it.

Sanitization has two layers, and the **first one is the real control**.

### 7.1 Layer 1 — the descriptor allowlist

A descriptor is built **only** from an explicitly allowlisted input key of an explicitly allowlisted
tool. A tool not in this table contributes **no descriptor at all** (`descriptor: null`); its
`tool_name` still transits, so the floor still shows that *something* ran.

| `tool_name` | Descriptor source | Rendered as | Example |
|---|---|---|---|
| `Bash` | `tool_input.command`, first line only | `Bash: <cmd>` | `Bash: composer test` |
| `Read` | `tool_input.file_path` | `Read: <path>` | `Read: ~/…/docs/PLAN.md` |
| `Write` | `tool_input.file_path` | `Write: <path>` | `Write: ~/…/design/EVENT-SCHEMA.md` |
| `Edit` | `tool_input.file_path` | `Edit: <path>` | `Edit: app/Http/IngestController.php` |
| `Glob` | `tool_input.pattern` | `Glob: <pattern>` | `Glob: **/*.php` |
| `Grep` | `tool_input.pattern` | `Grep: <pattern>` | `Grep: schema_version` |
| `Agent`, `Task` | `tool_input.description` | `<tool_name>: <description>` | `Agent: draft the D1 event schema` |
| `WebFetch` | `tool_input.url`, **scheme + host only** | `WebFetch: <scheme>://<host>` | `WebFetch: https://docs.anthropic.com` |
| `WebSearch` | `tool_input.query` | `WebSearch: <query>` | `WebSearch: laravel reverb auth` |
| `TodoWrite` | *(none)* | `TodoWrite` | `TodoWrite` |
| anything else, **including every `mcp__*` tool** | *(none)* | `null` | `null` |

MCP tools are called out because they are the largest open surface: their input schemas are defined by
third-party servers, so no rule written here can bound what a key contains. The allowlist handles them
by construction — an unknown tool has no allowlisted key, therefore no descriptor.

**`Bash`'s first-line-only rule is a minimization control, not a formatting one**, and it is not
made redundant by the control-character rule in layer 2. Rule 11 turns a newline into a space
*within* the text it is given; first-line-only decides that a 40-line heredoc's remaining 39 lines
are never given to it at all. The two are complementary and both are load-bearing: fixture 6 exists
to keep them that way.

**This is the property to test.** AT-2 fixture 8 feeds an unknown tool an input named `password` and
asserts `descriptor == null`, proving the allowlist and not the regexes is what stops it.

### 7.2 Layer 2 — redaction of the allowlisted text

The allowlisted fields can themselves contain secrets — `curl -H "Authorization: Bearer sk-…"` is an
allowlisted `Bash` command. So the candidate descriptor passes a redaction pass before truncation.

**The locking rule.** Once a rule replaces a span with a `‹…›` marker, later rules do not match inside
or across that marker; a candidate match overlapping a locked span is discarded. Without this the
output depends on rule interaction order in ways nobody can predict, and the fixtures below would not
be deterministic. The rule is load-bearing in fixtures 1 and 3, where it is the only reason a
credential keyword next to an already-redacted value does not redact the marker itself.

### 7.3 Redaction rules, applied in this order

**Order is part of the contract**, not an implementation detail: rule 6 runs before rule 7 because a
filesystem path is a long run of `[A-Za-z0-9/]` and would otherwise be eaten whole by the blob rule
before it could be shortened to something a human can read.

| # | Rule | Pattern (illustrative) | Replacement |
|---|---|---|---|
| 1 | URL userinfo | `(\w+://)[^/\s:@]+:[^/\s@]+@` | `$1‹redacted›@` |
| 2 | Env-expansion **defaults** | `\$\{(\w+):([-=?+])([^}]*)\}` | `${$1:$2‹redacted›}` — the operator is **kept verbatim**, so `${V:=x}` and `${V:?x}` are not relabelled as `${V:-…}` |
| 3 | Known-prefix credentials | `\b(gh[pousr]_\|github_pat_\|sk-\|sk_live_\|sk_test_\|xox[abposr]-\|AKIA\|ASIA\|glpat-\|AIza\|mzn_)[A-Za-z0-9_\-]{8,}` | `‹redacted:token›` |
| 4 | Credential **keyword** + its value, separated by `=`, `:` **or whitespace** | `(?i)(?<![\w-])((?:-{1,2}\|[A-Za-z0-9]{0,24}[_-])?(?:pass(?:word)?\|secret\|token\|api[_-]?key\|auth\|bearer\|credential))(?![A-Za-z])(\s*[:=]\s*\|\s+)(\S+)` | `$1$2‹redacted›` — keyword and separator kept verbatim |
| 5 | Credential **flags**, glued or separated: `--user` `--password` `--token` `--secret`, and `-u` / `-p` (case-insensitively, so `-P` too) | `(?i)(?<![\w-])(--(?:user\|password\|token\|secret)(\s*[:=]\s*\|\s+)\|-[up](\s*[:=]?\s*))(\S+)` | flag + separator verbatim, then `‹redacted›` |
| 6 | Home and long paths | `/home/<u>/`, `/Users/<u>/`, `C:\Users\<u>\` → `~/`; then a **path token** (one starting at a whitespace or quote boundary with `/`, `~/`, `./` or `X:\`) with > 4 segments keeps its **root prefix** + `…` + the last 2 segments. The root prefix is `~`, `.`, `X:` or — for an absolute non-home path — the **empty string before the leading `/`**, never the first named directory | `~/…/design/EVENT-SCHEMA.md`; `/var/www/app/Http/X.php` → `/…/Http/X.php` |
| 7 | Long opaque blobs | `[A-Za-z0-9+/]{32,}={0,2}` and `\b[0-9a-f]{24,}\b` | `‹redacted:blob›` |
| 8 | Email addresses | `[\w.+-]+@[\w-]+\.[\w.-]+` | `‹redacted:email›` |
| 9 | IPv4 literals | `\b(\d{1,3}\.){3}\d{1,3}\b` (valid octets) | `‹redacted:ip›` |
| 10 | ANSI escape sequences | `\x1b\[[0-9;]*[A-Za-z]` and `\x1b][^\x07\x1b]*(\x07\|\x1b\\)` | removed entirely |
| 11 | Control characters | `[\x00-\x1F\x7F]` including newline, tab, and any surviving ESC | single space |
| 12 | Whitespace collapse | ` {2,}` | single space, then trim |
| 13 | Encoding repair | lone surrogates / invalid UTF-8 | `U+FFFD`; output must be valid UTF-8 |
| 14 | Truncate | [§ 7.4](#74-truncation) | — |

**Rules 5, 7 and 9 have deliberate false positives, and they are the correct trade.** Rule 5 redacts
the argument of `git push -u origin main` (`-u ‹redacted› main`) and of `mysql -P 3306`; rule 7 eats a
40-character git SHA and can eat a long final path segment left by rule 6; rule 9 eats a four-part
version string. A descriptor that reads `Bash: git show ‹redacted:blob›` still answers the only
question the floor asks — *what is this agent doing* — while the inverse error, a leaked
32-character credential, is unrecoverable the moment it is written to a log. Every redaction
increments `sanitizer_redactions`, so an implausible rate is visible rather than mysterious.

**Rule 7 can still eat a short-but-long-segment path, and fixture 13 pins it.** Rule 6 only shortens
a path with **more than 4 segments**, so `/opt/verylongdirectoryname/application.php` passes through
untouched — and rule 7 then sees the run `opt/verylongdirectoryname/application`, 37 characters of
`[A-Za-z0-9+/]`, and redacts it to `‹redacted:blob›.php`. That is the acknowledged rule-7 false
positive, and the trade is unchanged: a descriptor that answers less is survivable, a leaked
credential is not. It is fixtured rather than left as prose so that a future widening of rule 6's
segment threshold has a test that changes with it.

**"Root prefix", spelled out, because the two readings differ and only one matches the fixtures.**
Fixture 5 requires `/home/aimlapm/projects/mezzanine/app/Http/Controllers/IngestController.php` →
`~/…/Controllers/IngestController.php`, which drops `projects` — so the retained head is the `~` that
rule 6's first half produced, **not** the first named segment. For an absolute path outside a home
directory there is no `~`, and the retained head is the empty root: `/var/www/app/Http/X.php` becomes
`/…/Http/X.php`, never `/var/…/Http/X.php`. Fixture 12 covers that case, because a rule whose two
readings disagree and whose fixtures exercise only one is an unstated default
([`docs/PLAN.md § 2`](../PLAN.md#2-design-first-gates--the-order-is-the-plan)).

**A stated gap in rule 7, with its backstop.** The blob class `[A-Za-z0-9+/]` excludes `-` and `_`, so
a **base64url** secret (which uses exactly those two characters) is split into runs shorter than 32
and can survive rule 7. Rule 3's known prefixes are the backstop for the credentials that matter most
— including this project's own `mzn_` seat tokens — and rules 4 and 5 catch the shapes where such a
value sits next to a credential keyword or flag. Widening rule 7's class to include `-` and `_` was
considered and rejected: it would eat ordinary long flag strings and hyphenated identifiers, and a
descriptor made of `‹redacted:blob›` answers nothing. The residual is named here rather than papered
over.

**Rule 2 is here because of a measured incident in this fleet:** `${VAR:-fallback}` prints the
fallback when `VAR` is unset, and the fallback is exactly where a hard-coded credential hides. The
variable *name* is kept (it carries no value and is useful context); only the default is redacted.
The reporter never expands a variable — it is reading text, not running a shell.

**Rule 6 is a PII rule as much as a length rule** — an absolute path carries the OS username, which
[§ 1](#1-non-goals) excludes from the wire.

**Rule 10 is not cosmetic.** A descriptor is written to a local log, a quarantine file and an
operator's terminal; an ESC sequence that survives into any of them is a terminal-control injection,
and stripping only the ESC byte (as rule 11 alone would) leaves the visible garbage `[31m` in the
label. Removing the whole sequence is both safer and more readable.

### 7.4 Truncation

The descriptor is truncated to **200 bytes** of UTF-8, never splitting a multi-byte character: cut at
the last character boundary at or before byte 197 and append `…` (U+2026, 3 bytes), giving exactly
≤ 200 bytes. Set `descriptor_truncated: true` and increment `sanitizer_truncations`.
`subagent.spawn.title` uses the same procedure at **120 bytes** (117 + `…`).

**Derivation of 200 bytes.** Three independent constraints agree on this order of magnitude, which is
why it is the number: the drill-down panel renders a desk's current action on about two lines of ~80
characters (≈ 160), so bytes past ~200 could not be displayed; the commands this is meant to label —
`composer test`, `npm run build`, `git log --oneline -20` — are all under 40 bytes, so 200 gives 5×
headroom for a long invocation with flags; and holding a `tool.start` near 500 bytes is what makes the
spool and batch arithmetic in [§ 14](#14-every-number-and-where-it-comes-from) come out where it does.
**120 bytes** for a title: a dispatch description is 3–8 words, and 120 bytes holds ~18 English words.

### 7.5 RED fixtures — required tests

These are unit tests over the sanitizer function, run in CI on both platforms. **They must be seen to
fail before they are trusted** ([`docs/PLAN.md § 2`](../PLAN.md#2-design-first-gates--the-order-is-the-plan)):
replace the sanitizer body with `s => s` and the whole table must go RED. A fixture set that only
ever passes proves nothing about the sanitizer; it proves the harness runs.

**Every fixture is produced by tracing [§ 7.3](#73-redaction-rules-applied-in-this-order) in order**,
and the "Rules that fire" column is that trace. It is not decoration: the two tables are one
behaviour written twice, and a fixture table hand-maintained beside a rule table drifts — this
document shipped a draft in which fixture 1 demanded output no rule could produce and fixture 6
demanded a second line the allowlist can never pass through. **When a rule changes, every fixture is
re-traced in the same change**, and the trace column is what makes that check mechanical rather than
a re-read.

| # | Input (tool, raw allowlisted value) | Rules that fire, in order | Required descriptor output |
|---|---|---|---|
| 1 | `Bash`, `curl -H "Authorization: Bearer ghp_ABCDEF1234567890abcdef1234" https://api.github.com/user` | 3 (rule 4's candidate `Bearer ‹…›` overlaps the lock and is discarded) | `Bash: curl -H "Authorization: Bearer ‹redacted:token›" https://api.github.com/user` |
| 2 | `Bash`, `psql "postgres://mez:s3cr3t-pw@db.example.com:5432/mezz" -c '\dt'` | 1 | `Bash: psql "postgres://‹redacted›@db.example.com:5432/mezz" -c '\dt'` |
| 3 | `Bash`, `echo "${STRIPE_SECRET:-sk_live_51H8xYzAbCdEfGhIj}" > /tmp/k` | 2 (rules 3 and 4 then overlap the lock and are discarded) | `Bash: echo "${STRIPE_SECRET:-‹redacted›}" > /tmp/k` |
| 4 | `Bash`, `deploy --host 203.0.113.47 --notify ops@example.org` | 8, 9 | `Bash: deploy --host ‹redacted:ip› --notify ‹redacted:email›` |
| 5 | `Read`, `/home/aimlapm/projects/mezzanine/app/Http/Controllers/IngestController.php` | 6 (then rule 7 finds no run ≥ 32: `Controllers/IngestController` is 28) | `Read: ~/…/Controllers/IngestController.php` |
| 6 | `Bash`, `git commit -m "\x1b[31mline one\nline two"` — a literal ESC sequence and a literal newline | *(layer 1 takes the first line only)*, 10 | `Bash: git commit -m "line one` — one line, no ESC byte, no `[31m`, and the string `line two` appears nowhere |
| 7 | `Bash`, the literal string `echo ` followed by `"` then the 2-byte `é` repeated 300 times then `"` — 611 bytes, no other rule's pattern present, and a character boundary that straddles byte 197 | 14 | exactly 200 bytes; valid UTF-8; ends with `…` (U+2026); the final `é` is whole, never a lone `0xC3`; `descriptor_truncated == true` |
| 8 | `mcp__vault__read`, `{"password":"hunter2","path":"/prod/db"}` | *(none — layer 1 refuses the tool)* | `descriptor == null`, `tool_name == "mcp__vault__read"`, and the string `hunter2` appears nowhere in the emitted event |
| 9 | `Bash`, `deploy --password hunter2 --host db1` | 4 | `Bash: deploy --password ‹redacted› --host db1` |
| 10 | `Bash`, `curl -u admin:s3cr3t https://api.example.org/v1/ping` | 5 | `Bash: curl -u ‹redacted› https://api.example.org/v1/ping` |
| 11 | `Bash`, `mysql -pS3cr3tP@ss -h db1 mezz` | 5 (glued, empty separator; rule 8's `@` then overlaps the lock) | `Bash: mysql -p‹redacted› -h db1 mezz` |
| 12 | `Read`, `/var/www/app/Http/Controllers/HealthController.php` — an absolute path **outside** any home directory, 6 segments | 6 (then rule 7 finds no run ≥ 32: `Controllers/HealthController` is 28) | `Read: /…/Controllers/HealthController.php` — the retained head is the empty root, **not** `/var` |
| 13 | `Read`, `/opt/verylongdirectoryname/application.php` — 3 segments, so rule 6 does not shorten it | 7 (the 37-character run `opt/verylongdirectoryname/application`) | `Read: /‹redacted:blob›.php` — the acknowledged rule-7 false positive, pinned |

Fixtures **9, 10 and 11 are the credential-on-argv shapes rule 4 alone did not cover**: a
space-separated `--password`, a `curl -u user:pass` with no `scheme://`, and a glued single-letter
flag. Each was an unredacted survivor before rules 4 and 5 took their present form, and each is the RED that
proves the extension is load-bearing.

Fixture 4's input is `--host`/`--notify` rather than the `--user` an earlier draft used, because
rule 5 now redacts `--user`'s argument before the email rule can see it. That is the fixture table and
the rule table being checked against each other rather than maintained twice: the fixture whose
purpose is the email and IP rules must not hand its input to an earlier rule.

Fixture 8 is the one that matters most: it tests the allowlist, which is the control that holds when
an input shape nobody anticipated arrives. Fixtures 1–4 and 9–11 test the second layer.

**Fixtures 12 and 13 exist because two rules had a stated behaviour with no test.** 12 pins rule 6's
retained head for an absolute non-home path — the reading that produces `/var/…/Http/X.php` and the
one that produces `/…/Http/X.php` both fit the old wording, and only the second matches fixture 5.
13 pins the rule-7 false positive that [§ 7.3](#73-redaction-rules-applied-in-this-order) acknowledges
in prose; an acknowledged behaviour with no fixture is a behaviour that changes silently.

**Every fixture's input is a literal string.** Fixture 7's was a description — *"a 600-byte command
whose bytes 195–205 are the 2-byte `é`"* — which [AT-2](#at-2-sanitizer-red-fixtures) cannot assert an
exact output for, and which left the rest of the 600 bytes unspecified and therefore free to trip a
rule the fixture was not testing. It is now a constructed literal whose only active rule is
truncation.

**Two whole-event assertions accompany the table**, because a per-function test cannot see a leak that
happens outside the function: (a) for every fixture, serialize the *complete* event and assert the raw
secret substring is absent from the serialized bytes; (b) a fuzz case feeds 1,000 randomly generated
`tool_input` objects containing planted credentials at random depths and asserts no planted string
reaches any emitted event. The planted-string corpus reuses rule 3's prefixes.

---

## 8. Call lifecycle — the kill-vs-complete contract

### 8.1 The problem, restated

**Measured upstream (roundtable #341/#340, 26 of 26 events): a `/clear` on a seat SIGKILLs an
in-flight subagent's tool call.** The kill produces no completion signal — no `PostToolUse`, and no
`PostToolUseFailure` either, because the call did not fail, it stopped existing. A consumer that
treats the next turn boundary as "the turn finished" therefore mints a **false idle transition**, and
it does so on exactly the seats that are busiest, because those are the seats running subagents when
someone clears. A dashboard whose idle indicator is least trustworthy when work is heaviest is worse
than no dashboard: it is confidently wrong in the one direction an operator would act on.

**The design answer: every tool call is an explicit ledger entry with an open and a close, and a
close always states *how* it was closed.** Absence is never read as completion — by anybody, at any
layer. The corollary, which the failure hook makes possible, is that *a call that ran and errored is
a close, not an absence* ([§ 6.4](#64-turnend)).

### 8.2 The call index: an append-only journal, and matching a close to its open

**The index is never read-modify-written by a hook.** Claude Code runs tool calls in parallel, so
several hook processes are alive at once, and a shared JSON file rewritten by each of them —
`.tmp` + rename, no lock, one fixed temp name — is a lost-update generator: two hooks read the same
state, each writes back its own mutation, one open call disappears. The consequence is not a lost
statistic. The reporter can no longer close a call it has forgotten, the server holds it open until
the orphan timeout, and a perfectly healthy seat renders *working* for fifteen minutes after it went
idle. So the index is structured the way the spool already is — **append-only, one `writeSync` per
record, no process ever rewriting what another may be writing**:

```
<spool_dir>/index/
  snapshot.json          {taken_at, bucket, offset, entries[], tombstones[]}  — FLUSHER ONLY
  2026082314.jsonl       one record per ledger mutation, appended by any hook, UTC-hour bucketed
  2026082315.jsonl
```

| Concern | How the journal handles it |
|---|---|
| **Reading the current index** | fold `snapshot.json`, then replay the snapshot's own bucket from byte `offset` onward, then every later bucket in full. Appends only ever grow a file, so a byte offset is stable |
| **Concurrent writers** | every record is one `O_APPEND` `writeSync` of one `\n`-terminated line ≤ 4 KiB — the same atomicity property, and the same test ([AT-10](#at-10-concurrent-append-and-index-integrity)), as the spool |
| **Compaction** | the flusher folds and rewrites `snapshot.json` (`.tmp` + rename, **unique temp name**) on every pass, then deletes index buckets older than the one the snapshot names. It is the only writer of `snapshot.json`, and [§ 2.3](#23-the-flusher-must-be-alive-whenever-the-seat-is) makes "the only flusher" true |
| **A dead flusher** | nothing breaks: the fold just walks more buckets. Cost is ~1 ms per 100 KiB of tail against the 250 ms budget, the tail grows at ~0.9 MB/day on a busy seat, and a flusher absent for more than 90 s is respawned by the next hook ([§ 2.3](#23-the-flusher-must-be-alive-whenever-the-seat-is)). A seat whose flusher is *permanently* dead is already rendering `stale`, so this degradation is never the silent one |
| **The pathological tail** | past **8 MiB** of unfolded journal (≈ 9 days of a busy seat with no flusher at all), a hook folds only the snapshot plus the newest bucket and increments `index_fold_truncated`, badging the seat `degraded`. Bounded, counted, and visible — not a silent read-time fallback |

| Record | Fields |
|---|---|
| `open` | `call_id`, `session_id`, `tool_name`, `harness_call_ref`, `started_at`, `is_dispatch` (`tool_name ∈ {"Agent","Task"}`), `agent_scope_id`, `child_agent_id` (null until bound) |
| `close` | `call_id`, `closed_at`, `close_source` |
| `bind` | `call_id`, `child_agent_id` — written by `SubagentStart` ([§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call)) |
| `tombstone` | `call_id`, `closed_at` — a reaped entry, retained for late matching |
| `attention_open` | `request_id`, `session_id`, `opened_at`, `call_id` — an open attention request ([§ 6.12](#612-attentionrequest)) |
| `attention_close` | `request_id`, `closed_at`, `resolution` |

**`agent_scope_id` and `child_agent_id` are two different facts and must never be one field.** Both
are `agent_id` values and an earlier draft carried exactly one field for both, which is how a single
name came to mean two opposite things:

| Field | What it holds | Written by | Read by |
|---|---|---|---|
| `agent_scope_id` | the payload `agent_id` **this call's own `PreToolUse` fired under** — `null` in the main agent, the subagent's own id inside a subagent | the `open` record, at `PreToolUse` | the turn reap's scope key ([§ 8.3](#83-the-reap-rules)) |
| `child_agent_id` | the `agent_id` of the subagent **this dispatch call spawned** — `null` on every non-dispatch call, and on a dispatch call until `SubagentStart` binds it | the `bind` record, at `SubagentStart` ([§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call)) | `SubagentStop`, to name the call it closes; `parent_call_id` resolution ([§ 6.5](#65-toolstart)) |

Read the parent's own dispatch call to see why one field could not carry both. It is opened in the
**main** agent, so its scope is `null`; it is bound to the **child's** id a moment later. With one
field, the bind overwrites the scope, and the parent's `Stop` reap — scoped to `"main"`, always, since
`Stop` carries no `agent_id` at all (MEASURED, [§ 6.0](#60-conventions-and-how-harness-payloads-are-read))
— then fails to select the one call it most needs to close. The dispatch call would sit open to its
**60-minute** orphan ceiling ([§ 12.5](#125-late-completions-and-orphan-timeouts)) and
[§ 8.6](#86-server-side-interpretation-of-open-call-state) would render the seat *working* for that
hour: the same false-*working* outcome that [§ 15](#15-decisions-taken-revisable-at-review) row 30
subscribes `StopFailure` to prevent, arriving through the door
[§ 8.3](#83-the-reap-rules) believes it locked. The mirror case is as bad: a call opened *inside* a
subagent had nowhere to record the scope it fired under, so the reap key for it was undefined.

The last two are in this journal for the same reason the calls are: an attention request is opened by
one hook process and resolved by a different one, the flusher needs the open set to fire the 60-minute
ceiling and to fill `open_attention` on the heartbeat, and none of those three can read each other's
memory. One index, one concurrency discipline.

The folded index holds at most **64 open entries** and **64 tombstones**; entry 65 in either set
evicts the oldest and increments `open_call_index_overflow` (64 = far above any observed concurrent
call count; a seat exceeding it has a harness anomaly worth surfacing).

**The same cap-plus-eviction applies to sessions, and it is what makes `open_sessions`' 0…16 bound
real.** The index holds at most **16 open sessions**; session 17 evicts the least-recently-active one
— reaping its open calls exactly as a `SessionEnd` would, with `abort_reason: "session_ended"` and
`close_source: "reap_session_boundary"`, and emitting its `session.end` with
`end_reason: "inferred_silence"` — and increments `open_session_index_overflow`. Without this the
bound was asserted twice as a fact with nothing enforcing it, and the seventeenth tracked session
would have made **every** heartbeat `422 invalid_event` under
[§ 12.1](#121-validation-order) step 9: the liveness backstop going permanently dark for that seat,
which is the one failure [§ 9.2](#92-why-this-is-the-structural-backstop) says the design must never
allow. Sixteen is the same judgement as the 64: far above the two or three terminals a real seat runs,
so reaching it is itself the signal. Eviction is by least-recent activity rather than oldest-opened
because a long-lived session that is still emitting is the one worth keeping.

**Tombstones exist so that a late close can still find its call.** When the reap closes an entry it
does not vanish from the index — it becomes a tombstone carrying its original `call_id` for
**15 min**, the same window as the ordinary orphan timeout
([§ 12.5](#125-late-completions-and-orphan-timeouts)), so there is no window in which the server
still holds a call open while the reporter can no longer name it. Without it, a `PostToolUse` arriving after its call was reaped would match nothing, be
*synthesized* under a **fresh** `call_id`, and the server's late-completion override — the instrument
in [§ 15](#15-decisions-taken-revisable-at-review) row 2 that is supposed to tell us the `Stop` reap
is too eager — could never fire, because the completion and the abort would name two different calls.
`late_completion` would read zero on exactly the path it exists to measure.

`PostToolUse` and `PostToolUseFailure` match, in this order, and record which one won in
`tool.end.match`:

| Order | Key | `match` value | Precision |
|---|---|---|---|
| 1 | equal `harness_call_ref` on an **open** entry | `harness_ref` | exact |
| 2 | equal `harness_call_ref` on a **tombstone** | `tombstone_ref` | exact — and by construction a *late* close, since the reap already closed this call |
| 3 | the only open call, if exactly one | `sole_open` | approximate |
| 4 | most recent open call with the same `tool_name` (LIFO) | `lifo_tool_name` | **approximate** |
| 5 | no match → synthesize the open ([§ 6.6](#66-toolend)) | `synthesized` | — |

**Both exact keys outrank both heuristics, and that ordering is deliberate.** An open entry beats a
tombstone when both match the same ref, because a live call is the better answer. But a tombstone
match beats `sole_open`, because after a reap the *only* open call is frequently a **different,
newer** call: a `Stop` reap tombstones call A, the next turn opens call B, and A's late close then
arrives. Ranking `sole_open` above the tombstone would attribute A's completion to B — closing a
running call and leaving a reaped one to look permanently aborted, which is worse than either error
alone. `sole_open` is therefore listed as approximate, not exact, and only reached when no ref
matched anything.

`tool_use_id` is documented as present on **both** `PreToolUse` and `PostToolUse`, so step 1 is
expected to win essentially always and the fleet-wide `match` distribution is a live check on that
expectation rather than an assumption: a seat whose `harness_ref` share drops is telling you the
harness contract moved.

**The `lifo_tool_name` fallback can mis-attribute**, and the bound on the damage is stated rather than
hidden: it can only swap two *concurrently open calls of the same tool in the same session*, and it
swaps their `call_id`s and durations, not their existence or their outcome. The counts of open, closed
and aborted calls stay exact, so **`D2-MUST` #1's idle rule is unaffected by a mis-match** — which is
the property that matters.

### 8.3 The reap rules

**Before emitting its own event**, a `hook` invocation reaps: any index entry that the table below
declares aborted is closed, in spool order, *ahead of* the triggering event. Every rule is scoped to
**one `session_id`** — the one the trigger belongs to, or the one it names.

| Reap trigger | What is aborted | `abort_reason` | `close_source` |
|---|---|---|---|
| `SessionEnd` with `reason == "clear"` | every open call of **that** `session_id` | `session_cleared` | `reap_session_boundary` |
| `SessionEnd` with any other reason | every open call of **that** `session_id` | `session_ended` | `reap_session_boundary` |
| `SessionStart` with `source == "clear"` | every open call of the **superseded session** ([§ 8.4](#84-detecting-a-clear-with-two-independent-signals) names it) | `session_cleared` | `reap_session_boundary` |
| `Stop` | every call still open in `(session_id, agent_scope_id ?? "main")` | `turn_boundary` | `reap_turn_boundary` |
| `StopFailure` | every call still open in `(session_id, agent_scope_id ?? "main")` | `api_error` | `reap_turn_boundary` |
| `SubagentStop` | every call still open in `(session_id, agent_scope_id)` where `agent_scope_id` equals this payload's `agent_id` — the subagent's **own inner** calls | `turn_boundary` | `reap_turn_boundary` |
| the session cap evicting a session ([§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)) | every open call of the evicted `session_id` | `session_ended` | `reap_session_boundary` |
| flusher start finding index entries older than its own start time | every such call | `reporter_restart` | `reap_reporter_restart` |

Each reap emits, in order: `tool.end(outcome:"aborted", …)` per call — plus a `subagent.stop` for any
of them with `is_dispatch: true` — then the triggering event, whose `aborted_call_ids` names them
where it has that field. The `SubagentStop` row is the one trigger that emits no `turn.end`
(a subagent finishing is not the turn ending), so the calls it reaps are named by their own
`tool.end`s and are counted into the **parent's** later `turn.end` only if they are still open when it
runs — which, having just been reaped, they are not. Each
reaped entry becomes a tombstone ([§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)).
A reap that ends a session also closes any attention request open in it, emitting
`attention.resolved(resolution: "session_ended")` after the boundary event — a *blocked* desk whose
session has ended is not blocked, and `D2-MUST` #5 needs the exit edge to say so.

**`Stop` and `StopFailure` are scoped to `(session_id, agent_scope_id ?? "main")`, not to `session_id`
alone, and the scope key is the call's own scope — never the child it spawned.** A subagent runs under
the **same `session_id` as its parent** — that is why the harness adds a separate `agent_id`, and why
[§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call) builds the whole subagent binding on it —
so `session_id` alone does not separate the two units whose lifecycles must not cross. It is MEASURED
at 2.1.240 that `Stop` does **not** fire inside a subagent
([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)): a turn dispatching one subagent produced
exactly one `Stop`. The scoping is kept anyway, because it costs one map key and it fails safe if that
ever changes. Were `Stop` to start firing per subagent against a `session_id`-only reap, every
subagent completion would abort the **parent's** in-flight calls — including the dispatch call itself
and any sibling parallel calls — and emit a `turn.end`, so a turn dispatching three subagents would
mint three mid-turn false idles on the busiest seats. That is the defect
[§ 8.1](#81-the-problem-restated) is written about, arriving through the door this section locks.
For the same reason `turn.end` is emitted **only** when the triggering payload carries no `agent_id`:
a subagent finishing is not the turn ending, so the `SubagentStop` row above emits `tool.end`s (and a
`subagent.stop`) and **no `turn.end`**.

**Why `SubagentStop` reaps at all, which an earlier draft left to the orphan timeout by accident.**
`Stop` does not fire inside a subagent, so nothing else in this table ever selects a subagent's own
inner calls: their `agent_scope_id` is the subagent's id, which no `Stop` and no `StopFailure` can
carry. Without this row a call left open inside a subagent — most importantly a call **refused by the
permission layer**, which fires `PreToolUse` and then no close hook of any kind (MEASURED,
[§ 6.0](#60-conventions-and-how-harness-payloads-are-read)) — had no close rule at all before its
session ended, and the three places this document promises that such a call "survives to the turn's
reap" ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read),
[§ 6.6](#66-toolend), [AT-20](#at-20-blocked-has-an-exit)) were false inside a subagent. A subagent's
completion **is** a turn boundary — of the subagent's turn — so the row reuses `turn_boundary` and
`reap_turn_boundary` rather than minting new members. That reuse is deliberate and worth its own
sentence: a new `abort_reason` or `close_source` member is a **rule-4 change**, a schema bump plus a
stated window ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)), and no fact here needs one
— the boundary being reaped at is genuinely a turn boundary, and `close_source` already says which
reap ran. Ordering inside the `SubagentStop` invocation: reap the subagent's inner calls **first**,
then close the bound dispatch call ([§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call)), so
the inner calls' `tool.end`s precede the parent call's on the wire and a consumer never sees a parent
close while its children are still open.

**Every rule keys on `session_id`, and that is a correctness requirement, not tidiness.** An earlier
draft reaped on "any hook carrying a `session_id` different from the index's current one". Two
terminals on one seat is an ordinary state, so each session's hooks would have aborted the other
session's healthy calls, both would have carried non-empty `aborted_call_ids` on every turn, and
`D2-MUST` #1 would have made *idle* unreachable on both — a design that renders two working desks as
permanently `unknown` and mints a `session.end` storm while doing it. A session now ends only when
the harness says it ended, which is what `SessionEnd` is for.

**Why `Stop` reaps.** When the main agent's turn ends, no tool call of that session can still be
legitimately running: the turn's completion is downstream of every call it made. A call open at `Stop`
is therefore either killed or lost, and both are correctly *not* completions. The one case this could
get wrong — a close that arrives *after* the reap — is handled by the tombstone
([§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)) plus the
late-completion rule in [§ 12.5](#125-late-completions-and-orphan-timeouts): **completion is an
observation and abort is an inference, so an observation always overrides.**

### 8.4 Detecting a `/clear` with two independent signals

A `/clear` announces itself twice, and the measurement says exactly how. **MEASURED at 2.1.240**
([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)): a `/clear` fired
`SessionEnd(reason: "clear")` on the outgoing session `d867abf5…`, then
`SessionStart(source: "clear")` under a **new** `session_id` `d8f4ac95…`, **144 ms later**. Three
facts fall out of that trace, and the design rests on them rather than on an assumption about hook
order:

1. **`SessionEnd(clear)` names the session to reap outright** — it arrives *on* the outgoing
   `session_id`. It needs nothing from the payload beyond `reason`, and it is the primary signal.
2. **`SessionStart(clear)` cannot name it, and no payload key exists that would.** Its own
   `session_id` is the *new* session, and there is no `previous_session_id` field on this build
   ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read), MEASURED). An earlier draft keyed the
   second reap on exactly that field and would have reaped nothing, forever.
3. `SessionEnd` arrived first in the one ordering captured, by 144 ms. **One capture is not an
   ordering guarantee**, so both still reap and the reap is still idempotent.

**The second signal keys on the reporter's own index, not on the payload.** The reporter already
holds the seat's live-session set ([§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)),
so `SessionStart(source == "clear")` reaps **the seat's most-recently-active open session other than
its own** — the session a `/clear` necessarily superseded, since a clear replaces the session it ran
in. That session's id becomes the `session.start.previous_session_id`
([§ 6.1](#61-sessionstart)). If the index holds no other open session, the primary signal already
reaped it 144 ms earlier: the rule selects nothing, `previous_session_id` is `null`, and
`reap_noop_second_signal` is incremented — which is the **healthy** outcome, not a failure.

Note what this rule deliberately does *not* do: it does not reap *every* other session. A seat with
two terminals open has two legitimately-live sessions, and clearing one must not abort the other's
calls — [§ 8.3](#83-the-reap-rules) explains what that rule did to an earlier draft.
Most-recently-active picks exactly one, and picks the right one in the only case that matters, since
the cleared session was active microseconds ago by construction.

| Signal | Counter | If it stops firing |
|---|---|---|
| `SessionEnd(reason == "clear")` | `predicates.clear_reap_by_session_end`, `true` branch | the `SessionStart` signal still reaps, so nothing visibly breaks — the branches diverge and the divergence criterion in [§ 9.4](#94-the-predicate-constant-alarm) is what says so |
| `SessionStart(source == "clear")` | `predicates.clear_reap_by_session_end`, `false` branch | the `SessionEnd` signal still reaps; same divergence criterion |

**The predicate is one classification with two branches, which is what [§ 3.4](#34-why-identity-never-comes-from-the-environment)
rule 2 asks for.** It is evaluated once per `/clear`, at the moment the reap runs, and its branches
answer *"which of the two signals got here first for this clear"* — `true` for `SessionEnd`, `false`
for `SessionStart`. An earlier draft counted the two *events* instead, which is not a predicate at
all: it pairs two different observations, so `[§ 9.4](#94-the-predicate-constant-alarm)`'s
"both branch counts" framing did not describe it, and its criterion was a divergence check dressed up
as a constant check. As one predicate over one event, both branch counts are genuine and healthy
operation puts every `/clear` in exactly one of them.

`reap_noop_second_signal` counts the second of the pair finding nothing left to reap, which in healthy
operation happens on **every** `/clear`: it is the positive evidence that both signals are alive.
A sustained gap between the branches means one of them has stopped working while the pipeline still
appears to function — the shape of the 30-day outage in
[§ 3.4](#34-why-identity-never-comes-from-the-environment), instrumented so it takes minutes rather
than a month to see.

Note what is *no longer* a `/clear` signal: a hook arriving with an unfamiliar `session_id`. It was
one in an earlier draft, and [§ 8.3](#83-the-reap-rules) explains what two concurrent sessions did to
it.

### 8.5 Subagent identity — binding `agent_id` to a call

`agent_id` and `agent_type` are common input fields present **only inside a subagent**, and
`SubagentStart` and `SubagentStop` both carry `agent_id` — all MEASURED at 2.1.240
([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)). That is the join the subagent lifecycle
is built on.

**The binding, at spawn.** `SubagentStart` fires inside the new subagent carrying its `agent_id`. The
reporter writes a `bind` record setting an open dispatch call's `child_agent_id` to that `agent_id`,
choosing by the first of these that applies:

| Situation | Binding | Counter |
|---|---|---|
| exactly one open dispatch call in that session has no `child_agent_id` yet | that call | `agent_bind_sole_unbound` |
| two or more unbound open dispatch calls | **none** — no guess is written | `agent_bind_ambiguous` |
| no `SubagentStart` payload at all (the hook did not fire, or carried no `agent_id`) | none | `payload_key_missing.agent_id` |

**There is no exact-reference binding row, because the payload carries no reference to bind on.**
`SubagentStart`'s complete key set at 2.1.240 is
`{session_id, transcript_path, cwd, prompt_id, agent_id, agent_type, hook_event_name}` — no
`tool_use_id`, no `parent_tool_use_id` (MEASURED). An earlier draft led this table with an exact-match
row keyed on those two fields and marked its reachability "unverified"; it is not unverified, it is
**unreachable**, and an implementer would have built a branch that can never execute while
`agent_bind_ref` — described as "the measurement that says which binding rule is carrying the fleet" —
reported a structural zero forever. The row is deleted rather than kept-and-hedged, and
`agent_bind_ref` is deleted with it. `prompt_id` is on both payloads but does **not** discriminate:
parent and subagent share it (MEASURED), which is what makes `agent_id` the only usable key.

The sole-unbound rule therefore carries the fleet, and it binds correctly for every dispatch that is
not *simultaneous*. `agent_bind_ambiguous` measures how often that happens, and a fleet where it is
non-trivial is the trigger to revisit this — with a real capture of a parallel dispatch, which is a
measurement nobody has taken.

**The use, at stop.** `SubagentStop` carries `agent_id` (MEASURED), and the reporter uses it for two
different lookups in one invocation — first as a **scope** key, then as a **binding** key. Both are
named here because they read the same payload value against two different index fields
([§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)):

1. **Scope — reap the subagent's own inner calls.** Every open call whose `agent_scope_id` equals this
   `agent_id` is reaped, `turn_boundary` / `reap_turn_boundary`
   ([§ 8.3](#83-the-reap-rules)). This runs first, and it is the only rule in the design that ever
   closes a call opened inside a subagent, because `Stop` does not fire there (MEASURED).
2. **Binding — close the dispatch call this subagent belongs to**, matching this `agent_id` against
   `child_agent_id`:

- payload carries an `agent_id` **bound** to an open call → close that call, `close_source:
  "subagent_stop_hook"`, `match: "agent_id"`. Parallel dispatches are handled correctly, which is the
  whole point of the binding;
- `agent_id` present but **unbound**, and **exactly one** open `is_dispatch` call → close it, `match:
  "sole_open"`. This is not a defence against a missing `agent_id` — the key is always there. It is
  the recovery path for a **lost `bind` record**: a torn index-journal line drops the binding while
  leaving the call open ([§ 11.4](#114-corruption-the-torn-last-line-and-a-lost-statejson)), and
  without this row that call would sit open to its 60-minute orphan ceiling;
- otherwise → emit nothing, increment `subagent_stop_unmatched`.

`SubagentStop` and the dispatch call's own `PostToolUse` are two independent signals for one
transition, in the same spirit as the two `/clear` signals — and at 2.1.240 `SubagentStop` arrives
**first** ([§ 6.8](#68-subagentstop), MEASURED), so it is usually the one that closes the call.
Whichever is second finds the call already closed and emits nothing. Using an unidentifiable signal to
close an arbitrary one of several candidates would be a guess with no observable, which is the failure
mode this whole section exists to remove.

**What the binding buys downstream.** Every hook firing inside a bound subagent carries that
`agent_id`; the reporter records it on each such call's `open` record as its `agent_scope_id`, and
resolves it through `child_agent_id` to stamp the parent Task call's `call_id` onto those events as
`parent_call_id` ([§ 6.5](#65-toolstart)). One payload value, two index fields, two jobs — the scope
the call ran under, and the parent it belongs to. The floor can therefore attribute an intern's tool calls to
the right intern without the harness's opaque `agent_id` ever transiting. Where the binding failed,
`parent_call_id` is `null` and `agent_bind_unresolved` counts it — an honest unknown, never a guess.

### 8.6 Server-side interpretation of open-call state

The ingest maintains a per-seat **call ledger** derived from the stream. Its rules:

| Situation | Server behaviour |
|---|---|
| `tool.start` for a new `call_id` | open the call, record `started_at` = `event_time` |
| `tool.start` for a `call_id` already known | ignore, count `duplicate_open` (a dedup escape or a replay) |
| `tool.end` for an open call | close with the stated `outcome` |
| `tool.end` with `match: "tombstone_ref"` for a call already closed `aborted` | **override** to the stated `outcome`, count `late_completion` ([§ 12.5](#125-late-completions-and-orphan-timeouts)) |
| `tool.end` arriving **before** its `tool.start` (out-of-order batches) | create the entry already closed; a later `tool.start` for it **does not reopen it**, and counts `late_open` |
| a call open past its **orphan timeout** ([§ 12.5](#125-late-completions-and-orphan-timeouts)) | close it `aborted` / `orphan_timeout`, server-side only — **no wire event is synthesized**, because the wire is what a seat said and the server must not put words in a seat's mouth |
| `turn.end` with `aborted_call_ids` non-empty | the turn is **not** a clean boundary; `D2-MUST` #1 forbids an idle transition |
| `turn.end` with `end_reason: "api_error"` | the seat renders **`stalled`**, carrying `api_error_type`; never `idle`, and distinct from `unknown`. `stalled` is **bounded** — it ends on the session's next `turn.start`, on that session's `session.end`, or on the seat leaving live state ([§ 6.4](#64-turnend)) |
| `turn.end` whose calls all closed `completed` / `failed` | an ordinary finish; `failed` never blocks *idle* ([§ 6.4](#64-turnend)) |
| a seat with any open call | the seat is **working**, regardless of turn state |

**This ledger is seat-scoped and models no agent scope, deliberately.** `agent_scope_id` and
`child_agent_id` are reporter-side index fields
([§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)) and neither
transits; the wire carries `agent_scope` and `parent_call_id` as labels
([§ 6.5](#65-toolstart)) and the server never reaps on them. That division is load-bearing for the
last row: a call open inside a subagent renders the seat *working*, correctly, and what bounds it is
the **reporter's** reap running at `SubagentStop` ([§ 8.3](#83-the-reap-rules)) — not a server rule.
Before that reap row existed, such a call had no reporter-side close at all and this row rendered a
finished seat *working* until the 60-minute orphan timeout ([§ 12.5](#125-late-completions-and-orphan-timeouts)),
which is the failure the row looks innocent enough to hide.

### 8.7 Worked flow — a `/clear` during a subagent's `Bash` call

The acceptance-test scenario ([AT-1](#at-1-kill-vs-complete-the-headline-test)), event by event.
`T` is the seat clock. This trace shows `SessionEnd` arriving before `SessionStart`; the reverse order
is equally valid and is covered below.

| # | Time | Kind | Key data |
|---|---|---|---|
| 1 | `T+00.0s` | `tool.start` | `call_id: A`, `tool_name: "Agent"`, `descriptor: "Agent: probe the ingest"` |
| 2 | `T+00.0s` | `subagent.spawn` | `call_id: A`, `title: "probe the ingest"`, `subagent_type: "coder"` — the `PreToolUse` payload's `tool_name` is `"Agent"` at 2.1.240 ([§ 6.7](#67-subagentspawn)) |
| — | `T+00.4s` | *(`SubagentStart`: sets call `A`'s `child_agent_id`; call `A`'s own `agent_scope_id` stays `null` — it was opened in the main agent. No event)* | |
| 3 | `T+03.2s` | `tool.start` | `call_id: B`, `tool_name: "Bash"`, `descriptor: "Bash: sleep 120"`, `agent_scope: "subagent"`, `parent_call_id: A`, `open_calls_before: 1` |
| — | `T+18.6s` | *(operator types `/clear`; the harness SIGKILLs call `B`; **no `PostToolUse` and no `PostToolUseFailure` ever fire**)* | |
| 4 | `T+18.7s` | `tool.end` | `call_id: B`, `outcome: "aborted"`, `abort_reason: "session_cleared"`, `close_source: "reap_session_boundary"`, `match: "reap"` |
| 5 | `T+18.7s` | `tool.end` | `call_id: A`, `outcome: "aborted"`, `abort_reason: "session_cleared"`, `close_source: "reap_session_boundary"` |
| 6 | `T+18.7s` | `subagent.stop` | `call_id: A`, `outcome: "aborted"`, `abort_reason: "session_cleared"` |
| 7 | `T+18.7s` | `turn.end` | `end_reason: "session_cleared"`, `open_calls_at_end: 2`, `aborted_call_ids: [B, A]` |
| 8 | `T+18.7s` | `session.end` | `end_reason: "clear"`, `aborted_calls: 2` |
| 9 | `T+18.8s` | `session.start` | `source: "clear"`, under a **new** `session_id`; `previous_session_id: <old>` from the reporter's own index ([§ 8.4](#84-detecting-a-clear-with-two-independent-signals)), **not** from the payload. Its reap finds nothing open and increments `reap_noop_second_signal` |

Events 4–8 are all produced by the **single `SessionEnd` hook invocation** at `T+18.7s`: reap first,
then the boundary events, then the trigger's own event — one process, one spool append per event, in
that order. Both calls are tombstoned for 15 min, so a `PostToolUse` that somehow arrived late would
close call `B` under its own `call_id` with `match: "tombstone_ref"` rather than inventing a new one.

**If `SessionStart` arrives first instead**, it selects the seat's most-recently-active other open
session ([§ 8.4](#84-detecting-a-clear-with-two-independent-signals)) — which is the old session,
active milliseconds earlier — reaps its calls with the identical `abort_reason: "session_cleared"`,
emits events 4–8 itself, and the later `SessionEnd` is the one that finds nothing and counts
`reap_noop_second_signal`. The wire is the same either way; that is what "two independent signals,
either suffices" buys, and AT-1 accepts both orders while asserting that exactly one reap happened.
*(The order MEASURED at 2.1.240 is the one traced above — `SessionEnd` 144 ms before `SessionStart` —
but one capture is not a guarantee, so both paths are specified and both are tested.)*

The server sees two aborted calls and a `turn.end` that fails `D2-MUST` #1, so **no idle transition is
minted**; the desk goes from *working* to *unknown* and back to *working* when the next turn starts.
That is the entire requirement, made checkable at the wire.

---

## 9. Liveness: heartbeat, staleness and the predicate alarm

### 9.1 The cadence and the alarm

| Signal | Value | Derivation |
|---|---|---|
| Heartbeat interval | **60 s** | one per minute per seat = 1,440 events/seat/day ≈ 14 % of the ceiling volume in [§ 6.0](#60-conventions-and-how-harness-payloads-are-read) — the cheapest continuous liveness assertion that still bounds detection latency to minutes. Matches the `context.sample` cadence so a seat's two periodic signals stay in step |
| Flush interval | **10 s** | a desk that reacts within 10 s reads as live to a human; it bounds request rate at 6/min/seat |
| Seat `stale` threshold | **300 s** since the last received event | a healthy seat's newest event is ≤ 70 s old at the server (60 s heartbeat + 10 s flush), so 300 s is ~4× the healthy worst case and cannot fire on a working seat |
| Seat `offline` threshold | **900 s** | 3× the stale threshold: long enough that "stale" is a distinct, investigable state rather than a flicker on the way to offline |

**What the 120 s backoff ceiling actually buys — stated correctly, because an earlier draft
overstated it.** It is *not* true that a network outage which recovers never trips `stale`. Do the
arithmetic: at the moment an outage starts, the server's newest event from that seat is already up to
70 s old; the outage adds its own duration `D`; and after the server comes back the seat waits up to
120 s of backoff before its next attempt. Total silence at the server is therefore up to
`70 + D + 120` seconds, which crosses 300 s once `D` exceeds **~110 s** in the worst case (~170 s at
the mean of full jitter). A 240 s outage plus a 120 s backoff wait is 430 s of silence and the seat
**correctly** renders `stale` — 300 s means "nothing has arrived for five minutes", and nothing had.
What the 120 s cap really bounds is the *recovery* side: a seat cannot stay stale for more than one
backoff interval plus one flush after connectivity returns, so an outage that ends is visibly over
within ~130 s rather than after an unbounded exponential climb.

**Rendering (constraining D2/D3, deliberately).** `stale` and `offline` are **visibly degraded**
states — a distinct rendered desk, per
[`docs/VERSIONING.md § The failure direction must be safe`](../VERSIONING.md#the-failure-direction-must-be-safe--reject-loudly-never-drop-quietly).
Neither may render as *idle*. An empty floor and a broken floor must never look alike, because
"quiet" is exactly what this product renders when the fleet is calm, so nobody investigates it.

**A draining seat is not a stale seat.** After a long outage the flusher sends oldest-first, so
`received_at` is fresh (no staleness) while the *content* is hours old. `spool_lag_events` and
`oldest_unsent_age_s` ride every heartbeat for exactly this case, and D2 renders a seat with
`oldest_unsent_age_s > 300` as *catching up* rather than as current — the arithmetic of how long that
lasts is in [§ 11.5](#115-retry-and-backoff).

### 9.2 Why this is the structural backstop

The [§ 3.4](#34-why-identity-never-comes-from-the-environment) incident's cost was not a wrong
predicate — predicates break routinely. Its cost was that a dark consumer and a healthy one were
indistinguishable from outside for 30 days. The heartbeat inverts that: liveness is **asserted
continuously by the producer**, so silence becomes a positive observation the server can alarm on. No
reporter bug, and no harness change, can make a seat *quietly* disappear — the worst it can do is make
a seat visibly stale. That property is worth more than any individual event kind in this document.

### 9.3 Degradation counters

Every counter below is monotonic since flusher start, rides in `reporter.heartbeat.counters`, and has
a *named* consequence. A counter without a consequence is decoration. Counters incremented in hook and
statusLine processes reach the flusher through the counter sink
([§ 11.1](#111-layout)); the flusher folds them, and folds `predicates` the same way.

| Counter | Meaning | Consequence when non-zero |
|---|---|---|
| `spool_dropped_events` | overflow or residency-cap discarded events | seat badge `lossy`; the number is rendered |
| `spool_overflow_deferred` | a hook found `spool_bytes` over the bound but the only over-bound bucket was the **current** one, which it may never drop ([§ 11.3](#113-rotation-and-the-overflow-policy)) | informational; the flusher drops it after the hour rolls. Sustained non-zero means a seat is producing > 32 MiB/hour and the bound needs re-deriving |
| `spool_corrupt_lines` | unparseable spool lines quarantined | seat badge `lossy` |
| `events_rejected_dropped` | events lost with a permanently-rejected batch — incremented by that batch's event count at quarantine time | seat badge `lossy`; this is the counter that makes `§ 0` item 9's promise true for the rejection path |
| `oversize_event_dropped` | a single event over the 4 KiB cap, undeliverable, quarantined | seat badge `lossy` |
| `batches_rejected` | permanent-status rejections | seat badge `degraded`; the last status and error code are shown |
| `hook_name_mismatch` | `argv[2]` ≠ `hook_event_name` | `degraded`; the harness contract moved |
| `payload_key_missing.<key>` | an expected harness key was absent | `degraded` when > 0 for a key marked required in [§ 6](#6-event-kinds) |
| `enum_value_unknown.<wire field>` | a closed-enum field carried a value this reporter does not know, coerced per [§ 6.0](#60-conventions-and-how-harness-payloads-are-read) rule 4 | informational, rendered `reporter_behind` — the harness has added a member and this document owes an edit |
| `invalid_tool_name` | `tool_name` failed its pattern and was sent as `INVALID_TOOL_NAME` | `degraded` |
| `open_call_index_overflow` | > 64 concurrent open calls or tombstones | `degraded` |
| `open_session_index_overflow` | > 16 concurrent open sessions; the least-recently-active was evicted and reaped ([§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)) | `degraded` |
| `value_clamped.<wire field>` | a numeric value or array exceeded its stated bound and was clamped ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read) rule 5) | `degraded` — a clamp means the reporter's own arithmetic left its declared range |
| `data_truncated.<wire field>` | an object exceeded its stated serialized cap and was reduced by that field's stated rule | `degraded`; for `counters` the count rides the event as `counters_omitted` |
| `notification_not_attention.<type>` | a `Notification` whose `notification_type` is not an attention type, so no `attention.request` was emitted ([§ 6.12](#612-attentionrequest)) | informational, and the record that the one emission gate in the design is never silent. Also the measurement of which types a real fleet produces |
| `dispatch_tool_name.<name>` | which `tool_name` the subagent-dispatch hook actually carried — `Agent` at 2.1.240 ([§ 6.7](#67-subagentspawn)) | informational; a change in the distribution means the harness renamed the tool and this document owes an edit |
| `payload_key_missing.is_interrupt` | `PostToolUseFailure` arrived without `is_interrupt`, so the close defaulted to `failed` ([§ 6.6](#66-toolend)) | `degraded`; an interrupted call would be mislabelled as a failure while this is non-zero |
| `index_fold_truncated` | the index journal tail exceeded 8 MiB and history was skipped | `degraded` |
| `flusher_lost_ownership` | a flusher found `state.json` owned by another and exited | informational; > 1/day means the lock is being lost, not just raced |
| `state_reset` | `state.json` was unreadable; a new epoch was minted and the spool re-sent from its oldest bucket ([§ 11.4](#114-corruption-the-torn-last-line-and-a-lost-statejson)) | informational, rendered `epoch_reset`; **not** `lossy`, because nothing was discarded |
| `subagent_stop_unmatched` | `SubagentStop` that could name no call | informational; expected ~0 once the `agent_id` binding works, so a rising share is the signal that it does not |
| `agent_bind_sole_unbound` | a `SubagentStart` bound by being the only unbound dispatch call — the **only** binding rule, since the payload carries no parent reference ([§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call)) | informational; its share of `SubagentStart`s is the binding's success rate |
| `agent_bind_ambiguous` / `agent_bind_unresolved` | two unbound dispatch calls at `SubagentStart`; a subagent hook whose parent could not be resolved | informational; `parent_call_id` is `null` for those events. `agent_bind_ambiguous` is the trigger to revisit [§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call) with a real parallel-dispatch capture |
| `attention_request_duplicate` | a second attention request while one was open | informational; also the measurement of whether `PermissionRequest` and `Notification` overlap |
| `context_sample_stale` | a compaction event found no statusLine sample newer than 300 s | informational; > 0 on every seat without the statusLine integration installed |
| `clear_second_signal_found_nothing` | a `SessionStart(source=clear)` whose index held no other open session to reap ([§ 8.4](#84-detecting-a-clear-with-two-independent-signals)) | informational, and **expected on every `/clear` where `SessionEnd` ran first** — which at 2.1.240 is every one measured. It is `reap_noop_second_signal` seen from the selection side, and the two disagreeing means the second signal selected the *wrong* session |
| `reap_noop_second_signal` | the second `/clear` signal found nothing to reap | informational, and **expected on every `/clear`** — a zero here on a seat that has cleared is the alarm ([§ 8.4](#84-detecting-a-clear-with-two-independent-signals)) |
| `tombstone_late_close` | a close matched a tombstone — the reap was too eager for that call | informational; the reporter-side half of `late_completion` |
| `compaction_double_close` | both `PostCompact` and `SessionStart(compact)` closed one compaction | informational; a zero means one of the two signals is dead |
| `bad_session_id` | `session_id` failed its pattern and was sent as `null` ([§ 3.2](#32-session-identity)) | `degraded` |
| `config_invalid` | the config failed validation at runtime (e.g. a non-`https` `ingest_url`) | `degraded`; the flusher keeps spooling and sends nothing |
| `statusline_suppressed` | sampling suppressions | informational; a *zero* here on an active seat means sampling is broken |
| `negative_duration` | clock stepped mid-call | informational |
| `wrapped_statusline_failures` | the wrapped status-line command failed | `degraded`; the seat's own UI is affected |
| `quarantine_corrupt_dropped` / `quarantine_rejected_dropped` | a quarantine file hit its cap and stopped accepting writes ([§ 11.1](#111-layout)) | informational; the loss they describe is already counted by `spool_corrupt_lines` / `events_rejected_dropped` |
| `sanitizer_redactions` / `sanitizer_truncations` | redaction and truncation activity | informational; an implausible rate is worth a look either way |

#### `reporter.heartbeat.degraded` — the member set, defined here and nowhere else

`data.degraded` ([§ 6.14](#614-reporterheartbeat)) carries the enum names of the conditions currently
active, so a consumer never has to re-derive the badge from the raw counters above. **This table is
the field's value set.** It exists because an earlier draft typed the field `array<enum>`, pointed at
this section for its members, and this section had no such list — the "Consequence when non-zero"
column mixes at least seven different vocabularies and none of them was declared to be the enum. A
wire field whose members are stated nowhere is not implementable: the ingest validates per-kind `data`
([§ 12.1](#121-validation-order) step 10), so a builder either cannot validate the field or validates
it against a guessed set, and a guess that misses makes a *degraded* seat's heartbeat a
`422 invalid_event` — a rejected batch ([§ 12.4](#124-batches-are-atomic)), then a permanent
quarantine ([§ 11.5](#115-retry-and-backoff)). That is the liveness backstop dying at the moment the
seat becomes interesting, which is the one thing [§ 9.2](#92-why-this-is-the-structural-backstop) says
it may never do and [AT-22](#at-22-a-maximally-degraded-seat-still-heartbeats) exists to forbid.

A member is present on a heartbeat when **any** counter in its "Raised by" cell is non-zero at that
flush. The reporter mints them from the counter sink; nothing here is read from a payload, so this is
a **reporter-minted** enum with no unknown member and adding a member is a rule-4 change
([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)).

**Read the counter table's "Consequence when non-zero" column as severity prose, not as a second
declaration of this set.** Where it says `degraded`, the table below says *which* member; where it
says `lossy`, `reporter_behind` or `epoch_reset` it is naming a member here directly; where it says
`informational` the counter raises no member at all. The **"Raised by" column below is the mapping**,
and it is the only one — two columns declaring the same set is how this field ended up with its
members stated nowhere and its two examples spelling one member two different ways.

| Member | Raised by | What a consumer should render |
|---|---|---|
| `lossy` | `spool_dropped_events`, `spool_corrupt_lines`, `events_rejected_dropped`, `oversize_event_dropped` | events were discarded and counted; the number is rendered |
| `batches_rejected` | `batches_rejected` | a batch took a permanent status and was quarantined; the last status and error code are shown |
| `harness_contract_moved` | `hook_name_mismatch`, `payload_key_missing.<key>` for a key [§ 6](#6-event-kinds) marks required, `payload_key_missing.is_interrupt` | the harness payload has moved under this reporter; this document owes a re-capture |
| `reporter_behind` | `enum_value_unknown.<wire field>` | the harness has added an enum member this reporter coerces; informational, and the trigger for an edit |
| `value_clamped` | `value_clamped.<wire field>` | the reporter's own arithmetic left a declared range and was clamped |
| `counters_omitted` | `data_truncated.counters`, i.e. any flush where `counters_omitted > 0` | too many kinds of trouble to fit 1.5 KiB; the count rides the event as `counters_omitted` |
| `index_overflow` | `open_call_index_overflow`, `open_session_index_overflow`, `index_fold_truncated` | a seat past the index caps; a harness anomaly worth surfacing |
| `invalid_tool_name` | `invalid_tool_name` | a `tool_name` failed its pattern and was sent as `INVALID_TOOL_NAME` |
| `bad_session_id` | `bad_session_id` | a `session_id` failed its pattern and was sent as `null` |
| `config_invalid` | `config_invalid` | the config failed runtime validation; the flusher spools and sends nothing |
| `statusline_degraded` | `wrapped_statusline_failures` | the wrapped status-line command is failing; the seat's own UI is affected |
| `epoch_reset` | `state_reset` | a new `seq_epoch` was minted and the spool re-sent; **not** `lossy` — nothing was discarded |

**Twelve members, and the array's bound is twelve.** The bound is not a chosen number: the array
carries at most one of each member, so its ceiling is the size of this table and moves only when this
table moves. That is the same discipline [§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)
applies to `open_sessions` — a bound with a mechanism behind it rather than an assertion.

**Two things deliberately absent.** `reporter_ahead`, `clock_skew` and `seq_gap` appear in
[§ 12.7](#127-server-side-counters) as badges the **server** derives; they are not members here,
because this array is what the *reporter* knows about itself and a reporter cannot observe its own
skew or its own gaps. And `disabled` is not a member: a seat with `enabled: false` is not degraded, it
is off, and [§ 6.14](#614-reporterheartbeat)'s `enabled` field is what distinguishes off from broken.
The `informational` rows above with no member — `statusline_suppressed`, `reap_noop_second_signal`,
the `agent_bind_*` family and the rest — are counters a drill-down reads, deliberately not badges: a
badge for every counter is a floor of permanently-yellow desks, which renders the badge meaningless.

**One caveat that must not be discovered later: statusLine-side counters are a floor, not a census.**
The harness cancels an in-flight status-line script when a new render is triggered, and a cancelled
process never reaches the exit path where it writes its counter line. `statusline_suppressed` and
`wrapped_statusline_failures` therefore under-count by construction, and the design leans on that
being *bounded* rather than corrected: the reporter's own 1 s wrapped-command timeout
([§ 6.11](#611-contextsample)) sits below the harness's cancellation, so the common failure is still
counted, and the counters are used for *direction* (zero vs non-zero, rising vs flat) rather than for
arithmetic. Hook processes are not cancelled — they run to completion inside the 250 ms budget — so
hook counters are exact.

### 9.4 The predicate-constant alarm

Every classifying predicate in the reporter reports both branch counts in
`reporter.heartbeat.predicates`, and the server alarms — `predicate_constant`, surfaced per seat —
when a predicate stops discriminating.

**The criterion is per predicate, because a threshold above a predicate's own evaluation rate is an
alarm that can never fire, which is a decoration rather than a check.** Two of these predicates are
evaluated thousands of times a day and three are evaluated tens of times a day
([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)'s volume column is where that comes from),
so one threshold cannot serve both.

**Every predicate here is one classification with two branches of the same evaluation.** That is a
requirement, not a description: a "predicate" that pairs the counts of two *different* events cannot
have a constant branch in the sense [§ 3.4](#34-why-identity-never-comes-from-the-environment) rule 2
means, so a constancy criterion over it is measuring something other than what it claims. An earlier
draft's `session_boundary_detected` was exactly that — `SessionEnd(clear)` counted against
`SessionStart(source=clear)`, two events, with a divergence test standing in for a constancy test —
and it is replaced above by `clear_reap_by_session_end`, one evaluation per `/clear` asking which
signal arrived first. Adding a predicate that pairs two events is a review-blocking defect for the
same reason adding one with an unreachable threshold is.

| Predicate | Branches | Volume | Alarm criterion | What it means when it fires |
|---|---|---|---|---|
| `descriptor_allowlisted` | tool on the allowlist / not | ~1,000–3,000/day | **0 % or 100 % across ≥ 500 evaluations in a rolling 24 h** | constant-false: the allowlist no longer matches any tool name the harness sends |
| `agent_scope_subagent` | `agent_id` present / absent ([§ 6.5](#65-toolstart)) | ~1,000–3,000/day | same 500 / 24 h rule | constant-true: the harness now sends `agent_id` everywhere; constant-false: it stopped sending it |
| `clear_reap_by_session_end` | which `/clear` signal reaped first — `SessionEnd` / `SessionStart` ([§ 8.4](#84-detecting-a-clear-with-two-independent-signals)) | ~10–80/day | **0 % or 100 % across ≥ 20 evaluations in a rolling 7 days** | one of the two `/clear` signals has died; the other is still reaping, so nothing else looks broken |
| `attention_source_permission_hook` | an `attention.request` opened by `PermissionRequest` / by `Notification` ([§ 6.12](#612-attentionrequest)) | 0–50/day | **0 % or 100 % across ≥ 50 evaluations in a rolling 7 days** | constant-false: the permission hooks stopped firing and every *blocked* now comes from a notification. This is the predicate that would have seen a permission-hook death, which `attention_resolved_by_hook` cannot ([§ 6.13](#613-attentionresolved)) |
| `attention_resolved_by_hook` | resolved by an observed edge / by the 60-minute timeout ([§ 6.13](#613-attentionresolved)) | 0–50/day | **any** `false` branch in a rolling 24 h is surfaced; constant-false over ≥ 10 resolutions alarms | every *blocked* is now ending on a timer rather than on an observed edge |

**On the 500 (and the 50, and the 20).** A threshold must exceed the longest legitimate run of one
branch, and nobody has measured that on any seat yet. 500 is chosen so the high-volume alarms need
roughly a working day of evidence before speaking, which makes a false alarm cheap and a real one
still ~29× faster than the 30-day outage this exists to catch; the low-volume numbers are the same
judgement scaled to a class that produces tens of events a day rather than thousands. **The
implementer records per-seat, per-predicate evaluation counts through the first week of live running,
and the operator re-picks every one of these from that data.** What must not change under review is
that each predicate has a criterion its own volume can actually reach, that it fires visibly, and
that it is proven capable of firing ([AT-8](#at-8-predicate-constant-alarm)).

Adding a predicate to the reporter without adding it to this object **and giving it a criterion its
volume can reach** is a review-blocking defect.

---

## 10. Time, ordering and idempotency

### 10.1 Two clocks, and which is authoritative for what

Seat clocks skew. A VM resumed from suspend can be minutes out, and no design should assume NTP is
healthy on every agent machine in every install.

| Value | Source | Authoritative for |
|---|---|---|
| `event.event_time` | seat clock | ordering **within** a seat (with `seq`), durations within a seat, the seat's own narrative sequence |
| `batch.sent_at` | seat clock | skew measurement only |
| `received_at` | **server clock**, recorded at ingest | liveness and staleness, retention and expiry, **all cross-seat comparison**, and every relative age the UI renders |

Rules:

1. **The server never rewrites `event_time`.** A stored event keeps what the seat said, because that
   is the only record of what the seat believed.
2. **The UI never renders a seat-supplied timestamp as an absolute clock**, and renders age from
   `received_at`. Otherwise one skewed seat displays "last seen in 3 hours".
3. The server computes `clock_skew_ms = received_at − sent_at` per batch and stores the latest per
   seat. `|skew| > 120 s` → the seat renders a `clock_skew` badge and the number.
   **120 s derivation:** 2× the heartbeat interval, well above any NTP-managed drift (sub-second) and
   below the 300 s stale threshold, so the two alarms cannot alias into one another.
4. `event_time` values within a seat may be non-monotonic if the clock steps; ordering falls back to
   `seq`, which is monotonic by construction.

### 10.2 Ordering: `seq` and gap detection

`seq` is a per-seat integer assigned **by the flusher** at batch time, monotonically increasing within
a `seq_epoch`. Exactly one flusher runs per seat ([§ 2.3](#23-the-flusher-must-be-alive-whenever-the-seat-is)),
which is what makes a lock-free counter correct — and this is why `seq` is not assigned in the hook,
where cross-process locking would sit inside the 250 ms budget P-5 protects.

- `seq_epoch` is a ULID minted when `state.json` is created. Losing state (reinstall, wiped state dir,
  an unreadable `state.json`) mints a **new epoch**, which the server treats as an intentional
  discontinuity: logged, counted as `seq_epoch_change`, rendered per seat as `epoch_reset`, and not
  alarmed — because it is a re-numbering, not a loss ([§ 11.4](#114-corruption-the-torn-last-line-and-a-lost-statejson)
  states what happens to the events themselves, and the answer is that they are re-sent, not skipped).
  Without a new epoch, a reset counter would look like a 48,000-event gap.
- The ordering key is `(seq_epoch, seq)`. A **missing `seq`** within an epoch is a real gap — events
  lost after the flusher counted them — and the server counts `seq_gap` and renders the seat `lossy`.
- **A repeated `(seq_epoch, seq)` carrying two different `event_id`s is an ordering-key collision**,
  which `D2-MUST` #4 forbids. It is nonetheless checked rather than assumed away: the server counts
  `seq_collision` and badges the seat `degraded`. The only mechanism that could produce one is two
  flushers overlapping, which [§ 2.3](#23-the-flusher-must-be-alive-whenever-the-seat-is) makes
  impossible in the common case and detectable in the residual one.
- Events dropped by spool overflow are dropped **before** `seq` assignment, so overflow produces **no
  gap**. That loss is reported by `spool_dropped_events` instead. The two mechanisms report two
  different losses and must not be conflated: a gap means the network or the server lost something a
  seat successfully queued.

**Batches can arrive out of order** (a retried batch lands after a later one). The server must not
assume ordering anywhere:

| Out-of-order case | Required behaviour |
|---|---|
| `tool.end` before its `tool.start` | [§ 8.6](#86-server-side-interpretation-of-open-call-state) — create closed, never reopen |
| `turn.end` before `turn.start` | close the turn; `duration_ms` from the event, not from arrival times |
| any state field | last write wins by `(event_time, seq)`, **never by arrival order** |
| a batch older than the seat's newest processed `seq` | processed normally; it is history, not a conflict |

### 10.3 Idempotency and the dedup window

The flusher retries on timeout, and a timeout is *ambiguous* — the server may well have committed the
batch. Retrying is correct; the duplicates it creates must be free.

- Every event carries a globally unique `event_id` (ULID: 48-bit ms timestamp + 80 random bits; the
  collision probability within one millisecond on one seat is negligible, and ids are namespaced by
  seat regardless).
- **Server dedup:** a unique index on `(install_id, seat_id, event_id)`; insert with conflict-ignore;
  count conflicts.
- **The dedup window is 10 days.**
- Duplicates are **not** an error. The response is `202` with `"duplicates": N`. A reporter that
  re-sends after an ambiguous timeout must be able to converge without operator involvement.

> **The coupling that must not be broken:** the dedup window must **exceed the maximum spool
> residency**, and the spool's residency is capped *by time*, not left to fall out of a volume
> estimate. [§ 11.3](#113-rotation-and-the-overflow-policy) drops any bucket older than **8 days**,
> so no event can be delivered more than 8 days after it was minted, whatever the seat's event rate.
> 10 days gives that a 25 % margin. **If either number moves, both move** — a dedup window below the
> spool's reach silently re-ingests the oldest events of a long outage, which is the single most
> confusing possible corruption of a timeline.
>
> The size bound alone could not have carried this coupling, and the arithmetic is why: 32 MiB at the
> [§ 6.0](#60-conventions-and-how-harness-payloads-are-read) ceiling of ~5.2 MB/day fills in ~6.4
> days, but a **quiet** seat — one emitting little more than its 1,440 daily heartbeats — takes far
> longer. A heartbeat is not a ~500 B typical event: its `data` alone allows 1.5 KiB of counters plus
> 512 B of predicates plus 256 B of selftest, and the worked example in
> [§ 6.14](#614-reporterheartbeat) serializes to ~900 B. At ~900 B, 1,440/day is **~1.3 MB/day**, so a
> heartbeat-only seat fills 32 MiB in **~25 days** — not the 50+ an earlier draft claimed from a
> 500 B assumption, and not the ~0.6 MB/day it cited. The conclusion is unchanged and the corrected
> number makes it *stronger*, not weaker: 25 days still leaves the oldest event 15 days past a 10-day
> dedup window while it is still queued. Residency has to be bounded by age, and the age bound is the
> one the coupling is stated against.

### 10.4 Batch-level idempotency

`batch_id` is recorded per seat for **24 h**: long enough to cover the whole retry ladder, which
saturates at 120 s, plus a same-day manual replay. The 6/min flush ceiling bounds the table at
≤ 8,640 rows/seat/day, and that ceiling — not any observed figure — is what the storage is sized
against; no seat has been instrumented yet, so a real seat's number is unknown and a quiet one will
send far fewer. A repeat `batch_id` returns the previous response without re-processing — an
optimisation, not the correctness mechanism. Per-event dedup is the correctness mechanism, and it
holds even when a retry is re-batched under a fresh `batch_id`.

---

## 11. The spool and the flusher

### 11.1 Layout

```
<spool_dir>/
  2026082314.jsonl      one file per UTC hour, append-only, LF-terminated
  2026082315.jsonl
  index/
    snapshot.json       folded call index (§ 8.2) — written by the FLUSHER only
    2026082314.jsonl    call-index journal, one record per ledger mutation, any process appends
  counters/
    2026082314.jsonl    counter and predicate deltas, one line per process exit, any process appends
  sample/
    <16 hex>.json       per-session last statusLine sample — written by statusline processes
  log/
    20260823.log        local diagnostics, one file per UTC day, any process appends
  state.json            flusher-owned: seq_epoch, next_seq, cursors, folded counters, ownership
  flusher.lock          exclusive-create lock (§ 2.3)
  quarantine/corrupt.jsonl    unparseable spool lines, capped 256 KiB
  quarantine/rejected.jsonl   permanently-rejected batches, capped 1 MiB
  REJECTED.txt          human-readable marker, capped 64 KiB
```

**One discipline covers all four append-only trees.** Hour-bucketed (or day-bucketed) filenames
replace rotation; every writer does one `O_APPEND` `writeSync` of one `\n`-terminated line; nobody
ever rewrites a file another process may be writing; and the flusher — the single process that
[§ 2.3](#23-the-flusher-must-be-alive-whenever-the-seat-is) guarantees is alone — folds and deletes
superseded buckets. A rename-based rotation races with concurrent appenders and fails outright on
Windows when the file is open; deriving the filename from the clock removes the operation entirely.
A busy seat's spool hour bucket is ~430 events ≈ 215 KB, so no single file approaches a size that
would need splitting.

**Who writes what** — the writer set is part of the contract, because an earlier draft said
`state.json` was flusher-only while specifying counters that only hook processes can compute:

| File | Writers | Mechanism |
|---|---|---|
| `<hour>.jsonl` (spool) | every hook and statusline process | append-only |
| `index/<hour>.jsonl` | every hook process | append-only |
| `counters/<hour>.jsonl` | every hook and statusline process | append-only |
| `index/snapshot.json`, `state.json` | **the flusher only** | `.tmp` + rename, **unique temp name**, ownership-checked ([§ 2.3](#23-the-flusher-must-be-alive-whenever-the-seat-is)) |
| `sample/<hash>.json` | statusline processes | `.tmp` + rename, unique temp name, last writer wins |
| `log/<day>.log`, `quarantine/*`, `REJECTED.txt` | any process (log); flusher (quarantine, marker) | append-only |

**The counter sink, and why it exists.** `sanitizer_redactions`, `hook_name_mismatch`,
`payload_key_missing.*`, `statusline_suppressed`, `wrapped_statusline_failures`, every
`enum_value_unknown.*`, and all five predicate branch counts are computed in **hook and statusLine
processes** — one-shot, concurrent, and not the flusher. They cannot be written into `state.json`
without recreating exactly the lost-update defect
[§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open) removes from the
call index, and without them the heartbeat's `counters` and `predicates` objects are unbuildable —
which would make [§ 9.4](#94-the-predicate-constant-alarm)'s alarm, the structural backstop of the
whole design, something the implementer could not construct from this document.

So each process appends **one line at exit** to the current hour's counter bucket, carrying *deltas*
for that process only:

```json
{"t":"2026-08-23T14:23:09.913Z","p":"hook","c":{"sanitizer_redactions":2,"statusline_suppressed":0},
 "k":{"descriptor_allowlisted":{"true":1,"false":0}}}
```

The flusher folds them on every pass, exactly as it folds the spool: it reads each bucket up to the
**last complete line**, adds the deltas to the totals in `state.json`, and records the byte offset per
bucket so the same line is never folded twice. Counters are therefore at most one flush interval
(10 s) stale in a heartbeat.

> **The bucket-deletion precondition — one rule, applied identically to all four trees.**
> A bucket may be deleted only when **`now ≥ bucket_end + grace`**, with **`grace = 5 s`**, and only
> after it has been fully folded (or, for the spool, fully drained and acknowledged). The delete
> tolerates `ENOENT` and `EBUSY` by leaving the bucket for the next pass rather than throwing. And,
> on the writer's side: **every writer re-derives its bucket name from the clock immediately before
> the `writeSync`**, never at process entry.
>
> **Why a grace window at all — the argument this replaces bounded the wrong quantity.** An earlier
> draft argued that a bucket older than the current UTC hour "has no living writer — a hook lives
> ≤ 250 ms (P-5), four orders of magnitude under an hour — so once such a bucket is fully folded the
> flusher deletes it … no counter is ever lost to a race." That bounds a hook's **lifetime**, not its
> **straddle** of the boundary. A hook that starts at `13:59:59.900` and computes its bucket name at
> entry writes to bucket `13` at `14:00:00.050` — after the hour rolled, and the flusher's next pass
> is ≤ 10 s away. Read-to-EOF, unlink, line lost. Both halves of the fix are needed: re-deriving at
> write time means that hook writes to bucket `14` where it belongs, and the 5 s grace covers the
> residual — a process descheduled *between* deriving the name and the `writeSync`. 5 s is 20× P-5's
> 250 ms hook budget, and it costs one extra flush pass of retention on a file that is already
> written.
>
> **This is a shared primitive with four different consequences, which is why it is stated once
> here rather than four times below.** In `counters/` the lost line is a counter delta, and
> [AT-16](#at-16-the-counter-sink-survives-concurrency)'s GREEN is *exact equality* — it would have
> flaked under exactly this race and been debugged as test flakiness. In `index/` a lost `open`
> record makes its close `synthesized` and falsifies the ledger, while a lost `close` leaves a live
> entry reapable — a false `aborted` that blocks *idle*, which is the defect
> [§ 8.1](#81-the-problem-restated) is about. In the **spool** it is a lost **event**, uncounted,
> which breaks [§ 0](#0-overview) item 9's promise of a counter for every discarded event outright.
> In `log/` it is cosmetic. The one property that has to hold — *no record is ever lost to a
> deletion race* — is a property of the primitive, so it is fixed at the primitive.

The **sample store** is the one piece of cross-process state that is not a journal, because it is not
an accumulation: it is one current value per session, `{"session_id":…,"at":…,"used_pct":…,
"bucket":…}`, keyed by the first 16 hex characters of SHA-256(`session_id`) so an opaque id never
becomes a filename. Concurrent statusLine renders of the same session can race, and the race is
harmless and stated: the loser's value is overwritten, the cost is at most one extra `context.sample`,
and no counter or ledger entry depends on it. The flusher deletes sample files untouched for 24 h.

**Quarantine and log caps have stated at-cap behaviour**, because a cap without one is an unstated
default:

| File | Cap | At the cap |
|---|---|---|
| `quarantine/corrupt.jsonl` | 256 KiB | **stop writing** — the earliest evidence is the diagnostic; increment `quarantine_corrupt_dropped`. The loss itself is already counted by `spool_corrupt_lines` |
| `quarantine/rejected.jsonl` | 1 MiB | **stop writing**; increment `quarantine_rejected_dropped`. `events_rejected_dropped` still counts every event lost |
| `REJECTED.txt` | 64 KiB | **drop oldest** — the flusher rewrites it keeping the newest 64 KiB, because a human opening it wants the most recent refusal |
| `log/<day>.log` | 1 MiB per day, **2 days retained** | the day's file stops accepting writes at 1 MiB; the flusher deletes files older than 2 days. No renames, so nothing races |

### 11.2 Spool line format

A spool line is the wire event plus exactly what the wire does not carry:

```json
{"v":1,"t":"2026-08-23T14:23:09.882Z","e":{"event_id":"01K3TA4E5F6G7H8J9K0M1N2P3R","kind":"tool.start"}}
```

| Key | Why it is here and not on the wire |
|---|---|
| `v` | the `schema_version` **this line was written under**. The flusher groups contiguous same-`v` runs into batches, so a reporter upgraded mid-spool drains cleanly — old lines go out under the old version, which the ingest still accepts inside its `N`/`N-1` window. It is also stamped onto the event itself ([§ 4.3](#43-common-per-event-fields)) when the batch is built, from this value and not from the running build's |
| `t` | spool write time; the flusher uses it for `oldest_unsent_age_s` and for the residency cap without parsing the event |
| `e.seq` | absent: assigned at flush ([§ 10.2](#102-ordering-seq-and-gap-detection)) |

*(The `e` object above is abbreviated to two fields for legibility; on a real line it is the complete
event object minus `seq`.)*

**Write discipline.** One `fs.writeSync` of one `\n`-terminated buffer on a descriptor opened `'a'`,
always LF (never `os.EOL` — identical bytes on both platforms keeps fixtures identical). Concurrent
hook processes therefore interleave at line granularity rather than inside a line, under `O_APPEND` on
Linux and `FILE_APPEND_DATA` on Windows. **This is an assumption with a test, not a belief**:
[AT-10](#at-10-concurrent-append-and-index-integrity) runs 8 concurrent writers × 500 lines and
asserts 4,000 well-formed lines, and asserts the same property for the index journal, whose
correctness depends on it identically. Line size is capped at 4 KiB
([§ 4.4](#44-size-caps-and-their-derivations)) to stay under the conventional atomic-small-write floor.

### 11.3 Rotation and the overflow policy

| Bound | Value | Derivation |
|---|---|---|
| Total spool | **32 MiB** | at the [§ 6.0](#60-conventions-and-how-harness-payloads-are-read) ceiling — 10,420 events/seat/day at ~500 B ≈ **5.2 MB/day** — 32 MiB is **~6.4 days** of a busy seat (33.55 MB ÷ 5.21 MB/day). The requirement is "survives the server being down for days"; a working week of a broken deploy fits, at a disk cost nobody will notice on a developer machine |
| **Residency cap** | **8 days** | a bucket older than 8 days is dropped whatever the spool's size. This is what bounds residency on a *quiet* seat, where 32 MiB would take 50+ days to fill, and it is the bound the 10-day dedup window is coupled to ([§ 10.3](#103-idempotency-and-the-dedup-window)). It is also independently right: a nine-day-old event has no consumer left |
| Overflow unit | one whole hour bucket | O(1) `unlink`, no rewriting of a file another process is appending to |
| Overflow policy | **drop oldest** | the dashboard's value is *current* state; a week-old queued event has no consumer left. Dropping newest would discard exactly the events that still matter |
| Loss visibility | `spool_dropped_events` += the dropped file's line count; badge `lossy` | never a silent drop, per [`docs/VERSIONING.md § The failure direction must be safe`](../VERSIONING.md#the-failure-direction-must-be-safe--reject-loudly-never-drop-quietly) |

Both bounds are evaluated by the flusher on every pass, and the size bound also by any hook that finds
`spool_bytes` over it (so a seat whose flusher is dead cannot fill a disk). Granularity is coarse and
stated: one drop removes up to one hour of the oldest telemetry.

**A hook may never drop the current-hour bucket, and every drop obeys the deletion precondition in
[§ 11.1](#111-layout).** Without that restriction a single-hour burst over 32 MiB makes the oldest
bucket *also* the current one, and a hook would unlink a file other hooks are appending to and the
flusher is mid-read — losing events that were never counted, on the one path
[§ 0](#0-overview) item 9 promises is always counted. If the only bucket over the bound is the current
one, the hook drops nothing and increments `spool_overflow_deferred`; the flusher handles it on the
next pass after the hour rolls and the 5 s grace elapses. The bound is exceeded for at most one hour
plus one flush interval in that case, which is a bounded overshoot of a disk-space guard, not a loss.

**The consequence a consumer must handle:** an overflow drop can remove a `tool.start` whose
`tool.end` survives. That is the `synthesized` path in [§ 6.6](#66-toolend) — the ledger stays total,
and the anomaly is flagged rather than silently producing a negative open-call count.

### 11.4 Corruption, the torn last line, and a lost `state.json`

| Case | Rule | Observable |
|---|---|---|
| Trailing bytes with **no** final `\n` | **Not consumed.** The flusher reads only up to and including the last `\n`; a partial line is a write in progress and is picked up next pass | none — the normal case |
| A `\n`-terminated line that fails `JSON.parse` | append it to `quarantine/corrupt.jsonl`, advance the cursor past it, **continue the batch** | `spool_corrupt_lines`, badge `lossy` |
| A line that parses but fails schema validation | same as above | `spool_corrupt_lines` |
| A line longer than 4 KiB | quarantine | `spool_corrupt_lines` |
| An entire bucket file unreadable | record the filename in `quarantine/corrupt.jsonl`, skip it, continue | **`spool_dropped_events`** += the bucket's estimated line count (its byte size ÷ the ~500 B typical event size, [§ 4.4](#44-size-caps-and-their-derivations)); badge `lossy` |
| A torn or unparseable **index journal** line | skipped by the fold, counted `spool_corrupt_lines`; a lost `open` record makes its close `synthesized`, a lost `close` makes the entry reapable — both already-handled paths | `lossy` |
| `state.json` unreadable or corrupt | **state reset** — see below | `state_reset`, `seq_epoch_change` server-side, badge `epoch_reset` |

**One torn line never poisons a batch and never wedges the queue.** The failure is bounded to the
line, counted, and quarantined for inspection — never "abort the batch", which would let one bad byte
stop a seat's telemetry indefinitely.

**The state reset re-sends; it does not skip.** When `state.json` cannot be read, the flusher mints a
fresh `seq_epoch` **and sets its cursor to the start of the OLDEST bucket still on disk**, not the
newest. An earlier draft did the opposite, and the cost was severe and silent: up to a full spool of
unsent events — days of them — discarded with no counter incremented, while
[§ 0](#0-overview) item 9 promises "a counter for every discarded event" and `seq_epoch_change` is
explicitly never alarmed. A corrupt 200-byte file would have deleted a week of a seat's history
invisibly.

Re-sending is nearly free and provably safe: every event carries a globally unique `event_id`, the
server's dedup window (10 days) exceeds the spool's residency cap (8 days) **by design**
([§ 10.3](#103-idempotency-and-the-dedup-window)), so already-delivered events return in
`"duplicates"` and change nothing. The cost is one extra drain — bounded by the same arithmetic as an
outage recovery ([§ 11.5](#115-retry-and-backoff)) — and the visible signal is `state_reset` plus a
non-zero `duplicates` on the next batches. If a bucket cannot be read at all during that re-send it
follows the unreadable-bucket row above: counted into `spool_dropped_events`, badge `lossy`. Nothing
is discarded uncounted.

**One path, one counter.** An earlier draft counted the unreadable bucket into `spool_corrupt_lines`
in the table and into `spool_dropped_events` in this paragraph — two counters for one loss, so any
loss-accounting sum over [§ 9.3](#93-degradation-counters) either double-counted it or missed it.
`spool_corrupt_lines` counts **lines** the flusher read and could not use; an unreadable bucket
yields no lines at all, so it belongs to the dropped-events path, and the count is an estimate that
says so rather than a `+= unknown` that no sum can use.

`state.json` is written `.tmp` + `renameSync` (atomic-replace on both platforms) with a unique temp
name, by the flusher only, and only while it still owns the seat
([§ 2.3](#23-the-flusher-must-be-alive-whenever-the-seat-is)).

### 11.5 Retry and backoff

| Parameter | Value | Derivation |
|---|---|---|
| Flush trigger | ≥ 50 queued events **or** 10 s elapsed | 50 events ≈ 25 KB, a batch worth a WAN round-trip; 10 s bounds dashboard latency and holds request rate at ≤ 6/min/seat |
| Backoff | exponential from **2 s**, ×2, **capped at 120 s**, **full jitter** (uniform in `[0, computed]`) | 2→4→8→16 covers a ~30 s app restart within 4 attempts. The 120 s cap bounds how long a seat stays `stale` *after* connectivity returns ([§ 9.1](#91-the-cadence-and-the-alarm) does that arithmetic and states plainly that a longer outage does trip `stale`, correctly). Full jitter stops N seats re-synchronising into a thundering herd after a server restart |
| `Retry-After` on `429` | honoured, clamped to ≤ 600 s | a server's explicit instruction outranks the ladder; the clamp stops a bad header parking a seat for hours |
| Retryable | timeout, DNS/connect failure, TLS failure, `408`, `429`, all `5xx` | transient by nature |
| **Not** retryable | `400`, `401`, `403`, `413`\*, `415`, `422` | permanent: the same bytes will be refused forever, and retrying hides the error behind an infinite loop instead of surfacing it |
| Retry attempts before giving up on a batch | unbounded **while** the condition is retryable | "the server is down for days" is the required-survivable case; the spool bounds, not an attempt count, are what limit growth |

\* `413 batch_too_large` gets exactly one adaptive retry: halve the batch and resend. If a **single
event** still exceeds the limit, that event is quarantined and counted (`oversize_event_dropped`) —
it can never be delivered, so retrying it forever would block every event behind it.

**Draining a full spool takes hours, and the arithmetic belongs here rather than in a surprise.** A
32 MiB spool at ~500 B/event holds ~67,000 events. The ingest's per-seat ceiling is 20,000 events/hour
([§ 12.3](#123-rate-limits)), so a seat that filled its spool during a long outage needs **~3.4 hours**
to drain, spent alternating between accepted batches and `429`s whose `retry_after_s` it honours.
**Nothing is lost** — the spool retains everything until it is accepted, the residency cap is 8 days,
and the 10-day dedup window covers any re-send — but the seat renders *catching up* rather than
current for those hours ([§ 9.1](#91-the-cadence-and-the-alarm)), and an operator who does not know
this will read it as a stuck reporter. [AT-4](#at-4-survives-the-server-being-down-for-days) exercises
it at scale rather than leaving it as arithmetic.

**The poison-pill rule.** A batch refused with a permanent status is **never retried**. It is appended
to `quarantine/rejected.jsonl`, its cursor is advanced, `batches_rejected` and
`events_rejected_dropped` (by that batch's event count) are incremented, a line is written to
`REJECTED.txt` and the day's log, and the flusher moves to the next batch. One bad batch costs its own
events, never the stream behind it — and the events it costs are counted, because a rejected batch is
a discarded-events path like any other.

**Local surfacing of a rejection** — required by
[`docs/VERSIONING.md § The failure direction must be safe`](../VERSIONING.md#the-failure-direction-must-be-safe--reject-loudly-never-drop-quietly)
("make the reporter surface the refusal locally too… that somebody is the only person who can fix
it"): `REJECTED.txt` carries timestamp, HTTP status, the machine-readable error code and the response
body (**with any `Authorization` header value excluded — the reporter logs the request's status, never
its headers**); the log carries the same; and `degraded: ["batches_rejected"]` rides every subsequent
heartbeat that still gets through. The reporter does **not** rely on hook stderr being displayed by
the harness — hook stderr surfacing is a behaviour that varies by exit code and hook, and a surfacing
mechanism that might silently not exist is not a surfacing mechanism.

---

## 12. The server contract

### 12.1 Validation order

Cheapest and most-fatal first; **the first failure wins and nothing is ingested**.

1. `Content-Type` is `application/json` → else `415`.
2. Body ≤ 256 KiB → else `413`. When `Content-Encoding: gzip` is set, decompression is **capped at
   256 KiB and aborted past it** — an uncapped inflate is an unbounded allocation from an
   authenticated-but-compromised seat.
3. Body parses as JSON → else `400 malformed_body`.
4. `Authorization` present and resolves to an active token → else the **failed-authentication path**:
   increment `auth_failed_by_ip` for the source IP, then return `429 rate_limited` if that IP is over
   the 60/hour failed-authentication limit ([§ 12.3](#123-rate-limits)) and `401 unauthenticated`
   otherwise. **That limit is evaluated here, at step 4, and not with the others at step 5** — it is
   the one exception to the ordering and it needs to be, because a request that fails this step never
   reaches step 5. An earlier draft placed it at step 5, where the only requests that could reach it
   had already authenticated successfully, so a limit whose entire subject is *failed*
   authentications could never fire: the same defect class [§ 12.3](#123-rate-limits) records fixing
   in this limit's **key**, arriving a second time through its **placement**. A check that cannot
   fail is a decoration. What the seat sees is unchanged in the case that matters: a
   correctly-configured reporter never reaches this path at all, and one holding a dead credential
   behind a busy NAT takes a retryable `429`, backs off ([§ 11.5](#115-retry-and-backoff)), and then
   takes the `401` that quarantines its batch — the limit delays that diagnosis by one backoff ladder
   and never replaces it.
5. Rate limits ([§ 12.3](#123-rate-limits)) — every limit except the failed-authentication one, which
   step 4 owns → else `429`.
6. `schema_version` present on the batch, an integer, and in the accepted set → else
   `400 unsupported_schema_version`.
7. Batch `install_id`/`seat_id` equal the token's binding → else `403 identity_mismatch`.
8. `events` is a non-empty array of ≤ 200 elements → else `422 invalid_batch`.
9. Every event validates: common fields present and in-bounds; per-event `install_id`/`seat_id` and
   `schema_version` equal the batch's; `kind` a string matching `^[a-z]+\.[a-z_]+$`; `data` an object
   ≤ 3 KiB → else `422 invalid_event`. This step is safe to keep strict **only because the reporter
   clamps every bound before it writes** ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)
   rule 5): a conforming reporter cannot reach it, so a `422` here means a genuine reporter bug —
   which is exactly what it should mean. Without the producer-side clamp this step would convert any
   bound overrun into 200 permanently-quarantined events ([§ 12.4](#124-batches-are-atomic),
   [§ 11.5](#115-retry-and-backoff)), which is the trade [§ 12.4](#124-batches-are-atomic) says the
   design must never make by accident.
10. Per-kind `data` validation for **known** kinds. An **unknown** kind skips this step, is ignored,
    and is counted in `ignored_unknown_kinds`. Within a known kind, an unrecognised value in a
    closed-enum field is **coerced to that field's unknown member and counted** in
    `coerced_enum_values`, never rejected — the receiving half of
    [`docs/VERSIONING.md § Wire compatibility`](../VERSIONING.md#the-rules) rule 7, and the reason a
    newer reporter cannot poison an older ingest ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)
    rule 4 is the producing half).
11. Insert with per-event dedup; return `202`.

Note the ordering of 6 before 7 and 9: the version answer must be reachable even for a batch that is
wrong in other ways, because "which versions do you accept" is the question a stuck seat needs
answered. Note equally that **4 comes before all of them**, which is what makes the attribution rule
below possible.

**Attribution — every refusal is attributed to the *token*, never to the body.** The claimed
`install_id`/`seat_id` in a payload are an assertion by whoever holds the token; they are validated
for equality and are never used to name a seat. Concretely:

| Where the refusal happens | Attributed to | Rendered |
|---|---|---|
| steps 1–3, before authentication | nothing — the request has no established identity | counted globally as `unattributed_refusals`; **no seat is rendered degraded**, because no seat is known. The reporter still surfaces it locally ([§ 11.5](#115-retry-and-backoff)) |
| step 4 (`401`, and the `429` the failed-auth limit returns) | the presented token's **hash prefix**, plus the source IP the limit is keyed on | an operator-visible auth-failure count; **no seat is rendered degraded**, because a token that resolves to nothing names no seat |
| steps 5–11 | the token's bound `(install_id, seat_id)` | that seat renders degraded |

Without this rule, a `400 unsupported_schema_version` at step 6 — which happens *before* the identity
equality check at step 7 — would be counted against whatever seat the body claimed to be. Any holder
of any valid token could then post a bogus `schema_version` naming a colleague's seat and render that
desk degraded on the floor. The binding is the identity ([§ 3.3](#33-authentication-and-the-identity-binding-rule));
the body is a claim.

### 12.2 Error responses

Every error body has the same shape — `{"error": <code>, "message": <human string>, …context}` — so a
reporter can branch on `error` and a human can read `message`.

| Condition | Status | `error` | Extra body keys | Reporter action |
|---|---|---|---|---|
| accepted | `202` | — | `accepted`, `duplicates`, `ignored_unknown_kinds`, `coerced_enum_values`, `server_time` | advance cursor, reset backoff |
| wrong content type | `415` | `unsupported_media_type` | `expected` | permanent → quarantine |
| body too large | `413` | `batch_too_large` | `max_bytes`, `received_bytes` | halve and retry once ([§ 11.5](#115-retry-and-backoff)) |
| unparseable body | `400` | `malformed_body` | `detail` | permanent → quarantine |
| missing/unknown/revoked token | `401` | `unauthenticated` | — | permanent → quarantine, badge `degraded` |
| identity ≠ token binding | `403` | `identity_mismatch` | `expected_install_id`, `expected_seat_id` | permanent → quarantine, badge `degraded` |
| **unaccepted schema version** | `400` | `unsupported_schema_version` | `received_version`, `accepted_versions` | permanent → quarantine, `REJECTED.txt`, badge `degraded` |
| batch/event validation failure | `422` | `invalid_event` | `index`, `field`, `reason` | permanent → quarantine, badge `degraded` |
| rate limited | `429` | `rate_limited` | `retry_after_s`, `limit`, `window_s` | back off, retry |
| server fault | `5xx` | `server_error` | `detail` (no internals) | back off, retry |

**The deliberately-invalid example.** A reporter at schema 3 posting to an ingest that accepts `[1,2]`,
with a token bound to `(aimla, impl-2)`:

```http
POST /api/ingest/events HTTP/1.1
Host: mezzanine.example.org
Authorization: Bearer mzn_<43 chars>
Content-Type: application/json; charset=utf-8

{"schema_version":3,"batch_id":"01K3TC0Q4R5T6W7X8Y9Z0A1B2C","install_id":"aimla",
 "seat_id":"impl-2","reporter_version":"0.4.0","reporter_platform":"win32",
 "runtime_version":"v22.11.0","seq_epoch":"01K3T0000A5N7M2X9V4B6D0FGH",
 "sent_at":"2026-08-23T15:20:02.004Z","events":[ …120 valid events… ]}
```

**Required response — exactly this shape:**

```http
HTTP/1.1 400 Bad Request
Content-Type: application/json
```
```json
{
  "error": "unsupported_schema_version",
  "message": "schema_version 3 is not accepted; this ingest accepts 1, 2",
  "received_version": 3,
  "accepted_versions": [1, 2],
  "batch_id": "01K3TC0Q4R5T6W7X8Y9Z0A1B2C"
}
```

And, as **required behaviour, not merely a status code**: zero of the 120 events are ingested; the
refusal is counted against the seat **the token is bound to** — which in this example is
`(aimla, impl-2)`, the same pair the body claims, and the body's claim is not what made it so; the
seat renders **visibly degraded** on the floor with the received and accepted versions readable in its
drill-down; the reporter writes `REJECTED.txt`, counts the 120 lost events in
`events_rejected_dropped`, and stops retrying that batch. The one outcome this design forbids
everywhere is the `200`-with-nothing-in-it that reads as a clean zero — the failure class
[`docs/VERSIONING.md`](../VERSIONING.md#the-failure-direction-must-be-safe--reject-loudly-never-drop-quietly)
and [`docs/KANBAN.md § G-1`](../KANBAN.md#g-1--a-token-whose-user-is-not-a-board-member-fails-silently-and-positively)
both record this fleet hitting for real.

### 12.3 Rate limits

| Limit | Keyed on | Value | Derivation | Over-limit |
|---|---|---|---|---|
| Requests | token binding | **120 / minute** | healthy cadence is 6/min ([§ 11.5](#115-retry-and-backoff)); 120 is 20× headroom — it can only be reached by a spin loop | `429`, `retry_after_s: 30` |
| Events | token binding | **20,000 / hour** | the [§ 6.0](#60-conventions-and-how-harness-payloads-are-read) ceiling is ~10,420/day ≈ 434/hour; 20,000 is ~46× headroom, still bounds one runaway seat's storage to ~10 MB/hour, and is the number a full-spool drain is measured against ([§ 11.5](#115-retry-and-backoff)) | `429`, `retry_after_s: 60` |
| Body size | — | 256 KiB | [§ 4.4](#44-size-caps-and-their-derivations) | `413` |
| Failed authentications | **source IP** | **60 / hour** | see below | `429` and an operator-visible alert — **enforced at [§ 12.1](#121-validation-order) step 4**, inside the auth check itself, because a request that fails auth never reaches step 5 where the other limits live |

**On the failed-auth limit, and what it is honestly for.** It bounds log volume, CPU spent on hash
comparisons, and the noise floor an operator reads — nothing more. **It is not a defence against
guessing, and an earlier draft's "10 per presented token string" could not have been one**: a
brute-forcer sends a *different* string every attempt, so a counter keyed on the string never
accumulates past 1 and the limit never fires. The actual defence against guessing a seat token is its
**256 bits of entropy** ([§ 3.3](#33-authentication-and-the-identity-binding-rule)), which no rate
limit meaningfully improves. Keying on source IP is what makes the limit describe something real, at
the cost that seats behind one NAT share a budget — 60/hour is far above the zero failures a healthy
fleet produces, and a rotation race costs one or two.

**Where it is enforced is part of the fix, not a detail.** The key was corrected once and the limit
was still unfireable, because [§ 12.1](#121-validation-order) evaluated rate limits at step 5 and a
request with a bad token terminates at step 4. So the failed-authentication limit is evaluated
**inside step 4**, and step 5 owns the other three; [AT-6](#at-6-unknown-schema-version-is-refused-loudly)
case B drives the 61st bad token from one IP and asserts the `429`, with 60 bad tokens from distinct
IPs as the negative control. Both halves of a limit — what it is keyed on and where it runs — have to
be right for it to be a check rather than a decoration, and this one got each wrong once.

Separately, and as a **diagnostic rather than a limit**: a presented token that resolves to a
*revoked* row is counted per token row and alerted on, because that is a real signal with a real
owner — a seat still holding a dead credential, which nobody else can see.

### 12.4 Batches are atomic

**A batch is ingested completely or not at all.** No partial ingest, ever. Three reasons, in order of
weight:

1. **A partial ingest under a success status is indistinguishable from a full one, and the reporter
   deletes its spool on success.** Silently-partial acceptance is the same defect class as the 200-
   with-empty-data this whole design is built against — except it destroys the only other copy of the
   data.
2. **Every event in a batch comes from one reporter at one version**, so a validation failure is
   systemic, not a bad apple. Accepting 199 of 200 hides a reporter bug behind a mostly-working
   stream; refusing all 200 with `index` and `field` in the body puts the bug on somebody's screen.
3. **Atomicity makes retry trivially idempotent**: a batch is either fully present or fully absent, so
   a retry needs no reconciliation beyond per-event dedup.

The cost — one malformed event costs its ≤ 199 neighbours — is bounded by the 200-event cap and by the
poison-pill rule ([§ 11.5](#115-retry-and-backoff)), which stops one bad batch from wedging the
stream. It is also why **an unknown kind and an unknown enum value are never validation failures**
([§ 12.1](#121-validation-order) step 10): under atomic rejection, treating an additive change as
invalid would convert one new harness value into the permanent loss of 200 good events, which is the
exact trade this rule exists to avoid making by accident. **Duplicates are not a validation failure**
either, and never trigger this path.

### 12.5 Late completions and orphan timeouts

| Rule | Value | Derivation |
|---|---|---|
| Orphan timeout, ordinary tool call | **15 min** | the harness's documented `Bash` timeout ceiling is 10 minutes — **unverified against the installed build** ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)); 15 min = that ceiling + 50 % headroom for flush latency and clock skew, and it moves if the ceiling does |
| Orphan timeout, `Task` (subagent) call | **60 min** | a subagent is a full agent session and routinely runs tens of minutes; 60 min is 4× the ordinary ceiling. Erring long is the safe direction — a desk that shows *working* too long is honest-ish; a desk that goes idle while its subagent runs is the exact defect this section exists to prevent |
| Reporter-side tombstone window | **15 min** | deliberately the same number as the ordinary orphan timeout, so the reporter can still name a call for exactly as long as the server still holds one open ([§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)) |
| Orphan close | server-side ledger only, `aborted` / `orphan_timeout` | **no wire event is synthesized** — the wire records what a seat said |
| **Late completion** | a `completed` or `failed` close carrying `match: "tombstone_ref"` for a call already closed as `aborted` **overrides** it | **completion is an observation; abort is an inference.** An observation always wins over an inference about the same fact. Counted as `late_completion`; a rising count means a reap rule is too eager and is a design signal, not noise — and it can only *be* counted because the tombstone gives the late close its original `call_id` |

### 12.6 The five `D2-MUST` constraints

The complete list of what this contract imposes on D2 (`docs/design/FLEET-STATE.md`). Everything else
about the store is D2's to decide.

| # | Constraint |
|---|---|
| 1 | **Idle may be minted only from `turn.end` with `end_reason == "stop_hook"` and `aborted_call_ids == []`.** Every other turn ending yields `unknown`, never `idle` — **except `end_reason == "api_error"`, which yields the distinct rendered state `stalled`** carrying `api_error_type` ([§ 6.4](#64-turnend)). A `failed` tool call is a closed call and does not block idle; an **interrupted** one closes `aborted` and does ([§ 6.6](#66-toolend)). **`stalled` is cleared by the first of that session's next `turn.start`, that session's `session.end` (including the 90-minute `inferred_silence` close), or the seat leaving live state; past a `session.end` with no new turn the seat renders `unknown`.** A state with an entry edge and no exit edge is a one-way trapdoor, which is the same thing constraint 5 forbids for *blocked* ([§ 6.4](#64-turnend)). |
| 2 | **`stale` (300 s) and `offline` (900 s) are visibly degraded rendered states, never `idle`,** and a seat with `degraded` non-empty renders its badge. |
| 3 | **Per-event dedup on `(install_id, seat_id, event_id)` with a 10-day window,** and the window must exceed the spool's 8-day residency cap ([§ 10.3](#103-idempotency-and-the-dedup-window)). |
| 4 | **State transitions are ordered by `(event_time, seq)`, never by arrival order,** `received_at` is the only clock used for liveness, retention and cross-seat comparison, and a repeated `(seq_epoch, seq)` with differing `event_id`s is counted as `seq_collision` rather than silently applied. |
| 5 | **Blocked is minted only from `attention.request` and cleared only by its matching `attention.resolved`** (joined on `request_id`), by the session ending, or by the seat leaving live state — and never rendered for longer than the 60-minute ceiling without a resolution ([§ 6.13](#613-attentionresolved)). This holds because D1 guarantees `attention.request` is emitted **only** for a genuine wait on a human: [§ 6.12](#612-attentionrequest) gates the `Notification` hook on `notification_type` so that `auth_success`, `agent_completed` and the rest never open one. **D2 needs no second predicate over `notification_kind`** — and the reason is the gate, not the enum: every member of that field (`permission_required`, `input_awaited`, `elicitation`) *is* a wait on a human, because the gate emits nothing for anything else. An earlier draft's fourth member, `other`, was unreachable and is deleted ([§ 6.12](#612-attentionrequest)), so there is no member left for D2 to have to exclude. |

### 12.7 Server-side counters

The reporter's counters ride its heartbeat and are listed in [§ 9.3](#93-degradation-counters). The
ingest keeps its own, and they are collected here rather than left scattered through the sections that
introduce them, because an implementer building the server needs the whole set in one place — and
because a counter nobody can find is a counter nobody reads.

| Counter | Incremented when | Consequence |
|---|---|---|
| `accepted` / `duplicates` | per batch, in the `202` body | the reporter's convergence signal ([§ 10.3](#103-idempotency-and-the-dedup-window)) |
| `ignored_unknown_kinds` | an event's `kind` is not one this ingest knows | seat renders `reporter_ahead`, informational — the additive-change rule at work ([§ 5](#5-compatibility--what-this-document-owes-the-policy)) |
| `ignored_unknown_fields` | an event carried a `data` key this ingest's per-kind schema does not define | seat renders `reporter_ahead`, informational — the counter [§ 5](#5-compatibility--what-this-document-owes-the-policy)'s rule-3 row claims, counted per seat so "a newer reporter" is a visible state rather than a silent one |
| `coerced_enum_values` | a closed-enum field carried an unrecognised value | seat renders `reporter_ahead`; a persistent non-zero means this ingest is behind its fleet |
| `duplicate_open` | a `tool.start` for a `call_id` already open | informational; a dedup escape or a replay ([§ 8.6](#86-server-side-interpretation-of-open-call-state)) |
| `late_open` | a `tool.start` arriving for a call already closed | informational; ordinary with out-of-order batches |
| `late_completion` | a `match: "tombstone_ref"` close overriding an `aborted` one | **a design signal**: a rising count means a reap rule is too eager ([§ 12.5](#125-late-completions-and-orphan-timeouts)) |
| `orphan_timeout_closes` | the ledger closed a call nobody ever closed | informational per seat; a spike means the reporter stopped closing calls |
| `session_reopened` | an event arrived for a session closed by `inferred_silence` | **re-derives the 90-minute rule** ([§ 6.2](#62-sessionend)) |
| `seq_gap` | a missing `seq` inside an epoch | seat badge `lossy` ([§ 10.2](#102-ordering-seq-and-gap-detection)) |
| `seq_collision` | one `(seq_epoch, seq)` carrying two different `event_id`s | seat badge `degraded`; the only mechanism that produces it is two flushers ([§ 2.3](#23-the-flusher-must-be-alive-whenever-the-seat-is)) |
| `seq_epoch_change` | a batch arrived under a new `seq_epoch` | seat renders `epoch_reset`, informational — a re-numbering, not a loss |
| `batches_refused.<error>` | any 4xx refusal, keyed by error code | counted **against the token's binding**; the seat renders degraded ([§ 12.1](#121-validation-order)) |
| `unattributed_refusals` | a refusal at validation steps 1–3, before any identity is established | global only; **no seat is degraded by it**, because no seat is known ([§ 12.1](#121-validation-order)) |
| `auth_failed_by_ip` | a token that resolves to nothing — incremented at [§ 12.1](#121-validation-order) **step 4**, which is also where the limit it feeds is evaluated | the 60/h limit ([§ 12.3](#123-rate-limits)); log-volume control, not a guessing defence. Counted globally and per source IP; it degrades no seat, because the token named none |
| `revoked_token_presented` | a token that resolves to a revoked row | **operator alert**: a seat is still holding a dead credential and only the server can see it |
| `clock_skew_ms` | *(a gauge, not a counter)* per batch, `received_at − sent_at` | seat badge `clock_skew` past ±120 s ([§ 10.1](#101-two-clocks-and-which-is-authoritative-for-what)) |

---

## 13. Acceptance tests

Each test names **what to build, what to break to make it RED, and what GREEN asserts**. A test never
seen to fail is not evidence — it is a decoration that reports the harness ran.

### AT-1 kill-vs-complete: the headline test

*This is the gate on trusting the signal at all (`docs/PLAN.md § 3`, card #7337).*

- **Build:** a real seat with the reporter installed, pointed at a real ingest over TLS. A dispatch
  fixture that dispatches a subagent (the `Agent`/`Task` tool) → the subagent runs `Bash: sleep 120`.
- **Do:** wait until the server's ledger shows both calls open, then type `/clear` in the seat.
- **GREEN — the event stream matches [§ 8.7](#87-worked-flow--a-clear-during-a-subagents-bash-call)**:
  two `tool.end`s with `outcome:"aborted"`, `abort_reason:"session_cleared"`,
  `close_source:"reap_session_boundary"`; a `subagent.stop` with `outcome:"aborted"`; a `turn.end`
  with `end_reason:"session_cleared"` and `aborted_call_ids` of length 2; a `session.end` with
  `end_reason:"clear"`; and a `session.start(source:"clear")`. **Either hook order is a pass** —
  `SessionEnd` before `SessionStart` or the reverse — but the reap must have happened **exactly
  once**: assert `reap_noop_second_signal` incremented by exactly 1 and that no call was closed twice.
- **GREEN — what must NOT appear:** no `tool.end` with `outcome:"completed"` or `"failed"` for either
  call; no `turn.end` with `end_reason:"stop_hook"`; and **no idle transition in the derived state** —
  the seat goes `working → unknown`, never through `idle`, and the floor shows no idle animation.
- **RED (run it, don't assume it):** disable the reap in [§ 8.3](#83-the-reap-rules) and re-run. The
  calls stay open until the orphan timeout, the boundary events carry `aborted_call_ids: []`, and a
  consumer applying only "turn ended ⇒ idle" mints the false idle. Seeing that is the proof the test
  discriminates.
- **Second RED:** keep the reap but weaken `D2-MUST` #1 to "any `turn.end` ⇒ idle". The idle appears.
  Both halves — the schema and the consumer rule — must be individually necessary.
- **Case B — `/clear` against a parallel dispatch, which is what the `agent_id` scoping is for.**
  Same fixture, but the turn dispatches **three** subagents concurrently, each running
  `Bash: sleep 120`, and the `/clear` lands with all three plus their parent calls open. **GREEN:**
  exactly **one** `turn.end` for the turn (not four), `aborted_call_ids` naming all six calls, three
  `subagent.stop`s with `outcome: "aborted"`, and **no idle transition**. **RED:** scope the `Stop`
  reap to `session_id` alone instead of `(session_id, agent_id ?? "main")` and emit `turn.end`
  regardless of `agent_id` → if the harness ever fires `Stop` inside subagents, each subagent's
  completion aborts the parent's in-flight calls and mints a mid-turn `turn.end`. At 2.1.240 this RED
  does **not** reproduce, because `Stop` is MEASURED not to fire inside a subagent
  ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)) — and that is the point of running it:
  a RED that stops reproducing on a harness upgrade is how this test reports that the fact moved.
- **Case C — a turn that ends on `Stop` with a dispatch call still open.** This is the
  `(session_id, agent_scope_id)` reap path, and neither case above drives it: both end their turn with
  a `/clear`, which reaps under `SessionEnd`'s **session-wide** rule
  ([§ 8.3](#83-the-reap-rules)) and would pass with the turn-scoped rule broken. **Do:** dispatch one
  subagent that runs `Bash: sleep 120`, and end the turn normally — no `/clear` — so the harness fires
  `Stop` with the parent's dispatch call `A` still open. **GREEN:** `A` is reaped —
  `tool.end(outcome:"aborted", abort_reason:"turn_boundary", close_source:"reap_turn_boundary")` and a
  `subagent.stop` — the `turn.end` names `A` in `aborted_call_ids` with `open_calls_at_end: 1`, and
  **no idle transition is minted**. Assert `A` closes at the `Stop`, not at its 60-minute orphan
  ceiling: the server's ledger must show it closed within one flush interval.
  **RED — the defect this case exists for:** scope the reap on the dispatch call's **bound child**
  `agent_id` instead of on its own `agent_scope_id` (the single-field index an earlier draft carried).
  `A.agent_scope_id` becomes the child's id at `SubagentStart`, `Stop` carries no `agent_id` at all so
  its scope is always `"main"`, and `A` is therefore excluded from the parent's own reap: it sits open
  for an hour, `aborted_call_ids` is `[]`, `open_calls_at_end` is `0`, and
  [§ 8.6](#86-server-side-interpretation-of-open-call-state) renders the seat *working* the whole
  time. That RED reproduces on **every** build — unlike Case B's, it does not depend on a harness fact
  that might move.
- **Case D — a permission-refused call inside a subagent is reaped by `SubagentStop`.** Run the
  subagent under `--permission-mode default` against an operation it will be refused, so its
  `PreToolUse` fires and **no close hook of any kind** does (MEASURED,
  [§ 6.0](#60-conventions-and-how-harness-payloads-are-read)). **GREEN:** the inner call closes
  `aborted`/`turn_boundary` with `close_source: "reap_turn_boundary"` at the `SubagentStop`, its
  `tool.end` precedes the dispatch call's, and **no `turn.end` is emitted for the subagent**.
  **RED:** remove the `SubagentStop` reap row from [§ 8.3](#83-the-reap-rules) → the inner call has no
  close rule at all (`Stop` does not fire inside a subagent) and stays open until its session ends.

### AT-2 sanitizer red fixtures

- **Build:** the 13 fixtures of [§ 7.5](#75-red-fixtures--required-tests) plus the two whole-event
  assertions, as unit tests, run on Linux **and** Windows. Each fixture asserts the exact output
  string, not a substring — which is buildable for all 13 because all 13 inputs are literals.
- **RED:** replace the sanitizer with the identity function → all 13 fail. Then restore it and remove
  only the allowlist → fixture 8 fails alone (proving the layers are independently load-bearing).
  Then restore the allowlist and revert rule 5 to the pre-extension rule 4 → fixtures 9, 10 and 11
  fail alone, and the credential in each appears verbatim in the output (proving the credential-on-
  argv extension is load-bearing and not decoration).
- **GREEN:** all 13 exact-match; no planted credential appears in any serialized event.
- **Third RED — the two rules fixtures 12 and 13 pin:** change rule 6's retained head to the first
  *named* segment → fixture 12 fails alone (`/var/…/Http/X.php`) while fixture 5 still passes, which
  is what proves the two fixtures disagree about the reading rather than duplicating each other.
  Raise rule 7's threshold from 32 to 64 → fixture 13 fails alone.
- **The consistency check the table's trace column exists for:** a test asserts that the rule
  indices listed in each fixture's "Rules that fire" column are exactly the rules the implementation
  reports firing for that input (the sanitizer returns the fired-rule list under test). A fixture whose
  documented trace and actual trace disagree fails **even if its output string matches** — that
  disagreement is the drift between the two tables that produced two broken fixtures in the draft.

### AT-3 the reporter never blocks the seat

- **Build:** a harness that invokes `hook PreToolUse` 200 times with a realistic payload, measuring
  wall time per invocation, on both platforms — **plus 20 `hook SessionEnd` invocations against an
  index pre-loaded with 64 open calls**, which is the ~130-append worst case
  ([§ 2.2](#22-rules-that-protect-the-seat)) and the one the budget is derived against. The p99 is
  taken over the combined set; measuring only the cheap path would report a budget nothing tests.
- **GREEN:** p99 < 250 ms; **exit code 0 in 200/200**, including a run where `spool_dir` is
  read-only, one where the config file is absent, one where the index journal's last line is torn,
  one where `index/snapshot.json` is unparseable, and one where stdin is empty. Nothing is printed to
  stdout in any run.
- **RED:** insert a synchronous 2 s HTTPS call into the hook path → p99 blows the budget. Make the
  hook `process.exit(1)` on a parse error → the exit-code assertion fails.

### AT-4 survives the server being down for days

- **Build:** point the flusher at a black-holed address (a firewall `DROP`, not a refusal — a `DROP`
  exercises the timeout path, a refusal exercises only the connect-error path; run both).
- **Do — case A, the outage:** drive normal seat activity for 30 min, then restore the server.
- **GREEN (A):** the seat is unaffected throughout (AT-3's budget still met); spool grows
  monotonically; `oldest_unsent_age_s` rises; backoff is observed to reach and hold at ≤ 120 s with
  jitter; on restore **every** spooled event arrives, `duplicates` stays at 0, and
  `spool_dropped_events` is 0.
- **Do — case B, the drain against the limiter** (case A does **not** exercise it: 30 minutes of
  events is far under one hour's allowance). The real shape is ~67,000 spooled events against
  20,000/hour = **3.35 limiter windows**, so the scaled fixture must reproduce *that* ratio, not a
  round 10:1: set the ingest's event limit to **200 per 36-second window** and pre-fill the spool with
  **670** events. That is 3.35 windows again, and the whole test runs in ~2 minutes instead of the
  ~10 hours an earlier draft's parameters implied — it scaled the event counts by 1/100 but left the
  limiter window at one hour, so "1/100 of the wall time" was arithmetic over a constant that had not
  been scaled.
- **GREEN (B):** the drain takes ~3.35 limiter windows (~2 min at the scaled parameters,
  corresponding to the ~3.4 h the real numbers give); `429 rate_limited` responses are observed and
  their `retry_after_s` is honoured;
  **zero events are lost** and the final delivered set equals the spooled set exactly (assert by id,
  not by count); the seat renders *catching up* — `oldest_unsent_age_s > 300` — and **never** `stale`,
  because `received_at` keeps moving.
- **RED:** shrink the spool bound to 1 MiB and repeat case A → `spool_dropped_events` > 0 and the seat
  badges `lossy`, proving overflow is visible rather than silent. For case B, ignore `retry_after_s`
  and retry immediately → the seat spends the drain in `429`s making no progress, which is what the
  honouring rule prevents.

### AT-5 duplicate delivery is free

- **Build:** a flusher flag that re-POSTs the last accepted batch verbatim.
- **GREEN:** second response is `202` with `accepted: 0, duplicates: N`; the ledger and the derived
  state are byte-identical before and after; no double-counted tool call.
- **RED:** drop the unique index → duplicates ingest, open-call counts double, and the seat shows
  phantom concurrent calls.

### AT-6 unknown schema version is refused loudly

- **Build:** a hand-crafted POST with `schema_version: 999` and 120 otherwise-valid events, sent with
  a token bound to seat X while the body claims seat Y.
- **GREEN:** `400`, body exactly the shape in [§ 12.2](#122-error-responses) naming received and
  accepted versions; **0 events stored** (assert by count, not by absence of errors); the refusal
  counter increments **for seat X, the token's binding — and not for seat Y**; seat X renders
  degraded and seat Y is untouched; the reporter writes `REJECTED.txt`, quarantines, counts 120 in
  `events_rejected_dropped`, and does not retry.
- **RED:** make the ingest accept any version → events land and nothing is degraded. **Second RED:**
  attribute the refusal to the body's claimed identity → seat Y, an innocent seat, renders degraded
  from a batch it never sent. Also assert the negative control: the *same* batch at an accepted
  version returns `202`, so the test discriminates version handling and not batch validity.
- **Case B — the failed-authentication limit fires where it is placed.** [§ 12.3](#123-rate-limits)
  bounds failed authentications at 60/hour per source IP, and
  [§ 12.1](#121-validation-order) evaluates it **inside step 4** rather than with the other limits at
  step 5. **Do:** send 61 batches from one source IP, each with a *different* unresolvable token.
  **GREEN:** the first 60 return `401 unauthenticated`; the **61st returns `429 rate_limited`** with
  `retry_after_s`, `limit` and `window_s` in the body; `auth_failed_by_ip` reads 61 for that IP; and
  **no seat renders degraded** at any point, because none of the 61 tokens named a seat.
  **RED:** move the limit back to step 5 → all 61 return `401`, the `429` never appears, and
  `auth_failed_by_ip` still counts 61 — a counter feeding a limit that cannot fire, which is the
  shape this case exists to catch. **Negative control:** 60 bad tokens from 60 *distinct* IPs → all
  `401`, no `429`, so the test measures the IP key and not merely the attempt count.

### AT-7 staleness alarm — the dark-reporter backstop

- **Build:** a running seat with a healthy heartbeat.
- **Do:** `SIGKILL` the flusher and prevent respawn (make the lock look fresh).
- **GREEN:** at ≤ 300 s the seat renders `stale`; at ≤ 900 s, `offline`; **at no point does it render
  `idle`**, and the transition is visible in the UI without a reload.
- **RED:** disable the staleness evaluation → the seat renders its last known state forever, which is
  precisely the 30-day-dark failure of [§ 3.4](#34-why-identity-never-comes-from-the-environment)
  reproduced on purpose.

### AT-8 predicate-constant alarm

- **Build:** two cases, because [§ 9.4](#94-the-predicate-constant-alarm) states two criteria. **(a)
  high-volume:** a seat whose `descriptor_allowlisted` predicate is forced constant, driven past 500
  evaluations in 24 h. **(b) low-volume:** a seat whose
  `attention_source_permission_hook` predicate is forced constant-`false` — every `attention.request`
  opened by `Notification`, none by `PermissionRequest` — over ≥ 50 evaluations of a 7-day window, fed
  from a seeded heartbeat series rather than by waiting a week, since the check is over counters and
  not over wall time.
- **GREEN:** each case fires `predicate_constant` for its own predicate at its own criterion, and the
  seat surfaces it. **Negative control:** a seat with a mixed distribution over the same volume does
  **not** fire, in both cases — and this control is *reachable*, which it was not while case (b)
  tested a regex classifier that [§ 6.12](#612-attentionrequest) has since deleted. That classifier
  matched English wording against a payload that carries `notification_type` instead, so it returned
  `other` on every real notification: the forced-constant case and the unforced case were the same
  case, and the control could never discriminate.
- **RED:** the alarm with no threshold check fires never, or always — both are visible against the
  control. **Second RED — the one this design added:** apply the 500 / 24 h criterion to the
  low-volume predicate (case b) → it never fires on any real seat, because a seat produces tens of
  notifications a day, not hundreds. An alarm that cannot reach its own threshold is a decoration,
  and this RED is what proves the per-predicate criteria are load-bearing.

### AT-9 a torn spool line does not poison the batch

- **Build:** a spool bucket with a valid line, a `\n`-terminated truncated JSON line, another valid
  line, and a trailing partial line with no `\n`.
- **GREEN:** both valid lines are delivered; the truncated line lands in `quarantine/corrupt.jsonl`
  with `spool_corrupt_lines == 1`; the trailing partial line is **untouched** and is delivered
  intact after it is completed on the next pass.
- **RED:** make the parser throw on the batch → nothing is delivered, which is the wedge this rule
  exists to prevent.

### AT-10 concurrent append and index integrity

- **Build:** 8 processes × 500 `hook` invocations each, concurrently, on Linux and on Windows. Each
  invocation opens a call and a matching later invocation closes it, so the expected final state is
  known exactly: 4,000 spool lines, 4,000 index-journal records, **zero** calls left open.
- **GREEN — the spool:** exactly 4,000 lines, every one parsing, no interleaved fragments, no lost
  lines.
- **GREEN — the index:** the folded index (snapshot + journal tail) contains exactly the calls that
  should be open, with no entry lost and none duplicated; every `call_id` that was opened was also
  closed; `open_call_index_overflow` is 0. Run it with the flusher **compacting concurrently** as
  well, so snapshot-write and journal-append overlap.
- **RED — the defect this replaced:** re-implement the index as a shared `open-calls.json` rewritten
  `.tmp` + rename by each hook, with one fixed temp name and no lock, and re-run → open calls
  disappear from the index under concurrency (lost update), and the calls whose entries vanished can
  never be closed by the reporter, so the server holds them open to the orphan timeout while the seat
  is healthy. Count the losses; a run that loses none has not reproduced the race and the concurrency
  must be raised before the GREEN is trusted.
- **RED — the atomicity claim:** replace the single `writeSync` with a two-part write (payload, then
  `\n`) → interleaving appears. If it does **not** appear on a platform, that platform's atomicity
  claim is unproven, not proven — increase concurrency until the RED reproduces before trusting the
  GREEN.

### AT-11 clock skew

- **Build:** a seat whose clock is set +10 min.
- **GREEN:** events ingest normally; `clock_skew` badge appears with the measured value; ordering
  within the seat is unaffected; liveness and the UI's rendered ages come from `received_at`, so the
  seat does **not** appear "last seen in the future"; `event_time` is stored unmodified.
- **RED:** derive staleness from `event_time` → the skewed seat looks perpetually fresh (or 10 minutes
  stale, at −10 min), demonstrating why `received_at` is authoritative.

### AT-12 out-of-order batches

- **Build:** capture two consecutive batches; deliver batch 2, then batch 1.
- **GREEN:** the final ledger and derived state are identical to in-order delivery, including a
  `tool.end` that arrives before its `tool.start` (created closed, not reopened by the late start).
- **RED:** apply state by arrival order → a completed call reopens and the seat stays "working"
  forever.

### AT-13 atomic batch rejection

- **Build:** a batch of 200 events where event 137 has `data` exceeding 3 KiB.
- **GREEN:** `422 invalid_event` with `index: 137` and the offending `field`; **0 of 200** stored;
  the batch is quarantined and never retried; `events_rejected_dropped` rises by 200 and the seat
  badges `lossy`; the stream continues with the next batch.
- **RED:** allow partial ingest → 199 stored under a success status and the reporter's cursor
  advances, permanently losing event 137 with no record that anything was lost.

### AT-14 statusLine sampling and passthrough

- **Build:** a seat with a pre-existing statusLine command, wrapped by the installer.
- **GREEN:** the original status line still renders, byte-identical; over a 10-minute active window,
  `context.sample` events number ≤ 10 for cadence plus one per 5-point bucket crossing;
  `statusline_suppressed` is > 0 (a zero here means sampling is not running at all); the session's
  sample file exists and its `at` is within 60 s; a `compaction.start` fired in that window carries a
  non-null `context_used_pct` with `context_used_pct_age_s ≤ 300`.
- **RED:** remove the sampling gate → hundreds of events per minute. Break the wrapped command → the
  seat's status line blanks, `wrapped_statusline_failures` rises, and **the reporter still exits 0**.
  Stop the statusLine integration entirely and fire a `compaction.start` → `context_used_pct` is
  `null` and `context_sample_stale` rises, proving the field is honestly sourced rather than
  fabricated.

### AT-15 transport posture

- **Build:** `selftest` runs against (a) an `http://` URL, (b) a host with an untrusted certificate,
  (c) the real host.
- **GREEN:** (a) install fails with a named error and the flusher refuses to send while continuing to
  spool; (b) the connection is refused by certificate verification and the batch is retried, not
  dropped; (c) `202`, and `GET /api/ingest/health` returns an accepted set containing the reporter's
  own `schema_version`.
- **RED:** set `rejectUnauthorized: false` → (b) passes, which is the wrong answer and must be caught
  in review; a lint rule forbidding `rejectUnauthorized` and `NODE_TLS_REJECT_UNAUTHORIZED` in the
  reporter source makes it mechanical.

### AT-16 the counter sink survives concurrency

*The heartbeat's counters and predicates are computed in processes the flusher never shares memory
with ([§ 11.1](#111-layout)). If they do not arrive intact, [§ 9.4](#94-the-predicate-constant-alarm)'s
alarm — the structural backstop — is built on sand.*

- **Build:** 8 concurrent hook processes × 200 invocations, each invocation performing a **known**
  number of redactions (a fixture descriptor containing exactly 2 redactable spans) and exactly one
  `descriptor_allowlisted` evaluation. Expected totals are therefore exact: 3,200 redactions and
  1,600 predicate evaluations.
- **GREEN:** the next `reporter.heartbeat` carries `sanitizer_redactions == 3200` and
  `descriptor_allowlisted.true + .false == 1600` — **exact equality, not a threshold**; counter
  buckets older than the current UTC hour have been deleted after folding; no bucket line is folded
  twice (re-run the flusher pass and assert the totals do not move).
- **RED:** have each process read-modify-write its counters into `state.json` instead (the design this
  replaced) → totals land below expectation under concurrency, and the shortfall grows with
  parallelism. **Discriminating control:** the same run with concurrency 1 → both designs report the
  exact totals, proving the test measures concurrency and not arithmetic.
- **Second RED:** fold buckets without recording the per-bucket byte offset → the totals double on the
  next pass.
- **Third case — the hour-roll straddle, which the exact-equality GREEN above cannot be trusted
  without.** Drive the same 8 × 200 run across a **simulated UTC hour boundary** (the reporter takes
  its clock from an injectable source in tests), with the flusher folding and deleting concurrently,
  and with several writers deliberately descheduled between deriving a bucket name and their
  `writeSync`. **GREEN:** the totals are still exactly 3,200 and 1,600, and every counter line lands
  in the bucket its *write* time names, not its *entry* time. **RED:** derive the bucket name at
  process entry instead of immediately before the `writeSync`, and drop the 5 s grace from the
  deletion precondition ([§ 11.1](#111-layout)) → lines written just after the roll land in a bucket
  the flusher has already folded and unlinked, and the totals come in low. This RED is the reason the
  case exists: without it the exact-equality assertion would flake under a real hour roll roughly once
  per hour per busy seat and be read as test flakiness rather than as the lost-counter defect it is.

### AT-17 a corrupt `state.json` loses nothing

- **Build:** a seat with three hour buckets spooled (~600 events), of which the first two were already
  accepted by the server; then truncate `state.json` to a corrupt fragment and restart the flusher.
- **GREEN:** the flusher counts `state_reset`, mints a new `seq_epoch`, and re-sends **from the oldest
  bucket**; the server's stored event-id set for that seat is **exactly** the union of all three
  buckets, each event present once; `duplicates` on the re-sent batches is > 0 (proving the re-send
  actually happened); `spool_dropped_events == 0`; the seat renders `epoch_reset`, informational, and
  **not** `lossy`.
- **RED — the defect this replaced:** set the cursor to the start of the newest bucket instead → the
  events of the first two buckets are absent from the server's id set, `spool_dropped_events` is still
  0, no badge appears, and nothing anywhere records the loss. Assert the missing ids explicitly; that
  silent hole is the failure being designed out.
- **Discriminating control:** the same run **without** corrupting `state.json` → `duplicates == 0`,
  proving the duplicates in GREEN come from the reset path and not from ordinary retry.

### AT-18 an unknown enum value costs one field, not a batch

- **Build:** a `SessionStart` payload whose `source` is `"teleport"` — a value no build of this
  reporter knows — inside a batch of 200 otherwise-valid events.
- **GREEN:** the emitted `session.start` carries `source: "unknown"`;
  `enum_value_unknown.session.start.source` is 1; the literal string `teleport` appears **nowhere** in
  the emitted event or the batch body; the ingest returns `202` and stores all 200 events.
- **GREEN — the server half:** post a hand-crafted batch (as a *newer* reporter would send) whose
  `session.start.source` is `"teleport"` verbatim → `202`, all 200 stored, `coerced_enum_values` is 1,
  the stored value is the unknown member. Neither end may reject.
- **RED:** pass the value through verbatim from the reporter *and* validate it strictly on the server
  → `422 invalid_event`, **0 of 200** stored, the batch quarantined and never retried. That is up to
  200 good events destroyed by one unannounced harness value, and it is the outcome this rule exists
  to prevent.
- **Discriminating control:** the same fixture with `source: "fork"` — a member this reporter *does*
  know — → emitted verbatim as `fork`, `enum_value_unknown` unchanged. The test therefore measures
  coercion of the unknown, not blanket rewriting.

### AT-19 a failed tool call still ends the turn cleanly

*The difference between a call that failed and a call that was killed — the distinction the whole
call ledger turns on.*

- **Build:** a seat turn that runs `Bash: exit 1` (a real failing tool call) and then finishes
  normally.
- **GREEN:** a `tool.end` with `outcome: "failed"`, `close_source: "post_tool_use_failure"`, and no
  `abort_reason`; the following `turn.end` has `end_reason: "stop_hook"`, `aborted_call_ids: []` and
  `failed_calls: 1`; the derived state **does** mint *idle*.
- **RED:** unsubscribe `PostToolUseFailure` (the pre-fix design) → the failed call's ledger entry stays
  open, the `Stop` reap closes it as `aborted`/`turn_boundary`, `aborted_call_ids` has one element, and
  `D2-MUST` #1 forbids *idle* — the seat sits in `unknown` after an entirely ordinary turn. Run it on a
  session containing several failing calls to see that this is the common case, not an edge one.
- **Discriminating control:** AT-1's killed call must still **not** mint idle under the same build, so
  the two tests together prove `failed` and `aborted` are distinguished rather than merged.
- **Case B — an interrupted call is not a failed one.** Drive a long `Bash` call and interrupt it, so
  the harness fires `PostToolUseFailure` with `is_interrupt: true`. **GREEN:** `tool.end` carries
  `outcome: "aborted"` and `abort_reason: "interrupted"`, the turn's `aborted_call_ids` is non-empty,
  and **no idle is minted**. **RED:** ignore `is_interrupt` and map every `PostToolUseFailure` to
  `failed` (the pre-fix design) → the interrupted call reads as an ordinary error, `aborted_call_ids`
  is empty, and the seat mints a **false idle** on a turn whose work was killed — AT-1's defect
  arriving through the failure hook instead of through the missing one.
- **Case C — a turn that ends on an API error still ends.** With the ingest reachable, force the
  harness's `StopFailure` path (a rate-limit or an injected API error on the model call). **GREEN:**
  a `turn.end` with `end_reason: "api_error"` and a non-null `api_error_type`; the session's open
  calls are reaped with `abort_reason: "api_error"`; the derived state is **`stalled`**, not `idle`
  and not `unknown`. **RED:** unsubscribe `StopFailure` → **no `turn.end` is emitted at all**, the
  open calls sit to their 15- or 60-minute orphan ceiling, `session.end.turns` under-counts, and the
  desk renders *working* for up to an hour after the agent stopped. That RED is the whole reason the
  hook is subscribed, and it is the same shape as this test's primary RED one hook over.
  **GREEN — `stalled` also *ends*, which is half of what makes it a state.** Assert all three exits,
  because an entry edge with no exit is a one-way trapdoor
  ([§ 6.4](#64-turnend)'s `D2-MUST` #1): (i) submit a new prompt in that session → the `turn.start`
  clears `stalled` and the seat renders *working*; (ii) on a second seat, leave the session silent
  → the flusher's 90-minute `inferred_silence` `session.end` ([§ 6.2](#62-sessionend)) clears it and
  the seat renders **`unknown`**, not `idle` and not `stalled`; (iii) on a third, kill the reporter →
  the seat renders `stale` then `offline`, never `stalled` underneath.
  **Second RED — the trapdoor:** give `stalled` the entry edge and no exit rule, then leave the seat
  heartbeating and idle. Because the flusher heartbeats every 60 s regardless of session activity, the
  seat never reaches `stale`, and one transient rate-limit at 09:00 renders `stalled` for the rest of
  the day on a healthy machine. Watch that happen once: it is the same defect
  [AT-20](#at-20-blocked-has-an-exit) exists to forbid for *blocked*, minted fresh for a second state.

### AT-20 blocked has an exit

- **Build:** a seat driven into a permission prompt (an operation requiring approval), instrumented at
  the ingest.
- **GREEN — granted:** an `attention.request` (`source: "permission_request_hook"`), then, after the
  human approves and the tool runs, an `attention.resolved` with `resolution: "granted"`,
  `resolution_source: "call_close"`, and a plausible `waited_ms`; the derived state enters *blocked*
  and leaves it.
- **GREEN — denied, driven the only way it is reachable.** `PermissionDenied` fires for **auto-mode**
  denials, not for a human clicking no ([§ 6.13](#613-attentionresolved)), so this case runs a seat in
  auto mode with a deny rule that refuses the operation → `resolution: "denied"`,
  `resolution_source: "permission_denied_hook"`. Driving it interactively, as an earlier draft
  specified, cannot produce this GREEN on any build: the hook never fires on that path.
- **GREEN — the human refusal, which is a different edge.** Interactively refuse the prompt, then type
  a new instruction → `resolution: "human_input"`, `resolution_source: "user_prompt_submit"`, and the
  refused call closes `aborted`/`turn_boundary` on the turn reap of the scope it ran in, with **no**
  close hook of its own ([§ 6.6](#66-toolend), MEASURED) — the `Stop` reap in the main agent, and the
  `SubagentStop` reap when the refusal happened inside a subagent
  ([§ 8.3](#83-the-reap-rules)). **Run it both ways**: a refusal in the main agent and a refusal
  inside a dispatched subagent, because only the second exercises the rule that `Stop` cannot, and
  [AT-1](#at-1-kill-vs-complete-the-headline-test) case D is its RED. Asserting this separately is what keeps the `resolution`
  distribution interpretable — a low `denied` share means few *auto-mode* refusals, not few refusals.
- **GREEN — the ceiling:** with the resolution hooks stubbed out, the request is resolved at 60 min
  with `resolution: "timeout"`, `attention_resolved_by_hook` shows a `false` branch, and the seat is
  no longer rendered *blocked*.
- **RED:** remove `attention.resolved` entirely (the design this replaced) → the seat enters *blocked*
  and never leaves it; every subsequent turn renders under a stale blocked badge, and no counter
  anywhere marks the state as unresolved. A state with an entry event and no exit event is the defect.
- **Discriminating control:** a seat that is never blocked emits neither kind and never renders
  *blocked*. **This control is only reachable because [§ 6.12](#612-attentionrequest) gates the
  `Notification` hook** — an ordinary seat *does* receive notifications (`auth_success`,
  `agent_completed`), and under an unconditional emission it would open an `attention.request` and
  render *blocked* for each one, so the control would fail on a healthy seat and the test would
  measure nothing. **Fourth RED:** remove the `notification_type` gate → the never-blocked seat
  renders *blocked* after an ordinary `auth_success`, which is the false-*blocked* mirror of AT-1's
  false idle.

### AT-21 the harness-fact drift guard

*This is the test that makes the class fix real. Two review rounds were lost to hand-transcribed
harness facts with nothing binding them to a source ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read));
`SELFTEST-MUST`'s `harness_payload_keys` check is that binding, and a binding nobody has seen fail is
a decoration.*

- **Build:** the captured fixtures of [§ 17](#17-appendix--the-captured-harness-payloads) vendored
  beside the reporter as `fixtures/hooks/<HookEventName>.json`, and the `selftest` subcommand's
  `harness_payload_keys` check over them.
- **GREEN:** `selftest` reports `harness_payload_keys: "pass"`; the same value rides the next
  `reporter.heartbeat`'s `selftest` object ([§ 6.14](#614-reporterheartbeat)). Assert it covers
  **every** hook in the subscription table — a check that silently skips a hook with no fixture is the
  same false-clean the whole section is about, so a missing fixture file is a `fail`, never a skip.
- **RED — one key, one hook.** In `fixtures/hooks/SessionStart.json`, rename `source` to
  `session_start_reason` and re-run → `harness_payload_keys` goes **`fail`**, names `SessionStart` and
  the missing key `source`, and **no other hook's assertion moves**. That last clause is what makes it
  a discriminating check rather than a tripwire: a guard that reds everything on any change tells you
  nothing about what moved. Repeat for `SessionEnd.reason` and `PreCompact.trigger`, which are the
  other two keys a review round asserted were named differently.
- **Second RED — the enum half.** Remove `clear` from the reporter's recognised
  `SessionStart.source` set while leaving the fixture intact → the check fails on the value-set
  assertion rather than the key assertion, and says which. Coercion to `unknown`
  ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read) rule 4) keeps the *events* flowing,
  which is correct and is exactly why a separate assertion is needed: the coercion means the fleet
  would otherwise show only a slow rise in `enum_value_unknown.session.start.source` and no failure at
  all.
- **Third RED — the document half, which is a different guard from the two above.** The two REDs above
  bind the *reporter* to the fixtures. Nothing there binds **this document's stated value sets** to
  the build, and that gap is where a `notification_type` row transcribed with 14 of 16 members
  survived three review rounds. `tools/design/verify-harness-facts.py` re-derives every harness enum's
  value set from the installed binary on every run and asserts it against each place
  [§ 6.0](#60-conventions-and-how-harness-payloads-are-read)'s enum-source table says this document
  states it. **Run it:** delete `elicitation_url_dialog` from [§ 6.12](#612-attentionrequest)'s lookup
  table → the tool goes **rc 1**, names the set, the site and the missing member, and no other set's
  assertion moves. Repeat with an *invented* member added to
  [§ 6.1](#61-sessionstart)'s `source` row → the same failure from the other direction. A guard that
  can only see omission is half a guard.
- **Discriminating control — the extractor must be shown capable of the other answer, or its "every
  set matches" is a decoration.** The tool aborts rather than reporting clean if its binary extractor
  cannot discriminate: it asserts that two different declarations resolve to two *different* sets,
  that every resolved set is non-empty, and that a **fabricated** field name resolves to nothing
  rather than to a default. An extractor that silently returns the same thing for everything would
  pass a value-set check over any document ever written.
- **Discriminating control:** with every fixture and every recognised set intact, the check passes on
  a run where the reporter reads an **extra** key the fixture does not contain, and fails only if a
  key it *reads* is absent. The guard's job is "the reporter's expectations are still met", not
  "the payload is byte-identical to the capture" — the harness adding a field is
  [`docs/VERSIONING.md` rule 3](../VERSIONING.md#the-rules)'s additive case and must not red a seat.
  Note where that one-directional reach stops and the value-set assertion above takes over: a fixture
  may omit a key the harness declares, but a value **set** this document states must match the build
  exactly, because a missing member there is a branch nobody builds.

### AT-22 a maximally-degraded seat still heartbeats

*[§ 9.2](#92-why-this-is-the-structural-backstop) makes the heartbeat the structural backstop, so the
one thing it may never do is fail on the seats that need it. The counters cap is where that could
happen ([§ 6.14](#614-reporterheartbeat)).*

- **Build:** a seat driven into a state where **every** counter in [§ 9.3](#93-degradation-counters)
  is non-zero, including twelve distinct `payload_key_missing.<key>` entries, eight
  `enum_value_unknown.<wire field>` entries, six `notification_not_attention.<type>` entries and four
  `value_clamped.<wire field>` entries — well past what 1.5 KiB can hold.
- **GREEN:** the heartbeat still validates and is accepted `202`; `counters` is ≤ 1.5 KiB;
  `counters_omitted` is > 0 and equals the number of non-zero counters not serialized; the retained
  entries are the always-present delivery counters plus the highest-valued of the rest, ordered by the
  deterministic rule in [§ 6.14](#614-reporterheartbeat) (assert the exact serialization — it is
  reproducible by construction).
- **GREEN — the `degraded` array itself, asserted exactly.** With that state driven, `degraded` equals
  the exact array `["lossy","batches_rejected","harness_contract_moved","reporter_behind",
  "value_clamped","counters_omitted","index_overflow","invalid_tool_name","bad_session_id",
  "config_invalid","statusline_degraded","epoch_reset"]` — every member [§ 9.3](#93-degradation-counters)
  declares, in that section's order, no duplicates. **`counters_omitted` is a member and must be
  present**, because the same fixture drove `counters_omitted > 0`: a seat with too many kinds of
  trouble to report is reporting that fact, and this is the assertion that makes it true rather than
  intended.
- **Third RED — the member set is what the ingest validates, so a guess is a poison pill.** Emit
  `degraded: ["rejected_batches"]` — the plausible transposition of the counter's name, and the exact
  string an earlier draft's own example carried — and post it. **Assert `422 invalid_event` naming the
  field**, then [§ 12.4](#124-batches-are-atomic) rejecting all 200 events, then
  [§ 11.5](#115-retry-and-backoff) quarantining the batch permanently. This is the case the whole test
  is named for arriving through a *different* field: the heartbeat of a degraded seat dying at the
  moment the seat becomes interesting. It is unreachable only because [§ 9.3](#93-degradation-counters)
  declares the member set and both ends validate against the same declaration — which is why a
  `array<enum>` whose members are stated nowhere is a blocker and not a documentation gap.
- **RED:** remove the reduction rule and emit every non-zero counter → `data` exceeds 3 KiB, the
  ingest returns `422 invalid_event`, [§ 12.4](#124-batches-are-atomic) rejects the whole batch, and
  [§ 11.5](#115-retry-and-backoff) quarantines it permanently. **The seat's liveness signal dies at
  exactly the moment the seat becomes interesting**, which is the failure this test exists to make
  impossible. Assert the `422`, not just a missing heartbeat, so the mechanism is visible.
- **Second RED:** clamp `open_sessions` without the eviction-and-reap rule of
  [§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open) → the
  seventeenth session makes `open_sessions` disagree with the index, and the calls of the untracked
  session are never reaped.

---

## 14. Every number, and where it comes from

One table, so a reviewer can audit the arithmetic without reading the prose, and so a future change
can find every number that moves with it. **Measured** = observed in this fleet or captured from the
harness at a pinned version ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)).
**Derived** = computed from another number here. **Chosen** = a judgement call, with the reasoning
and, where it applies, what would re-derive it.

The volume figures are **owned by [§ 6.0](#60-conventions-and-how-harness-payloads-are-read)'s kind
table** and cited here, not restated: the ceiling of that table's per-kind ranges is 10,420
events/seat/day, and every row below that says "the ceiling" means that sum.

| Value | Number | Basis | Where |
|---|---|---|---|
| Hook wall-time budget | 250 ms | Chosen — ~4× the 30–60 ms Node cold start that dominates it; under the ~300 ms a human notices. Covers the index fold and three to six small appends. **Verified by AT-3, not assumed** | [§ 2.2](#22-rules-that-protect-the-seat) |
| Flusher lock staleness | 90 s | Derived — 1.5 × the 60 s heartbeat: never trips on a flusher inside a 15 s POST | [§ 2.3](#23-the-flusher-must-be-alive-whenever-the-seat-is) |
| Token entropy | 256 bits | Chosen — standard floor for a bearer credential, **and the only real defence against guessing one** | [§ 3.3](#33-authentication-and-the-identity-binding-rule) |
| Token rotation overlap | 7 days | Chosen — seats upgrade on their owners' schedules; a week spans a weekend plus slack | [§ 3.3](#33-authentication-and-the-identity-binding-rule) |
| Request total deadline | 15 s | Derived — 256 KiB at 1 Mbit/s (2.1 s) + TLS (~1 s) + processing ≈ 4 s worst realistic; 15 s ≈ 3.5× | [§ 3.5](#35-transport-is-wan-always) |
| Connect deadline | 5 s | Derived — ~2.5× a 2 s pathological cross-continent TLS connect | [§ 3.5](#35-transport-is-wan-always) |
| gzip threshold | 8 KiB | Chosen — below it, CPU and header overhead outweigh the WAN saving | [§ 3.5](#35-transport-is-wan-always) |
| Max event size | 4 KiB | Derived — 8× the ~500 B typical, aligned to the conventional atomic-small-write floor (`PIPE_BUF`) | [§ 4.4](#44-size-caps-and-their-derivations) |
| Max events per batch | 200 | Derived — ~100 KB at typical size; bounds the atomic-rejection blast radius | [§ 4.4](#44-size-caps-and-their-derivations) |
| Max batch body | 256 KiB | Derived — 4× under nginx's documented 1 MiB `client_max_body_size` default. **The deploy host's actual value is unverified** and re-derives this if it is tighter | [§ 4.4](#44-size-caps-and-their-derivations) |
| Typical event size | ~500 B | Derived from the field tables in [§ 6](#6-event-kinds) — the sizing input for spool, batch and rate limits | [§ 4.4](#44-size-caps-and-their-derivations) |
| Descriptor cap | 200 B | Derived — three constraints agree: ~160 chars renderable, 5× the longest realistic command, keeps events ~500 B | [§ 7.4](#74-truncation) |
| Title cap | 120 B | Chosen — ~18 English words; a dispatch description is 3–8 | [§ 7.4](#74-truncation) |
| Busy-seat volume | ceiling **10,420 events/day ≈ 5.2 MB/day**; midpoint ~7,100/day ≈ 3.6 MB/day | **Estimate, not a measurement** — the sum of the kind table's per-kind ranges (1,440 heartbeats + ≤ 1,440 context samples + ≤ 6,000 tool events + ≤ 1,200 turn events + the rest). Sizing uses the ceiling. Re-derived from the first week of live data | [§ 6.0](#60-conventions-and-how-harness-payloads-are-read) |
| statusLine sample cadence | 60 s | Derived — matches the heartbeat. Bounds the class at **≤ 1,440 cadence samples/session-day plus one per 5-point crossing**; deliberately *not* expressed as a multiple of the render rate, which is event-driven and burst-shaped, not a rate | [§ 6.11](#611-contextsample) |
| statusLine bucket | 5 percentage points | Chosen — the resolution a human reads a gauge at | [§ 6.11](#611-contextsample) |
| Wrapped statusLine timeout | 1 s | Chosen — a status line re-renders on every trigger; slower is already broken. Also sits below the harness's own cancellation, which is what allows the failure to be counted | [§ 6.11](#611-contextsample) |
| Context-sample staleness bound | 300 s | **Chosen** — a tolerance, not a freshness guarantee: statusLine is event-driven with no timer, so nothing guarantees a fresh sample and the earlier "5× the 60 s cadence" derivation was arithmetic over a rate that does not exist. Past 300 s `compaction.start` reports `null` rather than a stale percentage | [§ 6.9](#69-compactionstart) |
| Bucket-deletion grace | 5 s | Derived — 20× P-5's 250 ms hook budget; covers a writer descheduled between deriving its bucket name and its `writeSync`, which is what the hour-roll straddle actually is | [§ 11.1](#111-layout) |
| Open-session index | 16 open sessions | Chosen — far above the two or three terminals a real seat runs, so reaching it is itself the signal; **enforced** by eviction-and-reap, which is what makes `open_sessions`' 0…16 bound real rather than asserted | [§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open) |
| Harness build the MEASURED facts are pinned to | Claude Code **2.1.240** | **Measured** — 56 payloads across 10 hook events captured 2026-08-23, of which [§ 17](#17-appendix--the-captured-harness-payloads) reproduces the **16** distinct shapes every MEASURED row is read from. The 16 is re-derived from the appendix by the verifier; the 56 is capture-run provenance and is not checkable from this repo ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)). Every MEASURED row is versioned to the build, and a harness minor bump re-runs the capture | [§ 6.0](#60-conventions-and-how-harness-payloads-are-read) |
| `reporter.heartbeat.degraded` length | 12 elements | **Derived** — not chosen: the array carries at most one of each declared member, so its bound *is* the size of [§ 9.3](#93-degradation-counters)'s member table and moves only when that table moves | [§ 9.3](#93-degradation-counters) |
| Wire enum fields, and how many are classified | **23**, all of them | **Derived** — re-derived from [§ 6](#6-event-kinds)'s field tables by `tools/design/verify-event-schema.py` on every run, which fails on any row absent from [§ 6.0](#60-conventions-and-how-harness-payloads-are-read)'s classification table. Stated as a population, never as a maintained list | [§ 6.0](#60-conventions-and-how-harness-payloads-are-read) |
| Session `inferred_silence` | 90 min | Derived — 1.5× the 60 min `Task` orphan ceiling, the longest legitimate silence inside a live session. Cheap to be wrong now that an early close is reversible (`session_reopened` re-derives it) | [§ 6.2](#62-sessionend) |
| Compaction close timeout | 10 min | Derived — ~10× a typical one-minute compaction | [§ 6.10](#610-compactionend) |
| Attention resolution ceiling | 60 min | Chosen — reuses the `Task` orphan ceiling so a seat cannot render *blocked* after every call it was blocked on has been reaped; erring long is the safe direction | [§ 6.13](#613-attentionresolved) |
| Heartbeat interval | 60 s | Chosen — 1,440/day ≈ 14 % of the ceiling volume for continuous liveness | [§ 9.1](#91-the-cadence-and-the-alarm) |
| Flush interval | 10 s | Chosen — under human "live" perception; caps request rate at 6/min | [§ 11.5](#115-retry-and-backoff) |
| Flush event trigger | 50 events | Derived — ~25 KB, a batch worth a WAN round-trip | [§ 11.5](#115-retry-and-backoff) |
| Seat `stale` | 300 s | Derived — ~4× the 70 s worst-case freshness of a healthy seat. It **does** fire on an outage longer than ~110 s, correctly; what the 120 s backoff cap bounds is how long a seat stays stale *after* recovery | [§ 9.1](#91-the-cadence-and-the-alarm) |
| Seat `offline` | 900 s | Derived — 3× `stale`, so `stale` is a distinct investigable state | [§ 9.1](#91-the-cadence-and-the-alarm) |
| Predicate-constant criteria | 500 evaluations / 24 h (high-volume predicates); 50 / 7 days, or 20 / 7 days for `clear_reap_by_session_end` | **Chosen provisionally** — ~a working day of evidence for a predicate evaluated thousands of times a day, scaled down for the three evaluated tens of times a day, because a threshold above a predicate's own rate is an alarm that can never fire. Re-picked from the first week's per-predicate counts | [§ 9.4](#94-the-predicate-constant-alarm) |
| Clock-skew badge | 120 s | Derived — 2× heartbeat, above NTP drift, below the 300 s stale threshold so the alarms cannot alias | [§ 10.1](#101-two-clocks-and-which-is-authoritative-for-what) |
| Dedup window | 10 days | Derived — 25 % above the **8-day residency cap**, which is what bounds how old a delivered event can be. **Moves whenever the residency cap moves** | [§ 10.3](#103-idempotency-and-the-dedup-window) |
| Spool residency cap | 8 days | Chosen — bounds residency by *age* rather than leaving it to fall out of a volume estimate, which a quiet seat would stretch past 50 days and out of the dedup window | [§ 11.3](#113-rotation-and-the-overflow-policy) |
| Batch-id memory | 24 h | Chosen — covers the retry ladder plus a same-day manual replay; bounded by the 6/min ceiling at ≤ 8,640 rows/seat/day | [§ 10.4](#104-batch-level-idempotency) |
| Spool bound | 32 MiB | Derived — ~6.4 days at the ceiling rate (33.55 MB ÷ 5.21 MB/day); satisfies "survives the server being down for days" at negligible disk cost | [§ 11.3](#113-rotation-and-the-overflow-policy) |
| Spool / journal bucket | 1 UTC hour | Chosen — removes rotation-rename races entirely; ~430 events ≈ 215 KB per spool bucket at the ceiling | [§ 11.1](#111-layout) |
| Index-journal fold cap | 8 MiB | Derived — ~9 days of a busy seat's index writes with no flusher at all; past it the fold truncates, counts, and badges rather than growing without bound | [§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open) |
| Open-call index | 64 open + 64 tombstones | Chosen — far above any observed concurrency; overflow is itself a signal | [§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open) |
| Tombstone retention | 15 min | Derived — equals the ordinary orphan timeout, so the reporter can name a call for as long as the server holds one open | [§ 12.5](#125-late-completions-and-orphan-timeouts) |
| Backoff base / factor / cap | 2 s / ×2 / 120 s, full jitter | Derived — 2→4→8→16 covers a ~30 s app restart; the 120 s cap bounds post-recovery staleness; jitter prevents a fleet-wide herd | [§ 11.5](#115-retry-and-backoff) |
| `Retry-After` clamp | 600 s | Chosen — honours the server without letting a bad header park a seat for hours | [§ 11.5](#115-retry-and-backoff) |
| Full-spool drain time | ~3.4 h | Derived — ~67,000 events (32 MiB ÷ 500 B) ÷ 20,000 events/hour. Nothing is lost; the seat renders *catching up* for that long | [§ 11.5](#115-retry-and-backoff) |
| Orphan timeout, ordinary | 15 min | Derived — the harness's documented 10-minute `Bash` ceiling + 50 %. **The ceiling is unverified against the installed build** | [§ 12.5](#125-late-completions-and-orphan-timeouts) |
| Orphan timeout, `Task` | 60 min | Chosen — 4× the ordinary; erring long is the safe direction | [§ 12.5](#125-late-completions-and-orphan-timeouts) |
| Rate limit, requests | 120/min/seat | Derived — 20× the 6/min healthy cadence | [§ 12.3](#123-rate-limits) |
| Rate limit, events | 20,000/h/seat | Derived — ~46× the ceiling's 434/h | [§ 12.3](#123-rate-limits) |
| Failed-auth limit | 60/h **per source IP** | Chosen — bounds log volume and CPU, nothing else; a per-token-string key could never bound guessing, because each attempt presents a fresh string | [§ 12.3](#123-rate-limits) |
| Quarantine caps | 256 KiB corrupt (stop writing) / 1 MiB rejected (stop writing) / 64 KiB marker (drop oldest) | Chosen — enough to diagnose, bounded so a broken seat cannot fill a disk; each with its at-cap behaviour stated | [§ 11.1](#111-layout) |
| Local log | 1 MiB/day, 2 days retained | Chosen — day-bucketed so retention needs no rename; two days spans a weekend-adjacent incident | [§ 11.1](#111-layout) |
| Sample-store retention | 24 h | Chosen — a session's last context sample is worthless the day after; the flusher unlinks older files | [§ 11.1](#111-layout) |

Three numbers rest on estimates rather than measurements and say so at their definition: the
busy-seat volume, the predicate-constant threshold, and the hook wall-time budget. Each names what
re-derives it, and each has ≥ 4× headroom in the direction that fails safely. Two more rest on
**UNVERIFIED harness or host facts** — the `Bash` timeout ceiling behind the 15-minute orphan window,
and nginx's body-size default behind the 256 KiB batch cap — and both carry their cost-if-wrong and
their closure act in [§ 6.0](#60-conventions-and-how-harness-payloads-are-read)'s table, which is the
one place this document tracks what it has not established.

---

## 15. Decisions taken, revisable at review

This document contains no placeholders and no deferred decisions. Where a call was genuinely
contestable it was **made**, and it is listed here with the alternative and the cost of being wrong, so review can
reverse it deliberately rather than discover it later.

Rows carrying a dated **Amended** or **Superseded** note were revised after an adversarial review;
the original decision is left standing in the row so the change is legible rather than erased. Rows
27–31 are the third round's, and **row 27 is the one the other four are consequences of.** Rows
32–37 are the fourth round's, and **row 35 is the one to read first**: round 3 bound the harness's
*key names* to the installed binary and built a guard for them, and rows 32, 34 and 36 are all what
the rung below that — value sets, and the document's own classification tables — cost while nothing
was checking it.

| # | Decision | Alternative considered | Why this one | Cost if wrong |
|---|---|---|---|---|
| 1 | **Identity repeats on every event**, with server-enforced equality against the batch header and the token binding | batch-level only, stamped onto events at ingest | an event is the durable, forwardable, quotable unit; ~60 B (12 %) buys unambiguity, and enforced equality makes drift impossible | ~12 % wire overhead. Reversible in one direction only: removing the fields later is a **schema bump** under the policy. **Amended 2026-08-23:** `schema_version` now rides every event on the identical argument — see row 19 |
| 2 | **`Stop` reaps every open call in its turn scope** as aborted | wait for the orphan timeout and let the server infer | a false idle at a turn boundary is the exact defect this design exists to prevent; waiting 15–60 min to notice defeats it | over-eager aborts on any legitimate call outstanding at `Stop`. **Amended 2026-08-23:** the late-completion override this row leans on now has a path — reaped entries are **tombstoned** for 15 min so a late close rejoins its original `call_id` ([§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)). Before that amendment the late close was synthesized under a fresh id and `late_completion` could never leave zero, so the instrument bounding this decision could not report. **Amended 2026-08-23 (round 4):** "its session" was always shorthand — the rule is scoped to `(session_id, agent_scope_id ?? "main")` and row 32 is why the scope key had to be split out of the binding field before that sentence was true |
| 3 | **The dispatch tool emits both `tool.start` and `subagent.spawn`** sharing a `call_id` | one `subagent.spawn` and no `tool.start` for the dispatch call | a special case in the call ledger would live in the *one* path the kill-vs-complete requirement is actually about | ~120 B per dispatch, tens of times a day |
| 4 | **Batches are atomic** — one bad event rejects 200 | per-event partial ingest with a report | a partially-ingested batch under a success status destroys the reporter's only other copy of the data | one malformed event costs ≤ 199 neighbours, bounded by the poison-pill rule. **Amended 2026-08-23:** this is precisely why an unknown `kind` or enum value must never be a validation failure (rows 20, and [§ 12.4](#124-batches-are-atomic)) |
| 5 | ~~A new event `kind` is compatible; unknown kinds are ignored and counted — **minted in D1** with a pointer saying it might belong in the policy~~ | reject the batch on an unknown kind | — | **Superseded 2026-08-23.** The rule was policy, not mechanic: it governs what any producer and any receiver may do without a bump. It now lives in [`docs/VERSIONING.md § Wire compatibility` rule 7](../VERSIONING.md#the-rules), extended to cover new closed-enum members too, and [§ 5](#5-compatibility--what-this-document-owes-the-policy) carries only the mechanics with a cite. One rule, one home |
| 6 | **`attention.request` exists**, ~~classified client-side from `Notification`~~ **mapped from the harness's `notification_type`** | omit it; let D2 derive *blocked* from its status tiers | `docs/PLAN.md § 7` requires *blocked* as a rendered state and no other hook supplies it | **Amended 2026-08-23 (round 2):** *blocked* is now a **pair** — `attention.request` / `attention.resolved` ([§ 6.13](#613-attentionresolved)). The original had an entry event and no exit event, so a seat entered *blocked* once and rendered it forever. **Amended 2026-08-23 (round 3):** the "knowingly-fragile classifier, instrumented rather than trusted" is **deleted**. It matched English wording against a payload that carries `notification_type` — a harness-supplied field naming the kind directly — so it would have returned `other` on every real notification and its watching predicate would have gone constant on day one. Instrumenting a fragile classifier is the wrong move when the field that removes the need for one is in the same payload (canon: read the field, do not instrument the guess). The hook is now also **gated** on that field, which is the one carve-out to [§ 6.0](#60-conventions-and-how-harness-payloads-are-read) rule 2 — see row 29 |
| 7 | **`turn.start` carries `prompt_chars`** | carry nothing about the prompt | a length is a size, not content, and distinguishes a nudge from a pasted brief | if review reads a length as content-adjacent, deleting the field is compatible |
| 8 | **The `lifo_tool_name` match fallback** exists at all | require a harness call reference; drop the close if absent | dropping a close would put an unmatched call into the ledger — the failure this design forbids | can swap two concurrent same-tool calls' ids and durations; **cannot** affect counts or outcomes, so `D2-MUST` #1 is untouched. **Amended 2026-08-23:** `tool_use_id` is documented on **both** `PreToolUse` and `PostToolUse`, so `harness_ref` is expected to win essentially always; the fallback is now a backstop whose *use* is a defect signal, and tombstone matching sits between it and synthesis |
| 9 | **The flusher is OS-supervised *and* hook-respawned** | hook-respawn only (no OS integration) | respawn-only means an idle seat stops heartbeating and renders `offline` while it is merely quiet — destroying the idle/offline distinction the product depends on | installer complexity on two platforms (card #7336) |
| 10 | **`GET /api/ingest/health` requires a seat token** | unauthenticated health surface | the accepted-version set is fleet-internal, and everyone who needs it already holds a token | an operator without a token must read the deployed declaration instead |
| 11 | **`session.end` is an observation from the `SessionEnd` hook**, with one inferred member (`inferred_silence`, 90 min) | keep the four-way inference (`cleared` / `superseded` / `compacted` / 6 h stale) that an earlier draft built on the premise that no session-end hook exists | the premise was false — `SessionEnd` exists with a documented `reason` set. Reading a session's end from the harness that ended it is strictly better than inferring it from three side effects | if `SessionEnd` does not fire on some path, that path closes on the 90-minute silence rule instead, and `session_reopened` says so. The inference did not become *less* available; it became the fallback |
| 12 | **Every reap and index rule is keyed by `session_id`**, and the supersede-on-different-session rule is deleted | keep reaping whenever a hook carries an unfamiliar `session_id` | two terminals on one seat is ordinary (`open_sessions` is bounded at 16), and under the old rule each session aborted the other's healthy calls, making *idle* unreachable on both and minting a `session.end` storm | a session that genuinely ends without a `SessionEnd` now waits for the 90-minute rule rather than being closed by its successor's first hook |
| 13 | **`tool.end.outcome` gains `failed`, from the `PostToolUseFailure` hook; `is_error` is deleted** | keep `outcome ∈ completed\|aborted` with a nullable `is_error` read from the payload | `PostToolUse` fires **only on success**, so without the failure hook every failed tool call stayed open until the `Stop` reap aborted it — and `D2-MUST` #1 then forbade *idle* on any turn containing one failed `Read`. On a real seat that is most turns, so *idle* was unreachable. The closing hook's identity is also a cleaner error indicator than a payload field nobody has verified | a third `outcome` value for consumers to handle, and one deleted field. AT-19 is the regression test |
| 14 | **The call index is an append-only journal folded over a flusher-written snapshot** | keep the shared `open-calls.json` rewritten `.tmp`+rename by every hook, or add OS advisory locks | tool calls run in parallel, so the old design was a lost-update generator with no lock and one fixed temp name: a forgotten open call can never be closed, and a healthy seat renders *working* until the orphan timeout. Locking in the hook path would put contention inside the 250 ms budget; appending is the primitive the spool already proves | a fold on every hook invocation (~1 ms per 100 KiB of tail) and a compaction duty for the flusher. Bounded, counted (`index_fold_truncated`), and tested by AT-10 |
| 15 | **Counters and predicates travel to the flusher through an hour-bucketed append-only sink** | let hook processes write `state.json` directly, or drop the counters that hooks compute | `state.json` is flusher-owned and the counters are computed in short-lived concurrent processes — the earlier draft specified both and reconciled neither, which left [§ 9.4](#94-the-predicate-constant-alarm)'s alarm unbuildable from this document. The sink reuses the spool's own primitive, so there is one concurrency discipline in the design rather than two | one extra small append per process exit, and counters up to one flush interval stale in a heartbeat. StatusLine-side counters remain a floor because the harness cancels renders — stated at [§ 9.3](#93-degradation-counters) rather than hidden |
| 16 | **A `state.json` reset re-sends from the OLDEST spool bucket** | keep the cursor jump to the newest bucket, or count the skipped lines as loss | the jump discarded up to a full spool — days of events — with no counter and no badge, while [§ 0](#0-overview) item 9 promises a counter for every discarded event. Re-sending is nearly free: dedup absorbs it, and the 10-day window exceeds the 8-day residency cap by design | one extra drain after a rare event, visible as a `duplicates` spike and an `epoch_reset` badge. AT-17 asserts the id-set equality |
| 17 | **Spool residency is capped by age (8 days) as well as by size (32 MiB)** | derive maximum residency from the size bound and the volume estimate alone | residency-from-size is rate-dependent, and the *quiet* seat is the dangerous one: at heartbeat-only volume a 32 MiB spool takes 50+ days to fill, so its oldest event would age out of the 10-day dedup window while still queued and be re-ingested as new. An age cap makes the dedup coupling exact and rate-independent | a quiet seat's week-old events are dropped and counted rather than kept; that is the same judgement the drop-oldest policy already makes |
| 18 | **Exactly one flusher runs per seat: `O_EXCL` lock plus an ownership check on `state.json`** | tolerate brief overlap and let server-side dedup absorb the duplicates | dedup absorbs *events*, not the `seq` counter: two flushers each reading `next_seq = X` produce either a gap (the seat renders `lossy` from nothing) or a duplicated `(seq_epoch, seq)` — the ordering key `D2-MUST` #4 makes load-bearing | a losing flusher exits silently (counted). The residual microsecond window is not assumed away: the server counts `seq_collision` |
| 19 | **`schema_version` rides every event as well as the batch** | keep it batch-only, or make D2 stamp it onto each event at ingest | the policy's rule 1 says *every event* carries it, and the stored event is what gets replayed, quoted and pasted; a field that tells a reader what the other fields **mean** is the last one to leave the durable unit. Making the store stamp it would put a compliance obligation in another document | ~20 B/event (~4 %). Equality with the batch is enforced, so it cannot drift |
| 20 | **An unrecognised closed-enum value is coerced to the field's unknown member and counted, at both ends** | pass the harness's value through verbatim and let the ingest validate strictly | verbatim pass-through plus atomic batches means one unannounced harness value (`SessionStart.source: "fork"` was exactly this, and is now a known member) destroys up to 200 good events and quarantines them permanently. Coercion costs one mislabelled field | a genuinely new harness state is rendered as `unknown` until this document is updated — visible in `enum_value_unknown.<field>`, which is the edit's trigger |
| 21 | **`agent_scope` is labelled from the documented `agent_id` payload field** | keep it permanently `null`, as an earlier draft did on the grounds that any presence-based inference repeats the 30-day outage | the outage was an **undocumented environment variable** with nothing watching it. This is a documented payload field, and it is watched: both branches ride the heartbeat and the predicate-constant alarm fires if it goes constant either way | if the harness starts or stops sending `agent_id` universally, the label is wrong until the alarm fires — which is precisely the instrument the incident lacked |
| 22 | **`SubagentStart` binds `agent_id` to the open dispatch call; `SubagentStop` closes the call it names** | the earlier narrow rule: close only when exactly one dispatch call is open, otherwise emit nothing | that rule designed a permanent loss into every parallel dispatch, on a flat assertion (with no cite) that `SubagentStop` identifies nothing | **Amended 2026-08-23 (round 3):** `SubagentStop` **does** carry `agent_id` — MEASURED, so the hedge it was parked behind is gone. In the same measurement the binding table's *first* row turned out to be **unreachable**, not merely unverified: `SubagentStart` carries no `tool_use_id` and no `parent_tool_use_id`, so the exact-reference rule could never fire and `agent_bind_ref` — billed as "the measurement that says which binding rule is carrying the fleet" — would have read a structural zero forever. That row and that counter are **deleted** rather than kept-and-hedged. The `sole_open` fallback survives with a stated reason it can still fire: a lost `bind` record after a torn index line. **Amended 2026-08-23 (round 4):** the bound id is now written to a field of its own, `child_agent_id`, and no longer overwrites the call's own scope — see row 32 |
| 23 | **`parent_call_id` on `tool.start`; the harness's `agent_id` never transits** | put `agent_id` on the wire and let the server do the join | the reporter already holds the binding, so resolving it locally sends a `call_id` the consumer already knows instead of a second opaque identifier — less wire surface, less PII-adjacent data, and an immediately usable join for the drill-down's interns | ~30 B on the highest-volume kind, and `null` where the binding failed (counted as `agent_bind_unresolved`) rather than a guess |
| 24 | **`compaction.end` carries no post-compaction percentage; `compaction.start`'s comes from the statusLine sample store with an age** | keep both `context_used_pct` fields as specified, or delete both | context percentages exist only in the statusLine payload, so as originally specified both fields were always `null`. The *pre*-compaction number is the interesting one and is available from the sample store; the *post* number is documented as unavailable (`current_usage` is null after a `/compact` until the next API call) and arrives seconds later as an ordinary `context.sample` | one cross-process read in the hook path, bounded by a 300 s staleness rule and reported with `context_used_pct_age_s` |
| 25 | **Every refusal is attributed to the token's binding; pre-auth refusals are attributed to nothing** | attribute to the batch's claimed `install_id`/`seat_id`, which the earlier draft's worked example did | the schema-version check runs *before* the identity-equality check, so any holder of any valid token could render a colleague's desk degraded by posting a bogus version naming their seat | a `415`/`413`/`400 malformed_body` cannot degrade any seat's badge, because no identity is established yet — it is counted globally and surfaced locally by the reporter instead |
| 26 | **Sanitizer rule order is part of the contract: paths are rewritten before blobs are redacted, and every fixture carries its rule trace** | keep the earlier order and maintain the fixture table by hand beside the rule table | the blob class `[A-Za-z0-9+/]` matches a long absolute path, so under the old order `Read: /home/…/IngestController.php` sanitized to `‹redacted:blob›.php` — a descriptor that answers nothing. Hand-maintained twins drift, which is how the draft shipped two fixtures no rule could produce | rule numbering moved, so every cross-reference to a rule number had to move with it. The trace column makes the next such change mechanical (AT-2 asserts it) |
| 27 | **Every harness fact carries one of three states — MEASURED / DOCS-CITED / UNVERIFIED — and MEASURED means a captured payload, vendored as a fixture, asserted by `selftest`** ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read), [§ 17](#17-appendix--the-captured-harness-payloads), [AT-21](#at-21-the-harness-fact-drift-guard)) | keep hand-transcribing from the vendor reference and fix the errors each review finds | **This is the round-3 class fix, and the evidence for it is the two rounds before it.** Round 1 found two transcribed hook facts wrong. The round-2 fix corrected those two instances — and built new designs on five more transcribed facts, which round 2 found wrong or absent. Per-instance correction had then failed twice, so the defect is not any instance: it is that **nothing bound the transcription to a source and nothing could red when they diverged** — a restatement with neither a pointer nor a guard. Round 3 stopped correcting instances first and landed the binding first. It immediately paid for itself: the capture found a defect no review had — the dispatch tool's payload `tool_name` is `"Agent"`, not `"Task"` (row 28) — and refuted three of round 2's own key-name findings, which a fourth round of transcription would have "fixed" into being wrong | the capture is a snapshot of one build on one OS. A fact that is true at 2.1.240 and false at 2.2.0 is MEASURED-and-wrong, which is why the version pin and the re-capture obligation are part of the rule and `harness_label` rides the wire. The residual is a harness upgrade nobody re-captures — which `harness_label` makes queryable and `payload_key_missing.<key>` makes visible, but only after deployment |
| 28 | **The subagent-dispatch hook matches `tool_name ∈ {"Agent", "Task"}`, counting which fired** | match `"Task"`, as every prior draft did | the payload says **`"Agent"`** at 2.1.240 (MEASURED). Matching `"Task"` alone would emit no `subagent.spawn` on any current seat, bind no `agent_id`, and render every dispatch as an ordinary tool call — the interns feature reading zero forever, from one transcribed string that no review round caught. Both names are matched because both are live across harness versions; `dispatch_tool_name.<name>` reports which the fleet actually sends | a future third name is missed until the counter's total diverges from the `subagent.spawn` count. That divergence is the observable, and it is cheap |
| 29 | **The `Notification` hook's emission is gated on `notification_type`; every suppressed type is counted individually** | emit an `attention.request` for every notification, per [§ 6.0](#60-conventions-and-how-harness-payloads-are-read) rule 2's never-suppress wording | the documented types include `auth_success`, `agent_completed` and the `quota_auto_resume_*` family — none of which is an agent waiting on a human. Unconditional emission would put a seat into *blocked* every time it **finished** something, which is the false-idle defect mirrored, and `D2-MUST` #5 makes `attention.request` the only source of *blocked*. Rule 2 is right for a *classification* and wrong for a hook that legitimately fires outside this design's subject; what [§ 3.4](#34-why-identity-never-comes-from-the-environment) actually requires is that no suppression is **silent**, and `notification_not_attention.<type>` satisfies that literally | a genuinely attention-bearing type this list misses opens no request until someone reads `enum_value_unknown.notification_type`. Adding a second carve-out to rule 2 is review-blocking, so the exception cannot spread quietly |
| 30 | **`StopFailure` is subscribed and emits `turn.end` with `end_reason: "api_error"`, which mints `stalled` — a state of its own, not `idle` and not `unknown`** | leave it unsubscribed, as every prior draft did, or fold it into `unknown` | unsubscribed, a rate-limited turn emits **no `turn.end` at all**: no reap, open calls to their orphan ceiling, and a desk rendering *working* for up to an hour — on the busiest seats, because those are the ones that get rate-limited. Folding it into `unknown` would then hide a rate-limited *fleet* behind the same state a killed subagent produces, and a rate-limited fleet is a thing an operator acts on | one more `turn.end.end_reason` member and one more rendered state for D2. `api_error_type` is DOCS-CITED, not MEASURED — provoking a real rate limit was not a cost worth paying — so the sub-classification is the part most likely to need correcting, and `enum_value_unknown.turn.end.api_error_type` is what will say so |
| 31 | **One bucket-deletion precondition on the shared primitive: `now ≥ bucket_end + grace`, `grace = 5 s`, writers re-derive the bucket name immediately before the `writeSync`, and no hook may drop the current bucket** | keep "a bucket older than the current UTC hour has no living writer", the argument the four append-only trees shared | that argument bounds a hook's **lifetime** and not its **straddle** of the hour boundary: a hook entering at `13:59:59.900` and writing at `14:00:00.050` writes into a bucket the flusher may unlink ≤ 10 s later. One primitive, four trees, four consequences — a lost counter delta (which would have flaked [AT-16](#at-16-the-counter-sink-survives-concurrency)'s exact-equality GREEN and been debugged as flakiness), a lost index record (a false `aborted` blocking *idle*), a lost **event** (breaking [§ 0](#0-overview) item 9 outright), and a cosmetic log gap. Fixing it once at the primitive is the only version of this fix worth making | one extra flush pass of retention per bucket, and a bounded overshoot of the spool size bound when the only over-bound bucket is the current one (`spool_overflow_deferred`) |

| 32 | **The call index carries two `agent_id` fields — `agent_scope_id` (the scope a call was opened in) and `child_agent_id` (the subagent a dispatch call spawned) — and the turn reap keys on the first** | ~~one `agent_id` field, `null` until `SubagentStart` bound it, read by both the binding and the reap~~ | One field could not hold both facts, and the two are *opposites* on the call that matters most. A dispatch call is opened in the main agent (scope `null`) and bound to the child's id a moment later; with one field the bind overwrote the scope, so the parent's own `Stop` — which carries no `agent_id` and therefore always scopes to `"main"` (MEASURED) — excluded the one call it most needed to close. The mirror case was as bad: a call opened *inside* a subagent had nowhere to record its scope, so the reap key for it was undefined, and the three places this document promises a permission-refused call "survives to the turn's reap" were false inside a subagent | **Superseded 2026-08-23 (round 4).** Two index fields instead of one, and one new reap row (row 33). The cost is one more field on every `open` record — ~20 B of a journal that is compacted every flush — against a dispatch call sitting open to its **60-minute** orphan ceiling with the seat rendered *working* the whole time, which is [§ 8.1](#81-the-problem-restated)'s defect reached through [§ 8.3](#83-the-reap-rules)'s own scoping rule. [AT-1](#at-1-kill-vs-complete-the-headline-test) case C is the regression test and its RED reproduces on every build |
| 33 | **`SubagentStop` reaps the subagent's own open calls, reusing `turn_boundary` / `reap_turn_boundary`** | leave them to the 15/60-minute orphan timeout, as every prior draft did by omission | `Stop` does not fire inside a subagent (MEASURED), so before this row **no rule in the design ever closed a call opened inside one** — the reap table had `SessionEnd`, `SessionStart`, `Stop`, `StopFailure`, session eviction and flusher restart, and every one of them either scopes to `"main"` or ends the whole session. The gap was invisible because it only shows on calls the harness never closes, which is exactly the permission-refused call the design already documents three times. A subagent completing **is** a turn boundary — of the subagent's turn — so no new enum member is needed | one extra index scan per `SubagentStop`, tens of times a day. Reusing the existing `abort_reason` and `close_source` members is deliberate: a new member of either would be a **rule-4 change**, a schema bump plus a stated window ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)), and no fact here needs one. If a later round wants to tell a subagent boundary from a main-agent one, `close_source` plus the event's own `agent_scope` already do it |
| 34 | **`reporter.heartbeat.degraded`'s member set is declared in [§ 9.3](#93-degradation-counters), twelve members, and the array's bound is that count** | ~~`array<enum>`, `0…16 elements`, pointing at [§ 9.3](#93-degradation-counters) for members it did not list~~ | A wire field typed `array<enum>` whose members are stated nowhere is not implementable: the ingest validates per-kind `data`, so a builder validates against a *guess*, and a guess that misses turns a degraded seat's heartbeat into a `422`, a rejected 200-event batch and a permanent quarantine — the liveness backstop dying on the seats that need it. The two examples in the document disagreed with each other (`rejected_batches` against `batches_rejected`), which is what a set with no home always ends in | **Superseded 2026-08-23 (round 4).** The member name follows the counter (`batches_rejected`), and the bound follows the table rather than being chosen. Cost if the twelve are wrong: a genuinely new degradation condition is a rule-4 change like any other reporter-minted member ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)). [AT-22](#at-22-a-maximally-degraded-seat-still-heartbeats) asserts the exact array and drives the guessed-member `422` as a RED |
| 35 | **One enum classification table over a population a tool re-derives, and every harness enum's value set is asserted against the installed binary on every run** | ~~two hand-written lists, each asserting it was "the complete list" of its side~~ | The two lists omitted **five** of the wire's 23 enum fields between them, so those five inherited neither rule 4 nor rule 7 and an editor adding a member to one had no rule to apply. That is [decision 27](#15-decisions-taken-revisable-at-review)'s own shape one rung down: round 3 bound *key names* to the binary and built a guard for them, and left *value sets* transcribed — which is how a `notification_type` row carrying 14 of the build's 16 declared members survived three reviews, omitting exactly the two the `elicitation` branch depends on. Correcting the row would have been instance N+1 | **Superseded 2026-08-23 (round 4).** `tools/design/verify-event-schema.py` re-derives the enum-row population from the field tables and fails on any row missing from the table; `tools/design/verify-harness-facts.py` re-derives each harness enum's value set from the installed binary and asserts every site this document states it at. Cost: two more checks in the gate, and a harness-sourced enum can no longer be added without a row in the enum-source table. The residual is unchanged from row 27 — a build nobody re-runs the tools against |
| 36 | **`stalled` has three stated exits and `notification_kind` has three members, not four** | ~~`stalled` with an entry edge and one sentence of exit; `notification_kind` carrying `other` as its unknown member~~ | Both were minted in round 3 and both are the shape this document names elsewhere and forbids. `stalled` had an entry edge and no bounded exit — and because the flusher heartbeats every 60 s regardless of session activity, a `stalled` seat never reaches `stale`, so one transient rate-limit rendered `stalled` for the rest of the day. `notification_kind.other` was the opposite defect: a member **no path can emit**, because the lookup's unrecognised row suppresses the event before rule 4 could coerce anything onto it — a render branch D2 and D3 would build and never reach, on a quarter of the field's surface | **Superseded 2026-08-23 (round 4).** `stalled` clears on the next `turn.start`, on `session.end` (including the 90-minute `inferred_silence` close, so the bound needs no new timer), or on the seat leaving live state; past a `session.end` it renders `unknown`. `other` is deleted and the unknown case lives where it actually is — the counter `enum_value_unknown.notification_type`, the one counter in this design named after a payload key rather than a wire field, stated as the single exception to the grammar. Cost: D2 gains one clearing rule and loses one dead branch |
| 37 | **The failed-authentication rate limit is evaluated inside [§ 12.1](#121-validation-order) step 4** | ~~step 5, with the other three limits~~ | `Validation order` states "the first failure wins": a request whose token resolves to nothing terminates at step 4 and never reaches step 5, so a limit whose entire subject is *failed* authentications was evaluated only on requests that had already authenticated. It could never return the `429` it declared. This is the same defect class [§ 12.3](#123-rate-limits) already congratulates itself for fixing in this limit's **key** — a counter keyed on the presented string never accumulates past 1 — arriving a second time through its **placement** | **Superseded 2026-08-23 (round 4).** One exception to the ordering, stated at both ends (step 4, and the limit's own row). Cost: the auth check now has a side effect and a second exit status, which is why the attribution table gains the `429` explicitly — it degrades no seat, because a token that resolves to nothing names none. [AT-6](#at-6-unknown-schema-version-is-refused-loudly) case B drives the 61st bad token and carries a distinct-IP negative control |

**One thing this document deliberately does not contain:** the accepted schema-version set. That set
lives in exactly one machine-readable place in the ingest's code and is reported by the health
surface, per [`docs/VERSIONING.md § Wire compatibility` rule 2](../VERSIONING.md#the-rules). Writing
it here would create a second statement of it, free to drift, with nothing binding the two together.

---

## 16. What an implementer builds from this

In dependency order, with the gate each must pass before the next is trusted.

| Order | Artifact | Gate |
|---|---|---|
| 0 | the captured fixtures ([§ 17](#17-appendix--the-captured-harness-payloads)) vendored, and `selftest`'s `harness_payload_keys` check over them | **AT-21** RED then GREEN — first, because every artifact below reads a harness payload and this is the only thing that reds when one moves |
| 1 | the sanitizer, standalone and pure | AT-2 RED then GREEN, both platforms |
| 2 | `hook` subcommand + spool writer + call-index journal + counter sink | AT-3, AT-9, AT-10, AT-16 |
| 3 | `flusher` subcommand + `state.json` + ownership + backoff | AT-4, AT-5, AT-17 |
| 4 | `statusline` subcommand + passthrough + sample store | AT-14 |
| 5 | ingest endpoint: auth, attribution, validation, atomic batch, dedup, enum coercion | AT-6, AT-12, AT-13, AT-15, AT-18 |
| 6 | server-side call ledger + orphan timeouts | AT-1 (**the gate on trusting the signal at all**), AT-11, AT-19 |
| 7 | staleness, predicate alarm, and the attention pair | AT-7, AT-8, AT-20, AT-22 |

Three of these are hard requirements before anything downstream may treat this telemetry as true:
**AT-1** (`docs/PLAN.md § 3`, card #7337 — a real `/clear` against a real subagent tool call); a
**real install on a Windows seat** (card #7336), because every file, path and process assumption in
[§ 11](#11-the-spool-and-the-flusher) is cross-platform by design and unproven until then; and
**AT-21**, because a schema this document transcribed from another product is only as good as the
check that reds when it moves — and this document has already shipped that transcription wrong twice.

**A re-capture is part of a harness upgrade, not a follow-up to one.** When a seat moves to a new
Claude Code minor version, re-run the capture rig, diff the fixtures, re-mark
[§ 6.0](#60-conventions-and-how-harness-payloads-are-read)'s table, and land all of it in one change.
`harness_label` on every `session.start` ([§ 6.1](#61-sessionstart)) is how the fleet is queried for
seats that have moved off the measured build.

---

## 17. Appendix — the captured harness payloads

**These are the measurement of record.** Every **MEASURED** row in
[§ 6.0](#60-conventions-and-how-harness-payloads-are-read) is read off this appendix, and
`selftest`'s `harness_payload_keys` check ([AT-21](#at-21-the-harness-fact-drift-guard)) asserts the
reporter against it. The reporter vendors them as `fixtures/hooks/<HookEventName>.json`
([§ 2.1](#21-one-file-four-subcommands)).

| | |
|---|---|
| Harness | **Claude Code 2.1.240** (`claude --version`) |
| Platform | Linux |
| Captured | **2026-08-23** |
| Volume | **56 payloads across 10 hook events** captured; **16 reproduced below**, one per distinct payload shape, across those same **10** hooks. The 16 and the 10 are re-derived from this appendix by `tools/design/verify-harness-facts.py` on every run and asserted against these numbers; the **56** is provenance for the capture run and is *not* checkable from this repo — see the note under [§ 6.0](#60-conventions-and-how-harness-payloads-are-read)'s fact table |
| Method | a project-local `.claude/settings.json` wiring each subscribed hook to a command that appends its raw stdin to a capture file, plus a `statusLine` entry doing the same; sessions then driven headlessly with `claude -p` |

**What was driven, and what could not be.** Driven: `SessionStart` at `startup`, `resume` and
`clear`; `UserPromptSubmit`; `PreToolUse`/`PostToolUse` in the main agent and inside a subagent;
`PostToolUseFailure` twice (a `Bash` exiting 3, and a `Read` of a missing path); `SubagentStart` and
`SubagentStop` via a real dispatch; `Stop`; `SessionEnd` at `clear` and `other`; `PreCompact` at
`manual`. **Not drivable on this seat, and DOCS-CITED instead:** `StopFailure` (needs a real API
error), `Notification`, `PermissionRequest` and `PermissionDenied` (need an interactive surface —
`claude -p` cannot show a prompt), `PostCompact` (the scratch session had too little history to
compact), and the **statusLine payload** (`-p` renders no status line). Each of those carries its
cost-if-wrong and its closure act in [§ 6.0](#60-conventions-and-how-harness-payloads-are-read)'s
table; none is silently absent.

**Sanitization applied to this appendix only.** Session ids, prompt ids, tool-use ids and agent ids
are replaced with stable placeholders of the **same shape and length**; `cwd` and `transcript_path`
are rewritten to a generic home; prompt and assistant text is replaced with a marker. Key names,
key *presence*, value *types* and every enum value are verbatim — those are the facts the fixtures
exist to pin, and none of them was touched.

**SessionStart (source=startup)**

```json
{"session_id":"11111111-2222-4333-8444-000000000000","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000000.jsonl","cwd":"/home/agent/proj","hook_event_name":"SessionStart","source":"startup"}
```

**SessionStart (source=resume)**

```json
{"session_id":"11111111-2222-4333-8444-000000000001","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000001.jsonl","cwd":"/home/agent/proj","hook_event_name":"SessionStart","source":"resume"}
```

**SessionStart (source=clear)**

```json
{"session_id":"11111111-2222-4333-8444-000000000002","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000002.jsonl","cwd":"/home/agent/proj","hook_event_name":"SessionStart","source":"clear"}
```

**UserPromptSubmit**

```json
{"session_id":"11111111-2222-4333-8444-000000000000","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000000.jsonl","cwd":"/home/agent/proj","prompt_id":"aaaaaaaa-bbbb-4ccc-8ddd-000000000000","permission_mode":"acceptEdits","hook_event_name":"UserPromptSubmit","prompt":"<prompt text, never transits>"}
```

**PreToolUse (main agent, Bash)**

```json
{"session_id":"11111111-2222-4333-8444-000000000000","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000000.jsonl","cwd":"/home/agent/proj","prompt_id":"aaaaaaaa-bbbb-4ccc-8ddd-000000000000","permission_mode":"acceptEdits","effort":{"level":"high"},"hook_event_name":"PreToolUse","tool_name":"Bash","tool_input":{"command":"echo hello-mezzanine","description":"Echo test string"},"tool_use_id":"toolu_01FIXTURE0000000000"}
```

**PostToolUse (main agent, Bash)**

```json
{"session_id":"11111111-2222-4333-8444-000000000000","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000000.jsonl","cwd":"/home/agent/proj","prompt_id":"aaaaaaaa-bbbb-4ccc-8ddd-000000000000","permission_mode":"acceptEdits","effort":{"level":"high"},"hook_event_name":"PostToolUse","tool_name":"Bash","tool_input":{"command":"echo hello-mezzanine","description":"Echo test string"},"tool_response":{"stdout":"hello-mezzanine","stderr":"","interrupted":false,"isImage":false,"noOutputExpected":false},"tool_use_id":"toolu_01FIXTURE0000000000","duration_ms":251}
```

**PostToolUseFailure (Bash, exit 3)**

```json
{"session_id":"11111111-2222-4333-8444-000000000003","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000003.jsonl","cwd":"/home/agent/proj","prompt_id":"aaaaaaaa-bbbb-4ccc-8ddd-000000000001","permission_mode":"acceptEdits","effort":{"level":"high"},"hook_event_name":"PostToolUseFailure","tool_name":"Bash","tool_input":{"command":"exit 3","description":"Exit with status 3"},"tool_use_id":"toolu_01FIXTURE0000000001","error":"Exit code 3","is_interrupt":false,"duration_ms":260}
```

**PostToolUseFailure (Read, missing file)**

```json
{"session_id":"11111111-2222-4333-8444-000000000003","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000003.jsonl","cwd":"/home/agent/proj","prompt_id":"aaaaaaaa-bbbb-4ccc-8ddd-000000000001","permission_mode":"acceptEdits","effort":{"level":"high"},"hook_event_name":"PostToolUseFailure","tool_name":"Read","tool_input":{"file_path":"/nonexistent/definitely/missing.txt"},"tool_use_id":"toolu_01FIXTURE0000000002","error":"File does not exist. Note: your current working directory is /home/agent/proj.","is_interrupt":false,"duration_ms":18}
```

**PreToolUse (subagent dispatch)**

```json
{"session_id":"11111111-2222-4333-8444-000000000001","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000001.jsonl","cwd":"/home/agent/proj","prompt_id":"aaaaaaaa-bbbb-4ccc-8ddd-000000000002","permission_mode":"acceptEdits","effort":{"level":"high"},"hook_event_name":"PreToolUse","tool_name":"Agent","tool_input":{"description":"count files","prompt":"Run bash: ls /etc | wc -l and report the number. Nothing else.","subagent_type":"Explore","run_in_background":false},"tool_use_id":"toolu_01FIXTURE0000000003"}
```

**SubagentStart**

```json
{"session_id":"11111111-2222-4333-8444-000000000001","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000001.jsonl","cwd":"/home/agent/proj","prompt_id":"aaaaaaaa-bbbb-4ccc-8ddd-000000000002","agent_id":"00000000000000000","agent_type":"Explore","hook_event_name":"SubagentStart"}
```

**PreToolUse (inside a subagent)**

```json
{"session_id":"11111111-2222-4333-8444-000000000001","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000001.jsonl","cwd":"/home/agent/proj","prompt_id":"aaaaaaaa-bbbb-4ccc-8ddd-000000000002","permission_mode":"acceptEdits","agent_id":"00000000000000000","agent_type":"Explore","effort":{"level":"high"},"hook_event_name":"PreToolUse","tool_name":"Bash","tool_input":{"command":"ls /etc | wc -l","description":"Count entries in /etc"},"tool_use_id":"toolu_01FIXTURE0000000004"}
```

**SubagentStop**

```json
{"session_id":"11111111-2222-4333-8444-000000000001","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000001.jsonl","cwd":"/home/agent/proj","prompt_id":"aaaaaaaa-bbbb-4ccc-8ddd-000000000002","permission_mode":"acceptEdits","agent_id":"00000000000000000","agent_type":"Explore","effort":{"level":"high"},"hook_event_name":"SubagentStop","stop_hook_active":false,"agent_transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000001/subagents/agent-00000000000000000.jsonl","last_assistant_message":"<assistant text, never transits>","background_tasks":[],"session_crons":[]}
```

**Stop**

```json
{"session_id":"11111111-2222-4333-8444-000000000000","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000000.jsonl","cwd":"/home/agent/proj","prompt_id":"aaaaaaaa-bbbb-4ccc-8ddd-000000000000","permission_mode":"acceptEdits","effort":{"level":"high"},"hook_event_name":"Stop","stop_hook_active":false,"last_assistant_message":"<assistant text, never transits>","background_tasks":[],"session_crons":[]}
```

**SessionEnd (reason=clear)**

```json
{"session_id":"11111111-2222-4333-8444-000000000004","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000004.jsonl","cwd":"/home/agent/proj","prompt_id":"aaaaaaaa-bbbb-4ccc-8ddd-000000000003","hook_event_name":"SessionEnd","reason":"clear"}
```

**SessionEnd (reason=other)**

```json
{"session_id":"11111111-2222-4333-8444-000000000000","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000000.jsonl","cwd":"/home/agent/proj","prompt_id":"aaaaaaaa-bbbb-4ccc-8ddd-000000000000","hook_event_name":"SessionEnd","reason":"other"}
```

**PreCompact (trigger=manual)**

```json
{"session_id":"11111111-2222-4333-8444-000000000005","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000005.jsonl","cwd":"/home/agent/proj","prompt_id":"aaaaaaaa-bbbb-4ccc-8ddd-000000000004","hook_event_name":"PreCompact","trigger":"manual","custom_instructions":null}
```

### 17.1 DOCS-CITED stubs — the five hooks that could not be driven

**These are not measurements.** Each carries exactly the key set the installed build's own payload
schema declares for that hook (read from the binary, 2026-08-23), with placeholder values, so that
`harness_payload_keys` ([AT-21](#at-21-the-harness-fact-drift-guard)) has something to assert against
for every subscribed hook rather than skipping five of them. The vendored files carry
`"_source": "docs-cited-stub"`; the captures above carry `"_source": "capture"`. **Replacing one of
these with a real payload is the closure act for its row in
[§ 6.0](#60-conventions-and-how-harness-payloads-are-read)** — and the first seat to hit a real rate
limit, permission prompt or auto-denial can supply it.

**StopFailure** — *DOCS-CITED stub, **not** a capture*

```json
{"session_id":"11111111-2222-4333-8444-000000000009","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000009.jsonl","cwd":"/home/agent/proj","hook_event_name":"StopFailure","error":"rate_limit","error_details":null,"last_assistant_message":null}
```

**Notification** — *DOCS-CITED stub, **not** a capture*

```json
{"session_id":"11111111-2222-4333-8444-000000000009","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000009.jsonl","cwd":"/home/agent/proj","hook_event_name":"Notification","notification_type":"permission_prompt","message":"<notification text, never read>","title":null}
```

**PermissionRequest** — *DOCS-CITED stub, **not** a capture*

```json
{"session_id":"11111111-2222-4333-8444-000000000009","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000009.jsonl","cwd":"/home/agent/proj","hook_event_name":"PermissionRequest","tool_name":"Bash","tool_input":{"command":"<never read>"},"permission_suggestions":null}
```

**PermissionDenied** — *DOCS-CITED stub, **not** a capture*

```json
{"session_id":"11111111-2222-4333-8444-000000000009","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000009.jsonl","cwd":"/home/agent/proj","hook_event_name":"PermissionDenied","tool_name":"Bash","tool_input":{"command":"<never read>"},"tool_use_id":"toolu_01FIXTURE0000000009","reason":"auto-mode denial"}
```

**PostCompact** — *DOCS-CITED stub, **not** a capture*

```json
{"session_id":"11111111-2222-4333-8444-000000000009","transcript_path":"~/.claude/projects/-home-agent-proj/11111111-2222-4333-8444-000000000009.jsonl","cwd":"/home/agent/proj","hook_event_name":"PostCompact","trigger":"auto","compact_summary":"<summary, never read>"}
```

### 17.2 Two facts visible only in the ordering

**Two facts that are visible only in the ordering, not in any single payload**, and that
[§ 6.8](#68-subagentstop) and [§ 8.4](#84-detecting-a-clear-with-two-independent-signals) rest on:

```
one turn dispatching one subagent, in fired order:
  SessionStart(startup) · UserPromptSubmit · PreToolUse(Agent) · SubagentStart
  · PreToolUse(Bash, agent_id=…) · PostToolUse(Bash, agent_id=…) · SubagentStop
  · PostToolUse(Agent) · Stop · SessionEnd(other)
```

Exactly **one** `Stop`, at the end — not one per subagent. `SubagentStop` precedes the dispatch
call's own `PostToolUse`.

```
one /clear, in fired order, with elapsed time and session id:
  T+0ms     SessionEnd   reason=clear   session_id=d867abf5…
  T+144ms   SessionStart source=clear   session_id=d8f4ac95…   (a NEW id)
```

`SessionEnd` first, by 144 ms; the new session carries a **different** `session_id`, and the
`SessionStart` payload contains no reference to the old one. A `resume`, by contrast, fires
`SessionStart(source=resume)` under the **same** `session_id`.

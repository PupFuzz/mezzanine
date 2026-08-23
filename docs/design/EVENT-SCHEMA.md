# D1 — the wire event schema

**`fleet-reporter` → Mezzanine ingest.** The contract every seat POSTs and the server accepts.

> **Status: Draft — pending design review.** Owner: aimla-pm. Gate: [`docs/PLAN.md § 2`](../PLAN.md#2-design-first-gates--the-order-is-the-plan)
> (P0 design, board 14). Written to the **standalone-implementer standard (D-14)**: an agent holding
> only this file must be able to build both ends. Nothing here is built yet — `fleet-reporter/` and
> the ingest route do not exist in this repo. Every number below carries its derivation; where a
> derivation rests on a value nobody has measured yet, it says so and names what to measure.
> Decisions a reviewer is most likely to contest are collected in [§ 15](#15-decisions-taken-revisable-at-review),
> not scattered — and none is left as a placeholder: each is **decided**, and review may reverse it.

---

## 0. Overview

1. `fleet-reporter` is one zero-dependency Node.js file (Node ≥ 18) installed on every agent machine,
   Linux and Windows, invoked by Claude Code **hooks** and by the **statusLine** integration.
2. A hook invocation does exactly three things: read stdin, append one line to a local spool file,
   exit 0. It never opens a socket, never blocks, and never exits non-zero.
3. A separate long-lived **flusher** process on the same machine reads the spool and POSTs batches
   over **HTTPS** to the ingest — the server is on a different physical host, always ([§ 3.5](#35-transport-is-wan-always)).
4. The payload is **minimized at the reporter** (D-06): tool name plus a 200-byte sanitized
   descriptor, turn boundaries, context percentage, subagent task titles. Arguments and outputs
   never transit, so a misbehaving server cannot be handed a secret it was never sent.
5. Thirteen event kinds ([§ 6](#6-event-kinds)) cover session, turn, tool, subagent, compaction,
   context, attention and reporter-liveness.
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

```
  Claude Code seat (Linux or Windows)                    │ WAN, TLS 1.2+ │   Mezzanine host
  ─────────────────────────────────────────────────────  │               │  ────────────────
  SessionStart ─┐                                        │               │
  UserPrompt   ─┤                                        │               │
  Pre/PostTool ─┼─▶ fleet-reporter.js hook <Name>        │               │
  Stop         ─┤     · read stdin JSON                  │               │
  SubagentStop ─┤     · sanitize (allowlist + redact)    │               │
  Pre/PostComp ─┤     · ONE append, ONE line             │               │
  Notification ─┤     · exit 0, always, ≤ 250 ms         │               │
  statusLine   ─┘              │                         │               │
                               ▼                         │               │
                     spool/<YYYYMMDDHH>.jsonl  ──▶  fleet-reporter.js flusher
                     (≤ 32 MiB, drop oldest)            · batch ≤ 200 ev / 256 KiB
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
| **PII beyond `install_id` / `seat_id`** | No prompt text, no file contents, no OS usernames, no hostnames, no email addresses, no IP addresses. Usernames leak through absolute paths, which is why [§ 7.3 rule 8](#73-redaction-rules-applied-in-this-order) rewrites them. |
| **The storage schema, retention, and state model** | D2 (`docs/design/FLEET-STATE.md`). This doc says what arrives and what it *means*; D2 says what is kept. Where D1 constrains D2 it is marked **`D2-MUST`** and there are exactly four such constraints ([§ 12.6](#126-the-four-d2-must-constraints)). |
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
| `node fleet-reporter.js statusline` | one-shot, fires on every status-line render | sample context ([§ 6.11](#611-contextsample)), **pass the wrapped status line through to stdout**, exit 0 |
| `node fleet-reporter.js flusher` | long-lived, one per seat | own the spool cursor, POST batches, emit heartbeats |
| `node fleet-reporter.js selftest` | one-shot, run by the installer and by CI | assert config, TLS reachability, accepted schema set, sanitizer fixtures, predicate discrimination |

Hook wiring lives in the seat's Claude Code settings. The shape below is illustrative — **the
implementer verifies the settings key names against the installed harness's own hook documentation
before writing the installer**; the reporter's actual contract is narrower and stable: *it is invoked
with the hook name as `argv[2]` and the hook's JSON payload on stdin.*

```json
{
  "hooks": {
    "PreToolUse": [
      { "matcher": "*", "hooks": [
        { "type": "command", "command": "node ~/.local/share/fleet-reporter/fleet-reporter.js hook PreToolUse" } ] }
    ]
  },
  "statusLine": {
    "type": "command",
    "command": "node ~/.local/share/fleet-reporter/fleet-reporter.js statusline"
  }
}
```

### 2.2 Rules that protect the seat

These are absolute. A violation of any of them is a defect even if telemetry is perfect.

| # | Rule | Mechanism | Failure if broken |
|---|---|---|---|
| P-1 | **Always exit 0**, on every path including a crash | top-level `try/catch` around everything; `process.exit(0)` in a `finally` | a non-zero `PreToolUse` exit is a harness *decision* signal — the reporter would start blocking the agent's tool calls |
| P-2 | **Emit no JSON on stdout from a `hook` invocation** | the `hook` subcommand writes nothing to stdout, ever | the harness reads hook stdout as control output; stray output changes agent behaviour |
| P-3 | **No network in the hook path** | the `hook` subcommand contains no HTTP client call | a WAN round-trip inside a hook adds ≥ 100 ms to every tool call on the seat |
| P-4 | **One synchronous append, then exit** | `fs.writeSync` on a descriptor opened `'a'`; no `await` after the write | an event-loop hang holds the seat |
| P-5 | **p99 hook wall time < 250 ms** | measured by AT-3 | see AT-3 |
| P-6 | **Never print a token or a raw payload** | the config's `token` is redacted in every diagnostic path; raw hook stdin is never logged | a transcript, a log or an `argv` is a secret-exfiltration surface |
| P-7 | **The flusher is spawned detached** | `spawn(..., { detached: true, stdio: 'ignore' }).unref()` | the hook would wait on the flusher's lifetime |

**Budget derivation for P-5 (250 ms).** Node 18 cold start on a modern machine is 30–60 ms and
dominates; the reporter's own work is one `JSON.parse` of a payload under 1 MiB, a few regexes over
≤ 2 KiB of text, one small file read and one append — all under 5 ms. 250 ms is ~4× the expected
worst case, and is under the ~300 ms at which a human notices added latency between tool calls.
**This is a budget to verify, not a measurement**: AT-3 measures it on both platforms, and if a real
seat exceeds it, the fix is in the reporter, not in the number.

### 2.3 The flusher must be alive whenever the seat is

The heartbeat is only a liveness signal if its absence means something. Two mechanisms, both required:

1. **Supervised start** — the installer registers the flusher with the OS: a `systemd --user` unit on
   Linux, a Scheduled Task at logon on Windows. (Mechanics belong to the installer card, #7336; the
   *contract* is here.)
2. **Opportunistic respawn** — every `hook` invocation checks `spool/flusher.lock`. If the lock is
   absent, or its `mtime` is older than **90 s**, the hook spawns a detached flusher and rewrites the
   lock. Derivation of 90 s: the flusher touches the lock once per heartbeat (60 s), so 90 s = 1.5
   heartbeat intervals — long enough that a flusher busy in a 15 s POST is never declared dead,
   and under two heartbeat intervals, so a crashed flusher is replaced by the next hook fire.

The lock file contains `{"pid":…,"started_at":…,"seq_epoch":…}` and is advisory only. Two flushers
briefly overlapping is a tolerated state: both write the same `state.json` atomically and the
duplicate events they may send are absorbed by server-side dedup ([§ 10.3](#103-idempotency-and-the-dedup-window)).
A flusher that finds a lock with an `mtime` newer than 90 s exits 0 immediately.

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
> the whole batch is rejected, the refusal is counted per token, and the seat renders degraded.
> A payload cannot name itself into another desk.

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
| `reporter_platform` | enum | — | no | `linux` \| `win32` \| `darwin` | `"linux"` |
| `runtime_version` | string | — | no | ≤ 24 B | `"v22.11.0"` |
| `seq_epoch` | ULID | — | no | 26 chars | `"01K3T0000A5N7M2X9V4B6D0FGH"` |
| `sent_at` | rfc3339_ms | UTC | no | — | `"2026-08-23T14:07:11.482Z"` |
| `events` | array | — | no | 1…200 elements | see [§ 4.5](#45-worked-batch-example) |

`schema_version` sits on the **batch**, not on each event: a batch is produced by one reporter
process at one version, so a mixed-version batch cannot arise on the wire. The one case that could
produce mixed data — a reporter upgraded while events from the older build are still spooled — is
handled inside the spool, which stores the version per line and makes the flusher split batches at
version boundaries ([§ 11.2](#112-spool-line-format)). That split is exactly what the `N`/`N-1`
support window exists to absorb.

### 4.3 Common per-event fields

Present on **every** event of every kind.

| Field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `event_id` | ULID | — | no | 26 chars | `"01K3T8ZQ6P2R4S8T0VWXYZ1234"` |
| `kind` | enum | — | no | one of the 13 in [§ 6](#6-event-kinds), ≤ 32 B | `"tool.start"` |
| `event_time` | rfc3339_ms | UTC, seat clock | no | — | `"2026-08-23T14:07:03.118Z"` |
| `seq` | int | — | no | 1…2^53−1, monotonic per `seq_epoch` | `48211` |
| `install_id` | slug | — | no | ≤ 32 B, **must equal the batch's** | `"aimla"` |
| `seat_id` | slug | — | no | ≤ 48 B, **must equal the batch's** | `"aimla-pm"` |
| `session_id` | string | — | **yes** (null only on `reporter.heartbeat`) | ≤ 128 B | `"e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913"` |
| `data` | object | — | no | kind-specific, ≤ 3 KiB serialized | `{ … }` |

**Why identity repeats on every event when the batch already carries it.** The batch is a transport
frame that is dissolved at ingest; the event is the durable unit and gets stored, forwarded, replayed
in a test fixture, and pasted into a bug report on its own. A self-describing event costs ~60 bytes
(≈ 12 % of a 500 B event) and removes an entire class of "which desk was this?" ambiguity downstream.
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
| Batch body, uncompressed | **256 KiB** | binds before the 200-event cap in the worst case (200 × 4 KiB = 800 KiB). 256 KiB is 4× under the tightest common default in a Laravel stack — nginx `client_max_body_size` 1 MiB (PHP's `post_max_size` default 8 MiB is looser) — so a stock reverse proxy never silently `413`s a healthy seat |
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
        "agent_scope": null,
        "harness_call_ref": "toolu_01A9F3kQ2mZ",
        "open_calls_before": 0
      }
    },
    {
      "event_id": "01K3T8ZQ8R4T6V0W2XYZ345678",
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
        "is_error": false,
        "duration_ms": 1347,
        "close_source": "post_tool_use",
        "match": "harness_ref"
      }
    },
    {
      "event_id": "01K3T8ZQ9S5V7W1X3YZ4567890",
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
**owns the versioning and compatibility policy.** It is not restated here. This section records only
how the fields above comply, and resolves the one mechanic the policy does not name.

| Policy rule | How D1 complies |
|---|---|
| explicit `schema_version` on every event's envelope | [§ 4.2](#42-batch-envelope-fields); a batch without it is `400 malformed_body` — invalid input, not a legacy payload |
| ingest declares its accepted set in one machine-readable place | `GET /api/ingest/health` surfaces that declaration; **this doc names no accepted set**, deliberately |
| unknown / aged-out version ⇒ loud reject, never silent drop | [§ 12.2](#122-error-responses) `400 unsupported_schema_version`, with received version and accepted set in the body; counted per seat; seat rendered degraded; reporter writes `REJECTED.txt` and quarantines |
| adding an optional field is compatible | the server **ignores unknown fields** at a known version and counts them; the reporter defaults absent optional fields to `null` |
| removing / renaming / retyping / **re-meaning** a field ⇒ version bump + window | binding on every future edit of [§ 6](#6-event-kinds). The re-meaning case is the one to fear: it passes every validator |
| support window is `N` and `N-1` | the flusher may hold events for up to ~8 days ([§ 11.3](#113-rotation-and-the-overflow-policy)); the window is what lets a mid-spool reporter upgrade drain cleanly |

**The one mechanic the policy does not name: a new event `kind`.** D1 reads a new kind as the exact
analogue of an added optional field — a new thing no existing consumer depended on — and therefore
**compatible without a version bump**. An unknown `kind` at an accepted version is **ignored and
counted**, never a batch rejection, because rejecting the batch would discard the known events beside
it. The count is surfaced per seat and renders the seat as `reporter_ahead` (informational), so the
handling is visible rather than silent. *Changing* an existing kind's meaning or fields remains a
bump under rule 4. If review reads this as policy rather than mechanic, it belongs in
`docs/VERSIONING.md` as an amendment — not duplicated into both files.

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

**Reading the harness payload — defensively, always.** The key names below (`session_id`,
`hook_event_name`, `tool_name`, `tool_input`, `tool_response`, `source`, `trigger`, `message`) are
what Claude Code 2.1.x emits on stdin to hooks. **The implementer verifies each against the installed
harness's own hook documentation before shipping** — this table is a design input, not a source of
truth about someone else's product. The binding rules are:

1. A missing or unexpected key yields `null` in the event and increments
   `payload_key_missing.<key>` ([§ 9.3](#93-degradation-counters)). **It never suppresses the event.**
2. No branch of any payload read decides *whether* to emit — only *what to label*.
3. The hook name arrives twice (`argv[2]` and `hook_event_name`). The reporter uses `argv[2]`, and a
   disagreement increments `hook_name_mismatch`. This is a free discriminating check on the assumption
   that the payload's own labelling is what we think it is.

**The kind table.**

| Kind | Trigger | Emitted by | Typical volume, busy seat-day |
|---|---|---|---|
| `session.start` | `SessionStart` hook | hook | 5–40 |
| `session.end` | inferred at a session transition, or 6 h of silence | hook / flusher | 5–40 |
| `turn.start` | `UserPromptSubmit` hook | hook | 200–600 |
| `turn.end` | `Stop` hook, or a session boundary with a turn open | hook | 200–600 |
| `tool.start` | `PreToolUse` hook | hook | 1,000–3,000 |
| `tool.end` | `PostToolUse` hook, or a reap ([§ 8.3](#83-the-reap-rules)) | hook | 1,000–3,000 |
| `subagent.spawn` | `PreToolUse` where `tool_name == "Task"` | hook | 5–60 |
| `subagent.stop` | the Task call's close, from any close source | hook | 5–60 |
| `compaction.start` | `PreCompact` hook | hook | 2–20 |
| `compaction.end` | `PostCompact` hook, or `SessionStart(source=compact)` | hook | 2–20 |
| `context.sample` | statusLine render, **sampled** | statusline | ≤ 1,440 |
| `attention.request` | `Notification` hook | hook | 0–50 |
| `reporter.heartbeat` | flusher timer, every 60 s | flusher | 1,440 |

Volumes are **estimates for sizing, not measurements** — no seat has been instrumented yet. They are
used only to derive the spool and rate-limit numbers in [§ 14](#14-every-number-and-where-it-comes-from),
and the first week of real data re-derives them. The sizing has ≥ 4× headroom against every one.

### 6.1 `session.start`

**Trigger:** the `SessionStart` hook, unconditionally. Before emitting, the hook runs the reap
([§ 8.3](#83-the-reap-rules)), so any calls left open by the previous session are already closed as
aborted and appear *earlier* in the spool.

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `source` | enum | — | no | `startup` \| `resume` \| `clear` \| `compact` \| `unknown` | `"clear"` |
| `project_label` | string | — | yes | ≤ 48 B, sanitized basename of cwd | `"mezzanine"` |
| `harness_label` | string | — | yes | ≤ 32 B, `^[A-Za-z0-9._-]+$` | `"claude-code/2.1.219"` |
| `previous_session_id` | string | — | yes | ≤ 128 B | `"e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913"` |

`source` is `unknown` when the payload key is absent — never silently `startup`, because
`startup`-vs-`clear` is load-bearing for [§ 8](#8-call-lifecycle--the-kill-vs-complete-contract) and a
wrong-but-plausible default would hide exactly the case this design exists to catch.

```json
{ "event_id":"01K3TA1B2C3D4E5F6G7H8J9K0M","kind":"session.start",
  "event_time":"2026-08-23T14:22:40.201Z","seq":48310,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"source":"clear","project_label":"mezzanine","harness_label":"claude-code/2.1.219",
          "previous_session_id":"e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913"} }
```

### 6.2 `session.end`

**Trigger:** *inferred.* The harness hook set used here exposes no session-end hook, so this event is
never a direct observation, and the server must treat it as an inference. It is emitted when:

| `end_reason` | Condition |
|---|---|
| `cleared` | a `SessionStart` arrives with `source == "clear"` |
| `superseded` | a hook arrives carrying a `session_id` different from the index's current one |
| `compacted` | a `SessionStart` arrives with `source == "compact"` |
| `inferred_stale` | the flusher sees no event for a session for **6 h** |

6 h derivation: it must exceed any plausible legitimate gap — an overnight unattended run, a seat left
open over lunch — while still closing sessions orphaned by a hard reboot. The cost of being late is a
session row that lingers (D2 ages it out); the cost of being early is a desk that vanishes while its
agent is thinking. Asymmetric, so the number is generous.

| `data` field | Type | Null? | Bounds | Example |
|---|---|---|---|---|
| `end_reason` | enum | no | `cleared` \| `superseded` \| `compacted` \| `inferred_stale` | `"cleared"` |
| `duration_ms` | int | yes | ≥ 0; `null` if the start was not observed | `938204` |
| `turns` | int | yes | ≥ 0, reporter's count for this session | `14` |
| `aborted_calls` | int | no | ≥ 0, calls reaped as aborted at this boundary | `1` |

```json
{ "event_id":"01K3TA1B1A2B3C4D5E6F7G8H9J","kind":"session.end",
  "event_time":"2026-08-23T14:22:40.198Z","seq":48309,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913",
  "data":{"end_reason":"cleared","duration_ms":938204,"turns":14,"aborted_calls":1} }
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

```json
{ "event_id":"01K3TA2C3D4E5F6G7H8J9K0M1N","kind":"turn.start",
  "event_time":"2026-08-23T14:23:02.660Z","seq":48311,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"prompt_chars":412,"project_label":"mezzanine"} }
```

### 6.4 `turn.end`

**Trigger:** the `Stop` hook (`end_reason: "stop_hook"`), or a session boundary reached with a turn
still open (`session_cleared` / `session_superseded`). **This is the event a consumer reads to mint
"idle", and the only one.**

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `end_reason` | enum | — | no | `stop_hook` \| `session_cleared` \| `session_superseded` | `"stop_hook"` |
| `duration_ms` | int | ms | yes | ≥ 0; `null` if no `turn.start` was seen | `41880` |
| `open_calls_at_end` | int | — | no | 0…64; counted **before** the reap closed them | `0` |
| `aborted_call_ids` | array\<ULID\> | — | no | 0…64 elements | `[]` |
| `stop_hook_active` | bool | — | yes | — | `false` |
| `tool_calls` | int | — | no | ≥ 0, calls started in this turn | `6` |

`aborted_call_ids` names exactly the calls `open_calls_at_end` counted, so the two never disagree —
the reap ([§ 8.3](#83-the-reap-rules)) emits their closes immediately before this event.

> **`D2-MUST` #1 — the idle rule.** A consumer may mint an *idle* transition **only** from a
> `turn.end` with `end_reason == "stop_hook"` **and** `aborted_call_ids == []`. Every other
> combination means the turn stopped for a reason other than the agent finishing, and the seat's
> state is `unknown`, never `idle`. This one sentence is what the kill-vs-complete machinery in
> [§ 8](#8-call-lifecycle--the-kill-vs-complete-contract) exists to make checkable.

```json
{ "event_id":"01K3TA3D4E5F6G7H8J9K0M1N2P","kind":"turn.end",
  "event_time":"2026-08-23T14:23:44.540Z","seq":48325,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"end_reason":"stop_hook","duration_ms":41880,"open_calls_at_end":0,
          "aborted_call_ids":[],"stop_hook_active":false,"tool_calls":6} }
```

### 6.5 `tool.start`

**Trigger:** the `PreToolUse` hook, for every tool without exception — including `Task`, which *also*
produces a `subagent.spawn` sharing the same `call_id` ([§ 6.7](#67-subagentspawn)).

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `call_id` | ULID | — | no | 26 chars, minted by the reporter | `"01K3TA4E5F6G7H8J9K0M1N2P3Q"` |
| `tool_name` | string | — | no | ≤ 64 B, `^[A-Za-z0-9_.-]{1,64}$`, else the literal `"INVALID_TOOL_NAME"` + counter | `"Bash"` |
| `descriptor` | string | — | **yes** | ≤ 200 B, sanitized ([§ 7](#7-sanitization-at-the-reporter)); `null` when the tool is not on the descriptor allowlist | `"Bash: composer test"` |
| `descriptor_truncated` | bool | — | no | — | `false` |
| `agent_scope` | enum | — | **yes** | `main` \| `subagent` \| `null` | `null` |
| `harness_call_ref` | string | — | yes | ≤ 64 B, opaque | `"toolu_01A9F3kQ2mZ"` |
| `open_calls_before` | int | — | no | 0…64 | `1` |

**`agent_scope` is `null` unless the harness payload carries an explicit, documented field naming it.**
It is *never* inferred from an environment marker — that inference is precisely the measured 30-day
outage in [§ 3.4](#34-why-identity-never-comes-from-the-environment) — and it is never defaulted to
`main`, because a wrong-but-plausible label is worse than an honest absence. `null` means *not
determinable*, and nothing in the pipeline gates on it.

`harness_call_ref` is recorded **when present** and used as the preferred `PostToolUse` matching key
([§ 8.2](#82-matching-a-close-to-its-open)). Note the distinction from the rule above: this is not a
gate — the event is emitted identically whether the ref is present or absent; only the match
*quality* changes, and the quality is itself reported in `tool.end.match`.

```json
{ "event_id":"01K3TA4E5F6G7H8J9K0M1N2P3R","kind":"tool.start",
  "event_time":"2026-08-23T14:23:09.882Z","seq":48312,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"call_id":"01K3TA4E5F6G7H8J9K0M1N2P3Q","tool_name":"Bash",
          "descriptor":"Bash: composer test","descriptor_truncated":false,
          "agent_scope":null,"harness_call_ref":"toolu_01A9F3kQ2mZ","open_calls_before":0} }
```

### 6.6 `tool.end`

**Trigger:** the `PostToolUse` hook (`outcome: "completed"`), or a reap
([§ 8.3](#83-the-reap-rules)) (`outcome: "aborted"`).

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `call_id` | ULID | — | no | 26 chars; `"UNMATCHED"` is **not** permitted — see below | `"01K3TA4E5F6G7H8J9K0M1N2P3Q"` |
| `tool_name` | string | — | no | ≤ 64 B | `"Bash"` |
| `outcome` | enum | — | no | `completed` \| `aborted` | `"aborted"` |
| `abort_reason` | enum | — | **yes** | `session_cleared` \| `session_superseded` \| `turn_boundary` \| `reporter_restart` \| `null` when completed | `"session_cleared"` |
| `is_error` | bool | — | **yes** | `null` unless the payload carries an unambiguous error indicator | `null` |
| `duration_ms` | int | ms | **yes** | `null` if the open was not in the index, or if the computed value is negative | `27411` |
| `close_source` | enum | — | no | `post_tool_use` \| `reap_session_boundary` \| `reap_turn_boundary` \| `reap_reporter_restart` \| `subagent_stop_hook` | `"reap_session_boundary"` |
| `match` | enum | — | no | `harness_ref` \| `lifo_tool_name` \| `sole_open` \| `synthesized` | `"synthesized"` |

`is_error` is `null` by default and set only from an unambiguous harness error indicator. It is
**never** inferred by inspecting output text — that would require reading exactly the content D-06
forbids transiting, and inference from prose is the fragile-predicate shape all over again.

`duration_ms` is end-wall-clock minus start-wall-clock on one machine; an NTP step mid-call can make
it negative, in which case the reporter sends `null` and counts `negative_duration`.

If a `PostToolUse` arrives that matches **no** open call (the open was lost to spool overflow, or the
reporter was installed mid-call), the reporter **synthesizes** the pair: it emits a `tool.start` with
a fresh `call_id`, `descriptor: null`, `event_time` equal to the close time, and
`data.synthesized: true`, immediately followed by the `tool.end` with `match: "synthesized"`. The
ledger therefore never contains a close without an open — a rule that keeps every consumer's
open-call arithmetic total, and makes the anomaly a visible flag rather than a silent negative count.

```json
{ "event_id":"01K3TA5F6G7H8J9K0M1N2P3Q4S","kind":"tool.end",
  "event_time":"2026-08-23T14:22:40.121Z","seq":48307,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913",
  "data":{"call_id":"01K3T9ZZ1A2B3C4D5E6F7G8H9J","tool_name":"Bash","outcome":"aborted",
          "abort_reason":"session_cleared","is_error":null,"duration_ms":27411,
          "close_source":"reap_session_boundary","match":"synthesized"} }
```

### 6.7 `subagent.spawn`

**Trigger:** the `PreToolUse` hook where `tool_name == "Task"`, emitted **immediately after** that
call's `tool.start`, sharing its `call_id`.

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `call_id` | ULID | — | no | 26 chars, equals the Task `tool.start`'s | `"01K3TA6G7H8J9K0M1N2P3Q4R5T"` |
| `title` | string | — | **yes** | ≤ 120 B, sanitized; `null` if the payload has no description | `"draft the D1 event schema"` |
| `title_truncated` | bool | — | no | — | `false` |
| `subagent_type` | string | — | yes | ≤ 32 B, `^[A-Za-z0-9_-]+$` | `"coder"` |

**Why both `tool.start` and `subagent.spawn` for one call.** Making `Task` an exception in the call
ledger would create a second lifecycle path through the abort machinery — and the subagent case is
the *one* the kill-vs-complete requirement is actually about, so it is the last place to want a
special case. Instead the call is ordinary, and `subagent.spawn` is a second projection of the same
fact carrying only what the ledger has no field for (the title and the type). The shared `call_id` is
the join key. Cost: ~120 bytes on an event class that fires tens of times a day.

**The title is the one free-text field with a human author**, so it passes the full sanitizer
([§ 7](#7-sanitization-at-the-reporter)) exactly like a descriptor. A dispatch description saying
`use token ghp_…` must not reach the wire.

```json
{ "event_id":"01K3TA6G7H8J9K0M1N2P3Q4R5V","kind":"subagent.spawn",
  "event_time":"2026-08-23T14:23:31.004Z","seq":48320,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"call_id":"01K3TA6G7H8J9K0M1N2P3Q4R5T","title":"draft the D1 event schema",
          "title_truncated":false,"subagent_type":"coder"} }
```

### 6.8 `subagent.stop`

**Trigger:** the close of the Task call — from `PostToolUse`, from a reap, or from the `SubagentStop`
hook in the unambiguous case ([§ 8.5](#85-what-the-subagentstop-hook-is-used-for)). Emitted
immediately after that call's `tool.end`, sharing the `call_id`.

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `call_id` | ULID | — | no | 26 chars | `"01K3TA6G7H8J9K0M1N2P3Q4R5T"` |
| `outcome` | enum | — | no | `completed` \| `aborted` | `"aborted"` |
| `abort_reason` | enum | — | yes | as [§ 6.6](#66-toolend) | `"session_cleared"` |
| `duration_ms` | int | ms | yes | ≥ 0 | `184992` |
| `close_source` | enum | — | no | as [§ 6.6](#66-toolend) | `"reap_session_boundary"` |

The title is **not** repeated here. One fact, one home: the consumer joins on `call_id`. If the spawn
was lost to spool overflow the stop is title-less — an observable orphan, which is the honest outcome,
not a gap to paper over with a second copy that could disagree with the first.

```json
{ "event_id":"01K3TA7H8J9K0M1N2P3Q4R5T6W","kind":"subagent.stop",
  "event_time":"2026-08-23T14:26:36.001Z","seq":48338,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"call_id":"01K3TA6G7H8J9K0M1N2P3Q4R5T","outcome":"aborted",
          "abort_reason":"session_cleared","duration_ms":184992,
          "close_source":"reap_session_boundary"} }
```

### 6.9 `compaction.start`

**Trigger:** the `PreCompact` hook.

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `trigger` | enum | — | no | `auto` \| `manual` \| `unknown` | `"auto"` |
| `context_used_pct` | float | percent | yes | 0.0…100.0, last known sample | `92.4` |
| `open_calls` | int | — | no | 0…64 | `0` |

**Compaction does not reap.** It rewrites context; it does not kill a running tool process, so a call
open across a compaction still receives its `PostToolUse`. The open count is recorded rather than
acted on. (If a future harness version makes compaction kill in-flight calls, the reap list in
[§ 8.3](#83-the-reap-rules) gains a row — a mechanical change, and the orphan timeout is the backstop
until it is made.)

```json
{ "event_id":"01K3TA8J9K0M1N2P3Q4R5T6W7X","kind":"compaction.start",
  "event_time":"2026-08-23T15:02:18.774Z","seq":48402,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"trigger":"auto","context_used_pct":92.4,"open_calls":0} }
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
| `context_used_pct_after` | float | percent | yes | 0.0…100.0 | `31.7` |

```json
{ "event_id":"01K3TA9K0M1N2P3Q4R5T6W7X8Y","kind":"compaction.end",
  "event_time":"2026-08-23T15:03:01.886Z","seq":48403,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"duration_ms":43112,"close_source":"post_compact","context_used_pct_after":31.7} }
```

### 6.11 `context.sample`

**Trigger:** the statusLine integration — **sampled, never streamed.**

The statusLine command is invoked on every status-line render, which is far above the rate at which
this data is worth storing. The reporter emits a `context.sample` only when one of three conditions
holds; every other invocation increments `statusline_suppressed` and emits nothing.

| Condition | `sample_reason` | Rule |
|---|---|---|
| ≥ 60 s since the last sample for this session | `cadence` | wall-clock, from `state.json` |
| the 5-percentage-point bucket changed | `threshold_cross` | `floor(pct/5)` differs from the last emitted |
| no sample yet for this session | `first_of_session` | — |

**Derivations.** 5 points: a human reads a context gauge at roughly that resolution, so a finer step
buys nothing anyone can see, and a coarser one hides the approach to auto-compaction. 60 s: it matches
the heartbeat cadence, so a seat's two periodic signals stay in step, and it bounds the class at
1,440 events/seat/day against a raw render rate of ~1 Hz (86,400/day) — a **60× reduction**, which is
what moves this class from "the dominant cost of the whole pipeline" to ~18 % of a seat's events.

**The passthrough obligation.** The statusLine command's stdout *is* the rendered status line, so a
seat that already has one must not lose it. The installer records the previously configured command
as `config.wrapped_statusline`; the reporter spawns it with the same stdin, prints its stdout
**verbatim** to its own stdout, and prints nothing of its own there. Failure behaviour: a wrapped
command that exits non-zero or exceeds **1 s** → the reporter prints whatever bytes it produced (or
nothing), increments `wrapped_statusline_failures`, and exits 0. The seat's status line degrades to
blank; the seat itself never breaks. 1 s: a status line is re-rendered continuously, so a slower
command is already broken for its own reasons.

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `used_pct` | float | percent | no | 0.0…100.0, one decimal | `73.2` |
| `used_tokens` | int | tokens | yes | 0…10,000,000 | `146401` |
| `total_tokens` | int | tokens | yes | 1…10,000,000 | `200000` |
| `model_label` | string | — | yes | ≤ 48 B, sanitized | `"claude-opus-5"` |
| `sample_reason` | enum | — | no | `cadence` \| `threshold_cross` \| `first_of_session` | `"threshold_cross"` |

`used_pct` is read from the statusLine JSON at `context_window.used_percentage` — a field known to
exist and be readable, from a statusLine sensor already running in this fleet. If it is absent,
`used_pct` is computed from `used_tokens / total_tokens`; if those are absent too, **no event is
emitted** and `payload_key_missing.context_window` is incremented — the only suppression in the
design driven by payload shape, and it is counted precisely because [§ 3.4](#34-why-identity-never-comes-from-the-environment)
says a silent one is how a signal dies unnoticed.

```json
{ "event_id":"01K3TB0M1N2P3Q4R5T6W7X8Y9Z","kind":"context.sample",
  "event_time":"2026-08-23T14:41:00.310Z","seq":48366,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"used_pct":73.2,"used_tokens":146401,"total_tokens":200000,
          "model_label":"claude-opus-5","sample_reason":"threshold_cross"} }
```

### 6.12 `attention.request`

**Trigger:** the `Notification` hook.

Beyond the required set, and deliberately: `docs/PLAN.md § 7` requires the floor to render **blocked**
as a distinct state, and no other hook supplies a "the agent is waiting on a human" signal. Deleting
this kind is a compatible change ([§ 5](#5-compatibility--what-this-document-owes-the-policy)) if
review decides D2's status tiers should carry it instead.

| `data` field | Type | Null? | Bounds | Example |
|---|---|---|---|---|
| `notification_kind` | enum | no | `permission_required` \| `input_awaited` \| `other` | `"permission_required"` |
| `open_calls` | int | no | 0…64 | `1` |

**The message text never transits** — not truncated, not sanitized, not at all. Only the classified
enum does.

**This classifier is knowingly fragile, and is built to fail visibly.** It matches the harness's
English notification wording, which is undocumented and will be reworded. Three protections, straight
from [§ 3.4](#34-why-identity-never-comes-from-the-environment): it never gates emission (an
unmatched message emits `other`, so the blocked state degrades to "attention requested, kind unknown",
never to silence); all three branch counts ride the heartbeat; and the server's predicate-constant
alarm ([§ 9.4](#94-the-predicate-constant-alarm)) fires when the distribution collapses. The rules —
`/permission|approve|allow|grant/i` → `permission_required`; `/waiting|idle|input/i` →
`input_awaited`; else `other` — are the mutable part; the visibility is not.

```json
{ "event_id":"01K3TB1N2P3Q4R5T6W7X8Y9Z0A","kind":"attention.request",
  "event_time":"2026-08-23T14:44:12.007Z","seq":48371,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"notification_kind":"permission_required","open_calls":1} }
```

### 6.13 `reporter.heartbeat`

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
| `open_calls` | int | — | no | 0…64 | `1` |
| `open_sessions` | int | — | no | 0…16 | `1` |
| `degraded` | array\<enum\> | — | no | 0…16 elements, [§ 9.3](#93-degradation-counters) | `["rejected_batches"]` |
| `counters` | object | — | no | ≤ 1.5 KiB serialized, all monotonic since flusher start | see below |
| `predicates` | object | — | no | ≤ 512 B, `{name:{true:int,false:int}}` | see below |
| `selftest` | object | — | no | ≤ 256 B, `{name:"pass"\|"fail"}` | see below |
| `config_fingerprint` | string | — | no | 16 hex chars = SHA-256 of `install_id\|seat_id\|ingest_url`, **token excluded** | `"9f2c41a7be03d518"` |

`config_fingerprint` deliberately excludes the token: a fingerprint that covered the secret would let
anyone holding the event stream confirm a guessed token by comparing hashes. It exists so an operator
can tell "this seat was reconfigured" from "this seat is a different seat".

```json
{ "event_id":"01K3TB2P3Q4R5T6W7X8Y9Z0A1B","kind":"reporter.heartbeat",
  "event_time":"2026-08-23T14:45:00.000Z","seq":48374,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":null,
  "data":{
    "uptime_s":86213,"spool_bytes":18422,"spool_files":2,"spool_lag_events":0,
    "oldest_unsent_age_s":null,"last_hook_at":"2026-08-23T14:44:12.007Z",
    "open_calls":1,"open_sessions":1,"degraded":[],
    "counters":{"events_emitted":48374,"events_sent":48373,"spool_dropped_events":0,
                "spool_corrupt_lines":0,"batches_ok":1611,"batches_retried":4,
                "batches_rejected":0,"statusline_suppressed":51882,
                "sanitizer_redactions":37,"sanitizer_truncations":12,
                "hook_name_mismatch":0,"negative_duration":0,
                "payload_key_missing.session_id":0,"wrapped_statusline_failures":0},
    "predicates":{"notification_kind_permission":{"true":6,"false":19},
                  "descriptor_allowlisted":{"true":2841,"false":93},
                  "session_boundary_detected":{"true":11,"false":48363}},
    "selftest":{"sanitizer_fixtures":"pass","config_readable":"pass","tls_verify":"pass"},
    "config_fingerprint":"9f2c41a7be03d518"} }
```

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
| `Task` | `tool_input.description` | `Task: <description>` | `Task: draft the D1 event schema` |
| `WebFetch` | `tool_input.url`, **scheme + host only** | `WebFetch: <host>` | `WebFetch: docs.anthropic.com` |
| `WebSearch` | `tool_input.query` | `WebSearch: <query>` | `WebSearch: laravel reverb auth` |
| `TodoWrite` | *(none)* | `TodoWrite` | `TodoWrite` |
| anything else, **including every `mcp__*` tool** | *(none)* | `null` | `null` |

MCP tools are called out because they are the largest open surface: their input schemas are defined by
third-party servers, so no rule written here can bound what a key contains. The allowlist handles them
by construction — an unknown tool has no allowlisted key, therefore no descriptor.

**This is the property to test.** AT-2 fixture 8 feeds an unknown tool an input named `password` and
asserts `descriptor == null`, proving the allowlist and not the regexes is what stops it.

### 7.2 Layer 2 — redaction of the allowlisted text

The allowlisted fields can themselves contain secrets — `curl -H "Authorization: Bearer sk-…"` is an
allowlisted `Bash` command. So the candidate descriptor passes a redaction pass before truncation.

**The locking rule.** Once a rule replaces a span with a `‹…›` marker, later rules do not match inside
or across that marker; a candidate match overlapping a locked span is discarded. Without this the
output depends on rule interaction order in ways nobody can predict, and the fixtures below would not
be deterministic.

### 7.3 Redaction rules, applied in this order

| # | Rule | Pattern (illustrative) | Replacement |
|---|---|---|---|
| 1 | URL userinfo | `(\w+://)[^/\s:@]+:[^/\s@]+@` | `$1‹redacted›@` |
| 2 | Env-expansion **defaults** | `\$\{(\w+):[-=?+]([^}]*)\}` | `${$1:-‹redacted›}` |
| 3 | Known-prefix credentials | `\b(gh[pousr]_\|github_pat_\|sk-\|sk_live_\|sk_test_\|xox[abposr]-\|AKIA\|ASIA\|glpat-\|AIza\|mzn_)[A-Za-z0-9_\-]{8,}` | `‹redacted:token›` |
| 4 | Secret-shaped assignment | `(?i)\b(pass(word)?\|secret\|token\|api[_-]?key\|auth\|bearer\|credential)\b\s*[:=]\s*\S+` | `$1=‹redacted›` |
| 5 | Long opaque blobs | `[A-Za-z0-9+/]{32,}={0,2}` and `\b[0-9a-f]{24,}\b` | `‹redacted:blob›` |
| 6 | Email addresses | `[\w.+-]+@[\w-]+\.[\w.-]+` | `‹redacted:email›` |
| 7 | IPv4 literals | `\b(\d{1,3}\.){3}\d{1,3}\b` (valid octets) | `‹redacted:ip›` |
| 8 | Home and long paths | `/home/<u>/`, `/Users/<u>/`, `C:\Users\<u>\` → `~/`; then paths with > 4 segments keep segment 1 + `…` + the last 2 | `~/…/design/EVENT-SCHEMA.md` |
| 9 | Control characters | `[\x00-\x1F\x7F]` including newline, tab, ESC | single space |
| 10 | Whitespace collapse | ` {2,}` | single space, then trim |
| 11 | Encoding repair | lone surrogates / invalid UTF-8 | `U+FFFD`; output must be valid UTF-8 |
| 12 | Truncate | [§ 7.4](#74-truncation) | — |

**Rules 5 and 7 have deliberate false positives, and they are the correct trade.** Rule 5 eats a
40-character git SHA; rule 7 eats a four-part version string. A descriptor that reads
`Bash: git show ‹redacted:blob›` still answers the only question the floor asks — *what is this agent
doing* — while the inverse error, a leaked 32-character credential, is unrecoverable the moment it is
written to a log. Every redaction increments `sanitizer_redactions`, so an implausible rate is
visible rather than mysterious.

**Rule 2 is here because of a measured incident in this fleet:** `${VAR:-fallback}` prints the
fallback when `VAR` is unset, and the fallback is exactly where a hard-coded credential hides. The
variable *name* is kept (it carries no value and is useful context); only the default is redacted.
The reporter never expands a variable — it is reading text, not running a shell.

**Rule 8 is a PII rule as much as a length rule** — an absolute path carries the OS username, which
[§ 1](#1-non-goals) excludes from the wire.

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

| # | Input (tool, raw allowlisted value) | Required descriptor output |
|---|---|---|
| 1 | `Bash`, `curl -H "Authorization: Bearer ghp_ABCDEF1234567890abcdef1234" https://api.github.com/user` | `Bash: curl -H "Authorization: ‹redacted:token›" https://api.github.com/user` |
| 2 | `Bash`, `psql "postgres://mez:s3cr3t-pw@db.example.com:5432/mezz" -c '\dt'` | `Bash: psql "postgres://‹redacted›@db.example.com:5432/mezz" -c '\dt'` |
| 3 | `Bash`, `echo "${STRIPE_SECRET:-sk_live_51H8xYzAbCdEfGhIj}" > /tmp/k` | `Bash: echo "${STRIPE_SECRET:-‹redacted›}" > /tmp/k` |
| 4 | `Bash`, `deploy --user ops@example.org --host 203.0.113.47` | `Bash: deploy --user ‹redacted:email› --host ‹redacted:ip›` |
| 5 | `Read`, `/home/aimlapm/projects/mezzanine/app/Http/Controllers/IngestController.php` | `Read: ~/…/Controllers/IngestController.php` |
| 6 | `Bash`, `git commit -m "line one\nline two"` *(literal newline + ESC `\x1b[31m` in the text)* | `Bash: git commit -m "line one line two"` — one line, no ESC byte in the output |
| 7 | `Bash`, a 600-byte command whose bytes 195–205 are the 2-byte `é` | exactly ≤ 200 bytes, valid UTF-8, ends `…`, `descriptor_truncated == true`, no split character |
| 8 | `mcp__vault__read`, `{"password":"hunter2","path":"/prod/db"}` | `descriptor == null`, `tool_name == "mcp__vault__read"`, and the string `hunter2` appears nowhere in the emitted event |

Fixture 8 is the one that matters most: it tests the allowlist, which is the control that holds when
an input shape nobody anticipated arrives. Fixtures 1–4 test the second layer.

**Two whole-event assertions accompany the table**, because a per-function test cannot see a leak that
happens outside the function: (a) for every fixture, serialize the *complete* event and assert the raw
secret substring is absent from the serialized bytes; (b) a fuzz case feeds 1,000 randomly generated
`tool_input` objects containing planted credentials at random depths and asserts no planted string
reaches any emitted event. The planted-string corpus reuses rule 3's prefixes.

---

## 8. Call lifecycle — the kill-vs-complete contract

### 8.1 The problem, restated

**Measured upstream (roundtable #341/#340, 26 of 26 events): a `/clear` on a seat SIGKILLs an
in-flight subagent's tool call.** The kill produces no completion signal — `PostToolUse` simply never
fires. A consumer that treats the next turn boundary as "the turn finished" therefore mints a **false
idle transition**, and it does so on exactly the seats that are busiest, because those are the seats
running subagents when someone clears. A dashboard whose idle indicator is least trustworthy when
work is heaviest is worse than no dashboard: it is confidently wrong in the one direction an operator
would act on.

**The design answer: every tool call is an explicit ledger entry with an open and a close, and a
close always states *how* it was closed.** Absence is never read as completion — by anybody, at any
layer.

### 8.2 Matching a close to its open

The reporter keeps a per-seat **open-call index** at `spool/open-calls.json`, rewritten atomically
(`.tmp` + rename) by each hook invocation. It holds at most 64 entries; entry 65 evicts the oldest and
increments `open_call_index_overflow` (64 = far above any observed concurrent-call count; a seat
exceeding it has a harness anomaly worth surfacing).

| Index entry field | Example |
|---|---|
| `call_id` | `"01K3TA4E5F6G7H8J9K0M1N2P3Q"` |
| `session_id` | `"a7f2c918-…"` |
| `tool_name` | `"Bash"` |
| `harness_call_ref` | `"toolu_01A9F3kQ2mZ"` or `null` |
| `started_at` | `"2026-08-23T14:23:09.882Z"` |
| `is_task` | `true` / `false` |

`PostToolUse` matches, in this order, and records which one won in `tool.end.match`:

| Order | Key | `match` value | Precision |
|---|---|---|---|
| 1 | equal `harness_call_ref` | `harness_ref` | exact |
| 2 | the only open call, if exactly one | `sole_open` | exact |
| 3 | most recent open call with the same `tool_name` (LIFO) | `lifo_tool_name` | **approximate** |
| 4 | no match → synthesize the open ([§ 6.6](#66-toolend)) | `synthesized` | — |

**The `lifo_tool_name` fallback can mis-attribute**, and the bound on the damage is stated rather than
hidden: it can only swap two *concurrently open calls of the same tool in the same session*, and it
swaps their `call_id`s and durations, not their existence or their outcome. The counts of open, closed
and aborted calls stay exact, so **`D2-MUST` #1's idle rule is unaffected by a mis-match** — which is
the property that matters. The `match` field ships in every `tool.end` so a consumer can see how much
of its data is exact, and a fleet-wide `match` distribution that is ~100 % `harness_ref` is the signal
that the fallback could later be deleted.

### 8.3 The reap rules

**Before emitting its own event**, every `hook` invocation reaps: any index entry that the table below
declares aborted is closed, in spool order, *ahead of* the triggering event.

| Reap trigger | What is aborted | `abort_reason` | `close_source` |
|---|---|---|---|
| `SessionStart` with `source == "clear"` | every open call of the previous session | `session_cleared` | `reap_session_boundary` |
| any hook carrying a `session_id` different from the index's current one | every open call of the previous session | `session_superseded` | `reap_session_boundary` |
| `Stop` | every call still open **in that session** | `turn_boundary` | `reap_turn_boundary` |
| flusher start finding index entries older than its own start time | every such call | `reporter_restart` | `reap_reporter_restart` |

Each reap emits, in order: `tool.end(outcome:"aborted", …)` per call — plus a `subagent.stop` for any
of them with `is_task: true` — then the triggering event, whose `aborted_call_ids` names them.

**Why `Stop` reaps.** When the main agent's turn ends, no tool call of that session can still be
legitimately running: the turn's completion is downstream of every call it made. A call open at `Stop`
is therefore either killed or lost, and both are correctly *not* completions. The one case this could
get wrong — a close that arrives *after* the reap — is handled by the late-completion rule in
[§ 12.5](#125-late-completions-and-orphan-timeouts): **completion is an observation and abort is an
inference, so an observation always overrides.**

### 8.4 Detecting a `/clear` with two independent signals

Whether `/clear` mints a new `session_id`, and whether `SessionStart.source` reads `clear`, are both
properties of a harness this project does not control and cannot pin. So both are used, either
suffices, and both are counted:

| Signal | Counter | If it goes constant |
|---|---|---|
| `SessionStart.source == "clear"` | `predicates.session_boundary_detected` (source branch) | the session-id-change signal still reaps; the predicate alarm ([§ 9.4](#94-the-predicate-constant-alarm)) fires |
| incoming `session_id` ≠ index's current | `predicates.session_boundary_detected` (id branch) | the source signal still reaps; the alarm fires |

The counters **diverging** is the discriminating self-test: in healthy operation a `/clear` trips both
within one hook invocation, so a large gap between the two branch counts means one of them has
stopped working while the pipeline still appears to function. That is the shape of the 30-day outage
in [§ 3.4](#34-why-identity-never-comes-from-the-environment), instrumented so it takes minutes rather
than a month to see.

### 8.5 What the `SubagentStop` hook is used for

`SubagentStop` fires when a subagent finishes but carries no field identifying *which* one. It is
therefore used narrowly:

- **exactly one open `is_task` call** → close it, `close_source: "subagent_stop_hook"`, `match: "sole_open"`;
- **zero or two-or-more** → emit nothing; increment `subagent_stop_unmatched`.

The Task call's own `PostToolUse` remains the primary close. Using an unidentifiable signal to close
an arbitrary one of several candidates would be a guess with no observable, which is the failure mode
this whole section exists to remove.

### 8.6 Server-side interpretation of open-call state

The ingest maintains a per-seat **call ledger** derived from the stream. Its rules:

| Situation | Server behaviour |
|---|---|
| `tool.start` for a new `call_id` | open the call, record `started_at` = `event_time` |
| `tool.start` for a `call_id` already known | ignore, count `duplicate_open` (a dedup escape or a replay) |
| `tool.end` for an open call | close with the stated `outcome` |
| `tool.end` arriving **before** its `tool.start` (out-of-order batches) | create the entry already closed; a later `tool.start` for it **does not reopen it**, and counts `late_open` |
| a call open past its **orphan timeout** ([§ 12.5](#125-late-completions-and-orphan-timeouts)) | close it `aborted` / `orphan_timeout`, server-side only — **no wire event is synthesized**, because the wire is what a seat said and the server must not put words in a seat's mouth |
| `turn.end` with `aborted_call_ids` non-empty | the turn is **not** a clean boundary; `D2-MUST` #1 forbids an idle transition |
| a seat with any open call | the seat is **working**, regardless of turn state |

### 8.7 Worked flow — a `/clear` during a subagent's `Bash` call

The acceptance-test scenario ([AT-1](#at-1-kill-vs-complete-the-headline-test)), event by event.
`T` is the seat clock.

| # | Time | Kind | Key data |
|---|---|---|---|
| 1 | `T+00.0s` | `tool.start` | `call_id: A`, `tool_name: "Task"`, `descriptor: "Task: probe the ingest"` |
| 2 | `T+00.0s` | `subagent.spawn` | `call_id: A`, `title: "probe the ingest"`, `subagent_type: "coder"` |
| 3 | `T+03.2s` | `tool.start` | `call_id: B`, `tool_name: "Bash"`, `descriptor: "Bash: sleep 120"`, `open_calls_before: 1` |
| — | `T+18.6s` | *(operator types `/clear`; the harness SIGKILLs call `B`; **no `PostToolUse` ever fires**)* | |
| 4 | `T+18.7s` | `tool.end` | `call_id: B`, `outcome: "aborted"`, `abort_reason: "session_cleared"`, `close_source: "reap_session_boundary"` |
| 5 | `T+18.7s` | `tool.end` | `call_id: A`, `outcome: "aborted"`, `abort_reason: "session_cleared"`, `close_source: "reap_session_boundary"` |
| 6 | `T+18.7s` | `subagent.stop` | `call_id: A`, `outcome: "aborted"`, `abort_reason: "session_cleared"` |
| 7 | `T+18.7s` | `turn.end` | `end_reason: "session_cleared"`, `open_calls_at_end: 2`, `aborted_call_ids: [B, A]` |
| 8 | `T+18.7s` | `session.end` | `end_reason: "cleared"`, `aborted_calls: 2` |
| 9 | `T+18.8s` | `session.start` | `source: "clear"`, `previous_session_id: <old>` |

Events 4–9 are all produced by the **single `SessionStart` hook invocation** at `T+18.7s`: reap first,
then the boundary events, then the trigger's own event — one process, one spool append per event, in
that order. The server sees two aborted calls and a `turn.end` that fails `D2-MUST` #1, so **no idle
transition is minted**; the desk goes from *working* to *unknown* and back to *working* when the next
turn starts. That is the entire requirement, made checkable at the wire.

---

## 9. Liveness: heartbeat, staleness and the predicate alarm

### 9.1 The cadence and the alarm

| Signal | Value | Derivation |
|---|---|---|
| Heartbeat interval | **60 s** | one per minute per seat = 1,440 events/seat/day ≈ 18 % of a busy seat's volume — the cheapest continuous liveness assertion that still bounds detection latency to minutes. Matches the `context.sample` cadence so a seat's two periodic signals stay in step |
| Flush interval | **10 s** | a desk that reacts within 10 s reads as live to a human; it bounds request rate at 6/min/seat |
| Seat `stale` threshold | **300 s** since the last received event | a healthy seat's newest event is ≤ 70 s old at the server (60 s heartbeat + 10 s flush). 300 s ≈ 4× that and, critically, exceeds the 120 s retry-backoff ceiling ([§ 11.5](#115-retry-and-backoff)) so a transient network outage that *recovers* never trips it |
| Seat `offline` threshold | **900 s** | 3× the stale threshold: long enough that "stale" is a distinct, investigable state rather than a flicker on the way to offline |

**Rendering (constraining D2/D3, deliberately).** `stale` and `offline` are **visibly degraded**
states — a distinct rendered desk, per
[`docs/VERSIONING.md § The failure direction must be safe`](../VERSIONING.md#the-failure-direction-must-be-safe--reject-loudly-never-drop-quietly).
Neither may render as *idle*. An empty floor and a broken floor must never look alike, because
"quiet" is exactly what this product renders when the fleet is calm, so nobody investigates it.

### 9.2 Why this is the structural backstop

The [§ 3.4](#34-why-identity-never-comes-from-the-environment) incident's cost was not a wrong
predicate — predicates break routinely. Its cost was that a dark consumer and a healthy one were
indistinguishable from outside for 30 days. The heartbeat inverts that: liveness is **asserted
continuously by the producer**, so silence becomes a positive observation the server can alarm on. No
reporter bug, and no harness change, can make a seat *quietly* disappear — the worst it can do is make
a seat visibly stale. That property is worth more than any individual event kind in this document.

### 9.3 Degradation counters

Every counter below is monotonic since flusher start, rides in `reporter.heartbeat.counters`, and has
a *named* consequence. A counter without a consequence is decoration.

| Counter | Meaning | Consequence when non-zero |
|---|---|---|
| `spool_dropped_events` | overflow discarded events | seat badge `lossy`; the number is rendered |
| `spool_corrupt_lines` | unparseable spool lines quarantined | seat badge `lossy` |
| `batches_rejected` | permanent-status rejections | seat badge `degraded`; the last status and error code are shown |
| `hook_name_mismatch` | `argv[2]` ≠ `hook_event_name` | `degraded`; the harness contract moved |
| `payload_key_missing.<key>` | an expected harness key was absent | `degraded` when > 0 for a key marked required in [§ 6](#6-event-kinds) |
| `open_call_index_overflow` | > 64 concurrent open calls | `degraded` |
| `subagent_stop_unmatched` | `SubagentStop` with ≠ 1 open Task call | informational; expected to be non-zero on parallel dispatches |
| `statusline_suppressed` | sampling suppressions | informational; a *zero* here on an active seat means sampling is broken |
| `negative_duration` | clock stepped mid-call | informational |
| `wrapped_statusline_failures` | the wrapped status-line command failed | `degraded`; the seat's own UI is affected |
| `sanitizer_redactions` / `sanitizer_truncations` | redaction and truncation activity | informational; an implausible rate is worth a look either way |

`data.degraded` carries the enum names of the conditions currently active, so a consumer never has to
re-derive the badge from raw counters.

### 9.4 The predicate-constant alarm

Every classifying predicate in the reporter reports both branch counts in
`reporter.heartbeat.predicates`. The server alarms when a predicate's branch ratio is **0 % or 100 %
across ≥ 500 evaluations within a rolling 24 h window** — the `predicate_constant` warning, surfaced
per seat.

**On the 500.** The threshold must exceed the longest legitimate run of one branch, and nobody has
measured that yet on any seat. 500 is chosen so the alarm needs roughly a working day of evidence
before it speaks, which makes a false alarm cheap and a real one still ~29× faster than the 30-day
outage it exists to catch. **The implementer records per-seat, per-predicate evaluation counts through
the first week of live running, and the operator re-picks the threshold from that data.** What must
not change under review is that the check exists, that it fires visibly, and that it is proven capable
of firing ([AT-8](#at-8-predicate-constant-alarm)).

The predicates in scope, all three from this document: `descriptor_allowlisted`,
`session_boundary_detected` (two branches, [§ 8.4](#84-detecting-a-clear-with-two-independent-signals)),
`notification_kind_permission` ([§ 6.12](#612-attentionrequest)). Adding a predicate to the reporter
without adding it to this object is a review-blocking defect.

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
a `seq_epoch`. The flusher is the single writer of `state.json`, so it needs no lock — and this is
precisely why `seq` is not assigned in the hook, where cross-process locking would sit inside the
250 ms budget P-5 protects.

- `seq_epoch` is a ULID minted when `state.json` is created. Losing state (reinstall, wiped state dir)
  mints a **new epoch**, which the server treats as an intentional discontinuity: logged, counted as
  `seq_epoch_change`, never alarmed. Without it, a reset counter would look like a 48,000-event gap.
- The ordering key is `(seq_epoch, seq)`. A **missing `seq`** within an epoch is a real gap — events
  lost after the flusher counted them — and the server counts `seq_gap` and renders the seat `lossy`.
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
> residency**. A 32 MiB spool at the derived ~4 MB/day rate holds ~8 days of events, so an event can
> legitimately arrive 8 days after it was minted. 10 days gives that a 25 % margin. **If either number
> moves, both move** — a dedup window below the spool's reach silently re-ingests the oldest
> events of a long outage, which is the single most confusing possible corruption of a timeline.

### 10.4 Batch-level idempotency

`batch_id` is recorded per seat for **24 h**: long enough to cover the whole retry ladder, which
saturates at 120 s, plus a same-day manual replay, and bounded by the 6/min flush ceiling at
≤ 8,640 rows/seat/day (~1,600 observed on a busy seat). A repeat `batch_id` returns the previous response without re-processing — an
optimisation, not
the correctness mechanism; per-event dedup is the correctness mechanism and holds even if a retry is
re-batched under a fresh `batch_id`.

---

## 11. The spool and the flusher

### 11.1 Layout

```
<spool_dir>/
  2026082314.jsonl      one file per UTC hour, append-only, LF-terminated
  2026082315.jsonl
  open-calls.json       the reporter's call index (§ 8.2), atomic rewrite
  state.json            flusher-owned: seq_epoch, next_seq, cursor, counters
  flusher.lock          advisory (§ 2.3)
  quarantine/corrupt.jsonl    unparseable spool lines, capped 256 KiB
  quarantine/rejected.jsonl   permanently-rejected batches, capped 1 MiB
  REJECTED.txt          human-readable marker, capped 64 KiB
  reporter.log          local diagnostics, capped 1 MiB, 2 rotations
```

**Hour-bucketed filenames replace rotation.** A rename-based rotation races with concurrent appending
hooks, and renaming an open file fails outright on Windows. Deriving the filename from the current UTC
hour removes the operation entirely: no rename, no lock, no race — the writer simply starts writing to
a new name when the hour turns. A busy seat's hour bucket is ~350 events ≈ 175 KB, so a single file
never approaches any size that would need splitting.

### 11.2 Spool line format

A spool line is the wire event plus exactly what the wire does not carry:

```json
{"v":1,"t":"2026-08-23T14:23:09.882Z","e":{ …the event object, minus `seq`… }}
```

| Key | Why it is here and not on the wire |
|---|---|
| `v` | the `schema_version` **this line was written under**. The flusher groups contiguous same-`v` runs into batches, so a reporter upgraded mid-spool drains cleanly — old lines go out under the old version, which the ingest still accepts inside its `N`/`N-1` window |
| `t` | spool write time; the flusher uses it for `oldest_unsent_age_s` without parsing the event |
| `e.seq` | absent: assigned at flush ([§ 10.2](#102-ordering-seq-and-gap-detection)) |

**Write discipline.** One `fs.writeSync` of one `\n`-terminated buffer on a descriptor opened `'a'`,
always LF (never `os.EOL` — identical bytes on both platforms keeps fixtures identical). Concurrent
hook processes therefore interleave at line granularity rather than inside a line, under `O_APPEND` on
Linux and `FILE_APPEND_DATA` on Windows. **This is an assumption with a test, not a belief**:
[AT-10](#at-10-concurrent-append-atomicity) runs 8 concurrent writers × 500 lines and asserts 4,000
well-formed lines. Line size is capped at 4 KiB ([§ 4.4](#44-size-caps-and-their-derivations)) to stay
under the conventional atomic-small-write floor.

### 11.3 Rotation and the overflow policy

| Bound | Value | Derivation |
|---|---|---|
| Total spool | **32 MiB** | at the [§ 6.0](#60-conventions-and-how-harness-payloads-are-read) volume estimate — ~8,000 events/seat/day at ~500 B ≈ **4 MB/day** — 32 MiB is **~8 days** of a busy seat. The requirement is "survives the server being down for days"; 8 days spans a long weekend plus a working week of a broken deploy, at a disk cost nobody will notice on a developer machine |
| Overflow unit | one whole hour bucket | O(1) `unlink`, no rewriting of a file another process is appending to |
| Overflow policy | **drop oldest** | the dashboard's value is *current* state; a 7-day-old queued event has no consumer left. Dropping newest would discard exactly the events that still matter |
| Loss visibility | `spool_dropped_events` += the dropped file's line count; badge `lossy` | never a silent drop, per [`docs/VERSIONING.md § The failure direction must be safe`](../VERSIONING.md#the-failure-direction-must-be-safe--reject-loudly-never-drop-quietly) |

Overflow is evaluated by the flusher on every pass and by any hook that finds `spool_bytes` over the
bound (so a seat whose flusher is dead cannot fill a disk). Granularity is coarse and stated: one drop
removes up to one hour of the oldest telemetry.

**The consequence a consumer must handle:** an overflow drop can remove a `tool.start` whose
`tool.end` survives. That is the `synthesized` path in [§ 6.6](#66-toolend) — the ledger stays total,
and the anomaly is flagged rather than silently producing a negative open-call count.

### 11.4 Corruption and the torn last line

| Case | Rule | Observable |
|---|---|---|
| Trailing bytes with **no** final `\n` | **Not consumed.** The flusher reads only up to and including the last `\n`; a partial line is a write in progress and is picked up next pass | none — the normal case |
| A `\n`-terminated line that fails `JSON.parse` | append it to `quarantine/corrupt.jsonl`, advance the cursor past it, **continue the batch** | `spool_corrupt_lines`, badge `lossy` |
| A line that parses but fails schema validation | same as above | `spool_corrupt_lines` |
| A line longer than 4 KiB | quarantine | `spool_corrupt_lines` |
| `state.json` unreadable or corrupt | mint a fresh `seq_epoch`, cursor to the start of the newest bucket, count `state_reset` | `seq_epoch_change` server-side |
| An entire bucket file unreadable | quarantine the filename, skip it, continue | `spool_corrupt_lines` += unknown; badge `lossy` |

**One torn line never poisons a batch and never wedges the queue.** The failure is bounded to the
line, counted, and quarantined for inspection — never "abort the batch", which would let one bad byte
stop a seat's telemetry indefinitely.

`state.json` is written `.tmp` + `renameSync` (atomic-replace on both platforms), by the flusher only.

### 11.5 Retry and backoff

| Parameter | Value | Derivation |
|---|---|---|
| Flush trigger | ≥ 50 queued events **or** 10 s elapsed | 50 events ≈ 25 KB, a batch worth a WAN round-trip; 10 s bounds dashboard latency and holds request rate at ≤ 6/min/seat |
| Backoff | exponential from **2 s**, ×2, **capped at 120 s**, **full jitter** (uniform in `[0, computed]`) | 2→4→8→16 covers a ~30 s app restart within 4 attempts. The 120 s cap sits **below** the 300 s stale threshold, so a recovered server is detected before the seat is declared stale. Full jitter stops N seats re-synchronising into a thundering herd after a server restart |
| `Retry-After` on `429` | honoured, clamped to ≤ 600 s | a server's explicit instruction outranks the ladder; the clamp stops a bad header parking a seat for hours |
| Retryable | timeout, DNS/connect failure, TLS failure, `408`, `429`, all `5xx` | transient by nature |
| **Not** retryable | `400`, `401`, `403`, `413`\*, `415`, `422` | permanent: the same bytes will be refused forever, and retrying hides the error behind an infinite loop instead of surfacing it |
| Retry attempts before giving up on a batch | unbounded **while** the condition is retryable | "the server is down for days" is the required-survivable case; the spool bound, not an attempt count, is what limits growth |

\* `413 batch_too_large` gets exactly one adaptive retry: halve the batch and resend. If a **single
event** still exceeds the limit, that event is quarantined and counted (`oversize_event_dropped`) —
it can never be delivered, so retrying it forever would block every event behind it.

**The poison-pill rule.** A batch refused with a permanent status is **never retried**. It is appended
to `quarantine/rejected.jsonl`, its cursor is advanced, `batches_rejected` is incremented, a line is
written to `REJECTED.txt` and `reporter.log`, and the flusher moves to the next batch. One bad batch
costs its own events, never the stream behind it.

**Local surfacing of a rejection** — required by
[`docs/VERSIONING.md § The failure direction must be safe`](../VERSIONING.md#the-failure-direction-must-be-safe--reject-loudly-never-drop-quietly)
("make the reporter surface the refusal locally too… that somebody is the only person who can fix
it"): `REJECTED.txt` carries timestamp, HTTP status, the machine-readable error code and the response
body (**with any `Authorization` header value excluded — the reporter logs the request's status, never
its headers**); `reporter.log` carries the same; and `degraded: ["batches_rejected"]` rides every
subsequent heartbeat that still gets through. The reporter does **not** rely on hook stderr being
displayed by the harness — that is another undocumented behaviour, and a surfacing mechanism that
might silently not exist is not a surfacing mechanism.

---

## 12. The server contract

### 12.1 Validation order

Cheapest and most-fatal first; **the first failure wins and nothing is ingested**.

1. `Content-Type` is `application/json` → else `415`.
2. Body ≤ 256 KiB → else `413`. When `Content-Encoding: gzip` is set, decompression is **capped at
   256 KiB and aborted past it** — an uncapped inflate is an unbounded allocation from an
   authenticated-but-compromised seat.
3. Body parses as JSON → else `400 malformed_body`.
4. `Authorization` present and resolves to an active token → else `401 unauthenticated`.
5. Rate limits ([§ 12.3](#123-rate-limits)) → else `429`.
6. `schema_version` present, an integer, and in the accepted set → else `400 unsupported_schema_version`.
7. Batch `install_id`/`seat_id` equal the token's binding → else `403 identity_mismatch`.
8. `events` is a non-empty array of ≤ 200 elements → else `422 invalid_batch`.
9. Every event validates: common fields present and in-bounds; per-event `install_id`/`seat_id` equal
   the batch's; `kind` a string; `data` an object ≤ 3 KiB → else `422 invalid_event`.
10. Per-kind `data` validation for **known** kinds → else `422 invalid_event`. An **unknown** kind
    skips this step, is ignored, and is counted ([§ 5](#5-compatibility--what-this-document-owes-the-policy)).
11. Insert with per-event dedup; return `202`.

Note the ordering of 6 before 7 and 9: the version answer must be reachable even for a batch that is
wrong in other ways, because "which versions do you accept" is the question a stuck seat needs
answered.

### 12.2 Error responses

Every error body has the same shape — `{"error": <code>, "message": <human string>, …context}` — so a
reporter can branch on `error` and a human can read `message`.

| Condition | Status | `error` | Extra body keys | Reporter action |
|---|---|---|---|---|
| accepted | `202` | — | `accepted`, `duplicates`, `ignored_unknown_kinds`, `server_time` | advance cursor, reset backoff |
| wrong content type | `415` | `unsupported_media_type` | `expected` | permanent → quarantine |
| body too large | `413` | `batch_too_large` | `max_bytes`, `received_bytes` | halve and retry once ([§ 11.5](#115-retry-and-backoff)) |
| unparseable body | `400` | `malformed_body` | `detail` | permanent → quarantine |
| missing/unknown/revoked token | `401` | `unauthenticated` | — | permanent → quarantine, badge `degraded` |
| identity ≠ token binding | `403` | `identity_mismatch` | `expected_install_id`, `expected_seat_id` | permanent → quarantine, badge `degraded` |
| **unaccepted schema version** | `400` | `unsupported_schema_version` | `received_version`, `accepted_versions` | permanent → quarantine, `REJECTED.txt`, badge `degraded` |
| batch/event validation failure | `422` | `invalid_event` | `index`, `field`, `reason` | permanent → quarantine, badge `degraded` |
| rate limited | `429` | `rate_limited` | `retry_after_s`, `limit`, `window_s` | back off, retry |
| server fault | `5xx` | `server_error` | `detail` (no internals) | back off, retry |

**The deliberately-invalid example.** A reporter at schema 3 posting to an ingest that accepts `[1,2]`:

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
refusal is counted against `(install=aimla, seat=impl-2)`; the seat renders **visibly degraded** on the
floor with the received and accepted versions readable in its drill-down; the reporter writes
`REJECTED.txt` and stops retrying that batch. The one outcome this design forbids everywhere is the
`200`-with-nothing-in-it that reads as a clean zero — the failure class
[`docs/VERSIONING.md`](../VERSIONING.md#the-failure-direction-must-be-safe--reject-loudly-never-drop-quietly)
and [`docs/KANBAN.md § G-1`](../KANBAN.md#g-1--a-token-whose-user-is-not-a-board-member-fails-silently-and-positively)
both record this fleet hitting for real.

### 12.3 Rate limits

| Limit | Value | Derivation | Over-limit |
|---|---|---|---|
| Requests per seat | **120 / minute** | healthy cadence is 6/min ([§ 11.5](#115-retry-and-backoff)); 120 is 20× headroom — it can only be reached by a spin loop | `429`, `retry_after_s: 30` |
| Events per seat | **20,000 / hour** | a busy seat is ~8,000/day ≈ 330/hour; 20,000 is ~60× headroom, and still bounds one runaway seat's storage to ~10 MB/hour | `429`, `retry_after_s: 60` |
| Body size | 256 KiB | [§ 4.4](#44-size-caps-and-their-derivations) | `413` |
| Failed-auth attempts per token | **10 / hour** | a valid seat never fails auth; 10 tolerates a rotation race | `429` and an operator-visible alert |

Limits are per **token**, evaluated after authentication except the last, which is evaluated per
presented token string.

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
stream. **Duplicates are not a validation failure** and never trigger this path.

### 12.5 Late completions and orphan timeouts

| Rule | Value | Derivation |
|---|---|---|
| Orphan timeout, ordinary tool call | **15 min** | the harness's own `Bash` timeout ceiling is 10 minutes; 15 min = that ceiling + 50 % headroom for flush latency and clock skew |
| Orphan timeout, `Task` (subagent) call | **60 min** | a subagent is a full agent session and routinely runs tens of minutes; 60 min is 4× the ordinary ceiling. Erring long is the safe direction — a desk that shows *working* too long is honest-ish; a desk that goes idle while its subagent runs is the exact defect this section exists to prevent |
| Orphan close | server-side ledger only, `aborted` / `orphan_timeout` | **no wire event is synthesized** — the wire records what a seat said |
| **Late completion** | a `completed` close for a call already closed as `aborted` **overrides** it | **completion is an observation; abort is an inference.** An observation always wins over an inference about the same fact. Counted as `late_completion`; a rising count means a reap rule is too eager and is a design signal, not noise |

### 12.6 The four `D2-MUST` constraints

The complete list of what this contract imposes on D2 (`docs/design/FLEET-STATE.md`). Everything else
about the store is D2's to decide.

| # | Constraint |
|---|---|
| 1 | **Idle may be minted only from `turn.end` with `end_reason == "stop_hook"` and `aborted_call_ids == []`.** Every other turn ending yields `unknown`, never `idle`. |
| 2 | **`stale` (300 s) and `offline` (900 s) are visibly degraded rendered states, never `idle`,** and a seat with `degraded` non-empty renders its badge. |
| 3 | **Per-event dedup on `(install_id, seat_id, event_id)` with a 10-day window,** and the window must exceed maximum spool residency ([§ 10.3](#103-idempotency-and-the-dedup-window)). |
| 4 | **State transitions are ordered by `(event_time, seq)`, never by arrival order,** and `received_at` is the only clock used for liveness, retention and cross-seat comparison. |

---

## 13. Acceptance tests

Each test names **what to build, what to break to make it RED, and what GREEN asserts**. A test never
seen to fail is not evidence — it is a decoration that reports the harness ran.

### AT-1 kill-vs-complete: the headline test

*This is the gate on trusting the signal at all (`docs/PLAN.md § 3`, card #7337).*

- **Build:** a real seat with the reporter installed, pointed at a real ingest over TLS. A dispatch
  fixture that runs `Task` → the subagent runs `Bash: sleep 120`.
- **Do:** wait until the server's ledger shows both calls open, then type `/clear` in the seat.
- **GREEN — the event stream matches [§ 8.7](#87-worked-flow--a-clear-during-a-subagents-bash-call)**:
  two `tool.end`s with `outcome:"aborted"`, `abort_reason:"session_cleared"`,
  `close_source:"reap_session_boundary"`; a `subagent.stop` with `outcome:"aborted"`; a `turn.end`
  with `end_reason:"session_cleared"` and `aborted_call_ids` of length 2; then `session.end` and
  `session.start(source:"clear")`.
- **GREEN — what must NOT appear:** no `tool.end` with `outcome:"completed"` for either call; no
  `turn.end` with `end_reason:"stop_hook"`; and **no idle transition in the derived state** — the seat
  goes `working → unknown`, never through `idle`, and the floor shows no idle animation.
- **RED (run it, don't assume it):** disable the reap in [§ 8.3](#83-the-reap-rules) and re-run. The
  calls stay open until the orphan timeout, the boundary events carry `aborted_call_ids: []`, and a
  consumer applying only "turn ended ⇒ idle" mints the false idle. Seeing that is the proof the test
  discriminates.
- **Second RED:** keep the reap but weaken `D2-MUST` #1 to "any `turn.end` ⇒ idle". The idle appears.
  Both halves — the schema and the consumer rule — must be individually necessary.

### AT-2 sanitizer red fixtures

- **Build:** the 8 fixtures of [§ 7.5](#75-red-fixtures--required-tests) plus the two whole-event
  assertions, as unit tests, run on Linux **and** Windows.
- **RED:** replace the sanitizer with the identity function → all 8 fail. Then restore it and remove
  only the allowlist → fixture 8 fails alone (proving the layers are independently load-bearing).
- **GREEN:** all 8 exact-match; no planted credential appears in any serialized event.

### AT-3 the reporter never blocks the seat

- **Build:** a harness that invokes `hook PreToolUse` 200 times with a realistic payload, measuring
  wall time per invocation, on both platforms.
- **GREEN:** p99 < 250 ms; **exit code 0 in 200/200**, including a run where `spool_dir` is
  read-only, one where the config file is absent, one where `open-calls.json` is corrupt, and one
  where stdin is empty. Nothing is printed to stdout in any run.
- **RED:** insert a synchronous 2 s HTTPS call into the hook path → p99 blows the budget. Make the
  hook `process.exit(1)` on a parse error → the exit-code assertion fails.

### AT-4 survives the server being down for days

- **Build:** point the flusher at a black-holed address (a firewall `DROP`, not a refusal — a `DROP`
  exercises the timeout path, a refusal exercises only the connect-error path; run both).
- **Do:** drive normal seat activity for 30 min, then restore the server.
- **GREEN:** the seat is unaffected throughout (AT-3's budget still met); spool grows monotonically;
  `oldest_unsent_age_s` rises; backoff is observed to reach and hold at ≤ 120 s with jitter; on
  restore **every** spooled event arrives, `duplicates` stays at 0, and `spool_dropped_events` is 0.
- **RED:** shrink the spool bound to 1 MiB and repeat → `spool_dropped_events` > 0 and the seat badges
  `lossy`, proving overflow is visible rather than silent.

### AT-5 duplicate delivery is free

- **Build:** a flusher flag that re-POSTs the last accepted batch verbatim.
- **GREEN:** second response is `202` with `accepted: 0, duplicates: N`; the ledger and the derived
  state are byte-identical before and after; no double-counted tool call.
- **RED:** drop the unique index → duplicates ingest, open-call counts double, and the seat shows
  phantom concurrent calls.

### AT-6 unknown schema version is refused loudly

- **Build:** a hand-crafted POST with `schema_version: 999` and 120 otherwise-valid events.
- **GREEN:** `400`, body exactly the shape in [§ 12.2](#122-error-responses) naming received and
  accepted versions; **0 events stored** (assert by count, not by absence of errors); the refusal
  counter for that seat increments; the seat renders degraded; the reporter writes `REJECTED.txt`,
  quarantines, and does not retry.
- **RED:** make the ingest accept any version → events land and nothing is degraded. Also assert the
  negative control: the *same* batch at an accepted version returns `202`, so the test discriminates
  version handling and not batch validity.

### AT-7 staleness alarm — the dark-reporter backstop

- **Build:** a running seat with a healthy heartbeat.
- **Do:** `SIGKILL` the flusher and prevent respawn (make the lock look fresh).
- **GREEN:** at ≤ 300 s the seat renders `stale`; at ≤ 900 s, `offline`; **at no point does it render
  `idle`**, and the transition is visible in the UI without a reload.
- **RED:** disable the staleness evaluation → the seat renders its last known state forever, which is
  precisely the 30-day-dark failure of [§ 3.4](#34-why-identity-never-comes-from-the-environment)
  reproduced on purpose.

### AT-8 predicate-constant alarm

- **Build:** a seat whose `notification_kind` classifier is forced to return `other` always.
- **GREEN:** after ≥ 500 evaluations in the window, `predicate_constant` fires for that predicate and
  the seat surfaces it. **Negative control:** a seat with a mixed distribution over the same volume
  does **not** fire.
- **RED:** the alarm with no threshold check fires never, or always — both are visible against the
  control.

### AT-9 a torn spool line does not poison the batch

- **Build:** a spool bucket with a valid line, a `\n`-terminated truncated JSON line, another valid
  line, and a trailing partial line with no `\n`.
- **GREEN:** both valid lines are delivered; the truncated line lands in `quarantine/corrupt.jsonl`
  with `spool_corrupt_lines == 1`; the trailing partial line is **untouched** and is delivered
  intact after it is completed on the next pass.
- **RED:** make the parser throw on the batch → nothing is delivered, which is the wedge this rule
  exists to prevent.

### AT-10 concurrent append atomicity

- **Build:** 8 processes × 500 `hook` invocations each, concurrently, on Linux and on Windows.
- **GREEN:** exactly 4,000 lines, every one parsing, no interleaved fragments, no lost lines.
- **RED:** replace the single `writeSync` with a two-part write (payload, then `\n`) → interleaving
  appears under concurrency. If it does **not** appear on a platform, that platform's atomicity claim
  is unproven, not proven — increase concurrency until the RED reproduces before trusting the GREEN.

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
  the batch is quarantined and never retried; the stream continues with the next batch.
- **RED:** allow partial ingest → 199 stored under a success status and the reporter's cursor
  advances, permanently losing event 137 with no record that anything was lost.

### AT-14 statusLine sampling and passthrough

- **Build:** a seat with a pre-existing statusLine command, wrapped by the installer.
- **GREEN:** the original status line still renders, byte-identical; over a 10-minute active window,
  `context.sample` events number ≤ 10 for cadence plus one per 5-point bucket crossing;
  `statusline_suppressed` is > 0 (a zero here means sampling is not running at all).
- **RED:** remove the sampling gate → hundreds of events per minute. Break the wrapped command → the
  seat's status line blanks, `wrapped_statusline_failures` rises, and **the reporter still exits 0**.

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

---

## 14. Every number, and where it comes from

One table, so a reviewer can audit the arithmetic without reading the prose, and so a future change
can find every number that moves with it. **Measured** = observed in this fleet or documented by the
harness. **Derived** = computed from another number here. **Chosen** = a judgement call, with the
reasoning and, where it applies, what would re-derive it.

| Value | Number | Basis | Where |
|---|---|---|---|
| Hook wall-time budget | 250 ms | Chosen — ~4× the 30–60 ms Node cold start that dominates it; under the ~300 ms a human notices. **Verified by AT-3, not assumed** | [§ 2.2](#22-rules-that-protect-the-seat) |
| Flusher lock staleness | 90 s | Derived — 1.5 × the 60 s heartbeat: never trips on a flusher inside a 15 s POST | [§ 2.3](#23-the-flusher-must-be-alive-whenever-the-seat-is) |
| Token entropy | 256 bits | Chosen — standard floor for a bearer credential | [§ 3.3](#33-authentication-and-the-identity-binding-rule) |
| Token rotation overlap | 7 days | Chosen — seats upgrade on their owners' schedules; a week spans a weekend plus slack | [§ 3.3](#33-authentication-and-the-identity-binding-rule) |
| Request total deadline | 15 s | Derived — 256 KiB at 1 Mbit/s (2.1 s) + TLS (~1 s) + processing ≈ 4 s worst realistic; 15 s ≈ 3.5× | [§ 3.5](#35-transport-is-wan-always) |
| Connect deadline | 5 s | Derived — ~2.5× a 2 s pathological cross-continent TLS connect | [§ 3.5](#35-transport-is-wan-always) |
| gzip threshold | 8 KiB | Chosen — below it, CPU and header overhead outweigh the WAN saving | [§ 3.5](#35-transport-is-wan-always) |
| Max event size | 4 KiB | Derived — 8× the ~500 B typical, aligned to the conventional atomic-small-write floor (`PIPE_BUF`) | [§ 4.4](#44-size-caps-and-their-derivations) |
| Max events per batch | 200 | Derived — ~100 KB at typical size; bounds the atomic-rejection blast radius | [§ 4.4](#44-size-caps-and-their-derivations) |
| Max batch body | 256 KiB | Derived — 4× under nginx's 1 MiB `client_max_body_size` default, the tightest common default in the stack | [§ 4.4](#44-size-caps-and-their-derivations) |
| Descriptor cap | 200 B | Derived — three constraints agree: ~160 chars renderable, 5× the longest realistic command, keeps events ~500 B | [§ 7.4](#74-truncation) |
| Title cap | 120 B | Chosen — ~18 English words; a dispatch description is 3–8 | [§ 7.4](#74-truncation) |
| Typical event size | ~500 B | Derived from the field tables in [§ 6](#6-event-kinds) — the sizing input for spool and rate limits |  |
| Busy-seat volume | ~8,000 events/day ≈ 4 MB/day | **Estimate, not a measurement** — 1,440 heartbeats + ≤ 1,440 context samples + ~4,300 tool events + ~500 turn events. Re-derived from the first week of live data | [§ 6.0](#60-conventions-and-how-harness-payloads-are-read) |
| statusLine sample cadence | 60 s | Derived — matches the heartbeat; 60× reduction from a ~1 Hz render rate | [§ 6.11](#611-contextsample) |
| statusLine bucket | 5 percentage points | Chosen — the resolution a human reads a gauge at | [§ 6.11](#611-contextsample) |
| Wrapped statusLine timeout | 1 s | Chosen — a status line re-renders continuously; slower is already broken | [§ 6.11](#611-contextsample) |
| Session-idle inference | 6 h | Chosen — exceeds an overnight unattended run; asymmetric cost favours generosity | [§ 6.2](#62-sessionend) |
| Compaction close timeout | 10 min | Derived — ~10× a typical one-minute compaction | [§ 6.10](#610-compactionend) |
| Heartbeat interval | 60 s | Chosen — 1,440/day ≈ 18 % of a seat's volume for continuous liveness | [§ 9.1](#91-the-cadence-and-the-alarm) |
| Flush interval | 10 s | Chosen — under human "live" perception; caps request rate at 6/min | [§ 11.5](#115-retry-and-backoff) |
| Flush event trigger | 50 events | Derived — ~25 KB, a batch worth a WAN round-trip | [§ 11.5](#115-retry-and-backoff) |
| Seat `stale` | 300 s | Derived — ~4× the 70 s worst-case freshness, and above the 120 s backoff ceiling so a recovered outage never trips it | [§ 9.1](#91-the-cadence-and-the-alarm) |
| Seat `offline` | 900 s | Derived — 3× `stale`, so `stale` is a distinct investigable state | [§ 9.1](#91-the-cadence-and-the-alarm) |
| Predicate-constant threshold | 500 evaluations / 24 h | **Chosen provisionally** — ~a working day of evidence; re-picked from the first week's per-predicate counts | [§ 9.4](#94-the-predicate-constant-alarm) |
| Clock-skew badge | 120 s | Derived — 2× heartbeat, above NTP drift, below the 300 s stale threshold so the alarms cannot alias | [§ 10.1](#101-two-clocks-and-which-is-authoritative-for-what) |
| Dedup window | 10 days | Derived — 25 % above the ~8-day maximum spool residency. **Moves whenever the spool bound moves** | [§ 10.3](#103-idempotency-and-the-dedup-window) |
| Batch-id memory | 24 h | Chosen — covers the retry ladder plus a same-day manual replay | [§ 10.4](#104-batch-level-idempotency) |
| Spool bound | 32 MiB | Derived — ~8 days at 4 MB/day; satisfies "survives the server being down for days" at negligible disk cost | [§ 11.3](#113-rotation-and-the-overflow-policy) |
| Spool bucket | 1 UTC hour | Chosen — removes rotation-rename races entirely; ~175 KB/bucket on a busy seat | [§ 11.1](#111-layout) |
| Open-call index | 64 entries | Chosen — far above any observed concurrency; overflow is itself a signal | [§ 8.2](#82-matching-a-close-to-its-open) |
| Backoff base / factor / cap | 2 s / ×2 / 120 s, full jitter | Derived — 2→4→8→16 covers a ~30 s app restart; the 120 s cap sits below the 300 s stale threshold; jitter prevents a fleet-wide herd | [§ 11.5](#115-retry-and-backoff) |
| `Retry-After` clamp | 600 s | Chosen — honours the server without letting a bad header park a seat for hours | [§ 11.5](#115-retry-and-backoff) |
| Orphan timeout, ordinary | 15 min | Derived — the harness's own 10-minute `Bash` timeout ceiling + 50 % | [§ 12.5](#125-late-completions-and-orphan-timeouts) |
| Orphan timeout, `Task` | 60 min | Chosen — 4× the ordinary; erring long is the safe direction | [§ 12.5](#125-late-completions-and-orphan-timeouts) |
| Rate limit, requests | 120/min/seat | Derived — 20× the 6/min healthy cadence | [§ 12.3](#123-rate-limits) |
| Rate limit, events | 20,000/h/seat | Derived — ~60× the ~330/h busy rate | [§ 12.3](#123-rate-limits) |
| Failed-auth limit | 10/h/token | Chosen — a valid seat never fails; 10 tolerates a rotation race | [§ 12.3](#123-rate-limits) |
| Quarantine caps | 256 KiB corrupt / 1 MiB rejected / 64 KiB marker / 1 MiB log | Chosen — enough to diagnose, bounded so a broken seat cannot fill a disk | [§ 11.1](#111-layout) |

Three numbers rest on estimates rather than measurements and say so at their definition: the
busy-seat volume, the predicate-constant threshold, and the hook wall-time budget. Each names what
re-derives it, and each has ≥ 4× headroom in the direction that fails safely.

---

## 15. Decisions taken, revisable at review

This document contains no placeholders and no deferred decisions. Where a call was genuinely
contestable it was **made**, and it is listed here with the alternative and the cost of being wrong, so review can
reverse it deliberately rather than discover it later.

| # | Decision | Alternative considered | Why this one | Cost if wrong |
|---|---|---|---|---|
| 1 | **Identity repeats on every event**, with server-enforced equality against the batch header and the token binding | batch-level only, stamped onto events at ingest | an event is the durable, forwardable, quotable unit; ~60 B (12 %) buys unambiguity, and enforced equality makes drift impossible | ~12 % wire overhead. Reversible in one direction only: removing the fields later is a **schema bump** under the policy |
| 2 | **`Stop` reaps every open call in its session** as aborted | wait for the orphan timeout and let the server infer | a false idle at a turn boundary is the exact defect this design exists to prevent; waiting 15–60 min to notice defeats it | over-eager aborts on any legitimate call outstanding at `Stop`. Bounded by the late-completion override ([§ 12.5](#125-late-completions-and-orphan-timeouts)) and made visible by `late_completion` |
| 3 | **`Task` emits both `tool.start` and `subagent.spawn`** sharing a `call_id` | one `subagent.spawn` and no `tool.start` for `Task` | a special case in the call ledger would live in the *one* path the kill-vs-complete requirement is actually about | ~120 B per dispatch, tens of times a day |
| 4 | **Batches are atomic** — one bad event rejects 200 | per-event partial ingest with a report | a partially-ingested batch under a success status destroys the reporter's only other copy of the data | one malformed event costs ≤ 199 neighbours, bounded by the poison-pill rule |
| 5 | **A new event `kind` is compatible; unknown kinds are ignored and counted** | reject the batch on an unknown kind | rejecting would discard the known events beside it, and a kind is the exact analogue of an added optional field | a genuinely-wrong kind name is tolerated rather than caught at the wire. Surfaced as `reporter_ahead`. **If review calls this policy rather than mechanic, it belongs in `docs/VERSIONING.md`, not duplicated** |
| 6 | **`attention.request` exists**, classified client-side from `Notification` | omit it; let D2 derive *blocked* from its status tiers | `docs/PLAN.md § 7` requires *blocked* as a rendered state and no other hook supplies it | one kind's worth of surface, and a knowingly-fragile classifier — instrumented ([§ 6.12](#612-attentionrequest)) rather than trusted. Deleting it later is compatible |
| 7 | **`turn.start` carries `prompt_chars`** | carry nothing about the prompt | a length is a size, not content, and distinguishes a nudge from a pasted brief | if review reads a length as content-adjacent, deleting the field is compatible |
| 8 | **The `lifo_tool_name` match fallback** exists at all | require a harness call reference; drop the close if absent | dropping a close would put an unmatched call into the ledger — the failure this design forbids | can swap two concurrent same-tool calls' ids and durations; **cannot** affect counts or outcomes, so `D2-MUST` #1 is untouched. `match` reports the exposure per event |
| 9 | **The flusher is OS-supervised *and* hook-respawned** | hook-respawn only (no OS integration) | respawn-only means an idle seat stops heartbeating and renders `offline` while it is merely quiet — destroying the idle/offline distinction the product depends on | installer complexity on two platforms (card #7336) |
| 10 | **`GET /api/ingest/health` requires a seat token** | unauthenticated health surface | the accepted-version set is fleet-internal, and everyone who needs it already holds a token | an operator without a token must read the deployed declaration instead |

**One thing this document deliberately does not contain:** the accepted schema-version set. That set
lives in exactly one machine-readable place in the ingest's code and is reported by the health
surface, per [`docs/VERSIONING.md § Wire compatibility` rule 2](../VERSIONING.md#the-rules). Writing
it here would create a second statement of it, free to drift, with nothing binding the two together.

---

## 16. What an implementer builds from this

In dependency order, with the gate each must pass before the next is trusted.

| Order | Artifact | Gate |
|---|---|---|
| 1 | the sanitizer, standalone and pure | AT-2 RED then GREEN, both platforms |
| 2 | `hook` subcommand + spool writer + open-call index | AT-3, AT-9, AT-10 |
| 3 | `flusher` subcommand + `state.json` + backoff | AT-4, AT-5 |
| 4 | `statusline` subcommand + passthrough | AT-14 |
| 5 | ingest endpoint: auth, validation, atomic batch, dedup | AT-6, AT-12, AT-13, AT-15 |
| 6 | server-side call ledger + orphan timeouts | AT-1 (**the gate on trusting the signal at all**), AT-11 |
| 7 | staleness and predicate alarms | AT-7, AT-8 |

Two of these are hard requirements before anything downstream may treat this telemetry as true:
**AT-1** (`docs/PLAN.md § 3`, card #7337 — a real `/clear` against a real subagent tool call), and a
**real install on a Windows seat** (card #7336), because every file, path and process assumption in
[§ 11](#11-the-spool-and-the-flusher) is cross-platform by design and unproven until then.

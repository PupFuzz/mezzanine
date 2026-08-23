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

```
  Claude Code seat (Linux or Windows)                    │ WAN, TLS 1.2+ │   Mezzanine host
  ─────────────────────────────────────────────────────  │               │  ────────────────
  SessionStart/End ─┐                                    │               │
  UserPrompt/Stop  ─┤                                    │               │
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
| `node fleet-reporter.js selftest` | one-shot, run by the installer and by CI | assert config, TLS reachability, accepted schema set, sanitizer fixtures, predicate discrimination |

Hook wiring lives in the seat's Claude Code settings; the complete set of hooks this design
subscribes to, and what each one produces, is
[§ 6.0](#60-conventions-and-how-harness-payloads-are-read). The shape below is illustrative — **the
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
— a snapshot of ≤ 128 records plus at most one flush interval of journal tail — and three to six
small appends: all under 5 ms. 250 ms is ~4× the expected
worst case, and is under the ~300 ms at which a human notices added latency between tool calls.
**This is a budget to verify, not a measurement**: AT-3 measures it on both platforms, and if a real
seat exceeds it, the fix is in the reporter, not in the number.

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

| Policy rule | How D1 complies |
|---|---|
| [rule 1](../VERSIONING.md#the-rules) — every event carries an explicit `schema_version` | [§ 4.3](#43-common-per-event-fields) puts it in the per-event common fields and [§ 4.2](#42-batch-envelope-fields) on the batch, with server-enforced equality between them; a batch without it is `400 malformed_body` — invalid input, not a legacy payload to guess at |
| [rule 2](../VERSIONING.md#the-rules) — the accepted set is declared in exactly one machine-readable place | `GET /api/ingest/health` reports that declaration ([§ 4.1](#41-endpoints)); **this doc names no accepted set**, deliberately ([§ 15](#15-decisions-taken-revisable-at-review)) |
| [rule 3](../VERSIONING.md#the-rules) — an added optional field is backward-compatible | the server **ignores unknown fields** at a known version and counts them; the reporter defaults absent optional fields to `null` ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)) |
| [rule 4](../VERSIONING.md#the-rules) — removing / renaming / retyping / **re-meaning** a field needs a bump plus a window | binding on every future edit of [§ 6](#6-event-kinds). The re-meaning case is the one to fear: it passes every structural validator |
| [rule 5](../VERSIONING.md#the-rules) — the support window is `N` and `N-1` | the spool holds an event for at most 8 days ([§ 11.3](#113-rotation-and-the-overflow-policy)), and the window is what lets a reporter upgraded mid-spool drain its older lines cleanly ([§ 11.2](#112-spool-line-format)) |
| [rule 6](../VERSIONING.md#the-rules) — dropping support is its own announced release act | nothing in this document narrows an accepted set; a release that does states it |
| [rule 7](../VERSIONING.md#the-rules) — **additive change: a new `kind`, a new closed-enum member** | two mechanics implement it: [§ 12.1](#121-validation-order) step 10 (an unknown `kind` skips per-kind validation, is ignored, and is counted in `ignored_unknown_kinds`) and [§ 6.0](#60-conventions-and-how-harness-payloads-are-read) rule 4 (an unrecognised enum value is coerced to the field's unknown member and counted, at the reporter *and* again at the ingest). Both are counted per seat and render the seat `reporter_ahead` — informational, and never a batch rejection, because rejecting would discard the known events beside it |
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

**Every harness-sourced enum carries an unknown member, and it is always the last one listed.**
`unknown` on [`session.start.source`](#61-sessionstart) and
[`compaction.start.trigger`](#69-compactionstart); `other` on
[`session.end.end_reason`](#62-sessionend), on
[`attention.request.notification_kind`](#612-attentionrequest) and on `reporter_platform`
([§ 4.2](#42-batch-envelope-fields)). Enums whose values the **reporter itself mints** — `outcome`,
`abort_reason`, `close_source`, `match`, `sample_reason`, `end_reason` on `turn.end`, `resolution`,
`resolution_source` — have no unknown member and need none: a value outside those sets is a reporter
bug, not a harness change, and the ingest refuses it as `422 invalid_event`.

#### What is verified about the harness, and what is not

The key names and hook semantics used below were read from the Claude Code hooks reference
(`code.claude.com/docs/en/hooks`) on **2026-08-23**. That is a citation, not a guarantee: it
describes a product this project neither controls nor can pin, so this table separates what the
reference states from what nobody has checked. Every **UNVERIFIED** row names what it costs and what
closes it.

| Fact this design rests on | Status |
|---|---|
| `session_id` and `hook_event_name` on every hook payload | **CONFIRMED** — common input fields |
| `tool_input.*` (`.command`, `.file_path`, `.pattern`, `.url`, `.query`, `.description`) | **CONFIRMED** — the descriptor allowlist ([§ 7.1](#71-layer-1--the-descriptor-allowlist)) reads only these |
| `tool_use_id` present on **both** `PreToolUse` and `PostToolUse` | **CONFIRMED** — so `harness_call_ref` should be present on ~100 % of closes, and `match` telemetry that says otherwise is a defect signal ([§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)) |
| `PostToolUse` fires only when a tool call **succeeds**; a failed call fires the separate **`PostToolUseFailure`** hook | **CONFIRMED** — both are subscribed, and [§ 6.6](#66-toolend) explains why subscribing to only the first would make *idle* unreachable |
| `SessionEnd` exists, with `reason` ∈ `clear` \| `resume` \| `logout` \| `prompt_input_exit` \| `other` | **CONFIRMED** — session end is an observation here, not an inference ([§ 6.2](#62-sessionend)) |
| `SessionStart.source` includes `fork`, alongside `startup` \| `resume` \| `clear` \| `compact` | **CONFIRMED** |
| `PreCompact.trigger` ∈ `auto` \| `manual`; a `PostCompact` hook exists | **CONFIRMED** |
| `agent_id` and `agent_type` are common input fields **present inside subagents**; a `SubagentStart` hook exists | **CONFIRMED** — the basis of the subagent binding ([§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call)) and of `agent_scope` ([§ 6.5](#65-toolstart)) |
| `PermissionRequest` and `PermissionDenied` hooks exist | **CONFIRMED** — they are the two edges of *blocked* ([§ 6.12](#612-attentionrequest), [§ 6.13](#613-attentionresolved)) |
| statusLine payload carries `context_window.used_percentage` | **CONFIRMED**, and **nullable early in a session**; `current_usage` is null after a `/compact` until the next API call ([§ 6.11](#611-contextsample)) |
| statusLine is **event-driven** with a ~300 ms debounce, and an in-flight status-line script is **cancelled** when a new trigger arrives; a timed re-render happens only when `refreshInterval` is configured | **CONFIRMED** — why [§ 6.11](#611-contextsample) states a ceiling rather than a reduction ratio, and why statusLine-side counters are a floor ([§ 9.3](#93-degradation-counters)) |
| hook exit codes: **2 blocks** the operation and feeds stderr to the model; any other non-zero is a non-blocking error; `SessionStart` and `UserPromptSubmit` **stdout is added to the model's context** | **CONFIRMED** — the mechanism behind P-1 and P-2 ([§ 2.2](#22-rules-that-protect-the-seat)) |
| `tool_response`'s schema, and whether any payload field flags an errored call | **UNVERIFIED** — and no longer needed: which hook closed the call carries that fact ([§ 6.6](#66-toolend)). Nothing to close |
| `stop_hook_active` on the `Stop` payload | **UNVERIFIED** — carried as a nullable passthrough that nothing gates on; if the key is absent the field is `null` and `payload_key_missing.stop_hook_active` counts it |
| `SubagentStop`'s own payload schema — does it carry `agent_id`? | **UNVERIFIED** — [§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call) closes the call exactly when it does and degrades to the sole-open rule when it does not, counting which happened. Closed by reading one real payload on an instrumented seat |
| the `Bash` tool's 10-minute timeout ceiling | **UNVERIFIED** against the installed build — the 15-minute orphan timeout ([§ 12.5](#125-late-completions-and-orphan-timeouts)) is derived from it and moves with it |
| nginx `client_max_body_size` on the actual deploy host | **UNVERIFIED** — the host is not provisioned yet (`docs/PLAN.md` D-08); read it at first deploy ([§ 4.4](#44-size-caps-and-their-derivations)) |

**The hook set this design subscribes to.** Everything else the harness offers is deliberately not
wired: an unsubscribed hook costs nothing, a subscribed one costs latency on the seat.

| Hook | What the reporter does with it | Events |
|---|---|---|
| `SessionStart` | reap by `previous_session_id` when `source == "clear"` | `session.start` |
| `SessionEnd` | reap that session's open calls | `tool.end`(s), `turn.end` if open, `session.end` |
| `UserPromptSubmit` | resolve an open attention request | `turn.start`, maybe `attention.resolved` |
| `Stop` | reap that session's open calls | `tool.end`(s), `turn.end` |
| `PreToolUse` | open a ledger entry | `tool.start`, plus `subagent.spawn` when `tool_name == "Task"` |
| `PostToolUse` | close it as succeeded | `tool.end` (`completed`), maybe `subagent.stop`, maybe `attention.resolved` |
| `PostToolUseFailure` | close it as failed | `tool.end` (`failed`), maybe `subagent.stop`, maybe `attention.resolved` |
| `SubagentStart` | bind `agent_id` to the open `Task` call | *(none — a binding, not an event)* |
| `SubagentStop` | close the bound `Task` call if it is still open | `tool.end`, `subagent.stop` |
| `PreCompact` | — | `compaction.start` |
| `PostCompact` | — | `compaction.end` |
| `PermissionRequest` | open an attention request, unambiguously | `attention.request` |
| `PermissionDenied` | close it | `attention.resolved` (`denied`) |
| `Notification` | open an attention request, classified | `attention.request` |
| statusLine *(integration, not a hook)* | sample context; write the seat's last-sample state | `context.sample`, sampled |

**Reading the harness payload — defensively, always.** The key names above are what Claude Code 2.1.x
emits on stdin to hooks. **The implementer re-verifies each against the installed harness's own hook
documentation before shipping** — the table is a design input, not a source of truth about someone
else's product. The binding rules are:

1. A missing or unexpected key yields `null` in the event and increments
   `payload_key_missing.<key>` ([§ 9.3](#93-degradation-counters)). **It never suppresses the event.**
2. No branch of any payload read decides *whether* to emit — only *what to label*.
3. The hook name arrives twice (`argv[2]` and `hook_event_name`). The reporter uses `argv[2]`, and a
   disagreement increments `hook_name_mismatch`. This is a free discriminating check on the assumption
   that the payload's own labelling is what we think it is.
4. **An unrecognised value in a closed-enum field is coerced to that field's unknown member and
   counted as `enum_value_unknown.<field>`. The raw value never reaches the wire.** This is not
   tidiness. The enums in this document are exactly what the ingest validates, so one new harness
   value passed through verbatim makes its event invalid, [§ 12.4](#124-batches-are-atomic) rejects
   all 200 events in its batch, and [§ 11.5](#115-retry-and-backoff)'s poison-pill rule quarantines
   them permanently — an unannounced harness change would delete a seat's telemetry rather than
   mislabel one field. The coercion happens at the **reporter**; the ingest applies the same rule
   again on receipt ([§ 12.1](#121-validation-order) step 10) so a *newer* reporter's added member
   cannot poison an *older* server either. Both ends implement one policy rule:
   [`docs/VERSIONING.md § Wire compatibility`](../VERSIONING.md#the-rules) rule 7.
5. An unknown `kind` is treated the same way and for the same reason — accepted, ignored, counted,
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
| `subagent.spawn` | `PreToolUse` where `tool_name == "Task"` | hook | 5–60 |
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

**Trigger:** the `SessionStart` hook, unconditionally. When `source == "clear"` the hook first runs
the reap for `previous_session_id` ([§ 8.3](#83-the-reap-rules)), so any calls the clear killed are
already closed as aborted and appear *earlier* in the spool. A `/clear` also fires
`SessionEnd(reason: "clear")`, which reaps the same set; **the order of those two hooks is not
documented, so both reap and the reap is idempotent** — whichever runs second finds nothing open and
increments `reap_noop_second_signal`, which is the counter that says both signals are alive
([§ 8.4](#84-detecting-a-clear-with-two-independent-signals)).

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `source` | enum | — | no | `startup` \| `resume` \| `clear` \| `compact` \| `fork` \| `unknown` | `"clear"` |
| `project_label` | string | — | yes | ≤ 48 B, sanitized basename of cwd | `"mezzanine"` |
| `harness_label` | string | — | yes | ≤ 32 B, `^[A-Za-z0-9._-]+$` | `"claude-code/2.1.219"` |
| `previous_session_id` | string | — | yes | ≤ 128 B | `"e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913"` |

`source` is `unknown` when the payload key is absent **or carries a value this reporter does not
know** ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read) rule 4) — never silently
`startup`, because `startup`-vs-`clear` is load-bearing for
[§ 8](#8-call-lifecycle--the-kill-vs-complete-contract) and a wrong-but-plausible default would hide
exactly the case this design exists to catch. `fork` is in the set because the harness documents it;
a fork is not a kill, so it reaps nothing.

```json
{ "event_id":"01K3TA1B2C3D4E5F6G7H8J9K0M","schema_version":1,"kind":"session.start",
  "event_time":"2026-08-23T14:22:40.201Z","seq":48310,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"source":"clear","project_label":"mezzanine","harness_label":"claude-code/2.1.219",
          "previous_session_id":"e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913"} }
```

### 6.2 `session.end`

**Trigger:** the `SessionEnd` hook — an **observation**, not an inference. The hook carries a
`reason`; `end_reason` is that value passed through, coerced to `other` if it is one this reporter
does not know ([§ 6.0](#60-conventions-and-how-harness-payloads-are-read) rule 4).

Before emitting, the hook reaps every call still open **in that session** and emits a `turn.end` if a
turn was open, so the spool order at a session boundary is: aborted `tool.end`s (and their
`subagent.stop`s), then `turn.end`, then `session.end`.

| `end_reason` | Where it comes from |
|---|---|
| `clear` | `SessionEnd(reason: "clear")` — the `/clear` path, the one [§ 8](#8-call-lifecycle--the-kill-vs-complete-contract) is about |
| `resume` | `SessionEnd(reason: "resume")` |
| `logout` | `SessionEnd(reason: "logout")` |
| `prompt_input_exit` | `SessionEnd(reason: "prompt_input_exit")` |
| `other` | any other `reason`, including one this reporter does not recognise |
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

**Trigger:** the `Stop` hook (`end_reason: "stop_hook"`), or a session boundary reached with a turn
still open — `session_cleared` when that boundary is a `/clear`, `session_ended` for every other
`SessionEnd` reason. **This is the event a consumer reads to mint "idle", and the only one.**

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `end_reason` | enum | — | no | `stop_hook` \| `session_cleared` \| `session_ended` | `"stop_hook"` |
| `duration_ms` | int | ms | yes | ≥ 0; `null` if no `turn.start` was seen | `41880` |
| `open_calls_at_end` | int | — | no | 0…64; counted **before** the reap closed them | `0` |
| `aborted_call_ids` | array\<ULID\> | — | no | 0…64 elements | `[]` |
| `stop_hook_active` | bool | — | yes | `null` when the payload does not carry it | `false` |
| `tool_calls` | int | — | no | ≥ 0, calls started in this turn | `6` |
| `failed_calls` | int | — | no | ≥ 0, calls closed `failed` in this turn | `1` |

`aborted_call_ids` names exactly the calls `open_calls_at_end` counted, so the two never disagree —
the reap ([§ 8.3](#83-the-reap-rules)) emits their closes immediately before this event.
(`session_cleared` here is the same boundary `session.end` reports as `clear`: the two enums differ
because `session.end` passes the harness's own `reason` through verbatim while this field names the
boundary from the turn's point of view.)

> **`D2-MUST` #1 — the idle rule.** A consumer may mint an *idle* transition **only** from a
> `turn.end` with `end_reason == "stop_hook"` **and** `aborted_call_ids == []`. Every other
> combination means the turn stopped for a reason other than the agent finishing, and the seat's
> state is `unknown`, never `idle`. This one sentence is what the kill-vs-complete machinery in
> [§ 8](#8-call-lifecycle--the-kill-vs-complete-contract) exists to make checkable.

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
  "data":{"end_reason":"stop_hook","duration_ms":41880,"open_calls_at_end":0,
          "aborted_call_ids":[],"stop_hook_active":false,"tool_calls":6,"failed_calls":1} }
```

### 6.5 `tool.start`

**Trigger:** the `PreToolUse` hook, for every tool without exception — including `Task`, which *also*
produces a `subagent.spawn` sharing the same `call_id` ([§ 6.7](#67-subagentspawn)).

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `call_id` | ULID | — | no | 26 chars, minted by the reporter | `"01K3TA4E5F6G7H8J9K0M1N2P3Q"` |
| `tool_name` | string | — | no | ≤ 64 B, `^[A-Za-z0-9_.-]{1,64}$`, else the literal `"INVALID_TOOL_NAME"` and `invalid_tool_name` is incremented | `"Bash"` |
| `descriptor` | string | — | **yes** | ≤ 200 B, sanitized ([§ 7](#7-sanitization-at-the-reporter)); `null` when the tool is not on the descriptor allowlist | `"Bash: composer test"` |
| `descriptor_truncated` | bool | — | no | — | `false` |
| `agent_scope` | enum | — | **yes** | `main` \| `subagent` \| `null` | `"main"` |
| `parent_call_id` | ULID | — | **yes** | the `call_id` of the `Task` call this call runs inside; `null` in the main agent or when the binding is unresolved | `null` |
| `harness_call_ref` | string | — | yes | ≤ 64 B, opaque | `"toolu_01A9F3kQ2mZ"` |
| `open_calls_before` | int | — | no | 0…64 | `1` |

**`agent_scope` is labelled from the harness's own `agent_id` field, and from nothing else.**
`agent_id` and `agent_type` are documented common input fields *present inside subagents*: a hook
invocation carrying `agent_id` is running in a subagent, one without it is running in the main agent.
That is a **payload field**, which is the distinction
[§ 3.4](#34-why-identity-never-comes-from-the-environment) actually draws — the 30-day outage was an
*undocumented environment variable* whose meaning changed under a harness upgrade with nothing
watching it. This label is watched: both branches ride the heartbeat as the `agent_scope_subagent`
predicate, and the predicate-constant alarm ([§ 9.4](#94-the-predicate-constant-alarm)) fires if the
harness ever starts sending `agent_id` everywhere (constant `subagent`) or stops sending it at all
(constant `main`). `agent_scope` is `null` only when the payload could not be read, it is never
inferred from an environment variable, and **nothing in the pipeline gates on it** — it labels.

**`parent_call_id` is the intern join key, and the harness's `agent_id` never transits.** The
reporter resolves it locally: `SubagentStart` binds an `agent_id` to the open `Task` call
([§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call)), and every later hook carrying that
`agent_id` stamps that call's `call_id` here. A consumer therefore knows which intern ran which tool
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
| `PostToolUse` — fires only when the call **succeeded** | `completed` | `post_tool_use` |
| `PostToolUseFailure` — fires when the call **failed** | `failed` | `post_tool_use_failure` |
| a reap ([§ 8.3](#83-the-reap-rules)) — no harness close was ever observed | `aborted` | `reap_session_boundary` \| `reap_turn_boundary` \| `reap_reporter_restart` |
| `SubagentStop`, for a `Task` call still open ([§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call)) | `completed` | `subagent_stop_hook` |

The `SubagentStop` row reports `completed` because that hook says a subagent *finished* and says
nothing about whether it errored — its payload schema is unverified
([§ 6.0](#60-conventions-and-how-harness-payloads-are-read)). `close_source` is what tells a consumer
that this close came from the secondary signal rather than from the call's own `PostToolUse`, so the
two are never conflated.


| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `call_id` | ULID | — | no | 26 chars; `"UNMATCHED"` is **not** permitted — see below | `"01K3TA4E5F6G7H8J9K0M1N2P3Q"` |
| `tool_name` | string | — | no | ≤ 64 B | `"Bash"` |
| `outcome` | enum | — | no | `completed` \| `failed` \| `aborted` | `"aborted"` |
| `abort_reason` | enum | — | **yes** | `session_cleared` \| `session_ended` \| `turn_boundary` \| `reporter_restart`; `null` unless `outcome == "aborted"` | `"session_cleared"` |
| `duration_ms` | int | ms | **yes** | `null` if the open was not in the index, or if the computed value is negative | `27411` |
| `close_source` | enum | — | no | `post_tool_use` \| `post_tool_use_failure` \| `reap_session_boundary` \| `reap_turn_boundary` \| `reap_reporter_restart` \| `subagent_stop_hook` | `"reap_session_boundary"` |
| `match` | enum | — | no | `harness_ref` \| `sole_open` \| `lifo_tool_name` \| `agent_id` \| `tombstone_ref` \| `synthesized` \| `reap` | `"reap"` |

`match` records how the close found its call: the five orders in
[§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open) when a harness
close had to be matched, `agent_id` when a bound `SubagentStop` named the call outright
([§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call)), and `reap` when the reporter closed its
own index entry and no matching was involved.

**There is no `is_error` field, deliberately.** An earlier draft carried one, `null` by default and
set "only from an unambiguous harness error indicator" — a field whose value depended on a payload
shape nobody had verified, and which restated in a second place what `outcome` already says. Which
hook closed the call **is** the error indicator, and it is an observation rather than an inspection:
`completed` from `PostToolUse`, `failed` from `PostToolUseFailure`, `aborted` from a reap. One fact,
one home, and no dependency on `tool_response`'s unverified schema.

`duration_ms` is end-wall-clock minus start-wall-clock on one machine; an NTP step mid-call can make
it negative, in which case the reporter sends `null` and counts `negative_duration`.

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
          "abort_reason":"session_cleared","duration_ms":27411,
          "close_source":"reap_session_boundary","match":"reap"} }
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
{ "event_id":"01K3TA6G7H8J9K0M1N2P3Q4R5V","schema_version":1,"kind":"subagent.spawn",
  "event_time":"2026-08-23T14:23:31.004Z","seq":48320,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"call_id":"01K3TA6G7H8J9K0M1N2P3Q4R5T","title":"draft the D1 event schema",
          "title_truncated":false,"subagent_type":"coder"} }
```

### 6.8 `subagent.stop`

**Trigger:** the close of the Task call — from `PostToolUse`, from `PostToolUseFailure`, from a reap,
or from the `SubagentStop` hook ([§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call)). Emitted
immediately after that call's `tool.end`, sharing the `call_id`.

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `call_id` | ULID | — | no | 26 chars | `"01K3TA6G7H8J9K0M1N2P3Q4R5T"` |
| `outcome` | enum | — | no | `completed` \| `failed` \| `aborted` | `"aborted"` |
| `abort_reason` | enum | — | yes | as [§ 6.6](#66-toolend) | `"session_cleared"` |
| `duration_ms` | int | ms | yes | ≥ 0 | `184992` |
| `close_source` | enum | — | no | as [§ 6.6](#66-toolend) | `"reap_session_boundary"` |

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

**Trigger:** the `PreCompact` hook.

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
`context_used_pct` is `null` and `context_sample_stale` is incremented. 300 s = 5× the 60 s sampling
cadence ([§ 6.11](#611-contextsample)), so a session rendering its status line at all has a fresh
sample, and a session whose statusLine integration is not installed reports an honest `null` instead
of a stale number. `context_used_pct_age_s` ships the age so a consumer never has to assume freshness.

**Compaction does not reap.** It rewrites context; it does not kill a running tool process, so a call
open across a compaction still receives its `PostToolUse`. The open count is recorded rather than
acted on. (If a future harness version makes compaction kill in-flight calls, the reap list in
[§ 8.3](#83-the-reap-rules) gains a row — a mechanical change, and the orphan timeout is the backstop
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

| `data` field | Type | Units | Null? | Bounds | Example |
|---|---|---|---|---|---|
| `used_pct` | float | percent | no | 0.0…100.0, one decimal | `73.2` |
| `used_tokens` | int | tokens | yes | 0…10,000,000 | `146401` |
| `total_tokens` | int | tokens | yes | 1…10,000,000 | `200000` |
| `model_label` | string | — | yes | ≤ 48 B, sanitized | `"claude-opus-5"` |
| `sample_reason` | enum | — | no | `cadence` \| `threshold_cross` \| `first_of_session` | `"threshold_cross"` |

`used_pct` is read from the statusLine JSON at `context_window.used_percentage`. That field is
documented and confirmed present — **and documented as nullable early in a session**, before enough
of the context window is known to compute one. If it is absent or null, `used_pct` is computed from
`used_tokens / total_tokens`; if those are absent too, **no event is emitted** and
`payload_key_missing.context_window` is incremented. That is the only suppression in the design
driven by payload shape, it is expected to be non-zero on every seat during the first seconds of a
session, and it is counted precisely because
[§ 3.4](#34-why-identity-never-comes-from-the-environment) says a silent one is how a signal dies
unnoticed.

Every invocation that produces a usable percentage — emitted or suppressed — updates the session's
entry in the sample store, which is what [§ 6.9](#69-compactionstart) reads and what the cadence rule
above compares against.

```json
{ "event_id":"01K3TB0M1N2P3Q4R5T6W7X8Y9Z","schema_version":1,"kind":"context.sample",
  "event_time":"2026-08-23T14:41:00.310Z","seq":48366,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":"a7f2c918-4d0b-4e11-9a3c-7b5e2f81d604",
  "data":{"used_pct":73.2,"used_tokens":146401,"total_tokens":200000,
          "model_label":"claude-opus-5","sample_reason":"threshold_cross"} }
```

### 6.12 `attention.request`

**Trigger:** the `PermissionRequest` hook, or the `Notification` hook — the opening edge of the
*blocked* state `docs/PLAN.md § 7` requires the floor to render. The closing edge is
[§ 6.13](#613-attentionresolved); neither ships without the other, because a state with an entry
event and no exit event is not a state, it is a one-way trapdoor.

| Source | `notification_kind` | How it is decided |
|---|---|---|
| `PermissionRequest` hook | `permission_required` | **observed** — the hook's identity says what it is; no classifier runs |
| `Notification` hook | classified from the message | the regex rules below, which are the fragile path |

| `data` field | Type | Null? | Bounds | Example |
|---|---|---|---|---|
| `request_id` | ULID | no | 26 chars; the join key [§ 6.13](#613-attentionresolved) closes on | `"01K3TB1N2P3Q4R5T6W7X8Y9Z0B"` |
| `source` | enum | no | `permission_request_hook` \| `notification_hook` | `"permission_request_hook"` |
| `notification_kind` | enum | no | `permission_required` \| `input_awaited` \| `other` | `"permission_required"` |
| `call_id` | ULID | yes | the open call the permission is for, when exactly one is open; else `null` | `"01K3TA4E5F6G7H8J9K0M1N2P3Q"` |
| `open_calls` | int | no | 0…64 | `1` |

**At most one attention request is open per session.** The harness may well fire both
`PermissionRequest` and `Notification` for one prompt; a second request while one is open is dropped
and counted (`attention_request_duplicate`) rather than minting a second *blocked* the floor would
have to reconcile. The counter is also the discriminating signal for whether the two hooks overlap on
this build, which nobody has measured.

**The message text never transits** — not truncated, not sanitized, not at all. Only the classified
enum does.

**The `Notification` classifier is knowingly fragile, and is built to fail visibly.** It matches the
harness's English notification wording, which is undocumented and will be reworded. Three
protections, straight from [§ 3.4](#34-why-identity-never-comes-from-the-environment): it never gates
emission (an unmatched message emits `other`, so *blocked* degrades to "attention requested, kind
unknown", never to silence); all three branch counts ride the heartbeat; and the predicate-constant
alarm ([§ 9.4](#94-the-predicate-constant-alarm)) fires when the distribution collapses. The rules —
`/permission|approve|allow|grant/i` → `permission_required`; `/waiting|idle|input/i` →
`input_awaited`; else `other` — are the mutable part; the visibility is not. **The
`PermissionRequest` path runs no classifier at all**, which is why it is preferred: the permission
case, the one that actually blocks an agent, is now an observation.

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
| the `PermissionDenied` hook | `denied` | `permission_denied_hook` |
| the request's `call_id` closing `completed` or `failed` — the tool ran, so permission was given | `granted` | `call_close` |
| where the request carries **no** `call_id`: the next `tool.end` in that session with any outcome other than `aborted` — the agent is running tools again, so it is no longer waiting on a human | `granted` | `call_close` |
| a `UserPromptSubmit` — a human typing is a human present | `human_input` | `user_prompt_submit` |
| that session's `SessionEnd`, or any reap of it | `session_ended` | `session_end` |
| **60 min** with none of the above | `timeout` | `timeout` |

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

**The predicate that watches this.** `attention_resolved_by_hook` counts observed resolutions
(`true`) against timeouts (`false`). If the permission hooks are renamed, removed or stop firing, the
branch goes constant-`false` and the predicate-constant alarm ([§ 9.4](#94-the-predicate-constant-alarm))
says so — the exact instrument the 30-day outage in
[§ 3.4](#34-why-identity-never-comes-from-the-environment) lacked.

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
| `open_calls` | int | — | no | 0…64 | `1` |
| `open_sessions` | int | — | no | 0…16 | `1` |
| `open_attention` | int | — | no | 0…16, requests awaiting an `attention.resolved` | `0` |
| `degraded` | array\<enum\> | — | no | 0…16 elements, [§ 9.3](#93-degradation-counters) | `["rejected_batches"]` |
| `counters` | object | — | no | ≤ 1.5 KiB serialized, all monotonic since flusher start | see below |
| `predicates` | object | — | no | ≤ 512 B, `{name:{true:int,false:int}}` | see below |
| `selftest` | object | — | no | ≤ 256 B, `{name:"pass"\|"fail"}` | see below |
| `config_fingerprint` | string | — | no | 16 hex chars = SHA-256 of `install_id\|seat_id\|ingest_url`, **token excluded** | `"9f2c41a7be03d518"` |

`config_fingerprint` deliberately excludes the token: a fingerprint that covered the secret would let
anyone holding the event stream confirm a guessed token by comparing hashes. It exists so an operator
can tell "this seat was reconfigured" from "this seat is a different seat".

The `counters` object carries the always-present delivery counters below plus **every** counter in
[§ 9.3](#93-degradation-counters) that is non-zero — which is what keeps it inside 1.5 KiB, and
therefore the whole event inside the 3 KiB `data` cap, on a healthy seat where most of them are zero; the flusher folds them from the counter sink
([§ 11.1](#111-layout)) rather than computing them, because most of them are incremented in hook and
statusLine processes it never shares memory with.

```json
{ "event_id":"01K3TB2P3Q4R5T6W7X8Y9Z0A1B","schema_version":1,"kind":"reporter.heartbeat",
  "event_time":"2026-08-23T14:45:00.000Z","seq":48374,
  "install_id":"aimla","seat_id":"aimla-pm","session_id":null,
  "data":{
    "uptime_s":86213,"spool_bytes":18422,"spool_files":2,"spool_lag_events":0,
    "oldest_unsent_age_s":null,"last_hook_at":"2026-08-23T14:44:12.007Z",
    "open_calls":1,"open_sessions":1,"open_attention":0,"degraded":[],
    "counters":{"events_emitted":48374,"events_sent":48373,"spool_dropped_events":0,
                "spool_corrupt_lines":0,"batches_ok":1611,"batches_retried":4,
                "batches_rejected":0,"events_rejected_dropped":0,"statusline_suppressed":51882,
                "sanitizer_redactions":37,"sanitizer_truncations":12,
                "hook_name_mismatch":0,"negative_duration":0,"tombstone_late_close":1,
                "enum_value_unknown.session.start.source":0,"agent_bind_unresolved":0,
                "reap_noop_second_signal":11,"context_sample_stale":0,
                "payload_key_missing.session_id":0,"wrapped_statusline_failures":0},
    "predicates":{"notification_kind_permission":{"true":6,"false":19},
                  "descriptor_allowlisted":{"true":2841,"false":93},
                  "session_boundary_detected":{"true":11,"false":11},
                  "agent_scope_subagent":{"true":412,"false":2522},
                  "attention_resolved_by_hook":{"true":25,"false":0}},
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
| 2 | Env-expansion **defaults** | `\$\{(\w+):[-=?+]([^}]*)\}` | `${$1:-‹redacted›}` |
| 3 | Known-prefix credentials | `\b(gh[pousr]_\|github_pat_\|sk-\|sk_live_\|sk_test_\|xox[abposr]-\|AKIA\|ASIA\|glpat-\|AIza\|mzn_)[A-Za-z0-9_\-]{8,}` | `‹redacted:token›` |
| 4 | Credential **keyword** + its value, separated by `=`, `:` **or whitespace** | `(?i)(?<![\w-])((?:-{1,2}\|[A-Za-z0-9]{0,24}[_-])?(?:pass(?:word)?\|secret\|token\|api[_-]?key\|auth\|bearer\|credential))(?![A-Za-z])(\s*[:=]\s*\|\s+)(\S+)` | `$1$2‹redacted›` — keyword and separator kept verbatim |
| 5 | Credential **flags**, glued or separated: `--user` `--password` `--token` `--secret`, and `-u` / `-p` (case-insensitively, so `-P` too) | `(?i)(?<![\w-])(--(?:user\|password\|token\|secret)(\s*[:=]\s*\|\s+)\|-[up](\s*[:=]?\s*))(\S+)` | flag + separator verbatim, then `‹redacted›` |
| 6 | Home and long paths | `/home/<u>/`, `/Users/<u>/`, `C:\Users\<u>\` → `~/`; then a **path token** (one starting at a whitespace or quote boundary with `/`, `~/`, `./` or `X:\`) with > 4 segments keeps segment 1 + `…` + the last 2 | `~/…/design/EVENT-SCHEMA.md` |
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
| 7 | `Bash`, a 600-byte command whose bytes 195–205 are the 2-byte `é` | 14 | exactly ≤ 200 bytes, valid UTF-8, ends `…`, `descriptor_truncated == true`, no split character |
| 8 | `mcp__vault__read`, `{"password":"hunter2","path":"/prod/db"}` | *(none — layer 1 refuses the tool)* | `descriptor == null`, `tool_name == "mcp__vault__read"`, and the string `hunter2` appears nowhere in the emitted event |
| 9 | `Bash`, `deploy --password hunter2 --host db1` | 4 | `Bash: deploy --password ‹redacted› --host db1` |
| 10 | `Bash`, `curl -u admin:s3cr3t https://api.example.org/v1/ping` | 5 | `Bash: curl -u ‹redacted› https://api.example.org/v1/ping` |
| 11 | `Bash`, `mysql -pS3cr3tP@ss -h db1 mezz` | 5 (glued, empty separator; rule 8's `@` then overlaps the lock) | `Bash: mysql -p‹redacted› -h db1 mezz` |

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
| `open` | `call_id`, `session_id`, `tool_name`, `harness_call_ref`, `started_at`, `is_task`, `agent_id` (null until bound) |
| `close` | `call_id`, `closed_at`, `close_source` |
| `bind` | `call_id`, `agent_id` — written by `SubagentStart` ([§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call)) |
| `tombstone` | `call_id`, `closed_at` — a reaped entry, retained for late matching |
| `attention_open` | `request_id`, `session_id`, `opened_at`, `call_id` — an open attention request ([§ 6.12](#612-attentionrequest)) |
| `attention_close` | `request_id`, `closed_at`, `resolution` |

The last two are in this journal for the same reason the calls are: an attention request is opened by
one hook process and resolved by a different one, the flusher needs the open set to fire the 60-minute
ceiling and to fill `open_attention` on the heartbeat, and none of those three can read each other's
memory. One index, one concurrency discipline.

The folded index holds at most **64 open entries** and **64 tombstones**; entry 65 in either set
evicts the oldest and increments `open_call_index_overflow` (64 = far above any observed concurrent
call count; a seat exceeding it has a harness anomaly worth surfacing).

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
| `SessionStart` with `source == "clear"` | every open call of `previous_session_id` | `session_cleared` | `reap_session_boundary` |
| `Stop` | every call still open **in that session** | `turn_boundary` | `reap_turn_boundary` |
| flusher start finding index entries older than its own start time | every such call | `reporter_restart` | `reap_reporter_restart` |

Each reap emits, in order: `tool.end(outcome:"aborted", …)` per call — plus a `subagent.stop` for any
of them with `is_task: true` — then the triggering event, whose `aborted_call_ids` names them. Each
reaped entry becomes a tombstone ([§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)).
A reap that ends a session also closes any attention request open in it, emitting
`attention.resolved(resolution: "session_ended")` after the boundary event — a *blocked* desk whose
session has ended is not blocked, and `D2-MUST` #5 needs the exit edge to say so.

A `SessionStart(source == "clear")` whose `previous_session_id` is `null` names no session, so it
reaps nothing and increments `clear_without_previous_session_id`. That is deliberately *not* widened
into "reap every other session" — the whole point of keying on `session_id` is that a seat may have
another live session — and it is counted rather than ignored because if it ever becomes the common
case, the `SessionEnd(clear)` signal is doing all the work alone and
[§ 8.4](#84-detecting-a-clear-with-two-independent-signals)'s pair has quietly become a single.

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

A `/clear` announces itself twice — `SessionEnd(reason: "clear")` and
`SessionStart(source: "clear")` — and the order in which those two hooks fire is not documented. So
both reap, either suffices, the reap is idempotent, and both are counted:

| Signal | Counter | If it stops firing |
|---|---|---|
| `SessionEnd(reason == "clear")` | `predicates.session_boundary_detected`, `true` branch | the `SessionStart` signal still reaps, so nothing visibly breaks — the branches diverge and the divergence criterion in [§ 9.4](#94-the-predicate-constant-alarm) is what says so |
| `SessionStart(source == "clear")` | `predicates.session_boundary_detected`, `false` branch | the `SessionEnd` signal still reaps; same divergence criterion |

`reap_noop_second_signal` counts the second of the pair finding nothing left to reap, which in healthy
operation happens on **every** `/clear`: it is the positive evidence that both signals are alive.
The two branch counts **diverging** is the discriminating self-test — in healthy operation a `/clear`
trips both within a second, so a large gap means one of them has stopped working while the pipeline
still appears to function. That is the shape of the 30-day outage in
[§ 3.4](#34-why-identity-never-comes-from-the-environment), instrumented so it takes minutes rather
than a month to see.

Note what is *no longer* a `/clear` signal: a hook arriving with an unfamiliar `session_id`. It was
one in an earlier draft, and [§ 8.3](#83-the-reap-rules) explains what two concurrent sessions did to
it.

### 8.5 Subagent identity — binding `agent_id` to a call

`agent_id` and `agent_type` are documented **common input fields present inside subagents**, and a
`SubagentStart` hook exists. That is the join the subagent lifecycle is built on, and it replaces an
earlier draft's flat assertion that `SubagentStop` "carries no field identifying which one" — an
assertion made with no cite, which designed a loss into every parallel dispatch.

**The binding, at spawn.** `SubagentStart` fires inside the new subagent carrying its `agent_id`. The
reporter writes a `bind` record joining that `agent_id` to an open `Task` call, choosing by the first
of these that applies:

| Situation | Binding | Counter |
|---|---|---|
| the payload references the parent tool call (`tool_use_id` / `parent_tool_use_id`) and it matches an open `Task` call's `harness_call_ref` | exact | `agent_bind_ref` |
| exactly one open `Task` call in that session has no `agent_id` yet | that call | `agent_bind_sole_unbound` |
| two or more unbound open `Task` calls | **none** — no guess is written | `agent_bind_ambiguous` |
| no `SubagentStart` payload at all (the hook did not fire, or carried no `agent_id`) | none | `payload_key_missing.agent_id` |

Whether the first row is reachable is **unverified** — nobody has read a real `SubagentStart` payload
— and the design does not depend on it: the second row alone binds correctly for every dispatch that
is not simultaneous, and the counters say which row is carrying the fleet. That is the verification
task, discharged by the first week of live counters rather than by a guess written into the design.

**The use, at stop.** `SubagentStop` closes a `Task` call only when it can name one:

- payload carries an `agent_id` **bound** to an open call → close that call, `close_source:
  "subagent_stop_hook"`, `match: "agent_id"`. Parallel dispatches are handled correctly, which is the
  whole point of the binding;
- no `agent_id` (or an unbound one) and **exactly one** open `is_task` call → close it, `match:
  "sole_open"`;
- otherwise → emit nothing, increment `subagent_stop_unmatched`.

The Task call's own `PostToolUse` / `PostToolUseFailure` remains the primary close; `SubagentStop` is
a second, independent signal for the same transition, in the same spirit as the two `/clear` signals.
Using an unidentifiable signal to close an arbitrary one of several candidates would be a guess with
no observable, which is the failure mode this whole section exists to remove.

**What the binding buys downstream.** Every hook firing inside a bound subagent carries that
`agent_id`, so the reporter stamps the parent Task call's `call_id` onto those events as
`parent_call_id` ([§ 6.5](#65-toolstart)). The floor can therefore attribute an intern's tool calls to
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
| `turn.end` whose calls all closed `completed` / `failed` | an ordinary finish; `failed` never blocks *idle* ([§ 6.4](#64-turnend)) |
| a seat with any open call | the seat is **working**, regardless of turn state |

### 8.7 Worked flow — a `/clear` during a subagent's `Bash` call

The acceptance-test scenario ([AT-1](#at-1-kill-vs-complete-the-headline-test)), event by event.
`T` is the seat clock. This trace shows `SessionEnd` arriving before `SessionStart`; the reverse order
is equally valid and is covered below.

| # | Time | Kind | Key data |
|---|---|---|---|
| 1 | `T+00.0s` | `tool.start` | `call_id: A`, `tool_name: "Task"`, `descriptor: "Task: probe the ingest"` |
| 2 | `T+00.0s` | `subagent.spawn` | `call_id: A`, `title: "probe the ingest"`, `subagent_type: "coder"` |
| — | `T+00.4s` | *(`SubagentStart`: binds `agent_id` → call `A`; no event)* | |
| 3 | `T+03.2s` | `tool.start` | `call_id: B`, `tool_name: "Bash"`, `descriptor: "Bash: sleep 120"`, `agent_scope: "subagent"`, `parent_call_id: A`, `open_calls_before: 1` |
| — | `T+18.6s` | *(operator types `/clear`; the harness SIGKILLs call `B`; **no `PostToolUse` and no `PostToolUseFailure` ever fire**)* | |
| 4 | `T+18.7s` | `tool.end` | `call_id: B`, `outcome: "aborted"`, `abort_reason: "session_cleared"`, `close_source: "reap_session_boundary"`, `match: "reap"` |
| 5 | `T+18.7s` | `tool.end` | `call_id: A`, `outcome: "aborted"`, `abort_reason: "session_cleared"`, `close_source: "reap_session_boundary"` |
| 6 | `T+18.7s` | `subagent.stop` | `call_id: A`, `outcome: "aborted"`, `abort_reason: "session_cleared"` |
| 7 | `T+18.7s` | `turn.end` | `end_reason: "session_cleared"`, `open_calls_at_end: 2`, `aborted_call_ids: [B, A]` |
| 8 | `T+18.7s` | `session.end` | `end_reason: "clear"`, `aborted_calls: 2` |
| 9 | `T+18.8s` | `session.start` | `source: "clear"`, `previous_session_id: <old>` — its reap finds nothing open and increments `reap_noop_second_signal` |

Events 4–8 are all produced by the **single `SessionEnd` hook invocation** at `T+18.7s`: reap first,
then the boundary events, then the trigger's own event — one process, one spool append per event, in
that order. Both calls are tombstoned for 15 min, so a `PostToolUse` that somehow arrived late would
close call `B` under its own `call_id` with `match: "tombstone_ref"` rather than inventing a new one.

**If `SessionStart` arrives first instead**, it reaps `previous_session_id`'s calls with the identical
`abort_reason: "session_cleared"`, emits events 4–8 itself, and the later `SessionEnd` is the one that
finds nothing and counts `reap_noop_second_signal`. The wire is the same either way; that is what
"two independent signals, either suffices" buys, and AT-1 accepts both orders while asserting that
exactly one reap happened.

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
| `spool_corrupt_lines` | unparseable spool lines quarantined | seat badge `lossy` |
| `events_rejected_dropped` | events lost with a permanently-rejected batch — incremented by that batch's event count at quarantine time | seat badge `lossy`; this is the counter that makes `§ 0` item 9's promise true for the rejection path |
| `oversize_event_dropped` | a single event over the 4 KiB cap, undeliverable, quarantined | seat badge `lossy` |
| `batches_rejected` | permanent-status rejections | seat badge `degraded`; the last status and error code are shown |
| `hook_name_mismatch` | `argv[2]` ≠ `hook_event_name` | `degraded`; the harness contract moved |
| `payload_key_missing.<key>` | an expected harness key was absent | `degraded` when > 0 for a key marked required in [§ 6](#6-event-kinds) |
| `enum_value_unknown.<field>` | a closed-enum field carried a value this reporter does not know, coerced per [§ 6.0](#60-conventions-and-how-harness-payloads-are-read) rule 4 | informational, rendered `reporter_behind` — the harness has added a member and this document owes an edit |
| `invalid_tool_name` | `tool_name` failed its pattern and was sent as `INVALID_TOOL_NAME` | `degraded` |
| `open_call_index_overflow` | > 64 concurrent open calls or tombstones | `degraded` |
| `index_fold_truncated` | the index journal tail exceeded 8 MiB and history was skipped | `degraded` |
| `flusher_lost_ownership` | a flusher found `state.json` owned by another and exited | informational; > 1/day means the lock is being lost, not just raced |
| `state_reset` | `state.json` was unreadable; a new epoch was minted and the spool re-sent from its oldest bucket ([§ 11.4](#114-corruption-the-torn-last-line-and-a-lost-statejson)) | informational, rendered `epoch_reset`; **not** `lossy`, because nothing was discarded |
| `subagent_stop_unmatched` | `SubagentStop` that could name no call | informational; expected ~0 once the `agent_id` binding works, so a rising share is the signal that it does not |
| `agent_bind_ref` / `agent_bind_sole_unbound` | how a `SubagentStart` bound its `agent_id` — by an exact parent reference or by being the only unbound Task call ([§ 8.5](#85-subagent-identity--binding-agent_id-to-a-call)) | informational, and the measurement that says which binding rule is carrying the fleet |
| `agent_bind_ambiguous` / `agent_bind_unresolved` | two unbound Task calls at `SubagentStart`; a subagent hook whose parent could not be resolved | informational; `parent_call_id` is `null` for those events |
| `attention_request_duplicate` | a second attention request while one was open | informational; also the measurement of whether `PermissionRequest` and `Notification` overlap |
| `context_sample_stale` | a compaction event found no statusLine sample newer than 300 s | informational; > 0 on every seat without the statusLine integration installed |
| `clear_without_previous_session_id` | a `SessionStart(source=clear)` that named no previous session, so its reap had nothing to key on ([§ 8.3](#83-the-reap-rules)) | informational; a rising share means the `SessionEnd` signal is carrying `/clear` alone |
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

`data.degraded` carries the enum names of the conditions currently active, so a consumer never has to
re-derive the badge from raw counters.

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

| Predicate | Branches | Volume | Alarm criterion | What it means when it fires |
|---|---|---|---|---|
| `descriptor_allowlisted` | tool on the allowlist / not | ~1,000–3,000/day | **0 % or 100 % across ≥ 500 evaluations in a rolling 24 h** | constant-false: the allowlist no longer matches any tool name the harness sends |
| `agent_scope_subagent` | `agent_id` present / absent ([§ 6.5](#65-toolstart)) | ~1,000–3,000/day | same 500 / 24 h rule | constant-true: the harness now sends `agent_id` everywhere; constant-false: it stopped sending it |
| `session_boundary_detected` | `SessionEnd(clear)` / `SessionStart(source=clear)` ([§ 8.4](#84-detecting-a-clear-with-two-independent-signals)) | ~10–80/day | **branch divergence**: `\|true − false\| > 5` in a rolling 24 h in which at least one `/clear` happened | one of the two `/clear` signals has died; the other is still reaping, so nothing else looks broken |
| `notification_kind_permission` | classified `permission_required` / not | 0–50/day | **0 % or 100 % across ≥ 50 evaluations in a rolling 7 days** | the `Notification` wording changed under the classifier |
| `attention_resolved_by_hook` | resolved by an observed edge / by the 60-minute timeout ([§ 6.13](#613-attentionresolved)) | 0–50/day | **any** `false` branch in a rolling 24 h is surfaced; constant-false over ≥ 10 resolutions alarms | the permission hooks stopped firing and every *blocked* is now ending on a timer |

**On the 500 (and the 50, and the 5).** A threshold must exceed the longest legitimate run of one
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
> days, but a **quiet** seat — one emitting little more than its 1,440 daily heartbeats, ~0.6 MB/day —
> would take over 50 days to fill the same spool, and its oldest event would fall out of a 10-day
> dedup window while still queued. Residency has to be bounded by age, and the age bound is the one
> the coupling is stated against.

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
bucket so the same line is never folded twice. A bucket older than the current UTC hour has no living
writer — a hook lives ≤ 250 ms (P-5), four orders of magnitude under an hour — so once such a bucket
is fully folded the flusher deletes it. Counters are therefore at most one flush interval (10 s) stale
in a heartbeat, and no counter is ever lost to a race.

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
| An entire bucket file unreadable | quarantine the filename, skip it, continue | `spool_corrupt_lines` += unknown; badge `lossy` |
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
4. `Authorization` present and resolves to an active token → else `401 unauthenticated`.
5. Rate limits ([§ 12.3](#123-rate-limits)) → else `429`.
6. `schema_version` present on the batch, an integer, and in the accepted set → else
   `400 unsupported_schema_version`.
7. Batch `install_id`/`seat_id` equal the token's binding → else `403 identity_mismatch`.
8. `events` is a non-empty array of ≤ 200 elements → else `422 invalid_batch`.
9. Every event validates: common fields present and in-bounds; per-event `install_id`/`seat_id` and
   `schema_version` equal the batch's; `kind` a string matching `^[a-z]+\.[a-z_]+$`; `data` an object
   ≤ 3 KiB → else `422 invalid_event`.
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
| step 4 (`401`) | the presented token's **hash prefix** only | an operator-visible auth-failure count; never the claimed slug |
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
| Failed authentications | **source IP** | **60 / hour** | see below | `429` and an operator-visible alert |

**On the failed-auth limit, and what it is honestly for.** It bounds log volume, CPU spent on hash
comparisons, and the noise floor an operator reads — nothing more. **It is not a defence against
guessing, and an earlier draft's "10 per presented token string" could not have been one**: a
brute-forcer sends a *different* string every attempt, so a counter keyed on the string never
accumulates past 1 and the limit never fires. The actual defence against guessing a seat token is its
**256 bits of entropy** ([§ 3.3](#33-authentication-and-the-identity-binding-rule)), which no rate
limit meaningfully improves. Keying on source IP is what makes the limit describe something real, at
the cost that seats behind one NAT share a budget — 60/hour is far above the zero failures a healthy
fleet produces, and a rotation race costs one or two.

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
| 1 | **Idle may be minted only from `turn.end` with `end_reason == "stop_hook"` and `aborted_call_ids == []`.** Every other turn ending yields `unknown`, never `idle`. A `failed` tool call is a closed call and does not block idle ([§ 6.4](#64-turnend)). |
| 2 | **`stale` (300 s) and `offline` (900 s) are visibly degraded rendered states, never `idle`,** and a seat with `degraded` non-empty renders its badge. |
| 3 | **Per-event dedup on `(install_id, seat_id, event_id)` with a 10-day window,** and the window must exceed the spool's 8-day residency cap ([§ 10.3](#103-idempotency-and-the-dedup-window)). |
| 4 | **State transitions are ordered by `(event_time, seq)`, never by arrival order,** `received_at` is the only clock used for liveness, retention and cross-seat comparison, and a repeated `(seq_epoch, seq)` with differing `event_id`s is counted as `seq_collision` rather than silently applied. |
| 5 | **Blocked is minted only from `attention.request` and cleared only by its matching `attention.resolved`** (joined on `request_id`), by the session ending, or by the seat leaving live state — and never rendered for longer than the 60-minute ceiling without a resolution ([§ 6.13](#613-attentionresolved)). |

### 12.7 Server-side counters

The reporter's counters ride its heartbeat and are listed in [§ 9.3](#93-degradation-counters). The
ingest keeps its own, and they are collected here rather than left scattered through the sections that
introduce them, because an implementer building the server needs the whole set in one place — and
because a counter nobody can find is a counter nobody reads.

| Counter | Incremented when | Consequence |
|---|---|---|
| `accepted` / `duplicates` | per batch, in the `202` body | the reporter's convergence signal ([§ 10.3](#103-idempotency-and-the-dedup-window)) |
| `ignored_unknown_kinds` | an event's `kind` is not one this ingest knows | seat renders `reporter_ahead`, informational — the additive-change rule at work ([§ 5](#5-compatibility--what-this-document-owes-the-policy)) |
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
| `auth_failed_by_ip` | a token that resolves to nothing | the 60/h limit ([§ 12.3](#123-rate-limits)); log-volume control, not a guessing defence |
| `revoked_token_presented` | a token that resolves to a revoked row | **operator alert**: a seat is still holding a dead credential and only the server can see it |
| `clock_skew_ms` | *(a gauge, not a counter)* per batch, `received_at − sent_at` | seat badge `clock_skew` past ±120 s ([§ 10.1](#101-two-clocks-and-which-is-authoritative-for-what)) |

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

### AT-2 sanitizer red fixtures

- **Build:** the 11 fixtures of [§ 7.5](#75-red-fixtures--required-tests) plus the two whole-event
  assertions, as unit tests, run on Linux **and** Windows. Each fixture asserts the exact output
  string, not a substring.
- **RED:** replace the sanitizer with the identity function → all 11 fail. Then restore it and remove
  only the allowlist → fixture 8 fails alone (proving the layers are independently load-bearing).
  Then restore the allowlist and revert rule 5 to the pre-extension rule 4 → fixtures 9, 10 and 11
  fail alone, and the credential in each appears verbatim in the output (proving the credential-on-
  argv extension is load-bearing and not decoration).
- **GREEN:** all 11 exact-match; no planted credential appears in any serialized event.
- **The consistency check the table's trace column exists for:** a test asserts that the rule
  indices listed in each fixture's "Rules that fire" column are exactly the rules the implementation
  reports firing for that input (the sanitizer returns the fired-rule list under test). A fixture whose
  documented trace and actual trace disagree fails **even if its output string matches** — that
  disagreement is the drift between the two tables that produced two broken fixtures in the draft.

### AT-3 the reporter never blocks the seat

- **Build:** a harness that invokes `hook PreToolUse` 200 times with a realistic payload, measuring
  wall time per invocation, on both platforms.
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
  events is far under one hour's allowance). Run the same outage with the ingest's event limit set to
  **200/hour** and the spool pre-filled with **2,000** events — the same 10:1 shape as a full 32 MiB
  spool against the real 20,000/hour limit, at 1/100 of the wall time.
- **GREEN (B):** the drain takes ~10× the limiter window (≈ the 3.4 h the real numbers give,
  scaled); `429 rate_limited` responses are observed and their `retry_after_s` is honoured;
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
  evaluations in 24 h. **(b) low-volume:** a seat whose `notification_kind` classifier is forced to
  return `other` always, over ≥ 50 evaluations of a 7-day window — fed from a seeded heartbeat series
  rather than by waiting a week, since the check is over counters and not over wall time.
- **GREEN:** each case fires `predicate_constant` for its own predicate at its own criterion, and the
  seat surfaces it. **Negative control:** a seat with a mixed distribution over the same volume does
  **not** fire, in both cases.
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

### AT-20 blocked has an exit

- **Build:** a seat driven into a permission prompt (an operation requiring approval), instrumented at
  the ingest.
- **GREEN — granted:** an `attention.request` (`source: "permission_request_hook"`), then, after the
  human approves and the tool runs, an `attention.resolved` with `resolution: "granted"`,
  `resolution_source: "call_close"`, and a plausible `waited_ms`; the derived state enters *blocked*
  and leaves it. **Denied:** the same with `resolution: "denied"` from `permission_denied_hook`.
  **Human input:** dismiss the prompt and type a new instruction → `resolution: "human_input"`.
- **GREEN — the ceiling:** with the resolution hooks stubbed out, the request is resolved at 60 min
  with `resolution: "timeout"`, `attention_resolved_by_hook` shows a `false` branch, and the seat is
  no longer rendered *blocked*.
- **RED:** remove `attention.resolved` entirely (the design this replaced) → the seat enters *blocked*
  and never leaves it; every subsequent turn renders under a stale blocked badge, and no counter
  anywhere marks the state as unresolved. A state with an entry event and no exit event is the defect.
- **Discriminating control:** a seat that is never blocked emits neither kind and never renders
  *blocked*, so the test measures the pair and not the renderer's default.

---

## 14. Every number, and where it comes from

One table, so a reviewer can audit the arithmetic without reading the prose, and so a future change
can find every number that moves with it. **Measured** = observed in this fleet or documented by the
harness. **Derived** = computed from another number here. **Chosen** = a judgement call, with the
reasoning and, where it applies, what would re-derive it.

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
| Context-sample staleness bound | 300 s | Derived — 5× the 60 s sampling cadence; past it `compaction.start` reports `null` rather than a stale percentage | [§ 6.9](#69-compactionstart) |
| Session `inferred_silence` | 90 min | Derived — 1.5× the 60 min `Task` orphan ceiling, the longest legitimate silence inside a live session. Cheap to be wrong now that an early close is reversible (`session_reopened` re-derives it) | [§ 6.2](#62-sessionend) |
| Compaction close timeout | 10 min | Derived — ~10× a typical one-minute compaction | [§ 6.10](#610-compactionend) |
| Attention resolution ceiling | 60 min | Chosen — reuses the `Task` orphan ceiling so a seat cannot render *blocked* after every call it was blocked on has been reaped; erring long is the safe direction | [§ 6.13](#613-attentionresolved) |
| Heartbeat interval | 60 s | Chosen — 1,440/day ≈ 14 % of the ceiling volume for continuous liveness | [§ 9.1](#91-the-cadence-and-the-alarm) |
| Flush interval | 10 s | Chosen — under human "live" perception; caps request rate at 6/min | [§ 11.5](#115-retry-and-backoff) |
| Flush event trigger | 50 events | Derived — ~25 KB, a batch worth a WAN round-trip | [§ 11.5](#115-retry-and-backoff) |
| Seat `stale` | 300 s | Derived — ~4× the 70 s worst-case freshness of a healthy seat. It **does** fire on an outage longer than ~110 s, correctly; what the 120 s backoff cap bounds is how long a seat stays stale *after* recovery | [§ 9.1](#91-the-cadence-and-the-alarm) |
| Seat `offline` | 900 s | Derived — 3× `stale`, so `stale` is a distinct investigable state | [§ 9.1](#91-the-cadence-and-the-alarm) |
| Predicate-constant criteria | 500 evaluations / 24 h (high-volume predicates); 50 / 7 days, or branch divergence > 5, for the low-volume ones | **Chosen provisionally** — ~a working day of evidence for a predicate evaluated thousands of times a day, scaled down for the three evaluated tens of times a day, because a threshold above a predicate's own rate is an alarm that can never fire. Re-picked from the first week's per-predicate counts | [§ 9.4](#94-the-predicate-constant-alarm) |
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
**unverified harness or host facts** — the `Bash` timeout ceiling behind the 15-minute orphan window,
and nginx's body-size default behind the 256 KiB batch cap — and both are listed with everything else
still to verify in [§ 6.0](#60-conventions-and-how-harness-payloads-are-read).

---

## 15. Decisions taken, revisable at review

This document contains no placeholders and no deferred decisions. Where a call was genuinely
contestable it was **made**, and it is listed here with the alternative and the cost of being wrong, so review can
reverse it deliberately rather than discover it later.

Rows carrying a dated **Amended** or **Superseded** note were revised on 2026-08-23 after the first
adversarial review; the original decision is left standing in the row so the change is legible rather
than erased.

| # | Decision | Alternative considered | Why this one | Cost if wrong |
|---|---|---|---|---|
| 1 | **Identity repeats on every event**, with server-enforced equality against the batch header and the token binding | batch-level only, stamped onto events at ingest | an event is the durable, forwardable, quotable unit; ~60 B (12 %) buys unambiguity, and enforced equality makes drift impossible | ~12 % wire overhead. Reversible in one direction only: removing the fields later is a **schema bump** under the policy. **Amended 2026-08-23:** `schema_version` now rides every event on the identical argument — see row 19 |
| 2 | **`Stop` reaps every open call in its session** as aborted | wait for the orphan timeout and let the server infer | a false idle at a turn boundary is the exact defect this design exists to prevent; waiting 15–60 min to notice defeats it | over-eager aborts on any legitimate call outstanding at `Stop`. **Amended 2026-08-23:** the late-completion override this row leans on now has a path — reaped entries are **tombstoned** for 15 min so a late close rejoins its original `call_id` ([§ 8.2](#82-the-call-index-an-append-only-journal-and-matching-a-close-to-its-open)). Before that amendment the late close was synthesized under a fresh id and `late_completion` could never leave zero, so the instrument bounding this decision could not report |
| 3 | **`Task` emits both `tool.start` and `subagent.spawn`** sharing a `call_id` | one `subagent.spawn` and no `tool.start` for `Task` | a special case in the call ledger would live in the *one* path the kill-vs-complete requirement is actually about | ~120 B per dispatch, tens of times a day |
| 4 | **Batches are atomic** — one bad event rejects 200 | per-event partial ingest with a report | a partially-ingested batch under a success status destroys the reporter's only other copy of the data | one malformed event costs ≤ 199 neighbours, bounded by the poison-pill rule. **Amended 2026-08-23:** this is precisely why an unknown `kind` or enum value must never be a validation failure (rows 20, and [§ 12.4](#124-batches-are-atomic)) |
| 5 | ~~A new event `kind` is compatible; unknown kinds are ignored and counted — **minted in D1** with a pointer saying it might belong in the policy~~ | reject the batch on an unknown kind | — | **Superseded 2026-08-23.** The rule was policy, not mechanic: it governs what any producer and any receiver may do without a bump. It now lives in [`docs/VERSIONING.md § Wire compatibility` rule 7](../VERSIONING.md#the-rules), extended to cover new closed-enum members too, and [§ 5](#5-compatibility--what-this-document-owes-the-policy) carries only the mechanics with a cite. One rule, one home |
| 6 | **`attention.request` exists**, classified client-side from `Notification` | omit it; let D2 derive *blocked* from its status tiers | `docs/PLAN.md § 7` requires *blocked* as a rendered state and no other hook supplies it | a knowingly-fragile classifier, instrumented rather than trusted. **Amended 2026-08-23:** *blocked* is now a **pair** — `attention.request` / `attention.resolved` ([§ 6.13](#613-attentionresolved)) — sourced from the `PermissionRequest` and `PermissionDenied` hooks, which exist. The original had an entry event and no exit event: nothing un-minted *blocked*, so a seat entered it once and rendered blocked forever |
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
| 22 | **`SubagentStart` binds `agent_id` to the open `Task` call; `SubagentStop` closes the call it names** | the earlier narrow rule: close only when exactly one Task call is open, otherwise emit nothing | that rule designed a permanent loss into every parallel dispatch, on a flat assertion (with no cite) that `SubagentStop` identifies nothing. `agent_id` and `agent_type` are documented common fields inside subagents and `SubagentStart` exists | if `SubagentStop` turns out not to carry `agent_id`, the sole-open rule is still there as the fallback and `subagent_stop_unmatched` measures how often it is needed. Nothing regresses |
| 23 | **`parent_call_id` on `tool.start`; the harness's `agent_id` never transits** | put `agent_id` on the wire and let the server do the join | the reporter already holds the binding, so resolving it locally sends a `call_id` the consumer already knows instead of a second opaque identifier — less wire surface, less PII-adjacent data, and an immediately usable join for the drill-down's interns | ~30 B on the highest-volume kind, and `null` where the binding failed (counted as `agent_bind_unresolved`) rather than a guess |
| 24 | **`compaction.end` carries no post-compaction percentage; `compaction.start`'s comes from the statusLine sample store with an age** | keep both `context_used_pct` fields as specified, or delete both | context percentages exist only in the statusLine payload, so as originally specified both fields were always `null`. The *pre*-compaction number is the interesting one and is available from the sample store; the *post* number is documented as unavailable (`current_usage` is null after a `/compact` until the next API call) and arrives seconds later as an ordinary `context.sample` | one cross-process read in the hook path, bounded by a 300 s staleness rule and reported with `context_used_pct_age_s` |
| 25 | **Every refusal is attributed to the token's binding; pre-auth refusals are attributed to nothing** | attribute to the batch's claimed `install_id`/`seat_id`, which the earlier draft's worked example did | the schema-version check runs *before* the identity-equality check, so any holder of any valid token could render a colleague's desk degraded by posting a bogus version naming their seat | a `415`/`413`/`400 malformed_body` cannot degrade any seat's badge, because no identity is established yet — it is counted globally and surfaced locally by the reporter instead |
| 26 | **Sanitizer rule order is part of the contract: paths are rewritten before blobs are redacted, and every fixture carries its rule trace** | keep the earlier order and maintain the fixture table by hand beside the rule table | the blob class `[A-Za-z0-9+/]` matches a long absolute path, so under the old order `Read: /home/…/IngestController.php` sanitized to `‹redacted:blob›.php` — a descriptor that answers nothing. Hand-maintained twins drift, which is how the draft shipped two fixtures no rule could produce | rule numbering moved, so every cross-reference to a rule number had to move with it. The trace column makes the next such change mechanical (AT-2 asserts it) |

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
| 2 | `hook` subcommand + spool writer + call-index journal + counter sink | AT-3, AT-9, AT-10, AT-16 |
| 3 | `flusher` subcommand + `state.json` + ownership + backoff | AT-4, AT-5, AT-17 |
| 4 | `statusline` subcommand + passthrough + sample store | AT-14 |
| 5 | ingest endpoint: auth, attribution, validation, atomic batch, dedup, enum coercion | AT-6, AT-12, AT-13, AT-15, AT-18 |
| 6 | server-side call ledger + orphan timeouts | AT-1 (**the gate on trusting the signal at all**), AT-11, AT-19 |
| 7 | staleness, predicate alarm, and the attention pair | AT-7, AT-8, AT-20 |

Two of these are hard requirements before anything downstream may treat this telemetry as true:
**AT-1** (`docs/PLAN.md § 3`, card #7337 — a real `/clear` against a real subagent tool call), and a
**real install on a Windows seat** (card #7336), because every file, path and process assumption in
[§ 11](#11-the-spool-and-the-flusher) is cross-platform by design and unproven until then.

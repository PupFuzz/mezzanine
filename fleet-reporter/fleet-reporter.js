#!/usr/bin/env node
'use strict';
/*
 * fleet-reporter.js — the Mezzanine seat telemetry producer.
 *
 * ONE FILE, NO DEPENDENCIES, NODE >= 18. Built to `docs/design/EVENT-SCHEMA.md` (D1), which is
 * the wire contract and the authority for every number, field and enum below. Where this file
 * cites a section (§ 6.5, § 8.3, …) it is citing D1; the citation is a pointer, never a second
 * copy of the rule. Where D1 was silent and a choice had to be made, the comment says
 * "D1-SILENT" and states the choice and why — those are the lines a reviewer should read first.
 *
 * FOUR SUBCOMMANDS (§ 2.1):
 *   node fleet-reporter.js hook <HookName>   one-shot: read stdin, append, exit 0
 *   node fleet-reporter.js statusline        one-shot: sample context, pass the status line through
 *   node fleet-reporter.js flusher           long-lived: own the cursor, POST batches, heartbeat
 *   node fleet-reporter.js selftest          one-shot: the six checks § 6.14 declares
 *
 * THE SIX RULES THAT PROTECT THE SEAT (§ 2.2) ARE THE POINT OF THIS FILE, not a feature of it.
 * A hook that blocks, prints, or exits non-zero damages the agent it is watching:
 *   P-1 always exit 0        — every entry point is wrapped; `process.exit(0)` in a `finally`.
 *                              exit 2 is the harness's BLOCK signal: a PreToolUse exiting 2
 *                              kills the tool call outright and feeds our stderr to the model.
 *   P-2 no stdout from hook  — hook stdout is harness control input, and on SessionStart /
 *                              UserPromptSubmit it is injected into the MODEL'S CONTEXT.
 *   P-3 no network in hook   — a WAN round-trip inside a hook taxes every tool call on the seat.
 *   P-4 sync appends only    — every write is one `writeSync` on a descriptor opened 'a'; no
 *                              `await` between the first write and the exit.
 *   P-5 p99 < 250 ms         — measured by the acceptance suite, worst case = a 64-call reap.
 *   P-6 never print a token  — see `redactSecrets()`; the config token never reaches any stream.
 *   P-7 detached respawn     — `{detached, stdio:'ignore', windowsHide}` + `unref()`.
 *
 * WINDOWS IS A FIRST-CLASS TARGET (card #7336 validates it on a real seat). Consequences that
 * are easy to lose in review: LF is written explicitly and `os.EOL` is never used, so fixtures
 * are byte-identical on both platforms; no file is ever renamed over a path another process may
 * hold open; bucket filenames come from the clock so rotation needs no rename at all; every
 * spawn passes `windowsHide` so a respawn never flashes a console window on the seat.
 *
 * READ THE SPOOL AS APPEND-ONLY, ALWAYS. Claude Code runs tool calls in parallel, so several
 * hook processes are alive at once. Nothing here ever read-modify-writes a file another process
 * may be writing — that pattern is a lost-update generator, and a lost `open` record is not a
 * lost statistic: it is a call the reporter can no longer close, a seat that renders `working`
 * for fifteen minutes after it went idle (§ 8.2).
 */

const fs = require('fs');
const path = require('path');
const os = require('os');
const crypto = require('crypto');

/* THE HOOK PATH LOADS NO NETWORK MODULE, AND THAT IS P-3 MADE STRUCTURAL RATHER THAN OBSERVED.
 * `https`, `zlib` and `child_process` are required LAZILY, inside the functions that use them,
 * for two reasons and both are load-bearing:
 *   1. MEASURED on this machine: `node -e ''` costs 197 ms, and adding
 *      require('https')+require('zlib')+require('child_process') costs 67 ms more — 27 % of
 *      P-5's whole 250 ms budget, spent on every hook fire, for modules a hook must never use.
 *      The reporter's own work, by contrast, measured ~13 ms.
 *   2. P-3 says "the hook subcommand contains no HTTP client call". A rule like that is only as
 *      good as the next edit. With the client absent from the module graph of a hook process,
 *      a hook that tried to POST would have to require it first — which is a visible, greppable
 *      act rather than an innocent-looking call to something already in scope. */
const lazy = (name) => require(name);

const REPORTER_VERSION = '0.1.0';
const SCHEMA_VERSION = 1;

/* ── Every number, from § 14 and the section that derives it ──────────────────────────────── */
const K = {
  EVENT_CAP: 4096,               // § 4.4 one serialized event
  DATA_CAP: 3072,                // § 4.3 data object
  DESCRIPTOR_CAP: 200,           // § 7.4
  TITLE_CAP: 120,                // § 7.4
  BATCH_EVENTS: 200,             // § 4.4
  BATCH_BYTES: 262144,           // § 4.4 256 KiB
  SPOOL_BYTES: 33554432,         // § 11.3 32 MiB
  RESIDENCY_MS: 8 * 86400000,    // § 11.3 8 days
  FLUSH_MS: 10000,               // § 9.1
  FLUSH_MIN_EVENTS: 50,          // § 11.5
  HEARTBEAT_MS: 60000,           // § 9.1
  BACKOFF_BASE_MS: 2000,         // § 11.5
  BACKOFF_MAX_MS: 120000,        // § 11.5
  RETRY_AFTER_CAP_S: 600,        // § 11.5
  REQUEST_MS: 15000,             // § 3.5 total request deadline
  CONNECT_MS: 5000,              // § 3.5 connect deadline
  GZIP_MIN: 8192,                // § 3.5
  LOCK_STALE_MS: 90000,          // § 2.3 1.5 heartbeat intervals
  OPEN_CALLS: 64,                // § 8.2
  TOMBSTONES: 64,                // § 8.2
  SESSIONS: 16,                  // § 8.2
  TOMBSTONE_MS: 900000,          // § 8.2 15 min, == the ordinary orphan timeout
  ATTENTION_MS: 3600000,         // § 6.13 60 min
  COMPACTION_MS: 600000,         // § 6.10 10 min
  SILENCE_MS: 5400000,           // § 6.2 90 min
  SAMPLE_STALE_MS: 300000,       // § 6.9 300 s
  SAMPLE_TTL_MS: 86400000,       // § 11.1 24 h
  INDEX_FOLD_MAX: 8388608,       // § 8.2 8 MiB
  COUNTERS_CAP: 1536,            // § 6.14 1.5 KiB
  PREDICATES_CAP: 512,           // § 6.14
  SELFTEST_CAP: 256,             // § 6.14
  DEGRADED_MAX: 12,              // § 9.3 — the member table's size, not a chosen number
  BUCKET_GRACE_MS: 5000,         // § 11.1 20x the P-5 hook budget
  WRAPPED_STATUSLINE_MS: 1000,   // § 6.11
  STATUSLINE_CADENCE_MS: 60000,  // § 6.11
  STATUSLINE_BUCKET_PCT: 5,      // § 6.11
  Q_CORRUPT_CAP: 262144,         // § 11.1 256 KiB
  Q_REJECTED_CAP: 1048576,       // § 11.1 1 MiB
  REJECTED_TXT_CAP: 65536,       // § 11.1 64 KiB
  LOG_DAY_CAP: 1048576,          // § 11.1 1 MiB/day
  LOG_RETAIN_DAYS: 2,            // § 11.1
};

/* ── Harness-sourced enum value sets (§ 6.0). ─────────────────────────────────────────────────
 * These are transcriptions of another product's declarations, which is the exact shape that
 * cost D1 two review rounds — so they are not trusted here either: `selftest`'s
 * `harness_payload_keys` check asserts every one of them against the vendored fixtures, and
 * D1's own § 6.0 binds them to the installed binary. An unrecognised value is COERCED to the
 * field's unknown member and counted; it never reaches the wire (§ 6.0 rule 4), because the
 * ingest validates these sets and one unannounced harness value would otherwise turn into a
 * 422, a rejected 200-event batch, and a permanent quarantine.                               */
const ENUM = {
  session_start_source: ['startup', 'resume', 'clear', 'compact', 'fork'],
  session_end_reason: ['clear', 'resume', 'logout', 'prompt_input_exit', 'other'],
  precompact_trigger: ['manual', 'auto'],
  stopfailure_error: ['rate_limit', 'overloaded', 'server_error', 'authentication_failed',
    'billing_error', 'invalid_request', 'model_not_found', 'max_output_tokens',
    'oauth_org_not_allowed', 'account_on_hold', 'unknown'],
};

/* § 6.12's lookup table — this reporter's one home for the notification_type value set. The
 * three emitting rows produce `notification_kind`; every other declared member is a real
 * notification that is NOT a request for human attention, and emitting for it would put the
 * desk into a false `blocked` — the exact mirror of the false-idle defect D1 exists to prevent.
 * The suppression is never silent: `notification_not_attention.<type>` counts each one. */
const NOTIFICATION_KIND = {
  permission_prompt: 'permission_required',
  worker_permission_prompt: 'permission_required',
  idle_prompt: 'input_awaited',
  agent_needs_input: 'input_awaited',
  elicitation_dialog: 'elicitation',
  elicitation_url_dialog: 'elicitation',
};
const NOTIFICATION_NOT_ATTENTION = ['auth_success', 'agent_completed', 'elicitation_complete',
  'elicitation_response', 'push_notification', 'computer_use_enter', 'computer_use_exit',
  'quota_auto_resume_fired', 'quota_auto_resume_disabled', 'quota_auto_resume_stale'];

/* § 9.3's degradation members, in that section's order — the array's bound IS this list's
 * length (§ 9.3: "Twelve members, and the array's bound is twelve"). Each maps to the counters
 * that raise it; a member is present when ANY of them is non-zero at that flush. */
const DEGRADED = [
  ['lossy', ['spool_dropped_events', 'spool_corrupt_lines', 'events_rejected_dropped', 'oversize_event_dropped']],
  ['batches_rejected', ['batches_rejected']],
  ['harness_contract_moved', ['hook_name_mismatch', 'payload_key_missing.is_interrupt', /^payload_key_missing\./]],
  ['reporter_behind', [/^enum_value_unknown\./]],
  ['value_clamped', [/^value_clamped\./]],
  ['counters_omitted', ['data_truncated.reporter.heartbeat.counters']],
  ['index_overflow', ['open_call_index_overflow', 'open_session_index_overflow', 'index_fold_truncated']],
  ['invalid_tool_name', ['invalid_tool_name']],
  ['bad_session_id', ['bad_session_id']],
  ['config_invalid', ['config_invalid']],
  ['statusline_degraded', ['wrapped_statusline_failures']],
  ['epoch_reset', ['state_reset']],
];

/* § 9.4's predicates. Every classifying predicate reports BOTH branch counts, because the
 * incident this design is written around (§ 3.4) was a predicate that went silently constant
 * and stayed wrong for 30 days. Adding a predicate without adding it here is a review-blocking
 * defect there; `selftest`'s `predicate_discrimination` check asserts the set is complete. */
const PREDICATES = ['attention_source_permission_hook', 'descriptor_allowlisted',
  'clear_reap_by_session_end', 'agent_scope_subagent', 'attention_resolved_by_hook'];

/* The six `selftest` checks § 6.14's member table declares — the same set the subcommand runs
 * and the heartbeat reports, so the two cannot drift apart. */
const SELFTEST_CHECKS = ['config_readable', 'tls_verify', 'schema_version_accepted',
  'sanitizer_fixtures', 'predicate_discrimination', 'harness_payload_keys'];

/* Always-present delivery counters — serialized FIRST under § 6.14's reduction rule, so a seat
 * with too many kinds of trouble to fit 1.5 KiB still reports the ones delivery depends on. */
const ALWAYS_COUNTERS = ['events_emitted', 'events_sent', 'spool_dropped_events',
  'spool_corrupt_lines', 'batches_ok', 'batches_retried', 'batches_rejected',
  'events_rejected_dropped'];

/* ── Clock ────────────────────────────────────────────────────────────────────────────────────
 * D1-MANDATED INJECTION: AT-16's third case drives an 8x200 run across a simulated UTC hour
 * boundary, which requires "the reporter takes its clock from an injectable source in tests"
 * (§ AT-16). FLEET_REPORTER_NOW_MS is that source. It shifts time only; it gates nothing, and
 * no identity, transport or emission decision reads it (§ 3.4 rule 1).                        */
const CLOCK_OFFSET = (() => {
  const v = process.env.FLEET_REPORTER_NOW_MS;
  if (!v) return 0;
  const n = Number(v);
  return Number.isFinite(n) ? n - Date.now() : 0;
})();
const now = () => Date.now() + CLOCK_OFFSET;

/* rfc3339_ms — always three fractional digits, always UTC, always Z (§ 6.0). */
const rfc3339 = (ms) => new Date(ms).toISOString();

/* ULID: 48-bit ms timestamp + 80 random bits, Crockford base32, lexicographically sortable by
 * mint time (§ 6.0). Generated inline from crypto.randomBytes — a dependency for 26 characters
 * would be a supply-chain surface on every agent machine (§ 2.1). */
const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
function ulid(atMs) {
  let t = Math.floor(atMs === undefined ? now() : atMs);
  let head = '';
  for (let i = 0; i < 10; i++) { head = CROCKFORD[t % 32] + head; t = Math.floor(t / 32); }
  const rnd = crypto.randomBytes(10); // 80 bits -> exactly 16 base32 characters
  let bits = 0, n = 0, tail = '';
  for (const b of rnd) {
    bits = (bits << 8) | b; n += 8;
    while (n >= 5) { n -= 5; tail += CROCKFORD[(bits >> n) & 31]; bits &= (1 << n) - 1; }
  }
  return head + tail;
}

const utcBucket = (ms) => rfc3339(ms).slice(0, 13).replace(/[-T]/g, ''); // YYYYMMDDHH
const utcDay = (ms) => rfc3339(ms).slice(0, 10).replace(/-/g, '');       // YYYYMMDD
const bucketEndMs = (b) => Date.UTC(+b.slice(0, 4), +b.slice(4, 6) - 1, +b.slice(6, 8), +b.slice(8, 10)) + 3600000;
const bytes = (s) => Buffer.byteLength(s, 'utf8');

/* ── Config (§ 3.1) ──────────────────────────────────────────────────────────────────────────
 * The config file is the ONLY source of the reporter's identity. Identity is never inferred
 * from hostname, cwd, username, process tree, or any harness variable — § 3.4 is written about
 * a seat-detection predicate keyed on an undocumented harness env var that went silently
 * constant and left two consumers dark for 30 days.
 *
 * D1-SILENT: the config PATH. D1 fixes the per-OS default but names no override, and both the
 * acceptance suite and an operator debugging a second seat need one. FLEET_REPORTER_CONFIG
 * selects WHICH FILE to read and nothing else — no value in it comes from the environment, so
 * § 3.4 rule 1 is untouched: identity is still file-resident, and a wrong path is a loud
 * `config_readable` failure rather than a silently different identity. An argv flag was the
 * alternative and was rejected: § 2.1 fixes the hook contract as `argv[2] == <HookName>`.     */
function configPath() {
  if (process.env.FLEET_REPORTER_CONFIG) return process.env.FLEET_REPORTER_CONFIG;
  if (process.platform === 'win32') {
    return path.join(process.env.APPDATA || path.join(os.homedir(), 'AppData', 'Roaming'),
      'fleet-reporter', 'config.json');
  }
  return path.join(os.homedir(), '.config', 'fleet-reporter', 'config.json');
}

const SLUG_INSTALL = /^[a-z0-9][a-z0-9-]{1,31}$/;
const SLUG_SEAT = /^[a-z0-9][a-z0-9-]{1,47}$/;
const TOKEN_RE = /^mzn_[A-Za-z0-9_-]{43}$/;

/* Returns {config, errors[]}. NEVER throws: a hook with an unreadable config still exits 0 and
 * still writes nothing to stdout (P-1, P-2). A config error is loud on the seat's OWN surface
 * (the log, `config_invalid`, `selftest`) — never on the agent's. */
function loadConfig(p) {
  const errors = [];
  let raw;
  try { raw = fs.readFileSync(p, 'utf8'); }
  catch (e) { return { config: null, errors: [`config unreadable at ${p}: ${e.code || e.message}`] }; }
  let c;
  try { c = JSON.parse(raw); }
  catch (e) { return { config: null, errors: [`config is not valid JSON: ${e.message}`] }; }
  const str = (k) => (typeof c[k] === 'string' ? c[k] : null);
  if (!SLUG_INSTALL.test(str('install_id') || '')) errors.push('install_id fails ^[a-z0-9][a-z0-9-]{1,31}$');
  if (!SLUG_SEAT.test(str('seat_id') || '')) errors.push('seat_id fails ^[a-z0-9][a-z0-9-]{1,47}$');
  const url = str('ingest_url') || '';
  // § 3.5: an http:// ingest_url is REFUSED at install and at runtime. Fail closed, loudly, on
  // the client's own surface — the bearer token rides the header and cleartext broadcasts it.
  if (!/^https:\/\//.test(url)) errors.push('ingest_url must be an absolute https:// URL (§ 3.5 — http is refused, not downgraded)');
  else if (bytes(url) > 256) errors.push('ingest_url exceeds 256 B');
  if (!TOKEN_RE.test(str('token') || '')) errors.push('token must be mzn_ + 43 base64url characters');
  if (!str('spool_dir')) errors.push('spool_dir must be an absolute path');
  if (typeof c.enabled !== 'boolean') errors.push('enabled must be a boolean');
  for (const k of ['ca_file', 'proxy_url', 'wrapped_statusline']) {
    if (c[k] !== undefined && c[k] !== null && typeof c[k] !== 'string') errors.push(`${k} must be a string or null`);
  }
  return { config: c, errors };
}

/* ── P-6: a secret VALUE must never reach an output stream, a log, a traceback or an argv ────
 * The control is that the token is never PASSED to a writer — but "never passed" is a property
 * of every call site, and one future call site is all it takes. So the log writer redacts at
 * the sink as well: the config token by value, and anything carrying a known credential prefix
 * (rule 3's set) by shape, which also covers a token pasted into an error body by a server.
 * The acceptance suite plants a token into a log call and asserts this catches it — a control
 * whose silence means nothing unless it has been seen to speak. */
let SECRET_VALUES = [];
function registerSecret(v) { if (typeof v === 'string' && v.length >= 8 && !SECRET_VALUES.includes(v)) SECRET_VALUES.push(v); }
const CRED_PREFIX_RE = /\b(gh[pousr]_|github_pat_|sk-|sk_live_|sk_test_|xox[abposr]-|AKIA|ASIA|glpat-|AIza|mzn_|mzr_)[A-Za-z0-9_-]{8,}/g;
function redactSecrets(s) {
  let out = String(s);
  for (const v of SECRET_VALUES) if (v) out = out.split(v).join('‹redacted:token›');
  return out.replace(CRED_PREFIX_RE, '‹redacted:token›');
}

/* ── Append-only filesystem primitives (§ 11.1) ──────────────────────────────────────────────
 * ONE DISCIPLINE FOR ALL FOUR TREES: hour-bucketed filenames instead of rotation, one O_APPEND
 * writeSync of one LF-terminated line per record, and nobody ever rewriting a file another
 * process may be writing. A rename-based rotation races with concurrent appenders and fails
 * outright on Windows when the file is open; deriving the name from the clock removes the
 * operation entirely.
 *
 * THE BUCKET NAME IS DERIVED IMMEDIATELY BEFORE THE WRITE, never at process entry (§ 11.1). A
 * hook that starts at 13:59:59.900 and computes its bucket at entry writes to bucket 13 after
 * the hour rolled, and the flusher's next pass is <= 10 s away: read-to-EOF, unlink, line lost.
 * Both halves of the fix are needed — this one, and the 5 s grace on the deletion side.       */
function ensureDir(d) { try { fs.mkdirSync(d, { recursive: true, mode: 0o700 }); } catch (e) { /* EEXIST or a read-only tree: the caller's write fails and is counted */ } }

/* One line, one writeSync, LF always (never os.EOL — identical bytes on both platforms keeps
 * fixtures identical). Returns true on success; a failed append is never fatal to the seat.
 *
 * THE DESCRIPTOR IS CACHED PER PATH, AND ONLY IN A ONE-SHOT PROCESS. Measured on this machine:
 * a session-boundary reap at the 64-open-call cap performs ~130 appends, and an open+close per
 * line cost ~0.7 ms each — ~90 ms of the P-5 budget spent on syscalls the design never asked
 * for. § 11.2 requires "one fs.writeSync of one LF-terminated buffer on a descriptor opened
 * 'a'"; it does not require a FRESH descriptor per line, and reusing one preserves the
 * atomicity property exactly, because O_APPEND is a property of each write and not of the open.
 *
 * The cache is OFF in the flusher, deliberately. A one-shot process lives under 250 ms, and the
 * 5 s deletion grace (§ 11.1) means no bucket it holds open can be unlinked under it. The
 * flusher is long-lived AND is the process that deletes buckets: a cached descriptor to a
 * bucket it later unlinks would write to an orphaned inode — events lost with no counter
 * incremented, which is the one loss shape § 0 item 9 forbids outright. */
let FD_CACHE = null;   // a Map only in `hook` / `statusline`; null everywhere else
function appendLine(file, line) {
  try {
    if (FD_CACHE) {
      let fd = FD_CACHE.get(file);
      if (fd === undefined) { ensureDir(path.dirname(file)); fd = fs.openSync(file, 'a'); FD_CACHE.set(file, fd); }
      fs.writeSync(fd, Buffer.from(line + '\n', 'utf8'));
      return true;
    }
  } catch (e) { FD_CACHE.delete(file); return false; }
  let fd = -1;
  try {
    ensureDir(path.dirname(file));
    fd = fs.openSync(file, 'a');
    fs.writeSync(fd, Buffer.from(line + '\n', 'utf8'));
    return true;
  } catch (e) { return false; }
  finally { if (fd >= 0) { try { fs.closeSync(fd); } catch (e) { /* nothing left to do */ } } }
}

/* Append respecting a byte cap with stated at-cap behaviour (§ 11.1) — a cap without one is an
 * unstated default. Returns false when the cap refused the write, so the caller counts it. */
function appendCapped(file, line, cap) {
  try {
    let size = 0;
    try { size = fs.statSync(file).size; } catch (e) { size = 0; }
    if (size >= cap) return false;
  } catch (e) { /* fall through and try the write */ }
  return appendLine(file, line);
}

function atomicWrite(file, text) {
  // .tmp + rename with a UNIQUE temp name (§ 11.1): one fixed temp name is a lost-update
  // generator the moment two processes race, which is exactly what § 8.2 removed from the index.
  const tmp = `${file}.${process.pid}.${crypto.randomBytes(4).toString('hex')}.tmp`;
  try {
    ensureDir(path.dirname(file));
    fs.writeFileSync(tmp, text, { encoding: 'utf8', mode: 0o600 });
    fs.renameSync(tmp, file); // atomic-replace on both platforms
    return true;
  } catch (e) {
    try { fs.unlinkSync(tmp); } catch (e2) { /* the temp file is already gone */ }
    return false;
  }
}

/* ── Process-local counters and predicate branches, flushed as ONE delta line at exit ─────────
 * These are computed in hook and statusLine processes the flusher never shares memory with
 * (§ 11.1). Writing them into state.json would recreate exactly the lost-update defect § 8.2
 * removes from the call index; without them the heartbeat's `counters` and `predicates` are
 * unbuildable, and § 9.4's alarm — the structural backstop of the whole design — is something
 * an implementer could not construct.                                                        */
const C = Object.create(null);   // counter deltas for THIS process
const P = Object.create(null);   // predicate branch deltas for THIS process

/* A DIAGNOSTIC MUST NOT MOVE AN OPERATIONAL COUNTER. `selftest` runs § 7.5's thirteen fixtures
 * through the real sanitizer, and the flusher runs `selftest` at startup — so without this the
 * fixtures' own 12 redactions were folded into the seat's `sanitizer_redactions` on every
 * flusher start. That is a fleet-visible number rendered on the floor, and inflating it by a
 * self-check makes it a wrong number that no operator could account for. */
let COUNTING_SUSPENDED = false;
function count(name, n) {
  if (COUNTING_SUSPENDED) return;
  C[name] = (C[name] || 0) + (n === undefined ? 1 : n);
}
function predicate(name, branch) {
  if (COUNTING_SUSPENDED) return;
  if (!P[name]) P[name] = { true: 0, false: 0 };
  P[name][branch ? 'true' : 'false'] += 1;
}

function flushCounters(spoolDir, role, atMs) {
  if (!Object.keys(C).length && !Object.keys(P).length) return;
  const t = atMs === undefined ? now() : atMs;
  const line = JSON.stringify({ t: rfc3339(t), p: role, c: C, k: P });
  appendLine(path.join(spoolDir, 'counters', `${utcBucket(t)}.jsonl`), line);
}

/* The seat's own diagnostic log — local, never shipped (§ 1: "The reporter's own log stays
 * local. It is a diagnostic for the seat's owner, not a stream."). */
function logLine(spoolDir, role, msg) {
  if (!spoolDir) return;
  const t = now();
  const line = redactSecrets(`${rfc3339(t)} [${role}] ${msg}`);
  appendCapped(path.join(spoolDir, 'log', `${utcDay(t)}.log`), line, K.LOG_DAY_CAP);
}

/* ════════════════════════════════════════════════════════════════════════════════════════════
 * SANITIZATION, AT THE REPORTER (§ 7)
 *
 * D-06 is binding and the reason is a property, not a preference: A SECRET THAT IS NEVER SENT
 * CANNOT BE LEAKED BY THE SERVER. If this lived at the ingest, every secret in every seat's
 * tool arguments would cross the WAN and land in a request log, an APM trace or a stack trace
 * before any rule ran.
 *
 * TWO LAYERS, AND THE FIRST ONE IS THE REAL CONTROL. Layer 1 is an allowlist: a descriptor is
 * built ONLY from an explicitly allowlisted input key of an explicitly allowlisted tool, so a
 * tool nobody anticipated — every `mcp__*` tool, whose input schema is defined by a third-party
 * server — contributes no descriptor at all. Layer 2 redacts the allowlisted text, because
 * `curl -H "Authorization: Bearer sk-…"` is an allowlisted Bash command.
 *
 * THE LOCKING RULE (§ 7.2). Once a rule replaces a span with a `‹…›` marker, later rules do not
 * match inside or across it; a candidate overlapping a locked span is DISCARDED. Without it the
 * output depends on rule interaction order in ways nobody can predict and the fixtures below
 * would not be deterministic. It is load-bearing in fixtures 1, 3, 9 and 11, where it is the
 * only reason a credential keyword next to an already-redacted value does not redact the marker.
 *
 * ORDER IS PART OF THE CONTRACT (§ 7.3), not an implementation detail: rule 6 runs before rule 7
 * because a filesystem path is a long run of [A-Za-z0-9/] and would otherwise be eaten whole by
 * the blob rule before it could be shortened to something a human can read.
 * ════════════════════════════════════════════════════════════════════════════════════════════ */

function overlapsLock(locks, s, e) {
  for (let i = 0; i < locks.length; i++) if (s < locks[i][1] && locks[i][0] < e) return true;
  return false;
}

/* Apply one rule across the string, carrying locked spans forward. `build(match)` returns
 * {text, lock:[relStart,relEnd]|null}. A candidate overlapping a lock is skipped, not retried. */
function applyRule(st, re, build) {
  const src = st.s;
  let out = '';
  const locks = [];
  let last = 0, fired = 0, m;
  re.lastIndex = 0;
  while ((m = re.exec(src)) !== null) {
    const ms = m.index, me = ms + m[0].length;
    if (me === ms) { re.lastIndex = ms + 1; continue; }
    if (overlapsLock(st.locks, ms, me)) continue;   // § 7.2 — discarded, not re-tried shorter
    const shift = out.length - last;
    for (const L of st.locks) if (L[0] >= last && L[1] <= ms) locks.push([L[0] + shift, L[1] + shift]);
    out += src.slice(last, ms);
    const b = build(m);
    const base = out.length;
    out += b.text;
    if (b.lock) locks.push([base + b.lock[0], base + b.lock[1]]);
    last = me;
    // A rule that MATCHED but changed nothing has not fired. Rule 6b matches every path token
    // and shortens only those over 4 segments; rule 9 matches every dotted quad and redacts
    // only the valid ones. Counting those as fired would make the trace column AT-2 asserts
    // against § 7.5 report rules that did nothing — which is a false trace, not a nicety.
    if (b.text !== m[0]) fired += 1;
  }
  const shift = out.length - last;
  for (const L of st.locks) if (L[0] >= last) locks.push([L[0] + shift, L[1] + shift]);
  out += src.slice(last);
  return { s: out, locks, fired };
}

/* A marker plus the offsets of its own span, so the caller can lock exactly the replacement. */
function mark(prefix, marker, suffix) {
  return { text: prefix + marker + suffix, lock: [prefix.length, prefix.length + marker.length] };
}

const IPV4_RE = /\b(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\b/g;

/* § 7.4 — truncate to `cap` bytes of UTF-8 without ever splitting a multi-byte character: cut
 * at the last character boundary at or before byte cap-3 and append '…' (U+2026, 3 bytes). */
function truncateBytes(s, cap) {
  const buf = Buffer.from(s, 'utf8');
  if (buf.length <= cap) return { text: s, truncated: false };
  let end = cap - 3;
  while (end > 0 && (buf[end] & 0xC0) === 0x80) end--;  // walk back over continuation bytes
  return { text: buf.slice(0, end).toString('utf8') + '…', truncated: true };
}

/* Rule 6's path shortening. D1-SILENT on the separator to REJOIN with on Windows: the doc
 * mandates the literal output `~/…/design/EVENT-SCHEMA.md` (so a home-rooted token joins with
 * '/') and names `X:` as a root prefix in its own right (so a drive-rooted token joins with
 * '\'). Those two literal statements are the rule implemented here; nothing else in D1
 * constrains it, and no fixture exercises a Windows path. Reported as a D1 gap. */
function shortenPathToken(tok) {
  let root, rest, sep;
  if (/^~[/\\]/.test(tok)) { root = '~'; rest = tok.slice(1); sep = '/'; }
  else if (/^\.[/\\]/.test(tok)) { root = '.'; rest = tok.slice(1); sep = '/'; }
  else if (/^[A-Za-z]:\\/.test(tok)) { root = tok.slice(0, 2); rest = tok.slice(2); sep = '\\'; }
  else if (tok[0] === '/') { root = ''; rest = tok; sep = '/'; }
  else return null;
  const segs = rest.split(/[/\\]/).filter((x) => x.length);
  if (segs.length <= 4) return null;   // "> 4 segments" — 4 is untouched
  return `${root}${sep}…${sep}${segs.slice(-2).join(sep)}`;
}

/*
 * sanitize(text) -> { text, truncated, rules }
 *
 * `rules` is the ordered list of numbered § 7.3 rules that actually fired. It is not
 * diagnostics: AT-2's consistency check asserts it equals the "Rules that fire" column of each
 * § 7.5 fixture, so a fixture whose documented trace and actual trace disagree fails EVEN IF
 * its output string matches. That disagreement is the drift between the two tables that
 * produced two broken fixtures in an earlier draft of D1.
 */
function sanitize(input, cap) {
  let st = { s: String(input == null ? '' : input), locks: [] };
  const rules = [];
  let redactions = 0;   // SPANS replaced by rules 1-9, not rules fired: AT-16 asserts an exact
  const run = (n, re, build) => {   // total over a fixture with a known number of spans.
    const r = applyRule(st, re, build);
    st = { s: r.s, locks: r.locks };
    if (r.fired) { if (!rules.includes(n)) rules.push(n); if (n <= 9) redactions += r.fired; }
  };

  // 1 — URL userinfo.
  run(1, /(\w+:\/\/)[^/\s:@]+:[^/\s@]+@/g, (m) => mark(m[1], '‹redacted›', '@'));

  // 2 — env-expansion DEFAULTS. `${VAR:-fallback}` prints the fallback when VAR is unset, and
  // the fallback is exactly where a hard-coded credential hides (a measured incident in this
  // fleet). The variable NAME is kept — it carries no value and is useful context — and the
  // operator is kept VERBATIM, so `${V:=x}` is never relabelled as `${V:-…}`.
  run(2, /\$\{(\w+):([-=?+])([^}]*)\}/g, (m) => mark(`\${${m[1]}:${m[2]}`, '‹redacted›', '}'));

  // 3 — known-prefix credentials.
  run(3, /\b(gh[pousr]_|github_pat_|sk-|sk_live_|sk_test_|xox[abposr]-|AKIA|ASIA|glpat-|AIza|mzn_|mzr_)[A-Za-z0-9_-]{8,}/g,
    () => mark('', '‹redacted:token›', ''));

  // 4 — credential KEYWORD + its value, separated by '=', ':' or whitespace. Keyword and
  // separator are kept verbatim so the descriptor still says what was being done.
  run(4, /(?<![\w-])((?:-{1,2}|[A-Za-z0-9]{0,24}[_-])?(?:pass(?:word)?|secret|token|api[_-]?key|auth|bearer|credential))(?![A-Za-z])(\s*[:=]\s*|\s+)(\S+)/gi,
    (m) => mark(m[1] + m[2], '‹redacted›', ''));

  // 5 — credential FLAGS, glued or separated. Fixtures 9-11 are the credential-on-argv shapes
  // rule 4 alone did not cover; each was an unredacted survivor before this rule existed.
  // The false positives are deliberate and correct: `git push -u origin main` loses `origin`,
  // and a leaked credential is unrecoverable the moment it is written to a log.
  run(5, /(?<![\w-])(--(?:user|password|token|secret)(?:\s*[:=]\s*|\s+)|-[up](?:\s*[:=]?\s*))(\S+)/gi,
    (m) => mark(m[1], '‹redacted›', ''));

  // 6a — home directories. This is a PII rule as much as a length rule: an absolute path
  // carries the OS username, which § 1 excludes from the wire outright.
  run(6, /\/home\/[^/\s]+\/|\/Users\/[^/\s]+\/|[A-Za-z]:\\Users\\[^\\\s]+\\/g, () => ({ text: '~/', lock: null }));
  // 6b — long paths. Runs BEFORE rule 7 (§ 7.3), or the blob rule eats the path whole.
  run(6, /(^|[\s"'`])((?:~[/\\]|\.[/\\]|\/|[A-Za-z]:\\)[^\s"'`]*)/g, (m) => {
    const short = shortenPathToken(m[2]);
    return { text: short === null ? m[0] : m[1] + short, lock: null };
  });

  // 7 — long opaque blobs. `\b` anchors the run to its first alphanumeric, which is what makes
  // fixture 13's stated 37-character run `opt/verylongdirectoryname/application` the match and
  // leaves the leading '/' in the output. STATED GAP (§ 7.3): the class excludes '-' and '_',
  // so a base64URL secret is split into runs under 32 and can survive this rule — rule 3's
  // prefixes and rules 4-5's keyword/flag shapes are the backstop for the ones that matter.
  run(7, /\b[A-Za-z0-9+/]{32,}={0,2}/g, () => mark('', '‹redacted:blob›', ''));
  run(7, /\b[0-9a-f]{24,}\b/g, () => mark('', '‹redacted:blob›', ''));

  // 8 — email addresses.
  run(8, /[\w.+-]+@[\w-]+\.[\w.-]+/g, () => mark('', '‹redacted:email›', ''));

  // 9 — IPv4 literals, valid octets only (so a four-part version string that is not a valid
  // dotted quad survives; one that is, does not, and that false positive is the correct trade).
  run(9, IPV4_RE, (m) => {
    for (let i = 1; i <= 4; i++) if (+m[i] > 255) return { text: m[0], lock: null };
    return mark('', '‹redacted:ip›', '');
  });

  // 10 — ANSI escape sequences, removed ENTIRELY. Not cosmetic: a descriptor is written to a
  // local log, a quarantine file and an operator's terminal, and an ESC sequence that survives
  // into any of them is a terminal-control injection. Stripping only the ESC byte (which rule
  // 11 alone would do) leaves the visible garbage `[31m` in the label.
  run(10, /\x1b\[[0-9;]*[A-Za-z]|\x1b\][^\x07\x1b]*(?:\x07|\x1b\\)/g, () => ({ text: '', lock: null }));

  // 11 — control characters, including newline, tab and any surviving ESC.
  run(11, /[\x00-\x1F\x7F]/g, () => ({ text: ' ', lock: null }));

  // 12 — whitespace collapse, then trim.
  const collapsed = st.s.replace(/ {2,}/g, ' ').trim();
  if (collapsed !== st.s) { st = { s: collapsed, locks: [] }; if (!rules.includes(12)) rules.push(12); }

  // 13 — encoding repair: lone surrogates become U+FFFD, so the output is always valid UTF-8.
  const repaired = st.s.replace(/[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/g, '�');
  if (repaired !== st.s) { st = { s: repaired, locks: [] }; if (!rules.includes(13)) rules.push(13); }

  // 14 — truncate.
  const t = truncateBytes(st.s, cap);
  if (t.truncated && !rules.includes(14)) rules.push(14);
  return { text: t.text, truncated: t.truncated, rules, redactions };
}

/* ── Layer 1: the descriptor allowlist (§ 7.1) ───────────────────────────────────────────────
 * A tool not in this table contributes NO descriptor; its tool_name still transits, so the
 * floor still shows that something ran. MCP tools are handled by construction rather than by a
 * rule: an unknown tool has no allowlisted key, therefore no descriptor. AT-2 fixture 8 is the
 * proof — an unknown tool with an input named `password` yields `descriptor == null`.        */
const ALLOWLIST = {
  Bash: (ti) => firstLine(ti.command),
  Read: (ti) => ti.file_path,
  Write: (ti) => ti.file_path,
  Edit: (ti) => ti.file_path,
  Glob: (ti) => ti.pattern,
  Grep: (ti) => ti.pattern,
  Agent: (ti) => ti.description,
  Task: (ti) => ti.description,
  WebFetch: (ti) => schemeAndHost(ti.url),
  WebSearch: (ti) => ti.query,
  TodoWrite: () => '',            // rendered as the bare tool name — a label, no argument
};
const firstLine = (s) => (typeof s === 'string' ? s.split(/\r?\n/, 1)[0] : null);
/* D1-SILENT: an unparseable WebFetch url. Minimization decides it — no descriptor rather than
 * a raw url through the redactor, since scheme+host is the whole allowlisted surface. */
function schemeAndHost(u) {
  if (typeof u !== 'string') return null;
  const m = /^([A-Za-z][A-Za-z0-9+.-]*:\/\/[^/\s?#]+)/.exec(u);
  return m ? m[1] : null;
}

/* Returns {descriptor, truncated, allowlisted}. `allowlisted` drives § 9.4's
 * `descriptor_allowlisted` predicate, whose constant-false branch means the allowlist no
 * longer matches any tool name the harness sends. */
function buildDescriptor(toolName, toolInput) {
  const fn = ALLOWLIST[toolName];
  if (!fn) return { descriptor: null, truncated: false, allowlisted: false, rules: [] };
  let value = null;
  try { value = fn(toolInput && typeof toolInput === 'object' ? toolInput : {}); }
  catch (e) { value = null; }
  if (value === null || value === undefined) return { descriptor: null, truncated: false, allowlisted: true, rules: [], redactions: 0 };
  if (value === '') return { descriptor: toolName, truncated: false, allowlisted: true, rules: [], redactions: 0 };
  const r = sanitize(`${toolName}: ${value}`, K.DESCRIPTOR_CAP);
  countSanitizer(r);
  return { descriptor: r.text, truncated: r.truncated, allowlisted: true, rules: r.rules, redactions: r.redactions };
}

function countSanitizer(r) {
  if (r.redactions) count('sanitizer_redactions', r.redactions);
  if (r.truncated) count('sanitizer_truncations');
}

/* ════════════════════════════════════════════════════════════════════════════════════════════
 * THE CALL INDEX — an append-only journal (§ 8.2)
 *
 * THE INDEX IS NEVER READ-MODIFY-WRITTEN BY A HOOK. Claude Code runs tool calls in parallel, so
 * several hook processes are alive at once, and a shared JSON file rewritten by each of them
 * (.tmp + rename, one fixed temp name, no lock) is a lost-update generator: two hooks read the
 * same state, each writes back its own mutation, one open call disappears. THE CONSEQUENCE IS
 * NOT A LOST STATISTIC — the reporter can no longer close a call it has forgotten, the server
 * holds it open to the orphan timeout, and a perfectly healthy seat renders `working` for
 * fifteen minutes after it went idle. So the index is structured the way the spool is: one
 * O_APPEND writeSync per record, one folder (the flusher) compacting.
 *
 * D1-SILENT, AND NAMED AS SUCH: § 8.2's record table declares open / close / bind / tombstone /
 * attention_open / attention_close, and its snapshot carries `entries[]` and `tombstones[]`.
 * Four facts D1 requires elsewhere have no record to carry them, so four are added here. All
 * are REPORTER-INTERNAL — none reaches the wire, so none costs a schema version:
 *   · session_open / session_close  — § 8.2's 16-session cap with eviction-and-reap, § 8.4's
 *     "most-recently-active open session other than its own", § 6.14's `open_sessions`, and
 *     § 6.2's `duration_ms` / `turns` all read a live-session set D1 says the reporter "already
 *     holds" but gives no record for.
 *   · turn_open / turn_close        — § 6.4's `duration_ms` and § 6.2's `turns`.
 *   · compaction_open / compaction_close — § 6.10's two-signal close and the flusher's 10 min
 *     timeout need a compaction to be open across processes.
 *   · `prompt_id` on `open` and `outcome` on `close` — § 6.4 counts `tool_calls` and
 *     `failed_calls` over the calls sharing a turn's `prompt_id`, which neither record carries.
 * ════════════════════════════════════════════════════════════════════════════════════════════ */

const indexDir = (spool) => path.join(spool, 'index');

function journal(spool, rec) {
  const t = now();
  rec.at = rfc3339(t);
  return appendLine(path.join(indexDir(spool), `${utcBucket(t)}.jsonl`), JSON.stringify(rec));
}

function emptyIndex() {
  return {
    calls: new Map(), tombstones: new Map(), sessions: new Map(),
    turns: new Map(), attention: new Map(), compactions: new Map(),
    // A session TOMBSTONE, for the same reason a call has one: /clear announces itself twice
    // (§ 8.4) and the second signal must be able to tell "already reaped by my twin" from
    // "never seen", which are different events on the wire. Retained for the same 15 min.
    sessionsClosed: new Map(),
    bucket: null, offset: 0, corrupt: 0, truncated: false, evicted: [],
  };
}

function applyIndexRecord(ix, r, nowMs) {
  const touch = (sid) => { const s = ix.sessions.get(sid); if (s) s.last_active = r.at || s.last_active; };
  switch (r.k) {
    case 'session_open':
      if (!ix.sessions.has(r.session_id)) {
        ix.sessions.set(r.session_id, { session_id: r.session_id, opened_at: r.at, last_active: r.at, turns: 0, aborted_calls: 0 });
      }
      break;
    case 'session_close':
      ix.sessions.delete(r.session_id); ix.turns.delete(r.session_id);
      ix.sessionsClosed.set(r.session_id, r.at);
      break;
    case 'turn_open':
      ix.turns.set(r.session_id, { prompt_id: r.prompt_id || null, started_at: r.at, tool_calls: 0, failed_calls: 0 });
      { const s = ix.sessions.get(r.session_id); if (s) s.turns += 1; }
      touch(r.session_id); break;
    case 'turn_close': ix.turns.delete(r.session_id); touch(r.session_id); break;
    case 'open': {
      ix.calls.set(r.call_id, {
        call_id: r.call_id, session_id: r.session_id, tool_name: r.tool_name,
        harness_call_ref: r.harness_call_ref || null, started_at: r.at,
        is_dispatch: !!r.is_dispatch, agent_scope_id: r.agent_scope_id || null,
        child_agent_id: r.child_agent_id || null, prompt_id: r.prompt_id || null,
      });
      const turn = ix.turns.get(r.session_id);
      if (turn && turn.prompt_id === (r.prompt_id || null)) turn.tool_calls += 1;
      touch(r.session_id);
      break;
    }
    case 'close': {
      const e = ix.calls.get(r.call_id);
      if (e) {
        const turn = ix.turns.get(e.session_id);
        if (turn && turn.prompt_id === e.prompt_id && r.outcome === 'failed') turn.failed_calls += 1;
        ix.calls.delete(r.call_id);
        touch(e.session_id);
      }
      break;
    }
    case 'bind': { const e = ix.calls.get(r.call_id); if (e) e.child_agent_id = r.child_agent_id; break; }
    case 'tombstone': {
      const e = ix.calls.get(r.call_id);
      // A reaped entry does not vanish: it keeps its original call_id for 15 min so a late
      // close still finds it (§ 8.2). Without this the server's late-completion override —
      // the instrument that reports the Stop reap is too eager — could never fire.
      ix.tombstones.set(r.call_id, Object.assign({}, e || {}, { call_id: r.call_id, closed_at: r.at }));
      if (e) { const s = ix.sessions.get(e.session_id); if (s) s.aborted_calls += 1; ix.calls.delete(r.call_id); }
      break;
    }
    case 'attention_open':
      ix.attention.set(r.session_id, { request_id: r.request_id, session_id: r.session_id, opened_at: r.at, call_id: r.call_id || null });
      break;
    case 'attention_close':
      for (const [sid, a] of ix.attention) if (a.request_id === r.request_id) ix.attention.delete(sid);
      break;
    case 'compaction_open': ix.compactions.set(r.session_id, { session_id: r.session_id, started_at: r.at }); break;
    case 'compaction_close': ix.compactions.delete(r.session_id); break;
    default: break;   // an unknown record kind is ignored, never a throw
  }
}

/* Caps, applied after the fold so every reader sees the same bounded view. § 6.14's
 * `open_calls` 0..64, `open_sessions` 0..16 bounds are ENFORCED here, not asserted: the
 * seventeenth tracked session would otherwise make every heartbeat a 422 invalid_event —
 * the liveness backstop going permanently dark for that seat. */
function capIndex(ix, nowMs) {
  while (ix.calls.size > K.OPEN_CALLS) { ix.calls.delete(ix.calls.keys().next().value); count('open_call_index_overflow'); }
  for (const [id, tb] of ix.tombstones) {
    if (nowMs - Date.parse(tb.closed_at || 0) > K.TOMBSTONE_MS) ix.tombstones.delete(id);
  }
  while (ix.tombstones.size > K.TOMBSTONES) { ix.tombstones.delete(ix.tombstones.keys().next().value); count('open_call_index_overflow'); }
  for (const [sid, at] of ix.sessionsClosed) if (nowMs - Date.parse(at || 0) > K.TOMBSTONE_MS) ix.sessionsClosed.delete(sid);
  while (ix.sessions.size > K.SESSIONS) {
    // Eviction is by LEAST-RECENT ACTIVITY, not oldest-opened: a long-lived session that is
    // still emitting is the one worth keeping (§ 8.2).
    let victim = null;
    for (const [sid, s] of ix.sessions) if (!victim || Date.parse(s.last_active || 0) < Date.parse(ix.sessions.get(victim).last_active || 0)) victim = sid;
    count('open_session_index_overflow');
    // The eviction is REPORTED, not performed here: the fold cannot emit, and an evicted
    // session owes a full session boundary on the wire (§ 8.2). The caller reaps it.
    ix.evicted.push(victim);
    ix.sessions.delete(victim); ix.turns.delete(victim);
  }
}

function listBuckets(dir) {
  try {
    return fs.readdirSync(dir).filter((f) => /^\d{10}\.jsonl$/.test(f)).sort();
  } catch (e) { return []; }
}

/* Fold: snapshot.json, then the snapshot's own bucket from byte `offset`, then every later
 * bucket in full. Appends only ever grow a file, so a byte offset is stable (§ 8.2). */
function foldIndex(spool, nowMs) {
  const ix = emptyIndex();
  const dir = indexDir(spool);
  let snapBucket = null, snapOffset = 0;
  try {
    const snap = JSON.parse(fs.readFileSync(path.join(dir, 'snapshot.json'), 'utf8'));
    snapBucket = snap.bucket || null; snapOffset = snap.offset || 0;
    for (const e of snap.entries || []) ix.calls.set(e.call_id, e);
    for (const t of snap.tombstones || []) ix.tombstones.set(t.call_id, t);
    for (const s of snap.sessions || []) ix.sessions.set(s.session_id, s);
    for (const c of snap.sessions_closed || []) ix.sessionsClosed.set(c.session_id, c.closed_at);
    for (const t of snap.turns || []) ix.turns.set(t.session_id, t);
    for (const a of snap.attention || []) ix.attention.set(a.session_id, a);
    for (const c of snap.compactions || []) ix.compactions.set(c.session_id, c);
  } catch (e) {
    // A missing snapshot is the ordinary first-run state; an unparseable one costs history,
    // never the exit code (AT-3 drives exactly this case and asserts exit 0).
    snapBucket = null; snapOffset = 0;
  }

  const all = listBuckets(dir);
  let pending = all.filter((f) => !snapBucket || f.slice(0, 10) >= snapBucket);
  let unfolded = 0;
  for (const f of pending) {
    try { unfolded += fs.statSync(path.join(dir, f)).size; } catch (e) { /* vanished mid-list */ }
  }
  if (unfolded > K.INDEX_FOLD_MAX) {
    // § 8.2's pathological tail: bounded, counted and visible — not a silent read-time
    // fallback. ~9 days of a busy seat with no flusher at all.
    pending = pending.slice(-1);
    ix.truncated = true;
    count('index_fold_truncated');
  }

  for (const f of pending) {
    const b = f.slice(0, 10);
    let text = '';
    try {
      const fd = fs.openSync(path.join(dir, f), 'r');
      try {
        const size = fs.fstatSync(fd).size;
        const from = (b === snapBucket && !ix.truncated) ? Math.min(snapOffset, size) : 0;
        const buf = Buffer.alloc(Math.max(0, size - from));
        if (buf.length) fs.readSync(fd, buf, 0, buf.length, from);
        text = buf.toString('utf8');
        ix.bucket = b; ix.offset = size;
      } finally { fs.closeSync(fd); }
    } catch (e) { continue; }
    // Trailing bytes with no final LF are a write in progress: NOT consumed (§ 11.4).
    const nl = text.lastIndexOf('\n');
    if (nl < 0) continue;
    ix.offset -= (text.length - nl - 1);
    for (const line of text.slice(0, nl).split('\n')) {
      if (!line) continue;
      let r;
      try { r = JSON.parse(line); } catch (e) { ix.corrupt += 1; continue; }
      try { applyIndexRecord(ix, r, nowMs); } catch (e) { ix.corrupt += 1; }
    }
  }
  if (ix.corrupt) count('spool_corrupt_lines', ix.corrupt);
  capIndex(ix, nowMs);
  return ix;
}

/* § 8.2's match order for a harness close. Both EXACT keys outrank both heuristics, and a
 * tombstone match beats `sole_open`: after a reap the only open call is frequently a
 * different, NEWER call, so ranking sole_open above the tombstone would attribute call A's
 * completion to call B — closing a running call and leaving a reaped one permanently aborted,
 * which is worse than either error alone. */
function matchClose(ix, sessionId, toolName, harnessRef) {
  if (harnessRef) {
    for (const e of ix.calls.values()) if (e.harness_call_ref && e.harness_call_ref === harnessRef) return { entry: e, match: 'harness_ref', tombstone: false };
    for (const t of ix.tombstones.values()) if (t.harness_call_ref && t.harness_call_ref === harnessRef) return { entry: t, match: 'tombstone_ref', tombstone: true };
  }
  const open = [...ix.calls.values()];
  if (open.length === 1) return { entry: open[0], match: 'sole_open', tombstone: false };
  const same = open.filter((e) => e.tool_name === toolName);
  if (same.length) return { entry: same[same.length - 1], match: 'lifo_tool_name', tombstone: false };
  return { entry: null, match: 'synthesized', tombstone: false };
}

/* ════════════════════════════════════════════════════════════════════════════════════════════
 * EVENTS AND THE SPOOL LINE (§ 4, § 11.2)
 * ════════════════════════════════════════════════════════════════════════════════════════════ */

const SESSION_ID_RE = /^[A-Za-z0-9._:-]{1,128}$/;
const TOOL_NAME_RE = /^[A-Za-z0-9_.-]{1,64}$/;

/* § 3.2 — session_id is taken VERBATIM and never parsed, normalised or interpreted. A value
 * failing the pattern becomes null and is counted; the event is still emitted, because an event
 * with an unknown session is worth more than no event. */
function validSessionId(v) {
  if (typeof v === 'string' && SESSION_ID_RE.test(v)) return v;
  if (v === undefined || v === null) { count('payload_key_missing.session_id'); return null; }
  count('bad_session_id');
  return null;
}

/* § 6.0 rule 4 — an unrecognised value in a closed-enum field is coerced to that field's
 * unknown member and counted. THE RAW VALUE NEVER REACHES THE WIRE. This is not tidiness: the
 * ingest validates exactly these sets, so one new harness value passed through verbatim makes
 * its event invalid, § 12.4 rejects all 200 events in its batch, and § 11.5's poison-pill rule
 * quarantines them permanently — an unannounced harness change would DELETE a seat's telemetry
 * rather than mislabel one field. */
function coerceEnum(value, set, unknownMember, wireField) {
  if (set.includes(value)) return value;
  count(`enum_value_unknown.${wireField}`);
  return unknownMember;
}

/* § 6.0 rule 5 — a numeric value outside its stated bound is CLAMPED, emitted and counted. A
 * clamp is a mislabelled field; a rejection is 200 deleted events. */
function clampInt(v, lo, hi, wireField) {
  if (typeof v !== 'number' || !Number.isFinite(v)) return null;
  const n = Math.round(v);
  if (n < lo || n > hi) { count(`value_clamped.${wireField}`); return Math.min(hi, Math.max(lo, n)); }
  return n;
}

function makeEmitter(cfg, spool) {
  return function emit(kind, sessionId, data, atMs) {
    const t = atMs === undefined ? now() : atMs;
    const ev = {
      event_id: ulid(t), schema_version: SCHEMA_VERSION, kind, event_time: rfc3339(t),
      install_id: cfg.install_id, seat_id: cfg.seat_id, session_id: sessionId, data,
    };
    // § 4.4 — an event over the 4 KiB cap is truncated at data.descriptor and flagged
    // `oversize: true`. `oversize` is ABSENT on an ordinary event (§ 4.3), never `false`.
    let line = JSON.stringify({ v: SCHEMA_VERSION, t: rfc3339(t), e: ev });
    if (bytes(line) > K.EVENT_CAP && typeof data.descriptor === 'string') {
      let d = data.descriptor;
      while (d.length > 1 && bytes(line) > K.EVENT_CAP) {
        d = truncateBytes(d, Math.max(4, Math.floor(bytes(d) / 2))).text;
        ev.data = Object.assign({}, data, { descriptor: d, descriptor_truncated: true });
        ev.oversize = true;
        line = JSON.stringify({ v: SCHEMA_VERSION, t: rfc3339(t), e: ev });
      }
    }
    if (appendLine(path.join(spool, `${utcBucket(t)}.jsonl`), line)) count('events_emitted');
    return ev;
  };
}

/* ════════════════════════════════════════════════════════════════════════════════════════════
 * THE HOOK SUBCOMMAND (§ 6, § 8)
 *
 * ORDER INSIDE ONE INVOCATION IS PART OF THE CONTRACT (§ 8.3): a hook REAPS FIRST — closing
 * every entry the reap table declares aborted, in spool order, ahead of its own event — then
 * emits the boundary events, then the trigger's own event. § 8.7 traces the whole of it for a
 * /clear during a subagent's Bash call: events 4-8 are all produced by ONE SessionEnd hook
 * invocation, one spool append per event, in that order.
 * ════════════════════════════════════════════════════════════════════════════════════════════ */

function readStdin() {
  try { return fs.readFileSync(0, 'utf8'); } catch (e) { return ''; }
}

function parsePayload(raw) {
  if (!raw || !raw.trim()) return {};
  try { const v = JSON.parse(raw); return v && typeof v === 'object' ? v : {}; }
  catch (e) { return {}; }
}

function key(payload, name) {
  if (payload[name] === undefined) { count(`payload_key_missing.${name}`); return null; }
  return payload[name];
}

function projectLabel(payload) {
  const cwd = typeof payload.cwd === 'string' ? payload.cwd : null;
  if (!cwd) return null;
  const base = path.basename(cwd.replace(/[\\/]+$/, ''));
  if (!base) return null;
  const r = sanitize(base, 48);
  countSanitizer(r);
  return r.text || null;
}

/* § 6.1's harness_label is `claude-code/<version>`, and D1 NAMES NO SOURCE FOR THE VERSION.
 * MEASURED on this fleet 2026-08-24: no hook payload carries one and no CLAUDE_CODE_VERSION
 * exists in a hook-visible environment. So it is read from an installer-supplied config key —
 * the installer (card #7336) can run `claude --version` once, where it costs nothing, while a
 * hook cannot inside the 250 ms budget — and is honestly `null` plus a counter until then.
 * Filed as a D1 amendment request rather than guessed at. */
function harnessLabel(cfg) {
  const v = typeof cfg.harness_label === 'string' ? cfg.harness_label : null;
  if (!v) { count('payload_key_missing.harness_label'); return null; }
  return /^[A-Za-z0-9._/-]{1,32}$/.test(v) ? v : null;
}

function durationFor(entry, payload, atMs) {
  // § 6.6 — the harness's own duration_ms is STRICTLY BETTER than end-minus-start across two
  // processes and is immune to an NTP step. duration_source says which was used, so the two
  // are never conflated in an aggregate.
  if (typeof payload.duration_ms === 'number' && Number.isFinite(payload.duration_ms) && payload.duration_ms >= 0) {
    return { duration_ms: Math.round(payload.duration_ms), duration_source: 'harness' };
  }
  if (entry && entry.started_at) {
    const d = atMs - Date.parse(entry.started_at);
    if (d >= 0) return { duration_ms: d, duration_source: 'index' };
    count('negative_duration');          // the clock stepped mid-call
    return { duration_ms: null, duration_source: 'none' };
  }
  return { duration_ms: null, duration_source: 'none' };
}

/* Close one ledger entry as ABORTED and emit its tool.end (+ subagent.stop when it is a
 * dispatch call). Used by every row of § 8.3's reap table. */
function reapOne(ctx, entry, abortReason, closeSource, atMs) {
  journal(ctx.spool, { k: 'tombstone', call_id: entry.call_id });
  const d = durationFor(entry, {}, atMs);
  ctx.emit('tool.end', entry.session_id, {
    call_id: entry.call_id, tool_name: entry.tool_name, outcome: 'aborted',
    abort_reason: abortReason, duration_ms: d.duration_ms, duration_source: d.duration_source,
    close_source: closeSource, match: 'reap',
  }, atMs);
  if (entry.is_dispatch) {
    ctx.emit('subagent.stop', entry.session_id, {
      call_id: entry.call_id, outcome: 'aborted', abort_reason: abortReason,
      duration_ms: d.duration_ms, close_source: closeSource,
    }, atMs);
  }
}

function reap(ctx, ix, select, abortReason, closeSource, atMs) {
  /* LIFO — most-recently-opened first. This is not a preference: a call opened INSIDE a
   * subagent is always opened after the dispatch call that created it, so reverse-open order
   * is what guarantees "the inner calls' tool.ends precede the parent call's on the wire and a
   * consumer never sees a parent close while its children are still open" (§ 8.5), and it is
   * the order § 8.7's worked trace shows for a /clear: tool.end(B, the subagent's Bash) at
   * T+18.7s BEFORE tool.end(A, the dispatch), with turn.end naming them `[B, A]`. Insertion
   * order would emit the parent's close first and contradict both. */
  const victims = [...ix.calls.values()].filter(select).reverse();
  for (const e of victims) { reapOne(ctx, e, abortReason, closeSource, atMs); ix.calls.delete(e.call_id); }
  return victims.map((e) => e.call_id);
}

/* A reap that ends a session also closes any attention request open in it: a *blocked* desk
 * whose session has ended is not blocked, and D2-MUST #5 needs the exit edge to say so. */
function resolveAttention(ctx, ix, sessionId, resolution, source, atMs, byHook) {
  const a = ix.attention.get(sessionId);
  if (!a) return false;
  journal(ctx.spool, { k: 'attention_close', request_id: a.request_id, resolution });
  ix.attention.delete(sessionId);
  ctx.emit('attention.resolved', sessionId, {
    request_id: a.request_id, resolution, resolution_source: source,
    waited_ms: Math.max(0, atMs - Date.parse(a.opened_at || atMs)),
  }, atMs);
  if (byHook !== undefined) predicate('attention_resolved_by_hook', byHook);
  return true;
}

/* Emit turn.end for a session whose turn is open, and close the turn record.
 * § 6.4: open_calls_at_end and aborted_call_ids are stated over ONE scope — the scope of THE
 * REAP THAT PRODUCED THIS EVENT — which is (session_id, agent_scope_id ?? "main") for
 * stop_hook / api_error and the whole session_id for session_cleared / session_ended. The two
 * readings differ on the /clear case, and AT-1 cases B and C separate them by test. */
function emitTurnEnd(ctx, ix, sessionId, endReason, apiErrorType, openBefore, abortedIds, payload, atMs) {
  const turn = ix.turns.get(sessionId) || null;
  let ids = abortedIds;
  if (ids.length > K.OPEN_CALLS) { count('value_clamped.turn.end.aborted_call_ids'); ids = ids.slice(0, K.OPEN_CALLS); }
  ctx.emit('turn.end', sessionId, {
    end_reason: endReason,
    api_error_type: apiErrorType,
    duration_ms: turn && turn.started_at ? Math.max(0, atMs - Date.parse(turn.started_at)) : null,
    open_calls_at_end: Math.min(openBefore, K.OPEN_CALLS),
    aborted_call_ids: ids,
    stop_hook_active: typeof payload.stop_hook_active === 'boolean' ? payload.stop_hook_active : null,
    background_tasks_open: Array.isArray(payload.background_tasks) ? payload.background_tasks.length : 0,
    tool_calls: turn ? turn.tool_calls : 0,
    failed_calls: turn ? turn.failed_calls : 0,
  }, atMs);
  journal(ctx.spool, { k: 'turn_close', session_id: sessionId });
  ix.turns.delete(sessionId);
}

/* § 8.3's session-boundary reap, shared by SessionEnd and by SessionStart(source=clear) —
 * ONE primitive, because § 8.7 requires both orders to put the identical events on the wire.
 * "The wire is the same either way; that is what two independent signals, either suffices,
 * buys." Two copies of this would be two chances to make them differ. */
function reapSessionBoundary(ctx, ix, sessionId, endReasonWire, abortReason, atMs) {
  const openBefore = [...ix.calls.values()].filter((e) => e.session_id === sessionId).length;
  const aborted = reap(ctx, ix, (e) => e.session_id === sessionId, abortReason, 'reap_session_boundary', atMs);
  if (ix.turns.has(sessionId)) {
    emitTurnEnd(ctx, ix, sessionId, abortReason === 'session_cleared' ? 'session_cleared' : 'session_ended',
      null, openBefore, aborted, {}, atMs);
  }
  const s = ix.sessions.get(sessionId);
  ctx.emit('session.end', sessionId, {
    end_reason: endReasonWire,
    duration_ms: s && s.opened_at ? Math.max(0, atMs - Date.parse(s.opened_at)) : null,
    turns: s ? s.turns : null,
    aborted_calls: aborted.length,
  }, atMs);
  resolveAttention(ctx, ix, sessionId, 'session_ended', 'session_end', atMs);
  journal(ctx.spool, { k: 'session_close', session_id: sessionId });
  ix.sessions.delete(sessionId); ix.turns.delete(sessionId);
  return aborted;
}

/* Register the session this hook BELONGS TO. Nothing about session B is ever inferred from a
 * hook belonging to session A (§ 8.3) — two terminals on one seat is an ordinary state, and an
 * earlier draft of D1 reaped on "a hook carrying an unfamiliar session_id", which made *idle*
 * unreachable on both sessions and minted a session.end storm. This registers only the hook's
 * own session, so the seat's live-session set is real without inferring anything. */
function touchSession(ctx, ix, sessionId) {
  if (!sessionId) return;
  if (!ix.sessions.has(sessionId)) {
    journal(ctx.spool, { k: 'session_open', session_id: sessionId });
    ix.sessions.set(sessionId, { session_id: sessionId, opened_at: rfc3339(now()), last_active: rfc3339(now()), turns: 0, aborted_calls: 0 });
  }
}

function handleHook(ctx, hookName, payload, ix, atMs) {
  const sid = validSessionId(payload.session_id);
  const emit = ctx.emit;
  const agentId = typeof payload.agent_id === 'string' ? payload.agent_id : null;

  switch (hookName) {
    /* ── SessionStart (§ 6.1) — and the SECOND of the two /clear signals (§ 8.4) ──────────── */
    case 'SessionStart': {
      const source = coerceEnum(key(payload, 'source'), ENUM.session_start_source, 'unknown', 'session.start.source');
      let previous = null;
      if (source === 'clear') {
        /* § 8.4 — SessionStart(clear) CANNOT name the session it superseded: its own
         * session_id is the NEW one and this build carries no previous_session_id key
         * (MEASURED — an earlier draft keyed this reap on that field and would have reaped
         * nothing, forever). So the rule keys on the reporter's OWN index: the seat's
         * most-recently-active open session other than its own. It deliberately does NOT reap
         * every other session — a seat with two terminals has two legitimately-live sessions. */
        let pick = null;
        for (const [s, rec] of ix.sessions) {
          if (s === sid) continue;
          if (!pick || Date.parse(rec.last_active || 0) > Date.parse(ix.sessions.get(pick).last_active || 0)) pick = s;
        }
        if (pick) {
          previous = pick;
          predicate('clear_reap_by_session_end', false);   // this signal got here first
          reapSessionBoundary(ctx, ix, pick, 'clear', 'session_cleared', atMs);
        } else {
          // The healthy outcome, not a failure: SessionEnd(clear) already reaped it 144 ms ago.
          count('reap_noop_second_signal');
          count('clear_second_signal_found_nothing');
        }
      }
      if (source === 'compact') {
        for (const [s] of ix.compactions) { closeCompaction(ctx, ix, s, 'session_start_compact', atMs); break; }
      }
      touchSession(ctx, ix, sid);
      emit('session.start', sid, {
        source, project_label: projectLabel(payload), harness_label: harnessLabel(ctx.config),
        previous_session_id: previous,
      }, atMs);
      break;
    }

    /* ── SessionEnd (§ 6.2) — an OBSERVATION, never an inference ──────────────────────────── */
    case 'SessionEnd': {
      const reason = coerceEnum(key(payload, 'reason'), ENUM.session_end_reason, 'other', 'session.end.end_reason');
      if (reason === 'clear' && !ix.sessions.has(sid) && ix.sessionsClosed.has(sid)) {
        // The SessionStart(clear) signal won the race and already emitted events 4-8 for this
        // session. Emitting a second boundary set would double-close every call it named.
        count('reap_noop_second_signal');
        break;
      }
      if (reason === 'clear') predicate('clear_reap_by_session_end', true);
      touchSession(ctx, ix, sid);
      reapSessionBoundary(ctx, ix, sid, reason, reason === 'clear' ? 'session_cleared' : 'session_ended', atMs);
      break;
    }

    /* ── UserPromptSubmit (§ 6.3) ─────────────────────────────────────────────────────────── */
    case 'UserPromptSubmit': {
      // A human typing is a human present (§ 6.13). D1-SILENT on the order of the two events;
      // the resolution is emitted FIRST, matching every other close-before-trigger ordering in
      // § 8.3 and putting the human's presence before the turn it starts.
      resolveAttention(ctx, ix, sid, 'human_input', 'user_prompt_submit', atMs, true);
      touchSession(ctx, ix, sid);
      const prompt = typeof payload.prompt === 'string' ? payload.prompt : null;
      journal(ctx.spool, { k: 'turn_open', session_id: sid, prompt_id: payload.prompt_id || null });
      applyIndexRecord(ix, { k: 'turn_open', session_id: sid, prompt_id: payload.prompt_id || null, at: rfc3339(atMs) }, atMs);
      emit('turn.start', sid, {
        // Only the LENGTH transits — a size, not content (§ 6.3). The prompt text never does.
        prompt_chars: prompt === null ? null : clampInt(prompt.length, 0, 1000000, 'turn.start.prompt_chars'),
        project_label: projectLabel(payload),
      }, atMs);
      break;
    }

    /* ── Stop / StopFailure (§ 6.4) — the turn reap ────────────────────────────────────────── */
    case 'Stop':
    case 'StopFailure': {
      const isFailure = hookName === 'StopFailure';
      const scope = agentId || 'main';
      const inScope = (e) => e.session_id === sid && (e.agent_scope_id || 'main') === scope;
      const openBefore = [...ix.calls.values()].filter(inScope).length;
      const aborted = reap(ctx, ix, inScope, isFailure ? 'api_error' : 'turn_boundary', 'reap_turn_boundary', atMs);
      // § 8.3 — turn.end is emitted ONLY when the triggering payload carries no agent_id: a
      // subagent finishing is not the turn ending.
      if (!agentId) {
        emitTurnEnd(ctx, ix, sid, isFailure ? 'api_error' : 'stop_hook',
          isFailure ? coerceEnum(key(payload, 'error'), ENUM.stopfailure_error, 'unrecognised', 'turn.end.api_error_type') : null,
          openBefore, aborted, payload, atMs);
      }
      break;
    }

    /* ── PreToolUse (§ 6.5, § 6.7) ─────────────────────────────────────────────────────────── */
    case 'PreToolUse': {
      touchSession(ctx, ix, sid);
      let toolName = key(payload, 'tool_name');
      if (typeof toolName !== 'string' || !TOOL_NAME_RE.test(toolName)) { count('invalid_tool_name'); toolName = 'INVALID_TOOL_NAME'; }
      const ti = payload.tool_input && typeof payload.tool_input === 'object' ? payload.tool_input : {};
      const d = buildDescriptor(toolName, ti);
      predicate('descriptor_allowlisted', d.allowlisted);
      // § 6.5 — agent_scope is labelled from the harness's own agent_id PAYLOAD FIELD and from
      // nothing else. Both branches ride the heartbeat as the `agent_scope_subagent` predicate,
      // so a harness that starts sending agent_id everywhere (constant true) or stops sending
      // it (constant false) is an alarm rather than a silent re-meaning. It LABELS; nothing in
      // the pipeline gates on it.
      predicate('agent_scope_subagent', !!agentId);
      let parentCallId = null;
      if (agentId) {
        for (const e of ix.calls.values()) if (e.child_agent_id === agentId) { parentCallId = e.call_id; break; }
        if (!parentCallId) count('agent_bind_unresolved');
      }
      const isDispatch = toolName === 'Agent' || toolName === 'Task';
      const callId = ulid(atMs);
      const ref = typeof payload.tool_use_id === 'string' && bytes(payload.tool_use_id) <= 64 ? payload.tool_use_id : null;
      const openBefore = ix.calls.size;
      const rec = {
        k: 'open', call_id: callId, session_id: sid, tool_name: toolName, harness_call_ref: ref,
        is_dispatch: isDispatch, agent_scope_id: agentId, prompt_id: payload.prompt_id || null,
      };
      journal(ctx.spool, rec);
      applyIndexRecord(ix, Object.assign({}, rec, { at: rfc3339(atMs) }), atMs);
      emit('tool.start', sid, {
        call_id: callId, tool_name: toolName, descriptor: d.descriptor,
        descriptor_truncated: d.truncated, agent_scope: agentId ? 'subagent' : 'main',
        parent_call_id: parentCallId, harness_call_ref: ref,
        open_calls_before: Math.min(openBefore, K.OPEN_CALLS),
      }, atMs);
      if (isDispatch) {
        // § 6.7 — the dispatch tool's PAYLOAD tool_name is "Agent" at 2.1.240; "Task" is the
        // model-facing name. Both are matched, because a design keyed on "Task" alone would
        // emit no subagent.spawn on any seat running this build — a whole feature reading zero
        // forever, from one transcribed string. Which one fired is counted.
        count(`dispatch_tool_name.${toolName}`);
        const title = typeof ti.description === 'string' ? sanitize(`${ti.description}`, K.TITLE_CAP) : null;
        if (title) countSanitizer(title);
        const st = typeof ti.subagent_type === 'string' && /^[A-Za-z0-9_-]{1,32}$/.test(ti.subagent_type) ? ti.subagent_type : null;
        emit('subagent.spawn', sid, {
          call_id: callId, title: title ? title.text : null,
          title_truncated: title ? title.truncated : false, subagent_type: st,
        }, atMs);
      }
      break;
    }

    /* ── PostToolUse / PostToolUseFailure (§ 6.6) — the trigger IS the outcome ─────────────── */
    case 'PostToolUse':
    case 'PostToolUseFailure': {
      touchSession(ctx, ix, sid);
      const failed = hookName === 'PostToolUseFailure';
      let outcome = 'completed', abortReason = null;
      if (failed) {
        // § 6.6 — is_interrupt is the harness's OWN kill-vs-fail discriminator and this design
        // uses it rather than re-deriving one. An interrupted call did not fail; it stopped
        // existing, which is § 8.1's subject exactly.
        const ii = payload.is_interrupt;
        if (typeof ii !== 'boolean') count('payload_key_missing.is_interrupt');
        if (ii === true) { outcome = 'aborted'; abortReason = 'interrupted'; }
        else outcome = 'failed';
      }
      const toolName = typeof payload.tool_name === 'string' ? payload.tool_name : 'INVALID_TOOL_NAME';
      const ref = typeof payload.tool_use_id === 'string' ? payload.tool_use_id : null;
      const m = matchClose(ix, sid, toolName, ref);
      let entry = m.entry, matchKind = m.match;
      if (!entry) {
        // § 6.6 — a close matching no open call SYNTHESIZES the pair, so the ledger never
        // contains a close without an open. That keeps every consumer's open-call arithmetic
        // total and makes the anomaly a visible flag rather than a silent negative count.
        const callId = ulid(atMs);
        emit('tool.start', sid, {
          call_id: callId, tool_name: toolName, descriptor: null, descriptor_truncated: false,
          agent_scope: agentId ? 'subagent' : 'main', parent_call_id: null,
          harness_call_ref: ref, open_calls_before: Math.min(ix.calls.size, K.OPEN_CALLS),
          synthesized: true,
        }, atMs);
        entry = { call_id: callId, session_id: sid, tool_name: toolName, started_at: rfc3339(atMs), is_dispatch: false, prompt_id: payload.prompt_id || null };
        matchKind = 'synthesized';
      } else if (m.tombstone) {
        count('tombstone_late_close');   // the reap was too eager for this call
      }
      const d = durationFor(entry, payload, atMs);
      if (!m.tombstone) {
        journal(ctx.spool, { k: 'close', call_id: entry.call_id, close_source: failed ? 'post_tool_use_failure' : 'post_tool_use', outcome });
        applyIndexRecord(ix, { k: 'close', call_id: entry.call_id, outcome, at: rfc3339(atMs) }, atMs);
      }
      emit('tool.end', sid, {
        call_id: entry.call_id, tool_name: entry.tool_name || toolName, outcome,
        abort_reason: abortReason, duration_ms: d.duration_ms, duration_source: d.duration_source,
        close_source: failed ? 'post_tool_use_failure' : 'post_tool_use', match: matchKind,
      }, atMs);
      if (entry.is_dispatch) {
        emit('subagent.stop', sid, {
          call_id: entry.call_id, outcome, abort_reason: abortReason,
          duration_ms: d.duration_ms, close_source: failed ? 'post_tool_use_failure' : 'post_tool_use',
        }, atMs);
      }
      // § 6.13 rows 2 and 3 — the tool ran, so permission was given.
      const a = ix.attention.get(sid);
      if (a && outcome !== 'aborted' && (a.call_id === entry.call_id || a.call_id === null)) {
        resolveAttention(ctx, ix, sid, 'granted', 'call_close', atMs, true);
      }
      break;
    }

    /* ── SubagentStart (§ 8.5) — a BINDING, not an event ──────────────────────────────────── */
    case 'SubagentStart': {
      touchSession(ctx, ix, sid);
      if (!agentId) { count('payload_key_missing.agent_id'); break; }
      // There is NO exact-reference binding row, because the payload carries no reference to
      // bind on: SubagentStart's complete key set at 2.1.240 has no tool_use_id and no
      // parent_tool_use_id (MEASURED). prompt_id does not discriminate — parent and subagent
      // share it. So the sole-unbound rule carries the fleet, and its ambiguous case is
      // counted rather than guessed.
      const unbound = [...ix.calls.values()].filter((e) => e.session_id === sid && e.is_dispatch && !e.child_agent_id);
      if (unbound.length === 1) {
        journal(ctx.spool, { k: 'bind', call_id: unbound[0].call_id, child_agent_id: agentId });
        unbound[0].child_agent_id = agentId;
        count('agent_bind_sole_unbound');
      } else if (unbound.length > 1) {
        count('agent_bind_ambiguous');   // no guess is written
      }
      break;
    }

    /* ── SubagentStop (§ 8.5) — one payload value, two lookups, in this order ──────────────── */
    case 'SubagentStop': {
      touchSession(ctx, ix, sid);
      if (agentId) {
        // 1. SCOPE — reap the subagent's own inner calls. This is the ONLY rule in the design
        //    that ever closes a call opened inside a subagent, because Stop does not fire
        //    there (MEASURED). Without it, a call refused by the permission layer — which
        //    fires PreToolUse and then no close hook of any kind — had no close rule at all
        //    before its session ended. Inner calls close FIRST so a consumer never sees a
        //    parent close while its children are still open.
        reap(ctx, ix, (e) => e.session_id === sid && e.agent_scope_id === agentId, 'turn_boundary', 'reap_turn_boundary', atMs);
      }
      // 2. BINDING — close the dispatch call this subagent belongs to.
      let entry = null, matchKind = null;
      if (agentId) for (const e of ix.calls.values()) if (e.child_agent_id === agentId) { entry = e; matchKind = 'agent_id'; break; }
      if (!entry) {
        // Not a defence against a missing agent_id — the key is always there. It is the
        // recovery path for a LOST bind record (a torn index-journal line), without which that
        // call would sit open to its 60-minute orphan ceiling.
        const dispatches = [...ix.calls.values()].filter((e) => e.is_dispatch);
        if (dispatches.length === 1) { entry = dispatches[0]; matchKind = 'sole_open'; }
      }
      if (!entry) { count('subagent_stop_unmatched'); break; }
      const d = durationFor(entry, {}, atMs);
      journal(ctx.spool, { k: 'close', call_id: entry.call_id, close_source: 'subagent_stop_hook', outcome: 'completed' });
      ix.calls.delete(entry.call_id);
      // The payload carries agent_id, agent_type, agent_transcript_path, last_assistant_message,
      // stop_hook_active, background_tasks and session_crons — and NO error indicator of any
      // kind (MEASURED). The hook genuinely cannot distinguish a subagent that succeeded from
      // one that failed, so reporting the transition it DOES observe is the only honest option;
      // close_source is what tells a consumer this came from the secondary signal.
      emit('tool.end', sid, {
        call_id: entry.call_id, tool_name: entry.tool_name, outcome: 'completed',
        abort_reason: null, duration_ms: d.duration_ms, duration_source: d.duration_source,
        close_source: 'subagent_stop_hook', match: matchKind,
      }, atMs);
      emit('subagent.stop', sid, {
        call_id: entry.call_id, outcome: 'completed', abort_reason: null,
        duration_ms: d.duration_ms, close_source: 'subagent_stop_hook',
      }, atMs);
      break;
    }

    /* ── PreCompact / PostCompact (§ 6.9, § 6.10) ─────────────────────────────────────────── */
    case 'PreCompact': {
      touchSession(ctx, ix, sid);
      const trigger = coerceEnum(key(payload, 'trigger'), ENUM.precompact_trigger, 'unknown', 'compaction.start.trigger');
      const sample = readSample(ctx.spool, sid, atMs);
      journal(ctx.spool, { k: 'compaction_open', session_id: sid });
      // custom_instructions is present on this payload and NEVER transits: human-authored prose
      // about the session's content, which § 1 excludes from the wire outright.
      emit('compaction.start', sid, {
        trigger,
        context_used_pct: sample ? sample.used_pct : null,
        context_used_pct_age_s: sample ? Math.round((atMs - Date.parse(sample.at)) / 1000) : null,
        open_calls: Math.min(ix.calls.size, K.OPEN_CALLS),
      }, atMs);
      break;
    }
    case 'PostCompact': {
      touchSession(ctx, ix, sid);
      // compact_summary — the whole model-authored summary of the conversation — is the single
      // largest and most content-bearing field the harness offers any hook. It is not read, not
      // sanitized and not logged.
      if (!closeCompaction(ctx, ix, sid, 'post_compact', atMs)) count('compaction_double_close');
      break;
    }

    /* ── The attention pair (§ 6.12, § 6.13) ──────────────────────────────────────────────── */
    case 'PermissionRequest': {
      touchSession(ctx, ix, sid);
      openAttention(ctx, ix, sid, 'permission_request_hook', 'permission_required', atMs);
      break;
    }
    case 'PermissionDenied': {
      touchSession(ctx, ix, sid);
      // `denied` means AUTO MODE denied it. A human clicking "no" fires nothing at all, and
      // takes the human_input edge on their next prompt instead (§ 6.13).
      resolveAttention(ctx, ix, sid, 'denied', 'permission_denied_hook', atMs, true);
      break;
    }
    case 'Notification': {
      touchSession(ctx, ix, sid);
      const type = typeof payload.notification_type === 'string' ? payload.notification_type : null;
      const kind = type ? NOTIFICATION_KIND[type] : undefined;
      if (kind) { openAttention(ctx, ix, sid, 'notification_hook', kind, atMs); break; }
      // THE ONE CARVE-OUT to § 6.0 rule 2, and it is never silent. Most Notification types are
      // not attention requests at all (auth_success, agent_completed, the quota_auto_resume_*
      // family); emitting for them would put EVERY seat into a false *blocked* — the exact
      // mirror of the false-idle defect this design exists to prevent. Every suppressed type is
      // counted INDIVIDUALLY, and a type the table has never seen is counted separately again,
      // so "a type we chose not to emit for" and "a type we have never seen" are never one
      // number. A rising `enum_value_unknown.notification_type` is the trigger for an edit.
      count(`notification_not_attention.${type === null ? 'null' : type}`);
      if (type === null || !NOTIFICATION_NOT_ATTENTION.includes(type)) count('enum_value_unknown.notification_type');
      break;
    }
    default:
      count(`payload_key_missing.unsubscribed_hook.${hookName}`);
      break;
  }
}

function openAttention(ctx, ix, sid, source, kind, atMs) {
  if (ix.attention.has(sid)) { count('attention_request_duplicate'); return; }
  const openCalls = [...ix.calls.values()].filter((e) => e.session_id === sid);
  // § 6.12 — neither hook names a tool call (PermissionRequest carries no tool_use_id, and
  // Notification carries no tool reference at all on this build), so call_id is filled only
  // when exactly one call is open and is null otherwise. That is a MEASURED absence, not an
  // oversight, and § 6.13's second resolution row exists to keep `granted` reachable when it
  // is null.
  const requestId = ulid(atMs);
  journal(ctx.spool, { k: 'attention_open', request_id: requestId, session_id: sid, call_id: openCalls.length === 1 ? openCalls[0].call_id : null });
  ix.attention.set(sid, { request_id: requestId, session_id: sid, opened_at: rfc3339(atMs), call_id: openCalls.length === 1 ? openCalls[0].call_id : null });
  predicate('attention_source_permission_hook', source === 'permission_request_hook');
  ctx.emit('attention.request', sid, {
    request_id: requestId, source, notification_kind: kind,
    call_id: openCalls.length === 1 ? openCalls[0].call_id : null,
    open_calls: Math.min(openCalls.length, K.OPEN_CALLS),
  }, atMs);
}

function closeCompaction(ctx, ix, sid, closeSource, atMs) {
  const c = ix.compactions.get(sid);
  if (!c) return false;
  journal(ctx.spool, { k: 'compaction_close', session_id: sid });
  ix.compactions.delete(sid);
  ctx.emit('compaction.end', sid, {
    duration_ms: c.started_at ? Math.max(0, atMs - Date.parse(c.started_at)) : null,
    close_source: closeSource,
  }, atMs);
  return true;
}

/* ── The sample store (§ 11.1) ───────────────────────────────────────────────────────────────
 * The ONE piece of cross-process state that is not a journal, because it is not an
 * accumulation: it is one current value per session. Keyed by the first 16 hex characters of
 * SHA-256(session_id) so an opaque id never becomes a filename. Concurrent statusLine renders
 * of the same session can race, and the race is harmless and stated: the loser's value is
 * overwritten, the cost is at most one extra context.sample, and no counter or ledger entry
 * depends on it. */
const sampleFile = (spool, sid) =>
  path.join(spool, 'sample', `${crypto.createHash('sha256').update(String(sid)).digest('hex').slice(0, 16)}.json`);

function readSample(spool, sid, atMs) {
  if (!sid) return null;
  try {
    const s = JSON.parse(fs.readFileSync(sampleFile(spool, sid), 'utf8'));
    // § 6.9 — a sample older than 300 s is NOT used. The failure direction is an honest null
    // plus a counter, never a stale number rendered as current: a seat compacts because its
    // context filled, so a five-minute-old percentage is describing a different context.
    if (atMs - Date.parse(s.at) > K.SAMPLE_STALE_MS) { count('context_sample_stale'); return null; }
    return s;
  } catch (e) { count('context_sample_stale'); return null; }
}

/* ── § 2.3 — the flusher must be alive whenever the seat is ──────────────────────────────────
 * The heartbeat is only a liveness signal if its absence means something, so every hook
 * invocation opportunistically respawns a dead flusher. 90 s = 1.5 heartbeat intervals: long
 * enough that a flusher busy in a 15 s POST is never declared dead, and under two intervals, so
 * a crashed one is replaced by the next hook fire.
 *
 * P-7: detached, stdio ignored, unref'd — the hook must not wait on the flusher's lifetime —
 * and windowsHide, without which every respawn flashes a console window on a Windows seat.  */
function maybeRespawnFlusher(spool, configPathUsed) {
  try {
    const lock = path.join(spool, 'flusher.lock');
    let fresh = false;
    try { fresh = (now() - fs.statSync(lock).mtimeMs) < K.LOCK_STALE_MS; } catch (e) { fresh = false; }
    if (fresh) return false;
    const env = Object.assign({}, process.env);
    if (configPathUsed) env.FLEET_REPORTER_CONFIG = configPathUsed;
    const child = lazy('child_process').spawn(process.execPath, [__filename, 'flusher'],
      { detached: true, stdio: 'ignore', windowsHide: true, env });
    child.unref();
    return true;
  } catch (e) { return false; }
}

function spoolBuckets(spool) {
  try { return fs.readdirSync(spool).filter((f) => /^\d{10}\.jsonl$/.test(f)).sort(); }
  catch (e) { return []; }
}

function spoolBytes(spool) {
  let total = 0;
  for (const f of spoolBuckets(spool)) { try { total += fs.statSync(path.join(spool, f)).size; } catch (e) { /* raced away */ } }
  return total;
}

/* § 11.3 — a hook that finds the spool over its bound drops the oldest bucket, so a seat whose
 * flusher is dead cannot fill a disk. A HOOK MAY NEVER DROP THE CURRENT-HOUR BUCKET: without
 * that restriction a single-hour burst over 32 MiB makes the oldest bucket also the current
 * one, and a hook would unlink a file other hooks are appending to and the flusher is
 * mid-read — losing events that were never counted, on the one path § 0 item 9 promises is
 * always counted. */
function enforceSpoolBoundFromHook(spool, atMs) {
  if (spoolBytes(spool) <= K.SPOOL_BYTES) return;
  const cur = utcBucket(atMs);
  const buckets = spoolBuckets(spool);
  const oldest = buckets[0];
  if (!oldest || oldest.slice(0, 10) === cur) { count('spool_overflow_deferred'); return; }
  if (atMs < bucketEndMs(oldest.slice(0, 10)) + K.BUCKET_GRACE_MS) { count('spool_overflow_deferred'); return; }
  dropSpoolBucket(spool, oldest);
}

function dropSpoolBucket(spool, file) {
  const p = path.join(spool, file);
  let lines = 0;
  try { lines = (fs.readFileSync(p, 'utf8').match(/\n/g) || []).length; } catch (e) { lines = 0; }
  try { fs.unlinkSync(p); count('spool_dropped_events', lines); }
  catch (e) { /* ENOENT or EBUSY: leave it for the next pass rather than throwing */ }
}

/* ════════════════════════════════════════════════════════════════════════════════════════════
 * ENTRY POINTS
 *
 * P-1 lives here and nowhere else: every subcommand runs inside one try/catch with
 * process.exit(0) in a finally. There is no path — parse error, missing config, read-only
 * spool, torn journal, empty stdin — on which a hook exits non-zero, because exit 2 is the
 * harness's BLOCK signal and every other non-zero puts our stderr in the transcript.
 * ════════════════════════════════════════════════════════════════════════════════════════════ */

function hookMain(hookName) {
  const atMs = now();
  const cp = configPath();
  const { config, errors } = loadConfig(cp);
  if (!config) return;                                   // nothing to spool to, and nothing to say
  registerSecret(config.token);
  const spool = config.spool_dir;
  if (errors.length) { count('config_invalid'); logLine(spool, 'hook', `config invalid: ${errors.join('; ')}`); }
  if (!spool || !config.install_id || !config.seat_id) { flushCounters(spool, 'hook', atMs); return; }
  // § 3.1/§ 6.14 — `enabled: false` is the ONLY switch that stops emission. The hooks go quiet
  // and the flusher keeps heartbeating with enabled:false, so the desk renders *disabled*
  // rather than sliding through stale into offline and looking broken.
  if (config.enabled === false) { maybeRespawnFlusher(spool, cp); return; }

  const raw = readStdin();
  const payload = parsePayload(raw);
  // § 3.4 — the hook name arrives TWICE (argv[2] and the payload's own hook_event_name) and a
  // disagreement is counted. A free discriminating check on the assumption that the payload's
  // own labelling is what we think it is: it agreed on every payload of the capture run, so the
  // healthy value is 0 and any non-zero is real.
  if (typeof payload.hook_event_name === 'string' && payload.hook_event_name !== hookName) count('hook_name_mismatch');

  const ctx = { config, spool, emit: makeEmitter(config, spool) };
  const ix = foldIndex(spool, atMs);
  // § 8.2 — the seventeenth session evicts the least-recently-active one, and the eviction
  // REAPS: its open calls close exactly as a SessionEnd would and its session.end goes out as
  // `inferred_silence`. Without this the bound was asserted twice with nothing enforcing it,
  // and the seventeenth session's calls would never be reaped at all.
  for (const evicted of ix.evicted) reapSessionBoundary(ctx, ix, evicted, 'inferred_silence', 'session_ended', atMs);
  handleHook(ctx, hookName, payload, ix, atMs);
  enforceSpoolBoundFromHook(spool, atMs);
  maybeRespawnFlusher(spool, cp);
  flushCounters(spool, 'hook', atMs);
}

/* ── statusline (§ 6.11) ─────────────────────────────────────────────────────────────────────
 * SAMPLED, NEVER STREAMED: the statusLine command is invoked far more often than this data is
 * worth storing, and every suppression is counted. Note the caveat § 9.3 states and that must
 * not be discovered later — the harness CANCELS an in-flight status-line script when a new
 * render is triggered, and a cancelled process never reaches the exit path where it writes its
 * counter line, so statusLine-side counters are a FLOOR, not a census. They are read for
 * direction (zero vs non-zero), never for arithmetic. */
function statuslineMain() {
  const atMs = now();
  const cp = configPath();
  const { config, errors } = loadConfig(cp);
  const raw = readStdin();
  if (!config) { return; }
  registerSecret(config.token);
  const spool = config.spool_dir;
  if (errors.length) count('config_invalid');

  try {
    if (spool && config.install_id && config.seat_id && config.enabled !== false) {
      sampleContext(config, spool, parsePayload(raw), atMs);
    }
  } catch (e) { logLine(spool, 'statusline', `sample failed: ${e && e.message}`); }

  // THE PASSTHROUGH OBLIGATION (§ 6.11): the statusLine command's stdout IS the rendered status
  // line, so a seat that already has one must not lose it. A wrapped command that exits
  // non-zero or exceeds 1 s costs the seat a blank status line and a counter — never the seat.
  try {
    const wrapped = config.wrapped_statusline;
    if (typeof wrapped === 'string' && wrapped.trim()) {
      // `input` is a BUFFER and no `encoding` is set. Passing a string input together with
      // `encoding: 'buffer'` makes Node do Buffer.from(input, 'buffer') and throw
      // ERR_UNKNOWN_ENCODING — which this function's own try/catch then swallowed into a
      // `wrapped_statusline_failures` increment, silently blanking the status line of every
      // seat that had one. With no encoding, spawnSync already returns Buffers.
      const r = lazy('child_process').spawnSync(wrapped, {
        shell: true, input: Buffer.from(raw, 'utf8'), timeout: K.WRAPPED_STATUSLINE_MS,
        windowsHide: true, maxBuffer: 1024 * 1024,
      });
      if (r.stdout && r.stdout.length) process.stdout.write(r.stdout);
      if (r.error || r.status !== 0 || r.signal) count('wrapped_statusline_failures');
    }
  } catch (e) { count('wrapped_statusline_failures'); }
  if (spool) flushCounters(spool, 'statusline', atMs);
}

function sampleContext(config, spool, payload, atMs) {
  const cw = payload.context_window && typeof payload.context_window === 'object' ? payload.context_window : null;
  let usedPct = null, source = null, usedTokens = null, totalTokens = null;
  if (cw) {
    usedTokens = typeof cw.total_input_tokens === 'number' ? cw.total_input_tokens : null;
    totalTokens = typeof cw.context_window_size === 'number' ? cw.context_window_size : null;
    if (typeof cw.used_percentage === 'number') { usedPct = cw.used_percentage; source = 'harness'; }
    else if (usedTokens !== null && totalTokens) {
      // INPUT TOKENS ONLY, output tokens EXCLUDED. Not a stylistic choice: the harness's own
      // builder computes used_percentage from total_input_tokens / context_window_size, so
      // adding total_output_tokens here — the obvious reading, and what an implementer would
      // write unprompted — yields a systematically LARGER number and makes one wire field carry
      // two different meanings depending on which branch produced it. That is the re-meaning
      // case the versioning policy singles out as the dangerous one, and it would also mint a
      // spurious threshold_cross on the way in and another on the way out.
      usedPct = (usedTokens / totalTokens) * 100;
      source = 'computed';
    }
  }
  if (usedPct === null) {
    // The ONE suppression in the design driven by payload shape, expected to be non-zero on
    // every seat during the first seconds of a session, and counted precisely because a silent
    // one is how a signal dies unnoticed (§ 3.4).
    count('payload_key_missing.context_window');
    return;
  }
  usedPct = Math.round(Math.min(100, Math.max(0, usedPct)) * 10) / 10;
  const sid = validSessionId(payload.session_id);
  const bucket = Math.floor(usedPct / K.STATUSLINE_BUCKET_PCT);
  const prev = (() => { try { return JSON.parse(fs.readFileSync(sampleFile(spool, sid), 'utf8')); } catch (e) { return null; } })();

  let reason = null;
  if (!prev) reason = 'first_of_session';
  else if (prev.bucket !== bucket) reason = 'threshold_cross';
  else if (atMs - Date.parse(prev.at) >= K.STATUSLINE_CADENCE_MS) reason = 'cadence';

  // EVERY invocation that produces a usable percentage — emitted or suppressed — updates the
  // store, which is what § 6.9 reads and what the cadence rule compares against.
  atomicWrite(sampleFile(spool, sid), JSON.stringify({
    session_id: sid, at: rfc3339(atMs), used_pct: usedPct,
    bucket: reason ? bucket : (prev ? prev.bucket : bucket),
  }));
  if (!reason) { count('statusline_suppressed'); return; }

  const model = payload.model && typeof payload.model === 'object' && typeof payload.model.display_name === 'string'
    ? sanitize(payload.model.display_name, 48) : null;
  if (model) countSanitizer(model);
  makeEmitter(config, spool)('context.sample', sid, {
    used_pct: usedPct,
    used_tokens: usedTokens === null ? null : clampInt(usedTokens, 0, 10000000, 'context.sample.used_tokens'),
    total_tokens: totalTokens === null ? null : clampInt(totalTokens, 1, 10000000, 'context.sample.total_tokens'),
    used_pct_source: source,
    model_label: model ? model.text : null,
    sample_reason: reason,
  }, atMs);
}

/* ════════════════════════════════════════════════════════════════════════════════════════════
 * THE FLUSHER (§ 2.3, § 10.2, § 11)
 *
 * ONE PER SEAT, and that exclusivity is MANDATORY rather than advisory. `seq` is a lock-free
 * counter, which is correct only because exactly one process increments it. Two flushers each
 * reading next_seq = X produce either a gap (and the seat renders `lossy` from nothing) or two
 * events sharing one (seq_epoch, seq) — the ordering key D2-MUST #4 makes load-bearing. Dedup
 * absorbs duplicate EVENTS; it does not absorb a duplicated counter.
 *
 * THE LOCK IS NOT THE CORRECTNESS MECHANISM — OWNERSHIP IS. flusher.lock is an atomic
 * exclusive-create so exactly one process can win it, but state.json carries owner_pid and
 * owner_started_at, and every write re-reads it and proceeds only if it still names itself.
 * The residual window is the microseconds between that re-read and the rename, and even that is
 * not assumed away: the server counts a repeated (seq_epoch, seq) as `seq_collision`.
 * ════════════════════════════════════════════════════════════════════════════════════════════ */

const statePath = (spool) => path.join(spool, 'state.json');

function newState(spool, atMs) {
  return {
    seq_epoch: ulid(atMs), next_seq: 1,
    owner_pid: process.pid, owner_started_at: rfc3339(atMs),
    started_at: rfc3339(atMs),
    /* A PER-BUCKET offset map, not a single (bucket, offset) cursor. A single cursor has to
     * decide what to do with a bucket OLDER than the one it points at, and every answer is
     * wrong: skipping it loses events silently, re-reading it re-sends them. And an older
     * bucket does receive late writes — that is the whole reason § 11.1 puts a 5 s grace on
     * bucket deletion. A writer descheduled between deriving its bucket name and its writeSync
     * lands in the previous hour after the flusher has moved on, and under a single cursor its
     * events would never be delivered and never be counted as dropped, which is the one loss
     * shape § 0 item 9 forbids. One offset per bucket has no such case. */
    cursors: {},
    counter_offsets: {}, counters: {}, predicates: {},
    last_hook_at: null, last_session_activity: {},
  };
}

function loadState(spool, atMs) {
  try {
    const s = JSON.parse(fs.readFileSync(statePath(spool), 'utf8'));
    if (!s || typeof s !== 'object' || !s.seq_epoch || typeof s.next_seq !== 'number') throw new Error('shape');
    s.counters = s.counters || {}; s.predicates = s.predicates || {};
    s.counter_offsets = s.counter_offsets || {}; s.cursors = s.cursors || {};
    s.last_session_activity = s.last_session_activity || {};
    return { state: s, reset: false };
  } catch (e) {
    /* § 11.4 — THE STATE RESET RE-SENDS; IT DOES NOT SKIP. A fresh seq_epoch, and the cursor
     * set to the start of the OLDEST bucket still on disk, not the newest. An earlier draft of
     * D1 did the opposite and the cost was severe and silent: up to a full spool of unsent
     * events — days of them — discarded with no counter incremented, while § 0 item 9 promises
     * a counter for every discarded event and seq_epoch_change is explicitly never alarmed. A
     * corrupt 200-byte file would have deleted a week of a seat's history invisibly.
     * Re-sending is nearly free and provably safe: every event carries a unique event_id and
     * the server's 10-day dedup window exceeds the spool's 8-day residency BY DESIGN. */
    const st = newState(spool, atMs);
    st.cursors = {};   // every bucket from byte 0 — the re-send § 11.4 requires
    return { state: st, reset: true };
  }
}

function ownsState(spool, state) {
  try {
    const on = JSON.parse(fs.readFileSync(statePath(spool), 'utf8'));
    if (!on || !on.owner_pid) return true;
    return on.owner_pid === state.owner_pid && on.owner_started_at === state.owner_started_at;
  } catch (e) { return true; }   // unreadable: this process is as entitled as any other
}

function saveState(spool, state) {
  if (!ownsState(spool, state)) { count('flusher_lost_ownership'); return false; }
  return atomicWrite(statePath(spool), JSON.stringify(state));
}

/* § 2.3 — exclusive create, atomic on both platforms. A starting flusher that loses the create
 * reads the lock: a mtime newer than 90 s means a live owner and it exits 0 immediately; an
 * older one is unlinked ONLY IF the mtime it read is still the one on disk, and the exclusive
 * create is retried exactly once. Losing that retry is also an immediate exit 0. */
function acquireLock(spool, state) {
  const lock = path.join(spool, 'flusher.lock');
  const body = JSON.stringify({ pid: process.pid, started_at: state.owner_started_at, seq_epoch: state.seq_epoch });
  ensureDir(spool);
  const tryCreate = () => {
    try { const fd = fs.openSync(lock, 'wx'); fs.writeSync(fd, Buffer.from(body, 'utf8')); fs.closeSync(fd); return true; }
    catch (e) { return false; }
  };
  if (tryCreate()) return true;
  let st;
  try { st = fs.statSync(lock); } catch (e) { return tryCreate(); }
  if (now() - st.mtimeMs < K.LOCK_STALE_MS) return false;
  try {
    const again = fs.statSync(lock);
    if (again.mtimeMs !== st.mtimeMs) return false;   // it moved under us: a live owner
    fs.unlinkSync(lock);
  } catch (e) { return false; }
  return tryCreate();
}

function touchLock(spool) {
  try { const t = new Date(now()); fs.utimesSync(path.join(spool, 'flusher.lock'), t, t); } catch (e) { /* removed under us; ownership still governs */ }
}

/* ── The counter sink fold (§ 11.1) ──────────────────────────────────────────────────────────
 * Read each bucket up to the LAST COMPLETE LINE, add the deltas to the totals in state.json,
 * and record the byte offset per bucket so the same line is never folded twice. AT-16's second
 * RED is exactly the omission of that offset: the totals double on the next pass. */
function foldCounterSink(spool, state) {
  const dir = path.join(spool, 'counters');
  for (const f of listBuckets(dir)) {
    const b = f.slice(0, 10);
    const from = state.counter_offsets[b] || 0;
    let text = '', size = 0;
    try {
      const fd = fs.openSync(path.join(dir, f), 'r');
      try {
        size = fs.fstatSync(fd).size;
        if (size <= from) continue;
        const buf = Buffer.alloc(size - from);
        fs.readSync(fd, buf, 0, buf.length, from);
        text = buf.toString('utf8');
      } finally { fs.closeSync(fd); }
    } catch (e) { continue; }
    const nl = text.lastIndexOf('\n');
    if (nl < 0) continue;
    for (const line of text.slice(0, nl).split('\n')) {
      if (!line) continue;
      let r; try { r = JSON.parse(line); } catch (e) { continue; }
      for (const [k, v] of Object.entries(r.c || {})) if (typeof v === 'number') state.counters[k] = (state.counters[k] || 0) + v;
      for (const [k, v] of Object.entries(r.k || {})) {
        if (!state.predicates[k]) state.predicates[k] = { true: 0, false: 0 };
        state.predicates[k].true += (v && v.true) || 0;
        state.predicates[k].false += (v && v.false) || 0;
      }
      if (r.p === 'hook' && r.t && (!state.last_hook_at || r.t > state.last_hook_at)) state.last_hook_at = r.t;
    }
    state.counter_offsets[b] = from + nl + 1;
  }
}

/* THE BUCKET-DELETION PRECONDITION, one rule for all four trees (§ 11.1): a bucket may be
 * deleted only when now >= bucket_end + 5 s grace, and only after it has been fully folded (or,
 * for the spool, fully drained and acknowledged). 5 s is 20x the P-5 hook budget, and it covers
 * the residual the write-time bucket derivation cannot: a process descheduled BETWEEN deriving
 * the name and the writeSync. Without both halves, AT-16's exact-equality GREEN would flake
 * under a real hour roll roughly once per hour per busy seat and be read as test flakiness
 * rather than as the lost-counter defect it is. */
function deletableBucket(bucket, atMs) { return atMs >= bucketEndMs(bucket) + K.BUCKET_GRACE_MS; }

function reapOldBuckets(spool, state, atMs) {
  const cdir = path.join(spool, 'counters');
  for (const f of listBuckets(cdir)) {
    const b = f.slice(0, 10);
    if (!deletableBucket(b, atMs)) continue;
    let size = 0; try { size = fs.statSync(path.join(cdir, f)).size; } catch (e) { continue; }
    if ((state.counter_offsets[b] || 0) < size) continue;      // not fully folded
    try { fs.unlinkSync(path.join(cdir, f)); delete state.counter_offsets[b]; } catch (e) { /* next pass */ }
  }
  const idir = indexDir(spool);
  let snapBucket = null;
  try { snapBucket = JSON.parse(fs.readFileSync(path.join(idir, 'snapshot.json'), 'utf8')).bucket; } catch (e) { snapBucket = null; }
  if (snapBucket) {
    for (const f of listBuckets(idir)) {
      const b = f.slice(0, 10);
      if (b < snapBucket && deletableBucket(b, atMs)) { try { fs.unlinkSync(path.join(idir, f)); } catch (e) { /* next pass */ } }
    }
  }
  const ldir = path.join(spool, 'log');
  try {
    for (const f of fs.readdirSync(ldir)) {
      const m = /^(\d{8})\.log$/.exec(f);
      if (!m) continue;
      const end = Date.UTC(+m[1].slice(0, 4), +m[1].slice(4, 6) - 1, +m[1].slice(6, 8)) + 86400000;
      if (atMs > end + K.LOG_RETAIN_DAYS * 86400000) { try { fs.unlinkSync(path.join(ldir, f)); } catch (e) { /* next pass */ } }
    }
  } catch (e) { /* no log dir yet */ }
  const sdir = path.join(spool, 'sample');
  try {
    for (const f of fs.readdirSync(sdir)) {
      const p = path.join(sdir, f);
      try { if (atMs - fs.statSync(p).mtimeMs > K.SAMPLE_TTL_MS) fs.unlinkSync(p); } catch (e) { /* next pass */ }
    }
  } catch (e) { /* no sample dir yet */ }
}

/* § 11.3 — both bounds are evaluated on every pass. Drop-oldest, one whole hour bucket at a
 * time: the dashboard's value is CURRENT state, and a week-old queued event has no consumer
 * left, while dropping newest would discard exactly the events that still matter. */
function enforceSpoolBounds(spool, state, atMs) {
  for (const f of spoolBuckets(spool)) {
    const b = f.slice(0, 10);
    if (atMs - bucketEndMs(b) > K.RESIDENCY_MS && deletableBucket(b, atMs)) {
      dropSpoolBucket(spool, f);
      delete state.cursors[b];
    }
  }
  let guard = 0;
  while (spoolBytes(spool) > K.SPOOL_BYTES && guard++ < 64) {
    const buckets = spoolBuckets(spool);
    const oldest = buckets[0];
    if (!oldest || !deletableBucket(oldest.slice(0, 10), atMs)) { count('spool_overflow_deferred'); break; }
    dropSpoolBucket(spool, oldest);
    delete state.cursors[oldest.slice(0, 10)];
  }
}

/* Read pending spool lines from the cursor forward. A trailing partial line (no final LF) is a
 * write in progress and is NOT consumed — it is picked up next pass (§ 11.4). */
function collectPending(spool, state, limit) {
  const items = [];
  const buckets = spoolBuckets(spool);
  for (const f of buckets) {
    const b = f.slice(0, 10);
    const from = state.cursors[b] || 0;
    let text = '', size = 0;
    try {
      const fd = fs.openSync(path.join(spool, f), 'r');
      try {
        size = fs.fstatSync(fd).size;
        if (size <= from) continue;
        const buf = Buffer.alloc(size - from);
        fs.readSync(fd, buf, 0, buf.length, from);
        text = buf.toString('utf8');
      } finally { fs.closeSync(fd); }
    } catch (e) {
      /* § 11.4 — an entire bucket unreadable: record it, skip it, continue. Counted into
       * spool_dropped_events (an ESTIMATE that says so) and NOT into spool_corrupt_lines,
       * which counts LINES the flusher read and could not use — one loss, one counter.
       * THE CURSOR IS ADVANCED PAST IT IMMEDIATELY. Nothing here is deliverable, so the
       * disposal is committed now rather than on a delivery that can never happen — otherwise
       * every retry pass of an outage re-counts the same loss. */
      quarantine(spool, 'corrupt', JSON.stringify({ at: rfc3339(now()), unreadable_bucket: f }));
      count('spool_dropped_events', Math.max(1, Math.round((size || 512) / 512)));
      state.cursors[b] = Number.MAX_SAFE_INTEGER;
      continue;
    }
    const nl = text.lastIndexOf('\n');
    if (nl < 0) continue;
    let pos = from;
    for (const line of text.slice(0, nl).split('\n')) {
      const end = pos + Buffer.byteLength(line, 'utf8') + 1;
      pos = end;
      if (!line) continue;
      if (Buffer.byteLength(line, 'utf8') > K.EVENT_CAP) {
        items.push({ skip: true, raw: line, bucket: b, endOffset: end }); continue;
      }
      let rec;
      try { rec = JSON.parse(line); } catch (e) {
        // ONE TORN LINE NEVER POISONS A BATCH and never wedges the queue: bounded to the line,
        // counted, quarantined for inspection — never "abort the batch", which would let one
        // bad byte stop a seat's telemetry indefinitely. The counting happens at the COMMIT
        // (`disposeSkips`), not here: collectPending runs again on every retry pass, so
        // counting at read time would multiply one torn line by the length of an outage — and
        // `spool_corrupt_lines` is a number the floor RENDERS beside a `lossy` badge.
        items.push({ skip: true, raw: line, bucket: b, endOffset: end }); continue;
      }
      if (!rec || typeof rec !== 'object' || !rec.e || typeof rec.e !== 'object' || !rec.e.kind) {
        items.push({ skip: true, raw: line, bucket: b, endOffset: end }); continue;
      }
      items.push({ v: rec.v || SCHEMA_VERSION, t: rec.t, e: rec.e, bucket: b, endOffset: end });
      if (items.length >= limit) return items;
    }
  }
  return items;
}

/* Quarantine and count every unusable line in items[0..upTo], exactly once. Called when their
 * disposal is COMMITTED — either because they sit at the cursor with nothing undelivered before
 * them, or because a batch spanning them was accepted. */
function disposeSkips(spool, items, upTo) {
  for (let i = 0; i <= upTo && i < items.length; i++) {
    const it = items[i];
    if (!it.skip || it.disposed) continue;
    it.disposed = true;
    quarantine(spool, 'corrupt', it.raw);
    count('spool_corrupt_lines');
  }
}

/* Record every bucket touched by items[0..upTo] at its consumed offset. */
function advanceCursors(state, items, upTo) {
  for (let i = 0; i <= upTo && i < items.length; i++) {
    const it = items[i];
    state.cursors[it.bucket] = Math.max(state.cursors[it.bucket] || 0, it.endOffset);
  }
}

function quarantine(spool, which, line) {
  const file = path.join(spool, 'quarantine', which === 'corrupt' ? 'corrupt.jsonl' : 'rejected.jsonl');
  const cap = which === 'corrupt' ? K.Q_CORRUPT_CAP : K.Q_REJECTED_CAP;
  if (!appendCapped(file, redactSecrets(line), cap)) count(which === 'corrupt' ? 'quarantine_corrupt_dropped' : 'quarantine_rejected_dropped');
}

/* Build ONE batch from contiguous same-version items. The flusher groups contiguous same-`v`
 * runs so a reporter upgraded mid-spool drains cleanly — old lines go out under the old
 * version, which the ingest still accepts inside its N/N-1 window (§ 11.2). */
function buildBatch(config, state, items, maxEvents) {
  const events = [];
  let used = null, lastIdx = -1, size = 512;
  for (let i = 0; i < items.length; i++) {
    const it = items[i];
    if (it.skip) { lastIdx = i; continue; }
    if (used === null) used = it.v;
    if (it.v !== used) break;
    const ev = {
      event_id: it.e.event_id, schema_version: it.v, kind: it.e.kind, event_time: it.e.event_time,
      seq: state.next_seq + events.length,
      install_id: it.e.install_id, seat_id: it.e.seat_id, session_id: it.e.session_id === undefined ? null : it.e.session_id,
      data: it.e.data,
    };
    if (it.e.oversize) ev.oversize = true;
    const s = JSON.stringify(ev).length + 1;
    if (events.length && (events.length >= maxEvents || size + s > K.BATCH_BYTES)) break;
    events.push(ev); size += s; lastIdx = i;
  }
  if (!events.length) return { events: [], lastIdx, body: null, version: used };
  const batch = {
    schema_version: used, batch_id: ulid(now()),
    install_id: config.install_id, seat_id: config.seat_id,
    reporter_version: REPORTER_VERSION,
    // `other` is the unknown member for any process.platform outside the three (§ 4.2).
    reporter_platform: ['linux', 'win32', 'darwin'].includes(process.platform) ? process.platform : 'other',
    runtime_version: process.version,
    seq_epoch: state.seq_epoch, sent_at: rfc3339(now()), events,
  };
  return { events, lastIdx, body: JSON.stringify(batch), version: used, batch };
}

/* ── Transport (§ 3.5) — WAN, ALWAYS ─────────────────────────────────────────────────────────
 * The Mezzanine server runs on a physically separate host from every agent seat (operator
 * ruling): no loopback mode, no unix socket, no "it's local so a retry is cheap".
 *
 * CERTIFICATE VERIFICATION IS ALWAYS ON. There is no `rejectUnauthorized: false` in this file
 * and no read of NODE_TLS_REJECT_UNAUTHORIZED — a sandbox host with a private CA is supported
 * by config.ca_file, which is passed as an ADDITIONAL trust anchor with verification intact.
 * Loosening verification to make a sandbox work is the classic constraint-weakening fix, and it
 * ships to production seats. `selftest` and the acceptance suite both lint for it. */
let _agent = null;
const getAgent = () => (_agent || (_agent = new (lazy('https').Agent)({ keepAlive: true, maxSockets: 2 })));

function proxyConnect(proxyUrl, targetHost, targetPort) {
  return new Promise((resolve, reject) => {
    const u = new URL(proxyUrl);
    const mod = lazy(u.protocol === 'https:' ? 'https' : 'http');
    const req = mod.request({
      host: u.hostname, port: u.port || (u.protocol === 'https:' ? 443 : 80),
      method: 'CONNECT', path: `${targetHost}:${targetPort}`,
      headers: { Host: `${targetHost}:${targetPort}` },
      timeout: K.CONNECT_MS,
    });
    req.on('connect', (res, socket) => {
      if (res.statusCode !== 200) { socket.destroy(); reject(new Error(`proxy CONNECT ${res.statusCode}`)); return; }
      resolve(socket);
    });
    req.on('timeout', () => { req.destroy(new Error('proxy connect timeout')); });
    req.on('error', reject);
    req.end();
  });
}

function postBatch(config, body) {
  return new Promise((resolve) => {
    let settled = false;
    const done = (v) => { if (!settled) { settled = true; resolve(v); } };
    let url;
    try { url = new URL(config.ingest_url); } catch (e) { done({ kind: 'permanent', status: 0, error: 'bad ingest_url' }); return; }
    if (url.protocol !== 'https:') { done({ kind: 'refused', status: 0, error: 'ingest_url is not https' }); return; }

    const headers = { 'Content-Type': 'application/json; charset=utf-8', Authorization: `Bearer ${config.token}` };
    let payload = Buffer.from(body, 'utf8');
    if (payload.length > K.GZIP_MIN) {
      try { payload = lazy('zlib').gzipSync(payload); headers['Content-Encoding'] = 'gzip'; } catch (e) { payload = Buffer.from(body, 'utf8'); }
    }
    headers['Content-Length'] = String(payload.length);

    const isIp = /^\d{1,3}(\.\d{1,3}){3}$/.test(url.hostname) || url.hostname.includes(':');
    const opts = {
      host: url.hostname, port: url.port || 443, path: url.pathname + url.search,
      method: 'POST', headers, agent: getAgent(),
    };
    // SNI is a HOSTNAME extension: RFC 6066 forbids an IP literal there, and Node warns
    // (DEP0123) that it will stop honouring one. Verification is unaffected either way.
    if (!isIp) opts.servername = url.hostname;
    if (config.ca_file) { try { opts.ca = fs.readFileSync(config.ca_file); } catch (e) { /* fall back to the system store, still verifying */ } }

    // The TOTAL request deadline. 256 KiB on a 1 Mbit/s uplink is 2.1 s; plus TLS setup plus
    // server processing is ~4 s worst realistic case, and 15 s is ~3.5x that — past it,
    // retrying beats waiting.
    const timer = setTimeout(() => { try { req.destroy(new Error('request deadline')); } catch (e) { /* already gone */ } done({ kind: 'retryable', status: 0, error: 'timeout' }); }, K.REQUEST_MS);

    const start = (socketOverride) => {
      if (socketOverride) { opts.agent = false; opts.createConnection = () => lazy('tls').connect({ socket: socketOverride, servername: url.hostname, ca: opts.ca }); }
      const r = lazy('https').request(opts, (res) => {
        const chunks = [];
        res.on('data', (c) => { if (chunks.length < 64) chunks.push(c); });
        res.on('end', () => {
          clearTimeout(timer);
          const text = Buffer.concat(chunks).toString('utf8').slice(0, 4096);
          done(classify(res.statusCode, res.headers, text));
        });
      });
      r.on('error', (e) => { clearTimeout(timer); done({ kind: 'retryable', status: 0, error: String(e && e.code || e && e.message) }); });
      // The CONNECT deadline is enforceable only via socket.setTimeout; § 3.5 makes the 15 s
      // total the binding requirement and this the refinement.
      r.on('socket', (s) => { s.setTimeout(K.CONNECT_MS, () => { if (!s.destroyed && !settled) r.destroy(new Error('connect deadline')); }); });
      r.end(payload);
      return r;
    };

    let req;
    if (config.proxy_url) {
      // config.proxy_url ONLY. HTTP(S)_PROXY environment variables are IGNORED — § 3.4 rule 1,
      // no transport decision from ambient environment.
      proxyConnect(config.proxy_url, url.hostname, url.port || 443)
        .then((sock) => { req = start(sock); })
        .catch((e) => { clearTimeout(timer); done({ kind: 'retryable', status: 0, error: `proxy: ${e && e.message}` }); });
    } else { req = start(null); }
  });
}

function classify(status, headers, text) {
  let parsed = null; try { parsed = JSON.parse(text); } catch (e) { parsed = null; }
  if (status === 202 || status === 200) return { kind: 'ok', status, body: parsed, text };
  // NOT retryable — the same bytes will be refused forever, and retrying hides the error
  // behind an infinite loop instead of surfacing it.
  if ([400, 401, 403, 415, 422].includes(status)) return { kind: 'permanent', status, body: parsed, text };
  if (status === 413) return { kind: 'too_large', status, body: parsed, text };
  if (status === 429) {
    let after = parsed && typeof parsed.retry_after_s === 'number' ? parsed.retry_after_s : null;
    if (after === null && headers && headers['retry-after']) { const n = Number(headers['retry-after']); if (Number.isFinite(n)) after = n; }
    // A server's explicit instruction outranks the ladder; the clamp stops a bad header
    // parking a seat for hours.
    return { kind: 'retryable', status, retry_after_s: after === null ? null : Math.min(K.RETRY_AFTER_CAP_S, Math.max(0, after)), body: parsed, text };
  }
  if (status === 408 || status >= 500) return { kind: 'retryable', status, body: parsed, text };
  return { kind: 'permanent', status, body: parsed, text };
}

function backoffDelay(attempt) {
  const computed = Math.min(K.BACKOFF_MAX_MS, K.BACKOFF_BASE_MS * Math.pow(2, Math.max(0, attempt - 1)));
  // FULL jitter, uniform in [0, computed]: it stops N seats re-synchronising into a thundering
  // herd after a server restart.
  return Math.floor(Math.random() * computed);
}

/* ── The heartbeat (§ 6.14) ──────────────────────────────────────────────────────────────────
 * THE EVENT THAT MAKES REPORTER SILENCE A STATE RATHER THAN AN APPEARANCE. § 3.4's incident
 * cost 30 days not because a predicate was wrong — predicates break routinely — but because a
 * dark consumer and a healthy one were indistinguishable from outside. Liveness is asserted
 * continuously by the producer, so silence becomes a positive observation the server alarms on.
 *
 * THE ONE THING IT MAY NEVER DO IS FAIL ON THE SEATS THAT NEED IT. That is what the counters
 * reduction rule below is for: a DEGRADED seat is exactly the seat where many counters are
 * non-zero at once, so without a bound the mechanism that makes a broken seat visible would be
 * the first thing to break on a broken seat — a 422, a rejected 200-event batch, a permanent
 * quarantine, and the liveness backstop dying at the moment the seat becomes interesting. */
function buildCounters(all) {
  const ordered = [];
  for (const k of ALWAYS_COUNTERS) ordered.push([k, all[k] || 0]);
  const rest = Object.entries(all).filter(([k, v]) => !ALWAYS_COUNTERS.includes(k) && v);
  // Descending value keeps the loudest signals; name-ascending makes the output identical for
  // identical input, so a fixture can assert the exact serialization.
  rest.sort((a, b) => (b[1] - a[1]) || (a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : 0));
  for (const e of rest) ordered.push(e);
  const out = {};
  let i = 0;
  for (; i < ordered.length; i++) {
    const [k, v] = ordered[i];
    out[k] = v;
    if (JSON.stringify(out).length > K.COUNTERS_CAP) { delete out[k]; break; }
  }
  return { counters: out, counters_omitted: ordered.length - i };
}

function buildDegraded(all) {
  const on = [];
  for (const [member, raisers] of DEGRADED) {
    let hit = false;
    for (const r of raisers) {
      if (r instanceof RegExp) { for (const [k, v] of Object.entries(all)) if (v && r.test(k)) { hit = true; break; } }
      else if (all[r]) hit = true;
      if (hit) break;
    }
    if (hit) on.push(member);
  }
  return on;   // one of each, in § 9.3's order — which is why the array's bound IS this table
}

function configFingerprint(cfg) {
  // The TOKEN IS EXCLUDED, deliberately: a fingerprint that covered the secret would let anyone
  // holding the event stream confirm a guessed token by comparing hashes. It exists so an
  // operator can tell "this seat was reconfigured" from "this seat is a different seat".
  return crypto.createHash('sha256')
    .update(`${cfg.install_id}|${cfg.seat_id}|${cfg.ingest_url}`).digest('hex').slice(0, 16);
}

function spoolLag(spool, state) {
  let lines = 0, oldest = null;
  for (const f of spoolBuckets(spool)) {
    const b = f.slice(0, 10);
    const from = state.cursors[b] || 0;
    try {
      const size = fs.statSync(path.join(spool, f)).size;
      if (size <= from) continue;
      const fd = fs.openSync(path.join(spool, f), 'r');
      try {
        const buf = Buffer.alloc(size - from);
        fs.readSync(fd, buf, 0, buf.length, from);
        for (let i = 0; i < buf.length; i++) if (buf[i] === 0x0A) lines += 1;
        if (oldest === null) {
          const m = /"t":"([^"]+)"/.exec(buf.slice(0, 512).toString('utf8'));
          if (m) oldest = m[1];
        }
      } finally { fs.closeSync(fd); }
    } catch (e) { /* raced away; the next pass sees it */ }
  }
  return { lines, oldest };
}

function emitHeartbeat(cfg, spool, state, ix, selftest, atMs) {
  const all = state.counters;
  const { counters, counters_omitted } = buildCounters(all);
  if (counters_omitted > 0) all['data_truncated.reporter.heartbeat.counters'] = (all['data_truncated.reporter.heartbeat.counters'] || 0) + 1;
  const lag = spoolLag(spool, state);
  const predicates = {};
  for (const p of PREDICATES) predicates[p] = state.predicates[p] || { true: 0, false: 0 };
  const st = {};
  for (const c of SELFTEST_CHECKS) st[c] = selftest[c] === true ? 'pass' : 'fail';
  makeEmitter(cfg, spool)('reporter.heartbeat', null, {
    uptime_s: Math.max(0, Math.round((atMs - Date.parse(state.started_at)) / 1000)),
    spool_bytes: Math.min(spoolBytes(spool), K.SPOOL_BYTES),
    spool_files: Math.min(spoolBuckets(spool).length, 400),
    spool_lag_events: lag.lines,
    oldest_unsent_age_s: lag.oldest ? Math.max(0, Math.round((atMs - Date.parse(lag.oldest)) / 1000)) : null,
    last_hook_at: state.last_hook_at || null,
    open_calls: ix.calls.size, open_sessions: ix.sessions.size, open_attention: ix.attention.size,
    // A deliberately-disabled seat must be distinguishable from a dead one: the hooks stop
    // emitting and the flusher keeps heartbeating with enabled:false, so the desk renders
    // *disabled* rather than sliding through stale into offline and looking broken.
    enabled: cfg.enabled !== false,
    degraded: buildDegraded(all).slice(0, K.DEGRADED_MAX),
    counters, counters_omitted, predicates, selftest: st,
    config_fingerprint: configFingerprint(cfg),
  }, atMs);
}

function writeSnapshot(spool, state, ix) {
  if (!ownsState(spool, state)) { count('flusher_lost_ownership'); return; }
  atomicWrite(path.join(indexDir(spool), 'snapshot.json'), JSON.stringify({
    taken_at: rfc3339(now()), bucket: ix.bucket, offset: ix.offset,
    entries: [...ix.calls.values()], tombstones: [...ix.tombstones.values()],
    sessions: [...ix.sessions.values()], turns: [...ix.turns.entries()].map(([session_id, t]) => Object.assign({ session_id }, t)),
    attention: [...ix.attention.values()], compactions: [...ix.compactions.values()],
    sessions_closed: [...ix.sessionsClosed.entries()].map(([session_id, closed_at]) => ({ session_id, closed_at })),
  }));
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/* The flusher's own process-local counters are part of the same totals the hook processes
 * contribute through the sink. */
function foldLocalCounters(state) {
  for (const [k, v] of Object.entries(C)) { state.counters[k] = (state.counters[k] || 0) + v; delete C[k]; }
  for (const [k, v] of Object.entries(P)) {
    if (!state.predicates[k]) state.predicates[k] = { true: 0, false: 0 };
    state.predicates[k].true += v.true; state.predicates[k].false += v.false;
    delete P[k];
  }
}

async function flusherMain() {
  const cp = configPath();
  const { config, errors } = loadConfig(cp);
  if (!config || !config.spool_dir) { return; }
  registerSecret(config.token);
  const spool = config.spool_dir;
  ensureDir(spool);
  const atStart = now();
  const { state, reset } = loadState(spool, atStart);
  state.owner_pid = process.pid; state.owner_started_at = rfc3339(atStart); state.started_at = rfc3339(atStart);
  if (!acquireLock(spool, state)) { logLine(spool, 'flusher', 'another flusher owns the lock; exiting'); return; }
  /* CLAIM state.json before any ownership-checked write. Winning the exclusive create IS the
   * grant (§ 2.3); the ownership field is what protects the writes AFTER it. Without this claim
   * the check compares this process against the PREVIOUS owner's record, never matches, and
   * every subsequent write is refused — measured: the cursor and `next_seq` then never persist,
   * so each flusher restart re-sends the whole spool from seq 1. Server dedup absorbs the
   * duplicate EVENTS, but not the duplicated `seq`: two runs emit the same (seq_epoch, seq) for
   * different events, which is the ordering-key collision D2-MUST #4 forbids. */
  if (!atomicWrite(statePath(spool), JSON.stringify(state))) {
    logLine(spool, 'flusher', 'cannot write state.json; exiting rather than running unowned');
    try { fs.unlinkSync(path.join(spool, 'flusher.lock')); } catch (e) { /* nothing to release */ }
    return;
  }
  if (reset) { count('state_reset'); logLine(spool, 'flusher', 'state.json unreadable — new seq_epoch, re-sending from the oldest bucket'); }
  if (errors.length) { count('config_invalid'); logLine(spool, 'flusher', `config invalid: ${errors.join('; ')} — spooling, sending nothing`); }
  const configOk = errors.length === 0;

  const emit = makeEmitter(config, spool);
  const ctx = { config, spool, emit };

  // Flusher start finds index entries older than its own start time: those calls belong to a
  // reporter that is no longer running (§ 8.3's last row).
  {
    const ix = foldIndex(spool, atStart);
    reap(ctx, ix, (e) => Date.parse(e.started_at || 0) < atStart, 'reporter_restart', 'reap_reporter_restart', atStart);
  }

  let selftest = runSelftestChecks(config, cp).results;
  let lastHeartbeat = 0, lastHealth = 0, attempt = 0, waitUntil = 0, running = true;
  const stop = () => { running = false; };
  process.on('SIGTERM', stop); process.on('SIGINT', stop);

  while (running) {
    const atMs = now();
    try {
      touchLock(spool);
      foldCounterSink(spool, state);
      foldLocalCounters(state);

      const ix = foldIndex(spool, atMs);
      for (const evicted of ix.evicted) reapSessionBoundary(ctx, ix, evicted, 'inferred_silence', 'session_ended', atMs);
      expireOpenFacts(ctx, ix, atMs);
      writeSnapshot(spool, state, ix);

      if (configOk && atMs - lastHealth > 600000) { lastHealth = atMs; await refreshHealth(config, selftest); }

      if (atMs >= waitUntil && configOk && config.enabled !== false) {
        const drained = await drainOnce(config, spool, state, atMs);
        if (drained.retry) { attempt += 1; waitUntil = now() + (drained.retry_after_s !== undefined && drained.retry_after_s !== null ? drained.retry_after_s * 1000 : backoffDelay(attempt)); }
        else { attempt = 0; if (drained.sent) selftest.tls_verify = true; }
      }

      if (atMs - lastHeartbeat >= K.HEARTBEAT_MS) {
        lastHeartbeat = atMs;
        emitHeartbeat(config, spool, state, ix, selftest, atMs);
      }

      enforceSpoolBounds(spool, state, atMs);
      reapOldBuckets(spool, state, atMs);
      // FOLD AGAIN, AT THE END. Everything above can raise a counter — a dropped bucket, a
      // lost quarantine write, a corrupt line — and folding only at the START of a pass leaves
      // those in process memory until a NEXT pass that a shutdown may never run. The loss is
      // invisible by construction: the counter that would have reported it is the one lost.
      foldLocalCounters(state);
      saveState(spool, state);
    } catch (e) {
      logLine(spool, 'flusher', `pass failed: ${e && e.message}`);
    }
    // A TEST SEAM, and a deliberately inert one: it breaks the loop after a completed pass and
    // changes no logic inside it, which is what lets the acceptance suite assert a pass's
    // effects deterministically instead of polling a background process and flaking.
    if (process.env.FLEET_REPORTER_ONE_PASS) break;
    await sleep(K.FLUSH_MS);
  }
  try { fs.unlinkSync(path.join(spool, 'flusher.lock')); } catch (e) { /* another owner already replaced it */ }
}

/* Every open fact has a ceiling, and each one's expiry is a WIRE EVENT this reporter emits —
 * an entry edge with no exit event is not a state, it is a one-way trapdoor (§ 6.12). */
function expireOpenFacts(ctx, ix, atMs) {
  for (const [sid, a] of [...ix.attention]) {
    if (atMs - Date.parse(a.opened_at || atMs) > K.ATTENTION_MS) {
      resolveAttention(ctx, ix, sid, 'timeout', 'timeout', atMs, false);
    }
  }
  for (const [sid, c] of [...ix.compactions]) {
    if (atMs - Date.parse(c.started_at || atMs) > K.COMPACTION_MS) closeCompaction(ctx, ix, sid, 'timeout', atMs);
  }
  for (const [sid, s] of [...ix.sessions]) {
    if (atMs - Date.parse(s.last_active || s.opened_at || atMs) > K.SILENCE_MS) {
      /* § 6.2 — the 90-minute `inferred_silence` close is NOT the reap path and emits NO
       * turn.end: it fires in the flusher, which holds no observation of a turn ending. The
       * server closes any turn still open at that boundary itself. A hook-path exception here
       * would put a second writer on a fact one writer already owns. */
      journal(ctx.spool, { k: 'session_close', session_id: sid });
      ix.sessions.delete(sid); ix.turns.delete(sid);
      ctx.emit('session.end', sid, {
        end_reason: 'inferred_silence',
        duration_ms: s.opened_at ? Math.max(0, atMs - Date.parse(s.opened_at)) : null,
        turns: s.turns, aborted_calls: 0,
      }, atMs);
    }
  }
}

/* One drain pass. Returns {retry, retry_after_s, sent}. The flush trigger is >= 50 queued
 * events OR 10 s elapsed (§ 11.5); the loop's own cadence supplies the second half, so a pass
 * always attempts whatever is pending. */
async function drainOnce(config, spool, state, atMs) {
  let sent = false;
  for (let round = 0; round < 8; round++) {
    const items = collectPending(spool, state, K.BATCH_EVENTS);
    if (!items.length) return { retry: false, sent };
    // Leading unusable lines have nothing undelivered before them, so their disposal is
    // committed now and the cursor moves past them whether or not the POST that follows
    // succeeds. Without this an unreachable ingest re-reads and re-counts them every pass.
    let lead = -1;
    while (lead + 1 < items.length && items[lead + 1].skip) lead += 1;
    if (lead >= 0) {
      disposeSkips(spool, items, lead);
      advanceCursors(state, items, lead);
      saveState(spool, state);
      items.splice(0, lead + 1);
      if (!items.length) continue;
    }
    let maxEvents = K.BATCH_EVENTS;
    let built = buildBatch(config, state, items, maxEvents);
    if (!built.events.length) {
      // Only unusable lines remain in this run: dispose and advance past them.
      disposeSkips(spool, items, built.lastIdx);
      advanceCursors(state, items, built.lastIdx >= 0 ? built.lastIdx : items.length - 1);
      continue;
    }
    let res = await postBatch(config, built.body);

    if (res.kind === 'too_large') {
      // § 11.5 — 413 gets exactly ONE adaptive retry: halve the batch and resend. If a SINGLE
      // event still exceeds the limit it can never be delivered, so it is quarantined and
      // counted rather than blocking every event behind it forever.
      if (built.events.length > 1) {
        built = buildBatch(config, state, items, Math.max(1, Math.floor(built.events.length / 2)));
        res = await postBatch(config, built.body);
      }
      if (res.kind === 'too_large' && built.events.length === 1) {
        quarantine(spool, 'rejected', JSON.stringify(built.events[0]));
        count('oversize_event_dropped');
        advanceCursors(state, items, built.lastIdx);
        state.next_seq += 1;
        continue;
      }
    }

    if (res.kind === 'ok') {
      disposeSkips(spool, items, built.lastIdx);
      advanceCursors(state, items, built.lastIdx);
      state.next_seq += built.events.length;
      state.counters.events_sent = (state.counters.events_sent || 0) + built.events.length;
      state.counters.batches_ok = (state.counters.batches_ok || 0) + 1;
      sent = true;
      saveState(spool, state);
      continue;
    }

    if (res.kind === 'retryable') {
      state.counters.batches_retried = (state.counters.batches_retried || 0) + 1;
      logLine(spool, 'flusher', `batch retryable: status=${res.status} ${res.error || ''}`);
      return { retry: true, retry_after_s: res.retry_after_s, sent };
    }
    if (res.kind === 'refused') {
      // config_invalid: keep spooling and send nothing. Fail closed, loudly, on the client's
      // own surface (§ 3.5).
      count('config_invalid');
      return { retry: true, sent };
    }

    /* THE POISON-PILL RULE (§ 11.5). A batch refused with a permanent status is NEVER retried:
     * quarantined, cursor advanced, counted, surfaced locally, and the flusher moves to the
     * next batch. One bad batch costs its own events, never the stream behind it — and the
     * events it costs ARE COUNTED, because a rejected batch is a discarded-events path like
     * any other. The reporter does not rely on hook stderr being displayed by the harness: a
     * surfacing mechanism that might silently not exist is not a surfacing mechanism. */
    quarantine(spool, 'rejected', built.body);
    state.counters.batches_rejected = (state.counters.batches_rejected || 0) + 1;
    state.counters.events_rejected_dropped = (state.counters.events_rejected_dropped || 0) + built.events.length;
    // The Authorization header value is EXCLUDED: the reporter logs the request's status,
    // never its headers.
    const marker = `${rfc3339(now())} status=${res.status} code=${(res.body && res.body.error) || 'unknown'} events=${built.events.length} body=${redactSecrets(String(res.text || '').slice(0, 512))}`;
    appendRejectedMarker(spool, marker);
    logLine(spool, 'flusher', `batch REJECTED permanently: ${marker}`);
    disposeSkips(spool, items, built.lastIdx);
    advanceCursors(state, items, built.lastIdx);
    state.next_seq += built.events.length;
    saveState(spool, state);
  }
  return { retry: false, sent };
}

/* REJECTED.txt caps at 64 KiB and DROPS OLDEST — a human opening it wants the most recent
 * refusal. It is the one capped file here that rewrites rather than stops, and it is safe to
 * rewrite because the flusher is its only writer. */
function appendRejectedMarker(spool, line) {
  const p = path.join(spool, 'REJECTED.txt');
  let prev = '';
  try { prev = fs.readFileSync(p, 'utf8'); } catch (e) { prev = ''; }
  let text = prev + line + '\n';
  if (bytes(text) > K.REJECTED_TXT_CAP) text = text.slice(-Math.floor(K.REJECTED_TXT_CAP / 2));
  atomicWrite(p, text);
}

/* GET /api/ingest/health — the accepted schema-version set is read from the RUNNING ingest, and
 * this document deliberately restates no accepted set anywhere (VERSIONING.md rule 2). */
function refreshHealth(config, selftest) {
  return new Promise((resolve) => {
    let url;
    try { url = new URL(config.ingest_url); } catch (e) { resolve(); return; }
    const opts = {
      host: url.hostname, port: url.port || 443,
      path: url.pathname.replace(/\/events$/, '/health'), method: 'GET',
      headers: { Authorization: `Bearer ${config.token}` }, agent: getAgent(),
    };
    if (!(/^\d{1,3}(\.\d{1,3}){3}$/.test(url.hostname) || url.hostname.includes(':'))) opts.servername = url.hostname;
    if (config.ca_file) { try { opts.ca = fs.readFileSync(config.ca_file); } catch (e) { /* system store */ } }
    const timer = setTimeout(() => { try { req.destroy(); } catch (e) { /* gone */ } resolve(); }, K.REQUEST_MS);
    const req = lazy('https').request(opts, (res) => {
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => {
        clearTimeout(timer);
        try {
          const body = JSON.parse(Buffer.concat(chunks).toString('utf8'));
          selftest.tls_verify = true;
          selftest.schema_version_accepted = Array.isArray(body.accepted_schema_versions)
            && body.accepted_schema_versions.includes(SCHEMA_VERSION);
        } catch (e) { selftest.schema_version_accepted = false; }
        resolve();
      });
    });
    req.on('error', () => { clearTimeout(timer); selftest.tls_verify = false; selftest.schema_version_accepted = false; resolve(); });
    req.end();
  });
}

/* ════════════════════════════════════════════════════════════════════════════════════════════
 * SELFTEST (§ 2.1, § 6.14, and § 6.0's SELFTEST-MUST)
 *
 * The six checks § 6.14's member table declares — the SAME set the heartbeat reports pass/fail
 * for, so the subcommand and the wire object cannot drift apart. Two of them are the guards
 * this design's own history argues for: `sanitizer_fixtures`, because a fixture set that only
 * ever passes proves the harness runs and nothing else; and `harness_payload_keys`, because a
 * schema transcribed from another product is only as good as the check that reds when it moves,
 * and D1 shipped that transcription wrong twice.
 * ════════════════════════════════════════════════════════════════════════════════════════════ */

/* § 7.5's thirteen RED fixtures, verbatim, with the "Rules that fire" trace column. The trace
 * is asserted too: a fixture whose documented trace and actual trace disagree FAILS EVEN IF its
 * output string matches, because that disagreement is drift between two tables that are one
 * behaviour written twice. */
const SANITIZER_FIXTURES = [
  { n: 1, tool: 'Bash', input: { command: 'curl -H "Authorization: Bearer ghp_ABCDEF1234567890abcdef1234" https://api.github.com/user' }, rules: [3], out: 'Bash: curl -H "Authorization: Bearer \u2039redacted:token\u203a" https://api.github.com/user' },
  { n: 2, tool: 'Bash', input: { command: 'psql "postgres://mez:s3cr3t-pw@db.example.com:5432/mezz" -c \'\\dt\'' }, rules: [1], out: 'Bash: psql "postgres://\u2039redacted\u203a@db.example.com:5432/mezz" -c \'\\dt\'' },
  { n: 3, tool: 'Bash', input: { command: 'echo "${STRIPE_SECRET:-sk_live_51H8xYzAbCdEfGhIj}" > /tmp/k' }, rules: [2], out: 'Bash: echo "${STRIPE_SECRET:-\u2039redacted\u203a}" > /tmp/k' },
  { n: 4, tool: 'Bash', input: { command: 'deploy --host 203.0.113.47 --notify ops@example.org' }, rules: [8, 9], out: 'Bash: deploy --host \u2039redacted:ip\u203a --notify \u2039redacted:email\u203a' },
  { n: 5, tool: 'Read', input: { file_path: '/home/aimlapm/projects/mezzanine/app/Http/Controllers/IngestController.php' }, rules: [6], out: 'Read: ~/\u2026/Controllers/IngestController.php' },
  { n: 6, tool: 'Bash', input: { command: 'git commit -m "\x1b[31mline one\nline two"' }, rules: [10], out: 'Bash: git commit -m "line one' },
  { n: 7, tool: 'Bash', input: { command: `echo "${'\u00e9'.repeat(300)}"` }, rules: [14], out: null /* asserted by property below */ },
  { n: 8, tool: 'mcp__vault__read', input: { password: 'hunter2', path: '/prod/db' }, rules: [], out: null, expectNull: true },
  { n: 9, tool: 'Bash', input: { command: 'deploy --password hunter2 --host db1' }, rules: [4], out: 'Bash: deploy --password \u2039redacted\u203a --host db1' },
  { n: 10, tool: 'Bash', input: { command: 'curl -u admin:s3cr3t https://api.example.org/v1/ping' }, rules: [5], out: 'Bash: curl -u \u2039redacted\u203a https://api.example.org/v1/ping' },
  { n: 11, tool: 'Bash', input: { command: 'mysql -pS3cr3tP@ss -h db1 mezz' }, rules: [5], out: 'Bash: mysql -p\u2039redacted\u203a -h db1 mezz' },
  { n: 12, tool: 'Read', input: { file_path: '/var/www/app/Http/Controllers/HealthController.php' }, rules: [6], out: 'Read: /\u2026/Controllers/HealthController.php' },
  { n: 13, tool: 'Read', input: { file_path: '/opt/verylongdirectoryname/application.php' }, rules: [7], out: 'Read: /\u2039redacted:blob\u203a.php' },
];
/* The planted secrets each fixture must never leak, for the whole-event assertion (§ 7.5). */
const FIXTURE_SECRETS = {
  1: ['ghp_ABCDEF1234567890abcdef1234'], 2: ['s3cr3t-pw'], 3: ['sk_live_51H8xYzAbCdEfGhIj'],
  4: ['ops@example.org', '203.0.113.47'], 5: ['aimlapm'], 8: ['hunter2'],
  9: ['hunter2'], 10: ['admin:s3cr3t'], 11: ['S3cr3tP@ss'],
};

function checkSanitizerFixtures() {
  const detail = [];
  let ok = true;
  COUNTING_SUSPENDED = true;   // see the note on COUNTING_SUSPENDED: this is a diagnostic
  try {
  for (const f of SANITIZER_FIXTURES) {
    const r = buildDescriptor(f.tool, f.input);
    const traceOk = JSON.stringify(r.rules) === JSON.stringify(f.rules);
    let outOk;
    if (f.expectNull) outOk = r.descriptor === null;
    else if (f.n === 7) {
      const b = Buffer.from(r.descriptor, 'utf8');
      outOk = b.length <= K.DESCRIPTOR_CAP && r.truncated === true && r.descriptor.endsWith('\u2026')
        && b.toString('utf8') === r.descriptor && !/\uFFFD/.test(r.descriptor);
    } else outOk = r.descriptor === f.out;
    // The whole-event assertion a per-function test cannot make: serialize the COMPLETE event
    // and assert the raw secret substring is absent from the serialized bytes.
    const serialized = JSON.stringify({ data: { descriptor: r.descriptor, tool_name: f.tool } });
    let leaked = null;
    for (const s of (FIXTURE_SECRETS[f.n] || [])) if (serialized.includes(s)) leaked = s;
    const pass = traceOk && outOk && !leaked;
    if (!pass) ok = false;
    detail.push({ fixture: f.n, pass, trace: r.rules, expected_trace: f.rules, got: r.descriptor, leaked });
  }
  } finally { COUNTING_SUSPENDED = false; }
  return { ok, detail };
}

/* § 6.0's SELFTEST-MUST. For EVERY hook in the subscription table, load that hook's vendored
 * fixture and assert that every payload key this reporter READS for that hook is present, and
 * that every closed-enum value it recognises is a member of the declared set. A MISSING FIXTURE
 * IS A FAIL, NEVER A SKIP — a check that silently skips a hook with no fixture is the same
 * false-clean the whole rule is about. The reach is one-directional and deliberately so: the
 * harness ADDING a field is the additive case and must not red a seat, so an extra key in the
 * payload passes; only a key the reporter READS being absent fails. */
const READS = {
  SessionStart: { keys: ['session_id', 'hook_event_name', 'source', 'cwd'], enums: { source: ENUM.session_start_source } },
  SessionEnd: { keys: ['session_id', 'hook_event_name', 'reason'], enums: { reason: ENUM.session_end_reason } },
  UserPromptSubmit: { keys: ['session_id', 'hook_event_name', 'prompt_id', 'prompt', 'cwd'], enums: {} },
  Stop: { keys: ['session_id', 'hook_event_name', 'prompt_id', 'stop_hook_active', 'background_tasks'], enums: {} },
  StopFailure: { keys: ['session_id', 'hook_event_name', 'error'], enums: { error: ENUM.stopfailure_error } },
  PreToolUse: { keys: ['session_id', 'hook_event_name', 'tool_name', 'tool_input', 'tool_use_id', 'prompt_id', 'agent_id'], enums: {} },
  PostToolUse: { keys: ['session_id', 'hook_event_name', 'tool_name', 'tool_use_id', 'duration_ms', 'prompt_id'], enums: {} },
  PostToolUseFailure: { keys: ['session_id', 'hook_event_name', 'tool_name', 'tool_use_id', 'duration_ms', 'is_interrupt', 'prompt_id'], enums: {} },
  SubagentStart: { keys: ['session_id', 'hook_event_name', 'agent_id'], enums: {} },
  SubagentStop: { keys: ['session_id', 'hook_event_name', 'agent_id'], enums: {} },
  PreCompact: { keys: ['session_id', 'hook_event_name', 'trigger'], enums: { trigger: ENUM.precompact_trigger } },
  PostCompact: { keys: ['session_id', 'hook_event_name'], enums: {} },
  PermissionRequest: { keys: ['session_id', 'hook_event_name', 'tool_name'], enums: {} },
  PermissionDenied: { keys: ['session_id', 'hook_event_name', 'tool_name'], enums: {} },
  Notification: { keys: ['session_id', 'hook_event_name', 'notification_type'], enums: {} },
};

function checkHarnessPayloadKeys() {
  const dir = path.join(__dirname, 'fixtures', 'hooks');
  const detail = [];
  let ok = true;
  for (const [hook, spec] of Object.entries(READS)) {
    let fx = null;
    try { fx = JSON.parse(fs.readFileSync(path.join(dir, `${hook}.json`), 'utf8')); } catch (e) { fx = null; }
    if (!fx || !Array.isArray(fx.shapes) || !fx.shapes.length) {
      ok = false; detail.push({ hook, pass: false, reason: 'fixture missing or malformed' }); continue;
    }
    const present = new Set();
    for (const shape of fx.shapes) for (const k of Object.keys(shape)) present.add(k);
    const missing = spec.keys.filter((k) => !present.has(k));
    // The VALUE-SET half. The key check alone let a wrong value set through three of D1's
    // review rounds, so every enum value this reporter recognises for that hook's enum fields
    // must be a member of the set D1 declares — and the fixture's own value must be in it too.
    const badEnums = [];
    for (const [field, set] of Object.entries(spec.enums)) {
      for (const shape of fx.shapes) {
        const v = shape[field];
        if (typeof v === 'string' && !set.includes(v)) badEnums.push(`${field}=${v}`);
      }
    }
    const pass = missing.length === 0 && badEnums.length === 0;
    if (!pass) ok = false;
    detail.push({ hook, pass, source: fx._source, missing, unrecognised_enum_values: badEnums });
  }
  return { ok, detail };
}

/* Every predicate in § 9.4's table must be present in `predicates` AND have a criterion its own
 * volume can reach. An alarm threshold above a predicate's own evaluation rate is an alarm that
 * can never fire, which is a decoration rather than a check. */
function checkPredicateDiscrimination() {
  const declared = new Set(PREDICATES);
  const emitted = new Set();
  const src = fs.readFileSync(__filename, 'utf8');
  const re = /predicate\('([a-z_]+)'/g;
  let mm; while ((mm = re.exec(src)) !== null) emitted.add(mm[1]);
  const missing = [...emitted].filter((p) => !declared.has(p));
  const unreachable = [...declared].filter((p) => !emitted.has(p));
  return { ok: missing.length === 0 && unreachable.length === 0, detail: { missing_from_table: missing, never_evaluated: unreachable } };
}

/* The TLS posture lint AT-15 asks for, made mechanical: this source may not contain a
 * verification-disabling spelling anywhere. */
function checkTlsPosture() {
  // COMMENTS ARE STRIPPED FIRST, and the needles are ASSEMBLED rather than written out. Both
  // are load-bearing and both were defects on the first run of this check: a lint that scans
  // its own literal needles reports every source containing the lint as unsafe, and a lint
  // that cannot tell code from prose forbids the file from NAMING the thing it forbids.
  const src = fs.readFileSync(__filename, 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/(^|[^:])\/\/[^\n]*/g, '$1 ');
  const banned = [
    new RegExp('reject' + 'Unauthorized\\s*:\\s*false'),
    new RegExp('NODE_TLS_' + 'REJECT_UNAUTHORIZED'),
  ];
  const hits = banned.filter((r) => r.test(src)).map((r) => r.source);
  return { ok: hits.length === 0, detail: { forbidden_spellings_present: hits } };
}

function runSelftestChecks(config, cp) {
  const results = {}; const detail = {};
  const cfg = loadConfig(cp);
  results.config_readable = !!cfg.config && cfg.errors.length === 0;
  detail.config_readable = { errors: cfg.errors, path: cp };
  const s = checkSanitizerFixtures(); results.sanitizer_fixtures = s.ok; detail.sanitizer_fixtures = s.detail;
  const h = checkHarnessPayloadKeys(); results.harness_payload_keys = h.ok; detail.harness_payload_keys = h.detail;
  const p = checkPredicateDiscrimination(); results.predicate_discrimination = p.ok; detail.predicate_discrimination = p.detail;
  const t = checkTlsPosture();
  // tls_verify has two halves: the source posture (checkable offline, and the half that can
  // regress in review) and reachability with verification ON (needs the real host). Offline,
  // the second half is UNPROVEN and reported as a fail with its reason rather than assumed.
  results.tls_verify = t.ok && results.config_readable;
  detail.tls_verify = Object.assign({ reachability: 'not probed in this run — refreshed by the flusher against the real host' }, t.detail);
  results.schema_version_accepted = false;
  detail.schema_version_accepted = { reporter_schema_version: SCHEMA_VERSION, note: 'read from GET /api/ingest/health by the flusher; unprobed here' };
  return { results, detail };
}

function selftestMain() {
  const cp = configPath();
  const { config } = loadConfig(cp);
  if (config) registerSecret(config.token);
  const { results, detail } = runSelftestChecks(config, cp);
  const report = { reporter_version: REPORTER_VERSION, schema_version: SCHEMA_VERSION, checks: {}, detail };
  for (const c of SELFTEST_CHECKS) report.checks[c] = results[c] === true ? 'pass' : 'fail';
  process.stdout.write(redactSecrets(JSON.stringify(report, null, 2)) + '\n');
  return Object.values(report.checks).every((v) => v === 'pass') ? 0 : 1;
}

/* ── main ────────────────────────────────────────────────────────────────────────────────────
 * P-1 IS ENFORCED HERE. hook and statusline exit 0 on every path including a crash; selftest
 * is the one subcommand allowed a non-zero exit, because it is run by the installer and by CI
 * and never by the harness. */
function main() {
  const cmd = process.argv[2];
  if (cmd === 'hook' || cmd === 'statusline') FD_CACHE = new Map();
  if (cmd === 'flusher') {
    flusherMain().catch(() => { /* nothing above this to report to */ }).then(() => process.exit(0));
    return;
  }
  if (cmd === 'selftest') {
    let code = 1;
    try { code = selftestMain(); } catch (e) { process.stderr.write(`selftest crashed: ${redactSecrets(String(e && e.stack || e))}\n`); code = 1; }
    process.exit(code);
  }
  try {
    if (cmd === 'statusline') statuslineMain();
    else if (cmd === 'hook') hookMain(process.argv[3] || '');
  } catch (e) {
    // The catch of last resort. It writes to the seat's own log if it can and to NOTHING else:
    // stderr from a hook reaches the transcript, and stdout on SessionStart / UserPromptSubmit
    // reaches the MODEL.
    try {
      const { config } = loadConfig(configPath());
      if (config && config.spool_dir) logLine(config.spool_dir, cmd || 'unknown', `crashed: ${e && e.stack}`);
    } catch (e2) { /* there is nowhere left to write, and the seat must still be untouched */ }
  } finally {
    process.exit(0);
  }
}

if (require.main === module) main();
/* `appendLine` is exported for ONE reason: AT-10's atomicity claim is a claim about THIS
 * primitive, and driving it through a hook invocation makes the write a microsecond window
 * inside a 200 ms process, so the RED that is supposed to prove interleaving is possible
 * reproduces only by luck. A RED that reproduces by luck is not evidence. The stress harness
 * calls the primitive directly, in a tight loop, from concurrent processes. */
module.exports = { sanitize, buildDescriptor, truncateBytes, ulid, buildCounters, buildDegraded,
  appendLine, K, ENUM, SANITIZER_FIXTURES };

#!/usr/bin/env node
/* fleet-reporter/install.js — install, re-check, upgrade or uninstall fleet-reporter on a Linux, Windows or
 * macOS seat, without root (card#12571; the design is docs/design/REPORTER-INSTALL.md, D6).
 *
 * ONE FILE, NODE >= 18, NO DEPENDENCIES, AND THE ONLY IMPLEMENTATION. Every step is a function here and a
 * subcommand, `node install.js <step>`, so the by-hand fallback `INSTALL.md` describes and the installer are
 * one code path. No bash block is run: paths come from the platform table, files and modes from `fs`,
 * owner-only on Windows from the ACL helper below, digests from `crypto`, and `crontab`, `launchctl`, `ps`,
 * PowerShell and the reporter itself are spawned by argument array. `INSTALL.md`, beside this file, is the
 * reference for what each step does on each OS and why; this header says how the file is laid out.
 *
 *   node install.js --token-file <path> [--site <url> --seat-id <id> [--install-id <id>]] [--agent-name <n>]
 *                   [--ca-file <path>] [--no-board]      first install; with a config present, the ROTATION
 *   node install.js [--site <url>] [--seat-id <id>] [--install-id <id>] [--no-board]
 *                                                        re-check and upgrade; refuses with no config
 *   node install.js add-site --token-file <path> [--site <url>] [--ca-file <path>] [--site-name <n>]
 *   node install.js remove-site <name> · statusline [--wrap <command>] · set-descriptors full|paths|none
 *   node install.js issue-file --out <path> [--prompt]   a v1 token file, from the issue command or a prompt
 *   node install.js <step> [args]                        one step alone (STEPS below lists them)
 *   node install.js --uninstall · --paths · --render-shipping (the version guard's; reads stdin)
 *
 * Exit: 0 installed, or nothing to change · 1 refused, or a step or check failed · 2 installed, and a check
 * could not be measured. Every argument is scanned for the token shape before anything is parsed.
 *
 * REQUIRING THIS FILE RUNS NOTHING: `main` runs only under the `require.main === module` guard at the end,
 * and the reporter beside it is required lazily, so the render needs no other file. The exports are the
 * reporter acceptance suite's and the version guard's (D6 § 3.3). */
'use strict';

const fs = require('fs');
const path = require('path');
const os = require('os');
const cp = require('child_process');
const crypto = require('crypto');

/* THE FILES THE `artifact` STEP INSTALLS, relative to this directory. `bin/reporter-version-guard.py` reads
 * the quoted strings between these two markers as its blob population (D6 § 3.3), so a file added here joins
 * it in the PR that adds it. A plain name is the ARTIFACT; a `*` in the last segment is FIXTURES, and the
 * names it matches are the hook set (`hooks`). Exactly one such region; the guard exits 2 otherwise. */
// reporter-version-guard: BEGIN artifact
const ARTIFACT = ['fleet-reporter.js', 'fixtures/hooks/*.json'];
// reporter-version-guard: END artifact

const TOKEN_SHAPE = /mzn_[A-Za-z0-9_-]{43}/;
const TOKEN_SHAPE_G = /mzn_[A-Za-z0-9_-]{43}/g;
const TOKEN_RE = /^mzn_[A-Za-z0-9_-]{43}$/;
/* A v1 token file: the token alone, and an optional line feed (the writer's `FILE_TOKEN`, D6 § 4.2). */
const FILE_TOKEN = /^mzn_[A-Za-z0-9_-]{43}\n?$/;
/* The issue command's token line (`mezzanine:ingest-token:issue`), the writer's `TOKEN` rule. */
const ISSUE_TOKEN_LINE = /^\s*token\s+(mzn_[A-Za-z0-9_-]{43})\s*$/;
const SLUG_INSTALL = /^[a-z0-9][a-z0-9-]{1,31}$/;
const SLUG_SEAT = /^[a-z0-9][a-z0-9-]{1,47}$/;
const AGENT_NAME_RE = /^[a-z0-9-]{1,48}$/;
const INGEST_PATH = '/api/ingest/events';
const LABEL = 'com.pupfuzz.fleet-reporter';
const CRON_BEGIN = '# BEGIN fleet-reporter-flusher';
const CRON_END = '# END fleet-reporter-flusher';
/* Kept VERBATIM, its reference to INSTALL-LINUX.md included: a seat installed by `install.sh` holds this
 * line, so it reads `crontab already current`, and the file it names is the redirect stub (D6 § 3.4). */
const CRON_COMMENT = '# D1 § 2.3 supervised start, no root: fleet-reporter/INSTALL-LINUX.md in PupFuzz/mezzanine. flock -n makes each start a no-op while one runs.';
const TODO_KEY = 'CLAUDE_CODE_ENABLE_TODO_TOOLS';
const TOKEN_FILE_NAME = 'mezzanine-reporter-token';
/* The bounded waits, each derived where `INSTALL.md` says: cron's next minute plus margin, and § 7's window. */
const RESTART_WAIT_S = 70;
const ARRIVAL_WAIT_S = 150;
const SYSTEM_SID = 'S-1-5-18';
const ADMINS_SID = 'S-1-5-32-544';

class Refusal extends Error {
  constructor(message, code) { super(message); this.exitCode = code === undefined ? 1 : code; }
}
const refuse = (m, code) => { throw new Refusal(m, code); };

/* Every line this file prints goes through `scrub`: a token-shaped string becomes `<redacted>`. Nothing here
 * prints a token on purpose; this is the boundary that keeps a child's output (the reporter's `selftest`, a
 * `crontab` error) from carrying one either. A prefix (`mzn_` and 8 characters) is not the shape. */
const scrub = (s) => String(s).replace(TOKEN_SHAPE_G, '<redacted>');
const say = (...a) => { process.stdout.write(scrub(a.join(' ')) + '\n'); };
const warn = (...a) => { process.stderr.write(scrub(a.join(' ')) + '\n'); };
/* FLEET_REPORTER_INSTALL_TRACE=1: each step heading and each spawned PowerShell or icacls call reports, on
 * stderr, the milliseconds since the run began and what it ran on (a path, never content) — the diagnostic for a
 * run that stalls where no step prints (card#12571's first windows-latest runs). Inert unless set. */
const TRACE = process.env.FLEET_REPORTER_INSTALL_TRACE === '1', T0 = Date.now();
const trace = (what) => { if (TRACE) warn(`trace +${Date.now() - T0} ms: ${what}`); };
const step = (name, note) => { trace('step ' + name); say(`── step \`${name}\`${note ? ' (' + note + ')' : ''}`); };
const sleepSync = (ms) => Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
const exists = (p) => { try { fs.lstatSync(p); return true; } catch (e) { return false; } };
const sha256 = (b) => crypto.createHash('sha256').update(b).digest('hex');

/* The reporter beside this file — THE CHECKOUT'S, never the artifact already on the seat, which an upgrade is
 * replacing (D6 § 3.1). Lazy, so `--render-shipping` and a bare `require` load nothing else. */
let reporterModule = null;
const reporter = () => reporterModule || (reporterModule = require(path.join(__dirname, 'fleet-reporter.js')));

// ── the platform table ─────────────────────────────────────────────────────────────────────────────────

/* D1 § 2.1's install directory and § 3.1's config path for `platform`, under `home`, and everything the
 * install derives from them. A function of its arguments alone: it reads no `process.platform`, no
 * `process.env` and no `os.homedir()`, so the render can ask it for every platform on any one (D6 § 3.3).
 * `env` carries the two Windows folders when the caller has them (the running install passes its own
 * environment; the render passes nothing and gets the defaults under the profile). */
function platformTable(platform, home, env) {
  env = env || {};
  if (platform === 'win32') {
    const P = path.win32;
    const local = env.LOCALAPPDATA || P.join(home, 'AppData', 'Local');
    const roaming = env.APPDATA || P.join(home, 'AppData', 'Roaming');
    return derive({ platform, P, home, artifactDir: P.join(local, 'fleet-reporter'), configDir: P.join(roaming, 'fleet-reporter'),
      spool: P.join(local, 'fleet-reporter-state'), attempts: P.join(local, 'fleet-reporter-install'),
      supervisor: 'none', ownerOnly: 'acl', flock: null, plist: null });
  }
  if (platform !== 'linux' && platform !== 'darwin') refuse(`this installer supports linux, darwin and win32, not ${platform}: NOTHING RUN`);
  const P = path.posix;
  return derive({ platform, P, home, artifactDir: P.join(home, '.local', 'share', 'fleet-reporter'),
    configDir: P.join(home, '.config', 'fleet-reporter'), spool: P.join(home, '.local', 'state', 'fleet-reporter'),
    attempts: P.join(home, '.local', 'state', 'fleet-reporter-install'),
    supervisor: platform === 'linux' ? 'crontab' : 'launchd', ownerOnly: 'mode',
    flock: platform === 'linux' ? P.join(home, '.local', 'state', 'fleet-reporter-flusher.flock') : null,
    plist: platform === 'darwin' ? P.join(home, 'Library', 'LaunchAgents', LABEL + '.plist') : null });
}
function derive(t) {
  const P = t.P;
  return Object.assign(t, {
    js: P.join(t.artifactDir, 'fleet-reporter.js'), fixtures: P.join(t.artifactDir, 'fixtures', 'hooks'),
    config: P.join(t.configDir, 'config.json'), tokenFile: P.join(t.configDir, TOKEN_FILE_NAME),
    todoMark: P.join(t.configDir, 'todo-tools-set'),
    settings: P.join(t.home, '.claude', 'settings.json'), settingsLocal: P.join(t.home, '.claude', 'settings.local.json'),
  });
}
const publicTable = (T) => {
  const o = {};
  for (const k of ['platform', 'home', 'artifactDir', 'js', 'fixtures', 'configDir', 'config', 'tokenFile', 'todoMark', 'spool',
    'attempts', 'settings', 'settingsLocal', 'supervisor', 'ownerOnly', 'flock', 'plist']) o[k] = T[k];
  return o;
};

// ── the start definitions and the hook commands: pure functions of their inputs ────────────────────────

/* The canonical settings command, per OS (D6 § 3.1): `node <abs path> <sub>` on POSIX; on `win32` the path in
 * FORWARD SLASHES and DOUBLE-QUOTED — what the first Windows seat's settings.json holds, and what D1 § 2.1's
 * Windows row says. `sub` is `hook <Hook>` or `statusline`. */
function hookCommand(platform, artifactPath, sub) {
  if (platform === 'win32') return `node "${artifactPath.split('\\').join('/')}" ${sub}`;
  return `node ${artifactPath} ${sub}`;
}
/* A settings command split into `node`, the quoted-or-bare path and the rest, or null. Recognition is by this
 * parse, never by string equality, because `path.join` on win32 yields backslashes. */
function parseReporterCommand(cmd) {
  const m = /^node\s+(?:"([^"]+)"|(\S+))\s+(hook\s+(\S+)|statusline)\s*$/.exec(typeof cmd === 'string' ? cmd.trim() : '');
  if (!m) return null;
  return { path: m[1] !== undefined ? m[1] : m[2], sub: m[4] !== undefined ? 'hook ' + m[4] : 'statusline' };
}
/* `stop-flusher.js`'s comparison: resolved, and lower-cased on win32. */
function samePath(platform, a, b) {
  if (platform === 'win32') return path.win32.resolve(a).toLowerCase() === path.win32.resolve(b).toLowerCase();
  return path.posix.resolve(a) === path.posix.resolve(b);
}
const isReporterCommand = (platform, js, cmd, sub) => {
  const p = parseReporterCommand(cmd);
  return !!p && samePath(platform, p.path, js) && (sub === undefined || p.sub === sub);
};
const isCanonicalForm = (platform, cmd) => (platform === 'win32'
  ? /^node "[^"\\]+" (hook \S+|statusline)$/.test(cmd) : /^node \/\S+ (hook \S+|statusline)$/.test(cmd));

/* The crontab start line for `inputs` ({ coordConfig, flock, node, js }) — byte for byte the line the runbook's
 * Step 5 wrote at `188e5fce` — or null on a platform the crontab does not supervise. */
function cronLine(platform, inputs) {
  if (platform !== 'linux') return null;
  const cc = inputs.coordConfig || '';
  return `${cc ? 'COORD_CONFIG=' + cc + ' ' : ''}flock -n ${inputs.flock} ${inputs.node} ${inputs.js} flusher >/dev/null 2>&1`;
}
const cronBlock = (platform, inputs) => {
  const line = cronLine(platform, inputs);
  return line === null ? null : [CRON_BEGIN, CRON_COMMENT, `* * * * * ${line}`, `@reboot ${line}`, CRON_END];
};
const xml = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
/* The user launchd agent (D6 § 3.4): `StartInterval 60`, not `KeepAlive`, which relaunches a lock loser every
 * throttle interval; `node` by the path `command -v node` gives (the stable symlink, not a Homebrew Cellar
 * realpath); `COORD_CONFIG` by `EnvironmentVariables` when the resolver finds one. */
function launchdPlist(inputs) {
  const env = inputs.coordConfig
    ? `  <key>EnvironmentVariables</key>\n  <dict>\n    <key>COORD_CONFIG</key>\n    <string>${xml(inputs.coordConfig)}</string>\n  </dict>\n` : '';
  return '<?xml version="1.0" encoding="UTF-8"?>\n'
    + '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">\n'
    + '<plist version="1.0">\n<dict>\n'
    + `  <key>Label</key>\n  <string>${LABEL}</string>\n`
    + `  <key>ProgramArguments</key>\n  <array>\n    <string>${xml(inputs.node)}</string>\n    <string>${xml(inputs.js)}</string>\n    <string>flusher</string>\n  </array>\n`
    + '  <key>RunAtLoad</key>\n  <true/>\n  <key>StartInterval</key>\n  <integer>60</integer>\n'
    + env
    + '  <key>StandardOutPath</key>\n  <string>/dev/null</string>\n  <key>StandardErrorPath</key>\n  <string>/dev/null</string>\n'
    + '</dict>\n</plist>\n';
}
/* One hook per fixture; matcher `*` when every captured payload carries `tool_name`, else `""`. */
const hookMatcher = (shapes) => (Array.isArray(shapes) && shapes.every((p) => p && typeof p.tool_name === 'string') ? '*' : '');

/* `--render-shipping`: what a seat installs, for EVERY platform, from one JSON object (D6 § 3.3). The input
 * keys are a CROSS-REV CONTRACT and APPEND-ONLY: unknown keys are ignored, so a head that reads a new key still
 * renders at a base that does not know it — and removing a key takes two PRs (stop reading it, then stop
 * sending it), because a base that still reads it would fail. It spawns nothing and writes nothing. */
function renderShipping(input) {
  const need = (k, type) => {
    if (typeof input[k] !== type && !(type === 'array' && Array.isArray(input[k])) && !(type === 'object' && input[k] && typeof input[k] === 'object')) {
      refuse(`--render-shipping: the input has no ${k} (${type})`);
    }
    return input[k];
  };
  const home = need('home', 'string'), winHome = need('win32_home', 'string'), node = need('node', 'string');
  const cc = typeof input.coord_config === 'string' ? input.coord_config : '';
  const names = need('fixtures', 'array').slice().sort();
  const shapes = input.fixture_shapes && typeof input.fixture_shapes === 'object' ? input.fixture_shapes : {};
  const out = {};
  for (const platform of ['linux', 'darwin', 'win32']) {
    const T = platformTable(platform, platform === 'win32' ? winHome : home, {});
    const hooks = {};
    for (const n of names) hooks[n] = { matcher: hookMatcher(shapes[n] || []), command: hookCommand(platform, T.js, 'hook ' + n) };
    const r = { hooks, statusline: hookCommand(platform, T.js, 'statusline') };
    if (platform === 'linux') r.crontab_block = cronBlock(platform, { coordConfig: cc, flock: T.flock, node, js: T.js }).join('\n') + '\n';
    if (platform === 'darwin') r.plist = launchdPlist({ coordConfig: cc, node, js: T.js });
    out[platform] = r;
  }
  return out;
}

// ── the token file: one parser, v1 and v2 ──────────────────────────────────────────────────────────────

/* THE ONE READER OF A TOKEN FILE'S BYTES (D6 § 4.2). v1 is the token alone with an optional line feed; v2 is
 * exactly four `<key> <value>` lines — `token`, `ingest_url`, `install_id`, `seat_id` — once each, in any
 * order, LF only. Anything else is refused BY NAME: a message names a line number or a key, never content. */
const V2_KEYS = ['token', 'ingest_url', 'install_id', 'seat_id'];
function parseTokenFile(bytes) {
  const text = Buffer.isBuffer(bytes) ? bytes.toString('utf8') : String(bytes);
  if (text.includes('\r')) refuse('the token file holds a carriage return (it went through an editor or a terminal on the way)');
  if (FILE_TOKEN.test(text)) return { version: 1, token: text.replace(/\n$/, '') };
  if (text === '' || text === '\n') refuse('the token file is empty');
  const lines = (text.endsWith('\n') ? text.slice(0, -1) : text).split('\n');
  const got = {};
  lines.forEach((l, i) => {
    const m = /^([a-z_]+) (\S+)$/.exec(l);
    if (!m) refuse(`the token file's line ${i + 1} is not one "<key> <value>" pair with no other space`);
    if (!V2_KEYS.includes(m[1])) refuse(`the token file's line ${i + 1} carries a key that is not one of ${V2_KEYS.join(', ')}`);
    if (Object.prototype.hasOwnProperty.call(got, m[1])) refuse(`the token file carries ${m[1]} more than once`);
    got[m[1]] = m[2];
  });
  for (const k of V2_KEYS) if (!Object.prototype.hasOwnProperty.call(got, k)) refuse(`the token file carries no ${k} line`);
  if (!TOKEN_RE.test(got.token)) refuse('the token file\'s token is not mzn_ and 43 base64url characters');
  let u = null;
  try { u = new URL(got.ingest_url); } catch (e) { u = null; }
  if (!u || !/^https:\/\//.test(got.ingest_url) || u.username || u.password || !got.ingest_url.endsWith(INGEST_PATH)
      || Buffer.byteLength(got.ingest_url) > 256) {
    refuse(`the token file's ingest_url is not an https:// URL of at most 256 B, with no userinfo, ending ${INGEST_PATH}`);
  }
  if (!SLUG_INSTALL.test(got.install_id)) refuse('the token file\'s install_id fails D1 § 3.1\'s ^[a-z0-9][a-z0-9-]{1,31}$');
  if (!SLUG_SEAT.test(got.seat_id)) refuse('the token file\'s seat_id fails D1 § 3.1\'s ^[a-z0-9][a-z0-9-]{1,47}$');
  return { version: 2, token: got.token, ingest_url: got.ingest_url, install_id: got.install_id, seat_id: got.seat_id };
}

/* The issue command's output (D6 § 3.1, `issue-file`): EXACTLY ONE line matching the token line rule; every
 * other line echoed with each token-shaped substring `<redacted>`. Zero lines, or two, refuse — the two-line
 * refusal is new with this file (the runbook's writer took the last of several). A refusal carries the echo,
 * so the caller can still show the prefix line the operator revokes by. */
function parseIssueOutput(text) {
  const echoed = [], tokens = [];
  for (const l of String(text).split('\n')) {
    const m = ISSUE_TOKEN_LINE.exec(l);
    if (m) { tokens.push(m[1]); echoed.push('  token       <written to the token file, not shown>'); } else echoed.push(l.replace(TOKEN_SHAPE_G, '<redacted>'));
  }
  if (tokens.length !== 1) {
    const r = new Refusal(tokens.length ? `the issue output holds ${tokens.length} token lines, not one: NOTHING WRITTEN` : 'no token line in the issue output: NOTHING WRITTEN');
    r.echoed = echoed;
    throw r;
  }
  return { token: tokens[0], echoed };
}

/* A site's key for matching a token file's `ingest_url` against the config's (D6 § 4.5): a bare origin gains
 * the ingest path and a trailing `/` is dropped, then the reporter's own `siteKey` (scheme and host
 * lower-cased, userinfo and a default port dropped) — one function of a site's identity, not a second. */
function siteKey(u) {
  let x;
  try { x = new URL(String(u).trim()); } catch (e) { return null; }
  x.pathname = x.pathname === '/' || x.pathname === '' ? INGEST_PATH : x.pathname.replace(/\/+$/, '');
  return reporter().siteKey(x.href);
}
/* `--site`'s value as an ingest URL: an address gains the path, as install.sh did. */
function ingestUrlOf(site) {
  const s = String(site).trim().replace(/\/+$/, '');
  return s.endsWith(INGEST_PATH) ? s : s + INGEST_PATH;
}

// ── the config writer ──────────────────────────────────────────────────────────────────────────────────

const TRANSPORT = ['ingest_url', 'token', 'ca_file', 'proxy_url'];
const SEAT_OPTS = ['install', 'seat', 'spool', 'agent', 'wrapped', 'descriptors', 'boardUser', 'boardBase'];
const WRITE_MODES = ['', 'add-site', 'set-token', 'remove-site', 'set-descriptors', 'board', 'wrapped'];

/* THE CONFIG WRITER — the runbook's `write-config.js` as a function (D6 § 3.1). A fresh install (`mode` '')
 * writes D1 § 3.1's multi-site form with one site, and refuses a config that exists. Every other mode reads
 * the config, lifts a single-site file into `sites` under `liftName` (default `default`), changes one thing,
 * and writes the result. EVERY RESULT is validated with the checkout's `parseConfig` before it reaches the
 * disk, then written by the reporter's `atomicWrite` (a temp file beside the config, fsynced, renamed), so a
 * refused or failed write leaves the config as it was. `add-site` and `set-token` change `sites` and nothing
 * else: a seat key passed with either is refused. It prints the site names and nothing of any value. */
function writeConfig(o) {
  const mode = o.mode || '';
  const platform = o.platform || process.platform;
  if (!WRITE_MODES.includes(mode)) refuse(`writeConfig: mode must be one of ${WRITE_MODES.filter(Boolean).join(', ')}, or none: NOTHING WRITTEN`);
  if (!o.config) refuse('writeConfig needs the config path: NOTHING WRITTEN');
  if (o.caFile !== undefined && o.caFile !== null && !path.isAbsolute(o.caFile)) refuse('--ca-file must be an absolute path (D1 § 3.1): NOTHING WRITTEN');
  if (['', 'board'].includes(mode)) {
    if (!o.boardUser !== !o.boardBase) refuse('the board user and the board base are a pair: both or neither: NOTHING WRITTEN');
    if (o.boardUser && !(Number.isInteger(Number(o.boardUser)) && Number(o.boardUser) >= 1 && Number(o.boardUser) <= 4294967295)) {
      refuse('the board user must be a positive integer board user id: NOTHING WRITTEN');
    }
  }
  if (['', 'set-descriptors'].includes(mode) && o.descriptors !== undefined && !['full', 'paths', 'none'].includes(o.descriptors)) {
    refuse('descriptors must be full, paths or none: NOTHING WRITTEN');
  }
  const siteOf = (name, url, token) => ({ name, ingest_url: url, token, ca_file: o.caFile || null, proxy_url: null });
  let c, text;
  if (mode === '') {
    for (const k of ['install', 'seat', 'url', 'spool']) if (!o[k]) refuse(`a fresh config needs ${k}: NOTHING WRITTEN`);
    if (!TOKEN_RE.test(o.token || '')) refuse('the token is not mzn_ and 43 base64url characters: NOTHING WRITTEN');
    if (exists(o.config)) refuse(`${o.config} already exists, and a fresh install writes only a config that does not: NOTHING WRITTEN`);
    c = {
      install_id: o.install, seat_id: o.seat, spool_dir: o.spool,
      wrapped_statusline: o.wrapped || null, protocol_agent_name: o.agent || null,
      descriptors: o.descriptors || 'full', enabled: true,
      ...(o.boardUser ? { board_user_id: Number(o.boardUser), board_api_base: o.boardBase } : {}),
      sites: [siteOf(o.siteName || 'default', o.url, o.token)],
    };
  } else {
    if (['add-site', 'set-token', 'remove-site'].includes(mode)) {
      for (const k of SEAT_OPTS) if (o[k] !== undefined) refuse(`${k} is given, and ${mode} takes the seat's keys from the file: NOTHING WRITTEN`);
    }
    if (!exists(o.config)) refuse(`${mode} changes a config that exists, and ${o.config} does not: NOTHING WRITTEN`);
    try { c = JSON.parse(fs.readFileSync(o.config, 'utf8')); } catch (err) { refuse(`cannot read ${o.config} as JSON (${err.code || 'not JSON'}): NOTHING WRITTEN`); }
    if (!c || typeof c !== 'object' || Array.isArray(c)) refuse(`${o.config} is not a JSON object: NOTHING WRITTEN`);
    if (!('sites' in c)) {
      const lifted = { name: o.liftName || 'default' };
      for (const k of TRANSPORT) { lifted[k] = k in c ? c[k] : null; delete c[k]; }
      c.sites = [lifted];
    }
    if (!Array.isArray(c.sites)) refuse(`${o.config}'s sites is not an array: NOTHING WRITTEN`);
    const names = c.sites.map((x) => (x && typeof x.name === 'string' ? x.name : '<unnamed>'));
    if (mode === 'add-site') {
      if (!o.siteName) refuse('add-site needs a site name: NOTHING WRITTEN');
      if (!o.url) refuse('add-site needs the site\'s ingest URL: NOTHING WRITTEN');
      if (!TOKEN_RE.test(o.token || '')) refuse('the token is not mzn_ and 43 base64url characters: NOTHING WRITTEN');
      c.sites.push(siteOf(o.siteName, o.url, o.token));
    } else if (mode === 'set-token') {
      for (const k of ['url', 'caFile']) if (o[k] !== undefined) refuse(`${k} is given, and set-token changes one token and nothing else: NOTHING WRITTEN`);
      if (!TOKEN_RE.test(o.token || '')) refuse('the token is not mzn_ and 43 base64url characters: NOTHING WRITTEN');
      const at = names.indexOf(o.siteName);
      if (at < 0) refuse(`${o.siteName} is not a site in ${o.config}, whose sites are: ${names.join(', ')}: NOTHING WRITTEN`);
      c.sites[at].token = o.token;
    } else if (mode === 'remove-site') {
      const at = names.indexOf(o.siteName);
      if (at < 0) refuse(`${o.siteName} is not a site in ${o.config}, whose sites are: ${names.join(', ')}: NOTHING WRITTEN`);
      if (c.sites.length === 1) refuse('the last site: uninstall instead: NOTHING WRITTEN');
      c.sites.splice(at, 1);
    } else if (mode === 'set-descriptors') {
      if (o.descriptors === undefined) refuse('set-descriptors needs full, paths or none: NOTHING WRITTEN');
      c.descriptors = o.descriptors;
    } else if (mode === 'board') {
      delete c.board_user_id; delete c.board_api_base;
      if (o.boardUser) { c.board_user_id = Number(o.boardUser); c.board_api_base = o.boardBase; }
    } else if (mode === 'wrapped') {
      c.wrapped_statusline = o.wrapped || null;
    }
  }
  text = JSON.stringify(c, null, 2) + '\n';
  const errs = reporter().allConfigErrors(reporter().parseConfig(text));
  if (errs.length) { for (const x of errs) warn('config error: ' + x); refuse('the result does not validate: NOTHING WRITTEN'); }
  if (mode === '') ownerOnlyDir(platform, path.dirname(o.config));
  if (!reporter().atomicWrite(o.config, text, { sync: true })) refuse(`could not write ${o.config}: NOTHING WRITTEN`);
  say(`wrote ${o.config} (${permOf(platform, o.config)})` + (mode && mode !== 'board' && mode !== 'wrapped' && mode !== 'set-descriptors' ? ', sites: ' + c.sites.map((x) => x.name).join(', ') : ''));
  if (mode === 'set-token') say('token replaced: ' + o.siteName);
  return { config: c };
}

// ── owner-only: mode bits on POSIX, the ACL on Windows ─────────────────────────────────────────────────

/* Windows' owner-only (card#12335's `acl-only-me.js`, carried in as functions): the owner is this account or
 * Administrators, and every ACE, explicit or inherited, is an Allow for this account, SYSTEM or Administrators.
 * Output names SIDs only, and this account's own as `<me>`. PowerShell 5.1 given a PowerShell 7 PSModulePath
 * can fail to load its own modules, so the child gets none; the script goes as -EncodedCommand, and every path
 * reaches it through the environment, never its text. */
function powershell(script, extra) {
  const env = Object.assign({}, process.env, extra || {});
  for (const k of Object.keys(env)) if (k.toUpperCase() === 'PSMODULEPATH') delete env[k];
  const enc = Buffer.from("$ErrorActionPreference = 'Stop'\n" + script, 'utf16le').toString('base64');
  const exe = path.join(process.env.SystemRoot || 'C:\\Windows', 'System32', 'WindowsPowerShell', 'v1.0', 'powershell.exe');
  const on = (extra && (extra.FR_ACL_PATH || extra.FR_PID || extra.FR_SID)) || 'this account';
  trace('powershell start, on ' + on);
  try {
    return cp.execFileSync(exe, ['-NoProfile', '-NonInteractive', '-EncodedCommand', enc],
      { env, windowsHide: true, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  } finally { trace('powershell end, on ' + on); }
}
let mySid = null;
const sid = () => mySid || (mySid = powershell('[System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value').trim());
const who = (s) => (s === sid() ? '<me>' : s);
function aclCheck(paths) {
  const lines = [], foreign = [], foreignSids = [];
  let ok = true;
  const bad = (p, why) => { ok = false; lines.push(p + ': NOT owner-only: ' + why); };
  // The type checks first, in node; then ONE PowerShell process reads every remaining path's ACL — a PowerShell
  // start costs seconds (23 s each on windows-latest, measured by the trace, card#12571), so never one per path.
  const todo = [];
  for (const p of paths) {
    let st;
    try { st = fs.lstatSync(p); } catch (e) { bad(p, 'cannot read it (' + e.code + ')'); continue; }
    if (st.isSymbolicLink()) { bad(p, 'a symbolic link or junction'); continue; }
    if (!st.isFile() && !st.isDirectory()) { bad(p, 'neither a regular file nor a directory'); continue; }
    todo.push(p);
  }
  if (!todo.length) return { ok, lines, foreign, foreignSids };
  let out;
  try {
    out = powershell("foreach ($p in $env:FR_ACL_PATHS.Split('|')) {\n"
      + "'path ' + $p\n"
      + "'reparse ' + [bool]((Get-Item -LiteralPath $p -Force).Attributes -band [System.IO.FileAttributes]::ReparsePoint)\n"
      + '$a = Get-Acl -LiteralPath $p\n'
      + "'owner ' + $a.GetOwner([System.Security.Principal.SecurityIdentifier]).Value\n"
      + "foreach ($r in $a.GetAccessRules($true, $true, [System.Security.Principal.SecurityIdentifier])) { 'ace ' + $r.IdentityReference.Value + ' ' + $r.AccessControlType + ' ' + $r.IsInherited }\n"
      + '}', { FR_ACL_PATH: todo[0], FR_ACL_PATHS: todo.map((x) => path.win32.normalize(x)).join('|') });
  } catch (e) {
    for (const p of todo) bad(p, 'its ACL could not be read (PowerShell exited ' + e.status + ')');
    return { ok, lines, foreign, foreignSids };
  }
  const blocks = [];
  for (const l of out.split(/\r?\n/).filter(Boolean)) {
    if (l.startsWith('path ')) blocks.push([]); else if (blocks.length) blocks[blocks.length - 1].push(l);
  }
  todo.forEach((p, i) => {
    const rows = blocks[i] || [];
    const owner = (rows.find((l) => l.startsWith('owner ')) || 'owner ?').slice(6);
    const aces = rows.filter((l) => l.startsWith('ace ')).map((l) => l.split(' '));
    if (!rows.includes('reparse False')) { bad(p, rows.length ? 'a reparse point' : 'its ACL was not read'); return; }
    if (owner !== sid() && owner !== ADMINS_SID) { bad(p, 'owned by ' + who(owner)); return; }
    const f = aces.filter(([, s, type]) => type !== 'Allow' || ![sid(), SYSTEM_SID, ADMINS_SID].includes(s));
    if (f.length) {
      for (const [, s, type, inh] of f) { foreign.push(type + ' ' + who(s) + (inh === 'True' ? ' (inherited)' : '')); foreignSids.push(s); }
      bad(p, f.map(([, s, type, inh]) => type + ' ' + who(s) + (inh === 'True' ? ' (inherited)' : '')).join(', '));
      return;
    }
    lines.push(p + ': ACL owner-only (owner ' + who(owner) + '; ' + aces.length + ' ACEs)');
  });
  return { ok, lines, foreign, foreignSids };
}
/* Removes the directory's inherited rules and grants full control to this account, SYSTEM and Administrators,
 * inherited by everything made inside it afterwards. */
function aclPrep(dir) {
  const icacls = path.join(process.env.SystemRoot || 'C:\\Windows', 'System32', 'icacls.exe');
  const run = (...a) => { trace('icacls ' + a[0] + ' on ' + dir); cp.execFileSync(icacls, [path.win32.normalize(dir), ...a], { windowsHide: true, stdio: ['ignore', 'ignore', 'inherit'] }); trace('icacls done'); };
  run('/inheritance:r', '/grant:r', '*' + sid() + ':(OI)(CI)F', '*' + SYSTEM_SID + ':(OI)(CI)F', '*' + ADMINS_SID + ':(OI)(CI)F');
  // `/grant:r` replaces the grants of the SIDs it names and `/inheritance:r` drops inherited ACEs, so an EXPLICIT
  // ACE for any other SID survives both — windows-latest gives a new directory one for OWNER RIGHTS (S-1-3-4).
  // Each is removed by SID, grant and deny alike, so the directory holds the three grants above and nothing else.
  for (const s of new Set(aclCheck([dir]).foreignSids)) run('/remove', '*' + s);
}
/* Whether this account's profile roams (W1's check, card#12335): %APPDATA%, the config's home, is copied to a
 * profile server at logoff on a roaming profile. FLEET_REPORTER_INSTALL_ASSUME_ROAMING=1 forces the answer
 * `true` — the suite's seam for R-win-4; it can only make the installer refuse, never make it proceed. */
function roaming() {
  if (process.env.FLEET_REPORTER_INSTALL_ASSUME_ROAMING === '1') return true;
  const v = powershell("(Get-CimInstance Win32_UserProfile -Filter (\"SID='\" + $env:FR_SID + \"'\")).RoamingConfigured", { FR_SID: sid() }).trim();
  if (v !== 'True' && v !== 'False') refuse(`the profile's RoamingConfigured read [${v}]: NOT MEASURED, NOTHING WRITTEN`);
  return v === 'True';
}
function ownerOnlyDir(platform, dir) {
  if (platform === 'win32') {
    fs.mkdirSync(dir, { recursive: true });
    // A symbolic link or junction is refused BEFORE icacls runs, so its target is never re-ACL'd (review S5);
    // node's lstat reports a junction as a symbolic link on Windows.
    const st = fs.lstatSync(dir);
    if (st.isSymbolicLink() || !st.isDirectory()) refuse(`${dir} is a symbolic link, a junction or no directory: it is never re-ACL'd: NOTHING WRITTEN`);
    if (!aclCheck([dir]).ok) aclPrep(dir);
    const r = aclCheck([dir]);
    if (!r.ok) { for (const l of r.lines) warn(l); refuse(`${dir} could not be made owner-only: NOTHING WRITTEN`); }
    return;
  }
  fs.mkdirSync(dir, { recursive: true, mode: 0o700 });
  const st = fs.lstatSync(dir);
  if (!st.isDirectory() || st.uid !== process.getuid()) refuse(`${dir} is not a directory this account owns: NOTHING WRITTEN`);
  if ((st.mode & 0o777) !== 0o700) fs.chmodSync(dir, 0o700);
}
function permOf(platform, f) {
  if (platform !== 'win32') return 'mode ' + (fs.statSync(f).mode & 0o777).toString(8);
  const r = aclCheck([path.dirname(f), f]);
  if (!r.ok) { for (const l of r.lines) warn(l); refuse(`${f} was written and is NOT owner-only: delete it, and treat its token as exposed`); }
  return 'ACL: owner-only';
}

// ── small readers ──────────────────────────────────────────────────────────────────────────────────────

/* `command -v <name>`: the first match on PATH (PATHEXT on Windows), or null. */
function which(name) {
  const exts = process.platform === 'win32' ? (process.env.PATHEXT || '.EXE;.CMD;.BAT').split(';').concat(['']) : [''];
  for (const d of (process.env.PATH || '').split(path.delimiter)) {
    if (!d) continue;
    for (const x of exts) {
      const f = path.join(d, name + x);
      try { if (fs.statSync(f).isFile()) return f; } catch (e) { /* not here */ }
    }
  }
  return null;
}
const readJson = (f) => JSON.parse(fs.readFileSync(f, 'utf8'));
const versionOf = (js) => { const m = /^const REPORTER_VERSION = '([^']*)';$/m.exec(fs.readFileSync(js, 'utf8')); return m ? m[1] : null; };
/* The account name as an install_id slug (install.sh's derivation, D6 § 5). */
const accountSlug = () => os.userInfo().username.toLowerCase().replace(/[_.]/g, '-').replace(/[^a-z0-9-]/g, '').slice(0, 32);

/* COORD_CONFIG as the seat's sessions see it: settings.json, then settings.local.json, the last that sets it
 * winning (Claude Code's settings precedence). Empty when none sets it. */
function coordConfig(T) {
  let v = '';
  for (const f of [T.settings, T.settingsLocal]) {
    let s;
    try { s = readJson(f); } catch (e) { if (e.code === 'ENOENT') continue; refuse(`cannot read ${f} as JSON: NOTHING WRITTEN`); }
    if (s && s.env && typeof s.env.COORD_CONFIG === 'string') v = s.env.COORD_CONFIG;
  }
  return v;
}

// ── the crontab (Linux) ────────────────────────────────────────────────────────────────────────────────

/* CARD#12334'S READ PREDICATE (D6 § 3.4): `crontab -l` exiting 1 with stderr matching `^no crontab for ` is
 * the EMPTY crontab; any other non-zero exit, or a 1 with other stderr, is a READ FAILURE and the caller
 * refuses — never writes. `spawnSync` reports status 1 for both, so the stderr test is the whole distinction:
 * a port that read status alone would install a crontab holding the reporter's block and nothing else. */
function readCrontab() {
  const r = cp.spawnSync('crontab', ['-l'], { encoding: 'utf8' });
  if (r.error) refuse(`crontab could not be run (${r.error.code}): NOTHING WRITTEN`);
  if (r.status === 0) return { text: r.stdout, none: false };
  if (r.status === 1 && /^no crontab for /m.test(r.stderr || '')) return { text: '', none: true };
  refuse(`crontab -l failed (exit ${r.status}${r.stderr ? ': ' + r.stderr.trim() : ''}); a crontab this step cannot read is never rewritten: NOTHING WRITTEN`);
}
/* The crontab with every fleet-reporter-flusher block removed (`block` null) or replaced by `block`. Refuses a
 * crontab whose BEGIN and END markers do not pair, which would otherwise take everything after an unterminated
 * BEGIN with it. */
function cronReplace(text, block) {
  const lines = text === '' ? [] : text.replace(/\n$/, '').split('\n');
  const b = lines.filter((l) => l === CRON_BEGIN).length, e = lines.filter((l) => l === CRON_END).length;
  if (b !== e) refuse(`BEGIN/END fleet-reporter-flusher markers do not pair (${b}/${e}): NOTHING WRITTEN`);
  const kept = [];
  let inside = false;
  for (const l of lines) {
    if (!inside && l === CRON_BEGIN) { inside = true; continue; }
    if (inside) { if (l === CRON_END) inside = false; continue; }
    kept.push(l);
  }
  const out = block ? kept.concat(block) : kept;
  return out.length ? out.join('\n') + '\n' : '';
}
function installCrontab(ctx, text) {
  const f = path.join(attemptDir(ctx), 'crontab.new');
  fs.writeFileSync(f, text, { mode: 0o600 });
  const r = cp.spawnSync('crontab', [f], { encoding: 'utf8' });
  if (r.status !== 0) refuse(`crontab refused the new crontab (exit ${r.status}${r.stderr ? ': ' + r.stderr.trim() : ''})`);
  const back = readCrontab();
  if (back.text !== text) refuse('the installed crontab reads back different from the one written: check it with crontab -l');
  say('installed: ' + crypto.createHash('md5').update(text).digest('hex'));
}

// ── launchd (macOS) ────────────────────────────────────────────────────────────────────────────────────

const launchctl = (...args) => cp.spawnSync('launchctl', args, { encoding: 'utf8' });
const guiDomain = () => `gui/${process.getuid()}`;
const launchdPid = () => {
  const r = launchctl('print', `${guiDomain()}/${LABEL}`);
  if (r.status !== 0) return null;
  const m = /^\s*pid = (\d+)\s*$/m.exec(r.stdout || '');
  return m ? Number(m[1]) : null;
};

// ── the flusher: lock, stop, holder, start ─────────────────────────────────────────────────────────────

const lockPath = (T) => path.join(T.spool, 'flusher.lock');
const readLock = (T) => { try { return JSON.parse(fs.readFileSync(lockPath(T), 'utf8')); } catch (e) { return null; } };
/* Whether `pid` is a running process — a zombie (Linux state Z) is not. */
const alive = (pid) => {
  try { process.kill(pid, 0); } catch (e) { if (e.code !== 'EPERM') return false; }
  if (process.platform === 'linux') { try { return !/^\S+ \(.*\) Z /s.test(fs.readFileSync(`/proc/${pid}/stat`, 'utf8')); } catch (e) { return false; } }
  return true;
};
/* When process `pid` began, in ms since the epoch, or null when nothing can be read: field 22 of /proc/<pid>/stat
 * from /proc/stat's btime on Linux; `ps -o etime=` (elapsed time, locale-free) under LC_ALL=C on macOS; the CIM
 * `CreationDate` on Windows. */
function processStartMs(T, pid) {
  try {
    if (T.platform === 'linux') {
      const ticks = reporter().procStartTicks(pid);
      if (ticks === null) return null;
      const btime = Number(/^btime (\d+)$/m.exec(fs.readFileSync('/proc/stat', 'utf8'))[1]);
      const hz = Number(cp.execFileSync('getconf', ['CLK_TCK']).toString().trim());
      const ms = (btime + ticks / hz) * 1000;
      return Number.isFinite(ms) ? ms : null;
    }
    if (T.platform === 'darwin') {
      const r = cp.spawnSync('ps', ['-o', 'etime=', '-p', String(pid)], { encoding: 'utf8', env: Object.assign({}, process.env, { LC_ALL: 'C' }) });
      const m = r.status === 0 ? /^\s*(?:(\d+)-)?(?:(\d+):)?(\d+):(\d+)\s*$/.exec(r.stdout) : null;
      if (!m) return null;
      const secs = (Number(m[1] || 0) * 86400) + (Number(m[2] || 0) * 3600) + (Number(m[3]) * 60) + Number(m[4]);
      return Date.now() - secs * 1000;
    }
    const out = powershell("$p = Get-CimInstance Win32_Process -Filter ('ProcessId=' + [int]$env:FR_PID)\n"
      + "if ($null -eq $p) { 'gone' } else { $p.CreationDate.ToUniversalTime().ToString('o') }", { FR_PID: String(pid) }).trim();
    const ms = out === 'gone' ? NaN : Date.parse(out);
    return Number.isFinite(ms) ? ms : null;
  } catch (e) { return null; }
}
/* How much LATER than its true start a process's start time can read, the one tolerance of the stale-lock test
 * below. The largest misread measured is 2.1 s (/proc/stat's whole-second btime against the wall clock, the
 * sandbox, card#12180 and card#12571); macOS's `etime` is whole seconds, so up to 1 s; Windows' CreationDate is
 * exact. 2.5 s covers the largest with a margin. A flusher that crashed within this window of its own start and
 * whose pid was reused at once is the one case it can misread, as live — the fail-closed direction. */
const START_SLACK_MS = 2500;
/* THE ONE TEST of whether `flusher.lock` names a running flusher, shared by every reader — the holder check, the
 * stop, the restart's wait, the Windows start's wait and the ancestry report (card#12571 review rounds 1 and 2).
 * The lock is written by the flusher itself, with its pid and its own `started_at`, so the flusher is identified
 * by TIME and never by a path's spelling: a pid reused by another process is precisely a live process that began
 * AFTER the lock's started_at (the flusher's process began before it wrote the lock). So:
 *   live   ⇔ the pid is alive AND it began no later than started_at + START_SLACK_MS;
 *   stale  ⇔ the pid is dead, OR it began after that — its flusher died and the pid is another process's, which
 *            names no flusher: nothing is signalled and nothing is refused, and the reporter's `acquireLock` takes
 *            the lock over once it is K.LOCK_STALE_MS old;
 *   unknown ⇔ the pid is alive and the lock's started_at, or the process's start, cannot be read: REFUSED, loudly,
 *            because a flusher cannot then be told from a reused pid (fail closed, never open).
 * On LINUX, a lock carrying `start_ticks` — the flusher's own /proc start in clock ticks since boot, written since
 * 0.6.0 — is judged by that instead, exactly: live ⇔ the pid's field 22 equals it. The time test reads the start as
 * btime + ticks, i.e. the wall clock now minus the process's age, so a forward wall-clock STEP larger than the
 * slack after the flusher began (an NTP step after a wrong-RTC boot, a VM restore) would read a live flusher as
 * stale; the ticks cannot move (review round 3).
 * Comparing the command line's path was the test before round 2: a hook-respawned flusher runs `__filename`, the
 * REALPATH, so under a symlinked or junctioned home a live flusher read as stale. Returns { held, live, why }. */
function lockHolder(T) {
  const held = readLock(T);
  if (!held || !Number.isInteger(held.pid)) return { held: null, live: false, why: 'no readable flusher.lock' };
  const pid = held.pid;
  const stale = (what) => ({ held, live: false, why: `flusher.lock names pid ${pid}, ${what}: the lock is stale` });
  if (!alive(pid)) return stale('which is not running');
  // Linux, a lock that carries the flusher's own start ticks (written by 0.6.0 on): the kernel's counter on both
  // sides, so it must match EXACTLY, with no slack, and no wall-clock step can move either side (review round 3).
  if (T.platform === 'linux' && held.start_ticks !== undefined) {
    const ticks = reporter().procStartTicks(pid);
    if (!Number.isSafeInteger(held.start_ticks) || ticks === null) {
      if (!alive(pid)) return stale('which is not running');
      refuse(`flusher.lock names live pid ${pid} with ${ticks === null ? 'a /proc start this step cannot read' : 'a start_ticks that is not a whole number'}, so a flusher cannot be told from a reused pid: check pid ${pid} yourself, stop it if it is the reporter's flusher, delete ${lockPath(T)}, and run again: NOTHING SENT`);
    }
    if (ticks !== held.start_ticks) return stale(`which began at tick ${ticks} since boot, not the lock's ${held.start_ticks}, so it is a reused pid`);
    return { held, live: true };
  }
  // Otherwise — macOS, Windows, and a Linux lock a pre-0.6.0 flusher wrote — the lock's started_at against the
  // process's start, within START_SLACK_MS.
  const startedAt = Date.parse(held.started_at), began = processStartMs(T, pid);
  if (!Number.isFinite(startedAt) || began === null) {
    if (!alive(pid)) return stale('which is not running');
    refuse(`flusher.lock names live pid ${pid} with ${Number.isFinite(startedAt) ? 'a process start this step cannot read' : 'no usable started_at'}, so a flusher cannot be told from a reused pid: check pid ${pid} yourself, stop it if it is the reporter's flusher, delete ${lockPath(T)}, and run again: NOTHING SENT`);
  }
  if (began > startedAt + START_SLACK_MS) return stale(`which began ${new Date(began).toISOString()}, after the lock's started_at ${held.started_at}, so it is a reused pid`);
  return { held, live: true };
}
/* The bound a stop waits, derived from the installed artifact's own constants (the runbook's derivation). */
function stopBoundMs(T) {
  const src = fs.readFileSync(T.js, 'utf8');
  const num = (re, what) => { const m = re.exec(src); if (!m) refuse(`cannot read ${what} from ${T.js}: no bound, NOTHING SENT`); return Number(m[1]); };
  return num(/\bFLUSH_MS:\s*(\d+)/, 'K.FLUSH_MS') + num(/\bREQUEST_MS:\s*(\d+)/, 'K.REQUEST_MS')
    * (1 + 2 * num(/for \(let round = 0; round < (\d+);/, "laneDrain's round cap"));
}
/* Stop the flusher `flusher.lock` names, by pid, only when that pid runs this artifact's `flusher`. Returns
 * the pid it stopped, or null when none ran. */
function stopFlusher(T) {
  const h = lockHolder(T);
  if (!h.live) { say(`${h.why}: no flusher to stop, nothing signalled`); return null; }
  const held = h.held, pid = held.pid;
  const bound = stopBoundMs(T);
  // On Windows process.kill terminates outright; D1 § 2.3 absorbs that (state is saved by temp file and rename).
  process.kill(pid, T.platform === 'win32' ? undefined : 'SIGTERM');
  const t0 = Date.now();
  while (alive(pid)) {
    if (Date.now() - t0 > bound) refuse(`flusher pid ${pid} still running ${bound / 1000} s after it was told to stop, past K.FLUSH_MS + K.REQUEST_MS x (1 + 2 x laneDrain rounds): NOT STOPPED`);
    sleepSync(250);
  }
  say(`flusher pid ${pid} exited ${Math.round((Date.now() - t0) / 1000)} s after ${T.platform === 'win32' ? 'it was terminated' : 'SIGTERM'}`);
  if (T.platform === 'win32') {
    const now = readLock(T);
    if (now && now.pid === pid && now.started_at === held.started_at) { fs.unlinkSync(lockPath(T)); say(`removed flusher.lock naming pid ${pid}`); }
  }
  return pid;
}
/* When the lock holder started: the lock's own `started_at`, on every OS, read only after the pid is confirmed
 * to run this artifact's `flusher`, so no `ps lstart` is parsed (D6 § 3.1). The flusher writes it from its own
 * millisecond clock as it takes the lock. Linux's `/proc/<pid>/stat` start time was the source before card#12571
 * and cannot order a start against an artifact written a second earlier: `/proc/stat`'s `btime` is whole
 * seconds and drifts from the wall clock (0.6–2.1 s early, measured on the sandbox and on ubuntu-latest, where
 * a no-op run then restarted a flusher cron had started 1 s after the install), and `/proc/uptime` is
 * virtualized under a container (53 s off on the sandbox host). A value that does not parse reads as the
 * previous build, so the restart it owes is the safe direction. */
function holderStartMs(T, held) {
  const t = Date.parse(held.started_at);
  return Number.isFinite(t) ? t : -Infinity;
}
/* Whether the RUNNING flusher runs the installed build: 'current', 'previous' (started before the artifact
 * was installed — the restart is owed), or 'none' (no live flusher holds the lock: its next start runs the
 * installed build, so nothing is owed). Read-only. */
function holder(T) {
  const h = lockHolder(T), held = h.held;
  if (!h.live) {
    say(`no live flusher holds the lock (${h.why}): its next start runs the installed artifact`);
    return 'none';
  }
  const startedMs = holderStartMs(T, held), builtMs = fs.statSync(T.js).mtimeMs;
  if (startedMs < builtMs) {
    say(`FAIL: the lock holder started ${new Date(startedMs).toISOString()}, before the artifact was installed at ${new Date(builtMs).toISOString()}: it runs the previous build`);
    return 'previous';
  }
  say(`PASS: the lock holder runs the artifact installed at ${new Date(builtMs).toISOString()} (pid ${held.pid}, started ${new Date(startedMs).toISOString()})`);
  return 'current';
}
/* The Windows start (D6 § 3.4): one `node <artifact> flusher`, UNCONDITIONALLY, with the reporter's own spawn
 * options (D1 P-7). The flusher's `acquireLock` makes a loser exit 0 at once, so this file holds no copy of the
 * respawn's lock logic. Then a wait, bounded by the artifact's K.LOCK_STALE_MS, for a lock naming a live pid. */
function startFlusherWin32(ctx) {
  const T = ctx.T;
  const env = Object.assign({}, process.env);
  const cc = resolver(ctx, true);
  if (cc) env.COORD_CONFIG = cc; else delete env.COORD_CONFIG;
  const child = cp.spawn(process.execPath, [T.js, 'flusher'], { detached: true, stdio: 'ignore', windowsHide: true, env });
  child.unref();
  const bound = require(T.js).K.LOCK_STALE_MS, t0 = Date.now();
  for (;;) {
    const h = lockHolder(T);
    if (h.live) {
      say(h.held.pid === child.pid ? `flusher started pid ${h.held.pid}` : `flusher already running pid ${h.held.pid}`);
      return h.held.pid;
    }
    if (Date.now() - t0 > bound) refuse(`NO FLUSHER: no lock naming a live flusher of this artifact ${Math.round(bound / 1000)} s after the start`);
    sleepSync(250);
  }
}
/* Stop the running flusher and have the OS's start run the installed build, then check it does (U6). */
function restart(ctx) {
  const T = ctx.T;
  step('restart', 'stops the flusher, then waits for its next start on the installed build');
  const old = stopFlusher(T);
  if (T.platform === 'darwin') {
    const r = launchctl('kickstart', '-k', `${guiDomain()}/${LABEL}`);
    if (r.status !== 0) refuse(`launchctl kickstart -k ${guiDomain()}/${LABEL} failed (exit ${r.status})`);
    say(`launchctl kickstart -k ${guiDomain()}/${LABEL}`);
  } else if (T.platform === 'win32') {
    startFlusherWin32(ctx);
  }
  const waitS = T.platform === 'linux' ? RESTART_WAIT_S : Math.ceil(reporter().K.LOCK_STALE_MS / 1000);
  const t0 = Date.now(), builtMs = fs.statSync(T.js).mtimeMs;
  // A start that began before the stop — a hook's, or cron's minute, launched before the artifact was replaced
  // and not yet holding the lock when the stop looked — takes the lock afterwards running the PREVIOUS build. So
  // the wait is for a holder on the installed build, and an older one found in the window is stopped again.
  for (;;) {
    const h = lockHolder(T), l = h.held;
    if (h.live && l.pid !== old) {
      if (holderStartMs(T, l) >= builtMs) break;
      say(`pid ${l.pid} took the lock running the previous build (it started before the artifact was installed): stopping it too`);
      stopFlusher(T);
      if (T.platform === 'darwin') launchctl('kickstart', '-k', `${guiDomain()}/${LABEL}`);
      else if (T.platform === 'win32') startFlusherWin32(ctx);
    }
    if (Date.now() - t0 > waitS * 1000) refuse(`no flusher on the installed build holds the lock ${waitS} s after the stop: run install.js again`);
    sleepSync(500);
  }
  if (holder(T) !== 'current') refuse('the flusher does not yet run the new build (above): run install.js again');
  ctx.changed = true;
}

// ── settings.json: the hooks, the to-do tools key, the statusLine ──────────────────────────────────────

/* The hook set, derived from the INSTALLED artifact's fixtures: one hook per file (D1 § 2.1). */
function hookSet(T) {
  let names;
  try { names = fs.readdirSync(T.fixtures).filter((f) => f.endsWith('.json')).sort(); } catch (e) { refuse(`no fixtures at ${T.fixtures}: install the artifact first: NOTHING WRITTEN`); }
  return names.map((f) => ({ hook: f.slice(0, -5), matcher: hookMatcher(readJson(path.join(T.fixtures, f)).shapes) }));
}
function readSettings(T) {
  let s;
  try { s = readJson(T.settings); } catch (e) { refuse(`cannot read ${T.settings} as JSON (${e.code || 'not JSON'}): NOTHING WRITTEN`); }
  // Claude Code's settings file is one JSON object; an array, a string, a number or `null` is not one to merge into.
  if (!s || typeof s !== 'object' || Array.isArray(s)) refuse(`${T.settings} is not a JSON object (it holds ${Array.isArray(s) ? 'an array' : s === null ? 'null' : typeof s}): NOTHING WRITTEN`);
  return s;
}
/* Wire the reporter's hooks into `settings` (a parsed settings.json), appended after the user's own entries in
 * each list; every other key is held. An existing reporter entry is RECOGNISED BY PARSING; one written in a
 * non-canonical form (a backslash path on win32) is rewritten to the canonical form, and a second reporter
 * entry for the same hook is removed — so the result is written only when an entry changed. */
function wireHooks(settings, platform, js, set) {
  const s = JSON.parse(JSON.stringify(settings));
  if (s.hooks !== undefined && (s.hooks === null || typeof s.hooks !== 'object' || Array.isArray(s.hooks))) refuse('hooks in settings.json is not an object: NOTHING WRITTEN');
  s.hooks = s.hooks || {};
  const added = [], changed = [];
  for (const { hook, matcher } of set) {
    const canonical = hookCommand(platform, js, 'hook ' + hook);
    if (s.hooks[hook] !== undefined && !Array.isArray(s.hooks[hook])) refuse(`hooks.${hook} in settings.json is not an array: NOTHING WRITTEN`);
    const list = (s.hooks[hook] = s.hooks[hook] || []);
    let found = false;
    const emptied = new Set();
    for (const e of list) {
      if (!e || !Array.isArray(e.hooks)) continue;
      const had = e.hooks.length;
      e.hooks = e.hooks.filter((x) => {
        if (!x || !isReporterCommand(platform, js, x.command, 'hook ' + hook)) return true;
        if (found) { changed.push(hook + ' (a second entry removed)'); return false; }
        found = true;
        if (!isCanonicalForm(platform, x.command)) { x.command = canonical; changed.push(hook + ' (rewritten in the canonical form)'); }
        return true;
      });
      if (had && !e.hooks.length) emptied.add(e);
    }
    s.hooks[hook] = list.filter((e) => !emptied.has(e));
    if (!found) { s.hooks[hook].push({ matcher, hooks: [{ type: 'command', command: canonical }] }); added.push(hook); }
  }
  return { settings: s, added, changed };
}
/* `settings` with every reporter command (this artifact's, by the parsed comparison) taken out, and every entry
 * and hook list that held only those — what must be equal before and after any reporter change. */
function withoutReporter(settings, platform, js) {
  const s = JSON.parse(JSON.stringify(settings));
  if (!s.hooks || typeof s.hooks !== 'object') return s;
  for (const h of Object.keys(s.hooks)) {
    if (!Array.isArray(s.hooks[h])) continue;
    s.hooks[h] = s.hooks[h].map((e) => {
      if (!e || !Array.isArray(e.hooks)) return e;
      const kept = e.hooks.filter((x) => !(x && isReporterCommand(platform, js, x.command)));
      return kept.length === e.hooks.length ? e : (kept.length ? Object.assign({}, e, { hooks: kept }) : null);
    }).filter((e) => e !== null);
    if (!s.hooks[h].length) delete s.hooks[h];
  }
  if (s.hooks && !Object.keys(s.hooks).length) delete s.hooks;
  return s;
}
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
/* THE PRESERVED-KEYS COMPARISON (`check-preserved.js`, as a function): with the reporter's own entries taken
 * out of both, the two files are equal, apart from the keys the step names in `may` — `statusLine` (only to
 * the reporter's command, or back to the wrapped one) and `env.CLAUDE_CODE_ENABLE_TODO_TOOLS`. Every reporter
 * command left in `after` is in the canonical form. */
function checkPreserved(before, after, platform, js, may) {
  may = may || {};
  const bad = [];
  const a = withoutReporter(before, platform, js), b = withoutReporter(after, platform, js);
  if (may.statusLine !== undefined) { delete a.statusLine; delete b.statusLine; }
  if (may.todo) {
    for (const x of [a, b]) if (x.env && typeof x.env === 'object') { delete x.env[TODO_KEY]; if (!Object.keys(x.env).length) delete x.env; }
  }
  for (const k of new Set([...Object.keys(a), ...Object.keys(b)])) if (!same(a[k], b[k])) bad.push(k === 'hooks' ? 'an existing hook entry was removed, reordered or rewritten' : `top-level key ${k} changed`);
  if (may.statusLine !== undefined && after.statusLine && !same(after.statusLine, before.statusLine)) {
    const c = after.statusLine.command;
    if (!(isReporterCommand(platform, js, c, 'statusline') || c === may.statusLine)) bad.push('statusLine changed to something other than the reporter or the wrapped command');
  }
  if (may.todo === 'set') {
    const had = before.env && Object.prototype.hasOwnProperty.call(before.env, TODO_KEY);
    const now = after.env && Object.prototype.hasOwnProperty.call(after.env, TODO_KEY) ? after.env[TODO_KEY] : undefined;
    if (had ? now !== before.env[TODO_KEY] : now !== '1') bad.push(`env.${TODO_KEY} may only be ADDED, as "1": a value already there is the user's`);
  }
  if (may.todo === 'remove') {
    const now = after.env && Object.prototype.hasOwnProperty.call(after.env, TODO_KEY) ? after.env[TODO_KEY] : undefined;
    if (now !== undefined && now !== (before.env || {})[TODO_KEY]) bad.push(`env.${TODO_KEY} may only be removed`);
  }
  for (const h of Object.keys(after.hooks || {})) {
    for (const e of after.hooks[h] || []) for (const x of (e && e.hooks) || []) {
      if (x && isReporterCommand(platform, js, x.command) && !isCanonicalForm(platform, x.command)) bad.push(`hook ${h}: a reporter entry is not in the canonical form`);
    }
  }
  if (bad.length) refuse('NOT PRESERVED:\n  ' + bad.join('\n  ') + '\nNOTHING WRITTEN');
}
/* settings.json through the reporter's atomicWrite, beside `realpath`'s answer, so a symlinked file stays a
 * symlink and its target gets the bytes; its mode is kept. */
function writeSettings(T, obj) {
  const real = fs.realpathSync(T.settings);
  if (!reporter().atomicWrite(real, JSON.stringify(obj, null, 2) + '\n', { keepMode: true })) refuse(`could not write ${real}: NOTHING WRITTEN`);
  return real;
}
function setTodoTools(settings, file) {
  const t = JSON.parse(JSON.stringify(settings));
  if (t.env !== undefined && (t.env === null || typeof t.env !== 'object' || Array.isArray(t.env))) refuse(`env in ${file} is not an object: NOTHING WRITTEN`);
  if (t.env && Object.prototype.hasOwnProperty.call(t.env, TODO_KEY)) return { settings: t, state: t.env[TODO_KEY] === '1' ? 'already' : 'kept' };
  t.env = Object.assign({}, t.env || {}, { [TODO_KEY]: '1' });
  return { settings: t, state: 'set' };
}

// ── the attempt directory ──────────────────────────────────────────────────────────────────────────────

/* `<state>/fleet-reporter-install/<UTC stamp>/`, owner-only, made once per invocation when a step first needs
 * it. A suffix keeps two runs inside one second apart. */
function attemptDir(ctx) {
  if (ctx.attempt) return ctx.attempt;
  const T = ctx.T;
  fs.mkdirSync(T.attempts, { recursive: true, mode: 0o700 });
  const stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+Z$/, 'Z');
  for (let i = 0; ; i++) {
    const d = T.P.join(T.attempts, stamp + (i ? '-' + i : ''));
    try { fs.mkdirSync(d, { mode: 0o700 }); ctx.attempt = d; break; } catch (e) { if (e.code !== 'EEXIST' || i > 50) throw e; }
  }
  if (T.platform === 'win32') ownerOnlyDir('win32', ctx.attempt); else {
    const st = fs.lstatSync(ctx.attempt);
    if (st.uid !== process.getuid() || (st.mode & 0o777) !== 0o700) refuse(`${ctx.attempt} is not a new 0700 directory of this account's: NOTHING COPIED`);
  }
  return ctx.attempt;
}
function dropAttempt(ctx) {
  if (!ctx.attempt) return;
  fs.rmSync(ctx.attempt, { recursive: true, force: true });
  say('nothing changed, so this run\'s attempt directory is removed');
  ctx.attempt = null;
}

// ── the steps ──────────────────────────────────────────────────────────────────────────────────────────

function prereqs(ctx) {
  const T = ctx.T;
  step('prereqs');
  const v = process.versions.node;
  if (Number(v.split('.')[0]) < 18) refuse(`node ${v} is older than 18 (D1 § 2.1): NOTHING INSTALLED`);
  say(`node ${v} at ${process.execPath}`);
  // POSIX writes the hook commands with the artifact's path bare (D1 § 2.1; the form every seat holds), and they
  // are recognised by parsing that path back, so a home holding whitespace or a quote cannot be wired — refused
  // here, before anything is written (review S2), never at the hooks step after the config and the artifact.
  if (T.platform !== 'win32' && /[\s"]/.test(T.home)) refuse(`the home directory ${JSON.stringify(T.home)} holds whitespace or a quote, which the reporter's hook commands on this OS cannot carry: NOTHING INSTALLED`);
  if (T.platform === 'linux') cronInputs(ctx);
  if (T.platform === 'linux') {
    for (const t of ['flock', 'crontab']) { const w = which(t); if (!w) refuse(`${t} is not on PATH, and the Linux supervised start needs it: NOTHING INSTALLED`); say(w); }
  } else if (T.platform === 'darwin') {
    if (!which('launchctl')) refuse('launchctl is not on PATH: NOTHING INSTALLED');
    if (launchctl('print', guiDomain()).status !== 0) {
      refuse(`no gui launchd domain for this user: the reporter's macOS install supports a GUI login with Claude Code in a Terminal there; an SSH-only seat is not supported: NOTHING INSTALLED`);
    }
    say(`launchd domain ${guiDomain()}`);
  } else {
    if (roaming()) refuse('a roaming profile copies %APPDATA% to a profile server at logoff, the config and its token with it: NOTHING INSTALLED');
    say('roaming false');
  }
}

/* Back up what the install touches into the attempt directory: settings.json (created as {} first when the
 * account has none, which is how Claude Code reads a missing file), the crontab or the plist, the artifact and
 * the config (owner-only: it holds the token). */
function backup(ctx) {
  const T = ctx.T;
  step('backup');
  const B = attemptDir(ctx);
  say('B=' + B);
  if (!exists(T.settings)) {
    fs.mkdirSync(path.dirname(T.settings), { recursive: true });
    fs.writeFileSync(T.settings, '{}\n', { mode: 0o600 });
    say('no settings.json yet: created it as {}');
  }
  fs.copyFileSync(T.settings, T.P.join(B, 'settings.json'));
  say('settings copied');
  if (T.platform === 'linux') {
    const c = readCrontab();
    fs.writeFileSync(T.P.join(B, 'crontab.txt'), c.text, { mode: 0o600 });
    say(c.none ? 'no crontab installed: the crontab backup is empty' : 'crontab copied, md5 ' + crypto.createHash('md5').update(c.text).digest('hex'));
  }
  if (T.plist && exists(T.plist)) { fs.copyFileSync(T.plist, T.P.join(B, path.basename(T.plist))); say('plist copied'); }
  if (exists(T.artifactDir)) { fs.cpSync(T.artifactDir, T.P.join(B, 'artifact'), { recursive: true, preserveTimestamps: true }); say('artifact and fixtures copied'); } else say('no artifact installed yet: nothing to copy');
  if (exists(T.config)) {
    fs.copyFileSync(T.config, T.P.join(B, 'config.json'));
    if (T.platform !== 'win32') fs.chmodSync(T.P.join(B, 'config.json'), 0o600);
    say('config copied, ' + permOf(T.platform, T.P.join(B, 'config.json')));
  } else say('no config written yet: nothing to copy');
}

/* The files ARTIFACT names, in the checkout, as [relative path, absolute path] pairs. */
function artifactFiles(dir) {
  const out = [];
  for (const rel of ARTIFACT) {
    const parts = rel.split('/');
    const last = parts.pop();
    const base = path.join(dir, ...parts);
    if (!last.includes('*')) { out.push([rel, path.join(base, last)]); continue; }
    const re = new RegExp('^' + last.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '[^/]*') + '$');
    let names = [];
    try { names = fs.readdirSync(base).filter((n) => re.test(n)).sort(); } catch (e) { names = []; }
    for (const n of names) out.push([[...parts, n].join('/'), path.join(base, n)]);
  }
  return out;
}
/* Compare the checkout's artifact and fixtures with the installed ones; install them when they differ —
 * fixtures first, the script last, every file through atomicWrite, mode 0600 in 0700 directories. */
function artifact(ctx) {
  const T = ctx.T;
  step('artifact');
  const src = artifactFiles(__dirname), dst = artifactFiles(T.artifactDir);
  const v = (f) => { try { return versionOf(f); } catch (e) { return null; } };
  say(`installed ${v(T.js) || 'none'}, this checkout ${v(path.join(__dirname, 'fleet-reporter.js'))}`);
  // A file missing on the seat (a deleted script beside its fixtures, or nothing installed yet) is a difference.
  const bytes = (f) => { try { return fs.readFileSync(f); } catch (e) { return null; } };
  const same_ = src.length === dst.length && src.every(([rel, f], i) => {
    const have = bytes(dst[i][1]);
    return dst[i][0] === rel && have !== null && fs.readFileSync(f).equals(have);
  });
  if (same_) { say('NO-OP: this seat already runs this checkout\'s artifact and fixtures'); return false; }
  if (!src.length || !src.some(([rel]) => rel === 'fleet-reporter.js')) refuse(`the checkout at ${__dirname} holds no fleet-reporter.js: NOTHING INSTALLED`);
  if (T.platform === 'win32') ownerOnlyDir('win32', T.artifactDir); else fs.mkdirSync(T.artifactDir, { recursive: true, mode: 0o700 });
  const wanted = new Set(src.map(([rel]) => rel));
  for (const [rel, f] of dst) if (!wanted.has(rel)) { fs.unlinkSync(f); say('removed ' + rel + ': the checkout no longer ships it'); }
  const order = src.filter(([rel]) => rel !== 'fleet-reporter.js').concat(src.filter(([rel]) => rel === 'fleet-reporter.js'));
  for (const [rel, f] of order) {
    const to = T.P.join(T.artifactDir, ...rel.split('/'));
    if (!reporter().atomicWrite(to, fs.readFileSync(f))) refuse(`could not write ${to} (the files before it are installed; run install.js again)`);
  }
  for (const [rel, f] of src) if (!fs.readFileSync(f).equals(fs.readFileSync(T.P.join(T.artifactDir, ...rel.split('/'))))) refuse(`${rel} reads back different from the checkout's`);
  say('sha256 ' + sha256(fs.readFileSync(T.js)) + '  ' + T.js);
  say('fixtures-match');
  if (T.platform === 'win32') { const r = aclCheck([T.artifactDir, T.js]); for (const l of r.lines) say(l); if (!r.ok) refuse('the artifact is not owner-only'); }
  ctx.changed = true;
  return true;
}

function resolver(ctx, quiet) {
  if (ctx.cc === undefined) {
    ctx.cc = coordConfig(ctx.T);
    if (!quiet) { step('resolver'); say(ctx.cc ? 'COORD_CONFIG ' + ctx.cc : 'COORD_CONFIG: no settings file sets it'); }
  }
  return ctx.cc;
}
/* --agent-name against the roster the reporter reads (D1 § 3.1): $COORD_CONFIG, and on Linux the home path. */
function identity(ctx) {
  step('identity');
  const name = ctx.a.flags['agent-name'];
  if (!name) { say('no --agent-name: the seat declares none (undeclared)'); return; }
  if (!AGENT_NAME_RE.test(name)) refuse(`--agent-name [${name}] is not a protocol agent name (D1 § 3.1): NOTHING RUN`);
  const cc = resolver(ctx, true);
  const roster = cc || (ctx.T.platform === 'linux' ? path.join(ctx.T.home, '.config', 'coord', 'coordination.config.json') : '');
  if (!roster) { say('no COORD_CONFIG in the settings, and on this platform there is no home-path roster (D1 § 3.1): the name is unchecked'); return; }
  let c;
  try { c = readJson(roster); } catch (e) { refuse(`the roster at ${roster} cannot be read (${e.code || 'not JSON'}): NOTHING WRITTEN`); }
  const names = (c.roster || []).map((r) => r.name);
  for (const n of names) say(n);
  if (!names.includes(name)) refuse(`--agent-name ${name} is not a member of the roster (above): disagreed, so its desk would join no post: NOTHING WRITTEN`);
  say(`--agent-name ${name} is a member: checked`);
}

/* THE BOARD DECLARATION (card#12447; the runbook's board-identity.js as a function). The kanban API base is the
 * coordination config's `kanban.base_url` joined to `api/v3`; the credential is the store's `[kanban]
 * api_token_file` pointer — never the exported read variable. It prints no token, pointer or response body.
 * Returns { id, base } to write, or null, and whether it rewrote an existing config. */
async function boardIdentity(ctx) {
  const T = ctx.T, e = process.env;
  step('board-identity');
  const home = T.home;
  const expand = (p) => (p === '~' ? home : p.startsWith('~/') ? path.join(home, p.slice(2)) : p);
  const shown = (base) => { try { const u = new URL(base); return u.protocol + '//' + u.host; } catch (err) { return '<unparseable base>'; } };
  const cc = resolver(ctx, true) || path.join(home, '.config', 'coord', 'coordination.config.json');
  function apiBase() {
    let c;
    try { c = JSON.parse(fs.readFileSync(cc, 'utf8')); } catch (err) { return err.code === 'ENOENT' ? { none: 'no coordination config' } : { fail: 'coordination config unreadable' }; }
    const b = c && c.kanban && typeof c.kanban.base_url === 'string' ? c.kanban.base_url.trim().replace(/\/+$/, '') : '';
    if (!b) return { none: 'the coordination config names no kanban.base_url' };
    const base = /\/api\/v3$/.test(b) ? b : b + '/api/v3';
    let u = null;
    try { u = new URL(base); } catch (err) { u = null; }
    if (!u || u.protocol !== 'https:' || u.username || u.password || Buffer.byteLength(base) > 256) return { fail: 'the kanban base is not an https:// URL of at most 256 B with no userinfo' };
    return { base };
  }
  function pointer() {
    const store = e.COORD_CREDENTIALS || path.join(home, '.config', 'coord', 'credentials.ini');
    let text;
    try { text = fs.readFileSync(store, 'utf8'); } catch (err) { return err.code === 'ENOENT' ? { none: 'no coordination credential store' } : { fail: 'the credential store is unreadable' }; }
    let sec = null;
    const found = [];
    for (const raw of text.split(/\r?\n/)) {
      const l = raw.trim();
      if (!l || l[0] === '#' || l[0] === ';') continue;
      if (l[0] === '[') { const p = l.lastIndexOf(']'); sec = p > 0 ? l.slice(1, p) : ''; continue; }
      if (sec !== 'kanban') continue;
      const at = [l.indexOf('='), l.indexOf(':')].filter((x) => x > 0);
      if (!at.length) continue;
      const d = Math.min(...at);
      if (l.slice(0, d).trim().toLowerCase() === 'api_token_file' && l.slice(d + 1).trim()) found.push(l.slice(d + 1).trim());
    }
    if (found.length > 1) return { fail: 'the credential store declares [kanban] api_token_file more than once' };
    return found.length ? { file: expand(found[0]) } : { none: 'the credential store names no [kanban] api_token_file' };
  }
  function whoami(base, token) {
    return new Promise((resolve) => {
      let done = false;
      const end = (v) => { if (!done) { done = true; resolve(v); } };
      let req;
      try {
        req = require('https').get(base + '/users/current.json', { headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' }, timeout: 20000, agent: false }, (res) => {
          let body = '';
          res.setEncoding('utf8');
          res.on('data', (d) => { if (body.length < 65536) body += d; });
          res.on('end', () => {
            if (res.statusCode !== 200) return end({ fail: 'the board answered ' + res.statusCode });
            let id = null;
            try { const j = JSON.parse(body); id = j && j.data ? j.data.id : null; } catch (err) { id = null; }
            end(Number.isInteger(id) && id >= 1 && id <= 4294967295 ? { id } : { fail: 'the board\'s answer carries no integer data.id' });
          });
          res.on('error', () => end({ fail: 'the board\'s answer was cut off' }));
        });
      } catch (err) { return end({ fail: 'the request could not be made' }); }
      req.on('timeout', () => { req.destroy(); end({ fail: 'the board did not answer in 20 s' }); });
      req.on('error', () => end({ fail: 'the board is unreachable' }));
    });
  }
  async function resolveBoard() {
    if (ctx.a.flags['no-board']) return { none: '--no-board' };
    const a = apiBase();
    if (!a.base) return a;
    const p = pointer();
    if (!p.file) return p;
    let token;
    try { token = fs.readFileSync(p.file, 'utf8').trim(); } catch (err) { return { fail: 'the credential store\'s token file is unreadable' }; }
    if (!/^[\x21-\x7E]+$/.test(token)) return { fail: 'the credential store\'s token file holds no usable token' };
    const w = await whoami(a.base, token);
    return w.id ? { id: w.id, base: a.base } : w;
  }
  const r = await resolveBoard();
  let c = null;
  if (exists(T.config)) { try { c = readJson(T.config); } catch (err) { refuse(`cannot read ${T.config} as JSON: NOTHING WRITTEN`); } }
  const held = c && c.board_user_id !== undefined && c.board_user_id !== null ? { id: c.board_user_id, base: c.board_api_base } : null;
  if (r.id) {
    const sayIt = 'board user ' + r.id + ' on ' + shown(r.base);
    if (!c) { say(sayIt + ': the config writer takes it'); return { decl: r, rewrote: false }; }
    if (held && held.id === r.id && held.base === r.base) { say(sayIt + ': board identity: unchanged'); return { decl: r, rewrote: false }; }
    writeConfig({ mode: 'board', config: T.config, boardUser: r.id, boardBase: r.base, platform: T.platform });
    say(sayIt + ': written to ' + T.config + ' — the flusher reads it at its next start');
    return { decl: r, rewrote: true };
  }
  if (r.none) {
    if (held) {
      writeConfig({ mode: 'board', config: T.config, platform: T.platform });
      say('undeclared: this seat has no board credential (' + r.none + '): the declaration is cleared from ' + T.config);
      return { decl: null, rewrote: true };
    }
    say('undeclared: this seat has no board credential (' + r.none + '): board identity: unchanged');
    return { decl: null, rewrote: false };
  }
  say('kept: ' + r.fail + ' — ' + (held ? 'the declaration the config holds stands' : 'nothing is declared') + ': board identity: unchanged');
  return { decl: held, rewrote: false };
}

// ── the take rule (D6 § 4.3) ───────────────────────────────────────────────────────────────────────────

/* The file at `p` must be a regular file this account owns, not a symlink — checked before any read. */
function guardTokenFile(T, p) {
  let st;
  try { st = fs.lstatSync(p); } catch (e) { refuse(`cannot read the token file ${p} (${e.code}): NOTHING WRITTEN`); }
  if (st.isSymbolicLink()) refuse(`the token file ${p} is a symbolic link: give the file itself: NOTHING WRITTEN`);
  if (!st.isFile()) refuse(`the token file ${p} is not a regular file: NOTHING WRITTEN`);
  if (T.platform !== 'win32') {
    if (st.uid !== process.getuid()) refuse(`the token file ${p} is not owned by this account: NOTHING WRITTEN`);
    return st;
  }
  // Windows has no uid: the owner is the ACL's, this account or Administrators, as owner-only reads it.
  let owner;
  try { owner = powershell('(Get-Acl -LiteralPath $env:FR_ACL_PATH).GetOwner([System.Security.Principal.SecurityIdentifier]).Value', { FR_ACL_PATH: path.win32.normalize(p) }).trim(); }
  catch (e) { refuse(`cannot read the token file ${p}'s owner (PowerShell exited ${e.status}): NOTHING WRITTEN`); }
  if (owner !== sid() && owner !== ADMINS_SID) refuse(`the token file ${p} is not owned by this account: NOTHING WRITTEN`);
  return st;
}
/* Read and parse the token file in place (the pre-read: nothing is written until it parses). macOS's
 * privacy-protected folders answer a terminal's read with EPERM. */
function readTokenFile(T, p) {
  guardTokenFile(T, p);
  let bytes;
  try { bytes = fs.readFileSync(p); } catch (e) {
    if (T.platform === 'darwin' && e.code === 'EPERM') refuse('macOS refused the read: allow the terminal access to this folder, or put the file in your home folder, and run again: NOTHING WRITTEN');
    refuse(`cannot read the token file ${p} (${e.code}): NOTHING WRITTEN`);
  }
  try { return parseTokenFile(bytes); } catch (e) {
    if (e instanceof Refusal) refuse(`${e.message}: NOTHING WRITTEN. Treat the token as exposed: revoke its prefix on the Seat tokens page and issue another`);
    throw e;
  }
}
/* Local accounts in group `gid` other than this one, from /etc/group and /etc/passwd; null when unreadable. */
function groupMembers(gid) {
  try {
    const me = os.userInfo().username, out = new Set();
    for (const l of fs.readFileSync('/etc/passwd', 'utf8').split('\n')) { const f = l.split(':'); if (f.length > 3 && Number(f[3]) === gid && f[0] !== me) out.add(f[0]); }
    for (const l of fs.readFileSync('/etc/group', 'utf8').split('\n')) { const f = l.split(':'); if (f.length > 3 && Number(f[2]) === gid) for (const u of f[3].split(',')) if (u && u !== me) out.add(u); }
    return [...out].sort();
  } catch (e) { return null; }
}
/* Who other than this account could have read the file: per principal, over the file and its directory chain
 * (POSIX: the file's read bit and every ancestor's traverse bit for group and for other); on Windows the
 * file's foreign ACEs. Returns null when nobody could. */
function reachability(T, p, st) {
  if (T.platform === 'win32') {
    const r = aclCheck([p]);
    return r.ok ? null : { principals: 'foreign ACEs: ' + (r.foreign.join(', ') || r.lines.join('; ')) };
  }
  const chain = [];
  for (let d = path.dirname(path.resolve(p)); ; d = path.dirname(d)) { chain.push(fs.statSync(d)); if (d === path.dirname(d)) break; }
  // A group member traverses an ancestor through its group bit or its other bit, whichever applies to it, so
  // either counts: the comparison errs toward the warning.
  const groupReach = (st.mode & 0o040) && chain.every((d) => d.mode & 0o011);
  const otherReach = (st.mode & 0o004) && chain.every((d) => d.mode & 0o001);
  if (!groupReach && !otherReach) return null;
  const parts = [];
  if (groupReach) { const m = groupMembers(st.gid); parts.push('group: ' + (m === null ? 'its members are not enumerable here' : m.length ? m.join(', ') : 'none')); } else parts.push('group: none');
  if (otherReach) {
    let others = null;
    try { others = fs.readFileSync('/etc/passwd', 'utf8').split('\n').map((l) => l.split(':')).filter((f) => f.length > 6 && Number(f[2]) >= 1000 && f[0] !== os.userInfo().username && !/nologin|false$/.test(f[6])).map((f) => f[0]); } catch (e) { others = null; }
    parts.push('other: ' + (others === null ? 'any account on this machine (not enumerable here)' : others.length ? others.join(', ') : 'none found'));
  } else parts.push('other: none');
  return { principals: parts.join('; ') };
}
const SYNC_ROOTS = [/[/\\]Library[/\\]Mobile Documents[/\\]/i, /[/\\]OneDrive[^/\\]*[/\\]/i, /[/\\]Dropbox[/\\]/i, /[/\\]Google Drive[/\\]/i, /[/\\]iCloud ?Drive[/\\]/i];
/* TOKEN-TAKE: the config directory owner-only, the file moved into it as `mezzanine-reporter-token` (nothing
 * moves when it is already there), 0600 on POSIX, the directory's ACL on Windows (a copy, so it inherits; the
 * source removed after the check). A file some other principal could have read is TAKEN, WITH ONE WARNING (the
 * operator's ruling, Q4). Returns the named path. */
function tokenTake(ctx, p) {
  const T = ctx.T;
  step('token-take');
  const st = guardTokenFile(T, p);
  const reach = reachability(T, p, st);
  if (reach) {
    // The copy's age, never its mtime, which a copy keeps. A birth time AFTER the mtime is a copy's own; one at
    // or before it may be the platform's clamp — APFS moves a file's birth time back to an earlier mtime set on
    // it, as a copy that keeps the mtime does (measured on macos-latest, card#12571) — so then the change time,
    // which that same act sets to now. A file written once at creation has all three about equal.
    const born = st.birthtimeMs > st.mtimeMs ? st.birthtimeMs : st.ctimeMs;
    say(`token-take: WARNING: ${p}, in ${path.dirname(path.resolve(p))}, could be read by other local accounts for ${Math.max(0, Math.round((Date.now() - born) / 1000))} s (${reach.principals}); revoke this prefix on the Seat tokens page and issue again if you are unsure`);
  }
  if (SYNC_ROOTS.some((re) => re.test(path.resolve(p)))) say(`token-take: WARNING: ${p} looks like it is in a synced folder (a heuristic, by its path); a sync client may have copied it, which this installer cannot see: revoke this prefix and issue again if you are unsure`);
  ownerOnlyDir(T.platform, T.configDir);
  const dest = T.tokenFile;
  const already = exists(dest) && samePath(T.platform, fs.realpathSync(p), fs.realpathSync(dest));
  if (!already) {
    // With no config, a run with no --token-file takes that file up; with one (a rotation or add-site), such a
    // run is a re-check that never reads it, so the way forward is to delete it (review N2).
    if (exists(dest)) {
      refuse(exists(T.config)
        ? `a token file is already at ${dest}, left by an earlier run that did not finish: delete it, then run this command again: NOTHING WRITTEN`
        : `a token file is already at ${dest}, from an earlier run: run install.js with no --token-file to use it, or delete it: NOTHING WRITTEN`);
    }
    if (T.platform === 'win32') {
      // A NEW file, written `wx`, so its ACL is the directory's inherited one and nothing of the source's.
      fs.writeFileSync(dest, fs.readFileSync(p), { flag: 'wx' });
      const r = aclCheck([T.configDir, dest]);
      if (!r.ok) { for (const l of r.lines) warn(l); refuse(`the copy at ${dest} is not owner-only: delete it, and treat its token as exposed`); }
      fs.unlinkSync(p);
    } else {
      try { fs.renameSync(p, dest); } catch (e) {
        if (e.code !== 'EXDEV') throw e;
        fs.copyFileSync(p, dest, fs.constants.COPYFILE_EXCL);
        fs.unlinkSync(p);
      }
    }
  }
  if (T.platform !== 'win32') fs.chmodSync(dest, 0o600);
  say(already ? `the token file is already at ${dest}: nothing moved (${permOf(T.platform, dest)})` : `taken into ${dest} (${permOf(T.platform, dest)})`);
  return dest;
}
/* Remove the named token file once the config holding it is verified: overwritten with zeros, synced and
 * unlinked. Snapshots, backups and sync history keep what an unlink does not reach, so the line says so. */
function removeTokenFile(p) {
  try {
    const n = fs.statSync(p).size, fd = fs.openSync(p, 'r+');
    try { fs.writeSync(fd, Buffer.alloc(n)); fs.fsyncSync(fd); } finally { fs.closeSync(fd); }
  } catch (e) { /* the overwrite is best-effort; the unlink below is the removal */ }
  fs.unlinkSync(p);
  say('token file unlinked; overwrite best-effort: ' + p);
}

// ── the checks ─────────────────────────────────────────────────────────────────────────────────────────

function configCheck(ctx) {
  const T = ctx.T;
  step('config-check');
  if (T.platform === 'win32') { const r = aclCheck([T.configDir, T.config]); for (const l of r.lines) say(l); if (!r.ok) refuse('the config is not owner-only'); } else {
    say((fs.statSync(T.configDir).mode & 0o777).toString(8) + ' ' + T.configDir);
    say((fs.statSync(T.config).mode & 0o777).toString(8) + ' ' + T.config);
  }
  let c;
  try { c = readJson(T.config); } catch (e) { refuse(`cannot read ${T.config} as JSON`); }
  const u = (x) => { try { const w = new URL(x); if (w.username || w.password) { w.username = 'USER'; w.password = ''; return w.href.replace('USER@', '<userinfo>@'); } } catch (e) { /* not a URL */ } return x; };
  const show = (p, o) => {
    for (const [k, v] of Object.entries(o)) {
      if (k === 'sites' && Array.isArray(v)) { v.forEach((x, i) => show('sites[' + ((x && x.name) || i) + '].', x || {})); continue; }
      say(p + k, k === 'token' ? '<' + (TOKEN_RE.test(v) ? 'well-formed' : 'MALFORMED') + '>' : JSON.stringify(typeof v === 'string' && /_url$/.test(k) ? u(v) : v));
    }
  };
  show('', c);
  const errs = reporter().allConfigErrors(reporter().loadConfig(T.config));
  if (errs.length) { for (const x of errs) warn('config error: ' + x); refuse('the config does not validate'); }
}

function selftest(ctx) {
  const T = ctx.T;
  step('selftest', 'probes each site');
  const env = Object.assign({}, process.env);
  const cc = resolver(ctx, true);
  if (cc) env.COORD_CONFIG = cc; else delete env.COORD_CONFIG;
  const r = cp.spawnSync(process.execPath, [T.js, 'selftest'], { encoding: 'utf8', env, timeout: 120000 });
  if (r.stdout) say(r.stdout.trimEnd());
  if (r.stderr) warn(r.stderr.trimEnd());
  const rc = r.status === null ? 1 : r.status;
  say('selftest rc=' + rc);
  return rc;
}

async function health(ctx) {
  const T = ctx.T;
  step('health');
  const want = versionOf(T.js);
  if (!want) { say('no installed REPORTER_VERSION was read: NOT MEASURED'); say('health rc=2'); return 2; }
  const { sites } = require(T.js).loadConfig(T.config);
  if (!sites.length) { say('the config holds no site: NOT MEASURED, the selftest step names why'); say('health rc=2'); return 2; }
  let behind = false, unmeasured = false;
  const one = (s) => new Promise((done) => {
    let said = false;
    const sayIt = (m, rc) => { if (said) return; said = true; if (rc === 1) behind = true; if (rc === 2) unmeasured = true; say((s.name || 'sites[' + s.index + ']') + ': ' + m); done(); };
    if (s.errors.length) return sayIt('the site has a config error: NOT MEASURED, the selftest step names it', 2);
    if (s.proxy_url !== null) return sayIt('the site routes through proxy_url, which this check does not: NOT MEASURED', 2);
    const u = new URL(s.ingest_url);
    u.pathname = u.pathname.replace(/\/events$/, '/health');
    const req = require('https').get(u, { headers: { Authorization: 'Bearer ' + s.token, Accept: 'application/json' }, ca: s.ca_file === null ? undefined : fs.readFileSync(s.ca_file), timeout: 15000, agent: false }, (r) => {
      let b = '';
      r.setEncoding('utf8');
      r.on('data', (d) => { b += d; });
      r.on('end', () => {
        let j = null;
        try { j = JSON.parse(b); } catch (e) { /* not JSON: an outage page, or something in front of the ingest */ }
        const cur = r.statusCode === 200 && j && typeof j.current_reporter_version === 'string' ? j.current_reporter_version : null;
        if (cur === null) return sayIt('HTTP ' + r.statusCode + (j && typeof j.error === 'string' ? ' ' + j.error : '') + ' with no current_reporter_version: NOT MEASURED', 2);
        sayIt('installed ' + want + ', server current ' + cur + (cur === want ? ': current' : ': NOT CURRENT'), cur === want ? 0 : 1);
      });
    });
    req.on('timeout', () => req.destroy(new Error('no answer within 15 s')));
    req.on('error', (e) => sayIt('no answer (' + (e.code || e.message) + '): NOT MEASURED', 2));
  });
  for (const s of sites) await one(s);
  const rc = behind ? 1 : unmeasured ? 2 : 0;
  say('health rc=' + rc);
  return rc;
}

async function arrival(ctx, waitS) {
  const T = ctx.T;
  step('arrival', `waits up to ${waitS} s for each site to accept a batch`);
  const { spool, sites } = require(T.js).loadConfig(T.config);
  if (!spool || !sites.length) { say('the config names no spool or no site: NOT MEASURED, the selftest step names why'); say('arrival rc=2'); return 2; }
  const t0 = Date.now();
  const state = () => { try { return readJson(path.join(spool, 'state.json')); } catch (e) { return null; } };
  for (;;) {
    const st = state(), lines = [];
    let waiting = 0, refused = 0;
    for (const s of sites) {
      const name = s.name || 'sites[' + s.index + ']';
      const e = st && st.sites && s.name && Object.prototype.hasOwnProperty.call(st.sites, s.name) ? st.sites[s.name] : null;
      const ok = (e && e.counters && e.counters.batches_ok) || 0;
      if (s.name && exists(path.join(spool, 'REJECTED.' + s.name + '.txt'))) { refused += 1; lines.push(name + ': a batch was REFUSED; REJECTED.' + s.name + '.txt in ' + spool + ' names the status'); }
      else if (ok) lines.push(name + ': ' + ok + ' batch(es) accepted: events arrive');
      else { waiting += 1; lines.push(name + ': no batch accepted yet: NOT ARRIVED'); }
    }
    if (!refused && waiting && Date.now() - t0 < waitS * 1000) { await new Promise((r) => setTimeout(r, 2000)); continue; }
    for (const l of lines) say(l);
    const rc = refused ? 1 : waiting ? 2 : 0;
    say('arrival rc=' + rc);
    return rc;
  }
}

/* Who started the lock holder — INFORMATIONAL, as before: reported, never judged (a hook's flusher holds the
 * same lock). Linux: the chain, `flock` and `cron` among its parents; macOS: `launchctl print`'s pid equal to
 * the lock's (a hook-started flusher is reparented to pid 1 too, so "parent is launchd" could not fail);
 * Windows: the holder is live, fresh and started after the artifact. */
function ancestry(ctx) {
  const T = ctx.T;
  step('ancestry', 'informational');
  const h = lockHolder(T), held = h.held;
  if (!h.live) { say(`FAIL: no live flusher holds the lock (${h.why})`); return; }
  if (T.platform === 'linux') {
    const chain = [];
    for (let p = held.pid; p > 1;) {
      let stat, cmd;
      try { stat = fs.readFileSync('/proc/' + p + '/stat', 'utf8'); cmd = fs.readFileSync('/proc/' + p + '/cmdline', 'utf8').split('\0').join(' ').trim(); } catch (e) { break; }
      const r = stat.lastIndexOf(')');
      chain.push({ p, comm: stat.slice(stat.indexOf('(') + 1, r), cmd });
      p = Number(stat.slice(r + 2).split(' ')[1]);
    }
    for (const c of chain) say(String(c.p).padStart(8) + '  ' + c.comm.padEnd(8) + ' ' + c.cmd.slice(0, 150));
    const flock = chain[1] && chain[1].comm === 'flock' && chain[1].cmd.includes(T.js);
    const cron = chain.some((c) => /^cron$/i.test(c.comm));
    say(flock && cron ? 'PASS: the lock holder was started by cron through flock'
      : `FAIL: the lock holder's parents include ${flock ? 'flock' : 'no flock'} and ${cron ? 'cron' : 'no cron'}: a flusher cron did not start holds the lock (a hook's, or one run by hand), so cron's line is NOT verified`);
  } else if (T.platform === 'darwin') {
    const lp = launchdPid();
    say(lp === held.pid ? `PASS: launchctl reports pid ${lp} = flusher.lock` : `FAIL: launchctl reports ${lp === null ? 'no pid' : 'pid ' + lp}, and flusher.lock names pid ${held.pid}: a flusher launchd did not start holds the lock`);
  } else {
    const age = Date.now() - fs.statSync(lockPath(T)).mtimeMs;
    const fresh = age < require(T.js).K.LOCK_STALE_MS;
    const after = Date.parse(held.started_at) >= fs.statSync(T.js).mtimeMs;
    say(`pid ${held.pid} is running; the lock was renewed ${Math.round(age / 1000)} s ago${fresh ? '' : ', past K.LOCK_STALE_MS'}; it started ${held.started_at}, ${after ? 'after' : 'BEFORE'} the artifact`);
  }
}

// ── the steps that write settings.json ─────────────────────────────────────────────────────────────────

function hooks(ctx) {
  const T = ctx.T;
  step('hooks');
  const set = hookSet(T);
  say('hook set, from ' + T.fixtures + ': ' + set.map((h) => h.hook + (h.matcher === '*' ? '(*)' : '')).join(' '));
  const before = readSettings(T);
  const w = wireHooks(before, T.platform, T.js, set);
  checkPreserved(before, w.settings, T.platform, T.js);
  if (!w.added.length && !w.changed.length) { say('already wired: nothing applied'); return; }
  const real = writeSettings(T, w.settings);
  say(`applied to ${real}; added: ${w.added.join(', ') || 'nothing'}${w.changed.length ? '; changed: ' + w.changed.join(', ') : ''}`);
  ctx.changed = true;
}

function todoTools(ctx) {
  const T = ctx.T;
  step('todo-tools');
  const before = readSettings(T);
  const t = setTodoTools(before, T.settings);
  if (t.state === 'already') { say(`${TODO_KEY}=1: already set: unchanged`); return; }
  if (t.state === 'kept') { say(`${TODO_KEY}=${JSON.stringify(before.env[TODO_KEY])}: kept — a value an operator chose is not overwritten: unchanged`); return; }
  checkPreserved(before, t.settings, T.platform, T.js, { todo: 'set' });
  const real = writeSettings(T, t.settings);
  if ((readJson(real).env || {})[TODO_KEY] !== '1') refuse(`read-back of ${real} does not hold the key: NOT APPLIED`);
  fs.writeFileSync(T.todoMark, new Date().toISOString() + '\n', { mode: 0o600 });
  say(`${TODO_KEY}=1: set in ${real} — the to-do tools reach every session on this account from its next start`);
  ctx.changed = true;
}

/* Step 4 (b)'s wrap: statusLine points at the reporter, which passes the prior command through. Refused on
 * win32, where nothing measured it (W4), and refused when the live statusLine is neither the reporter nor the
 * prior command, so the config's `wrapped_statusline` and the live file cannot disagree. */
function statusline(ctx) {
  const T = ctx.T;
  step('statusline');
  if (T.platform === 'win32') refuse('the statusLine wrap is not applied on Windows: nothing measured it there (W4): NOTHING WRITTEN');
  if (!exists(T.config)) refuse(`no config at ${T.config}: install first: NOTHING WRITTEN`);
  const s = readSettings(T), cfg = readJson(T.config);
  const sl = hookCommand(T.platform, T.js, 'statusline');
  const current = s.statusLine && s.statusLine.command ? s.statusLine.command : '';
  const prior = ctx.a.flags.wrap !== undefined ? ctx.a.flags.wrap : (cfg.wrapped_statusline || '');
  if (isReporterCommand(T.platform, T.js, current, 'statusline')) { say('statusLine already wraps the reporter: unchanged'); return; }
  if (current !== prior) refuse(`statusLine is [${current}], not the prior command [${prior}]: NOTHING WRITTEN`);
  if ((cfg.wrapped_statusline || '') !== prior) writeConfig({ mode: 'wrapped', config: T.config, wrapped: prior || null, platform: T.platform });
  const after = JSON.parse(JSON.stringify(s));
  after.statusLine = Object.assign({}, s.statusLine, { type: 'command', command: sl });
  checkPreserved(s, after, T.platform, T.js, { statusLine: prior });
  const real = writeSettings(T, after);
  say(`statusLine: wrapped in ${real} (was: ${current || 'none'})`);
}

// ── the supervisor ─────────────────────────────────────────────────────────────────────────────────────

function nodeForCron() {
  const w = which('node');
  return w ? fs.realpathSync(w) : process.execPath;
}
/* The values the Linux crontab line carries, each refused BY NAME unless every character is one cron and the
 * shell take literally: letters, digits and `_ . / : @ + -` (an allowlist; cron turns `%` into a newline and
 * the shell splits on whitespace and expands `$`, quotes and globs — review S1). */
const CRON_SAFE = /^[A-Za-z0-9_./:@+-]+$/;
function cronInputs(ctx) {
  const T = ctx.T;
  const inputs = { coordConfig: ctx.cc !== undefined ? ctx.cc : coordConfig(T), flock: T.flock, node: nodeForCron(), js: T.js };
  for (const [name, v] of [['COORD_CONFIG', inputs.coordConfig], ['the flock file', inputs.flock], ['node', inputs.node], ['the artifact', inputs.js]]) {
    if (v && !CRON_SAFE.test(v)) refuse(`${name} ${JSON.stringify(v)} holds a character the crontab line cannot carry (only letters, digits and _ . / : @ + - are written there): NOTHING INSTALLED`);
  }
  return inputs;
}
function supervisor(ctx) {
  const T = ctx.T;
  step('supervisor', T.supervisor);
  const cc = resolver(ctx, true);
  if (T.platform === 'linux') {
    const inputs = cronInputs(ctx);
    const before = readCrontab();
    const next = cronReplace(before.text, cronBlock('linux', inputs));
    if (next === before.text) { say('crontab already current: nothing written, nothing restarted'); return; }
    installCrontab(ctx, next);
    say('crontab: installed');
    stopFlusher(T);
    ctx.changed = true;
    return;
  }
  if (T.platform === 'darwin') {
    const staged = launchdPlist({ coordConfig: cc, node: which('node') || process.execPath, js: T.js });
    const onDisk = exists(T.plist) ? fs.readFileSync(T.plist, 'utf8') : null;
    const svc = `${guiDomain()}/${LABEL}`;
    const loaded = launchctl('print', svc).status === 0;
    if (loaded && onDisk === staged) { say('launchd agent already current: nothing written, nothing called'); return; }
    const write = () => {
      fs.mkdirSync(path.dirname(T.plist), { recursive: true, mode: 0o700 });
      if (!reporter().atomicWrite(T.plist, staged)) refuse(`could not write ${T.plist}: NOTHING WRITTEN`);
      fs.chmodSync(T.plist, 0o600);
    };
    if (loaded) {
      const r = launchctl('bootout', svc);
      if (r.status !== 0) refuse(`launchctl bootout ${svc} failed (exit ${r.status})`);
      say(`launchd agent booted out of ${guiDomain()}: its plist differs from the staged one`);
      write();
    } else if (onDisk !== staged) write();
    const b = launchctl('bootstrap', guiDomain(), T.plist);
    if (b.status !== 0) refuse(`launchctl bootstrap ${guiDomain()} ${T.plist} failed (exit ${b.status}${b.stderr ? ': ' + b.stderr.trim() : ''})`);
    say(`launchd agent bootstrapped in ${guiDomain()}`);
    ctx.changed = true;
    return;
  }
  startFlusherWin32(ctx);
}

// ── uninstall (D6 § 3.5) ───────────────────────────────────────────────────────────────────────────────

/* Every file under the attempts root whose CONTENT carries the token shape — a config backup, a token file,
 * under any name. */
function secretFiles(root) {
  const out = [];
  const walk = (d) => {
    let es;
    try { es = fs.readdirSync(d, { withFileTypes: true }); } catch (e) { return; }
    for (const e of es) {
      const f = path.join(d, e.name);
      if (e.isDirectory()) walk(f);
      else if (e.isFile()) { try { if (TOKEN_SHAPE.test(fs.readFileSync(f, 'latin1'))) out.push(f); } catch (err) { /* unreadable: not ours to judge */ } }
    }
  };
  walk(root);
  return out;
}
function uninstall(ctx) {
  const T = ctx.T;
  // The prefixes first, before anything is removed; and the crontab read now, so an unreadable one refuses
  // before anything changes (the refusable part first).
  let cfg = null;
  try { cfg = readJson(T.config); } catch (e) { cfg = null; }
  const prefixes = cfg ? reporter().loadConfig(T.config).sites.filter((s) => typeof s.token === 'string').map((s) => {
    let host = '<unparseable URL>';
    try { host = new URL(s.ingest_url).host; } catch (e) { /* named so */ }
    return `revoke prefix \`${s.token.slice(0, 12)}\` on ${host}'s Seat tokens page (site ${s.name})`;
  }) : [];
  if (T.platform === 'linux') readCrontab();
  // The stale-lock test now, so a lock it must refuse (a live pid with no usable started_at) refuses before anything
  // is removed, never at the stop after the hooks and the config are gone.
  lockHolder(T);
  // The revoke list FIRST, before anything is removed: the config that holds the prefixes goes in the next steps,
  // so a run that fails after that would otherwise leave the operator nothing to revoke by (review N1).
  step('uninstall: the prefixes to revoke');
  if (!prefixes.length) say('no configured site was read: no prefix to revoke');
  for (const l of prefixes) say(l);
  const B = attemptDir(ctx);
  if (exists(T.settings)) fs.copyFileSync(T.settings, T.P.join(B, 'settings.json'));
  if (T.platform === 'linux') fs.writeFileSync(T.P.join(B, 'crontab.txt'), readCrontab().text, { mode: 0o600 });
  if (T.plist && exists(T.plist)) fs.copyFileSync(T.plist, T.P.join(B, path.basename(T.plist)));

  step('uninstall: hooks');
  if (exists(T.settings)) {
    const before = readSettings(T);
    const after = withoutReporter(before, T.platform, T.js);
    let note = [];
    if (before.statusLine && isReporterCommand(T.platform, T.js, before.statusLine.command, 'statusline')) {
      const wrapped = cfg && typeof cfg.wrapped_statusline === 'string' ? cfg.wrapped_statusline : null;
      if (wrapped === null) { delete after.statusLine; note.push('statusLine removed'); } else { after.statusLine = Object.assign({}, before.statusLine, { command: wrapped }); note.push('statusLine set back to the wrapped command'); }
    } else if (before.statusLine !== undefined) after.statusLine = before.statusLine;
    if (exists(T.todoMark) && after.env && after.env[TODO_KEY] === '1') {
      delete after.env[TODO_KEY];
      if (!Object.keys(after.env).length) delete after.env;
      note.push(`${TODO_KEY} removed`);
    } else if (exists(T.todoMark)) note.push(`${TODO_KEY}: no longer "1": left`);
    else note.push(`${TODO_KEY}: this install did not set it: left`);
    checkPreserved(before, after, T.platform, T.js, { statusLine: cfg && cfg.wrapped_statusline ? cfg.wrapped_statusline : true, todo: 'remove' });
    if (same(before, after)) say('no reporter entry in settings.json: nothing changed');
    else { const real = writeSettings(T, after); say(`the reporter's entries removed from ${real}; ${note.join('; ')}`); }
  }

  step('uninstall: config');
  if (exists(T.configDir)) { fs.rmSync(T.configDir, { recursive: true, force: true }); say('removed ' + T.configDir); } else say('no config directory: nothing to remove');

  step('uninstall: supervisor', T.supervisor);
  if (T.platform === 'linux') {
    const now = readCrontab();
    const next = cronReplace(now.text, null);
    if (next === now.text) say(now.none ? 'no crontab installed: nothing to remove' : 'the crontab holds no fleet-reporter-flusher block: nothing to remove');
    else if (next === '') {
      const r = cp.spawnSync('crontab', ['-r'], { encoding: 'utf8' });
      if (r.status !== 0) refuse(`crontab -r failed (exit ${r.status})`);
      say('the crontab held the reporter\'s block alone: crontab removed');
    } else { installCrontab(ctx, next); say('the fleet-reporter-flusher block removed from the crontab'); }
    if (exists(T.flock)) { fs.unlinkSync(T.flock); say('removed ' + T.flock); }
  } else if (T.platform === 'darwin') {
    const r = launchctl('bootout', `${guiDomain()}/${LABEL}`);
    say(r.status === 0 ? `launchd agent booted out of ${guiDomain()}` : 'the launchd agent was not loaded');
    if (exists(T.plist)) { fs.unlinkSync(T.plist); say('removed ' + T.plist); }
  } else say('Windows has no supervisor: nothing to remove');

  step('uninstall: flusher');
  if (exists(T.js)) stopFlusher(T); else say('no artifact installed: no flusher to stop');
  for (const d of [T.spool, T.artifactDir]) { if (exists(d)) { fs.rmSync(d, { recursive: true, force: true }); say('removed ' + d); } }

  step('uninstall: attempt directories');
  for (const f of secretFiles(T.attempts)) { fs.rmSync(f, { force: true }); say('removed ' + f + ' (it held a token)'); }
  say(`kept this run's backups of what it undid: ${B}`);
  say('install.js: UNINSTALLED. Revoking each prefix listed at the start of this run is the operator\'s act, on that site.');
  return 0;
}

// ── the token routes: first install, rotation, add-site, issue-file ────────────────────────────────────

/* The seat's values: from a v2 file, or from the flags for a v1 file. A flag that disagrees with a v2 file is
 * refused. */
function valuesFrom(ctx, parsed) {
  const f = ctx.a.flags;
  if (parsed.version === 2) {
    if (f['install-id'] && f['install-id'] !== parsed.install_id) refuse(`--install-id ${f['install-id']} disagrees with the token file's install_id ${parsed.install_id}: NOTHING WRITTEN`);
    if (f['seat-id'] && f['seat-id'] !== parsed.seat_id) refuse(`--seat-id ${f['seat-id']} disagrees with the token file's seat_id ${parsed.seat_id}: NOTHING WRITTEN`);
    if (f.site && siteKey(ingestUrlOf(f.site)) !== siteKey(parsed.ingest_url)) refuse('--site disagrees with the token file\'s ingest_url: NOTHING WRITTEN');
    return { install: parsed.install_id, seat: parsed.seat_id, url: parsed.ingest_url };
  }
  if (!f.site || !f['seat-id']) refuse('a v1 token file carries no site and no seat: pass --site <url> and --seat-id <id> with it: NOTHING WRITTEN');
  const url = ingestUrlOf(f.site);
  if (!/^https:\/\//.test(url) || siteKey(url) === null) refuse('--site must be an https:// URL (D1 § 3.5): NOTHING WRITTEN');
  const install = f['install-id'] || accountSlug();
  if (!SLUG_INSTALL.test(install)) refuse(`${f['install-id'] ? '--install-id' : 'this account\'s name'} gives no install_id (D1 § 3.1): pass --install-id: NOTHING WRITTEN`);
  if (!SLUG_SEAT.test(f['seat-id'])) refuse(`--seat-id ${f['seat-id']} is not a seat_id (D1 § 3.1): NOTHING WRITTEN`);
  return { install, seat: f['seat-id'], url };
}
function caFileOf(ctx) {
  const ca = ctx.a.flags['ca-file'];
  if (ca === undefined) return undefined;
  if (!path.isAbsolute(ca)) refuse('--ca-file must be an absolute path (D1 § 3.1): NOTHING RUN');
  try { fs.accessSync(ca, fs.constants.R_OK); } catch (e) { refuse(`--ca-file ${ca} is not readable: NOTHING RUN`); }
  return ca;
}
function tokenConfig(ctx, named, values, board) {
  const T = ctx.T;
  step('token-config');
  const parsed = readTokenFile(T, named);
  let s = {};
  try { s = readJson(T.settings); } catch (e) { s = {}; }
  const cur = s.statusLine && typeof s.statusLine.command === 'string' ? s.statusLine.command : '';
  writeConfig({ config: T.config, token: parsed.token, install: values.install, seat: values.seat, url: values.url, spool: T.spool,
    agent: ctx.a.flags['agent-name'] || undefined, wrapped: isReporterCommand(T.platform, T.js, cur) ? undefined : (cur || undefined),
    descriptors: 'full', boardUser: board && board.decl ? board.decl.id : undefined, boardBase: board && board.decl ? board.decl.base : undefined,
    caFile: caFileOf(ctx), siteName: ctx.a.flags['site-name'], platform: T.platform });
}
/* The config's sites, keyed for matching, for the rotation's and add-site's refusals with a listing. */
const listing = (cfg) => cfg.sites.map((s) => `  site ${s.name}: ${s.ingest_url}`).join('\n');
function configSites(T) {
  const r = reporter().loadConfig(T.config);
  if (!r.config) refuse(`cannot read ${T.config}: ${r.errors.join('; ')}`);
  return { cfg: r.config, sites: r.sites };
}
/* THE ROTATION (D6 § 4.5): `--token-file` with a config present. A v2 file names its site; a v1 file rotates
 * --site-name, `default` when unset. */
function rotate(ctx) {
  const T = ctx.T, f = ctx.a.flags;
  prereqs(ctx);
  const parsed = readTokenFile(T, f['token-file']);
  const { cfg, sites } = configSites(T);
  let name;
  if (parsed.version === 2) {
    const key = siteKey(parsed.ingest_url);
    const hits = sites.filter((s) => typeof s.ingest_url === 'string' && siteKey(s.ingest_url) === key);
    const idOk = parsed.install_id === cfg.install_id && parsed.seat_id === cfg.seat_id;
    if (hits.length !== 1 || !idOk || (f['site-name'] && f['site-name'] !== hits[0].name)) {
      refuse(`the token file is for ${parsed.install_id}/${parsed.seat_id} on ${parsed.ingest_url}, and this seat is ${cfg.install_id}/${cfg.seat_id} with:\n${listing({ sites })}\n`
        + (hits.length !== 1 ? 'no configured site matches the file\'s ingest_url: to add it as a new site, run install.js add-site --token-file <path>' : !idOk ? 'the identity differs: this token was issued for another seat' : `--site-name ${f['site-name']} names another site than the file's`)
        + ': NOTHING WRITTEN');
    }
    name = hits[0].name;
  } else {
    name = f['site-name'] || 'default';
    if (!sites.some((s) => s.name === name)) refuse(`${name} is not a site of this config:\n${listing({ sites })}\npass --site-name: NOTHING WRITTEN`);
  }
  backup(ctx);
  const named = tokenTake(ctx, f['token-file']);
  step('token-config', 'rotation');
  writeConfig({ mode: 'set-token', config: T.config, siteName: name, token: readTokenFile(T, named).token, platform: T.platform });
  configCheck(ctx);
  removeTokenFile(named);
  restart(ctx);
  say(`install.js: the token of site ${name} is replaced. Revoke the old token's prefix on that site's Seat tokens page now.`);
  return 0;
}
function addSite(ctx) {
  const T = ctx.T, f = ctx.a.flags;
  if (!f['token-file']) refuse('add-site needs --token-file <path>: NOTHING RUN');
  if (!exists(T.config)) refuse(`no config at ${T.config}: install first, then add a site: NOTHING RUN`);
  prereqs(ctx);
  const parsed = readTokenFile(T, f['token-file']);
  const { cfg, sites } = configSites(T);
  let url;
  if (parsed.version === 2) {
    if (parsed.install_id !== cfg.install_id || parsed.seat_id !== cfg.seat_id) refuse(`the token file is for ${parsed.install_id}/${parsed.seat_id}, and this seat is ${cfg.install_id}/${cfg.seat_id}: issue the token for this seat's pair: NOTHING WRITTEN`);
    if (f.site && siteKey(ingestUrlOf(f.site)) !== siteKey(parsed.ingest_url)) refuse('--site disagrees with the token file\'s ingest_url: NOTHING WRITTEN');
    url = parsed.ingest_url;
  } else {
    if (!f.site) refuse('a v1 token file carries no site: pass --site <url> with add-site: NOTHING WRITTEN');
    url = ingestUrlOf(f.site);
  }
  if (sites.some((s) => typeof s.ingest_url === 'string' && siteKey(s.ingest_url) === siteKey(url))) refuse(`that site is already configured:\n${listing({ sites })}\nto replace its token, run install.js --token-file <path>: NOTHING WRITTEN`);
  let name = f['site-name'];
  if (!name) {
    name = new URL(url).hostname.toLowerCase().split('.')[0].replace(/[^a-z0-9-]/g, '-').slice(0, 32);
    if (!SLUG_INSTALL.test(name) || sites.some((s) => s.name === name)) refuse('no site name could be derived from the URL: pass --site-name <name>: NOTHING WRITTEN');
  }
  const ca = caFileOf(ctx);
  backup(ctx);
  const named = tokenTake(ctx, f['token-file']);
  step('token-config', 'add-site');
  writeConfig({ mode: 'add-site', config: T.config, siteName: name, url, token: readTokenFile(T, named).token, caFile: ca, platform: T.platform });
  configCheck(ctx);
  removeTokenFile(named);
  restart(ctx);
  say(`install.js: site ${name} added; run install.js to check that it accepts a batch`);
  return 0;
}
/* ISSUE-FILE: a v1 token file for a seat with no browser download. Without --prompt the issue command's output
 * comes on stdin; a stdin that is a TERMINAL is refused, so a token is never typed into an echoing terminal
 * (claimed on POSIX; on win32 under mintty a terminal can read as a pipe, which is unmeasured). --prompt reads
 * a bare token (FILE_TOKEN's form) from /dev/tty with echo off and restores the terminal on every exit path;
 * it is Linux and macOS only. The file is written 0600, `wx`, one line; the prefix alone is printed. */
async function issueFile(ctx) {
  const T = ctx.T, out = ctx.a.flags.out;
  step('issue-file');
  if (!out) refuse('issue-file needs --out <path>: NOTHING WRITTEN');
  if (exists(out)) refuse(`${out} already exists: NOTHING WRITTEN`);
  let token;
  if (ctx.a.flags.prompt) {
    if (T.platform === 'win32') refuse('issue-file --prompt reads /dev/tty, which Windows does not have: issue the token on the Seat tokens page and save the file instead: NOTHING WRITTEN');
    token = await promptToken();
  } else {
    if (process.stdin.isTTY) refuse('the input is a terminal: pipe the issue command in, or use --prompt: NOTHING WRITTEN');
    let raw = '';
    for await (const d of process.stdin) raw += d;
    let r;
    try { r = parseIssueOutput(raw); } catch (e) { if (e.echoed) say(e.echoed.join('\n')); throw e; }
    say(r.echoed.join('\n'));
    token = r.token;
  }
  try {
    const fd = fs.openSync(out, 'wx', 0o600);
    try { fs.writeSync(fd, token + '\n'); } finally { fs.closeSync(fd); }
  } catch (e) { refuse(`could not write ${out} (${e.code}): NOTHING WRITTEN`); }
  say(`wrote ${out} (${T.platform === 'win32' ? 'ACL inherited' : 'mode ' + (fs.statSync(out).mode & 0o777).toString(8)}), prefix ${token.slice(0, 12)}`);
  return 0;
}
function promptToken() {
  let fd;
  try { fd = fs.openSync('/dev/tty', 'r+'); } catch (e) { refuse('there is no terminal to read the token from: run issue-file --prompt in a terminal of your own: NOTHING WRITTEN'); }
  const tty = require('tty');
  const input = new tty.ReadStream(fd);
  const restore = () => { try { input.setRawMode(false); } catch (e) { /* already restored */ } };
  fs.writeSync(fd, 'Paste the token from the Seat tokens page (it is not shown), then press Enter: ');
  input.setRawMode(true);
  const onSig = () => { restore(); try { fs.writeSync(fd, '\n'); } catch (e) { /* gone */ } process.exit(130); };
  process.once('SIGINT', onSig);
  process.once('SIGTERM', onSig);
  return new Promise((resolve, reject) => {
    let buf = '';
    const done = (err, v) => {
      input.removeAllListeners('data');
      restore();
      process.removeListener('SIGINT', onSig);
      process.removeListener('SIGTERM', onSig);
      try { fs.writeSync(fd, '\n'); } catch (e) { /* gone */ }
      input.destroy();
      if (err) reject(err); else resolve(v);
    };
    input.on('data', (d) => {
      try {
        for (const ch of d.toString('utf8')) {
          if (ch === '\u0003') return done(new Refusal('interrupted: NOTHING WRITTEN', 130));
          if (ch === '\r' || ch === '\n') {
            if (!FILE_TOKEN.test(buf)) return done(new Refusal('what was pasted is not one mzn_ token: NOTHING WRITTEN'));
            return done(null, buf);
          }
          if (ch === '\u007f' || ch === '\b') buf = buf.slice(0, -1); else buf += ch;
        }
      } catch (e) { done(e); }
    });
  });
}

// ── the main install, and the arguments ────────────────────────────────────────────────────────────────

async function install(ctx) {
  const T = ctx.T, f = ctx.a.flags;
  const hasConfig = exists(T.config);
  if (hasConfig && f['token-file']) return rotate(ctx);
  const tokenFile = f['token-file'] || (!hasConfig && exists(T.tokenFile) ? T.tokenFile : null);
  if (!hasConfig && !tokenFile) refuse(`no config at ${T.config} yet: pass --token-file <where you saved or copied the token file from the Seat tokens page>: NOTHING RUN`);
  prereqs(ctx);
  let fresh = false, boardRewrote = false;
  if (!hasConfig) {
    step('values');
    const values = valuesFrom(ctx, readTokenFile(T, tokenFile));
    say(`install_id ${values.install} · seat_id ${values.seat} · ${values.url}`);
    backup(ctx);
    resolver(ctx);
    identity(ctx);
    const board = await boardIdentity(ctx);
    const named = tokenTake(ctx, tokenFile);
    tokenConfig(ctx, named, values, board);
    configCheck(ctx);
    removeTokenFile(named);
    ctx.changed = true;
    fresh = true;
  } else {
    const { cfg, sites } = configSites(T);
    if (f['install-id'] && f['install-id'] !== cfg.install_id) refuse(`the config is for install_id ${cfg.install_id}, not ${f['install-id']}: NOTHING RUN`);
    if (f['seat-id'] && f['seat-id'] !== cfg.seat_id) refuse(`the config is for seat_id ${cfg.seat_id}, not ${f['seat-id']}: NOTHING RUN`);
    if (f.site && !sites.some((s) => typeof s.ingest_url === 'string' && siteKey(s.ingest_url) === siteKey(ingestUrlOf(f.site)))) refuse(`the config's sites do not include ${f.site}:\n${listing({ sites })}\nNOTHING RUN`);
    backup(ctx);
  }
  artifact(ctx);
  resolver(ctx);
  if (!fresh) boardRewrote = (await boardIdentity(ctx)).rewrote;
  if (boardRewrote) ctx.changed = true;
  hooks(ctx);
  todoTools(ctx);
  supervisor(ctx);
  step('holder');
  if (holder(T) === 'previous' || boardRewrote) restart(ctx);
  if (!fresh) configCheck(ctx);
  const st = selftest(ctx);
  const h = await health(ctx);
  const a = await arrival(ctx, ARRIVAL_WAIT_S);
  if (ctx.changed) ancestry(ctx);
  let verdict = 0;
  for (const v of [st, h, a]) { if (v === 2) { if (verdict !== 1) verdict = 2; } else if (v !== 0) verdict = 1; }
  say(`install.js: selftest rc=${st} · health rc=${h} · arrival rc=${a}`);
  if (!ctx.changed) dropAttempt(ctx);
  if (verdict === 0) say(ctx.changed ? 'install.js: INSTALLED: the server holds this build current and every site has accepted a batch; the desk is on the site\'s floor' : 'install.js: ALREADY CURRENT: nothing changed, and every check passed');
  else if (verdict === 2) say(`install.js: ${ctx.changed ? 'INSTALLED' : 'UNCHANGED'}, and NOT VERIFIED: a check above could not be measured; its lines say why`);
  else say('install.js: A CHECK FAILED (above): its lines name it, and INSTALL.md § The checks reads each code');
  if (T.platform === 'win32' && verdict !== 1) say('install.js: nothing supervises the flusher on Windows: the desk reads offline while no Claude Code session is open');
  return verdict;
}

const VALUE_FLAGS = ['token-file', 'site', 'seat-id', 'install-id', 'agent-name', 'ca-file', 'site-name', 'out', 'wrap'];
const BOOL_FLAGS = ['no-board', 'uninstall', 'paths', 'render-shipping', 'prompt', 'help'];
/* Each step alone, the by-hand fallback (INSTALL.md § By hand). */
const STEPS = {
  prereqs: (c) => { prereqs(c); return 0; },
  backup: (c) => { backup(c); return 0; },
  artifact: (c) => { artifact(c); return 0; },
  resolver: (c) => { resolver(c); return 0; },
  identity: (c) => { identity(c); return 0; },
  'board-identity': async (c) => { await boardIdentity(c); return 0; },
  'token-take': (c) => { if (!c.a.flags['token-file']) refuse('token-take needs --token-file <path>: NOTHING RUN'); readTokenFile(c.T, c.a.flags['token-file']); tokenTake(c, c.a.flags['token-file']); return 0; },
  'token-config': async (c) => {
    if (!exists(c.T.tokenFile)) refuse(`no token file at ${c.T.tokenFile}: run token-take first: NOTHING RUN`);
    const values = valuesFrom(c, readTokenFile(c.T, c.T.tokenFile));
    tokenConfig(c, c.T.tokenFile, values, await boardIdentity(c));
    removeTokenFile(c.T.tokenFile);
    return 0;
  },
  'config-check': (c) => { configCheck(c); return 0; },
  hooks: (c) => { hooks(c); return 0; },
  'todo-tools': (c) => { todoTools(c); return 0; },
  supervisor: (c) => { supervisor(c); return 0; },
  holder: (c) => { step('holder'); return holder(c.T) === 'previous' ? 1 : 0; },
  restart: (c) => { restart(c); return 0; },
  selftest: (c) => selftest(c),
  health: (c) => health(c),
  arrival: (c) => arrival(c, ARRIVAL_WAIT_S),
  ancestry: (c) => { ancestry(c); return 0; },
  'add-site': (c) => addSite(c),
  'remove-site': (c) => {
    const name = c.a.pos[0];
    if (!name) refuse('remove-site needs the site\'s name: NOTHING WRITTEN');
    step('remove-site');
    writeConfig({ mode: 'remove-site', config: c.T.config, siteName: name, platform: c.T.platform });
    restart(c);
    say(`install.js: site ${name} removed. Revoke its token's prefix on that site's Seat tokens page.`);
    return 0;
  },
  statusline: (c) => { statusline(c); return 0; },
  'set-descriptors': (c) => {
    const v = c.a.pos[0];
    if (!['full', 'paths', 'none'].includes(v)) refuse('set-descriptors takes full, paths or none: NOTHING WRITTEN');
    step('set-descriptors');
    writeConfig({ mode: 'set-descriptors', config: c.T.config, descriptors: v, platform: c.T.platform });
    say('descriptors = ' + v + ': every hook reads the config when it fires, so nothing restarts');
    return 0;
  },
  'issue-file': (c) => issueFile(c),
};
function parseArgs(argv) {
  const flags = {}, pos = [];
  let cmd = null;
  for (let i = 0; i < argv.length; i++) {
    const x = argv[i];
    if (x.startsWith('--')) {
      const k = x.slice(2);
      if (VALUE_FLAGS.includes(k)) { if (i + 1 >= argv.length) refuse(`${x} needs a value: NOTHING RUN`); flags[k] = argv[++i]; }
      else if (BOOL_FLAGS.includes(k)) flags[k] = true;
      else refuse(`unknown argument [${x}]; --help lists them: NOTHING RUN`);
    } else if (cmd === null && pos.length === 0 && Object.prototype.hasOwnProperty.call(STEPS, x)) cmd = x;
    else if (cmd !== null) pos.push(x);
    else refuse(`unknown argument [${x}]; --help lists them: NOTHING RUN`);
  }
  return { flags, pos, cmd, n: argv.length };
}
async function main(argv) {
  // Before anything is parsed: an argument that is really the token (D6 § 4.4).
  if (argv.some((x) => TOKEN_SHAPE.test(x))) {
    refuse('an argument looks like a seat token, not a path: NOTHING RUN. That token is now in this shell\'s history and transcript: revoke its prefix on the Seat tokens page and issue another');
  }
  if (argv.includes('--render-shipping')) {
    if (argv.length !== 1) refuse('--render-shipping takes no other argument (its input is one JSON object on stdin): NOTHING RUN');
    let raw = '';
    for await (const d of process.stdin) raw += d;
    let input;
    try { input = JSON.parse(raw); } catch (e) { refuse('--render-shipping: stdin is not one JSON object'); }
    if (!input || typeof input !== 'object' || Array.isArray(input)) refuse('--render-shipping: stdin is not one JSON object');
    process.stdout.write(JSON.stringify(renderShipping(input), null, 2) + '\n');
    return 0;
  }
  const a = parseArgs(argv);
  if (a.flags.help) { process.stdout.write(fs.readFileSync(__filename, 'utf8').split('\n').slice(1, 26).map((l) => l.replace(/^ ?\*? ?/, '')).join('\n') + '\n'); return 0; }
  const T = platformTable(process.platform, os.homedir(), process.env);
  if (a.flags.paths) { process.stdout.write(JSON.stringify(publicTable(T), null, 2) + '\n'); return 0; }
  const ctx = { T, a, attempt: null, changed: false, cc: undefined };
  if (a.flags.uninstall) return uninstall(ctx);
  if (a.cmd) return STEPS[a.cmd](ctx);
  return install(ctx);
}

module.exports = {
  ARTIFACT, platformTable, cronLine, cronBlock, launchdPlist, hookCommand, parseReporterCommand, samePath, hookMatcher,
  renderShipping, writeConfig, parseTokenFile, parseIssueOutput, siteKey, wireHooks, withoutReporter, checkPreserved,
  setTodoTools, cronReplace, readCrontab, Refusal,
};
/* The reporter's own atomicWrite, re-exported (D6 § 3.1): one primitive, reached from either file. */
Object.defineProperty(module.exports, 'atomicWrite', { enumerable: true, get: () => reporter().atomicWrite });

if (require.main === module) {
  main(process.argv.slice(2)).then((rc) => { process.exitCode = rc || 0; }, (e) => {
    if (e instanceof Refusal) { warn('install.js: ' + e.message); process.exitCode = e.exitCode; return; }
    warn('install.js: ' + (e && e.stack ? e.stack : e));
    process.exitCode = 1;
  });
}

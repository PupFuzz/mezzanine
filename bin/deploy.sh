#!/usr/bin/env bash
# deploy.sh — the ONLY path by which Mezzanine's PRODUCTION server moves. card#7459
#
# WHY IT EXISTS. `docs/PLAN.md § 5` + D-13: prod is a separate deployment from the sandbox and
# "hand-deploys to prod are not a path". Until this file existed the repo had no prod deploy path
# at all, so every prod change would have been a hand-deploy by construction.
#
# ⚑ PROVENANCE — READ THIS BEFORE ASSUMING THE PATTERN WAS COPIED.
#   card#7459 names a sample: `share/issue-347/kanban-solo/kanban-board-deploy.sh @ ee7df9b9`, in
#   the roundtable share tree. THAT FILE IS NOT REACHABLE FROM THIS SEAT — no such path exists on
#   this host and `PupFuzz/agent-roundtable` answers 404 to this credential. So the sample's LINES
#   were not copied. What is adopted is the card's own enumeration of the sample's load-bearing
#   properties (the rt#347 close comment is the ruling of record):
#     forward-only migrations (MySQL/MariaDB DDL is non-transactional) · the load-bearing
#     cache-rebuild order · down-and-stay-down on failure for operator review ·
#     re-exec-after-checkout · every "fails once, then silently succeeds on a bare re-run" state
#     made LOUD, never idempotent by accident.
#   Each is implemented below with its reasoning stated AT the step, which is what handover item 2
#   asked for. ⚠ This script has NOT been diffed against the sample; rt#347 item 5 — take the
#   adapted draft to kanban-solo on a fresh thread once the P2 host exists — is the review that
#   has not happened, and no line here should be read as "the way kanban-board does it".
#
# ⚑ WHERE THIS TOPOLOGY DIVERGES FROM THE SAMPLE'S (handover item 1, BINDING).
#   The sample's host runs a HOST-SCOPED shared daemon serving several tenants, so restarting it
#   is not one tenant's act to take and the sample is silent about it. Mezzanine's prod host is
#   SINGLE-TENANT (D-08, D-13): every long-lived PHP process on it belongs to this app and holds
#   THIS app's code in memory. Copying the silence would leave the fold, the sweep and the feed
#   heartbeat running the PREVIOUS release's code after every deploy, indefinitely and invisibly:
#   the floor would render and the payloads would be the old ones. So the daemons are restarted
#   INSIDE the maintenance window, and a daemon that does not come back on the new code is an
#   in-window failure, not a warning.
#
# ⚑ NO ROOT, NO SUDO, NO SYSTEMD. Operator ruling 2026-09-13: "the web app should not need root
#   access" — and for prod, "prod is set up the same way as sandbox". That host shape is a
#   Virtualmin sub-server account: no sudo, no lingering systemd user manager, and a per-domain
#   PHP-FPM pool whose workers run as this user under a master that is root's. So:
#     · SUPERVISION is this user's crontab — cron + `flock -n`, rendered and installed by
#       bin/supervision.sh, which is also the one list of what is supervised. The window installs the
#       DEPLOYED release's block; A13 refuses, before anything is touched, a crontab that block could not
#       be installed into, and a release that would move the daemons' lock files.
#     · A RESTART is SIGTERM to whatever holds ANY of this checkout's daemon lock files — a path no
#       release may move, so what holds one is what is running, whichever release's crontab started it —
#       then the command cron runs, started detached. PROVEN by each of the deployed release's locks
#       being held a settle later only by processes that started after the restart, and by every other
#       lock file of the checkout being held by nothing (restart_daemons). A re-run after a deploy that
#       failed in the window, with the previous release's daemons still up, is that same restart.
#     · PHP-FPM IS NOT RELOADED; this user cannot. Its workers read the new code through opcache's
#       timestamp validation, which A14 reads off the FPM SAPI and the pool — refusing a host where
#       that would not happen, a `.user.ini` over the app's scripts included — and phase B waits out
#       the longest `revalidate_freq`, the previous release's `.user.ini` counted, before `up`
#       (fpm_code_reload_ready, phase_b_open_window).
#   There is no escalation knob and no second mode: a root path kept "optional" would be a second
#   supported way to deploy, which is what the ruling ends.
#
# ⚑ THE FEED'S STREAMS (card#9300, `docs/design/FLEET-STATE.md § 8.3`). `GET /api/fleet/stream` is a
#   PHP-FPM request that does not end, so opcache revalidation never reaches it. Phase A reads the two
#   host conditions the stream depends on that need no credential — R1's ini half and R2's pool half —
#   off the FPM SAPI and the dedicated stream pool, and refuses a host where either is false
#   (fpm_code_reload_ready) — in phase A; phase B, whose phase A may have been a release without the check,
#   warns rather than keeping the app down over it. Phase B writes `fleet.reload` (`mezzanine:feed-reload`) immediately before
#   the opcache wait, watches the stream pool until every stream the previous release served has gone,
#   and at a ceiling SIGTERMs the ones that missed the message (drain_previous_streams) — D2 § 14 item
#   17's decision, measured on a throwaway non-root master before it was written here. R1's WIRE half —
#   what the proxy does to the stream — needs a signed-in MFA session and is the operator runbook's
#   (docs/PLAN.md § 5), not a deploy step.
#
# WHAT IT IS NOT. It does not provision the host (D-08 / D-15 own that), writes no crontab outside its
# window (`bin/supervision.sh install` supervises a host before its first deploy, as the application
# user; each deploy then replaces that managed block with its own release's), does not write `.env`,
# does not create databases, does not mint an APP_KEY, and never rolls anything back. It refuses
# to start when the host is not in the state those acts leave behind.
#
# EXIT CODES — deliberately distinct, because "refused" and "broke" are different events:
#   0  deployed, smoke-checked, app up
#   1  REFUSED in the precondition phase. Nothing was touched; the app is still serving the
#      previous release. There is nothing to undo.
#   2  FAILED INSIDE THE MAINTENANCE WINDOW. The app is DOWN and stays down for operator review.
#      A failure marker is left and a bare re-run REFUSES until an operator clears it.
#   3  the app came back up but the post-window smoke check did not pass. Marker left.
#
# USAGE
#   bin/deploy.sh [--ref <ref>] [--dry-run] [--redeploy] [--allow-unreleased]
#
# CONFIG (environment; every default is derived, none is guessed):
#   MEZZ_DEPLOY_ROOT      the checkout to deploy        [default: the repo this script lives in]
#   MEZZ_REMOTE           git remote to fetch from      [default: origin]
#   MEZZ_FPM_BIN          the PHP-FPM binary whose opcache settings A14 reads [default:
#                         php-fpm<this host's CLI PHP minor>, e.g. php-fpm8.5 — derived; see below]
#   MEZZ_DAEMON_STOP_TIMEOUT_S  seconds the previous daemons get to exit after SIGTERM [default: 30]
#   MEZZ_DAEMON_SETTLE_S  seconds a relaunched daemon must stay alive to count [default: 3]
#                         Both are whole numbers of seconds; anything else is refused (A1b).
#   MEZZ_DOCROOT          the vhost's document root, whose `.user.ini` A14 reads [default:
#                         $HOME/public_html, the Virtualmin layout; absent ⇒ a warning naming it]
#   MEZZ_STREAM_POOL      the name of the DEDICATED PHP-FPM pool the web server routes
#                         `/api/fleet/stream` to (FLEET-STATE.md § 8.3 R2) [REQUIRED — no default: a
#                         guessed pool would be read, judged and drained as if it were the stream's]
#   MEZZ_FEED_DRAIN_CEILING_S  seconds phase B waits for the previous release's streams to end on
#                         `fleet.reload` before it SIGTERMs the rest [default: 30, § 2.1's ceiling]
#                         ⚠ The BROWSER consumes this default: FLOOR.md § 12 derives the client's
#                         silent reload grace from it, so raising the ceiling CAN push the drain — and
#                         the maintenance window with it — past that grace, which no client can read.
#                         It does so on a deploy where a stream missed fleet.reload; the drain
#                         returns as soon as no previous-release stream remains.
#                         FLOOR.md § 2.2 owns what the viewer sees then.
#   The supervised set is deliberately NOT configurable here: it is bin/supervision.sh's, the same
#   list the crontab was installed from.
#
# CARD / DECISION TOKENS, kept in the script on purpose (handover item 4 — they make it
# answerable): card#7459 (this script) · card#9287 (the feed re-pinned to SSE; no Reverb daemon) · card#7344 (CI
# lanes) · D-08 (separate prod host; named 2026-09-14, never deployed to) · D-13 (prod moves only by this script) ·
# D-15 (the store; MariaDB per its 2026-09-09 amendment, TLS only to another host per its 2026-09-14 one) · D-16 (the app
# lives in server/) · rt#347 (the sample and the binding handover) · `docs/design/FLEET-STATE.md § 2.1` (the daemons), `§ 6.1` (store posture),
# `§ 6.9` (migrations on a live `events` table), `§ 8.3` (the heartbeat) · `docs/PLAN.md § 5`.

set -Eeuo pipefail

# ── output ────────────────────────────────────────────────────────────────────────────────────
say()  { printf '%s\n' "$*"; }
step() { printf '\n▶ %s\n' "$*"; }
warn() { printf '⚠ %s\n' "$*" >&2; }

# refuse — precondition phase only. Nothing has been touched, and the message says so, because an
# operator who cannot tell "refused" from "half-deployed" will go looking for damage that is not
# there (or, worse, will not go looking when it is).
refuse() {
  printf '\n⛔ REFUSED — %s\n' "$1" >&2
  shift
  # Every line of a multi-line argument gets the indent, not just the first — a refusal message
  # is read once, under pressure, by someone deciding whether prod is broken.
  local line
  for line in "$@"; do printf '%s\n' "$line" | sed 's/^/   /' >&2; done
  printf '\n   Nothing was changed. The previous release is still serving.\n' >&2
  exit 1
}

usage() { sed -n '/^# USAGE/,/^# CARD/p' "$0" | grep -v '^# CARD' | sed 's/^# \{0,1\}//'; exit 0; }

# ── configuration ─────────────────────────────────────────────────────────────────────────────
SELF="$(readlink -f "${BASH_SOURCE[0]}")"
# Canonical: the crontab entries A13 matches and the window installs carry this path and cron runs them
# from HOME, and phase B `cd`s into server/ before it re-execs through it — a relative MEZZ_DEPLOY_ROOT
# would be wrong in each. Phase B exports the canonical value, so the re-exec cannot re-resolve it.
DEPLOY_ROOT="$(readlink -f -- "${MEZZ_DEPLOY_ROOT:-$(dirname "$SELF")/..}")" \
  || refuse "MEZZ_DEPLOY_ROOT '${MEZZ_DEPLOY_ROOT:-}' does not resolve to a path"
REMOTE="${MEZZ_REMOTE:-origin}"
# The PHP-FPM binary A14 reads, DERIVED from the PHP this host actually runs. card#9203: the
# literal `php8.3-fpm` unit name that used to sit here was one of three surfaces that drifted
# apart, and a literal was the wrong SHAPE of claim as well as the wrong value — FPM tracks the PHP
# the host has INSTALLED, not the floor `server/composer.json` declares. A host whose CLI and FPM
# are different minors names its binary with MEZZ_FPM_BIN, and a wrong derivation is not silent:
# A14 refuses a binary that is not there rather than reading nothing.
HOST_PHP_VERSION="$(php -r 'echo PHP_VERSION;' 2>/dev/null || true)"
FPM_BIN="${MEZZ_FPM_BIN:-php-fpm$(printf '%s' "$HOST_PHP_VERSION" | cut -d. -f1,2)}"

# The supervised daemons, their locks and the exact command cron runs for each — stated ONCE, in
# bin/supervision.sh, sourced from beside THIS file. In phase A that is the SERVING release's copy, and
# A13 evaluates the target's, read out of git, in a bash process of its own; after the re-exec it is the
# DEPLOYED release's, so a release that adds a daemon installs its entries and starts it on the deploy
# that ships it.
# `mezzanine:retire` is an operator command and runs nothing between deploys.
# shellcheck source=bin/supervision.sh
. "$(dirname "$SELF")/supervision.sh"

APP_DIR="$DEPLOY_ROOT/server"          # D-16: the Laravel app is not at the repo root
ENV_FILE="$APP_DIR/.env"
MARKER="$DEPLOY_ROOT/.deploy-failed"   # git-ignored; see .gitignore

REF="main"; DRY_RUN=0; REDEPLOY=0; ALLOW_UNRELEASED=0; POST_CHECKOUT_SHA=""; TARGET_DAEMONS=""

while [ $# -gt 0 ]; do
  case "$1" in
    --ref)               REF="${2:?--ref needs a value}"; shift 2 ;;
    --dry-run)           DRY_RUN=1; shift ;;
    --redeploy)          REDEPLOY=1; shift ;;
    --allow-unreleased)  ALLOW_UNRELEASED=1; shift ;;
    # Internal. Phase B re-enters here after the checkout — see § re-exec. Never run by hand:
    # it assumes the maintenance window is already open.
    --internal-post-checkout) POST_CHECKOUT_SHA="${2:?}"; shift 2 ;;
    -h|--help)           usage ;;
    *) refuse "unknown argument: $1" "run \`$0 --help\`" ;;
  esac
done

# ── .env reading ──────────────────────────────────────────────────────────────────────────────
# Read `.env` DIRECTLY rather than asking `php artisan` for resolved config: at this moment the
# config cache is stale by construction (it was built by the PREVIOUS deploy, from the previous
# release's config/*.php), so `config()` is the one source here that is guaranteed wrong.
#
# ⚠ SECRETS. `env_get` RETURNS values, and the only ones this script PRINTS are printed deliberately, each
# for a key it names and none of them a credential: A5 prints APP_ENV, APP_DEBUG, DB_CONNECTION, CACHE_STORE
# and DB_HOST, and phase B's smoke step prints APP_URL — in `Smoke: GET …/up` and in the report that the app
# is up but `/up` answered something other than 200. A public base URL is the one thing that step can name.
# APP_KEY, DB_PASSWORD, DB_URL and every other value are tested for shape only (`-n`, a prefix), never
# echoed, never put in an argv and never in an error message —
# a refusal about a `.env` line names the line's NUMBER, never its text. A deploy log is a transcript
# that outlives the deploy.
#
# env_lines_load — $ENV_FILE as the LINES Laravel's own parser reads it as, in ENV_LINES; ENV_LINES_NUL is 1
# when the read STOPPED at a NUL byte rather than reaching EOF, and ENV_LINES_UNREADABLE is 1 when the file
# could not be OPENED at all, in which case ENV_LINES is empty because nothing was read. This is the ONE
# place in this script that turns the file into lines, and both readers below iterate ENV_LINES, so
# "a line" is a single thing here.
# It has to be, and card#9561 r4's BLOCKER is why: while `env_file_scan` split on `\r\n`, `\n` and `\r` alike
# and `env_get` shelled to `grep`, whose terminator is `\n` ONLY, a `.env` ending `# note\rDB_HOST=db.internal`
# was TWO lines to Dotenv and ONE to the reader that decides — so Laravel went to db.internal over TCP in
# plaintext while A5 read DB_HOST as unset, took the config default of 127.0.0.1, and exempted the store from
# TLS. Its mirror: a whole-file CRLF `.env`, which Laravel boots on perfectly, was one line to `grep` and so
# unreadable for EVERY key. Both are the same defect — two notions of "a line" — and both end here rather
# than in a refusal, because a file Laravel reads is one this script should read the same way.
#   · the split is Dotenv\Parser\Parser::parse's `Regex::split("/(\r\n|\n|\r)/")`, mirrored (v5.7.0);
#   · `read -d ''` returns status 1 at EOF **and** on a read ERROR, so status 1 alone does not mean the read
#     completed. Splitting the OPEN out below discriminates the open's failure; the read's own two meanings
#     stay fused, so a path that yields no bytes (a mid-read EIO) still reads here as an empty file — the
#     residual is card#9610, and A5's `-f` at the call site closes the directory case for phase A. Status 0
#     is the other thing: it STOPPED, at a NUL, and everything past that byte is unread — so the NUL flag.
#   · the OPEN is a step of its own, with a status of its own, because `read`'s statuses cannot carry its
#     failure and a silenced open is indistinguishable from an empty file (card#9605). Until this, the open
#     rode on the read: `read … 2>/dev/null < "$ENV_FILE"` applies its redirections LEFT TO RIGHT, so stderr
#     was already `/dev/null` when the OPEN reported, and a failed open left the `if` with status 1 — the
#     very status a complete read to EOF returns. A `.env` the deploy user cannot read came back as an empty
#     one, silently, and `env_file_scan` certified a file it had never read.
# It re-reads per call rather than caching: this is a bash builtin over a small file, cheaper than the `grep`
# fork it replaced, and a cached copy would invent a staleness and ordering coupling that does not exist.
ENV_LINES=()
ENV_LINES_NUL=0
ENV_LINES_UNREADABLE=0
env_lines_load() {
  local content="" line fd=
  ENV_LINES=(); ENV_LINES_NUL=0; ENV_LINES_UNREADABLE=0
  # The group's `2>/dev/null` is established before the open inside it runs, which is the ordering the old
  # one-liner got wrong; the open's own status is what sets the flag, and nothing below runs on a file that
  # was never opened.
  if ! { exec {fd}< "$ENV_FILE"; } 2>/dev/null; then ENV_LINES_UNREADABLE=1; return 0; fi
  if IFS= read -r -d '' content <&"$fd"; then ENV_LINES_NUL=1; fi
  exec {fd}<&-
  content="${content//$'\r\n'/$'\n'}"
  content="${content//$'\r'/$'\n'}"
  # `<<<` appends exactly the terminator the last line needs, so ${#ENV_LINES[@]} is the number of lines
  # Dotenv sees — including the partial one a NUL cut short, which is the line the refusal below names.
  while IFS= read -r line; do ENV_LINES+=("$line"); done <<< "$content"
}

# env_get KEY — prints KEY's value and returns 0; returns 1 when no line defines KEY; returns 2, printing nothing,
# when a line defining KEY is in a form this reader does not read EXACTLY as Laravel does (vlucas/phpdotenv's
# Dotenv\Parser, then Illuminate\Support\Env::get). Status 2 is never "unset": a check that took an unread value
# as absent would pass the value Laravel then uses.
#
# PRECONDITION: `env_file_scan` has passed on $ENV_FILE. `env_lines_load` decides where a line ENDS; the
# scan is what makes one of those lines a SETTING — Dotenv reads a `KEY="` value its own line does not close
# as running ON into the lines below it, so without that scan a `DB_SOCKET=` line could be part of another
# key's value, and this reader would hand back a setting the app never receives. Both run over the same
# lines, which is the one thing that makes this precondition mean anything (card#9561 r5).
# A5 runs it before its first read, and phase B's one read runs on the same file.
#
# The one form read: `KEY=value` on one line, optionally indented, KEY on no other line, the value either
# unquoted and free of whitespace, quotes, `#` and `$`, or wholly inside '…', or wholly inside "…" free
# of `\` and `$`. That is Dotenv\Parser\EntryParser::parseLiteral's form, whose value Dotenv takes verbatim.
# Every other line Dotenv reads as KEY is status 2, because Laravel reads each one differently:
#   · `export KEY=`, `KEY = value`, a quoted name — Dotenv strips the prefix, the whitespace and the quotes;
#   · a `$` unquoted or inside "…" — Dotenv interpolates `${NAME}` (inside '…' a `$` is literal, and read here);
#   · an inline comment, trailing whitespace, a `\` inside "…" — stripped, or unescaped;
#   · a bare `KEY` with no `=` — Dotenv CLEARS the key;
#   · KEY on a second line — Dotenv applies every line in order, so the last one wins;
#   · a value that is itself quoted, `"'value'"` — Env::get strips that second pair of quotes.
#
# What this reads is `.env` and nothing else. Laravel prefers a variable already in the process environment —
# a PHP-FPM pool's `env[KEY]`, a daemon's environment — over `.env`, and this script cannot see those.
env_get() {
  local key="$1" lines="" line value re
  env_lines_load
  # The pattern is the one this reader has always used; what changed in card#9561 r5 is what it runs over.
  # bash's `=~` is ERE, so the text is unchanged — but it matches the lines ENV_LINES holds, which are the
  # lines Dotenv reads, rather than the ones a `\n`-only splitter would have found. The matches are joined
  # with a literal newline below, which is what the duplicate-key note relies on.
  re="^[[:space:]]*(export[[:space:]]+)?[\"']?${key}[\"']?[[:space:]]*(=|\$)"
  for line in "${ENV_LINES[@]}"; do
    [[ $line =~ $re ]] || continue
    [ -z "$lines" ] || lines+=$'\n'
    lines+="$line"
  done
  [ -n "$lines" ] || return 1
  lines="${lines#"${lines%%[![:space:]]*}"}"
  [ "${lines#"$key="}" != "$lines" ] || return 2
  # KEY on a second line needs no guard of its own, and card#9561 r2 MINOR 1 is that a guard here could
  # not be made to fail: the loop above joins the matches with a newline, and the value below carries it.
  # `re_plain` excludes every [[:space:]] character; `re_single` and `re_double` would have to run across
  # the newline, which needs the FIRST line to open a quote it never closes — and env_file_scan has
  # already refused that file, because Dotenv rejects an unterminated '…' and folds an unterminated "…"
  # into the lines below it. So a second definition returns 2 through the value's shape.
  value="${lines#"$key="}"
  local re_plain='^[^[:space:]\'"'"'"#$]*$' re_single="^'[^']*'\$" re_double='^"[^"\$]*"$'
  if [[ "$value" =~ $re_single || "$value" =~ $re_double ]]; then
    value="${value:1:${#value}-2}"
    case "$value" in \'*\' | \"*\") return 2 ;; esac
  elif ! [[ "$value" =~ $re_plain ]]; then
    return 2
  fi
  printf '%s' "$value"
}

# env_read VAR KEY — env_get KEY into VAR, in THIS shell: returns 1 with VAR empty when KEY is unset, and REFUSES
# when env_get cannot read KEY. The refusal names the key and never the line, which may carry a credential.
env_read() {
  local _env_value _env_rc=0
  _env_value="$(env_get "$2")" || _env_rc=$?
  [ "$_env_rc" -ne 2 ] || refuse ".env defines $2 in a form this deploy does not read, so what Laravel reads for it is not established" \
    "$ENV_FILE sets $2 with an export prefix, whitespace around =, a quoted name, a \$ outside '…' (Dotenv" \
    "interpolates \${…}), an inline comment, trailing whitespace, a \\ inside \"…\", a doubly quoted value, a bare" \
    "$2 with no =, or on more than one line. Write it once, as plain $2=value (the value may be" \
    "wholly '…'- or \"…\"-quoted)."
  printf -v "$1" '%s' "$_env_value"
  return "$_env_rc"
}

# env_file_scan — refuse unless Laravel's own parser reads $ENV_FILE as the LINES it is written in.
# env_get reads a LINE; Dotenv reads the FILE, and two file-level facts decide whether those are the same
# thing (both measured 2026-09-15 against server/vendor's phpdotenv v5.7.0):
#   · a `KEY="` value its own line does not close is MULTI-LINE. Dotenv\Parser\Lines folds every following
#     line into that value up to the next `"`, so a `DB_SOCKET=/run/…` line inside one is never defined at
#     all — and a value nothing ever closes is DISCARDED with every line it swallowed, silently, with no
#     exception raised. Read a line at a time, that file says "socket, this host, no TLS needed" while
#     Laravel goes to DB_HOST over TCP with no CA.
#   · one line the parser REJECTS fails the WHOLE file: Dotenv\Parser\Parser throws InvalidFileException,
#     Laravel reads no value from `.env` at all, and every request and every artisan command dies at boot —
#     on keys written perfectly, because of one that is not. A stray quote in an unread key is enough.
# Either one makes "what Laravel reads for this key is established" false for EVERY key, the ones A5 never
# reads included, so this runs over the whole file before A5's first read. Without it phase A certifies a
# file phase B cannot boot on, and that failure lands INSIDE the maintenance window with the app down,
# instead of before it, where a refusal promises nothing was touched.
#
# It mirrors Dotenv\Parser v5.7.0: the file is split by `env_lines_load` the way Parser::parse splits it
# (`\r\n`, `\n` or `\r` alike, and the same split `env_get` reads), every line is tested for a multi-line
# start BEFORE the comment/blank test (Lines::process's order — a `#` before the `="` makes it a comment),
# and each remaining line goes through EntryParser's own name and value rules. THREE places are deliberately
# narrower than Dotenv, and they are the whole of what this does not certify:
#   · a name outside `[A-Za-z0-9_.]` is refused, where EntryParser::isValidName also accepts Unicode letters,
#     marks and digits;
#   · ANY `"` value a line does not close is refused — including one phpdotenv folds correctly and reads as
#     the multi-line value it was written as. A closed fold is not a defect; it is a shape this script's own
#     line-at-a-time reader cannot follow, and a fold that is wrong is indistinguishable from it HERE;
#   · a NUL byte anywhere in the file is refused, where phpdotenv reads it as a character like any other.
# Nothing else here is a judgement call — a line this does not refuse is one phpdotenv parses, as the one
# line it is. Each narrowing refuses a file Laravel could boot on; none of them certifies one it could not.
# A file that does not OPEN is refused too, before any of that, and it is NOT a fourth narrowing: it judges
# nothing about what the file contains. It is an I/O failure of the same kind as A5's missing-file refusal —
# there is no text to be narrower than phpdotenv about, because nothing was read (card#9605).
ENV_SCAN_SPACE=$' \t\v\f\r'   # PHP's ctype_space, less the \n that no single line can hold
ENV_SCAN_TRIM=$' \t\v\r'      # the set Dotenv's own trims use (" \n\r\t\0\x0B"), same caveat

# env_scan_multiline_start LINE — Dotenv\Parser\Lines::looksLikeMultilineStart, mirrored.
env_scan_multiline_start() {
  local line="$1" stripped i count=0 backslashes='\\'
  case "$line" in *'="'*) ;; *) return 1 ;; esac
  case "${line%%'="'*}" in *'#'*) return 1 ;; esac
  # looksLikeMultilineStop(line, true): with `\\` pairs removed, count the `"` that follow a character
  # which is not a backslash — the `"` that OPENS the value follows the `=`, so it counts — and a line
  # carrying more than one of them closes on itself.
  stripped="${line//"$backslashes"/}"
  for (( i = 0; i + 1 < ${#stripped}; i++ )); do
    [ "${stripped:i:1}" = '\' ] || [ "${stripped:i+1:1}" != '"' ] || count=$((count + 1))
  done
  [ "$count" -le 1 ]
}

# env_scan_value_cause VALUE — sets ENV_SCAN_CAUSE to the cause Dotenv\Parser\EntryParser::parseValue
# rejects VALUE with and returns 0; returns 1 when it accepts. The states below are that transducer's and
# the causes are its own words. Its parseLiteral fast path is deliberately NOT mirrored: it accepts a
# subset of what these states accept, so mirroring it would only add a second statement of one form.
ENV_SCAN_CAUSE=""
env_scan_value_cause() {
  local value="$1" c i state=initial
  for (( i = 0; i < ${#value}; i++ )); do
    c="${value:i:1}"
    case "$state" in
      initial)  case "$c" in "'") state=single ;; '"') state=double ;; '#') state=comment ;; *) state=unquoted ;; esac ;;
      unquoted) case "$c" in '#') state=comment ;; [$ENV_SCAN_SPACE]) state=closed ;; esac ;;
      single)   [ "$c" != "'" ] || state=closed ;;
      double)   case "$c" in '"') state=closed ;; '\') state=escape ;; esac ;;
      escape)   case "$c" in '"' | '\' | '$' | f | n | r | t | v) state=double ;;
                  *) ENV_SCAN_CAUSE="an unexpected escape sequence"; return 0 ;; esac ;;
      closed)   case "$c" in '#') state=comment ;; [$ENV_SCAN_SPACE]) ;;
                  *) ENV_SCAN_CAUSE="unexpected whitespace"; return 0 ;; esac ;;
    esac
  done
  case "$state" in single | double | escape) ENV_SCAN_CAUSE="a missing closing quote"; return 0 ;; esac
  return 1
}

# env_scan_refuse LINE-NUMBER CAUSE — one refusal for every way Dotenv's parser turns this file down.
env_scan_refuse() {
  refuse "$ENV_FILE line $1 is not one Laravel's own .env parser reads ($2)" \
    "vlucas/phpdotenv refuses the WHOLE file on a single line it cannot parse, so Laravel would read no" \
    "value from it at all: every request and every artisan command on this host would fail at boot," \
    "however the other lines are written, and nothing this deploy just read for any key is established." \
    "Fix line $1. Its text is not printed here — it may carry a credential."
}

env_file_scan() {
  local raw line n=0 name value quote
  # The file is loaded once, here, by the one loader both readers use; ENV_LINES is what is scanned, so the
  # lines this certifies are the lines env_get then reads, which is the whole point of there being one.
  env_lines_load
  # An I/O refusal, the same kind as A5's "does not exist" one and not a fourth narrowing: this says nothing
  # about what the file CONTAINS. The mode check before it reads the file's permissions and not its own
  # access to it, so a `.env` that exists, is a regular file and is 640 to an owner this script is not can
  # reach here — and every line below would then certify a file nothing had read.
  if [ "$ENV_LINES_UNREADABLE" = 1 ]; then
    refuse "$ENV_FILE exists but cannot be read by the user this deploy runs as" \
      "The file is there and its mode's other-digit is 0, so the two checks above passed — opening it for" \
      "reading is what failed. The usual cause is ownership: a .env written by another user when the host" \
      "was stood up, and left mode 640, is readable by its owner and its group alone. Check \`ls -l\` on it" \
      "and give it to the user this deploy runs as, keeping mode 640." \
      "Nothing was read out of it. Without this refusal every key comes back 'unset' and the deploy stops" \
      "on the first key A5 checks, naming a cause that is not the real one." \
      "Its content is not printed here — it may carry a credential."
  fi
  if [ "$ENV_LINES_NUL" = 1 ]; then
    # The NUL is on the line the load stopped in the middle of — the last one ENV_LINES holds.
    refuse "$ENV_FILE line ${#ENV_LINES[@]} carries a NUL byte, which nothing here can read past" \
      "phpdotenv reads a NUL as a character like any other, so Laravel boots on this file — and no reader" \
      "in this script can see it as Laravel does. bash's \`read\` stops at the first NUL, and every reader" \
      "here goes through that one load, so no line below that byte is scanned at all and EVERY key defined" \
      "below it comes back 'unset', whatever the file sets it to. Without this refusal the deploy still" \
      "stops — on the first key A5 checks, naming a cause that is not the real one. Rewrite the line" \
      "without it." \
      "Its text is not printed here — it may carry a credential."
  fi
  for raw in "${ENV_LINES[@]}"; do
    n=$((n + 1))
    ! env_scan_multiline_start "$raw" || refuse \
      "$ENV_FILE line $n opens a \"…\" value that its own line does not close" \
      "Laravel's .env parser reads that as a value running ON into the lines below it, to the next line" \
      "carrying a \". Every line it swallows stops being a setting of its own — a DB_SOCKET= or" \
      "MYSQL_ATTR_SSL_CA= line inside one is never defined — and a value that nothing ever closes is" \
      "discarded with all of them, silently. This deploy reads .env a line at a time, so while one is" \
      "open no key's value is established. Close the value on its own line." \
      "Its text is not printed here — it may carry a credential."
    # Lines::isCommentOrWhitespace, on the trimmed line, AFTER the multi-line test.
    line="${raw#"${raw%%[!$ENV_SCAN_TRIM]*}"}"
    line="${line%"${line##*[!$ENV_SCAN_TRIM]}"}"
    [ -n "$line" ] || continue
    [ "${line:0:1}" != '#' ] || continue
    # EntryParser::splitStringIntoParts — the name and value are trimmed only when there IS an `=`; a line
    # with none is a name on its own (Dotenv CLEARS that key), and carries nothing to reject.
    if [ "${raw#*=}" != "$raw" ]; then
      name="${raw%%=*}"; value="${raw#*=}"
      name="${name#"${name%%[!$ENV_SCAN_TRIM]*}"}"; name="${name%"${name##*[!$ENV_SCAN_TRIM]}"}"
      value="${value#"${value%%[!$ENV_SCAN_TRIM]*}"}"; value="${value%"${value##*[!$ENV_SCAN_TRIM]}"}"
      [ -n "$name" ] || env_scan_refuse "$n" "an unexpected equals"
    else
      name="$raw"; value=""
    fi
    # EntryParser::parseName — an `export ` prefix and a wrapping quote pair are stripped before the name
    # is judged, so `export DB_HOST=…` and `"DB_HOST"=…` are Dotenv's DB_HOST (env_get refuses them by
    # name for that reason; they are not this file's defect).
    if [ "${#name}" -gt 6 ] && [ "${name:0:6}" = export ] && [[ ${name:6:1} == [$ENV_SCAN_SPACE] ]]; then
      name="${name:6}"
      name="${name#"${name%%[!$ENV_SCAN_SPACE]*}"}"
    fi
    if [ "${#name}" -ge 3 ]; then
      quote="${name:0:1}"
      if [ "$quote" = "${name: -1}" ] && { [ "$quote" = '"' ] || [ "$quote" = "'" ]; }; then
        name="${name:1:${#name}-2}"
      fi
    fi
    [[ $name =~ ^[A-Za-z0-9_.]+$ ]] || env_scan_refuse "$n" "a name outside [A-Za-z0-9_.]"
    # A blank value is Value::blank() and cannot be rejected; anything else goes through the transducer.
    [ -n "$value" ] || continue
    ! env_scan_value_cause "$value" || env_scan_refuse "$n" "$ENV_SCAN_CAUSE"
  done
}

# env_laravel_value VAR TEXT — sets VAR to a tag for the value the APP RECEIVES for a key whose `.env` text
# is TEXT: `null`, `false`, `true`, or `string:` followed by the string itself. This is the ONE place this
# script says what Illuminate\Support\Env::get does to a line's text, and every check below decides on the
# tag rather than on the text: a check that compares the text is reading something the app never uses.
# Env::get maps `null`, `false`, `true`, `empty` and their `(…)` forms — case-insensitively, before any
# config file sees them — to null, false, true and '', and passes everything else through unchanged
# (measured 2026-09-15 against server/vendor's phpdotenv v5.7.0 and Illuminate\Support\Env).
# Env::get also strips ONE pair of wrapping quotes; that is not mirrored because it cannot arrive here.
# env_get has already stripped the pair Dotenv strips, and returns 2 for a value quoted a second time.
env_laravel_value() {
  local _tag
  case "${2,,}" in
    null | '(null)')   _tag=null ;;
    false | '(false)') _tag=false ;;
    true | '(true)')   _tag=true ;;
    empty | '(empty)') _tag='string:' ;;
    *)                 _tag="string:$2" ;;
  esac
  printf -v "$1" '%s' "$_tag"
}

# env_app_falsy TEXT — true when the value the app receives for this text is one PHP treats as FALSE.
# That is the rule `server/config/database.php` applies to the CA: `array_filter` with no callback drops
# every falsy value, a strictly larger set than Env::get's literals, so the option the connection carries
# is only the one PHP reads as true. It is also what "no key at all" means for APP_KEY.
# The falsy values a `.env` line can reach are null, false, '' and the STRING '0'; `0.0`, `0e0`, `off` and
# `no` are non-empty strings, which PHP reads as TRUE — array_filter KEEPS them, and pdo_mysql then fails
# to open a CA by that name, closed at connect rather than silently (measured 2026-09-15, same run).
env_app_falsy() {
  local _value
  env_laravel_value _value "$1"
  case "$_value" in null | false | 'string:' | 'string:0') return 0 ;; esac
  return 1
}

# store_locality — whether the `mysql` connection reaches its store without leaving this host, which is the
# whole of what decides A5's TLS requirement (FLEET-STATE.md § 6.1's Transport row, docs/PLAN.md D-15's
# 2026-09-14 amendment). Sets STORE_LOCALITY to `socket`, `loopback` or `remote`, and STORE_WHY to a
# sentence naming the key that decided it. Neither carries a credential: DB_URL is never printed, and the
# host PHP parses out of it is compared, never echoed.
#
# The keys are server/config/database.php's, resolved the way Laravel resolves them:
#   · DB_URL, when it parses to a host, REPLACES DB_HOST (Illuminate\Support\ConfigurationUrlParser merges
#     parse_url's rawurldecoded host over the connection's keys); a URL naming no host leaves DB_HOST in
#     force. Its query string is merged over EVERY key, so a `?host=` or `?unix_socket=` replaces the URL's
#     host and DB_SOCKET alike: a URL carrying either is not followed here and counts as remote, as does a
#     URL parse_url refuses. PHP's own parse_url and parse_str read it — the functions the parser uses — with
#     the URL on stdin, because it carries the password and an argv is readable by every user of the host.
#   · DB_SOCKET makes Illuminate\Database\Connectors\MySqlConnector build a unix_socket DSN whatever the host
#     says. Only a value starting with `/` counts: Laravel reads `null`, `false`, `(empty)` and an inline
#     comment as no socket at all, and env_get returns each of them as a non-empty string.
#   · DB_HOST, else the config's own default, 127.0.0.1. Which hosts are ON this host is stated once, in
#     ENV_LOOPBACK_HOSTS below; any other value, an empty one included, is remote.
# It reads `server/.env` only (env_get's closing note): a variable the process environment sets, which Laravel
# prefers, is not seen. Within `.env`, what this cannot establish is REMOTE: a URL it does not follow counts as
# another host, and a key env_get cannot read refuses by name, so neither is exempted. A URL's host is judged
# INSIDE the `php` that parsed it, which is what makes that true of the host too: no byte of it has to survive
# the trip back into a bash string to be judged, and every byte that crosses a boundary is one a boundary can
# eat (card#9561 r3 MAJOR).
#
# ENV_LOOPBACK_HOSTS — the one statement of which host names the connection reaches without leaving this host.
# `localhost` is pdo_mysql's name for the Unix socket (measured 2026-09-14, PHP 8.5.4: `host=localhost`
# connected to /var/run/mysqld/mysqld.sock); 127.0.0.1 and ::1 are loopback TCP, and `[::1]` is how parse_url
# returns ::1 out of a URL. BOTH deciders read this array — host_locality for DB_HOST, and the `php -r` below
# for DB_URL's host, through its environment — so the two cannot drift apart.
ENV_LOOPBACK_HOSTS=(localhost 127.0.0.1 ::1 '[::1]')

# host_locality VAR HOST — sets VAR to `loopback` or `remote`.
host_locality() {
  local _h
  for _h in "${ENV_LOOPBACK_HOSTS[@]}"; do
    [ "$2" != "$_h" ] || { printf -v "$1" loopback; return 0; }
  done
  printf -v "$1" remote
}

store_locality() {
  local url url_locality="" host socket from
  host="127.0.0.1"; from="DB_HOST is unset, and server/config/database.php defaults it to 127.0.0.1"
  env_read url DB_URL || true
  if [ -n "$url" ]; then
    # It prints the VERDICT — one of `loopback`, `remote`, `none` — and exits 1 on a URL it will not follow.
    # A host's BYTES never cross back into bash, because `$(…)` is not a lossless channel for them: it
    # deletes NUL bytes and strips trailing newlines, so a host judged on what survived it read
    # `local%00host` as `localhost` (r3 MAJOR) and `localhost%0A` as `localhost` (r1) — both a store on
    # another host, certified as this one. A token from a fixed set is the same token after either edit.
    # Anything else it prints, and any failure to run it at all, is remote.
    url_locality="$(printf '%s' "$url" | MEZZ_LOOPBACK_HOSTS="${ENV_LOOPBACK_HOSTS[*]}" php -r '
      $p = parse_url(stream_get_contents(STDIN));
      if ($p === false) exit(1);
      parse_str($p["query"] ?? "", $q);
      if (array_key_exists("host", $q) || array_key_exists("unix_socket", $q)) exit(1);
      if (! isset($p["host"])) { echo "none"; exit(0); }
      echo in_array(rawurldecode($p["host"]), explode(" ", getenv("MEZZ_LOOPBACK_HOSTS")), true)
        ? "loopback" : "remote";' 2>/dev/null)" || {
      STORE_LOCALITY=remote
      STORE_WHY="DB_URL does not parse, or its query string sets host or unix_socket, so this host is not established"
      return 0; }
    case "$url_locality" in loopback | remote | none) ;; *) url_locality=remote ;; esac
  fi
  env_read socket DB_SOCKET || true
  if [ "${socket:0:1}" = "/" ]; then
    STORE_LOCALITY=socket; STORE_WHY="DB_SOCKET names a Unix socket"; return 0
  fi
  if [ -n "$url_locality" ] && [ "$url_locality" != none ]; then
    STORE_LOCALITY="$url_locality"; from=""
  elif env_read host DB_HOST; then
    host_locality STORE_LOCALITY "$host"
    from="DB_HOST is '$host'"
  else
    # env_read emptied `host` on its way to saying DB_HOST is unset; the default is the config's own.
    host="127.0.0.1"; host_locality STORE_LOCALITY "$host"
  fi
  # A URL's host stays out of the sentence: a malformed URL can put credential bytes where a host should be.
  STORE_WHY="${from:-DB_URL names a $STORE_LOCALITY host}"
  return 0
}

git_at() { git -C "$DEPLOY_ROOT" "$@"; }

# ── reading the TARGET RELEASE out of git ─────────────────────────────────────────────────────
# Phase A judges the release being deployed BEFORE it is checked out, so every precondition that
# reads a file OF that release reads it out of the object database. These are the ONE place that
# does it, and they exist because the shape they replace could not tell two different things apart:
# `git_at show … 2>/dev/null || true` silences git's own error AND discards its status, so "there is
# no such path at this commit" and "git could not read it" both arrive as an empty string — and a
# gate that reads empty as a finding then certifies a file it never opened. card#9608; card#9605 was
# this same shape on the `.env` reader, which is why the fix is a primitive and not three call-sites.
#   · THE STATUS IS THE DISCRIMINATOR, never the emptiness. `git ls-tree` exits 0 for a pathspec that
#     matches nothing — an honest "not at this commit" — and non-zero when it could not READ the trees
#     it had to walk (measured, git 2.53.0: 1 on an unreadable tree object, 128 on a rev that will not
#     resolve). `git show` and `git cat-file -e` cannot be asked this question at all: both exit 128
#     for an absent path and for a failure alike, and `cat-file -e` exits 0 for a blob that is present
#     and UNREADABLE. So presence is established by ls-tree, and only then is content read, where a
#     non-zero status can only mean the read failed.
#   · STDERR IS NOT SILENCED. git's own message is what names WHICH object and why, and hiding it is
#     half of how this class survives: the refusal below names the read, git names the cause, and they
#     are read together. The absent case prints nothing, because ls-tree is silent about it.
#   · A FAILED READ IS TERMINAL HERE, rather than handing back a status a caller could drop — and
#     WHICH terminal it takes is decided by git_read_unusable, from the PHASE, rather than asserted in
#     this comment. The assertion it replaces ("every caller is in phase A") was true, and what kept it
#     true was one `[ -z "$POST_CHECKOUT_SHA" ] &&` at the single caller that runs on both sides of the
#     window (fpm_code_reload_ready) — a guard these readers cannot see, that reads like a phase-A
#     optimisation, and whose removal would have made `refuse` a one-way door: "Nothing was changed.
#     The previous release is still serving." printed with the checkout landed and the app down.

# git_read_call_site <var> — the line THE CALLER of these readers is on, into <var>. The frame depth
# is DERIVED rather than assumed, because git_read_unusable is reached at three different depths:
# straight from git_read_at (a mode refusal), through git_read_failed from git_read_at (a failed
# `git show`), and through git_read_failed from _git_ls_at (a failed `ls-tree` — the commonest of the
# three in the window, because presence is established before content is ever read). A fixed
# BASH_LINENO index is therefore right for ONE path and names THIS FILE for the other two: measured
# on the shape this replaces (bash 5.3.9, git 2.53.0), BASH_LINENO[1] gave the line INSIDE _git_ls_at
# that calls git_read_failed, and the one INSIDE git_read_at — so `failed_line:` in the marker and in
# the banner pointed an operator recovering a down app at the primitive instead of at the precondition
# that was running. (The line NUMBERS of that measurement are on card#9608, not restated here, where
# every edit to this file would move them.) When one of these
# readers fails they are the innermost CONTIGUOUS frames, so the OUTERMOST of them is the frame the
# caller itself invoked and BASH_LINENO at that index is the caller's own line, at every depth. The
# family is named below rather than matched by prefix, so that a CALLER whose name happens to look
# like a reader's cannot be walked past; a reader added here and not named falls back to reporting
# its own call site — what the fixed index did — never some further caller's.
git_read_call_site() {
  local __i __outer=0
  for __i in "${!FUNCNAME[@]}"; do
    case "${FUNCNAME[__i]}" in
      git_read_call_site | git_read_unusable | git_read_failed | git_read_at | git_ls_at | _git_ls_at | \
      git_rev_read_failed | git_commit_of | git_ref_oid)
        __outer="$__i" ;;
      *) break ;;
    esac
  done
  printf -v "$1" '%s' "${BASH_LINENO[__outer]:-0}"
}

# git_read_unusable <headline> <detail line…> — the ONE exit these readers take, and the ONE place
# the phase is read. Phase A REFUSES: nothing has been touched, and the refusal says exactly that.
# Phase B cannot say it — the window is open and the checkout has landed — so it takes the in-window
# failure path, which writes the marker and says the app is down and stays down. POST_CHECKOUT_SHA is
# what phase B re-enters with, so no caller has to remember which side of the window it is on.
git_read_unusable() {
  local __line
  if [ -n "$POST_CHECKOUT_SHA" ]; then
    git_read_call_site __line
    printf '%s\n' "${@:2}" >&2
    FAILED_STEP="reading the release out of git — $1"
    # `false ||` so the banner reports a failing status, as it does for every other in-window failure
    # (in_window_failure reads `$?`) — which is also why the line is resolved into $__line ABOVE and
    # not in the argument: a command substitution there would run between the `false` and the call.
    false || in_window_failure "$__line"
  fi
  refuse "$@"
}

git_read_failed() { # git_read_failed <git subcommand> <rev> <path> <status>
  git_read_unusable "git could not read $3 at $2 (\`git $1\` exited $4)" \
    "git's own error is above this refusal and names the object it could not read." \
    "Nothing was read, so nothing about $3 at that commit is known — this is not a finding" \
    "about the release. A deploy that carried on here would be certifying a file it never" \
    "opened, which is the defect card#9608 ends."
}

# _git_ls_at <var> <rev> <path> [ls-tree option…] — THE ls-tree, and THE status rule, in one place.
# :(literal) because the pathspec is a PATH and not a pattern: `.user.ini`'s name comes from phpinfo.
# core.quotePath=false so that a name ls-tree PRINTS is a name it will also MATCH. With git's default
# quoting a non-ASCII name comes back C-quoted — measured, git 2.53.0:
# `"server/database/migrations/2026_01_01_cr\303\251\303\251.php"` — which the read that follows
# cannot find, so A10 refused a HEALTHY release with "is in <sha>'s tree and then was not there to
# read". ⚠ Measured the same way: a name carrying `"`, `\` or a control byte is still quoted with it
# off. Such a name does not round-trip either and fails that same read — wrongly, but loudly; it can
# never read as a DIFFERENT file, which is the property that matters here.
#
# EVERY LOCAL OF THESE READERS IS `__`-PREFIXED, and that is the contract, not a style: <var> is
# written with `printf -v`, so a caller passing the name of a variable one of them declares `local`
# has its own variable shadowed — the write lands on the shadow, NOTHING fails, the caller reads an
# empty string as the release's content, and the gate downstream refuses a healthy release with
# "… is missing or empty". `__` is reserved to these readers; a caller's variable must not start with it.
_git_ls_at() {
  local __var="$1" __rev="$2" __path="$3"; shift 3
  local __out __rc=0
  __out="$(git_at -c core.quotePath=false ls-tree "$@" "$__rev" -- ":(literal)$__path")" || __rc=$?
  [ "$__rc" -eq 0 ] || git_read_failed ls-tree "$__rev" "$__path" "$__rc"
  printf -v "$__var" '%s' "$__out"
}

# git_ls_at <var> <rev> <pathspec> — the paths under <pathspec> at <rev>, one per line, into <var>.
# EMPTY IS A REAL ANSWER: at status 0 it means there is no such path at that commit. The names are
# ls-tree's own, unquoted for every byte that can be (core.quotePath=false, above), so a name this
# prints is one git_read_at can read back.
git_ls_at() { _git_ls_at "$1" "$2" "$3" --name-only -r; }

# git_read_at <var> <rev> <path> — the CONTENT of one file at <rev>, into <var>.
#   0 — read. <var> is the content; a file that is genuinely empty reads as empty AT STATUS 0.
#   1 — there is no such file at <rev>, and <var> is empty. The caller must say what that means:
#       it is a different answer from "the file is empty", and neither is a reading of the text.
# A git failure does not return, and neither does an entry that is not a regular file: both are
# terminal above.
git_read_at() {
  local __var="$1" __rev="$2" __path="$3" __entry __what="" __why="" __content __rc=0
  printf -v "$__var" '%s' ""
  _git_ls_at __entry "$__rev" "$__path"
  [ -n "$__entry" ] || return 1
  # The entry's MODE, which is its FIRST field — NOT its type, and not its name. Its name is quoted
  # for some bytes (above) and this path can come from outside this script; its TYPE is `blob` for a
  # SYMLINK exactly as it is for a regular file (measured, git 2.53.0: `120000 blob …`), and a
  # symlink's blob is its TARGET PATH — so a type check passed one through and handed the caller the
  # string `../../top.txt` as the file's text, which every grep below then answered about instead of
  # the file. Only 100644 and 100755 are a file whose blob IS its content; every other mode is
  # refused BY NAME rather than read as one.
  case "${__entry%% *}" in
    100644 | 100755) ;;
    040000) __what="a tree"
            __why="\`git show\` prints a tree's LISTING, and a caller grepping that listing is reading file NAMES as a file's text." ;;
    120000) __what="a symbolic link"
            __why="A symlink's blob is the PATH it points at, not the text at the other end, and ls-tree calls its type \`blob\` exactly as it does a file's." ;;
    160000) __what="a submodule"
            __why="Its entry is a commit id in another repository. This deploy checks out one tree and clones nothing, so there is no text here for it to read." ;;
    *)      __what="mode ${__entry%% *}"
            __why="A regular file is 100644 or 100755. This deploy refuses every other mode by name rather than guess what its blob holds." ;;
  esac
  [ -z "$__what" ] || git_read_unusable "$__path is $__what at $__rev, not a file" "$__why" \
    "This deploy reads it as a file, and will not read anything else as one."
  __content="$(git_at show "$__rev:$__path")" || __rc=$?
  [ "$__rc" -eq 0 ] || git_read_failed show "$__rev" "$__path" "$__rc"
  printf -v "$__var" '%s' "$__content"
}

# ── resolving a REF, and reading the COMMIT GRAPH ─────────────────────────────────────────────
# A7 and A8's reads. The SAME status discipline as the readers above — the status is the
# discriminator, never the emptiness — arrived at differently, because `rev-parse` cannot be asked
# the question ls-tree can. card#9611.
#
# ⛔ THE STATUS OF THE CALL A7 WAS MAKING CANNOT DISCRIMINATE, AT ALL. Measured, git 2.53.0, on a
# checkout whose objects are PACKED — which is how they arrive from `fetch`, so on a real host an
# object-store failure is all-or-nothing and takes the REFS' reads down with everything else:
#
#   .pack chmod 000 · .idx chmod 000 · .pack deleted · one byte flipped mid-pack
#     git rev-parse --verify --quiet <ref>^{commit}  → 1, silent      ← THE STATUS "NO SUCH REF" HAS
#     git rev-parse --verify        <ref>^{commit}   → 128, "fatal: Needed a single revision"
#                                                                    ← and a NO SUCH REF gives 128 and
#                                                                      that same sentence, too
#     git cat-file -t <oid>                          → 128, "could not get object info"
#     git merge-base --is-ancestor <oid> <ref>       → 128, "Not a valid commit name <oid>"
#
# `--quiet` folds "git could not read the object" onto 1, the status that MEANS the ref is absent;
# dropping it folds both onto 128 with one sentence. So `rev-parse … || true` then `[ -n "$SHA" ] ||
# refuse "'<ref>' does not resolve to a commit on origin"` said the ref was bad about a host whose
# object store was unreadable — and said it FIRST, before any of card#9608's readers is reached,
# which makes it the first thing an operator meets when a real object store goes bad.
#
# WHAT SEPARATES THEM IS THE QUESTION, NOT THE STATUS. Resolving a ref NAME to an object id reads
# the refs alone and never opens the object store — measured on that same broken checkout,
# `rev-parse --verify --quiet refs/remotes/origin/main` still answers with the id — so THERE a
# status 1 is the name being absent, and a LOUD 128 (unreadable `packed-refs`, not a repository) is
# a failed read. Whether the object that id names is a readable commit is a SECOND question, asked
# of `cat-file -t`, where a non-zero status can only be a failed read.
#
# ⚠ "AND CAN MEAN NOTHING ELSE" IS WHAT THIS PARAGRAPH USED TO SAY, AND IT IS FALSE — recorded here
# rather than left as a premise the code leans on (card#9611 r3). Measured, git 2.53.0, on a store
# where every object reads: ONE ref file at mode 000 → `rev-parse --verify --quiet refs/heads/<it>`
# → 1, stderr EMPTY. A REFS-level read that failed, wearing the status and the silence that mean
# absence, which no discriminator in this file can see. Two things follow, both stated rather than
# assumed:
#   · IT IS NOT REACHABLE THROUGH THIS SCRIPT TODAY. A7 fetches before it resolves anything, and a
#     ref it cannot read fails THAT first — measured: `fatal: bad object refs/remotes/origin/main`,
#     exit 1, so the deploy dies at the fetch with git's error on screen (card#9646 owns the fetch's
#     own status reading; that is where a fix for this belongs).
#   · IF IT EVER BECOMES REACHABLE, THE COUPLING IS A8's. An unreadable `refs/remotes/$REMOTE/main`
#     would answer git_ref_oid with 1-silent, A8 would read that as "there is no release branch",
#     and the run would conclude that nothing is released — so `--allow-unreleased` would apply —
#     out of a read that failed. That is the card#9608 direction (an empty answer read as a
#     finding), which is why it is written down here and not only measured.
# Peeling with `^{commit}` asks both at once, which is what collapsed them. They are asked apart.
#
# ⚠ AND A CANDIDATE IS NOT ALWAYS A NAME (card#9611 r2). Everything above holds for a ref NAME;
# `$REF` is the operator's own string, so `main~2`, `v1^{}`, `:/subject` and an abbreviated id are
# candidates too, and resolving one of THOSE walks into the object store after all. git_ref_oid is
# where that is met: at status 1 it reads git's own SILENCE, which is what a name that is simply
# absent answers with, and the one shape it still cannot see is named there rather than assumed
# away — A7's refusal says so instead of calling it an absence.

# git_rev_read_failed <what could not be done> <git subcommand> <status> <detail line…> — the
# ref/graph counterpart of git_read_failed, through the SAME one exit, so the PHASE is read here too
# rather than asserted (git_read_unusable).
git_rev_read_failed() {
  git_read_unusable "git could not $1 (\`git $2\` exited $3)" \
    "git's own error is above this refusal and names what it could not read." \
    "${@:4}"
}

# git_ref_oid <var> <candidate> — the object id <candidate> names, into <var>. THE name question,
# in ONE place: git_commit_of asks it of each of A7's candidates and A8 asks it of the release
# branch, and both need the same answers rather than two readings of "non-zero".
#
# ITS DECLARED ANSWERS ARE THE STATUS AND GIT'S SILENCE TOGETHER, and there are five of them:
#   0                   — <var> is a full object id.
#   1, git SILENT       — nothing of that name, and <var> is empty. The caller must say what that
#                         means. THIS IS THE ONLY ANSWER THAT RETURNS 1.
#   1, git LOUD         — does not return. git printed while reaching the status that means absence,
#                         so it is not that; what it met is what git named.
#   ∉ {0,1}, git LOUD   — does not return. A read that failed, named by git's own error.
#   ∉ {0,1}, git SILENT — does not return, AND THE REFUSAL CLAIMS NO FAILURE (card#9611 r3): git
#                         answered without printing anything, so nothing establishes a failed read.
# ⚠ THOSE FIVE ARE THE COVERAGE UNIT, and that is this round's lesson rather than a note: r2 added
# cases for the 1 half of this function and NONE for the ∉ {0,1} half — so the branch nothing
# exercised was the branch still over-reading, and it reached an operator through `--ref HEAD@{1}`
# on a completely healthy host. bin/deploy.selftest.sh now carries a case per ANSWER above, which is
# coverage over this contract rather than over the failures someone thought of.
#
# ⛔ STATUS 1 ALONE DOES NOT MEAN ABSENCE — THE SILENCE IS THE OTHER HALF (card#9611 r2). A
# candidate is not always a ref NAME, so resolving one can walk into the object store, and a read
# that fails there comes back as 1 — the status a name that is not there gives. Measured, git
# 2.53.0, one loose object at mode 000, one variable apart:
#   git rev-parse --verify --quiet no-such-branch      → 1, stderr EMPTY   ← the ref is not there
#   git rev-parse --verify --quiet origin/main~2       → 1, stderr `error: unable to open loose
#                                                         object …: Permission denied`
#   git rev-parse --verify --quiet refs/remotes/origin/main → 0 + the id   ← a NAME, on that same
#                                                                            broken checkout
# So the discriminator at status 1 is git's own stderr: an absence answers SILENTLY. It is captured
# in order to be READ, never to be hidden — every byte of it is printed back before anything is
# decided, because git's message is what names the object (the rule stated for the readers above).
#
# ⚠ THE SHAPE THIS CANNOT SEE, named rather than assumed away: a checkout whose PACK is unreadable
# answers with 1 and says nothing, because git never opened an object to fail on — the index it
# needed to FIND one is what it could not read. That is not one candidate shape but every candidate
# whose resolution needs the pack, which is the shape a real host is in (objects arrive packed from
# `fetch`) — measured, git 2.53.0, `.pack` chmod 000, stderr EMPTY at each: an abbreviated id → 1,
# `main~1` → 1, `:/subject` → 1. A FULL 40-character id cannot reach that: it resolves to itself at
# status 0 with the store untouched (measured on that same broken checkout, and on a healthy one for
# an id naming no object at all), so the object question is asked of `cat-file -t` below, where a
# failure is loud. A7's refusal tells an operator that, rather than calling that silence the ref's
# absence.
#
# Its locals are `__ro_`-prefixed rather than `__`: git_commit_of calls it WITH `__oid` as <var>,
# and a local of that name here would swallow the write — the shadowing hazard _git_ls_at states.
git_ref_oid() {
  local __ro_var="$1" __ro_cand="$2" __ro_err __ro_msg __ro_out __ro_rc=0
  printf -v "$__ro_var" '%s' ""
  __ro_err="$(mktemp)"
  # --end-of-options because the candidate can be `$REF` as the operator typed it: a leading `-` is
  # a ref name here and must not be read as an option. NO `^{commit}`: that peel is what drags the
  # object store into this question and collapses its failure onto status 1 (above).
  __ro_out="$(git_at rev-parse --verify --quiet --end-of-options "$__ro_cand" 2>"$__ro_err")" || __ro_rc=$?
  __ro_msg="$(cat "$__ro_err")"; rm -f "$__ro_err"
  [ -z "$__ro_msg" ] || printf '%s\n' "$__ro_msg" >&2
  if [ "$__ro_rc" -eq 0 ]; then printf -v "$__ro_var" '%s' "$__ro_out"; return 0; fi
  if [ "$__ro_rc" -eq 1 ]; then
    [ -n "$__ro_msg" ] || return 1
    git_rev_read_failed "resolve '$__ro_cand'" "rev-parse --verify" "$__ro_rc" \
      "Exit 1 is \"there is no ref of that name\" — and that answer is SILENT. This one printed the" \
      "message above, so it is not that, and GIT'S OWN MESSAGE IS WHAT SAYS WHICH READ THIS WAS:" \
      "resolving '$__ro_cand' goes past the refs and into the object store when it is an abbreviated" \
      "id or rev syntax (~, ^, @{}, :/), which is the common case here — and a ref the refs" \
      "themselves cannot follow is loud at this status too (measured: a dangling symref, on a" \
      "completely healthy object store)." \
      "Nothing was resolved, so nothing about what '$__ro_cand' names is known."
  fi
  if [ -n "$__ro_msg" ]; then
    git_rev_read_failed "resolve '$__ro_cand'" "rev-parse --verify" "$__ro_rc" \
      "Exit 1 is \"there is no ref of that name\" and $__ro_rc is not that, and git printed the error" \
      "above on the way there: the read failed, and git's own message names what it could not read." \
      "Nothing was resolved, so nothing about what '$__ro_cand' names is known."
  fi
  # ∉ {0,1} AND GIT SAID NOTHING. The same over-read this card exists to end, one branch further in
  # (card#9611 r3): the text here used to be "the REFS could not be read", which is a positive claim
  # about a failure that nothing above establishes — and `--ref HEAD@{1}` reaches it on a healthy
  # host, permanently, because refs/remotes/origin/HEAD is in every clone and its reflog gets one
  # entry at clone time and never grows on a deploy root. A8 splits --is-ancestor's 128 apart for
  # exactly this reason three screens below; this is that same split, at the same discriminator the
  # status-1 branch above uses — git's own silence.
  git_read_unusable "git could not resolve '$__ro_cand' (\`git rev-parse --verify\` exited $__ro_rc)" \
    "NOTHING WAS PRINTED ABOVE THIS REFUSAL, so it does not claim a read that failed — there is no" \
    "error above it to name one, and --quiet silences only rev-parse's own diagnostic for this" \
    "status. Measured, git 2.53.0: a COMPLETELY HEALTHY store answers exactly this way for @{…}" \
    "reflog syntax whose reflog does not go back that far, and so does a reflog this checkout could" \
    "not read. Which of those this is, is NOT established here." \
    "Nothing was resolved, so nothing about what '$__ro_cand' names is known." \
    "Deploy from a branch, a tag, or the full 40-character commit id: a reflog is this host's own" \
    "local history of where a ref has pointed, not something $REMOTE can be asked for."
}

# git_commit_of <var> <candidate…> — the commit id the FIRST candidate that NAMES an object peels
# to, into <var>.
#   0 — <var> is a full commit id.
#   1 — no candidate names anything, and <var> is empty. That is the refs' own answer, read from
#       refs that were read (git_ref_oid): the caller must say what it means.
# A read git could not complete does not return, and neither does a name that is not a commit.
# Every local is `__`-prefixed, for the reason stated at _git_ls_at: <var> is written with
# `printf -v` and a caller naming one of these would have its own variable shadowed.
git_commit_of() {
  local __var="$1" __cand="" __oid="" __peeled __type __rc=0
  shift
  printf -v "$__var" '%s' ""
  for __cand in "$@"; do
    if git_ref_oid __oid "$__cand"; then break; fi
  done
  [ -n "$__oid" ] || return 1
  __type="$(git_at cat-file -t "$__oid")" || __rc=$?
  [ "$__rc" -eq 0 ] || git_rev_read_failed "read the object $__oid" "cat-file -t" "$__rc" \
    "The REFS were read; the object behind them did not come back." \
    "'$__cand' is the name that resolved to it. A FULL commit id resolves to itself without the" \
    "object store being read at all, so a mistyped id, an id from another repository and an id that" \
    "was never pushed all arrive here — and so does a store this checkout could not read. GIT'S" \
    "ERROR ABOVE TELLS THEM APART: \"could not get object info\" is an object that is not here," \
    "\"unable to open …\" is one that could not be read. Check the id, and that the commit is pushed."
  case "$__type" in
    commit) printf -v "$__var" '%s' "$__oid" ;;
    tag)    # An annotated tag: the object is readable, so peeling reads FURTHER objects, and a
            # failure here is that read failing or a tag that dereferences to something else. git's
            # own error says which; this refusal does not guess — and does not head itself "could
            # not peel" either, because one of those two cases is git peeling the tag perfectly well
            # and arriving somewhere this deploy cannot use (measured: a tag over a tree gives
            # `expected commit type, but the object dereferences to tree type`). card#9611 r2.
            __rc=0
            __peeled="$(git_at rev-parse --verify --end-of-options "$__oid^{commit}")" || __rc=$?
            [ "$__rc" -eq 0 ] || git_rev_read_failed "resolve the tag '$__cand' ($__oid) to a commit" \
              "rev-parse --verify" "$__rc" \
              "A tag object was read at that name; what it dereferences to did not come back as a" \
              "commit. git's error above says which this is: a read that failed, or an object that" \
              "is not a commit and never will be one."
            printf -v "$__var" '%s' "$__peeled" ;;
    *)      git_read_unusable "'$__cand' names a $__type at $__oid, not a commit" \
              "This deploy checks out a commit. It will not check out, or reason about, anything else." ;;
  esac
}

# whole_seconds <value> — a whole number of seconds read from OUTSIDE this script (an ini, a pool, the environment,
# the serving release's hand-over), printed in base 10; fails, printing nothing, on anything but digits. Every such
# number is parsed through here, because bash arithmetic reads a leading zero as octal: `08` aborts the script with
# "value too great for base" — exit 1, which the ERR trap never sees, so a window already open would fail with no
# banner under the exit code that says nothing was touched — and `010` is silently 8.
# For opcache.revalidate_freq, base 10 is never SHORTER than PHP's own reading, so a wait computed from it is never
# short and "within N s" stays true. Measured with PHP 8.5.4's CLI (opcache_get_configuration(), 2026-09-13): `010`
# is 8, `08` is 0 with a warning ("interpreting as "0" for backwards compatibility"), `0178` is 15.
whole_seconds() {
  case "$1" in '' | *[!0-9]*) return 1 ;; esac
  printf '%s' "$((10#$1))"
}

# daemon_timings — MEZZ_DAEMON_STOP_TIMEOUT_S and MEZZ_DAEMON_SETTLE_S, parsed into DAEMON_STOP_TIMEOUT_S and
# DAEMON_SETTLE_S; on a value that is not a whole number of seconds, fails with TIMING_NOT_READY naming it. Phase A
# refuses on it (A1b). Phase B reads them again before building anything, because the serving release that ran
# phase A may be one that never checked.
# drain_timing — MEZZ_FEED_DRAIN_CEILING_S into FEED_DRAIN_CEILING_S; on a value that is not a whole number of seconds,
# fails with TIMING_NOT_READY naming it. Read in phase A (A1b) and again in phase B, as daemon_timings is.
drain_timing() {
  FEED_DRAIN_CEILING_S="$(whole_seconds "${MEZZ_FEED_DRAIN_CEILING_S:-30}")" || {
    TIMING_NOT_READY="MEZZ_FEED_DRAIN_CEILING_S is '${MEZZ_FEED_DRAIN_CEILING_S:-}', not a whole number of seconds"
    return 1; }
}

daemon_timings() {
  DAEMON_STOP_TIMEOUT_S="$(whole_seconds "${MEZZ_DAEMON_STOP_TIMEOUT_S:-30}")" || {
    TIMING_NOT_READY="MEZZ_DAEMON_STOP_TIMEOUT_S is '${MEZZ_DAEMON_STOP_TIMEOUT_S:-}', not a whole number of seconds"
    return 1; }
  DAEMON_SETTLE_S="$(whole_seconds "${MEZZ_DAEMON_SETTLE_S:-3}")" || {
    TIMING_NOT_READY="MEZZ_DAEMON_SETTLE_S is '${MEZZ_DAEMON_SETTLE_S:-}', not a whole number of seconds"
    return 1; }
}

# ── the PHP floor, derived ────────────────────────────────────────────────────────────────────
# card#9203. `server/composer.json` is the ONE place this project states which PHP it runs on,
# and A6 below READS it. The restatement is what failed: a hand-written `8.3*|8.4*|…` case list
# here went on saying the floor was ^8.3 for as long as the committed `composer.lock` could only
# be installed on >=8.4.1 — so the precondition built to keep `composer install` OUT of the
# maintenance window PASSED on a host where it was certain to fail INSIDE it, with the app down.
# The old case list also accepted `9.*`, which `^8.3` never allowed: a restated constraint drifts
# in both directions at once, and nothing reads both copies.
#
# `tools/verify-php-floor.py` derives the same floor in CI and checks it against composer.lock.
# This script cannot call it — it runs on the prod host, against a tree it has not checked out
# yet, and putting `python3` on that host's requirement list is an infrastructure decision, not
# a side effect of a version bump. Both derivations fail SAFE: each refuses on a constraint it
# cannot interpret rather than guessing one, so a divergence is a loud refusal, never a pass.

# php_require_constraint — read `require.php` from composer.json on stdin; print nothing if it
# is not there. Scoped to the TOP-LEVEL `require` object on purpose: `require-dev` and a
# `config.platform.php` both carry a `"php"` key and neither of them is the floor.
php_require_constraint() {
  awk '
    /^[ \t]*"require"[ \t]*:/ && !seen { inreq = 1; seen = 1; next }
    inreq && /^[ \t]*}/ { inreq = 0 }
    inreq && match($0, /"php"[ \t]*:[ \t]*"[^"]*"/) {
      s = substr($0, RSTART, RLENGTH)
      sub(/^"php"[ \t]*:[ \t]*"/, "", s); sub(/"$/, "", s)
      print s; exit
    }
  '
}

# ver_ge A B — true when version A is at least version B, compared numerically field by field.
# A non-numeric suffix (`8.5.0RC1`, `8.4.1-dev`) is truncated at the first non-digit, which
# treats a release candidate as its release — the permissive direction, and the one composer
# itself takes with a host PHP.
ver_ge() {
  local i x y
  local -a a b
  IFS=. read -r -a a <<< "$1"
  IFS=. read -r -a b <<< "$2"
  for i in 0 1 2; do
    x="${a[i]:-0}"; y="${b[i]:-0}"
    x="${x%%[!0-9]*}"; y="${y%%[!0-9]*}"
    x="${x:-0}"; y="${y:-0}"
    [ "$((10#$x))" -gt "$((10#$y))" ] && return 0
    [ "$((10#$x))" -lt "$((10#$y))" ] && return 1
  done
  return 0
}

# ── PHP-FPM: new code without a reload ────────────────────────────────────────────────────────
# The pool master is root's (a Virtualmin per-domain pool), so this user cannot reload it — and it
# does not have to. MEASURED on the sandbox host, 2026-09-13, with its own php-fpm8.5 binary and FPM
# php.ini, a non-root master, and an in-place `git checkout` between two commits of one file: the
# worker served the OLD file 0.1 s after the checkout and the NEW one 3.1 s after it, at
# opcache.validate_timestamps=1 / revalidate_freq=2 (both PHP's defaults). Rerun changing only
# validate_timestamps=0, the worker still served the old file 8.1 s later — and nothing short of
# restarting the master would change that. So whether the workers see a deploy is decided by ini
# settings, and this READS them rather than assuming them:
#   · opcache.enable off                → nothing is cached; every request compiles from disk.
#   · validate_timestamps on            → a cached script is re-checked against its mtime at most
#                                         every revalidate_freq s; phase B waits that out before `up`.
#   · validate_timestamps off, enabled  → REFUSED. No root-free act makes those workers re-read.
#   · opcache.preload set               → REFUSED. Preloaded code is fixed for the master's life.
# Read from the FPM SAPI (`php-fpm -i`; the CLI reads a different php.ini) and then from every pool
# that runs as THIS user, because a pool's php_value / php_admin_value beats the ini and Virtualmin
# writes a domain's PHP options exactly there. The worst case across those pools wins.
# And then from every `.user.ini` that sits over the app's scripts — in the vhost's document root
# (MEZZ_DOCROOT) and in the release's server/public/ — because opcache.enable, validate_timestamps and
# revalidate_freq are all PHP_INI_ALL, so such a file beats the pool for every request under it
# (opcache.preload is PHP_INI_SYSTEM, and no .user.ini can set it). MEASURED on the sandbox host,
# 2026-09-13, with php-cgi8.5 (the CGI SAPI, not FPM itself): a .user.ini's validate_timestamps=0 and
# revalidate_freq=9 both took effect, its opcache.enable=0 turned the cache off, and its
# opcache.enable=1 over an ini with the cache off did NOT turn it on ("can't be temporarily enabled").
# The judgement does not lean on that last result: each .user.ini is overlaid on each pool, enable
# included, and judged exactly as a pool is — which can only refuse more. A document root that does not
# exist is WARNED about, by name, not refused: the default is the Virtualmin layout, and a host laid out
# otherwise names its own.
#
# ⚑ AND THE FEED STREAM'S TWO HOST CONDITIONS (card#9300, FLEET-STATE.md § 8.3), from the SAME reads —
# one reader of the FPM SAPI, its pools and its .user.ini files, never a second (the card's ruling):
#   R1, its ini half — on the pool MEZZ_STREAM_POOL names, the ini baseline then that pool's override then
#   each .user.ini, exactly as opcache is resolved above. MEASURED on the sandbox host (2026-09-13) with its
#   own php-fpm8.5 and FPM php.ini under a throwaway non-root master, through the framework's real
#   eventStream() primitive, before any of these became a refusal:
#     · output_buffering = 4096 (this host's FPM ini) is NOT a hazard: the primitive's ob_flush() delivered
#       each message at its own second (+0.4 s, +1.4 s, +2.4 s); the same frames with flush() alone arrived
#       together at the end (+3.0 s). Reported, never refused.
#     · zlib.output_compression = on BUFFERS the stream: three messages a second apart arrived as one 242 B
#       record at +3.0 s, gzip-encoded. Refused.
#     · output_handler = ob_gzhandler compressed it (Content-Encoding: gzip), each flush a record of its
#       own. § 8.3 R1 forbids compression between PHP-FPM and the browser; what a browser's EventSource
#       does with that encoding was not measured. Refused — any non-empty output_handler, since this
#       reader cannot tell a compressing handler from another.
#     · ignore_user_abort = on let a handler outlive its client: with it off the worker stopped at the
#       first failed write (4.0 s into a request whose client left at 3 s); with it on the generator was
#       resumed past that write and ran to the next one (6.0 s) — § 8.5's frozen-consumer claim rests on it
#       being off. Refused.
#   R2, its pool half — MEZZ_STREAM_POOL must be one of the pools running as this user (so phase B's SIGTERM
#   needs no root), with request_terminate_timeout 0 or unset (any other value ends every stream on that
#   period), pm.status_path AND pm.status_listen set, and its status must ANSWER over that listener with
#   the pool's own name. pm.status_listen is not optional: MEASURED, with every worker of a pool pinned by a
#   stream, the status request over the pool's own socket queued behind them until a 5 s timeout, and over
#   pm.status_listen it answered in 0.17 s — and a pool pinned by streams is exactly the pool phase B has to
#   read. What this CANNOT see, named rather than assumed: that the web server routes /api/fleet/stream to
#   this pool and nothing else to it (the vhost is root's), pm.max_children against the number of open
#   tabs, and the proxy's client-send timeout. Those, and R1's wire half, are the runbook's.
#
# Sets FPM_POSTURE, FPM_REVALIDATE_S, STREAM_POSTURE and STREAM_STATUS_LISTEN on success; on failure
# FPM_NOT_READY — a refusal title, then its lines. Phase A refuses on it; phase B, which re-reads it, fails
# the window on it.
phpinfo_value() { awk -F' => ' -v k="$2" '$1 == k { print $2; exit }' <<< "$1"; }
pool_ini() { awk -F'\t' -v p="$2" -v k="$3" '$1 == p && $2 == k { v = $3 } END { printf "%s", v }' <<< "$1"; }
ini_on() { case "${1,,}" in 1 | on | yes | true) return 0 ;; esac; return 1; }
unquote() { local v="$1"; v="${v%\"}"; v="${v#\"}"; printf '%s' "$v"; }

# ini_file_value <ini text> <key> — the value a php.ini-syntax file gives <key>, the last setting winning,
# printed after a '=' so that an EMPTY value — which PHP reads as off — differs from no setting at all.
ini_file_value() {
  awk -v k="$2" '
    { line = $0; sub(/^[[:space:]]+/, "", line) }
    index(line, k) == 1 {
      rest = substr(line, length(k) + 1)
      if (rest !~ /^[[:space:]]*=/) next
      sub(/^[[:space:]]*=[[:space:]]*/, "", rest)
      if (rest ~ /^"/) { sub(/^"/, "", rest); sub(/".*$/, "", rest) }
      else { sub(/[[:space:]]*;.*$/, "", rest); sub(/[[:space:]]+$/, "", rest) }
      v = rest; found = 1
    }
    END { if (found) printf "=%s", v }' <<< "$1"
}

# fpm_judge <label> <enable> <validate_timestamps> <revalidate_freq> <preload> — one effective opcache
# posture. Called by fpm_code_reload_ready only: it records into that function's locals (cached, stale,
# preloaded, max_f — in base 10, whole_seconds), and fails with FPM_NOT_READY set on a revalidate_freq it cannot
# wait out. The one place a revalidate_freq is parsed: the ini's, a pool's and each .user.ini's all arrive here.
fpm_judge() {
  local freq
  # Off, or not loaded at all (no `opcache.enable` in the phpinfo): every request reads the disk.
  if ! ini_on "${2:-0}"; then return 0; fi
  cached=1
  if ! ini_on "$3"; then stale+=("$1"); fi
  if [ -n "$5" ]; then preloaded+=("$1"); fi
  freq="$(whole_seconds "$4")" || {
    FPM_NOT_READY=("$1: opcache.revalidate_freq is '$4', not a whole number of seconds")
    return 1; }
  if [ "$freq" -gt "$max_f" ]; then max_f="$freq"; fi
}

# R1's ini directives, read wherever opcache's are: the ini baseline, a pool's php_(admin_)value/flag, a .user.ini.
R1_KEYS='output_buffering|output_handler|zlib\\.output_compression|ignore_user_abort'

fpm_code_reload_ready() {
  FPM_NOT_READY=(); FPM_POSTURE=""; FPM_REVALIDATE_S=0; STREAM_POSTURE=""; STREAM_STATUS_LISTEN=""; STREAM_NOT_READY=()
  local me info ini_file fpm_conf inc f rows
  me="$(id -un)"
  if ! command -v "$FPM_BIN" >/dev/null 2>&1; then
    FPM_NOT_READY=("PHP-FPM binary '$FPM_BIN' was not found"
      "It is derived from this host's CLI PHP (${HOST_PHP_VERSION:-unknown}). Where FPM runs a"
      "different minor, name its binary with MEZZ_FPM_BIN.")
    return 1
  fi
  info="$("$FPM_BIN" -i 2>/dev/null || true)"
  if [ "$(phpinfo_value "$info" 'Server API')" != "FPM/FastCGI" ]; then
    FPM_NOT_READY=("\`$FPM_BIN -i\` did not print an FPM phpinfo"
      "Without it nothing here can say what the workers' opcache does with a changed file.")
    return 1
  fi
  ini_file="$(phpinfo_value "$info" 'Loaded Configuration File')"
  fpm_conf="$(dirname "$ini_file")/php-fpm.conf"
  if [ ! -r "$fpm_conf" ]; then
    FPM_NOT_READY=("cannot read $fpm_conf"
      "It is looked for beside the FPM SAPI's php.ini ($ini_file); the pools that serve the app"
      "are defined from it.")
    return 1
  fi
  local -a pool_files=("$fpm_conf")
  while IFS= read -r inc; do
    # An include is a glob (Debian: pool.d/*.conf), expanded here on purpose.
    # shellcheck disable=SC2086
    for f in $inc; do if [ -r "$f" ]; then pool_files+=("$f"); fi; done
  done < <(awk -F= '/^[[:space:]]*include[[:space:]]*=/ { v = $2; gsub(/^[[:space:]]+|[[:space:]]+$/, "", v); print v }' "$fpm_conf")

  # One row per pool running as this user ("<pool> - -"), then one per opcache or R1 override in it, and one per
  # pool directive R2 reads (request_terminate_timeout, pm.status_path, pm.status_listen), keyed "=<directive>".
  # A pool that runs as ANOTHER user gets one row "<pool> = <user>", so R2 can say whose it is.
  rows="$(awk -v me="$me" -v r1="$R1_KEYS" '
    /^[[:space:]]*\[[^]]+\][[:space:]]*$/ { pool = $0; gsub(/^[[:space:]]*\[|\][[:space:]]*$/, "", pool); next }
    pool != "" && /^[[:space:]]*user[[:space:]]*=/ {
      v = $0; sub(/^[^=]*=[[:space:]]*/, "", v); sub(/[[:space:]]+$/, "", v); owner[pool] = v; next }
    pool != "" && /^[[:space:]]*php_(admin_)?(value|flag)\[[a-z_.]+\][[:space:]]*=/ {
      k = $0; sub(/^[^[]*\[/, "", k); sub(/\].*$/, "", k)
      if (k !~ /^opcache\.[a-z_]+$/ && k !~ ("^(" r1 ")$")) next
      v = $0; sub(/^[^=]*=[[:space:]]*/, "", v); sub(/[[:space:]]+$/, "", v)
      ov[pool, k] = v; next }
    pool != "" && /^[[:space:]]*(request_terminate_timeout|pm\.status_path|pm\.status_listen)[[:space:]]*=/ {
      k = $0; sub(/^[[:space:]]*/, "", k); sub(/[[:space:]]*=.*$/, "", k)
      v = $0; sub(/^[^=]*=[[:space:]]*/, "", v); sub(/[[:space:]]*;.*$/, "", v); sub(/[[:space:]]+$/, "", v)
      ov[pool, "=" k] = v; next }
    END {
      for (p in owner) {
        if (owner[p] != me) { print p "\t=\t" owner[p]; continue }
        print p "\t-\t-"
        for (x in ov) { split(x, pk, SUBSEP); if (pk[1] == p) print p "\t" pk[2] "\t" ov[x] }
      }
    }' "${pool_files[@]}")"
  local -a mine=() stale=() preloaded=()
  mapfile -t mine < <(awk -F'\t' '$2 == "-" { print $1 }' <<< "$rows")
  if [ ${#mine[@]} -eq 0 ]; then
    FPM_NOT_READY=("no PHP-FPM pool runs as $me"
      "Read: ${pool_files[*]}"
      "On this host shape the app's pool runs as the deploy user (docs/PLAN.md § 5). With no pool of"
      "this user's, nothing here can say what will serve the new code.")
    return 1
  fi

  # The .user.ini files that can override a pool for the requests under them (see above).
  local uif docroot f i x ue uv uf rel_uif
  local -a ui_src=() ui_text=()
  uif="$(phpinfo_value "$info" user_ini.filename)"
  if [ "$uif" != "no value" ]; then
    uif="${uif:-.user.ini}"   # no such phpinfo line: PHP's own default name is read, not skipped
    docroot="${MEZZ_DOCROOT:-$HOME/public_html}"
    if [ ! -d "$docroot" ]; then
      warn "no document root at $docroot, so a $uif there — which can turn opcache's timestamp validation off for every request under it — was NOT read. Name the vhost's document root with MEZZ_DOCROOT."
    fi
    for f in "$docroot/$uif" "$APP_DIR/public/$uif"; do
      [ -e "$f" ] || continue
      if [ ! -r "$f" ]; then
        FPM_NOT_READY=("cannot read $f"
          "A $uif can override opcache for every request under it, so it is read, never skipped.")
        return 1
      fi
      ui_src+=("$f"); ui_text+=("$(cat "$f")")
    done
    # Phase A reads the RELEASE's copy as well, which the checkout has not written yet; phase B, run after
    # the checkout, has already read it from disk above. Through git_read_at (card#9608): this was the one
    # `cat-file -e` whose false reading SKIPPED a file rather than refusing, and it was safe only because
    # two earlier reads of the same $SHA had already succeeded — an ordering nothing here stated. It was
    # not even safe against the case it looks safe against: `cat-file -e` exits 0 for a blob that is
    # present and unreadable, so the `git show` below was what would have failed, mid-check, under set -e.
    if [ -z "$POST_CHECKOUT_SHA" ] && git_read_at rel_uif "$SHA" "server/public/$uif"; then
      ui_src+=("server/public/$uif at $(git_at rev-parse --short "$SHA")")
      ui_text+=("$rel_uif")
    fi
  fi

  local p e v fq pl base_e base_v base_f base_p max_f=0 cached=0 k
  base_e="$(phpinfo_value "$info" opcache.enable)"
  base_v="$(phpinfo_value "$info" opcache.validate_timestamps)"
  base_f="$(phpinfo_value "$info" opcache.revalidate_freq)"
  base_p="$(phpinfo_value "$info" opcache.preload)"
  [ "$base_p" != "no value" ] || base_p=""
  for p in "${mine[@]}"; do
    e="$(pool_ini "$rows" "$p" opcache.enable)";              e="$(unquote "${e:-$base_e}")"
    v="$(pool_ini "$rows" "$p" opcache.validate_timestamps)"; v="$(unquote "${v:-$base_v}")"
    fq="$(pool_ini "$rows" "$p" opcache.revalidate_freq)";    fq="$(unquote "${fq:-$base_f}")"
    pl="$(pool_ini "$rows" "$p" opcache.preload)";            pl="$(unquote "${pl:-$base_p}")"
    fpm_judge "[$p]" "$e" "$v" "$fq" "$pl" || return 1
    for i in "${!ui_src[@]}"; do
      ue="$e"; uv="$v"; uf="$fq"
      x="$(ini_file_value "${ui_text[i]}" opcache.enable)";              [ -z "$x" ] || ue="${x#=}"
      x="$(ini_file_value "${ui_text[i]}" opcache.validate_timestamps)"; [ -z "$x" ] || uv="${x#=}"
      x="$(ini_file_value "${ui_text[i]}" opcache.revalidate_freq)";     [ -z "$x" ] || uf="${x#=}"
      fpm_judge "[$p] under ${ui_src[i]}" "$ue" "$uv" "$uf" "$pl" || return 1
    done
  done
  if [ ${#stale[@]} -gt 0 ]; then
    FPM_NOT_READY=("PHP-FPM would go on serving the PREVIOUS release: opcache.validate_timestamps is off for ${stale[*]}"
      "With it off a cached script is never re-read from disk, and this user cannot reload the pool"
      "master, which is root's — measured on the sandbox host, a worker still served a replaced file"
      "8 s after the change. Turn it back on (PHP's default; revalidate_freq bounds what it costs)"
      "in the FPM php.ini, the pool, or the .user.ini named.")
    return 1
  fi
  if [ ${#preloaded[@]} -gt 0 ]; then
    FPM_NOT_READY=("opcache.preload is set for ${preloaded[*]}"
      "Preloaded code is fixed for the life of the FPM master whatever the file timestamps say, and"
      "this user cannot restart the master.")
    return 1
  fi
  # ⚑ THE STREAM POOL IS A REFUSAL IN PHASE A AND A WARNING IN PHASE B, and only this half of the reader is split
  # that way. Phase A runs the SERVING release's copy of this function, so the deploy that first ships this check is
  # judged in phase A by a copy that has none — and a phase B that failed on it would take the app down for a pool
  # nobody was ever asked to provision, over a condition that degrades the feed (F19/F20) rather than the pages. Every
  # later deploy refuses it before anything is touched. opcache, above, stays a failure in both phases: without it the
  # new code is not served at all.
  if ! stream_pool_ready "$info" "$rows" "$me"; then
    [ -n "$POST_CHECKOUT_SHA" ] || return 1
    STREAM_NOT_READY=("${FPM_NOT_READY[@]}"); FPM_NOT_READY=(); STREAM_STATUS_LISTEN=""
  fi
  FPM_REVALIDATE_S="$max_f"
  if [ "$cached" -eq 1 ]; then
    FPM_POSTURE="opcache revalidates a changed file within ${max_f} s"
  else
    FPM_POSTURE="opcache is off, so every request reads the disk"
  fi
}

# stream_pool_ready <phpinfo> <pool rows> <me> — R1's ini half and R2's pool half, for fpm_code_reload_ready (the
# argument and every measurement are there). Reads ui_src/ui_text, that function's .user.ini files. Sets STREAM_POSTURE
# and STREAM_STATUS_LISTEN; on failure FPM_NOT_READY.
stream_pool_ready() {
  local info="$1" rows="$2" me="$3" pool="${MEZZ_STREAM_POOL:-}" key base val x i listen status bad=()
  if [ -z "$pool" ]; then
    FPM_NOT_READY=("MEZZ_STREAM_POOL is unset — no PHP-FPM pool is named as the one that serves /api/fleet/stream"
      "FLEET-STATE.md § 8.3 R2: the stream route needs a DEDICATED pool — request_terminate_timeout 0, pm.status_path"
      "and pm.status_listen set — because each open browser tab pins a worker for as long as it is open, and in the"
      "shared pool those workers starve every snapshot and admin page (FLOOR.md § 9 F20). Provision the pool, route"
      "/api/fleet/stream to it in the vhost (docs/PLAN.md § 5), and name it here.")
    return 1
  fi
  if ! awk -F'\t' -v p="$pool" '$1 == p && $2 == "-" { f = 1 } END { exit !f }' <<< "$rows"; then
    x="$(awk -F'\t' -v p="$pool" '$1 == p && $2 == "=" { print $3 }' <<< "$rows")"
    if [ -n "$x" ]; then
      FPM_NOT_READY=("the stream pool [$pool] runs as '$x', not as $me"
        "Phase B ends the streams that miss fleet.reload with SIGTERM to that pool's workers, which needs no root only"
        "because they run as this user (FLEET-STATE.md § 14 item 17).")
    else
      FPM_NOT_READY=("MEZZ_STREAM_POOL names [$pool], and no pool of that name is defined in ${pool_files[*]}")
    fi
    return 1
  fi

  # R1 — the ini baseline, the stream pool's override, then each .user.ini over the app's scripts.
  local -a r1_where=("[$pool]")
  for i in "${!ui_src[@]}"; do r1_where+=("[$pool] under ${ui_src[i]}"); done
  local ob=""
  for key in output_buffering output_handler zlib.output_compression ignore_user_abort; do
    base="$(phpinfo_value "$info" "$key")"; [ "$base" != "no value" ] || base=""
    val="$(pool_ini "$rows" "$pool" "$key")"; val="$(unquote "${val:-$base}")"
    for i in "" "${!ui_src[@]}"; do
      local v2="$val" where="[$pool]"
      if [ -n "$i" ]; then
        x="$(ini_file_value "${ui_text[i]}" "$key")"; [ -z "$x" ] || v2="${x#=}"
        where="[$pool] under ${ui_src[i]}"
      fi
      case "$key" in
        zlib.output_compression) if ini_on "$v2"; then bad+=("zlib.output_compression is on for $where — measured to hold the stream until the request ends"); fi ;;
        ignore_user_abort)       if ini_on "$v2"; then bad+=("ignore_user_abort is on for $where — a handler then outlives its client past the failed write (§ 8.5)"); fi ;;
        output_handler)          if [ -n "$v2" ] && [ "$v2" != "no value" ]; then bad+=("output_handler is '$v2' for $where — an output filter § 8.3 R1 forbids between PHP-FPM and the browser"); fi ;;
        output_buffering)        [ -n "$i" ] || ob="$v2" ;;
      esac
    done
  done
  if [ ${#bad[@]} -gt 0 ]; then
    FPM_NOT_READY=("the stream would not reach the browser as it is written (FLEET-STATE.md § 8.3 R1)" "${bad[@]}"
      "Every browser on this host would render 'feed down — polling' against a healthy fleet (FLOOR.md § 9 F19).")
    return 1
  fi

  # R2 — the pool's own directives, then its status, read over its separate status listener.
  val="$(pool_ini "$rows" "$pool" "=request_terminate_timeout")"
  case "$(unquote "$val")" in
    '' | 0 | 0s | 0m | 0h | 0d) ;;
    *) bad+=("request_terminate_timeout is '$val' — it would end every healthy stream on that period, every browser reconnecting in lockstep") ;;
  esac
  local spath slisten
  spath="$(unquote "$(pool_ini "$rows" "$pool" "=pm.status_path")")"
  slisten="$(unquote "$(pool_ini "$rows" "$pool" "=pm.status_listen")")"
  [ -n "$spath" ] || bad+=("pm.status_path is not set — phase B lists the streams still open from it")
  [ -n "$slisten" ] || bad+=("pm.status_listen is not set — a status request on the pool's own socket queues behind the very streams it must list (measured: timed out with every worker pinned; 0.17 s over pm.status_listen)")
  if [ ${#bad[@]} -gt 0 ]; then
    FPM_NOT_READY=("the stream pool [$pool] is not one a deploy can drain (FLEET-STATE.md § 8.3 R2)" "${bad[@]}")
    return 1
  fi
  STREAM_STATUS_LISTEN="$slisten"; STREAM_STATUS_PATH="$spath"
  if ! status="$(fpm_status)"; then
    FPM_NOT_READY=("the stream pool [$pool]'s status did not answer over pm.status_listen ($slisten, path $spath) within 5 s"
      "Phase B reads it to find the streams that missed fleet.reload. Check that the pool is running and that this user"
      "can reach that listener.")
    return 1
  fi
  x="$(php -r '$s = json_decode(stream_get_contents(STDIN), true); echo is_array($s) ? ($s["pool"] ?? "") : "";' <<< "$status")"
  if [ "$x" != "$pool" ]; then
    FPM_NOT_READY=("the status at $slisten (path $spath) answers for pool '${x:-<not a PHP-FPM JSON status>}', not [$pool]"
      "MEZZ_STREAM_POOL and the listener must name the same pool, or phase B would drain another pool's requests.")
    return 1
  fi
  STREAM_POSTURE="stream pool [$pool]: its status answers over $slisten; zlib.output_compression, output_handler and ignore_user_abort off; output_buffering ${ob:-0} (defeated by the handler's flush — measured)"
}

# fpm_status [full] — the stream pool's status as JSON, over pm.status_listen, in 5 s or a failure. cgi-fcgi sends
# its whole ENVIRONMENT as the request's FastCGI params, so it runs under `env -i` with PATH alone: nothing of
# this deploy's environment reaches the pool.
fpm_status() {
  local q='json'; [ "${1:-}" = full ] && q='json&full'
  env -i PATH="$PATH" SCRIPT_NAME="$STREAM_STATUS_PATH" SCRIPT_FILENAME="$STREAM_STATUS_PATH" REQUEST_METHOD=GET QUERY_STRING="$q" \
    timeout 5 cgi-fcgi -bind -connect "$STREAM_STATUS_LISTEN" 2>/dev/null | sed '1,/^\r\{0,1\}$/d'
  [ "${PIPESTATUS[0]}" -eq 0 ]
}

# ══════════════════════════════════════════════════════════════════════════════════════════════
# PHASE A — preconditions. EVERY refusal in this phase happens BEFORE anything is touched.
# ══════════════════════════════════════════════════════════════════════════════════════════════
phase_a() {
  step "Preconditions"

  # A0 — never as root. `composer install` and `npm ci` write vendor/, node_modules/ and
  # public/build/; run as root they leave root-owned files that the FPM user cannot rewrite, and
  # the NEXT deploy fails on a permission error whose cause is two deploys old. A root run would
  # also relaunch the daemons as root, and a daemon log created by root is one the cron-started
  # copies — running as this user — cannot append to, so cron's next relaunch fails at the
  # redirect and the daemon stays down. Nothing in this script needs root.
  [ "$(id -u)" -ne 0 ] || refuse "running as root" \
    "Deploy as the application user: the account whose crontab supervises the daemons and whose" \
    "PHP-FPM pool serves the app. Nothing this script does needs root (docs/PLAN.md § 5)."

  # A1 — the tools this script shells out to. A missing binary discovered mid-window is an
  # outage; discovered here it is a refusal. crontab, flock, fuser, setsid and ps are the supervision's:
  # A13 reads the crontab, and restart_daemons finds, stops, relaunches and ages the daemons with them —
  # without ps no holder of a lock can be proven to have started after the restart. cgi-fcgi and timeout
  # are the stream pool's: A14 reads the pool's status over FastCGI, and phase B's drain lists the
  # streams still open with it (fpm_status).
  local missing=()
  for c in git php composer npm curl crontab flock fuser setsid ps cgi-fcgi timeout; do
    command -v "$c" >/dev/null 2>&1 || missing+=("$c")
  done
  [ ${#missing[@]} -eq 0 ] || refuse "missing required command(s): ${missing[*]}"

  # A1b — the restart's timings. restart_daemons does arithmetic on them inside the window, where a value it
  # cannot read would stop the deploy with the app down.
  daemon_timings || refuse "$TIMING_NOT_READY"
  drain_timing || refuse "$TIMING_NOT_READY"

  # A2 — the anti-"bare re-run" guard (handover item 3). A deploy that failed in the window left
  # this marker AND left the app down. Without this check the obvious operator reflex — run it
  # again — would open a fresh window over a half-applied release and, if the second run happened
  # to succeed, would erase every trace that the first one did not. The recovery is deliberately
  # a human act: read the marker, decide, then remove it.
  if [ -e "$MARKER" ]; then
    refuse "a previous deploy failed and has not been reviewed" \
      "$(sed 's/^/  | /' "$MARKER" 2>/dev/null || true)" \
      "" \
      "Review the failure, then clear the marker to allow another deploy:" \
      "  rm $MARKER" \
      "The app is (or should be) DOWN. Once the marker is cleared, re-running this script with --redeploy" \
      "and the --ref that is checked out brings it back on that code AND restarts every daemon on it. A bare" \
      "\`cd $APP_DIR && php artisan up\` leaves the daemons as they are, which may be the previous release's." \
      "Check \`git -C $DEPLOY_ROOT rev-parse HEAD\` first."
  fi

  # A3 — this really is a Mezzanine checkout, and a git one.
  git_at rev-parse --git-dir >/dev/null 2>&1 || refuse "$DEPLOY_ROOT is not a git checkout"
  [ -f "$APP_DIR/artisan" ] || refuse "$APP_DIR/artisan not found — MEZZ_DEPLOY_ROOT is not a Mezzanine checkout"

  # A4 — a clean tree. A modified file on the prod checkout IS the hand-deploy D-13 forbids, and
  # the checkout below would either clobber it or fail. Either way the operator must see it now.
  local dirty; dirty="$(git_at status --porcelain)"
  [ -z "$dirty" ] || refuse "the prod checkout has local modifications" \
    "$(printf '%s' "$dirty" | sed 's/^/  | /')" \
    "Prod moves only by this script (D-13). Nothing may be edited on the host."

  # A5 — the environment file. No secret value is printed: a line below prints a key's value only for a
  # non-secret key, and APP_KEY, DB_URL and every credential are tested for shape only. Every key is read
  # through env_read, so a line it cannot read exactly as Laravel does refuses here by name; and wherever
  # Laravel resolves a line's text to something else, the check below decides on the value the app
  # RECEIVES (env_laravel_value) rather than on that text — APP_DEBUG, CACHE_STORE, APP_KEY and the CA.
  # The text is compared only where no text the check ACCEPTS resolves to another value: APP_ENV,
  # DB_CONNECTION, store_locality's `/`-prefixed DB_SOCKET, and the loopback DB_HOST names. A text those
  # four do NOT accept may still resolve to something else, and every one of them falls on the strict side
  # of that line rather than the exempting one: `DB_HOST=null` is a host of null, which pdo_mysql reads as
  # the local socket, and is read here as a store on ANOTHER host — a CA demanded that Laravel would not
  # need, never a network hop waved through.
  [ -f "$ENV_FILE" ] || refuse "$ENV_FILE does not exist" \
    "It is created once when the host is stood up: copy server/.env.example, fill it in," \
    "and run \`php artisan key:generate\` there (docs/PLAN.md § 5)."
  local perm other; perm="$(stat -c '%a' "$ENV_FILE")"; other="${perm: -1}"
  [ "$other" = "0" ] || refuse ".env is readable beyond its owner and group (mode $perm)" \
    "chmod 640 $ENV_FILE"
  # Before the first read: whether a LINE is what Laravel reads is a property of the whole FILE.
  env_file_scan

  local app_env app_debug app_key db_conn ssl_ca
  env_read app_env APP_ENV || true
  [ "$app_env" = "production" ] || refuse "APP_ENV is '${app_env:-unset}', not 'production'" \
    "This script deploys PROD. Pointing it at a sandbox checkout is how the two instances" \
    "(D-13) become one."
  env_read app_debug APP_DEBUG || true
  # `server/config/app.php` is `(bool) env('APP_DEBUG', false)`, so what decides debug is the value the app
  # RECEIVES: Env::get lowercases first, and `FALSE`, `False` and `(false)` each reach it as PHP false —
  # debug OFF, a correct production config, which a text compare refused.
  local app_debug_value; env_laravel_value app_debug_value "$app_debug"
  [ "$app_debug_value" = "false" ] || refuse "APP_DEBUG is '${app_debug:-unset}', not 'false'" \
    "Debug mode renders stack traces — including environment values — to any visitor." \
    "Write it as false — FALSE, False and (false) are the same value to Laravel and pass here. Any other" \
    "text is refused even where PHP would cast it to false (0, null, an empty value, no line at all): a" \
    "production host states that debug is off. 'off' is not one of them — PHP reads that string as TRUE."
  env_read app_key APP_KEY || true
  # A key the app receives as a falsy value is no key at all, whatever the line reads as (env_app_falsy).
  ! env_app_falsy "$app_key" || refuse "APP_KEY is empty, or is a value the app receives as no key at all" \
    "server/.env.example ships it empty deliberately; it is minted per host with" \
    "\`php artisan key:generate\` (docs/PLAN.md § 5). Minting one HERE would silently" \
    "invalidate every existing session and encrypted column."
  env_read db_conn DB_CONNECTION || true
  # ⚠ 'mysql' HERE IS THE LARAVEL CONNECTION NAME (server/config/database.php), NOT THE SERVER
  # PRODUCT. D-15's 2026-09-09 amendment repinned the product to MariaDB; the app is still
  # wired to the `mysql` connection — Tests\TestCase and § 6.2's pin guard both key on
  # `database.connections.mysql.database` — and Laravel's `mysql` driver speaks to a MariaDB server.
  # The operator ruled on 2026-09-14 that the app keeps the `mysql` connection name (docs/PLAN.md,
  # D-15's 2026-09-14 amendment). Moving to config/database.php's `mariadb` connection reopens only
  # for a MariaDB-specific Laravel feature, and would change what this script accepts and what those
  # guards key on.
  [ "$db_conn" = "mysql" ] || refuse "DB_CONNECTION is '${db_conn:-unset}', not 'mysql'" \
    "D-15 and docs/design/FLEET-STATE.md § 6.1 pin the store to MariaDB, at the version floor" \
    "§ 6.1 states, reached through Laravel's 'mysql' connection. sqlite here" \
    "would be a prod store that silently cannot do what the fold needs (FOR UPDATE SKIP LOCKED)" \
    "and that no backup or provisioning decision covers."
  # A cache store that PERSISTS between requests (docs/PLAN.md § 5) — a security obligation, not a
  # tuning choice. App\Auth\ActiveUserProvider pays for its dummy bcrypt ONCE per deployment by
  # keeping it in the cache, so that an unknown address and a known one with a wrong password cost
  # the same hashing work. On array/null the hash is minted again on every miss — two bcrypts
  # against one — and the timing gap is a user-enumeration oracle on an endpoint whose rate limiter
  # keys on email+IP and so does not throttle probing N addresses from one IP at all. UNSET is fine
  # and is not checked: config/cache.php's own default is 'database'.
  #
  # The decision is on the value the app RECEIVES, because the two non-persistent stores are reached by
  # more texts than spell them. A value the app receives as PHP null leaves `config('cache.default')`
  # null, `CacheManager::getDefaultDriver()` falls back to `'null'`, and `getConfig('null')` returns the
  # DISCARD driver — a cache that keeps nothing, silently, which is this oracle wide open. `array` is the
  # per-request store. Every other value either names a store in config/cache.php or throws "Cache store
  # [x] is not defined" at boot: loud, and not this hole. (Measured 2026-09-15 against server/vendor:
  # CACHE_STORE=NULL, Null, (null) and (NULL) each reached the discard driver.)
  local cache_store cache_value; env_read cache_store CACHE_STORE || true
  env_laravel_value cache_value "$cache_store"
  case "$cache_value" in
    null | string:array) refuse "CACHE_STORE is '$cache_store', which does not survive a request" \
      "docs/PLAN.md § 5: the login path's non-enumerability depends on the dummy bcrypt" \
      "outliving the request that minted it. server/.env.example ships 'database'." ;;
  esac

  # TLS to the store is required BECAUSE the credential and every descriptor cross a network, so it is
  # required exactly when they do (FLEET-STATE.md § 6.1; the operator's ruling of 2026-09-14, docs/PLAN.md
  # D-15's amendment: "database is local; there is no SSL support nor is it needed when mysql is on
  # localhost"). A store on this host passes with the CA unset, and with it set: setting one is the
  # operator's choice, not a defect. store_locality names what decided it, and fails closed.
  # A CA set for a store on this host is WARNED about: pdo_mysql then requires TLS over the socket as well,
  # so a MariaDB that offers none refuses every connection the release makes. Measured 2026-09-14 with
  # PHP 8.5.4 against the sandbox host's MariaDB and a non-existent account: over the socket the connection
  # reached authentication without a CA, and failed "[2002] Cannot connect to MySQL using SSL" with one.
  env_read ssl_ca MYSQL_ATTR_SSL_CA || true
  # The CA the connection carries is the one server/config/database.php's `array_filter` KEEPS, and that
  # is the value the app receives when PHP reads it as true — env_app_falsy owns that rule and the reason
  # it is larger than Env::get's literals. A line whose value falls in it is a connection with NO CA
  # whatever its text says; counting it set would pass a store on another host that connects in plaintext.
  ! env_app_falsy "$ssl_ca" || ssl_ca=""
  store_locality
  if [ "$STORE_LOCALITY" = remote ]; then
    [ -n "$ssl_ca" ] || refuse "MYSQL_ATTR_SSL_CA is unset for a store on another host ($STORE_WHY)" \
      "FLEET-STATE.md § 6.1: TLS is REQUIRED to a store on another host, certificate verified, with no" \
      "plaintext fallback — the credential and every descriptor cross a network between hosts." \
      "A store on this host needs none: DB_SOCKET naming its socket, or DB_HOST one of ${ENV_LOOPBACK_HOSTS[*]}." \
      "A CA the app receives as a value PHP reads as false is UNSET here, whatever the line says:" \
      "server/config/database.php's array_filter drops the option, so that line is a connection with no" \
      "CA at all (this script's env_app_falsy states which values those are). Give the CA's path."
  elif [ -z "$ssl_ca" ]; then
    say "  ok — store on this host ($STORE_LOCALITY; $STORE_WHY) — TLS not required, FLEET-STATE.md § 6.1 (decided from .env; a variable set in the PHP-FPM or process environment is not seen)"
  else
    say "  ok — store on this host ($STORE_LOCALITY; $STORE_WHY) — MYSQL_ATTR_SSL_CA is set, though not required, FLEET-STATE.md § 6.1 (decided from .env; a variable set in the PHP-FPM or process environment is not seen)"
    warn "MYSQL_ATTR_SSL_CA is set for a store on this host: pdo_mysql then requires TLS to it, over the socket too," \
      "and a MariaDB that offers no TLS refuses every connection. Leave it unset unless this store serves TLS."
  fi

  # NOT CHECKED HERE, on purpose: § 6.1's MariaDB version floor, the storage engine and the
  # collations. FLEET-STATE.md § 6.1 assigns every one of them to "verified at provisioning", and a
  # deploy-time re-check would either duplicate that verification or, worse, become the place it is
  # believed to happen while checking something weaker. The session time zone is the app's own: the
  # `mysql` connection's `timezone` key in server/config/database.php sets it on every connection, and
  # DatabasePinTest asserts it.
  #
  # The PHP floor is A6, and it is NOT here — it needs $SHA, so it sits after A9. See it there.

  # A7 — fetch, and resolve the ref to an exact commit. The fetch is read-only with respect to
  # what is being served (it moves remote-tracking refs, nothing in the worktree), so --dry-run
  # runs it too: a ref check that did not fetch would be checking yesterday's answer.
  step "Fetching $REMOTE"
  git_at fetch --prune --tags "$REMOTE"

  # The candidates, in the order they have always been tried. git_commit_of (card#9611) asks the
  # refs and the object store separately, so "there is no ref of that name" and "git could not read
  # it" cannot arrive as the same answer: the refusal below is reached ONLY when the refs were read
  # and none of the three names anything. A failed read refuses above it, naming the object.
  # ⚠ THE INPUTS THE LINE BELOW CANNOT PROMISE THAT ABOUT, named for the operator rather than
  # assumed away (card#9611 r2): `$REF` is not always a ref NAME. Rev syntax (`~`, `^`, `@{}`, `:/`)
  # resolves by WALKING THE COMMIT GRAPH and an abbreviated id is a lookup IN the object store, and
  # a store too damaged to search answers either with the SAME SILENCE an absent name does (measured
  # — git_ref_oid states it). check-ref-format is what separates a name from rev syntax; its stderr
  # is dropped because it reads NOTHING — it parses a string, and its status is the whole answer,
  # which is the opposite of the silenced reads this card is about.
  # ⚠ AND `$REF` IS ASKED OF check-ref-format WITH ITS LEADING DASHES OFF (card#9611 r3). That
  # command has no `--end-of-options` and no `--`: BOTH are parsed as the refname and exit 129 with
  # the usage message for EVERY ref, valid or not (measured, git 2.53.0 — `--allow-onelevel
  # --end-of-options main` → 129), so the guard git_ref_oid uses cannot be used here. Without one,
  # `--ref -foo` was read as an OPTION: exit 129, usage swallowed by 2>/dev/null, and the note below
  # then told the operator "'-foo' is not a ref name: it carries rev syntax" — which is false, and
  # false in the one direction this whole card is about. git_ref_oid resolves `$REF` with
  # --end-of-options, so it IS asked as a name; the dashes are stripped for the classification only,
  # which leaves `-foo` classified as the name it is and `-foo~2` as the rev syntax it is.
  local ref_note=() ref_note_head="" ref_probe="$REF"
  case "$REF" in -*) ref_probe="${REF#"${REF%%[!-]*}"}" ;; esac
  if [ -n "$ref_probe" ] && ! git_at check-ref-format --allow-onelevel "$ref_probe" >/dev/null 2>&1; then
    ref_note_head="⚠ '$REF' is not a ref name: it carries rev syntax, so resolving it walked the commit graph —"
  else
    case "$REF" in
      '' | ? | ?? | ??? | *[!0-9a-fA-F]*) ;;
      *) ref_note_head="⚠ '$REF' is hex, so git also looked for it as an ABBREVIATED commit id —" ;;
    esac
  fi
  [ -z "$ref_note_head" ] || ref_note=( "$ref_note_head" \
    "a question that READS THE OBJECT STORE, where a store too damaged to search answers with the" \
    "same silence. Pass the full 40-character commit id if you have one: that resolves without the" \
    "store being read, so a failure behind it is named as the failed read it is." )
  git_commit_of SHA "refs/remotes/$REMOTE/$REF" "refs/tags/$REF" "$REF" \
    || refuse "'$REF' does not resolve to a commit on $REMOTE" \
      "The refs were read and carry no such name, and git printed nothing while reading them." \
      "Check the spelling, and that the branch or tag is pushed." "${ref_note[@]}"

  # A8 — prod runs RELEASED code. `main` is the release branch (README § Branch model), so a
  # commit that is not an ancestor of $REMOTE/main has not been through the release PR, the
  # release-pr-guard or the tagger. The escape hatch is explicit and named in the log, never
  # implicit: a hotfix an operator has decided to deploy is a decision, not a default.
  #
  # ⛔ AND THE ANSWER IS THE STATUS, NOT "non-zero" (card#9611). `--is-ancestor` exits 1 for "it is
  # not one" and 128 for everything it could not answer (the block below splits THAT apart in turn).
  # `2>/dev/null` on an `if !` read the second as the first, so the
  # deploy refused with a statement about the commit graph that was never established, and hid git's
  # own error while doing it. Nothing is silenced now: exit 1 is silent anyway (measured, git
  # 2.53.0), so what reaches the operator is exactly what git had to say.
  #
  # ⛔ AND 128 IS NOT ONE CONDITION EITHER (card#9611 r2). `--is-ancestor` exits 128 for a TARGET REF
  # THAT IS NOT THERE exactly as for a graph it could not read — measured on a COMPLETELY HEALTHY
  # store: with `refs/remotes/origin/main` pruned away, `fatal: Not a valid object name
  # refs/remotes/origin/main`, exit 128. Reading that as "git could not read the graph" states a
  # failure that never happened AND takes the escape hatch away in the one place it is most needed,
  # because where there is no release branch THE QUESTION IS ANSWERED: nothing has been released, so
  # this commit is not released — which is exactly the finding --allow-unreleased waives. So the
  # NAME question is asked first, through the same git_ref_oid A7 resolves with, and it is
  # answerable on a broken store (measured: that name still resolves while every object read fails).
  # What reaches --is-ancestor is then the ID it resolved to, so a 128 from it can only be the graph.
  local main_oid="" ancestry=0 short_sha
  # THE ONE UNGUARDED READ HERE, and why it needs no guard (card#9611 r3, recorded rather than
  # wrapped): $SHA is a full commit id that git_commit_of proved readable with `cat-file -t` one
  # call earlier, and abbreviating it reads that same object. A store that could fail this fails
  # A7's `git fetch` long before. If it somehow did fail, `set -e` ends phase A with git's own error
  # on screen and nothing touched — the safe direction, which is why this is a comment and not a
  # refusal path for a state that cannot be reached.
  short_sha="$(git_at rev-parse --short "$SHA")"
  if git_ref_oid main_oid "refs/remotes/$REMOTE/main"; then
    git_at merge-base --is-ancestor "$SHA" "$main_oid" || ancestry=$?
    [ "$ancestry" -le 1 ] || git_rev_read_failed \
      "tell whether $short_sha is contained in $REMOTE/main" \
      "merge-base --is-ancestor" "$ancestry" \
      "Exit 1 is \"it is not an ancestor\" and $ancestry is not that. $REMOTE/main IS there — it" \
      "resolved to $main_oid — so what could not be read is the graph between the two." \
      "--allow-unreleased does NOT apply here. It waives a commit that is not released, which is a" \
      "finding; this run has no finding to waive, because the question was never answered." \
      "NEXT STEP — THIS IS THE STORE, NOT THE RELEASE, so the same refusal meets the recovery" \
      "deploy the in-window banner names (--ref <sha> --allow-unreleased). Repair the read, then" \
      "run the same command again:" \
      "  git -C $DEPLOY_ROOT fsck" \
      "      names the object, and whether it is unreadable or absent" \
      "  git -C $DEPLOY_ROOT fetch --prune $REMOTE" \
      "      asks $REMOTE for the objects behind its refs again" \
      "  git -C $DEPLOY_ROOT repack -a -d" \
      "      rewrites this checkout's packs from what it can still read" \
      "Each of those works inside $DEPLOY_ROOT/.git and leaves this host's own files alone." \
      "⛔ DO NOT RE-CLONE $DEPLOY_ROOT. server/.env is created on this host and is in no commit, so" \
      "its APP_KEY and DB_PASSWORD exist nowhere else; a fresh clone also takes server/storage/ —" \
      "the logs — and .deploy-failed, the marker a failed window leaves to be read, with it."
  else
    # No release branch on $REMOTE at all. The fetch above ran --prune, so this is what $REMOTE
    # carries NOW, read from refs that were read — an ANSWER, and the answer is that nothing has
    # been released, so neither is this commit. That is the finding the hatch waives, and the hatch
    # therefore still works here: the gate below is reached with the flag's meaning intact.
    ancestry=1
  fi
  if [ "$ancestry" -ne 0 ]; then
    # ONE gate, two texts. What an operator is told differs — a commit off the release branch and a
    # release branch that is not there are not the same news — what the flag DOES does not.
    local head_line="$short_sha is not contained in $REMOTE/main" warn_line="$short_sha is not on $REMOTE/main"
    local why=(
      "Prod deploys released code. Cut the release PR, or — deliberately —" \
      "re-run with --allow-unreleased." )
    if [ -z "$main_oid" ]; then
      head_line="there is no $REMOTE/main for $short_sha to be contained in"
      warn_line="there is no $REMOTE/main to contain $short_sha"
      why=(
        "The fetch above ran --prune, so this is what $REMOTE carries now: no release branch, and" \
        "therefore nothing released. The refs were read and carry no such name — this is an ANSWER," \
        "not a read that failed, and it is the same finding --allow-unreleased waives, so the flag" \
        "applies here as it does to any other unreleased commit." \
        "Check that MEZZ_REMOTE names the remote you mean and that the release branch is pushed," \
        "cut the release PR, or — deliberately — re-run with --allow-unreleased." )
    fi
    [ "$ALLOW_UNRELEASED" -eq 1 ] || refuse "$head_line" "${why[@]}"
    warn "DEPLOYING UNRELEASED CODE: $warn_line (--allow-unreleased)"
  fi

  CURRENT_SHA="$(git_at rev-parse HEAD)"
  # A9 — a same-sha re-run is not a silent no-op (handover item 3). It is sometimes exactly what
  # an operator wants (a host rebuilt under an unchanged release); it must never be what they get
  # without saying so, because "the deploy ran and nothing changed" and "the deploy ran and the
  # new code is live" print the same success line.
  if [ "$CURRENT_SHA" = "$SHA" ] && [ "$REDEPLOY" -eq 0 ]; then
    refuse "$(git_at rev-parse --short "$SHA") is already what is checked out" \
      "Nothing would change. To rebuild dependencies, caches and daemons on the same commit:" \
      "  $0 --ref $REF --redeploy"
  fi

  # A6 — THE PHP FLOOR, read from the RELEASE BEING DEPLOYED. Out of letter order on purpose: it
  # needs $SHA (A7), and the floor that matters is the TARGET tree's, not this host's checkout.
  # Those two differ on exactly one deploy — the one that RAISES the floor — and that is the deploy
  # that would otherwise take the app down and only then discover the host cannot install the new
  # lock. Reading the checkout would pass it; reading the target refuses it before the window.
  #
  # composer would refuse too. It would refuse in PHASE B, with the app already down: exit 2, a
  # marker on disk, nothing rolled back and a bare re-run refused (card#7459). This check is that
  # same failure, moved to before anything is touched — and it is DERIVED from composer.json rather
  # than restated, because a restated copy of it is what card#9203 was.
  local composer_json="" floor_constraint floor_op floor_min floor_max phpver
  # The `|| true` drops ONE status — "no such file at $SHA" — and the line below disposes of it by name,
  # together with a file that is there and empty. A git read that FAILED never reaches either.
  git_read_at composer_json "$SHA" server/composer.json || true
  [ -n "$composer_json" ] || refuse \
    "server/composer.json is missing or empty at $(git_at rev-parse --short "$SHA")" \
    "It is where the PHP floor is declared. Without it this check cannot run, and a deploy" \
    "that carried on regardless would meet the floor inside the maintenance window."
  floor_constraint="$(printf '%s\n' "$composer_json" | php_require_constraint)"
  [ -n "$floor_constraint" ] || refuse \
    "no \`require.php\` in server/composer.json at $(git_at rev-parse --short "$SHA")" \
    "This precondition derives the PHP floor from that constraint (card#9203) and will not" \
    "guess one. Declare it there."
  case "$floor_constraint" in
    '^'*)  floor_op='^';  floor_min="${floor_constraint#^}"  ;;
    '>='*) floor_op='>='; floor_min="${floor_constraint#>=}" ;;
    *)     floor_op=''; floor_min='' ;;
  esac
  # …and the remainder must be a bare dotted version, nothing else. `^8.4.1 || ^9.0` carries the
  # prefix above and is NOT a constraint this can evaluate: reading it as `^8.4.1` and silently
  # dropping the alternative is a MISREAD, and a floor check that misreads its constraint is the
  # defect card#9203 filed, not a fix for it. Refusing beats guessing, always in this direction.
  case "$floor_min" in
    ''|*[!0-9.]*|.*|*.) floor_op='' ;;
  esac
  [ -n "$floor_op" ] || refuse \
    "server/composer.json declares a PHP constraint this check cannot evaluate: '$floor_constraint'" \
    "It understands \`^X.Y.Z\` and \`>=X.Y.Z\`, and refuses everything else rather than read" \
    "part of it. Widen \`php_require_constraint\` and this case deliberately, or simplify the" \
    "constraint."
  # `^X.…` is bounded above at the next major; `>=X.…` is not bounded at all.
  floor_max=""
  if [ "$floor_op" = '^' ]; then floor_max="$(( 10#${floor_min%%.*} + 1 )).0.0"; fi
  phpver="${HOST_PHP_VERSION:-0}"
  if ! ver_ge "$phpver" "$floor_min" || { [ -n "$floor_max" ] && ver_ge "$phpver" "$floor_max"; }; then
    refuse "PHP $phpver does not satisfy server/composer.json's $floor_constraint at $(git_at rev-parse --short "$SHA")" \
      "\`composer install\` reads that same constraint and would refuse — but it runs in the" \
      "maintenance window, with the app already down. This refusal is that failure, moved to" \
      "before anything is touched." \
      "" \
      "Either raise this host's PHP to satisfy $floor_constraint, or deploy a release whose" \
      "floor it already meets."
  fi
  say "  ok — PHP $phpver satisfies $floor_constraint, declared by server/composer.json at $(git_at rev-parse --short "$SHA")"

  # A10 — FLEET-STATE.md § 6.9 rule 1: "Every migration on `events` states its algorithm in a
  # comment AND THE DEPLOY CHECKS IT". This is that check, and it reads the TARGET tree out of the
  # object database (git show) rather than the working copy, so it can refuse BEFORE the checkout
  # and before the window. What it proves is that an algorithm was DECLARED — it cannot prove
  # MariaDB will honour it; a declared INSTANT that the server rejects fails loudly at migrate time,
  # which is the backstop. What it removes is the silent case: an ALTER that nobody thought about,
  # taking the ingest down for the length of a table copy.
  step "Checking migrations against FLEET-STATE.md § 6.9"
  local mig body mig_list offenders=()
  # The file list is this gate's DENOMINATOR: an empty one certifies the entire tree in a single line,
  # so where it came from is what decides whether that line is evidence. git_ls_at answers "nothing at
  # this commit" at status 0 and refuses a read that failed (card#9608) — until it, a git error left the
  # list empty and the gate printed `ok — no undeclared ALTER` over a tree it had never listed.
  git_ls_at mig_list "$SHA" server/database/migrations
  while IFS= read -r mig; do
    [ -n "$mig" ] || continue
    git_read_at body "$SHA" "$mig" \
      || refuse "$mig is in $SHA's tree and then was not there to read"
    if printf '%s' "$body" | grep -Eqi "Schema::table\([[:space:]]*['\"]events['\"]|ALTER[[:space:]]+TABLE[[:space:]]+\`?events\`?"; then
      printf '%s' "$body" | grep -Eqi "ALGORITHM[[:space:]]*=[[:space:]]*(INSTANT|INPLACE)" \
        || offenders+=("$mig")
    fi
  done <<< "$mig_list"
  if [ ${#offenders[@]} -gt 0 ]; then
    refuse "migration(s) alter \`events\` without stating an ALGORITHM" \
      "$(printf '  | %s\n' "${offenders[@]}")" \
      "FLEET-STATE.md § 6.9: ALGORITHM=INSTANT for a nullable column added at the end," \
      "INPLACE for a secondary index. Anything that would be COPY does not ship as a" \
      "migration at all — \`events\` is written on the ingest's request path and a blocking" \
      "ALTER is an ingest outage."
  fi
  if [ -n "$mig_list" ]; then
    say "  ok — no undeclared ALTER on \`events\` in $(git_at rev-parse --short "$SHA")"
  else
    # A release that ships no migration at all passes — there is no ALTER to declare — but it says
    # THAT, rather than saying it read a list of migrations and found them all declared.
    say "  ok — $(git_at rev-parse --short "$SHA") ships no migrations: nothing under server/database/migrations"
  fi

  # A10b — config drift between the release and the host. A release that introduces a setting ships
  # it in `server/.env.example`; the host's `.env` was written by hand when the host was stood up
  # and NOTHING ever updates it again. The failure this catches is the quiet one: the deploy is
  # green, the app is up, and one feature reads a value nobody set. It WARNS rather than refuses
  # because an absent key is not automatically a defect — several have framework defaults, and
  # `.env.example`'s commented lines are deliberately optional — but nothing else on this host will
  # ever mention it.
  # Whether this host sets a key is asked through env_get, the one reader that answers it the way Laravel
  # would: a hand-rolled `^[[:space:]]*KEY=` line-grep reported `export FOO=…` and `"FOO"=…` — both of them
  # Dotenv's FOO — as a key the host does not set. Its third answer is kept apart: status 2 is a key written
  # in a form this deploy does not read, which is not "missing" and not "set" but "not established", and
  # saying "does not set" of it sends the operator to add a line that is already there. (A5 has run
  # env_file_scan on this file, which is what makes a LINE a unit here at all — env_get's precondition.)
  local want="" example missing_keys=() unread_keys=() k k_rc
  # "no key of .env.example is unset on this host" and "that file was not read" are the same silence
  # from here, so the read that produced the key list has to be the one that can tell them apart.
  if git_read_at example "$SHA" server/.env.example; then
    want="$(printf '%s\n' "$example" | grep -Eo '^[A-Z][A-Z0-9_]*=' | tr -d '=' || true)"
  else
    warn "the target release has no server/.env.example at $(git_at rev-parse --short "$SHA"), so no key of it was compared against this host's .env"
  fi
  for k in $want; do
    k_rc=0; env_get "$k" >/dev/null || k_rc=$?
    case "$k_rc" in
      1) missing_keys+=("$k") ;;
      2) unread_keys+=("$k") ;;
    esac
  done
  [ ${#missing_keys[@]} -eq 0 ] \
    || warn "the target release's .env.example names keys this host's .env does not set: ${missing_keys[*]}"
  [ ${#unread_keys[@]} -eq 0 ] \
    || warn "the target release's .env.example names keys this host's .env writes in a form this deploy does not read, so whether the release's default or the host's value is in force is not established: ${unread_keys[*]}"

  # A11 — trusted proxies (docs/PLAN.md § 5). Checked against the TARGET tree for the same reason
  # as A10. `trustProxies('*')` lets any client forge X-Forwarded-For, which defeats the key that
  # D1 § 12.3's failed-authentication limit is built on and turns that limit into the decoration
  # § 12.3 says it must not be. Trusting NOTHING is the state § 5 describes as fail-safe-but-coarse
  # (every request appears to come from the proxy), so it is a loud warning here and not a
  # refusal — the doc's own reading, not a softened one.
  # Both answers below are positive statements about this file's TEXT, and neither can be made about a
  # file that was not read — which is why the read is git_read_at's (card#9608). A silenced git error
  # used to leave `bootstrap` empty: the `*` refusal became UNREACHABLE and the run emitted `no
  # trustProxies() configured` as its finding, so the gate that exists to stop a forgeable
  # X-Forwarded-For shipping reported the coarse-but-safe state instead, about a file it never opened.
  local bootstrap
  if git_read_at bootstrap "$SHA" server/bootstrap/app.php; then
    if printf '%s' "$bootstrap" | grep -Eq "trustProxies\(.*['\"]\*['\"]"; then
      refuse "server/bootstrap/app.php trusts ALL proxies (\`*\`)" \
        "docs/PLAN.md § 5: never \`*\`. Name the actual reverse proxy."
    fi
    printf '%s' "$bootstrap" | grep -q "trustProxies" \
      || warn "no trustProxies() configured — the failed-auth limit will key on the reverse proxy's IP for every request (docs/PLAN.md § 5; coarse, not forgeable)"
  else
    # NOT a warning, and not because the unchecked proxies are worth a refusal on their own: a release
    # without this file cannot RUN. `server/artisan` line 14 is
    # `$app = require_once __DIR__.'/bootstrap/app.php';`, so every artisan command fails on it — the
    # first of them `php artisan optimize:clear`, INSIDE the maintenance window, with the app already
    # down and recovery a human act. This is that failure moved to before anything is touched, which is
    # the same reading A6 makes of the PHP floor and A13 of a missing bin/supervision.sh.
    refuse "server/bootstrap/app.php is not in $(git_at rev-parse --short "$SHA")" \
      "server/artisan requires it (\`\$app = require_once __DIR__.'/bootstrap/app.php';\`), so EVERY" \
      "artisan command of that release fails — the first being \`php artisan optimize:clear\`, which" \
      "runs inside the maintenance window. A deploy that carried on here would take the app down and" \
      "leave it down." \
      "" \
      "It is also where docs/PLAN.md § 5's trusted proxies are declared, and which proxies that" \
      "release trusts cannot be read either."
  fi

  # A12 — a lockfile for the asset build. `npm ci` is used below and requires one; more to the
  # point, package.json floats (vite ^8, tailwind ^4), so a lockfile-less prod build can ship
  # different JavaScript from the same commit on two consecutive days, and nothing in the repo
  # would record which. Refusing here is not this script being strict — it is the only place the
  # question is still cheap.
  local lock_at=""
  git_ls_at lock_at "$SHA" server/package-lock.json
  [ -n "$lock_at" ] || refuse \
    "server/package-lock.json is missing from $(git_at rev-parse --short "$SHA")" \
    "The prod asset build must be reproducible: package.json floats (vite ^8, tailwind ^4)," \
    "so without a lockfile the same commit can build different assets on different days." \
    "Commit the lockfile (\`npm install\` in server/, commit server/package-lock.json)."

  # A13 — supervision: the DEPLOYED release's crontab block, judged by that release's own bin/supervision.sh.
  # The long-lived daemons of FLEET-STATE.md § 2.1 have no systemd unit (the header's NO ROOT note): this
  # user's crontab supervises them, and the window installs the deployed release's block (restart_daemons).
  # So the target's bin/supervision.sh is read out of git, as A6 reads its composer.json, and its own install
  # is run with nothing written: every refusal that install makes — a crontab it cannot read, a supervised
  # command already run outside the managed block, a path cron cannot carry — is made HERE, before anything
  # is touched, and the write in the window can fail only on what changed in between. A crontab that merely
  # lacks this checkout's entries, or carries another release's, is NOT refused: the window replaces the
  # block, and the lines it adds and removes are printed. That is also the crontab a deploy that failed in
  # the window leaves behind — the previous release's block over HEAD's code — and the re-run repairs it.
  #
  # IN A BASH PROCESS OF ITS OWN, not sourced into this one: this shell already holds the SERVING copy's
  # supervision_* functions (sourced at the top), so a target that renamed or dropped one would silently run
  # the serving copy's here, pass, and meet its own only in the window, with the app down.
  #
  # THE LOCK FILES DO NOT MOVE. They are how the window finds the daemons that are RUNNING — whichever
  # release's crontab started them, whether or not any crontab or list still names them: it stops every
  # process holding one of this checkout's (restart_daemons). That is a true statement about what is running
  # only while no release moves them, so a target whose `supervision_lock <root> 'mezzanine:*'` differs from
  # the serving copy's is refused: its window would stop nothing the serving release started, and those
  # daemons would run the previous code beside the new ones for as long as they lived.
  step "Checking cron supervision (bin/supervision.sh)"
  local php_bin short target_sup work eval_err installed added removed serving_locks target_locks
  short="$(git_at rev-parse --short "$SHA")"
  php_bin="$(supervision_default_php)"
  target_sup=""
  git_read_at target_sup "$SHA" bin/supervision.sh || true
  [ -n "$target_sup" ] || refuse "bin/supervision.sh is missing or empty at $short" \
    "The window installs the deployed release's crontab block from it and restarts the daemons it names." \
    "A release without it cannot be supervised by this deploy."
  work="$(mktemp -d)"
  printf '%s\n' "$target_sup" > "$work/supervision.sh"
  # shellcheck disable=SC2016 # expanded by the bash it is handed to, not by this one
  eval_err="$(env -u BASH_ENV bash -c '
      set -Eeuo pipefail
      . "$1/supervision.sh"
      for f in supervision_install_plan supervision_lock; do
        declare -F "$f" >/dev/null || { echo "it defines no $f, which this deploy runs from it" >&2; exit 1; }
      done
      supervision_install_plan "$2" "$3" > "$1/plan"
      supervision_lock "$2" "mezzanine:*" > "$1/locks"
      printf "%s " "${SUPERVISED_DAEMONS[@]}" > "$1/daemons"
    ' a13 "$work" "$DEPLOY_ROOT" "$php_bin" 2>&1)" || {
    rm -rf "$work"
    refuse "the crontab block of bin/supervision.sh at $short could not be installed here" \
      "$(printf '%s\n' "$eval_err" | sed 's/^/  | /')" \
      "" \
      "The maintenance window installs it; this is that install's refusal, made before anything is touched."
  }
  TARGET_DAEMONS="$(cat "$work/daemons")"; TARGET_DAEMONS="${TARGET_DAEMONS% }"
  target_locks="$(cat "$work/locks")"
  installed="$(crontab -l 2>/dev/null || true)"
  added="$(grep -Fxv -f <(printf '%s\n' "$installed") "$work/plan" || true)"
  removed="$(printf '%s\n' "$installed" | grep -Fxv -f "$work/plan" || true)"
  rm -rf "$work"

  serving_locks="$(supervision_lock "$DEPLOY_ROOT" 'mezzanine:*')"
  [ "$target_locks" = "$serving_locks" ] || refuse "$short would move the daemons' lock files" \
    "  serving release: $serving_locks" \
    "  $short: $target_locks" \
    "" \
    "The window finds the running daemons by the lock files they hold, and the deployed release's deploy.sh" \
    "knows only its own. Moved, the window would stop nothing the serving release started, and those daemons" \
    "would go on running its code beside the new ones. The path is a contract across releases" \
    "(bin/supervision.sh § LOCKS): keep supervision_lock where it is."
  say "  ok — $short keeps the daemons' lock files at $serving_locks"
  if [ -z "$added$removed" ]; then
    say "  ok — $short's block (bin/supervision.sh) is what is installed; the window rewrites it unchanged"
  else
    say "  ok — the window installs $short's block (bin/supervision.sh), which changes the crontab:"
    if [ -n "$added" ]; then printf '%s\n' "$added" | sed 's/^/    + /'; fi
    if [ -n "$removed" ]; then printf '%s\n' "$removed" | sed 's/^/    - /'; fi
  fi

  # A14 — PHP-FPM will serve the new code without a reload. The reasoning and the measurement are at
  # fpm_code_reload_ready; phase B re-reads the same posture before it waits.
  step "Checking that PHP-FPM picks up new code without a reload, and can serve and drain the feed's streams"
  fpm_code_reload_ready || refuse "${FPM_NOT_READY[@]}"
  say "  ok — $FPM_BIN, pool(s) running as $(id -un): $FPM_POSTURE"
  say "  ok — $STREAM_POSTURE"

  say ""
  say "Ready:"
  say "  from     $(git_at rev-parse --short "$CURRENT_SHA")"
  say "  to       $(git_at rev-parse --short "$SHA")  ($REF)"
  say "  daemons  $TARGET_DAEMONS — the deployed release's: its crontab block installed, every holder of this checkout's daemon lock files sent SIGTERM, relaunched with cron's command"
  say "  php-fpm  not reloaded — $FPM_POSTURE"
  say "  streams  fleet.reload written before the opcache wait; streams still open after ${FEED_DRAIN_CEILING_S} s ended with SIGTERM ([${MEZZ_STREAM_POOL:-}])"
}

# ══════════════════════════════════════════════════════════════════════════════════════════════
# PHASE B — the maintenance window. Everything from here MUTATES.
# ══════════════════════════════════════════════════════════════════════════════════════════════

# DOWN AND STAY DOWN. On any failure after the window opens, the app is NOT brought back up. A
# migration that failed halfway (MariaDB DDL is not transactional — there is no partial-statement
# rollback to fall back on), a composer install that produced no vendor/, a daemon that died on
# start: in each of those the previous code cannot serve (the schema has moved) and the new code
# is not ready. Bringing the site up would serve the failure. The window stays open, the marker
# stays on disk, and the next bare re-run refuses (A2) — the operator is the recovery path.
FAILED_STEP="opening the maintenance window"
in_window_failure() {
  local rc=$? line="$1"
  cat >> "$MARKER" <<MARKER_END
failed_step: $FAILED_STEP
failed_line: $line (exit $rc)
MARKER_END
  cat >&2 <<BANNER

═══════════════════════════════════════════════════════════════════════════════
⛔ DEPLOY FAILED INSIDE THE MAINTENANCE WINDOW — THE APP IS DOWN AND STAYS DOWN
═══════════════════════════════════════════════════════════════════════════════
  step   : $FAILED_STEP
  commit : $(git_at rev-parse --short HEAD 2>/dev/null || echo '?')
  marker : $MARKER

  This is deliberate. Forward-only: nothing was rolled back, because a failed
  MariaDB migration is half-applied and a rollback would be a second guess at a
  state nobody has read yet. An operator reads it.

  Recovery is a human act:
    cd $APP_DIR
    php artisan migrate:status        # what actually landed
    tail -n 200 storage/logs/laravel.log
    ...then either finish forward — bring the schema to where this release expects
    it, then re-run this script with the same --ref and --redeploy, which restarts
    every daemon — or deploy the previous commit deliberately with --ref <sha>
    --allow-unreleased. A bare \`php artisan up\` serves the new code beside daemons
    that may still be running the previous release's.
  Clear $MARKER when the failure has been reviewed. Until it is cleared, another
  run of this script REFUSES — a bare re-run must not be able to erase this.
═══════════════════════════════════════════════════════════════════════════════
BANNER
  exit 2
}

phase_b_open_window() {
  trap 'in_window_failure $LINENO' ERR

  # The marker is written BEFORE the first mutation, not after the first failure: a deploy killed
  # by a dropped SSH session, an OOM or a reboot leaves no chance to write one afterwards, and
  # that is exactly the deploy whose state nobody knows.
  cat > "$MARKER" <<MARKER_END
# Written by bin/deploy.sh (card#7459) when the maintenance window opened.
# Its presence means a deploy did not finish. Read it, then remove it.
started_at: $(date -u +%FT%TZ)
from_commit: $CURRENT_SHA
to_commit: $SHA ($REF)
by: $(id -un)@$(hostname 2>/dev/null || echo '?')
MARKER_END

  FAILED_STEP="php artisan down"
  step "Maintenance window: OPEN"
  # Not `( cd … && … )`: with `set -E` the ERR trap is inherited by the subshell, so a failure
  # would print the banner and append to the marker TWICE — once in the subshell, once here.
  cd "$APP_DIR"
  php artisan down --retry=60

  # DOWN BEFORE CHECKOUT, deliberately. Between the checkout and the migration the tree holds new
  # code against an old schema; serving that window is how a deploy produces 500s from code that
  # is perfectly correct. Everything that could refuse has already refused (phase A), so the
  # remaining risk this ordering takes on — a checkout that fails with the app down — is a case
  # the failure banner handles, not a silent one.
  FAILED_STEP="git checkout $SHA"
  step "Checking out $(git_at rev-parse --short "$SHA")"
  # Detached on purpose: a branch on the prod checkout invites `git pull` on the host, which is
  # the hand-deploy D-13 forbids. A detached HEAD makes "what is running" one unambiguous sha.
  git_at checkout --detach "$SHA"

  # RE-EXEC AFTER CHECKOUT. From here on, the steps that run must be the steps the DEPLOYED
  # release defines — a release that adds a daemon, changes the cache order or adds a build step
  # ships that change in its own bin/deploy.sh, and phase A already ran (it belongs to the
  # currently-serving release, which is the one that knows how to check itself). Without this the
  # new code would always be deployed by the previous release's procedure, forever one behind.
  step "Re-exec: handing off to the deployed release's own bin/deploy.sh"
  # HANDED OVER, AS A FLOOR ONLY: the longest opcache.revalidate_freq A14 read. It counts the SERVING
  # release's server/public/.user.ini, which the checkout above has just replaced — and FPM keeps a
  # directory's .user.ini values for user_ini.cache_ttl, so a worker can go on revalidating at the previous
  # release's interval after the file is gone. Phase B can no longer read that file; it waits the larger of
  # this and what it reads itself, so a wrong value here can lengthen the wait and never shorten it.
  # NOT handed over: which daemons to stop. The lock files say that (restart_daemons).
  trap - ERR
  export MEZZ_DEPLOY_IN_WINDOW=1 MEZZ_DEPLOY_ROOT="$DEPLOY_ROOT" MEZZ_DEPLOY_REVALIDATE_FLOOR_S="$FPM_REVALIDATE_S"
  exec "$DEPLOY_ROOT/bin/deploy.sh" --internal-post-checkout "$SHA"
}

# ── the daemons: a restart without systemd ─────────────────────────────────────────────────────
# INSTALL. First, the deployed release's crontab block — bin/supervision.sh install, from THIS copy —
# whose every refusal A13 made before the window. First, so that from cron's next minute nothing starts
# under the previous release's lines while the stop below clears them.
#
# STOP. SIGTERM to every process holding ANY of this checkout's daemon lock files — every file matching
# supervision_lock <root> 'mezzanine:*' — until none is held; flock and the php it runs both hold one,
# through the inherited fd. Not the locks some release's list names: the files say what is RUNNING, whichever
# release's crontab started it — a daemon the deployed release dropped, and, on a re-run after a window that
# failed before this step, every daemon the previous release still has up. That holds because no release
# moves the files (A13). Files and holders are read afresh every round, so a copy cron started just before
# the install is stopped too. No supervised command registers a signal
# handler: measured, an artisan loop in this app exits 8 ms after SIGTERM with status 143, the default
# action. So the daemon dies wherever the signal lands, INSIDE a transaction included — which is exactly
# a crash, and a crash is already a case § 2.1 requires every process to survive ("individually
# restartable without losing or double-applying anything"):
#   · fold — FLEET-STATE.md § 6.5, idempotency mechanism 1: the cursor advance is in the SAME
#     transaction as the projections, so a kill mid-pass rolls back both; mechanism 2: every
#     projection is an upsert on a natural key, so re-applying an event is a no-op anyway. AT-D2-9
#     specifies the kill. ⚠ The suite reaches it by failing the cursor advance in-process, not with a
#     real SIGKILL (tests/Feature/Fold/At9FoldIdempotenceTest.php says why). What a real kill adds is
#     the ENGINE rolling back a transaction whose client died — measured on MariaDB (mezzanine_test,
#     2026-09-13): a row inserted inside an open transaction was gone after SIGTERM killed the client.
#   · sweep — one transaction per seat, and every job guarded on the fact it closes still being open.
#   · feed heartbeat — a kill loses at most the tick in flight; the relaunched loop ticks first.
# ⚑ A DAEMON ADDED TO bin/supervision.sh MUST KEEP THAT PROPERTY: it registers no signal handler (no
# pcntl_signal, no Laravel `trap()`, no SignalableCommandInterface), or else it survives SIGTERM mid-pass
# exactly as it survives a crash — and either way its crash case is written beside the ones above.
# Re-measure both halves when adding one, on a host where the command may run:
#   grep -rnE 'pcntl_signal|->trap\(|SignalableCommandInterface' server/app       # prints nothing
#   (cd server && exec php artisan mezzanine:<name>) & sleep 5; kill -TERM $!; wait $!; echo $?   # 143
# A stop FLAG polled between passes was weighed and not taken: it buys no correctness the transaction
# does not already give, it has to wait out a 15 s sleep, and a flag left behind by a deploy that died
# would make every cron relaunch exit at once — every daemon down and the fold frozen, § 2.3's one
# degradation that looks healthy.
#
# START. The exact command cron runs (bin/supervision.sh), run the way cron runs it — /bin/sh, cron's
# minimal environment, detached — rather than waiting up to 60 s for cron. Anything but cron's
# environment would be a daemon that works when the deploy starts it and not when cron does: a
# DB_DATABASE exported in the operator's shell beats server/.env. If cron's own minute tick got there
# first, this `flock -n` exits at once and changes nothing.
#
# PROVE. A start is not a survival. Each of the deployed release's locks must be held MEZZ_DAEMON_SETTLE_S
# after the relaunch, and every process holding it THEN must have STARTED AFTER this step began — so it
# loaded the tree as it is now (the CLI runs without opcache). Judged at the settle, not by following the
# pids first seen holding the lock: cron's minute tick can land a losing `flock -n` that has the lock
# file open for a moment, and a pid that was only ever that would read as a daemon that died. A daemon
# that dies two seconds in (a bad config, a class a package removal took away) leaves its lock free at
# the settle; unproven, it would leave a fold frozen behind a green deploy. What the settle cannot see is
# a daemon that dies and is started again by cron inside it. A holder's start is read with `ps -o etimes=`,
# and one still running whose start cannot be read fails the proof: it is never taken for a fresh process.
# And every OTHER lock file of the checkout must then be held by nothing at all.
lock_holders() { # <file…> — the pids that have any of these files open; nothing for a file not there
  local f
  local -a present=()
  for f in "$@"; do if [ -e "$f" ]; then present+=("$f"); fi; done
  if [ ${#present[@]} -gt 0 ]; then fuser "${present[@]}" 2>/dev/null || true; fi
}

checkout_lock_files() { # every daemon lock file of this checkout that exists, whichever release named it
  compgen -G "$(supervision_lock "$DEPLOY_ROOT" 'mezzanine:*')" || true
}

checkout_lock_holders() { # the pids holding any of them
  local -a files=()
  mapfile -t files < <(checkout_lock_files)
  lock_holders "${files[@]}"
}

holders_started_after() { # <cmd> <lock> <since> <pid…> — every pid still running started at or after <since>
  local cmd="$1" lock="$2" since="$3" pid age now
  shift 3
  now="$(date +%s)"
  for pid in "$@"; do
    age="$(ps -o etimes= -p "$pid" 2>/dev/null || true)"; age="${age//[[:space:]]/}"
    case "$age" in
      '' | *[!0-9]*)
        # Gone since fuser listed it — cron's losing `flock -n` — it holds nothing now and is not judged.
        if ! kill -0 "$pid" 2>/dev/null; then continue; fi
        echo "$cmd: cannot read when pid $pid, which holds $lock, started (\`ps -o etimes=\` printed '$age') — it cannot be proven to run the deployed release's code" >&2
        false ;;
    esac
    [ $((now - age)) -ge "$since" ] || { echo "$cmd: $lock is held by pid $pid, which started $((since - now + age)) s BEFORE this restart — it is running the previous release's code" >&2; false; }
  done
}

restart_daemons() {
  local php_bin cmd lock lk pid deadline step_started stopped=""
  local stop_timeout="$DAEMON_STOP_TIMEOUT_S" settle="$DAEMON_SETTLE_S"
  local -a target_locks=() files=() hs=()
  php_bin="$(supervision_default_php)"
  step_started="$(date +%s)"
  for cmd in "${SUPERVISED_DAEMONS[@]}"; do target_locks+=("$(supervision_lock "$DEPLOY_ROOT" "$cmd")"); done

  # A subshell, because install ends with `exit` on a refusal; the ERR trap is dropped inside it so the
  # failure is reported once, here, and not a second time from within.
  ( trap - ERR; supervision_install "$DEPLOY_ROOT" "$php_bin" >/dev/null )
  say "  installed the deployed release's crontab block (bin/supervision.sh)"

  deadline=$(($(date +%s) + stop_timeout))
  while :; do
    read -r -a hs <<< "$(checkout_lock_holders)"
    [ ${#hs[@]} -gt 0 ] || break
    for pid in "${hs[@]}"; do case " $stopped " in *" $pid "*) ;; *) stopped+=" $pid" ;; esac; done
    [ "$(date +%s)" -lt "$deadline" ] || { echo "the previous daemons did not exit within ${stop_timeout} s of SIGTERM — pid(s) ${hs[*]}" >&2; false; }
    kill -TERM "${hs[@]}" 2>/dev/null || true
    sleep 0.2
  done
  say "  stopped — pid(s)${stopped:- none were running}"

  for cmd in "${SUPERVISED_DAEMONS[@]}"; do
    env -i HOME="$HOME" LOGNAME="$(id -un)" USER="$(id -un)" SHELL=/bin/sh PATH=/usr/bin:/bin \
      setsid -f /bin/sh -c "$(supervision_command "$DEPLOY_ROOT" "$php_bin" "$cmd")" </dev/null >/dev/null 2>&1
    say "  relaunched $cmd"
  done

  for cmd in "${SUPERVISED_DAEMONS[@]}"; do
    lock="$(supervision_lock "$DEPLOY_ROOT" "$cmd")"; deadline=$(($(date +%s) + 10))
    read -r -a hs <<< "$(lock_holders "$lock")"
    while [ ${#hs[@]} -eq 0 ]; do
      [ "$(date +%s)" -lt "$deadline" ] || { echo "$cmd: nothing holds $lock after the relaunch — see $APP_DIR/storage/logs/daemon-$(supervision_daemon_name "$cmd").log" >&2; false; }
      sleep 0.2
      read -r -a hs <<< "$(lock_holders "$lock")"
    done
    holders_started_after "$cmd" "$lock" "$step_started" "${hs[@]}"
  done

  sleep "$settle"
  for cmd in "${SUPERVISED_DAEMONS[@]}"; do
    lock="$(supervision_lock "$DEPLOY_ROOT" "$cmd")"
    read -r -a hs <<< "$(lock_holders "$lock")"
    [ ${#hs[@]} -gt 0 ] || { echo "$cmd: nothing holds $lock ${settle} s after the relaunch — the daemon died on start; see $APP_DIR/storage/logs/daemon-$(supervision_daemon_name "$cmd").log" >&2; false; }
    holders_started_after "$cmd" "$lock" "$step_started" "${hs[@]}"
    say "  ok — $cmd: pid(s) ${hs[*]} started after the restart and still hold its lock ${settle} s later"
  done
  mapfile -t files < <(checkout_lock_files)
  for lk in "${files[@]}"; do
    case " ${target_locks[*]} " in *" $lk "*) continue ;; esac
    read -r -a hs <<< "$(lock_holders "$lk")"
    [ ${#hs[@]} -eq 0 ] || { echo "pid(s) ${hs[*]} still hold $lk, the lock file of a daemon the deployed release's bin/supervision.sh does not supervise — they run a previous release's code beside the new daemons" >&2; false; }
    say "  ok — nothing holds $lk, which no daemon of the deployed release uses"
  done
}

# ── the feed's streams: D2 § 14 item 17's decision ──────────────────────────────────────────────
# A stream the previous release served and that missed `fleet.reload` — its consumer frozen or too slow to take the
# message inside the ceiling — would hold that release's code until its session expires. It is ended here, from the
# stream pool's own status (fpm_status full), by SIGTERM: the pool runs as this user (A14), so no root is needed, and
# the pool's master starts a fresh worker in its place. MEASURED on the sandbox host (2026-09-13) against a throwaway
# non-root php-fpm8.5 master behind a throwaway Apache with this host's vhost shape:
#   · the full listing does NOT identify a stream by its URI — behind the front controller every Laravel request is
#     `request uri=/index.php`. What identifies one in a DEDICATED pool is a request STILL RUNNING THAT STARTED BEFORE
#     `fleet.reload` WAS WRITTEN: its `request duration` exceeds the time since then. Every other request the
#     window lets into that pool is a 503 from maintenance mode, milliseconds long.
#   · SIGTERM from the pool's user ended the worker at once ("exited on signal 15"), the master started a new one in
#     its place ("child … started"), and the client — curl, through the proxy — saw its transfer cut without a
#     terminating chunk (curl exit 18). A browser's EventSource reads that as a network error: no feed.close, so the
#     client takes its reconnect path, which for this stream is the point. Its consumer had stopped taking messages.
#   · on this host the live pool workers under the ROOT master carry real, effective and saved uid 1002 (/proc, read
#     only), which is what kill(2) checks; the respawn by a root-owned master was not exercised — only a non-root one.
# A residual the signal does not end, or a status that stops answering, is WARNED about and never fails the window:
# the app is down while this runs, and a stale stream is a degradation named in the log, not a reason to stay down.
# Sets DRAIN_RESULT.
previous_stream_pids() { # <since epoch> — the Running requests in the stream pool that started before <since>
  local listing
  listing="$(fpm_status full)" || return 1
  php -r '
    $s = json_decode(stream_get_contents(STDIN), true);
    if (! is_array($s) || ! isset($s["processes"])) { exit(1); }
    $min = (time() - (int) $argv[1]) * 1000000;
    foreach ($s["processes"] as $p) {
      if (($p["state"] ?? "") === "Running" && (int) ($p["request duration"] ?? 0) > $min) { echo $p["pid"], "\n"; }
    }' "$1" <<< "$listing"
}

drain_previous_streams() {
  local since="$1" deadline pids
  if [ -z "$STREAM_STATUS_LISTEN" ]; then
    DRAIN_RESULT="NOT DRAINED — the stream pool is not one this deploy can read (see the warning above)"
    warn "streams the previous release served were not drained: $DRAIN_RESULT"
    return 0
  fi
  deadline=$(($(date +%s) + FEED_DRAIN_CEILING_S))
  while :; do
    if ! pids="$(previous_stream_pids "$since")"; then
      DRAIN_RESULT="NOT DRAINED — the stream pool's status stopped answering"
      warn "the stream pool [$MEZZ_STREAM_POOL]'s status did not answer — streams the previous release served may still be open, and will hold its code until their sessions expire"
      return 0
    fi
    [ -n "$pids" ] || { DRAIN_RESULT="every stream the previous release served had ended on fleet.reload"; say "  ok — $DRAIN_RESULT"; return 0; }
    [ "$(date +%s)" -lt "$deadline" ] || break
    sleep 1
  done
  pids="$(printf '%s' "$pids" | tr '\n' ' ')"; pids="${pids% }"
  say "  $(wc -w <<< "$pids") stream(s) the previous release served were still open after a ${FEED_DRAIN_CEILING_S} s drain that began once fleet.reload had been read: pid(s) $pids — ending them"
  # shellcheck disable=SC2086 # one pid per word, on purpose
  kill -TERM $pids 2>/dev/null || true
  deadline=$(($(date +%s) + 5))
  local left="$pids"
  while [ -n "$left" ] && [ "$(date +%s)" -lt "$deadline" ]; do
    sleep 0.5
    if left="$(previous_stream_pids "$since")"; then left="$(printf '%s' "$left" | tr '\n' ' ')"; left="${left% }"
    else left="(unknown — the status stopped answering)"; break; fi
  done
  if [ -n "$left" ]; then
    DRAIN_RESULT="NOT DRAINED — pid(s) $left still serve a stream the previous release opened"
    warn "SIGTERM did not end pid(s) $left in [$MEZZ_STREAM_POOL] — those streams hold the previous release until they end on their own"
  else
    DRAIN_RESULT="ended the stream(s) that missed fleet.reload with SIGTERM (pid(s) $pids)"
    say "  ok — $DRAIN_RESULT"
  fi
}

phase_b_post_checkout() {
  [ "${MEZZ_DEPLOY_IN_WINDOW:-0}" = "1" ] || refuse \
    "--internal-post-checkout is not an operator entry point" \
    "It assumes the maintenance window is already open. Run bin/deploy.sh without it."
  [ -e "$MARKER" ] || refuse "--internal-post-checkout with no failure marker present" \
    "The window was never opened by this script. Refusing to continue a deploy that did not start."

  SHA="$POST_CHECKOUT_SHA"
  trap 'in_window_failure $LINENO' ERR
  FAILED_STEP="verifying the checkout"

  # The checkout is verified, not assumed: `git checkout` failing is loud, but a re-exec that
  # somehow ran an older copy of this file, or a DEPLOY_ROOT that resolved somewhere else, is not.
  local head; head="$(git_at rev-parse HEAD)"
  [ "$head" = "$SHA" ] || { echo "HEAD is $head, expected $SHA" >&2; false; }
  say "  ok — HEAD is $(git_at rev-parse --short "$SHA")"

  # The supervised set installed and restarted below is the DEPLOYED release's: this file and the
  # bin/supervision.sh it sourced are both the checked-out copies — that is what the re-exec bought. Phase A's
  # opcache floor (phase_b_open_window) and the restart's timings are read here, before anything is built, rather
  # than where they are used, after the migration. Both through whole_seconds: phase A ran in the SERVING release,
  # which may hand over, or have let through, a number with a leading zero.
  FAILED_STEP="reading the opcache floor phase A handed over"
  REVALIDATE_FLOOR_S="$(whole_seconds "${MEZZ_DEPLOY_REVALIDATE_FLOOR_S:-}")" || {
    echo "MEZZ_DEPLOY_REVALIDATE_FLOOR_S is '${MEZZ_DEPLOY_REVALIDATE_FLOOR_S:-}', not the whole number of seconds phase A hands over" >&2
    false; }
  FAILED_STEP="reading MEZZ_DAEMON_STOP_TIMEOUT_S, MEZZ_DAEMON_SETTLE_S and MEZZ_FEED_DRAIN_CEILING_S"
  daemon_timings || { echo "$TIMING_NOT_READY" >&2; false; }
  drain_timing || { echo "$TIMING_NOT_READY" >&2; false; }

  # ── dependencies ────────────────────────────────────────────────────────────────────────────
  # Every artisan/composer/npm call below runs from the app directory (D-16) rather than in a
  # `( cd … )` subshell — see the note in phase_b_open_window on the doubled ERR trap.
  cd "$APP_DIR"

  FAILED_STEP="composer install"
  step "composer install (no-dev)"
  composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

  FAILED_STEP="npm ci"
  step "npm ci"
  # `npm ci` and NOT `npm ci --omit=dev`: vite, tailwind and the laravel plugin are all
  # devDependencies, so omitting them removes the build itself. The build OUTPUT is what prod
  # serves; node_modules/ is not served and is not part of the runtime.
  npm ci

  FAILED_STEP="npm run build"
  step "npm run build"
  npm run build

  # ── caches and schema — THE ORDER IS LOAD-BEARING ───────────────────────────────────────────
  # 1. CLEAR FIRST. The config/route/view/event caches on disk were built from the PREVIOUS
  #    release. `migrate` is an artisan command like any other: run against a stale config cache
  #    it resolves the old connection settings — the one command where being one release behind
  #    can write to the wrong database. Clearing also removes a compiled view or route cache that
  #    names classes the new autoloader no longer has.
  # 2. MIGRATE SECOND — after composer (the migration's own classes must be autoloadable) and
  #    after the asset build (a failed build must not leave a migrated schema behind).
  # 3. REBUILD LAST, and only then bring the app up. Caching before `migrate` would cache a
  #    config the migration might invalidate; bringing the app up before caching hands the first
  #    real requests the job of compiling every route and view at once, at the moment traffic
  #    resumes.
  FAILED_STEP="php artisan optimize:clear"
  step "Clearing caches (before migrate — see the note above)"
  php artisan optimize:clear

  # FORWARD-ONLY. There is no `migrate:rollback` in this script and there must not be: MariaDB DDL
  # is non-transactional, so a migration that failed halfway has already applied part of itself
  # and `down()` would be run against a schema neither state describes. § 6.9's rules (algorithm
  # declared, additive nullable columns, bounded backfills) are what make forward-only safe; A10
  # checks the first of them.
  FAILED_STEP="php artisan migrate --force"
  step "Migrating (forward-only)"
  php artisan migrate --force

  FAILED_STEP="rebuilding caches"
  step "Rebuilding caches"
  # config first: route/event caching resolve providers and route middleware through config.
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  php artisan event:cache
  # The last write of code the FPM workers will execute; the opcache wait below counts from here.
  local last_code_write; last_code_write="$(date +%s)"

  # ── the daemons (handover item 1) ───────────────────────────────────────────────────────────
  # Everything above changed code on disk. Every long-lived PHP process is still running the code
  # it loaded at its last start. A deploy that stopped here would change nothing about what the
  # daemons actually execute. How they are stopped, started and proven: restart_daemons.
  FAILED_STEP="restarting daemons"
  step "Restarting the supervised daemons (cron + flock — no systemd)"
  php artisan queue:restart   # graceful; a no-op where no worker is running
  restart_daemons

  # ── PHP-FPM: no reload ──────────────────────────────────────────────────────────────────────
  # This user cannot reload the pool (its master is root's) and does not need to: fpm_code_reload_ready
  # says why. The posture is RE-READ here rather than trusted from A14, because the wait it sets is
  # what makes `up` safe, and a host whose FPM settings changed mid-window must fail loudly. It is read
  # BEFORE the feed reload, because the drain below reads the stream pool it resolves.
  # +1 s because opcache's clock and file mtimes are both whole seconds.
  FAILED_STEP="re-reading PHP-FPM's posture"
  step "PHP-FPM: re-reading the opcache posture and the stream pool"
  fpm_code_reload_ready || { printf '%s\n' "${FPM_NOT_READY[@]}" >&2; false; }
  if [ ${#STREAM_NOT_READY[@]} -gt 0 ]; then
    warn "the feed's stream pool is NOT ready — the next deploy will refuse this host until it is (FLEET-STATE.md § 8.3 R1/R2):"
    printf '    %s\n' "${STREAM_NOT_READY[@]}" >&2
  fi

  # ── the feed's open streams (card#9300) ─────────────────────────────────────────────────────
  # Revalidation reaches every NEW request; a stream already open holds the previous release in memory
  # until something ends it. `mezzanine:feed-reload` writes `fleet.reload` and returns once every draining
  # stream has read it (lag + tick + margin); each ends with feed.close{reason:"reload"} and its client
  # reconnects — onto the new code once the window closes. drain_previous_streams then ends the ones that
  # did not. IMMEDIATELY BEFORE the opcache wait, which is where FLEET-STATE.md § 2.1 puts it.
  FAILED_STEP="php artisan mezzanine:feed-reload"
  step "Ending the previous release's open streams (mezzanine:feed-reload)"
  local reload_at; reload_at="$(date +%s)"
  php artisan mezzanine:feed-reload
  FAILED_STEP="draining the stream pool"
  drain_previous_streams "$reload_at"

  FAILED_STEP="waiting for PHP-FPM's opcache to revalidate"
  step "PHP-FPM: letting opcache revalidate the new code (no reload)"
  # The previous release's .user.ini can hold a longer revalidate_freq than anything readable now: phase A's
  # reading of it is the floor (phase_b_open_window).
  local floor="$REVALIDATE_FLOOR_S" wait_for="$FPM_REVALIDATE_S"
  if [ "$floor" -gt "$wait_for" ]; then wait_for="$floor"; fi
  local wait_s=$((last_code_write + wait_for + 1 - $(date +%s)))
  if [ "$wait_s" -gt 0 ]; then sleep "$wait_s"; fi
  say "  ok — $FPM_POSTURE (before the checkout: within ${floor} s), and $(($(date +%s) - last_code_write)) s have passed since the last code write"

  # ── close the window ────────────────────────────────────────────────────────────────────────
  FAILED_STEP="php artisan up"
  step "Maintenance window: CLOSING"
  php artisan up
  trap - ERR

  # ── smoke ───────────────────────────────────────────────────────────────────────────────────
  # The window is closed, so a failure here is NOT the down-and-stay-down case — the app is up and
  # taking traffic. It is still not a success: exit 3, and the marker stays, so the next run
  # refuses and an operator has to look. `/up` is Laravel's health route (server/bootstrap/app.php
  # `health: '/up'`); it needs no credential and it is the one endpoint that answers before MFA.
  # APP_URL is read with env_get rather than env_read: a refusal promises nothing was changed, and here the new
  # release is already serving. A value env_get cannot read gets the unset case's warning, named.
  local url code url_rc=0
  url="$(env_get APP_URL)" || url_rc=$?
  # The same rule as every A5 check: what the app has is the value it RECEIVES. An APP_URL Laravel
  # resolves to a falsy value is no URL — `config('app.url')` is null or '' — so it takes the unset
  # case's warning rather than a smoke request to a host named `null` and a failure report naming it.
  ! env_app_falsy "$url" || url=""
  if [ "$url_rc" -eq 2 ]; then
    warn "APP_URL is in a form this script does not read (env_get, bin/deploy.sh) — no smoke check was made. The deploy is UNVERIFIED."
  elif [ -z "$url" ]; then
    warn "APP_URL is unset — no smoke check was made. The deploy is UNVERIFIED."
  else
    step "Smoke: GET $url/up"
    code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "$url/up" 2>/dev/null || echo 000)"
    if [ "$code" != "200" ]; then
      cat >&2 <<SMOKE

⛔ THE APP IS UP BUT $url/up ANSWERED $code
   The maintenance window is closed and traffic is being served, so this is not
   the stay-down case — but nothing has confirmed the new release works.
   $MARKER is left in place: the next run of this script will REFUSE until an
   operator has looked.
SMOKE
      exit 3
    fi
    say "  ok — 200"
  fi

  rm -f "$MARKER"
  local version; version="$(tr -d '\n' < "$DEPLOY_ROOT/VERSION" 2>/dev/null || echo '?')"
  cat <<DONE

═══════════════════════════════════════════════════════════════════════════════
✔ DEPLOYED — $(git_at rev-parse --short "$SHA")  (VERSION $version)
═══════════════════════════════════════════════════════════════════════════════
  restarted : ${SUPERVISED_DAEMONS[*]} (new pids proven alive)
  php-fpm   : not reloaded — $FPM_POSTURE
  streams   : $DRAIN_RESULT

  A tag is not a deploy and a deploy is not a verdict (docs/VERSIONING.md).
  Exercise the real surface: log in, watch a floor render from live telemetry.
  And state the deploy verdict for BOTH targets in the release notes — this run
  moved the SERVER only; every seat's fleet-reporter upgrades on its own.
═══════════════════════════════════════════════════════════════════════════════
DONE
}

# ══════════════════════════════════════════════════════════════════════════════════════════════
main() {
  if [ -n "$POST_CHECKOUT_SHA" ]; then
    phase_b_post_checkout
    return
  fi

  say "Mezzanine prod deploy (card#7459) — root $DEPLOY_ROOT, ref $REF"
  phase_a

  if [ "$DRY_RUN" -eq 1 ]; then
    cat <<PLAN

── DRY RUN — every precondition above was really checked; nothing below was run ──
  1  php artisan down
  2  git checkout --detach $(git_at rev-parse --short "$SHA")
  3  re-exec the deployed release's own bin/deploy.sh
  4  composer install --no-dev · npm ci · npm run build
  5  optimize:clear → migrate --force → config/route/view/event:cache
  6  queue:restart · install the release's crontab block · SIGTERM every holder of the checkout's daemon lock files · relaunch $TARGET_DAEMONS (cron's command)
  6b mezzanine:feed-reload · SIGTERM the [${MEZZ_STREAM_POOL:-}] streams still open after ${FEED_DRAIN_CEILING_S} s · wait out opcache revalidation (no FPM reload)
  7  php artisan up · GET \$APP_URL/up
PLAN
    return
  fi

  phase_b_open_window   # never returns: it execs
}

main "$@"

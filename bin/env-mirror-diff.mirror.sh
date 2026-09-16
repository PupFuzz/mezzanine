#!/usr/bin/env bash
# env-mirror-diff.mirror.sh — the MIRROR side of bin/env-mirror-diff.sh.
#
# It answers, for each fixture directory on stdin, what `bin/deploy.sh` makes of that `.env`. The reader
# functions are NOT re-implemented here: the `# ── .env reading` block is EXTRACTED at run time out of the
# deploy.sh named on the command line and sourced, so every answer below is produced by the script's own
# text. That is what lets the driver point this at a MUTANT of deploy.sh and watch the differential go red.
#
# USAGE — fixture directories on stdin, one per line, one TSV line out per directory:
#   env-mirror-diff.mirror.sh <deploy.sh> scan KEY…  →  DIR  accept|refuse  VALUE-PER-KEY…
#   env-mirror-diff.mirror.sh <deploy.sh> locality   →  DIR  ok|refuse:TAG  STORE  CA
#   env-mirror-diff.mirror.sh <deploy.sh> loopback-hosts  →  deploy.sh's own ENV_LOOPBACK_HOSTS
#
# A VALUE is `unset` (env_get status 1), `unread` (status 2 — a line in a form this reader does not read
# EXACTLY as Laravel does, which A5 refuses BY NAME), or `=` followed by the value with `\`, TAB, CR and LF
# escaped. That is the encoding env-mirror-diff.oracle.php prints, so a cell compares as one string.
#
# ⚠ WHAT IS RESTATED, AND WHY. In `locality` mode the five lines of A5 GLUE between those functions — the
# APP_ENV compare, the CACHE_STORE case, the array_filter CA rule and the remote-without-a-CA refusal — are
# restated below, because they live inside `phase_a_preconditions`, which cannot run without a whole fixture
# host. `bin/deploy.selftest.sh` is the real-surface half of that: it runs the actual phase against actual
# fixtures. This half is what makes the LINE-ENDING and PARSER axes affordable at hundreds of cells. A5's
# APP_DEBUG, APP_KEY and DB_CONNECTION checks are deliberately NOT restated — every fixture writes them
# valid, they gate nothing this differential measures, and each one restated is one more line that can drift.

set -uo pipefail

DEPLOY="${1:?usage: env-mirror-diff.mirror.sh <deploy.sh> <scan KEY…|locality|loopback-hosts>}"
MODE="${2:?usage: env-mirror-diff.mirror.sh <deploy.sh> <scan KEY…|locality|loopback-hosts>}"
shift 2
KEYS=("$@")

die() { printf 'env-mirror-diff.mirror.sh: %s\n' "$*" >&2; exit 2; }

[ -f "$DEPLOY" ] || die "no such deploy script: $DEPLOY"

# ── extracting the reader ─────────────────────────────────────────────────────────────────────────────
# Both anchors must exist EXACTLY once, and in order. A `sed -n '/a/,/b/p'` whose end anchor has moved runs
# to end of file and would source the whole script — including `main "$@"` — and one whose start anchor has
# moved yields nothing at all, which would leave every function below undefined. Neither failure announces
# itself, so both are checked here rather than discovered as a wrong answer.
start="$(grep -c '^# ── \.env reading' "$DEPLOY")"
end="$(grep -c '^git_at() {' "$DEPLOY")"
[ "$start" = 1 ] || die "expected exactly one '# ── .env reading' line in $DEPLOY, found $start"
[ "$end" = 1 ] || die "expected exactly one 'git_at() {' line in $DEPLOY, found $end"
start="$(grep -n '^# ── \.env reading' "$DEPLOY" | cut -d: -f1)"
end="$(grep -n '^git_at() {' "$DEPLOY" | cut -d: -f1)"
[ "$start" -lt "$end" ] || die "the .env reading block's anchors are out of order in $DEPLOY"

WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
sed -n "${start},$((end - 1))p" "$DEPLOY" > "$WORK/env.bash"

# `refuse` is defined BEFORE the block is sourced and is the script's own contract: it never returns. Each
# fixture therefore runs in a subshell, and the refusal is that subshell's whole output.
refuse() { printf 'refuse\n'; exit 0; }
# shellcheck disable=SC1091
. "$WORK/env.bash"

# A silently-empty extraction reads as "nothing disagreed". Name every function this harness calls.
for fn in env_lines_load env_get env_read env_file_scan env_laravel_value env_app_falsy store_locality; do
  declare -F "$fn" >/dev/null || die "$DEPLOY defines no $fn — the .env reading block has moved or been renamed"
done
# `declare -p` rather than `${#…[@]}`: under `set -u` an unset array is an ERROR, not a zero length, and
# this needs to say which script is missing what rather than die on an unbound variable.
declare -p ENV_LOOPBACK_HOSTS >/dev/null 2>&1 || die "$DEPLOY defines no ENV_LOOPBACK_HOSTS — the .env reading block has moved or been renamed"
[ "${#ENV_LOOPBACK_HOSTS[@]}" -gt 0 ] || die "$DEPLOY's ENV_LOOPBACK_HOSTS is empty"

if [ "$MODE" = loopback-hosts ]; then
  printf '%s\n' "${ENV_LOOPBACK_HOSTS[*]}"
  exit 0
fi

case "$MODE" in scan | locality) ;; *) die "unknown mode '$MODE' (scan | locality | loopback-hosts)" ;; esac

# ⚠ NOTHING ON THE `scan` PATH FORKS, AND THAT IS DELIBERATE. The population is in the hundreds of cells,
# every key is read in every one of them, and every mutant control re-runs the lot — so a `$(…)` per key
# would be most of this harness's wall clock, and a check nobody can afford to run on every PR is a check
# that gets a `paths:` filter and then rots. A scan fixture costs exactly ONE fork: the subshell that gives
# deploy.sh's `refuse` something to exit.
#
# ⚠ `locality` IS NOT IN THAT BUDGET, and a maintainer optimising it should know where its cost actually
# is. It reads through `env_read`, which is deploy.sh's OWN function and whose first statement is
# `_env_value="$(env_get "$2")"` — a command substitution, one fork per call, inside the script under test
# and not this harness's to remove. `locality_one` calls it three times (APP_ENV, CACHE_STORE,
# MYSQL_ATTR_SSL_CA) and `store_locality` up to three more (DB_URL, DB_SOCKET, DB_HOST), so a locality
# fixture costs about SIX forks beside its own subshell — plus a `php -r` inside `store_locality` for every
# fixture that sets DB_URL. The locality population is the smaller of the two, which is what keeps that
# affordable; the fork-free rule below is what keeps the scan population affordable.
#
# esc VALUE → REPLY: env-mirror-diff.oracle.php's `enc`, on the bash side. A bash string cannot hold a NUL,
# so a NUL needs no case here: a fixture carrying one is one the loader stopped at, and the scan refuses it.
esc() {
  REPLY="${1//\\/\\\\}"
  REPLY="${REPLY//$'\t'/\\t}"; REPLY="${REPLY//$'\r'/\\r}"; REPLY="${REPLY//$'\n'/\\n}"
}

# read_one KEY → REPLY: the value env_get hands back, encoded. `env_get` PRINTS, so its value has to be
# captured — and `$(…)` forks. A redirection runs in THIS shell, and `$(<file)` is the one command
# substitution bash performs without forking, so the value goes through a file.
read_one() {
  local rc=0
  env_get "$1" > "$VALUE_FILE" || rc=$?
  case "$rc" in
    1) REPLY=unset ;;
    2) REPLY=unread ;;
    0) esc "$(<"$VALUE_FILE")"; REPLY="=$REPLY" ;;
    *) REPLY="rc$rc" ;;
  esac
}

scan_one() ( # a subshell: deploy.sh's refuse exits, which is the contract the sourced text is written to
  ENV_FILE="$DIR/.env"
  refuse() { printf '%s\trefuse\n' "$DIR"; exit 0; }
  env_file_scan
  printf '%s\taccept' "$DIR"
  local key
  for key in "${KEYS[@]}"; do read_one "$key"; printf '\t%s' "$REPLY"; done
  printf '\n'
)

locality_one() ( # the same subshell contract; `tag` names which check the refusal came from
  ENV_FILE="$DIR/.env"
  local tag=scan app_env cache_store cache_value ssl_ca ca
  refuse() { printf '%s\trefuse:%s\t-\t-\n' "$DIR" "$tag"; exit 0; }

  env_file_scan

  tag=app_env; app_env=""; env_read app_env APP_ENV || true
  [ "$app_env" = "production" ] || { printf '%s\trefuse:app_env\t-\t-\n' "$DIR"; exit 0; }

  tag=cache; cache_store=""; env_read cache_store CACHE_STORE || true
  env_laravel_value cache_value "$cache_store"
  case "$cache_value" in
    null | string:array) printf '%s\trefuse:cache\t-\t-\n' "$DIR"; exit 0 ;;
  esac

  tag=ca; ssl_ca=""; env_read ssl_ca MYSQL_ATTR_SSL_CA || true
  ! env_app_falsy "$ssl_ca" || ssl_ca=""

  tag=locality; store_locality
  ca=none; [ -z "$ssl_ca" ] || ca=set
  if [ "$STORE_LOCALITY" = remote ] && [ -z "$ssl_ca" ]; then
    printf '%s\trefuse:no_ca_remote\t%s\t%s\n' "$DIR" "$STORE_LOCALITY" "$ca"; exit 0
  fi
  printf '%s\tok\t%s\t%s\n' "$DIR" "$STORE_LOCALITY" "$ca"
)

VALUE_FILE="$WORK/value"
# ⚠ THE READER IS ALLOWED TO LEAVE EARLY, AND THAT IS NOT AN ERROR HERE. A control in the driver stops at
# the FIRST dangerous cell and closes this pipe; the rest of the population is then work nobody wants. Where
# SIGPIPE is DEFAULT that kills this script silently, but a caller may have it IGNORED — GitHub Actions
# runs a step that way — and an ignored SIGPIPE turns every further write into an EPIPE that bash reports,
# one line per fixture. Measured: 6119 `write error: Broken pipe` lines on one run, burying the harness's
# own output. So the whole cell is built first and emitted by ONE printf whose failure is the signal to
# stop. Only that printf's stderr is dropped: a real error from anything inside the cell still speaks.
while IFS= read -r DIR; do
  [ -n "$DIR" ] || continue
  CELL="$("${MODE}_one")"
  printf '%s\n' "$CELL" 2>/dev/null || break
done

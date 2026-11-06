#!/usr/bin/env bash
# ci-store-probe.selftest.sh — proves tools/ci-store-probe.php measures the server the APPLICATION wrote to,
# in each case listed at the bottom of this file: DB_HOST/DB_PORT, and DB_SOCKET with and without a live
# host — and that it REFUSES, measuring nothing, when DB_URL is set, by socket and by host (card#10318).
# Each case is one way of pointing the app's `mysql` connection at a server. A host of `localhost`, which
# pdo_mysql takes to mean its default socket, has no case here.
#
# WHY. The probe is the php-tests lane's proof FROM OUTSIDE the PHP process that a step wrote to the store it
# claims. card#10318: it used to build its DSN from DB_HOST/DB_PORT alone while server/config/database.php
# honours DB_SOCKET (and DB_URL), so on a socket-only private mariadbd — the documented path for a parallel
# local run — it measured a different server than the suite, and an `--expect-tables=none` there certified a
# server nobody had touched.
#
# HOW. Two PRIVATE mariadbd instances, each with its own datadir and socket and `--skip-grant-tables` (so no
# credential exists to leak): A listens on its socket only, B on its socket and a loopback TCP port. For each
# case below the APP itself writes — `php artisan migrate --force` under the case's environment — so which
# server the app resolved is MEASURED (each server is asked how many tables it holds), not assumed. Then:
#   · `--expect-tables=some` must pass and must print the endpoint of the server the app wrote to;
#   · `--expect-tables=none` must FAIL with the probe's own "found tables" verdict — the server it measures
#     holds tables. A probe that measured the other, empty server passes here: that is the false clean this
#     file exists to catch.
# The DB_URL cases are the other way round; `url_refused`, below, says why and what they assert.
# Every case sets DB_HOST, DB_PORT, DB_SOCKET and DB_URL explicitly, so the checkout's server/.env cannot decide
# a case; the dead-end port (nothing listens on it) is what a probe that ignored the socket would hit.
#
# NEEDS mariadbd and mariadb-install-db on PATH, at or above docs/design/FLEET-STATE.md § 6.1's floor (the probe
# enforces it), php with pdo_mysql, python3, and server/vendor (`composer install`). Not run in CI: the runner image has
# no MariaDB at that floor. It is the check for the surface the card names — local and dispatched runs.
#
# USAGE: bash tools/ci-store-probe.selftest.sh
#   PROBE_SELFTEST_DATADIR_A / PROBE_SELFTEST_DATADIR_B — datadirs to create (must not exist; default: under a
#   fresh mktemp dir). Both are deleted at exit, and both servers are stopped by their own PIDs.
set -u

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/.." && pwd)"
PROBE="$REPO/tools/ci-store-probe.php"
# shellcheck source=bin/lib/selftest-assert.sh
. "$REPO/bin/lib/selftest-assert.sh" || exit 1
declare -F eq exits has notverified >/dev/null || { echo 'selftest-assert.sh did not define its assertions' >&2; exit 1; }

DB=probe_selftest

for tool in mariadbd mariadb-install-db mariadb mariadb-admin php python3; do
  if ! command -v "$tool" >/dev/null; then
    notverified "no $tool on PATH" 'every case needs two private mariadbd instances; nothing was measured'
    exit 2
  fi
done
[ -f "$REPO/server/vendor/autoload.php" ] || { echo "no server/vendor — run composer install in server/" >&2; exit 2; }
if [ -e "$REPO/server/bootstrap/cache/config.php" ]; then
  # A cached config outranks every variable below, for the app and the probe alike: no case would test anything.
  echo "server/bootstrap/cache/config.php exists — run \`php artisan config:clear\` in server/ first" >&2
  exit 2
fi

WORK="$(mktemp -d)"
SOCKDIR="$(mktemp -d /tmp/csp.XXXXXX)"   # short: a Unix socket path is limited to ~107 bytes
DATA_A="${PROBE_SELFTEST_DATADIR_A:-$WORK/a}"
DATA_B="${PROBE_SELFTEST_DATADIR_B:-$WORK/b}"
SOCK_A="$SOCKDIR/a.sock"
SOCK_B="$SOCKDIR/b.sock"
PIDS=()

cleanup() {
  local pid
  for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null; done
  for pid in "${PIDS[@]}"; do wait "$pid" 2>/dev/null; done
  rm -rf "$DATA_A" "$DATA_B" "$WORK" "$SOCKDIR"
}
trap cleanup EXIT

free_port() { python3 -c 'import socket; s = socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1])'; }

# start <datadir> <socket> <networking args…>
start() {
  local dir="$1" sock="$2"; shift 2
  [ -e "$dir" ] && { echo "$dir already exists; refusing to reuse or delete it" >&2; exit 2; }
  mkdir -p "$dir"
  mariadb-install-db --no-defaults --datadir="$dir" --skip-test-db --auth-root-authentication-method=normal \
    >"$WORK/install-$(basename "$dir").log" 2>&1 || { echo "mariadb-install-db failed for $dir" >&2; exit 2; }
  mariadbd --no-defaults --datadir="$dir" --socket="$sock" --pid-file="$dir/pid" --log-error="$dir/err.log" \
    --skip-grant-tables "$@" &
  PIDS+=("$!")
  local tries=0
  while [ "$tries" -lt 100 ]; do
    mariadb-admin --no-defaults -S "$sock" -uroot ping >/dev/null 2>&1 && return 0
    tries=$((tries + 1)); sleep 0.2
  done
  echo "mariadbd on $sock did not come up; see $dir/err.log" >&2; exit 2
}

PORT_B="$(free_port)"
PORT_DEAD="$(free_port)"   # bound for an instant and released; nothing listens on it during the run
start "$DATA_A" "$SOCK_A" --skip-networking
start "$DATA_B" "$SOCK_B" --port="$PORT_B" --bind-address=127.0.0.1

q() { mariadb --no-defaults -S "$1" -uroot -N -B -e "$2"; }
tables_on() { q "$1" "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB'"; }
reset_both() { local s; for s in "$SOCK_A" "$SOCK_B"; do q "$s" "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB"; done; }

# case <label> <server the app should land on: A|B> <the endpoint text the probe must print> <VAR=value…>
run_case() {
  local label="$1" want="$2" endpoint="$3"; shift 3
  local -a envs=(DB_CONNECTION=mysql "DB_DATABASE=$DB" DB_USERNAME=root DB_PASSWORD= MYSQL_ATTR_SSL_CA= "$@")
  section "$label"
  reset_both

  OUT="$(cd "$REPO/server" && env "${envs[@]}" php artisan migrate --force 2>&1)"; RC=$?
  exits "$label: the app migrates" 0
  local a b
  a="$(tables_on "$SOCK_A")"; b="$(tables_on "$SOCK_B")"
  if [ "$want" = A ]; then
    eq "$label: the app wrote to A and not to B (fixture premise)" "yes" "$([ "$a" -gt 0 ] && [ "$b" -eq 0 ] && echo yes || echo "no (A=$a B=$b)")"
  else
    eq "$label: the app wrote to B and not to A (fixture premise)" "yes" "$([ "$b" -gt 0 ] && [ "$a" -eq 0 ] && echo yes || echo "no (A=$a B=$b)")"
  fi

  OUT="$(cd "$REPO" && env "${envs[@]}" php "$PROBE" --expect-tables=some 2>&1)"; RC=$?
  exits "$label: --expect-tables=some passes" 0
  has "$label: the probe names the endpoint it measured" "$endpoint" "$OUT"

  OUT="$(cd "$REPO" && env "${envs[@]}" php "$PROBE" --expect-tables=none 2>&1)"; RC=$?
  exits "$label: --expect-tables=none fails" 1
  # Its OWN verdict, not just any exit 1: a probe that could not connect also exits 1, and that is no
  # measurement of the server the app wrote to.
  has "$label: --expect-tables=none fails because it found the app's tables" "to be EMPTY and found" "$OUT"
}

run_case "DB_HOST/DB_PORT only" B "host=127.0.0.1;port=$PORT_B" \
  DB_HOST=127.0.0.1 "DB_PORT=$PORT_B" DB_SOCKET= DB_URL=
run_case "DB_SOCKET and DB_HOST/DB_PORT both set (the socket wins)" A "unix_socket=$SOCK_A" \
  DB_HOST=127.0.0.1 "DB_PORT=$PORT_B" "DB_SOCKET=$SOCK_A" DB_URL=
run_case "DB_SOCKET with a dead-end DB_HOST/DB_PORT" A "unix_socket=$SOCK_A" \
  DB_HOST=127.0.0.1 "DB_PORT=$PORT_DEAD" "DB_SOCKET=$SOCK_A" DB_URL=

# url_refused <label> <VAR=value…> — a non-empty DB_URL. `artisan` honours it and the SUITE does not
# (server/phpunit.xml forces DB_URL=""), so with one set the two can address different servers and no
# measurement is about both: the probe must refuse by name, before it measures anything. The suite's side is
# run here as `artisan migrate` with DB_URL emptied, which is the effect of that pin; it writes to B. A probe
# that followed the URL measures A, which is empty — and `--expect-tables=none` then passes: the false clean.
url_refused() {
  local label="$1"; shift
  local -a envs=(DB_CONNECTION=mysql "DB_DATABASE=$DB" DB_USERNAME=root DB_PASSWORD= MYSQL_ATTR_SSL_CA= "$@")
  section "$label"
  reset_both

  OUT="$(cd "$REPO/server" && env "${envs[@]}" DB_URL= php artisan migrate --force 2>&1)"; RC=$?
  exits "$label: the suite's resolution (DB_URL blanked) migrates" 0
  local a b
  a="$(tables_on "$SOCK_A")"; b="$(tables_on "$SOCK_B")"
  eq "$label: it wrote to B and not to A (fixture premise)" "yes" "$([ "$b" -gt 0 ] && [ "$a" -eq 0 ] && echo yes || echo "no (A=$a B=$b)")"

  local expect
  for expect in none some; do
    OUT="$(cd "$REPO" && env "${envs[@]}" php "$PROBE" --expect-tables=$expect 2>&1)"; RC=$?
    exits "$label: --expect-tables=$expect refuses" 1
    has "$label: --expect-tables=$expect names DB_URL as the reason" "DB_URL is set" "$OUT"
    hasnt "$label: --expect-tables=$expect measured nothing" "measuring mysql:" "$OUT"
  done
}

url_refused "DB_URL whose query names A's socket, over DB_HOST/DB_PORT naming B" \
  DB_HOST=127.0.0.1 "DB_PORT=$PORT_B" DB_SOCKET= "DB_URL=mysql://root@127.0.0.1:$PORT_B/$DB?unix_socket=$SOCK_A"
url_refused "DB_URL naming a dead-end port, over DB_HOST/DB_PORT naming B" \
  DB_HOST=127.0.0.1 "DB_PORT=$PORT_B" DB_SOCKET= "DB_URL=mysql://root@127.0.0.1:$PORT_DEAD/$DB"

printf '\nci-store-probe selftest: %d assertions, %d FAILED\n' "$cases" "$fails"
[ "$fails" -eq 0 ]

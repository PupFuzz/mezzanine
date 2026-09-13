#!/usr/bin/env bash
# deploy.selftest.sh — hermetic, network-free acceptance for bin/deploy.sh and bin/supervision.sh. card#7459
#
# WHY IT EXISTS. card#7459's acceptance is one sentence: the deploy is "seen to fail on a broken
# precondition before trusted". A check that has never been watched refuse is a decoration, and a
# deploy script is the worst place to find that out — the first real run is against production, in
# a maintenance window, with the app down. So every refusal below is exercised HERE, against the
# real script, before the host it will run on even exists.
#
# WHAT IS REAL AND WHAT IS STUBBED.
#   REAL: bash, git (throwaway fixture repositories in a temp dir), the whole of bin/deploy.sh —
#         including the re-exec, which really does hand off to the checked-out copy — and
#         bin/supervision.sh. And THE DAEMON RESTART: flock, fuser, setsid, ps and kill run for real (fuser
#         behind a pass-through that one case slows by 0.3 s, to know when a lock is first sampled, and ps
#         behind one that one case blinds) against stub daemons holding locks inside the temp dir, so "the old process is gone and a
#         new one holds the lock" is observed, not merely recorded.
#   STUB: php (and the daemons it runs), php-fpm<minor>, composer, npm, crontab, curl, id — on PATH,
#         recording every call to $CALL_LOG. The crontab stub reads and writes one file per fixture;
#         the php-fpm stub prints a phpinfo whose opcache values each case sets, beside a fixture
#         php-fpm.conf and pool directory shaped like the Virtualmin sandbox's.
#   NOTHING here touches a live host, the real crontab, a real FPM pool, a credential or a socket,
#   and nothing outside the temp dir is signalled: every kill targets a lock under $T.
#
# RED-FIRST, WITH CONTROLS. Every refusal case is paired with a control that differs by ONE
# variable and passes, so a green is evidence that the check DISCRIMINATES rather than evidence
# that it always fires. The ones that matter most:
#   - the migration gate (§ 6.9): the same fixture, with and without the `ALGORITHM=` comment;
#   - the in-window failure: the same fixture, with and without a failing `migrate`, asserting
#     that `artisan up` IS called in one and is NEVER called in the other;
#   - the crontab (A13): the same host with its block intact, with one entry removed, and the deploy
#     installing it again;
#   - the opcache posture (A14): the same host, timestamps off then on — and a pool that is NOT the
#     deploy user's, carrying timestamps off, that must never be read;
#   - the restart proof and the opcache wait: each seen to fail against a copy of the release's own
#     deploy.sh with the step it guards cut out;
#   - ACROSS RELEASES: a target release whose bin/supervision.sh adds a daemon, drops one, no longer defines a
#     function the deploy runs from it, or would move the lock files (refused) — the serving release's copy is
#     not the one that may judge any of them — the stop of every lock file of the checkout seen to fail with it
#     cut out, and the re-run after a window that failed with the previous release's daemons still up;
#   - the opcache wait after a release that LOWERS revalidate_freq in its .user.ini, seen to fail with the
#     floor phase A hands over cut out;
#   - a `.user.ini` over the app's scripts (A14): absent, turning timestamps off, removed again.
#
# RUN: bin/deploy.selftest.sh          (exit 0 = every case passed)

set -uo pipefail

HERE="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
DEPLOY="$HERE/deploy.sh"
REPO="$(cd "$HERE/.." && pwd)"
[ -x "$DEPLOY" ] || { echo "selftest: $DEPLOY not found or not executable" >&2; exit 1; }
# shellcheck source=bin/supervision.sh
. "$HERE/supervision.sh"

# Resolved, because deploy.sh resolves the php it writes into crontab entries (readlink -f): a T
# under a symlinked /tmp would make every fixture's installed entries differ from what it expects.
T="$(readlink -f "$(mktemp -d)")"
kill_daemons() { # kill_daemons <root> — SIGKILL whatever holds a fixture's daemon locks
  local l
  for l in "$1"/server/storage/framework/daemon-*.lock; do
    if [ -e "$l" ]; then fuser -k -KILL "$l" >/dev/null 2>&1; fi
  done
  return 0
}
cleanup() {
  local r
  for r in "$T"/*/root; do kill_daemons "$r"; done
  rm -rf "$T"
}
trap cleanup EXIT
export CALL_LOG="$T/calls.log"; : > "$CALL_LOG"
ME="$(/usr/bin/id -un)"

fails=0; cases=0
ok()  { printf '  ok   %s\n' "$1"; }
bad() { printf '  FAIL %s\n' "$1" >&2; fails=$((fails + 1)); }
eq()  { cases=$((cases+1)); [ "$2" = "$3" ] && ok "$1" || bad "$1 — expected '$2', got '$3'"; }
neq() { cases=$((cases+1)); [ "$2" != "$3" ] && ok "$1" || bad "$1 — expected anything but '$2'"; }
has() { cases=$((cases+1)); case "$3" in *"$2"*) ok "$1" ;; *) bad "$1 — output did not contain '$2'" ;; esac; }
hasnt() { cases=$((cases+1)); case "$3" in *"$2"*) bad "$1 — output unexpectedly contained '$2'" ;; *) ok "$1" ;; esac; }
logged()   { cases=$((cases+1)); grep -q -- "$2" "$CALL_LOG" && ok "$1" || bad "$1 — '$2' was never called"; }
unlogged() { cases=$((cases+1)); grep -q -- "$2" "$CALL_LOG" && bad "$1 — '$2' WAS called" || ok "$1"; }
# before — ordering inside the call log. The cache-rebuild order is load-bearing, so it is pinned
# here rather than left to a reader of the script.
before() {
  cases=$((cases+1))
  local a b
  a="$(grep -n -- "$2" "$CALL_LOG" | head -1 | cut -d: -f1)"
  b="$(grep -n -- "$3" "$CALL_LOG" | head -1 | cut -d: -f1)"
  if [ -n "$a" ] && [ -n "$b" ] && [ "$a" -lt "$b" ]; then ok "$1"
  else bad "$1 — '$2' (line ${a:-none}) is not before '$3' (line ${b:-none})"; fi
}
section() { printf '\n── %s\n' "$1"; }

# ── stubs on PATH ─────────────────────────────────────────────────────────────────────────────
REAL_FUSER="$(command -v fuser)" || { echo "selftest: fuser not found" >&2; exit 1; }
REAL_PS="$(command -v ps)" || { echo "selftest: ps not found" >&2; exit 1; }
mkdir -p "$T/bin" "$T/knobs"; export PATH="$T/bin:$PATH"
# `mezzanine:extra` is in no release this repo ships: it is the daemon the ACROSS RELEASES case's target
# release adds, and the stub has to know to hold a lock for it.
printf '%s\n' "${SUPERVISED_DAEMONS[@]}" mezzanine:extra > "$T/knobs/daemons"

# php. A supervised daemon is started under `env -i` (by the fixture, and by deploy.sh exactly as
# cron would), so the paths it needs are baked in and its knobs are FILES, not environment.
{
  printf '#!/usr/bin/env bash\nCALL_LOG=%q\nKNOBS=%q\n' "$CALL_LOG" "$T/knobs"
  cat <<'STUB'
printf 'php %s\n' "$*" >> "$CALL_LOG"
[ "${1:-}" = "-r" ] && { printf '%s' "${STUB_PHP_VERSION:-8.3.14}"; exit 0; }
# A supervised daemon holds flock's lock — the inherited fd — until it is signalled, or dies.
if [ "${1:-}" = artisan ] && grep -qxF -- "${2:-}" "$KNOBS/daemons"; then
  if grep -qxF -- "$2" "$KNOBS/dies_after_start" 2>/dev/null; then sleep 1; exit 1; fi
  if grep -qxF -- "$2" "$KNOBS/ignores_term" 2>/dev/null; then trap '' TERM; fi
  if grep -qxF -- "$2" "$KNOBS/transient_loser" 2>/dev/null; then
    # A cron tick that lost the race: a flock with this daemon's lock file open, gone a second later.
    flock -w 1 "storage/framework/daemon-${2#mezzanine:}.lock" true </dev/null >/dev/null 2>&1 &
  fi
  exec sleep 600
fi
if [ -n "${STUB_FAIL_RE:-}" ] && printf 'php %s' "$*" | grep -Eq "$STUB_FAIL_RE"; then
  echo "stub php: forced failure on: $*" >&2; exit 1
fi
exit 0
STUB
} > "$T/bin/php"
for c in composer npm; do
cat > "$T/bin/$c" <<STUB
#!/usr/bin/env bash
printf '$c %s\n' "\$*" >> "\$CALL_LOG"
if [ -n "\${STUB_FAIL_RE:-}" ] && printf '$c %s' "\$*" | grep -Eq "\$STUB_FAIL_RE"; then
  echo "stub $c: forced failure" >&2; exit 1
fi
exit 0
STUB
done
# php-fpm<minor>: its phpinfo, the FPM SAPI's view of opcache (A14). One script, one name per minor
# the cases below run, so the binary deploy.sh derives from the host's PHP is always resolvable.
cat > "$T/bin/php-fpm-stub" <<'STUB'
#!/usr/bin/env bash
printf '%s %s\n' "$(basename "$0")" "$*" >> "$CALL_LOG"
[ "${1:-}" = "-i" ] || { echo "stub php-fpm: only -i is stubbed" >&2; exit 1; }
cat <<INFO
phpinfo()
Server API => FPM/FastCGI
Loaded Configuration File => $STUB_FPM_ETC/php.ini
user_ini.filename => .user.ini => .user.ini
opcache.enable => $STUB_OPCACHE_ENABLE => $STUB_OPCACHE_ENABLE
opcache.preload => $STUB_OPCACHE_PRELOAD => $STUB_OPCACHE_PRELOAD
opcache.revalidate_freq => $STUB_OPCACHE_FREQ => $STUB_OPCACHE_FREQ
opcache.validate_timestamps => $STUB_OPCACHE_VALIDATE => $STUB_OPCACHE_VALIDATE
INFO
STUB
for v in 8.3 8.4 8.5 9.0; do ln -s php-fpm-stub "$T/bin/php-fpm$v"; done
# crontab: `-l` and `-` over one file per fixture. No file is "no crontab for <user>" (exit 1, as
# cron says it); an empty file is an empty crontab (exit 0, nothing printed).
cat > "$T/bin/crontab" <<'STUB'
#!/usr/bin/env bash
printf 'crontab %s\n' "$*" >> "$CALL_LOG"
if [ -n "${STUB_CRONTAB_BROKEN:-}" ]; then echo "crontab: $STUB_CRONTAB_BROKEN" >&2; exit 2; fi
case "${1:-}" in
  -l) [ -e "$STUB_CRONTAB_FILE" ] || { echo "no crontab for $(/usr/bin/id -un)" >&2; exit 1; }
      cat "$STUB_CRONTAB_FILE" ;;
  -)  cat > "$STUB_CRONTAB_FILE" ;;
  *)  echo "stub crontab: unsupported: $*" >&2; exit 2 ;;
esac
STUB
cat > "$T/bin/curl" <<'STUB'
#!/usr/bin/env bash
printf 'curl %s\n' "$*" >> "$CALL_LOG"
printf '%s' "${STUB_HTTP_CODE:-200}"
STUB
# fuser: the REAL one — only slowed, by a knob, so a case can know a lock is sampled 0.3 s after a start.
{
  printf '#!/usr/bin/env bash\nKNOBS=%q\nREAL_FUSER=%q\n' "$T/knobs" "$REAL_FUSER"
  cat <<'STUB'
if [ -e "$KNOBS/slow_fuser" ]; then sleep 0.3; fi
exec "$REAL_FUSER" "$@"
STUB
} > "$T/bin/fuser"
# ps: the REAL one — blinded, by a knob, to print no process age at all, as a ps that cannot read a pid does.
{
  printf '#!/usr/bin/env bash\nKNOBS=%q\nREAL_PS=%q\n' "$T/knobs" "$REAL_PS"
  cat <<'STUB'
if [ -e "$KNOBS/blind_ps" ]; then exit 0; fi
exec "$REAL_PS" "$@"
STUB
} > "$T/bin/ps"
cat > "$T/bin/id" <<'STUB'
#!/usr/bin/env bash
case "${1:-}" in
  -u) echo "${STUB_UID:-$(/usr/bin/id -u)}" ;;
  *)  /usr/bin/id "$@" ;;
esac
STUB
chmod +x "$T/bin/"*

# The host's FPM configuration, shaped like the Virtualmin sandbox's: php-fpm.conf beside php.ini,
# including pool.d/*.conf, and a per-domain pool running as the deploy user.
export STUB_FPM_ETC="$T/etc-fpm"
POOL="$STUB_FPM_ETC/pool.d/178815168175465.conf"
write_fpm_etc() {
  rm -rf "$STUB_FPM_ETC"; mkdir -p "$STUB_FPM_ETC/pool.d"
  : > "$STUB_FPM_ETC/php.ini"
  printf '[global]\npid = /run/php/php-fpm.pid\ninclude=%s/pool.d/*.conf\n' "$STUB_FPM_ETC" > "$STUB_FPM_ETC/php-fpm.conf"
  printf '[178815168175465]\nuser = %s\ngroup = %s\nlisten = /run/php/178815168175465.sock\nphp_value[log_errors] = On\n' \
    "$ME" "$ME" > "$POOL"
  # ⛔ A TRAP, not filler: a pool that is NOT the deploy user's, with timestamps off. A reader that
  # took every pool rather than this user's would refuse every control in this file.
  printf '[www]\nuser = www-data\nphp_admin_flag[opcache.validate_timestamps] = off\n' > "$STUB_FPM_ETC/pool.d/www.conf"
}

# reset_stubs — bash persists `VAR=x func` assignments after the call, so a knob set for one case
# would silently leak into every later one. Each fixture starts from a known set instead.
reset_stubs() {
  export STUB_FAIL_RE="" STUB_HTTP_CODE=200
  # 8.4.7 SATISFIES $FIXTURE_PHP_FLOOR without BEING it, so a case that passes here is not
  # passing on an accidental exact match.
  export STUB_PHP_VERSION="8.4.7"
  export STUB_OPCACHE_ENABLE=On STUB_OPCACHE_VALIDATE=On STUB_OPCACHE_FREQ=0 STUB_OPCACHE_PRELOAD="no value"
  export MEZZ_DAEMON_SETTLE_S=2 MEZZ_DAEMON_STOP_TIMEOUT_S=3
  unset STUB_UID STUB_CRONTAB_BROKEN MEZZ_DEPLOY_IN_WINDOW MEZZ_DEPLOY_REVALIDATE_FLOOR_S MEZZ_FPM_BIN
  : > "$T/knobs/dies_after_start"; : > "$T/knobs/ignores_term"; : > "$T/knobs/transient_loser"
  rm -f "$T/knobs/slow_fuser" "$T/knobs/blind_ps"
  # The document root A14 reads a .user.ini from. Never the default ($HOME/public_html): on the host
  # this runs on that is a REAL vhost's, and a selftest that read it would not be hermetic.
  export MEZZ_DOCROOT="$T/docroot"; rm -rf "$MEZZ_DOCROOT"; mkdir -p "$MEZZ_DOCROOT"
  write_fpm_etc
}

# ── fixture ───────────────────────────────────────────────────────────────────────────────────
# A throwaway repo pair: `origin.git` (bare) with `main` at two commits, and `root` — the "prod
# checkout" — parked one commit behind, which is the state a real deploy starts from. Its crontab
# is installed with the REAL bin/supervision.sh, so every control is also a test of the install.
FAKE_KEY='base64:SELFTESTFAKEKEYAAAAAAAAAAAAAAAAAAAAAAAAAAA='
FAKE_PW='SELFTESTFAKEDBPASSWORD'

gitc() { git -C "$1" -c user.email=selftest@example.invalid -c user.name=selftest "${@:2}"; }

# The floor the FIXTURE declares. deploy.sh's A6 reads its constraint out of the release being
# deployed rather than carrying a case list of its own (card#9203), so a case below can move
# the declaration and watch the refusal follow it — which is what makes these assertions
# evidence of DERIVATION rather than of a literal that happens to agree today. Deliberately
# NOT read from the real server/composer.json: a fixture tracking the repo's own floor would
# go green for the wrong reason on the day that floor moved.
FIXTURE_PHP_FLOOR='^8.4.1'

write_composer_json() { # write_composer_json <tree> <php-constraint>
  # Pretty-printed, because that is the shape composer itself writes and the shape A6's
  # reader is built for; a fixture in a shape the real file never takes would test nothing.
  # The `require-dev` php key is a TRAP, not filler: the floor is `require`'s, and a reader
  # that grabbed the first "php" it saw anywhere would still pass every case without it.
  cat > "$1/server/composer.json" <<COMPOSER
{
    "name": "selftest/fixture",
    "require": {
        "php": "$2",
        "laravel/framework": "^13.17"
    },
    "require-dev": {
        "php": "NEVER-READ-require-dev-is-not-the-floor"
    }
}
COMPOSER
}

write_env() { # write_env <root>
  local root="$1"
  cat > "$root/server/.env" <<ENV
APP_ENV=production
APP_DEBUG=false
APP_KEY=$FAKE_KEY
APP_URL=https://mezzanine.example
DB_CONNECTION=mysql
DB_PASSWORD=$FAKE_PW
MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt
BROADCAST_CONNECTION=log
CACHE_STORE=database
ENV
  chmod 640 "$root/server/.env"
}

install_crontab() { "$ROOT/bin/supervision.sh" install --root "$ROOT" --php "$T/bin/php" >/dev/null 2>&1; }

# mkfix <case> [mutator] [v1-mutator]  — mutator runs in the source tree before the SECOND commit, so a
# case can put whatever it needs into the commit the deploy is asked to move TO; v1-mutator runs before the
# FIRST, the release the host is serving.
mkfix() {
  local case="$1" mutator="${2:-}" v1_mutator="${3:-}" cd="$T/$1"
  [ -z "${ROOT:-}" ] || kill_daemons "$ROOT"
  reset_stubs
  ROOT="$cd/root"; ORIGIN="$cd/origin.git"; SRC="$cd/src"
  mkdir -p "$cd"
  git init -q --bare -b main "$ORIGIN"
  git init -q -b main "$SRC"
  mkdir -p "$SRC/bin" "$SRC/server/bootstrap" "$SRC/server/database/migrations" \
    "$SRC/server/storage/framework" "$SRC/server/storage/logs"
  cp "$DEPLOY" "$SRC/bin/deploy.sh"; chmod +x "$SRC/bin/deploy.sh"
  cp "$HERE/supervision.sh" "$SRC/bin/supervision.sh"; chmod +x "$SRC/bin/supervision.sh"
  # The REAL ignore files, so a full run's lock and log files are judged against what ships.
  cp "$REPO/server/storage/framework/.gitignore" "$SRC/server/storage/framework/.gitignore"
  cp "$REPO/server/storage/logs/.gitignore" "$SRC/server/storage/logs/.gitignore"
  printf '0.0.1\n' > "$SRC/VERSION"
  printf '.deploy-failed\nserver/.env\n' > "$SRC/.gitignore"
  printf '#!/usr/bin/env php\n' > "$SRC/server/artisan"
  printf '<?php return Application::configure()->withRouting(health: "/up")->create();\n' \
    > "$SRC/server/bootstrap/app.php"
  printf '{"lockfileVersion":3}\n' > "$SRC/server/package-lock.json"
  write_composer_json "$SRC" "$FIXTURE_PHP_FLOOR"
  printf 'APP_ENV=\nAPP_DEBUG=\nAPP_KEY=\nAPP_URL=\nDB_CONNECTION=\nDB_PASSWORD=\nMYSQL_ATTR_SSL_CA=\nBROADCAST_CONNECTION=\nCACHE_STORE=\n# COMMENTED_OPTIONAL=\n' \
    > "$SRC/server/.env.example"
  cat > "$SRC/server/database/migrations/2026_01_01_000000_create_fleet_store_tables.php" <<'MIG'
<?php
// Schema::create('events', ...) — a CREATE, not an ALTER: FLEET-STATE.md § 6.9 rule 1 is about
// migrations that ALTER a live `events` table, and this fixture asserts the gate knows that.
return new class { public function up(): void { Schema::create('events', fn ($t) => $t->id()); } };
MIG
  [ -z "$v1_mutator" ] || "$v1_mutator" "$SRC"
  gitc "$SRC" add -A >/dev/null
  gitc "$SRC" commit -qm 'fixture v1'
  gitc "$SRC" remote add origin "$ORIGIN"
  gitc "$SRC" push -q origin main
  V1="$(gitc "$SRC" rev-parse HEAD)"

  [ -z "$mutator" ] || "$mutator" "$SRC"
  printf '0.0.2\n' > "$SRC/VERSION"
  gitc "$SRC" add -A >/dev/null
  gitc "$SRC" commit -qm 'fixture v2 (the release being deployed)'
  gitc "$SRC" push -q origin main
  V2="$(gitc "$SRC" rev-parse HEAD)"

  git clone -q "$ORIGIN" "$ROOT"
  gitc "$ROOT" checkout -q --detach "$V1"
  write_env "$ROOT"
  export STUB_CRONTAB_FILE="$cd/crontab"
  install_crontab
}

# start_old_daemons — the daemons cron is already running on the host, the way cron runs them.
start_old_daemons() {
  local c l deadline
  OLD_PIDS=""
  for c in "${SUPERVISED_DAEMONS[@]}"; do
    env -i HOME="$HOME" PATH=/usr/bin:/bin \
      setsid -f /bin/sh -c "$(supervision_command "$ROOT" "$T/bin/php" "$c")" </dev/null >/dev/null 2>&1
  done
  for c in "${SUPERVISED_DAEMONS[@]}"; do
    l="$(supervision_lock "$ROOT" "$c")"; deadline=$(($(date +%s) + 5))
    until [ -n "$(fuser "$l" 2>/dev/null)" ] || [ "$(date +%s)" -ge "$deadline" ]; do sleep 0.1; done
    OLD_PIDS+=" $(fuser "$l" 2>/dev/null)"
  done
}

# run <args…> — invoke the deploy script under the fixture, capturing everything.
run() {
  : > "$CALL_LOG"
  OUT="$(MEZZ_DEPLOY_ROOT="$ROOT" "$ROOT/bin/deploy.sh" "$@" 2>&1)"
  RC=$?
}

# ══════════════════════════════════════════════════════════════════════════════════════════════
section "CONTROL — a well-formed host passes every precondition (--dry-run)"
mkfix control
run --dry-run
eq  "control: exit 0"                       0 "$RC"
has "control: the § 6.9 migration gate ran and passed" "no undeclared ALTER" "$OUT"
has "control: prints the plan"              "DRY RUN" "$OUT"
hasnt "control: no config drift on a host whose .env covers .env.example" "does not set:" "$OUT"
has "control: the release's crontab block is what is installed" "is what is installed; the window rewrites it unchanged" "$OUT"
has "control: the release keeps the daemons' lock files where the serving release's are" "keeps the daemons' lock files at $ROOT/server/" "$OUT"
has "control: PHP-FPM is not reloaded, and the posture that makes that safe was read" "php-fpm  not reloaded — opcache revalidates a changed file within 0 s" "$OUT"
logged "control: A14 read the FPM SAPI (php-fpm -i), not the CLI's ini" "php-fpm8.4 -i"
hasnt "control: another user's pool (the www trap, timestamps off) was not read" "validate_timestamps is off" "$OUT"
unlogged "control: --dry-run mutates nothing (no artisan down)" "artisan down"
unlogged "control: --dry-run writes no crontab" "crontab -$"
hasnt "control: a document root with no .user.ini is not warned about" "no document root at" "$OUT"
eq  "control: --dry-run left HEAD where it was" "$V1" "$(git -C "$ROOT" rev-parse HEAD)"

section "REFUSAL — the host is not in a deployable state"
run_refusal() { # run_refusal <label> <needle> <args…>
  local label="$1" needle="$2"; shift 2
  run "$@"
  eq  "$label: exit 1 (refused, nothing touched)" 1 "$RC"
  has "$label: says why" "$needle" "$OUT"
  unlogged "$label: never opened the window" "artisan down"
}

mkfix as_root; export STUB_UID=0; run --dry-run
eq "root: exit 1" 1 "$RC"; has "root: says why" "running as root" "$OUT"
hasnt "root: offers no escalation route" "sudo" "$OUT"

mkfix stale_marker
printf 'started_at: 2026-09-08T00:00:00Z\nto_commit: deadbeef\n' > "$ROOT/.deploy-failed"
run_refusal "stale marker" "a previous deploy failed and has not been reviewed" --dry-run
has "stale marker: shows the marker's content" "to_commit: deadbeef" "$OUT"

mkfix dirty_tree; printf 'hand edit\n' >> "$ROOT/VERSION"
run_refusal "dirty tree" "local modifications" --dry-run

mkfix no_env; rm -f "$ROOT/server/.env"
run_refusal "missing .env" "does not exist" --dry-run

mkfix loose_env; chmod 644 "$ROOT/server/.env"
run_refusal "world-readable .env" "readable beyond its owner" --dry-run

mkfix wrong_app_env; sed -i 's/^APP_ENV=.*/APP_ENV=local/' "$ROOT/server/.env"
run_refusal "APP_ENV=local" "APP_ENV is 'local'" --dry-run

mkfix debug_on; sed -i 's/^APP_DEBUG=.*/APP_DEBUG=true/' "$ROOT/server/.env"
run_refusal "APP_DEBUG=true" "APP_DEBUG is 'true'" --dry-run

mkfix no_key; sed -i 's/^APP_KEY=.*/APP_KEY=/' "$ROOT/server/.env"
run_refusal "empty APP_KEY" "APP_KEY is empty" --dry-run

mkfix sqlite_prod; sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' "$ROOT/server/.env"
run_refusal "sqlite in prod" "DB_CONNECTION is 'sqlite'" --dry-run
hasnt "sqlite refusal leaks no DB password" "$FAKE_PW" "$OUT"

mkfix cache_array; sed -i 's/^CACHE_STORE=.*/CACHE_STORE=array/' "$ROOT/server/.env"
run_refusal "non-persistent cache store" "CACHE_STORE is 'array'" --dry-run

mkfix no_tls; sed -i '/^MYSQL_ATTR_SSL_CA=/d' "$ROOT/server/.env"
run_refusal "no TLS to the store" "MYSQL_ATTR_SSL_CA is unset" --dry-run

section "REFUSAL — a tool the supervision needs is missing (A1)"
# PATH is rebuilt from every stub and every host binary, minus ONE command. The control is the same
# rebuilt PATH minus nothing, so a refusal is the missing command and never the rebuilt PATH.
path_without() { # path_without <dir> [command-to-hide]
  mkdir -p "$1"
  ln -s -t "$1" "$T/bin"/* 2>/dev/null
  ln -s -t "$1" /usr/local/bin/* /usr/bin/* /bin/* 2>/dev/null
  [ -z "${2:-}" ] || rm -f "$1/$2"
}
mkfix tool_control; path_without "$T/path-control"
: > "$CALL_LOG"; OUT="$(PATH="$T/path-control" MEZZ_DEPLOY_ROOT="$ROOT" "$ROOT/bin/deploy.sh" --dry-run 2>&1)"; RC=$?
eq "control: the rebuilt PATH, hiding nothing, deploys" 0 "$RC"
for hide in crontab flock fuser setsid ps; do
  mkfix "tool_$hide"; path_without "$T/path-$hide" "$hide"
  : > "$CALL_LOG"; OUT="$(PATH="$T/path-$hide" MEZZ_DEPLOY_ROOT="$ROOT" "$ROOT/bin/deploy.sh" --dry-run 2>&1)"; RC=$?
  eq  "no $hide: exit 1" 1 "$RC"
  has "no $hide: names it" "missing required command(s): $hide" "$OUT"
done

section "CRON SUPERVISION (A13) — the deployed release's block, judged by that release's own install"
drop_lines() { grep -v -E -- "$1" "$STUB_CRONTAB_FILE" > "$STUB_CRONTAB_FILE.new"; mv "$STUB_CRONTAB_FILE.new" "$STUB_CRONTAB_FILE"; }
# A crontab that lacks this checkout's entries, or carries another release's, is NOT refused: the window installs the
# deployed release's block, and the dry run names every line it will add or remove. Control, mutant and full run on
# ONE host: the block intact, one entry dropped, and the deploy putting it back.
mkfix cron_sweep_missing
run --dry-run
has "control: an intact crontab — the window rewrites the block unchanged" "is what is installed; the window rewrites it unchanged" "$OUT"
SWEEP_LINE="* * * * * $(supervision_command "$ROOT" "$T/bin/php" mezzanine:sweep)"
drop_lines '^\* \* \* \* \* .* artisan mezzanine:sweep '
run --dry-run
eq    "no every-minute entry for the sweep, dry run: exit 0 — the window installs it" 0 "$RC"
has   "sweep missing: names the exact line the window adds" "+ $SWEEP_LINE" "$OUT"
hasnt "sweep missing: adds no line that IS installed" "+ * * * * * $(supervision_command "$ROOT" "$T/bin/php" mezzanine:fold)" "$OUT"
unlogged "sweep missing, dry run: the crontab was not written" "crontab -$"
run
eq  "sweep missing, full run: exit 0" 0 "$RC"
has "sweep missing, full run: the crontab carries the sweep's entry again" "$SWEEP_LINE" "$(cat "$STUB_CRONTAB_FILE")"

mkfix cron_reboot_missing; drop_lines '^@reboot .* artisan mezzanine:fold '; run --dry-run
eq  "no @reboot entry for the fold: exit 0" 0 "$RC"
has "@reboot missing: names it as added" "+ @reboot $(supervision_command "$ROOT" "$T/bin/php" mezzanine:fold)" "$OUT"

mkfix cron_scheduler_missing; drop_lines 'artisan schedule:run'; run --dry-run
eq  "no schedule:run entry: exit 0" 0 "$RC"
has "schedule:run missing: names it as added (mezzanine:purge runs from it)" "+ * * * * * cd $ROOT/server && $T/bin/php artisan schedule:run" "$OUT"

mkfix cron_commented
sed -i 's|^\(\* \* \* \* \* .* artisan mezzanine:fold \)|# \1|' "$STUB_CRONTAB_FILE"; run --dry-run
eq  "the fold's entry commented out: exit 0" 0 "$RC"
has "commented out: the commented line is named as removed" "- # * * * * * $(supervision_command "$ROOT" "$T/bin/php" mezzanine:fold)" "$OUT"

mkfix cron_none; rm -f "$STUB_CRONTAB_FILE"; run --dry-run
eq  "no crontab at all ('no crontab for …', exit 1): exit 0" 0 "$RC"
has "no crontab: the whole block is named as added" "+ $(supervision_begin "$ROOT")" "$OUT"

mkfix cron_empty; : > "$STUB_CRONTAB_FILE"; run --dry-run
eq  "an EMPTY crontab (crontab -l exits 0, prints nothing): exit 0" 0 "$RC"
has "empty crontab: the whole block is named as added" "+ $(supervision_begin "$ROOT")" "$OUT"

mkfix cron_other_checkout; supervision_render "/srv/elsewhere" "$T/bin/php" > "$STUB_CRONTAB_FILE"; run --dry-run
eq    "only ANOTHER checkout's block: exit 0" 0 "$RC"
has   "another checkout: this checkout's block is named as added" "+ $(supervision_begin "$ROOT")" "$OUT"
hasnt "another checkout: its block is kept, not removed" "- $(supervision_begin /srv/elsewhere)" "$OUT"

mkfix cron_other_php; supervision_render "$ROOT" "/usr/bin/php8.1" > "$STUB_CRONTAB_FILE"; run --dry-run
eq  "this checkout's block rendered for ANOTHER php binary: exit 0" 0 "$RC"
has "another php: its lines are named as removed" "- * * * * * cd $ROOT/server && /usr/bin/php8.1 artisan schedule:run" "$OUT"

# What the release's install would refuse, the deploy refuses — before anything is touched.
mkfix cron_unreadable; export STUB_CRONTAB_BROKEN="cannot open crontab: Permission denied"
run_refusal "crontab -l fails for another reason than 'no crontab' (exit 2)" "could not be installed here" --dry-run
has "unreadable: carries install's own reason" "not because the crontab is empty" "$OUT"

# The sandbox's hand-staged shape: the same commands, written through cron variables.
mkfix cron_handstaged
{ printf 'MZ=%s/server\n' "$ROOT"
  for c in schedule:run "${SUPERVISED_DAEMONS[@]}"; do printf '* * * * * cd $MZ && %s artisan %s >> /dev/null 2>&1\n' "$T/bin/php" "$c"; done
} > "$STUB_CRONTAB_FILE"
run_refusal "a hand-staged crontab written with variables" "could not be installed here" --dry-run
has "hand-staged with variables: carries install's own reason" "already runs a supervised command outside the managed block" "$OUT"

mkfix "space root"
run_refusal "a checkout path cron cannot carry (a space)" "cron cannot carry" --dry-run

# A hand-staged line BESIDE an intact managed block: only the release's install can refuse this host.
mkfix cron_handstaged_beside_block
printf '* * * * * cd /home/x/server && flock -n /tmp/fold.lock /usr/bin/php8.5 artisan mezzanine:fold >> x 2>&1\n' >> "$STUB_CRONTAB_FILE"
cp "$STUB_CRONTAB_FILE" "$T/crontab.before"
run_refusal "a hand-staged fold line BESIDE the managed block (the release's install would refuse it)" "could not be installed here" --dry-run
has "beside the block: carries install's own reason" "already runs a supervised command outside the managed block" "$OUT"
eq  "beside the block: the crontab is unchanged" "$(cat "$T/crontab.before")" "$(cat "$STUB_CRONTAB_FILE")"
drop_lines 'flock -n /tmp/fold\.lock'; run --dry-run
eq  "control: with that line removed, the same host deploys" 0 "$RC"

drop_supervision() { rm -f "$1/bin/supervision.sh"; }
mkfix target_no_supervision drop_supervision
run_refusal "a release with no bin/supervision.sh" "bin/supervision.sh is missing or empty at" --dry-run

# The target's copy is evaluated in a FRESH bash process. Sourced into this one, which already holds the serving
# copy's supervision_* functions, a release that renamed one would silently run the serving copy's here — a green
# dry run — and meet its own only in the window, with the app down. The single-variable control is the CONTROL
# fixture at the top of this file: the same tree with the function not renamed.
rename_plan() { sed -i 's/^supervision_install_plan() {/supervision_install_plan_renamed() {/' "$1/bin/supervision.sh"; }
mkfix target_renames_plan rename_plan
eq "renamed plan: the mutator really renamed it" 0 "$(git -C "$SRC" show HEAD:bin/supervision.sh | grep -c '^supervision_install_plan() {')"
run_refusal "a release whose bin/supervision.sh no longer defines supervision_install_plan" "defines no supervision_install_plan" --dry-run

section "REFUSAL — PHP-FPM must pick up new code without a reload (A14)"
mkfix fpm_timestamps_off; export STUB_OPCACHE_VALIDATE=Off
run_refusal "opcache.validate_timestamps=Off in the FPM ini" "opcache.validate_timestamps is off for [178815168175465]" --dry-run
has "timestamps off: says the previous release would keep serving" "would go on serving the PREVIOUS release" "$OUT"
export STUB_OPCACHE_VALIDATE=On; run --dry-run
eq "control: the same host with validate_timestamps=On deploys" 0 "$RC"

mkfix fpm_pool_override
printf 'php_admin_flag[opcache.validate_timestamps] = off\n' >> "$POOL"
run_refusal "the deploy user's POOL turns timestamps off (the ini says On)" "opcache.validate_timestamps is off for [178815168175465]" --dry-run

mkfix fpm_opcache_off; export STUB_OPCACHE_ENABLE=Off STUB_OPCACHE_VALIDATE=Off
run --dry-run
eq  "control: opcache OFF, timestamps off, deploys — nothing is cached" 0 "$RC"
has "control: and says why that is safe" "opcache is off, so every request reads the disk" "$OUT"

mkfix fpm_freq_override
printf 'php_value[opcache.revalidate_freq] = "7"\n' >> "$POOL"
run --dry-run
eq  "control: a pool's revalidate_freq override deploys" 0 "$RC"
has "control: and the wait follows the POOL's 7 s, not the ini's 0 s" "revalidates a changed file within 7 s" "$OUT"

mkfix fpm_preload; export STUB_OPCACHE_PRELOAD=/srv/preload.php
run_refusal "opcache.preload set" "opcache.preload is set for [178815168175465]" --dry-run

mkfix fpm_no_pool; sed -i 's/^user = .*/user = somebody-else/' "$POOL"
run_refusal "no FPM pool runs as the deploy user" "no PHP-FPM pool runs as $ME" --dry-run

mkfix fpm_bin_missing; export MEZZ_FPM_BIN=php-fpm-not-installed
run_refusal "the PHP-FPM binary is not there" "PHP-FPM binary 'php-fpm-not-installed' was not found" --dry-run

mkfix fpm_not_fpm; export MEZZ_FPM_BIN=php
run_refusal "a binary whose phpinfo is not the FPM SAPI's (the CLI)" "did not print an FPM phpinfo" --dry-run

section "REFUSAL — a .user.ini over the app's scripts (A14)"
# Control, mutant, control on ONE host: the document root with no .user.ini, with one turning timestamps
# off, and with it removed again.
mkfix uini_docroot
run --dry-run
eq  "control: a document root with no .user.ini deploys" 0 "$RC"
printf '; per-directory tuning\nopcache.validate_timestamps = Off ; saves a stat\n' > "$MEZZ_DOCROOT/.user.ini"
run_refusal "the document root's .user.ini turns timestamps off" \
  "opcache.validate_timestamps is off for [178815168175465] under $MEZZ_DOCROOT/.user.ini" --dry-run
rm -f "$MEZZ_DOCROOT/.user.ini"; run --dry-run
eq  "control: the same host with that .user.ini removed deploys" 0 "$RC"
printf 'opcache.validate_timestamps=\n' > "$MEZZ_DOCROOT/.user.ini"
run_refusal "an EMPTY validate_timestamps in a .user.ini (PHP reads it as off)" "is off for [178815168175465] under" --dry-run

uini_release_off() { mkdir -p "$1/server/public"; printf 'opcache.validate_timestamps=0\n' > "$1/server/public/.user.ini"; }
mkfix uini_release_off uini_release_off
run_refusal "the RELEASE's server/public/.user.ini turns timestamps off (read from the target tree)" \
  "under server/public/.user.ini at" --dry-run
uini_release_freq() { mkdir -p "$1/server/public"; printf 'opcache.revalidate_freq = "9"\n' > "$1/server/public/.user.ini"; }
mkfix uini_release_freq uini_release_freq
run --dry-run
eq  "control: a release whose .user.ini sets revalidate_freq deploys" 0 "$RC"
has "control: and the wait follows that 9 s, not the ini's 0 s" "revalidates a changed file within 9 s" "$OUT"

mkfix uini_no_docroot; export MEZZ_DOCROOT="$T/no-such-docroot"
run --dry-run
eq  "a document root that does not exist: still deploys (a warning, not a refusal)" 0 "$RC"
has "no document root: names it, and how to name the right one" "no document root at $T/no-such-docroot" "$OUT"
has "no document root: says MEZZ_DOCROOT"                     "MEZZ_DOCROOT" "$OUT"

section "REFUSAL — what is being deployed"
mkfix unreleased
gitc "$SRC" checkout -q -b hotfix "$V1"
printf 'x\n' > "$SRC/hotfix.txt"; gitc "$SRC" add -A >/dev/null
gitc "$SRC" commit -qm 'unreleased hotfix'; gitc "$SRC" push -q origin hotfix
run_refusal "ref not on main" "is not contained in origin/main" --dry-run --ref hotfix
run --dry-run --ref hotfix --allow-unreleased
eq  "control: --allow-unreleased deploys it" 0 "$RC"
has "control: and says so loudly"            "DEPLOYING UNRELEASED CODE" "$OUT"

mkfix same_sha; gitc "$ROOT" checkout -q --detach "$V2"
run_refusal "already deployed" "is already what is checked out" --dry-run
run --dry-run --redeploy
eq "control: --redeploy proceeds" 0 "$RC"

mig_alter_bare() {
  cat > "$1/server/database/migrations/2026_02_02_000000_add_col_to_events.php" <<'MIG'
<?php
return new class { public function up(): void {
    Schema::table('events', fn ($t) => $t->string('trace_id')->nullable());
} };
MIG
}
mig_alter_declared() {
  cat > "$1/server/database/migrations/2026_02_02_000000_add_col_to_events.php" <<'MIG'
<?php
// ALGORITHM=INSTANT — nullable column added at the end of `events` (FLEET-STATE.md § 6.9 rule 1).
return new class { public function up(): void {
    Schema::table('events', fn ($t) => $t->string('trace_id')->nullable());
} };
MIG
}
mkfix mig_bare mig_alter_bare
run_refusal "undeclared ALTER on events" "without stating an ALGORITHM" --dry-run
has "undeclared ALTER: names the file" "add_col_to_events.php" "$OUT"
mkfix mig_declared mig_alter_declared      # single-variable control: only the comment differs
run --dry-run
eq "control: the SAME migration with ALGORITHM=INSTANT passes" 0 "$RC"

env_drift() { printf 'NEW_FEATURE_TOKEN=\n' >> "$1/server/.env.example"; }
mkfix env_drift_case env_drift
run --dry-run
eq  "config drift: still deploys (a warning, not a refusal)" 0 "$RC"
has "config drift: names the key the host does not set" "does not set: NEW_FEATURE_TOKEN" "$OUT"

no_lockfile() { rm -f "$1/server/package-lock.json"; }
mkfix no_lock no_lockfile
run_refusal "no npm lockfile" "package-lock.json is missing" --dry-run

trust_star() {
  printf '<?php return Application::configure()->trustProxies(at: "*")->create();\n' \
    > "$1/server/bootstrap/app.php"
}
mkfix trust_all trust_star
run_refusal "trustProxies('*')" "trusts ALL proxies" --dry-run

section "REFUSAL — the PHP floor (A6), DERIVED from the release being deployed"
# ⛔ card#9203, and this section is what that card exists for. A6 used to carry its OWN copy of
# the floor — `8.3*|8.4*|8.5*|9.*` — and that copy went on saying ^8.3 for as long as the
# committed `composer.lock` could only be installed on >=8.4.1. So on an 8.3 host A6 PASSED, the
# maintenance window OPENED, the app went DOWN, and `composer install` then failed inside it:
# deploy exit 2, marker on disk, nothing rolled back. The guard was not broken — it was faithfully
# enforcing a claim that had stopped being true. It now READS `server/composer.json` out of the
# release being deployed, and every case below moves the DECLARATION and the HOST independently,
# so a green is evidence that the refusal follows the declaration and not a literal.
#
# It also sits here, after A8/A9, rather than up with the .env checks: A6 needs the resolved $SHA,
# because the floor that matters is the TARGET release's. And it runs BEFORE A13/A14, whose stubs
# (a php-fpm for every minor used here, an installed crontab) are in place anyway — so on these
# cases A6 is the only thing that can refuse, and the exit code cannot read 1 with it gutted.

# THE CARD'S OWN SCENARIO: one minor below the floor the release declares.
mkfix php_below_floor; STUB_PHP_VERSION=8.3.33
run_refusal "PHP 8.3.33 under a ^8.4.1 floor" "does not satisfy server/composer.json's ^8.4.1" --dry-run
unlogged "PHP below the floor: composer install never ran"             "composer install"
has "PHP below the floor: says the failure was moved out of the window" "with the app already down" "$OUT"

# One PATCH below it. `^8.4.1` is not `^8.4`, and a check comparing only minors would pass this —
# which is exactly the difference the ratified floor turns on.
mkfix php_below_patch; STUB_PHP_VERSION=8.4.0
run_refusal "PHP 8.4.0 under a ^8.4.1 floor" "does not satisfy server/composer.json's ^8.4.1" --dry-run

# CONTROL — exactly AT the floor, one variable from the case above.
mkfix php_at_floor; STUB_PHP_VERSION=8.4.1; run --dry-run
eq  "control: PHP 8.4.1 is exactly the floor, and deploys" 0 "$RC"
has "control: names the constraint it read and where from" "PHP 8.4.1 satisfies ^8.4.1, declared by server/composer.json" "$OUT"

# CONTROL — above the floor on a LATER minor. The old case list allowed that by ENUMERATING
# `8.5*`; this allows it by EVALUATING `^8.4.1`, and the FPM binary A14 reads follows the host.
mkfix php_above_floor; STUB_PHP_VERSION=8.5.4
run --dry-run
eq     "control: PHP 8.5.4 satisfies ^8.4.1"                         0 "$RC"
logged "control: the FPM binary is derived from the host's PHP"      "php-fpm8.5 -i"
unlogged "control: and not from a literal minor"                     "php-fpm8.4 -i"

# ⛔ THE CEILING, which the old case list got wrong in the OTHER direction: it listed `9.*`, and
# `^8.4.1` has never allowed 9. A restated constraint drifts both ways at once, and nothing in
# the tree read both copies.
mkfix php_above_ceiling; STUB_PHP_VERSION=9.0.0
run_refusal "PHP 9.0.0 is above a ^8.4.1 ceiling" "does not satisfy server/composer.json's ^8.4.1" --dry-run

# ⛔ READ FROM THE TARGET TREE, NOT THE HOST'S CHECKOUT. The mutator runs before the SECOND
# commit, so the prod checkout still declares ^8.4.1 while the release being deployed declares
# ^8.5. A host on 8.4.7 satisfies what it is RUNNING and not what it is being asked to run — and
# the floor-raising deploy is precisely the one where reading the wrong tree opens the window and
# only then discovers the new lock will not install.
raise_floor() { write_composer_json "$1" '^8.5'; }
mkfix floor_raised_by_release raise_floor
STUB_PHP_VERSION=8.4.7
run_refusal "a release that RAISES the floor, on a host below the NEW one" \
  "does not satisfy server/composer.json's ^8.5" --dry-run
hasnt "raised floor: it did NOT read the checkout's own ^8.4.1" "composer.json's ^8.4.1" "$OUT"

# CONTROL — the same release, on a host that meets the raised floor.
mkfix floor_raised_ok raise_floor; STUB_PHP_VERSION=8.5.4
run --dry-run
eq "control: the same raised floor deploys on 8.5.4" 0 "$RC"

# A constraint the check cannot evaluate REFUSES rather than guessing: a floor check that misreads
# its constraint is the defect this card filed, not a fix for it.
weird_floor() { write_composer_json "$1" '>= 8.4.1 || banana'; }
mkfix floor_unparseable weird_floor
run_refusal "a PHP constraint A6 cannot evaluate" "cannot evaluate" --dry-run

# ⛔ And an ALTERNATION is refused, not half-read. `^8.4.1 || ^9.0` carries a prefix the reader
# recognises, and taking the `^8.4.1` while dropping the `|| ^9.0` would silently narrow a
# constraint the project deliberately widened — a misread floor is this card's defect, not its fix.
alternation_floor() { write_composer_json "$1" '^8.4.1 || ^9.0'; }
mkfix floor_alternation alternation_floor; STUB_PHP_VERSION=9.0.0
run_refusal "an alternation A6 will not half-read" "cannot evaluate" --dry-run
hasnt "alternation: it did NOT quietly read it as ^8.4.1" "satisfies ^8.4.1" "$OUT"

# No constraint at all is a refusal too, never a silent skip.
no_php_require() {
  cat > "$1/server/composer.json" <<'COMPOSER'
{
    "name": "selftest/fixture",
    "require": {
        "laravel/framework": "^13.17"
    }
}
COMPOSER
}
mkfix floor_absent no_php_require
run_refusal "no require.php in the release's composer.json" "no \`require.php\` in server/composer.json" --dry-run

drop_composer_json() { rm -f "$1/server/composer.json"; }
mkfix floor_file_absent drop_composer_json
run_refusal "no server/composer.json in the release at all" "server/composer.json is missing or empty at" --dry-run

mkfix internal_flag
export MEZZ_DEPLOY_IN_WINDOW=0; run --internal-post-checkout "$V2"
eq "post-checkout entry point by hand: exit 1" 1 "$RC"
has "post-checkout entry point: says why" "not an operator entry point" "$OUT"

# ══════════════════════════════════════════════════════════════════════════════════════════════
section "NO ROOT — nothing in the deploy path escalates or reaches systemd"
# Every line of both scripts with its comment removed — a whole-line `#` comment, and a trailing one
# after whitespace — and each word matched ANYWHERE that is left: as a command, in a quoted string, in a
# default expansion or in a `command -v`. What it cannot see is a word inside a string that itself
# holds ` #`, which it reads as a comment. The mutants are deploy.sh with one real line replaced, so a
# clean result is a check that CAN see each form; the control is that same line with the word only in
# its trailing comment.
escalations() { sed 's/[[:space:]]#.*$//' "$1" | grep -nE '(^|[^[:alnum:]_])(sudo|systemctl|pkexec|doas|su)([^[:alnum:]_]|$)' | grep -vE '^[0-9]+:[[:space:]]*#'; }
eq "deploy.sh: no non-comment line names sudo, su, doas, pkexec or systemctl" "" "$(escalations "$DEPLOY")"
eq "supervision.sh: likewise"                                               "" "$(escalations "$HERE/supervision.sh")"
mutate_restart() { sed "s|^  php artisan queue:restart .*|  $1|" "$DEPLOY" > "$T/deploy.mutant.sh"; }
mutate_restart 'sudo -n systemctl restart php8.5-fpm'
neq "mutant: the check sees an injected sudo/systemctl command" "" "$(escalations "$T/deploy.mutant.sh")"
for form in 'ESC="sudo"' 'X="${ESCALATE:-sudo}"' 'ESC="$(command -v sudo)"'; do
  mutate_restart "$form"
  neq "mutant: the check sees $form" "" "$(escalations "$T/deploy.mutant.sh")"
done
mutate_restart 'php artisan queue:restart   # never sudo here'
eq  "control: the word only in a trailing comment is not flagged" "" "$(escalations "$T/deploy.mutant.sh")"

section "THE SUPERVISED SET — bin/supervision.sh against FLEET-STATE.md § 2.1"
# § 2.1 is what a host is provisioned from, and bin/supervision.sh is what one is supervised from.
# The rows keyed on here are § 2.1's `long-lived daemon (`mezzanine:…`)` Kind cells.
DOC="$REPO/docs/design/FLEET-STATE.md"
doc_daemons() { awk '/^### 2\.1 /{ s = 1; next } /^### /{ s = 0 } s' "$1" | grep -oE 'long-lived daemon \(`mezzanine:[a-z-]+`\)' | grep -oE 'mezzanine:[a-z-]+' | sort; }
SET="$(printf '%s\n' "${SUPERVISED_DAEMONS[@]}" | sort)"
neq "control: § 2.1 names at least one long-lived daemon (the comparison is not vacuous)" "" "$(doc_daemons "$DOC")"
eq  "§ 2.1's long-lived daemon rows ARE bin/supervision.sh's set" "$SET" "$(doc_daemons "$DOC")"
sed 's/| \*\*purge\*\* | scheduled command (`mezzanine:purge`)/| **purge** | long-lived daemon (`mezzanine:purge`)/' "$DOC" > "$T/fleet-state.mutant.md"
neq "mutant: a § 2.1 that gains a daemon row no longer matches" "$SET" "$(doc_daemons "$T/fleet-state.mutant.md")"

section "bin/supervision.sh install — the one documented way to install the crontab"
mkfix install_cases
F="$STUB_CRONTAB_FILE"; BLOCK="$(supervision_render "$ROOT" "$T/bin/php")"
inst() { : > "$CALL_LOG"; INS="$("$ROOT/bin/supervision.sh" install --root "$ROOT" --php "$T/bin/php" 2>&1)"; IRC=$?; }

rm -f "$F"; inst
eq "install into NO crontab at all: exit 0"          0 "$IRC"
eq "install: the crontab is exactly the rendered block" "$BLOCK" "$(cat "$F")"

printf 'MAILTO=ops@example.invalid\n0 3 * * * /usr/local/bin/backup\n' > "$F"
inst; FIRST="$(cat "$F")"; inst; SECOND="$(cat "$F")"
eq  "install beside other lines: exit 0"              0 "$IRC"
has "install: keeps a line it does not manage"        "0 3 * * * /usr/local/bin/backup" "$SECOND"
has "install: keeps MAILTO"                           "MAILTO=ops@example.invalid" "$SECOND"
eq  "install: a second install changes nothing"       "$FIRST" "$SECOND"
eq  "install: one managed block, not two"             1 "$(grep -c '^# BEGIN mezzanine-supervision ' "$F")"

supervision_render "/srv/other-checkout" "$T/bin/php" >> "$F"; inst
eq  "install beside ANOTHER checkout's block: exit 0 (it is not a duplicate)" 0 "$IRC"
has "install: keeps the other checkout's block" "# BEGIN mezzanine-supervision /srv/other-checkout" "$(cat "$F")"

printf '* * * * * cd /home/x/server && flock -n /tmp/fold.lock /usr/bin/php8.5 artisan mezzanine:fold >> x 2>&1\n' >> "$F"
cp "$F" "$T/crontab.before"; inst
eq  "install beside a HAND-STAGED fold line: exit 1"   1 "$IRC"
has "hand-staged: names the line"                      "artisan mezzanine:fold >> x" "$INS"
eq  "hand-staged: the crontab is unchanged"            "$(cat "$T/crontab.before")" "$(cat "$F")"
unlogged "hand-staged: nothing was written"            "crontab -$"
grep -v 'flock -n /tmp/fold.lock' "$F" > "$F.new"; mv "$F.new" "$F"; inst
eq "control: with that line removed, install succeeds" 0 "$IRC"

export STUB_CRONTAB_BROKEN="cannot open crontab: Permission denied"; cp "$F" "$T/crontab.before"; inst
eq  "install when crontab -l fails (not 'no crontab'): exit 1" 1 "$IRC"
has "unreadable: says it would not write over it"      "not because the crontab is empty" "$INS"
unlogged "unreadable: nothing was written"             "crontab -$"
eq  "unreadable: the crontab is unchanged"             "$(cat "$T/crontab.before")" "$(cat "$F")"
unset STUB_CRONTAB_BROKEN

mkdir -p "$T/pct%root/server"; : > "$T/pct%root/server/artisan"
INS="$("$HERE/supervision.sh" install --root "$T/pct%root" --php "$T/bin/php" 2>&1)"; IRC=$?
eq  "install for a root cron cannot carry ('%'): exit 1" 1 "$IRC"
has "'%' root: says why"                               "cron cannot carry" "$INS"

# RELATIVE --root and --php: cron runs every entry from the account's HOME, so they must be installed
# canonical. Control (absolute), mutant (relative, from the fixture's directory), control (absolute again).
rm -f "$F"; inst
eq "control: an absolute install is the rendered block"  "$BLOCK" "$(cat "$F")"
rm -f "$F"; INS="$(cd "$T/install_cases" && "$ROOT/bin/supervision.sh" install --root root --php ../bin/php 2>&1)"; IRC=$?
eq "install with RELATIVE --root and --php: exit 0"      0 "$IRC"
eq "relative: installed as the block for the ABSOLUTE paths" "$BLOCK" "$(cat "$F")"
inst
eq "control: an absolute install after it leaves one managed block, not two" 1 "$(grep -c '^# BEGIN mezzanine-supervision ' "$F")"

# ══════════════════════════════════════════════════════════════════════════════════════════════
section "THE FULL RUN — the window, the order, the daemons, the close"
mkfix full_run
start_old_daemons
run
eq "full run: exit 0"                                   0 "$RC"
eq "full run: HEAD moved to the target commit"          "$V2" "$(git -C "$ROOT" rev-parse HEAD)"
has "full run: reports success"                         "✔ DEPLOYED" "$OUT"
eq "full run: the failure marker is gone"               "absent" "$([ -e "$ROOT/.deploy-failed" ] && echo present || echo absent)"
logged   "full run: opened the window"                  "artisan down"
logged   "full run: closed the window"                  "artisan up"
logged   "full run: restarted queue workers"            "artisan queue:restart"
eq       "full run: read the FPM posture in phase A AND again in the window" 2 "$(grep -c 'php-fpm8.4 -i' "$CALL_LOG")"
logged   "full run: forward-only — never rolls back"    "artisan migrate --force"
unlogged "full run: forward-only — no rollback"         "migrate:rollback"
neq "control: old daemons were running before the deploy" "" "${OLD_PIDS// /}"
for pid in $OLD_PIDS; do
  eq "full run: the previous daemon pid $pid is gone" "gone" "$(kill -0 "$pid" 2>/dev/null && echo alive || echo gone)"
done
for c in "${SUPERVISED_DAEMONS[@]}"; do
  logged "full run: relaunched $c with cron's command" "php artisan $c"
  NEW="$(fuser "$(supervision_lock "$ROOT" "$c")" 2>/dev/null)"
  neq "full run: a process holds $c's lock after the deploy" "" "${NEW// /}"
  for pid in $NEW; do
    hasnt "full run: $c's lock holder $pid is not a previous pid" " $pid " "$OLD_PIDS "
  done
done
has "full run: the proof names the new pids alive a settle later" "started after the restart and still hold its lock 2 s later" "$OUT"
has "full run: PHP-FPM was not reloaded, and says what replaced it" "since the last code write" "$OUT"
eq  "full run: lock and log files leave the checkout clean (git-ignored)" "" "$(git -C "$ROOT" status --porcelain)"
before "order: down before anything is built"           "artisan down" "composer install"
before "order: composer before npm"                     "composer install" "npm ci"
before "order: npm ci before the asset build"           "npm ci" "npm run build"
before "order: caches CLEARED before migrate"           "optimize:clear" "artisan migrate"
before "order: migrate before the caches are rebuilt"   "artisan migrate" "config:cache"
before "order: config:cache first of the four"          "config:cache" "route:cache"
before "order: caches rebuilt before the daemons"       "event:cache" "php artisan mezzanine:fold"
before "order: the release's crontab block installed before the daemons are relaunched" "crontab -$" "php artisan mezzanine:fold"
before "order: daemons relaunched before the app is up" "php artisan mezzanine:fold" "artisan up"
before "order: smoke check after the app is up"         "artisan up" "curl "
OUTL="$(printf '%s\n' "$OUT" | grep -n -e 'since the last code write' -e 'Maintenance window: CLOSING' | cut -d: -f1 | tr '\n' ' ')"
eq  "order: the opcache wait ends before the window closes" "ascending" "$(set -- $OUTL; [ $# -eq 2 ] && [ "$1" -lt "$2" ] && echo ascending || echo "lines: $OUTL")"
hasnt "full run leaks no APP_KEY"                       "$FAKE_KEY" "$OUT"
hasnt "full run leaks no DB password"                   "$FAKE_PW"  "$OUT"
hasnt "full run never mentions systemctl"               "systemctl" "$OUT"

section "THE OPCACHE WAIT — seen to fail against a release that skips it"
# revalidate_freq=3 and no daemon settle, so the only thing that can make ≥ 4 s pass between the
# last code write and `up` is the wait. The mutant is the RELEASE's own deploy.sh with the sleep cut
# out — the re-exec runs it — and a clean result from it would be a check that cannot fail.
waited() { printf '%s\n' "$OUT" | sed -n 's/.*and \([0-9]*\) s have passed since the last code write.*/\1/p'; }
mkfix fpm_wait; export STUB_OPCACHE_FREQ=3 MEZZ_DAEMON_SETTLE_S=0
run
eq  "wait control: exit 0"                               0 "$RC"
has "wait control: a host whose daemons were already dead still gets them relaunched" "stopped — pid(s) none were running" "$OUT"
eq  "wait control: ≥ revalidate_freq + 1 s passed before up" "yes" "$([ "$(waited)" -ge 4 ] 2>/dev/null && echo yes || echo "no ($(waited))")"
cut_wait() { sed -i 's/^  if \[ "\$wait_s" -gt 0 \]; then sleep "\$wait_s"; fi$/  : wait cut out by the selftest mutant/' "$1/bin/deploy.sh"; }
mkfix fpm_wait_cut cut_wait; export STUB_OPCACHE_FREQ=3 MEZZ_DAEMON_SETTLE_S=0
run
eq  "wait mutant: the mutator really cut the wait"        1 "$(git -C "$SRC" show HEAD:bin/deploy.sh | grep -c 'wait cut out by the selftest mutant')"
eq  "wait mutant: less than revalidate_freq + 1 s passed" "yes" "$([ "$(waited)" -lt 4 ] 2>/dev/null && echo yes || echo "no ($(waited))")"

section "THE OPCACHE WAIT — after a release that LOWERS revalidate_freq in its .user.ini"
# The serving release's server/public/.user.ini says 4 s; the release lowers it to 1 s. FPM keeps a directory's
# .user.ini values for user_ini.cache_ttl, so a worker can go on revalidating at the previous release's 4 s after the
# checkout — the wait must be the longer of the two. Phase B reads only the new file on disk; the previous one's
# value comes from phase A, handed over as a floor. The mutant is the release's own deploy.sh with that floor cut.
uini_freq() { mkdir -p "$1/server/public"; printf 'opcache.revalidate_freq = %s\n' "$2" > "$1/server/public/.user.ini"; }
uini_freq_4() { uini_freq "$1" 4; }
uini_freq_1() { uini_freq "$1" 1; }
mkfix fpm_floor uini_freq_1 uini_freq_4; export MEZZ_DAEMON_SETTLE_S=0
run
eq  "floor: exit 0"                                                    0 "$RC"
has "floor: phase A read the previous release's 4 s"                  "revalidates a changed file within 4 s" "$OUT"
eq  "floor: ≥ the PREVIOUS release's revalidate_freq + 1 s passed before up" "yes" "$([ "$(waited)" -ge 5 ] 2>/dev/null && echo yes || echo "no ($(waited))")"
cut_floor() {
  uini_freq_1 "$1"
  sed -i 's/^  if \[ "\$floor" -gt "\$wait_for" \]; then wait_for="\$floor"; fi$/  : floor cut out by the selftest mutant/' "$1/bin/deploy.sh"
}
mkfix fpm_floor_cut cut_floor uini_freq_4; export MEZZ_DAEMON_SETTLE_S=0
run
eq  "floor mutant: the mutator really cut the floor"                   1 "$(git -C "$SRC" show HEAD:bin/deploy.sh | grep -c 'floor cut out by the selftest mutant')"
eq  "floor mutant: less than the previous release's revalidate_freq + 1 s passed" "yes" "$([ "$(waited)" -lt 5 ] 2>/dev/null && echo yes || echo "no ($(waited))")"

section "IN-WINDOW FAILURE — down and stay down, and a bare re-run refuses"
mkfix migrate_fails
STUB_FAIL_RE='artisan migrate'; run
eq  "migrate fails: exit 2 (not 1 — this one broke)"    2 "$RC"
has "migrate fails: the banner is unmissable"           "THE APP IS DOWN AND STAYS DOWN" "$OUT"
has "migrate fails: names the step"                     "php artisan migrate --force" "$OUT"
unlogged "migrate fails: the app is NEVER brought up"   "artisan up"
unlogged "migrate fails: nothing was rolled back"       "migrate:rollback"
eq  "migrate fails: the marker is on disk"              "present" "$([ -e "$ROOT/.deploy-failed" ] && echo present || echo absent)"
eq  "migrate fails: HEAD did move (the checkout landed)" "$V2" "$(git -C "$ROOT" rev-parse HEAD)"
# THE TRAP FAMILY, exercised: the obvious operator reflex is to run it again. It must refuse.
run --redeploy
eq  "bare re-run after a failure: exit 1 (refused)"     1 "$RC"
has "bare re-run: names the unreviewed failure"         "a previous deploy failed and has not been reviewed" "$OUT"
unlogged "bare re-run: did not reopen the window"       "artisan down"

section "IN-WINDOW FAILURE — the daemon restart, each way it can go wrong"
mkfix daemon_dies; printf 'mezzanine:fold\n' > "$T/knobs/dies_after_start"
run
eq  "dead daemon: exit 2"                               2 "$RC"
has "dead daemon: names it, and its lock held by nothing" "mezzanine:fold: nothing holds" "$OUT"
has "dead daemon: …the daemon died on start"            "the daemon died on start" "$OUT"
unlogged "dead daemon: the app is NEVER brought up"     "artisan up"

mkfix daemon_ignores_term; printf 'mezzanine:sweep\n' > "$T/knobs/ignores_term"
start_old_daemons; : > "$T/knobs/ignores_term"
run
eq  "a previous daemon that ignores SIGTERM: exit 2"     2 "$RC"
has "ignores SIGTERM: says so, within the stop timeout"  "did not exit within 3 s of SIGTERM" "$OUT"
unlogged "ignores SIGTERM: the app is NEVER brought up"  "artisan up"

# The age proof, seen to fail: a release whose own deploy.sh finds NO previous holders — the bug
# class of a snapshot reading the wrong lock. Nothing is signalled and nothing is waited for (cutting
# only the kill is caught earlier, by the stop timeout above), the previous daemons keep their locks,
# cron's command exits at once beside them, and only the proof that each holder STARTED AFTER the
# restart can tell that nothing restarted. They are given 2 s of age first, because a real previous
# daemon has been running since the last deploy, not since this second.
cut_snapshot() { sed -i 's/^    read -r -a hs <<< "\$(checkout_lock_holders)"$/    hs=() # snapshot cut out by the selftest mutant/' "$1/bin/deploy.sh"; }
mkfix daemon_snapshot_cut cut_snapshot
eq "snapshot mutant: the mutator really cut the snapshot" 1 "$(git -C "$SRC" show HEAD:bin/deploy.sh | grep -c 'snapshot cut out by the selftest mutant')"
start_old_daemons; sleep 2
run
eq  "snapshot mutant: exit 2"                                2 "$RC"
has "snapshot mutant: the lock holder predates the restart"  "BEFORE this restart — it is running the previous release's code" "$OUT"
unlogged "snapshot mutant: the app is NEVER brought up"      "artisan up"
# …and the same mutant under a ps that prints no age. A holder whose start cannot be read cannot be proven fresh: read
# as started this very second, this mutant deployed green naming a previous pid as a new one.
mkfix daemon_snapshot_cut_blind_ps cut_snapshot
start_old_daemons; sleep 2; : > "$T/knobs/blind_ps"
run
eq  "blind ps mutant: exit 2"                                  2 "$RC"
has "blind ps mutant: says the holder's start cannot be read"  "cannot read when pid" "$OUT"
hasnt "blind ps mutant: no success line"                       "✔ DEPLOYED" "$OUT"
unlogged "blind ps mutant: the app is NEVER brought up"        "artisan up"

section "IN-WINDOW — cron's losing flock, sampled beside a live daemon, is not a daemon that died"
# cron's minute tick runs `flock -n` against the lock the running daemon holds, and the loser has the
# lock file open for as long as it takes to fail — so fuser lists it. Made observable here: a
# `flock -w 1` starts beside the relaunched fold and is gone a second later, and fuser is slowed 0.3 s
# so the first sighting of the lock certainly includes it. A proof that followed the pids of that first
# sighting would call the fold dead at the settle. The fold is alive.
mkfix loser_sampled; printf 'mezzanine:fold\n' > "$T/knobs/transient_loser"; : > "$T/knobs/slow_fuser"
run
eq    "transient loser: exit 0 (the fold did not die)"     0 "$RC"
hasnt "transient loser: not reported as died on start"     "died on start" "$OUT"
rm -f "$T/knobs/slow_fuser"; : > "$T/knobs/transient_loser"

section "ACROSS RELEASES — the deployed release's supervision, not the serving release's"
# The serving release runs phase A; the deployed release's bin/supervision.sh is the one that knows what it supervises,
# and the lock files — which no release may move — say what is RUNNING. (a) a release that ADDS a daemon must leave
# cron an entry for it; (b) one that DROPS a daemon must still stop it; (c) one that would MOVE the locks is refused
# before anything is touched; (d) a re-run after a window that failed with the previous daemons still up must stop
# them — and, after a stop that timed out, must not go green while one still runs.
add_daemon() { sed -i 's/^SUPERVISED_DAEMONS=(\(.*\))$/SUPERVISED_DAEMONS=(\1 mezzanine:extra)/' "$1/bin/supervision.sh"; }
mkfix release_adds_daemon add_daemon
eq  "adds a daemon: the mutator really added it to the release's list" 1 "$(git -C "$SRC" show HEAD:bin/supervision.sh | grep -c '^SUPERVISED_DAEMONS=(.* mezzanine:extra)$')"
EXTRA_CMD="$(supervision_command "$ROOT" "$T/bin/php" mezzanine:extra)"
run --dry-run
eq  "adds a daemon, dry run: exit 0"                               0 "$RC"
has "adds a daemon, dry run: names the entry the window will add" "+ * * * * * $EXTRA_CMD" "$OUT"
unlogged "adds a daemon, dry run: the crontab was not written"    "crontab -$"
start_old_daemons
run
eq  "adds a daemon: exit 0"                                       0 "$RC"
has "adds a daemon: the crontab carries its every-minute entry"   "* * * * * $EXTRA_CMD" "$(cat "$STUB_CRONTAB_FILE")"
has "adds a daemon: …and its @reboot entry"                       "@reboot $EXTRA_CMD" "$(cat "$STUB_CRONTAB_FILE")"
neq "adds a daemon: a process holds its lock"                     "" "$(fuser "$ROOT/server/storage/framework/daemon-extra.lock" 2>/dev/null | tr -d ' ')"
for pid in $OLD_PIDS; do
  eq "adds a daemon: the previous daemon pid $pid is gone" "gone" "$(kill -0 "$pid" 2>/dev/null && echo alive || echo gone)"
done

# (b) The release drops the LAST daemon of the list. Nothing in the deployed release names it; only its lock file says
# it is running.
DROPPED="${SUPERVISED_DAEMONS[${#SUPERVISED_DAEMONS[@]}-1]}"
drop_daemon() { sed -i "s/^\(SUPERVISED_DAEMONS=(.*\) $DROPPED)\$/\1)/" "$1/bin/supervision.sh"; }
dropped_lock() { supervision_lock "$ROOT" "$DROPPED"; }
mkfix release_drops_daemon drop_daemon
eq "drops a daemon: the mutator really dropped $DROPPED from the release's list" 0 "$(git -C "$SRC" show HEAD:bin/supervision.sh | grep -c "^SUPERVISED_DAEMONS=(.*$DROPPED")"
start_old_daemons
run
eq  "drops a daemon: exit 0"                                       0 "$RC"
for pid in $OLD_PIDS; do
  eq "drops a daemon: the previous daemon pid $pid is gone" "gone" "$(kill -0 "$pid" 2>/dev/null && echo alive || echo gone)"
done
eq    "drops a daemon: nothing holds its lock"                     "" "$(fuser "$(dropped_lock)" 2>/dev/null | tr -d ' ')"
has   "drops a daemon: the proof names its lock, held by nothing"  "nothing holds $(dropped_lock)" "$OUT"
hasnt "drops a daemon: the crontab no longer runs it"              "artisan $DROPPED " "$(cat "$STUB_CRONTAB_FILE")"

# The checkout-wide stop, seen to fail: the same release, with its own deploy.sh stopping only the locks IT names. The
# dropped daemon survives beside the new ones, every lock the release names is held by a fresh process — and only the
# proof that every other lock file is held by nothing can tell.
cut_checkout_stop() {
  drop_daemon "$1"
  sed -i 's/^    read -r -a hs <<< "\$(checkout_lock_holders)"$/    read -r -a hs <<< "$(lock_holders "${target_locks[@]}")" # checkout-wide stop cut out by the selftest mutant/' "$1/bin/deploy.sh"
}
mkfix checkout_stop_cut cut_checkout_stop
eq  "checkout-stop mutant: the mutator really cut it" 1 "$(git -C "$SRC" show HEAD:bin/deploy.sh | grep -c 'checkout-wide stop cut out by the selftest mutant')"
start_old_daemons
run
eq  "checkout-stop mutant: exit 2"                              2 "$RC"
has "checkout-stop mutant: names the lock still held"           "still hold $(dropped_lock)" "$OUT"
unlogged "checkout-stop mutant: the app is NEVER brought up"   "artisan up"

# (c) The second review's reproduction: a release that moves the lock files, and whose migrate then fails. Deployed, its
# window stopped nothing the previous release started; the re-run, told to install, went green with every previous
# daemon alive and holding both locks. The move is refused before the window opens, so nothing of that follows.
move_locks() { sed -i "s|daemon-%s\\.lock'|daemon-%s.v2.lock'|" "$1/bin/supervision.sh"; }
mkfix release_moves_locks move_locks
eq "moves the locks: the mutator really moved them" 1 "$(git -C "$SRC" show HEAD:bin/supervision.sh | grep -c "daemon-%s.v2.lock'")"
start_old_daemons
STUB_FAIL_RE='artisan migrate'; run
eq  "moves the locks: exit 1 — refused, nothing touched"          1 "$RC"
has "moves the locks: says the release would move them"           "would move the daemons' lock files" "$OUT"
has "moves the locks: names where the release would put them"     "$ROOT/server/storage/framework/daemon-*.v2.lock" "$OUT"
unlogged "moves the locks: never opened the window"               "artisan down"
eq  "moves the locks: HEAD did not move"                          "$V1" "$(git -C "$ROOT" rev-parse HEAD)"
neq "control: old daemons were running before the deploy"         "" "${OLD_PIDS// /}"
for pid in $OLD_PIDS; do
  eq "moves the locks: the serving daemon pid $pid is untouched" "alive" "$(kill -0 "$pid" 2>/dev/null && echo alive || echo gone)"
done

section "RECOVERY — a re-run after a window that failed stops what is RUNNING, not what HEAD names"
# (d) The window failed at migrate, before the restart: HEAD is the new release, the previous release's crontab block
# is still installed and its daemons are still up. The operator reviews and clears the marker, and re-runs. That
# re-run's serving copy is HEAD's, whose list does not name the daemon the release dropped — only its lock file does.
mkfix recover_after_failure drop_daemon
start_old_daemons
STUB_FAIL_RE='artisan migrate'; run
eq "recovery: the first run fails in the window (exit 2)" 2 "$RC"
for pid in $OLD_PIDS; do
  eq "recovery: the previous daemon pid $pid still runs after the failed window" "alive" "$(kill -0 "$pid" 2>/dev/null && echo alive || echo gone)"
done
rm -f "$ROOT/.deploy-failed"; STUB_FAIL_RE=''
run --redeploy
eq  "recovery: the re-run deploys (exit 0)"                   0 "$RC"
for pid in $OLD_PIDS; do
  eq "recovery: the previous daemon pid $pid is gone" "gone" "$(kill -0 "$pid" 2>/dev/null && echo alive || echo gone)"
done
eq  "recovery: nothing holds the dropped daemon's lock"       "" "$(fuser "$(dropped_lock)" 2>/dev/null | tr -d ' ')"

# (d') A previous daemon that ignores SIGTERM — the one the release dropped. The window times out; the re-run must time
# out on it again rather than go green beside it; once the operator has killed it, the re-run deploys.
mkfix stop_timeout_rerun drop_daemon
printf '%s\n' "$DROPPED" > "$T/knobs/ignores_term"; start_old_daemons; : > "$T/knobs/ignores_term"
run
eq  "stop timeout: exit 2"                                      2 "$RC"
has "stop timeout: says so"                                     "did not exit within 3 s of SIGTERM" "$OUT"
rm -f "$ROOT/.deploy-failed"
run --redeploy
eq    "stop timeout, re-run: exit 2 again — the stubborn daemon still holds its lock" 2 "$RC"
has   "stop timeout, re-run: says so"                           "did not exit within 3 s of SIGTERM" "$OUT"
hasnt "stop timeout, re-run: no success line beside it"         "✔ DEPLOYED" "$OUT"
fuser -k -KILL "$(dropped_lock)" >/dev/null 2>&1
rm -f "$ROOT/.deploy-failed"
run --redeploy
eq  "stop timeout, after the operator killed it: exit 0"        0 "$RC"
for pid in $OLD_PIDS; do
  eq "stop timeout: the previous daemon pid $pid is gone" "gone" "$(kill -0 "$pid" 2>/dev/null && echo alive || echo gone)"
done

section "A RELATIVE MEZZ_DEPLOY_ROOT — canonical before anything renders it or re-execs through it"
mkfix relative_root
: > "$CALL_LOG"; OUT="$(cd "$T/relative_root" && MEZZ_DEPLOY_ROOT=root root/bin/deploy.sh --dry-run 2>&1)"; RC=$?
eq  "relative root, dry run: exit 0"                          0 "$RC"
has "relative root: the lock files are judged for the canonical path" "lock files at $ROOT/server/" "$OUT"
: > "$CALL_LOG"; OUT="$(cd "$T/relative_root" && MEZZ_DEPLOY_ROOT=root root/bin/deploy.sh 2>&1)"; RC=$?
eq  "relative root, full run: exit 0 (the re-exec, run from server/, found the checkout)" 0 "$RC"
eq  "relative root, full run: HEAD moved"                     "$V2" "$(git -C "$ROOT" rev-parse HEAD)"

section "POST-WINDOW — the smoke check is not the same failure"
mkfix smoke_fails
STUB_HTTP_CODE=503; run
eq  "smoke fails: exit 3 (up, but unverified)"          3 "$RC"
has "smoke fails: says the app IS serving"              "THE APP IS UP BUT" "$OUT"
logged "smoke fails: the window WAS closed"             "artisan up"
eq  "smoke fails: the marker stays, so the next run refuses" "present" "$([ -e "$ROOT/.deploy-failed" ] && echo present || echo absent)"

printf '\n──────────────────────────────────────────────\n'
if [ "$fails" -eq 0 ]; then
  printf 'deploy.selftest.sh: %d assertions, all passed\n' "$cases"; exit 0
fi
printf 'deploy.selftest.sh: %d assertions, %d FAILED\n' "$cases" "$fails" >&2; exit 1

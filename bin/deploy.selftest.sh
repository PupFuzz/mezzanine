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
#         behind one that one case blinds; mktemp, too, is the real one, behind a pass-through § card#9816
#         makes fail after a set number of calls; and git, behind one the card#9616 cases turn into a git
#         without `:(literal)` pathspec magic) against stub daemons holding locks inside the temp dir, so
#         "the old process is gone and a new one holds the lock" is observed, not merely recorded.
#   STUB: php (and the daemons it runs), php-fpm<minor>, composer, npm, crontab, curl, id, cgi-fcgi — on PATH,
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
#   - a `.user.ini` over the app's scripts (A14): absent, turning timestamps off, removed again;
#   - the feed stream's host conditions (A14, card#9300): each R1 ini hazard and each R2 pool defect
#     refused, each beside the same host without it;
#   - the stream drain (phase B): a stream the previous release opened ended by SIGTERM, one opened after
#     fleet.reload left alone — and the same run with the release's kill cut out, where it survives;
#   - the host's version floors (card#9616): the bash floor moved above this host's bash on the SERVING
#     copy and, separately, on the TARGET release alone — each beside the same fixture at a floor this
#     bash meets; a git without `:(literal)` in both of the ways one answers, beside a git that cannot
#     list a tree at all; and the release's lockfileVersion against npm 6.14.0 and npm 9.2.0;
#   - MEZZ_REMOTE (card#9832): a credential-bearing URL refused WITHOUT the value reaching the output,
#     beside a remote NAMED with that same string, which deploys and prints it — so the absence is a
#     measurement rather than a needle the output could never have carried; `backup@nas`, a legal
#     remote name, deploying, which is what reds the pattern match the membership test must not become;
#     and a value joining two ADJACENT remote names with a newline, beside each half deploying alone;
#   - a remote NAMED with a credential-bearing URL (card#9991): refused as MEZZ_REMOTE, and marked
#     rather than printed in any A3c refusal's list, beside the same token as a legal name, which the
#     list DOES print; and a legal name carrying `/`, `@`, `.` and `-` deploying, which is what reds a
#     predicate widened from the colon into a guess at what a URL looks like.
#
# ⛔ AND THE REFUSAL CONTRACT ITSELF, ASSERTED AT `run_refusal` RATHER THAN PER CASE (card#9646).
# A refusal is three things together — exit 1, the `⛔ REFUSED — <cause>` banner, and the closing
# `Nothing was changed. The previous release is still serving.` — and every one of them is met by a
# banner-less DEATH on a command phase A ran without reading its status: under `set -Eeuo pipefail`
# the script exits with that command's status, and git's exit 1 is the very code the exit table says
# means "refused, nothing was touched". This suite asserted only the exit code, so it could not tell
# the two apart. `run_refusal` now carries all three, which upgrades every case that uses it in one
# edit rather than one case at a time — `grep -c 'run_refusal '` counts them. The sites where the
# status was not being read (`--ref` with no value, A3, A4, A7's fetch) have cases of their own under
# § card#9646, each with the mutant it catches named in the case, and the ASYMMETRIC ones marked ⭐:
# a fetch fix that handles git's 1 and lets its 128 escape, and an A3 fix that matches `dubious
# ownership` and defaults everything else back to "not a git checkout", each pass every case but one.
#
# ⛔ AND A FIXTURE IS CHOSEN FOR THE WRONG FIXES IT REDS ON, NOT FOR THE RIGHT ONE IT PASSES
# (card#9610). § card#9610 is where that is most explicit: the loader could not tell a `.env` the
# kernel refused MID-READ from an empty one, because bash's `read` returns the same status at
# end-of-file and on a read error, and three different wrong fixes each pass an obvious fixture.
# `/proc/self/mem` — a regular file, mode 600, owned by this user, that `stat` calls size 0 and whose
# first byte cannot be read, with no root and no special mount — is the ONE input that reds on all
# three: on dropping the stderr capture, on discriminating with `[ -d "$ENV_FILE" ]` (which a
# DIRECTORY fixture passes), and on discriminating by length against `stat -c %s` (which reads it as
# empty). The directory case is beside it as a second shape, never as a substitute. Each wrong fix was
# BUILT and run in that card's build round, and each reds exactly where this says it does.
#
# ⛔ AND A CONDITION THIS RUNNER CANNOT PRODUCE IS NAMED, NEVER SKIPPED (card#9646). Several cases
# here depend on a state the suite has to MANUFACTURE — a file this user cannot open, an object at
# mode 000, a checkout git treats as another user's. Each asserts that state before it asserts
# anything about the refusal, so a runner that cannot hold it (a ROOT one opens every mode; a git
# that does not honour `GIT_TEST_ASSUME_DIFFERENT_OWNER`, or a `safe.directory` entry that covers
# the fixture, defeats the ownership one) reports the ABSENCE rather than certifying a refusal that
# never happened. Where the state is one this suite can still be sure it wants, that assertion is a
# RED. Where it depends on the runner's own git build or configuration, `notverified` prints
# ⚠ NOT VERIFIED HERE with what this runner answered and what would have to be true, the count is
# repeated in the summary, and the suite does not fail: the case did not run, and saying which one
# and why IS the result. ⛔ A silent skip is worse than either, because it is shaped like coverage.
#
# RUN: bin/deploy.selftest.sh          (exit 0 = every case passed; ⚠ lines name what did not run)

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
kill_streams() { # SIGKILL the stand-in stream workers a case started — they are listed in one knob file
  local pid started
  while read -r pid started; do [ -n "$pid" ] && kill -KILL "$pid" 2>/dev/null; done < "$T/knobs/streams" 2>/dev/null
  return 0
}
cleanup() {
  local r
  kill_streams
  for r in "$T"/*/root; do kill_daemons "$r"; done
  rm -rf "$T"
}
trap cleanup EXIT
export CALL_LOG="$T/calls.log"; : > "$CALL_LOG"
ME="$(/usr/bin/id -un)"

fails=0; cases=0; unverified=0
ok()  { printf '  ok   %s\n' "$1"; }
bad() { printf '  FAIL %s\n' "$1" >&2; fails=$((fails + 1)); }
# notverified <headline> <detail line…> — a CONDITION THIS RUNNER COULD NOT PRODUCE. It is not a
# pass and not a failure: the case did not run, and saying so BY NAME is the result (card#9646).
# ⛔ A SILENT SKIP IS WORSE THAN A RED, because it is shaped exactly like coverage. Every caller
# names the knob or the state it needed, what this runner answered instead, and what would have to
# be true to exercise it — so the next reader gets the measurement rather than a mystery. The
# summary at the bottom repeats the count, so it cannot scroll past unseen.
notverified() {
  unverified=$((unverified + 1))
  printf '  ⚠ NOT VERIFIED HERE  %s\n' "$1" >&2
  local l; for l in "${@:2}"; do printf '                       %s\n' "$l" >&2; done
}
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

# no_shell_death <label> — the run printed no bash DIAGNOSTIC of its own. ⛔ THIS IS THE BASH-FLOOR
# TRIPWIRE, and it is the only assertion in this file whose job is to fail on a DIFFERENT INTERPRETER
# rather than on different code (card#9616). `"${a[@]}"`/`"${a[*]}"` over an empty array under `set -u`
# is an `unbound variable` death below bash 4.4, and some of those sites do not change what the deploy
# DECIDES — checkout_lock_holders' is inside a `$( )`, so on an old bash the subshell dies, the parent
# reads an empty answer and carries on. No assertion about the deploy's verdict can see that; this one
# can. At or above BASH_FLOOR it always passes, which is the point: the run that makes it fire is
# `deploy-selftest.yml`'s below-floor control, and every case carrying it widens that control's
# denominator past the one construct it started with.
no_shell_death() { hasnt "$1: no shell diagnostic of bash's own (bash-floor tripwire)" "unbound variable" "$2"; }

# ── stubs on PATH ─────────────────────────────────────────────────────────────────────────────
# ⛔ EVERY `REAL_…` RESOLVES HERE, BEFORE THE PATH EXPORT BELOW. After it, `command -v <name>` finds
# this suite's own stub as soon as one is written, and a stub that execs itself never returns.
REAL_FUSER="$(command -v fuser)" || { echo "selftest: fuser not found" >&2; exit 1; }
REAL_PHP="$(command -v php)" || { echo "selftest: php not found (deploy.sh parses the stream pool's JSON status with php -r)" >&2; exit 1; }
REAL_PS="$(command -v ps)" || { echo "selftest: ps not found" >&2; exit 1; }
REAL_MKTEMP="$(command -v mktemp)" || { echo "selftest: mktemp not found" >&2; exit 1; }
REAL_GIT="$(command -v git)" || { echo "selftest: git not found" >&2; exit 1; }
REAL_BASH="$(command -v bash)" || { echo "selftest: bash not found" >&2; exit 1; }
mkdir -p "$T/bin" "$T/knobs"; export PATH="$T/bin:$PATH"
# `mezzanine:extra` is in no release this repo ships: it is the daemon the ACROSS RELEASES case's target
# release adds, and the stub has to know to hold a lock for it.
printf '%s\n' "${SUPERVISED_DAEMONS[@]}" mezzanine:extra > "$T/knobs/daemons"

# php. A supervised daemon is started under `env -i` (by the fixture, and by deploy.sh exactly as
# cron would), so the paths it needs are baked in and its knobs are FILES, not environment.
{
  printf '#!/usr/bin/env bash\nCALL_LOG=%q\nKNOBS=%q\nREAL_PHP=%q\n' "$CALL_LOG" "$T/knobs" "$REAL_PHP"
  cat <<'STUB'
# `php -r 'echo PHP_VERSION;'` is the host-PHP probe and answers the stub's version; any other `php -r` is
# deploy.sh parsing a PHP-FPM JSON status (fpm_code_reload_ready, previous_stream_pids), run for real.
if [ "${1:-}" = "-r" ]; then
  if [ "${2:-}" = 'echo PHP_VERSION;' ]; then printf '%s' "${STUB_PHP_VERSION:-8.3.14}"; exit 0; fi
  exec "$REAL_PHP" "$@"
fi
printf 'php %s\n' "$*" >> "$CALL_LOG"
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
# \`npm --version\` is A1c's read of THIS HOST's npm, which A12 holds to the floor the release's
# lockfile implies (card#9616). The stub answers it from a knob, and can fail it. composer is never
# asked its version by this script — A6 reads the floor out of the release, not out of composer.
if [ "$c" = npm ] && [ "\${1:-}" = --version ]; then
  if [ "\${STUB_NPM_VERSION_RC:-0}" -ne 0 ]; then
    echo "stub npm: cannot determine version" >&2; exit "\$STUB_NPM_VERSION_RC"
  fi
  printf '%s\n' "\${STUB_NPM_VERSION:-9.2.0}"; exit 0
fi
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
output_buffering => $STUB_OB => $STUB_OB
output_handler => $STUB_OH => $STUB_OH
zlib.output_compression => $STUB_ZLIB => $STUB_ZLIB
ignore_user_abort => $STUB_IUA => $STUB_IUA
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
# cgi-fcgi: the stream pool's pm.status_listen. Its JSON is built from knob files — the pool name it answers
# for, whether it answers at all, and the requests it lists: one "<pid> <start epoch>" line each, Running,
# `request uri` /index.php as behind the front controller, and ONLY while that pid is alive — so a worker the
# deploy's SIGTERM ended leaves the listing as it leaves a real one.
{
  printf '#!/usr/bin/env bash\nCALL_LOG=%q\nKNOBS=%q\n' "$CALL_LOG" "$T/knobs"
  cat <<'STUB'
printf 'cgi-fcgi %s SCRIPT_NAME=%s QUERY_STRING=%s\n' "$*" "${SCRIPT_NAME:-}" "${QUERY_STRING:-}" >> "$CALL_LOG"
[ -e "$KNOBS/status_down" ] && exit 1
pool="$(cat "$KNOBS/status_pool" 2>/dev/null || echo mezz-stream)"
now="$(date +%s)"; procs=""
while read -r pid started; do
  [ -n "$pid" ] || continue
  kill -0 "$pid" 2>/dev/null || continue
  procs+="${procs:+,}{\"pid\":$pid,\"state\":\"Running\",\"request uri\":\"/index.php\",\"request duration\":$(( (now - started) * 1000000 ))}"
done < "$KNOBS/streams"
printf 'Content-type: application/json\r\n\r\n{"pool":"%s","processes":[%s]}' "$pool" "$procs"
STUB
} > "$T/bin/cgi-fcgi"
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
# mktemp: the REAL one — and, while the `mktemp_passes` knob holds N, the first N calls succeed and every
# later one is handed a TMPDIR that does not exist, so it fails with mktemp's OWN error, exactly as on a
# host whose TMPDIR is gone (card#9816). Each call is logged while the knob is set. A count, because the
# .env loader makes phase A's FIRST scratch file, and a TMPDIR broken from the start stops every run there.
# ⛔ AND, while the `mktemp_fill_after` knob holds N, the first N calls pass and the next one FILLS the
# filesystem behind TMPDIR with `dd` before letting the real mktemp run (card#9932) — a real block-full
# filesystem, not a simulated one, and only ever the private tmpfs `run_full` mounts (§ card#9932). The
# fill REFUSES unless TMPDIR is that mount point AND it is on a different device from $T — a tmpfs that
# was not mounted is a plain directory on this runner's own disk, which a `dd` must never fill — and the
# `dd` is capped besides. It logs `mktemp FILLED` so a case can prove the fill happened.
{
  printf '#!/usr/bin/env bash\nKNOBS=%q\nREAL_MKTEMP=%q\nBROKEN_TMPDIR=%q\nFULL_TMP=%q\nT=%q\n' \
    "$T/knobs" "$REAL_MKTEMP" "$T/no-such-dir" "$T/full-tmp" "$T"
  cat <<'STUB'
if [ -e "$KNOBS/mktemp_passes" ]; then
  printf 'mktemp %s\n' "$*" >> "$CALL_LOG"
  n="$(cat "$KNOBS/mktemp_passes")"
  if [ "$n" -gt 0 ]; then printf '%s\n' "$((n - 1))" > "$KNOBS/mktemp_passes"; else export TMPDIR="$BROKEN_TMPDIR"; fi
fi
if [ -e "$KNOBS/mktemp_fill_after" ]; then
  printf 'mktemp %s\n' "$*" >> "$CALL_LOG"
  n="$(cat "$KNOBS/mktemp_fill_after")"
  if [ "$n" -gt 0 ]; then printf '%s\n' "$((n - 1))" > "$KNOBS/mktemp_fill_after"
  else
    rm -f "$KNOBS/mktemp_fill_after"
    if [ "${TMPDIR:-}" = "$FULL_TMP" ] && [ "$(stat -c %d "$FULL_TMP")" != "$(stat -c %d "$T")" ]; then
      dd if=/dev/zero of="$FULL_TMP/selftest-fill" bs=4096 count=1024 2>/dev/null
      printf 'mktemp FILLED %s\n' "$FULL_TMP" >> "$CALL_LOG"
    else
      printf 'mktemp REFUSED TO FILL %s (not the private mount)\n' "${TMPDIR:-unset}" >> "$CALL_LOG"
    fi
  fi
fi
exec "$REAL_MKTEMP" "$@"
STUB
} > "$T/bin/mktemp"
# git: the REAL one — turned, by a knob, into a git that does not accept `:(literal)` pathspec magic
# (card#9616, A3b). ⭐ BOTH of the ways such a git answers are produced, because a probe reading only
# the STATUS would pass the second: `128` fails any invocation carrying the magic, as git does on a
# pathspec it cannot parse, and `empty` exits 0 listing nothing, as a git taking the whole string for
# a literal path would. `plain128` instead fails the probe's PLAIN form (`ls-tree … -- VERSION`) —
# a git that cannot list a tree at all, which must not be blamed on the magic it never reached.
{
  printf '#!/usr/bin/env bash\nKNOBS=%q\nREAL_GIT=%q\n' "$T/knobs" "$REAL_GIT"
  cat <<'STUB'
# A `git remote` that FAILS (card#9832, A3c). After A3 has opened the repository nothing this
# suite can do to a fixture makes the real `git remote` non-zero — a config git cannot parse, or
# cannot read, fails A3's `rev-parse --git-dir` first — so the status A3c reads is exercised from
# here instead of left as a branch nothing has been seen to take (canon #9). Only the deploy's own
# call is affected: the knob is set immediately before the run and cleared by reset_stubs, and the
# fixture's own `git remote add` calls happen while it is unset.
if [ -e "$KNOBS/git_remote_fail" ]; then
  for a in "$@"; do
    if [ "$a" = remote ]; then echo "fatal: unable to read config file (selftest shim)" >&2; exit 128; fi
  done
fi
# git's stderr made UNREADABLE the moment git is done with it (card#9932): the deploy captures a
# `rev-parse --verify`'s diagnostic into a scratch FILE and reads it back with `cat`, and this is the one
# way to make that `cat` fail with the real git's real answer already written. Only a stderr that is a
# regular file is touched, so no captured pipe and no terminal is.
# The knob's CONTENT is a skip count (empty means 0 — fire on the first matching call, C1's own usage):
# git_commit_of's tag peel is not the first `rev-parse --verify` of a run, so its own twin (SF-2, card#9932
# review) counts past the candidate resolutions ahead of it instead of tripping on one of those first.
if [ -e "$KNOBS/git_stderr_unreadable" ]; then
  case " $* " in *" rev-parse --verify "*)
    n="$(cat "$KNOBS/git_stderr_unreadable" 2>/dev/null)"; n="${n:-0}"
    if [ "$n" -gt 0 ]; then
      printf '%s\n' "$((n - 1))" > "$KNOBS/git_stderr_unreadable"
    else
      errf="$(readlink "/proc/$$/fd/2" 2>/dev/null)"
      if [ -f "$errf" ]; then "$REAL_GIT" "$@"; rc=$?; chmod 000 "$errf"; exit "$rc"; fi
    fi ;;
  esac
fi
mode="$(cat "$KNOBS/git_magic" 2>/dev/null)"
if [ -n "$mode" ]; then
  for a in "$@"; do
    case "$mode:$a" in
      128:':(literal)'*) echo "fatal: Invalid pathspec magic 'literal' in ':(literal)VERSION' (selftest shim)" >&2; exit 128 ;;
      empty:':(literal)'*) exit 0 ;;
    esac
  done
  if [ "$mode" = plain128 ] && [ "${*: -2}" = "-- VERSION" ]; then
    echo "fatal: unable to read tree (selftest shim)" >&2; exit 128
  fi
fi
exec "$REAL_GIT" "$@"
STUB
} > "$T/bin/git"
# bash: the REAL one, behind a pass-through that RECORDS — the only way to see which interpreter a
# child was started with (the review at `0de8857`, card#9616 comment 5846). deploy.sh starts exactly TWO bash processes: the
# re-exec that runs the maintenance window (phase_b_open_window) and A13's read of the target's
# bin/supervision.sh (gate_a13_target_plan). The supervised daemons are not a third — they are
# started `/bin/sh -c` under `env -i`, which resets PATH as well, so neither this stub nor any
# bash is in that path. A1 and A6b hold THIS PROCESS's bash to the floors, so both of those
# children must be `"$BASH"` and not the `bash` that happens to be first on PATH; a child started
# through a `#!/usr/bin/env bash` shebang or a bare `bash -c` lands HERE, and the case below
# asserts that neither does. (A1's own message counts THREE interpreters, correctly for what it
# says: the two children and the process phase A is already running in, which it does not start.)
# ⛔ ITS SHEBANG IS THE REAL BASH BY ABSOLUTE PATH, not `#!/usr/bin/env bash`: this file IS what
# `env bash` resolves to once $T/bin is on PATH, so the usual spelling would exec itself forever.
# Logging is off unless the knob file exists, so every other case in this suite is unaffected by it.
{
  printf '#!%s\nKNOBS=%q\nREAL_BASH=%q\n' "$REAL_BASH" "$T/knobs" "$REAL_BASH"
  cat <<'STUB'
if [ -e "$KNOBS/bash_calls" ]; then printf 'bash %s\n' "$*" >> "$KNOBS/bash_calls"; fi
exec "$REAL_BASH" "$@"
STUB
} > "$T/bin/bash"
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
STREAM_POOL="$STUB_FPM_ETC/pool.d/mezz-stream.conf"
write_fpm_etc() {
  rm -rf "$STUB_FPM_ETC"; mkdir -p "$STUB_FPM_ETC/pool.d"
  : > "$STUB_FPM_ETC/php.ini"
  printf '[global]\npid = /run/php/php-fpm.pid\ninclude=%s/pool.d/*.conf\n' "$STUB_FPM_ETC" > "$STUB_FPM_ETC/php-fpm.conf"
  printf '[178815168175465]\nuser = %s\ngroup = %s\nlisten = /run/php/178815168175465.sock\nphp_value[log_errors] = On\n' \
    "$ME" "$ME" > "$POOL"
  # ⛔ A TRAP, not filler: a pool that is NOT the deploy user's, with timestamps off. A reader that
  # took every pool rather than this user's would refuse every control in this file.
  printf '[www]\nuser = www-data\nphp_admin_flag[opcache.validate_timestamps] = off\n' > "$STUB_FPM_ETC/pool.d/www.conf"
  # The DEDICATED stream pool FLEET-STATE.md § 8.3 R2 requires (card#9300), as the host would define it.
  printf '[mezz-stream]\nuser = %s\ngroup = %s\nlisten = /run/php/mezz-stream.sock\npm.status_path = /stream-status\npm.status_listen = /run/php/mezz-stream-status.sock\nrequest_terminate_timeout = 0\n' \
    "$ME" "$ME" > "$STREAM_POOL"
}

# reset_stubs — bash persists `VAR=x func` assignments after the call, so a knob set for one case
# would silently leak into every later one. Each fixture starts from a known set instead.
reset_stubs() {
  export STUB_FAIL_RE="" STUB_HTTP_CODE=200
  # 8.4.7 SATISFIES $FIXTURE_PHP_FLOOR without BEING it, so a case that passes here is not
  # passing on an accidental exact match.
  export STUB_PHP_VERSION="8.4.7"
  export STUB_OPCACHE_ENABLE=On STUB_OPCACHE_VALIDATE=On STUB_OPCACHE_FREQ=0 STUB_OPCACHE_PRELOAD="no value"
  # R1's ini directives as this host's FPM php.ini sets them (measured): output_buffering 4096, the rest off.
  export STUB_OB=4096 STUB_OH="no value" STUB_ZLIB=Off STUB_IUA=Off
  export MEZZ_STREAM_POOL=mezz-stream MEZZ_FEED_DRAIN_CEILING_S=2
  : > "$T/knobs/streams"; rm -f "$T/knobs/status_down" "$T/knobs/status_pool"
  export MEZZ_DAEMON_SETTLE_S=2 MEZZ_DAEMON_STOP_TIMEOUT_S=3
  # ⛔ MEZZ_REMOTE IS UNSET HERE, not defaulted: A3c (card#9832) refuses a value that is not among
  # `git remote`'s names, so one case's remote leaking into the next would refuse every later
  # fixture — whose clone knows only `origin` — on a cause that case never set.
  unset STUB_UID STUB_CRONTAB_BROKEN MEZZ_DEPLOY_IN_WINDOW MEZZ_DEPLOY_REVALIDATE_FLOOR_S MEZZ_FPM_BIN \
        MEZZ_REMOTE
  kill_streams
  : > "$T/knobs/dies_after_start"; : > "$T/knobs/ignores_term"; : > "$T/knobs/transient_loser"
  rm -f "$T/knobs/slow_fuser" "$T/knobs/blind_ps" "$T/knobs/mktemp_passes" "$T/knobs/git_magic" \
        "$T/knobs/bash_calls" "$T/knobs/git_remote_fail" "$T/knobs/mktemp_fill_after" \
        "$T/knobs/git_stderr_unreadable"
  # 9.2.0 is ABOVE the npm 7 the fixture's lockfileVersion 3 implies without BEING it, so a case
  # that passes A12 here is not passing on an accidental exact match (card#9616).
  export STUB_NPM_VERSION=9.2.0 STUB_NPM_VERSION_RC=0
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
  printf 'APP_ENV=\nAPP_DEBUG=\nAPP_KEY=\nAPP_URL=\nDB_CONNECTION=\nDB_PASSWORD=\nMYSQL_ATTR_SSL_CA=\nCACHE_STORE=\n# COMMENTED_OPTIONAL=\n' \
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

# run_via <interpreter> <args…> — the same deploy, started through an EXPLICIT interpreter instead
# of through the script's `#!/usr/bin/env bash`. `run` above cannot distinguish the two interpreters
# card#9616 cares about, because it invokes the shebang: the shell it starts IS PATH's bash, so a
# deploy that handed the window to PATH's bash and one that handed over its own would be the same
# process either way, and the difference the gates depend on would be untestable.
run_via() {
  local interp="$1"; shift
  : > "$CALL_LOG"
  OUT="$(MEZZ_DEPLOY_ROOT="$ROOT" "$interp" "$ROOT/bin/deploy.sh" "$@" 2>&1)"
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
hasnt "control: no key of .env.example is written in a form this deploy cannot read" "in a form this deploy does not read, so whether" "$OUT"
has "control: the release's crontab block is what is installed" "is what is installed; the window rewrites it unchanged" "$OUT"
has "control: the release keeps the daemons' lock files where the serving release's are" "keeps the daemons' lock files at $ROOT/server/" "$OUT"
has "control: PHP-FPM is not reloaded, and the posture that makes that safe was read" "php-fpm  not reloaded — opcache revalidates a changed file within 0 s" "$OUT"
has "control: the stream pool's status answered over pm.status_listen" "stream pool [mezz-stream]: its status answers over /run/php/mezz-stream-status.sock" "$OUT"
has "control: output_buffering 4096 is reported and NOT refused (measured: the handler's flush defeats it)" "output_buffering 4096 (defeated by the handler's flush" "$OUT"
logged "control: A14 read the status over the listener, at its path" "cgi-fcgi -bind -connect /run/php/mezz-stream-status.sock SCRIPT_NAME=/stream-status"
logged "control: A14 read the FPM SAPI (php-fpm -i), not the CLI's ini" "php-fpm8.4 -i"
hasnt "control: another user's pool (the www trap, timestamps off) was not read" "validate_timestamps is off" "$OUT"
unlogged "control: --dry-run mutates nothing (no artisan down)" "artisan down"
unlogged "control: --dry-run writes no crontab" "crontab -$"
hasnt "control: a document root with no .user.ini is not warned about" "no document root at" "$OUT"
eq  "control: --dry-run left HEAD where it was" "$V1" "$(git -C "$ROOT" rev-parse HEAD)"
no_shell_death "control" "$OUT"

# ══════════════════════════════════════════════════════════════════════════════════════════════
section "card#9745 — the READ LEDGER, and the declaration it holds TRUE"
# bin/deploy.sh DECLARES every function phase A reads a path out of a tree through (GATE_TREE_READERS),
# and bin/deploy-gate-inputs.sh RUNS every host-free one of them over the commit under test. What makes
# that declaration a contract rather than a comment is this section: two claims about it, each refereed
# by a RUN of the real script with its read ledger on, and each seen to fail against a copy of the
# script built to falsify it.
#   LEG 1 — the functions a full `--dry-run` attributes a path read to are EXACTLY the declared ones. An
#           undeclared one is named with the path it read; a declared one that read nothing is named too.
#   LEG 2 — every host-free row, run ON ITS OWN over the same commit (library mode — the card#9644 seam),
#           reads exactly what the gate phase_a called read with it.
# Leg 3 — every git process of a gate run is a git_at call — is bin/deploy-gate-inputs.sh's, which runs
# on the real tree; bin/deploy-gate-inputs.selftest.sh is where it is seen to fail.

# lib <deploy.sh> <command…> — <deploy.sh> SOURCED, in a shell of its own, with this fixture as its root,
# then <command…>. The first consumer of the card#9644 seam that is a test: until card#9745 nothing sourced
# bin/deploy.sh, so "sourcing it runs no deploy" was a claim no run had checked.
# ⚠ The command is held in an array ACROSS the source: a file sourced with no arguments shares its caller's
# positional parameters, and bin/deploy.sh's library mode runs `set --` (§ library mode), so "$@" after the
# `.` is empty — measured: the first version of this helper ran nothing, and every case built on it passed.
# shellcheck disable=SC1090  # <deploy.sh> is a fixture's copy, named by the caller
lib() { local d="$1"; shift; local -a __cmd=("$@"); ( export MEZZ_DEPLOY_ROOT="$ROOT"; . "$d"; "${__cmd[@]}" ); }
# declared_readers <deploy.sh> — the first field of each GATE_TREE_READERS row, as that file declares it.
# shellcheck disable=SC2016  # expanded by the sourcing shell lib starts
declared_readers() { lib "$1" eval 'for __r in "${GATE_TREE_READERS[@]}"; do printf "%s\n" "${__r%% *}"; done' | sort -u; }
# shellcheck disable=SC2016  # expanded by the sourcing shell lib starts
host_free_readers() { lib "$1" eval 'for __r in "${GATE_TREE_READERS[@]}"; do [ "${__r#* }" != host-free ] || printf "%s\n" "${__r%% *}"; done'; }
# leg1 <ledger> <deploy.sh> — prints nothing when the functions the ledger attributes a read to EQUAL the
# declaration, and one line per disagreement otherwise.
leg1() {
  local fn
  while IFS= read -r fn; do
    awk -F'\t' -v f="$fn" '$1 == "read" && $2 == f { printf "undeclared reader %s read %s:%s\n", f, $3, $4 }' "$1" | sort -u
  done < <(comm -23 <(awk -F'\t' '$1 == "read" { print $2 }' "$1" | sort -u) <(declared_readers "$2"))
  comm -13 <(awk -F'\t' '$1 == "read" { print $2 }' "$1" | sort -u) <(declared_readers "$2") \
    | sed 's/^/declared reader read nothing in this run: /'
}
# leg2 <ledger> <deploy.sh> <commit> — prints nothing when each host-free row, run alone over <commit>,
# reads what the ledger says it read inside phase_a; one line per path read by one and not the other.
leg2() {
  local fn own="$T/ledger.own"
  while IFS= read -r fn; do
    : > "$own"
    MEZZ_GIT_READ_LEDGER="$own" lib "$2" "$fn" "$3" >/dev/null 2>&1 \
      || printf 'host-free %s REFUSED or failed when run on its own over %s\n' "$fn" "$3"
    diff <(awk -F'\t' -v f="$fn" '$1 == "read" && $2 == f { print $3 ":" $4 }' "$1" | sort) \
         <(awk -F'\t' -v f="$fn" '$1 == "read" && $2 == f { print $3 ":" $4 }' "$own" | sort) \
      | sed -n "s/^< /$fn read inside phase_a and NOT on its own: /p; s/^> /$fn read on its own and NOT inside phase_a: /p" | sort -u
  done < <(host_free_readers "$2")
}

mkfix ledger
export MEZZ_GIT_READ_LEDGER="$T/ledger.control"; : > "$MEZZ_GIT_READ_LEDGER"
run --dry-run
unset MEZZ_GIT_READ_LEDGER
eq  "ledger: the control deploys with the ledger on" 0 "$RC"
neq "ledger: it recorded path reads (an empty ledger is a measurement that never happened)" 0 \
    "$(grep -c '^read' "$T/ledger.control")"
has "ledger: a read is attributed to the GATE that asked, not to the reader it asked through" \
    "$(printf 'read\tgate_a11_trusted_proxies\t%s\tserver/bootstrap/app.php' "$V2")" "$(cat "$T/ledger.control")"
has "ledger: a path the TREE names (A10's migrations) is recorded as read, with no pattern for it anywhere" \
    "$(printf 'read\tgate_a10_migration_algorithm\t%s\tserver/database/migrations/2026_01_01_000000_create_fleet_store_tables.php' "$V2")" \
    "$(cat "$T/ledger.control")"
# OFF by default, and off means git_at never reaches the ledger code at all — checked by replacing that
# code with one that announces itself, which is what a hook reached unconditionally would do.
# shellcheck disable=SC2016  # expanded by the sourcing shell lib starts
eq  "ledger: OFF by default — with MEZZ_GIT_READ_LEDGER unset, git_at never calls the ledger" "" \
    "$(lib "$ROOT/bin/deploy.sh" eval 'git_read_ledger_note() { echo CALLED >&2; }; unset MEZZ_GIT_READ_LEDGER; git_at rev-parse HEAD >/dev/null' 2>&1)"
# shellcheck disable=SC2016  # expanded by the sourcing shell lib starts
eq  "ledger: …and the same probe, with it set, is called (the control that the probe can see a call)" "CALLED" \
    "$(lib "$ROOT/bin/deploy.sh" eval 'git_read_ledger_note() { echo CALLED >&2; }; MEZZ_GIT_READ_LEDGER=/dev/null; git_at rev-parse HEAD >/dev/null' 2>&1)"

# Library mode (card#9644): sourcing runs no deploy, and a gate is then callable on its own.
OUT="$(lib "$ROOT/bin/deploy.sh" true 2>&1)"; RC=$?
eq  "library mode: sourcing bin/deploy.sh exits 0" 0 "$RC"
hasnt "library mode: and runs no deploy" "Mezzanine prod deploy" "$OUT"
OUT="$(lib "$ROOT/bin/deploy.sh" gate_a11_trusted_proxies "$V2" 2>&1)"; RC=$?
eq  "library mode: a gate runs on its own over a commit" 0 "$RC"
has "library mode: and says what it says inside phase_a" "no trustProxies() configured" "$OUT"

eq  "LEG 1: the functions the control's run read through ARE the declaration" \
    "" "$(leg1 "$T/ledger.control" "$ROOT/bin/deploy.sh")"
neq "LEG 2: the declaration names host-free rows to run (none would make the leg below a pass over nothing)" \
    "" "$(host_free_readers "$ROOT/bin/deploy.sh")"
eq  "LEG 2: every host-free row, run alone over the same commit, reads what it read inside phase_a" \
    "" "$(leg2 "$T/ledger.control" "$ROOT/bin/deploy.sh" "$V2")"

# ── each leg seen to FAIL. Each mutant is a copy of the script with ONE change, deployed from.
undeclared_reader() { # a gate that asks a helper nobody declared to read the release for it
  # shellcheck disable=SC2016,SC2317  # injected source; invoked by mkfix, by name
  sed -i -e '/^gate_a11_trusted_proxies() {$/i extra_reader() { local x; git_read_at x "$1" server/artisan || true; }' \
         -e '/^gate_a11_trusted_proxies() {$/a \  extra_reader "$1"' "$1/bin/deploy.sh"
}
mkfix ledger_undeclared "" undeclared_reader
export MEZZ_GIT_READ_LEDGER="$T/ledger.undeclared"; : > "$MEZZ_GIT_READ_LEDGER"
run --dry-run
unset MEZZ_GIT_READ_LEDGER
eq  "LEG 1 mutant: it still deploys — nothing but the declaration is wrong" 0 "$RC"
eq  "LEG 1 mutant: the undeclared reader is named, with the path it read" \
    "undeclared reader extra_reader read $V2:server/artisan" "$(leg1 "$T/ledger.undeclared" "$ROOT/bin/deploy.sh")"

divergent_entry() { # a gate whose reads depend on a global a deploy sets and a sourced run does not
  # shellcheck disable=SC2016,SC2317  # injected source; invoked by mkfix, by name
  sed -i '/^gate_a11_trusted_proxies() {$/a \  [ "$DRY_RUN" -eq 0 ] || { local x; git_read_at x "$1" server/artisan || true; }' "$1/bin/deploy.sh"
}
mkfix ledger_divergent "" divergent_entry
export MEZZ_GIT_READ_LEDGER="$T/ledger.divergent"; : > "$MEZZ_GIT_READ_LEDGER"
run --dry-run
unset MEZZ_GIT_READ_LEDGER
eq  "LEG 2 mutant: it still deploys" 0 "$RC"
eq  "LEG 2 mutant: LEG 1 does not see it — the reader IS declared" "" "$(leg1 "$T/ledger.divergent" "$ROOT/bin/deploy.sh")"
eq  "LEG 2 mutant: the path the entry does not read on its own is named" \
    "gate_a11_trusted_proxies read inside phase_a and NOT on its own: $V2:server/artisan" \
    "$(leg2 "$T/ledger.divergent" "$ROOT/bin/deploy.sh" "$V2")"

section "REFUSAL — the host is not in a deployable state"
# ⛔ THE REFUSAL CONTRACT IS ASSERTED HERE, ONCE, FOR EVERY CASE THAT USES THIS (card#9646).
# What `refuse` promises is three things together — exit 1, the `⛔ REFUSED — <cause>` banner, and
# the closing `Nothing was changed. The previous release is still serving.` — and until this card
# the suite asserted only the first of them. Measured at 7bca04a: `grep -c '⛔ REFUSED'` over this
# file returned 0, and both `Nothing was changed` hits were non-positive (a comment, and a `hasnt`
# for the in-window mutant). EVERY ONE of those three is satisfiable by a banner-less DEATH: any
# command phase A runs without reading its status exits the script under `set -Eeuo pipefail` with
# THAT command's status, and git's exit 1 — a ref of this checkout it could not read — is exactly
# the status the exit table says MEANS "refused, nothing was touched". So a green here proved the
# deploy stopped, and said nothing about whether the operator could tell WHY.
#
# ⚠ THE FIX IS AT THE CONTRACT, NOT AT THE CASES, and that is the point rather than a convenience:
# `grep -c 'run_refusal '` counts the call sites this one edit upgrades, and re-deriving that count
# is the only honest way to state it. It reds cases that were green for the wrong reason — that red
# is the FINDING. Each is fixed by making the case assert the real contract; NEVER by weakening the
# three assertions below, which is how this class would come straight back.
run_refusal() { # run_refusal <label> <needle> <args…>
  local label="$1" needle="$2"; shift 2
  run "$@"
  eq  "$label: exit 1 (refused, nothing touched)" 1 "$RC"
  has "$label: the ⛔ REFUSED banner, so exit 1 is a verdict and not a death" "⛔ REFUSED — " "$OUT"
  has "$label: the phase-A promise" "Nothing was changed. The previous release is still serving." "$OUT"
  has "$label: says why" "$needle" "$OUT"
  unlogged "$label: never opened the window" "artisan down"
}

mkfix as_root; export STUB_UID=0; run --dry-run
eq "root: exit 1" 1 "$RC"; has "root: says why" "running as root" "$OUT"
hasnt "root: offers no escalation route" "sudo" "$OUT"

# ── S1 — an option with no value ───────────────────────────────────────────────────────────────
# `REF="${2:?--ref needs a value}"` had BASH refuse, not this script: `bash: line N: 2: --ref needs
# a value` on stderr and exit 1, which is the code the table says means "refused, nothing touched"
# with nothing on screen to confirm it. `--internal-post-checkout`'s bare `${2:?}` printed only the
# parameter's NAME. Both go through `refuse` now. `eq 1` was green before the fix, on both.
mkfix option_needs_value
run --dry-run --ref
eq  "--ref with no value: exit 1" 1 "$RC"
has "--ref with no value: the ⛔ REFUSED banner, so the 1 is this script's verdict" \
  "⛔ REFUSED — --ref needs a value" "$OUT"
has "--ref with no value: the phase-A promise" \
  "Nothing was changed. The previous release is still serving." "$OUT"
hasnt "--ref with no value: bash's own parameter error is not what the operator is left with" \
  "2: --ref needs a value" "$OUT"
unlogged "--ref with no value: never opened the window" "artisan down"
# An EMPTY value is the same decision — which is what `${2:?}` did, and what a `[ $# -ge 2 ]` test
# would have quietly dropped: `--ref ''` would then resolve `refs/remotes/origin/` at A7.
run --dry-run --ref ''
eq  "--ref '': exit 1, the empty value refused exactly as a missing one" 1 "$RC"
has "--ref '': the banner" "⛔ REFUSED — --ref needs a value" "$OUT"
run --dry-run --internal-post-checkout
eq  "--internal-post-checkout with no value: exit 1" 1 "$RC"
has "--internal-post-checkout with no value: refused BY NAME, where bash printed only \`2\`" \
  "⛔ REFUSED — --internal-post-checkout needs a value" "$OUT"
# THE CONTROL, one variable away: the same option WITH a value deploys.
run --dry-run --ref main
eq  "the control: --ref WITH a value still deploys" 0 "$RC"

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

# card#9605 — the I/O sibling of the two cases above, and the one that was missing. A `.env` that EXISTS, is
# a regular file, and whose mode's other-digit is 0 passes both of them, and can still be one the deploy user
# cannot OPEN (created by another user when the host was stood up, left 640 to an owner and group this script
# is in neither of). `env_lines_load` wrote its `2>/dev/null` BEFORE the input redirect, so stderr was already
# gone when the OPEN failed, and a failed open returns the same status 1 a complete read to EOF returns: the
# loader handed back an empty file, `env_file_scan` certified a file it had never read, and A5 then refused on
# `APP_ENV is 'unset'` — the deploy stopped on a cause that is not the real one, with no stderr at all. That
# is the failure the NUL refusal exists to end (§ A5 below), reproduced on a different input.
env_openability() { if { : < "$1"; } 2>/dev/null; then printf 'openable'; else printf 'unopenable'; fi; }
mkfix unreadable_env; chmod 000 "$ROOT/server/.env"
# The condition is ASSERTED, not assumed. Root opens every mode, so under a root runner this fixture is a
# readable file and the case below would certify nothing while looking like it had; it reds HERE, naming why,
# rather than skipping (canon #9 — a check that cannot fail is a decoration). GitHub's ubuntu-latest runs
# this suite as the non-root `runner` user, where the condition holds.
eq "unreadable .env: the fixture really is unopenable by this user (a root runner cannot hold this)" \
  unopenable "$(env_openability "$ROOT/server/.env")"
run_refusal "unreadable .env" "cannot be read" --dry-run
hasnt "unreadable .env: does not name the wrong cause (every key read as unset)" "APP_ENV is 'unset'" "$OUT"
hasnt "unreadable .env: no DB password is printed" "$FAKE_PW" "$OUT"
hasnt "unreadable .env: no store verdict is reached on a file that was never read" "store on this host" "$OUT"
# The twin, one variable away: the same file, openable by this user, passes every check.
chmod 640 "$ROOT/server/.env"; run --dry-run
eq "the same .env, readable: exit 0" 0 "$RC"

# ── card#9610 — A .ENV THAT OPENED AND COULD NOT BE READ TO ITS END ────────────────────────────
# The residual card#9605 left, and the sibling of the case above one step further in. Splitting the OPEN
# out gave the open's failure a status of its own; the READ's two meanings stayed fused, because bash's
# `read -d ''` returns 1 at end-of-file AND on a read error alike. So a file that yielded NO BYTES because
# the kernel refused mid-read came back here as an EMPTY file, env_file_scan certified a file nothing had
# read, and A5 refused on `APP_ENV is 'unset'` — a deploy stopped on a cause nothing established.
#
# WHAT DISCRIMINATES is not the status and not the SIZE: it is bash's own DIAGNOSTIC, which is a message on
# a read error and silence at end-of-file — the same rule git_ref_oid reads git by. The cases below are
# written to red on each wrong fix rather than on the absence of any fix, which is why the EIO one is here
# at all and why the directory is NOT a substitute for it.
#
# ⛔ H1 IS THE LOAD-BEARING FIXTURE. `/proc/self/mem` is a REGULAR file, mode 600, owned by this user,
# that `stat` reports as SIZE 0 and whose first byte cannot be read — no root and no special mount needed.
# It is the only one of these that reds on ALL THREE wrong fixes:
#   · dropping the stderr capture altogether        — status 1 is read as end-of-file, and this passes;
#   · discriminating with `[ -d "$ENV_FILE" ]`      — H1b (a directory) passes, and this does not;
#   · discriminating on length against `stat -c %s` — size 0 against 0 bytes read is "an empty file".
# Each of those is built and run in the build round, and each reds HERE and nowhere else.
#
# These run deploy.sh as a LIBRARY (§ library mode, card#9644): `$0` is this selftest, so DEPLOY_IS_RUN is
# 0, nothing execs, and one reader can be pointed at one path. There is no way to reach this through a real
# `server/.env`: a symlink to /proc/self/mem is refused earlier, by A5's mode check, which reads `stat -c %a`
# WITHOUT `-L` and so reports the LINK's 777. That is stated rather than worked around — a fixture bent into
# shape to reach a check would be testing the bend.
#
# LC_ALL=C is pinned on the subshell, not on deploy.sh: bash's read diagnostic is gettext-translated, the
# loader reads only its PRESENCE (never its words), and these assertions are the one place the words have to
# be predictable. A runner in another language exercises the same mechanism; only this file needs the pin.
section "card#9610 — a .env that OPENED and could not be READ to its end"

# THE FIXTURE'S OWN PRECONDITION, ASSERTED FIRST, THROUGH A READER THAT IS NOT BASH'S. A kernel that answers
# this path with 0 bytes instead of EIO would make every case below pass while testing nothing, so it reds
# HERE, by name. `head` is used because it is not the mechanism under test (canon #9).
eio_err="$(LC_ALL=C head -c1 /proc/self/mem 2>&1 >/dev/null)"; eio_rc=$?
neq "EIO fixture: /proc/self/mem really cannot be read by an ordinary reader on this runner" 0 "$eio_rc"
has "EIO fixture: and the reason it gives is an I/O error" "Input/output error" "$eio_err"

# H1 — the loader, on a regular file whose read fails. env_file_scan is what turns the flag into a refusal.
# ⚠ WHICH OF THESE WERE RED BEFORE THE FIX, MEASURED rather than assumed — a case comment that implies more
# than the run showed is the same defect as a check that cannot fail. Red at 6686c0f: the exit code, the
# banner, the promise, and the headline. GREEN there, FOR A DIFFERENT REASON: the two that read bash's
# diagnostic (the old loader did not redirect `read`'s stderr at all, so bash printed straight to the stream
# this capture merges — they assert the diagnostic SURVIVES the capture the fix adds, which is the thing a
# fix can drop), and the three `hasnt` guards (the old loader reached no refusal at all, so it printed none
# of the wrong ones either — they hold the fix to naming THIS cause rather than a neighbouring one).
#
# env_lib <.env path> <reader> [args…] — ONE way in, for every case here. It is a subshell function on
# purpose: that subshell is what gives deploy.sh's `refuse` — which never returns — something to exit, and
# it keeps LC_ALL, ENV_FILE and any TMPDIR the caller exported out of the rest of this suite. ENV_FILE is
# set AFTER the source because deploy.sh's own configuration block writes it.
# ⚠ The comment inside must not OPEN with the analyser's own directive word: a `# shellcheck …` line it
# cannot parse is an ERROR, not a note, and it stops the whole file being checked (measured, 0.9.0).
env_lib() (
  # $0 is this selftest, so deploy.sh's DEPLOY_IS_RUN is 0 and nothing execs (§ library mode, card#9644).
  LC_ALL=C
  _env_lib_path="$1"; shift
  # ⛔ THE READER IS SAVED BEFORE THE SOURCE, and that is not defensiveness — deploy.sh runs `set --` when
  # it is SOURCED (§ library mode: a sourced copy must not see its caller's arguments), so `$@` is EMPTY
  # below it. Measured while building this: called as `"$@"` after the source, env_lib ran NOTHING and every
  # case here compared against an empty string — three of them still passed, because "no output" contains no
  # wrong cause either. That is the shape a `hasnt`-only case has, and why each of these has a positive twin.
  _env_lib_cmd=( "$@" )
  # shellcheck disable=SC1090  # NOT `source=bin/deploy.sh`: measured 0.9.0, that directive pulls the whole
  # of deploy.sh into THIS file's measurement and moves four of its finding classes at once (SC2016, SC2030,
  # SC2031, SC2317), which buys nothing — deploy.sh is a population member and is linted in its own right.
  . "$DEPLOY"
  # shellcheck disable=SC2034  # ENV_FILE is deploy.sh's OWN input, read by the reader sourced above, which
  # ShellCheck cannot follow from here — the same annotation bin/env-mirror-diff.mirror.sh carries for it.
  ENV_FILE="$_env_lib_path"
  "${_env_lib_cmd[@]}"
)

# env_get_status KEY — env_get's STATUS as text, with its output discarded. Run through env_lib, so it is
# the status as a caller inside a `$(…)` sees it: the one thing that crosses back out of that subshell.
# shellcheck disable=SC2317  # every call is indirect: env_lib runs it through "$@", which ShellCheck reads
# as a body nothing reaches. Measured: rename it and the four cases below red, so it is reached.
env_get_status() { local _rc=0; env_get "$1" >/dev/null || _rc=$?; printf 'rc=%s' "$_rc"; }

OUT="$( env_lib /proc/self/mem env_file_scan 2>&1 )"; RC=$?
eq  "EIO at the loader: exit 1 (refused, nothing touched)" 1 "$RC"
has "EIO at the loader: the ⛔ REFUSED banner" "⛔ REFUSED — " "$OUT"
has "EIO at the loader: the phase-A promise" "Nothing was changed. The previous release is still serving." "$OUT"
has "EIO at the loader: says the read stopped short, not that the file is empty" \
    "was opened but could not be read to its end" "$OUT"
has "EIO at the loader: bash's own diagnostic is printed back, not swallowed" "read error" "$OUT"
has "EIO at the loader: and it names the errno the kernel answered with" "Input/output error" "$OUT"
hasnt "EIO at the loader: never reports the wrong cause (every key read as unset)" "APP_ENV is 'unset'" "$OUT"
hasnt "EIO at the loader: not the OPEN's refusal — the open succeeded" "exists but cannot be read by the user" "$OUT"
hasnt "EIO at the loader: no NUL verdict on a file no byte of which was read" "carries a NUL byte" "$OUT"

# H1b — the directory, card#9610's own T4 shape. It is a SECOND case and not the first: a `[ -d ]` test
# passes it while leaving H1 red, which is the whole reason H1 exists.
OUT="$( env_lib "$T" env_file_scan 2>&1 )"; RC=$?
eq  "a directory at the loader: exit 1" 1 "$RC"
has "a directory at the loader: the ⛔ REFUSED banner" "⛔ REFUSED — " "$OUT"
has "a directory at the loader: the same refusal, by the same route" "was opened but could not be read to its end" "$OUT"
has "a directory at the loader: bash's errno says which fault it was" "Is a directory" "$OUT"

# H1c — the STATUS, which is the only thing that crosses a `$(…)`. Phase B and A10b read through one, so a
# flag set inside it is invisible to them: env_get answers 3, prints nothing, and 3 is never 'unset'.
env_get_rc="$( env_lib /proc/self/mem env_get_status APP_URL 2>/dev/null )"
eq "env_get on a file it could not read: status 3, which is neither 'unset' (1) nor 'unread' (2)" "rc=3" "$env_get_rc"
env_get_out="$( env_lib /proc/self/mem env_get APP_URL 2>/dev/null || true )"
eq "env_get on a file it could not read: prints nothing at all" "" "$env_get_out"
# The twin, one variable away: the same reader, on a file it CAN read, still answers the value.
env_get_ok="$( env_lib "$ROOT/server/.env" env_get APP_URL 2>/dev/null )"
eq "the same reader on a readable .env: the value, unchanged" "https://mezzanine.example" "$env_get_ok"

# The scratch-file failure the loader answers for ITSELF. It runs in both phases and inside
# bin/env-mirror-diff.mirror.sh, where neither `refuse` nor `not_established` is the right answer, so it
# sets the same flag with a reason of its own rather than dying or guessing.
#
# ⛔ AND IT REFUSES UNDER A HEADLINE OF ITS OWN (card#9933), WHICH IS WHAT THIS CASE PINS. The flag is
# shared with the read that stopped short above it, and the REFUSAL used to be shared too: this case came
# out as "was opened but could not be read to its end" — of a read that was never made — under a body
# saying the open had succeeded, that a read failing on an already-open file is usually a disk or
# filesystem one, and that `dmesg` and the mount are where that family is visible. On a host whose TMPDIR
# is unwritable every one of those sentences points away from the cause, which the run had established one
# line down in the reason this case already asserted.
# ⚠ THE SUITE IS WHAT LET IT STAND. This case asserted the exit code, the reason and two `hasnt`s, and the
# EIO case above asserted the shared headline — so the wrong headline was PINNED here and survived a fully
# green run. The headline is asserted positively below and the read's is asserted ABSENT, so the two cases
# no longer disagree about which headline is right, and the mutation that reds this one is stated with it.
#
# ⚠ TMPDIR IS EXPORTED, NOT JUST SET, and that is the fixture's whole mechanism: `mktemp` is an external
# command and reads TMPDIR out of its ENVIRONMENT. Measured while building this case — with TMPDIR set but
# unexported, mktemp never sees it, writes under /tmp and SUCCEEDS, and this case passed vacuously against
# a loader that has the branch. A `.env`-shaped path that is a regular FILE is what mktemp cannot create
# under (ENOTDIR), so the readable `.env` here is doing double duty as the input and as the broken TMPDIR.
# shellcheck disable=SC2030  # TMPDIR is MEANT to be local to this expansion — a broken TMPDIR leaking into
# the rest of the suite would break every later fixture. The subshell is the containment, not an accident.
OUT="$( export TMPDIR="$ROOT/server/.env"; env_lib "$ROOT/server/.env" env_file_scan 2>&1 )"; RC=$?
eq  "no scratch file for the diagnostic: exit 1" 1 "$RC"
has "no scratch file for the diagnostic: the ⛔ REFUSED banner" "⛔ REFUSED — " "$OUT"
has "no scratch file for the diagnostic: the phase-A promise" \
    "Nothing was changed. The previous release is still serving." "$OUT"
# ⭐ THE HEADLINE, which the three assertions above cannot see: each of them passes on the read's refusal
# exactly as it does on this one, which is how the wrong one survived. Mutation, run against the fix: give
# the scratch refusal the read's headline back and this reds, alone, with the exit code and the banner
# still green — the measurement PR #191 made about exit-code-only cases, on this class.
has "no scratch file for the diagnostic: the headline names the scratch file the run actually failed on" \
    "could not be read: no usable scratch file for bash's read diagnostic" "$OUT"
has "no scratch file for the diagnostic: names the scratch file as the reason" \
    "No scratch file could be created for bash's read diagnostic" "$OUT"
hasnt "no scratch file for the diagnostic: never the READ's headline — no read was made on this path" \
    "was opened but could not be read to its end" "$OUT"
hasnt "no scratch file for the diagnostic: does not send the operator to \`dmesg\` for a scratch file" \
    "dmesg" "$OUT"
hasnt "no scratch file for the diagnostic: claims no disk or filesystem fault, which nothing here established" \
    "usually a disk or filesystem one" "$OUT"
hasnt "no scratch file for the diagnostic: does not report an open that succeeded as the finding" \
    "The open SUCCEEDED" "$OUT"
hasnt "no scratch file for the diagnostic: claims no path it did not establish was the one mktemp used" \
    "could not be created under" "$OUT"
hasnt "no scratch file for the diagnostic: no DB password is printed" "$FAKE_PW" "$OUT"
# THE OTHER WAY THE LOADER GETS NO SCRATCH FILE, and it is a DIFFERENT fault with a different fix
# (card#9933 review): `env_read_err_open` also fails when mktemp SUCCEEDS and this shell cannot OPEN what
# it named — status 2, where $TMPDIR is working and the realistic cause is the open-file limit. The
# refusal used to attribute both to mktemp and send both to $TMPDIR, so the reason is read off the status
# and this case is what holds it there.
# ⚠ WHAT THIS FIXTURE ESTABLISHES, AND WHAT IT DOES NOT. It reaches that return by SHADOWING `mktemp`
# with a shell function that succeeds and names a path in a directory that does not exist, so the `<>`
# open fails with ENOENT. That is not the realistic CAUSE — a real mktemp creates what it names, and what
# fails in the field is the descriptor limit, which no fixture here can produce without disturbing the
# fork the `$( )` around mktemp needs. So this establishes that the SECOND return is taken and that the
# reason written from it is the second one; it does not reproduce a host that ran out of descriptors.
env_scan_unopenable_scratch() { # the reader env_lib runs: mktemp succeeds, its path cannot be opened
  # shellcheck disable=SC2317  # reached through env_lib's "$@", which ShellCheck cannot follow
  mktemp() { printf '%s\n' "$T/no-such-dir/scratch"; }
  # shellcheck disable=SC2317
  env_file_scan
}
# No TMPDIR is exported here, and that is part of what the case says: this failure does not need one to
# be wrong, and the reason it produces must not mention one.
OUT="$( env_lib "$ROOT/server/.env" env_scan_unopenable_scratch 2>&1 )"; RC=$?
eq  "a scratch file that cannot be opened: exit 1" 1 "$RC"
has "a scratch file that cannot be opened: the ⛔ REFUSED banner" "⛔ REFUSED — " "$OUT"
has "a scratch file that cannot be opened: the same headline — no scratch file, whichever step failed" \
    "could not be read: no usable scratch file for bash's read diagnostic" "$OUT"
has "a scratch file that cannot be opened: the reason says the file WAS created and could not be opened" \
    "WAS created and this shell could not OPEN it" "$OUT"
# The number that advice names, pinned here because THIS is the fixture that reaches it — it needed no new
# mechanism, only this line (the card#9933 final review's MINOR-1). Mutation: drop `(\`ulimit -n\`)` from
# the status-2 reason in bin/deploy.sh and this reds, alone.
has "a scratch file that cannot be opened: names the number to read — the open-file limit" \
    "(\`ulimit -n\`)" "$OUT"
hasnt "a scratch file that cannot be opened: does not blame mktemp, which succeeded" \
    "\`mktemp\` failed" "$OUT"
hasnt "a scratch file that cannot be opened: does not send the operator to check \$TMPDIR" \
    "mktemp writes under \$TMPDIR" "$OUT"
# The twin, one variable away: the same file and the same reader, with a TMPDIR that IS a directory.
# shellcheck disable=SC2031  # the warning is about the broken-TMPDIR case's export — two cases up, with
# the unopenable-scratch one between them — not reaching here, which is the arrangement this twin depends
# on: each case exports its own, and neither sees the other's.
OUT="$( export TMPDIR="$T"; env_lib "$ROOT/server/.env" env_file_scan 2>&1 )"; RC=$?
eq "a working TMPDIR, same .env: the scan passes" 0 "$RC"

mkfix wrong_app_env; sed -i 's/^APP_ENV=.*/APP_ENV=local/' "$ROOT/server/.env"
run_refusal "APP_ENV=local" "APP_ENV is 'local'" --dry-run

mkfix debug_on; sed -i 's/^APP_DEBUG=.*/APP_DEBUG=true/' "$ROOT/server/.env"
run_refusal "APP_DEBUG=true" "APP_DEBUG is 'true'" --dry-run

# card#9561 r3 MINOR 1. APP_DEBUG was the one A5 check still comparing the TEXT. Env::get lowercases before
# the app sees it and `server/config/app.php` is `(bool) env('APP_DEBUG', false)`, so FALSE, False, (false)
# and (FALSE) each reach the app as debug OFF — a correct production config that A5 refused. The twin, one
# variable away, is `APP_DEBUG=true` above, still refused; with the rule reverted to `= "false"` every case
# below reds at the EXIT level (measured 2026-09-15 against server/vendor's Illuminate\Support\Env).
mkfix debug_literals
debug_is() { # debug_is <text> <expected exit>
  sed -i "s/^APP_DEBUG=.*/APP_DEBUG=$1/" "$ROOT/server/.env"; run --dry-run
  eq "APP_DEBUG=$1: exit $2" "$2" "$RC"
}
debug_is FALSE 0
debug_is False 0
debug_is '(false)' 0
debug_is '(FALSE)' 0
# `off` is a non-empty string, which PHP reads as TRUE — debug ON, and refused. `0` and `null` ARE cast to
# false, and are refused too: a production host says debug is off rather than resolving to it.
debug_is off 1
debug_is 0 1
debug_is null 1
sed -i 's/^APP_DEBUG=.*/APP_DEBUG=off/' "$ROOT/server/.env"; run --dry-run
has "APP_DEBUG=off: names the value and what is wanted" "APP_DEBUG is 'off', not 'false'" "$OUT"

mkfix no_key; sed -i 's/^APP_KEY=.*/APP_KEY=/' "$ROOT/server/.env"
run_refusal "empty APP_KEY" "APP_KEY is empty" --dry-run

mkfix sqlite_prod; sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' "$ROOT/server/.env"
run_refusal "sqlite in prod" "DB_CONNECTION is 'sqlite'" --dry-run
hasnt "sqlite refusal leaks no DB password" "$FAKE_PW" "$OUT"

mkfix cache_array; sed -i 's/^CACHE_STORE=.*/CACHE_STORE=array/' "$ROOT/server/.env"
run_refusal "non-persistent cache store" "CACHE_STORE is 'array'" --dry-run

section "A5 — TLS to the store is required for a store on another host, and only there (FLEET-STATE.md § 6.1)"
# One fixture; each case rewrites its .env from write_env's, which sets no DB_HOST, DB_SOCKET or DB_URL and
# sets MYSQL_ATTR_SSL_CA. The .env is git-ignored, so rewriting it leaves the tree clean for A4. Every
# refusal here has a same-host twin that differs in the one key deciding locality, and passes.
store_env() { # store_env <KEY=value…> — write_env's .env with the CA removed, then each pair appended
  write_env "$ROOT"; sed -i '/^MYSQL_ATTR_SSL_CA=/d' "$ROOT/server/.env"
  local kv; for kv in "$@"; do printf '%s\n' "$kv" >> "$ROOT/server/.env"; done
}
store_passes() { # store_passes <label> <needle> <KEY=value…>
  local label="$1" needle="$2"; shift 2
  store_env "$@"; run --dry-run
  eq  "$label: exit 0" 0 "$RC"
  has "$label: names why TLS is not required" "$needle" "$OUT"
}
mkfix store_locality
store_passes "DB_HOST unset, no CA" "store on this host (loopback; DB_HOST is unset, and server/config/database.php defaults it to 127.0.0.1) — TLS not required"
store_passes "DB_HOST=localhost, no CA" "store on this host (loopback; DB_HOST is 'localhost') — TLS not required" DB_HOST=localhost
store_passes "DB_HOST=127.0.0.1, no CA" "store on this host (loopback; DB_HOST is '127.0.0.1') — TLS not required" DB_HOST=127.0.0.1
store_passes "DB_HOST=::1, no CA" "store on this host (loopback; DB_HOST is '::1') — TLS not required" DB_HOST=::1
store_passes "DB_SOCKET over a remote DB_HOST, no CA" "store on this host (socket; DB_SOCKET names a Unix socket) — TLS not required" \
  DB_HOST=db.internal DB_SOCKET=/run/mysqld/mysqld.sock
store_passes "DB_URL host localhost over a remote DB_HOST, no CA" "store on this host (loopback; DB_URL names a loopback host) — TLS not required" \
  DB_HOST=db.internal DB_URL=mysql://u:p@localhost/mezzanine
hasnt "DB_URL localhost: the URL's credentials are not printed" "u:p" "$OUT"
store_env DB_HOST=localhost MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt; run --dry-run
eq  "same host with a CA set: exit 0 — the operator's choice is not refused" 0 "$RC"
has "same host with a CA set: says it is not required" "MYSQL_ATTR_SSL_CA is set, though not required" "$OUT"
has "same host with a CA set: warns that pdo_mysql then requires TLS to it" "a MariaDB that offers no TLS refuses every connection" "$OUT"
store_env DB_HOST=localhost; run --dry-run
hasnt "same host without a CA: no TLS warning" "offers no TLS" "$OUT"

store_env DB_HOST=db.internal
run_refusal "remote DB_HOST, no CA" "MYSQL_ATTR_SSL_CA is unset for a store on another host (DB_HOST is 'db.internal')" --dry-run
store_env DB_HOST=db.internal MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt; run --dry-run
eq    "remote DB_HOST with a CA: exit 0" 0 "$RC"
hasnt "remote DB_HOST with a CA: prints no same-host line" "store on this host" "$OUT"
# DB_HOST unset would pass on its own (the case above), so this refusal is the URL's host replacing it.
store_env DB_URL=mysql://u:p@db.internal/mezzanine "DB_PASSWORD=$FAKE_PW"
run_refusal "remote DB_URL, no CA" "MYSQL_ATTR_SSL_CA is unset for a store on another host (DB_URL names a remote host)" --dry-run
hasnt "remote DB_URL: the URL's credentials are not printed" "u:p" "$OUT"
hasnt "remote DB_URL: the URL's host is not printed" "db.internal" "$OUT"
hasnt "remote DB_URL: no DB password is printed" "$FAKE_PW" "$OUT"
# Fail closed on what A5 does not follow: a query string Laravel merges over host, and a socket keyword.
store_env DB_URL=mysql://u:p@localhost/mezzanine?host=db.internal
run_refusal "DB_URL whose query sets host, no CA" "its query string sets host or unix_socket" --dry-run
store_env DB_HOST=db.internal DB_SOCKET=null
run_refusal "DB_SOCKET=null (Laravel: no socket) over a remote DB_HOST, no CA" "store on another host (DB_HOST is 'db.internal')" --dry-run
# A `%0A` decodes to a trailing newline, which `$(…)` would strip back to `localhost`. Its twin is the passing
# `DB_URL host localhost` case above.
store_env 'DB_URL=mysql://u:p@localhost%0A/mezzanine'
run_refusal "DB_URL host localhost%0A, no CA" "MYSQL_ATTR_SSL_CA is unset for a store on another host (DB_URL names a remote host)" --dry-run
hasnt "DB_URL host localhost%0A: the URL's credentials are not printed" "u:p" "$OUT"
hasnt "DB_URL host localhost%0A: no DB password is printed" "$FAKE_PW" "$OUT"
# card#9561 r3 MAJOR, the same defect one byte later: a `%00` decodes to a NUL, which `$( )` does not strip
# but DELETES, so a host read back through one was `localhost` — this store certified as loopback, exit 0,
# no CA, while pdo_mysql takes the DSN's host as a C string and resolves `local` over the network. The host
# is judged inside the `php` that parsed the URL now, and a TOKEN crosses back. Same twin as above.
store_env 'DB_URL=mysql://u:p@local%00host/mezzanine'
run_refusal "DB_URL host local%00host, no CA" "MYSQL_ATTR_SSL_CA is unset for a store on another host (DB_URL names a remote host)" --dry-run
hasnt "DB_URL host local%00host: the URL's credentials are not printed" "u:p" "$OUT"
hasnt "DB_URL host local%00host: no DB password is printed" "$FAKE_PW" "$OUT"
# A percent-encoding that decodes to a name that IS loopback is still loopback: the decode is Laravel's own
# (ConfigurationUrlParser rawurldecodes every component), so this is not a narrowing, and it is the control
# that says the refusals above are about the decoded BYTES and not about the `%`.
store_passes "DB_URL host %6cocalhost (decodes to localhost), no CA" \
  "store on this host (loopback; DB_URL names a loopback host) — TLS not required" \
  'DB_URL=mysql://u:p@%6cocalhost/mezzanine'

section "A5 — a key in a form env_get does not read exactly as Laravel does is refused by name (card#9561 r1)"
# Each case is a .env Laravel reads as a store on ANOTHER host (or a non-persistent cache), in a form the old reader
# took as unset, loopback or a socket. Measured against vlucas/phpdotenv v5.7.0 + Illuminate\Support\Env on
# 2026-09-14. The refusal names the key and prints no line: a DB_URL line carries the password.
# The twins that pass: the plain `DB_HOST=db.internal` refusal above says "for a store on another host", not this.
unread_refused() { # unread_refused <label> <KEY> — after the caller wrote the .env
  run_refusal "$1" ".env defines $2 in a form this deploy does not read" --dry-run
  hasnt "$1: no URL credentials are printed" "u:p" "$OUT"
  hasnt "$1: no DB password is printed" "$FAKE_PW" "$OUT"
  hasnt "$1: no TLS verdict is reached on a value that was not read" "store on this host" "$OUT"
}
store_env 'export DB_HOST=db.internal';                                  unread_refused "export DB_HOST" DB_HOST
store_env 'DB_HOST = db.internal';                                       unread_refused "whitespace around = in DB_HOST" DB_HOST
store_env '"DB_HOST"=db.internal';                                       unread_refused "a quoted name DB_HOST" DB_HOST
store_env DB_HOST=localhost 'export DB_URL=mysql://u:p@db.internal/mezzanine'; unread_refused "export DB_URL over a plain DB_HOST=localhost" DB_URL
hasnt "export DB_URL: the URL's host is not printed" "db.internal" "$OUT"
store_env REMOTE=db.internal 'DB_URL=mysql://u:p@${REMOTE}/mezzanine';  unread_refused "\${REMOTE} interpolated into DB_URL" DB_URL
store_env REMOTE=db.internal 'DB_URL="mysql://u:p@${REMOTE}/mezzanine"'; unread_refused "\${REMOTE} interpolated into a double-quoted DB_URL" DB_URL
store_env DB_HOST=localhost DB_HOST=db.internal;                         unread_refused "DB_HOST defined twice (local, then remote)" DB_HOST
# The duplicate above is read the same way by the old reader (`tail -n 1` and Dotenv both take the last
# line), so it reds here only on the REASON. This one flips the verdict: the old grep matched only the
# plain line and passed a remote store, while Laravel applies both and connects to db.internal.
store_env DB_HOST=localhost 'export DB_HOST=db.internal';                unread_refused "a plain DB_HOST=localhost with a later export DB_HOST naming a remote host" DB_HOST
store_env DB_HOST=db.internal DB_SOCKET=/run/mysqld/mysqld.sock 'export DB_SOCKET='; unread_refused "a later export DB_SOCKET= emptying the socket" DB_SOCKET
store_env DB_HOST=db.internal DB_SOCKET=/run/mysqld/mysqld.sock DB_SOCKET; unread_refused "a later bare DB_SOCKET clearing the socket" DB_SOCKET
store_env 'DB_HOST=localhost # was db.internal';                         unread_refused "an inline comment on DB_HOST" DB_HOST
# CACHE_STORE: write_env sets it once, so the case REPLACES that line rather than appending a second one.
store_env; sed -i 's/^CACHE_STORE=.*/export CACHE_STORE=array/' "$ROOT/server/.env"; unread_refused "export CACHE_STORE=array" CACHE_STORE
store_env; sed -i 's/^CACHE_STORE=.*/CACHE_STORE=array # per-request/' "$ROOT/server/.env"; unread_refused "CACHE_STORE=array with an inline comment" CACHE_STORE
store_env; sed -i "s/^CACHE_STORE=.*/CACHE_STORE=\"'array'\"/" "$ROOT/server/.env"; unread_refused "CACHE_STORE doubly quoted (Env::get strips the inner pair)" CACHE_STORE
# The forms env_get reads keep reading: a quoted value, a comment line naming the key, a `$` inside '…'.
store_passes "DB_SOCKET double-quoted over a remote DB_HOST, no CA" "store on this host (socket; DB_SOCKET names a Unix socket) — TLS not required" \
  DB_HOST=db.internal 'DB_SOCKET="/run/mysqld/mysqld.sock"'
store_passes "DB_SOCKET single-quoted over a remote DB_HOST, no CA" "store on this host (socket; DB_SOCKET names a Unix socket) — TLS not required" \
  DB_HOST=db.internal "DB_SOCKET='/run/mysqld/mysqld.sock'"
store_passes "a commented-out remote DB_HOST above DB_HOST=localhost, no CA" "store on this host (loopback; DB_HOST is 'localhost') — TLS not required" \
  '# DB_HOST=db.internal' '  # export DB_HOST=db.internal' DB_HOST=localhost
store_env DB_HOST=db.internal "MYSQL_ATTR_SSL_CA='/etc/ssl/\$certs/ca.crt'"; run --dry-run
eq  "a \$ inside a single-quoted value is literal to Dotenv, and read: exit 0" 0 "$RC"

section "A5 — a value Laravel reads as null, false or empty is not the TEXT of it (card#9561 r1)"
# Illuminate\Support\Env::get maps `null`, `false`, `empty` and their `(…)` forms, case-insensitively, to
# PHP null, false and '' before any config file sees them (measured 2026-09-15 against server/vendor).
# config/database.php wraps the CA in array_filter, which drops all three — so each of these is a store on
# another host reached with NO TLS, while the .env line reads as a CA that is set. The twin that passes is
# the `remote DB_HOST with a CA` case above, which differs only in the value being a path.
ca_literal_refused() { # ca_literal_refused <literal>
  store_env DB_HOST=db.internal "MYSQL_ATTR_SSL_CA=$1"
  run_refusal "remote DB_HOST, MYSQL_ATTR_SSL_CA=$1 (Laravel: no CA)" \
    "MYSQL_ATTR_SSL_CA is unset for a store on another host (DB_HOST is 'db.internal')" --dry-run
  has "MYSQL_ATTR_SSL_CA=$1: says why the literal is not a CA" "array_filter drops the option" "$OUT"
}
ca_literal_refused null
ca_literal_refused NULL
ca_literal_refused '(null)'
ca_literal_refused false
ca_literal_refused empty
ca_literal_refused '(empty)'
# card#9561 r2 BLOCKER. The gate is `array_filter`, which with no callback drops every PHP-falsy value —
# a strictly LARGER set than Env::get's literals, and the string '0' is in it. Each of these three was a
# store on another host connecting with no TLS while A5 read the line as a CA that is set (measured
# 2026-09-15 against server/vendor: ca=ABSENT for all three, ca=SET for all four twins below).
ca_literal_refused 0
ca_literal_refused '"0"'
ca_literal_refused "'0'"
# The twins, one variable away: array_filter KEEPS these, the connection really does carry a CA by that
# name, and pdo_mysql fails closed at connect on a file that does not exist rather than silently.
ca_kept() { # ca_kept <value>
  store_env DB_HOST=db.internal "MYSQL_ATTR_SSL_CA=$1"; run --dry-run
  eq "remote DB_HOST, MYSQL_ATTR_SSL_CA=$1 (Laravel: a CA by that name): exit 0" 0 "$RC"
}
ca_kept 0.0
ca_kept 0e0
ca_kept off
ca_kept true
# On a store on THIS host the same literal is still unset: no "set, though not required" line, no TLS warning.
store_passes "DB_HOST=localhost with MYSQL_ATTR_SSL_CA=null" \
  "store on this host (loopback; DB_HOST is 'localhost') — TLS not required" DB_HOST=localhost MYSQL_ATTR_SSL_CA=null
hasnt "MYSQL_ATTR_SSL_CA=null on this host: not reported as a CA that is set" "though not required" "$OUT"
store_passes "DB_HOST=localhost with MYSQL_ATTR_SSL_CA=0" \
  "store on this host (loopback; DB_HOST is 'localhost') — TLS not required" DB_HOST=localhost MYSQL_ATTR_SSL_CA=0
hasnt "MYSQL_ATTR_SSL_CA=0 on this host: not reported as a CA that is set" "though not required" "$OUT"
# APP_KEY reads through the same rule: env('APP_KEY') of `null` — or of `0` — is no key, and every session
# and encrypted column depends on it. The control is every other case in this file, whose APP_KEY is a key.
store_env; sed -i 's/^APP_KEY=.*/APP_KEY=null/' "$ROOT/server/.env"
run_refusal "APP_KEY=null (Laravel: no key at all)" "APP_KEY is empty" --dry-run
store_env; sed -i 's/^APP_KEY=.*/APP_KEY=0/' "$ROOT/server/.env"
run_refusal "APP_KEY=0 (Laravel: a falsy key, which is no key)" "APP_KEY is empty" --dry-run

# card#9561 r2 MAJOR 1. CACHE_STORE decided on the TEXT, case-sensitively, while Env::get lowercases
# first: `NULL`, `Null`, `(null)` and `(NULL)` all reach the app as PHP null, `config('cache.default')`
# is then null, `CacheManager::getDefaultDriver()` falls back to `'null'` and `getConfig('null')` returns
# the DISCARD driver — a cache that keeps nothing, which is the login-path enumeration oracle back open
# (measured 2026-09-15 against server/vendor: cache_driver=null(DISCARD) for each).
cache_refused() { # cache_refused <text>
  store_env; sed -i "s/^CACHE_STORE=.*/CACHE_STORE=$1/" "$ROOT/server/.env"
  run_refusal "CACHE_STORE=$1 (Laravel: a store that keeps nothing)" "which does not survive a request" --dry-run
}
cache_refused NULL
cache_refused Null
cache_refused '(null)'
cache_refused '(NULL)'
cache_refused '"null"'
# The twin: a store that IS persistent passes. `Array` is not `array` — Env::get lowercases only its own
# literals, so the app receives the string 'Array', which names no store in config/cache.php and throws
# "Cache store [Array] is not defined" at boot (measured 2026-09-15). Loud is not this check's hole.
store_env; sed -i 's/^CACHE_STORE=.*/CACHE_STORE=file/' "$ROOT/server/.env"; run --dry-run
eq "CACHE_STORE=file: exit 0 — a persistent store is not refused" 0 "$RC"

section "A5 — a .env Laravel's own parser does not read as the lines it is written in (card#9561 r2)"
# env_get reads a LINE; Dotenv reads the FILE. Two file-level defects make every key's value
# unestablished — including the keys A5 never reads — and the refusal must land in phase A, where
# nothing has been touched, rather than at boot inside the maintenance window. Every fixture here was
# measured against server/vendor's phpdotenv v5.7.0 on 2026-09-15. The refusal names the line NUMBER.
scan_refused() { # scan_refused <label> <needle>
  run_refusal "$1" "$2" --dry-run
  hasnt "$1: no DB password is printed" "$FAKE_PW" "$OUT"
  hasnt "$1: no store verdict is reached on a file that was not established" "store on this host" "$OUT"
}
# 1. A `KEY="` value its line does not close swallows the lines below it. Laravel ends with NO DB_SOCKET
# and a remote DB_HOST with no CA, while a line-at-a-time reader saw a socket and exempted the store.
store_env DB_HOST=db.internal 'NOTE="line one' DB_SOCKET=/run/mysqld/mysqld.sock 'line three"'
scan_refused "a multi-line value swallowing DB_SOCKET" 'opens a "…" value that its own line does not close'
# Worse: with nothing ever closing it, Dotenv DISCARDS the buffer and every line in it, with no exception.
store_env DB_HOST=db.internal 'NOTE="line one' DB_SOCKET=/run/mysqld/mysqld.sock
scan_refused "a multi-line value nothing ever closes" 'opens a "…" value that its own line does not close'
# The twin, one variable away: the same NOTE closed on its own line is no fold, and the socket is read.
store_passes "a closed \"…\" NOTE above DB_SOCKET" \
  "store on this host (socket; DB_SOCKET names a Unix socket) — TLS not required" \
  DB_HOST=db.internal 'NOTE="line one"' DB_SOCKET=/run/mysqld/mysqld.sock
# 2. ONE line the parser rejects fails the WHOLE file: Laravel reads nothing from it and every request and
# artisan command dies at boot. A5 used to certify such a file — "what Laravel reads is established" for a
# file Laravel cannot read at all. None of these keys is one A5 reads.
store_env DB_HOST=127.0.0.1 "NOTE='oops"
scan_refused "an unterminated '…' on a key A5 never reads" "a missing closing quote"
store_env DB_HOST=127.0.0.1 'MAIL_FROM_NAME=Mezzanine App'
scan_refused "an unquoted value carrying a space" "unexpected whitespace"
store_env DB_HOST=127.0.0.1 'NOTE="a\qb"'
scan_refused "an unknown escape inside a \"…\" value" "an unexpected escape sequence"
store_env DB_HOST=127.0.0.1 '=oops'
scan_refused "a line with no name before its =" "an unexpected equals"
store_env DB_HOST=127.0.0.1 'NOT-A-NAME=x'
scan_refused "a name Dotenv rejects" "a name outside [A-Za-z0-9_.]"
# card#9561 r2 MINOR 1: the shape that would have discriminated env_get's own duplicate-key guard — two
# DB_HOST lines whose concatenation satisfies the single-quoted form. The file scan refuses it one step
# earlier (Dotenv rejects an unterminated '…'), which is why that guard is gone rather than covered.
store_env "DB_HOST='a" "DB_HOST=b'"
scan_refused "a duplicate key split across an unterminated quote" "a missing closing quote"
# card#9561 r3 MINOR 4. THE shape where the multi-line rule is the only refuser: a `="` inside an otherwise
# valid UNQUOTED value. phpdotenv ACCEPTS this file and yields only APP_ENV and DB_HOST — the fold swallows
# DB_SOCKET and is discarded at EOF with no exception — so Laravel goes to db.internal over TCP with no CA,
# while a line-at-a-time reader sees the socket and exempts the store. Every other fixture in this section
# is ALSO refused by the value transducer, so with the multi-line rule reverted only this one moves the
# EXIT code (measured 2026-09-15 against server/vendor's phpdotenv v5.7.0, both halves).
store_env DB_HOST=db.internal 'NOTE=a="b' DB_SOCKET=/run/mysqld/mysqld.sock
scan_refused "an unquoted value carrying =\" swallows the DB_SOCKET line below it" 'opens a "…" value that its own line does not close'
# card#9561 r3 MINOR 3. A NUL byte blinds every reader in this script: bash's `read` stops at the first one,
# so no line below it is scanned, and `grep` matches nothing in a file carrying one, so every key comes back
# "unset". The deploy stopped anyway — on APP_ENV, naming a cause that was not the real one, which is the
# failure r2's `tr` control exists to prevent. phpdotenv reads the NUL as a character like any other and
# Laravel boots on this file (measured 2026-09-15: the parser yields all four keys, NOTE with the NUL in it).
store_env DB_HOST=127.0.0.1; printf 'NOTE=a\0b\n' >> "$ROOT/server/.env"
scan_refused "a NUL byte in .env" "carries a NUL byte"
has   "a NUL byte in .env: names the line it is on" "line $(wc -l < "$ROOT/server/.env") carries a NUL byte" "$OUT"
hasnt "a NUL byte in .env: does not name the wrong cause (every key read as unset)" "APP_ENV is 'unset'" "$OUT"
# The twin, one byte away: the same file with a printable character in place of the NUL is read normally.
store_env DB_HOST=127.0.0.1; printf 'NOTE=aXb\n' >> "$ROOT/server/.env"
run --dry-run
eq  "the same .env with no NUL: exit 0" 0 "$RC"
# The twins: every form Dotenv DOES parse passes the scan — including the ones env_get then refuses BY
# NAME, which is a different refusal, and including a `#` before a `="` (Dotenv's own comment rule).
store_passes "interpolation, an export prefix, an inline comment, a spaced = and a bare key all parse" \
  "store on this host (loopback; DB_HOST is '127.0.0.1') — TLS not required" \
  DB_HOST=127.0.0.1 APP_NAME=Mezzanine 'MAIL_FROM_NAME="${APP_NAME}"' 'export FOO=1' 'BAR=2 # a comment' \
  'BAZ = 3' '# FOO="an unterminated quote inside a comment' QUX

section "A5 — a line ENDS where Laravel's parser ends it, in every reader (card#9561 r4 BLOCKER)"
# Dotenv\Parser\Parser::parse splits on `\r\n`, `\n` or `\r` alike. Until r5, `env_file_scan` split that way
# and `env_get` shelled to `grep`, whose terminator is `\n` ONLY — two readers, two notions of "a line", and
# the scan CERTIFIED the file for the one it did not implement. Measured at 4626060 against the real boot
# path (server/vendor's phpdotenv v5.7.0 through Illuminate\Support\Env, then server/config/database.php
# through ConfigurationUrlParser, PHP 8.5.4): a `.env` ending `# note\rDB_HOST=db.internal` reaches Laravel
# as a REMOTE store with NO CA, while `--dry-run` exited 0 saying "store on this host (loopback; DB_HOST is
# unset…) — TLS not required". Both readers go through `env_lines_load` now, so there is ONE split — which
# is also why a `\r` is not refused: a file Laravel reads is one this script reads the same way.
env_after() { # env_after <printf-format> — store_env's .env with the format appended, its escapes expanded
  store_env; printf '%b' "$1" >> "$ROOT/server/.env"
}
crlf_env() { # crlf_env <KEY=value…> — store_env's .env with every line ending CRLF, as a Windows editor writes it
  store_env "$@"; sed -i 's/$/\r/' "$ROOT/server/.env"
}
mkfix env_line_endings
# THE BLOCKER: one lone CR, no LF. Two lines to Dotenv, one to a `\n`-only reader, and the line it hides is
# the one that decides the store.
env_after '# note\rDB_HOST=db.internal\n'
run_refusal "a lone CR above DB_HOST=db.internal" "MYSQL_ATTR_SSL_CA is unset for a store on another host (DB_HOST is 'db.internal')" --dry-run
hasnt "a lone CR above a remote DB_HOST: no same-host verdict is reached" "ok — store on this host" "$OUT"
hasnt "a lone CR above a remote DB_HOST: no DB password is printed" "$FAKE_PW" "$OUT"
# The twin one byte away: the same file with an LF. It refused before this round and refuses now, which is
# what makes the case above evidence about the CR rather than about the fixture.
env_after '# note\nDB_HOST=db.internal\n'
run_refusal "the same file with an LF in place of the CR" "MYSQL_ATTR_SSL_CA is unset for a store on another host (DB_HOST is 'db.internal')" --dry-run
# The other twin: a CR is not itself a refusal. Laravel boots on this file and reaches a loopback store,
# and so does A5 — the line the CR ended is read, not narrowed away.
env_after '# note\rDB_HOST=localhost\n'
run --dry-run
eq  "a lone CR above DB_HOST=localhost: exit 0" 0 "$RC"
has "a lone CR above DB_HOST=localhost: the store is judged on the line the CR ended" \
  "store on this host (loopback; DB_HOST is 'localhost') — TLS not required" "$OUT"
# The same root, the login-path enumeration oracle (docs/PLAN.md § 5): a CACHE_STORE the app receives as
# `array` while A5's case never fired — the check written to stop that shipping it.
store_env; sed -i '/^CACHE_STORE=/d' "$ROOT/server/.env"; printf '%b' '# note\rCACHE_STORE=array\n' >> "$ROOT/server/.env"
run_refusal "a lone CR above CACHE_STORE=array" "which does not survive a request" --dry-run
# The same root again: a DB_URL naming another host, hidden behind a DB_HOST=localhost that ends in a CR.
env_after 'DB_HOST=localhost\rDB_URL=mysql://u:p@db.internal/mezzanine\n'
run_refusal "a DB_URL hidden behind a CR-terminated DB_HOST=localhost" "MYSQL_ATTR_SSL_CA is unset for a store on another host (DB_URL names a remote host)" --dry-run
hasnt "a hidden DB_URL: the URL's credentials are not printed" "u:p" "$OUT"
hasnt "a hidden DB_URL: the URL's host is not printed" "db.internal" "$OUT"
# THE MIRROR IMAGE: a whole-file CRLF `.env`, which Laravel boots on perfectly. `grep` saw the whole file as
# ONE line, so every key came back "in a form this deploy does not read" and A5 refused on APP_ENV — naming
# a cause that was not the real one and never naming the line endings. It simply works now, as it does for
# Laravel, which is the product behaviour a `\r` refusal would have got wrong.
crlf_env DB_HOST=localhost
run --dry-run
eq  "a whole-file CRLF .env, loopback store: exit 0" 0 "$RC"
has "a whole-file CRLF .env: the store is judged" "store on this host (loopback; DB_HOST is 'localhost') — TLS not required" "$OUT"
hasnt "a whole-file CRLF .env: no key is reported unreadable" "in a form this deploy does not read" "$OUT"
# Its remote twin still refuses — on the host, which is the reason, and not on the line endings.
crlf_env DB_HOST=db.internal
run_refusal "a whole-file CRLF .env, remote store, no CA" "MYSQL_ATTR_SSL_CA is unset for a store on another host (DB_HOST is 'db.internal')" --dry-run
hasnt "a whole-file CRLF .env, remote store: not refused for being unreadable" "in a form this deploy does not read" "$OUT"
# The NUL refusal counts its line out of that same load now (`${#ENV_LINES[@]}` in place of the newlines it
# used to count), so the number has to survive a CR: the NUL below is on Dotenv's line `wc -l` + 2, because
# the two CRs end two lines that `\n` alone does not.
store_env; printf 'A=1\rB=2\rNOTE=a\0b\n' >> "$ROOT/server/.env"
scan_refused "a NUL byte below CR-terminated lines" "carries a NUL byte"
has "a NUL below CR-terminated lines: the line named is Dotenv's, not \\n's" \
  "line $(( $(wc -l < "$ROOT/server/.env") + 2 )) carries a NUL byte" "$OUT"

# card#9561 r4 MINOR 3. The hosts the TLS refusal offers as same-host are ENV_LOOPBACK_HOSTS interpolated,
# not prose restating it, so the message cannot drift from the array both deciders read. The expected text
# is DERIVED from that array in the release under test rather than typed here — a literal would be the same
# unguarded copy one line further out. It discriminates: the prose it replaces listed three of the four
# (`[::1]`, which is how parse_url returns ::1 out of a DB_URL, was missing from it).
loopback_hosts="$(sed -n "s/^ENV_LOOPBACK_HOSTS=(\(.*\))$/\1/p" "$DEPLOY" | tr -d "'")"
eq "the release names some same-host hosts to offer" "yes" "$([ -n "$loopback_hosts" ] && echo yes || echo no)"
store_env DB_HOST=db.internal
run_refusal "remote DB_HOST, no CA: the same-host hosts are named out of ENV_LOOPBACK_HOSTS" \
  "or DB_HOST one of $loopback_hosts." --dry-run

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
for hide in crontab flock fuser setsid ps cgi-fcgi; do
  mkfix "tool_$hide"; path_without "$T/path-$hide" "$hide"
  : > "$CALL_LOG"; OUT="$(PATH="$T/path-$hide" MEZZ_DEPLOY_ROOT="$ROOT" "$ROOT/bin/deploy.sh" --dry-run 2>&1)"; RC=$?
  eq  "no $hide: exit 1" 1 "$RC"
  has "no $hide: names it" "missing required command(s): $hide" "$OUT"
done
# card#9561 r2 MINOR 2: A5's reading of `.env` must not shell out to a command A1 does not require. It
# once lowercased through `tr`, and with `tr` off PATH every value compared equal to '' — so the APP_KEY
# guard refused every deploy on this host, naming a cause that was not the real one. (A10b's key-drift
# WARNING still uses `tr`, and degrades to no warning here; that is a separate gap, not A5's.)
mkfix tool_tr; path_without "$T/path-no-tr" tr
: > "$CALL_LOG"; OUT="$(PATH="$T/path-no-tr" MEZZ_DEPLOY_ROOT="$ROOT" "$ROOT/bin/deploy.sh" --dry-run 2>&1)"; RC=$?
eq    "no tr on PATH: exit 0 — A5 reads .env with bash alone" 0 "$RC"
hasnt "no tr on PATH: APP_KEY is not refused for want of a lowercasing tool" "APP_KEY is empty" "$OUT"

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
run_refusal "opcache.validate_timestamps=Off in the FPM ini" "opcache.validate_timestamps is off for" --dry-run
has "timestamps off: names the deploy user's app pool" "[178815168175465]" "$OUT"
has "timestamps off: names the stream pool too — it serves the app's code as well" "[mezz-stream]" "$OUT"
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
run_refusal "opcache.preload set" "opcache.preload is set for" --dry-run
has "preload: names the app pool" "[178815168175465]" "$OUT"

mkfix fpm_no_pool; sed -i 's/^user = .*/user = somebody-else/' "$POOL" "$STREAM_POOL"
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
  "[178815168175465] under $MEZZ_DOCROOT/.user.ini" --dry-run
rm -f "$MEZZ_DOCROOT/.user.ini"; run --dry-run
eq  "control: the same host with that .user.ini removed deploys" 0 "$RC"
printf 'opcache.validate_timestamps=\n' > "$MEZZ_DOCROOT/.user.ini"
run_refusal "an EMPTY validate_timestamps in a .user.ini (PHP reads it as off)" "[178815168175465] under $MEZZ_DOCROOT/.user.ini" --dry-run

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

section "REFUSAL — the feed stream's host conditions (A14: § 8.3 R1's ini half, R2's pool half)"
# Control, mutant, control on ONE host wherever the variable can be put back.
mkfix r1_zlib
run --dry-run
eq  "control: the host's ini (zlib off) deploys" 0 "$RC"
export STUB_ZLIB=On
run_refusal "zlib.output_compression on in the FPM ini" "zlib.output_compression is on for [mezz-stream]" --dry-run
has "zlib on: names what it does to the stream (measured)" "measured to hold the stream until the request ends" "$OUT"
export STUB_ZLIB=Off; run --dry-run
eq  "control: the same host with zlib off again deploys" 0 "$RC"

mkfix r1_pool_override
printf 'php_admin_flag[zlib.output_compression] = on\n' >> "$POOL"
run --dry-run
eq  "control: zlib on for ANOTHER pool of this user (not the stream's) is not the stream's hazard" 0 "$RC"
printf 'php_admin_flag[zlib.output_compression] = on\n' >> "$STREAM_POOL"
run_refusal "zlib.output_compression on in the STREAM pool's override" "zlib.output_compression is on for [mezz-stream]" --dry-run

mkfix r1_uini_abort
printf 'ignore_user_abort = On\n' > "$MEZZ_DOCROOT/.user.ini"
run_refusal "ignore_user_abort on in a .user.ini over the app" "ignore_user_abort is on for [mezz-stream] under $MEZZ_DOCROOT/.user.ini" --dry-run
rm -f "$MEZZ_DOCROOT/.user.ini"; run --dry-run
eq  "control: the same host with that .user.ini removed deploys" 0 "$RC"

mkfix r1_ini_abort; export STUB_IUA=On
run_refusal "ignore_user_abort on in the FPM ini" "ignore_user_abort is on for [mezz-stream]" --dry-run

mkfix r1_handler; export STUB_OH=ob_gzhandler
run_refusal "output_handler = ob_gzhandler" "output_handler is 'ob_gzhandler' for [mezz-stream]" --dry-run
export STUB_OH="no value"; run --dry-run
eq  "control: no output_handler deploys" 0 "$RC"

mkfix r2_unset; unset MEZZ_STREAM_POOL
run_refusal "MEZZ_STREAM_POOL unset" "MEZZ_STREAM_POOL is unset" --dry-run
export MEZZ_STREAM_POOL=mezz-stream; run --dry-run
eq  "control: the same host with the stream pool named deploys" 0 "$RC"

mkfix r2_missing; export MEZZ_STREAM_POOL=no-such-pool
run_refusal "MEZZ_STREAM_POOL names a pool that is not defined" "MEZZ_STREAM_POOL names [no-such-pool], and no pool of that name is defined" --dry-run

mkfix r2_other_user; sed -i 's/^user = .*/user = www-data/' "$STREAM_POOL"
run_refusal "the stream pool runs as another user (SIGTERM would need root)" "the stream pool [mezz-stream] runs as 'www-data'" --dry-run

mkfix r2_terminate; sed -i 's/^request_terminate_timeout = .*/request_terminate_timeout = 30s/' "$STREAM_POOL"
run_refusal "request_terminate_timeout 30s on the stream pool" "request_terminate_timeout is '30s'" --dry-run
sed -i 's/^request_terminate_timeout = .*/request_terminate_timeout = 0/' "$STREAM_POOL"; run --dry-run
eq  "control: request_terminate_timeout 0 again deploys" 0 "$RC"

mkfix r2_no_status_path; sed -i '/^pm.status_path/d' "$STREAM_POOL"
run_refusal "no pm.status_path on the stream pool" "pm.status_path is not set" --dry-run

mkfix r2_no_status_listen; sed -i '/^pm.status_listen/d' "$STREAM_POOL"
run_refusal "no pm.status_listen on the stream pool" "pm.status_listen is not set" --dry-run
has "no status_listen: says why it is not optional (measured)" "queues behind the very streams it must list" "$OUT"

mkfix r2_status_down; : > "$T/knobs/status_down"
run_refusal "the stream pool's status does not answer" "status did not answer over pm.status_listen" --dry-run
rm -f "$T/knobs/status_down"; run --dry-run
eq  "control: the same host with the status answering deploys" 0 "$RC"

mkfix r2_status_other_pool; printf '178815168175465' > "$T/knobs/status_pool"
run_refusal "the listener answers for ANOTHER pool" "answers for pool '178815168175465', not [mezz-stream]" --dry-run

mkfix drain_timing; export MEZZ_FEED_DRAIN_CEILING_S=3s
run_refusal "MEZZ_FEED_DRAIN_CEILING_S 3s (A1b)" "MEZZ_FEED_DRAIN_CEILING_S is '3s', not a whole number of seconds" --dry-run

section "THE STREAM DRAIN (phase B) — D2 § 14 item 17's decision"
# A stand-in stream worker is a real process the stub listing reports as a Running request; the deploy may
# signal it because it is this user's, which is the whole premise the decision rests on.
start_stream_worker() { # <start epoch> — prints the pid
  local pid
  sleep 600 </dev/null >/dev/null 2>&1 &
  pid=$!; disown "$pid"
  printf '%s %s\n' "$pid" "$1" >> "$T/knobs/streams"
  printf '%s' "$pid"
}
alive() { kill -0 "$1" 2>/dev/null && echo alive || echo gone; }

mkfix drain_residual
OLD_STREAM="$(start_stream_worker "$(( $(date +%s) - 3600 ))")"   # opened an hour before the deploy
NEW_STREAM="$(start_stream_worker "$(( $(date +%s) + 3600 ))")"   # a request younger than fleet.reload
eq  "control: the stand-in workers are running before the deploy" "alive alive" "$(alive "$OLD_STREAM") $(alive "$NEW_STREAM")"
run
eq  "residual stream: exit 0 (a stale stream is never a reason to stay down)" 0 "$RC"
logged "residual stream: wrote fleet.reload" "php artisan mezzanine:feed-reload"
has "residual stream: names the stream still open at the ceiling" "still open after a 2 s drain that began once fleet.reload had been read: pid(s) $OLD_STREAM" "$OUT"
eq  "residual stream: the previous release's stream worker was ended by SIGTERM" "gone" "$(alive "$OLD_STREAM")"
eq  "residual stream: a request younger than fleet.reload was left alone" "alive" "$(alive "$NEW_STREAM")"
has "residual stream: the result is in the closing banner" "streams   : ended the stream(s) that missed fleet.reload with SIGTERM (pid(s) $OLD_STREAM)" "$OUT"
before "order: fleet.reload is written after the daemons are relaunched" "php artisan mezzanine:fold" "artisan mezzanine:feed-reload"
before "order: fleet.reload is written before the app is up"             "artisan mezzanine:feed-reload" "artisan up"
OUTL="$(printf '%s\n' "$OUT" | grep -n -e 'Ending the previous release' -e 'letting opcache revalidate' | cut -d: -f1 | tr '\n' ' ')"
eq  "order: the feed reload and its drain come immediately before the opcache wait" "ascending" "$(set -- $OUTL; [ $# -eq 2 ] && [ "$1" -lt "$2" ] && echo ascending || echo "lines: $OUTL")"

cut_stream_kill() { sed -i 's/^  kill -TERM \$pids 2>\/dev\/null || true$/  : stream kill cut out by the selftest mutant/' "$1/bin/deploy.sh"; }
mkfix drain_kill_cut cut_stream_kill
eq  "drain mutant: the mutator really cut the kill" 1 "$(git -C "$SRC" show HEAD:bin/deploy.sh | grep -c 'stream kill cut out by the selftest mutant')"
OLD_STREAM="$(start_stream_worker "$(( $(date +%s) - 3600 ))")"
run
eq  "drain mutant: still exit 0" 0 "$RC"
eq  "drain mutant: the stream worker SURVIVES — the assertion above can fail" "alive" "$(alive "$OLD_STREAM")"
has "drain mutant: and the deploy says so, by pid" "SIGTERM did not end pid(s) $OLD_STREAM" "$OUT"

mkfix drain_none
run
eq  "control: no stream left open — exit 0" 0 "$RC"
has "control: says every stream ended on fleet.reload" "every stream the previous release served had ended on fleet.reload" "$OUT"

# The deploy that FIRST ships the stream-pool check: phase A runs the serving release's copy, which has none, so a
# host with no stream pool passes it — and the window must not then take the app down over a pool nobody was asked
# to provision. The serving copy is the release's own deploy.sh with the check reduced to a pass.
serving_without_stream_check() { sed -i 's/^stream_pool_ready() {$/stream_pool_ready() { return 0;/' "$1/bin/deploy.sh"; }
# mkfix's v1 mutator edits the source tree the release commit is also made from, so the release restores its own copy.
release_with_the_check() { cp "$DEPLOY" "$1/bin/deploy.sh"; }
mkfix first_ship release_with_the_check serving_without_stream_check; unset MEZZ_STREAM_POOL
eq  "first ship: the release really carries the check" 0 "$(git -C "$SRC" show HEAD:bin/deploy.sh | grep -c '^stream_pool_ready() { return 0;')"
eq  "first ship: the serving release really lacks the check" 1 "$(git -C "$SRC" show "$V1:bin/deploy.sh" | grep -c '^stream_pool_ready() { return 0;')"
run
eq  "first ship: exit 0 — the app comes back up" 0 "$RC"
logged "first ship: fleet.reload is still written" "php artisan mezzanine:feed-reload"
has "first ship: says the stream pool is not ready, and that the next deploy will refuse" "the next deploy will refuse this host until it is" "$OUT"
has "first ship: and names what is missing" "MEZZ_STREAM_POOL is unset" "$OUT"
has "first ship: the drain is skipped by name" "streams   : NOT DRAINED — the stream pool is not one this deploy can read" "$OUT"
strict_phase_b() { release_with_the_check "$1"; sed -i 's/^    \[ -n "\$POST_CHECKOUT_SHA" \] || return 1$/    return 1 # phase-B leniency cut out by the selftest mutant/' "$1/bin/deploy.sh"; }
mkfix first_ship_strict strict_phase_b serving_without_stream_check; unset MEZZ_STREAM_POOL
eq  "first ship mutant: the release really fails phase B on it" 1 "$(git -C "$SRC" show HEAD:bin/deploy.sh | grep -c 'phase-B leniency cut out by the selftest mutant')"
run
eq  "first ship mutant: exit 2 — the window stays down over the stream pool" 2 "$RC"
unlogged "first ship mutant: the app is NEVER brought up" "artisan up"

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
# card#9561 r3 MINOR 2. A10b was the last reader judging a `.env` line by a hand-rolled `^[[:space:]]*KEY=`
# grep — the pre-r1 one — so a key written `export KEY=…` or `"KEY"=…`, both of which ARE that key to
# Dotenv, was reported as one this host does not set. It asks env_get now, whose third answer it keeps
# apart: a key written in a form this deploy does not read is not established either way, and telling the
# operator to add a line that is already there is the wrong instruction. DB_PASSWORD is used because A5
# never reads it, so the run reaches A10b (a key A5 reads would refuse by name before this point).
sed -i "s/^DB_PASSWORD=/export DB_PASSWORD=/" "$ROOT/server/.env"
run --dry-run
eq    "a key written \`export KEY=\`: still deploys (a warning, not a refusal)" 0 "$RC"
has   "a key written \`export KEY=\`: reported as written in a form this deploy does not read" \
      "does not read, so whether the release's default or the host's value is in force is not established: DB_PASSWORD" "$OUT"
hasnt "a key written \`export KEY=\`: NOT reported as a key the host does not set" "does not set: DB_PASSWORD" "$OUT"
hasnt "a key written \`export KEY=\`: no DB password is printed" "$FAKE_PW" "$OUT"

no_lockfile() { rm -f "$1/server/package-lock.json"; }
mkfix no_lock no_lockfile
run_refusal "no npm lockfile" "package-lock.json is missing" --dry-run

trust_star() {
  printf '<?php return Application::configure()->trustProxies(at: "*")->create();\n' \
    > "$1/server/bootstrap/app.php"
}
mkfix trust_all trust_star
run_refusal "trustProxies('*')" "trusts ALL proxies" --dry-run

# card#9608 — A GIT READ THAT FAILED, then read as a finding about the release. `git_at <cmd> …
# 2>/dev/null || true` silenced git's own error AND discarded its status, so "there is no such path at
# this commit" and "git could not read it" both arrived as an empty string, and three phase A gates read
# that empty as a fact about the tree: the § 6.9 gate printed `ok — no undeclared ALTER` over a file list
# it never got, A11 emitted `no trustProxies() configured` about a file it never opened — which made the
# `*` refusal UNREACHABLE — and A10b compared zero keys. THE FAILURE MODE IS A CHECK THAT PASSES, so each
# case below is paired with the assertion that the false statement is gone, not only with an exit code.
#
# The condition is produced FOR REAL: one loose object of the fixture's own store, mode 000. Nothing is
# stubbed and no path is removed — the path is still in the tree at $V2, which is what makes each of these
# the read-failed case and not the file-missing case beside it.
blind_object() { # blind_object <rev-expr> — the ONE object <rev-expr> names, unreadable in $ROOT
  local o f
  o="$(git -C "$ROOT" rev-parse "$1")"
  f="$ROOT/.git/objects/${o:0:2}/${o:2}"
  cases=$((cases+1))
  if [ -f "$f" ]; then ok "fixture: $1 is a loose object"
  else bad "fixture: $1 is not a loose object under \$ROOT ($f is not there)"; return 1; fi
  # $ROOT is a LOCAL clone, so its loose objects are hardlinks to $ORIGIN's and a chmod on one lands
  # on both — which would make the fixture a broken REMOTE as well as a broken checkout, and the
  # deploy's own `git fetch` would then be the thing that failed, before any of this. Break the link
  # first: the condition these cases are about is this CHECKOUT's object store (card#9611).
  cp -p "$f" "$f.unlinked" && mv -f "$f.unlinked" "$f"
  chmod 000 "$f"
  cases=$((cases+1))
  if git -C "$ORIGIN" cat-file -p "$o" >/dev/null 2>&1
  then ok "fixture: \$ORIGIN can still read $1 — only the checkout's copy is blinded"
  else bad "fixture: \$ORIGIN lost $1 too (the hardlink was not broken)"; fi
  # ASSERTED, not assumed — root opens every mode, and under a root runner these cases would certify
  # nothing while looking like they had. They red HERE, naming why, rather than skipping (canon #9).
  cases=$((cases+1))
  if git -C "$ROOT" cat-file -p "$1" >/dev/null 2>&1
  then bad "fixture: git can still read $1 (a root runner cannot hold this condition)"
  else ok "fixture: git really cannot read $1 as this user"; fi
}

# THE SECURITY INSTANCE, in the release where it costs something: this v2 DOES `trustProxies('*')` — the
# case four lines above, which refuses on it — and with its blob unreadable the old reader handed A11 an
# empty string, so the refusal could not fire and the run reported the SAFE state and exited 0.
mkfix git_read_trust_star trust_star
blind_object "$V2:server/bootstrap/app.php"
run_refusal "unreadable bootstrap/app.php (a release that DOES trust \`*\`)" \
  "git could not read server/bootstrap/app.php" --dry-run
hasnt "unreadable bootstrap/app.php: makes no claim about a file it never read" \
  "no trustProxies() configured" "$OUT"

# The § 6.9 gate's own denominator: the migrations TREE object, unreadable, with every migration still in
# the tree. Both directions are proven — this, and the legitimate empty directly below it.
mkfix git_read_migrations
blind_object "$V2:server/database/migrations"
run_refusal "unreadable migrations tree" "git could not read server/database/migrations" --dry-run
hasnt "unreadable migrations tree: does not certify the list it never got" "no undeclared ALTER" "$OUT"

# THE OTHER DIRECTION, one variable away: a release that genuinely ships no migration at all. An empty
# list at status 0 is an honest answer and must still PASS — a fix that refused here would be a
# regression, not a fix — and the line it prints says what was actually read.
no_migrations() { rm -rf "$1/server/database/migrations"; }
mkfix git_read_no_migrations no_migrations
run --dry-run
eq  "a release with no migrations at all: still deploys" 0 "$RC"
has "a release with no migrations at all: says THAT, not that it read a list" "ships no migrations" "$OUT"
hasnt "a release with no migrations at all: claims nothing about migrations it never had" \
  "no undeclared ALTER" "$OUT"

# The reader's third answer, and the one that is not about I/O at all: the path is THERE and is not a
# file. `git show` prints a TREE's listing, so the old reader handed A11 a directory listing and the
# `trustProxies` grep found nothing in it — the same false reading of a file never opened, arrived at
# from the other side, and the deploy went green.
bootstrap_is_a_dir() {
  rm -f "$1/server/bootstrap/app.php"
  mkdir -p "$1/server/bootstrap/app.php"
  printf 'not the bootstrap file\n' > "$1/server/bootstrap/app.php/x"
}
mkfix git_read_bootstrap_dir bootstrap_is_a_dir
run_refusal "server/bootstrap/app.php is a directory in the release" \
  "server/bootstrap/app.php is a tree" --dry-run
hasnt "app.php as a directory: claims nothing about the trusted proxies it never read" \
  "no trustProxies() configured" "$OUT"

# A10b's key list, from a .env.example whose blob cannot be read: zero keys compared, nothing warned.
mkfix git_read_env_example
blind_object "$V2:server/.env.example"
run_refusal "unreadable .env.example" "git could not read server/.env.example" --dry-run

# ── card#9608 r2 — the reader's remaining false readings ──────────────────────────────────────
# ⛔ A SYMLINK IS `blob` TO ls-tree (measured, git 2.53.0: `120000 blob …`), so the TYPE check passed
# one and `git show` printed the link's TARGET PATH. The caller then read that path string as the
# file's text: A11 grepped `app.real.php` for `trustProxies`, found none, and emitted `no trustProxies()
# configured` — a positive statement about a file it never opened, the exact defect this card ends —
# about a release that DOES `trustProxies('*')`. The reader discriminates on the MODE now.
bootstrap_is_a_symlink() {
  printf '<?php return Application::configure()->trustProxies(at: "*")->create();\n' \
    > "$1/server/bootstrap/app.real.php"
  rm -f "$1/server/bootstrap/app.php"
  ln -s app.real.php "$1/server/bootstrap/app.php"
}
mkfix git_read_bootstrap_symlink bootstrap_is_a_symlink
eq "symlink fixture: app.php really is mode 120000 in the release" "120000" \
   "$(gitc "$SRC" ls-tree HEAD -- server/bootstrap/app.php | cut -d' ' -f1)"
run_refusal "server/bootstrap/app.php is a SYMLINK in the release" \
  "server/bootstrap/app.php is a symbolic link" --dry-run
hasnt "app.php as a symlink: claims nothing about the trusted proxies it never read" \
  "no trustProxies() configured" "$OUT"

# The same defeat at the § 6.9 gate, where it hides an ALTER instead of a forgeable header: a migration
# that is a symlink. The gate read `../alter_events.inc` as the migration's body, found no
# `Schema::table('events'` in that path string, and printed `ok — no undeclared ALTER`.
migration_is_a_symlink() {
  cat > "$1/server/database/alter_events.inc" <<'MIG'
<?php
return new class { public function up(): void {
    Schema::table('events', fn ($t) => $t->string('trace_id')->nullable());
} };
MIG
  ln -s ../alter_events.inc "$1/server/database/migrations/2026_02_02_000000_add_col_to_events.php"
}
mkfix git_read_migration_symlink migration_is_a_symlink
run_refusal "a migration that is a SYMLINK in the release" \
  "2026_02_02_000000_add_col_to_events.php is a symbolic link" --dry-run
hasnt "a symlinked migration: does not certify a body it never read" "no undeclared ALTER" "$OUT"

# A gitlink (mode 160000). Its type is `commit`, so the old type check did stop it — what the mode
# case adds is a refusal in the reader's own vocabulary, saying why there is no text here at all:
# the entry is a commit id in a repository this deploy never clones.
# The entry is written with plumbing rather than by embedding a repository: the directory left on disk
# is EMPTY, so the fixture's own `git add -A` has nothing to stage there and leaves the gitlink alone.
bootstrap_is_a_submodule() {
  rm -f "$1/server/bootstrap/app.php"
  mkdir -p "$1/server/bootstrap/app.php"
  gitc "$1" update-index --add --cacheinfo "160000,$(gitc "$1" rev-parse HEAD),server/bootstrap/app.php"
}
mkfix git_read_bootstrap_submodule bootstrap_is_a_submodule
eq "submodule fixture: app.php really is mode 160000 in the release" "160000" \
   "$(gitc "$SRC" ls-tree HEAD -- server/bootstrap/app.php | cut -d' ' -f1)"
run_refusal "server/bootstrap/app.php is a SUBMODULE in the release" \
  "server/bootstrap/app.php is a submodule" --dry-run
hasnt "app.php as a submodule: claims nothing about the trusted proxies it never read" \
  "no trustProxies() configured" "$OUT"

# ⛔ THE OTHER DIRECTION OF THE SAME READER — A HEALTHY RELEASE IT BLOCKED. ls-tree C-quotes a
# non-ASCII name by default and then cannot match the quoted string it is handed back, so git_read_at
# answered "not there" about a file it had just listed and A10 refused a well-formed release with a
# message that reads as an internal contradiction. `-c core.quotePath=false` makes the name it PRINTS
# one it will also MATCH. This release is healthy — its extra migration creates its own table — so the
# assertion is that the run REACHES THE END.
migration_non_ascii() {
  cat > "$(printf '%s/server/database/migrations/2026_02_02_000000_cr\303\251\303\251.php' "$1")" <<'MIG'
<?php
// A migration whose NAME is not ASCII. It creates its own table and alters nothing.
return new class { public function up(): void { Schema::create('feeds', fn ($t) => $t->id()); } };
MIG
}
mkfix git_read_non_ascii_name migration_non_ascii
# The fixture's OWN precondition, pinned the way its three siblings pin theirs. Without it every
# assertion below is satisfied by a plain-ASCII tree too, so a name that stopped being non-ASCII — an
# edit to the printf escapes above, a filesystem that mangles, a checkout that normalises — would
# leave this case passing as "a healthy release deploys" while certifying the core.quotePath=false
# fix it exists to guard. It asserts A BYTE OUTSIDE PRINTABLE ASCII rather than the literal `créé`,
# so the needle cannot move WITH the fixture and go on agreeing with itself; core.quotePath=false so
# that ls-tree prints the name's own bytes (under git's default quoting every name it prints is ASCII,
# which is the very defect this case exists for).
eq "non-ASCII fixture: exactly one migration in the release's tree really has a non-ASCII name" 1 \
   "$(gitc "$SRC" -c core.quotePath=false ls-tree --name-only -r HEAD -- server/database/migrations \
      | LC_ALL=C grep -c '[^ -~]')"
run --dry-run
eq    "a migration with a non-ASCII NAME: the healthy release still deploys" 0 "$RC"
has   "a migration with a non-ASCII NAME: the § 6.9 gate read it and passed" "no undeclared ALTER" "$OUT"
hasnt "a migration with a non-ASCII NAME: not refused as absent from its own tree" \
      "was not there to read" "$OUT"

# ⛔ A CALLER'S VARIABLE SHADOWED BY THE READER'S OWN LOCAL — the other way these readers blocked a
# healthy release. The internals were `out`, `rc`, `rev`, `path`, `entry`, `type`, `content`, and a call
# site naming its variable one of those got the reader's local instead: `printf -v` wrote the shadow,
# the caller's variable stayed empty, NOTHING failed, and A13 refused a release shipping a perfectly
# good bin/supervision.sh. No call site collided — which is why this mutant IS one, renaming A13's
# variable to `content`. It runs on the SERVING release's copy (v1), because A13 is phase A's.
collide_with_reader_local() { sed -i 's/\btarget_sup\b/content/g' "$1/bin/deploy.sh"; }
mkfix git_read_local_collision "" collide_with_reader_local
eq "collision mutant: the call site really asks for a variable named \`content\`" 1 \
   "$(gitc "$SRC" show "$V1:bin/deploy.sh" | grep -c 'git_read_at content "\$SHA" bin/supervision.sh')"
run --dry-run
eq    "a call site naming its variable \`content\`: the healthy release still deploys" 0 "$RC"
hasnt "a call site naming its variable \`content\`: no false 'missing or empty'" \
      "bin/supervision.sh is missing or empty" "$OUT"

# ⛔ THE PHASE-A PROMISE, MADE STRUCTURAL. `refuse` ends with "Nothing was changed. The previous release
# is still serving." That is true of every caller of these readers today, and what keeps it true is one
# `[ -z "$POST_CHECKOUT_SHA" ] &&` at fpm_code_reload_ready — the single function that runs on BOTH
# sides of the window, and a guard the readers cannot see. This mutant is the future edit that drops it:
# the DEPLOYED release (v2, the copy phase B re-execs into) reads the target tree again after the
# checkout, with a rev that will not resolve. The promise would be false — the window is open, the app
# is down — so the reader takes the in-window failure path instead, marker and all.
read_fails_in_phase_b() {
  sed -i 's|.*git_read_at rel_uif .*|    if git_read_at rel_uif "${SHA}-no-such-rev" "server/public/$uif"; then # phase-A guard cut out by the selftest mutant|' "$1/bin/deploy.sh"
}
mkfix git_read_in_window read_fails_in_phase_b
eq "phase-B mutant: the deployed release really re-reads the tree after the checkout" 1 \
   "$(gitc "$SRC" show "HEAD:bin/deploy.sh" | grep -c 'phase-A guard cut out by the selftest mutant')"
run
eq    "a git read that fails INSIDE the window: exit 2 (not 1)"     2 "$RC"
has   "a git read that fails in the window: the banner"             "THE APP IS DOWN AND STAYS DOWN" "$OUT"
has   "a git read that fails in the window: names the read"         "reading the release out of git" "$OUT"
hasnt "a git read that fails in the window: never promises nothing was changed" \
      "Nothing was changed. The previous release is still serving." "$OUT"
eq    "a git read that fails in the window: the marker is on disk"  "present" \
      "$([ -e "$ROOT/.deploy-failed" ] && echo present || echo absent)"
unlogged "a git read that fails in the window: the app is NEVER brought up" "artisan up"

# ── card#9611 — THE TWO READS THAT REFUSED CORRECTLY AND NAMED A FALSE CAUSE ───────────────────
# card#9608 ended the DANGEROUS direction of this class: an empty git result read as a PASS. These
# two are the SAFE direction, and what they cost an operator is the debugging path rather than the
# deploy. A7 answered "'<ref>' does not resolve to a commit on origin" and A8 "<sha> is not
# contained in origin/main" — a bad ref name and a statement about the commit graph — on a checkout
# whose OBJECT STORE was what had failed. Neither cause was ever established (canon #10: a
# wrong-but-specific cause is worse than an honest generic one).
#
# ⛔ THE STATUS OF THE CALL A7 WAS MAKING CANNOT DISCRIMINATE. `rev-parse --verify --quiet
# <ref>^{commit}` exits 1 for "no such ref" AND for every object-store failure measured (git 2.53.0:
# pack chmod 000, idx chmod 000, pack deleted, byte flipped mid-pack, loose object chmod 000), and
# without `--quiet` both are 128 with the same sentence. So the fix is not a status threshold on
# that call: resolving a NAME (which reads the refs alone) and reading the OBJECT it names are asked
# as two questions now. The cases below are what says so.
#
# ⛔ THEY MUST DISCRIMINATE, NOT MERELY REFUSE. Old and new both exit 1 on every fixture here, so an
# assertion on the exit code would have passed against the defect. Each case asserts WHICH cause is
# named AND that the other is not, and each has a control one variable away — the same `--ref` on a
# readable store, and a genuinely absent ref on the SAME broken one — so a fix that collapsed the
# two answers into "git could not read it" reds here exactly as loudly as the defect did.

# three_releases <case> — the fixture both halves use. main moves to $V3, so the commit these cases
# blind ($V2) is no longer the TIP of any ref, and `hotfix` is an unreleased branch off $V1. Both
# refs are fetched into the checkout before anything is broken.
# ⚠ THE TIP IS NOT AVAILABLE TO BLIND, and that is measured, not a preference: a checkout whose
# remote-tracking tip is unreadable cannot `git fetch` at all (it reads its own tips to say what it
# has), so such a fixture REFUSES at A7's `git fetch`, by name (card#9646 — it used to DIE there,
# with git's status and no banner), and never reaches either read. A middle commit leaves `git
# status` (A4) and the fetch working — the fetch prints git's error and still exits 0 — and breaks
# exactly the two reads this card is about.
# ⛔ THAT EXIT-0-WHILE-PRINTING FETCH IS ALSO THE CONTROL card#9646's fetch refusal must not widen
# into, and it is these cases rather than a case of its own: every `three_releases` fixture below
# blinds an object and then deploys THROUGH the fetch. A fetch rule keyed on git's STDERR instead of
# its STATUS refuses all of them, so it cannot land while this block is green.
three_releases() {
  mkfix "$1"
  printf '0.0.3\n' > "$SRC/VERSION"
  gitc "$SRC" add -A >/dev/null
  gitc "$SRC" commit -qm 'fixture v3 — origin/main moves past the commit these cases blind'
  gitc "$SRC" push -q origin main
  V3="$(gitc "$SRC" rev-parse HEAD)"
  gitc "$SRC" checkout -q -b hotfix "$V1"
  printf 'x\n' > "$SRC/hotfix.txt"; gitc "$SRC" add -A >/dev/null
  gitc "$SRC" commit -qm 'unreleased hotfix'; gitc "$SRC" push -q origin hotfix
  gitc "$ROOT" fetch -q --prune --tags origin
  # The clone left a LOCAL branch `main` behind at $V2, and the fetch runs a connectivity check
  # over every ref (`rev-list --not --all`), so a ref tip it cannot read aborts the FETCH ITSELF
  # — the measurement above, reached from the other side. Move that branch too, so no ref of this
  # checkout points at the commit these cases blind and the fetch stays a read that completes.
  gitc "$ROOT" update-ref refs/heads/main "$V3"
}

three_releases git_rev_unreadable_object
blind_object "$V2"

# A7. `--ref <sha>` is the deploy's own documented recovery path (the in-window banner: "deploy the
# previous commit deliberately with --ref <sha> --allow-unreleased"), and it is the path that meets
# a damaged object first. The ref RESOLVES — a full object name always does — and the object behind
# it does not come back. The old call got exit 1 for that, the same status a ref that is not there
# gives, and told the operator to go looking for a bad ref name.
run_refusal "--ref <sha> whose object git cannot read (A7)" \
  "git could not read the object $V2" --dry-run --ref "$V2"
hasnt "unreadable object: makes no claim about the ref, which resolved" \
  "does not resolve to a commit on" "$OUT"
has  "unreadable object: git's own error reaches the operator (never silenced)" \
  "unable to open loose object" "$OUT"
has  "unreadable object: says what was and was not established" \
  "The REFS were read; the object behind them did not come back." "$OUT"

# THE OTHER DIRECTION, over the SAME broken store, one --ref apart: a name that is genuinely not
# there still refuses as absent. The refs are read from `packed-refs` and loose refs, never from the
# object store, so this answer IS established even on this host — and a fix that answered "git could
# not read it" here would be the same defect pointing the other way.
run_refusal "a ref that is genuinely absent, on that same broken store" \
  "'no-such-branch' does not resolve to a commit on origin" --dry-run --ref no-such-branch
hasnt "absent ref: names no read that failed" "git could not read" "$OUT"
has  "absent ref: says the refs WERE read" "The refs were read and carry no such name" "$OUT"

# A8, on the same fixture: `hotfix`'s own commit is readable, so A7 passes and the ancestry walk is
# what meets the blinded object. `--is-ancestor` exits 1 for "it is not one" and 128 when it could
# not read the graph; `2>/dev/null` on an `if !` read the second as the first.
run_refusal "the ancestry cannot be read (A8)" \
  "is contained in origin/main (\`git merge-base --is-ancestor\` exited 128)" --dry-run --ref hotfix
hasnt "unreadable ancestry: states nothing about the commit graph" "is not contained in origin/main" "$OUT"
has  "unreadable ancestry: git's own error reaches the operator" "unable to open loose object" "$OUT"

# --allow-unreleased waives a FINDING — "this commit is not released". There is no finding here to
# waive, so the flag does not apply and the deploy still refuses. Its control is two cases below,
# where the same flag deploys the same hotfix once the store is readable.
run --dry-run --ref hotfix --allow-unreleased
eq  "unreadable ancestry: --allow-unreleased waives no question that was never answered" 1 "$RC"
has "unreadable ancestry: and says why the flag does not apply" "--allow-unreleased does NOT apply here" "$OUT"

# ── THE CONTROLS: the same fixture, the same three --ref values, every object readable ──────────
three_releases git_rev_readable_object
run --dry-run --ref "$V2"
eq  "control: that same --ref <sha>, with its object readable, deploys" 0 "$RC"
run_refusal "control: a ref that is genuinely absent, on a healthy store" \
  "'no-such-branch' does not resolve to a commit on origin" --dry-run --ref no-such-branch
hasnt "control: absent ref on a healthy store names no read that failed" "git could not read" "$OUT"
run_refusal "control: the ancestry is READ, and says the commit is not released" \
  "is not contained in origin/main" --dry-run --ref hotfix
hasnt "control: the read-failure refusal is not what fires when the graph is readable" \
  "git could not" "$OUT"
run --dry-run --ref hotfix --allow-unreleased
eq  "control: --allow-unreleased deploys the hotfix once the graph can be read" 0 "$RC"

# ⛔ THE ANNOTATED TAG, which A7's second candidate exists for. The old call peeled every candidate
# with `^{commit}` and got this for free; git_commit_of resolves the NAME to an object and peels it
# in a second step (that is the whole fix), so what a tag resolves to has to be asserted rather than
# assumed — a reader that stopped at the tag OBJECT would hand the deploy a tag id to check out, and
# every case above would still pass. The tag is created only on this fixture: a tag is a ref, `git
# fetch` walks every ref, and a tag over the blinded commit would break the fetch itself.
gitc "$SRC" tag -a v0.0.2 -m 'an annotated tag at the middle commit' "$V2"
gitc "$SRC" push -q origin v0.0.2
gitc "$ROOT" fetch -q --tags origin
neq "tag fixture: the tag OBJECT is not the commit it points at" "$V2" "$(gitc "$ROOT" rev-parse refs/tags/v0.0.2)"
run --dry-run --ref v0.0.2
eq  "control: an ANNOTATED tag resolves and deploys" 0 "$RC"
has "control: and it is the COMMIT the tag points at that would be checked out, not the tag object" \
  "git checkout --detach $(gitc "$ROOT" rev-parse --short "$V2")" "$OUT"

# ⛔ AND THE TAG THAT PEELS SOMEWHERE ELSE IS NOT A FAILED READ EITHER (card#9611 r4). This is the
# SIBLING of the peel-mismatch case further down — same defect, second surface, found by grepping
# the tree for r4's own false claim rather than by the review that named the other one. The tag
# branch's comment has said since r2 that one of its two cases is "git peeling the tag perfectly
# well and arriving somewhere this deploy cannot use" — but it refused through git_rev_read_failed,
# whose FIXED second line states that git's error "names what it could not read". So an annotated
# tag over a TREE refused under a claim of a failed read on a store where `git fsck` exits 0:
# measured, git 2.53.0, `rev-parse --verify --end-of-options <tag oid>^{commit}` → 128, `error: …:
# expected commit type, but the object dereferences to tree type`, `fatal: Needed a single
# revision`. Note the status: 128 here, 1 at the --quiet call site below, SAME wording — which is
# why git_peel_mismatch keys on git's message and is ONE function both sites call, rather than a
# status rule re-derived per caller. The assertions are over the CLAIM, not over a command string.
gitc "$SRC" tag -a treeonly -m 'a tag whose object is a TREE, not a commit' "$V2^{tree}"
gitc "$SRC" push -q origin treeonly
gitc "$ROOT" fetch -q --tags origin
eq "tree-tag fixture: the store is whole — every object reads" \
  0 "$(gitc "$ROOT" fsck >/dev/null 2>&1; echo $?)"
run --dry-run --ref treeonly
eq   "a tag that peels to a TREE: refused, nothing touched" 1 "$RC"
has  "tree tag: git's own message is what the operator gets" "dereferences to tree type" "$OUT"
has  "tree tag: says the objects behind the tag WERE read" "WAS read" "$OUT"
hasnt "tree tag: nothing claims git could not read" "git could not" "$OUT"
hasnt "tree tag: and nothing claims git's error names something unreadable" \
  "names what it could not read" "$OUT"
has  "tree tag: says outright that this establishes nothing about the object store" \
  "Nothing here says anything about the state of this checkout's object store" "$OUT"

# ── card#9611 r2 — THE CANDIDATE IS NOT ALWAYS A NAME, AND 128 IS NOT ONE CONDITION ────────────
# The cases above establish the split for ref NAMES, and that half is measured and holds. These are
# the two places the SAME defect survived it, and both are the card's own acceptance turned around:
# a refusal that positively asserts a cause nothing established.
#
# ⛔ 1. `$REF` IS THE OPERATOR'S OWN STRING, so a candidate can be rev syntax (`main~2`, `v1^{}`,
# `:/subject`) or an abbreviated id — and resolving one of THOSE walks into the object store, where
# a failed read comes back as 1, the status "there is no ref of that name". The loop consumed it as
# absence and the refusal then told an operator to check the spelling, printed directly under git's
# own `unable to open loose object … Permission denied`. The discriminator is git's SILENCE:
# measured one variable apart (git 2.53.0, the fixture below), an absent name answers 1 with an
# EMPTY stderr and the walk answers 1 having printed.
#
# ⛔ 2. `merge-base --is-ancestor` EXITS 128 FOR A TARGET REF THAT IS NOT THERE, on a completely
# healthy store — measured below by removing the release branch from $ORIGIN, where every object
# reads fine. A8 read every 128 as "git could not read the graph" and took --allow-unreleased away
# with it, which is wrong twice: the read never failed, and the question IS answered — nothing is
# released, so this commit is not released, which is the very finding the flag waives.
#
# THEY MUST DISCRIMINATE, NOT MERELY REFUSE: every fixture here refuses under the old code too, at
# the same exit status, so each case asserts WHICH cause is named and that the other is not, and
# each has a control one variable away.

three_releases git_rev_syntax_walks_the_store
blind_object "$V2"

# `--ref main~2` is V1, and reaching it means READING V2 — the blinded object — for its parent. The
# first candidate tried is `refs/remotes/origin/main~2`, so this is the string concatenation of
# $REF, not some exotic third path.
run_refusal "--ref <rev syntax> whose walk meets an unreadable object (A7)" \
  "git could not resolve 'refs/remotes/origin/main~2'" --dry-run --ref 'main~2'
hasnt "rev syntax over a broken store: does NOT call it the ref's absence" \
  "does not resolve to a commit on" "$OUT"
hasnt "rev syntax over a broken store: does not send the operator to check the spelling" \
  "Check the spelling" "$OUT"
has "rev syntax over a broken store: git's own error reaches the operator" \
  "unable to open loose object" "$OUT"
has "rev syntax over a broken store: names the discriminator it used — git's silence" \
  "that answer is SILENT" "$OUT"
# THE OTHER DIRECTION, same store, one --ref apart: an absent NAME is answered by the refs alone and
# is still an absence. This is the claim the fix must not weaken, asserted against the fix that
# could have.
run_refusal "an absent name on that same store is still an absence, not a read" \
  "'no-such-branch' does not resolve to a commit on origin" --dry-run --ref no-such-branch
hasnt "absent name beside it: no read-failure claim" "git could not resolve" "$OUT"
hasnt "absent name beside it: no abbreviation note (the --ref is not hex)" \
  "looked for it as an ABBREVIATED commit id" "$OUT"

# ── CONTROL: the same --ref, one variable away — every object readable ─────────────────────────
three_releases git_rev_syntax_readable_store
run --dry-run --ref 'main~2' --redeploy
eq  "control: that same rev syntax resolves and deploys once the object can be read" 0 "$RC"
has "control: and it is V1 — the commit the walk arrives at — that would be checked out" \
  "git checkout --detach $(gitc "$ROOT" rev-parse --short "$V1")" "$OUT"
# THE ABBREVIATION, which is the one shape the silence cannot discriminate: it is looked up IN the
# object store, and a store too damaged to search says nothing. The refusal names that for a hex
# --ref and for no other, so the operator is told what would tell them apart (the full id) instead
# of being told a store failure is a typo.
run_refusal "a hex --ref that names nothing is refused as absent, with the abbreviation named" \
  "'0badc0de' does not resolve to a commit on origin" --dry-run --ref 0badc0de
has "hex --ref: says the abbreviation lookup reads the object store" \
  "looked for it as an ABBREVIATED commit id" "$OUT"
has "hex --ref: and names the input that does not" "full 40-character commit id" "$OUT"
# Rev syntax that resolves to nothing is the same shape from the other side: measured on a HEALTHY
# store, `rev-parse --verify --quiet origin/main~99` is 1 and SILENT, so the silence cannot promise
# there that the object store was never asked. The refusal says which of the two this --ref is.
run_refusal "rev syntax that names nothing is refused as absent, with the walk named" \
  "'main~99' does not resolve to a commit on origin" --dry-run --ref 'main~99'
has "rev-syntax --ref: says it carries rev syntax and that resolving it walks the graph" \
  "carries rev syntax (~, ^, :, @{), so resolving it walked the commit graph" "$OUT"
hasnt "rev-syntax --ref: and does not call it an abbreviated id" \
  "ABBREVIATED commit id" "$OUT"
run --dry-run --ref no-such-branch
hasnt "an ordinary branch name that is absent gets neither note" "READS THE OBJECT STORE" "$OUT"
hasnt "an ordinary branch name that is absent is not called an invalid NAME either" \
  "is not a valid ref NAME" "$OUT"

# ⛔ A check-ref-format FAILURE IS NOT EVIDENCE OF REV SYNTAX (card#9611 r4). The note used to be
# derived from the NEGATION of `check-ref-format --allow-onelevel`, which exits 1 for around a dozen
# rules — measured, git 2.53.0, exit 1 and NONE of them rev syntax: `a b`, `main..dev`, `foo.lock`,
# `ab[c`, `.foo`, `foo//bar`, `foo/`, `ab*c`, `ab?c`, `ab\c`, a tab. So an ordinary typo on a
# COMPLETELY HEALTHY host — `--ref 'release 1.2'` — was told "it carries rev syntax, so resolving it
# walked the commit graph" and then pointed at a possibly-damaged object store: three false
# statements in one note, in this card's own direction. Measured for that same input: `rev-parse
# --verify --quiet --end-of-options 'refs/remotes/origin/release 1.2'` → 1 with EMPTY stderr, a
# lookup in the refs that never opens an object. r3 fixed ONE INPUT of this class (`-foo`); these
# cases are over the CLASS, and each is paired with the assertion that the false statement is gone,
# because the failure mode is a refusal that fires with the wrong reason attached.
run_refusal "a --ref git refuses as a NAME is refused as that, not as rev syntax" \
  "'release 1.2' does not resolve to a commit on origin" --dry-run --ref 'release 1.2'
has  "invalid-name --ref: says git refuses the name" "is not a valid ref NAME" "$OUT"
hasnt "invalid-name --ref: is NOT called rev syntax" "carries rev syntax" "$OUT"
hasnt "invalid-name --ref: and no walk of the commit graph is claimed" "walked the commit graph" "$OUT"
hasnt "invalid-name --ref: and no read of the object store is claimed" "READS THE OBJECT STORE" "$OUT"
has  "invalid-name --ref: says outright that the store was not read" \
  "THIS SAYS NOTHING ABOUT THIS CHECKOUT'S OBJECT STORE" "$OUT"
hasnt "invalid-name --ref: and does not send a typo after a full commit id" \
  "full 40-character commit id" "$OUT"
# THE CLASS, not the one input: three more of check-ref-format's rules, none of them rev syntax.
for bad_ref in 'main..dev' 'foo.lock' 'ab[c'; do
  run --dry-run --ref "$bad_ref"
  eq   "invalid-name --ref '$bad_ref': refused, nothing touched" 1 "$RC"
  has  "invalid-name --ref '$bad_ref': named as a name git refuses" "is not a valid ref NAME" "$OUT"
  hasnt "invalid-name --ref '$bad_ref': is NOT called rev syntax" "carries rev syntax" "$OUT"
done

# ⛔ AND THE THIRD SHAPE AT git_ref_oid's 1-LOUD ANSWER, WHERE EVERY READ SUCCEEDED (card#9611 r4).
# A peel to a type the object is not is LOUD at status 1 on a COMPLETELY HEALTHY store — measured,
# git 2.53.0: `rev-parse --verify --quiet --end-of-options refs/remotes/origin/main^{blob}` → 1,
# `error: refs/remotes/origin/main^{blob}: expected blob type, but the object dereferences to tree
# type`. The 1-loud branch read that as a failed read and said so under git_rev_read_failed's fixed
# line "git's own error … names what it could not read" — a failure that never happened, reachable
# with `--ref 'main^{blob}'`. git_commit_of's tag branch already tells this shape apart for its own
# peel; this is that same discrimination one branch up.
run --dry-run --ref 'main^{blob}'
eq  "peel to a type the object is not: refused" 1 "$RC"
has "peel mismatch: git's own message is what the operator gets" "dereferences to tree type" "$OUT"
hasnt "peel mismatch: nothing claims git could not read" "git could not" "$OUT"
hasnt "peel mismatch: and nothing claims git's error names something unreadable" \
  "names what it could not read" "$OUT"
has "peel mismatch: says the objects behind it WERE read" "WAS read" "$OUT"

# ── A8: NO RELEASE BRANCH AT ALL, on a store where every object reads ──────────────────────────
# The release branch is renamed on $ORIGIN, so the deploy's own `fetch --prune` removes
# refs/remotes/origin/main. Nothing is unreadable; `--is-ancestor` exits 128 all the same.
three_releases git_rev_no_release_branch
gitc "$ORIGIN" symbolic-ref HEAD refs/heads/release
gitc "$ORIGIN" branch -m main release
eq  "fixture: \$ORIGIN has no main to be contained in" "" \
  "$(gitc "$ORIGIN" rev-parse --verify --quiet refs/heads/main || true)"
eq  "fixture: and every object of the checkout still reads" 0 \
  "$(gitc "$ROOT" cat-file -t "$V2" >/dev/null 2>&1; echo $?)"

run_refusal "no origin/main to compare against (A8)" \
  "there is no origin/main for" --dry-run --ref hotfix
hasnt "no release branch: states no read that failed" "git could not" "$OUT"
hasnt "no release branch: does not claim the graph was unreadable" "could not read the graph" "$OUT"
has "no release branch: says the flag applies, because the question WAS answered" \
  "it is the same finding --allow-unreleased waives" "$OUT"

# THE HATCH, which is what the old code took away here. `--ref <sha> --allow-unreleased` is the
# shape the in-window recovery banner tells an operator with the app DOWN to run, so this is that
# documented last resort exercised against the condition it now meets.
run --dry-run --ref "$V2" --allow-unreleased
eq  "the recovery banner's own shape (--ref <sha> --allow-unreleased) deploys with no release branch" \
  0 "$RC"
has "recovery shape: and says in the log WHY it is unreleased — there is no branch" \
  "DEPLOYING UNRELEASED CODE: there is no origin/main to contain" "$OUT"
run --dry-run --ref hotfix --allow-unreleased
eq  "no release branch: the flag waives it for a branch too" 0 "$RC"

# ── AND THE OTHER 128, one variable away: the graph that genuinely could not be read ───────────
# Same flag, same refusal to deploy — the finding was never made, so there is none to waive — but
# the refusal now carries the way out, because on THIS store the recovery deploy above is refused
# too and the old text left an operator with the app down no next step at all.
three_releases git_rev_unreadable_ancestry
blind_object "$V2"
run --dry-run --ref hotfix --allow-unreleased
eq  "unreadable ancestry: still refused — a question never answered has no finding to waive" 1 "$RC"
has "unreadable ancestry: says origin/main itself was THERE, and what was not read" \
  "origin/main IS there" "$OUT"
has "unreadable ancestry: names the repair as the next step" \
  "git -C $ROOT fsck" "$OUT"
has "unreadable ancestry: tells the operator the recovery deploy meets this same refusal" \
  "--ref <sha> --allow-unreleased" "$OUT"
# ⛔ AND THE PRESCRIBED REPAIR MUST ACTUALLY REPAIR (card#9611 r4). The refusal used to name
# `fetch --prune` as "asks origin for the objects behind its refs again" — and it does not: fetch
# negotiates from REFS, and this refusal is reached only AFTER git_commit_of proved $SHA readable
# and git_ref_oid resolved refs/remotes/origin/main, so the object that cannot be read is an
# INTERIOR graph object this checkout's own refs already claim, and origin is never asked for it.
# Measured, git 2.53.0, on clones of a local origin with one middle commit's loose object damaged
# (the hardlink broken first, exactly as blind_object does):
#   object mode 000, remote unchanged  → `fetch --prune origin` exit 0, nothing transferred, mode
#                                         still 000, `cat-file -t` still 128
#   object mode 000, remote advanced   → exit 128, object still unreadable
#   object DELETED                     → exit 0, `cat-file -t` still 128 afterwards
# An operator with the app down ran it, got exit 0 and no output, read that as the repair having
# worked, and met the identical refusal. The two repairs that DO work were measured on those same
# clones: `chmod 644` on the blinded file → `cat-file -t` 0; and replacing .git alone from a
# `clone --no-checkout` then `checkout --force <sha>` → exit 0, object readable, with server/.env,
# server/storage/ and .deploy-failed still in place because only .git moved.
# ⚠ A `has` on a command STRING cannot catch advice that does not work — that is how the false
# claim survived a green suite. So the assertions below are over the CLAIM: the prescription that
# does not work must be named as not working, and the ones that do must be present with the
# sentence that makes them usable.
hasnt "unreadable ancestry: never prescribes a fetch as the way to get the object back" \
  "asks origin for the objects behind its refs again" "$OUT"
has "unreadable ancestry: says outright that fetch cannot restore it" \
  "⛔ git fetch CANNOT bring that object back" "$OUT"
has "unreadable ancestry: and why — the refs already claim the commit, so origin is never asked" \
  "ALREADY claim that commit, so origin is never asked for the objects behind it" "$OUT"
has "unreadable ancestry: names the in-place repair for a file that is there but unreadable" \
  "chmod 444 $ROOT/.git/objects/" "$OUT"
has "unreadable ancestry: and the last resort for one that is GONE — the object store only" \
  "git clone --no-checkout" "$OUT"
has "unreadable ancestry: which moves .git and nothing else" \
  "mv $ROOT/.git $ROOT/.git.broken" "$OUT"
has "unreadable ancestry: and puts the deploy's own commit back in the tree afterwards" \
  "git -C $ROOT checkout --force" "$OUT"
# repack stays in the refusal, described as what it IS. Measured all-or-nothing, git 2.53.0:
# blinded object → `fatal: Failed to traverse parents of commit …`, exit 128, no new pack written
# and nothing deleted; deleted object → exit 128; unreadable ref file → `fatal: bad object
# refs/heads/keep`, exit 128, the pack and the branch-only commit both still there. It salvages
# nothing, so it is named as the CONFIRMATION step it is.
has "unreadable ancestry: names repack as the confirmation step" \
  "git -C $ROOT repack -a -d" "$OUT"
hasnt "unreadable ancestry: and does not call repack a salvage" \
  "from what it can still read" "$OUT"
has "unreadable ancestry: says repack refuses outright rather than salvaging" \
  "refuses outright if anything reachable cannot be read" "$OUT"
# ⛔ SAFETY, and the reason this assertion exists at all (card#9611 r3). This refusal is read by an
# operator with the app DOWN, and the advice used to end "re-fetch it from origin, or RE-CLONE
# $DEPLOY_ROOT from it". A re-clone destroys `server/.env` — created on the host, in no commit (this
# repo's own .gitignore), so this host's APP_KEY and DB_PASSWORD exist nowhere else and backups are
# out of scope on this install — along with `server/storage/` (the logs the in-window banner tells
# the operator to tail) and `.deploy-failed` (the marker it says must be reviewed). Every repair the
# refusal now names works inside the checkout's .git and touches none of them.
hasnt "unreadable ancestry: never tells a mid-incident operator to re-clone the deploy root" \
  "re-clone $ROOT from it" "$OUT"
has "unreadable ancestry: says so, so the operator does not reach for one" \
  "DO NOT RE-CLONE $ROOT" "$OUT"
has "unreadable ancestry: and names what a re-clone would destroy" "server/.env" "$OUT"

# ── card#9611 r3 — ONE CASE PER DECLARED ANSWER OF git_ref_oid ─────────────────────────────────
# ⛔ WHY THE COVERAGE IS SHAPED THIS WAY, which is this round's lesson and not a note. r2 covered
# git_ref_oid's status-1 half — an absence, and the object-store walk that wears an absence's status
# — and NONE of its rc ∉ {0,1} half. So the branch no case exercised was the branch still making the
# over-read this whole card exists to end: it read every non-0/1 status as "the REFS could not be
# read", under git_rev_read_failed's fixed line saying git's error was above the refusal, and
# `--ref HEAD@{1}` reached it on a COMPLETELY HEALTHY host with git having printed nothing at all.
# The cases below are therefore one per ANSWER the function declares — the status AND git's silence
# together, the two things it actually reads — rather than one per failure someone thought of.
#
# The blinded-store answers (1-loud through the object store) are the block above; these add the
# ones a healthy store gives, plus the one answer that is not reachable through this script, named
# as that rather than skipped.
three_releases git_ref_oid_answers

# ANSWER 0 — <var> is a full object id, and the deploy goes on to use it.
run --dry-run --ref main
eq  "answer 0: a name that resolves deploys" 0 "$RC"
has "answer 0: and it is what origin/main points at that would be checked out" \
  "git checkout --detach $(gitc "$ROOT" rev-parse --short "$V3")" "$OUT"

# ANSWER 1 + git SILENT — the one answer that RETURNS. An absence, and A7 says what it means.
run_refusal "answer 1-silent: a name that is not there is an absence" \
  "'no-such-branch' does not resolve to a commit on origin" --dry-run --ref no-such-branch
hasnt "answer 1-silent: claims no read that failed" "git could not resolve" "$OUT"

# ANSWER 1 + git LOUD, WITHOUT THE OBJECT STORE. A dangling symref is the shape that proves this
# refusal must not name where the read failed: measured (git 2.53.0) `warning: ignoring dangling
# symref refs/heads/dangling` → exit 1, on a store where every object reads, and `git fetch`
# survives it — so it is reachable on a healthy host, unlike the blinded-store case above.
printf 'ref: refs/heads/nowhere-at-all\n' > "$ROOT/.git/refs/heads/dangling"
run_refusal "answer 1-loud: a ref the refs themselves cannot follow" \
  "git could not resolve 'dangling'" --dry-run --ref dangling
has  "answer 1-loud: git's own message reaches the operator" "ignoring dangling symref" "$OUT"
hasnt "answer 1-loud: does not call a loud 1 the ref's absence" \
  "does not resolve to a commit on" "$OUT"
# ⚠ The needle is the OLD text's own words — "went past", which ASSERTS a walk that did not happen
# here — and it is one printed line of it, because these refusals are printed a line at a time and a
# needle spanning two of them can never match, which would make this assertion a decoration.
hasnt "answer 1-loud: and does not assert an object store that was never opened" \
  "went past the refs and into the object" "$OUT"
rm -f "$ROOT/.git/refs/heads/dangling"

# ANSWER ∉ {0,1} + git SILENT — the branch this round fixes, reached the way an operator reaches it.
# `refs/remotes/origin/HEAD` is in every clone, its reflog gets ONE entry at clone time and never
# grows on a deploy root, and A7's first candidate is the concatenation `refs/remotes/origin/$REF` —
# so `--ref HEAD@{1}` exits 128 with an EMPTY stderr here, permanently, on a healthy host.
eq "fixture: the candidate really is 128 with git silent" "128|" \
  "$(gitc "$ROOT" rev-parse --verify --quiet --end-of-options 'refs/remotes/origin/HEAD@{1}' \
       2>"$T/ro.err" >/dev/null; printf '%s|%s' "$?" "$(cat "$T/ro.err")")"
run_refusal "answer other-silent: reflog syntax on a completely healthy store" \
  "git could not resolve 'refs/remotes/origin/HEAD@{1}'" --dry-run --ref 'HEAD@{1}'
has  "answer other-silent: names the status it got" "exited 128" "$OUT"
hasnt "answer other-silent: does NOT claim the refs could not be read" \
  "the REFS could not be read" "$OUT"
hasnt "answer other-silent: does not say git's error is above it — git printed nothing" \
  "git's own error is above this refusal" "$OUT"
has  "answer other-silent: says nothing was printed, so it claims no failed read" \
  "NOTHING WAS PRINTED ABOVE THIS REFUSAL" "$OUT"
has  "answer other-silent: names what a healthy store answers this way" \
  "reflog syntax whose reflog does not go back that far" "$OUT"
has  "answer other-silent: and tells the operator what to deploy from instead" \
  "full 40-character commit id" "$OUT"

# ⛔ AND THE NOTE ITSELF MUST NOT LIE (card#9611 r3). `--ref -foo` was classified by a
# `check-ref-format` call with no option guard, which parsed the leading `-` as an OPTION: exit 129
# with the usage message swallowed by 2>/dev/null, after which A7's note told the operator "'-foo'
# is not a ref name: it carries rev syntax" — false, and false in this card's own direction. ⚠ The
# guard git_ref_oid uses cannot be borrowed: check-ref-format accepts neither `--end-of-options` nor
# `--` (measured, git 2.53.0 — both exit 129 for EVERY ref, valid or not), so the leading dashes are
# stripped for the classification instead.
run_refusal "a --ref beginning with a dash is refused as the NAME it is" \
  "'-foo' does not resolve to a commit on origin" --dry-run --ref -foo
hasnt "dash --ref: is not called rev syntax" "carries rev syntax" "$OUT"
hasnt "dash --ref: and is not called an abbreviated id" "ABBREVIATED commit id" "$OUT"
run_refusal "a --ref beginning with a dash that DOES carry rev syntax is still called that" \
  "'-foo~2' does not resolve to a commit on origin" --dry-run --ref -foo~2
has "dash --ref with rev syntax: the note fires, one variable away" "carries rev syntax" "$OUT"

# ANSWER ∉ {0,1} + git LOUD — NAMED, NOT ASSERTED. Every shape measured that makes rev-parse exit
# non-0/1 LOUDLY is a refs-storage read failure (packed-refs unreadable; packed-refs corrupt), and
# a checkout in that state fails EARLIER commands of this same phase — so the branch is not
# reachable through this script today. Both halves of that are asserted rather than assumed: git
# really does answer that way, and the deploy really does stop before git_ref_oid is reached.
#
# ⛔ WHICH GATE MEETS IT HAS MOVED TWICE, and each move is stated rather than left for a reader to
# re-derive from an old sentence. It first said A7's `git fetch` meets it. Measured, git 2.53.0, on
# this fixture: `rev-parse --git-dir` (A3) exits 0 — it does not read the refs — while `status
# --porcelain` (A4) and `fetch` BOTH answer `fatal: couldn't read .git/packed-refs: Permission
# denied` at 128, and A4 ran first once card#9646 gave A4 its own status reading.
# ⇒ NOW IT IS A3b (card#9616), which runs between A3 and A4 and probes git's `:(literal)` pathspec
# magic. Its PLAIN form — `git ls-tree HEAD -- VERSION`, the step that makes a failure of the magic
# form attributable to the magic — resolves HEAD, which reads the refs, so this fixture answers it
# 128 and loud. Measured on this fixture. That is the right answer and not a regression: the cause
# named is "git could not list HEAD's tree", git's own `packed-refs` message is printed with it, and
# nothing is claimed about the release or about the pathspec support that was never reached. A4's
# refusal and A7's are the ones behind it, exercised on their own fixtures.
#
# ⛔ AND WHAT "STOPS" MEANT WAS NOT GOOD ENOUGH, which is card#9646's finding here rather than a
# tidy-up. `neq 0` was the assertion, and it passed identically before and after the fix: the
# unguarded assignment ENDED THE SCRIPT with git's 128 — a code the exit table does not list, no ⛔
# banner, no promise. `neq 0` cannot tell that from a refusal, so it certified "the deploy stops"
# over a death. The assertions are now the contract: exit 1 BECAUSE a gate refused, with the banner
# and the phase-A promise that make the 1 mean what the table says it means.
three_releases git_ref_oid_refs_unreadable
chmod 000 "$ROOT/.git/packed-refs"
eq "fixture: a name resolve really is 128 AND loud with packed-refs unreadable (a root runner is not this)" \
  "128|loud" \
  "$(gitc "$ROOT" rev-parse --verify --quiet --end-of-options refs/remotes/origin/main \
       2>"$T/ro.err" >/dev/null; printf '%s|%s' "$?" "$([ -s "$T/ro.err" ] && echo loud || echo silent)")"
eq "fixture: A3 passes it — \`rev-parse --git-dir\` does not read the refs" 0 \
  "$(LC_ALL=C gitc "$ROOT" rev-parse --git-dir >/dev/null 2>&1; echo $?)"
run --dry-run --ref main
eq   "answer other-loud: exit 1 — a gate REFUSES on it before git_ref_oid is reached" 1 "$RC"
has  "answer other-loud: the ⛔ REFUSED banner, so the 1 is a verdict and not a death" "⛔ REFUSED — " "$OUT"
has  "answer other-loud: the phase-A promise" \
  "Nothing was changed. The previous release is still serving." "$OUT"
has  "answer other-loud: named as the read that failed, with the status git gave" \
  "git could not list HEAD's tree in $ROOT (\`git ls-tree HEAD -- VERSION\` exited 128)" "$OUT"
has  "answer other-loud: git's own error is what the operator gets" "packed-refs" "$OUT"
hasnt "answer other-loud: and nothing claims the ref is absent" \
  "does not resolve to a commit on" "$OUT"
hasnt "answer other-loud: nor blamed on a pathspec support the probe never got to ask about" \
  "does not accept" "$OUT"
chmod 644 "$ROOT/.git/packed-refs"

# ══════════════════════════════════════════════════════════════════════════════════════════════
section "card#9646 — every phase-A exit is a REFUSAL, and names only what the run established"
# ⛔ THE CLASS. `refuse` is the only exit in bin/deploy.sh that prints `⛔ REFUSED — <cause>` and
# `Nothing was changed. The previous release is still serving.`, and the header's exit table says
# exit 1 MEANS that. Any command phase A runs WITHOUT reading its status breaks the promise from
# underneath: under `set -Eeuo pipefail` the script exits with THAT command's status and neither
# line. Three sub-shapes, by what the status collides with — and all three were live here:
#   · exit 1   — bash's `${2:?}`, and `git fetch` on a ref of this checkout it cannot read. READS
#                AS REFUSED. This is the dangerous one: the status says "nothing was touched" and
#                there is no banner to say it was never a verdict.
#   · exit 128 — most git fatals (`git fetch` on an unreachable remote, `git status` on an
#                unreadable index). A code the exit table does not list at all.
#   · exit 2   — a grep or an awk fed a fatal. That code MEANS "failed inside the window, the app
#                is DOWN" — the inversion, printed about a run that never opened a window.
# The suite could not see any of it: `run_refusal` asserted exit 1 and a needle, both of which a
# banner-less death satisfies. The two `has` lines it now carries are what close that, for every
# call site at once; the cases below are the sites where the status was not being read.
#
# ⛔ AND THE SECOND HALF IS THE CAUSE, not just the shape. A refusal may name only what the run
# ESTABLISHED. A3 asserted ONE cause — "is not a git checkout" — for a 128 that carries several,
# after throwing git's own message away; the operator most likely to meet it is the one whose prod
# checkout was restored from backup and is owned by another user, and they were sent looking for a
# checkout that is right there. Each case below therefore asserts WHICH cause is named AND that the
# other is not, with a control one variable away — an assertion on the exit code alone would have
# passed against the defect.

# ── A3 — git could not OPEN the repository, which is not "this is not a git checkout" ──────────
# THE DISCRIMINATOR IS GIT'S OWN WORDING. Every shape below exits 128; git says `not a git
# repository` in those words for the four that really are not a checkout, and `cannot change to
# '…': Not a directory` for a root that is a file. Anything else is the generic refusal, which is
# honest about an unrecognised wording rather than false about it. A3 was unfixtured before this.

# ⭐ G6 FIRST, BECAUSE IT IS THE ASYMMETRIC ONE. A fix that matched `dubious ownership` and let
# everything else fall back to "is not a git checkout" passes G1 AND all four of G2-G5 — every case
# a reader of the card would think to write — and reds only here. Measured, git 2.53.0: a `.git/
# config` reading `[core` answers `fatal: bad config line 1 in file .git/config` at 128.
mkfix repo_config_corrupt
printf '[core\n' > "$ROOT/.git/config"
eq "fixture: a corrupt .git/config really is 128 here" 128 \
  "$(LC_ALL=C gitc "$ROOT" rev-parse --git-dir >/dev/null 2>&1; echo $?)"
run_refusal "a .git/config git cannot parse" \
  "git could not open $ROOT as a repository (\`git rev-parse --git-dir\` exited 128)" --dry-run
has  "corrupt .git/config: git's own message reaches the operator" "bad config line" "$OUT"
hasnt "corrupt .git/config: is NOT called 'not a git checkout' — the checkout is right there" \
  "is not a git checkout" "$OUT"
has  "corrupt .git/config: says outright it is not that, so the operator does not go looking" \
  "IT IS NOT \"not a git checkout\"" "$OUT"

# G1 — dubious ownership: the shape a prod checkout restored from backup, rsynced or chowned is in.
#
# ⛔ THE CONDITION IS PRODUCED HERMETICALLY, AND THAT IS A MEASUREMENT RATHER THAN A PRECAUTION.
# A real ownership difference needs a second uid, which this suite does not have, so the condition
# comes from git's own `GIT_TEST_ASSUME_DIFFERENT_OWNER`. That knob only forces git PAST the uid
# check — `ensure_valid_ownership` then consults `safe.directory` — so a `safe.directory = *` in
# the SYSTEM or the GLOBAL gitconfig turns the 128 straight back into a 0, and the case would then
# be asserting a refusal against a run that succeeded. Measured, git 2.53.0, on a throwaway repo:
#   knob alone, no such entry                                      → 128, `detected dubious ownership`
#   knob + `safe.directory = *` in the SYSTEM config               → 0
#   knob + `safe.directory = *` in the GLOBAL config               → 0
#   knob + GIT_CONFIG_NOSYSTEM=1 + an EMPTY GIT_CONFIG_GLOBAL      → 128, even with both entries set
#   no knob, same hermetic environment                             → 0   ← the control below
# ⚠ THIS ARRIVED AS A CI RED WHERE THE CASE WAS GREEN LOCALLY (run 35370819477): the runner's git
# answered 0. The fixture asserted its own condition, so what the log said was that THE CONDITION
# WAS ABSENT — by name — rather than certifying a refusal that never happened. That is the check
# doing its job, and the reason the gate below exists rather than the reason to delete it.
: > "$T/empty.gitconfig"
DUBIOUS_ENV=( GIT_TEST_ASSUME_DIFFERENT_OWNER=1 GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL="$T/empty.gitconfig" )
mkfix repo_dubious_ownership
dubious_rc="$(env "${DUBIOUS_ENV[@]}" LC_ALL=C git -C "$ROOT" rev-parse --git-dir >/dev/null 2>&1; echo $?)"
if [ "$dubious_rc" -ne 128 ]; then
  # ⛔ NOT A SKIP AND NOT A PASS. `GIT_TEST_*` is git's own test scaffolding, not an interface git
  # promises to honour, so a build that ignores it is a thing that exists. The honest output is to
  # NAME what could not be produced — never to assert less, and never to drop the case quietly.
  notverified \
    "A3's generic branch against a DIFFERENTLY-OWNED checkout — the condition was never produced here, so none of that case ran." \
    "GIT_TEST_ASSUME_DIFFERENT_OWNER=1, with the system gitconfig off and an empty global one, exited $dubious_rc rather than 128." \
    "This runner: $(git --version); safe.directory system=[$(git config --system --get-all safe.directory 2>/dev/null | tr '\n' ' ')] global=[$(git config --global --get-all safe.directory 2>/dev/null | tr '\n' ' ')]." \
    "To exercise it here, this git must honour that knob, and no safe.directory entry it still reads may cover the fixture." \
    "A3's GENERIC BRANCH IS STILL COVERED on this runner by the corrupt \`.git/config\` case above, which uses no knob at all." \
    "What is NOT covered without this case is git's dubious-ownership WORDING and the two repairs the refusal quotes for it."
else
  eq "fixture: this git really refuses a differently-owned checkout at 128 (the knob is honoured)" \
    128 "$dubious_rc"
  : > "$CALL_LOG"
  OUT="$(env "${DUBIOUS_ENV[@]}" MEZZ_DEPLOY_ROOT="$ROOT" "$ROOT/bin/deploy.sh" --dry-run 2>&1)"; RC=$?
  eq  "dubious ownership: exit 1" 1 "$RC"
  has "dubious ownership: the ⛔ REFUSED banner" "⛔ REFUSED — " "$OUT"
  has "dubious ownership: the phase-A promise" \
    "Nothing was changed. The previous release is still serving." "$OUT"
  has "dubious ownership: named as a repository git could not OPEN" \
    "git could not open $ROOT as a repository" "$OUT"
  has "dubious ownership: git's own message reaches the operator" "detected dubious ownership" "$OUT"
  has "dubious ownership: with the repair git prints for it" "safe.directory" "$OUT"
  has "dubious ownership: and the other fix, which is the better one on a single-user host" \
    "chowning the checkout to the deploy user" "$OUT"
  hasnt "dubious ownership: is NOT called 'not a git checkout'" "is not a git checkout" "$OUT"
  unlogged "dubious ownership: never opened the window" "artisan down"
  # THE CONTROL, ONE VARIABLE AWAY: the same fixture, the same hermetic git environment, the knob
  # gone. It must deploy — which is also what says the NOSYSTEM/GLOBAL pair is not itself the cause.
  : > "$CALL_LOG"
  OUT="$(env GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL="$T/empty.gitconfig" \
      MEZZ_DEPLOY_ROOT="$ROOT" "$ROOT/bin/deploy.sh" --dry-run 2>&1)"; RC=$?
  eq "the control: the same checkout, owned by this user, deploys" 0 "$RC"
fi

# ── G2-G5 — the four shapes that really ARE "not a git checkout", each preserved ───────────────
# These are what the old refusal was RIGHT about, and a fix that generalised the headline over
# every 128 would red all four. Each asserts the preserved answer AND that the generic one is not
# reached. `run_refusal` carries the banner, the promise and exit 1 for each.
mkfix repo_plain_dir
mkdir -p "$T/repo_plain_dir/plain"
: > "$CALL_LOG"
OUT="$(MEZZ_DEPLOY_ROOT="$T/repo_plain_dir/plain" "$ROOT/bin/deploy.sh" --dry-run 2>&1)"; RC=$?
eq  "a plain directory: exit 1" 1 "$RC"
has "a plain directory: still refused as not a git checkout" \
  "$T/repo_plain_dir/plain is not a git checkout" "$OUT"
hasnt "a plain directory: not the generic 'could not open' answer" "git could not open" "$OUT"

mkfix repo_git_unreadable
chmod 000 "$ROOT/.git"
# ASSERTED, not assumed — root opens every mode, and under a root runner this fixture is an
# ordinary checkout and the case would certify nothing (canon #9).
eq "unreadable .git: the fixture really is unopenable by this user (a root runner cannot hold this)" \
  unopenable "$(env_openability "$ROOT/.git/HEAD")"
run_refusal "a .git directory this user cannot read" "is not a git checkout" --dry-run
hasnt "unreadable .git: git discovers PAST it rather than failing on it, so not the generic answer" \
  "git could not open" "$OUT"
chmod 755 "$ROOT/.git"

mkfix repo_gitfile_nowhere
rm -rf "$ROOT/.git"; printf 'gitdir: %s/nowhere\n' "$T" > "$ROOT/.git"
run_refusal "a .git file pointing at nothing" "is not a git checkout" --dry-run
hasnt "dangling .git file: not the generic 'could not open' answer" "git could not open" "$OUT"

mkfix repo_git_empty_dir
rm -rf "$ROOT/.git"; mkdir "$ROOT/.git"
run_refusal "a .git directory that is not a repository" "is not a git checkout" --dry-run
hasnt "empty .git directory: not the generic 'could not open' answer" "git could not open" "$OUT"

# And the fifth wording git uses for the same answer: a deploy root that is a FILE.
mkfix repo_root_is_a_file
printf 'x\n' > "$T/repo_root_is_a_file/afile"
: > "$CALL_LOG"
OUT="$(MEZZ_DEPLOY_ROOT="$T/repo_root_is_a_file/afile" "$ROOT/bin/deploy.sh" --dry-run 2>&1)"; RC=$?
eq  "a deploy root that is a file: exit 1" 1 "$RC"
has "a deploy root that is a file: refused as not a git checkout (git says \`cannot change to\`)" \
  "is not a git checkout" "$OUT"
hasnt "a deploy root that is a file: not the generic 'could not open' answer" "git could not open" "$OUT"

# ── S2 — A4's `git status`, which A3 does NOT cover ────────────────────────────────────────────
# ⛔ MEASURED, NOT ASSUMED, and the measurement is why this is fixed rather than recorded as
# unreachable (card#9646 § 5 S2): on a checkout whose `.git/index` is mode 000, A3's `rev-parse
# --git-dir` exits 0 — it never opens the index — and A4's `git status --porcelain` exits 128 with
# `fatal: .git/index: index file open failed: Permission denied`. The precondition is LIVE. The
# unguarded assignment ended phase A at 128, and the emptiness it left behind reads exactly like a
# clean tree, which is the card#9608 direction one gate along.
mkfix index_unreadable
chmod 000 "$ROOT/.git/index"
eq "fixture: the index really is unopenable by this user (a root runner cannot hold this)" \
  unopenable "$(env_openability "$ROOT/.git/index")"
eq "fixture: A3 passes it — \`rev-parse --git-dir\` never opens the index" 0 \
  "$(LC_ALL=C gitc "$ROOT" rev-parse --git-dir >/dev/null 2>&1; echo $?)"
run_refusal "an unreadable .git/index (A4)" \
  "git could not read the state of $ROOT (\`git status --porcelain\` exited 128)" --dry-run
has  "unreadable index: git's own error reaches the operator" "index file open failed" "$OUT"
hasnt "unreadable index: the empty result is NOT read as a clean tree" \
  "has local modifications" "$OUT"
has  "unreadable index: says outright that cleanliness was not established" \
  "this is not \"the tree is clean\"" "$OUT"
hasnt "unreadable index: makes no claim about A3, which passed" "is not a git checkout" "$OUT"
chmod 644 "$ROOT/.git/index"
# THE CONTROL, one variable away: the same checkout with a readable index deploys.
run --dry-run
eq "the control: the same checkout, index readable, deploys" 0 "$RC"

# ── A7 — the fetch, whose status was never read ────────────────────────────────────────────────
# ⛔ STATUS ONLY, NEVER STDERR. A fetch that SUCCEEDS prints to stderr as a matter of course, and
# prints git's own `error:` lines while still exiting 0 when an object it does not need is
# unreadable. Every `three_releases` case above is that shape and deploys through it, so they are
# the standing controls for this: a stderr-keyed fetch rule reds them all.

# F1 — a remote-tracking TIP this checkout cannot read. A fetch reads its own tips to tell the
# remote what it has, so this fails the FETCH, in the checkout's own store. Measured, git 2.53.0:
# `fatal: bad object <oid>`, exit 1.
# ⚠ `eq 1` IS GREEN BEFORE THE FIX and is not the assertion that catches anything here: the
# unguarded fetch died with git's own 1, the status the exit table says means "refused, nothing was
# touched". THE BANNER AND THE PROMISE ARE THE RED. That is the whole shape of this card.
three_releases fetch_tip_blind
FETCH_TIP_OID="$(gitc "$ROOT" rev-parse refs/remotes/origin/main)"
FETCH_TIP_OBJ="$ROOT/.git/objects/${FETCH_TIP_OID:0:2}/${FETCH_TIP_OID:2}"
blind_object refs/remotes/origin/main
run --dry-run
eq  "fetch on an unreadable tip: exit 1" 1 "$RC"
has "fetch on an unreadable tip: the ⛔ REFUSED banner, so the 1 is a verdict and not a death" \
  "⛔ REFUSED — " "$OUT"
has "fetch on an unreadable tip: the phase-A promise" \
  "Nothing was changed. The previous release is still serving." "$OUT"
has "fetch on an unreadable tip: named as the fetch, with the status git gave" \
  "git could not fetch origin (\`git fetch\` exited 1)" "$OUT"
has "fetch on an unreadable tip: git's own message reaches the operator" "bad object" "$OUT"
hasnt "fetch on an unreadable tip: nothing claims the ref did not resolve — no ref was resolved" \
  "does not resolve to a commit on" "$OUT"
has "fetch on an unreadable tip: says the failure is in THIS checkout's store, not at the remote" \
  "a ref of THIS CHECKOUT that git could not read" "$OUT"
has "fetch on an unreadable tip: and that a fetch is not the repair for it" \
  "it is the thing that failed" "$OUT"
unlogged "fetch on an unreadable tip: never opened the window" "artisan down"
# THE CONTROL, one variable away: the same fixture with that object readable deploys.
chmod 444 "$FETCH_TIP_OBJ"
run --dry-run
eq "the control: the same checkout, that object readable, deploys" 0 "$RC"

# ⭐ F2 — THE ASYMMETRIC ONE, and the reason it is here. A reader of the card writes `[ "$fetch_rc"
# -ne 1 ] || refuse …`: it handles the exit 1 the card names, F1 passes, and git's 128 still escapes
# as a banner-less death. Only a fixture whose fetch exits 128 catches that. Measured, git 2.53.0,
# with the remote gone: `does not appear to be a git repository` + `Could not read from remote
# repository`, exit 128. Run WITHOUT --dry-run, which also kills the mutant that instruments only
# the dry-run path — A7 fetches on both (the fetch moves remote-tracking refs and nothing else).
mkfix fetch_remote_gone
mv "$ORIGIN" "$ORIGIN.gone"
run
eq  "fetch with the remote gone: exit 1 (128 is what git gave; the REFUSAL is what the operator gets)" \
  1 "$RC"
has "fetch with the remote gone: the ⛔ REFUSED banner" "⛔ REFUSED — " "$OUT"
has "fetch with the remote gone: the phase-A promise" \
  "Nothing was changed. The previous release is still serving." "$OUT"
has "fetch with the remote gone: named as the fetch, with the status git gave" \
  "git could not fetch origin (\`git fetch\` exited 128)" "$OUT"
has "fetch with the remote gone: git's own message reaches the operator" \
  "Could not read from remote repository" "$OUT"
hasnt "fetch with the remote gone: not worded as a failed read of the release" \
  "⛔ REFUSED — git could not read" "$OUT"
has "fetch with the remote gone: says no ref was resolved and no gate ran" \
  "no ref was resolved and no gate ran" "$OUT"
unlogged "fetch with the remote gone: never opened the window, on a run with no --dry-run" "artisan down"
eq  "fetch with the remote gone: HEAD is where it was" "$V1" "$(gitc "$ROOT" rev-parse HEAD)"
# THE CONTROL, one variable away: put the remote back and the same command deploys.
mv "$ORIGIN.gone" "$ORIGIN"
run --dry-run
eq "the control: the same checkout with its remote back deploys" 0 "$RC"

# F3 — MEZZ_REMOTE naming no remote of this checkout. ⚠ THIS CASE MOVED, AND ITS OLD ASSERTIONS
# WERE THE DEFECT card#9832 CLOSES. It used to reach the FETCH and assert that the headline "names
# the remote it was given" — `git could not fetch nowhere` — which is the echo that puts a
# credential-bearing MEZZ_REMOTE on screen, since `git fetch` takes a URL as readily as a name.
# A3c now refuses such a value before A7 runs and without printing it, so this case's subject is
# the ORDERING: the run stops before the fetch, and the fetch's own refusals no longer speak for
# a MEZZ_REMOTE that names nothing. The refusal itself is asserted in § card#9832 below.
mkfix fetch_no_such_remote; export MEZZ_REMOTE=nowhere
run_refusal "MEZZ_REMOTE naming no remote" \
  "MEZZ_REMOTE does not name a remote of $ROOT" --dry-run
hasnt "MEZZ_REMOTE naming no remote: A7 was never reached, so no fetch spoke for it" \
  "git could not fetch" "$OUT"
hasnt "MEZZ_REMOTE naming no remote: and A7's step line, which prints the value, never ran" \
  "Fetching" "$OUT"
hasnt "MEZZ_REMOTE naming no remote: claims no ref failed to resolve — none was asked for" \
  "does not resolve to a commit on" "$OUT"

# ⛔ A RELEASE WITH NO server/bootstrap/app.php REFUSES, where it warned and deployed. Grounded in
# `server/artisan` line 14 — `$app = require_once __DIR__.'/bootstrap/app.php';` — so EVERY artisan
# command of such a release fails, the first of them `php artisan optimize:clear`, INSIDE the window,
# with the app down and recovery a human act. A6 and A13 move exactly that failure forward already.
no_bootstrap() { rm -f "$1/server/bootstrap/app.php"; }
mkfix no_bootstrap_file no_bootstrap
run_refusal "a release with no server/bootstrap/app.php" \
  "server/bootstrap/app.php is not in" --dry-run
has "no bootstrap/app.php: names the command that would fail first, and where" \
  "php artisan optimize:clear" "$OUT"

# The control, one variable away: the absent file that stays a WARNING. A release with no
# server/.env.example costs a comparison against this host's .env, not a boot, so it deploys.
no_env_example() { rm -f "$1/server/.env.example"; }
mkfix no_env_example_file no_env_example
run --dry-run
eq  "a release with no server/.env.example: still deploys (a warning, not a refusal)" 0 "$RC"
has "no .env.example: says which comparison did not happen" \
    "no key of it was compared against this host's .env" "$OUT"

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
section "card#9616 — the host's bash, git and npm, each refused BY NAME before anything is touched"
# Until this card A1 asked only whether each tool was PRESENT (`command -v`), for all twelve. PHP was
# the exception and still is: A6 has read the floor out of the release since card#9203. So a host whose
# bash, git or npm was too old got PARTWAY IN — npm ≤ 6 failed `npm ci` in phase B with the site
# already down, a git without `:(literal)` failed every read of the release and was reported as a
# release missing its files, and a bash below 4.4 DIED on `"${ref_note[@]}"` at A7's refusal, exiting 1
# with no ⛔ banner and no "Nothing was changed" promise — the code the exit table reserves for
# "refused, nothing was touched", reached by a death.
#
# ⛔ EVERY CASE HERE ASSERTS THE BANNER AND THE PROMISE, not the exit code (`run_refusal` carries all
# three). PR #191 measured why: in this failure class an exit-code assertion catches NOTHING, because
# the death being refused already exits 1.

# ── the bash floor ─────────────────────────────────────────────────────────────────────────────
# ⛔ BASH_VERSINFO CANNOT BE FAKED INSIDE A RUNNING BASH, so there is no knob that puts this host's
# bash below a floor. The COMPARISON is therefore a predicate of its own and is driven here with the
# versions either side of the floor — DERIVED from the declaration, never retyped — and the two GATES
# are driven end to end by moving the FLOOR instead: a copy of deploy.sh whose floor is above the bash
# running this suite is, to that copy, a host below its floor.
# The value the script HOLDS, read by sourcing it (§ library mode) — never by a pattern of this file's.
BASH_FLOOR_HERE="$(env_lib "$T/none" eval 'printf %s "$BASH_FLOOR"' 2>/dev/null)"
neq "fixture: bin/deploy.sh declares a BASH_FLOOR this suite can read" "" "$BASH_FLOOR_HERE"
floor_major="${BASH_FLOOR_HERE%%.*}"; floor_minor="${BASH_FLOOR_HERE#*.}"
floor_below="$floor_major.$((floor_minor - 1))"
# The bash deploy.sh runs under here is PATH's (its `#!/usr/bin/env bash`), which need not be the one
# running this suite — so the version is asked of THAT bash, the way deploy.sh itself will read it.
HOST_BASH_MM="$(bash -c 'printf "%s.%s" "${BASH_VERSINFO[0]}" "${BASH_VERSINFO[1]}"')"
HOST_BASH_FULL="$(bash -c 'printf %s "$BASH_VERSION"')"
# bash_pred <A> <B> — the predicate's answer as text, THREE-VALUED (card#9984). `meets` and `below`
# are its two verdicts; `refused` is the third answer, for operands it cannot compare — and it has
# to be told apart from `below` HERE, because a third state folded into a verdict is the whole
# defect that card fixed. `refuse` exits 1 from inside env_lib's subshell after printing the banner,
# so the BANNER is the discriminator and a bare `return 1` is not mistaken for it.
bash_pred() {
  local out rc=0
  out="$(env_lib "$T/none" bash_meets_floor "$@" 2>&1)" || rc=$?
  case "$out" in *"⛔ REFUSED — "*) echo refused; return 0 ;; esac
  [ "$rc" -eq 0 ] && echo meets || echo below
}
eq "bash_meets_floor: $floor_below is BELOW the floor $BASH_FLOOR_HERE" below "$(bash_pred "$floor_below" "$BASH_FLOOR_HERE")"
eq "bash_meets_floor: $BASH_FLOOR_HERE is exactly the floor, and meets it" meets "$(bash_pred "$BASH_FLOOR_HERE" "$BASH_FLOOR_HERE")"
eq "bash_meets_floor: a later major meets it" meets "$(bash_pred "$((floor_major + 1)).0" "$BASH_FLOOR_HERE")"
# Minors compare as NUMBERS, not as text — bash 4.10 would sort below 4.4 as a string.
eq "bash_meets_floor: $floor_major.10 is not below $floor_major.4" meets "$(bash_pred "$floor_major.10" "$floor_major.4")"
eq "bash_meets_floor: 4.3 is below 4.4" below "$(bash_pred 4.3 4.4)"
eq "bash_meets_floor: 4.4 meets 4.4" meets "$(bash_pred 4.4 4.4)"

# ── ver_ge: the fields, and the operands it will not compare (card#9984) ───────────────────────
# The split behind this predicate no longer goes through a here-string (bin/deploy.sh states why).
# These hold the REPLACEMENT to the same field semantics the old one had — A6 hands it three-field
# PHP versions and A12 hands it a bare major, so neither shape is incidental.
eq "ver_ge: the third field is compared too" below "$(bash_pred 8.4.1 8.4.2)"
eq "ver_ge: a field the operand does not have reads as 0, so 8.4 is below 8.4.2" below "$(bash_pred 8.4 8.4.2)"
eq "ver_ge: …and 8.4 therefore MEETS 8.4.0" meets "$(bash_pred 8.4 8.4.0)"
eq "ver_ge: a non-numeric suffix is truncated, so 8.5.0RC1 counts as its release" meets "$(bash_pred 8.5.0RC1 8.5.0)"
eq "ver_ge: a leading-zero field is decimal and not octal (4.09 is 4.9)" meets "$(bash_pred 4.09 4.9)"
eq "ver_ge: a bare major is a floor of its own — A12 compares npm against \`7\`" meets "$(bash_pred 9.2.0 7)"
eq "ver_ge: …and npm 6.14.0 is below that same bare 7" below "$(bash_pred 6.14.0 7)"

# ⛔ AND THE OPERANDS A COMPARISON CANNOT BE MADE OUT OF — the fails-open half of card#9984, driven
# straight into the predicate with the VALUES that produce it. Until that card each of these
# answered `meets`: an operand that is not a version left every field 0, 0 is neither greater nor
# less than 0 at any field, and the function returned 0. Every caller is a floor gate reading
# that as "the floor is met", and none of them is under `set -e` (each is left of a `||` or inside
# an `if`), so nothing died and nothing was refused — the host the gate exists to stop deployed.
# ⚠ THE EMPTY OPERANDS ARE NOT HYPOTHETICAL INPUTS: they are what a `<<<` read that FAILED used to
# leave behind, which is the route the card was filed on. The construct is gone, so what is pinned
# here is the property rather than the one host condition that reached it.
# RED at `9c4d67f` — restore that commit's `ver_ge` body verbatim into this tree and these reds,
# with `meets` where `refused` is expected. Naming the commit is what makes the mutation
# reproducible from this file rather than from a PR body nobody will be reading in a year.
eq "ver_ge: both operands empty — what a failed read leaves — is REFUSED, never 'meets'" \
   refused "$(bash_pred "" "")"
eq "ver_ge: an empty HOST version is refused, not read as 0" refused "$(bash_pred "" "$BASH_FLOOR_HERE")"
eq "ver_ge: an empty FLOOR is refused, not read as 0 — which every version 'meets'" \
   refused "$(bash_pred "$BASH_FLOOR_HERE" "")"
eq "ver_ge: a floor that is not a version at all is refused, not truncated to 0" \
   refused "$(bash_pred "$BASH_FLOOR_HERE" banana)"
eq "ver_ge: a HOST version that is not a version is refused too" refused "$(bash_pred banana "$BASH_FLOOR_HERE")"
# …and the refusal says what it could not do, rather than reporting a floor verdict it never reached.
VER_GE_OUT="$(env_lib "$T/none" bash_meets_floor "" "" 2>&1)"; VER_GE_RC=$?
eq  "ver_ge refusal: exit 1" 1 "$VER_GE_RC"
has "ver_ge refusal: the ⛔ REFUSED banner, so it is a verdict and not a death" "⛔ REFUSED — " "$VER_GE_OUT"
has "ver_ge refusal: names the comparison it could not perform" \
    "a version comparison this deploy cannot perform" "$VER_GE_OUT"
has "ver_ge refusal: names BOTH operands as at fault when both are" "NOT A VERSION: the first, ''; the second, ''" "$VER_GE_OUT"
has "ver_ge refusal: names the chain it was called through, so the gate that asked is identifiable" \
    "Called through: ver_ge bash_meets_floor" "$VER_GE_OUT"
hasnt "ver_ge refusal: reports no floor verdict, since it reached none" "is below this script's floor" "$VER_GE_OUT"
no_shell_death "ver_ge refusal" "$VER_GE_OUT"
# ONE READER: what A6b takes out of a release's text, and what deploy-selftest.yml reads this file's
# floor with, is bash_floor_declared — held here to the value the file really declares, so a workflow
# pattern of its own cannot drift away from the gate's.
eq "bash_floor_declared reads deploy.sh's own floor back as declared" "$BASH_FLOOR_HERE" \
   "$(env_lib "$T/none" bash_floor_declared < "$DEPLOY" 2>/dev/null)"
# …and it reads the declaration's SHAPES as one value: quoted, or with a trailing comment.
eq "bash_floor_declared: a quoted value with a trailing comment, and only the FIRST declaration" 4.4 \
   "$(printf 'x=1\nBASH_FLOOR="4.4"  # measured\nBASH_FLOOR=9.9\n' | env_lib "$T/none" bash_floor_declared 2>/dev/null)"
eq "bash_floor_declared: no declaration, no floor — which is every release before this card" "" \
   "$(printf '#!/usr/bin/env bash\n# BASH_FLOOR=9.9 in a comment is not a declaration\n' | env_lib "$T/none" bash_floor_declared 2>/dev/null)"

# A1 — the SERVING copy's floor. The v1 mutator raises the floor of the copy the HOST is running, so
# the bash running this suite is, to that copy, below the floor.
raise_bash_floor() { sed -i 's/^BASH_FLOOR=.*/BASH_FLOOR=99.0/' "$1/bin/deploy.sh"; }
mkfix bash_below_serving_floor '' raise_bash_floor
run_refusal "a bash below the SERVING copy's floor (A1)" \
  "bash $HOST_BASH_FULL is below this script's floor, BASH_FLOOR=99.0" --dry-run
hasnt "a bash below the serving floor: refused in A1, before any gate read the release" "ok — PHP" "$OUT"
hasnt "a bash below the serving floor: not blamed on the release being deployed" "the release being deployed declares" "$OUT"

# A6b — the TARGET release's floor, which the serving copy's A1 never reads (design review r1, F1).
# The serving copy declares the real floor, which this bash meets; the release being deployed raises
# it. The deployed copy is what the re-exec runs INSIDE the window, so a floor only IT declares has to
# refuse here, before anything is touched.
mkfix bash_below_target_floor raise_bash_floor
run_refusal "a release that RAISES the bash floor above this host's bash (A6b)" \
  "bash $HOST_BASH_MM is below the floor the release being deployed declares: BASH_FLOOR=99.0 in bin/deploy.sh at" --dry-run
has "raised bash floor: says the SERVING copy's own floor was met, so the operator knows which one bound" \
  "This copy's own floor ($BASH_FLOOR_HERE) was met — A1 checked it." "$OUT"
has "raised bash floor: says why a floor the serving copy does not declare still binds" \
  "re-execs THAT release's bin/deploy.sh" "$OUT"
unlogged "raised bash floor: npm ci never ran" "npm ci"
# The control, one variable away: the same deploy with the release's floor AT this host's own bash.
floor_at_host() { sed -i "s/^BASH_FLOOR=.*/BASH_FLOOR=$HOST_BASH_MM/" "$1/bin/deploy.sh"; }
mkfix bash_at_target_floor floor_at_host
run --dry-run
eq  "control: a release whose floor IS this host's bash deploys" 0 "$RC"
has "control: and says which floor it held the bash to, and where it read it" \
  "ok — bash $HOST_BASH_MM meets BASH_FLOOR=$HOST_BASH_MM, declared by bin/deploy.sh at" "$OUT"

# A release cut before this card declares no floor. That is NOT a refusal — it could not have declared
# one — and the run says exactly what WAS enforced for it instead of going quiet.
drop_bash_floor() { sed -i '/^BASH_FLOOR=/d' "$1/bin/deploy.sh"; }
mkfix bash_target_no_floor drop_bash_floor
run --dry-run
eq  "a release with no BASH_FLOOR line: deploys" 0 "$RC"
has "a release with no BASH_FLOOR line: says so, and names the floor enforced instead" \
  "declares no BASH_FLOOR (it predates card#9616); only this copy's floor, $BASH_FLOOR_HERE, was enforced (A1)" "$OUT"

# nonsense_bash_floor — writes a BASH_FLOOR that is not a version into <tree>'s bin/deploy.sh. The
# VALUE is the knob `BAD_FLOOR`, defaulting to `banana`, so the separator table further down drives
# this ONE mutator instead of minting six (canon #5, and the same shape as this suite's STUB_ knobs).
# No value used with it contains a `|` or an `&`, which is what lets the `sed` stay this simple.
# ⛔ THE VALUE IS WRITTEN QUOTED, which is not cosmetic: `BASH_FLOOR=4 4` unquoted is an assignment
# followed by the COMMAND `4`, so under `set -Eeuo pipefail` that copy of deploy.sh dies at 127 with
# no banner before `main` is reached — it is not a floor any gate gets to refuse, it is a script
# that does not run. MEASURED. Quoted is a shape this file's own header permits ("unquoted or
# quoted") and `bash_floor_declared` strips, so every value below is a declaration a gate really
# sees.
# ⚠ `${BAD_FLOOR-banana}`, NOT `${BAD_FLOOR:-banana}`. The colon form treats an EMPTY value as
# unset, so `BAD_FLOOR=''` would have written `banana` and the blank-declaration case below would
# have tested the wrong thing — measured: it did, and the case reded on the wrong refusal, which
# is the whole reason that case asserts the message and not just the exit code.
nonsense_bash_floor() { sed -i "s|^BASH_FLOOR=.*|BASH_FLOOR=\"${BAD_FLOOR-banana}\"|" "$1/bin/deploy.sh"; }
mkfix bash_target_floor_unreadable nonsense_bash_floor
run_refusal "a release whose BASH_FLOOR is not a version" \
  "declares BASH_FLOOR='banana', which is not a version" --dry-run

# ── what a BASH_FLOOR IS, held to ONE test wherever it is read (card#9984) ─────────────────────
# ⛔ `bash_floor_is_version` IS THAT TEST, and it is a predicate rather than a refusal because each
# of its three callers speaks about a different copy of the declaration — A1 about this script's,
# A6b about the release's, deploy-selftest.yml's floor step about the tree it is measuring — so
# each owes its own words while none of them owes its own PATTERN. Driven here directly, because
# what it accepts is the whole of what the gates will act on.
floor_ok() { env_lib "$T/none" bash_floor_is_version "$1" >/dev/null 2>&1 && echo version || echo not; }
eq "bash_floor_is_version: the floor this file declares is one" version "$(floor_ok "$BASH_FLOOR_HERE")"
eq "bash_floor_is_version: a two-digit minor is one" version "$(floor_ok 4.10)"
eq "bash_floor_is_version: a leading zero is still digits" version "$(floor_ok 04.4)"
# The separator table. EVERY ONE of these begins with a digit, which is all `ver_ge` ever required.
eq "bash_floor_is_version: a comma for the dot is not a version" not "$(floor_ok '4,4')"
eq "bash_floor_is_version: a non-numeric minor is not a version" not "$(floor_ok '4.x')"
eq "bash_floor_is_version: a hyphen for the dot is not a version" not "$(floor_ok '4-4')"
eq "bash_floor_is_version: a missing separator is not a version" not "$(floor_ok '4x')"
eq "bash_floor_is_version: a bare major is not a bash floor" not "$(floor_ok '4')"
eq "bash_floor_is_version: a space for the dot is not a version" not "$(floor_ok '4 4')"
# …and the shapes A6b's old `[0-9]*.[0-9]*` glob admitted, which this one does not.
eq "bash_floor_is_version: a trailing non-digit is not a version (the old glob took it)" not "$(floor_ok '4.4x')"
eq "bash_floor_is_version: a third field is not <major>.<minor> (the old glob took it, as floor 4.0.5)" \
   not "$(floor_ok '4.x.5')"
eq "bash_floor_is_version: three numeric fields are refused too — this compares two" not "$(floor_ok '4.4.1')"
eq "bash_floor_is_version: an empty declaration is not a version" not "$(floor_ok '')"
eq "bash_floor_is_version: a dotless word is not a version" not "$(floor_ok banana)"
eq "bash_floor_is_version: a leading v is not a version" not "$(floor_ok v4.4)"
eq "bash_floor_is_version: a leading dot is not a version" not "$(floor_ok '.4')"
eq "bash_floor_is_version: a trailing dot is not a version" not "$(floor_ok '4.')"

# ── and the OTHER predicate, which is not a looser spelling of it (card#9984 r4) ───────────────
# `ver_is_comparable` asks a different question of a HOST version: does every dot-field begin
# with a digit? A1c holds `npm --version` to it. The two predicates are driven side by side
# because the difference between them is what a future editor is most likely to get wrong —
# a floor is exactly two numeric fields, a host version is two or more and may carry a suffix
# `ver_ge` truncates on purpose.
# ⚠ IT IS STRICTER THAN `ver_ge` NEEDS, deliberately: `ver_ge` reads three fields, so a fourth
# that is not digit-led could not have been misread — it is never read. The cases below pin
# that surplus as INTENDED rather than leaving it to look like an oversight; bin/deploy.sh's
# header says why it is not narrowed to the first three.
comparable() { env_lib "$T/none" ver_is_comparable "$1" >/dev/null 2>&1 && echo comparable || echo not; }
eq "ver_is_comparable: an ordinary three-field version is" comparable "$(comparable 9.2.0)"
eq "ver_is_comparable: two fields are enough" comparable "$(comparable 9.2)"
eq "ver_is_comparable: a prerelease suffix is fine — ver_ge truncates it on purpose" \
   comparable "$(comparable '9.2.0-pre.1')"
eq "ver_is_comparable: …as is the RC form ver_ge's own header documents" comparable "$(comparable 8.5.0RC1)"
# The shape A1c's old glob admitted: a field that STARTS with a non-digit, which ver_ge reads as 0.
eq "ver_is_comparable: a non-numeric MIDDLE field is not (ver_ge would read it as 0)" \
   not "$(comparable '9.x.5')"
eq "ver_is_comparable: …the same shape lower down the range" not "$(comparable '6.x.9')"
eq "ver_is_comparable: a bare major is not — A1c needs a dotted version" not "$(comparable 9)"
eq "ver_is_comparable: a leading v is not" not "$(comparable v9.2)"
eq "ver_is_comparable: a word is not" not "$(comparable banana)"
eq "ver_is_comparable: an empty answer is not" not "$(comparable '')"
eq "ver_is_comparable: an empty middle field is not" not "$(comparable '9..2')"
eq "ver_is_comparable: a trailing dot is not" not "$(comparable '9.2.')"
# ⛔ THE SURPLUS STRICTNESS, PINNED AS INTENDED. `ver_ge` reads three fields, so a FOURTH that is
# not digit-led could not have been misread — it is never read. These are refused anyway, and
# these cases exist so that the next reader finds a decision rather than an oversight, and so that
# narrowing the predicate to the first three fields reds here rather than passing quietly.
eq "ver_is_comparable: a non-numeric FOURTH field is refused, though ver_ge never reads it" \
   not "$(comparable '1.0.0-alpha.beta')"
eq "ver_is_comparable: …and semver build metadata likewise" not "$(comparable '9.2.0+build.abc')"
# The twin, one field away: npm's own prerelease convention is `-<tag>.<number>`, so its fourth
# field IS digit-led and is accepted — which is why the strictness costs nothing real.
eq "ver_is_comparable: npm's own prerelease shape has a digit-led fourth field, and passes" \
   comparable "$(comparable '7.0.0-beta.0')"
eq "ver_is_comparable: …as does the one A1c's control deploys with" comparable "$(comparable '9.2.0-pre.1')"
# ⛔ THE PREDICATES DISAGREE, ON PURPOSE, and these pin the disagreement so that neither can be
# quietly swapped for the other: a bash floor may not carry a suffix or a third field, and a host
# version may.
eq "the two differ: 9.2.0 is a comparable host version…" comparable "$(comparable 9.2.0)"
eq "…and is NOT a bash floor, which is exactly two fields" not "$(floor_ok 9.2.0)"
eq "the two differ: 8.5.0RC1 is a comparable host version…" comparable "$(comparable 8.5.0RC1)"
eq "…and is NOT a bash floor, which carries no suffix" not "$(floor_ok 8.5.0RC1)"
eq "the two agree: a plain two-field version is both" comparable "$(comparable 4.4)"
eq "…and a bash floor" version "$(floor_ok 4.4)"

# ⭐ AND A1 NOW HOLDS THIS COPY'S OWN DECLARATION TO IT, BEFORE COMPARING ANYTHING (card#9984 r2).
# A6b has always refused a non-version floor in the TARGET release; A1 handed its own straight to
# `ver_ge`, whose leading-digit test is the right one for a predicate A6 gives three-field PHP
# versions and A12 a bare `7`, and much too loose for a bash floor. MEASURED by DELETING A1's
# `bash_floor_is_version "$BASH_FLOOR" || refuse` guard — which is the mutation these cases red
# on, runnable in this tree, where a SHA no published ref reaches would not be. Library-mode,
# host bash 4.0, against a serving copy whose floor line was meant to read `4.4`:
# every value in the loop below was read as 4.0.0 and answered MEETS — bash 4.0 deploying past a
# 4.4 floor. `banana` was caught, because it does not start with a digit; a mistyped SEPARATOR is
# the likelier typo and was not. A floor read as far as it parses and then passed is the same
# defect as one never read at all.
# The v1 mutator breaks the SERVING copy's line; `floor_at_host` — A6b's own control mutator,
# reused — gives the RELEASE a floor this host meets, so the release is well-formed and A1 is the
# only gate at issue. Each `hasnt` is a step the old code reached and this one must not, which is
# what makes these cases a measurement of A1 rather than of whichever later gate stopped the run.
bad_floor_n=0
for BAD_FLOOR in 'banana' '4,4' '4.x' '4-4' '4x' '4' '4 4'; do
  bad_floor_n=$((bad_floor_n + 1))
  mkfix "bash_serving_floor_bad$bad_floor_n" floor_at_host nonsense_bash_floor
  run_refusal "this copy's BASH_FLOOR is '$BAD_FLOOR' (A1)" \
    "this copy of bin/deploy.sh declares BASH_FLOOR='$BAD_FLOOR', which is not a version" --dry-run
  hasnt "serving floor '$BAD_FLOOR': never reported as a floor this host's bash MET" \
    "meets BASH_FLOOR" "$OUT"
  hasnt "serving floor '$BAD_FLOOR': refused in A1, before any gate read the release" "ok — PHP" "$OUT"
  # ⚠ NOT a `hasnt` on `declares BASH_FLOOR='…'`: A1's own refusal now uses those very words, so
  # that needle would fire on the refusal being asserted. A6b's own preamble is what names A6b.
  hasnt "serving floor '$BAD_FLOOR': not blamed on the release, whose floor is intact" \
    "the floor that release declares" "$OUT"
  no_shell_death "serving floor '$BAD_FLOOR'" "$OUT"
done
unset BAD_FLOOR   # …so every later use of nonsense_bash_floor is `banana` again.

# ⛔ AND THE LINE BEING GONE — the member of this class that was still a DEATH rather than a
# refusal, found auditing the separator table above for siblings (canon #7). Measured at `9c4d67f`
# and before this card's own guard: with the `BASH_FLOOR=` line deleted from the serving copy, the first expansion
# of `$BASH_FLOOR` died under `set -u` with `BASH_FLOOR: unbound variable` — exit 1, no ⛔ banner,
# no promise, which the exit table at the top of bin/deploy.sh says means *refused, nothing was
# touched*. `drop_bash_floor` is A6b's own mutator, reused: there it produces the SURVIVABLE
# "predates card#9616" path for a release, and here, on the SERVING copy, it must refuse — the two
# are different facts about different copies and the suite holds both.
# ⚠ NO v2 MUTATOR HERE, and that is not an omission: `floor_at_host` replaces a line, and this
# fixture has deleted it, so it would match nothing. The RELEASE therefore declares no floor
# either — A6b's survivable path — which changes nothing about what is asserted, because A1
# refuses first and that is the whole of what this case measures.
mkfix bash_serving_floor_absent '' drop_bash_floor
run_refusal "this copy declares no BASH_FLOOR at all (A1)" \
  "this copy of bin/deploy.sh declares no BASH_FLOOR" --dry-run
has "an absent serving floor: says a release without one is a different matter" \
  "predates card#9616 (A6b)" "$OUT"
hasnt "an absent serving floor: not a bash death — no unbound-variable diagnostic" \
  "unbound variable" "$OUT"
hasnt "an absent serving floor: never reported as a floor this host's bash MET" "meets BASH_FLOOR" "$OUT"
# …and an EMPTY declaration takes that same refusal: the remedy is the same line either way.
BAD_FLOOR=''
mkfix bash_serving_floor_blank floor_at_host nonsense_bash_floor
run_refusal "this copy's BASH_FLOOR is blank (A1)" \
  "this copy of bin/deploy.sh declares no BASH_FLOOR" --dry-run
hasnt "a blank serving floor: not a bash death" "unbound variable" "$OUT"
unset BAD_FLOOR

# A6b's END, tightened with it: a RELEASE declaring `4.x.5` used to pass that gate's
# `[0-9]*.[0-9]*` glob and be enforced as the floor 4.0.5 — a floor it never declared. Refused now.
BAD_FLOOR='4.x.5'
mkfix bash_target_floor_partly_readable nonsense_bash_floor
run_refusal "a release whose BASH_FLOOR parses only partly (A6b)" \
  "declares BASH_FLOOR='4.x.5', which is not a version" --dry-run
has "a partly-readable target floor: says it will not read one as far as it parses" \
  "nor read it as far as it parses" "$OUT"
unset BAD_FLOOR

# ⛔ AND `ver_ge`'s OWN REFUSAL IS STILL REACHED FROM A GATE, which the A1 cases above no longer
# show: A1 now stops at the floor test, one step earlier. A6 is the caller that proves the wiring —
# it validates its FLOOR operand (`floor_min`, out of server/composer.json) and is handed
# `${HOST_PHP_VERSION:-0}` unvalidated, so a `php` that answers with something that is not a
# version reaches `ver_ge` and is refused there rather than compared.
mkfix php_version_not_a_version; export STUB_PHP_VERSION=banana
run_refusal "a host php answering with something that is not a version (A6, through ver_ge)" \
  "a version comparison this deploy cannot perform: 'banana' against" --dry-run
has "a non-version host PHP: names the operand at fault" "NOT A VERSION: the first, 'banana'" "$OUT"
hasnt "a non-version host PHP: no floor verdict it never reached" "does not satisfy" "$OUT"
no_shell_death "a non-version host PHP" "$OUT"
unset STUB_PHP_VERSION

no_deploy_sh() { rm -f "$1/bin/deploy.sh"; }
mkfix bash_target_no_deploy_sh no_deploy_sh
run_refusal "a release with no bin/deploy.sh for the re-exec to run" "bin/deploy.sh is missing from" --dry-run
# An EMPTY one is a different fact, and must not be read as the survivable "declares no floor".
empty_deploy_sh() { : > "$1/bin/deploy.sh"; }
mkfix bash_target_empty_deploy_sh empty_deploy_sh
run_refusal "a release whose bin/deploy.sh is empty" "bin/deploy.sh at" --dry-run
has "empty bin/deploy.sh: refused as empty, not as a release that predates the floor" \
  "is empty" "$OUT"
hasnt "empty bin/deploy.sh: not reported as a release that simply declares no floor" \
  "declares no BASH_FLOOR (it predates card#9616)" "$OUT"

# ── git — `:(literal)` pathspec magic, PROBED after A3 (design review r1, F9) ──────────────────
mkfix git_no_magic_128; printf '128' > "$T/knobs/git_magic"
run_refusal "a git that rejects :(literal) with an error" \
  "this host's git does not accept \`:(literal)\` pathspec magic" --dry-run
has "git without magic (128): says what the MAGIC form did, which is the one variable" \
  "\`git ls-tree HEAD -- ':(literal)VERSION'\` exited 128" "$OUT"
has "git without magic (128): git's own message reaches the operator" "Invalid pathspec magic" "$OUT"
hasnt "git without magic (128): not misreported as a failed read of the release" "git could not read" "$OUT"
hasnt "git without magic (128): refused before any gate read the release" "ok — PHP" "$OUT"

# ⭐ THE ASYMMETRIC ONE. A probe reading only the STATUS passes this, and every read of the release
# through _git_ls_at would then list NOTHING at status 0 — which downstream is "no such path", a real
# answer. That is why the probe requires the exact line.
mkfix git_no_magic_empty; printf 'empty' > "$T/knobs/git_magic"
run_refusal "a git that answers :(literal) with exit 0 and nothing listed" \
  "this host's git does not accept \`:(literal)\` pathspec magic" --dry-run
has "git without magic (empty): names the STATUS and the OUTPUT, because the status alone was 0" \
  "exited 0 and printed ''" "$OUT"
has "git without magic (empty): and says what it had to print" "must print exactly VERSION" "$OUT"

# The PLAIN form runs first, and that is what makes a failure of the magic form attributable to magic.
mkfix git_plain_unreadable; printf 'plain128' > "$T/knobs/git_magic"
run_refusal "a git that cannot list HEAD's tree at all" \
  "git could not list HEAD's tree in $ROOT (\`git ls-tree HEAD -- VERSION\` exited 128)" --dry-run
hasnt "plain form unreadable: not blamed on the pathspec magic, which was never reached" \
  "does not accept" "$OUT"

no_version_file() { rm -f "$1/VERSION"; }
mkfix git_probe_no_version '' no_version_file
run_refusal "a checkout whose HEAD carries no VERSION for the probe to stand on" \
  "does not list VERSION" --dry-run

# ── npm — the floor the TARGET release's lockfile implies (A12) ────────────────────────────────
mkfix npm_below_lockfile_floor; export STUB_NPM_VERSION=6.14.0
run_refusal "npm 6.14.0 against a lockfileVersion 3 release" \
  "npm 6.14.0 is below npm 7, which lockfileVersion 3 in server/package-lock.json at" --dry-run
unlogged "npm 6.14.0: npm ci never ran" "npm ci"
has "npm 6.14.0: says the failure was moved out of the window" "with the app already down" "$OUT"

mkfix npm_at_lockfile_floor; export STUB_NPM_VERSION=7.0.0
run --dry-run
eq  "control: npm 7.0.0 is EXACTLY lockfileVersion 3's floor, and deploys" 0 "$RC"
mkfix npm_above_lockfile_floor
run --dry-run
eq  "control: npm 9.2.0 deploys a lockfileVersion 3 release" 0 "$RC"
has "control: says which floor it held npm to, and where that floor came from" \
  "ok — npm 9.2.0 meets npm 7, which lockfileVersion 3 in server/package-lock.json at" "$OUT"
logged "control: the host's npm really was asked for its version" "npm --version"

# READ FROM THE TARGET TREE: the serving release's lockfile is v2, the release being deployed moves to
# v3. A gate reading the CHECKOUT's lockfile passes this — and `npm ci` then fails inside the window.
lockfile_v2() { printf '{\n    "name": "server",\n    "lockfileVersion": 2,\n    "requires": true\n}\n' > "$1/server/package-lock.json"; }
lockfile_v3() { printf '{\n    "name": "server",\n    "lockfileVersion": 3,\n    "requires": true\n}\n' > "$1/server/package-lock.json"; }
mkfix npm_target_raises_lockfile lockfile_v3 lockfile_v2; export STUB_NPM_VERSION=6.14.0
run_refusal "a release that moves its lockfile to v3, on npm 6.14.0" \
  "npm 6.14.0 is below npm 7, which lockfileVersion 3 in server/package-lock.json at" --dry-run
# …and the other way round: a v2 release, for which npm's docs state no floor, deploys on npm 6.
mkfix npm_target_lockfile_v2 lockfile_v2 lockfile_v2; export STUB_NPM_VERSION=6.14.0
run --dry-run
eq  "control: a lockfileVersion 2 release on npm 6.14.0 deploys (npm's docs: v2 is backwards compatible to v1)" 0 "$RC"
has "control: and says it compared NOTHING, rather than that npm met a floor" \
  "is lockfileVersion 2, for which npm's docs state no floor; npm 6.14.0 was not compared" "$OUT"

lockfile_unmapped() { printf '{\n    "name": "server",\n    "lockfileVersion": 4\n}\n' > "$1/server/package-lock.json"; }
mkfix npm_lockfile_unmapped lockfile_unmapped
run_refusal "a lockfileVersion this gate has no npm floor for" \
  "declares lockfileVersion '4', which A12 cannot map to an npm floor" --dry-run
lockfile_versionless() { printf '{\n    "name": "server"\n}\n' > "$1/server/package-lock.json"; }
mkfix npm_lockfile_versionless lockfile_versionless
run_refusal "a lockfile with no lockfileVersion at all" \
  "declares lockfileVersion '(none)', which A12 cannot map to an npm floor" --dry-run
# …and an EMPTY lockfile is a different fact from one whose version this gate does not know: the
# mapping refusal's remediation ("teach A12 the new lockfile version") is the wrong instruction for a
# file with nothing in it. A6b draws the same line about an empty bin/deploy.sh.
lockfile_empty() { : > "$1/server/package-lock.json"; }
mkfix npm_lockfile_empty lockfile_empty
run_refusal "an empty server/package-lock.json" "server/package-lock.json at" --dry-run
has "empty lockfile: refused as empty" "is empty" "$OUT"
hasnt "empty lockfile: not reported as a lockfileVersion the gate does not know" \
  "cannot map to an npm floor" "$OUT"
hasnt "empty lockfile: and not as a MISSING one, which it is not" "is missing from" "$OUT"

mkfix npm_version_fails; export STUB_NPM_VERSION_RC=1
run_refusal "an npm that cannot answer --version" "\`npm --version\` exited 1" --dry-run
has "npm --version failed: says what is NOT established, rather than assuming a version" \
  "is NOT established" "$OUT"
mkfix npm_version_garbage; export STUB_NPM_VERSION='not a version'
run_refusal "an npm whose --version is not a version" \
  "\`npm --version\` printed 'not a version', which is not a version" --dry-run

# ⛔ AND ONE IT COULD READ ONLY AS FAR AS IT PARSES — A1c's own copy of the shape this card
# refuses everywhere else, and the FOURTH copy of `[0-9]*.[0-9]*`, left behind when the other
# three were consolidated (card#9984 r4). `9.x.5` begins with a digit and has a digit after a
# dot, so the old glob passed it, and A12 then compared it as 9.0.5 — a number this host never
# reported. Restore that glob in place of A1c's `ver_is_comparable` call to watch these red.
# ⚠ THE VERDICT DOES NOT CHANGE at the floors A12 can produce today (its lockfileVersion case
# yields the bare major `7` or nothing, so only the first field decides). That is why this is the
# predicate's job and not a comment: nothing re-checks that luck, and the next floor with a minor
# would let the substitution decide the gate.
mkfix npm_version_partly_readable; export STUB_NPM_VERSION='9.x.5'
run_refusal "an npm --version this deploy can read only as far as it parses" \
  "\`npm --version\` printed '9.x.5', which is not a version" --dry-run
# ⚠ THE NEEDLE MUST LIVE ON ONE LINE: `refuse` prints each argument as its own indented line, so
# a phrase spanning two of them is never found. Measured — this assertion reded on exactly that.
has "a partly-readable npm version: says it will not compare one read only as far as it parses" \
  "read only as far as it parses" "$OUT"
hasnt "a partly-readable npm version: no npm floor verdict it never reached" "is below npm" "$OUT"
# The twin, one variable away: a PRERELEASE suffix is NOT this shape and must still deploy —
# `ver_ge` truncating it is documented and deliberate, and refusing it would strand a host that
# can install the lockfile perfectly well.
mkfix npm_version_prerelease; export STUB_NPM_VERSION='9.2.0-pre.1'
run --dry-run
eq  "control: an npm whose version carries a prerelease suffix deploys" 0 "$RC"
has "control: and it was compared as its release, not refused" \
  "ok — npm 9.2.0-pre.1 meets npm 7" "$OUT"

# ── the floor's DENOMINATOR: the other empty-array sites, each reached by a fixture ────────────
# ⛔ THE BACKSTOP HAD A DENOMINATOR OF ONE. The header of bin/deploy.sh declares the rule "a new
# construct stays at or below this floor, or the floor MOVES", and `deploy-selftest.yml`'s
# below-floor run is the only thing enforcing it. Until these cases the ONLY empty-array expansion
# any fixture reached was A7's `ref_note` — so guard that one site and the 4.3 control goes green,
# the job reports that the floor can be LOWERED, and a first deploy on an older host then meets
# `checkout_lock_holders` for real, in the window, with the app down. That is the exact class this
# card exists to move out of the window, reintroduced by its own backstop.
#
# ⚠ BOTH CASES ARE REAL SCENARIOS THIS SUITE WAS MISSING, not contrivances built for the tripwire —
# a FIRST deploy (D-08: the prod host has never been deployed to, so this is the one run that is
# certain to happen) and a `.env` with nothing in it. That is why each carries its own assertions
# about what the deploy DECIDES, and would be worth keeping with no floor in the picture at all.

# A server/.env with no lines at all — a real A5 case this suite did not have, and the one that
# RULED OUT a supposed third empty-array site rather than adding one. `ENV_LINES` looks like the
# clearest hazard of the three: env_get and env_file_scan both loop over it unguarded. It is not
# one. MEASURED (`env_lines_load` over a zero-byte file): `${#ENV_LINES[@]}` is 1, not 0, because
# the split is `<<<`, which appends a terminator — so a file with nothing in it is ONE empty line,
# and the array is only ever `()` on the UNREADABLE / READ_FAILED paths, which both loops test
# before they run. ⇒ This case passes on bash 4.3 as well as 4.4, deliberately and by measurement;
# it is not a floor tripwire and is not counted as one. What it does assert is that an empty file
# refuses on the RIGHT cause — unlike the card#9610 fixtures, where "APP_ENV is 'unset'" was a
# cause nothing had established, here it is exactly what the file says.
mkfix env_zero_lines; : > "$ROOT/server/.env"
eq "fixture: the .env really is zero bytes" 0 "$(wc -c < "$ROOT/server/.env")"
run_refusal "a server/.env with no lines at all" "APP_ENV is 'unset', not 'production'" --dry-run
no_shell_death "zero-line .env" "$OUT"

# ── the window runs the interpreter the GATES measured, not PATH's bash ───────────────────────
# ⛔ THE ONE PLACE THIS CARD CHANGES PRODUCTION BEHAVIOUR, so it gets a control rather than a claim.
# A1 and A6b read `BASH_VERSINFO` — THIS PROCESS's shell. The maintenance window used to be started
# by `exec "$DEPLOY_ROOT/bin/deploy.sh" …`, which runs the target's `#!/usr/bin/env bash`: PATH's
# shell, which the gates never read. So `somebash bin/deploy.sh` on a host whose PATH bash is older
# passed both floors and then died in the window, with the app down, on the interpreter neither gate
# had seen. phase_b_open_window now execs `"$BASH"`, and A13 reads the release's bin/supervision.sh
# under `"$BASH"` too.
# ⚠ AND `run` CANNOT SEE ANY OF THAT: it starts the deploy through the shebang, so the invoking
# shell and PATH's shell are one process and both spellings behave identically. This case is the
# only one in the file that starts the deploy through an EXPLICIT interpreter, which is what makes
# the difference observable at all — reverting either site reds it, and nothing else in the suite.
mkfix window_interpreter
: > "$T/knobs/bash_calls"          # turns the bash pass-through's recording on for this case only
start_old_daemons
run_via "$REAL_BASH"
eq  "explicit interpreter: the deploy runs to completion" 0 "$RC"
has "explicit interpreter: and really deployed" "✔ DEPLOYED" "$OUT"
# THE POSITIVE TWIN, first: without it the two `unlogged`-shaped assertions below would pass just as
# happily with the recorder switched off, or absent from PATH, having observed nothing at all.
neq "explicit interpreter: the bash pass-through really is on PATH and recording" "" \
  "$(cat "$T/knobs/bash_calls" 2>/dev/null)"
hasnt "explicit interpreter: the WINDOW was not started through PATH's bash (phase_b_open_window execs \$BASH)" \
  "--internal-post-checkout" "$(cat "$T/knobs/bash_calls")"
hasnt "explicit interpreter: nor was A13's read of the release's bin/supervision.sh" \
  "supervision_install_plan" "$(cat "$T/knobs/bash_calls")"
rm -f "$T/knobs/bash_calls"

# `${files[@]}` in checkout_lock_holders — a FIRST deploy, the D-08 scenario this suite had no case
# for at all: nothing is running, so no daemon lock file exists and the glob behind that array
# matches nothing. The deploy must still stop nothing, start everything, and say so.
mkfix first_deploy
eq "fixture: a first deploy really starts with no daemon lock file" "" \
  "$(compgen -G "$ROOT/server/storage/framework/daemon-*.lock" || true)"
run
eq  "first deploy: exit 0" 0 "$RC"
has "first deploy: reports success" "✔ DEPLOYED" "$OUT"
has "first deploy: says it stopped nothing, rather than reporting pids it never saw" \
  "stopped — pid(s) none were running" "$OUT"
no_shell_death "first deploy" "$OUT"
for c in "${SUPERVISED_DAEMONS[@]}"; do
  neq "first deploy: $c holds its lock afterwards" "" "$(fuser "$(supervision_lock "$ROOT" "$c")" 2>/dev/null | tr -d ' ')"
done

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
no_shell_death "full run" "$OUT"
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

section "WHOLE SECONDS READ FROM OUTSIDE — a leading zero is base 10; a non-number is refused before the window"
# bash arithmetic reads `08` as a bad octal and aborts the script — exit 1, no ERR trap, no banner, the window open —
# and `010` as 8. Base 10 is never the shorter wait PHP keeps (deploy.sh whole_seconds says why).
mkfix freq_leading_zero; export MEZZ_DAEMON_SETTLE_S=0
printf 'php_value[opcache.revalidate_freq] = 08\n' >> "$POOL"
run
eq  "freq 08: exit 0"                                         0 "$RC"
has "freq 08: read as 8 s"                                    "revalidates a changed file within 8 s" "$OUT"
eq  "freq 08: ≥ 8 + 1 s passed before up"                     "yes" "$([ "$(waited)" -ge 9 ] 2>/dev/null && echo yes || echo "no ($(waited))")"
# The floor as a serving release hands it over — one whose phase A passed on the digits it read, as bbba3cd's did.
hand_over_010() { sed -i 's/ MEZZ_DEPLOY_REVALIDATE_FLOOR_S="\$FPM_REVALIDATE_S"$/ MEZZ_DEPLOY_REVALIDATE_FLOOR_S=010/' "$1/bin/deploy.sh"; }
mkfix floor_leading_zero "" hand_over_010; export MEZZ_DAEMON_SETTLE_S=0
eq  "floor 010: the serving release really hands over 010"    1 "$(git -C "$SRC" show "$V1:bin/deploy.sh" | grep -c ' MEZZ_DEPLOY_REVALIDATE_FLOOR_S=010$')"
run
eq  "floor 010: exit 0"                                       0 "$RC"
has "floor 010: read as 10 s"                                 "(before the checkout: within 10 s)" "$OUT"
eq  "floor 010: ≥ 10 + 1 s passed before up"                  "yes" "$([ "$(waited)" -ge 11 ] 2>/dev/null && echo yes || echo "no ($(waited))")"
mkfix timeout_leading_zero; export MEZZ_DAEMON_STOP_TIMEOUT_S=08
run
eq  "stop timeout 08: exit 0"                                 0 "$RC"
mkfix timing_not_a_number; export MEZZ_DAEMON_STOP_TIMEOUT_S=3s
run_refusal "stop timeout 3s (A1b)" "MEZZ_DAEMON_STOP_TIMEOUT_S is '3s', not a whole number of seconds" --dry-run
export MEZZ_DAEMON_STOP_TIMEOUT_S=3 MEZZ_DAEMON_SETTLE_S=-1
run_refusal "settle -1 (A1b)" "MEZZ_DAEMON_SETTLE_S is '-1', not a whole number of seconds" --dry-run
export MEZZ_DAEMON_SETTLE_S=2; run --dry-run
eq  "control: the same host with both timings whole deploys"  0 "$RC"
# …and in the window, under a serving release that never checked: phase B reads them again before building anything.
cut_timing_check() { sed -i 's/^  daemon_timings || refuse "\$TIMING_NOT_READY"$/  : timing check cut out by the selftest mutant/' "$1/bin/deploy.sh"; }
mkfix timing_unchecked_by_serving "" cut_timing_check; export MEZZ_DAEMON_STOP_TIMEOUT_S=3s
eq  "unchecked 3s: the serving release really lacks the check" 1 "$(git -C "$SRC" show "$V1:bin/deploy.sh" | grep -c 'timing check cut out by the selftest mutant')"
run
eq  "unchecked 3s: exit 2"                                    2 "$RC"
has "unchecked 3s: names the value"                           "MEZZ_DAEMON_STOP_TIMEOUT_S is '3s', not a whole number of seconds" "$OUT"
has "unchecked 3s: the banner"                                "THE APP IS DOWN AND STAYS DOWN" "$OUT"
unlogged "unchecked 3s: nothing was built"                    "composer install"
unlogged "unchecked 3s: the app is NEVER brought up"          "artisan up"

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
# A holder whose start cannot be read cannot be proven fresh. An unmutated deploy under a ps that prints no age: the
# relaunched daemons hold their locks and are alive, so what fails the window is holders_started_after's EMPTY-age
# branch — without it `$((now - age))` reads the empty age as 0, a process started this very second, and the deploy
# goes green without proving anything.
mkfix daemon_blind_ps
: > "$T/knobs/blind_ps"
run
eq  "blind ps: exit 2"                                  2 "$RC"
has "blind ps: says the holder's start cannot be read"  "cannot read when pid" "$OUT"
hasnt "blind ps: no success line"                       "✔ DEPLOYED" "$OUT"
unlogged "blind ps: the app is NEVER brought up"        "artisan up"

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
no_shell_death "adds a daemon" "$OUT"
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
no_shell_death "recovery, the failed window" "$OUT"
for pid in $OLD_PIDS; do
  eq "recovery: the previous daemon pid $pid still runs after the failed window" "alive" "$(kill -0 "$pid" 2>/dev/null && echo alive || echo gone)"
done
rm -f "$ROOT/.deploy-failed"; STUB_FAIL_RE=''
run --redeploy
eq  "recovery: the re-run deploys (exit 0)"                   0 "$RC"
no_shell_death "recovery, the re-run" "$OUT"
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

mkfix smoke_unread_url; sed -i 's/^APP_URL=/export APP_URL=/' "$ROOT/server/.env"
run
eq  "APP_URL in a form env_get does not read: exit 0, as for an unset APP_URL" 0 "$RC"
has "APP_URL unread: says so, and that the deploy is unverified" "APP_URL is in a form this script does not read" "$OUT"
unlogged "APP_URL unread: no smoke request was made to a URL that was not read" "curl "

# card#9561 r2: the smoke check reads the value the app RECEIVES too. `APP_URL=null` is config('app.url')
# of null — no URL — so it takes the unset case rather than requesting `null/up` and then reporting that
# `null/up` answered 000. The control is every other case here, whose APP_URL is a URL and IS requested.
mkfix smoke_null_url; sed -i 's|^APP_URL=.*|APP_URL=null|' "$ROOT/server/.env"
run
eq  "APP_URL=null (Laravel: no URL at all): exit 0, as for an unset APP_URL" 0 "$RC"
has "APP_URL=null: reported as unset, and the deploy as unverified" "APP_URL is unset" "$OUT"
unlogged "APP_URL=null: no smoke request was made to a URL the app does not have" "curl "

# ── card#9610 — A .ENV THAT STOPS BEING READABLE MID-RUN, ON EITHER SIDE OF THE WINDOW ─────────
# The two cases the unit-level ones above cannot reach: a `.env` that was read once and is not readable
# when it is read AGAIN. A5 scans it before its first key, so every later reader in the same run is
# entitled to assume it was readable — and that assumption is exactly what makes "unset" the wrong answer
# when it stops being true. Both mutants make it stop being true, at the two places it matters.
#
# ⚠ THE FLAGS CANNOT CARRY THIS, WHICH IS WHY env_get ANSWERS WITH A STATUS. Both readers call env_get
# inside a `$(…)`, so ENV_LINES_UNREADABLE and ENV_LINES_READ_FAILED are set in a SUBSHELL that then exits:
# a fix keyed on either flag in the parent passes nothing here. H4 is the asymmetric one — it is the case
# that fix reds on.
#
# ⚠ WHAT IS NOT FIXTURED, stated rather than skipped: a READ (as against an OPEN) that fails on a real
# `server/.env` cannot be produced on either side of the window — it needs a filesystem that answers EIO on
# demand. `chmod 000` is what this runner can do, and it fails the OPEN. Both failures reach the same
# env_get status 3 by the same route, and the READ half of it is covered at unit level by the /proc/self/mem
# cases in § card#9610 above, which run the loader directly.
section "card#9610 — a .env read once and not readable the second time"

# H3 — PHASE A. The v1-mutator runs on the SERVING release's copy, which is the one phase A runs, and makes
# `.env` unopenable immediately after env_file_scan has certified it. Without a status of its own, A5's
# `env_read app_env APP_ENV || true` swallows the failure and refuses on `APP_ENV is 'unset'` — the exact
# wrong cause, on a file that is right there and correct.
unreadable_after_the_scan() {
  # shellcheck disable=SC2016,SC2317  # SC2016: the `$ENV_FILE` and the `$` anchor are sed's, written
  # LITERALLY into the release's own deploy.sh — expanding either here would write this suite's paths into
  # the mutant. SC2317: every mutator is called indirectly, by mkfix, which ShellCheck cannot follow.
  sed -i 's|^  env_file_scan$|  env_file_scan\n  chmod 000 "$ENV_FILE" # .env made unreadable by the selftest mutant, AFTER the scan|' "$1/bin/deploy.sh"
}
mkfix env_unreadable_after_scan "" unreadable_after_the_scan
eq "phase-A mutant: the serving release really drops .env's mode after the scan" 1 \
   "$(gitc "$SRC" show "$V1:bin/deploy.sh" | grep -c 'made unreadable by the selftest mutant')"
run_refusal "a .env that stops being readable after A5's scan" \
  "could not be read, so what Laravel reads for APP_ENV is not established" --dry-run
hasnt "stopped being readable: never reports it as a key the host does not set" "APP_ENV is 'unset'" "$OUT"
hasnt "stopped being readable: no DB password is printed" "$FAKE_PW" "$OUT"
eq "phase-A mutant: the fixture really is unopenable afterwards (a root runner cannot hold this)" \
   unopenable "$(env_openability "$ROOT/server/.env")"
chmod 640 "$ROOT/server/.env"

# H4 — PHASE B. The v2-mutator runs on the DEPLOYED release, which is the copy phase B re-execs into, and
# makes `.env` unopenable just before the smoke step reads APP_URL. ⛔ NO REFUSAL IS ALLOWED HERE: the
# window is closed and the new release is serving, so the promise a refusal makes would be false. The
# deploy is UNVERIFIED, says which reason it is, and exits 0.
unreadable_before_the_smoke() {
  # shellcheck disable=SC2016,SC2317  # sed's own text, called indirectly by mkfix — both for the same
  # reasons as the mutator above.
  sed -i 's|^  local url="" code url_rc=0$|  chmod 000 "$ENV_FILE" # .env made unreadable by the selftest mutant, after the window closed\n  local url="" code url_rc=0|' "$1/bin/deploy.sh"
}
mkfix env_unreadable_phase_b unreadable_before_the_smoke
eq "phase-B mutant: the deployed release really drops .env's mode before the smoke read" 1 \
   "$(gitc "$SRC" show "HEAD:bin/deploy.sh" | grep -c 'made unreadable by the selftest mutant')"
run
eq  "a .env unreadable in phase B: exit 0 — the app is up and this is not a refusal" 0 "$RC"
has "a .env unreadable in phase B: names the file, not the key" "server/.env could not be read" "$OUT"
has "a .env unreadable in phase B: says the deploy is UNVERIFIED" "UNVERIFIED" "$OUT"
has "a .env unreadable in phase B: points at the run that names the cause" "names the cause the way A5 does" "$OUT"
# ⭐ AND THE SPAN IT NAMES, which is the other half of the scratch case's `hasnt` on this same literal: with
# only that `hasnt`, renaming the phrase in bin/deploy.sh would leave a control passing because the literal
# exists NOWHERE. This `has` is what makes the pair discriminate two live messages. The span is what the
# code establishes and no more — this load runs AFTER `php artisan up`, so "inside the window" was over-
# specific (card#9933 round-4 review), while phase A's successful scan is a real lower bound.
has "a .env unreadable in phase B: names the span the code establishes, not the window" \
  "stopped being so between phase A and this check" "$OUT"
# ⚠ THE NEEDLE CARRIES `warn`'s OWN `⚠ ` PREFIX, deliberately. The correct warning QUOTES the wrong cause
# in order to deny it — "This is not 'APP_URL is unset'" — so a bare `APP_URL is unset` needle matches the
# RIGHT message and reds on the fix. What discriminates is the wrong branch's own rendering, which is the
# line `warn` emits and nothing else in this output produces. Measured: this case reds with the needle
# below against the previous bin/deploy.sh, where that branch is the one that fires.
hasnt "a .env unreadable in phase B: never reports APP_URL as a key the host does not set" \
      "⚠ APP_URL is unset" "$OUT"
unlogged "a .env unreadable in phase B: no smoke request was made on a URL nothing read" "curl "
hasnt "a .env unreadable in phase B: no DB password is printed" "$FAKE_PW" "$OUT"
eq "phase-B mutant: the fixture really is unopenable (a root runner cannot hold this)" \
   unopenable "$(env_openability "$ROOT/server/.env")"
chmod 640 "$ROOT/server/.env"

# H4b — THE SAME WINDOW, A READ THAT STOPS SHORT, AND THE DIAGNOSTIC PRINTED EXACTLY ONCE (card#9933).
# H4 makes the OPEN fail, which is silent; this makes the READ fail, which is not — and the COUNT is what
# this case is for. The smoke step loads in phase B's own shell so that it can read the loader's KIND, and
# then SKIPS the `$( env_get )` when that load says the file was not read: one load answers, and it is the
# load whose flags are reported. ⛔ THAT SKIP IS A CORRECTNESS MECHANISM, NOT AN OPTIMISATION — without it
# the two loads are separate events, the inner one re-attempts the scratch file this one failed to make,
# and an inner load that succeeds there and then stops short in the READ would print bash's read error
# with the scratch warning's "the read was never made" directly beneath it. That state needs two coincident
# faults and no fixture here can produce it; what a fixture CAN see is the second load running at all, and
# a second load over a file whose read fails prints the diagnostic TWICE. So the count below is the check
# that guards the skip: restore the unconditional `env_get` and it reds at 2.
# A DIRECTORY is the fixture because it OPENS and cannot be READ — § card#9610's H1b uses the same shape
# through the library — so this is also the only place the phase-B read half is driven end to end;
# `rm`+`mkdir` at that point leaves `.env` a directory for the rest of this fixture, which nothing after
# the smoke step reads.
env_directory_before_the_smoke() {
  # shellcheck disable=SC2016,SC2317  # sed's own text, called indirectly by mkfix — as the mutator above.
  sed -i 's|^  local url="" code url_rc=0$|  rm -f "$ENV_FILE"; mkdir -p "$ENV_FILE" # .env replaced by a DIRECTORY by the selftest mutant, after the window closed\n  local url="" code url_rc=0|' "$1/bin/deploy.sh"
}
mkfix env_directory_phase_b env_directory_before_the_smoke
eq "phase-B directory mutant: the deployed release really replaces .env before the smoke read" 1 \
   "$(gitc "$SRC" show "HEAD:bin/deploy.sh" | grep -c 'replaced by a DIRECTORY by the selftest mutant')"
run
eq  "a .env whose read stops short in phase B: exit 0 — the app is up and this is not a refusal" 0 "$RC"
eq  "a .env whose read stops short in phase B: the fixture really is a directory afterwards" \
  yes "$( [ -d "$ROOT/server/.env" ] && printf yes || printf no )"
has "a .env whose read stops short in phase B: bash's own errno reaches the operator" \
  "Is a directory" "$OUT"
has "a .env whose read stops short in phase B: says the deploy is UNVERIFIED" "UNVERIFIED" "$OUT"
has "a .env whose read stops short in phase B: takes the READ's warning, not the scratch one" \
  "its open or its read failed" "$OUT"
hasnt "a .env whose read stops short in phase B: no DB password is printed" "$FAKE_PW" "$OUT"
# ⭐ THE COUNT IS THE ASSERTION, and it is the only one that reds on the skip's removal — the warning's
# TEXT is the same either way, which is why an assertion about the text would not catch it. Mutation, run:
# call `env_get` unconditionally instead of skipping it when the load says the file was not read, and this
# reds at 2 while every other assertion in this case and in the scratch case stays green.
eq "a .env whose read stops short in phase B: bash's diagnostic is printed ONCE, by the one load that ran" \
  1 "$(printf '%s\n' "$OUT" | grep -c 'read error')"

# ── card#9816 — A SCRATCH FILE PHASE A COULD NOT CREATE IS A REFUSAL, AND NAMES THE SCRATCH FILE ──
# A bare `x="$(mktemp)"` failed two ways, measured on the previous bin/deploy.sh with each case below:
# A13's ended phase A with mktemp's status 1 — the code the exit table says MEANS refused — and no ⛔ banner
# and no promise; A7's two sat inside calls made from an `if` or a `||`, where `set -e` does not apply, so
# the run carried on with an empty path and REFUSED ON A FALSE CAUSE — "'main' does not resolve to a commit
# on origin" for a ref that is there, and a failed git read for a tag git never peeled. Every migrated site
# now goes through scratch_file / scratch_dir, whose failure is not_established's, and each has a case here,
# because a fix at the shared exit is only shown to reach the callers a case reaches. ⚠ The exit code
# carries none of it — both wrong shapes exit 1 — and A7's wrong shape even carries the banner and the
# promise, so the HEADLINE is what reds on it.
#
# ⛔ EACH CASE PROVES IT REACHED mktemp — a case that never made mktemp fail would pass on any code — but
# NOT all by the same instrument: the scratch_refused cases below prove it by mktemp's OWN error naming
# the broken directory, and S0, where the loader silences that error, by the loader's own reason, which
# it sets only when mktemp failed (the case says so at the call). A TMPDIR that is set and not EXPORTED
# never reaches mktemp at all — the § card#9610 cases measured that.
section "card#9816 — a scratch file phase A could not create is a REFUSAL that names it"
MKTEMP_FAILED="mktemp: failed to create"

# S0 — the whole run with TMPDIR gone from the start. The .env loader makes phase A's FIRST scratch file
# (A5, before any git read), and it answers for that failure itself — so this is where such a run stops,
# refused, with the scratch file named as the reason and no git read blamed. The three sites the loader
# does not own are reached by the cases after it, which let the loader's file through first.
mkfix scratch_tmpdir_gone
# The loader silences mktemp's stderr, so what proves the fixture reached mktemp here is the loader's own
# reason, which it sets only when mktemp failed.
TMPDIR="$T/no-such-dir" run --dry-run
has "TMPDIR gone: the fixture reaches mktemp, and names the scratch file as the reason" \
  "No scratch file could be created for bash's read diagnostic" "$OUT"
eq  "TMPDIR gone: exit 1" 1 "$RC"
has "TMPDIR gone: the ⛔ REFUSED banner, so the 1 is a verdict and not a death" "⛔ REFUSED — " "$OUT"
has "TMPDIR gone: the phase-A promise" "Nothing was changed. The previous release is still serving." "$OUT"
# ⭐ AND THE HEADLINE, which the three above cannot see (card#9933). This is the operator's own sequence —
# a whole `bin/deploy.sh` run on a host whose TMPDIR is not there — so it is the one case that says what
# such a host is actually TOLD. It asserted the banner and the reason and not the headline, and the
# § card#9610 case beside it asserted the READ's headline for this same failure, so a refusal blaming a
# read that never happened was pinned by a green suite.
has "TMPDIR gone: the headline names the scratch file, not a read of .env that was never made" \
  "⛔ REFUSED — $ROOT/server/.env could not be read: no usable scratch file for bash's read diagnostic" "$OUT"
hasnt "TMPDIR gone: never the READ's headline" "was opened but could not be read to its end" "$OUT"
hasnt "TMPDIR gone: does not send the operator to \`dmesg\` and the mount for a scratch file" "dmesg" "$OUT"
# ⭐ AND THE NUMBER THE ADVICE NAMES, which nothing asserted until now — the round-4 review found both
# scratch-advice strings unpinned, so an edit putting free SPACE back would have reded nothing. Which
# number it is was MEASURED (card#9933 round-4 review): out of inodes fails `mktemp` and reaches this
# refusal; 100% blocks with inodes free does NOT reach it, which is why the denial below it is here.
has "TMPDIR gone: the advice names free INODES as the number that decides a failed \`mktemp\`" \
  "free INODES (\`df -i\`)" "$OUT"
has "TMPDIR gone: and denies the number that does not — a \`df\` at 100%" \
  "A \`df\` at 100% is not on its own the finding here" "$OUT"
hasnt "TMPDIR gone: blames no git read" "⛔ REFUSED — git" "$OUT"
unlogged "TMPDIR gone: never opened the window" "artisan down"

# scratch_refused <label> <passes> <headline needle> <args…> — the first <passes> mktemp calls succeed and
# the next fails; the refusal must be the migrated site's, by its headline.
scratch_refused() {
  local label="$1" passes="$2" needle="$3"; shift 3
  printf '%s\n' "$passes" > "$T/knobs/mktemp_passes"
  run "$@"
  has "$label: the fixture reaches mktemp and it fails — mktemp's own error is on screen" \
    "$MKTEMP_FAILED" "$OUT"
  has "$label: and names the directory it tried" "$T/no-such-dir/" "$OUT"
  eq  "$label: exit 1" 1 "$RC"
  has "$label: the ⛔ REFUSED banner, so the 1 is a verdict and not a death" "⛔ REFUSED — " "$OUT"
  has "$label: the phase-A promise" "Nothing was changed. The previous release is still serving." "$OUT"
  has "$label: the headline names the scratch file" "⛔ REFUSED — $needle" "$OUT"
  hasnt "$label: blames no git read" "⛔ REFUSED — git" "$OUT"
  hasnt "$label: the .env loader's own scratch file was let through" \
    "No scratch file could be created for bash's read diagnostic" "$OUT"
  has "$label: the advice names free INODES, the number a failed \`mktemp\` turns on" \
    "free INODES (\`df -i\`)" "$OUT"
  unlogged "$label: never opened the window" "artisan down"
  rm -f "$T/knobs/mktemp_passes"
}

# THE CONTROL, one variable away: the same fixture with every mktemp call let through deploys — so the
# pass-through is not itself what refuses the cases below.
mkfix scratch_sites
printf '99\n' > "$T/knobs/mktemp_passes"
run --dry-run
eq "the control: every scratch file created, the same fixture passes" 0 "$RC"
rm -f "$T/knobs/mktemp_passes"

# S1 — git_ref_oid's stderr file: A7's first candidate, the call after the loader's.
scratch_refused "A7, git_ref_oid" 1 \
  "no scratch file could be created for git's error output while resolving 'refs/remotes/origin/main'" \
  --dry-run
# ⭐ THE FILE KIND CARRIES THE DENIAL, and the directory case below does not — the two advice paths are
# asserted against each other here, because the denial is measured for an EMPTY FILE (an inode, no block)
# and a directory can cost a block as well. Mutation, run: collapse the two paths back into one and the
# pair below reds, whichever way it is collapsed.
has "A7, git_ref_oid: a scratch FILE's advice denies a \`df\` at 100%" \
  "mktemp creates an EMPTY file, and a filesystem out of BLOCKS can still give it one" "$OUT"

# S2 — A13's work directory: after the loader's, A7's and A8's (both git_ref_oid on refs/remotes/origin/main).
scratch_refused "A13, the crontab block's work directory" 3 \
  "no scratch directory could be created for A13's reading of $(gitc "$ROOT" rev-parse --short "$V2")'s crontab block" \
  --dry-run
has "A13's work DIRECTORY: its advice rules out neither number, because a directory can cost a block" \
  "free INODES (\`df -i\`) AND free BLOCKS (\`df\`)" "$OUT"
hasnt "A13's work DIRECTORY: and it does not tell that operator to discount a \`df\` at 100%" \
  "A \`df\` at 100% is not on its own" "$OUT"

# S3 — git_commit_of's peel of an ANNOTATED tag, which is the only path to that file: after the loader's,
# git_ref_oid on refs/remotes/origin/<tag> (absent) and on refs/tags/<tag> (the tag object).
gitc "$SRC" tag -a v0.0.2 -m 'selftest: an annotated tag over the release' "$V2"
gitc "$SRC" push -q origin v0.0.2
eq "fixture: v0.0.2 is an ANNOTATED tag, so resolving it peels" tag "$(gitc "$SRC" cat-file -t v0.0.2)"
run --dry-run --ref v0.0.2
eq "the control: the same tag, every scratch file created, deploys" 0 "$RC"
scratch_refused "A7, git_commit_of's tag peel" 3 \
  "no scratch file could be created for git's error output while peeling the tag 'refs/tags/v0.0.2'" \
  --dry-run --ref v0.0.2

# ── card#9932 — A SCRATCH FILE ON A FILESYSTEM OUT OF BLOCKS: `mktemp` SUCCEEDS AND THE WRITE IS LOST ──
# Every case above reaches the scratch helpers through a `mktemp` that FAILS. On a filesystem out of free
# BLOCKS it does not: an empty file costs no block, so `mktemp` answers 0 with a real path, and what fails
# is the first byte written into it — git's stderr, or bash's read diagnostic — and every reader here takes
# an EMPTY diagnostic as "the tool printed nothing". Measured (card#9932 comment 6016) and re-measured by
# the fixture probe below on every run: `mktemp` 0, the write ENOSPC.
#
# ⛔ THE FILESYSTEM IS REALLY FULL. `run_full` mounts a small tmpfs in a user and mount namespace of its
# own (`unshare -Ur -m`, no root) and runs the deploy inside it, mapped back to this user's own uid by a
# second, nested user namespace — so ownership and mode on the fixtures these cases touch, all of them
# this user's own, read the same as outside. The mapping is single-user: a file owned by root or any
# other uid would read as the overflow uid inside it, which no fixture here has occasion to be.
# The mktemp pass-through fills that tmpfs with `dd` just before the Nth call (§ the stub), so the scratch
# files made before it are made on a filesystem with room, exactly as on a host that fills mid-run. Inodes
# are left free on purpose: out of INODES is the `mktemp`-FAILS case the § card#9816 cases already cover.
# ⛔ THE HEADLINE IS THE ASSERTION (card#9646). A trust-`mktemp` primitive refuses these on a DIFFERENT
# headline or deploys — each case below names what it reds on, and that mutation was run against it.
section "card#9932 — a scratch file on a filesystem out of BLOCKS is refused as unwritable, never read as silence"
FULL_TMP="$T/full-tmp"; mkdir -p "$FULL_TMP"
MY_UID="$(/usr/bin/id -u)"; MY_GID="$(/usr/bin/id -g)"

# in_full_ns <command…> — <command> in a mount namespace where $FULL_TMP is a private 256 KiB tmpfs,
# as this user, with TMPDIR exported to it. Its exit status is the command's; 97 is the mount failing.
in_full_ns() {
  # shellcheck disable=SC2016  # expanded by the bash it is handed to
  unshare -Ur -m "$REAL_BASH" -c '
      mount -t tmpfs -o size=256k tmpfs "$1" || exit 97
      exec unshare -U --map-user="$2" --map-group="$3" env TMPDIR="$1" "${@:4}"
    ' full "$FULL_TMP" "$MY_UID" "$MY_GID" "$@"
}
# run_full <mktemp calls that pass before the fill> <args…> — `run`, inside that namespace.
run_full() {
  local after="$1"; shift
  : > "$CALL_LOG"
  printf '%s\n' "$after" > "$T/knobs/mktemp_fill_after"
  OUT="$(MEZZ_DEPLOY_ROOT="$ROOT" in_full_ns "$ROOT/bin/deploy.sh" "$@" 2>&1)"
  RC=$?
  rm -f "$T/knobs/mktemp_fill_after"
}

# THE FIXTURE, MEASURED BEFORE IT IS TRUSTED: inside the namespace, filled from the first call, `mktemp`
# must SUCCEED and a byte written into what it made must FAIL — and it must be the private mount that
# filled, never this runner's own disk. A runner that cannot make a user namespace (a kernel or an
# AppArmor policy that forbids unprivileged ones) is NAMED, never counted as a pass (card#9646).
: > "$CALL_LOG"
printf '0\n' > "$T/knobs/mktemp_fill_after"
# shellcheck disable=SC2016
full_probe="$(in_full_ns "$REAL_BASH" -c '
    f="$(mktemp)" && printf "mktemp-ok"
    { printf x > "$f"; } 2>/dev/null || printf " write-failed"
    printf " ifree=%s" "$(df --output=iavail "$TMPDIR" | tail -1 | tr -d " ")"' 2>&1)"
rm -f "$T/knobs/mktemp_fill_after"
FULL_OK=0
case "$full_probe" in
  "mktemp-ok write-failed ifree="[1-9]*) grep -q '^mktemp FILLED ' "$CALL_LOG" && FULL_OK=1 ;;
esac
if [ "$FULL_OK" = 1 ]; then
  ok "fixture: a block-full tmpfs in a user namespace — mktemp succeeds, the write fails, inodes are free ($full_probe)"
  cases=$((cases+1))
else
  notverified "card#9932: a filesystem out of BLOCKS could not be produced on this runner, so its cases did not run" \
    "It needs \`unshare -Ur -m\` (an unprivileged user + mount namespace) and \`unshare --map-user\`." \
    "This runner answered: ${full_probe:-nothing}" \
    "Not run: the whole deploy on a full TMPDIR (A5's loader), A7's git_ref_oid on a healthy and on a broken" \
    "store, A13's work directory, git_commit_of's tag peel — every case in this section but the \`cat\` one."
fi

if [ "$FULL_OK" = 1 ]; then
  # THE CONTROL, one variable away: the same namespace and mount, with no fill — the deploy passes, so
  # the namespace is not itself what refuses the cases below.
  mkfix scratch_full_sites
  run_full 99 --dry-run
  eq  "full-TMPDIR control: the same namespace with room on the tmpfs deploys" 0 "$RC"
  unlogged "full-TMPDIR control: nothing was filled" "mktemp FILLED"

  # full_refused <label> <passes> <headline needle> <args…> — the refusal every case here shares.
  full_refused() {
    local label="$1" passes="$2" needle="$3"; shift 3
    run_full "$passes" "$@"
    logged "$label: the tmpfs was filled before the call under test" "mktemp FILLED"
    eq  "$label: exit 1" 1 "$RC"
    has "$label: the ⛔ REFUSED banner, so the 1 is a verdict and not a death" "⛔ REFUSED — " "$OUT"
    has "$label: the phase-A promise" "Nothing was changed. The previous release is still serving." "$OUT"
    has "$label: the headline names the scratch space the run could not write" "⛔ REFUSED — $needle" "$OUT"
    hasnt "$label: never a ref that does not resolve — no read of the refs was lost into an empty file" \
      "does not resolve to a commit on" "$OUT"
    unlogged "$label: never opened the window" "artisan down"
    no_shell_death "$label" "$OUT"
  }

  # F0 — the operator's own sequence: a whole `bin/deploy.sh --dry-run` on a host whose TMPDIR is out of
  # blocks from the start. The .env loader makes phase A's first scratch file, so this is where it stops —
  # on its OWN probe (env_read_err_open's status 3), since the loader does not use `_scratch`.
  # Mutation, run: drop the probe from env_read_err_open and this refuses at A7's scratch file instead —
  # exit 1 and the ⛔ banner stay green (git_ref_oid's `_scratch` probe still catches the same full tmpfs
  # one step later), and the loader's own headline and reason red: 3 of this case's assertions.
  mkfix scratch_full_loader
  full_refused "full TMPDIR, the .env loader" 0 \
    "$ROOT/server/.env could not be read: no usable scratch file for bash's read diagnostic" --dry-run
  has "full TMPDIR, the .env loader: the reason is the WRITE that failed, not mktemp and not the open" \
    "WAS created and opened, and a byte written into it did not read back" "$OUT"
  has "full TMPDIR, the .env loader: and the number it names is free BLOCKS" "out of free BLOCKS: read \`df\` on it" "$OUT"
  hasnt "full TMPDIR, the .env loader: does not blame mktemp, which succeeded" "\`mktemp\` failed" "$OUT"
  hasnt "full TMPDIR, the .env loader: does not send the operator to the open-file limit" "ulimit -n" "$OUT"
  hasnt "full TMPDIR, the .env loader: never the READ's headline" "was opened but could not be read to its end" "$OUT"
  hasnt "full TMPDIR, the .env loader: no DB password is printed" "$FAKE_PW" "$OUT"

  # F1 — git_ref_oid's stderr file: A7's first candidate, the call after the loader's.
  # Mutation, run: restore the trust-`mktemp`-only `_scratch` and this reds on the headline — the ref
  # resolves on a healthy store, and the run goes on to REFUSE at A13's work directory instead, banner
  # and all, through this round's write-status check (§ SF-5) rather than the directory's own probe.
  mkfix scratch_full_ref_oid
  full_refused "full TMPDIR, A7's git_ref_oid" 1 \
    "the scratch file for git's error output while resolving 'refs/remotes/origin/main' was created and could not be written" \
    --dry-run
  has "full TMPDIR, A7's git_ref_oid: bash's own write error names the errno" "No space left on device" "$OUT"
  has "full TMPDIR, A7's git_ref_oid: and the advice is free BLOCKS" "out of free BLOCKS — read \`df\` on it" "$OUT"
  hasnt "full TMPDIR, A7's git_ref_oid: the loader's scratch file was let through" \
    "no usable scratch file for bash's read diagnostic" "$OUT"

  # F1b — ⭐ THE CARD'S OWN HARM, reproduced: a rev whose walk git reports FAILING (a blinded object, as
  # in § card#9611 r2) on a full TMPDIR. git's `unable to open loose object` is lost into the empty file,
  # and a trust-`mktemp` primitive reads that silence as absence — measured with the mutation: "⛔ REFUSED
  # — 'main~2' does not resolve to a commit on origin", which `full_refused` asserts absent.
  three_releases scratch_full_broken_store
  blind_object "$V2"
  full_refused "full TMPDIR over a broken store, --ref main~2" 1 \
    "the scratch file for git's error output while resolving 'refs/remotes/origin/main~2' was created and could not be written" \
    --dry-run --ref 'main~2'

  # F2 — A13's work DIRECTORY: after the loader's, A7's and A8's. On a tmpfs `mktemp -d` SUCCEEDS out of
  # blocks too (measured here), so this is the probe's refusal, through a file made inside the directory.
  # Mutation, run: restore the trust-`mktemp`-only `_scratch` and this reds on the headline too — the
  # directory's own probe no longer catches it, but A13's write-status check (§ SF-5) still does, banner
  # and all: the two are independent, and this mutation only removes one of them.
  mkfix scratch_full_a13
  full_refused "full TMPDIR, A13's work directory" 3 \
    "the scratch directory for A13's reading of $(gitc "$ROOT" rev-parse --short "$V2")'s crontab block was created and could not be written" \
    --dry-run

  # F3 — git_commit_of's peel of an ANNOTATED tag: after the loader's and git_ref_oid's two.
  # Mutation, run: restore the trust-`mktemp`-only `_scratch` and this reds on the headline — the tag
  # peels clean on a healthy store (`git_commit_of` runs before A13 in the gate order), and the run
  # goes on to REFUSE at A13's work directory instead, banner and all, same as F1 and F2 under this
  # mutation and for the same reason (§ SF-5).
  mkfix scratch_full_tag
  gitc "$SRC" tag -a v0.0.2 -m 'selftest: an annotated tag over the release' "$V2"
  gitc "$SRC" push -q origin v0.0.2
  run_full 99 --dry-run --ref v0.0.2
  eq "full-TMPDIR control: the same tag with room on the tmpfs deploys" 0 "$RC"
  full_refused "full TMPDIR, git_commit_of's tag peel" 3 \
    "the scratch file for git's error output while peeling the tag 'refs/tags/v0.0.2' ($(gitc "$SRC" rev-parse v0.0.2)) was created and could not be written" \
    --dry-run --ref v0.0.2
fi

# C1 — THE `cat` THAT READS git's STDERR BACK, with its status read (card#9932). The probe above proves a
# scratch file can be WRITTEN; this is the other half — one that was written and then cannot be READ.
# The git pass-through makes git's stderr file mode 000 the moment the real git has written it, over the
# broken store F1b uses, so the message that says WHICH answer this was exists and cannot be read.
# Mutation, run: drop `|| __ro_cat=$?` and the refusal is "'main~2' does not resolve to a commit on
# origin" — git's `unable to open loose object`, unread, taken for git's silence.
if [ "$(/usr/bin/id -u)" = 0 ]; then
  notverified "card#9932: an unreadable git stderr file cannot be produced by a ROOT runner" \
    "root opens a mode-000 file, so \`cat\` succeeds and the case would certify nothing."
else
  three_releases scratch_stderr_unreadable
  blind_object "$V2"
  : > "$T/knobs/git_stderr_unreadable"
  run_refusal "git's stderr unreadable at \`cat\`, over a broken store" \
    "⛔ REFUSED — git's error output could not be read back while trying to resolve 'refs/remotes/origin/main~2' (\`git rev-parse --verify\` exited 1, \`cat\` exited 1)" \
    --dry-run --ref 'main~2'
  rm -f "$T/knobs/git_stderr_unreadable"
  has "git's stderr unreadable: cat's own error is on screen and names the file" "Permission denied" "$OUT"
  hasnt "git's stderr unreadable: never a ref that does not resolve — git's message was not read, not absent" \
    "does not resolve to a commit on" "$OUT"
  hasnt "git's stderr unreadable: claims no read of the store that failed — that is what could not be read" \
    "git could not resolve 'refs/remotes/origin/main~2'" "$OUT"
fi

# C1b — C1's TWIN, on git_commit_of's OWN cat-status branch rather than git_ref_oid's (card#9932 review,
# SF-2): C1 never reaches git_commit_of's peel — it refuses inside git_ref_oid first, over a candidate
# that does not resolve at all. This fixture resolves the CANDIDATE cleanly (an annotated tag over a
# TREE — the healthy-store peel-mismatch fixture above, § ⛔ THE ANNOTATED TAG) and makes only the PEEL's
# own `rev-parse --verify` — the third call this run makes, after the two candidate resolutions the loop
# tries first — come back unreadable. The knob's skip count is what steers past the first two.
# Mutation, run: drop `|| __cat=$?` from git_commit_of's capture of the peel's stderr, so `$__cat`
# stays 0 as if `cat` had succeeded, and this case's own headline reds: the unreadable diagnostic is
# no longer told apart from a read one. (The guard's `[ "$__cat" -eq 0 ]` clause is not what this
# case controls: here `$__rc` is already non-zero, so that clause cannot change the outcome. It is
# exercised by the healthy-store `tree tag` fixture, card#9611, whose `cat` succeeds.)
if [ "$(/usr/bin/id -u)" = 0 ]; then
  notverified "card#9932: an unreadable git stderr file cannot be produced by a ROOT runner" \
    "root opens a mode-000 file, so \`cat\` succeeds and the case would certify nothing."
else
  three_releases scratch_stderr_unreadable_tag
  gitc "$SRC" tag -a treeonly -m 'a tag whose object is a TREE, not a commit' "$V2^{tree}"
  gitc "$SRC" push -q origin treeonly
  gitc "$ROOT" fetch -q --tags origin
  TAG_OID="$(gitc "$ROOT" rev-parse treeonly)"
  printf '2\n' > "$T/knobs/git_stderr_unreadable"
  run_refusal "git's stderr unreadable at \`cat\`, on git_commit_of's own tag peel" \
    "⛔ REFUSED — git's error output could not be read back while trying to resolve the tag 'refs/tags/treeonly' ($TAG_OID) to a commit (\`git rev-parse --verify\` exited 128, \`cat\` exited 1)" \
    --dry-run --ref treeonly
  rm -f "$T/knobs/git_stderr_unreadable"
  has "git_commit_of's stderr unreadable: cat's own error is on screen and names the file" "Permission denied" "$OUT"
  hasnt "git_commit_of's stderr unreadable: never the peel-mismatch text — git's message was not read, not absent" \
    "dereferences to tree type" "$OUT"
  hasnt "git_commit_of's stderr unreadable: never a ref that does not resolve — git's message was not read, not absent" \
    "does not resolve to a commit on" "$OUT"
fi

# ── card#9933 — THE LOADER'S SCRATCH FILE FAILS IN PHASE B, WHERE THERE IS NO REFUSAL TO MAKE ──
# Every case above stops in phase A. This one cannot: phase B is a RE-EXEC, so it is a new process with a
# new descriptor, and the first load it makes is the smoke check's — AFTER `php artisan up`, with the new
# release already serving. There is no refusal available there and none is wanted; what is owed is a
# warning that names what actually failed.
#
# ⛔ WHAT THIS CAUGHT, MEASURED on the tree that had card#9933's A5 half and not this one: the deploy
# FINISHED — exit 0, `✔ DEPLOYED`, the marker removed — and its one warning said that the open or the read
# of `server/.env` had failed, that the file had stopped being readable inside the window — the wording it
# carried then; it now names the span between phase A and the check — and that bash's reason
# was above. Not one of those was true: no read was made, the file was readable throughout, and no
# such diagnostic exists — and the scratch file in question is made AFTER `php artisan up`, so even the
# window was the wrong place to look. The remedy it offered — re-run `--dry-run`, which names the cause at
# A5 — only works while the cause is still there, so a $TMPDIR that was missing, unwritable or out of
# inodes during the deploy and was put right afterwards left a clean dry run and nothing at all.
# ⚠ A $TMPDIR OUT OF BLOCKS reaches this warning by a third route, not by a failing `mktemp`: `mktemp`
# creates an EMPTY file, so a filesystem with no space left can still give it one (card#9933 review round
# 3: mktemp rc 0, the `<>` open rc 0), and it is the loader's write probe that fails there — status 3 of
# env_read_err_open (card#9932), whose REASON is asserted on a real full tmpfs in § card#9932 (at A5).
# INODE exhaustion is the "full" that makes `mktemp` fail; the knob above reaches that by making `mktemp`
# fail instead.
# The value that fixes it is the KIND the loader now carries, and phase B can only read it because the
# load is made in its own shell (§ the smoke check).
#
# ⛔ THE PASS COUNT IS DERIVED, NEVER TYPED. The stub logs every mktemp call while the knob is set, so the
# control below COUNTS what a whole deploy makes and this case then fails the LAST one — which is phase
# B's loader, the only scratch file made after the window closes. A typed number silently starts failing a
# different call the first time a scratch file is added or removed anywhere in phase A, and would then
# pass while testing something else; the assertions below red instead, because a phase-A failure refuses
# (no exit 0, no `✔ DEPLOYED`) and this warning never appears.
section "card#9933 — the loader's scratch file fails in phase B, AFTER the maintenance window closes"
mkfix scratch_phase_b_control
printf '999\n' > "$T/knobs/mktemp_passes"
run
eq "the control: every scratch file created, the whole deploy — window and all — finishes" 0 "$RC"
scratch_calls="$(grep -c '^mktemp ' "$CALL_LOG" || true)"
rm -f "$T/knobs/mktemp_passes"
# The count is ASSERTED usable before it is used: a zero or a one would mean the stub logged nothing (the
# knob missing, the log reset) and `$((n - 1))` would then fail a call that is not the loader's, or none.
eq "the control: a whole deploy makes more than one scratch file, so the last of them can be failed" \
  yes "$( [ "${scratch_calls:-0}" -ge 2 ] && printf yes || printf no )"

mkfix scratch_phase_b
printf '%s\n' "$((scratch_calls - 1))" > "$T/knobs/mktemp_passes"
run
rm -f "$T/knobs/mktemp_passes"
eq  "no scratch file in phase B: the deploy FINISHES — exit 0, because the window is closed" 0 "$RC"
has "no scratch file in phase B: and it says the release is deployed" "DEPLOYED" "$OUT"
hasnt "no scratch file in phase B: no phase-A refusal — the failure is past every one of them" \
  "⛔ REFUSED — " "$OUT"
has "no scratch file in phase B: the deploy is UNVERIFIED, by name" "The deploy is UNVERIFIED" "$OUT"
# ⭐ EVERY CLAIM THE OLD WARNING MADE, each asserted absent, beside the one it should have made.
# Mutation, run against the fix: drop the `scratch` arm of the status-3 branch so it falls to the warning
# below it, and these red while the exit code, `DEPLOYED` and `UNVERIFIED` stay green.
has "no scratch file in phase B: names the scratch file as what failed" \
  "no usable scratch file could be had for bash's read diagnostic" "$OUT"
has "no scratch file in phase B: says server/.env may be perfectly readable" \
  "may be perfectly readable" "$OUT"
has "no scratch file in phase B: says the release IS serving, so the gap is the CHECK" \
  "The release IS deployed and serving" "$OUT"
hasnt "no scratch file in phase B: does not say the file's open or its read failed" \
  "its open or its read failed" "$OUT"
hasnt "no scratch file in phase B: does not say the file stopped being readable between phase A and here" \
  "stopped being so between phase A and this check" "$OUT"
hasnt "no scratch file in phase B: points at no bash diagnostic, which cannot exist on this path" \
  "bash's reason, if any, is above" "$OUT"
# ⚠ THE NEEDLE CARRIES `warn`'s OWN `⚠ ` PREFIX, for the reason H4 states above: the correct warning
# QUOTES the wrong cause in order to deny it, so a bare needle matches the RIGHT message. Measured here,
# not assumed — with the bare needle this case FAILED against the fix, on its own denial.
hasnt "no scratch file in phase B: reports no key as 'unset' on a file nothing read" \
  "⚠ APP_URL is unset" "$OUT"
hasnt "no scratch file in phase B: no DB password is printed" "$FAKE_PW" "$OUT"
# THE PROOF IT WAS THE FILE THAT WENT UNREAD AND NOT THE FILE THAT WENT BAD, through a reader that is not
# the one under test: the same .env, read with grep, still names APP_URL after the deploy that said it
# could not be read.
eq "no scratch file in phase B: server/.env was readable the whole time (read here with grep)" \
  1 "$(grep -c '^APP_URL=' "$ROOT/server/.env")"

# ── card#9832 — MEZZ_REMOTE IS A REMOTE NAME, AND ONE THAT IS NOT IS REFUSED WITHOUT BEING PRINTED ──
# `git fetch` takes a URL as readily as a name and a URL can carry a credential, so
# `MEZZ_REMOTE=https://user:token@host/org/repo` is a configuration git accepts — and every mention
# of `$REMOTE` then printed it: A7's step line before anything could fail, and A7's and A8's
# refusals. MEASURED against the tree before the gate, with the fixture below: the whole URL —
# the fake credential inside it — on screen five times over in one refused run, in A7's step line
# and in four lines of the fetch refusal that followed it. Git's own redaction is no
# backstop — it is per-transport (git 2.53.0: https strips the credential, `git://` does not) and
# the message is on screen before the deploy sees it. A3c refuses a value that is not among
# `git remote`'s names, in phase A, before the first line that could carry it.
#
# ⛔ THE ABSENCE ASSERTIONS ARE THE POINT OF THIS SECTION, AND AN ABSENCE PASSES FOR FREE. A `hasnt`
# is satisfied by a misspelled needle, by an output the needle could never have appeared in, and by
# a run that printed nothing at all. So the needle is observed PRESENT one variable away, on the
# same fixture — see the twin below. Without it the credential assertions here would be evidence of
# nothing.
#
# ⭐ AND THE MUTANT THIS SECTION EXISTS TO CATCH is the pattern match — `case $REMOTE in *://*|*@*)`
# — which is the obvious wrong fix, and the URL case above does NOT catch it: a URL matches the
# pattern, so that refusal stays green on the mutant. What catches it is `backup@nas`, a LEGAL
# remote name (a remote name is a refname component, and `@` is allowed in one — measured, git
# 2.53.0), which the mutant refuses: a host configured exactly right, turned away. Measured on the
# mutant, it reds there and on the two refusals for values that are not URLs at all — `nowhere`,
# and `origin` on a checkout with no remotes — because a pattern lets both through to the fetch.
#
# ⚠ WHAT MEMBERSHIP DOES NOT CLOSE IS TESTED IN § card#9991, NOT HERE. `git config` writes a section
# name straight into `.git/config` with no name check, so a remote whose NAME is a credential-bearing
# URL is a CONFIGURED remote and membership passes it — measured, git 2.53.0. card#9832 left that
# untested while the question was open with the operator; the operator decided it on card#9991 (refuse
# such a MEZZ_REMOTE, and mark such a name in the list), and that section carries the cases. What IS
# tested below is the leg card#9832's round 2 CLOSED — a multi-line value.
section "card#9832 — MEZZ_REMOTE is a remote NAME, and a value that is not one is refused unprinted"

# ⛔ AN OBVIOUSLY FAKE VALUE, by construction: `example.invalid` is reserved by RFC 2606 and can
# resolve nowhere, and the token is a literal that says what it is. Nothing here is a credential.
FAKE_TOKEN='SELFTESTFAKETOKEN'
FAKE_REMOTE_URL="https://selftest:$FAKE_TOKEN@example.invalid/org/repo.git"

mkfix remote_is_a_url; export MEZZ_REMOTE="$FAKE_REMOTE_URL"
run_refusal "MEZZ_REMOTE as a credential-bearing URL" \
  "MEZZ_REMOTE does not name a remote of $ROOT" --dry-run
hasnt "MEZZ_REMOTE as a URL: the URL is nowhere in the output" "$FAKE_REMOTE_URL" "$OUT"
hasnt "MEZZ_REMOTE as a URL: nor the credential inside it, on its own" "$FAKE_TOKEN" "$OUT"
hasnt "MEZZ_REMOTE as a URL: nor the host it would have been fetched from" "example.invalid" "$OUT"
hasnt "MEZZ_REMOTE as a URL: A7's step line, which prints \$REMOTE before anything can fail, never ran" \
  "Fetching" "$OUT"
hasnt "MEZZ_REMOTE as a URL: and no gate read the release" "ok — PHP" "$OUT"
has "MEZZ_REMOTE as a URL: says outright that the value is withheld, so the omission reads as a decision" \
  "ITS VALUE IS NOT PRINTED" "$OUT"
# ⚠ The label says what this fixture's list IS, not what a list can never contain: `origin` is the
# only remote here. A list CAN carry a URL where one was written in as a NAME (section head).
has "MEZZ_REMOTE as a URL: lists the names this checkout DOES have" \
  "  · origin" "$OUT"
has "MEZZ_REMOTE as a URL: and says how to add the one that was meant" "remote add <name> <url>" "$OUT"

# ⛔ THE POSITIVE TWIN. The same fixture, one variable away: a remote whose NAME is that string.
# It is a legal name, it deploys, and `Fetching SELFTESTFAKETOKEN` reaches the screen — so the
# credential needle and the `Fetching` needle are both things this output CAN carry, and their
# absence above is a measurement rather than a needle that could never appear.
# ⚠ THE FULL URL AND THE HOST ARE NOT TWINNED — and the reason is NOT that no run could print them.
# ⛔ AN EARLIER WORDING HERE SAID "AND CANNOT BE: no URL can be a remote's name", which is the round-1
# universal this card's review round 2 falsified on three other surfaces and which survived HERE, in
# the one place where it is load-bearing: it is the stated reason those two `hasnt`es get no twin, so
# a maintainer reads it as the leak path being closed by impossibility — the exact conclusion this
# card exists to prevent, about a question that was then still open with the operator (card#9991
# has since decided it).
# WHAT IS TRUE: no name `git remote add` will CREATE can be a URL, so no twin can be built the way
# the twin above is. A name written straight in with `git config` CAN be a URL — the hole recorded
# at this section's head — and before card#9991 such a run did NOT merely reach the fetch and fail:
# measured through this harness, git resolved that name as a REMOTE, fetched from its configured
# `.url` and exited 0, with A7's step line having printed the credential. § card#9991 now refuses
# that value and carries the case; it is not twinned HERE because this section's value is a URL
# that names no remote, which is a different refusal.
# The two needles are asserted absent as the strings CONTAINING the needle that IS twinned, which is
# what makes their absence meaningful without a twin of their own.
gitc "$ROOT" remote add "$FAKE_TOKEN" "$ORIGIN"
export MEZZ_REMOTE="$FAKE_TOKEN"
run --dry-run
eq  "the positive twin: a remote NAMED with that same string deploys" 0 "$RC"
has "the positive twin: and this output DOES carry it — the absences above are measurements" \
  "Fetching $FAKE_TOKEN" "$OUT"

# ⭐ THE MUTATION-CATCHER. `backup@nas` is a legal remote name; a gate that pattern-matched `@`
# would refuse a host that is configured exactly right, which is the widening the card forbids.
mkfix remote_name_with_at
gitc "$ROOT" remote add 'backup@nas' "$ORIGIN"
export MEZZ_REMOTE='backup@nas'
run --dry-run
eq  "⭐ a LEGAL remote name carrying @: deploys (a pattern match on \`://\` or \`@\` refuses it)" 0 "$RC"
has "a legal remote name carrying @: and it is the remote that was fetched" "Fetching backup@nas" "$OUT"

# A configured name that is not the default, which is what the variable is FOR.
mkfix remote_named_not_origin
gitc "$ROOT" remote add prod-mirror "$ORIGIN"
export MEZZ_REMOTE=prod-mirror
run --dry-run
eq  "a MEZZ_REMOTE naming a configured remote other than origin: deploys" 0 "$RC"
has "a configured non-default remote: it is the one that was fetched" "Fetching prod-mirror" "$OUT"

# THE DEFAULT PATH, unchanged: MEZZ_REMOTE unset is `origin`, and `origin` is a configured remote
# of any checkout this deploy runs on, so the gate is silent on every ordinary host.
mkfix remote_default_origin
run --dry-run
eq  "the default: MEZZ_REMOTE unset deploys, exactly as before this gate" 0 "$RC"
has "the default: and it fetched origin" "Fetching origin" "$OUT"

# `origin` IS NOT SPECIAL-CASED: on a checkout with no remotes at all the default is refused too,
# and the list has its own wording rather than an empty bullet.
mkfix remote_none_configured
gitc "$ROOT" remote remove origin
run_refusal "the default origin on a checkout with no remotes at all" \
  "MEZZ_REMOTE does not name a remote of $ROOT" --dry-run
has "no remotes at all: said in words, not as an empty list" \
  "(none — this checkout has no remotes configured at all)" "$OUT"

# ⛔ A MULTI-LINE VALUE — the leg review round 2 found open in the first membership test. The test
# brackets the LIST in newlines and matches the VALUE inside it, and the value is not a name: two
# or more ADJACENT names joined by a newline therefore matched, and the gate PASSED them. Nothing
# secret got through — every line has to be a real remote name — but A7 would then state that A3c
# "established that it does name a remote" about a value that names none, the fetch would fail, and
# the multi-line value would be echoed across that refusal: the leak this gate exists to end,
# reached by the back door. Refusing such a value costs no legitimate one, and the routes INTO the
# config are enumerated rather than counted — "either route" was this comment's own short
# enumeration, and review round 3 named a third (measured, git 2.53.0):
#   · `git remote add $'two\nlines' <url>`        — `is not a valid remote name`, nothing written
#   · `git config "remote.$'two\nlines'.url" …`   — `invalid key (newline)`, nothing written
#   · hand-editing `.git/config`                  — the file then does not PARSE: `fatal: bad config
#     line N in file .git/config`, exit 128 from `git remote`, `git config --list` and `git status`
#     alike — so that checkout is refused at A3, which names that wording, long before A3c.
# ⇒ No route leaves a remote whose NAME carries a newline, so the guard rejects no real name.
# ⭐ THE CONTROLS ARE WHAT MAKE THIS CASE ABOUT THE JOINING: each half is a configured remote of
# this same fixture and deploys on its own, one variable away.
MULTILINE_REMOTE=$'origin\nupstream'
mkfix remote_multiline
gitc "$ROOT" remote add upstream "$ORIGIN"
# The fixture's own premise, asserted rather than assumed: the joined value is EXACTLY what
# `git remote` prints, which is the only reason the bracketed-list test could ever have matched it.
eq  "fixture: the two names are adjacent in \`git remote\`'s output, which is what joined them" \
  "$MULTILINE_REMOTE" "$(gitc "$ROOT" remote)"
export MEZZ_REMOTE="$MULTILINE_REMOTE"
run_refusal "a MEZZ_REMOTE that is two ADJACENT remote names joined by a newline" \
  "MEZZ_REMOTE does not name a remote of $ROOT" --dry-run
hasnt "a multi-line MEZZ_REMOTE: A7 never ran, so nothing claimed A3c had established a name" \
  "Fetching" "$OUT"
hasnt "a multi-line MEZZ_REMOTE: and the value itself is not echoed" "$MULTILINE_REMOTE" "$OUT"
has "a multi-line MEZZ_REMOTE: both halves are listed as the names that WOULD have worked" \
  "  · origin" "$OUT"
# THE CONTROLS, one variable away each: both halves really are configured remotes here, so the
# refusal above is about the joining and not about either name being unknown.
export MEZZ_REMOTE=origin
run --dry-run
eq  "the control: the first half alone is a configured remote and deploys" 0 "$RC"
export MEZZ_REMOTE=upstream
run --dry-run
eq  "the control: the second half alone is a configured remote and deploys" 0 "$RC"
has "the control: and it is the remote that was fetched" "Fetching upstream" "$OUT"

# ⛔ `git remote`'s OWN STATUS. Unguarded, a failure here would end phase A with git's status and no
# banner — the card#9646 class. Nothing this suite can do to a FIXTURE produces it (a config git
# cannot read or parse fails A3's `rev-parse --git-dir` first), so the shim produces it, and the
# refusal must be about what was NOT established rather than about the value.
mkfix remote_list_unreadable; export MEZZ_REMOTE="$FAKE_REMOTE_URL"
: > "$T/knobs/git_remote_fail"
run_refusal "a \`git remote\` that failed" \
  "git could not list the remotes of $ROOT (\`git remote\` exited 128)" --dry-run
has "git remote failed: git's own message reaches the operator" "unable to read config file" "$OUT"
has "git remote failed: says what is NOT established, rather than that the value is wrong" \
  "is NOT established" "$OUT"
hasnt "git remote failed: not reported as a MEZZ_REMOTE that names nothing" \
  "does not name a remote of" "$OUT"
hasnt "git remote failed: and the value is withheld on this branch too (it is set to the URL here)" \
  "$FAKE_TOKEN" "$OUT"
# THE CONTROL, one variable away: the same fixture with `git remote` answering — and MEZZ_REMOTE
# back to the default, since the URL it was set to is what the gate below refuses.
rm -f "$T/knobs/git_remote_fail"; unset MEZZ_REMOTE
run --dry-run
eq  "the control: the same fixture with \`git remote\` answering deploys" 0 "$RC"

# ── card#9991 — A REMOTE WHOSE NAME CONTAINS A COLON IS REFUSED AS MEZZ_REMOTE, AND A3c DOES NOT PRINT IT ──
# card#9832's membership test passes a value that IS a configured remote, and `git config` will
# write a remote whose NAME is a URL (`git config 'remote.<url>.url' …` exits 0), which `git remote`
# then lists. Two holes followed, both measured against the tree before this card:
#   · LEG 1 — MEZZ_REMOTE set to such a name passed A3c, A7's step line printed it, and git resolved
#     it as a remote and fetched from its `.url`.
#   · LEG 2 — A3c's membership refusal printed `git remote`'s output as the list of names, so on
#     such a checkout every run it refused for naming no remote printed the credential.
# The operator's decision (card#9991): refuse a MEZZ_REMOTE containing `:` after membership passes,
# and mark such an entry in the list instead of printing it. ONE predicate does both
# (remote_name_unprintable in bin/deploy.sh). A colon is the test because no name `git remote add`
# or `git remote rename` creates can carry one — measured, git 2.53.0, both answer `'a:b' is not a
# valid remote name` — so no remote those two commands created is affected.
#
# ⛔ THE ABSENCES ARE MEASUREMENTS BECAUSE OF THE TWIN, as in § card#9832: a remote whose name is the
# same token WITHOUT the URL around it — a legal name — is listed by the same refusal, and its token
# is asserted PRESENT. So the needle is one this output carries when the name is printable, and its
# absence on the colon-bearing fixture is the rule working, not a needle that could never appear.
#
# ⭐ THE MUTANTS THIS SECTION CATCHES: widening the predicate into a URL-shape guess (`*@*`, `*/*`)
# reds the legal-name control, which carries `/`, `@`, `.` and `-` and no colon; a predicate that
# refuses everything reds every control; dropping the colon refusal reds leg 1; printing the list
# unmarked reds leg 2.
section "card#9991 — a remote NAME containing ':' is refused as MEZZ_REMOTE, and no A3c refusal prints it"

# ⛔ OBVIOUSLY FAKE, by construction: `example.invalid` is reserved by RFC 2606 and the token is a
# literal that says what it is. It is a DIFFERENT token from § card#9832's, so a hit here cannot be
# that section's fixture leaking forward.
COLON_TOKEN='SELFTESTFAKECOLONTOKEN'
COLON_NAME="https://selftest:$COLON_TOKEN@example.invalid/org/repo.git"
# no_token_in_files <label> — the token is in no file of the checkout outside .git/, where the
# fixture itself wrote it. A deploy that wrote a marker or a log file would be caught here as well
# as on its output. ⛔ THE INSTRUMENT IS SEEN TO FIND IT FIRST: the same grep without the exclusion
# finds the token in .git/config, so an empty answer below is a search that could have hit.
no_token_in_files() {
  eq "$1: the instrument finds the token where the fixture wrote it (.git/config)" \
    "$ROOT/.git/config" "$(grep -rlF -- "$COLON_TOKEN" "$ROOT/.git/config")"
  eq "$1: and it is in no file of the checkout outside .git/" \
    "" "$(grep -rlF --exclude-dir=.git -- "$COLON_TOKEN" "$ROOT")"
}

# LEG 1, RED-FIRST: MEZZ_REMOTE is the colon-bearing name. Against the tree before this card it
# passed A3c, printed `Fetching <the URL>` and fetched.
mkfix remote_name_is_a_url
gitc "$ROOT" config "remote.$COLON_NAME.url" "$ORIGIN"
# The fixture's own premise, asserted rather than assumed: git lists the URL as a remote NAME, which
# is the only reason membership could ever have passed it.
has "fixture: \`git remote\` lists the URL as a remote name" \
  $'\n'"$COLON_NAME"$'\n' $'\n'"$(gitc "$ROOT" remote)"$'\n'
export MEZZ_REMOTE="$COLON_NAME"
run_refusal "a MEZZ_REMOTE that IS a configured remote whose name contains ':'" \
  "MEZZ_REMOTE names a remote of $ROOT whose NAME contains ':'" --dry-run
hasnt "colon-named MEZZ_REMOTE: the credential is nowhere in the output" "$COLON_TOKEN" "$OUT"
hasnt "colon-named MEZZ_REMOTE: nor the host it names" "example.invalid" "$OUT"
hasnt "colon-named MEZZ_REMOTE: A7's step line, which prints \$REMOTE, never ran" "Fetching" "$OUT"
hasnt "colon-named MEZZ_REMOTE: not reported as a value that names no remote — it does name one" \
  "does not name a remote of" "$OUT"
has "colon-named MEZZ_REMOTE: says outright that the value is withheld" "ITS VALUE IS NOT PRINTED" "$OUT"
has "colon-named MEZZ_REMOTE: the entry is MARKED in the list, by its position" \
  "[name 1 of \`git remote\`'s list — NOT PRINTED: it contains ':'" "$OUT"
has "colon-named MEZZ_REMOTE: the printable name beside it is still listed" "  · origin" "$OUT"
has "colon-named MEZZ_REMOTE: and it says how to rename it without printing it" \
  "remote rename \"\$(git -C $ROOT remote | sed -n '<N>p')\" <a name>" "$OUT"
no_token_in_files "colon-named MEZZ_REMOTE"
no_shell_death "colon-named MEZZ_REMOTE" "$OUT"
has "colon-named MEZZ_REMOTE: and how to give the renamed remote the fetch refspec it lacks" \
  "config remote.<a name>.fetch '+refs/heads/*:refs/remotes/<a name>/*'" "$OUT"
# ⭐ THE ADVICE, RUN: the two commands the refusal prints, in its order, on a remote written in the
# way this fixture wrote it. Advice that was never run is a guess (canon #9). The refspec step is not
# decoration: without it this remote fetches and A8 finds no renamed-mirror/main (measured building
# this case), because `git config remote.<url>.url` writes no fetch line.
gitc "$ROOT" remote rename "$(gitc "$ROOT" remote | sed -n '1p')" renamed-mirror
eq  "the advice: the rename kept the remote's URL" "$ORIGIN" "$(gitc "$ROOT" config --get remote.renamed-mirror.url)"
eq  "the advice: and the renamed remote has no fetch refspec, which is why the second step exists" \
  "" "$(gitc "$ROOT" config --get remote.renamed-mirror.fetch)"
gitc "$ROOT" config remote.renamed-mirror.fetch '+refs/heads/*:refs/remotes/renamed-mirror/*'
export MEZZ_REMOTE=renamed-mirror
run --dry-run
eq  "the advice: the renamed remote deploys" 0 "$RC"
has "the advice: and it is the remote that was fetched" "Fetching renamed-mirror" "$OUT"

# LEG 2, RED-FIRST: the SAME checkout shape, with MEZZ_REMOTE naming nothing — a refusal that has
# nothing to do with the colon-bearing remote, and printed it anyway before this card.
mkfix remote_list_carries_a_url
gitc "$ROOT" config "remote.$COLON_NAME.url" "$ORIGIN"
export MEZZ_REMOTE=nowhere
run_refusal "any A3c refusal on a checkout carrying a colon-named remote" \
  "MEZZ_REMOTE does not name a remote of $ROOT" --dry-run
hasnt "the list: the credential in a remote NAME is not printed" "$COLON_TOKEN" "$OUT"
hasnt "the list: nor the host it names" "example.invalid" "$OUT"
has "the list: the entry is marked instead, by its position" \
  "[name 1 of \`git remote\`'s list — NOT PRINTED: it contains ':'" "$OUT"
has "the list: the printable names are still listed" "  · origin" "$OUT"
no_token_in_files "the list"
# ⛔ THE TWIN, one variable away: the token as a LEGAL name, with no URL around it. The same refusal
# lists it — so the token is a string this list prints when the name is printable, and its absence
# above is the marker's doing.
mkfix remote_list_twin
gitc "$ROOT" remote add "$COLON_TOKEN" "$ORIGIN"
export MEZZ_REMOTE=nowhere
run_refusal "the twin: the same refusal with the token as a legal name" \
  "MEZZ_REMOTE does not name a remote of $ROOT" --dry-run
has "the twin: the list DOES print the token when it is a printable name" "  · $COLON_TOKEN" "$OUT"
hasnt "the twin: and nothing is marked, since no name carries ':'" "NOT PRINTED: it contains ':'" "$OUT"

# ⭐ THE CONTROLS — A LEGAL NAME IS NOT CAUGHT. `mirror/prod@nas.example-1` carries `/`, `@`, `.`
# and `-` — each a character a URL-shape guess would key on — and no colon; `git remote add`
# accepts it (measured, git 2.53.0). It deploys, and it is printed as the name it is.
LEGAL_ODD_NAME='mirror/prod@nas.example-1'
mkfix remote_legal_odd_name
gitc "$ROOT" remote add "$LEGAL_ODD_NAME" "$ORIGIN"
export MEZZ_REMOTE="$LEGAL_ODD_NAME"
run --dry-run
eq  "⭐ a legal name carrying / @ . - and no ':': deploys (a URL-shape predicate refuses it)" 0 "$RC"
has "a legal name with no ':': it is the remote that was fetched" "Fetching $LEGAL_ODD_NAME" "$OUT"
# And the rule is about MEZZ_REMOTE, not the checkout: a checkout that CARRIES a colon-named remote
# deploys from a legal one — here the default, origin — and the colon-named remote is not printed.
mkfix remote_colon_beside_default
gitc "$ROOT" config "remote.$COLON_NAME.url" "$ORIGIN"
run --dry-run
eq  "the control: a checkout carrying a colon-named remote deploys from origin" 0 "$RC"
has "the control: and it fetched origin" "Fetching origin" "$OUT"
hasnt "the control: and the colon-named remote is not printed by a deploy that never lists it" \
  "$COLON_TOKEN" "$OUT"

printf '\n──────────────────────────────────────────────\n'
# ⚠ REPEATED HERE because a line 1,400 assertions up has scrolled past. A condition this runner
# could not produce is not a failure — the suite has nothing to say about it either way — but it
# IS a hole in the coverage, and it is named rather than counted as a pass (card#9646).
if [ "$unverified" -gt 0 ]; then
  printf '⚠ %d condition(s) could NOT BE PRODUCED on this runner, so the cases that need them did not run.\n' "$unverified" >&2
  printf '  Each is named above with what this runner answered and what would have to be true to exercise it.\n' >&2
fi
if [ "$fails" -eq 0 ]; then
  printf 'deploy.selftest.sh: %d assertions, all passed\n' "$cases"; exit 0
fi
printf 'deploy.selftest.sh: %d assertions, %d FAILED\n' "$cases" "$fails" >&2; exit 1

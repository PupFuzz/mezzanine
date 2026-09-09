#!/usr/bin/env bash
# deploy.selftest.sh — hermetic, network-free acceptance for bin/deploy.sh. card#7459
#
# WHY IT EXISTS. card#7459's acceptance is one sentence: the deploy is "seen to fail on a broken
# precondition before trusted". A check that has never been watched refuse is a decoration, and a
# deploy script is the worst place to find that out — the first real run is against production, in
# a maintenance window, with the app down. So every refusal below is exercised HERE, against the
# real script, before the host it will run on even exists.
#
# WHAT IS REAL AND WHAT IS STUBBED.
#   REAL: bash, git (throwaway fixture repositories in a temp dir), the whole of bin/deploy.sh —
#         including the re-exec, which really does hand off to the checked-out copy.
#   STUB: php, composer, npm, systemctl, curl, id — on PATH, recording every call to $CALL_LOG.
#   NOTHING here touches a live host, needs a credential, or opens a socket.
#
# RED-FIRST, WITH CONTROLS. Every refusal case is paired with a control that differs by ONE
# variable and passes, so a green is evidence that the check DISCRIMINATES rather than evidence
# that it always fires. The two that matter most:
#   - the migration gate (§ 6.9): the same fixture, with and without the `ALGORITHM=` comment;
#   - the in-window failure: the same fixture, with and without a failing `migrate`, asserting
#     that `artisan up` IS called in one and is NEVER called in the other.
#
# RUN: bin/deploy.selftest.sh          (exit 0 = every case passed)

set -uo pipefail

HERE="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
DEPLOY="$HERE/deploy.sh"
[ -x "$DEPLOY" ] || { echo "selftest: $DEPLOY not found or not executable" >&2; exit 1; }

T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
export CALL_LOG="$T/calls.log"; : > "$CALL_LOG"

fails=0; cases=0
ok()  { printf '  ok   %s\n' "$1"; }
bad() { printf '  FAIL %s\n' "$1" >&2; fails=$((fails + 1)); }
eq()  { cases=$((cases+1)); [ "$2" = "$3" ] && ok "$1" || bad "$1 — expected '$2', got '$3'"; }
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
mkdir -p "$T/bin"; export PATH="$T/bin:$PATH"

cat > "$T/bin/php" <<'STUB'
#!/usr/bin/env bash
printf 'php %s\n' "$*" >> "$CALL_LOG"
[ "${1:-}" = "-r" ] && { printf '%s' "${STUB_PHP_VERSION:-8.3.14}"; exit 0; }
if [ -n "${STUB_FAIL_RE:-}" ] && printf 'php %s' "$*" | grep -Eq "$STUB_FAIL_RE"; then
  echo "stub php: forced failure on: $*" >&2; exit 1
fi
exit 0
STUB
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
cat > "$T/bin/systemctl" <<'STUB'
#!/usr/bin/env bash
printf 'systemctl %s\n' "$*" >> "$CALL_LOG"
verb="${1:-}"; unit="${@: -1}"
case "$verb" in
  cat)
    case " ${STUB_UNITS:-} " in *" $unit "*) exit 0 ;; *) echo "No files found for $unit." >&2; exit 1 ;; esac ;;
  is-enabled)
    case " ${STUB_DISABLED:-} " in *" $unit "*) echo disabled; exit 1 ;; esac; echo enabled ;;
  is-active)
    case " ${STUB_INACTIVE:-} " in *" $unit "*) echo failed; exit 3 ;; esac; echo active ;;
  *) exit 0 ;;
esac
STUB
cat > "$T/bin/curl" <<'STUB'
#!/usr/bin/env bash
printf 'curl %s\n' "$*" >> "$CALL_LOG"
printf '%s' "${STUB_HTTP_CODE:-200}"
STUB
cat > "$T/bin/id" <<'STUB'
#!/usr/bin/env bash
case "${1:-}" in
  -u) echo "${STUB_UID:-$(/usr/bin/id -u)}" ;;
  *)  /usr/bin/id "$@" ;;
esac
STUB
chmod +x "$T/bin/"*

# reset_stubs — bash persists `VAR=x func` assignments after the call, so a knob set for one case
# would silently leak into every later one. Each fixture starts from a known set instead.
reset_stubs() {
  export STUB_UNITS="mezzanine-fold mezzanine-sweep mezzanine-feed-heartbeat php8.4-fpm"
  export STUB_DISABLED="" STUB_INACTIVE="" STUB_FAIL_RE="" STUB_HTTP_CODE=200
  # 8.4.7 SATISFIES $FIXTURE_PHP_FLOOR without BEING it, so a case that passes here is not
  # passing on an accidental exact match; `php8.4-fpm` above is the unit deploy.sh derives
  # from it (card#9203), so the two move together or every fixture refuses at A13.
  export STUB_PHP_VERSION="8.4.7" MEZZ_DAEMON_SETTLE_S=0
  unset STUB_UID MEZZ_REVERB_SERVICE MEZZ_DEPLOY_IN_WINDOW
}

# ── fixture ───────────────────────────────────────────────────────────────────────────────────
# A throwaway repo pair: `origin.git` (bare) with `main` at two commits, and `root` — the "prod
# checkout" — parked one commit behind, which is the state a real deploy starts from.
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

# mkfix <case> [mutator]  — mutator runs in the source tree before the SECOND commit, so a case
# can put whatever it needs into the commit the deploy is asked to move TO.
mkfix() {
  local case="$1" mutator="${2:-}" cd="$T/$1"
  reset_stubs
  ROOT="$cd/root"; ORIGIN="$cd/origin.git"; SRC="$cd/src"
  mkdir -p "$cd"
  git init -q --bare -b main "$ORIGIN"
  git init -q -b main "$SRC"
  mkdir -p "$SRC/bin" "$SRC/server/bootstrap" "$SRC/server/database/migrations"
  cp "$DEPLOY" "$SRC/bin/deploy.sh"; chmod +x "$SRC/bin/deploy.sh"
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
has "control: names the Reverb state"       "no Reverb daemon to restart yet (card#7339" "$OUT"
unlogged "control: --dry-run mutates nothing (no artisan down)" "artisan down"
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
# because the floor that matters is the TARGET release's.

# THE CARD'S OWN SCENARIO: one minor below the floor the release declares. The 8.3 host is given
# its OWN php8.3-fpm unit on purpose, so that A6 is the ONLY thing left that can refuse: without
# it, A13 refuses the missing unit instead and the exit code alone would go on reading 1 with the
# floor check gutted. Verified by gutting it (canon #9).
mkfix php_below_floor
STUB_PHP_VERSION=8.3.33
STUB_UNITS="mezzanine-fold mezzanine-sweep mezzanine-feed-heartbeat php8.3-fpm"
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
# `8.5*`; this allows it by EVALUATING `^8.4.1`, and the FPM unit follows the host with it.
mkfix php_above_floor
STUB_PHP_VERSION=8.5.4
STUB_UNITS="mezzanine-fold mezzanine-sweep mezzanine-feed-heartbeat php8.5-fpm"
run --dry-run
eq  "control: PHP 8.5.4 satisfies ^8.4.1"                  0 "$RC"
has "control: the FPM unit is derived from the host's PHP" "+ php8.5-fpm (reload)" "$OUT"

# ⛔ THE CEILING, which the old case list got wrong in the OTHER direction: it listed `9.*`, and
# `^8.4.1` has never allowed 9. A restated constraint drifts both ways at once, and nothing in
# the tree read both copies.
mkfix php_above_ceiling
STUB_PHP_VERSION=9.0.0
STUB_UNITS="mezzanine-fold mezzanine-sweep mezzanine-feed-heartbeat php9.0-fpm"
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
mkfix floor_raised_ok raise_floor
STUB_PHP_VERSION=8.5.4
STUB_UNITS="mezzanine-fold mezzanine-sweep mezzanine-feed-heartbeat php8.5-fpm"
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
mkfix floor_alternation alternation_floor
STUB_PHP_VERSION=9.0.0
STUB_UNITS="mezzanine-fold mezzanine-sweep mezzanine-feed-heartbeat php9.0-fpm"
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

section "REFUSAL — the daemons (handover item 1)"
mkfix unit_missing
STUB_UNITS="mezzanine-fold mezzanine-feed-heartbeat php8.4-fpm"; run --dry-run
eq "missing unit: exit 1" 1 "$RC"
has "missing unit: names it" "systemd unit 'mezzanine-sweep' does not exist" "$OUT"

mkfix unit_disabled
STUB_DISABLED="mezzanine-fold"; run --dry-run
eq "disabled unit: exit 1" 1 "$RC"
has "disabled unit: says why" "is 'disabled', not enabled" "$OUT"

mkfix reverb_no_unit; sed -i 's/^BROADCAST_CONNECTION=.*/BROADCAST_CONNECTION=reverb/' "$ROOT/server/.env"
run --dry-run
eq "BROADCAST=reverb without a unit: exit 1" 1 "$RC"
has "BROADCAST=reverb without a unit: names it" "systemd unit 'mezzanine-reverb' does not exist" "$OUT"
STUB_UNITS="mezzanine-fold mezzanine-sweep mezzanine-feed-heartbeat mezzanine-reverb php8.4-fpm"; run --dry-run
eq  "control: with the unit present, reverb deploys"    0 "$RC"
has "control: and Reverb is IN the restart set"         "systemctl restart mezzanine-fold mezzanine-sweep mezzanine-feed-heartbeat mezzanine-reverb" "$OUT"

mkfix reverb_orphan
export MEZZ_REVERB_SERVICE=mezzanine-reverb; run --dry-run
eq "named Reverb unit that nothing would restart: exit 1" 1 "$RC"
has "named Reverb unit: says why" "MEZZ_REVERB_SERVICE is set but BROADCAST_CONNECTION is 'log'" "$OUT"

mkfix internal_flag
export MEZZ_DEPLOY_IN_WINDOW=0; run --internal-post-checkout "$V2"
eq "post-checkout entry point by hand: exit 1" 1 "$RC"
has "post-checkout entry point: says why" "not an operator entry point" "$OUT"

# ══════════════════════════════════════════════════════════════════════════════════════════════
section "THE FULL RUN — the window, the order, the daemons, the close"
mkfix full_run
run
eq "full run: exit 0"                                   0 "$RC"
eq "full run: HEAD moved to the target commit"          "$V2" "$(git -C "$ROOT" rev-parse HEAD)"
has "full run: reports success"                         "✔ DEPLOYED" "$OUT"
eq "full run: the failure marker is gone"               "absent" "$([ -e "$ROOT/.deploy-failed" ] && echo present || echo absent)"
logged   "full run: opened the window"                  "artisan down"
logged   "full run: closed the window"                  "artisan up"
logged   "full run: restarted queue workers"            "artisan queue:restart"
logged   "full run: reloaded PHP-FPM (opcache)"         "reload-or-restart -- php8.4-fpm"
logged   "full run: restarted the fold"                 "restart -- mezzanine-fold"
logged   "full run: restarted the sweep"                "restart -- mezzanine-sweep"
logged   "full run: restarted the feed heartbeat"       "restart -- mezzanine-feed-heartbeat"
logged   "full run: re-checked each unit is ACTIVE"     "is-active -- mezzanine-sweep"
logged   "full run: smoke-checked /up"                  "curl "
unlogged "full run: forward-only — never rolls back"    "migrate:rollback"
before "order: down before anything is built"           "artisan down" "composer install"
before "order: composer before npm"                     "composer install" "npm ci"
before "order: npm ci before the asset build"           "npm ci" "npm run build"
before "order: caches CLEARED before migrate"           "optimize:clear" "artisan migrate"
before "order: migrate before the caches are rebuilt"   "artisan migrate" "config:cache"
before "order: config:cache first of the four"          "config:cache" "route:cache"
before "order: caches rebuilt before the daemons"       "event:cache" "restart -- mezzanine-fold"
before "order: daemons restarted before the app is up"  "restart -- mezzanine-fold" "artisan up"
before "order: FPM reloaded before the app is up"       "reload-or-restart" "artisan up"
before "order: smoke check after the app is up"         "artisan up" "curl "
hasnt "full run leaks no APP_KEY"                       "$FAKE_KEY" "$OUT"
hasnt "full run leaks no DB password"                   "$FAKE_PW"  "$OUT"

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

section "IN-WINDOW FAILURE — a daemon that restarts and then dies"
mkfix daemon_dies
STUB_INACTIVE="mezzanine-fold"; run
eq  "dead daemon: exit 2"                               2 "$RC"
has "dead daemon: names the unit and the state"         "unit mezzanine-fold is 'failed'" "$OUT"
unlogged "dead daemon: the app is NEVER brought up"     "artisan up"

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

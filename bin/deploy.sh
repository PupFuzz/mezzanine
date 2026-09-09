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
#   THIS app's code in memory. Copying the silence would leave the fold, the sweep, the feed
#   heartbeat and — once card#7339 lands — Reverb serving the PREVIOUS release's code after every
#   deploy, indefinitely and invisibly: the floor would render, the sockets would stay up, and the
#   broadcast payloads would be the old ones. So the daemons are restarted INSIDE the maintenance
#   window, and a unit that does not come back is an in-window failure, not a warning.
#
# WHAT IT IS NOT. It does not provision the host (D-08 / D-15 own that), does not write `.env`,
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
#   MEZZ_DAEMON_SERVICES  systemd units for the long-lived daemons of
#                         `docs/design/FLEET-STATE.md § 2.1` + § 8.3
#                         [default: mezzanine-fold mezzanine-sweep mezzanine-feed-heartbeat]
#   MEZZ_REVERB_SERVICE   systemd unit for Reverb       [default: mezzanine-reverb]
#   MEZZ_FPM_SERVICE      PHP-FPM unit to reload        [default: php8.3-fpm]
#   MEZZ_SYSTEMCTL        how to reach systemd          [default: systemctl; on a host where the
#                         deploy user needs escalation set `sudo -n systemctl` — the `-n` is
#                         load-bearing, see the sudo precondition below]
#
# CARD / DECISION TOKENS, kept in the script on purpose (handover item 4 — they make it
# answerable): card#7459 (this script) · card#7339 (Reverb feed; the broadcaster) · card#7344 (CI
# lanes) · D-08 (separate prod host, unprovisioned) · D-13 (prod moves only by this script) ·
# D-15 (the store on a dedicated host; MariaDB per its 2026-09-09 amendment) · D-16 (the app
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
DEPLOY_ROOT="${MEZZ_DEPLOY_ROOT:-$(cd "$(dirname "$SELF")/.." && pwd)}"
REMOTE="${MEZZ_REMOTE:-origin}"
FPM_SERVICE="${MEZZ_FPM_SERVICE:-php8.3-fpm}"
REVERB_SERVICE="${MEZZ_REVERB_SERVICE:-mezzanine-reverb}"
# The default daemon set is the supervised population of `FLEET-STATE.md § 2.1` — fold, sweep and
# the 15 s feed heartbeat, each stated there as "supervised", not scheduled, and each a long-lived
# loop (`mezzanine:feed-heartbeat` runs without `--once`). This set has carried the heartbeat since
# card#7459, which is where the § 2.1 omission card#9181 later closed was found: this script
# refuses a host missing the heartbeat's unit, and the doc could not. `mezzanine:purge` is NOT here:
# it is a scheduled command, so each run already starts from the new code. `mezzanine:retire` is an
# operator command and runs nothing between deploys.
DAEMON_SERVICES="${MEZZ_DAEMON_SERVICES:-mezzanine-fold mezzanine-sweep mezzanine-feed-heartbeat}"
read -r -a SYSTEMCTL <<< "${MEZZ_SYSTEMCTL:-systemctl}"

APP_DIR="$DEPLOY_ROOT/server"          # D-16: the Laravel app is not at the repo root
ENV_FILE="$APP_DIR/.env"
MARKER="$DEPLOY_ROOT/.deploy-failed"   # git-ignored; see .gitignore

REF="main"; DRY_RUN=0; REDEPLOY=0; ALLOW_UNRELEASED=0; POST_CHECKOUT_SHA=""

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
# ⚠ SECRETS. `env_get` RETURNS values; nothing in this script PRINTS one. APP_KEY, DB_PASSWORD and
# every credential are tested for shape only (`-n`, a prefix), never echoed, never put in an argv
# and never in an error message — a deploy log is a transcript that outlives the deploy.
env_get() {
  local key="$1" line
  line="$(grep -E "^[[:space:]]*${key}=" "$ENV_FILE" 2>/dev/null | tail -n 1 || true)"
  [ -n "$line" ] || return 1
  line="${line#*=}"
  line="${line%\"}"; line="${line#\"}"
  line="${line%\'}"; line="${line#\'}"
  printf '%s' "$line"
}

git_at() { git -C "$DEPLOY_ROOT" "$@"; }

# ══════════════════════════════════════════════════════════════════════════════════════════════
# PHASE A — preconditions. EVERY refusal in this phase happens BEFORE anything is touched.
# ══════════════════════════════════════════════════════════════════════════════════════════════
phase_a() {
  step "Preconditions"

  # A0 — never as root. `composer install` and `npm ci` write vendor/, node_modules/ and
  # public/build/; run as root they leave root-owned files that the FPM user cannot rewrite, and
  # the NEXT deploy fails on a permission error whose cause is two deploys old. Escalation for the
  # calls that genuinely need it (systemctl) is MEZZ_SYSTEMCTL's job, not the whole script's.
  [ "$(id -u)" -ne 0 ] || refuse "running as root" \
    "Deploy as the application user. If systemd needs escalation, set:" \
    "  MEZZ_SYSTEMCTL='sudo -n systemctl'"

  # A1 — the tools this script shells out to. A missing binary discovered mid-window is an
  # outage; discovered here it is a refusal.
  local missing=()
  for c in git php composer npm curl "${SYSTEMCTL[0]}"; do
    command -v "$c" >/dev/null 2>&1 || missing+=("$c")
  done
  [ ${#missing[@]} -eq 0 ] || refuse "missing required command(s): ${missing[*]}"

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
      "The app is (or should be) DOWN. \`cd $APP_DIR && php artisan up\` brings it back on the" \
      "code that is currently checked out — check \`git -C $DEPLOY_ROOT rev-parse HEAD\` first."
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

  # A5 — the environment file. Shape only; no value is printed.
  [ -f "$ENV_FILE" ] || refuse "$ENV_FILE does not exist" \
    "It is created once when the host is stood up: copy server/.env.example, fill it in," \
    "and run \`php artisan key:generate\` there (docs/PLAN.md § 5)."
  local perm other; perm="$(stat -c '%a' "$ENV_FILE")"; other="${perm: -1}"
  [ "$other" = "0" ] || refuse ".env is readable beyond its owner and group (mode $perm)" \
    "chmod 640 $ENV_FILE"

  local app_env app_debug app_key db_conn ssl_ca
  app_env="$(env_get APP_ENV || true)"
  [ "$app_env" = "production" ] || refuse "APP_ENV is '${app_env:-unset}', not 'production'" \
    "This script deploys PROD. Pointing it at a sandbox checkout is how the two instances" \
    "(D-13) become one."
  app_debug="$(env_get APP_DEBUG || true)"
  [ "$app_debug" = "false" ] || refuse "APP_DEBUG is '${app_debug:-unset}', not 'false'" \
    "Debug mode renders stack traces — including environment values — to any visitor."
  app_key="$(env_get APP_KEY || true)"
  [ -n "$app_key" ] || refuse "APP_KEY is empty" \
    "server/.env.example ships it empty deliberately; it is minted per host with" \
    "\`php artisan key:generate\` (docs/PLAN.md § 5). Minting one HERE would silently" \
    "invalidate every existing session and encrypted column."
  db_conn="$(env_get DB_CONNECTION || true)"
  # ⚠ 'mysql' HERE IS THE LARAVEL CONNECTION NAME (server/config/database.php), NOT THE SERVER
  # PRODUCT. D-15's 2026-09-09 amendment repinned the product to MariaDB; the app is still
  # wired to the `mysql` connection — Tests\TestCase and § 6.2's pin guard both key on
  # `database.connections.mysql.database` — and Laravel's `mysql` driver speaks to a MariaDB server.
  # Whether to move to config/database.php's `mariadb` connection is an OPEN DECISION for the
  # operator: it changes what this script accepts and what those guards key on. It is not taken here.
  [ "$db_conn" = "mysql" ] || refuse "DB_CONNECTION is '${db_conn:-unset}', not 'mysql'" \
    "D-15 and docs/design/FLEET-STATE.md § 6.1 pin the store to MariaDB on a dedicated host, at" \
    "the version floor § 6.1 states, reached through Laravel's 'mysql' connection. sqlite here" \
    "would be a prod store that silently cannot do what the fold needs (FOR UPDATE SKIP LOCKED)" \
    "and that no backup or provisioning decision covers."
  # A cache store that PERSISTS between requests (docs/PLAN.md § 5) — a security obligation, not a
  # tuning choice. App\Auth\ActiveUserProvider pays for its dummy bcrypt ONCE per deployment by
  # keeping it in the cache, so that an unknown address and a known one with a wrong password cost
  # the same hashing work. On array/null the hash is minted again on every miss — two bcrypts
  # against one — and the timing gap is a user-enumeration oracle on an endpoint whose rate limiter
  # keys on email+IP and so does not throttle probing N addresses from one IP at all. UNSET is fine
  # and is not checked: config/cache.php's own default is 'database'.
  local cache_store; cache_store="$(env_get CACHE_STORE || true)"
  case "$cache_store" in
    array|null) refuse "CACHE_STORE is '$cache_store', which does not survive a request" \
      "docs/PLAN.md § 5: the login path's non-enumerability depends on the dummy bcrypt" \
      "outliving the request that minted it. server/.env.example ships 'database'." ;;
  esac

  ssl_ca="$(env_get MYSQL_ATTR_SSL_CA || true)"
  [ -n "$ssl_ca" ] || refuse "MYSQL_ATTR_SSL_CA is unset" \
    "FLEET-STATE.md § 6.1: TLS is REQUIRED to the store, certificate verified, with no" \
    "plaintext fallback — the credential and every descriptor cross a network between hosts."

  # NOT CHECKED HERE, on purpose: § 6.1's MariaDB version floor, the storage engine, the
  # collations and the session time zone. FLEET-STATE.md § 6.1 assigns every one of them to "verified at
  # provisioning", and a deploy-time re-check would either duplicate that verification or, worse,
  # become the place it is believed to happen while checking something weaker.
  #
  # A6 — the PHP floor. server/composer.json requires ^8.3; composer would refuse anyway, but it
  # would refuse INSIDE the window, after the app was already taken down.
  local phpver; phpver="$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo 0)"
  case "$phpver" in
    8.3*|8.4*|8.5*|9.*) : ;;
    *) refuse "PHP $phpver does not satisfy server/composer.json's ^8.3" ;;
  esac

  # A7 — fetch, and resolve the ref to an exact commit. The fetch is read-only with respect to
  # what is being served (it moves remote-tracking refs, nothing in the worktree), so --dry-run
  # runs it too: a ref check that did not fetch would be checking yesterday's answer.
  step "Fetching $REMOTE"
  git_at fetch --prune --tags "$REMOTE"

  SHA="$(git_at rev-parse --verify --quiet "refs/remotes/$REMOTE/$REF^{commit}" \
      || git_at rev-parse --verify --quiet "refs/tags/$REF^{commit}" \
      || git_at rev-parse --verify --quiet "$REF^{commit}" || true)"
  [ -n "$SHA" ] || refuse "'$REF' does not resolve to a commit on $REMOTE"

  # A8 — prod runs RELEASED code. `main` is the release branch (README § Branch model), so a
  # commit that is not an ancestor of $REMOTE/main has not been through the release PR, the
  # release-pr-guard or the tagger. The escape hatch is explicit and named in the log, never
  # implicit: a hotfix an operator has decided to deploy is a decision, not a default.
  if ! git_at merge-base --is-ancestor "$SHA" "refs/remotes/$REMOTE/main" 2>/dev/null; then
    [ "$ALLOW_UNRELEASED" -eq 1 ] || refuse "$(git_at rev-parse --short "$SHA") is not contained in $REMOTE/main" \
      "Prod deploys released code. Cut the release PR, or — deliberately —" \
      "re-run with --allow-unreleased."
    warn "DEPLOYING UNRELEASED CODE: $(git_at rev-parse --short "$SHA") is not on $REMOTE/main (--allow-unreleased)"
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

  # A10 — FLEET-STATE.md § 6.9 rule 1: "Every migration on `events` states its algorithm in a
  # comment AND THE DEPLOY CHECKS IT". This is that check, and it reads the TARGET tree out of the
  # object database (git show) rather than the working copy, so it can refuse BEFORE the checkout
  # and before the window. What it proves is that an algorithm was DECLARED — it cannot prove
  # MariaDB will honour it; a declared INSTANT that the server rejects fails loudly at migrate time,
  # which is the backstop. What it removes is the silent case: an ALTER that nobody thought about,
  # taking the ingest down for the length of a table copy.
  step "Checking migrations against FLEET-STATE.md § 6.9"
  local mig body offenders=()
  while IFS= read -r mig; do
    [ -n "$mig" ] || continue
    body="$(git_at show "$SHA:$mig")"
    if printf '%s' "$body" | grep -Eqi "Schema::table\([[:space:]]*['\"]events['\"]|ALTER[[:space:]]+TABLE[[:space:]]+\`?events\`?"; then
      printf '%s' "$body" | grep -Eqi "ALGORITHM[[:space:]]*=[[:space:]]*(INSTANT|INPLACE)" \
        || offenders+=("$mig")
    fi
  done < <(git_at ls-tree --name-only -r "$SHA" -- server/database/migrations 2>/dev/null || true)
  if [ ${#offenders[@]} -gt 0 ]; then
    refuse "migration(s) alter \`events\` without stating an ALGORITHM" \
      "$(printf '  | %s\n' "${offenders[@]}")" \
      "FLEET-STATE.md § 6.9: ALGORITHM=INSTANT for a nullable column added at the end," \
      "INPLACE for a secondary index. Anything that would be COPY does not ship as a" \
      "migration at all — \`events\` is written on the ingest's request path and a blocking" \
      "ALTER is an ingest outage."
  fi
  say "  ok — no undeclared ALTER on \`events\` in $(git_at rev-parse --short "$SHA")"

  # A10b — config drift between the release and the host. A release that introduces a setting ships
  # it in `server/.env.example`; the host's `.env` was written by hand when the host was stood up
  # and NOTHING ever updates it again. The failure this catches is the quiet one: the deploy is
  # green, the app is up, and one feature reads a value nobody set. It WARNS rather than refuses
  # because an absent key is not automatically a defect — several have framework defaults, and
  # `.env.example`'s commented lines are deliberately optional — but nothing else on this host will
  # ever mention it.
  local want missing_keys=()
  want="$(git_at show "$SHA:server/.env.example" 2>/dev/null | grep -Eo '^[A-Z][A-Z0-9_]*=' | tr -d '=' || true)"
  for k in $want; do
    grep -Eq "^[[:space:]]*$k=" "$ENV_FILE" || missing_keys+=("$k")
  done
  [ ${#missing_keys[@]} -eq 0 ] \
    || warn "the target release's .env.example names keys this host's .env does not set: ${missing_keys[*]}"

  # A11 — trusted proxies (docs/PLAN.md § 5). Checked against the TARGET tree for the same reason
  # as A10. `trustProxies('*')` lets any client forge X-Forwarded-For, which defeats the key that
  # D1 § 12.3's failed-authentication limit is built on and turns that limit into the decoration
  # § 12.3 says it must not be. Trusting NOTHING is the state § 5 describes as fail-safe-but-coarse
  # (every request appears to come from the proxy), so it is a loud warning here and not a
  # refusal — the doc's own reading, not a softened one.
  local bootstrap; bootstrap="$(git_at show "$SHA:server/bootstrap/app.php" 2>/dev/null || true)"
  if printf '%s' "$bootstrap" | grep -Eq "trustProxies\(.*['\"]\*['\"]"; then
    refuse "server/bootstrap/app.php trusts ALL proxies (\`*\`)" \
      "docs/PLAN.md § 5: never \`*\`. Name the actual reverse proxy."
  fi
  printf '%s' "$bootstrap" | grep -q "trustProxies" \
    || warn "no trustProxies() configured — the failed-auth limit will key on the reverse proxy's IP for every request (docs/PLAN.md § 5; coarse, not forgeable)"

  # A12 — a lockfile for the asset build. `npm ci` is used below and requires one; more to the
  # point, package.json floats (vite ^8, tailwind ^4), so a lockfile-less prod build can ship
  # different JavaScript from the same commit on two consecutive days, and nothing in the repo
  # would record which. Refusing here is not this script being strict — it is the only place the
  # question is still cheap.
  git_at cat-file -e "$SHA:server/package-lock.json" 2>/dev/null || refuse \
    "server/package-lock.json is missing from $(git_at rev-parse --short "$SHA")" \
    "The prod asset build must be reproducible: package.json floats (vite ^8, tailwind ^4)," \
    "so without a lockfile the same commit can build different assets on different days." \
    "Commit the lockfile (\`npm install\` in server/, commit server/package-lock.json)."

  # A13 — the daemons. Derived, not asserted: Reverb is in the restart set exactly when the app
  # actually broadcasts through it. That derivation is what keeps handover item 1 from rotting —
  # when card#7339 flips BROADCAST_CONNECTION to reverb, the restart becomes mandatory on the
  # NEXT deploy with nobody having to remember to add it, and a missing unit refuses the deploy
  # rather than silently skipping the restart.
  local broadcast; broadcast="$(env_get BROADCAST_CONNECTION || true)"
  RESTART_UNITS=()
  for u in $DAEMON_SERVICES; do RESTART_UNITS+=("$u"); done
  if [ "$broadcast" = "reverb" ]; then
    [ -n "$REVERB_SERVICE" ] || refuse "BROADCAST_CONNECTION=reverb but MEZZ_REVERB_SERVICE is empty"
    RESTART_UNITS+=("$REVERB_SERVICE")
  else
    # The contradiction case, made loud rather than tolerated: a named Reverb unit that this
    # deploy would never restart is a restart the operator believes is happening.
    if [ -n "${MEZZ_REVERB_SERVICE:-}" ]; then
      refuse "MEZZ_REVERB_SERVICE is set but BROADCAST_CONNECTION is '${broadcast:-unset}'" \
        "Nothing would restart it, and the setting reads as though something does." \
        "Either point BROADCAST_CONNECTION at reverb (card#7339) or unset MEZZ_REVERB_SERVICE."
    fi
    say "  note — BROADCAST_CONNECTION='${broadcast:-unset}': no Reverb daemon to restart yet (card#7339 configures the real broadcaster; until then broadcasts go to the log driver)"
  fi

  step "Checking systemd units"
  # If systemd needs escalation, prove it is NON-INTERACTIVE now. `sudo` without -n inside the
  # window would sit at a password prompt with the app down, on a deploy nobody is watching.
  if [ "${SYSTEMCTL[0]}" = "sudo" ]; then
    sudo -n true 2>/dev/null || refuse "MEZZ_SYSTEMCTL uses sudo but non-interactive sudo does not work here" \
      "Grant the deploy user a NOPASSWD rule for the systemctl calls this script makes."
  fi
  local state
  for u in "${RESTART_UNITS[@]}" "$FPM_SERVICE"; do
    "${SYSTEMCTL[@]}" cat -- "$u" >/dev/null 2>&1 \
      || refuse "systemd unit '$u' does not exist on this host" \
           "The long-lived daemons of FLEET-STATE.md § 2.1 are supervised, not" \
           "scheduled: without a unit each one runs only until someone's shell closes." \
           "Set MEZZ_DAEMON_SERVICES if this host names them differently."
    state="$("${SYSTEMCTL[@]}" is-enabled -- "$u" 2>/dev/null || true)"
    case "$state" in
      enabled|enabled-runtime|static|generated|indirect|alias) : ;;
      *) refuse "systemd unit '$u' is '${state:-unknown}', not enabled" \
           "A disabled unit restarts fine today and is gone after the next reboot." ;;
    esac
    say "  ok — $u ($state)"
  done

  say ""
  say "Ready:"
  say "  from   $(git_at rev-parse --short "$CURRENT_SHA")"
  say "  to     $(git_at rev-parse --short "$SHA")  ($REF)"
  say "  units  ${RESTART_UNITS[*]} + $FPM_SERVICE (reload)"
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
    ...then either finish forward and \`php artisan up\`, or deploy the previous
    commit deliberately with --ref <sha> --allow-unreleased.
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
  trap - ERR
  export MEZZ_DEPLOY_IN_WINDOW=1
  exec "$DEPLOY_ROOT/bin/deploy.sh" --internal-post-checkout "$SHA"
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

  # Re-derive the restart set from the DEPLOYED release's defaults (that is what the re-exec
  # bought). Reverb's presence is derived from the deployed .env exactly as in A13.
  local broadcast; broadcast="$(env_get BROADCAST_CONNECTION || true)"
  RESTART_UNITS=()
  for u in $DAEMON_SERVICES; do RESTART_UNITS+=("$u"); done
  [ "$broadcast" != "reverb" ] || RESTART_UNITS+=("$REVERB_SERVICE")

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

  # ── the daemons (handover item 1) ───────────────────────────────────────────────────────────
  # Everything above changed code on disk. Every long-lived PHP process is still running the code
  # it loaded at its last start, and PHP-FPM is still serving its opcache. A deploy that stops
  # here is a deploy that changed nothing about what is actually executing in the daemons.
  FAILED_STEP="restarting daemons"
  step "Restarting long-lived processes"
  php artisan queue:restart   # graceful; a no-op where no worker is running
  for u in "${RESTART_UNITS[@]}"; do
    "${SYSTEMCTL[@]}" restart -- "$u"
    say "  restarted $u"
  done
  # `systemctl restart` returns when the unit STARTED, not when it survived. A daemon that dies
  # two seconds in — a bad config, a missing class after a package removal — leaves systemctl's
  # exit code at 0 and the fleet with a frozen fold that FLEET-STATE.md § 2.3 calls the one
  # degradation that looks healthy. So the state is re-read after a settle, and a unit that is
  # not active is an in-window failure.
  sleep "${MEZZ_DAEMON_SETTLE_S:-3}"
  for u in "${RESTART_UNITS[@]}"; do
    local st; st="$("${SYSTEMCTL[@]}" is-active -- "$u" 2>/dev/null || true)"
    [ "$st" = "active" ] || { echo "unit $u is '$st' ${MEZZ_DAEMON_SETTLE_S:-3}s after restart" >&2; false; }
    say "  ok — $u active"
  done

  FAILED_STEP="reloading $FPM_SERVICE"
  step "Reloading PHP-FPM (opcache still holds the previous release)"
  "${SYSTEMCTL[@]}" reload-or-restart -- "$FPM_SERVICE"

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
  local url code
  url="$(env_get APP_URL || true)"
  if [ -z "$url" ]; then
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
  restarted : ${RESTART_UNITS[*]}
  reloaded  : $FPM_SERVICE

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
  6  queue:restart · systemctl restart ${RESTART_UNITS[*]} · reload $FPM_SERVICE
  7  php artisan up · GET \$APP_URL/up
PLAN
    return
  fi

  phase_b_open_window   # never returns: it execs
}

main "$@"

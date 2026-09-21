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
# ⚑ WHAT THIS HOST'S TOOLS MUST BE (card#9616). Each refused BY NAME in phase A, before anything
#   is touched — the host is never discovered to be too old inside the window:
#     bash   `BASH_FLOOR` below, and the floor the RELEASE BEING DEPLOYED declares on its own copy
#            of that line (A1, A6b). The value is MEASURED, not read off the constructs; the block
#            that declares it says how, and what each measurement answered.
#     git    accepts `:(literal)` pathspec magic — PROBED on this checkout, no version parsed
#            (A3b). Every read of the release out of the object database passes a path that way.
#     npm    at least what the RELEASE's `server/package-lock.json` `lockfileVersion` implies, per
#            npm's own documentation (A1c reads the host's, A12 makes the comparison).
#     PHP    satisfies the RELEASE's `server/composer.json` `require.php` (A6), and FPM's opcache
#            will re-read changed code (A14).
#   Every one of them but git's is read out of the TARGET tree rather than out of this checkout,
#   because the deploy that RAISES a floor is exactly the deploy whose own checkout does not show
#   the new one. git's is a property of the host binary alone, so it is probed here.
#
# EXIT CODES — deliberately distinct, because "refused" and "broke" are different events:
#   0  deployed, smoke-checked, app up
#   1  REFUSED in the precondition phase. Nothing was touched; the app is still serving the
#      previous release. There is nothing to undo.
#   2  FAILED INSIDE THE MAINTENANCE WINDOW. The app is DOWN and stays down for operator review.
#      A failure marker is left and a bare re-run REFUSES until an operator clears it.
#   3  the app came back up but the post-window smoke check did not pass. Marker left.
#   ⛔ ANY OTHER CODE — and a 1 with no ⛔ banner — is this script DYING on a command it ran without
#      reading that command's status, not a verdict it reached (card#9646). Read it that way, and
#      read the two lines that tell them apart rather than the number: a REFUSAL prints
#      `⛔ REFUSED — <cause>` and ends `Nothing was changed. The previous release is still serving.`,
#      and an IN-WINDOW failure prints `▶ Maintenance window: OPEN` and leaves the marker named
#      above. A death prints neither, and the command's own error is the last thing on screen.
#      NOTHING WAS TOUCHED when neither the window line nor the marker is there: every phase-A
#      command runs before `php artisan down`. Each such site found is fixed — this note exists
#      because the class is not closed by inspection, not because the shape is acceptable.
#
# USAGE
#   bin/deploy.sh [--ref <ref>] [--dry-run] [--redeploy] [--allow-unreleased]
#
# CONFIG (environment; every default is derived, none is guessed):
#   MEZZ_DEPLOY_ROOT      the checkout to deploy        [default: the repo this script lives in]
#   MEZZ_REMOTE           the NAME of a remote of the checkout being deployed, to fetch from
#                         [default: origin]. ⛔ A NAME, NEVER A URL: `git fetch` would take a URL,
#                         and a URL can carry a credential that this script's own messages print
#                         and that git redacts in its own errors for some transports and not
#                         others. A3c refuses a value that is not among `git remote`'s names, in
#                         phase A, without echoing it (card#9832).
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
# ── THE BASH FLOOR (card#9616) ────────────────────────────────────────────────────────────────
# The oldest bash this script is known to run on. ⚠ THIS LINE IS ITS ONE HOME. A1 holds the bash
# running phase A to it; A6b holds that same bash to the floor the RELEASE BEING DEPLOYED declares
# on its own copy of this line, because that copy is what runs the maintenance window after the
# re-exec; and `.github/workflows/deploy-selftest.yml` READS the value from here rather than
# carrying one of its own.
#
# ⛔ IT IS MEASURED, AND A CONSTRUCT SCAN IS NOT HOW IT WAS FOUND. Scanning this file for
# version-gated SYNTAX finds `mapfile` (bash 4.0) and `exec {fd}<` (4.1) and stops — and both are a
# whole minor below the truth, because the binding construct is not syntax at all. `"${a[@]}"` over
# an array with NO elements, under `set -u`, is an `unbound variable` DEATH on bash before 4.4 —
# `"${a[*]}"` exactly as much as `"${a[@]}"` (measured, 4.3.0 vs 4.4.0; `"${!a[@]}"` is SAFE on both,
# which is why the `${!…[@]}` loops here are not in this class) — and this script has such
# expansions. The two the self-test is known to REACH with the array empty, each reded on 4.3 by a
# case of its own — which is how they are known to be reached, rather than by inspection:
#   · `"${ref_note[@]}"`   A7's refusal, empty for any ordinary ref name. The shell DIES, and the
#     refusal it was in the middle of printing never appears: exit 1, no banner, no promise.
#   · `"${files[@]}"`      checkout_lock_holders, empty on a FIRST deploy, when no daemon lock file
#     exists yet. MEASURED: this one does NOT kill the deploy. The call site is inside a `$( )`, so
#     the SUBSHELL dies, the parent reads an empty answer and carries on reporting that nothing was
#     running — which happens to be true on a first deploy. Only the self-test's `no_shell_death`
#     tripwire can see it.
#     `restart_daemons` reads that same array again in its own body, where an empty one WOULD kill
#     the run. REASONED, then measured, and the two are marked apart because they were established
#     differently: the reasoning is that the relaunch above it has created the lock files by then,
#     so no path reaches it empty; the measurement is the below-floor CI run, where the FIRST-DEPLOY
#     case's `exit 0` assertion passes on bash 4.3 — that run drives restart_daemons to completion
#     on the very interpreter the empty expansion would die on. ⇒ That assertion is also what would
#     CATCH the reasoning being wrong: were some path to reach it empty, it would stop being an
#     `exit 0` on 4.3 and the control would red on a third site instead of the two it names.
#
# ⛔ WHETHER A GIVEN EXPANSION CAN BE EMPTY IS A WHOLE-PROGRAM PROPERTY, NOT A SYNTACTIC ONE, which
# is the second half of why a scan cannot answer this. `bad`, `missing`, `stale`, `present`, `hs`
# and the rest are the same SYNTAX, and are guarded by a `${#…[@]} -gt 0` or initialised non-empty.
# `ENV_LINES` reads like the clearest case of all and is NOT one: `env_lines_load` splits with `<<<`,
# which appends a terminator, so even a ZERO-BYTE `.env` yields one (empty) element — measured — and
# the only paths that leave the array `()` set ENV_LINES_UNREADABLE or ENV_LINES_READ_FAILED, which
# both loops test before they run.
#
# ⚠ SO THE LIST ABOVE IS NOT A UNIVERSAL, AND NOTHING RE-DERIVES IT. It is a hand audit, and two
# hand audits of exactly this question have already been wrong. THE FIRST (card#9616's design
# review, F2) named three sites — `ref_note`, `ENV_LINES` and `why`. `ref_note` was right; the other
# two are not hazards at all (`why` is initialised non-empty at both of its assignments, and see
# `ENV_LINES` above); and it missed `files`. THE SECOND (this file's own header at 03a46b4) kept
# `ENV_LINES`, dropped `why` — naming it correctly among the guarded — and still missed `files`,
# which the review at `f2ee3d0` found (card#9616 comment 5831) and the measurement above then
# settled both ways. ⚠ The RECORD is named, not the round: this card has carried two numbering
# schemes at once — build rounds and review rounds — and they do not line up. Do not read the
# list as the population. What IS mechanical is the pair of CI runs
# below — they exercise whatever sites the suite reaches, enumerated or not, and `no_shell_death`
# in the self-test is what makes a site visible when it degrades instead of dying. So nothing here
# scans: the floor is where the SUITE was seen to pass and the minor below it is where it FAILED.
# ⛔ AND NO TRIPWIRE TABLE SHIPS EITHER, deliberately. A construct table is a list, every list of
# this kind measured so far has been incomplete, and an incomplete one is worse than none: it reds
# on the constructs somebody remembered and stays SILENT on the one that actually moves the floor,
# while reading like coverage. The pair of CI runs below is a check that can fail on a construct
# nobody has thought of, which is the property a table cannot have.
#
# MEASURED 2026-09-19, on the tree that introduced this line, against bash built from the GNU
# release tarballs (gcc 15.2.0, `./configure --without-bash-malloc --disable-nls`), each first on
# PATH so that the re-exec runs the same interpreter. ⚠ No figure is written down here, because the
# `bash-floor` job RE-RUNS this pair on every PR and its log is the live reading; what is recorded
# is what each run answered and why:
#   · bash 4.4  — `bin/deploy.selftest.sh` passed in full.
#   · bash 4.3  — the same suite on the same tree FAILED, on the two independent sites above: A7's
#     `'<ref>' does not resolve to a commit` refusal DYING on `"${ref_note[@]}"` instead of refusing
#     — no `⛔ REFUSED` banner, no "Nothing was changed" promise, exiting 1, which is the exact code
#     the table above says means *refused, nothing was touched* — and the FIRST-DEPLOY case's
#     tripwire, on checkout_lock_holders. That first shape is the failure this floor exists to move
#     to before anything runs; the second is the one no verdict-shaped assertion could have seen.
# The mechanism on its own, same two binaries: `set -u; a=(); for x in "${a[@]}"; do :; done`
# prints `a[@]: unbound variable` on 4.3 and completes on 4.4.
#
# ⚠ WHAT IS NOT MEASURED: anything below 4.3. A bash old enough to reject this file's SYNTAX never
# reaches A1 to be refused by it, so the gate can only speak for a shell that got that far.
#
# ⚠ READ AS TEXT — by A6b out of the release being deployed, and by CI. Keep it
# `BASH_FLOOR=<major>.<minor>`, alone on its line, at column 0, unquoted or quoted. A new construct
# stays at or below this floor, or the floor MOVES — and it moves by re-running the pair above,
# never by retyping this number.
BASH_FLOOR=4.4
# ── library mode (card#9644) ──────────────────────────────────────────────────────────────────
# RUN, or SOURCED? Phase A's target-tree gates are callable one at a time (§ PHASE A's TARGET-TREE
# GATES), and a checker reaches them by sourcing this file — which, while the bottom of it read
# `main "$@"` unguarded, ran a DEPLOY instead. `$0` is the sourcing script's name when this file is
# sourced and this file's own when it is run, so the two are equal exactly when it is being run.
# That is what the argument parsing below and `main` at the bottom are gated on, and nothing else.
#
# ⚠ SOURCING THIS FILE IS NOT FREE, and the effects are named here rather than left to be found:
# `set -Eeuo pipefail` above is set in the SOURCING shell, bin/supervision.sh is sourced beside this
# file, and DEPLOY_ROOT, HOST_PHP_VERSION and FPM_BIN are resolved below — the last two by running
# `php`. A caller that does not want any of that sources this file in a shell of its own.
DEPLOY_IS_RUN=0
[ "${BASH_SOURCE[0]}" != "$0" ] || DEPLOY_IS_RUN=1

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

# The bash running THIS PROCESS, as <major>.<minor> — what A1 and A6b hold to a BASH_FLOOR. Read from
# the interpreter itself rather than from `bash --version`, because the interpreter is the thing that
# will die.
# ⇒ IT SPEAKS FOR EVERY BASH THIS SCRIPT STARTS, AND THAT IS THE WHOLE LIST: the MAINTENANCE WINDOW
# (`exec "$BASH" …` in phase_b_open_window) and A13's read of the release's bin/supervision.sh
# (`env -u BASH_ENV "$BASH" -c …` in gate_a13_target_plan). Both hand THIS interpreter over by name,
# so the floors checked here are the floors those run under — a property of the two call sites, not
# a hope about PATH. Neither was so before card#9616: the re-exec went through the target's
# `#!/usr/bin/env bash` and A13 through a bare `bash -c`, both of which are whatever `bash` PATH
# happens to resolve to, which nothing here reads. The supervised daemons are not on this list at
# all — they start `/bin/sh -c` under `env -i`. `bin/deploy.selftest.sh`'s `window_interpreter`
# case is what holds the two to it; reverting either call site reds exactly that case.
HOST_BASH_VERSION="${BASH_VERSINFO[0]}.${BASH_VERSINFO[1]}"

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

# Sourced, `$@` is the SOURCING script's argument list, which is not this deploy's and must not be
# parsed as one — `--ref` would be taken from it, and anything else refused outright.
[ "$DEPLOY_IS_RUN" -eq 1 ] || set --
# ⛔ AN OPTION WITH NO VALUE IS REFUSED THROUGH `refuse`, LIKE EVERY OTHER PHASE-A EXIT (card#9646).
# `${2:?…}` was the shape here, and it is the same defect this card ends one line up from the deploy:
# bash prints `bash: line N: 2: --ref needs a value` and exits 1 ITSELF, so the operator gets the status
# that MEANS "refused, nothing was touched" (the exit table above) with no ⛔ banner and no "Nothing was
# changed" promise — the two lines that say which of those it is. `--internal-post-checkout`'s bare
# `${2:?}` was worse still: bash's message is then just the parameter's name. Both go through `refuse`.
# The test is `-n "${2:-}"` rather than `$# -ge 2` so that an EMPTY value is refused exactly as a missing
# one is, which is what `${2:?…}` did: `--ref ''` would otherwise resolve `refs/remotes/origin/` at A7.
while [ $# -gt 0 ]; do
  case "$1" in
    --ref)               [ -n "${2:-}" ] || refuse "--ref needs a value" "run \`$0 --help\`"
                         REF="$2"; shift 2 ;;
    --dry-run)           DRY_RUN=1; shift ;;
    --redeploy)          REDEPLOY=1; shift ;;
    --allow-unreleased)  ALLOW_UNRELEASED=1; shift ;;
    # Internal. Phase B re-enters here after the checkout — see § re-exec. Never run by hand:
    # it assumes the maintenance window is already open.
    --internal-post-checkout) [ -n "${2:-}" ] || refuse "--internal-post-checkout needs a value" \
                           "It is internal: phase B passes the commit it checked out. Run \`$0 --help\`."
                         POST_CHECKOUT_SHA="$2"; shift 2 ;;
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
# when the read STOPPED at a NUL byte rather than reaching EOF, ENV_LINES_UNREADABLE is 1 when the file
# could not be OPENED at all, and ENV_LINES_READ_FAILED is 1 when the file was NOT READ — in either of
# those last two ENV_LINES is empty, because nothing this deploy will use was read. This is the ONE place
# in this script that turns the file into lines, and both readers below iterate ENV_LINES, so "a line" is
# a single thing here.
#   · ⚠ ENV_LINES_READ_FAILED IS ONE FLAG OVER TWO DIFFERENT FAILURES, and ENV_LINES_READ_FAILED_KIND is
#     which one (card#9933): `read` for a read that RAN and stopped short, `scratch` for a read that never
#     ran at all, because no scratch file could be opened for the diagnostic that would judge it. Every
#     caller asking only "is what this file sets established?" reads the FLAG and needs no more — env_get
#     answers 3 for both, which is the whole of what those callers act on.
#   · THE KIND IS FOR THE CALLERS THAT TELL AN OPERATOR WHICH FAULT IT IS, AND THERE ARE TWO — A5's
#     `env_file_scan`, which refuses, and phase B's smoke check, which cannot refuse and warns. Both said
#     the read's thing about a read that was never made: A5 told a host whose $TMPDIR was unwritable that
#     its open had succeeded and its read had stopped short, and sent it to `dmesg` and the mount; phase B
#     told a host whose scratch file failed INSIDE the window that its `server/.env` had stopped being
#     readable, on a deploy that finished, exit 0, with the file readable the whole time.
#   · THE `scratch` KIND CAN ONLY FIRE ON A PROCESS'S FIRST LOAD, which is what decides which of those two
#     ever sees it: ENV_READ_ERR_FD is opened once and reused, so once any load has succeeded no later one
#     re-opens it. In phase A the first load is A5's `env_file_scan`; in phase B — a re-exec, so a new
#     process and a new descriptor — it is the load the smoke check makes before its read of APP_URL.
# It has to be, and card#9561 r4's BLOCKER is why: while `env_file_scan` split on `\r\n`, `\n` and `\r` alike
# and `env_get` shelled to `grep`, whose terminator is `\n` ONLY, a `.env` ending `# note\rDB_HOST=db.internal`
# was TWO lines to Dotenv and ONE to the reader that decides — so Laravel went to db.internal over TCP in
# plaintext while A5 read DB_HOST as unset, took the config default of 127.0.0.1, and exempted the store from
# TLS. Its mirror: a whole-file CRLF `.env`, which Laravel boots on perfectly, was one line to `grep` and so
# unreadable for EVERY key. Both are the same defect — two notions of "a line" — and both end here rather
# than in a refusal, because a file Laravel reads is one this script should read the same way.
#   · the split is Dotenv\Parser\Parser::parse's `Regex::split("/(\r\n|\n|\r)/")`, mirrored (v5.7.0);
#   · `read -d ''` returns status 1 at EOF **and** on a read ERROR, so THE STATUS CANNOT DISCRIMINATE —
#     and bash's DIAGNOSTIC can, which is the same "status + silence" rule git_ref_oid reads git by
#     (§ git_ref_oid). Measured here, bash 5.3.9, 2026-09-18: `/dev/null` → status 1, stderr EMPTY; a
#     directory → status 1, `read: N: read error: Is a directory`; `/proc/self/mem` → status 1,
#     `read: N: read error: Input/output error`. So status 1 with bash SILENT is end-of-file, and status 1
#     with bash SPEAKING is a read that stopped short. Status 0 is the third thing: it STOPPED, at a NUL,
#     and everything past that byte is unread — so the NUL flag. Anything else is neither, and says so.
#     ⛔ NOT A SIZE OR LENGTH TEST (card#9610 weighed and rejected it): `${#content}` against `stat -c %s`
#     counts CHARACTERS against BYTES under a UTF-8 locale, asks the filesystem a second question whose
#     answer can have changed since the first, and reads every /proc-style file — which reports size 0 —
#     as empty. The one no-root EIO fixture there is on this host is exactly such a file.
#   · ⚠ THE RESIDUAL, stated rather than assumed away: an I/O error the kernel reports to the read as an
#     end-of-file — 0 bytes rather than −1 — is invisible to EVERY userland reader, bash included, and
#     nothing below can see it. And bash's diagnostic is gettext-translated, so only its PRESENCE is read
#     here, never its words: a host running in another language refuses in that language and is not misread.
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
ENV_LINES_READ_FAILED=0
ENV_LINES_READ_FAILED_KIND=""
ENV_LINES_READ_FAILED_WHY=""

# ENV_READ_ERR_FD — the scratch `read`'s stderr goes to, and the only reason this loader needs one: bash's
# read ERROR is a message and its EOF is SILENCE, so the diagnostic has to be kept somewhere to be looked
# at, and a builtin that must set a variable in THIS shell cannot be wrapped in a `$(…)`. It is created
# once per process, on the first read, and UNLINKED the instant it exists: the fd holds the inode open, so
# there is no path left for anything to race on and nothing to clean up — it dies with this shell.
# `/dev/fd/N` re-opens that same inode from offset 0, which is what makes each write TRUNCATE what the
# previous read left and each read-back start at the top.
#
# ⚠ WHAT IT COSTS, stated rather than left to be found: bash does not set close-on-exec on a descriptor
# opened this way, so every child this script runs inherits it, and phase B's re-exec — which replaces the
# process, losing the variable but not the descriptor — makes the new process open a second. That is two
# empty, unlinked, unreferenced inodes at the very most, for the life of one deploy; it carries no content
# to leak, and no reader in this script or any child looks at a descriptor it was not given.
#
# ⚠ ONE PER PROCESS IS LOAD-BEARING, NOT TIDINESS, and the number that says so is a measurement rather
# than a preference. `bin/env-mirror-diff.sh` reads its whole key list through this loader once per cell —
# its own header states that NOTHING on the scan path forks, and why — so a scratch file per LOAD would be
# a fork per key per cell. Measured on this host, 2026-09-18, bash 5.3.9: a `mktemp`-and-`rm` per load
# costs 6.3 ms and this costs 0.08 ms, and what that lane actually pays for the shape below is one `mktemp`
# per CELL (each cell is a subshell of its own, so it opens its own): 1m41s before this change, 2m19s after.
# Re-derive both sides before changing the shape rather than trusting those figures:
#   time bash bin/env-mirror-diff.sh   ·   its own footer prints the cell count the timing is over
ENV_READ_ERR_FD=

# env_read_err_open — opens ENV_READ_ERR_FD, once. It is the one failure the loader answers for itself: it
# runs in BOTH phases and inside bin/env-mirror-diff.sh, where neither `refuse` nor `not_established` is the
# right answer, so it sets the flag — with a KIND of its own, so the callers that tell an OPERATOR can name
# THIS cause rather than the read's — and lets the caller decide.
# ⚠ IT HAS TWO FAILURE RETURNS AND THEY ARE NOT THE SAME FAULT (card#9933 review), which matters because
# each sends the operator somewhere different:
#   · 1 — `mktemp` failed. NOTHING was created, and the directory it writes into is where the fix is.
#   · 2 — a scratch file WAS created and this shell could not OPEN it. `mktemp` succeeded, so $TMPDIR is
#     not the finding; the realistic cause is how many files this deploy may have open at once.
# Both errors are silenced, so the STATUS is the only thing that tells them apart — and the loader writes
# its reason FROM that status rather than attributing both to `mktemp`, which is the same defect this card
# is about, one layer down. ⛔ A status-1 reason on a status-2 failure sends an operator to inspect a
# $TMPDIR that is working.
env_read_err_open() {
  local t
  t="$(mktemp 2>/dev/null)" || return 1
  { exec {ENV_READ_ERR_FD}<> "$t"; } 2>/dev/null || { rm -f "$t"; ENV_READ_ERR_FD=; return 2; }
  rm -f "$t"
}

env_lines_load() {
  local content="" line rc=0 msg="" scratch_rc=0 fd=
  ENV_LINES=(); ENV_LINES_NUL=0; ENV_LINES_UNREADABLE=0
  ENV_LINES_READ_FAILED=0; ENV_LINES_READ_FAILED_KIND=""; ENV_LINES_READ_FAILED_WHY=""
  # The group's `2>/dev/null` is established before the open inside it runs, which is the ordering the old
  # one-liner got wrong; the open's own status is what sets the flag, and nothing below runs on a file that
  # was never opened.
  if ! { exec {fd}< "$ENV_FILE"; } 2>/dev/null; then ENV_LINES_UNREADABLE=1; return 0; fi
  if [ -z "$ENV_READ_ERR_FD" ]; then env_read_err_open || scratch_rc=$?; fi
  if [ "$scratch_rc" -ne 0 ]; then
    exec {fd}<&-
    ENV_LINES_READ_FAILED=1
    ENV_LINES_READ_FAILED_KIND='scratch'
    # ⚠ NO PATH IS NAMED HERE, and that is a correctness point rather than a style one: `${TMPDIR:-/tmp}`
    # would read this SHELL's variable, while `mktemp` reads the one in its ENVIRONMENT, and the two are
    # the same only while TMPDIR is exported. Naming the mechanism is true either way; naming a path would
    # be a specific cause nothing here established (measured 2026-09-18: an unexported TMPDIR is invisible
    # to mktemp, which then writes under /tmp and succeeds).
    # ⛔ WHICH REASON IS READ OFF THE STATUS, never assumed (§ env_read_err_open): the two failures send an
    # operator to different places, and the fix for one is not the fix for the other.
    if [ "$scratch_rc" -eq 1 ]; then
      ENV_LINES_READ_FAILED_WHY="No scratch file could be created for bash's read diagnostic (\`mktemp\` failed), and that diagnostic is the only thing that tells a read error from an end of file here — so the read was never made and no byte of the file was taken. mktemp writes under \$TMPDIR, or /tmp when that is unset: check that whichever applies names a directory this deploy can write to, and that it is not full."
    else
      ENV_LINES_READ_FAILED_WHY="A scratch file for bash's read diagnostic WAS created and this shell could not OPEN it, and that diagnostic is the only thing that tells a read error from an end of file here — so the read was never made and no byte of the file was taken. \`mktemp\` itself succeeded, so this is not about \$TMPDIR: the usual cause is how many files this deploy may have open at once (\`ulimit -n\`)."
    fi
    return 0
  fi
  IFS= read -r -d '' content <&"$fd" 2>"/dev/fd/$ENV_READ_ERR_FD" || rc=$?
  exec {fd}<&-
  msg="$(< "/dev/fd/$ENV_READ_ERR_FD")"
  if [ "$rc" -eq 0 ]; then
    ENV_LINES_NUL=1
  elif [ "$rc" -ne 1 ] || [ -n "$msg" ]; then
    # A READ THAT STOPPED SHORT. bash's line is printed back in full and nothing is decided before it is —
    # the rule every reader in this script follows — and it is safe to print BY CONSTRUCTION, not by
    # inspection: `read`'s diagnostic names the file DESCRIPTOR and the errno string, and carries no byte
    # of what was read. ENV_LINES is left EMPTY on purpose: a file read partway is not a file this deploy
    # will certify, so there is nothing here for the readers below to hand back.
    [ -z "$msg" ] || printf '%s\n' "$msg" >&2
    ENV_LINES_READ_FAILED=1
    # Quoted because the word is `read`: bare, ShellCheck reads it as a command name (SC2209), and the
    # value here is the name of a KIND, never a command to run.
    ENV_LINES_READ_FAILED_KIND='read'
    if [ -n "$msg" ]; then
      ENV_LINES_READ_FAILED_WHY="bash's own read error is printed above this refusal. It names the file descriptor it was reading and the errno the kernel answered with, and no byte of what the file holds."
    else
      ENV_LINES_READ_FAILED_WHY="bash's \`read\` exited $rc and printed NOTHING. Status 0 is a NUL byte and status 1 is end-of-file or a read error; this is neither of those, so what the read did is not established here and nothing it may have returned is used."
    fi
    return 0
  fi
  content="${content//$'\r\n'/$'\n'}"
  content="${content//$'\r'/$'\n'}"
  # `<<<` appends exactly the terminator the last line needs, so ${#ENV_LINES[@]} is the number of lines
  # Dotenv sees — including the partial one a NUL cut short, which is the line the refusal below names.
  while IFS= read -r line; do ENV_LINES+=("$line"); done <<< "$content"
}

# env_file_unread — TRUE when the flags the LAST `env_lines_load` set say $ENV_FILE was not read: it could
# not be opened at all, or it was opened and no read of it completed. One place, because there are now two
# callers and a second copy would be a second contract (card#9933): `env_get`, which turns it into status 3,
# and phase B's smoke check, which asks it of a load IT made so that the status it reports and the KIND it
# reads come from one load rather than from two events (§ the smoke check).
# ⚠ IT IS ABOUT THE LOAD IN HAND. It reads globals, so a caller asks it directly after its own
# `env_lines_load` and never about somebody else's — which is exactly the distinction that makes phase B's
# use of it sound.
env_file_unread() { [ "$ENV_LINES_UNREADABLE" = 1 ] || [ "$ENV_LINES_READ_FAILED" = 1 ]; }

# env_get KEY — prints KEY's value and returns 0; returns 1 when no line defines KEY; returns 2, printing nothing,
# when a line defining KEY is in a form this reader does not read EXACTLY as Laravel does (vlucas/phpdotenv's
# Dotenv\Parser, then Illuminate\Support\Env::get). Status 2 is never "unset": a check that took an unread value
# as absent would pass the value Laravel then uses.
#
# ⛔ AND STATUS 3, printing nothing, when THE FILE ITSELF WAS NOT READ — it could not be opened, or it was
# opened and the read did not reach its end (§ env_lines_load). STATUS 3 IS NEVER "UNSET" EITHER, and it is a
# STATUS rather than a flag for one reason: every caller that wants a value calls this inside a `$(…)`, so the
# flags env_lines_load sets die with that subshell and the status is the only thing that crosses back. A reader
# that let a 3 fall into the status-1 branch would report every key of an unread file as one this host does not
# set — which is the wrong cause in the safe direction for A5 (it refuses, naming the wrong thing) and in the
# DANGEROUS one for phase B and A10b, where "unset" is a fact those two act on.
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
  # Asked of the LOADER's flags, above every question about content: ENV_LINES is empty in both of these
  # cases, and an empty ENV_LINES is exactly what a file that really sets nothing looks like from here.
  if env_file_unread; then return 3; fi
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

# env_unread_refuse KEY — the refusal for env_get status 3, in one place because TWO callers make it (env_read
# below, and A10b) and a second wording would be a second contract. It lives inside the .env reading block so
# that bin/env-mirror-diff.mirror.sh, which sources this block with `refuse` stubbed, gets it with the readers.
#
# It says less than env_file_scan's two refusals do, and deliberately: this is reached AFTER that scan has
# already passed on this file in this run, so what it knows is that the file has STOPPED being readable since —
# not which of the two ways. bash's own error, when the read was the half that failed, is on screen above it.
env_unread_refuse() {
  refuse "$ENV_FILE could not be read, so what Laravel reads for $1 is not established" \
    "It was read once already in this run — A5 scans it before its first key — so this is not a .env that was" \
    "never readable: it has stopped being readable since, either at the OPEN (ownership or mode changed under" \
    "this deploy) or during the READ (a disk, filesystem or network-mount fault, in which case bash's own error" \
    "is above this refusal)." \
    "Nothing was read out of it here, so no key's value is established — not this one, and not the ones already" \
    "checked, which were read from a file that has since changed under this run." \
    "Fix what made it unreadable and run \`$0 --dry-run\`, which reaches this file at A5 and names the cause there." \
    "Its content is not printed here — it may carry a credential."
}

# env_read VAR KEY — env_get KEY into VAR, in THIS shell: returns 1 with VAR empty when KEY is unset, and REFUSES
# when env_get cannot read KEY. The refusal names the key and never the line, which may carry a credential.
# It refuses on status 3 as well as on status 2, and that is the whole reason A5's `env_read … || true` call
# sites are safe: `|| true` swallows a status, and a 3 swallowed into A5's status-1 path would be read as
# "APP_ENV is 'unset'" — a deploy refused, loudly, on a cause nothing established (card#9610).
env_read() {
  local _env_value _env_rc=0
  _env_value="$(env_get "$2")" || _env_rc=$?
  [ "$_env_rc" -ne 3 ] || env_unread_refuse "$2"
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
  # The I/O refusal's sibling one step further in, and the one the open check above cannot make: the file
  # OPENED and the read of it did not reach the end. Splitting the open out gave that failure a status of
  # its own; this gives the READ one, which `read`'s own status cannot carry (§ env_lines_load).
  #
  # ⛔ TWO REFUSALS, BECAUSE ONE FLAG CARRIES TWO FAILURES AND ONLY ONE OF THEM IS ABOUT THE FILE
  # (card#9933). The loader sets ENV_LINES_READ_FAILED for a read that stopped short AND for a read it
  # never made, and this used to refuse both under the read's headline and the read's body — so a host
  # whose $TMPDIR was unwritable was told the open had succeeded and the read had stopped short, and was
  # pointed at `dmesg`, the mount and the media, for a scratch file. Every sentence of it named something
  # the run had not established, while the cause sat one line down in $ENV_LINES_READ_FAILED_WHY.
  # `refuse` never returns, so the scratch kind exits at its own refusal and the one below it is the
  # read's. What both still share is the FLAG, which is what keeps every caller that only needs to know
  # whether a key's value is established — env_get's status 3, and through it A10b — reading one fact:
  # the file was not read. Phase B's smoke check is the other caller that speaks to an OPERATOR, so it
  # reads the kind as well, and it warns where this refuses (§ env_lines_load).
  if [ "$ENV_LINES_READ_FAILED" = 1 ] && [ "$ENV_LINES_READ_FAILED_KIND" = 'scratch' ]; then
    refuse "$ENV_FILE could not be read: no scratch file could be opened for bash's read diagnostic" \
      "$ENV_LINES_READ_FAILED_WHY" \
      "THIS IS NOT A FINDING ABOUT $ENV_FILE. The file opened, the descriptor was closed again, and the" \
      "read was never made — so nothing here says that file is unreadable, damaged or short, and nothing" \
      "here is about the disk, the filesystem or the mount it sits on. What failed is a scratch file THIS" \
      "DEPLOY makes for itself, and the line above says at which step, because the fix is not the same" \
      "one: the directory \`mktemp\` writes into, where none could be created, and how many files this" \
      "deploy may have open at once, where one was created and could not be opened." \
      "NOTHING WAS READ. Without this refusal the file comes back as an EMPTY one and the deploy stops on" \
      "the first key A5 checks, naming a cause that is not the real one." \
      "No byte of its content was read, and none is printed here — it may carry a credential."
  fi
  if [ "$ENV_LINES_READ_FAILED" = 1 ]; then
    refuse "$ENV_FILE was opened but could not be read to its end" \
      "$ENV_LINES_READ_FAILED_WHY" \
      "The open SUCCEEDED, so this is neither a permission nor an ownership — those are what the two checks" \
      "above it and the refusal above this one are about, and all of them passed. WHICH fault it is, the" \
      "errno in bash's line says and this deploy does not guess past it: a read that fails on a file already" \
      "open is usually a disk or filesystem one — failing media, a filesystem remounted read-only or unmounted" \
      "under this host, a network mount that stopped answering — and \`dmesg\` and the mount this file sits on" \
      "are where that family is visible. An errno about the KIND of file instead, on a path A5 has already" \
      "tested with \`-f\`, says $ENV_FILE changed shape under this run." \
      "NOTHING THAT WAS READ IS USED. A file read partway is not certified here: every line below the point" \
      "it stopped is unread, and a partial read is indistinguishable from a shorter file once the bytes are" \
      "in hand — so the read is discarded whole rather than scanned. Without this refusal that partial read" \
      "comes back as an EMPTY file and the deploy stops on the first key A5 checks, naming a cause that is" \
      "not the real one." \
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
#     WHICH terminal it takes is decided by not_established (through git_read_unusable), from the
#     PHASE, rather than asserted in this comment. The assertion it replaces ("every caller is in
#     phase A") was true, and what kept it true was one `[ -z "$POST_CHECKOUT_SHA" ] &&` at the single
#     caller that runs on both sides of the window (fpm_code_reload_ready) — a guard these readers
#     cannot see, that reads like a phase-A optimisation, and whose removal would have made `refuse` a
#     one-way door: "Nothing was changed. The previous release is still serving." printed with the
#     checkout landed and the app down.

# git_read_call_site <var> — the line THE CALLER of these readers is on, into <var>. The frame depth
# is DERIVED rather than assumed, because git_read_unusable is reached at three different depths:
# straight from git_read_at (a mode refusal), through git_read_failed from git_read_at (a failed
# `git show`), and through git_read_failed from _git_ls_at (a failed `ls-tree` — the commonest of the
# three in the window, because presence is established before content is ever read). A fixed
# BASH_LINENO index is therefore right for ONE path and names THIS FILE for the other two: measured
# on the shape this replaces (bash 5.3.9, git 2.53.0), BASH_LINENO[1] gave the line INSIDE _git_ls_at
# that calls git_read_failed, and the one INSIDE git_read_at — so `failed_line:` in the marker pointed
# an operator recovering a down app at the primitive instead of at the precondition that was running.
# (The line NUMBERS of that measurement are on card#9608, not restated here, where every edit to this
# file would move them.) When one of these
# readers fails they are the innermost CONTIGUOUS frames, so the OUTERMOST of them is the frame the
# caller itself invoked and BASH_LINENO at that index is the caller's own line, at every depth. The
# family is named below rather than matched by prefix, so that a CALLER whose name happens to look
# like a reader's cannot be walked past; a reader added here and not named falls back to reporting
# its own call site — what the fixed index did — never some further caller's. The phase-reading exit
# and the scratch helpers below are in the family for the same reason: each is a frame BETWEEN the
# failure and the caller, and an unnamed one is where `failed_line:` would point (card#9816).
git_read_call_site() {
  local __i __outer=0
  for __i in "${!FUNCNAME[@]}"; do
    case "${FUNCNAME[__i]}" in
      git_read_call_site | not_established | git_read_unusable | git_read_failed | git_read_at | \
      git_ls_at | _git_ls_at | git_rev_read_failed | git_peel_mismatch | git_commit_of | git_ref_oid | \
      scratch_file | scratch_dir | _scratch)
        __outer="$__i" ;;
      *) break ;;
    esac
  done
  printf -v "$1" '%s' "${BASH_LINENO[__outer]:-0}"
}

# not_established <headline> <detail line…> — THE ONE phase-reading exit, for any failure that means a
# precondition could not be ESTABLISHED and that can happen on either side of the window: a read of
# the release out of git, a scratch file this script could not create (card#9816). Phase A REFUSES:
# nothing has been touched, and the refusal says exactly that. Phase B cannot say it — the window is
# open and the checkout has landed — so it takes the in-window failure path, which writes the marker
# and says the app is down and stays down; the step it names is the step in progress (FAILED_STEP)
# with the headline after it. POST_CHECKOUT_SHA is what phase B re-enters with, so no caller has to
# remember which side of the window it is on.
not_established() {
  local __line
  if [ -n "$POST_CHECKOUT_SHA" ]; then
    git_read_call_site __line
    printf '%s\n' "${@:2}" >&2
    FAILED_STEP="$FAILED_STEP — $1"
    # `false ||` so the banner reports a failing status, as it does for every other in-window failure
    # (in_window_failure reads `$?`) — which is also why the line is resolved into $__line ABOVE and
    # not in the argument: a command substitution there would run between the `false` and the call.
    false || in_window_failure "$__line"
  fi
  refuse "$@"
}

# git_read_unusable <headline> <detail line…> — the ONE exit the git readers below take: not_established,
# with the step named as the read of the release. It never returns, so naming the step unconditionally
# is safe on both sides of the window.
git_read_unusable() {
  FAILED_STEP="reading the release out of git"
  not_established "$@"
}

# scratch_file <var> <what it is for> · scratch_dir <var> <what it is for> — a new temporary file (or
# directory) into <var>, or not_established, NAMING THE SCRATCH FILE (card#9816). A bare `x="$(mktemp)"`
# failed two ways here, both measured with TMPDIR pointing at a directory that does not exist:
#   · where `set -e` applies (A13), it ended phase A with mktemp's status 1 — the code the exit table
#     says means REFUSED — with no ⛔ banner and no promise;
#   · inside a function called from an `if` or a `||` (git_ref_oid, git_commit_of — A7 and A8 call them
#     that way), `set -e` does not apply at all, so the run carried on with an EMPTY path and refused on
#     a cause nothing established: "'main' does not resolve to a commit on origin" for a ref that is
#     there, and "git could not resolve the tag …" for a tag git never got to peel.
# ⚠ A FULL filesystem is a DIFFERENT failure and is UNTESTED here: `mktemp` can SUCCEED on one, and
# what then fails is the WRITE of git's stderr into the file it made — which this exit never sees. The
# advice in the refusal below still names fullness — it is a thing to check on a host whose `mktemp`
# DID fail — but no fixture has produced it; the missing TMPDIR named above is what was measured.
# mktemp's own error is NOT silenced: it names the path it tried, which is the TMPDIR mktemp actually
# read (its ENVIRONMENT's, not necessarily this shell's — § env_lines_load).
#   · <var> is written with `printf -v`, never returned through `$(…)`: a refusal inside a command
#     substitution would exit only the subshell and the caller would carry on with an empty path. That
#     also keeps each call to the one fork mktemp itself costs.
#   · Its locals are `__sc_`-prefixed, for the shadowing hazard _git_ls_at states: git_ref_oid passes
#     `__ro_err` and git_commit_of `__err`.
# ⛔ NOT the .env loader's scratch file (env_read_err_open): the loader runs in both phases and inside
# bin/env-mirror-diff.mirror.sh, where neither `refuse` nor this exit is the right answer, so it answers
# for that failure itself.
scratch_file() { _scratch "$1" "$2" file; }
scratch_dir()  { _scratch "$1" "$2" directory -d; }
_scratch() { # _scratch <var> <what it is for> <file|directory> [mktemp option…]
  local __sc_var="$1" __sc_for="$2" __sc_kind="$3" __sc_out __sc_rc=0
  shift 3
  __sc_out="$(mktemp "$@")" || __sc_rc=$?
  [ "$__sc_rc" -eq 0 ] || not_established "no scratch $__sc_kind could be created for $__sc_for (\`mktemp\` exited $__sc_rc)" \
    "mktemp's own error is above this line and names the path it tried. mktemp writes under \$TMPDIR, or" \
    "/tmp when that is unset: check that whichever applies names a directory this deploy can write to," \
    "and that it is not full." \
    "Nothing was read in its place, so what it was for is not established — this is not a finding about" \
    "the release."
  printf -v "$__sc_var" '%s' "$__sc_out"
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
#     exit 1, so the deploy REFUSES at the fetch, by name, with git's error above the refusal
#     (card#9646 landed the fetch's own status reading; a fix for this belongs there).
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

# git_peel_mismatch <subject> <git's stderr> — THE ONE PLACE that tells a peel git ANSWERED apart
# from a read that failed, because git's own wording is the only thing that carries the difference.
#
# ⛔ A PEEL TO A TYPE THE OBJECT IS NOT IS NOT A FAILED READ (card#9611 r4). Every object behind the
# name was read; git is reporting what they ARE. Measured, git 2.53.0, on stores where `fsck` exits
# 0 — every object readable:
#   rev-parse --verify --quiet --end-of-options refs/remotes/origin/main^{blob} → 1, LOUD,
#     `error: …: expected blob type, but the object dereferences to tree type`  (`--ref main^{blob}`)
#   rev-parse --verify --end-of-options <annotated tag over a tree>^{commit}    → 128, LOUD,
#     `error: …: expected commit type, but the object dereferences to tree type` + `fatal: Needed a
#     single revision`                                                          (`--ref <that tag>`)
# Both reached git_rev_read_failed, whose fixed second line states that git's error "names what it
# could not read" — a failure that never happened, which is this card's own defect one branch in.
# THE DISCRIMINATOR IS THE MESSAGE AND NOT THE STATUS, which is why this is one function and not a
# clause in each caller: the same wording arrives at 1 from the --quiet call site and at 128 from the
# one without it, so a status rule would have to be re-derived per caller and would be wrong at the
# next one. It refuses and does not return when git said the objects dereference somewhere else, and
# returns 1 otherwise so the caller goes on to its own read-failure refusal.
git_peel_mismatch() {
  case "$2" in *"dereferences to"*) ;; *) return 1 ;; esac
  git_read_unusable "$1 does not name what it was asked to peel to" \
    "git's message above is not a failed read: every object behind $1 WAS read, and git is saying" \
    "what they are. The peel asked for a type they do not dereference to." \
    "Nothing here says anything about the state of this checkout's object store." \
    "This deploy checks out a commit. It will not check out, or reason about, anything else."
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
#                         so it is not that; what it met is what git named — AND WHAT IT NAMES IS
#                         NOT ALWAYS A FAILED READ (card#9611 r4): a peel to a type the object is
#                         not is loud here on a healthy store, and the branch says so by git's own
#                         wording rather than calling every loud 1 a read that failed.
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
  scratch_file __ro_err "git's error output while resolving '$__ro_cand'"
  # --end-of-options because the candidate can be `$REF` as the operator typed it: a leading `-` is
  # a ref name here and must not be read as an option. NO `^{commit}`: that peel is what drags the
  # object store into this question and collapses its failure onto status 1 (above).
  __ro_out="$(git_at rev-parse --verify --quiet --end-of-options "$__ro_cand" 2>"$__ro_err")" || __ro_rc=$?
  __ro_msg="$(cat "$__ro_err")"; rm -f "$__ro_err"
  [ -z "$__ro_msg" ] || printf '%s\n' "$__ro_msg" >&2
  if [ "$__ro_rc" -eq 0 ]; then printf -v "$__ro_var" '%s' "$__ro_out"; return 0; fi
  # ⛔ AND A LOUD ANSWER IS NOT ALWAYS A FAILED READ — THERE IS A THIRD SHAPE AT STATUS 1, AND EVERY
  # READ IN IT SUCCEEDED (card#9611 r4). `--ref 'main^{blob}'` reaches it on a COMPLETELY HEALTHY
  # store. It is asked here, ABOVE the status split rather than inside the status-1 branch, because
  # the discriminator is git's wording and not the status — git_peel_mismatch carries the
  # measurements and states why. The answer that MEANS absence (status 1, git SILENT) is settled
  # below and cannot reach git_peel_mismatch: it has no message to ask the question of.
  [ -z "$__ro_msg" ] || git_peel_mismatch "'$__ro_cand'" "$__ro_msg" || :
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
  local __var="$1" __cand="" __oid="" __peeled __type __rc=0 __err __msg
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
            #
            # ⛔ AND SAYING SO IN THE BODY WAS NOT ENOUGH — THE WRAPPER'S OWN FIXED LINE CLAIMED THE
            # FAILED READ THAT HAD NOT HAPPENED (card#9611 r4, the sibling of the same defect at
            # git_ref_oid). git_rev_read_failed always states that git's error "names what it could
            # not read", so an annotated tag over a TREE refused under that line on a store where
            # `fsck` exits 0 — measured, git 2.53.0: 128, `error: <oid>^{commit}: expected commit
            # type, but the object dereferences to tree type`, `fatal: Needed a single revision`.
            # git's stderr is captured and re-printed here, as git_ref_oid does, because the
            # discriminator IS that message and this call site used to let it go straight past.
            __rc=0
            scratch_file __err "git's error output while peeling the tag '$__cand' ($__oid)"
            __peeled="$(git_at rev-parse --verify --end-of-options "$__oid^{commit}" 2>"$__err")" || __rc=$?
            __msg="$(cat "$__err")"; rm -f "$__err"
            [ -z "$__msg" ] || printf '%s\n' "$__msg" >&2
            [ "$__rc" -eq 0 ] || git_peel_mismatch "the tag '$__cand' ($__oid)" "$__msg" || :
            [ "$__rc" -eq 0 ] || git_rev_read_failed "resolve the tag '$__cand' ($__oid) to a commit" \
              "rev-parse --verify" "$__rc" \
              "A tag object was read at that name; what it dereferences to did not come back as a" \
              "commit, and git's message above is a read that failed — a tag that simply peels to" \
              "something else is refused above this line, by what git called it."
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
#
# ⛔ AN OPERAND IT CANNOT COMPARE IS REFUSED, NEVER ANSWERED (card#9984). It used to fall through to
# zeros: an operand that is not a version left every field 0, 0 is neither greater nor less than 0
# at any field, and the function returned 0 — "A is at least B". MEASURED, by the cases in
# bin/deploy.selftest.sh that drive this predicate: with the body at `9c4d67f` restored verbatim,
# a floor operand that is EMPTY and one reading `banana` each answered MEETS. Nothing died on the way,
# because every call site invokes it where `set -e` does not apply (left of a `||`, or inside an
# `if`), so A1 certified a `BASH_FLOOR` it had not read and the deploy carried on with no bash floor
# enforced at all — a check that cannot fail on the population it guards (canon #9), failing OPEN,
# and the asymmetry is that A6b has always refused exactly that value in the TARGET release's
# declaration while this copy's own went unread.
# A comparison that could not be performed is not a comparison that passed, and there is no third
# RETURN VALUE a caller could read: every call site is `… || refuse` or `if ! …`, so a non-zero
# status means "below the floor" there and the NEXT caller added would inherit that same two-valued
# reading. The answer therefore does not come back at all — it exits through `refuse`, by name, the
# way A1c refuses an `npm --version` that is not a version rather than reading it as a version of
# nothing. `refuse`, not `not_established`: every caller runs only from `phase_a` (A3b's note states
# that rule and why).
# ⇒ THIS PREDICATE IS NOT TOTAL. A future caller that wants a soft answer for a string that may not
# be a version establishes that itself first, as A1c, A6 and A6b each already do for their floors.
#
# ⛔ AND NO HERE-STRING, WHICH IS WHAT THE SPLIT BELOW IS FOR. Both operands used to be read with
# `IFS=. read -r -a a <<< "$1"`, and a here-string is a TEMPORARY FILE on every bash below 5.1 —
# which is above the BASH_FLOOR declared at the top of this file, so on a SUPPORTED host that is
# what it is (bash 4.4 `redir.c`, `r_reading_string` → `here_document_to_fd` →
# `sh_mktmpfd("sh-thd", MT_USERANDOM|MT_USETMPDIR, …)`; read in the 4.4 release tarball,
# 2026-09-20). A read that fails there leaves the array empty, which is the fall-through above,
# reached with no bad input at all.
# ⚠ WHAT IT TAKES TO MAKE THAT READ FAIL IS NARROWER THAN IT LOOKS, and it is written down here
# because the obvious fixture does NOT produce it: bash validates `$TMPDIR` with `stat` + `W_OK` and
# falls back to `/tmp`, `/var/tmp`, `/usr/tmp` and then `.` (4.4 `lib/sh/tmpfile.c`, `get_tmpdir` →
# `file_iswdir`). MEASURED on bash 4.4 built from the GNU tarball: with `TMPDIR=/nonexistent` and a
# working directory this user cannot write to, the here-string still SUCCEEDS — it lands in `/tmp`,
# which on that host was writable. So the failing host is one where no directory in that chain is
# usable at all — a full `/tmp`, or a locked-down account — which no fixture here can produce
# without root. ⇒ The construct is removed rather than tested around, and what IS tested is the
# property above: an operand this function did not read is never answered as a floor that was met,
# whatever left it unread.
#   · The replacement is PARAMETER EXPANSION, which is not a redirection at all: no temporary file,
#     no pipe, no fork and no subshell, so there is nothing left for a temp directory to break and
#     the split stays in this function's own scope. Nothing in it is newer than bash 2.
#   · NOT a pipe into `read` (`printf … | IFS=. read -r -a a`): the read would run in a SUBSHELL and
#     the fields would never reach the caller — the same silently-empty answer by another route.
#   · NOT process substitution (`< <(printf …)`): where `/dev/fd` is unavailable bash falls back to
#     a NAMED FIFO under `$TMPDIR`, which is this same hazard on the hosts least likely to be tested.
#   · NOT an unquoted expansion split on IFS (`set -- $1`): that re-opens globbing on the operand.
ver_ge() {
  local a="$1" b="$2" bad="" i x y
  # `$bad` carries TEXT, not the operand, so an operand that is itself EMPTY still trips the test
  # below — which is the case that matters: an unread operand is the empty one.
  case "$a" in [0-9]*) ;; *) bad="the first, '$a'" ;; esac
  case "$b" in [0-9]*) ;; *) bad="${bad:+$bad; }the second, '$b'" ;; esac
  # `"${FUNCNAME[*]}"` is NOT in the empty-array class the top of this file warns about: it is
  # expanded inside a function, where FUNCNAME always holds at least this frame.
  [ -z "$bad" ] || refuse \
    "a version comparison this deploy cannot perform: '$1' against '$2'" \
    "\`ver_ge\` compares dotted versions numerically, field by field, and an operand that does not" \
    "begin with a digit is not one it can read." \
    "NOT A VERSION: $bad" \
    "It does NOT read such an operand as 0 and does NOT fall through to \"at least\": every caller" \
    "of this predicate is a FLOOR gate, and a false answer there reads as \"the floor is met\"," \
    "which lets a host the gate exists to refuse deploy anyway." \
    "" \
    "Called through: ${FUNCNAME[*]}." \
    "" \
    "The operands are this host's own versions (\`\$BASH_VERSINFO\`, \`php -r 'echo PHP_VERSION;'\`," \
    "\`npm --version\`) and a floor declared by a release (\`BASH_FLOOR=\` at the top of" \
    "bin/deploy.sh, \`require.php\` in server/composer.json, \`lockfileVersion\` in" \
    "server/package-lock.json). Whichever of the two above is not a version is what to fix."
  # The fields, consumed from the front — as many as the loop below names, which is the same
  # depth the indexed read it replaces compared. A field an operand does not have leaves the
  # remainder empty, which reads as 0 below, exactly as the old `${a[i]:-0}` did.
  for i in 1 2 3; do
    x="${a%%.*}"; y="${b%%.*}"
    case "$a" in *.*) a="${a#*.}" ;; *) a="" ;; esac
    case "$b" in *.*) b="${b#*.}" ;; *) b="" ;; esac
    x="${x%%[!0-9]*}"; y="${y%%[!0-9]*}"
    x="${x:-0}"; y="${y:-0}"
    [ "$((10#$x))" -gt "$((10#$y))" ] && return 0
    [ "$((10#$x))" -lt "$((10#$y))" ] && return 1
  done
  return 0
}

# ── the bash and npm floors (card#9616) ───────────────────────────────────────────────────────

# bash_meets_floor <major.minor> <floor> — THE comparison A1 and A6b both make, and a predicate of
# its own for one reason: `BASH_VERSINFO` cannot be faked inside a running bash, so a selftest
# cannot drive A1 by lying about the host. It drives THIS with the versions either side of the
# floor, and drives the two gates end to end by moving the FLOOR instead.
# ⚠ IT INHERITS `ver_ge`'s THIRD ANSWER (card#9984) and is therefore not two-valued either: an
# operand that is not a version ends the run through `refuse` rather than returning a verdict, so
# `bash_meets_floor … || refuse` below cannot report "below the floor" for a floor it never read.
bash_meets_floor() { ver_ge "$1" "$2"; }

# bash_floor_declared — the BASH_FLOOR a copy of this script declares, read from its TEXT on stdin:
# the first line beginning `BASH_FLOOR=`, with quotes and any trailing comment removed. It prints
# NOTHING when there is no such line, which is every release cut before card#9616.
# ⛔ ONE READER, on purpose: A6b reads the TARGET release with this and deploy-selftest.yml reads
# THIS file with it, so CI cannot measure a floor A6b would not see. A second pattern in the
# workflow is the restatement that drifts.
bash_floor_declared() {
  awk '/^BASH_FLOOR=/ && !seen { v = substr($0, 12); sub(/[ \t]*#.*$/, "", v); gsub(/["\047]/, "", v)
                                 sub(/[ \t]+$/, "", v); print v; seen = 1 }'
}

# bash_floor_is_version <value> — true when <value> is a BASH_FLOOR a floor gate may act on:
# `<digits>.<digits>`, exactly two fields, nothing else. A PREDICATE only — it prints nothing and
# refuses nothing, because each caller speaks about a DIFFERENT copy of the declaration (this
# script's, the release's, the tree CI is measuring) and owes its own words.
#
# ⛔ ONE TEST, for the same reason `bash_floor_declared` above is one reader (card#9984). A1, A6b
# and `.github/workflows/deploy-selftest.yml`'s floor step each held this value to a test of their
# own, and A1's was the weakest — `ver_ge`'s leading-digit check, which is right for THAT predicate
# (A6 hands it three-field PHP versions and A12 a bare `7`) and far too loose for a bash floor.
# MEASURED by DELETING A1's `bash_floor_is_version "$BASH_FLOOR" || refuse` guard, which puts a
# tree back in the state that guard fixed. Library-mode, host bash 4.0, against a serving copy
# whose floor line was meant to read `4.4`: `4,4`, `4.x`, `4-4`, `4x`, `4` and `"4 4"` each
# begin with a digit, so the
# comparison RAN, truncated the floor at the first non-digit, read it as 4.0.0 and answered MEETS —
# bash 4.0 deploying past a 4.4 floor. A mistyped separator is the likelier typo than `banana`, and
# a partly-read floor passing is the same defect as an unread one passing.
# (The last of those is only a DECLARATION when quoted: `BASH_FLOOR=4 4` unquoted is an assignment
# followed by the command `4`, so that copy dies at 127 before `main` and no gate ever sees it.)
#
# ⛔ IT IS STRICTER THAN THE `[0-9]*.[0-9]*` GLOB IT REPLACES IN A6b, deliberately. That glob also
# admitted `4.4x` and `4.x.5`, and the second is not the harmless case it looks: MEASURED, a release
# declaring `BASH_FLOOR=4.x.5` was enforced as the floor 4.0.5 — a floor nobody wrote, met by a host
# bash 4.4 — so the gate was not failing closed, it was silently substituting a floor for the one
# declared. Which way that substitution errs is unknowable, which is exactly why it is refused
# rather than guessed at: A6 refuses a PHP constraint it cannot evaluate in the same direction, and
# for the same reason. A three-field value is refused here too, so it cannot reach `ver_ge` and be
# compared with a patch field that `<major>.<minor>` does not have.
bash_floor_is_version() {
  case "$1" in
    *[!0-9.]* | *.*.* | .* | *.) return 1 ;;  # a non-digit, a third field, an empty field at an end
    *.*)                         return 0 ;;  # …leaving exactly <digits>.<digits>
    *)                           return 1 ;;  # no separator at all — a bare major is not a floor
  esac
}

# ver_is_comparable <value> — true when EVERY dot-separated field of <value> begins with a digit,
# and there are two or more of them. That is the rule, stated as the code has it.
#
# ⚠ IT IS STRICTER THAN `ver_ge` NEEDS, AND THE SURPLUS IS DELIBERATE (card#9984 r5). `ver_ge`
# reads three fields, so a fourth that does not begin with a digit could not have been misread —
# it is never read at all. This predicate refuses it anyway. Drive it to see: `1.0.0-alpha.beta`
# and `9.2.0+build.abc` are legal semver, are REFUSED here, and `ver_ge` would have compared
# them as `1.0.0` and `9.2.0`, exactly as written. The cases in bin/deploy.selftest.sh pin both.
#   · Refusing them costs nothing that is real: npm's prerelease convention is `-<tag>.<number>`,
#     so `9.2.0-pre.1` and `7.0.0-beta.0` have a digit-led fourth field and ARE accepted. This
#     card's independent verification swept npm's published versions from the registry and
#     found none that A1c's old glob accepted and this predicate refuses — not measured here.
#   · It fails CLOSED, in phase A, with the banner and the offending string — a legible refusal an
#     operator can act on, never a silent misread.
#   · And the alternative is worse: stopping the walk at the third field would hard-code `ver_ge`'s
#     depth in a SECOND place, so a later change to that depth would make this predicate wrong in
#     the PERMISSIVE direction — the exact failure class this card exists to close. Being stricter
#     than necessary degrades safely; being coupled to a constant elsewhere does not.
# ⇒ DO NOT "fix" the mismatch by loosening this to the first three fields. If a real tool is ever
# refused here, the fix is a case naming that tool's output, decided deliberately.
#
# ⛔ THE OTHER PREDICATE, AND NOT A LOOSER SPELLING OF THE ONE ABOVE (card#9984 r4).
# They answer different questions and the difference is deliberate:
#   · `bash_floor_is_version` — what a BASH_FLOOR DECLARATION may be. Exactly two fields, all
#     digits, no suffix, because that is the shape this file's header declares and the shape A1,
#     A6b and the `bash-floor` job all compare.
#   · `ver_is_comparable`     — what a HOST VERSION reported by a tool may be. Two fields or more,
#     and a non-numeric SUFFIX on a field is fine.
# A suffix is fine because `ver_ge` truncating it is DOCUMENTED and deliberate — `8.5.0RC1` is
# treated as its release — and MEASURED to be the permissive direction that matters here: a real
# prerelease npm prints one, and holding A1c to "all fields numeric" would refuse a host that is
# perfectly able to install the lockfile. A field that STARTS with a non-digit is the opposite
# case: among the three `ver_ge` reads, it becomes a 0 nobody reported — which is the defect —
# and beyond them it is refused by the surplus strictness stated above.
# MEASURED by restoring A1c's old `[0-9]*.[0-9]*` glob in place of the call it now makes:
# `9.x.5` passed it and `ver_ge` read it as 9.0.5; `6.x.9` passed and read as 6.0.9. Both are
# the partly-parseable shape this card refuses everywhere else — a version read as far as it
# parses and then acted on.
# ⚠ THE VERDICT DID NOT CHANGE at the floors A12 can currently produce, because its
# `lockfileVersion` case yields the bare major `7` or nothing, so only the FIRST field decides and
# the glob already guaranteed that one is a digit. That is luck, not design: it holds only while no
# floor here has a minor, nothing re-checks it, and the next floor with one would make the
# substitution decide a gate. Closed at the predicate rather than left as a note that decays.
# ⛔ NOT USED BY A6, deliberately: it is handed `${HOST_PHP_VERSION:-0}`, whose `0` sentinel is a
# single field and means "php could not be read", which `ver_ge` already refuses to compare.
ver_is_comparable() {
  local v="$1" f
  case "$v" in *.*) ;; *) return 1 ;; esac   # a single field is not a host version here
  while :; do
    f="${v%%.*}"
    case "$f" in [0-9]*) ;; *) return 1 ;; esac
    case "$v" in *.*) v="${v#*.}" ;; *) return 0 ;; esac
  done
}

# npm_lockfile_version — the TOP-LEVEL `lockfileVersion` of a package-lock.json on stdin, or nothing
# if it has none. npm writes that key once, at the top level, and in no package entry, so the first
# match is the file's. It reads to the END rather than exiting at the first hit: exiting early makes
# a writer feeding it a large lockfile through a pipe take a SIGPIPE, which under `pipefail` is a
# failure of the caller's whole pipeline.
npm_lockfile_version() {
  awk '!seen && match($0, /"lockfileVersion"[ \t]*:[ \t]*[^,} \t]+/) {
         v = substr($0, RSTART, RLENGTH); sub(/^"lockfileVersion"[ \t]*:[ \t]*/, "", v); seen = 1 }
       END { printf "%s", v }'
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
# ⚠ THE HERE-STRING SHAPE card#9984 REMOVED FROM `ver_ge` SURVIVES HERE, AND IS NAMED RATHER THAN
# FIXED (canon #7 — the sibling audit owes the NAME; whether it earns work is #18's question, and
# the answer below is why this round did not take it).
# ⛔ THE POPULATION IS "EVERY HERE-STRING `fpm_code_reload_ready` REACHES" — stated that way, and
# DERIVED, because the top of this file records hand audits of exactly this question having been
# wrong before, in both directions:
#     start at fpm_code_reload_ready, follow calls to functions defined in this file
#     transitively, and report every `<<<` in the reached set
# Run that (2026-09-20) and it reaches `phpinfo_value`, `pool_ini` and `ini_file_value`, plus
# inline sites in `fpm_code_reload_ready` itself and in `stream_pool_ready` — wider than the two
# functions the eye lands on. No figure is written here: re-derive it, the sites move.
# The ruling below is uniform over all of them, so the wider population does not change it —
# which is why the population had to be derived rather than guessed, not a reason to skip it.
#   · Every one splits with `<<<`, a TEMPORARY FILE on every bash below 5.1 and so on a supported
#     host (the citations are at `ver_ge`). A failed read leaves the substitution EMPTY.
#   · Empty is read PERMISSIVELY downstream: `fpm_judge`'s first line is
#     `if ! ini_on "${2:-0}"; then return 0; fi`, and an empty `opcache.enable` therefore means
#     "opcache is off, every request reads the disk, this deploy needs no reload" — the same
#     fail-open direction, on a host whose real opcache may be on with timestamps off.
#   · And `set -e` is off for the whole dynamic extent: `fpm_code_reload_ready` is invoked left of
#     a `||` at both of its call sites, exactly as `bash_meets_floor` was.
# ⇒ SO THE SHAPE IS THE SAME. What differs, and is why it is not the same defect: the FIRST
# here-string `fpm_code_reload_ready` reaches is the `Server API` read, and an empty answer there
# is `"" != "FPM/FastCGI"`, which REFUSES. A host condition that breaks here-strings at all breaks
# that one too, so it refuses before any opcache value is read. Reaching the permissive read needs
# a failure that begins PARTWAY THROUGH the function — a filesystem that fills between the two —
# which is narrower still than the condition `ver_ge`'s header already calls unreproducible here
# without root. No fixture can produce it, so no case is shipped for it and none is claimed.
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
  #
  # ⛔ AND FIRST, THE BASH RUNNING IT (card#9616). Below BASH_FLOOR — declared at the top of this
  # file, which says how the number was measured — this script does not refuse: it DIES, partway
  # through, on its own constructs, and the first such death is at A7's refusal, which arrives as a
  # bare exit 1 with neither the ⛔ banner nor the "Nothing was changed" promise. That is the exit
  # code the table above reserves for "refused, nothing was touched", reached by a death. So the
  # bash is refused here, by name, before anything else this script does can depend on a newer one.
  #
  # ⚠ `set -e` DOES NOT APPLY TO THE LEFT OF THE `||` BELOW, which is why the comparison must be
  # incapable of answering wrongly rather than merely incapable of lying (card#9984): a failure
  # inside `bash_meets_floor` there does not end the run, it just looks like a verdict.
  #
  # ⛔ SO THE FLOOR IS ESTABLISHED TO BE A VERSION FIRST, HERE, AND NOT BY THE COMPARISON.
  # `ver_ge` refuses an operand it cannot read AT ALL, which is the right test for a predicate A6
  # hands three-field PHP versions and A12 a bare `7` — and it is far too loose for this one: it
  # passes `4,4` and `4.x`, which begin with a digit, and the floor then silently becomes 4.0.
  # `ver_ge`'s own header says that predicate is not total and that a caller wanting a stricter
  # reading establishes its operand itself. A1 is such a caller, and this is where it does it,
  # through the SAME `bash_floor_is_version` A6b holds the release's declaration to — so the two
  # gates cannot disagree about what a floor is. Only then are there two things left that reach the
  # comparison: the floor being MET and the floor being MISSED.
  # ⛔ AND THE LINE BEING GONE IS REFUSED AS ITSELF, FIRST — which until card#9984 r2 was the one
  # shape in this class that still reached the operator as a DEATH. MEASURED at `9c4d67f`, and
  # reproducible here by deleting this guard: remove the `BASH_FLOOR=` line from a copy and the
  # first expansion of `$BASH_FLOOR` dies under `set -u` with `BASH_FLOOR: unbound variable`,
  # exit 1, NO ⛔ banner and NO "Nothing was changed" promise — the exit code the table at the top
  # of this file reserves for *refused, nothing was touched*, reached by a death, which is the
  # exact confusion `refuse` exists to prevent. `${BASH_FLOOR-}` is what lets it be answered
  # instead, and an empty declaration (`BASH_FLOOR=`) takes the same refusal: the remedy for both
  # is to write the line, so they are not split the way A6b splits absent from empty.
  # ⚠ NOT SYMMETRIC WITH A6b, deliberately. A RELEASE that declares no floor is the survivable
  # predates-card#9616 path, because it could not have declared one. THIS copy is the script
  # running now, its own header calls that line its one home, and a copy of it with the line
  # removed is edited, not old.
  [ -n "${BASH_FLOOR-}" ] || refuse \
    "this copy of bin/deploy.sh declares no BASH_FLOOR" \
    "That line is the oldest bash this script is known to run on, and A1 holds the bash running" \
    "this deploy to it. Without it there is no floor to enforce, and this refusal is deliberately" \
    "not the silence a missing declaration used to produce." \
    "" \
    "Restore it at the top of this file — \`BASH_FLOOR=<major>.<minor>\`, alone on its line, at" \
    "column 0 — and read the comment above it, which says how the number is arrived at. A release" \
    "that declares none is a different matter and still deploys: it predates card#9616 (A6b)."
  bash_floor_is_version "$BASH_FLOOR" || refuse \
    "this copy of bin/deploy.sh declares BASH_FLOOR='$BASH_FLOOR', which is not a version" \
    "BASH_FLOOR is the oldest bash this script is known to run on, and A1 holds the bash running" \
    "this deploy to it. A declaration this cannot read is not a floor, and it is refused rather" \
    "than read as far as it parses: a value like '4,4' or '4.x' would otherwise be taken as 4.0," \
    "which is a floor nobody wrote and which almost any bash meets." \
    "" \
    "The line is \`BASH_FLOOR=<major>.<minor>\`, alone on its line, at column 0, unquoted or" \
    "quoted — exactly two numeric fields. The comment at the top of this file says how the number" \
    "is arrived at, and that it moves by re-running the measurement rather than by being retyped." \
    "A6b holds the release being deployed to this same test, so nothing was read about it either."
  bash_meets_floor "$HOST_BASH_VERSION" "$BASH_FLOOR" || refuse \
    "bash $BASH_VERSION is below this script's floor, BASH_FLOOR=$BASH_FLOOR" \
    "bin/deploy.sh is MEASURED to pass its own selftest on bash $BASH_FLOOR and to FAIL it on the" \
    "minor below (.github/workflows/deploy-selftest.yml runs both on every PR, and the top of this" \
    "file records what each run answered). On an older bash it dies on its own constructs partway" \
    "through, and phase B's are inside the maintenance window, with the app down." \
    "" \
    "Run this deploy with bash $BASH_FLOOR or later. Whichever interpreter you run it with is the one" \
    "measured here, the one the re-exec hands the maintenance window to, and the one A13 reads the" \
    "release's bin/supervision.sh under — every bash this deploy starts. So there is one bash to fix," \
    "and the \`bash\` that happens to be first on PATH is not consulted by any of the three."
  local missing=()
  for c in git php composer npm curl crontab flock fuser setsid ps cgi-fcgi timeout; do
    command -v "$c" >/dev/null 2>&1 || missing+=("$c")
  done
  [ ${#missing[@]} -eq 0 ] || refuse "missing required command(s): ${missing[*]}"

  # A1c — npm's VERSION, read here beside npm's presence and handed to A12, which holds it to the
  # floor the RELEASE's lockfile implies. Read here so that gate stays host-free (§ PHASE A's
  # TARGET-TREE GATES), exactly as A6 is handed $HOST_PHP_VERSION.
  # ⛔ AND ITS STATUS IS READ (card#9646): an npm that cannot answer `--version` is refused as THAT,
  # never read as a version of nothing — and what it printed has to BE a version, or there is
  # nothing for A12 to compare and a `[ -z … ]` on it would certify an npm it never asked.
  local npm_version="" npm_rc=0
  npm_version="$(npm --version)" || npm_rc=$?
  [ "$npm_rc" -eq 0 ] || refuse "\`npm --version\` exited $npm_rc" \
    "What npm printed is above this refusal. Which npm this host runs is NOT established, so" \
    "whether it can install the release's lockfile (A12) is not established either — and \`npm ci\`" \
    "runs in phase B, inside the maintenance window, with the app already down."
  # ⛔ `ver_is_comparable`, NOT AN INLINE GLOB (card#9984 r4). This was the FOURTH copy of
  # `[0-9]*.[0-9]*`, left behind when r2 consolidated the other three, and it admitted the same
  # partly-parseable shape the rest of this card refuses: `9.x.5` passed it and A12 then compared
  # it as 9.0.5 — restore the glob here to measure that. The predicate is the HOST-VERSION one,
  # not the bash-floor one: a floor is exactly two numeric fields, while an npm may legitimately
  # print a prerelease suffix `ver_ge` truncates on purpose. Its header states the difference.
  ver_is_comparable "$npm_version" || refuse \
    "\`npm --version\` printed '$npm_version', which is not a version" \
    "A12 compares this host's npm against the floor the release's lockfile implies. It will not" \
    "make that comparison against something it cannot read as a version, nor against one it can" \
    "read only as far as it parses — which would compare a number this host never reported."

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
  #
  # ⛔ ONE CAUSE WAS ASSERTED FOR A STATUS THAT CARRIES SEVERAL (card#9646). `rev-parse --git-dir`
  # exits 128 for every way it cannot open a repository, and `2>&1 >/dev/null || refuse "… is not a
  # git checkout"` threw git's own message away and named the one cause the operator is LEAST likely
  # to be in: a prod checkout that has been deploying for months does not stop being a git checkout.
  # Measured, git 2.53.0, all 128 with the message dropped: `detected dubious ownership` (a checkout
  # restored from backup, rsynced, or chowned — git prints the exact repair line itself), `bad config
  # line 1 in file .git/config`, and a file under `.git` it could not read. An operator told "not a
  # git checkout" about any of those goes looking for a checkout that is right there.
  #
  # THE DISCRIMINATOR IS GIT'S OWN WORDING, not the status — the status is 128 for all of them. git
  # says `not a git repository` in those words for the four shapes that really are not a checkout
  # (a plain directory; `.git` at mode 000, which git discovers PAST rather than fails on; a `.git`
  # file pointing nowhere; a `.git` directory that is not a repository), and `cannot change to '…':
  # Not a directory` for a root that is a file. Anything else lands in the generic branch — which is
  # honest about an unrecognised wording rather than false about it. `LC_ALL=C` is pinned on this
  # call so the wording is the one measured; it is pinned on the NEW call only (card#9646 § 5 S9).
  # git's message is PRINTED before either refusal, because it is what names the cause.
  #
  # ⚠ AND NONE OF THE THREE REFUSALS card#9646 ADDS (here, A4, A7) SAYS "git's error is above this
  # refusal". They say what git PRINTED is above it, which is true whether or not git printed
  # anything. A fixed line asserting a message git may not have given is card#9611 r3's own defect
  # — git_ref_oid's `∉ {0,1}, git SILENT` answer is exactly that state, met on a healthy host — and
  # a silent non-zero from these three commands is not something this suite can produce, so a
  # branch on it would be a check that cannot fail (canon #9) guarding a state nothing establishes
  # is reachable (canon #6). The wording carries the uncertainty instead, and costs nothing.
  local repo_msg="" repo_rc=0
  repo_msg="$(LC_ALL=C git_at rev-parse --git-dir 2>&1 >/dev/null)" || repo_rc=$?
  if [ "$repo_rc" -ne 0 ]; then
    [ -z "$repo_msg" ] || printf '%s\n' "$repo_msg" >&2
    case "$repo_msg" in
      *"not a git repository"* | *"cannot change to"*)
        refuse "$DEPLOY_ROOT is not a git checkout" \
          "git's message above says what it found there instead." ;;
      *)
        refuse "git could not open $DEPLOY_ROOT as a repository (\`git rev-parse --git-dir\` exited $repo_rc)" \
          "What git printed is above this refusal. That, and the status, are the whole of what was" \
          "established, and this deploy does not guess past them." \
          "IT IS NOT \"not a git checkout\" — git says that in those words, and that answer has its" \
          "own refusal. What reaches here is a checkout git can see and could not OPEN. Measured," \
          "git 2.53.0:" \
          "  · \`detected dubious ownership in repository at …\` — the checkout is owned by another" \
          "    user, which is what a restore from backup, an rsync or a chown leaves behind. git" \
          "    prints the \`git config --global --add safe.directory <path>\` line for it itself;" \
          "    chowning the checkout to the deploy user is the other fix, and the better one on a" \
          "    host where this user is the only one that should be writing here." \
          "  · a \`.git/config\` git cannot parse — \`bad config line N in file …\`." \
          "  · a file under \`.git\` it could not read." \
          "Nothing was read out of the checkout, so nothing here says anything about the release or" \
          "about what is being served." ;;
    esac
  fi
  [ -f "$APP_DIR/artisan" ] || refuse "$APP_DIR/artisan not found — MEZZ_DEPLOY_ROOT is not a Mezzanine checkout"

  # A3b — this host's git accepts `:(literal)` PATHSPEC MAGIC (card#9616). Every read this deploy
  # makes of the release out of the object database goes through _git_ls_at, which passes its path
  # as `:(literal)<path>`. A git that does not know the magic fails, or mis-answers, EVERY one of
  # them — and the first would arrive as "git could not read server/composer.json at <sha>", a
  # statement about the RELEASE that is not the real cause. One probe here names the cause once.
  #
  # ⛔ PROBED, NOT PARSED. `git --version` is a claim about which build does what, and a distro
  # backport makes that claim wrong in both directions; the probe asks THIS build. So no version
  # number is compared here and none is declared. (The history is settled and recorded on card#9616
  # — `:(literal)` entered git at 5c6933d201fab183a9779dca0fe43bf2f1eca098, first stable tag
  # v1.8.5 — and it is history, not what decides this line.)
  #
  # AFTER A3, NEVER BEFORE IT. A3 has established that git can OPEN this repository; without that,
  # a probe that failed would be read as "this git lacks the magic" when the real cause is a
  # checkout git cannot open at all. And the PLAIN form runs first, for the same reason one step
  # further in: it establishes that this git can list HEAD's tree, so that a failure of the MAGIC
  # form differs from it by exactly one thing — the magic.
  #
  # ⚠ AND IT EXITS THROUGH `refuse`, NOT `not_established` (card#9816), which is a deliberate call
  # and not an oversight: `not_established` exists for a precondition that can fail on EITHER side
  # of the maintenance window, and reads POST_CHECKOUT_SHA to pick its terminal. Every gate this
  # card adds — this one, A1's bash floor, A1c's npm read, A6b and A12's comparison — runs only
  # from `phase_a`, which `main` calls only when POST_CHECKOUT_SHA is empty. Routing them through
  # it would buy nothing and would tell the next reader they are reachable in-window, which they
  # are not. A6 and A10–A13 refuse the same way for the same reason.
  #
  # ⭐ BOTH OF A MAGIC-LESS GIT'S ANSWERS ARE REFUSED, and the second is why the probe asserts the
  # OUTPUT and not the status: such a git can also exit 0 having listed NOTHING, taking the whole
  # string `:(literal)VERSION` for a literal path that is not in the tree. That is status 0 with an
  # empty answer — indistinguishable, to every reader downstream, from "the release does not carry
  # this path". So what is required is the exact line, not the exit code.
  local probe="" probe_rc=0
  probe="$(git_at ls-tree --name-only HEAD -- VERSION)" || probe_rc=$?
  [ "$probe_rc" -eq 0 ] || refuse \
    "git could not list HEAD's tree in $DEPLOY_ROOT (\`git ls-tree HEAD -- VERSION\` exited $probe_rc)" \
    "What git printed is above this refusal. A3 passed — git opened the repository — and what" \
    "failed is the read of a TREE, which is what every precondition that judges the release makes." \
    "Until that works, whether this git accepts the pathspec those reads use (\`:(literal)\`) cannot" \
    "be probed either, so this is not a statement about the release or about git's pathspec support."
  [ "$probe" = VERSION ] || refuse \
    "HEAD's tree in $DEPLOY_ROOT does not list VERSION (git printed '$probe')" \
    "A Mezzanine checkout carries VERSION at its root. This precondition probes git's pathspec" \
    "magic against that path, and a path that is not there cannot tell a git which accepts the" \
    "magic from one which does not — both would print nothing."
  probe_rc=0
  probe="$(git_at ls-tree --name-only HEAD -- ':(literal)VERSION')" || probe_rc=$?
  if [ "$probe_rc" -ne 0 ] || [ "$probe" != VERSION ]; then
    refuse "this host's git does not accept \`:(literal)\` pathspec magic" \
      "\`git ls-tree HEAD -- VERSION\` listed VERSION on this same checkout, one line ago." \
      "\`git ls-tree HEAD -- ':(literal)VERSION'\` exited $probe_rc and printed '$probe', where it" \
      "must print exactly VERSION. What git printed on stderr, if anything, is above this refusal." \
      "Every read this deploy makes of the release out of git passes its path that way (_git_ls_at)," \
      "so on this git each of them would fail, or list nothing and be read as a release that does" \
      "not carry the file. Upgrade git on this host: \`:(literal)\` has been in git since v1.8.5." \
      "" \
      "$(git --version 2>/dev/null || echo 'git --version printed nothing')"
  fi

  # A3c — MEZZ_REMOTE NAMES A REMOTE OF THIS CHECKOUT (card#9832). `git fetch` takes a URL as
  # happily as a name, and a URL can carry a credential — so `MEZZ_REMOTE=https://user:token@host/…`
  # is a configuration git accepts and an operator deploying a private checkout may reasonably
  # reach for, while the header above documents the variable as a NAME. Every later mention of
  # `$REMOTE` then puts that credential on the operator's screen and in this deploy's log: A7's
  # step line prints it before anything can fail, and A7's and A8's refusals print it — measured
  # against the tree before this gate, one refused run put the whole URL on screen five times over,
  # in A7's step line and in four lines of the fetch refusal that followed it. ⇒ Per canon #20 the
  # question is not which file was read but whether a secret VALUE can reach an output stream, and
  # through a supported configuration it can.
  #
  # ⛔ AND GIT'S OWN REDACTION IS NOT A BACKSTOP: it is PER-TRANSPORT, and it is not this script's
  # to rely on. Measured, git 2.53.0, fetching a URL that carries a fake credential —
  #     https://…  `fatal: unable to access 'https://host/o/r.git/': …`  — the credential STRIPPED
  #     git://…    `fatal: unable to look up user:secret@host:1 …`       — VERBATIM
  #     a path     `fatal: '/no/such/path' does not appear to be a …`    — VERBATIM
  # So for one transport git redacts and for another it does not, and either way its message is on
  # the operator's screen before this script sees it. ⇒ REDACTION IS THE WEAKER HALF AND IS NOT THE
  # FIX: the value is refused HERE instead, before the first line that could carry it.
  #
  # ⛔ BY MEMBERSHIP IN `git remote`, NEVER BY PATTERN-MATCHING THE STRING FOR `://` OR `@`. A
  # pattern is a guess about what a URL looks like, and it is wrong in the direction that costs an
  # operator a correctly configured deploy: `backup@nas`, `git@github` and a bare `@` are all LEGAL
  # remote names (measured, git 2.53.0 — a remote name is a refname component, and `@` is allowed
  # in one), so a rule keyed on `@` refuses a host that is set up exactly right.
  #
  # ⛔ WHAT MEMBERSHIP DOES NOT CLOSE, STATED AT THE SIZE IT WAS MEASURED (review round 2). The
  # claim here WAS that no URL can be a configured remote's name, on the strength of `git remote
  # add a:b <url>` answering `'a:b' is not a valid remote name`. ⚠ THAT CONTROL ONLY COVERS NAMES
  # GIT CREATES. `git config` writes a section name straight into `.git/config` with no such
  # check — measured, git 2.53.0:
  #     git config 'remote.https://user:secret@host/o/r.git.url' https://host/o/r.git   # exit 0
  # `git remote` then LISTS that URL as a name, and this gate PASSES it (verdict measured against
  # the construct below). ⇒ THE HONEST STATEMENT IS THE NARROW ONE: no name `git remote add` will
  # CREATE can be a URL — a refname may not contain `:`, and every URL git fetches from carries
  # one (`https://host/…`, `file://…`, `git://…`, scp-style `user@host:path`) — BUT a name written
  # directly with `git config` can be, and it IS a configured remote of this checkout.
  #
  # ⚠ AND ON SUCH A CHECKOUT THE CREDENTIAL IS ALREADY ON SCREEN WHENEVER THE REMOTES ARE LISTED,
  # this gate's own refusal included: the list it prints below is `git remote`'s output, so a
  # credential someone wrote into `.git/config` AS A REMOTE NAME appears under a headline saying
  # the value is not printed. The value withheld there is `$MEZZ_REMOTE`; the list is the
  # checkout's own configuration, and nothing here can make `git remote` say less than it says.
  # A credential does not belong in a remote NAME on any host — `remote.<name>.url` is where a URL
  # goes, and this gate is what makes the NAME the only thing MEZZ_REMOTE may be.
  #
  # ⚠ THE ONE FETCH TARGET WITH NO `:` IS A BARE FILESYSTEM PATH, disposed of rather than left
  # unexamined: it carries no credential, and it reaches the fetch only where somebody has
  # configured a remote whose NAME is that path — which is a configured remote, and is exactly
  # what this gate asks for.
  #
  # ⛔ AND THE REFUSAL DOES NOT ECHO THE VALUE, which is the whole of the point: a refusal that
  # quoted the rejected MEZZ_REMOTE to explain itself would emit the credential it exists to keep
  # out of the log. It names the VARIABLE and lists the remotes this checkout HAS, and those are
  # NAMES: `git remote` with no options prints one name per line and no `remote.<name>.url`
  # (measured, git 2.53.0; `git remote -v` is the form that prints URLs, and is deliberately not
  # the form called here). ⚠ WHAT THAT DOES NOT PROMISE is that no URL is on that list — a NAME
  # written into `.git/config` with `git config` can itself be a URL, and `git remote` prints the
  # names it is given (see the block above). The withholding is of `$MEZZ_REMOTE`; the list is the
  # checkout's own configuration, which this gate reports and does not author.
  # ⚠ THE COST IS PAID KNOWINGLY: an operator who merely mistyped a remote NAME does not get their
  # typo echoed back. Echoing "only when the value looks safe" would be the same pattern-matching
  # guess by another route, one more rule to keep true, and wrong the first time a value that looks
  # safe is not. The list of names that WOULD have worked is what makes the typo findable instead.
  #
  # AFTER A3, NEVER BEFORE IT, for A3b's reason one step along: without A3 a failed `git remote`
  # would be read as "MEZZ_REMOTE is wrong" when the real cause is a checkout git cannot open at
  # all. And BEFORE A7, which is where `$REMOTE` is first printed — that ordering is what makes
  # this a gate rather than a second opinion. It refuses through `refuse`, like every other gate
  # reachable only from `phase_a` (A3b states why).
  #
  # ⛔ AND `git remote`'s OWN STATUS IS READ (card#9646's class). Unguarded under `set -Eeuo
  # pipefail` a failure here would end phase A with git's status and no banner and no promise. THE
  # EMPTINESS IS NOT THE ANSWER EITHER: a `git remote` that failed hands back the same empty string
  # a checkout with no remotes hands back, so a membership test alone would refuse on the wrong
  # cause. What is NOT established is whether MEZZ_REMOTE names a remote — not that it does not.
  local remotes="" remotes_rc=0
  remotes="$(git_at remote)" || remotes_rc=$?
  [ "$remotes_rc" -eq 0 ] || refuse \
    "git could not list the remotes of $DEPLOY_ROOT (\`git remote\` exited $remotes_rc)" \
    "What git printed is above this refusal. A3 passed — git opened the repository — and what" \
    "failed is the read of its remote configuration." \
    "Whether MEZZ_REMOTE names a remote of this checkout is NOT established; this is not \"it does" \
    "not\". Nothing was fetched and no ref was resolved." \
    "MEZZ_REMOTE's value is not printed here, by this refusal or by any other: it may be a URL" \
    "carrying a credential, which is the configuration A3c exists to refuse (card#9832)."
  # ⛔ MATCHED IN THE SHELL, WITH NO HERE-STRING AND NO SUBSHELL. `while read … <<< "$remotes"` is
  # the obvious spelling and it is the wrong one HERE: bash before 5.1 backs a here-string with a
  # TEMPORARY FILE, and this gate runs BEFORE the `.env` loader — so on a host with nowhere to put
  # one it would die on `cannot create temp file for here-document`, with no banner and no promise,
  # and take the loader's refusal — which names that cause correctly — with it.
  # ⚠ CORRECTED (card#9984): this used to name card#9816's own fixture, a TMPDIR pointing at a
  # directory that is not there, as the host condition that produces that death. It does not.
  # `mktemp` is an external command and fails on such a TMPDIR, which is why that fixture works for
  # the scratch-file refusals — but BASH validates `$TMPDIR` itself (`stat` + `W_OK`) and silently
  # falls back to `/tmp`, `/var/tmp`, `/usr/tmp` and then `.` (4.4 `lib/sh/tmpfile.c`, `get_tmpdir`
  # → `file_iswdir`; MEASURED on bash 4.4 built from the GNU tarball, from a working directory this
  # user cannot write to, where the here-string still succeeds). The death needs the WHOLE chain
  # unusable — a full `/tmp`, a locked-down account — which no fixture here can produce without
  # root. ⇒ The construct choice below stands and the reasoning for it is unchanged; what is
  # withdrawn is the claim that a named fixture would demonstrate it. Nothing on the runner this
  # suite usually runs on would show it either: bash 5.1 and later use a pipe.
  # A `case` over the list bracketed by newlines needs no file and no child, and `$REMOTE` is
  # quoted inside the pattern, so it is matched LITERALLY and not as a glob — `*`, `o*`, `origi?`
  # and `[o]rigin` are each refused against a list holding `origin`, and so are `orig`, `rigin`
  # and `origin ` (measured, with this exact construct).
  #
  # ⛔ AND A VALUE CARRYING A NEWLINE IS REFUSED BEFORE THAT TEST, because the test is applied to
  # the VALUE and the value is not a name (review round 2). A list bracketed by newlines makes
  # ADJACENT entries joinable: against `origin\nupstream\nbackup@nas\nprod-mirror`, the value
  # `$'origin\nupstream'` MATCHES and the gate passes it — measured with this construct. Nothing
  # secret gets through that way, since every line of such a value has to be a real remote name,
  # but A7 would then state that A3c "established that it does name a remote" about a value that
  # names none, the fetch would fail, and the multi-line value would be echoed across that
  # refusal — the five echoes this gate exists to end, reached by the back door.
  # ⇒ It costs nothing to close, and the routes into the config are ENUMERATED rather than counted
  # — "either route" was this comment's own short enumeration until review round 3 named a third
  # (measured, git 2.53.0):
  #   · `git remote add $'two\nlines' <url>`       — `is not a valid remote name`, nothing written
  #   · `git config "remote.$'two\nlines'.url" …`  — `invalid key (newline)`, nothing written
  #   · hand-editing `.git/config`                 — the file then does not PARSE: `fatal: bad
  #     config line N in file .git/config`, exit 128 from `git remote`, `git config --list` and
  #     `git status` alike, so A3 refuses that checkout (its generic branch names that very
  #     wording) long before this gate is reached.
  # No route leaves a remote whose NAME carries a newline, so this rejects no value that could
  # ever have been a name. It takes the SAME refusal below rather than one of its own: a value
  # that is not a name does not name a remote, and a second near-identical message would be a
  # second thing to keep true.
  local remote_is_configured=0
  case "$REMOTE" in
    *$'\n'*) ;;
    *) case $'\n'"$remotes"$'\n' in
         *$'\n'"$REMOTE"$'\n'*) remote_is_configured=1 ;;
       esac ;;
  esac
  if [ "$remote_is_configured" -ne 1 ]; then
    local remote_names="  (none — this checkout has no remotes configured at all)"
    [ -z "$remotes" ] || remote_names="$(printf '%s' "$remotes" | sed 's/^/  · /')"
    refuse "MEZZ_REMOTE does not name a remote of $DEPLOY_ROOT" \
      "ITS VALUE IS NOT PRINTED, AND THAT IS THIS REFUSAL'S POINT. \`git fetch\` accepts a URL as" \
      "well as a remote name, and a URL can carry a credential — so MEZZ_REMOTE may BE a secret," \
      "and a refusal that quoted it to explain itself would put that secret on this screen and in" \
      "this deploy's log. Read the value where you set it, not here (card#9832)." \
      "" \
      "MEZZ_REMOTE must be the NAME of a remote of this checkout. The names it has are:" \
      "$remote_names" \
      "" \
      "Set MEZZ_REMOTE to one of those, or add the remote you mean and pass its NAME:" \
      "  git -C $DEPLOY_ROOT remote add <name> <url>" \
      "A URL is refused even when it carries no credential: nothing here can tell one that does" \
      "from one that does not without inspecting it, and whether git redacts a URL in its OWN fetch" \
      "error depends on the transport — measured, git 2.53.0: an https URL is reported with the" \
      "credential stripped and a git:// one verbatim — which is not this script's to rely on."
  fi

  # A4 — a clean tree. A modified file on the prod checkout IS the hand-deploy D-13 forbids, and
  # the checkout below would either clobber it or fail. Either way the operator must see it now.
  #
  # ⛔ AND ITS STATUS IS READ, because A3 does not cover it (card#9646 S2, measured rather than
  # assumed). `git status` opens `.git/index`; `rev-parse --git-dir` never does — measured, git
  # 2.53.0, on a checkout whose `.git/index` is mode 000: A3 exits 0 and this exits 128 with
  # `fatal: .git/index: index file open failed: Permission denied`. Unguarded under `set -Eeuo`
  # that ended phase A at exit 128, a code the exit table does not list, with no banner and no
  # promise. THE EMPTINESS IS NOT THE ANSWER HERE EITHER: a failed `status` hands back the same
  # empty string a clean tree does, so a `[ -z "$dirty" ]` on it certifies a tree it never read.
  local dirty="" dirty_rc=0
  dirty="$(git_at status --porcelain)" || dirty_rc=$?
  [ "$dirty_rc" -eq 0 ] || refuse \
    "git could not read the state of $DEPLOY_ROOT (\`git status --porcelain\` exited $dirty_rc)" \
    "What git printed is above this refusal, and is what names the file it could not read." \
    "Whether this checkout carries local modifications is NOT established — this is not \"the tree is clean\"." \
    "A deploy that carried on here would check out over an edit it never saw, which is the hand-deploy" \
    "D-13 forbids, applied by this script instead of by a person." \
    "A3 above passed: git opened the repository. What failed is the read of the working tree's" \
    "state, and \`.git/index\` is the file that read needs (measured, git 2.53.0)."
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
  # ⛔ THE FETCH'S OWN STATUS IS READ, and it is the first phase-A command that could not say so
  # (card#9646). Unguarded under `set -Eeuo pipefail`, a fetch that failed ended the script with
  # git's status — 1 for a ref of this checkout it could not read, 128 for a remote it could not
  # reach — and NO ⛔ banner and no "Nothing was changed" promise. At 1 that is indistinguishable
  # from a refusal (the exit table above says 1 MEANS refused); at 128 it is a code the table does
  # not list at all. The only discriminator an operator had was the absence of two lines nothing
  # told them to look for.
  #
  # ⛔ STATUS ONLY — git's stderr IS NOT CAPTURED, and that is load-bearing rather than incidental.
  # A SUCCESSFUL fetch prints to stderr as a matter of course (ref-update lines), and it prints
  # git's own `error:` lines while still exiting 0 when an object it does not need is unreadable —
  # the shape `three_releases` produces in bin/deploy.selftest.sh, where a middle commit is blinded
  # and the fetch completes. A rule keyed on stderr would refuse those healthy runs. The status is
  # the whole answer here; git's message goes straight to the operator's terminal, unsilenced, which
  # is the same rule the readers below state (§ reading the TARGET RELEASE out of git).
  #
  # The headline names no cause on purpose: the status does not carry one, so it does not claim one.
  local fetch_rc=0
  git_at fetch --prune --tags "$REMOTE" || fetch_rc=$?
  [ "$fetch_rc" -eq 0 ] || git_read_unusable \
    "git could not fetch $REMOTE (\`git fetch\` exited $fetch_rc)" \
    "What git printed is above this refusal. WHICH of these it is, git says and this deploy does" \
    "not guess:" \
    "  · $REMOTE could not be reached, or refused this host's credential, or its configured URL" \
    "    names nothing git can fetch from — git says \`does not appear to be a git repository\` or" \
    "    \`Could not read from remote repository\` (measured, git 2.53.0: exit 128 for both)." \
    "    It is NOT \"MEZZ_REMOTE names no remote of this checkout\": A3c established that it does," \
    "    by membership in \`git remote\`, before this fetch ran (card#9832)." \
    "  · a ref of THIS CHECKOUT that git could not read — \`bad object refs/…\`, exit 1. A fetch" \
    "    reads this checkout's own tips to tell $REMOTE what it already has, so that failure is in" \
    "    the object store HERE and not at $REMOTE. It is the store A8's refusal names the repair" \
    "    for, and \`git fetch\` is not that repair — it is the thing that failed." \
    "  · a store git could not write what it fetched into." \
    "Nothing about the release was read: no ref was resolved and no gate ran. Remote-tracking refs" \
    "may have moved partway; the worktree, HEAD and what is being served did not."

  # The candidates, in the order they have always been tried. git_commit_of (card#9611) asks the
  # refs and the object store separately, so "there is no ref of that name" and "git could not read
  # it" cannot arrive as the same answer: the refusal below is reached ONLY when the refs were read
  # and none of the three names anything. A failed read refuses above it, naming the object.
  # ⚠ THE INPUTS THE LINE BELOW CANNOT PROMISE THAT ABOUT, named for the operator rather than
  # assumed away (card#9611 r2): `$REF` is not always a ref NAME. Rev syntax (`~`, `^`, `@{}`, `:/`)
  # resolves by WALKING THE COMMIT GRAPH and an abbreviated id is a lookup IN the object store, and
  # a store too damaged to search answers either with the SAME SILENCE an absent name does (measured
  # — git_ref_oid states it). The rev-syntax metacharacters are what separate rev syntax from a
  # name; check-ref-format then separates a VALID name from one git refuses outright. Its stderr is
  # dropped because it reads NOTHING — it parses a string, and its status is the whole answer, which
  # is the opposite of the silenced reads this card is about.
  # ⚠ AND `$REF` IS ASKED OF check-ref-format WITH ITS LEADING DASHES OFF (card#9611 r3). That
  # command has no `--end-of-options` and no `--`: BOTH are parsed as the refname and exit 129 with
  # the usage message for EVERY ref, valid or not (measured, git 2.53.0 — `--allow-onelevel
  # --end-of-options main` → 129, `-- main` → 129, `main` → 0), so the guard git_ref_oid uses cannot
  # be used here. Without one, `--ref -foo` was read as an OPTION: exit 129, usage swallowed by
  # 2>/dev/null, and the note below then told the operator "'-foo' is not a ref name: it carries rev
  # syntax" — which is false, and false in the one direction this whole card is about. git_ref_oid
  # resolves `$REF` with --end-of-options, so it IS asked as a name; the dashes are stripped for the
  # classification only, which leaves `-foo` classified as the name it is.
  #
  # ⛔ AND THE REV-SYNTAX TEST IS POSITIVE, BECAUSE A check-ref-format FAILURE IS NOT EVIDENCE OF REV
  # SYNTAX (card#9611 r4). r3 fixed the `-foo` input; the CLASS is that `--allow-onelevel` exits 1
  # for around a dozen rules and rev syntax is only some of them. Measured, git 2.53.0, all exit 1
  # and NONE is rev syntax: `a b`, `main..dev`, `foo.lock`, `ab[c`, `.foo`, `foo//bar`, `foo/`,
  # `ab*c`, `ab?c`, `ab\c`, a tab. An ordinary typo — `--ref 'release 1.2'` — was therefore told
  # "it carries rev syntax, so resolving it walked the commit graph", THREE false statements in one
  # line, on a completely healthy host: measured, `rev-parse --verify --quiet --end-of-options
  # 'refs/remotes/origin/release 1.2'` exits 1 with EMPTY stderr, a lookup in the refs that never
  # touches the object store. So rev syntax is now detected by the metacharacters that ARE it —
  # `~`, `^`, `:`, `@{` — each cross-checked against what rev-parse does with it, and a name git
  # simply refuses gets the note that is TRUE of it, which is the more useful answer anyway.
  local ref_note=() ref_probe="$REF" store_read_tail=(
    "a question that READS THE OBJECT STORE, where a store too damaged to search answers with the" \
    "same silence. Pass the full 40-character commit id if you have one: that resolves without the" \
    "store being read, so a failure behind it is named as the failed read it is." )
  case "$REF" in -*) ref_probe="${REF#"${REF%%[!-]*}"}" ;; esac
  case "$REF" in
    *[~^:]* | *"@{"*)
      ref_note=( "⚠ '$REF' carries rev syntax (~, ^, :, @{), so resolving it walked the commit graph —" \
        "${store_read_tail[@]}" ) ;;
    *)
      if [ -n "$ref_probe" ] && ! git_at check-ref-format --allow-onelevel "$ref_probe" >/dev/null 2>&1; then
        ref_note=( "⚠ '$REF' is not a valid ref NAME — git refuses it (check-ref-format), so no ref of" \
          "that name can exist to be found. A space, '..', a trailing '.lock', a leading '.' and the" \
          "characters git reserves are the usual causes; check the spelling of what you typed." \
          "THIS SAYS NOTHING ABOUT THIS CHECKOUT'S OBJECT STORE, which was never read: an invalid" \
          "name is answered out of the refs alone, silently (measured, git 2.53.0)." )
      else
        case "$REF" in
          '' | ? | ?? | ??? | *[!0-9a-fA-F]*) ;;
          *) ref_note=( "⚠ '$REF' is hex, so git also looked for it as an ABBREVIATED commit id —" \
               "${store_read_tail[@]}" ) ;;
        esac
      fi ;;
  esac
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
  # A7's `git fetch` long before — and that fetch now REFUSES on it by name (card#9646), so the
  # state this would meet is one the run has already ended in.
  # ⚠ THAT IS A V1 THIS SCRIPT CANNOT REACH, NOT A SHAPE THAT IS ACCEPTABLE HERE — and the
  # difference is card#9646's whole point, so the old wording is corrected rather than kept.
  # A `set -e` death IS NOT "the safe direction": it exits with git's status, which at 1 is the
  # code the exit table above says MEANS "refused, nothing was touched", with no ⛔ banner and no
  # "Nothing was changed" promise to tell the operator which of the two they are looking at. What
  # makes this line a comment instead of a refusal path is that it is UNREACHABLE — canon #6, an
  # analysis finding no live precondition concludes no work — and nothing else.
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
      "run the same command again. Every step below works inside $DEPLOY_ROOT/.git and leaves this" \
      "host's own files alone." \
      "  1. NAME THE OBJECT, and learn which of the two states it is in:" \
      "       git -C $DEPLOY_ROOT fsck" \
      "     \"unable to mmap … Permission denied\" is a file that is THERE and cannot be read;" \
      "     \"broken link from … to …\" with nothing else about it is a file that is GONE." \
      "  2. IF IT IS UNREADABLE, restore that one file's mode and this checkout is whole again:" \
      "       chmod 444 $DEPLOY_ROOT/.git/objects/<first 2 characters>/<the other 38>" \
      "  3. IF IT IS GONE, replace the OBJECT STORE ONLY — never the deploy root:" \
      "       git clone --no-checkout <this repo's URL> /tmp/mezz-fresh" \
      "       mv $DEPLOY_ROOT/.git $DEPLOY_ROOT/.git.broken" \
      "       mv /tmp/mezz-fresh/.git $DEPLOY_ROOT/.git" \
      "       git -C $DEPLOY_ROOT checkout --force <the commit you are deploying>" \
      "     Only .git is replaced, so server/.env, server/storage/ and .deploy-failed — this host's" \
      "     own files, in no commit — are still there afterwards. There is no list to get right." \
      "  4. CONFIRM the store is whole before re-running the deploy:" \
      "       git -C $DEPLOY_ROOT repack -a -d" \
      "     It rebuilds the packs and refuses outright if anything reachable cannot be read, so a" \
      "     clean run is the answer you want. It repairs nothing — it reports." \
      "⛔ git fetch CANNOT bring that object back. fetch negotiates from REFS, and the refs of this" \
      "checkout ALREADY claim that commit, so $REMOTE is never asked for the objects behind it —" \
      "measured, git 2.53.0: with the object unreadable \`fetch --prune\` exits 0 having transferred" \
      "nothing and the file is still unreadable, and with the object DELETED it exits 0 too and the" \
      "object is still gone. A clean fetch here is not a repair that worked." \
      "⛔ DO NOT RE-CLONE $DEPLOY_ROOT ITSELF. server/.env is created on this host and is in no" \
      "commit, so its APP_KEY and DB_PASSWORD exist nowhere else; a fresh clone of the whole root" \
      "also takes server/storage/ — the logs — and .deploy-failed, the marker a failed window" \
      "leaves to be read, with it. Step 3 replaces the objects without touching any of them."
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

  # ── THE TARGET-TREE GATES, called in the order they refuse in (card#9644) ────────────────────
  # A6, A6b and A10–A13 decide about the RELEASE BEING DEPLOYED rather than about this host: each reads
  # the tree at $SHA out of the object database and refuses before the checkout. They are functions
  # of their own — § PHASE A's TARGET-TREE GATES, below — so that a checker can run one over a real
  # commit with no host to run it against. Inline, the only ways to reach a content predicate were
  # to fabricate a host, which is a second copy of deploy.selftest.sh's stub (canon #5), or to
  # restate the predicate in the checker, which is the drift card#9203 filed.
  #
  # THE ORDER OF THESE CALLS IS THE REFUSAL ORDER, and it is the whole of what this sequence
  # decides: a gate refuses out of the process, so the first refusal any of them reaches is the
  # first one a deploy meets. Each gate's own header says whether it touches anything but git.
  gate_a6_php_floor "$SHA" "${HOST_PHP_VERSION:-0}"
  gate_a6b_bash_floor "$SHA" "$HOST_BASH_VERSION"
  gate_a10_migration_algorithm "$SHA"
  gate_a10b_config_drift "$SHA"
  gate_a11_trusted_proxies "$SHA"
  gate_a12_asset_lockfile "$SHA" "$npm_version"
  gate_a13_supervision "$SHA"

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
# PHASE A's TARGET-TREE GATES — callable one at a time, on a commit, with no host (card#9644)
# ══════════════════════════════════════════════════════════════════════════════════════════════
# Each takes the commit being deployed as its FIRST argument, bound to a local named SHA: that is
# the deploy's own name for the rev, and it is what keeps every read in these bodies written
# `git_read_at <var> "$SHA" <path>` — the one shape bin/deploy-gate-inputs.sh's derivation takes a
# gate input out of. phase_a calls them in order (§ THE TARGET-TREE GATES); a checker calls one.
#
# HOST-FREE is stated per gate and it is a promise about the GATE, not about this file: sourcing
# bin/deploy.sh has its own effects (§ library mode). A gate marked host-free reads the object
# database of $DEPLOY_ROOT and the arguments it was handed, and nothing else. The two that are not
# are marked, with what they read: neither can be, because what they decide IS a comparison with
# this host — A10b against its `.env`, A13 against its crontab and the SERVING release's locks.

# gate_a6_php_floor <sha> <host PHP version> — A6. HOST-FREE: it derives the PHP floor from
# server/composer.json at <sha> and compares it against the version it is HANDED, so a checker
# names the host version instead of having one. Its first two refusals are properties of the target
# tree ALONE — no `require.php`, and a constraint this cannot evaluate — which makes them
# unconditional every-deploy refusals that no lane over fixtures can see (card#9644's comment 5498).
gate_a6_php_floor() {
  local SHA="$1" phpver="$2"
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
  local composer_json="" floor_constraint floor_op floor_min floor_max
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
}

# gate_a6b_bash_floor <sha> <host bash major.minor> — A6b. HOST-FREE: it reads the BASH_FLOOR that
# bin/deploy.sh at <sha> declares and compares it against the version it is HANDED, exactly as A6
# does the PHP one, so a checker names the host version instead of having one.
gate_a6b_bash_floor() {
  local SHA="$1" bashver="$2"
  # A6b — THE BASH FLOOR OF THE RELEASE BEING DEPLOYED (card#9616). A1 held this bash to THIS
  # copy's floor. But this copy does not run the maintenance window: after `artisan down` and the
  # checkout, phase B re-execs the DEPLOYED release's bin/deploy.sh (--internal-post-checkout), and
  # that copy never runs A1. So a release which RAISES the floor passes A1 here and then meets its
  # own floor the only other way there is — as a death on its own constructs, with the app down.
  # Reading the target's declaration refuses that before anything is touched. Same reasoning, same
  # place in the order, as A6's PHP floor: the two differ on exactly the deploy that moves a floor.
  local src="" floor="" short
  short="$(git_at rev-parse --short "$SHA")"
  # Absent is a refusal of its own and not "no floor": bin/deploy.sh at <sha> is the file the
  # re-exec RUNS, so a release without it cannot run its own window at all. A13 refuses a release
  # with no bin/supervision.sh for the same reason and in the same words.
  git_read_at src "$SHA" bin/deploy.sh || refuse \
    "bin/deploy.sh is missing from $short" \
    "After the checkout, phase B re-execs the deployed release's own bin/deploy.sh to run the" \
    "maintenance window. A release without it would be checked out with the app down and then have" \
    "nothing left to run."
  # …and an EMPTY one is refused as itself, not read as a release that declares no floor. The two
  # are different facts about the release and only the second is survivable: a file with no bytes
  # in it cannot run the window either. A6 refuses an empty server/composer.json for the same reason.
  [ -n "$src" ] || refuse \
    "bin/deploy.sh at $short is empty" \
    "It is the file phase B re-execs to run the maintenance window, so an empty one is a release" \
    "that cannot deploy itself. This is NOT \"it declares no bash floor\" — a release that predates" \
    "card#9616 declares none and deploys; this one has nothing in it at all."
  floor="$(printf '%s\n' "$src" | bash_floor_declared)"
  if [ -z "$floor" ]; then
    # ⛔ NOT A REFUSAL, and this is the deliberate part. Every release cut before card#9616 declares
    # no BASH_FLOOR, and that is a fact about WHEN it was written, not a claim that it runs on any
    # bash. What is enforced for such a release is what exists: A1's floor, on the copy running now.
    # Refusing instead would make every older release undeployable by this one — a ROLLBACK
    # included, which is the deploy most likely to be run under pressure — for want of a
    # declaration it could not have made.
    say "  ok — bin/deploy.sh at $short declares no BASH_FLOOR (it predates card#9616); only this copy's floor, $BASH_FLOOR, was enforced (A1)"
    return 0
  fi
  # ⛔ THE SAME TEST A1 HOLDS THIS COPY'S OWN DECLARATION TO (card#9984). It used to be an inline
  # `[0-9]*.[0-9]*` here — a third copy of the pattern, beside A1's looser one and the workflow's —
  # and it admitted `4.4x` and `4.x.5`; the second was enforced as the floor 4.0.5, which is not
  # the floor that release declared. `bash_floor_is_version` states what a floor is, once.
  # ⚠ TIGHTENING THIS GATE STRANDS NO RELEASE, and that was checked rather than assumed, because
  # refusing a release nobody can re-cut is the failure the branch above exists to avoid. Every
  # tag this repository has published was read (`git show <tag>:bin/deploy.sh`) and NONE declares
  # a BASH_FLOOR, so this predicate rejects nothing that is out there — but they do not all reach
  # it by the same route, and the earlier wording said they did:
  #   · the tags that CARRY bin/deploy.sh declare no floor and take the survivable path above;
  #   · the earliest tags carry no bin/deploy.sh at all, so A6b refuses them at the `git_read_at`
  #     branch further up — which it already did before this card, and for a different reason.
  # Re-derive rather than trusting either sentence: for each tag, `git cat-file -e <tag>:bin/
  # deploy.sh` says which group it is in. A rollback to a tag that HAS the file is unaffected.
  bash_floor_is_version "$floor" || refuse \
    "bin/deploy.sh at $short declares BASH_FLOOR='$floor', which is not a version" \
    "A6b compares this host's bash against the floor that release declares, and will not guess" \
    "one it cannot read — nor read it as far as it parses, which would enforce a floor the release" \
    "did not declare. The line is \`BASH_FLOOR=<major>.<minor>\`, alone on its line: exactly two" \
    "numeric fields."
  bash_meets_floor "$bashver" "$floor" || refuse \
    "bash $bashver is below the floor the release being deployed declares: BASH_FLOOR=$floor in bin/deploy.sh at $short" \
    "This copy's own floor ($BASH_FLOOR) was met — A1 checked it. But after \`artisan down\` and the" \
    "checkout, phase B re-execs THAT release's bin/deploy.sh, and on a bash below its floor it dies" \
    "on its own constructs inside the maintenance window, with the app already down. This refusal" \
    "is that failure, moved to before anything is touched." \
    "" \
    "Either run this deploy with bash $floor or later, or deploy a release whose floor this bash meets."
  say "  ok — bash $bashver meets BASH_FLOOR=$floor, declared by bin/deploy.sh at $short"
}

# gate_a10_migration_algorithm <sha> — A10. HOST-FREE: the migrations at <sha> and their text.
gate_a10_migration_algorithm() {
  local SHA="$1"
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
}

# gate_a10b_config_drift <sha> — A10b. NOT host-free: it reads the release's server/.env.example
# out of git and asks env_get what THIS HOST's $ENV_FILE sets, which is the comparison it exists to
# make. It warns and never refuses ON A FINDING — a key the host does not set, or sets in a form this
# deploy does not read, is a warning and nothing more. It DOES refuse when the file it is comparing
# against stopped being readable mid-run (env_get status 3), which is not a finding about config drift
# but the disappearance of the evidence this gate and every check above it were reading.
gate_a10b_config_drift() {
  local SHA="$1"
  # A10b — config drift between the release and the host. A release that introduces a setting ships
  # it in `server/.env.example`; the host's `.env` was written by hand when the host was stood up
  # and NOTHING ever updates it again. The failure this catches is the quiet one: the deploy is
  # green, the app is up, and one feature reads a value nobody set. It WARNS rather than refuses
  # because an absent key is not automatically a defect — several have framework defaults, and
  # `.env.example`'s commented lines are deliberately optional — but nothing else on this host will
  # ever mention it.
  # Whether this host sets a key is asked through env_get, the one reader that answers it the way Laravel
  # would: a hand-rolled `^[[:space:]]*KEY=` line-grep reported `export FOO=…` and `"FOO"=…` — both of them
  # Dotenv's FOO — as a key the host does not set. Its other three answers are each kept apart: status 2 is a key written
  # in a form this deploy does not read, which is not "missing" and not "set" but "not established", and
  # saying "does not set" of it sends the operator to add a line that is already there; status 3 is the file
  # itself not being read, which would put EVERY key of .env.example into that same list and is refused
  # rather than warned about (card#9610). (A5 has run env_file_scan on this file, which is what makes a LINE
  # a unit here at all — env_get's precondition, and the thing a status 3 here says has stopped holding.)
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
      # ⛔ NOT A WARNING, and not a key this host "does not set". A5 read this same file through this same
      # loader earlier in this run, so a 3 here means it stopped being readable in between — and every key
      # already collected above was read from a file that is no longer the one on disk. Warning would put
      # EVERY key the release's .env.example names into a "does not set" list that nothing established,
      # and the operator would go and re-add settings that are already there (card#9610).
      3) env_unread_refuse "$k" ;;
    esac
  done
  [ ${#missing_keys[@]} -eq 0 ] \
    || warn "the target release's .env.example names keys this host's .env does not set: ${missing_keys[*]}"
  [ ${#unread_keys[@]} -eq 0 ] \
    || warn "the target release's .env.example names keys this host's .env writes in a form this deploy does not read, so whether the release's default or the host's value is in force is not established: ${unread_keys[*]}"
}

# gate_a11_trusted_proxies <sha> — A11. HOST-FREE: server/bootstrap/app.php at <sha> and its text.
gate_a11_trusted_proxies() {
  local SHA="$1"
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
}

# gate_a12_asset_lockfile <sha> <host npm version> — A12. HOST-FREE: server/package-lock.json at
# <sha>, and the npm version it is HANDED (A1c read it), as A6 is handed the host's PHP.
gate_a12_asset_lockfile() {
  local SHA="$1" npmver="$2"
  # A12 — a lockfile for the asset build. `npm ci` is used below and requires one; more to the
  # point, package.json floats (vite ^8, tailwind ^4), so a lockfile-less prod build can ship
  # different JavaScript from the same commit on two consecutive days, and nothing in the repo
  # would record which. Refusing here is not this script being strict — it is the only place the
  # question is still cheap.
  local lock="" lock_version="" npm_floor="" short
  short="$(git_at rev-parse --short "$SHA")"
  # The CONTENT, not just the presence (card#9616): the lockfile's own `lockfileVersion` is what
  # says which npm can install it. `git_read_at`'s 1 is "no such path at this commit" — a git read
  # that FAILED never returns here at all (§ reading the TARGET RELEASE out of git).
  git_read_at lock "$SHA" server/package-lock.json || refuse \
    "server/package-lock.json is missing from $short" \
    "The prod asset build must be reproducible: package.json floats (vite ^8, tailwind ^4)," \
    "so without a lockfile the same commit can build different assets on different days." \
    "Commit the lockfile (\`npm install\` in server/, commit server/package-lock.json)."
  # …and an EMPTY one is refused as itself, not folded into "a lockfileVersion this gate cannot map"
  # (the review at `f2ee3d0`, card#9616 comment 5831). The mapping refusal tells the operator to teach A12 a new lockfile version
  # from npm's docs, which is the wrong instruction for a file that declares nothing because it holds
  # nothing: what they have is a truncated or half-written lockfile, and `npm ci` would refuse it too.
  # A6b makes the same distinction about bin/deploy.sh, for the same reason.
  [ -n "$lock" ] || refuse \
    "server/package-lock.json at $short is empty" \
    "\`npm ci\` reads that file in phase B, inside the maintenance window, and an empty one is not a" \
    "lockfile it can install from — nor does it declare the lockfileVersion this gate reads to decide" \
    "which npm the release needs. This is NOT \"a lockfileVersion A12 does not know\": there is no" \
    "version in it to know, and nothing to teach this gate." \
    "Regenerate it (\`npm install\` in server/) and commit the result."
  # …AND AN NPM THAT CAN INSTALL IT. `npm ci` runs in phase B, inside the window: an npm too old
  # for the release's lockfile fails there with the app already down. The floor is read from the
  # TARGET tree, because a release that MOVES to a newer lockfile format is exactly the one whose
  # floor the serving checkout's own lockfile does not show — the same reasoning as A6 and A6b.
  #
  # ⚠ THE MAPPING IS DOCUMENTED, NOT MEASURED — and it is npm's own documentation, quoted rather
  # than summarised. docs.npmjs.com, "package-lock.json", § lockfileVersion (the v11 page, read
  # 2026-09-19): "1: The lockfile version used by npm v5 and v6. … 2: The lockfile version used by
  # npm v7 and v8. Backwards compatible to v1 lockfiles. 3: The lockfile version used by npm v9 and
  # above. Backwards compatible to npm v7." So 3 implies npm >= 7, and that is the only floor those
  # words state: 2 carries the v1 `dependencies` section precisely so npm v6 can read it, and 1 is
  # v5/v6's own format. No npm below 7 was RUN against any of them by this repository, and nothing
  # here invents a floor for 1 or 2. A lockfileVersion this mapping does not name — none, a
  # non-number, a 4 — is REFUSED rather than guessed at, in the same direction A6 refuses a PHP
  # constraint it cannot evaluate: a guess that is wrong is discovered inside the window.
  lock_version="$(printf '%s\n' "$lock" | npm_lockfile_version)"
  case "$lock_version" in
    3)     npm_floor=7 ;;
    1 | 2) npm_floor="" ;;
    *) refuse "server/package-lock.json at $short declares lockfileVersion '${lock_version:-(none)}', which A12 cannot map to an npm floor" \
         "A12 knows lockfileVersion 1, 2 and 3, from npm's own documentation (this gate's comment" \
         "quotes it), and refuses any other rather than guess which npm \`npm ci\` would need — a" \
         "guess that is wrong is found inside the maintenance window, with the app down." \
         "Teach A12 the new lockfile version from npm's docs, deliberately, and re-read this gate." ;;
  esac
  if [ -n "$npm_floor" ]; then
    ver_ge "$npmver" "$npm_floor" || refuse \
      "npm $npmver is below npm $npm_floor, which lockfileVersion $lock_version in server/package-lock.json at $short needs" \
      "npm's own documentation says lockfileVersion 3 is \"backwards compatible to npm v7\" and no" \
      "further. \`npm ci\` reads that lockfile in phase B, inside the" \
      "maintenance window, with the app already down. This refusal is that failure, moved to" \
      "before anything is touched." \
      "" \
      "Upgrade this host's npm to $npm_floor or later (\`npm --version\` is what was read)."
    say "  ok — npm $npmver meets npm $npm_floor, which lockfileVersion $lock_version in server/package-lock.json at $short needs"
  else
    say "  ok — server/package-lock.json at $short is lockfileVersion $lock_version, for which npm's docs state no floor; npm $npmver was not compared"
  fi
}

# gate_a13_target_plan <sha> <workdir> <deploy root> <php binary> — A13's HOST-FREE half, and the
# one a checker wants: it reads the TARGET release's bin/supervision.sh out of git and runs that
# release's own install plan in a bash process of its own, writing <workdir>/plan, <workdir>/locks
# and <workdir>/daemons. Every refusal that install makes is made here — including "it defines no
# supervision_install_plan", which is an unconditional every-deploy refusal of the target tree
# (card#9644's comment 5498) — and the caller compares the result against the host.
# <workdir> is the caller's, and this function removes it on the paths that refuse out of the
# process, since a refusal never returns for the caller to clean up after.
gate_a13_target_plan() {
  local SHA="$1" work="$2" root="$3" php_bin="$4"
  local short target_sup eval_err
  short="$(git_at rev-parse --short "$SHA")"
  target_sup=""
  git_read_at target_sup "$SHA" bin/supervision.sh || true
  [ -n "$target_sup" ] || {
    rm -rf "$work"
    refuse "bin/supervision.sh is missing or empty at $short" \
      "The window installs the deployed release's crontab block from it and restarts the daemons it names." \
      "A release without it cannot be supervised by this deploy."
  }
  printf '%s\n' "$target_sup" > "$work/supervision.sh"
  # ⛔ `"$BASH"`, THIS PROCESS'S OWN INTERPRETER, NOT PATH'S (the review at `0de8857`, card#9616
  # comment 5846). This used to be a
  # bare `bash -c`, which is the same defect the re-exec had and is worse HERE, because of what this
  # gate says when the subprocess fails: "the crontab block of bin/supervision.sh at <sha> could not
  # be installed here" — a statement about THE RELEASE. A1 and A6b hold THIS bash to the two floors
  # and say nothing about PATH's, so on a host where those differ an old PATH bash would fail this
  # subprocess on its own constructs and the deploy would blame the release being deployed for it.
  # That misattribution is the class this card exists to end, and one of its own gates was making it.
  # Running the target's supervision.sh under the interpreter the floors were checked against is
  # also what makes A13's judgement about the RELEASE rather than about which bash came first.
  # shellcheck disable=SC2016 # expanded by the bash it is handed to, not by this one
  eval_err="$(env -u BASH_ENV "$BASH" -c '
      set -Eeuo pipefail
      . "$1/supervision.sh"
      for f in supervision_install_plan supervision_lock; do
        declare -F "$f" >/dev/null || { echo "it defines no $f, which this deploy runs from it" >&2; exit 1; }
      done
      supervision_install_plan "$2" "$3" > "$1/plan"
      supervision_lock "$2" "mezzanine:*" > "$1/locks"
      printf "%s " "${SUPERVISED_DAEMONS[@]}" > "$1/daemons"
    ' a13 "$work" "$root" "$php_bin" 2>&1)" || {
    rm -rf "$work"
    refuse "the crontab block of bin/supervision.sh at $short could not be installed here" \
      "$(printf '%s\n' "$eval_err" | sed 's/^/  | /')" \
      "" \
      "The maintenance window installs it; this is that install's refusal, made before anything is touched."
  }
}

# gate_a13_supervision <sha> — A13. NOT host-free: it compares the target release's crontab block
# against THIS HOST's installed crontab, and the lock files that release would use against the
# SERVING release's. Its target-tree half is gate_a13_target_plan, above, which a checker calls.
gate_a13_supervision() {
  local SHA="$1"
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
  local php_bin short work installed added removed serving_locks target_locks
  short="$(git_at rev-parse --short "$SHA")"
  php_bin="$(supervision_default_php)"
  scratch_dir work "A13's reading of $short's crontab block"
  gate_a13_target_plan "$SHA" "$work" "$DEPLOY_ROOT" "$php_bin"
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
  # ⛔ THROUGH `$BASH` — THIS PROCESS'S OWN INTERPRETER — NOT THE SHEBANG (card#9616). It used to be
  # `exec "$DEPLOY_ROOT/bin/deploy.sh" …`, which runs the target's `#!/usr/bin/env bash`: the bash on
  # PATH, which need not be the one phase A ran under. A1 and A6b hold THIS process's bash to the two
  # floors, so on `somebash bin/deploy.sh`, with an older bash first on PATH, both gates would pass and
  # the window would then run on the bash neither of them measured — and die on the constructs the
  # floors exist to keep out, with the app down. Handing the interpreter over makes the thing the gates
  # measured the thing that runs, rather than adding a third gate to check the difference.
  exec "$BASH" "$DEPLOY_ROOT/bin/deploy.sh" --internal-post-checkout "$SHA"
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
  # release is already serving. Every answer env_get cannot turn into a URL gets a warning of its OWN, NAMED —
  # a form this reader does not read (status 2), a file that was not read at all (status 3, which is TWO facts
  # and takes two warnings — § env_lines_load's KIND), and a key the file genuinely does not set are different
  # facts about this host, and only the last of them is "unset".
  # ⛔ NO `refuse` ON ANY OF THEM: the window is closed and the new release is serving, so the one promise a
  # refusal makes would be false. The deploy is UNVERIFIED and says so, which is what exit 0 means here.
  # ⚠ `url` IS INITIALISED HERE, and under `set -u` that is load-bearing rather than tidy: the skip
  # below reaches the falsy test without assigning it, and a bare `local url` leaves it UNSET, which
  # kills phase B at `"$url"` — after the window, with the release serving and no warning printed.
  # Measured: this suite's three in-window cases red at exit 1 with no smoke line at all.
  local url="" code url_rc=0
  # ⛔ THE LOADER IS RUN HERE, IN THIS SHELL, BEFORE THE READ BELOW PUTS IT INSIDE A `$( )` (card#9933).
  # `env_get` has to be called in a command substitution — that is the only way a value comes back — and
  # a subshell's variables die with it, so the STATUS was all that crossed and every status 3 looked the
  # same from here. MEASURED on the tree before this line, with the loader's own scratch file made the one
  # `mktemp` that fails: a deploy that FINISHED — exit 0, `✔ DEPLOYED`, the marker removed — whose single
  # warning told the operator that the open or the read of `server/.env` had failed, that the file had
  # stopped being readable inside the window, and that bash's reason was above. The file was readable
  # throughout, no read was ever made, and no such diagnostic exists.
  #
  # ⛔ ONE LOAD ANSWERS, AND IT IS THE LOAD WHOSE FLAGS ARE READ — that is what the skip below is for, and
  # it is a mechanism rather than a hope (card#9933 review round 3). Reading this load's KIND beside a
  # status that came from the OTHER load is only sound in one direction:
  #   · this load gets its scratch file ⇒ ENV_READ_ERR_FD is set and the subshell INHERITS it, so the
  #     inner load cannot fail at the scratch step and any 3 it answers is an open or a read — which is
  #     what the generic warning below says. Sound.
  #   · this load FAILS at the scratch step ⇒ the descriptor is still empty, so an inner load would
  #     RE-ATTEMPT `mktemp`: two separate events. The inner one can succeed where this one failed and
  #     then stop short in the READ, and the scratch warning — "the read was never made" — would print
  #     directly beneath bash's own read-error diagnostic from that inner read. Two coincident faults,
  #     and precisely the contradictory report this card exists to end.
  # So the inner load is not run in that case at all: the file was not read, no key of it has a value,
  # and this load is both the one that establishes that and the one whose kind is reported. Nothing is
  # re-derived here — `env_file_unread` is the one place that turns these flags into "not read", and
  # `env_get` answers its status 3 from the same predicate.
  # ⚠ NOT SILENCED, and that follows from the skip: on a read that stops short exactly ONE load runs and
  # its diagnostic is the one the warning means by "bash's reason … is above" (§ the suite's H4b, which
  # asserts the count). Silencing it here would leave that warning pointing at nothing.
  env_lines_load
  if env_file_unread; then
    url_rc=3
  else
    url="$(env_get APP_URL)" || url_rc=$?
  fi
  # The same rule as every A5 check: what the app has is the value it RECEIVES. An APP_URL Laravel
  # resolves to a falsy value is no URL — `config('app.url')` is null or '' — so it takes the unset
  # case's warning rather than a smoke request to a host named `null` and a failure report naming it.
  ! env_app_falsy "$url" || url=""
  if [ "$url_rc" -eq 2 ]; then
    warn "APP_URL is in a form this script does not read (env_get, bin/deploy.sh) — no smoke check was made. The deploy is UNVERIFIED."
  elif [ "$url_rc" -eq 3 ] && [ "$ENV_LINES_READ_FAILED_KIND" = 'scratch' ]; then
    warn "server/.env was NOT READ — no scratch file could be opened for bash's read diagnostic, so the read was never made — and no smoke check was made. The deploy is UNVERIFIED. This is not 'APP_URL is unset', and it is NOT a finding about server/.env, which may be perfectly readable: what failed is a scratch file this deploy makes for itself, inside the window. $ENV_LINES_READ_FAILED_WHY The release IS deployed and serving; what was not done is the check on it. Fix that and re-run \`bin/deploy.sh --dry-run\`, which reaches the same loader at A5 — and note it can only name this cause while the cause is still there: a \$TMPDIR that filled during the deploy and has since drained leaves a clean dry run and this line as the only record."
  elif [ "$url_rc" -eq 3 ]; then
    warn "server/.env could not be read (its open or its read failed; bash's reason, if any, is above) — no smoke check was made. The deploy is UNVERIFIED. This is not 'APP_URL is unset': the file was readable in phase A and stopped being so inside the window. \`bin/deploy.sh --dry-run\` names the cause the way A5 does."
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

# Run, not sourced — § library mode. Sourced, this file defines its functions and returns,
# which is what lets a checker call one gate (§ PHASE A's TARGET-TREE GATES) without deploying.
if [ "$DEPLOY_IS_RUN" -eq 1 ]; then
  main "$@"
fi

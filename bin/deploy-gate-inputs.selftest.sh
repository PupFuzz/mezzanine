#!/usr/bin/env bash
# deploy-gate-inputs.selftest.sh — hermetic, network-free acceptance for bin/deploy-gate-inputs.sh.
# card#9637, card#9745
#
# WHY IT EXISTS. `bin/deploy-gate-inputs.sh` RUNS the target-tree gates bin/deploy.sh declares
# host-free over a commit, and trusts a read ledger for what they read. Both halves fail silently if
# nothing watches them fail: a lane that stopped running a gate, or a ledger that stopped seeing a
# read, stays GREEN. So each verdict below is a case against the REAL script, each one
# asserted on the message as well as the exit code — a check with two reasons to exit 2 would
# otherwise look like it discriminates when it does not.
#
# WHAT IS REAL AND WHAT IS NOT.
#   REAL: bash, git, the whole of `bin/deploy-gate-inputs.sh`, the whole of `bin/deploy.sh` (the lane
#         sources it and runs its gates), and a fixture repository built from THIS repository's own
#         tracked tree at HEAD — with `bin/deploy.sh` taken from the working copy, so that a change to
#         the deploy is tested before it is committed.
#   NOT RUN: a deploy. Nothing here reaches a host, opens a `.env`, needs a credential or touches the
#         network; every fixture lives under one temp dir.
#
# RED-FIRST, WITH CONTROLS. `control: the repository as it is` is the single variable every red below
# differs from: the same fixture, plus exactly one mutation. The content predicates each have a control
# of their own that differs from their red by the one line the gate judges.
#
# ⚠ A MUTATION THAT CHANGES NOTHING IS A FAILURE, NOT A PASS. Mutations of bin/deploy.sh are anchored
# on the definition line of a gate the declaration names (`gate_a11_trusted_proxies() {`). If that
# line moves or the gate is renamed, `mutate` reds on the spot rather than letting the case run over
# the unmutated script and read its green as the check's.
#
# ⚠ A CASE THIS RUNNER CANNOT BUILD IS NAMED, NOT COUNTED. Where the environment prevents constructing a
# case, `notverified` prints ⚠ NOT VERIFIED HERE saying which and why, the count is repeated in the
# summary, and the suite does not fail: nothing was measured, so there is no finding either way.
#
# RUN: bin/deploy-gate-inputs.selftest.sh          (exit 0 = every case passed; ⚠ lines name what did not run)

set -uo pipefail

HERE="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
CHECK="$HERE/deploy-gate-inputs.sh"
REPO="$(cd "$HERE/.." && pwd)"
[ -r "$CHECK" ] || { echo "selftest: $CHECK not found" >&2; exit 1; }
[ -r "$REPO/bin/deploy.sh" ] || { echo "selftest: $REPO/bin/deploy.sh not found" >&2; exit 1; }

T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT

# The assertion basics — ok, bad, notverified, eq, exits, has, hasnt, section and the counters — are
# bin/lib/selftest-assert.sh's, shared with bin/deploy.selftest.sh (card#11562). Every exit-code assertion
# is `exits`, so a red prints what the run said: deploy-gate-inputs.sh's 1 is every gate that refused, and
# a status alone cannot say which. Sourced FAIL-CLOSED: without them this suite has no assertions.
# ⛔ WHAT `exits` CAN PRINT HERE is deploy-gate-inputs.sh's output over a fixture built from this
# repository's tracked tree in a temp dir: the gates' own words and the paths they read. The fixture has
# no .env, and the check opens none (its header: NO CREDENTIAL).
# shellcheck source=bin/lib/selftest-assert.sh
. "$HERE/lib/selftest-assert.sh" || { echo "selftest: cannot source $HERE/lib/selftest-assert.sh" >&2; exit 1; }
declare -F ok bad notverified eq exits has hasnt section >/dev/null \
  || { echo "selftest: $HERE/lib/selftest-assert.sh did not define every assertion this suite uses" >&2; exit 1; }

# git, with an identity and no signing: the fixtures are commits, and a machine whose global config
# signs or names nobody must not turn this suite red for a reason that is not about the check.
G() { local d="$1"; shift; git -c user.email=selftest@invalid -c user.name=selftest \
        -c commit.gpgsign=false -c init.defaultBranch=main -C "$d" "$@"; }

# ── the base fixture: this repository's own tree at HEAD ───────────────────────────────────────
mkdir -p "$T/base"
git -C "$REPO" archive HEAD | tar -x -C "$T/base" || { echo "selftest: git archive HEAD failed" >&2; exit 1; }
cp "$REPO/bin/deploy.sh" "$T/base/bin/deploy.sh"
G "$T/base" init -q
G "$T/base" add -A
G "$T/base" commit -q -m "fixture: this repository at HEAD"
BASE_SHA="$(G "$T/base" rev-parse HEAD)"
DIR="$T/base"; OUT=""; RC=0

# One fixture repository, restored to its base commit before each case: `reset --hard <the base
# commit>` + `clean -fdx` against an ABSOLUTE sha — never `HEAD~1`, which would walk backwards through
# whatever the last case happened to commit.
mkcase() { G "$DIR" reset -q --hard "$BASE_SHA"; G "$DIR" clean -qfdx; }
# mutate <sed script> — edit the fixture's bin/deploy.sh, and red if the edit changed nothing.
mutate() {
  cp "$DIR/bin/deploy.sh" "$T/before"
  sed -i "$1" "$DIR/bin/deploy.sh"
  cmp -s "$T/before" "$DIR/bin/deploy.sh" \
    && bad "mutation '$1' changed nothing in bin/deploy.sh — its anchor has moved, so the case below tests nothing"
  return 0
}
run() { # run — commit $DIR's mutation and ask the checker about it
  G "$DIR" add -A >/dev/null
  G "$DIR" diff --cached --quiet || G "$DIR" commit -q -m mutation
  OUT="$(cd "$DIR" && bash "$CHECK" --ref HEAD 2>&1)"; RC=$?
  # VERBOSE=1 prints what each mutation actually made the check SAY.
  [ -z "${VERBOSE:-}" ] || { printf '    → exit %s\n' "$RC"
                             printf '%s' "$OUT" | grep -E '⛔|✅' | head -3 | sed 's/^/      /'; }
}
# The gate the mutations below are anchored in, by its definition line.
A11='/^gate_a11_trusted_proxies() {$/'

# ── the control ────────────────────────────────────────────────────────────────────────────────
section "CONTROL — the repository as it is"
mkcase; run
exits "control: exit 0"                                  0
has "control: says every declared gate passes"         "every target-tree gate bin/deploy.sh declares host-free passes" "$OUT"
has "control: the gates are RUN, not parsed"           "its target-tree gates, RUN over" "$OUT"
# Every row the fixture's own bin/deploy.sh declares host-free is reported as run — read out of that
# file by sourcing it, never listed here, so a new host-free row is covered the day it is declared.
# shellcheck disable=SC2016  # expanded by the bash that sources the fixture's deploy.sh
declared="$(cd "$DIR" && MEZZ_DEPLOY_ROOT="$DIR" bash -c '. bin/deploy.sh; for r in "${GATE_TREE_READERS[@]}"; do [ "${r#* }" != host-free ] || printf "%s\n" "${r%% *}"; done')"
n_declared=0; n_ran=0
while IFS= read -r fn; do
  [ -n "$fn" ] || continue
  n_declared=$((n_declared + 1))
  printf '%s\n' "$OUT" | grep -qE "^  passed +$fn\$" && n_ran=$((n_ran + 1))
done <<< "$declared"
eq  "control: every host-free row the fixture's bin/deploy.sh declares was run and passed (declared/ran)" \
    "$n_declared/$n_declared" "$n_declared/$n_ran"
has "control: the population is the ledger's — a migration the TREE names is listed as read" \
    "read  server/database/migrations/2026_08_25_100000_create_fleet_store_tables.php" "$OUT"
has "control: each git process was matched to the ledger"  "each one a git_at call in the ledger" "$OUT"
has "control: names the declared readers it does NOT run, in the deploy's words" "fpm_code_reload_ready — A14" "$OUT"
# The claim about what a green does NOT establish has ONE home — the block the check prints — and the
# workflow header and the changelog point at it by name. Deleting it would leave them pointing at
# nothing, which is how the four copies that preceded it all went stale; this is the assertion that reds.
has "control: prints what a green does NOT prove"      "WHAT A GREEN HERE PROVES, AND WHAT IT DOES NOT" "$OUT"
has "control: names the card that built the ledger"    "card#9745" "$OUT"

# ── findings about the RELEASE — exit 1, the gate named, and the deploy's own words ────────────
section "FINDINGS — a gate REFUSES this commit, so a real deploy of it would"

mkcase; rm -f "$DIR/server/package-lock.json"; run
exits "an absent lockfile: exit 1 (a finding, not a failure)" 1
has "absent lockfile: the refusing gate is named"      "REFUSES" "$OUT"
has "absent lockfile: …as the gate function"            "REFUSED  gate_a12_target_lockfile" "$OUT"
has "absent lockfile: in the deploy's own words"        "server/package-lock.json is missing from" "$OUT"
has "absent lockfile: says it is the deploy's gate, not a rule of this lane" "This is the deploy's OWN gate" "$OUT"

mkcase; : > "$DIR/server/composer.json"; run
exits "an empty composer.json: exit 1"                   1
has "empty composer.json: A6's refusal"                "server/composer.json is missing or empty" "$OUT"

mkcase; rm -f "$DIR/server/bootstrap/app.php"; ln -s ../top.txt "$DIR/server/bootstrap/app.php"; run
exits "app.php committed as a SYMLINK: exit 1"           1
has "symlink: the deploy's reader refuses it by its mode" "is a symbolic link at" "$OUT"

mkcase; rm -f "$DIR/bin/supervision.sh"; run
exits "no bin/supervision.sh: exit 1"                    1
has "no bin/supervision.sh: A13's target half refuses" "bin/supervision.sh is missing or empty" "$OUT"

# ⭐ THE CONTENT PREDICATES — what this lane did not assert before card#9745. Each red beside a control
# that differs by the one line the gate judges.
alter_events() { # alter_events <algorithm clause or nothing>
  cat > "$DIR/server/database/migrations/2099_01_01_000000_alter_events_selftest.php" <<MIG
<?php
// $1
return new class { public function up(): void { Schema::table('events', fn (\$t) => \$t->string('x')->nullable()); } };
MIG
}
mkcase; alter_events ""; run
exits "an ALTER on events with no ALGORITHM=: exit 1"    1
has "ALTER without ALGORITHM=: A10's refusal, naming the migration" "2099_01_01_000000_alter_events_selftest.php" "$OUT"
has "ALTER without ALGORITHM=: in the deploy's words"  "without stating an ALGORITHM" "$OUT"
mkcase; alter_events "ALGORITHM=INSTANT"; run
exits "the control: the same ALTER declaring ALGORITHM=INSTANT: exit 0" 0

mkcase
# shellcheck disable=SC2016  # PHP source, written as it is: `$m` is PHP's, not the shell's
printf '<?php return Application::configure()->withMiddleware(fn ($m) => $m->trustProxies(at: '"'"'*'"'"'))->create();\n' \
  > "$DIR/server/bootstrap/app.php"
run
exits "trustProxies('*'): exit 1"                        1
has "trustProxies('*'): A11's refusal"                 "trusts ALL proxies" "$OUT"
mkcase
# shellcheck disable=SC2016  # PHP source, written as it is: `$m` is PHP's, not the shell's
printf '<?php return Application::configure()->withMiddleware(fn ($m) => $m->trustProxies(at: '"'"'10.0.0.1'"'"'))->create();\n' \
  > "$DIR/server/bootstrap/app.php"
run
exits "the control: trustProxies naming one proxy: exit 0" 0
hasnt "the control: and no warning that none is configured" "no trustProxies() configured" "$OUT"

# ⭐ A WRITER THAT OUTLIVES grep (card#11562). A10 and A11 judge a file's text through text_matches. Its
# grep must read ALL of the text: one that left at its first match (`grep -q`) killed the writer still
# feeding it with SIGPIPE, and under `pipefail` a line that MATCHED read as one that did not — a refusal
# of a clean tree in one direction, a `trustProxies('*')` waved through in the other. That race needs
# host load to show, so these cases hold the writer 0.2 s after its first 2400 bytes, every time: the
# same three verdicts must come out. Each file puts the line that decides it in the first 2400 bytes and
# enough padding after it that the writer is still writing when an early grep would have gone.
LATE="$(cat <<'SED'
/^text_matches() {/s/printf '%s' "\$3" |/{ printf '%s' "${3:0:2400}"; sleep 0.2; printf '%s' "${3:2400}"; } |/
SED
)"
pad="$(for i in $(seq 1 60); do printf '// padding line %02d, past the 2400 bytes the writer is held after\n' "$i"; done)"
mkcase; mutate "$LATE"
# shellcheck disable=SC2016  # PHP source, written as it is: `$t` is PHP's, not the shell's
printf '<?php\n// ALGORITHM=INSTANT\n%s\nreturn new class { public function up(): void { Schema::table('"'"'events'"'"', fn ($t) => $t->string('"'"'x'"'"')->nullable()); } };\n' \
  "$pad" > "$DIR/server/database/migrations/2099_01_01_000000_alter_events_selftest.php"
run
exits "a held writer: an ALTER on events declaring ALGORITHM= early in a long file: exit 0" 0
mkcase; mutate "$LATE"
# shellcheck disable=SC2016  # PHP source, written as it is: `$t` is PHP's, not the shell's
printf '<?php\nreturn new class { public function up(): void { Schema::table('"'"'events'"'"', fn ($t) => $t->string('"'"'x'"'"')->nullable()); } };\n%s\n' \
  "$pad" > "$DIR/server/database/migrations/2099_01_01_000000_alter_events_selftest.php"
run
exits "a held writer: an ALTER on events early in a long file, with no ALGORITHM=: exit 1" 1
# On its offender line (`| <path>`), not merely anywhere: every path A10 reads is printed (`read  <path>`).
has "a held writer: A10's refusal, naming the migration" "| server/database/migrations/2099_01_01_000000_alter_events_selftest.php" "$OUT"
mkcase; mutate "$LATE"
# shellcheck disable=SC2016  # PHP source, written as it is: `$m` is PHP's, not the shell's
printf '<?php return Application::configure()->withMiddleware(fn ($m) => $m->trustProxies(at: '"'"'*'"'"'))->create();\n%s\n' \
  "$pad" > "$DIR/server/bootstrap/app.php"
run
exits "a held writer: trustProxies('*') early in a long app.php: exit 1" 1
has "a held writer: A11's refusal"                     "trusts ALL proxies" "$OUT"

mkcase; printf '{"lockfileVersion":9}\n' > "$DIR/server/package-lock.json"; run
exits "a lockfileVersion A12 cannot map: exit 1"         1
has "unmappable lockfileVersion: A12's refusal"        "which A12 cannot map to an npm floor" "$OUT"

# Every gate is run, not only the first to refuse: a PR author learns every refusal in one round.
mkcase; rm -f "$DIR/server/package-lock.json"; : > "$DIR/server/composer.json"; run
exits "two gates refuse at once: exit 1"                 1
has "two refusals: both are named"                     "gate_a6_target_php_floor gate_a12_target_lockfile" "$OUT"

# ── LEG 3: every git process of a gate run is a git_at call ────────────────────────────────────
section "THE LEDGER — every git process a gate starts is one git_at recorded"

mkcase
# shellcheck disable=SC2016  # injected source
mutate "${A11}a \\  git -C \"\$DEPLOY_ROOT\" cat-file -e \"\$1:server/artisan\""
run
exits "a BARE git call inside a gate: exit 2"            2
has "bare git: named as a git process with no git_at call, by its arguments" \
    "git process with NO git_at call:   git -C" "$OUT"
has "bare git: …which are the ones it was given"       "cat-file -e" "$OUT"

mkcase
# shellcheck disable=SC2016  # injected source
mutate 's/^  git -C "\$DEPLOY_ROOT" "\$@"$/  command -p git -C "$DEPLOY_ROOT" "$@"/'
run
exits "git_at itself bypassing the shim (a git found without PATH): exit 2" 2
has "shim bypassed: every call is named as one no git process answered" \
    "git_at call that NO git process answered through the shim" "$OUT"

mkcase
# shellcheck disable=SC2016  # injected source
mutate "${A11}i extra_reader() { local x; git_read_at x \"\$1\" server/artisan || true; }"
# shellcheck disable=SC2016  # injected source
mutate "${A11}a \\  extra_reader \"\$1\""
run
exits "a gate reading through a helper the declaration does not name: exit 2" 2
has "undeclared helper: named, with the path it read" \
    "gate_a11_trusted_proxies read server/artisan through extra_reader, which bin/deploy.sh" "$OUT"

mkcase
# shellcheck disable=SC2016  # injected source
mutate "${A11}a \\  local __h; git_read_at __h HEAD server/artisan || true"
run
exits "a host-free gate reading a rev that is not the commit: exit 2" 2
has "another rev: named, as a read of this host's checkout" "gate_a11_trusted_proxies read HEAD:server/artisan" "$OUT"

# ── the declaration ────────────────────────────────────────────────────────────────────────────
section "THE DECLARATION — what is run is what bin/deploy.sh declares, and it must be there to run"

mkcase
mutate '/^GATE_TREE_READERS=(/,/^)$/d'
run
exits "no GATE_TREE_READERS: exit 2"                     2
has "no declaration: says so"                          "declares no GATE_TREE_READERS" "$OUT"

mkcase
mutate "s/^  'gate_a11_trusted_proxies host-free'\$/  'gate_a11_trusted_proxies host-free'\n  'gate_nowhere host-free'/"
run
exits "a declared function that is not defined: exit 2"  2
has "undefined: named"                                 "declares gate_nowhere in GATE_TREE_READERS and defines no such function" "$OUT"

mkcase
mutate "s/^  'gate_a11_trusted_proxies host-free'\$/  'gate_a11_trusted_proxies host-free'\n  'gate_reads_nothing host-free'/"
printf '%s\n' 'gate_reads_nothing() { :; }' >> "$DIR/bin/deploy.sh"
run
exits "a declared reader that reads nothing: exit 2"     2
has "reads nothing: named as stale"                    "gate_reads_nothing is declared a reader of the release" "$OUT"

mkcase
mutate "s/^\\(  '[a-z0-9_]*\\) host-free'\$/\\1 not run by this case'/"
run
exits "no host-free row at all: exit 2"                  2
has "no host-free row: an empty population is refused" "declares no host-free reader" "$OUT"

mkcase; rm -f "$DIR/bin/deploy.sh"; run
exits "no bin/deploy.sh at the commit: exit 2"           2
has "no deploy script: says nothing was measured"      "is not readable at" "$OUT"

# ── could not speak ────────────────────────────────────────────────────────────────────────────
section "COULD NOT SPEAK — a failure of the check is never a finding about the release"

# A read of git that FAILED is not a missing file: the deploy refuses it as not established, and here
# that is this check's exit 2. The object is made unreadable in the fixture's store. Two runners cannot
# build that, and each is named rather than counted as a pass or a fail: one where git has PACKED the
# blob (its own housekeeping — `gc --auto`, a clone arriving as a pack — decides that, never the change
# under test), so there is no per-object file to make unreadable; and one where root reads every mode.
# ⛔ Forcing the object loose would only move this dependency on git's storage layout one layer down.
mkcase; run
blob="$(G "$DIR" rev-parse HEAD:server/composer.json)"
obj="$DIR/.git/objects/${blob:0:2}/${blob:2}"
if [ ! -f "$obj" ]; then
  notverified "the fixture's server/composer.json blob is packed (no loose object at $obj), so the failed-read case did not run"
else
  chmod 000 "$obj"
  if G "$DIR" cat-file -p "$blob" >/dev/null 2>&1; then
    notverified "a git object at mode 000 is still readable on this runner (root?), so the failed-read case did not run"
  else
    OUT="$(cd "$DIR" && bash "$CHECK" --ref HEAD 2>&1)"; RC=$?
    exits "a read of the release git could not complete: exit 2, NOT 1" 2
    has "failed read: named as not established, not as a finding" "could not establish a read of" "$OUT"
  fi
  chmod 644 "$obj"
fi

# The check CRASHING must not be readable as a finding. `set -E` + the ERR trap keep the two apart, and
# `-E` without a trap is an inert flag, so the trap is watched to fire.
mkcase
mkdir -p "$T/stub"; printf '#!/bin/sh\nexit 3\n' > "$T/stub/mktemp"; chmod +x "$T/stub/mktemp"
OUT="$(cd "$DIR" && PATH="$T/stub:$PATH" bash "$CHECK" --ref HEAD 2>&1)"; RC=$?
exits "a tool the check needs fails: exit 2, NOT 1"      2
has "a crash says it established nothing, and is not a finding" "established NOTHING" "$OUT"

# ── the invocation ─────────────────────────────────────────────────────────────────────────────
# A mistyped COMMAND LINE must not be readable as a finding either (card#9831).
section "THE INVOCATION — a mistyped command line is never a verdict about the release"

mkcase
OUT="$(cd "$DIR" && bash "$CHECK" --ref 2>&1)"; RC=$?
exits "--ref with no value: exit 2, NOT 1"               2
has "--ref with no value: stops with the banner"       "⛔ deploy-gate-inputs.sh — --ref needs a value" "$OUT"

OUT="$(cd "$DIR" && bash "$CHECK" --ref '' 2>&1)"; RC=$?
exits "--ref with an empty value: exit 2, NOT 1"         2
has "--ref with an empty value: stops with the banner" "⛔ deploy-gate-inputs.sh — --ref needs a value" "$OUT"

OUT="$(cd "$DIR" && bash "$CHECK" --reff HEAD 2>&1)"; RC=$?
exits "an unknown argument: exit 2"                      2
has "an unknown argument: stops with the banner, naming it" "⛔ deploy-gate-inputs.sh — unknown argument: --reff" "$OUT"

printf '\n──────────────────────────────────────────────\n'
if [ "$unverified" -gt 0 ]; then
  printf '⚠ %d case(s) could NOT BE BUILT on this runner and did not run; each is named above with why.\n' "$unverified" >&2
fi
if [ "$fails" -eq 0 ]; then
  printf 'deploy-gate-inputs.selftest.sh: %d assertions, all passed\n' "$cases"; exit 0
fi
printf 'deploy-gate-inputs.selftest.sh: %d assertions, %d FAILED\n' "$cases" "$fails" >&2; exit 1

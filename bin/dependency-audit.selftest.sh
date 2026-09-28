#!/usr/bin/env bash
# dependency-audit.selftest.sh — proves the shipped dependency-advisory gate CAN FAIL, fails
# for the right reason, and DISTINGUISHES an affected lockfile from a clean one (a check that
# cannot fail is a decoration; a check that reds on everything is one too).
#
# WHAT IT RUNS. Every leg executes the SHIPPED YAML's own shell, extracted verbatim from
# between the `# --- <name>` markers of .github/workflows/dependency-audit.yml and
# .github/workflows/dependency-ref-matrix.yml. Nothing here re-implements the audit, so an assertion
# cannot keep passing against logic the templates no longer ship.
#
# ZERO NETWORK IN EVERY LEG. The three network seams (`osv_scan`, `advisory_fetch`,
# `registry_repo_lookup`) live in the yml's CUSTOMIZE zone precisely so they can be replaced
# here by fixture readers. What that means for coverage, stated plainly rather than implied:
# the SHIPPED seam bodies (a `gh api` call, a registry `curl`) are NOT executed by this suite —
# only their contract is asserted (they exist, and the block treats a non-zero exit and a
# non-array payload as RED). The first live run is what exercises them.
#
# WHAT "RED" MEANS, and where the list of reasons lives: the coord plugin's
# `docs/DEPENDENCY-AUDIT.md` § "Failure semantics" owns it, per code — this header does not
# restate it. The one part that belongs HERE is what the legs assert ON: the EXPORTED
# POPULATION (`errors`, `error_codes`), never the stderr log text. `err` prints AND increments
# a counter, nothing makes those agree, and a grep on the log is satisfied by an error the run
# never recorded — which is how a subshell-swallowed error once read as a clean run.
#
# THE CONTROLS. Three mutants: two (§M) delete a fail-closed branch from the extracted block
# and require the corresponding leg to go GREEN — without them, a fail-closed leg could be
# passing because the fixture is odd rather than because the shipped logic is closed — and one
# (§X) re-mints the swallowed-error defect and requires the class guard to catch it.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# MEZZANINE: the template keeps the workflows and fixtures beside this file; this repo keeps the
# workflows under .github/workflows/ and the fixtures beside the script, as bin/pr-body-lint-fixtures/.
ROOT="$(cd "$HERE/.." && pwd)"
CALLEE="$ROOT/.github/workflows/dependency-audit.yml"
CALLER="$ROOT/.github/workflows/dependency-ref-matrix.yml"
FIX="$HERE/dependency-audit-fixtures"
FAILURES=0

# MEMBERSHIP IS A `case` — NOT A PIPE INTO `grep -q`, AND NOT A HERESTRING EITHER (card#8080).
# `grep -q` exits on its FIRST match and closes the pipe, so the writer takes SIGPIPE and dies
# 141; under `pipefail` the PIPELINE is 141, and a TRUE membership comes back FALSE. Every
# assertion below is a membership question about `$out`, an audit report that grows with the
# population it describes, so the size gate is a matter of fixtures rather than of principle.
# The herestring these lines USED to be (`grep -q PAT <<< "$out"`) removes the SIGPIPE and buys
# the worse defect: MSYS blocks on a herestring past its own ~64 KiB window, turning a silent
# wrong answer into a HUNG test on the fleet's Windows seat (ci-verdict.sh § (b), card#7692).
# Byte-identical to `plugins/coord/hooks/bin/_selftest_match.sh`, which owns the argument and the
# measurement; IN-FILE rather than sourced because this file SHIPS into an adopter's repo, where
# that path is not one it can name. Two FRAMEWORK-REPO guards keep it honest — neither ships, so
# nothing in your install runs either and nothing here depends on them being present:
# `.githooks/pipe-grep-membership.selftest.py` reds if any copy goes back to the pipe (or to the
# refused herestring), and `.githooks/membership-primitive-drift.selftest.py` reds if this copy
# stops being byte-identical to the authority.
has() { case "$1" in *"$2"*) return 0 ;; *) return 1 ;; esac; }

fail() { echo "  FAIL: $1"; FAILURES=$((FAILURES + 1)); }
ok()   { echo "  ok:   $1"; }
finish() {
  if [ "$FAILURES" -gt 0 ]; then echo "dependency-audit selftest: FAIL ($FAILURES)"; exit 1; fi
  echo "dependency-audit selftest: all checks pass"; exit 0
}

for tool in jq sed awk find; do
  # A missing prerequisite is NAMED, never skipped: a silent skip reports a green suite that
  # ran none of these legs. jq is already a CI prerequisite for this repo's other guards.
  command -v "$tool" >/dev/null 2>&1 || { fail "PREREQUISITE MISSING: $tool is not on PATH; this suite does not skip legs"; finish; }
done

extract() {  # $1=file $2=block-name -> the block body, de-indented by 10
  sed -n "/--- $2\$/,/--- end $2\$/p" "$1" \
    | grep -v -- "--- $2\$" \
    | grep -v -- "--- end $2\$" \
    | sed 's/^          //'
}

# ── §E extraction controls: every leg below is worthless if these are empty ──────────────
BLOCKS=""
for name in dep-audit-lib dep-audit-discovery dep-audit-source1 dep-audit-source2 \
            dep-audit-report dep-audit-main; do
  body="$(extract "$CALLEE" "$name")"
  if [ -z "$body" ]; then
    fail "extraction of '$name' returned EMPTY — the yml markers moved; this selftest is testing nothing"
    finish
  fi
  BLOCKS="$BLOCKS
$body"
done
ok "6 blocks extracted from the shipped callee ($(printf '%s' "$BLOCKS" | wc -l) lines)"
if ! bash -n <(printf 'set -euo pipefail\n%s\n' "$BLOCKS") 2>/dev/null; then
  fail "the extracted callee blocks do not PARSE — every leg below would red on a syntax error"
  finish
fi
ok "extracted callee blocks parse"

REPORT_BLOCK="$(extract "$CALLER" "dep-matrix-report-assert")"
[ -n "$REPORT_BLOCK" ] || { fail "extraction of 'dep-matrix-report-assert' returned EMPTY — the caller's markers moved"; finish; }
ok "reporting-job block extracted from the shipped caller ($(printf '%s' "$REPORT_BLOCK" | wc -l) lines)"

# ── §S the SHIPPED seams: not executed here (they are the network), contract asserted ────
SEAMS="$(sed -n '/CUSTOMIZE-BEGIN audit-seams/,/CUSTOMIZE-END audit-seams/p' "$CALLEE")"
missing=""
for fn in 'osv_scan()' 'advisory_fetch()' 'registry_repo_lookup()'; do
  case "$SEAMS" in *"$fn"*) :;; *) missing="$missing $fn";; esac
done
if [ -n "$missing" ]; then
  fail "the shipped CUSTOMIZE zone no longer defines:$missing — the blocks below call them, so the template would fail at run time while this suite's stubs kept it green"
else
  ok "shipped CUSTOMIZE zone defines all three network seams (their bodies are NOT run here — no network)"
fi

# ── the harness: a fixture tree + fixture seams + the extracted blocks ────────────────────
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
: > "$TMP/swallowed"

run_audit() {
  # $1 = repo dir, $2 = seam-prelude file, $3 = control repo ("" = the fixture default),
  # $4 = block text ("" = the shipped blocks)
  local repo="$1" prelude="$2" ctrl="${3:-thephpleague/commonmark}" blocks="${4:-$BLOCKS}"
  local rc=0
  : > "$TMP/gh_output" ; : > "$TMP/step_summary"
  (
    cd "$repo" || exit 97
    GITHUB_OUTPUT="$TMP/gh_output" GITHUB_STEP_SUMMARY="$TMP/step_summary" \
    AUDIT_REF="fixture-ref" \
    bash -c "set -euo pipefail
LOCKFILE_NAMES=\"${AUDIT_LOCKFILE_NAMES:-composer.lock package-lock.json}\"
EXCLUDE_DIRS=\"sboms vendor node_modules .git\"
ADVISORY_CONTROL_REPO=\"$ctrl\"
$(cat "$prelude")
$blocks" 2>&1
  ) > "$TMP/audit_out" || rc=$?
  # ⛔ THE CLASS GUARD, applied to EVERY leg rather than to the legs someone remembered.
  # `err` does two things — print, and increment the counter the verdict and the exported
  # population read — and nothing makes those agree. When they disagree, the run has PRINTED an
  # error it did not RECORD, which is a green over a source that failed. That shipped once, from
  # an `err` reached only inside `$(...)`, where the increment died with the subshell.
  # Recorded to a FILE, not asserted here: run_audit is itself called inside `$(...)`, so a
  # `fail` raised here would be discarded by the very mechanism this guard exists to catch.
  if grep -q 'AUDIT-ERROR\[' "$TMP/audit_out"; then
    local e; e="$(pop errors)"
    [[ $e =~ ^[0-9]+$ ]] && [ "$e" -ge 1 ] \
      || printf 'repo=%s printed AUDIT-ERROR but exported errors=%s\n' "$repo" "'$e'" >> "$TMP/swallowed"
  fi
  cat "$TMP/audit_out"
  return "$rc"
}

seam() {  # writes a prelude file from the named behaviours: $1=osv $2=advisory $3=registry
  local f="$TMP/seam.sh"
  cat > "$f" <<PRELUDE
FIX="$FIX"
osv_scan() { $1 }
advisory_fetch() { $2 }
registry_repo_lookup() { $3 }
PRELUDE
  printf '%s' "$f"
}

OSV_CLEAN='printf %s "{\"results\":[]}";'
OSV_GARBAGE='printf %s "osv-scanner: could not open lockfile"; return 3;'
OSV_ERR='return 127;'
OSV_FINDING='printf %s "{\"results\":[{\"source\":{\"path\":\"composer.lock\"},\"packages\":[{\"package\":{\"ecosystem\":\"Packagist\",\"name\":\"some/pkg\",\"version\":\"1.0.0\"},\"vulnerabilities\":[{\"id\":\"GHSA-glob-al00-0001\"}]}]}]}";'
# shellcheck disable=SC2016  # MEZZANINE: single-quoted on purpose: this is shell text expanded later, inside the seam or by sed/grep, never here
ADV_FIXTURE='cat "$FIX/advisories/$(printf %s "$1" | tr / @).json";'
# shellcheck disable=SC2016  # MEZZANINE: single-quoted on purpose: this is shell text expanded later, inside the seam or by sed/grep, never here
ADV_FAIL_DEP='case "$1" in fixture-org/control-repo) cat "$FIX/advisories/fixture-org@control-repo.json";; *) return 22;; esac'
# shellcheck disable=SC2016  # MEZZANINE: single-quoted on purpose: this is shell text expanded later, inside the seam or by sed/grep, never here
ADV_RATELIMIT='case "$1" in fixture-org/control-repo) cat "$FIX/advisories/fixture-org@control-repo.json";; *) printf %s "{\"message\":\"API rate limit exceeded\"}";; esac'
ADV_EMPTY_CONTROL='printf %s "[]";'
REG_NONE='return 1;'
# shellcheck disable=SC2016  # MEZZANINE: single-quoted on purpose: this is shell text expanded later, inside the seam or by sed/grep, never here
REG_ONE='case "$1" in resolvable-pkg) printf %s "fixture-org/control-repo";; *) return 1;; esac'

mkrepo() {  # $1=name, then pairs of "src:dest"
  local d="$TMP/$1"; shift; mkdir -p "$d"
  local pair
  for pair in "$@"; do mkdir -p "$d/$(dirname "${pair#*:}")"; cp "$FIX/${pair%%:*}" "$d/${pair#*:}"; done
  printf '%s' "$d"
}

AFFECTED="$(mkrepo affected composer.lock.affected:composer.lock)"
CLEAN="$(mkrepo clean composer.lock.clean:composer.lock)"
EMPTY="$(mkrepo empty)"
NPM="$(mkrepo npm package-lock.json.npm:package-lock.json)"
NOPKGS="$(mkrepo nopkgs composer.lock.empty:composer.lock)"
CORRUPT="$(mkrepo corrupt composer.lock.corrupt:composer.lock)"

pop() { jq -r --arg k "$1" '(.[$k] // "MISSING") | tostring' <<< "$(sed -n 's/^population=//p' "$TMP/gh_output")"; }
# Membership in an exported ARRAY field. Gated on jq's EXIT STATUS, so a withheld or
# unparseable population is a NO rather than an empty string that compares equal to nothing.
pop_has() { jq -e --arg k "$1" --arg v "$2" '(.[$k] // []) | index($v) != null' \
              <<< "$(sed -n 's/^population=//p' "$TMP/gh_output")" >/dev/null 2>&1; }

# ══ §1 THE DISCRIMINATING PAIR — the same package at two versions ════════════════════════
out="$(run_audit "$AFFECTED" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE")")"; rc=$?
if [ "$rc" -ne 0 ] \
   && has "$out" 'GHSA-f8fg-pg57-v4j8' \
   && [ "$(grep -c 'SOURCE2 league/commonmark' <<< "$out")" -eq 4 ]; then
  ok "MUST-RED leg: commonmark 2.9.0 reds (rc=$rc) on all FOUR repo-level HIGH advisories, the XSS named"
else
  fail "MUST-RED leg did not red on the four known advisories (rc=$rc): $out"
fi
# shellcheck disable=SC2015  # MEZZANINE: ok/fail only print and count, so C runs only when A is false — the intended if/else
[ "$(pop findings)" = "4" ] && ok "…and the population output carries findings=4" \
  || fail "population findings should be 4, got '$(pop findings)'"
# shellcheck disable=SC2015  # MEZZANINE: ok/fail only print and count, so C runs only when A is false — the intended if/else
has "$out" 'POPULATION STATEMENT' \
  && ok "…and the POPULATION STATEMENT is still emitted on a RED run" \
  || fail "the population statement must be emitted even when the run reds: $out"
# ORDER, asserted on the SHIPPED driver rather than on the captured stream: stdout and stderr
# are merged into one pipe here, and their interleaving is a buffering artefact, not evidence.
# This reds if a later edit moves the statement after the verdict in the yml itself.
MAIN_BLOCK="$(extract "$CALLEE" dep-audit-main)"
stmt_line="$(grep -n '^ *population_statement' <<< "$MAIN_BLOCK" | head -1 | cut -d: -f1)"
verd_line="$(grep -n '^ *verdict' <<< "$MAIN_BLOCK" | head -1 | cut -d: -f1)"
if [ -n "$stmt_line" ] && [ -n "$verd_line" ] && [ "$stmt_line" -lt "$verd_line" ]; then
  ok "…and the shipped driver calls population_statement BEFORE verdict (line $stmt_line < $verd_line)"
else
  fail "the shipped driver must emit the population statement before any verdict (statement@${stmt_line:-none}, verdict@${verd_line:-none})"
fi
# shellcheck disable=SC2015  # MEZZANINE: ok/fail only print and count, so C runs only when A is false — the intended if/else
grep -q 'POPULATION STATEMENT' "$TMP/step_summary" \
  && ok "…and it reaches the step summary, not only stdout" \
  || fail "the statement never reached GITHUB_STEP_SUMMARY (the aimla lesson: a summary written after an exit is a summary nobody sees)"

out="$(run_audit "$CLEAN" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE")")"; rc=$?
if [ "$rc" -eq 0 ] && has "$out" 'VERDICT: GREEN'; then
  ok "MUST-GREEN leg: the same lockfile at 2.10.0 passes (rc=0) — the check DISTINGUISHES, it does not merely refuse"
else
  fail "MUST-GREEN leg should pass at 2.10.0 (rc=$rc): $out"
fi
# shellcheck disable=SC2015  # MEZZANINE: ok/fail only print and count, so C runs only when A is false — the intended if/else
[ "$(pop status)" = "audited" ] && [ "$(pop manifests)" = "1" ] && [ "$(pop packages)" = "2" ] \
  && [ "$(pop resolved)" = "1" ] && [ "$(pop unresolved)" = "1" ] \
  && ok "…and the population output names what was read (1 lockfile, 2 production packages, 1 resolved, 1 not)" \
  || fail "clean-leg population wrong: $(sed -n 's/^population=//p' "$TMP/gh_output")"
# shellcheck disable=SC2015  # MEZZANINE: ok/fail only print and count, so C runs only when A is false — the intended if/else
has "$out" 'commonmark-devonly' \
  && fail "a packages-dev entry leaked into the production population" \
  || ok "…and the dev-only package is excluded (2 packages, not 3)"
# shellcheck disable=SC2015  # MEZZANINE: ok/fail only print and count, so C runs only when A is false — the intended if/else
has "$out" 'UNRESOLVED PACKAGES' \
  && ok "…and the unresolved package is NAMED in the statement, not dropped" \
  || fail "an unresolved package must be named in the population statement: $out"
# shellcheck disable=SC2015  # MEZZANINE: ok/fail only print and count, so C runs only when A is false — the intended if/else
pop_has supported_manifests composer.lock \
  && ok "…and the exported population NAMES the manifest source 2 could read" \
  || fail "supported_manifests should carry composer.lock, got '$(pop supported_manifests)'"

# ══ §2 FAIL-CLOSED LEGS — each seen to fail, with its own named reason ════════════════════
red_because() {  # $1=label $2=repo $3=seam-prelude $4=expected code [$5=control repo, BARE owner/repo]
  local out rc errs
  # $5 is forwarded POSITIONALLY into run_audit's control slot, so it must be a bare
  # `<owner>/<repo>`. A `NAME=value` here is someone writing it as an env assignment: the whole
  # string becomes the repo NAME, no fixture seam matches, the CONTROL read fails, and the leg
  # reds on SOURCE2-CONTROL-FAILED instead of the reason under test — green for the wrong
  # reason, and blind to the defect it exists to catch. That shipped once. Refuse the SHAPE
  # here rather than re-checking each call site.
  case "${5:-}" in
    *=*) fail "$1: control-repo argument '$5' is an env assignment, not a bare <owner>/<repo> — this leg would red on the CONTROL read, not on the reason under test"; return 0 ;;
  esac
  out="$(run_audit "$2" "$3" "${5:-}")"; rc=$?
  errs="$(pop errors)"
  # ⛔ ASSERT ON THE EXPORTED POPULATION, NEVER ON THE LOG TEXT. A `printf` to stderr and the
  # counter that drives the verdict are two different facts, and only the counter is what the
  # caller's reporting job reads. A grep for the log line is satisfied by an error that
  # incremented nothing — which is exactly how a swallowed error once read as a clean run.
  if [ "$rc" -ne 0 ] && [[ $errs =~ ^[0-9]+$ ]] && [ "$errs" -ge 1 ] && pop_has error_codes "$4"; then
    ok "$1 → RED with AUDIT-ERROR[$4] RECORDED in the population (errors=$errs, rc=$rc)"
  else
    fail "$1 should red with AUDIT-ERROR[$4] recorded in the population (rc=$rc, errors='$errs', codes='$(pop error_codes)'): $out"
  fi
}

red_because "advisory FETCH error on a dependency (control still readable)" \
  "$AFFECTED" "$(seam "$OSV_CLEAN" "$ADV_FAIL_DEP" "$REG_NONE")" SOURCE2-FETCH-FAILED \
  fixture-org/control-repo
red_because "advisory response that is NOT a JSON array (a rate-limit body)" \
  "$AFFECTED" "$(seam "$OSV_CLEAN" "$ADV_RATELIMIT" "$REG_NONE")" SOURCE2-UNPARSEABLE \
  fixture-org/control-repo
red_because "the source-2 CONTROL comes back EMPTY (token cannot read another org's advisories)" \
  "$CLEAN" "$(seam "$OSV_CLEAN" "$ADV_EMPTY_CONTROL" "$REG_NONE")" SOURCE2-CONTROL-EMPTY
red_because "scanner output that does not parse" \
  "$CLEAN" "$(seam "$OSV_GARBAGE" "$ADV_FIXTURE" "$REG_NONE")" SOURCE1-UNPARSEABLE
red_because "scanner exits non-zero with NO output at all" \
  "$CLEAN" "$(seam "$OSV_ERR" "$ADV_FIXTURE" "$REG_NONE")" SOURCE1-UNPARSEABLE
red_because "a ref carrying NO lockfiles (a green here would be a green over nothing)" \
  "$EMPTY" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE")" NO-MANIFESTS
red_because "a lockfile that does not parse (never read as 'no dependencies')" \
  "$CORRUPT" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE")" SOURCE2-LOCKFILE-UNPARSEABLE
red_because "a readable lockfile that yields ZERO production packages" \
  "$NOPKGS" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE")" NO-PACKAGES
red_because "npm packages present and NOT ONE resolves (the registry hop is broken)" \
  "$NPM" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE")" SOURCE2-NPM-RESOLUTION-EMPTY
# a candidate repo token that is not <owner>/<repo>: NAMED and RED, never quietly demoted to
# "unresolved", which would shrink source 2's population by exactly one package per bad token
BADREPO="$(mkrepo badrepo composer.lock.clean:composer.lock)"
sed -i 's|github.com/thephpleague/commonmark.git|github.com/the;evil league/commonmark.git|' \
  "$BADREPO/composer.lock"
red_because "a lockfile whose repo token is not <owner>/<repo> (never silently unresolved)" \
  "$BADREPO" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE")" SOURCE2-REPO-TOKEN-INVALID

# the no-manifests run must STILL export a population, so the caller's reporting job can red
out="$(run_audit "$EMPTY" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE")")"
# shellcheck disable=SC2015  # MEZZANINE: ok/fail only print and count, so C runs only when A is false — the intended if/else
[ "$(pop manifests)" = "0" ] && [ "$(pop status)" = "error" ] \
  && ok "…and the empty-tree run still EXPORTS its population (manifests=0, status=error) for the reporting job" \
  || fail "an audit that found nothing must still export a population naming the nothing: $(cat "$TMP/gh_output")"

# an unrecognised range form must never read as "not affected"
mkdir -p "$TMP/badrange/advisories"
jq '[.[0] | .vulnerabilities[0].vulnerable_version_range = "sometime after 1.0"]' \
  "$FIX/advisories/thephpleague@commonmark.json" > "$TMP/badrange/advisories/thephpleague@commonmark.json"
cp "$FIX/advisories/fixture-org@control-repo.json" "$TMP/badrange/advisories/"
# shellcheck disable=SC2016  # MEZZANINE: single-quoted on purpose: this is shell text expanded later, inside the seam or by sed/grep, never here
ADV_BADRANGE='cat "'"$TMP"'/badrange/advisories/$(printf %s "$1" | tr / @).json";'
out="$(run_audit "$CLEAN" "$(seam "$OSV_CLEAN" "$ADV_BADRANGE" "$REG_NONE")" fixture-org/control-repo)"; rc=$?
if [ "$rc" -ne 0 ] && has "$out" 'AUDIT-ERROR[SOURCE2-RANGE-UNPARSEABLE]'; then
  ok "an advisory range form the audit cannot parse → RED, never a quiet 'not affected'"
else
  fail "an unparseable range must red (rc=$rc): $out"
fi

# a version the audit cannot compare, on a package that HAS advisories
DEVVER="$(mkrepo devver composer.lock.clean:composer.lock)"
sed -i 's/"version": "2.10.0"/"version": "dev-main"/' "$DEVVER/composer.lock"
out="$(run_audit "$DEVVER" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE")")"; rc=$?
if [ "$rc" -ne 0 ] && has "$out" 'AUDIT-ERROR[SOURCE2-VERSION-UNCOMPARABLE]'; then
  ok "a version that cannot be compared against a package's advisories → RED, not 'unaffected'"
else
  fail "an uncomparable version must red (rc=$rc): $out"
fi

# source 1 must be able to CONTRIBUTE a finding — otherwise leg §1's green proves only that
# source 1 is silent, and a scanner wired to nothing would pass every leg above
out="$(run_audit "$CLEAN" "$(seam "$OSV_FINDING" "$ADV_FIXTURE" "$REG_NONE")")"; rc=$?
# The ONE site here that is a REGEX and not a membership test: `.*` has to stay per-LINE, which
# `has` cannot express and bash's `[[ =~ ]]` gets WRONG in the dangerous direction (its `.`
# matches a newline, so the two halves could be matched on different lines of the report). So
# the pattern is unchanged and only its INPUT moves — off the herestring (card#7692's MSYS
# block) and onto a file, which `grep` reads directly with no pipe and no writer to SIGPIPE.
printf '%s\n' "$out" > "$TMP/source1.out"
if [ "$rc" -ne 0 ] && grep -q 'SOURCE1 .*GHSA-glob-al00-0001' "$TMP/source1.out" \
   && [ "$(pop findings)" = "1" ]; then
  ok "a SOURCE 1 finding reds on its own and is counted (source 1 is wired, not decorative)"
else
  fail "a source-1 finding must red and be counted (rc=$rc): $out"
fi

# a seam that reads stdin must not eat the loop driving it — a truncated population is the
# silent-shrinkage class this whole gate exists to prevent, and it would look like a clean run
# shellcheck disable=SC2016  # MEZZANINE: single-quoted on purpose: this is shell text expanded later, inside the seam or by sed/grep, never here
ADV_SLURP='cat >/dev/null; cat "$FIX/advisories/$(printf %s "$1" | tr / @).json";'
out="$(run_audit "$AFFECTED" "$(seam "$OSV_CLEAN" "$ADV_SLURP" "$REG_NONE")")"; rc=$?
if [ "$rc" -ne 0 ] && [ "$(grep -c 'SOURCE2 league/commonmark' <<< "$out")" -eq 4 ]; then
  ok "a stdin-consuming advisory seam does NOT truncate the package loop (still 4 findings)"
else
  fail "a seam that reads stdin truncated the population (rc=$rc): $out"
fi

# the npm partial-resolution case is REPORTED, not red — the other half of the same rule
out="$(run_audit "$NPM" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_ONE")")"; rc=$?
if [ "$rc" -eq 0 ] && [ "$(pop unresolved)" = "1" ] && [ "$(pop resolved)" = "1" ] \
   && has "$out" 'unresolvable-pkg'; then
  ok "npm packages that do not resolve are REPORTED in the statement (1 of 2) and do not red on their own"
else
  fail "a partially-resolving npm population should report, not red (rc=$rc): $out"
fi

# an ecosystem source 2 has NO resolver for is NAMED and EXPORTED, never dropped and never
# red on its own: whether that coverage gap fails the build is the ADOPTER's policy, and it
# can only be their policy if the fact leaves the run as data rather than as a log line.
UNSUP="$(mkrepo unsup composer.lock.clean:composer.lock composer.lock.clean:sub/Gemfile.lock)"
AUDIT_LOCKFILE_NAMES="composer.lock Gemfile.lock"
out="$(run_audit "$UNSUP" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE")")"; rc=$?
AUDIT_LOCKFILE_NAMES=""
if [ "$rc" -eq 0 ] && pop_has unsupported_manifests sub/Gemfile.lock \
   && pop_has supported_manifests composer.lock \
   && has "$out" 'has no resolver for: sub/Gemfile.lock'; then
  ok "a manifest source 2 cannot resolve is EXPORTED (unsupported_manifests) and named in the statement, without reding on its own"
else
  fail "the unsupported-ecosystem manifest must be exported and named (rc=$rc, unsupported='$(pop unsupported_manifests)'): $out"
fi

# ══ §M MUTANTS — prove the two load-bearing fail-closed legs bind the SHIPPED logic ═══════
MUT_RANGE="$(printf '%s\n' "$BLOCKS" | sed 's/then "RANGE-UNPARSEABLE"/then false/g; s/else "RANGE-UNPARSEABLE"/else false/g')"
if [ "$MUT_RANGE" = "$BLOCKS" ]; then
  fail "range mutation changed nothing — the fail-closed range leg above is uncontrolled"
elif ! bash -n <(printf 'set -euo pipefail\n%s\n' "$MUT_RANGE") 2>/dev/null; then
  fail "mutated block does not parse — its verdict would prove nothing"
else
  out="$(run_audit "$CLEAN" "$(seam "$OSV_CLEAN" "$ADV_BADRANGE" "$REG_NONE")" fixture-org/control-repo "$MUT_RANGE")"; rc=$?
  if [ "$rc" -eq 0 ]; then
    ok "mutant control: with the fail-closed range branch removed, the unparseable range goes GREEN — so that leg reds on the SHIPPED logic, not on the fixture"
  else
    fail "the range mutant should have gone green (rc=$rc); the fail-closed leg may be reding for an unrelated reason: $out"
  fi
fi

# keyed on the EXECUTABLE comparison, and required to hit exactly ONE site — a mutation that
# rewrote several conditions would red for a reason that is not the one under test.
n_sites="$(grep -c -- '-lt 1 \]; then' <<< "$BLOCKS")"
MUT_CTRL="$(printf '%s\n' "$BLOCKS" | sed "s/-lt 1 \]; then/-lt 0 ]; then/")"
if [ "$n_sites" -ne 1 ]; then
  fail "the control-probe mutation matched $n_sites sites, not 1 — it no longer isolates the empty-control check"
elif [ "$MUT_CTRL" = "$BLOCKS" ]; then
  fail "control-probe mutation changed nothing — the SOURCE2-CONTROL-EMPTY leg is uncontrolled"
elif ! bash -n <(printf 'set -euo pipefail\n%s\n' "$MUT_CTRL") 2>/dev/null; then
  fail "control-probe mutant does not parse — its verdict would prove nothing"
else
  out="$(run_audit "$CLEAN" "$(seam "$OSV_CLEAN" "$ADV_EMPTY_CONTROL" "$REG_NONE")" "" "$MUT_CTRL")"; rc=$?
  if [ "$rc" -eq 0 ]; then
    ok "mutant control: with the non-empty control requirement removed, an EMPTY advisory source goes GREEN — which is exactly the silent-empty state the probe exists to catch"
  else
    fail "the control-probe mutant should have gone green (rc=$rc): $out"
  fi
fi

# ══ §R THE REPORTING JOB — a green over nothing must be unreachable from the caller ══════
run_report() {  # $1=needs.audit.result  $2=population
  AUDIT_RESULT="$1" AUDIT_POPULATION="$2" bash -c "set -euo pipefail
$REPORT_BLOCK" 2>&1
}
GOOD='{"ref":"main","status":"audited","manifests":2,"packages":106,"resolved":106,"unresolved":0,"advisory_repos":98,"findings":0,"errors":0}'
out="$(run_report success "$GOOD")"; rc=$?
# shellcheck disable=SC2015  # MEZZANINE: ok/fail only print and count, so C runs only when A is false — the intended if/else
[ "$rc" -eq 0 ] && ok "reporting job: a real population over a successful matrix passes" \
  || fail "reporting job rejected a valid population (rc=$rc): $out"
# `|` separates the fields, NOT a tab: tab is an IFS *whitespace* character, so `read` folds
# two adjacent tabs into one delimiter and the empty-population row silently became a
# differently-shaped row that the loop skipped. The row count below is what would catch it now.
report_cases=0
while IFS='|' read -r result population label; do
  [ -n "$result" ] || continue
  [ -n "$label" ] || { fail "malformed reporting-job case row: '$result|$population'"; continue; }
  report_cases=$((report_cases + 1))
  out="$(run_report "$result" "$population")"; rc=$?
  if [ "$rc" -ne 0 ] && has "$out" 'REPORT: RED'; then
    ok "reporting job: $label → RED"
  else
    fail "reporting job accepted $label (rc=$rc): $out"
  fi
done <<CASES
success||a WITHHELD population
success|not json|an UNPARSEABLE population
success|{"status":"audited","manifests":0,"packages":0,"errors":0}|a population over ZERO lockfiles
success|{"status":"audited","manifests":2,"packages":0,"errors":0}|a population with lockfiles but ZERO packages
success|{"status":"error","manifests":2,"packages":9,"errors":1}|a population whose sources errored
success|{"status":"audited","manifests":2,"packages":9,"errors":2}|a population reporting source errors
failure|$GOOD|a matrix leg that did not succeed
cancelled|$GOOD|a CANCELLED matrix
CASES
# shellcheck disable=SC2015  # MEZZANINE: ok/fail only print and count, so C runs only when A is false — the intended if/else
[ "$report_cases" -eq 8 ] \
  && ok "reporting-job table: all 8 rejection cases ran (a row the reader folds away is a leg nobody runs)" \
  || fail "reporting-job table ran $report_cases of 8 cases — rows are being dropped by the reader"

# ══ §X THE CLASS GUARD — no leg above printed an error it failed to RECORD ═══════════════
# This is the property blocker-1 violated, asserted over EVERY leg this suite ran rather than
# over the two legs that happened to name it. It is shape-independent: it does not care which
# function raised the error or whether a subshell ate it, only that the log and the exported
# counter agree.
if [ -s "$TMP/swallowed" ]; then
  fail "a run PRINTED an AUDIT-ERROR that its exported population did NOT record — an error raised in a subshell, or a counter that never moved: $(tr '\n' '; ' < "$TMP/swallowed")"
else
  ok "class guard: every leg that printed an AUDIT-ERROR also EXPORTED it (no error was recorded into a subshell and discarded)"
fi

# …and the guard must be able to SEE that state, or the green above means only that nothing
# tried. The mutant RE-MINTS blocker 1 in its purest form: err() still prints, and no longer
# counts. A detector that cannot fail here would be a decoration.
# shellcheck disable=SC2016  # MEZZANINE: single-quoted on purpose: this is shell text expanded later, inside the seam or by sed/grep, never here
n_inc="$(grep -c -- 'AUDIT_ERRORS=\$((AUDIT_ERRORS + 1))' <<< "$BLOCKS")"
# shellcheck disable=SC2016  # MEZZANINE: single-quoted on purpose: this is shell text expanded later, inside the seam or by sed/grep, never here
MUT_SWALLOW="$(printf '%s\n' "$BLOCKS" | sed 's/^  AUDIT_ERRORS=\$((AUDIT_ERRORS + 1))$/  :/')"
if [ "$n_inc" -ne 1 ]; then
  fail "the swallow mutation matched $n_inc counter increments, not 1 — it no longer isolates err()'s recording step"
elif [ "$MUT_SWALLOW" = "$BLOCKS" ]; then
  fail "swallow mutation changed nothing — the class guard above is uncontrolled"
elif ! bash -n <(printf 'set -euo pipefail\n%s\n' "$MUT_SWALLOW") 2>/dev/null; then
  fail "swallow mutant does not parse — its verdict would prove nothing"
else
  : > "$TMP/swallowed"
  out="$(run_audit "$AFFECTED" "$(seam "$OSV_CLEAN" "$ADV_FAIL_DEP" "$REG_NONE")" \
          fixture-org/control-repo "$MUT_SWALLOW")"; rc=$?
  if [ -s "$TMP/swallowed" ] && [ "$rc" -eq 0 ]; then
    ok "mutant control: with err()'s counter increment removed, a failed advisory read exits 0 with VERDICT GREEN and the class guard CATCHES it — the guard binds the shipped err(), not the fixture"
  else
    fail "the swallow mutant should have produced a swallowed error the guard catches (rc=$rc, swallowed='$(cat "$TMP/swallowed")'): $out"
  fi
  : > "$TMP/swallowed"
fi

finish

#!/usr/bin/env bash
# ── VENDORED from coord 0.66.0 — the selftest of the scheduled dependency-advisory audit. A body edit reds bin/vendor-pin-check.sh until declared below. ──
#
#   upstream repo     PupFuzz/agent-board-framework  (PRIVATE — a public CI runner cannot clone it)
#   upstream path     plugins/coord/templates/security/dependency-audit.selftest.sh
#   vendored from     70fd3207c96f7240f7368c93de277970ac39598e   (marketplace, the v0.66.0 release merge)
#   plugin version    coord 0.66.0
#   upstream sha256   7fdb5496a1336f8818850058c3154131333c85d14ab1b9c9eb6da049d16cb436
#
# That sha256 is the WHOLE upstream file; `bin/vendor-pin-check.sh` pins the BODY (from the first
# line that is neither blank nor a comment), which is a different figure. Re-derive this one
# against a marketplace clone:   git show 70fd3207:plugins/coord/templates/security/dependency-audit.selftest.sh | sha256sum
#
# DECLARED LOCAL EDITS — each is also a FRAGMENTS row in `bin/vendor-pin-check.sh`, which reds
# when one leaves the body. Outside these edits, the text below this block was upstream's byte for
# byte when vendored (checked by diff against the commit above); the body pin holds it there.
#   (1) the CALLEE / CALLER / FIX paths point at this repo's layout — workflows under
#       .github/workflows/, fixtures in bin/dependency-audit-fixtures/ (fragments:
#       `ROOT/.github/workflows/dependency-audit.yml"` from the CALLEE line and
#       `HERE/dependency-audit-fixtures"` from the FIX line).
#   The two workflow paths in upstream's own header below are re-pointed too; that is header
#   text and is not pinned. ShellCheck's findings on this file are recorded in
#   bin/shell-lint.baseline.tsv rather than annotated in the body, so the body stays upstream's.
#
# TO RE-VENDOR: copy upstream's file over everything below this block, re-apply the edits above,
# then re-pin with the command `bin/vendor-pin-check.sh` prints on a red.
# ── END MEZZANINE VENDOR HEADER ────────────────────────────────────────────────────────────────
# dependency-audit.selftest.sh — proves the shipped dependency-advisory gate CAN FAIL, fails
# for the right reason, and DISTINGUISHES an affected lockfile from a clean one (a check that
# cannot fail is a decoration; a check that reds on everything is one too).
#
# WHAT IT RUNS. Every leg executes the SHIPPED YAML's own shell, extracted verbatim from
# between the `# --- <name>` markers of .github/workflows/dependency-audit.yml and
# .github/workflows/dependency-ref-matrix.yml. Nothing here re-implements the audit, so an assertion
# cannot keep passing against logic the templates no longer ship.
#
# ZERO NETWORK IN EVERY LEG. The network seams (`osv_scan`, `advisory_fetch`,
# `registry_repo_lookup`, `reviewed_advisory_fetch`) live in the yml's CUSTOMIZE zone precisely
# so they can be replaced here by fixture readers. What that means for coverage, stated plainly
# rather than implied: the SHIPPED seam bodies (`gh api` calls, a registry `curl`) are NOT
# executed by this suite — only their contract is asserted (they exist, and the block treats a
# non-zero exit and a payload of the wrong JSON type as RED). The first live run is what
# exercises them.
#
# WHAT "RED" MEANS, and where the list of reasons lives: the coord plugin's
# `docs/DEPENDENCY-AUDIT.md` § "Failure semantics" owns it, per code — this header does not
# restate it. The one part that belongs HERE is what the legs assert ON: the EXPORTED
# POPULATION (`errors`, `error_codes`), never the stderr log text. `err` prints AND increments
# a counter, nothing makes those agree, and a grep on the log is satisfied by an error the run
# never recorded — which is how a subshell-swallowed error once read as a clean run.
#
# THE CONTROLS. Mutants, each removing one guard from the extracted block, except the naive-fix
# range mutant, which removes both halves of the naive fix, the two belowtag re-narrowing
# mutants, which put back the narrower rule belowtag replaced, and the patched-version mutants
# that put a named wrong reading in place of the shipped one (own line, tie, pre-release, a
# bounded range overruled, an interval that begins at or above the fix overruled, an unread range
# cleared by its patched text, a read range that cannot be compared overruled, the no-shared-lead
# reading widened or removed), and the reviewed-record mutants that use a global record the
# shipped block refuses. The §M mutants each require the leg that guard holds to change verdict:
# a fail-closed leg goes GREEN, or a range-grammar row (§V), patched-version row (§P) or
# reviewed-record row (§G) takes the named wrong reading. The acceptance-input mutants (card#11434)
# each remove or weaken one check (reviewer, date, versions, expiry, the expiry day being inclusive,
# the version an acceptance covers, or the rule that an acceptance touches only a RANGE-UNPARSEABLE
# entry) and require the §A leg it holds to change verdict or lose its finding. The one-line mutants
# each remove `oneline` from one site (the notice records, the statement's acceptance lines, the
# SOURCE2-RANGE-UNPARSEABLE detail) and require the forging leg that site feeds to see its forgery.
# The card#11604 mutants each remove one guard of `agreed` (what every reading of an unread range
# agrees on), or put back the reading of a bare version as exact that card#11604 rules out.
# Without them, a leg could be passing
# because the fixture is odd rather than because the shipped logic is closed. The §X mutant
# re-mints the swallowed-error defect and requires the class guard to catch it.
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

for tool in jq sed awk find diff; do
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
BLOCK_NAMES="dep-audit-lib dep-audit-accept dep-audit-discovery dep-audit-source1 dep-audit-source2
            dep-audit-report dep-audit-main"
for name in $BLOCK_NAMES; do
  body="$(extract "$CALLEE" "$name")"
  if [ -z "$body" ]; then
    fail "extraction of '$name' returned EMPTY — the yml markers moved; this selftest is testing nothing"
    finish
  fi
  BLOCKS="$BLOCKS
$body"
done
ok "$(wc -w <<< "$BLOCK_NAMES" | tr -d ' ') blocks extracted from the shipped callee ($(printf '%s' "$BLOCKS" | wc -l) lines)"
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
for fn in 'osv_scan()' 'advisory_fetch()' 'registry_repo_lookup()' 'reviewed_advisory_fetch()'; do
  case "$SEAMS" in *"$fn"*) :;; *) missing="$missing $fn";; esac
done
if [ -n "$missing" ]; then
  fail "the shipped CUSTOMIZE zone no longer defines:$missing — the blocks below call them, so the template would fail at run time while this suite's stubs kept it green"
else
  ok "shipped CUSTOMIZE zone defines every network seam the blocks call (their bodies are NOT run here — no network)"
fi

# ── §W PERMISSIONS ARE GRANTED PER JOB (card#11434) ──────────────────────────────────────
# Neither shipped workflow may carry a top-level `permissions:`, and every job under `jobs:` must
# carry its own. PERM_AWK prints one line per violation, so an empty output is the pass; the
# control below feeds it the workflow-level shape both files shipped with before card#11434.
PERM_AWK='
  /^permissions:/ { print "a top-level permissions: grant"; next }
  /^jobs:/ { injobs = 1; next }
  /^[^ #]/ { if (job != "" && !granted) print "job " job " has no permissions: of its own"; injobs = 0; job = ""; next }
  injobs && /^  [A-Za-z0-9_-]+:[[:space:]]*$/ {
    if (job != "" && !granted) print "job " job " has no permissions: of its own"
    job = $1; sub(/:$/, "", job); granted = 0; jobs++; next }
  injobs && /^    permissions:/ { granted = 1 }
  END { if (job != "" && !granted) print "job " job " has no permissions: of its own"
        if (!jobs) print "no job found under jobs:" }'
for wf in "$CALLEE" "$CALLER"; do
  perm="$(awk "$PERM_AWK" "$wf")"
  [ -z "$perm" ] && ok "$(basename "$wf"): no top-level permissions:, and every job grants its own" \
    || fail "$(basename "$wf"): permissions must be granted per job: $(tr '\n' ';' <<< "$perm")"
done
perm="$(printf 'on:\n  workflow_dispatch:\npermissions:\n  contents: read\njobs:\n  audit:\n    runs-on: x\n  report:\n    permissions: {}\n    runs-on: x\n' | awk "$PERM_AWK")"
has "$perm" 'a top-level permissions: grant' && has "$perm" 'job audit has no permissions:' && ! has "$perm" 'job report' \
  && ok "control: the per-job check reds on a workflow-level grant and on a job with no grant, and passes a job that has one" \
  || fail "control: the per-job check did not discriminate the workflow-level shape: $(tr '\n' ';' <<< "$perm")"

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
ACCEPT_FILE=\".github/dependency-audit-accept.json\"
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

seam() {  # writes a prelude file from the named behaviours: $1=osv $2=advisory $3=registry [$4=reviewed, default REV_FIXTURE]
  local f="$TMP/seam.sh"
  cat > "$f" <<PRELUDE
FIX="$FIX"
osv_scan() { $1 }
advisory_fetch() { $2 }
registry_repo_lookup() { $3 }
reviewed_advisory_fetch() { ${4:-$REV_FIXTURE} }
PRELUDE
  printf '%s' "$f"
}

OSV_CLEAN='printf %s "{\"results\":[]}";'
OSV_GARBAGE='printf %s "osv-scanner: could not open lockfile"; return 3;'
OSV_ERR='return 127;'
OSV_FINDING='printf %s "{\"results\":[{\"source\":{\"path\":\"composer.lock\"},\"packages\":[{\"package\":{\"ecosystem\":\"Packagist\",\"name\":\"some/pkg\",\"version\":\"1.0.0\"},\"vulnerabilities\":[{\"id\":\"GHSA-glob-al00-0001\"}]}]}]}";'
ADV_FIXTURE='cat "$FIX/advisories/$(printf %s "$1" | tr / @).json";'
ADV_FAIL_DEP='case "$1" in fixture-org/control-repo) cat "$FIX/advisories/fixture-org@control-repo.json";; *) return 22;; esac'
ADV_RATELIMIT='case "$1" in fixture-org/control-repo) cat "$FIX/advisories/fixture-org@control-repo.json";; *) printf %s "{\"message\":\"API rate limit exceeded\"}";; esac'
ADV_EMPTY_CONTROL='printf %s "[]";'
REG_NONE='return 1;'
# The reviewed global records (fixtures/reviewed/<GHSA>.json). A GHSA with no fixture exits
# non-zero, as `gh api` does on a 404, so a leg that needs a record nobody vendored reds.
REV_FIXTURE='cat "$FIX/reviewed/$1.json";'
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
[ "$(pop findings)" = "4" ] && ok "…and the population output carries findings=4" \
  || fail "population findings should be 4, got '$(pop findings)'"
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
grep -q 'POPULATION STATEMENT' "$TMP/step_summary" \
  && ok "…and it reaches the step summary, not only stdout" \
  || fail "the statement never reached GITHUB_STEP_SUMMARY (the aimla lesson: a summary written after an exit is a summary nobody sees)"

out="$(run_audit "$CLEAN" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE")")"; rc=$?
if [ "$rc" -eq 0 ] && has "$out" 'VERDICT: GREEN'; then
  ok "MUST-GREEN leg: the same lockfile at 2.10.0 passes (rc=0) — the check DISTINGUISHES, it does not merely refuse"
else
  fail "MUST-GREEN leg should pass at 2.10.0 (rc=$rc): $out"
fi
[ "$(pop status)" = "audited" ] && [ "$(pop manifests)" = "1" ] && [ "$(pop packages)" = "2" ] \
  && [ "$(pop resolved)" = "1" ] && [ "$(pop unresolved)" = "1" ] \
  && ok "…and the population output names what was read (1 lockfile, 2 production packages, 1 resolved, 1 not)" \
  || fail "clean-leg population wrong: $(sed -n 's/^population=//p' "$TMP/gh_output")"
has "$out" 'commonmark-devonly' \
  && fail "a packages-dev entry leaked into the production population" \
  || ok "…and the dev-only package is excluded (2 packages, not 3)"
has "$out" 'UNRESOLVED PACKAGES' \
  && ok "…and the unresolved package is NAMED in the statement, not dropped" \
  || fail "an unresolved package must be named in the population statement: $out"
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
[ "$(pop manifests)" = "0" ] && [ "$(pop status)" = "error" ] \
  && ok "…and the empty-tree run still EXPORTS its population (manifests=0, status=error) for the reporting job" \
  || fail "an audit that found nothing must still export a population naming the nothing: $(cat "$TMP/gh_output")"

# an unrecognised range form must never read as "not affected". Its patched version is left in
# place: 2.10.0 is that advisory's own fix, at the version, and patched text never clears a range
# the parser does not read (card#11106). It has no vendored reviewed record, so the lookup fails.
mkdir -p "$TMP/badrange/advisories"
jq '[.[0] | .vulnerabilities[0].vulnerable_version_range = "sometime after 1.0"]' \
  "$FIX/advisories/thephpleague@commonmark.json" > "$TMP/badrange/advisories/thephpleague@commonmark.json"
cp "$FIX/advisories/fixture-org@control-repo.json" "$TMP/badrange/advisories/"
ADV_BADRANGE='cat "'"$TMP"'/badrange/advisories/$(printf %s "$1" | tr / @).json";'
out="$(run_audit "$CLEAN" "$(seam "$OSV_CLEAN" "$ADV_BADRANGE" "$REG_NONE")" fixture-org/control-repo)"; rc=$?
if [ "$rc" -ne 0 ] && has "$out" 'AUDIT-ERROR[SOURCE2-RANGE-UNPARSEABLE]'; then
  ok "an advisory range form the audit cannot parse → RED, never a quiet 'not affected'"
else
  fail "an unparseable range must red (rc=$rc): $out"
fi

# ══ §V THE RANGE GRAMMAR — live advisory range strings through the SHIPPED matcher ═════════
# Every row except those marked SYNTHETIC is a `vulnerable_version_range` copied verbatim from a
# published repository advisory, fetched 2026-09-28 with
# `gh api repos/<owner>/<repo>/security-advisories`. The verdicts come from that advisory's own
# `patched_versions` and, where GitHub reviewed it, from the global advisory's per-range split
# (`gh api /advisories/<GHSA>`). The grammar and its sources are stated once, above in_range in
# the callee; this table does not restate them. A SYNTHETIC row's verdict was checked on 2026-09-28
# against npm's `semver` and `composer/semver` (with `;` and `&&` spelled as a space, `=>` as `>=`
# and `through` as `-`): where either reads its version as affected, the row reads AFFECTED or
# RANGE-UNPARSEABLE, never clean. Fields are `#`-separated because the ranges
# themselves carry `,`, `;`, `|` and `&`.
#   want = AFFECTED | clean | RANGE-UNPARSEABLE     (clean = no verdict row at all)
RANGE_TABLE='>= 1.3.0; <=2.10.1#2.10.1#AFFECTED#commonmark GHSA-97jj-33gv-5xf9 (;)
>= 1.3.0; <=2.10.1#2.10.2#clean#commonmark GHSA-97jj-33gv-5xf9 (;)
<1.1.4 || >=2.0.0, <2.1.1#1.1.3#AFFECTED#flysystem GHSA-9f46-5r25-5wfm (||, left)
<1.1.4 || >=2.0.0, <2.1.1#2.1.0#AFFECTED#flysystem GHSA-9f46-5r25-5wfm (||, right)
<1.1.4 || >=2.0.0, <2.1.1#1.1.4#clean#flysystem GHSA-9f46-5r25-5wfm (||, between)
<1.1.4 || >=2.0.0, <2.1.1#2.1.1#clean#flysystem GHSA-9f46-5r25-5wfm (||, above)
>=3.0.0 && <3.8.4#3.8.3#AFFECTED#carbon GHSA-j3f9-p6hm-5w6q (&&)
>=3.0.0 && <3.8.4#3.8.4#clean#carbon GHSA-j3f9-p6hm-5w6q (&&)
=>2.0.0, <=2.1.0#2.1.0#AFFECTED#psr7 GHSA-q7rv-6hp3-vh96 (=>)
=>2.0.0, <=2.1.0#1.9.9#clean#psr7 GHSA-q7rv-6hp3-vh96 (=>)
>= 8.0.0 < 8.20.1#8.20.0#AFFECTED#ws GHSA-58qx-3vcg-4xpx (space)
>= 8.0.0 < 8.20.1#8.20.1#clean#ws GHSA-58qx-3vcg-4xpx (space)
>= 1.1.0 < 5.2.5, >= 6.0.0 < 6.2.4, >= 7.0.0 < 7.5.11, >= 8.0.0 < 8.21.0#6.1.0#AFFECTED#ws GHSA-96hv-2xvq-fx4p (comma = OR) THE FAIL-OPEN CONTROL
>= 1.1.0 < 5.2.5, >= 6.0.0 < 6.2.4, >= 7.0.0 < 7.5.11, >= 8.0.0 < 8.21.0#8.20.9#AFFECTED#ws GHSA-96hv-2xvq-fx4p (comma = OR, last)
>= 1.1.0 < 5.2.5, >= 6.0.0 < 6.2.4, >= 7.0.0 < 7.5.11, >= 8.0.0 < 8.21.0#5.9.0#clean#ws GHSA-96hv-2xvq-fx4p (comma = OR, gap)
>= 1.1.0 < 5.2.5, >= 6.0.0 < 6.2.4, >= 7.0.0 < 7.5.11, >= 8.0.0 < 8.21.0#8.21.0#clean#ws GHSA-96hv-2xvq-fx4p (comma = OR, patched)
>= 5.0.0 < 5.2.3, >= 6.0.0 < 6.2.2, >= 7.0.0 < 7.4.6 #7.4.5#AFFECTED#ws GHSA-6fc8-4gx4-v693 (comma = OR, trailing space)
>= 1.5.0, < 2.10.0#2.9.0#AFFECTED#commonmark GHSA-8rr7-cvq3-gmfh (GitHub documented shape)
>= 1.5.0, < 2.10.0#2.10.0#clean#commonmark GHSA-8rr7-cvq3-gmfh (GitHub documented shape)
2.3.0 - 2.8.1#2.8.1#AFFECTED#commonmark GHSA-hh8v-hgvp-g3f5 (hyphen)
0.15.6 through 0.18.0#0.18.1#clean#commonmark GHSA-rfj4-8hcc-qvwm (through)
> 2.0, < 2.3, > 3.0, < 3.2#3.1#RANGE-UNPARSEABLE#SYNTHETIC: GitHub docs example of the refused multi-range field (AND would read clean)
>= 3.0, < 2.0#2.5#RANGE-UNPARSEABLE#SYNTHETIC: an empty interval is a misread, never "no version"
>= 1.0 < 2.0, >= 3.0#3.5#RANGE-UNPARSEABLE#SYNTHETIC: comma between a bounded and an open piece is ambiguous
= 1.0, = 2.0#1.0#RANGE-UNPARSEABLE#SYNTHETIC: two equalities under one AND
>= 1.0,, < 2.0#1.5#RANGE-UNPARSEABLE#SYNTHETIC: an empty piece
>= 1.0 | < 2.0#1.5#RANGE-UNPARSEABLE#SYNTHETIC: a single pipe is not read
^1.2.3#1.5.0#RANGE-UNPARSEABLE#SYNTHETIC: caret is not read
< 2.x#1.5#RANGE-UNPARSEABLE#SYNTHETIC: unparseable bound
>= 1.0.0 && <= 2.3#2.3.5#AFFECTED#SYNTHETIC: partial <= under && reads as the next minor, exclusive (npm)
>= 8.0.0 <= 8.20#8.20.1#AFFECTED#SYNTHETIC: partial <= under space AND
>= 8.0.0 <= 8.20#8.21.0#clean#SYNTHETIC: partial <= stops below the next minor
<= 2.3 || >= 3.0, < 3.1#2.3.5#AFFECTED#SYNTHETIC: partial <= inside ||
= 1.2 || = 1.4#1.2.5#AFFECTED#SYNTHETIC: partial = reads as the whole minor (npm)
= 1.2 || = 1.4#1.3.0#clean#SYNTHETIC: partial = stops below the next minor
1.0 - 2.3 || >= 3.0#2.3.5#AFFECTED#SYNTHETIC: partial hyphen upper end inside ||
1.0 - 2.3#2.3.5#AFFECTED#SYNTHETIC: partial hyphen upper end
1.0 - 2.3#2.4.0#clean#SYNTHETIC: partial hyphen upper end stops below the next minor
>= 1.0; < 2.0.0-beta.3#2.0.0-beta.1#RANGE-UNPARSEABLE#SYNTHETIC: two pre-release tags on one core under ; cannot be ordered
>= 1.0.0 < 2.0.0-beta.3#2.0.0-beta.1#RANGE-UNPARSEABLE#SYNTHETIC: two pre-release tags on one core under space AND cannot be ordered
< 2.0.0-beta.3#2.0.0-beta.1#RANGE-UNPARSEABLE#SYNTHETIC: two pre-release tags on one core cannot be ordered
< 2.0.0-beta.3#2.0.0-beta.3#clean#SYNTHETIC: identical pre-release tags compare equal
> 2.0.0-rc.1, < 2.0.0#2.0.0-rc.2#RANGE-UNPARSEABLE#SYNTHETIC: one undecidable bound beside a true one is undecidable
>= 2.0.0-rc.1 < 2.0.0-rc.5#3.0.0#clean#SYNTHETIC (card 11604): bounds whose pre-release tags cannot be ordered are not read, and an untagged version on a higher core is above both in either order
>= 2.0.0-rc.1 < 2.0.0-rc.5#1.5.0#RANGE-UNPARSEABLE#SYNTHETIC: bounds whose pre-release tags cannot be ordered, so emptiness is undecidable, and a version below them is not decided
>= 1.0.0 < 2.0.0#1.0.0-beta#AFFECTED#SYNTHETIC: >= admits the pre-releases of its own core (Composer reads >= X as >= X-dev)
> 1.0.0, < 2.0.0#1.0.0-p1#RANGE-UNPARSEABLE#SYNTHETIC: a Composer patch tag sits ABOVE its core, an npm tag below
= 1.2.3#1.2.3-stable#RANGE-UNPARSEABLE#SYNTHETIC: Composer reads -stable as EQUAL to its core, npm below
= 1.2.3#v1.2.3-stable#RANGE-UNPARSEABLE#SYNTHETIC: Composer reads -stable as EQUAL to its core (v prefix)
<= 1.2.3-stable#1.2.3#RANGE-UNPARSEABLE#SYNTHETIC: a -stable bound is EQUAL to its core in Composer
> 1.2.3#1.2.3-p1-dev#RANGE-UNPARSEABLE#SYNTHETIC: Composer reads -p1-dev as ABOVE its core, npm below
> 1.2.3#1.2.3-patch1-dev#RANGE-UNPARSEABLE#SYNTHETIC: Composer reads -patch1-dev as ABOVE its core, npm below
0 through 1.2.3-patch1-dev#v1.2.3#RANGE-UNPARSEABLE#SYNTHETIC: a -patch1-dev upper end sits ABOVE its core in Composer
<1.2.3-patch1-dev && =>1#1.2.3#RANGE-UNPARSEABLE#SYNTHETIC: a -patch1-dev upper bound under && sits ABOVE its core in Composer
> 1.2.3#1.2.3-RC1-dev#clean#SYNTHETIC: a tag both ecosystems order below its core still reads below it
= 1.2.3#1.2.3#AFFECTED#SYNTHETIC: exact equality holds on its own version
= 1.2.3#1.2.4#clean#SYNTHETIC: exact equality does not hold on another version
1.17.0#1.19.0#RANGE-UNPARSEABLE#axios GHSA-r4gj-5m52-g5wh: a bare version is not read, since its reviewed global record reads ">= 1.17.0, < 1.20.0" (pm ruling card#11106 c-10035; §G decides it)
1.15.2#1.15.2#AFFECTED#axios GHSA-654m-c8p4-x5fp (card 11604): a bare version holds the version equal to it in every reading (its reviewed global record reads "= 1.15.2")
1.16.0#1.16.0#AFFECTED#follow-redirects GHSA-8r9p-f939-6h3c (card 11604): the version equal to the bare version
1.16.0#1.16.1#RANGE-UNPARSEABLE#follow-redirects GHSA-8r9p-f939-6h3c (card 11604): with no patched version, a version above the bare version is not decided
>=1.0.0-a.1 <1.0.0-b.2 || >=3.0.0#2.0.0#clean#SYNTHETIC (card 11604 r3): an unordered pair as one alternative of a composite whose other alternative excludes the version, above both bounds'

LIB_BLOCK="$(extract "$CALLEE" dep-audit-lib)"
vmatch_prog() {  # $1 = dep-audit-lib block text -> the VMATCH_JQ program that text defines
  bash -c "set -euo pipefail
$1
printf '%s' \"\$VMATCH_JQ\""
}
verdict_of() {   # $1=jq program $2=range $3=patched_versions (NULL = a JSON null) $4=version
                 # [$5=ghsa $6=ecosystem:package $7=reviewed-record map file, none = no record] -> verdict | clean
  local adv ghsa="${5:-GHSA-tabl-e000-0001}" pkg="${6:-npm:p}" map="${7:-}" eco name
  eco="${pkg%%:*}"; name="${pkg#*:}"
  adv="$(jq -cn --arg g "$ghsa" --arg e "$eco" --arg n "$name" --arg r "$2" --arg p "$3" '[{ghsa_id: $g, withdrawn_at: null, vulnerabilities: [{package: {ecosystem: $e, name: $n}, vulnerable_version_range: $r, patched_versions: (if $p == "NULL" then null else $p end)}]}]')"
  if [ -n "$map" ]; then
    jq -r --arg package "$name" --arg version "$4" --arg ecosystem "$eco" --slurpfile reviewed "$map" "$1" <<< "$adv"
  else
    jq -r --arg package "$name" --arg version "$4" --arg ecosystem "$eco" "$1" <<< "$adv"
  fi | jq -r 'if length == 0 then "clean" else .[0].verdict end'
}
range_rows() {   # $1 = jq program -> "got#range#version#want#source", one per table row
  # The range table carries no patched version (a JSON null), so each row's verdict is the
  # range's alone; the patched-version reading has its own table (§P) below.
  local prog="$1" range version want src got
  while IFS='#' read -r range version want src; do
    [ -n "$range" ] || continue
    got="$(verdict_of "$prog" "$range" NULL "$version")" || got="JQ-ERROR"
    printf '%s#%s#%s#%s#%s\n' "$got" "$range" "$version" "$want" "$src"
  done <<< "$RANGE_TABLE"
}
SHIPPED_PROG="$(vmatch_prog "$LIB_BLOCK")"
case "$SHIPPED_PROG" in
  *'def in_range'*) ok "range table: VMATCH_JQ extracted from the shipped callee block" ;;
  *) fail "range table: could not extract VMATCH_JQ from the shipped block — every row below would be testing nothing"; finish ;;
esac
RANGE_OUT="$(range_rows "$SHIPPED_PROG")"
n_table="$(grep -c . <<< "$RANGE_TABLE")"
n_ran="$(grep -c . <<< "$RANGE_OUT")"
[ "$n_ran" -eq "$n_table" ] && [ "$n_table" -gt 0 ] \
  && ok "range table: every row ran ($n_ran of $n_table)" \
  || fail "range table ran $n_ran of $n_table rows — rows are being dropped by the reader"
while IFS='#' read -r got range version want src; do
  [ -n "$got" ] || continue
  [ "$got" = "$want" ] && ok "range '$range' @ $version → $got  [$src]" \
    || fail "range '$range' @ $version should be $want, got $got  [$src]"
done <<< "$RANGE_OUT"

# ══ §P THE PATCHED VERSIONS — a version at or above its line's fix is not affected ══════════
# card#11106. Each row puts one advisory entry (a range and its `patched_versions`) through the
# SHIPPED matcher. Rows not marked SYNTHETIC copy both fields verbatim from a published repository
# advisory, fetched 2026-10-06 with `gh api repos/<owner>/<repo>/security-advisories`. The rule
# these rows hold is stated once, above `patched_state` in the callee; this table does not restate
# it. `NULL` is a JSON null; an empty field is an empty string.
#   want = AFFECTED | clean | RANGE-UNPARSEABLE     (clean = no verdict row at all)
PATCHED_TABLE='>=1.13.0#>=1.20.0#1.20.0#clean#axios GHSA-542g-h47m-68v8: EQUAL to the patched version
>=1.13.0#>=1.20.0#1.19.0#AFFECTED#axios GHSA-542g-h47m-68v8: BELOW the patched version
>=1.13.0#>=1.20.0#9.9.9#clean#axios GHSA-542g-h47m-68v8: above the patched version
>=1.13.0#>=1.20.0#1.20.0-beta.1#AFFECTED#SYNTHETIC: a pre-release of the patched version is below it
>1.0.0#>= 1.13.2#1.13.2#clean#axios GHSA-qj83-cq47-w5f8: a spaced >= on the patched version
1.17.0#>=1.20.0#1.20.0#clean#axios GHSA-r4gj-5m52-g5wh (card 11604): at its one fix, which every reading of the bare version leaves the version at or above
1.17.0#>=1.20.0#1.18.0#RANGE-UNPARSEABLE#axios GHSA-r4gj-5m52-g5wh (card 11604): a bare version is not read as exact, so 1.18.0, below the fix and not equal to it, is not decided; this table hands in no reviewed record (§G does)
1.17.0#>=1.20.0#1.17.0#AFFECTED#axios GHSA-r4gj-5m52-g5wh (card 11604): the version equal to the bare version
1.16.0#1.16.1#1.16.1#clean#follow-redirects GHSA-8r9p-f939-6h3c (card 11604): at its one fix, above the bare version
1.16.0#1.16.1#1.15.9#RANGE-UNPARSEABLE#follow-redirects GHSA-8r9p-f939-6h3c (card 11604): below the fix and not equal to the bare version, so not decided; this table hands in no reviewed record (§G does)
> = 1.19.10, < 2.1.3#2.1.3#2.1.3#clean#@hono/node-server GHSA-rmxm-3fg6-px4f (card 11604): at its one fix, which the range names only as an exclusive upper bound
> = 1.19.10, < 2.1.3#2.1.3#2.1.2#RANGE-UNPARSEABLE#@hono/node-server GHSA-rmxm-3fg6-px4f (card 11604): below the fix, so not decided; this table hands in no reviewed record (§G does)
>=5.0.0-alpha.1, <5.0.0-beta.3#5.0.0-beta.3#5.2.1#clean#express GHSA-rv95-896h-c2vc (card 11604): an untagged version on a higher core than both bounds, whose tags cannot be ordered
>=5.0.0-alpha.1, <5.0.0-beta.3#5.0.0-beta.3#5.0.0-alpha.5#RANGE-UNPARSEABLE#express GHSA-rv95-896h-c2vc (card 11604): a tagged version on the core of both bounds is not decided
>=5.0.0-alpha.1, <5.0.0-beta.3#5.0.0-beta.3#4.21.0#RANGE-UNPARSEABLE#express GHSA-rv95-896h-c2vc (card 11604): a version on a lower core than both bounds is not decided
>=5.0.0-alpha.1, <5.0.0-beta.3#5.0.0-beta.3#5.2.1-beta.1#RANGE-UNPARSEABLE#SYNTHETIC (card 11604): a tagged version on a higher core is not decided (the rule reads only an untagged one), and the text names a version that cannot be compared with the fix
all versions after 1.0#1.5.3#2.0.0#RANGE-UNPARSEABLE#SYNTHETIC (card 11604): a word can name a bound with no version, so text carrying one is not cleared by its fix
> = 1.0.0 || > = 2.1.3#2.1.3#2.2.0#RANGE-UNPARSEABLE#SYNTHETIC (card 11604): the fix named other than as an exclusive upper bound can begin an interval at it, so not cleared
 #1.5.3#2.0.0#RANGE-UNPARSEABLE#SYNTHETIC (card 11604): a range naming no version is not cleared by its fix
1.0.0-2.5.0#1.5.3#2.0.0#RANGE-UNPARSEABLE#SYNTHETIC (card 11604 r2): a span written without spaces reads as one version whose tag is off the belowtag list, so not cleared
1.0.0-and-later#1.2.0#5.0.0#RANGE-UNPARSEABLE#SYNTHETIC (card 11604 r2): a word joined to a version by hyphens reads as a tag off the belowtag list, so not cleared
1.0.0-2.x#1.2.0#5.0.0#RANGE-UNPARSEABLE#SYNTHETIC (card 11604 r2): a partial upper end joined without spaces reads as a tag off the belowtag list, so not cleared
1.0.0+#1.2.0#5.0.0#RANGE-UNPARSEABLE#SYNTHETIC (card 11604 r2): a trailing plus reads as build metadata, so not cleared
=< 1.5.3#1.5.3#1.5.3#RANGE-UNPARSEABLE#SYNTHETIC (card 11604 r2): =< is inclusive, so the fix it names is not an exclusive bound
< = 1.5.3#1.5.3#1.5.3#RANGE-UNPARSEABLE#SYNTHETIC (card 11604 r2): a spaced <= is inclusive, so the fix it names is not an exclusive bound
>= 1.0.0, <1.5.3 =#1.5.3#1.5.3#RANGE-UNPARSEABLE#SYNTHETIC (card 11604 r2): an = after the fix makes its < inclusive, so not an exclusive bound
> = 1.0.0#1.5.3#9.0.0#clean#SYNTHETIC (card 11604 r2): an open interval beginning below the fix is cleared at or above it, as a read open range is by its patched text
> = 0.9.0, < = 1#1.5.3#1.9.0#RANGE-UNPARSEABLE#SYNTHETIC (card 11642 r1): a partial upper bound reaches past its own numbers (<= 1 is read as < 2.0.0), so it names a version above the fix and is not cleared
< = 1.5#1.5.3#1.5.4#RANGE-UNPARSEABLE#SYNTHETIC (card 11642 r1): a partial <= 1.5 reaches 1.6.0, above the fix, so not cleared
> = 1.0, < 1.5#1.5.0#1.5.0#clean#SYNTHETIC (card 11642 r1): a partial bound written exactly < reaches no further than its own numbers, so the fix it names is an exclusive upper bound
1.0.0-beta.1-3#1.5.3#2.0.0#RANGE-UNPARSEABLE#SYNTHETIC (card 11642 r1): a tag with a hyphen-joined numeric segment is a span hidden in a tag (>=1.0.0-beta.1 <=3), so not cleared
<11.44.1, >=12.0.0, <12.1.1#12.1.1,11.44.1#13.30.1#RANGE-UNPARSEABLE#SYNTHETIC (card 11642 r1): laravel GHSA-78fx-h6xr-vch4 written with full versions, above both fixes; a patched list of several versions is not read here
>= 1.0 =< 1.5.3#1.5.3#1.5.3#RANGE-UNPARSEABLE#SYNTHETIC (card 11642 r3): an = after a version with an operator after it has two readings (the = closes >= 1.0 and < 1.5.3 stays exclusive, or it opens <= 1.5.3), so not cleared
< 1.5 =#1.5.3#1.5.9#RANGE-UNPARSEABLE#SYNTHETIC (card 11642 r2): an = after a partial < makes it inclusive, so it is compared at its reach (1.6.0), above the fix
<> 1.0#1.5.3#2.0.0#RANGE-UNPARSEABLE#SYNTHETIC (card 11642 r2): an operator other than >= <= > < = (here <>, which can mean "not 1.0") is not read, so not cleared
<1.5.3, =#1.5.3#1.5.3#RANGE-UNPARSEABLE#SYNTHETIC (card 11642 r2): an operator attached to no version is not read, so not cleared
>= 1.0, =< 1.5.3#1.5.3#1.5.3#RANGE-UNPARSEABLE#SYNTHETIC (card 11642 r3): an =< that no version precedes is spelled <= before the scan, so the fix it names is not an exclusive bound
=> 1.0.0, < 1.5.3#1.5.3#1.5.3#clean#SYNTHETIC (card 11642 r3): an => that no version precedes has one reading (>=), so the range is read and the fix, its exclusive upper bound, is outside it
>= 1.0.0 < 1.5.3 =< 1.2.0#1.5.3#1.5.3#RANGE-UNPARSEABLE#SYNTHETIC (card 11642 r3): an = between a version and a following < can make the < before it inclusive (<= 1.5.3), so not cleared at the fix
>= 1.0.0 < 1.5.3 = < 1.2.0#1.5.3#1.5.3#RANGE-UNPARSEABLE#SYNTHETIC (card 11642 r3): a spaced = between a version and a following < can make the < before it inclusive (<= 1.5.3), so not cleared at the fix
< 1.5 =< 1.4.0#1.5.0#1.5.2#RANGE-UNPARSEABLE#SYNTHETIC (card 11642 r3): an = between a partial version and a following < can make it <= 1.5, which reaches 1.6.0, so not cleared
> 1.0.0 < 1.5 => 1.0.0#1.5.0#1.5.2#RANGE-UNPARSEABLE#SYNTHETIC (card 11642 r3): an = between a partial version and a following > can make it <= 1.5, which reaches 1.6.0, so not cleared
>= 1.0.0, < 1.5 =< 1.4.9#1.5.0#1.5.2#RANGE-UNPARSEABLE#SYNTHETIC (card 11642 r3): after a comma, an = between a partial version and a following < can make it <= 1.5, which reaches 1.6.0, so not cleared
< 1.5.0 => 1.0.0#1.5.0#1.5.0#RANGE-UNPARSEABLE#SYNTHETIC (card 11642 r3): the range grammar does not read => as >= where an = after a version can make the < before it inclusive (<= 1.5.0), so the range is not read and not cleared at its fix
1.17.0#>=1.20.0#1.19.0#RANGE-UNPARSEABLE#axios GHSA-r4gj-5m52-g5wh: a bare version is not read, below the fix; this table hands in no reviewed record (§G does)
1.17.0#NULL#1.20.0#RANGE-UNPARSEABLE#SYNTHETIC: a bare version with no patched version is not read either
<6.20.45,>=7,<7.30.7,>=8,<8.83.28,>=9,<9.52.17,>=10,<10.48.23,>=11,<11.31.0#6.20.45,7.30.7,8.83.28,9.52.17,10.48.23,11.31.0#8.83.28#RANGE-UNPARSEABLE#laravel GHSA-gv7v-rgg6-548h: multi-line, at its own line fix — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
<6.20.45,>=7,<7.30.7,>=8,<8.83.28,>=9,<9.52.17,>=10,<10.48.23,>=11,<11.31.0#6.20.45,7.30.7,8.83.28,9.52.17,10.48.23,11.31.0#8.83.27#RANGE-UNPARSEABLE#laravel GHSA-gv7v-rgg6-548h: multi-line, below its own line fix, above older lines fixes — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
<6.20.45,>=7,<7.30.7,>=8,<8.83.28,>=9,<9.52.17,>=10,<10.48.23,>=11,<11.31.0#6.20.45,7.30.7,8.83.28,9.52.17,10.48.23,11.31.0#8.84.1#RANGE-UNPARSEABLE#laravel GHSA-gv7v-rgg6-548h: multi-line, a later minor of a line whose fix shipped — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
< 2.5.4, 3.0.0 - 3.0.3, 4.0.0 - 4.0.3#4.0.4, 3.0.4, 2.5.4#3.0.4#RANGE-UNPARSEABLE#form-data GHSA-fjxv-7rqg-78g4: multi-line listed newest first — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
< 2.5.4, 3.0.0 - 3.0.3, 4.0.0 - 4.0.3#4.0.4, 3.0.4, 2.5.4#3.0.3#RANGE-UNPARSEABLE#form-data GHSA-fjxv-7rqg-78g4: multi-line, below its own line fix — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
< 6.23.0; > 7.0.0 < 7.18.2#7.18.2; 6.23.0#6.23.0#RANGE-UNPARSEABLE#undici GHSA-g9mf-h72j-4rw9: semicolon list, out of order — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
< 6.23.0; > 7.0.0 < 7.18.2#7.18.2; 6.23.0#7.18.1#RANGE-UNPARSEABLE#undici GHSA-g9mf-h72j-4rw9: semicolon list, below its own line fix — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
>= 1.0.0#1.5.3, 2.1.0#2.0.5#AFFECTED#SYNTHETIC: above an older line fix, below its own line fix
>= 1.0.0#1.5.3, 2.1.0#1.6.0#clean#SYNTHETIC: a later minor of a line whose fix shipped
>= 1.0.0#1.5.3, 2.1.0#1.5.2#AFFECTED#SYNTHETIC: below its own line fix, on the oldest line
>= 1.0.0#1.5.3, 2.1.0#3.0.0#clean#SYNTHETIC: KNOWN LIMIT (2) ABOVE EVERY LINE, fails open: a later major above every listed fix and every version the range names
>= 1.0.0#2.1.0, 3.0.4#1.9.0#AFFECTED#SYNTHETIC: sharing no leading number with any fix, the highest decides
>= 1.0.0#2.3.5, 3.0.4#2.4.0#clean#SYNTHETIC: KNOWN LIMIT (1) OWN LINE, fails open: a minor line with no listed fix, above a fix on an earlier minor
>=1.0.0 <2.5.0 or >=3.0.0 <3.1.0#2.0.0, 3.1.0#2.1.0#RANGE-UNPARSEABLE#SYNTHETIC (r4 review): an unread range carrying a word is not cleared by its patched text, so its upper bound 2.5.0 is never overruled
>= 1.0.0#2.3.5, 2.4.1#2.4.0#AFFECTED#SYNTHETIC: two fixes on one major, the nearer line decides
>= 1.0.0#2.3.5, 2.4.1#2.3.6#clean#SYNTHETIC: two fixes on one major, at or above the nearer line fix
>= 1.0.0#2.1.0, 2.6.0#2.5.3#AFFECTED#SYNTHETIC: two fixes equally near, the higher decides
>= 1.0.0#NULL#9.9.9#AFFECTED#SYNTHETIC: no patched version, the range alone decides
>= 1.0.0##9.9.9#AFFECTED#SYNTHETIC: an empty patched version, the range alone decides
>= 5.0.0#>=5.4.19, <6.0.0#5.4.19#AFFECTED#SYNTHETIC: vite GHSA-859w-5945-r5v3 patched RANGE under an open range is not read, so it never clears
>= 1.0.0#3.0.0 and all 2.x versions, 3.4.0#3.4.0#AFFECTED#SYNTHETIC: DOMPurify GHSA-v9jr-rg53-9pgp patched prose under an open range is not read
< 6.24.0; 7.0.0 < 7.24.0#6.24.0: 7.24.0#7.24.0#RANGE-UNPARSEABLE#undici GHSA-2mjp-6q6p-2qxm: the range is not read, and neither is its colon-separated patched list
>= 1.0.0#5.0.0-beta.3#5.0.0-beta.2#AFFECTED#SYNTHETIC: two tags on one core cannot be ordered, so the version is not cleared
>= 1.0.0#5.0.0-beta.3#5.0.0#clean#SYNTHETIC: a release is above its own beta
>=1.0.0 <1.2.3 || >=2.0.0 <2.0.5#1.2.3#2.0.1#AFFECTED#SYNTHETIC (r1 review): a readable range that bounds the version stays AFFECTED, whatever the patched text says
<1.5.0#1.3.0#1.4.0#AFFECTED#SYNTHETIC: one bounded interval holds, so a fix on the own line below the version does not clear it
>=1.0.0 <1.5.0#1.3.0#1.4.0#AFFECTED#SYNTHETIC: one bounded interval holds and begins below the fix, so a fix on the own line below the version does not clear it
>=1.0.0 <1.2.0 || >=2.0.0#1.2.0, 2.0.3#2.0.5#clean#SYNTHETIC: only the open alternative holds, so the patched text decides
<1.5.3 || >=2.0.0#1.5.3#2.0.1#AFFECTED#SYNTHETIC (r2 review): the open interval holding the version begins above the only fix, so it is not the fix of that line
<1.5.3 || >=2.0.0#1.5.3#2.9.0#AFFECTED#SYNTHETIC (r2 review): the open interval holding the version begins above the only fix, later on that line
<2.3.5 || >=2.4.0#2.3.5#2.4.1#AFFECTED#SYNTHETIC (r2 review): the open interval holding the version begins above the own-line fix
>=1.0.0 <1.5.3 || >=2.0.0#1.5.3#2.2.0#AFFECTED#SYNTHETIC (r2 review): the open interval holding the version begins above the highest fix
>=1.0.0 <1.5.0#1.4.0#1.5.0-p1#RANGE-UNPARSEABLE#SYNTHETIC (r2 review): a range that is read but cannot be compared at this version is not cleared by the patched text
<1.5.3, >=2.0.0 <2.0.5#1.5.3#2.0.1#RANGE-UNPARSEABLE#SYNTHETIC (r1 review): a comma between a single term and a bounded piece — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
<1.5.3, >=3.0.0 <3.0.5#1.5.3#2.0.1#RANGE-UNPARSEABLE#SYNTHETIC: a comma between a single term and a bounded piece, naming versions above the version — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
<1.5.3, >=2.0.0#1.5.3#2.0.0#RANGE-UNPARSEABLE#SYNTHETIC: a comma between a single term and an open piece, at a version it names — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
sometime after 2.x#1.5.3#3.0.0#RANGE-UNPARSEABLE#SYNTHETIC: prose naming a version that does not parse — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
sometime later#1.5.3#3.0.0#RANGE-UNPARSEABLE#SYNTHETIC: prose naming no version — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
<6.20.45,>=7,<7.30.7,>=8,<8.83.28,>=9,<9.52.17,>=10,<10.48.23,>=11,<11.31.0#6.20.45,7.30.7,8.83.28,9.52.17,10.48.23,11.31.0#v13.30.1#RANGE-UNPARSEABLE#laravel GHSA-gv7v-rgg6-548h: a later major above every fix and every version the range names — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
<11.44.1,>=12,<12.1.1#12.1.1,11.44.1#v13.30.1#RANGE-UNPARSEABLE#laravel GHSA-78fx-h6xr-vch4: a later major above every fix and every version the range names — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
< 2.5.4, 3.0.0 - 3.0.3, 4.0.0 - 4.0.3#3.0.4#3.0.4#RANGE-UNPARSEABLE#SYNTHETIC (r3 review): form-data GHSA-fjxv-7rqg-78g4 range with its own-line fix alone, at that fix — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
<1.5.3 | >=2.0.0#1.5.3#2.0.1#RANGE-UNPARSEABLE#SYNTHETIC (r3 review): a single pipe, naming 2.0.0 above the fix — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
<2.3.5 | >=2.4.0#2.3.5#2.4.1#RANGE-UNPARSEABLE#SYNTHETIC (r3 review): a single pipe, naming 2.4.0 above the own-line fix — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
>=1.0.0 <1.5.3 | >=2.0.0#1.5.3#2.2.0#RANGE-UNPARSEABLE#SYNTHETIC (r3 review): a single pipe, naming 2.0.0 above the highest fix — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
<1.5.3 || ^2.0.0#1.5.3#2.0.1#RANGE-UNPARSEABLE#SYNTHETIC (r3 review): a caret alternative — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
<1.5.3 or >=2.0.0#1.5.3#2.2.0#RANGE-UNPARSEABLE#SYNTHETIC (r3 review): an `or` — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
<2.3.5 | >=2.4.1#2.3.5#2.4.1#RANGE-UNPARSEABLE#SYNTHETIC: a single pipe naming the version itself — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
<1.5.3 or 1.x#1.5.3#1.5.4#RANGE-UNPARSEABLE#SYNTHETIC: an `or` naming a version that does not parse — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)
>=1.0.0 <1.5.3 || >=2.0.0#2.0.0#2.0.1#AFFECTED#SYNTHETIC: a read lower bound equal to the fix begins an interval at the fix, so not cleared
>=1.0.0 || >=4.0.0 <4.2.0#1.5.3, 2.1.0#3.0.0#AFFECTED#SYNTHETIC: no shared leading number, and the read range names versions above it, so not cleared
>=1.0.0 || >=0.5.0 <3.0.0#1.5.3, 2.1.0#3.0.0#AFFECTED#SYNTHETIC: no shared leading number, at a version the read range names, so not cleared
<2.3.5 || >=2.4.0#2.3.5#2.4.0#AFFECTED#SYNTHETIC: the open interval holding the version begins at the version, above the own-line fix, so not cleared'
patched_rows() {  # $1 = jq program -> "got#range PATCHED patched#version#want#source", one per row
  local prog="$1" range patched version want src got
  while IFS='#' read -r range patched version want src; do
    [ -n "$range" ] || continue
    got="$(verdict_of "$prog" "$range" "$patched" "$version")" || got="JQ-ERROR"
    printf '%s#%s PATCHED %s#%s#%s#%s\n' "$got" "$range" "$patched" "$version" "$want" "$src"
  done <<< "$PATCHED_TABLE"
}
PATCHED_OUT="$(patched_rows "$SHIPPED_PROG")"
n_table="$(grep -c . <<< "$PATCHED_TABLE")"
n_ran="$(grep -c . <<< "$PATCHED_OUT")"
[ "$n_ran" -eq "$n_table" ] && [ "$n_table" -gt 0 ] \
  && ok "patched table: every row ran ($n_ran of $n_table)" \
  || fail "patched table ran $n_ran of $n_table rows — rows are being dropped by the reader"
while IFS='#' read -r got range version want src; do
  [ -n "$got" ] || continue
  [ "$got" = "$want" ] && ok "'$range' @ $version → $got  [$src]" \
    || fail "'$range' @ $version should be $want, got $got  [$src]"
done <<< "$PATCHED_OUT"

# ══ §G THE REVIEWED GLOBAL RECORD — an unread range decided by the reviewed split of it ════
# card#11106 (pm ruling 2026-10-06). Each row puts one repository-advisory entry whose range the
# grammar does not read through the SHIPPED matcher, with the reviewed global records handed in
# the way the shipped caller hands them in (`--slurpfile reviewed`). Rows not marked SYNTHETIC
# copy the entry verbatim from `gh api repos/<owner>/<repo>/security-advisories` (fetched
# 2026-10-06) and key on the real reviewed record of that advisory, vendored in
# fixtures/reviewed/ (provenance in fixtures/README.md). SYNTHETIC rows key on a record in
# REVIEWED_SYNTH below, each built so that one guard is all that keeps it from reading clean.
# The rule is stated once, above reviewed_hit in the callee; this table does not restate it.
#   fields: ghsa # ecosystem:package # range # patched_versions # version # want # source
REVIEWED_SYNTH='{
  "GHSA-syn0-0000-0001": {"ghsa_id": "GHSA-syn0-0000-0001", "type": "unreviewed", "withdrawn_at": null,
    "vulnerabilities": [{"package": {"ecosystem": "npm", "name": "p"}, "vulnerable_version_range": "< 2.0.0"}]},
  "GHSA-syn0-0000-0002": {"lookup_failed": "the lookup exited non-zero: HTTP 502"},
  "GHSA-syn0-0000-0003": {"ghsa_id": "GHSA-syn0-0000-0003", "type": "reviewed", "withdrawn_at": null,
    "vulnerabilities": [{"package": {"ecosystem": "npm", "name": "p"}, "vulnerable_version_range": "< 1.0.0"},
                        {"package": {"ecosystem": "npm", "name": "p"}, "vulnerable_version_range": "^2.0.0"}]},
  "GHSA-syn0-0000-0004": {"ghsa_id": "GHSA-syn0-0000-0004", "type": "reviewed", "withdrawn_at": "2026-01-01T00:00:00Z",
    "vulnerabilities": [{"package": {"ecosystem": "npm", "name": "p"}, "vulnerable_version_range": "< 2.0.0"}]},
  "GHSA-syn0-0000-0005": {"ghsa_id": "GHSA-syn0-0000-9999", "type": "reviewed", "withdrawn_at": null,
    "vulnerabilities": [{"package": {"ecosystem": "npm", "name": "p"}, "vulnerable_version_range": "< 2.0.0"}]},
  "GHSA-syn0-0000-0006": {"ghsa_id": "GHSA-syn0-0000-0006", "type": "reviewed", "withdrawn_at": null,
    "vulnerabilities": [{"package": {"ecosystem": "npm", "name": "other"}, "vulnerable_version_range": "< 2.0.0"}]},
  "GHSA-syn0-0000-0007": {"ghsa_id": "GHSA-syn0-0000-0007", "type": "reviewed", "withdrawn_at": null,
    "vulnerabilities": [{"package": {"ecosystem": "npm", "name": "other"}, "vulnerable_version_range": ">= 1.0.0"},
                        {"package": {"ecosystem": "npm", "name": "p"}, "vulnerable_version_range": "< 2.0.0"}]},
  "GHSA-syn0-0000-0009": {"ghsa_id": "GHSA-syn0-0000-0009", "type": "reviewed", "withdrawn_at": null,
    "vulnerabilities": [{"package": {"ecosystem": "npm", "name": "p"}, "vulnerable_version_range": "< 2.0.0-beta.3"}]},
  "GHSA-syn0-0000-0010": {"ghsa_id": "GHSA-syn0-0000-0010", "type": "reviewed", "withdrawn_at": null,
    "vulnerabilities": [{"package": {"ecosystem": "npm", "name": "p"}, "vulnerable_version_range": ">= 1.0.0"}]},
  "GHSA-syn0-0000-0011": {"lookup_failed": "the lookup exited non-zero: gh: Not Found (HTTP 404)"},
  "GHSA-syn0-0000-0012": {"lookup_failed": "the lookup exited non-zero: gh: Resource not accessible by integration (HTTP 403)"}
}'
REVIEWED_TABLE='GHSA-gv7v-rgg6-548h#composer:laravel/framework#<6.20.45,>=7,<7.30.7,>=8,<8.83.28,>=9,<9.52.17,>=10,<10.48.23,>=11,<11.31.0#6.20.45,7.30.7,8.83.28,9.52.17,10.48.23,11.31.0#8.83.28#clean#laravel GHSA-gv7v-rgg6-548h: at the fix of its own line
GHSA-gv7v-rgg6-548h#composer:laravel/framework#<6.20.45,>=7,<7.30.7,>=8,<8.83.28,>=9,<9.52.17,>=10,<10.48.23,>=11,<11.31.0#6.20.45,7.30.7,8.83.28,9.52.17,10.48.23,11.31.0#8.84.1#clean#laravel GHSA-gv7v-rgg6-548h: a later minor of a line whose fix shipped
GHSA-gv7v-rgg6-548h#composer:laravel/framework#<6.20.45,>=7,<7.30.7,>=8,<8.83.28,>=9,<9.52.17,>=10,<10.48.23,>=11,<11.31.0#6.20.45,7.30.7,8.83.28,9.52.17,10.48.23,11.31.0#v13.30.1#clean#laravel GHSA-gv7v-rgg6-548h: a later major than every range of the record (sola-backend pins v13.30.1)
GHSA-gv7v-rgg6-548h#composer:laravel/framework#<6.20.45,>=7,<7.30.7,>=8,<8.83.28,>=9,<9.52.17,>=10,<10.48.23,>=11,<11.31.0#6.20.45,7.30.7,8.83.28,9.52.17,10.48.23,11.31.0#8.83.27#AFFECTED#laravel GHSA-gv7v-rgg6-548h: below the fix of its own line
GHSA-gv7v-rgg6-548h#composer:laravel/framework#<6.20.45,>=7,<7.30.7,>=8,<8.83.28,>=9,<9.52.17,>=10,<10.48.23,>=11,<11.31.0#6.20.45,7.30.7,8.83.28,9.52.17,10.48.23,11.31.0#v11.30.0#AFFECTED#laravel GHSA-gv7v-rgg6-548h: below the fix of the newest line it lists
GHSA-78fx-h6xr-vch4#composer:laravel/framework#<11.44.1,>=12,<12.1.1#12.1.1,11.44.1#v13.30.1#clean#laravel GHSA-78fx-h6xr-vch4: a later major than every range of the record (sola-backend pins v13.30.1)
GHSA-78fx-h6xr-vch4#composer:laravel/framework#<11.44.1,>=12,<12.1.1#12.1.1,11.44.1#v12.1.0#AFFECTED#laravel GHSA-78fx-h6xr-vch4: below the fix of the 12 line
GHSA-78fx-h6xr-vch4#composer:laravel/framework#<11.44.1,>=12,<12.1.1#12.1.1,11.44.1#v10.48.28#AFFECTED#laravel GHSA-78fx-h6xr-vch4: the record splits the 10 line out of the free text "<11.44.1"
GHSA-66hf-2p6w-jqfw#composer:laravel/framework#>=8.0.0, <8.75.0, >=7.0.0, <7.30.6, <6.20.42#6.20.42, 7.30.6, 8.75.0#v13.30.1#clean#laravel GHSA-66hf-2p6w-jqfw: the record also lists illuminate/view, whose ranges are not read for this package
GHSA-66hf-2p6w-jqfw#composer:laravel/framework#>=8.0.0, <8.75.0, >=7.0.0, <7.30.6, <6.20.42#6.20.42, 7.30.6, 8.75.0#8.74.0#AFFECTED#laravel GHSA-66hf-2p6w-jqfw: below the fix of its own line
GHSA-g9mf-h72j-4rw9#npm:undici#< 6.23.0; > 7.0.0 < 7.18.2#7.18.2; 6.23.0#6.23.0#clean#undici GHSA-g9mf-h72j-4rw9: at the fix of its own line
GHSA-g9mf-h72j-4rw9#npm:undici#< 6.23.0; > 7.0.0 < 7.18.2#7.18.2; 6.23.0#6.22.0#AFFECTED#undici GHSA-g9mf-h72j-4rw9: below the fix of its own line
GHSA-g9mf-h72j-4rw9#npm:undici#< 6.23.0; > 7.0.0 < 7.18.2#7.18.2; 6.23.0#7.18.1#AFFECTED#undici GHSA-g9mf-h72j-4rw9: below the fix of the 7 line
GHSA-g9mf-h72j-4rw9#npm:undici#< 6.23.0; > 7.0.0 < 7.18.2#7.18.2; 6.23.0#7.18.2#clean#undici GHSA-g9mf-h72j-4rw9: at the fix of the 7 line
GHSA-fjxv-7rqg-78g4#npm:form-data#< 2.5.4, 3.0.0 - 3.0.3, 4.0.0 - 4.0.3#4.0.4, 3.0.4, 2.5.4#3.0.4#clean#form-data GHSA-fjxv-7rqg-78g4: at the fix of its own line
GHSA-fjxv-7rqg-78g4#npm:form-data#< 2.5.4, 3.0.0 - 3.0.3, 4.0.0 - 4.0.3#4.0.4, 3.0.4, 2.5.4#3.0.3#AFFECTED#form-data GHSA-fjxv-7rqg-78g4: below the fix of its own line
GHSA-2mjp-6q6p-2qxm#npm:undici#< 6.24.0; 7.0.0 < 7.24.0#6.24.0: 7.24.0#7.24.0#clean#undici GHSA-2mjp-6q6p-2qxm: at the fix of the 7 line
GHSA-2mjp-6q6p-2qxm#npm:undici#< 6.24.0; 7.0.0 < 7.24.0#6.24.0: 7.24.0#7.23.0#AFFECTED#undici GHSA-2mjp-6q6p-2qxm: below the fix of the 7 line
GHSA-r4gj-5m52-g5wh#npm:axios#1.17.0#>=1.20.0#1.18.0#AFFECTED#axios GHSA-r4gj-5m52-g5wh: a bare version in the repository advisory; its reviewed global record reads ">= 1.17.0, < 1.20.0", which holds 1.18.0
GHSA-r4gj-5m52-g5wh#npm:axios#1.17.0#>=1.20.0#1.19.0#AFFECTED#axios GHSA-r4gj-5m52-g5wh: the reviewed global record holds 1.19.0, below its fix
GHSA-r4gj-5m52-g5wh#npm:axios#1.17.0#>=1.20.0#1.20.0#clean#axios GHSA-r4gj-5m52-g5wh: at the fix the reviewed global record names
GHSA-654m-c8p4-x5fp#npm:axios#1.15.2#>=1.16.0#1.15.2#AFFECTED#axios GHSA-654m-c8p4-x5fp: its reviewed global record reads "= 1.15.2", which holds 1.15.2
GHSA-654m-c8p4-x5fp#npm:axios#1.15.2#>=1.16.0#1.17.0#clean#axios GHSA-654m-c8p4-x5fp: "= 1.15.2" does not hold 1.17.0
GHSA-654m-c8p4-x5fp#npm:axios#1.15.2#>=1.16.0#1.20.0#clean#axios GHSA-654m-c8p4-x5fp: "= 1.15.2" does not hold 1.20.0
GHSA-rm8p-cx58-hcvx#npm:axios#1.10.0#1.11.0#1.20.0#clean#axios GHSA-rm8p-cx58-hcvx (card 11604): at or above its one fix, decided without its reviewed global record, which was withdrawn 2025-07-24
GHSA-rm8p-cx58-hcvx#npm:axios#1.10.0#1.11.0#1.10.0#AFFECTED#axios GHSA-rm8p-cx58-hcvx (card 11604): the version equal to the bare version, decided without its withdrawn record
GHSA-rm8p-cx58-hcvx#npm:axios#1.10.0#1.11.0#1.10.5#WITHDRAWN#axios GHSA-rm8p-cx58-hcvx: between the bare version and the fix no reading agrees, and its reviewed global record was withdrawn 2025-07-24, so it is a notice, not a finding and not an error (card#11434)
GHSA-syn0-0000-0011#npm:axios#1.17.0#>=1.20.0#1.18.0#RANGE-UNPARSEABLE#SYNTHETIC (card 11604): the GHSA-r4gj-5m52-g5wh entry at 1.18.0 with its global lookup answering 404 stays unread
GHSA-syn0-0000-0012#npm:follow-redirects#1.16.0#1.16.1#1.15.9#RANGE-UNPARSEABLE#SYNTHETIC (card 11604): the follow-redirects GHSA-8r9p-f939-6h3c entry below its fix with its global lookup answering 403 stays unread
GHSA-syn0-0000-0011#npm:axios#1.17.0#>=1.20.0#1.20.0#clean#SYNTHETIC (card 11604): at its one fix the entry is decided without the global lookup that answered 404
GHSA-syn0-0000-0001#npm:p#sometime after 1.0#1.0.0#3.0.0#RANGE-UNPARSEABLE#SYNTHETIC: a global record that is not reviewed is not used
GHSA-syn0-0000-0002#npm:p#sometime after 1.0#1.0.0#3.0.0#RANGE-UNPARSEABLE#SYNTHETIC: a lookup that failed stays unread
GHSA-syn0-0000-0003#npm:p#sometime after 1.0#1.0.0#3.0.0#RANGE-UNPARSEABLE#SYNTHETIC: a reviewed record with one range not read and none holding stays unread
GHSA-syn0-0000-0003#npm:p#sometime after 1.0#1.0.0#0.5.0#AFFECTED#SYNTHETIC: a reviewed record with one range not read and another holding is affected
GHSA-syn0-0000-0004#npm:p#sometime after 1.0#1.0.0#3.0.0#WITHDRAWN#SYNTHETIC: a withdrawn reviewed record is not used to decide the range; the entry is a notice
GHSA-syn0-0000-0005#npm:p#sometime after 1.0#1.0.0#3.0.0#RANGE-UNPARSEABLE#SYNTHETIC: a record naming another advisory is not used
GHSA-syn0-0000-0006#npm:p#sometime after 1.0#1.0.0#3.0.0#RANGE-UNPARSEABLE#SYNTHETIC: a reviewed record with no range for this package is not used
GHSA-syn0-0000-0007#npm:p#sometime after 1.0#1.0.0#3.0.0#clean#SYNTHETIC: a range of another package in the record does not hold for this one
GHSA-syn0-0000-0008#npm:p#sometime after 1.0#1.0.0#3.0.0#RANGE-UNPARSEABLE#SYNTHETIC: an advisory whose record was never looked up stays unread
GHSA-syn0-0000-0009#npm:p#sometime after 1.0#1.0.0#2.0.0-beta.1#RANGE-UNPARSEABLE#SYNTHETIC: a reviewed range that cannot be compared at the version stays unread
GHSA-syn0-0000-0010#npm:p#sometime after 1.0#1.0.0#3.0.0#AFFECTED#SYNTHETIC: the reviewed range decides, and the patched text (1.0.0, below the version) is not read'
REVIEWED_MAP="$TMP/reviewed-map.json"
jq -s --argjson syn "$REVIEWED_SYNTH" 'map({(.ghsa_id): .}) | add + $syn' "$FIX"/reviewed/*.json > "$REVIEWED_MAP" \
  || { fail "reviewed table: could not build the map of reviewed records from fixtures/reviewed"; finish; }
reviewed_rows() {  # $1 = jq program -> "got#ghsa range#version#want#source", one per table row
  local prog="$1" ghsa pkg range patched version want src got
  while IFS='#' read -r ghsa pkg range patched version want src; do
    [ -n "$ghsa" ] || continue
    got="$(verdict_of "$prog" "$range" "$patched" "$version" "$ghsa" "$pkg" "$REVIEWED_MAP")" || got="JQ-ERROR"
    printf '%s#%s %s#%s#%s#%s\n' "$got" "$ghsa" "$range" "$version" "$want" "$src"
  done <<< "$REVIEWED_TABLE"
}
REVIEWED_OUT="$(reviewed_rows "$SHIPPED_PROG")"
n_table="$(grep -c . <<< "$REVIEWED_TABLE")"
n_ran="$(grep -c . <<< "$REVIEWED_OUT")"
[ "$n_ran" -eq "$n_table" ] && [ "$n_table" -gt 0 ] \
  && ok "reviewed table: every row ran ($n_ran of $n_table)" \
  || fail "reviewed table ran $n_ran of $n_table rows — rows are being dropped by the reader"
while IFS='#' read -r got range version want src; do
  [ -n "$got" ] || continue
  [ "$got" = "$want" ] && ok "'$range' @ $version → $got  [$src]" \
    || fail "'$range' @ $version should be $want, got $got  [$src]"
done <<< "$REVIEWED_OUT"
# A failed lookup and a record never looked up each have a branch that only names the reason (no
# row can flip on either, §M says why), so the reason each one names is asserted here.
why_of() {  # $1 = GHSA ID in REVIEWED_MAP -> the reason the shipped matcher gives for its unread entry at 3.0.0
  jq -cn --arg g "$1" '[{ghsa_id: $g, withdrawn_at: null, vulnerabilities: [{package: {ecosystem: "npm", name: "p"}, vulnerable_version_range: "sometime after 1.0", patched_versions: null}]}]' \
    | jq -r --arg package p --arg version 3.0.0 --arg ecosystem npm --slurpfile reviewed "$REVIEWED_MAP" "$SHIPPED_PROG" \
    | jq -r '.[0].reviewed // "no reason"'
}
why="$(why_of GHSA-syn0-0000-0008)"
[ "$why" = "its global record was not looked up" ] \
  && ok "reviewed table: a record never looked up is named as such ('$why')" \
  || fail "reviewed table: a record never looked up should be named 'its global record was not looked up', got '$why'"
why="$(why_of GHSA-syn0-0000-0002)"
[ "$why" = "the lookup of its global record failed: the lookup exited non-zero: HTTP 502" ] \
  && ok "reviewed table: a failed lookup is named with its own reason ('$why')" \
  || fail "reviewed table: a failed lookup should carry its own reason, got '$why'"

# The same rule end to end: the repository advisories of laravel/framework
# (fixtures/advisories/laravel@framework.json, the full set) through discovery, source 2, the
# reviewed-record seam and the finding count.
laravel_repo() {  # $1 = repo dir name, $2 = laravel/framework version -> a repo with a composer lockfile pinning it
  local d="$TMP/$1"; mkdir -p "$d"
  jq -n --arg v "$2" '{packages: [{name: "laravel/framework", version: $v, source: {type: "git", url: "https://github.com/laravel/framework.git", reference: "0"}}], "packages-dev": []}' > "$d/composer.lock"
  printf '%s' "$d"
}
REV_LOGGED='printf "%s\n" "$1" >> "'"$TMP"'/rev_calls"; cat "$FIX/reviewed/$1.json";'
rev_check() {  # $1=repo $2=seam prelude $3=want: GREEN | an error code $4=detail the log must carry ("" = none) [$5=blocks] -> 0 when the run matches
  local out rc
  : > "$TMP/rev_calls"
  out="$(run_audit "$1" "$2" "" "${5:-}")"; rc=$?
  REV_OUT="$out"
  if [ "$3" = GREEN ]; then
    [ "$rc" -eq 0 ] && [ "$(pop findings)" = "0" ] && [ "$(pop errors)" = "0" ]
  else
    [ "$rc" -ne 0 ] && pop_has error_codes "$3" && { [ -z "$4" ] || has "$out" "$4"; }
  fi
}
LARAVEL_AT_FIX="$(laravel_repo laravel-v13 v13.30.1)"
# The advisories looked up are DERIVED from the fixtures, never typed: every vendored reviewed
# record that lists laravel/framework. Each is an advisory whose laravel/framework range in the
# repository set the grammar does not read.
LARAVEL_WANT_LOOKUPS="$(jq -r 'select(any(.vulnerabilities[]; .package.name == "laravel/framework")) | .ghsa_id' "$FIX"/reviewed/*.json | sort -u)"
if rev_check "$LARAVEL_AT_FIX" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE" "$REV_LOGGED")" GREEN "" \
   && [ -n "$LARAVEL_WANT_LOOKUPS" ] && [ "$(sort -u "$TMP/rev_calls")" = "$LARAVEL_WANT_LOOKUPS" ]; then
  ok "laravel/framework v13.30.1 against its repository advisories → GREEN, every unread range decided by its reviewed record ($(tr '\n' ' ' <<< "$LARAVEL_WANT_LOOKUPS"))"
else
  fail "laravel/framework v13.30.1 should be GREEN with lookups '$(tr '\n' ' ' <<< "$LARAVEL_WANT_LOOKUPS")', got lookups '$(sort -u "$TMP/rev_calls" | tr '\n' ' ')' (findings='$(pop findings)', errors='$(pop errors)'): $REV_OUT"
fi
out="$(run_audit "$(laravel_repo laravel-v11 v11.30.0)" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE")")"; rc=$?
if [ "$rc" -ne 0 ] && [ "$(pop errors)" = "0" ] \
   && has "$out" 'SOURCE2 laravel/framework@v11.30.0 GHSA-gv7v-rgg6-548h' \
   && has "$out" 'SOURCE2 laravel/framework@v11.30.0 GHSA-78fx-h6xr-vch4'; then
  ok "laravel/framework v11.30.0 → RED with findings=$(pop findings), GHSA-gv7v-rgg6-548h and GHSA-78fx-h6xr-vch4 among them, read off their reviewed records (errors=0)"
else
  fail "laravel/framework v11.30.0 should red on GHSA-gv7v-rgg6-548h and GHSA-78fx-h6xr-vch4 with errors=0 (rc=$rc, findings='$(pop findings)', errors='$(pop errors)'): $out"
fi
# Each way a lookup can fail leaves the range unread: RED, with the reason named.
REV_FAIL='return 22;'
REV_FAIL_WITH_RECORD='cat "$FIX/reviewed/$1.json"; return 22;'
REV_NOT_OBJECT='printf %s "[]";'
REV_SLURP='cat >/dev/null; cat "$FIX/reviewed/$1.json";'
rev_check "$LARAVEL_AT_FIX" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE" "$REV_FAIL")" SOURCE2-RANGE-UNPARSEABLE 'the lookup of its global record failed: the lookup exited non-zero' \
  && ok "a reviewed-record lookup that exits non-zero → RED (SOURCE2-RANGE-UNPARSEABLE, the failed lookup named)" \
  || fail "a failed reviewed-record lookup must red and be named: $REV_OUT"
rev_check "$LARAVEL_AT_FIX" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE" "$REV_FAIL_WITH_RECORD")" SOURCE2-RANGE-UNPARSEABLE 'the lookup exited non-zero' \
  && ok "a lookup that prints a valid reviewed record but exits non-zero → RED: the exit status decides, not the payload" \
  || fail "a lookup that exits non-zero must red even when it printed a record: $REV_OUT"
rev_check "$LARAVEL_AT_FIX" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE" "$REV_NOT_OBJECT")" SOURCE2-RANGE-UNPARSEABLE 'a payload that is not a JSON object' \
  && ok "a lookup payload that is not a JSON object → RED (SOURCE2-RANGE-UNPARSEABLE, named)" \
  || fail "a non-object reviewed-record payload must red and be named: $REV_OUT"
rev_check "$LARAVEL_AT_FIX" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE" "$REV_SLURP")" GREEN "" \
  && ok "a lookup seam that reads stdin does NOT eat the list of advisories still to look up (still GREEN)" \
  || fail "a stdin-consuming reviewed-record seam truncated the lookups: $REV_OUT"
# A GHSA ID is built into an API path, so one that is not GHSA-shaped (here it carries a query
# string) is refused before the seam.
mkdir -p "$TMP/badghsa/advisories"
jq '[.[] | if .ghsa_id == "GHSA-78fx-h6xr-vch4" then .ghsa_id = "GHSA-78fx-h6xr-vch4?per_page=1" else . end]' \
  "$FIX/advisories/laravel@framework.json" > "$TMP/badghsa/advisories/laravel@framework.json"
cp "$FIX/advisories/thephpleague@commonmark.json" "$TMP/badghsa/advisories/"
ADV_BADGHSA='cat "'"$TMP"'/badghsa/advisories/$(printf %s "$1" | tr / @).json";'
if rev_check "$LARAVEL_AT_FIX" "$(seam "$OSV_CLEAN" "$ADV_BADGHSA" "$REG_NONE" "$REV_LOGGED")" SOURCE2-RANGE-UNPARSEABLE 'is not a GHSA ID' \
   && ! grep -q 'GHSA-78fx-h6xr-vch4?' "$TMP/rev_calls"; then
  ok "an advisory ID that is not GHSA-shaped → RED, and the lookup seam is never called with it"
else
  fail "an ID that is not GHSA-shaped must red without reaching the seam (seam calls: '$(tr '\n' ' ' < "$TMP/rev_calls")'): $REV_OUT"
fi

# The same rule end to end, over the LIVE advisory set the defect was measured on: axios's own
# repository advisories (fixtures/advisories/axios@axios.json), through discovery, the registry
# hop, the reviewed-record lookups and the finding count. At 1.20.0 every advisory is fixed at or
# below the version, and the run is GREEN with no lookup made; at 1.19.0 the ranges fixed in 1.20.0
# still red, and one fixed in 1.18.0 does not.
axios_repo() {  # $1 = repo dir name, $2 = axios version -> a repo with an npm lockfile pinning it
  local d="$TMP/$1"; mkdir -p "$d"
  jq -n --arg v "$2" '{name: "fixture-app", lockfileVersion: 3, requires: true, packages: {"": {name: "fixture-app", version: "0.0.0"}, "node_modules/axios": {version: $v}}}' > "$d/package-lock.json"
  printf '%s' "$d"
}
REG_AXIOS='case "$1" in axios) printf %s "axios/axios";; *) return 1;; esac'
# card#11604: the three bare-version advisories (GHSA-r4gj-5m52-g5wh "1.17.0", GHSA-654m-c8p4-x5fp
# "1.15.2", GHSA-rm8p-cx58-hcvx "1.10.0", whose reviewed global record is withdrawn) are each at or
# below their one fix at 1.20.0, so every reading agrees and no global record is looked up.
: > "$TMP/rev_calls"
out="$(run_audit "$(axios_repo axios-at-fix 1.20.0)" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_AXIOS" "$REV_LOGGED")")"; rc=$?
if [ "$rc" -eq 0 ] && [ "$(pop findings)" = "0" ] && [ "$(pop errors)" = "0" ] && [ "$(pop resolved)" = "1" ] \
   && [ ! -s "$TMP/rev_calls" ]; then
  ok "axios 1.20.0 against its live advisory set → GREEN (findings=0, errors=0), no reviewed global record looked up (card#11604)"
else
  fail "axios 1.20.0 should be GREEN with no reviewed-record lookup (rc=$rc, findings='$(pop findings)', errors='$(pop errors)', lookups='$(tr '\n' ' ' < "$TMP/rev_calls")'): $out"
fi
out="$(run_audit "$(axios_repo axios-below-fix 1.19.0)" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_AXIOS")")"; rc=$?
# The expected finding count is DERIVED from the fixture, never typed: every axios entry with a
# lower bound only (an open range the parser reads) whose patched text is ">=1.20.0", plus
# GHSA-r4gj-5m52-g5wh, whose bare range "1.17.0" no reading decides at 1.19.0 and whose reviewed
# global record (">= 1.17.0, < 1.20.0") holds 1.19.0. GHSA-rm8p-cx58-hcvx is at or above its one fix
# (1.11.0), so it is not an error (card#11604).
AXIOS_WANT_FINDINGS="$(jq '[.[] | select(.withdrawn_at == null) | .vulnerabilities[]
  | select(.package.name == "axios" and .patched_versions == ">=1.20.0")
  | select(.vulnerable_version_range | test("^\\s*(?:>=|=>|>)\\s*[0-9][^\\s<>=,;|&]*\\s*$"))] | length + 1' \
  "$FIX/advisories/axios@axios.json")"
if [ "$rc" -ne 0 ] && has "$out" 'SOURCE2 axios@1.19.0 GHSA-542g-h47m-68v8' \
   && has "$out" 'SOURCE2 axios@1.19.0 GHSA-r4gj-5m52-g5wh' \
   && ! has "$out" 'GHSA-42h9-826w-cgv3' && [[ $AXIOS_WANT_FINDINGS =~ ^[1-9][0-9]*$ ]] \
   && [ "$(pop findings)" = "$AXIOS_WANT_FINDINGS" ] && [ "$(pop errors)" = "0" ] \
   && ! has "$out" 'GHSA-rm8p-cx58-hcvx'; then
  ok "axios 1.19.0 against its live advisory set → RED: findings=$(pop findings) (the fixture's open ranges fixed in 1.20.0, and GHSA-r4gj-5m52-g5wh read off its reviewed global record), not on one fixed in 1.18.0, errors=0 (GHSA-rm8p-cx58-hcvx at or above its fix)"
else
  fail "axios 1.19.0 should red with findings=$AXIOS_WANT_FINDINGS (derived from the fixture) including GHSA-r4gj-5m52-g5wh, not on GHSA-42h9-826w-cgv3, and errors=0 with GHSA-rm8p-cx58-hcvx absent (rc=$rc, findings='$(pop findings)', errors='$(pop errors)'): $out"
fi
# card#11434 (backend) within card#11604: GHSA-rm8p-cx58-hcvx
# writes the bare version "1.10.0", patched 1.11.0, and its reviewed global record was withdrawn on
# 2025-07-24. Between the bare version and the fix no reading of it agrees, so the withdrawn record
# is reached, and a withdrawn advisory is not a finding and not an error: it is printed as a NOTICE
# naming it, and exported in the population's `withdrawn`. (Other advisories hold 1.10.5, so the
# run is RED on findings; this leg asserts the notice, and that the advisory is not an error.)
out="$(run_audit "$(axios_repo axios-withdrawn-window 1.10.5)" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_AXIOS")")"; rc=$?
if [ "$(pop errors)" = "0" ] && pop_has withdrawn GHSA-rm8p-cx58-hcvx \
   && has "$out" 'NOTICE[WITHDRAWN] axios@1.10.5 GHSA-rm8p-cx58-hcvx' \
   && ! has "$out" 'SOURCE2 axios@1.10.5 GHSA-rm8p-cx58-hcvx'; then
  ok "axios 1.10.5 (between GHSA-rm8p-cx58-hcvx's bare version and its fix) → GHSA-rm8p-cx58-hcvx a printed NOTICE, exported in withdrawn, neither a finding nor an error"
else
  fail "axios 1.10.5 should print GHSA-rm8p-cx58-hcvx as a NOTICE, exported in withdrawn, not a finding or an error (rc=$rc, findings='$(pop findings)', errors='$(pop errors)', withdrawn='$(pop withdrawn)'): $out"
fi

# card#11604 end to end, over the live advisory sets the daily red was measured on
# (fixtures/advisories/follow-redirects@follow-redirects.json, expressjs@express.json,
# honojs@node-server.json), with EVERY reviewed-record lookup failing as the global endpoint did
# (404/403). At the installed versions every entry is decided by what every reading agrees on, so
# the run is GREEN and makes no lookup; below a fix, the undecided entry still reds, its failed
# lookup named.
npm_repo() {  # $1 = repo dir name, then "name@version" pairs -> a repo with an npm lockfile pinning them
  local d="$TMP/$1" pkgs='{}' nv; shift; mkdir -p "$d"
  for nv in "$@"; do
    pkgs="$(jq -c --arg n "${nv%@*}" --arg v "${nv##*@}" '. + {("node_modules/" + $n): {version: $v}}' <<< "$pkgs")"
  done
  jq -n --argjson p "$pkgs" '{name: "fixture-app", lockfileVersion: 3, requires: true, packages: ({"": {name: "fixture-app", version: "0.0.0"}} + $p)}' > "$d/package-lock.json"
  printf '%s' "$d"
}
REG_11604='case "$1" in follow-redirects) printf %s "follow-redirects/follow-redirects";; express) printf %s "expressjs/express";; @hono/node-server) printf %s "honojs/node-server";; *) return 1;; esac'
REV_LOGGED_FAIL='printf "%s\n" "$1" >> "'"$TMP"'/rev_calls"; echo "gh: Not Found (HTTP 404)" >&2; return 1;'
: > "$TMP/rev_calls"
out="$(run_audit "$(npm_repo live-11604 follow-redirects@1.16.1 express@5.2.1 @hono/node-server@2.1.3)" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_11604" "$REV_LOGGED_FAIL")")"; rc=$?
if [ "$rc" -eq 0 ] && [ "$(pop findings)" = "0" ] && [ "$(pop errors)" = "0" ] && [ "$(pop resolved)" = "3" ] \
   && [ ! -s "$TMP/rev_calls" ]; then
  ok "follow-redirects 1.16.1, express 5.2.1, @hono/node-server 2.1.3 against their live advisory sets, every global lookup failing → GREEN, no lookup made (card#11604)"
else
  fail "follow-redirects 1.16.1, express 5.2.1, @hono/node-server 2.1.3 should be GREEN with no lookup (rc=$rc, findings='$(pop findings)', errors='$(pop errors)', resolved='$(pop resolved)', lookups='$(tr '\n' ' ' < "$TMP/rev_calls")'): $out"
fi
: > "$TMP/rev_calls"
out="$(run_audit "$(npm_repo below-11604 follow-redirects@1.15.12)" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_11604" "$REV_LOGGED_FAIL")")"; rc=$?
if [ "$rc" -ne 0 ] && pop_has error_codes SOURCE2-RANGE-UNPARSEABLE \
   && has "$out" 'GHSA-8r9p-f939-6h3c "1.16.0" (the lookup of its global record failed' \
   && grep -qx 'GHSA-8r9p-f939-6h3c' "$TMP/rev_calls"; then
  ok "follow-redirects 1.15.12 (below the fix, not the bare version) with the global lookup failing → RED, SOURCE2-RANGE-UNPARSEABLE with the failed lookup named"
else
  fail "follow-redirects 1.15.12 should red on GHSA-8r9p-f939-6h3c with its failed lookup named (rc=$rc, codes='$(pop error_codes)', lookups='$(tr '\n' ' ' < "$TMP/rev_calls")'): $out"
fi

# ══ §A THE ACCEPTANCE INPUT — a reviewed way out for one advisory whose range is not read ═══
# card#11434. follow-redirects GHSA-8r9p-f939-6h3c and GHSA-q3jh-wrc6-jgq8 write the bare range
# "1.16.0" (patched 1.16.1), and on 2026-10-07 `gh api /advisories/<GHSA>` returned 404 for both:
# no reviewed record exists, so the range is not read, and every version that no reading of it
# decides (card#11604: neither the bare version, a finding, nor at or above the fix, clean) reds;
# these legs run at 1.15.12, below the fix
# (fixtures/advisories/follow-redirects@follow-redirects.json, the live set; no fixture under
# fixtures/reviewed/, so the lookup seam fails as `gh api` does on that 404). The adopting repo's
# acceptance file (ACCEPT_FILE) accepts one advisory by ID, at the versions it lists, with a
# reason, a reviewer and a date;
# the rule is stated once, above read_acceptances in the callee, and the doc's § Accepting one
# advisory owns the file's format.
fr_repo() {  # $1 = repo dir name, $2 = follow-redirects version -> a repo with an npm lockfile pinning it
  local d="$TMP/$1"; mkdir -p "$d"
  jq -n --arg v "$2" '{name: "fixture-app", lockfileVersion: 3, requires: true, packages: {"": {name: "fixture-app", version: "0.0.0"}, "node_modules/follow-redirects": {version: $v}}}' > "$d/package-lock.json"
  printf '%s' "$d"
}
accept_file() {  # $1 = repo dir, $2 = the file's content -> writes the repo's acceptance file
  mkdir -p "$1/.github"; printf '%s\n' "$2" > "$1/.github/dependency-audit-accept.json"
}
REG_FR='case "$1" in follow-redirects) printf %s "follow-redirects/follow-redirects";; *) return 1;; esac'
FR_SEAM="$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_FR")"; cp "$FR_SEAM" "$TMP/fr-seam.sh"; FR_SEAM="$TMP/fr-seam.sh"
ACC_8R9P='{"advisory": "GHSA-8r9p-f939-6h3c", "versions": ["1.15.12"], "reason": "follow-redirects is the Node HTTP adapter of axios; the shipped browser bundle uses XHR/fetch", "reviewer": "backend", "date": "2026-10-07", "expires": "2999-12-31"}'
ACC_Q3JH='{"advisory": "GHSA-q3jh-wrc6-jgq8", "versions": ["1.15.12"], "reason": "as GHSA-8r9p-f939-6h3c: not reachable from the browser bundle", "reviewer": "backend", "date": "2026-10-07", "expires": "2999-12-31"}'
# Without an acceptance file the unread range is RED at 1.15.12, as before: an unread range that no
# reading decides never passes on its own.
FR_FIXED="$(fr_repo fr-none 1.15.12)"
rev_check "$FR_FIXED" "$FR_SEAM" SOURCE2-RANGE-UNPARSEABLE 'GHSA-8r9p-f939-6h3c "1.16.0"' \
  && has "$REV_OUT" 'GHSA-q3jh-wrc6-jgq8 "1.16.0"' \
  && ok "follow-redirects 1.15.12 with no acceptance file → RED (SOURCE2-RANGE-UNPARSEABLE), both bare-range advisories named" \
  || fail "follow-redirects 1.15.12 with no acceptance file must red on both unread ranges: $REV_OUT"
FR_ACC="$(fr_repo fr-accepted 1.15.12)"
accept_file "$FR_ACC" "{\"accepted\": [$ACC_8R9P, $ACC_Q3JH]}"
if rev_check "$FR_ACC" "$FR_SEAM" GREEN "" \
   && pop_has accepted GHSA-8r9p-f939-6h3c && pop_has accepted GHSA-q3jh-wrc6-jgq8 \
   && has "$REV_OUT" 'NOTICE[ACCEPTED] follow-redirects@1.15.12 GHSA-8r9p-f939-6h3c' \
   && has "$REV_OUT" 'accepted by backend on 2026-10-07, expires 2999-12-31' \
   && has "$REV_OUT" 'accepted GHSA-q3jh-wrc6-jgq8 at 1.15.12: as GHSA-8r9p-f939-6h3c: not reachable'; then
  ok "follow-redirects 1.15.12 with both advisories accepted at 1.15.12 → GREEN, each acceptance printed with its versions, reviewer, date and reason, and exported in accepted"
else
  fail "follow-redirects 1.15.12 with both accepted should be GREEN, each acceptance printed and exported (accepted='$(pop accepted)'): $REV_OUT"
fi
FR_ONE="$(fr_repo fr-one 1.15.12)"
accept_file "$FR_ONE" "{\"accepted\": [$ACC_8R9P]}"
if rev_check "$FR_ONE" "$FR_SEAM" SOURCE2-RANGE-UNPARSEABLE 'GHSA-q3jh-wrc6-jgq8 "1.16.0"' \
   && ! has "$REV_OUT" 'GHSA-8r9p-f939-6h3c "1.16.0"' \
   && pop_has accepted GHSA-8r9p-f939-6h3c && ! pop_has accepted GHSA-q3jh-wrc6-jgq8; then
  ok "one of the two accepted → still RED on the other alone: an acceptance names one advisory, never its neighbour"
else
  fail "with only GHSA-8r9p-f939-6h3c accepted the run should red on GHSA-q3jh-wrc6-jgq8 alone (accepted='$(pop accepted)'): $REV_OUT"
fi
# An entry missing a reviewer, a date or a reason is REFUSED: the run reds naming it, and the
# advisory it names is not accepted.
FR_NOREV="$(fr_repo fr-noreviewer 1.15.12)"
accept_file "$FR_NOREV" "{\"accepted\": [$(jq -c 'del(.reviewer)' <<< "$ACC_8R9P"), $ACC_Q3JH]}"
if rev_check "$FR_NOREV" "$FR_SEAM" ACCEPT-ENTRY-REFUSED 'GHSA-8r9p-f939-6h3c' \
   && pop_has error_codes SOURCE2-RANGE-UNPARSEABLE && ! pop_has accepted GHSA-8r9p-f939-6h3c \
   && has "$REV_OUT" 'no reviewer'; then
  ok "an acceptance entry with no reviewer → REFUSED (ACCEPT-ENTRY-REFUSED, 'no reviewer'), and its advisory stays RED"
else
  fail "an acceptance entry with no reviewer must be refused and its advisory stay RED (codes='$(pop error_codes)', accepted='$(pop accepted)'): $REV_OUT"
fi
FR_NODATE="$(fr_repo fr-nodate 1.15.12)"
accept_file "$FR_NODATE" "{\"accepted\": [$(jq -c 'del(.date)' <<< "$ACC_8R9P"), $ACC_Q3JH]}"
if rev_check "$FR_NODATE" "$FR_SEAM" ACCEPT-ENTRY-REFUSED 'GHSA-8r9p-f939-6h3c' \
   && pop_has error_codes SOURCE2-RANGE-UNPARSEABLE && ! pop_has accepted GHSA-8r9p-f939-6h3c \
   && has "$REV_OUT" 'no date'; then
  ok "an acceptance entry with no date → REFUSED (ACCEPT-ENTRY-REFUSED, 'no date'), and its advisory stays RED"
else
  fail "an acceptance entry with no date must be refused and its advisory stay RED (codes='$(pop error_codes)', accepted='$(pop accepted)'): $REV_OUT"
fi
FR_BADDATE="$(fr_repo fr-baddate 1.15.12)"
accept_file "$FR_BADDATE" "{\"accepted\": [$(jq -c '.date = "2026-02-30"' <<< "$ACC_8R9P"), $ACC_Q3JH]}"
rev_check "$FR_BADDATE" "$FR_SEAM" ACCEPT-ENTRY-REFUSED 'no date' \
  && ! pop_has accepted GHSA-8r9p-f939-6h3c \
  && ok "an acceptance entry whose date is not a calendar day (2026-02-30) → REFUSED" \
  || fail "an acceptance entry dated 2026-02-30 must be refused (accepted='$(pop accepted)'): $REV_OUT"
FR_NOREASON="$(fr_repo fr-noreason 1.15.12)"
accept_file "$FR_NOREASON" "{\"accepted\": [$(jq -c '.reason = "  "' <<< "$ACC_8R9P"), $ACC_Q3JH]}"
rev_check "$FR_NOREASON" "$FR_SEAM" ACCEPT-ENTRY-REFUSED 'no reason' \
  && ! pop_has accepted GHSA-8r9p-f939-6h3c \
  && ok "an acceptance entry with a blank reason → REFUSED" \
  || fail "an acceptance entry with a blank reason must be refused (accepted='$(pop accepted)'): $REV_OUT"
FR_BADID="$(fr_repo fr-badid 1.15.12)"
accept_file "$FR_BADID" "{\"accepted\": [$(jq -c '.advisory = "ghsa-8r9p-f939-6h3c "' <<< "$ACC_8R9P"), $ACC_Q3JH]}"
rev_check "$FR_BADID" "$FR_SEAM" ACCEPT-ENTRY-REFUSED 'not a GHSA ID' \
  && ! pop_has accepted GHSA-8r9p-f939-6h3c \
  && ok "an acceptance entry whose advisory is not a GHSA ID → REFUSED, not a silent no-op" \
  || fail "an acceptance entry naming no GHSA ID must be refused (accepted='$(pop accepted)'): $REV_OUT"
FR_BADEXP="$(fr_repo fr-badexpiry 1.15.12)"
accept_file "$FR_BADEXP" "{\"accepted\": [$(jq -c '.expires = "next month"' <<< "$ACC_8R9P"), $ACC_Q3JH]}"
rev_check "$FR_BADEXP" "$FR_SEAM" ACCEPT-ENTRY-REFUSED 'expiry "next month" is not a YYYY-MM-DD calendar day' \
  && ! pop_has accepted GHSA-8r9p-f939-6h3c \
  && ok "an acceptance entry whose expiry is present and is not a calendar day → REFUSED, never read as no expiry" \
  || fail "an acceptance entry with an unreadable expiry must be refused (accepted='$(pop accepted)'): $REV_OUT"
# An EXPIRED entry stops accepting: its advisory reds again, and the entry is printed as expired.
FR_EXP="$(fr_repo fr-expired 1.15.12)"
accept_file "$FR_EXP" "{\"accepted\": [$(jq -c '.expires = "2000-01-01"' <<< "$ACC_8R9P"), $ACC_Q3JH]}"
if rev_check "$FR_EXP" "$FR_SEAM" SOURCE2-RANGE-UNPARSEABLE 'GHSA-8r9p-f939-6h3c "1.16.0"' \
   && ! pop_has accepted GHSA-8r9p-f939-6h3c && ! pop_has error_codes ACCEPT-ENTRY-REFUSED \
   && has "$REV_OUT" 'expired GHSA-8r9p-f939-6h3c'; then
  ok "an acceptance entry that expired (2000-01-01) no longer accepts: its advisory is RED again, and the entry is printed as expired"
else
  fail "an expired acceptance entry must stop accepting and be printed as expired (codes='$(pop error_codes)', accepted='$(pop accepted)'): $REV_OUT"
fi
FR_NOEXP="$(fr_repo fr-noexpiry 1.15.12)"
accept_file "$FR_NOEXP" "{\"accepted\": [$(jq -c 'del(.expires)' <<< "$ACC_8R9P"), $ACC_Q3JH]}"
rev_check "$FR_NOEXP" "$FR_SEAM" GREEN "" && pop_has accepted GHSA-8r9p-f939-6h3c \
  && has "$REV_OUT" 'no expiry' \
  && ok "an acceptance entry with no expiry accepts, and is printed as having none (an expiry is recommended, not required)" \
  || fail "an acceptance entry with no expiry should accept and be printed with 'no expiry' (accepted='$(pop accepted)'): $REV_OUT"
# An acceptance never clears a range that IS read: follow-redirects 1.15.0 is inside GHSA-r4q5-vmmm-2653's
# "<= 1.15.11", and accepting that advisory leaves the finding in place. The two bare-range
# advisories are accepted too, so the run's only red is the finding.
FR_READ="$(fr_repo fr-read 1.15.0)"
accept_file "$FR_READ" "{\"accepted\": [$(jq -c '.advisory = "GHSA-r4q5-vmmm-2653" | .versions = ["1.15.0"]' <<< "$ACC_8R9P"), $(jq -c '.versions = ["1.15.0"]' <<< "$ACC_8R9P"), $(jq -c '.versions = ["1.15.0"]' <<< "$ACC_Q3JH")]}"
out="$(run_audit "$FR_READ" "$FR_SEAM")"; rc=$?
if [ "$rc" -ne 0 ] && has "$out" 'SOURCE2 follow-redirects@1.15.0 GHSA-r4q5-vmmm-2653' \
   && ! pop_has accepted GHSA-r4q5-vmmm-2653 && [ "$(pop errors)" = "0" ]; then
  ok "an acceptance of an advisory whose range IS read and holds the version leaves it a FINDING (findings=$(pop findings)): the input accepts unread ranges only"
else
  fail "an acceptance must not clear a finding on a read range (rc=$rc, findings='$(pop findings)', accepted='$(pop accepted)'): $out"
fi
FR_JUNK="$(fr_repo fr-junk 1.15.12)"
accept_file "$FR_JUNK" 'accepted: GHSA-8r9p-f939-6h3c'
rev_check "$FR_JUNK" "$FR_SEAM" ACCEPT-FILE-UNPARSEABLE '' \
  && pop_has error_codes SOURCE2-RANGE-UNPARSEABLE \
  && ok "an acceptance file that is not JSON → RED (ACCEPT-FILE-UNPARSEABLE), and nothing in it is accepted" \
  || fail "an unparseable acceptance file must red and accept nothing (codes='$(pop error_codes)'): $REV_OUT"
# Nor does an acceptance clear any other error: an entry listing a version this audit cannot compare
# leaves that version's SOURCE2-VERSION-UNCOMPARABLE in place, and accepts nothing. This holds by the
# shape of a VERSION-UNPARSEABLE hit, not by the scope guard: that hit carries `ghsa: ""`, and no
# accepted entry can match an empty ID (an entry whose advisory is not a GHSA ID is refused). So
# removing the scope guard leaves this leg green — it does not pin the guard.
FR_UNCMP="$(fr_repo fr-uncomparable not-a-version)"
accept_file "$FR_UNCMP" "{\"accepted\": [$(jq -c '.versions = ["not-a-version"]' <<< "$ACC_8R9P"), $(jq -c '.versions = ["not-a-version"]' <<< "$ACC_Q3JH")]}"
rev_check "$FR_UNCMP" "$FR_SEAM" SOURCE2-VERSION-UNCOMPARABLE "" \
  && [ "$(pop accepted)" = "[]" ] && ! pop_has error_codes ACCEPT-ENTRY-REFUSED \
  && ok "an acceptance listing a version the audit cannot compare (not-a-version) leaves SOURCE2-VERSION-UNCOMPARABLE RED and accepts nothing: no other error is cleared" \
  || fail "an acceptance must not clear SOURCE2-VERSION-UNCOMPARABLE (codes='$(pop error_codes)', accepted='$(pop accepted)'): $REV_OUT"
FR_NOARR="$(fr_repo fr-noarray 1.15.12)"
accept_file "$FR_NOARR" '{"accepted": {}}'
rev_check "$FR_NOARR" "$FR_SEAM" ACCEPT-FILE-UNPARSEABLE 'is not a JSON object with an "accepted" array' \
  && pop_has error_codes SOURCE2-RANGE-UNPARSEABLE && [ "$(pop accepted)" = "[]" ] \
  && ok "an acceptance file that is valid JSON with no \"accepted\" array → RED (ACCEPT-FILE-UNPARSEABLE), and nothing in it is accepted" \
  || fail "an acceptance file whose \"accepted\" is not an array must red and accept nothing (codes='$(pop error_codes)', accepted='$(pop accepted)'): $REV_OUT"
FR_NOTOBJ="$(fr_repo fr-notobject 1.15.12)"
accept_file "$FR_NOTOBJ" "{\"accepted\": [\"x\", $ACC_8R9P, $ACC_Q3JH]}"
rev_check "$FR_NOTOBJ" "$FR_SEAM" ACCEPT-ENTRY-REFUSED 'entry 1 (?): not a JSON object' \
  && has "$REV_OUT" 'refused entry 1 (?): not a JSON object' \
  && pop_has accepted GHSA-8r9p-f939-6h3c && pop_has accepted GHSA-q3jh-wrc6-jgq8 \
  && ok "an acceptance entry that is not a JSON object (\"x\") → entry 1 REFUSED and printed as refused; the other entries still accept" \
  || fail "an entry that is not a JSON object must be refused as entry 1 while the others accept (codes='$(pop error_codes)', accepted='$(pop accepted)'): $REV_OUT"
# The expiry day itself still accepts: an entry expiring TODAY (UTC, the callee's own clock) is
# accepted, and the `<=` reading of the expiry check (§M) turns this leg RED.
FR_TODAY="$(fr_repo fr-expires-today 1.15.12)"
accept_file "$FR_TODAY" "{\"accepted\": [$(jq -c --arg d "$(date -u +%F)" '.expires = $d' <<< "$ACC_8R9P"), $ACC_Q3JH]}"
rev_check "$FR_TODAY" "$FR_SEAM" GREEN "" && pop_has accepted GHSA-8r9p-f939-6h3c \
  && has "$REV_OUT" "expires $(date -u +%F)" \
  && ok "an acceptance entry whose expiry is today ($(date -u +%F)) still accepts: the expiry day is inclusive" \
  || fail "an entry expiring today must still accept (accepted='$(pop accepted)'): $REV_OUT"
# An acceptance names the VERSIONS it was reviewed for. The entries above accept GHSA-8r9p-f939-6h3c
# and GHSA-q3jh-wrc6-jgq8 at 1.15.12 only, so 1.15.13 — another version no reading decides, which
# nobody reviewed — stays RED on both, with no entry refused.
FR_VULN="$(fr_repo fr-unlisted 1.15.13)"
accept_file "$FR_VULN" "{\"accepted\": [$ACC_8R9P, $ACC_Q3JH]}"
if rev_check "$FR_VULN" "$FR_SEAM" SOURCE2-RANGE-UNPARSEABLE 'GHSA-8r9p-f939-6h3c "1.16.0"' \
   && has "$REV_OUT" 'GHSA-q3jh-wrc6-jgq8 "1.16.0"' && [ "$(pop accepted)" = "[]" ] \
   && ! pop_has error_codes ACCEPT-ENTRY-REFUSED; then
  ok "follow-redirects 1.15.13 with both advisories accepted at 1.15.12 only → RED (SOURCE2-RANGE-UNPARSEABLE) on both: an acceptance covers only the versions it lists"
else
  fail "an acceptance listing 1.15.12 must not accept the advisories at 1.15.13 (codes='$(pop error_codes)', accepted='$(pop accepted)'): $REV_OUT"
fi
# An acceptance never clears a bare version's finding (card#11642 r1): at 1.16.0, the version both
# advisories name, every reading holds it (card#11604 rule 1), so entries listing 1.16.0 accept
# nothing and both advisories stay findings.
FR_BARE="$(fr_repo fr-bare-accepted 1.16.0)"
accept_file "$FR_BARE" "{\"accepted\": [$(jq -c '.versions = ["1.16.0"]' <<< "$ACC_8R9P"), $(jq -c '.versions = ["1.16.0"]' <<< "$ACC_Q3JH")]}"
out="$(run_audit "$FR_BARE" "$FR_SEAM")"; rc=$?
if [ "$rc" -ne 0 ] && has "$out" 'SOURCE2 follow-redirects@1.16.0 GHSA-8r9p-f939-6h3c' \
   && has "$out" 'SOURCE2 follow-redirects@1.16.0 GHSA-q3jh-wrc6-jgq8' \
   && [ "$(pop accepted)" = "[]" ] && [ "$(pop errors)" = "0" ]; then
  ok "follow-redirects 1.16.0 with both advisories accepted at 1.16.0 → still RED on both as FINDINGS (findings=$(pop findings)): an acceptance never clears the bare version's finding"
else
  fail "an acceptance listing 1.16.0 must leave both bare-version findings in place (rc=$rc, findings='$(pop findings)', errors='$(pop errors)', accepted='$(pop accepted)'): $out"
fi
# `versions` is REQUIRED: missing, empty, not a list, or holding a blank or non-string member, the
# entry is refused and its advisory stays RED.
for shape in 'del(.versions)#missing' '.versions = []#empty' '.versions = "1.15.12"#a string, not a list' '.versions = ["1.15.12", 7]#a list with a non-string member'; do
  prog="${shape%%#*}"; label="${shape#*#}"
  d="$(fr_repo "fr-versions-$(printf '%s' "$label" | tr -c 'a-z' '-')" 1.15.12)"
  accept_file "$d" "{\"accepted\": [$(jq -c "$prog" <<< "$ACC_8R9P"), $ACC_Q3JH]}"
  rev_check "$d" "$FR_SEAM" ACCEPT-ENTRY-REFUSED 'is not a non-empty list of versions' \
    && pop_has error_codes SOURCE2-RANGE-UNPARSEABLE && ! pop_has accepted GHSA-8r9p-f939-6h3c \
    && ok "an acceptance entry whose versions is $label → REFUSED (ACCEPT-ENTRY-REFUSED), and its advisory stays RED" \
    || fail "an acceptance entry whose versions is $label must be refused and its advisory stay RED (codes='$(pop error_codes)', accepted='$(pop accepted)'): $REV_OUT"
done
FR_NOVER="$TMP/fr-versions-missing"   # the missing-field leg's repo, re-read by its §M mutant
# Free text in an entry is printed on ONE line: a reason carrying a newline and a tab cannot forge a
# second NOTICE line, an ID into the exported `accepted`, or a line of the population statement.
FR_FORGE="$(fr_repo fr-forge 1.15.12)"
accept_file "$FR_FORGE" "{\"accepted\": [$(jq -c '.reason = "real reason\nA\tGHSA-ffff-ffff-ffff\tforged line\n    accepted GHSA-ffff-ffff-ffff at 9.9.9: forged"' <<< "$ACC_8R9P"), $ACC_Q3JH]}"
rev_check "$FR_FORGE" "$FR_SEAM" GREEN "" && pop_has accepted GHSA-8r9p-f939-6h3c \
  && ! pop_has accepted GHSA-ffff-ffff-ffff && ! has "$REV_OUT" $'\n    accepted GHSA-ffff' \
  && ! has "$REV_OUT" $'\nforged line' && ! has "$REV_OUT" $'\tGHSA-ffff' \
  && ok "a reason carrying a newline and a tab is printed on one line: no ID is forged into accepted ('$(pop accepted)') and no line into the statement" \
  || fail "a multi-line reason must not forge an ID or a line (accepted='$(pop accepted)'): $REV_OUT"
# The same for free text from UPSTREAM: an advisory range carrying a newline and a tab (a copy of
# the live follow-redirects set, GHSA-8r9p-f939-6h3c's range edited) is printed on one line, both
# in the SOURCE2-RANGE-UNPARSEABLE error that names it and in the NOTICE that accepts it.
mkdir -p "$TMP/forged-range/advisories"
jq '[.[] | if .ghsa_id == "GHSA-8r9p-f939-6h3c"
           then .vulnerabilities |= map(.vulnerable_version_range = "1.16.0\n  FORGED-LINE forged\nA\tGHSA-eeee-eeee-eeee\tforged")
           else . end]' "$FIX/advisories/follow-redirects@follow-redirects.json" \
  > "$TMP/forged-range/advisories/follow-redirects@follow-redirects.json"
cp "$FIX/advisories/thephpleague@commonmark.json" "$TMP/forged-range/advisories/"   # the control repo's read
FR_RANGE_SEAM="$(seam "$OSV_CLEAN" 'cat "'"$TMP"'/forged-range/advisories/$(printf %s "$1" | tr / @).json";' "$REG_FR")"
cp "$FR_RANGE_SEAM" "$TMP/fr-range-seam.sh"; FR_RANGE_SEAM="$TMP/fr-range-seam.sh"
FR_RANGE_RED="$(fr_repo fr-forged-range-red 1.15.12)"
rev_check "$FR_RANGE_RED" "$FR_RANGE_SEAM" SOURCE2-RANGE-UNPARSEABLE 'GHSA-8r9p-f939-6h3c "1.16.0   FORGED-LINE forged A GHSA-eeee-eeee-eeee forged"' \
  && ! has "$REV_OUT" $'\n  FORGED-LINE' && ! has "$REV_OUT" $'\nA\tGHSA-eeee' \
  && ok "an upstream range carrying a newline and a tab is named on ONE SOURCE2-RANGE-UNPARSEABLE line: no line is forged into the error output" \
  || fail "a multi-line upstream range must stay on its SOURCE2-RANGE-UNPARSEABLE line (codes='$(pop error_codes)'): $REV_OUT"
FR_RANGE_ACC="$(fr_repo fr-forged-range-accepted 1.15.12)"
accept_file "$FR_RANGE_ACC" "{\"accepted\": [$ACC_8R9P, $ACC_Q3JH]}"
rev_check "$FR_RANGE_ACC" "$FR_RANGE_SEAM" GREEN "" && pop_has accepted GHSA-8r9p-f939-6h3c \
  && ! pop_has accepted GHSA-eeee-eeee-eeee && ! has "$REV_OUT" $'\tGHSA-eeee' \
  && ok "an accepted advisory whose upstream range carries a newline and a tab: its NOTICE is one line and no ID is forged into accepted ('$(pop accepted)')" \
  || fail "a multi-line upstream range must not forge an ID through the NOTICE (accepted='$(pop accepted)'): $REV_OUT"

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

# The range-grammar guards, each removed on its own (the first mutant removes two, on purpose,
# and the two re-narrowing mutants replace belowtag's list rather than remove it).
# Each mutant must turn ONE named §V row into a named wrong verdict. That proves the row is held
# by that guard and not by the fixture. The first mutant IS the naive fix card#10762 warned about:
# the comma always means AND, and a conjunction is not required to be one interval. Under it the
# ws comma-OR row reads CLEAN on a vulnerable version, which is a fail-OPEN.
range_mutant() {  # $1=label $2=sed program $3=sites expected $4=row source (exact) $5=wrong verdict [$6=row runner, default range_rows]
  local n mut got rows="${6:-range_rows}"
  mut="$(printf '%s\n' "$LIB_BLOCK" | sed "$2")"
  n="$(diff <(printf '%s\n' "$LIB_BLOCK") <(printf '%s\n' "$mut") | grep -c '^>' || true)"
  if [ "$n" -ne "$3" ]; then
    fail "range mutant '$1' matched $n site(s), not $3 — it no longer isolates the guard it names"
  elif [ "$mut" = "$LIB_BLOCK" ]; then
    fail "range mutant '$1' changed nothing — that guard is uncontrolled"
  else
    got="$("$rows" "$(vmatch_prog "$mut")" | awk -F'#' -v s="$4" '$5 == s {print $1}')"
    if [ "$got" = "$5" ]; then
      ok "mutant control: $1 → '$4' reads $got — the guard binds the shipped grammar"
    else
      fail "range mutant '$1' should make '$4' read $5, got '${got:-no such row}'"
    fi
  fi
}
range_mutant "naive fix (comma always AND, no one-interval rule)" \
  's/| gsub("=>"; ">=")$/| gsub("=>"; ">=") | gsub(","; " ")/; s/if (\$l | length) > 1 or (\$u | length) > 1 then null/if false then null/' \
  2 "ws GHSA-96hv-2xvq-fx4p (comma = OR) THE FAIL-OPEN CONTROL" clean
range_mutant "one-interval rule removed" \
  's/if (\$l | length) > 1 or (\$u | length) > 1 then null/if false then null/' \
  1 "SYNTHETIC: GitHub docs example of the refused multi-range field (AND would read clean)" clean
range_mutant "empty-interval rule removed" \
  's/\$c > 0 or (\$c == 0 and (\$l\[0\]\.op == ">" or \$u\[0\]\.op == "<"))/false/' \
  1 "SYNTHETIC: an empty interval is a misread, never \"no version\"" clean
range_mutant "comma-OR branch removed" \
  's/elif (\$pieces | all(interval | bounded)) then/elif false then/' \
  1 "ws GHSA-96hv-2xvq-fx4p (comma = OR) THE FAIL-OPEN CONTROL" RANGE-UNPARSEABLE
range_mutant "unparseable-bound guard in terms removed" \
  's/^\( *\)| if any(\.b == null) then null else \. end$/\1/' \
  1 "SYNTHETIC: unparseable bound" clean
range_mutant "partial <= rewrite removed" \
  's/if (\.b\.c | length) < 3 and \.op == "<=" then/if false then/' \
  1 "SYNTHETIC: partial <= under space AND" clean
range_mutant "partial = rewrite removed" \
  's/elif (\.b\.c | length) < 3 and \.op == "=" then/elif false then/' \
  1 "SYNTHETIC: partial = reads as the whole minor (npm)" clean
range_mutant "pre-release tags ordered as equal (UNDECIDABLE removed from vcmp)" \
  's/(if \$a\.tag == \$b\.tag then 0 else null end)/0/' \
  1 "SYNTHETIC: two pre-release tags on one core cannot be ordered" clean
range_mutant "UNDECIDABLE for a tag off the belowtag list removed" \
  's/elif (if \$a\.pre then \$a else \$b end | belowtag | not) then null/elif false then null/' \
  1 "SYNTHETIC: a Composer patch tag sits ABOVE its core, an npm tag below" clean
# The next two re-narrow belowtag to the rule it replaced (every tag is below its core except a
# bare Composer patch tag). That rule read Composer's -stable and -pN-dev as below: a fail-OPEN.
range_mutant "belowtag re-narrowed to the old patch-tag rule (-stable)" \
  's/^\( *def belowtag: \.tag | test("\)[^"]*"/\1^(?!(?:p|pl|patch)(?:[.-]?[0-9]+)*$)"/' \
  1 "SYNTHETIC: Composer reads -stable as EQUAL to its core, npm below" clean
range_mutant "belowtag re-narrowed to the old patch-tag rule (-p1-dev)" \
  's/^\( *def belowtag: \.tag | test("\)[^"]*"/\1^(?!(?:p|pl|patch)(?:[.-]?[0-9]+)*$)"/' \
  1 "SYNTHETIC: Composer reads -p1-dev as ABOVE its core, npm below" clean
range_mutant "belowtag list emptied (every tag refused against its core)" \
  's/^\( *def belowtag:\).*/\1 false;/' \
  1 "SYNTHETIC: a tag both ecosystems order below its core still reads below it" RANGE-UNPARSEABLE
range_mutant "exact-equality branch of holds replaced by false" \
  's/if \.eq != null then satisfies(\$ver; "="; \.eq\.b)/if .eq != null then false/' \
  1 "SYNTHETIC: exact equality holds on its own version" clean
range_mutant "satisfies stops passing UNDECIDABLE through" \
  's/| if \$c == null then null$/| if false then null/' \
  1 "SYNTHETIC: one undecidable bound beside a true one is undecidable" clean
range_mutant "three-valued AND in holds replaced by plain all" \
  's/^\( *def all3:\).*/\1 all(. == true);/' \
  1 "SYNTHETIC: two pre-release tags on one core under space AND cannot be ordered" clean
range_mutant "three-valued OR in in_range replaced by plain any" \
  's/^\( *def any3:\).*/\1 any(. == true);/' \
  1 "SYNTHETIC: two pre-release tags on one core cannot be ordered" clean
range_mutant "undecidable-order interval rule removed" \
  's/and vcmp(\$l\[0\]\.b; \$u\[0\]\.b) == null$/and false/' \
  1 "SYNTHETIC: bounds whose pre-release tags cannot be ordered, so emptiness is undecidable, and a version below them is not decided" clean
range_mutant ">= admitting the pre-releases of its own core removed" \
  's/^ *or (\$ver\.pre and (\$bound\.pre | not) and vcmp(\$ver | \.pre = false; \$bound) == 0)$//' \
  1 "SYNTHETIC: >= admits the pre-releases of its own core (Composer reads >= X as >= X-dev)" clean
# The patched-version guards (card#11106), each removed on its own against a §P row. The first
# re-mints the defect itself: patched_versions computed and then ignored.
range_mutant "patched clearing removed (the card#11106 defect)" \
  's/^\( *\)| select(\$ps != "clean")$/\1| select(true)/' \
  1 "axios GHSA-542g-h47m-68v8: EQUAL to the patched version" AFFECTED patched_rows
range_mutant "own line read as the highest fix at or below the version" \
  's/| \[ \.\[\] | select(lead(\$ver; \.) == \$m) \] as \$c/| [ .[] | select(vcmp(.; $ver) <= 0) ] as $c/' \
  1 "SYNTHETIC: above an older line fix, below its own line fix" clean patched_rows
range_mutant "a tie between equally near fixes resolved to the lower" \
  's/elif \$k < 0 then \$p else \. end end);/elif $k > 0 then $p else . end end);/' \
  1 "SYNTHETIC: two fixes equally near, the higher decides" clean patched_rows
range_mutant "the fix compared with the range reading of >= (admits its pre-releases)" \
  's/else vcmp(\$ver; \$own) as \$k$/else (satisfies($ver; ">="; $own) | if . == null then null elif . then 0 else -1 end) as $k/' \
  1 "SYNTHETIC: a pre-release of the patched version is below it" clean patched_rows
# The wrong reading names the version itself as the fix, so starts_below has a fix to test; with
# no fix at all it would refuse every row and the mutant would prove nothing.
range_mutant "no patched version read as patched" \
  's/| if \$t == "" then {state: "none"}$/| if $t == "" then {state: "clean", fix: $ver}/' \
  1 "SYNTHETIC: no patched version, the range alone decides" clean patched_rows
range_mutant "an unread item dropped from the patched list instead of refusing it" \
  's/| if length == 0 or any(\. == null) then null else \. end;$/| map(select(. != null)) | if length == 0 then null else . end;/' \
  1 "SYNTHETIC: DOMPurify GHSA-v9jr-rg53-9pgp patched prose under an open range is not read" clean patched_rows
range_mutant "an undecidable comparison against the fix read as patched" \
  's/state: (if \$k == null then "undecidable" elif \$k >= 0 then "clean"/state: (if $k == null then "clean" elif $k >= 0 then "clean"/' \
  1 "SYNTHETIC: two tags on one core cannot be ordered, so the version is not cleared" clean patched_rows
# r1/r2 review (card#11106): the patched text is consulted only where the range leaves room for it, and
# the no-shared-lead reading clears only above every version the range names. Each mutant removes
# one guard, except the r1 example's: that row is held by THREE guards (upper bound, range names,
# where the holding interval begins), so its mutant removes all three.
range_mutant "a range that bounds the version cleared by the patched text" \
  's/elif (open_hit(\$ver; \$vr\.r) | not) then "bounded"$/elif false then "bounded"/' \
  1 "SYNTHETIC: one bounded interval holds and begins below the fix, so a fix on the own line below the version does not clear it" clean patched_rows
range_mutant "the interval tests taken over every interval, not those that may hold" \
  's/^\( *\)ranges(\$range) | map(select(holds(\$ver) != false));$/\1ranges($range);/' \
  1 "SYNTHETIC: only the open alternative holds, so the patched text decides" AFFECTED patched_rows
range_mutant "a fix at or below where the holding interval begins read as the fix of that line (starts_below emptied)" \
  's/^\( *\)\[ may_hold(\$ver; \$range)\[\] | \.lo\.b \] | all(room_below(\$ver; \$fix));$/\1[] | all(room_below($ver; $fix));/' \
  1 "SYNTHETIC (r2 review): the open interval holding the version begins above the only fix, so it is not the fix of that line" clean patched_rows
# That row is held by two guards in the entry match: "undecided" and, since the dead `$hit == true`
# test was dropped, "bounded" (a bounded interval refuses whatever `$hit` is). The mutant removes both.
range_mutant "a read range that cannot be compared at the version cleared by the patched text" \
  's/elif \$hit != true then "undecided"$/elif false then "undecided"/; s/elif (open_hit(\$ver; \$vr\.r) | not) then "bounded"$/elif false then "bounded"/' \
  2 "SYNTHETIC (r2 review): a range that is read but cannot be compared at this version is not cleared by the patched text" clean patched_rows
range_mutant "all three guards on the r1 example removed (upper bound, range names, where the interval begins)" \
  's/elif (open_hit(\$ver; \$vr\.r) | not) then "bounded"$/elif false then "bounded"/; s/^\( *\)elif (\$ps | map(lead(\$ver; \.)) | max) == 0 and (named_above(\$ver; \$range) | not)$/\1elif false/; s/elif (starts_below(\(.*\)) | not) then "startsabove"$/elif false then "startsabove"/' \
  3 "SYNTHETIC (r1 review): a readable range that bounds the version stays AFFECTED, whatever the patched text says" clean patched_rows
# Each boundary of room_below, the test of one lower bound, moved on its own.
range_mutant "a lower bound at the version read as below it" \
  's/and (\$f < 0 or \$v > 0);$/and ($f < 0 or $v >= 0);/' \
  1 "SYNTHETIC: the open interval holding the version begins at the version, above the own-line fix, so not cleared" clean patched_rows
range_mutant "a read lower bound equal to the fix read as ending below it" \
  's/and (\$f < 0 or \$v > 0);$/and ($f <= 0 or $v > 0);/' \
  1 "SYNTHETIC: a read lower bound equal to the fix begins an interval at the fix, so not cleared" clean patched_rows
# Keyed on a row whose holding interval begins below every fix: starts_below passes it, so only
# named_above refuses it.
range_mutant "no shared leading number read against the highest fix whatever the range names" \
  's/^\( *\)elif (\$ps | map(lead(\$ver; \.)) | max) == 0 and (named_above(\$ver; \$range) | not)$/\1elif false/' \
  1 "SYNTHETIC: no shared leading number, and the read range names versions above it, so not cleared" clean patched_rows
range_mutant "no shared leading number never cleared" \
  's/ == 0 and (named_above(\$ver; \$range) | not)$/ == 0/' \
  1 "SYNTHETIC: KNOWN LIMIT (2) ABOVE EVERY LINE, fails open: a later major above every listed fix and every version the range names" AFFECTED patched_rows
range_mutant "a version equal to one the range names read as above it" \
  's/named(\$range) | all(vcmp(\$ver; \.) == 1);$/named($range) | all(vcmp($ver; .) >= 0);/' \
  1 "SYNTHETIC: no shared leading number, at a version the read range names, so not cleared" clean patched_rows
# r4 review / pm ruling (card#11106): a range the parser does not read is never cleared by its
# patched text. The mutant re-mints the r3/r4 defect on the unread branch: the patched reading
# consulted there, with no range to hold it to.
range_mutant "an unread range cleared by its patched text (the r4 defect)" \
  's/^\( *\)| select(\$rv\.hit != false)$/\1| select($rv.hit != false and (patched_state($ver; $vr.p; $vr.r).state != "clean"))/' \
  1 "SYNTHETIC (r4 review): an unread range carrying a word is not cleared by its patched text, so its upper bound 2.5.0 is never overruled" clean patched_rows
# WHAT EVERY READING OF AN UNREAD RANGE AGREES ON (card#11604), each guard of `agreed` removed on
# its own against a row, and the reading card#11604 rules out (a bare version as exact) put back.
range_mutant "a bare version equal to the version no longer read as affected" \
  's/^\( *\)if bare(\$ver; \$range) then "AFFECTED"$/\1if false then "AFFECTED"/' \
  1 "follow-redirects GHSA-8r9p-f939-6h3c (card 11604): the version equal to the bare version" RANGE-UNPARSEABLE
range_mutant "a bare version read as exact (clean at any other version)" \
  's/^\( *\)if bare(\$ver; \$range) then "AFFECTED"$/\1if bare($ver; $range) then "AFFECTED" elif bare($range | vparse; $range) then "clean"/' \
  1 "axios GHSA-r4gj-5m52-g5wh (card 11604): a bare version is not read as exact, so 1.18.0, below the fix and not equal to it, is not decided; this table hands in no reviewed record (§G does)" clean patched_rows
range_mutant "the version cleared by the fix without being at or above it" \
  's/and (vcmp(\$ver; \$ps\[0\]) as \$k | \$k != null and \$k >= 0)$/and true/' \
  1 "@hono/node-server GHSA-rmxm-3fg6-px4f (card 11604): below the fix, so not decided; this table hands in no reviewed record (§G does)" clean patched_rows
range_mutant "a patched list of several versions read through its first" \
  's/| \$ps != null and (\$ps | length) == 1$/| $ps != null/' \
  1 "SYNTHETIC (card 11642 r1): laravel GHSA-78fx-h6xr-vch4 written with full versions, above both fixes; a patched list of several versions is not read here" clean patched_rows
range_mutant "text carrying a word cleared by its fix" \
  's/^\( *\)and (\$range | gsub(.*test(.*))$/\1and true/' \
  1 "SYNTHETIC (card 11604): a word can name a bound with no version, so text carrying one is not cleared by its fix" clean patched_rows
range_mutant "a range naming no version cleared by its fix" \
  's/^\( *\)and (\$ns | length) > 0$/\1and true/' \
  1 "SYNTHETIC (card 11604): a range naming no version is not cleared by its fix" clean patched_rows
range_mutant "a named version equal to the fix accepted whatever its operator" \
  's/^\( *\)and \$op == "<"$/\1and true/; s/^\( *\)and \$m\[2\] == null))))));$/\1and true))))));/' \
  2 "SYNTHETIC (card 11604): the fix named other than as an exclusive upper bound can begin an interval at it, so not cleared" clean patched_rows
range_mutant "a named version above the fix accepted" \
  's/and (\$c < 0$/and (true/' \
  1 "SYNTHETIC (r3 review): a single pipe, naming 2.0.0 above the fix — not read, so its patched text never clears it; this table hands in no reviewed record (§G does)" clean patched_rows
range_mutant "a named version that cannot be compared with the fix accepted" \
  's/^\( *\)| \$c != null$/\1| true/' \
  1 "SYNTHETIC (card 11604): a tagged version on a higher core is not decided (the rule reads only an untagged one), and the text names a version that cannot be compared with the fix" clean patched_rows
range_mutant "a named version with a tag off the belowtag list accepted (card 11604 r2)" \
  's/^\( *\)and ((\$n\.pre | not) or (\$n | belowtag))$/\1and true/' \
  1 "SYNTHETIC (card 11604 r2): a span written without spaces reads as one version whose tag is off the belowtag list, so not cleared" clean patched_rows
range_mutant "a named version carrying build metadata accepted (card 11604 r2)" \
  's/^\( *\)and (\$m\[1\] | test(.*) | not)$/\1and true/' \
  1 "SYNTHETIC (card 11604 r2): a trailing plus reads as build metadata, so not cleared" clean patched_rows
range_mutant "any operator carrying < accepted on the fix (card 11604 r2)" \
  's/^\( *\)and \$op == "<"$/\1and ($op | test("<"))/' \
  1 "SYNTHETIC (card 11604 r2): =< is inclusive, so the fix it names is not an exclusive bound" clean patched_rows
range_mutant "an = after the fix ignored (card 11604 r2)" \
  's/^\( *\)and \$m\[2\] == null))))));$/\1and true))))));/' \
  1 "SYNTHETIC (card 11604 r2): an = after the fix makes its < inclusive, so not an exclusive bound" clean patched_rows
range_mutant "a partial version compared at its own numbers, not its reach (card 11642 r1)" \
  's/((if (\$n\.c | length) < 3 and (\$op != "<" or \$m\[2\] != null) then (\$n | upto) else \$n end)$/(($n)/' \
  1 "SYNTHETIC (card 11642 r1): a partial upper bound reaches past its own numbers (<= 1 is read as < 2.0.0), so it names a version above the fix and is not cleared" clean patched_rows
range_mutant "a partial version written exactly < compared at its reach (card 11642 r1)" \
  's/ and (\$op != "<" or \$m\[2\] != null) then (\$n | upto)/ then ($n | upto)/' \
  1 "SYNTHETIC (card 11642 r1): a partial bound written exactly < reaches no further than its own numbers, so the fix it names is an exclusive upper bound" RANGE-UNPARSEABLE patched_rows
range_mutant "a span hidden in a tag accepted (card 11642 r1)" \
  's/^\( *\)and (\$n\.tag | split("-")\[1:\] | any(test("^\[0-9\]")) | not)$/\1and true/' \
  1 "SYNTHETIC (card 11642 r1): a tag with a hyphen-joined numeric segment is a span hidden in a tag (>=1.0.0-beta.1 <=3), so not cleared" clean patched_rows
range_mutant "=< canonicalised to an exclusive < (card 11642 r2)" \
  's/gsub("=<"; "<=")/gsub("=<"; "<")/' \
  1 "SYNTHETIC (card 11642 r3): an =< that no version precedes is spelled <= before the scan, so the fix it names is not an exclusive bound" clean patched_rows
range_mutant "whitespace inside an operator kept (card 11642 r2)" \
  's/gsub("(?<=\[<>=\])\\\\s+(?=\[<>=\])"; "") | //' \
  1 "@hono/node-server GHSA-rmxm-3fg6-px4f (card 11604): at its one fix, which the range names only as an exclusive upper bound" RANGE-UNPARSEABLE patched_rows
range_mutant "a trailing = ignored by the partial reach (card 11642 r2)" \
  's/ and (\$op != "<" or \$m\[2\] != null) then/ and $op != "<" then/' \
  1 "SYNTHETIC (card 11642 r2): an = after a partial < makes it inclusive, so it is compared at its reach (1.6.0), above the fix" clean patched_rows
range_mutant "an operator off the five spellings accepted (card 11642 r2)" \
  's/| (\$ops | all(IN(">=", "<=", ">", "<", "=")))$/| true/' \
  1 "SYNTHETIC (card 11642 r2): an operator other than >= <= > < = (here <>, which can mean \"not 1.0\") is not read, so not cleared" clean patched_rows
range_mutant "an operator attached to no version ignored (card 11642 r2)" \
  's/^\( *\)and (\$ops | length) == (.*$/\1and true/' \
  1 "SYNTHETIC (card 11642 r2): an operator attached to no version is not read, so not cleared" clean patched_rows
# card#11642 r3: an = after a version with an operator after it (eq_after_version), removed
# where it is defined (every reader then picks a reading), at each of its two callers on its own,
# and widened to refuse an =< / => that no version precedes.
range_mutant "an = after a version read as part of the next operator (eq_after_version removed, card 11642 r3)" \
  's/^\( *\)test("\[^\\\\s<>=,;|&\]\\\\s\*=\\\\s\*\[<>=\]");$/\1false;/' \
  1 "SYNTHETIC (card 11642 r3): an = between a version and a following < can make the < before it inclusive (<= 1.5.3), so not cleared at the fix" clean patched_rows
range_mutant "the fix check reads an = after a version as part of the next operator (card 11642 r3)" \
  's/^\( *\)and (\$range | eq_after_version | not)$/\1and true/' \
  1 "SYNTHETIC (card 11642 r3): an = between a partial version and a following < can make it <= 1.5, which reaches 1.6.0, so not cleared" clean patched_rows
range_mutant "the range grammar reads an => after a version as >= (card 11642 r3)" \
  's/^\( *\)if (\$range | eq_after_version) then null$/\1if false then null/' \
  1 "SYNTHETIC (card 11642 r3): the range grammar does not read => as >= where an = after a version can make the < before it inclusive (<= 1.5.0), so the range is not read and not cleared at its fix" clean patched_rows
range_mutant "an => that no version precedes refused (card 11642 r3)" \
  's/^\( *\)test("\[^\\\\s<>=,;|&\]\\\\s\*=\\\\s\*\[<>=\]");$/\1test("=\\\\s*[<>]");/' \
  1 "SYNTHETIC (card 11642 r3): an => that no version precedes has one reading (>=), so the range is read and the fix, its exclusive upper bound, is outside it" RANGE-UNPARSEABLE patched_rows
range_mutant "an unordered pair never cleared (the higher-core reading removed)" \
  's/^\( *\)elif (ranges_of(\$range; true) | .*$/\1elif false then "clean"/' \
  1 "express GHSA-rv95-896h-c2vc (card 11604): an untagged version on a higher core than both bounds, whose tags cannot be ordered" RANGE-UNPARSEABLE patched_rows
range_mutant "an unordered pair read as not holding a tagged version on a higher core" \
  's/(if (\$ver\.pre | not) and vcmp(/(if vcmp(/' \
  1 "SYNTHETIC (card 11604): a tagged version on a higher core is not decided (the rule reads only an untagged one), and the text names a version that cannot be compared with the fix" clean patched_rows
range_mutant "an unordered pair read as not holding any untagged version" \
  's/ and vcmp(\$ver; \.hi\.b | core) == 1 then false/ then false/' \
  1 "SYNTHETIC: bounds whose pre-release tags cannot be ordered, so emptiness is undecidable, and a version below them is not decided" clean
# THE REVIEWED GLOBAL RECORD (card#11106, pm ruling 2026-10-06), each guard removed on its own
# against a §G row. A failed lookup and a record that was never looked up have their own branch
# only for the reason they print: with either branch removed, the record-identity guard below
# still refuses the entry (it carries no matching ghsa_id), so no row can flip on them, and the
# reason legs after the §G table assert the printed reason instead.
range_mutant "an entry the reviewed record leaves unread read as clean (the fail-closed select)" \
  's/^\( *\)| select(\$rv\.hit != false)$/\1| select($rv.hit == true)/' \
  1 "SYNTHETIC: a lookup that failed stays unread" clean reviewed_rows
range_mutant "a global record that is not reviewed used" \
  's/elif \$rec\.type != "reviewed" then/elif false then/' \
  1 "SYNTHETIC: a global record that is not reviewed is not used" clean reviewed_rows
range_mutant "a withdrawn reviewed record used" \
  's/elif \$rec\.withdrawn_at != null then/elif false then/' \
  1 "SYNTHETIC: a withdrawn reviewed record is not used to decide the range; the entry is a notice" clean reviewed_rows
range_mutant "a record naming another advisory used" \
  's/elif \$rec\.ghsa_id != \$id then/elif false then/' \
  1 "SYNTHETIC: a record naming another advisory is not used" clean reviewed_rows
range_mutant "a reviewed record with no range for the package read as clean" \
  's/| if (\$h | length) == 0 then {hit: null/| if false then {hit: null/' \
  1 "SYNTHETIC: a reviewed record with no range for this package is not used" clean reviewed_rows
range_mutant "a reviewed range that is not read counted as not holding" \
  's/(\$h | map(if \. == "RANGE-UNPARSEABLE" then null else \. end) | any3)/($h | map(. == true) | any3)/' \
  1 "SYNTHETIC: a reviewed record with one range not read and none holding stays unread" clean reviewed_rows
range_mutant "the ranges of every package in the reviewed record read for this one" \
  's/(\$rec\.vulnerabilities \/\/ \[\])\[\] | is_pkg | in_range/($rec.vulnerabilities \/\/ [])[] | in_range/' \
  1 "SYNTHETIC: a range of another package in the record does not hold for this one" AFFECTED reviewed_rows

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

# The reviewed-record lookup in the caller (card#11106), each guard removed on its own: the
# end-to-end leg it holds must change verdict. block_mutant is called bare, never inside $( ),
# so its `fail` counts.
block_mutant() {  # $1=label $2=sed program $3=sites expected -> MUT (the mutated blocks); 0 when it isolates its guard and parses
  local n
  MUT="$(printf '%s\n' "$BLOCKS" | sed "$2")"
  n="$(diff <(printf '%s\n' "$BLOCKS") <(printf '%s\n' "$MUT") | grep -c '^>' || true)"
  if [ "$n" -ne "$3" ]; then
    fail "block mutant '$1' matched $n site(s), not $3 — it no longer isolates the guard it names"; return 1
  fi
  if ! bash -n <(printf 'set -euo pipefail\n%s\n' "$MUT") 2>/dev/null; then
    fail "block mutant '$1' does not parse — its verdict would prove nothing"; return 1
  fi
}
if block_mutant "the second match, with the reviewed records handed in, dropped" \
     's/ --slurpfile reviewed "\$WORK\/reviewed\.json"//' 1; then
  rev_check "$LARAVEL_AT_FIX" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE")" GREEN "" "$MUT" \
    && fail "with the reviewed records never handed to the matcher, laravel v13.30.1 still went GREEN — that leg is not held by the lookup: $REV_OUT" \
    || ok "mutant control: with the reviewed records never handed to the matcher, laravel v13.30.1 goes RED — its GREEN is the lookup's"
fi
if block_mutant "an unread range never flagged for a lookup" \
     's/lookup: (\$id != "" and (\$reviewed | has(\$id) | not))}/lookup: false}/' 1; then
  rev_check "$LARAVEL_AT_FIX" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE")" GREEN "" "$MUT" \
    && fail "with no unread range flagged for a lookup, laravel v13.30.1 still went GREEN: $REV_OUT" \
    || ok "mutant control: with no unread range flagged for a lookup, laravel v13.30.1 goes RED (unread, as before the reviewed record)"
fi
if block_mutant "an ID that is not GHSA-shaped sent to the seam" \
     's/if ! \[\[ \$id =~ ^GHSA-\[a-z0-9\]{4}-\[a-z0-9\]{4}-\[a-z0-9\]{4}\$ \]\]; then/if false; then/' 1; then
  rev_check "$LARAVEL_AT_FIX" "$(seam "$OSV_CLEAN" "$ADV_BADGHSA" "$REG_NONE" "$REV_LOGGED")" SOURCE2-RANGE-UNPARSEABLE "" "$MUT"
  grep -q 'GHSA-78fx-h6xr-vch4?' "$TMP/rev_calls" \
    && ok "mutant control: with the GHSA-shape check removed, the seam IS called with 'GHSA-78fx-h6xr-vch4?per_page=1' — the leg above sees the call it forbids" \
    || fail "with the GHSA-shape check removed the seam should have been called with the bad ID (calls: '$(tr '\n' ' ' < "$TMP/rev_calls")')"
fi
if block_mutant "the exit status of the lookup ignored" \
     's/if ! reviewed_advisory_fetch "\$id" > "\$rec\.part" 2> "\$WORK\/rev\.err" < \/dev\/null; then/if ! { reviewed_advisory_fetch "$id" > "$rec.part" 2> "$WORK\/rev.err" < \/dev\/null || true; }; then/' 1; then
  rev_check "$LARAVEL_AT_FIX" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE" "$REV_FAIL_WITH_RECORD")" GREEN "" "$MUT" \
    && ok "mutant control: with the exit status ignored, a lookup that printed a record and exited non-zero goes GREEN — the leg above is held by the status check" \
    || fail "with the exit status ignored the record-then-fail lookup should have gone GREEN: $REV_OUT"
fi
if block_mutant "a lookup payload that is not a JSON object passed to the matcher" \
     's/elif ! jq -e .type == "object". "\$rec\.part" >\/dev\/null 2>&1; then/elif false; then/' 1; then
  rev_check "$LARAVEL_AT_FIX" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE" "$REV_NOT_OBJECT")" SOURCE2-RANGE-UNPARSEABLE 'a payload that is not a JSON object' "$MUT" \
    && fail "with the object check removed, the non-object payload leg still passed: $REV_OUT" \
    || ok "mutant control: with the object check removed, a non-object payload is no longer named as one (codes: $(pop error_codes))"
fi
if block_mutant "the lookup seam handed the list of IDs still to look up on stdin" \
     's/reviewed_advisory_fetch "\$id" > "\$rec\.part" 2> "\$WORK\/rev\.err" < \/dev\/null;/reviewed_advisory_fetch "$id" > "$rec.part" 2> "$WORK\/rev.err";/' 1; then
  rev_check "$LARAVEL_AT_FIX" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE" "$REV_SLURP")" GREEN "" "$MUT" \
    && fail "with the seam's stdin not redirected, a stdin-reading seam still left every lookup in place: $REV_OUT" \
    || ok "mutant control: with the seam's stdin not redirected, a stdin-reading seam eats the IDs still to look up and the run reds"
fi

# THE ACCEPTANCE INPUT (card#11434), each guard removed or weakened on its own: the §A leg it holds
# must change verdict. The reviewer, date and expiry guards each turn their leg GREEN; the version
# guard turns the 1.15.13 leg GREEN; the versions refusal and the inclusive expiry day each turn their
# leg's verdict away from the one it asserts; the scope guard, which lets an acceptance touch only a
# RANGE-UNPARSEABLE entry, turns a FINDING into an acceptance.
if block_mutant "an acceptance entry with no reviewer accepted" \
     's/then "no reviewer" else empty end/then empty else empty end/' 1; then
  rev_check "$FR_NOREV" "$FR_SEAM" GREEN "" "$MUT" \
    && ok "mutant control: with the reviewer check removed, the entry with no reviewer accepts and the run goes GREEN — the refusal leg is held by that check" \
    || fail "with the reviewer check removed the no-reviewer leg should have gone GREEN: $REV_OUT"
fi
if block_mutant "an acceptance entry with no date accepted" \
     's/(if (\$e\.date | day) then empty/(if true then empty/' 1; then
  rev_check "$FR_NODATE" "$FR_SEAM" GREEN "" "$MUT" \
    && ok "mutant control: with the date check removed, the entry with no date accepts and the run goes GREEN — the refusal leg is held by that check" \
    || fail "with the date check removed the no-date leg should have gone GREEN: $REV_OUT"
fi
if block_mutant "an expired acceptance entry still accepting" \
     's/elif \$e\.expires != null and \$e\.expires < \$today then "expired"/elif false then "expired"/' 1; then
  rev_check "$FR_EXP" "$FR_SEAM" GREEN "" "$MUT" \
    && ok "mutant control: with the expiry check removed, the expired entry accepts and the run goes GREEN — the expiry leg is held by that check" \
    || fail "with the expiry check removed the expired leg should have gone GREEN: $REV_OUT"
fi
if block_mutant "an acceptance applied to a finding" \
     's/| if \.verdict == "RANGE-UNPARSEABLE" and (\$m | length) > 0/| if (.verdict == "RANGE-UNPARSEABLE" or .verdict == "AFFECTED") and ($m | length) > 0/' 1; then
  out="$(run_audit "$FR_READ" "$FR_SEAM" "" "$MUT")"
  if ! has "$out" 'SOURCE2 follow-redirects@1.15.0 GHSA-r4q5-vmmm-2653' && pop_has accepted GHSA-r4q5-vmmm-2653; then
    ok "mutant control: with the acceptance scope widened to findings, GHSA-r4q5-vmmm-2653 at 1.15.0 is accepted instead of found — the read-range leg is held by the scope guard"
  else
    fail "with the acceptance scope widened, the finding on GHSA-r4q5-vmmm-2653 should have become an acceptance (accepted='$(pop accepted)'): $out"
  fi
fi
if block_mutant "an acceptance applied to a finding (the bare-version leg)" \
     's/| if \.verdict == "RANGE-UNPARSEABLE" and (\$m | length) > 0/| if (.verdict == "RANGE-UNPARSEABLE" or .verdict == "AFFECTED") and ($m | length) > 0/' 1; then
  out="$(run_audit "$FR_BARE" "$FR_SEAM" "" "$MUT")"
  if ! has "$out" 'SOURCE2 follow-redirects@1.16.0 GHSA-8r9p-f939-6h3c' && pop_has accepted GHSA-8r9p-f939-6h3c; then
    ok "mutant control: with the acceptance scope widened to findings, the bare-version finding at 1.16.0 is accepted instead of found — the bare-version leg is held by the scope guard"
  else
    fail "with the acceptance scope widened, the bare-version finding at 1.16.0 should have become an acceptance (accepted='$(pop accepted)'): $out"
  fi
fi
if block_mutant "an acceptance applied at a version it does not list" \
     's/ | select(any(\.versions\[\]; \. == \$version))\]/]/' 1; then
  rev_check "$FR_VULN" "$FR_SEAM" GREEN "" "$MUT" \
    && pop_has accepted GHSA-8r9p-f939-6h3c \
    && ok "mutant control: with the version check removed, entries listing 1.15.12 accept the advisories at the unlisted 1.15.13 and the run goes GREEN — the out-of-list leg is held by that check" \
    || fail "with the version check removed the 1.15.13 leg should have gone GREEN (accepted='$(pop accepted)'): $REV_OUT"
fi
if block_mutant "an acceptance entry with no versions not refused" \
     's/then empty else "versions \\(\$e\.versions | tojson) is not a non-empty list of versions" end),/then empty else empty end),/' 1; then
  rev_check "$FR_NOVER" "$FR_SEAM" ACCEPT-ENTRY-REFUSED 'is not a non-empty list of versions' "$MUT" \
    && fail "with the versions refusal removed, the entry with no versions was still refused: $REV_OUT" \
    || ok "mutant control: with the versions refusal removed, the entry with no versions is no longer refused (codes: $(pop error_codes)) — the missing-field leg is held by that check"
fi
if block_mutant "an acceptance entry refused on its expiry day" \
     's/elif \$e\.expires != null and \$e\.expires < \$today then "expired"/elif $e.expires != null and $e.expires <= $today then "expired"/' 1; then
  rev_check "$FR_TODAY" "$FR_SEAM" GREEN "" "$MUT" \
    && fail "with the expiry read as exclusive (<=), the entry expiring today still accepted — the expiry-day leg is not held by the < comparison: $REV_OUT" \
    || ok "mutant control: with the expiry read as exclusive (<=), the entry expiring today stops accepting and the run reds — the expiry-day leg is held by the < comparison"
fi
# ONE LINE PER RECORD (card#11434 r1): `oneline` removed from each site on its own, the forging leg
# that site's output feeds must red.
if block_mutant "the notice records not made one line" \
     's/| map(oneline) | join("\\t")/| join("\\t")/' 1; then
  rev_check "$FR_FORGE" "$FR_SEAM" GREEN "" "$MUT"
  pop_has accepted GHSA-ffff-ffff-ffff \
    && ok "mutant control: with the notice records not made one line, the multi-line reason forges GHSA-ffff-ffff-ffff into accepted ('$(pop accepted)')" \
    || fail "with the notice records not made one line the reason should have forged an ID into accepted (accepted='$(pop accepted)'): $REV_OUT"
fi
if block_mutant "the acceptance lines of the population statement not made one line" \
     's/ else "    refused entry \\(\.n) (\\(\.advisory)): \\(\.why | join("; "))" end) | oneline/ else "    refused entry \\(.n) (\\(.advisory)): \\(.why | join("; "))" end)/' 1; then
  rev_check "$FR_FORGE" "$FR_SEAM" GREEN "" "$MUT"
  has "$REV_OUT" $'\n    accepted GHSA-ffff' \
    && ok "mutant control: with the statement's acceptance lines not made one line, the multi-line reason forges an 'accepted GHSA-ffff-ffff-ffff' line into the statement" \
    || fail "with the statement's acceptance lines not made one line the reason should have forged a statement line: $REV_OUT"
fi
if block_mutant "the SOURCE2-RANGE-UNPARSEABLE detail not made one line" \
     's/ else "" end)"\] | join("; ") | oneline/ else "" end)"] | join("; ")/' 1; then
  rev_check "$FR_RANGE_RED" "$FR_RANGE_SEAM" SOURCE2-RANGE-UNPARSEABLE "" "$MUT"
  has "$REV_OUT" $'\n  FORGED-LINE' \
    && ok "mutant control: with the SOURCE2-RANGE-UNPARSEABLE detail not made one line, the multi-line upstream range forges a '  FORGED-LINE' line into the error output" \
    || fail "with the SOURCE2-RANGE-UNPARSEABLE detail not made one line the range should have forged a line: $REV_OUT"
fi

# ══ §R THE REPORTING JOB — a green over nothing must be unreachable from the caller ══════
run_report() {  # $1=needs.audit.result  $2=population
  AUDIT_RESULT="$1" AUDIT_POPULATION="$2" bash -c "set -euo pipefail
$REPORT_BLOCK" 2>&1
}
GOOD='{"ref":"main","status":"audited","manifests":2,"packages":106,"resolved":106,"unresolved":0,"advisory_repos":98,"findings":0,"errors":0}'
out="$(run_report success "$GOOD")"; rc=$?
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
n_inc="$(grep -c -- 'AUDIT_ERRORS=\$((AUDIT_ERRORS + 1))' <<< "$BLOCKS")"
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

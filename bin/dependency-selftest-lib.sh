# ── VENDORED from coord 0.79.0 — the test-only library the dependency-audit and advisory-notices selftests source. A body edit reds bin/vendor-pin-check.sh until declared below. ──
#
#   upstream repo     PupFuzz/agent-board-framework  (PRIVATE — a public CI runner cannot clone it)
#   upstream path     plugins/coord/templates/security/dependency-selftest-lib.sh
#   vendored from     31191341a2176a81f5e15b6d72e80282a2df168c   (marketplace, the v0.79.0 release merge)
#   plugin version    coord 0.79.0
#   upstream sha256   db63cb50abd5dea8621a3095f8106bbdf214158d0c8a0e104a7117f5e2dcce53
#
# That sha256 is the WHOLE upstream file; `bin/vendor-pin-check.sh` pins the BODY (from the first
# line that is neither blank nor a comment), which is a different figure. Re-derive this one
# against a marketplace clone:   git show 31191341:plugins/coord/templates/security/dependency-selftest-lib.sh | sha256sum
#
# DECLARED LOCAL EDITS — each is also a FRAGMENTS row in `bin/vendor-pin-check.sh`, which reds
# when one leaves the body. Outside these edits, the text below this block was upstream's byte for
# byte when vendored (checked by diff against the commit above); the body pin holds it there.
#   (1) the fixtures path is this repo's `bin/dependency-audit-fixtures/`, beside the selftest,
#       rather than upstream's `fixtures/` (fragment: `HERE/dependency-audit-fixtures"`).
#   This repository carries every suite upstream's header below names, beside this file
#   in `bin/`.
#   ShellCheck's findings on this file are recorded in bin/shell-lint.baseline.tsv rather than
#   annotated in the body, so the body stays upstream's.
#
# TO RE-VENDOR: this file moves with `bin/dependency-audit.selftest.sh`, whose header names the
# whole set and the procedure.
# ── END MEZZANINE VENDOR HEADER ────────────────────────────────────────────────────────────────
# dependency-selftest-lib.sh — TEST-ONLY helpers sourced by `dependency-audit.selftest.sh`,
# `dependency-advisory-notices.selftest.sh` and `dependency-advisory-notices-shape.selftest.sh`, which
# sit beside it here and in an adopter's repo.
# It defines the assertions, the extraction of the shipped YAML's marked blocks, and the harness
# that runs the callee's blocks over a fixture tree with fixture seams. It runs no leg of its own.
#
# The sourcing suite sets SUITE (the name its verdict line carries) and HERE (its own directory)
# first. Sourcing it makes TMP, removed on exit.
#
# WHERE THE WORKFLOWS ARE IS AN INPUT. In the plugin they sit in `workflows/` beside this file, and
# that is the default. A repo that copies the suites keeps them in `.github/workflows`, so it passes
# `--workflows <dir>` to each suite, or sets DEPENDENCY_AUDIT_WORKFLOWS=<dir>; the argument wins, and
# a variable set to nothing names no directory. `need_workflows` fails a suite whose file is not there.
if [ "${1-}" = --workflows ]; then
  WORKFLOWS="${2-}"; WORKFLOWS_FROM="--workflows"
elif [ -n "${DEPENDENCY_AUDIT_WORKFLOWS+set}" ]; then
  WORKFLOWS="$DEPENDENCY_AUDIT_WORKFLOWS"; WORKFLOWS_FROM="DEPENDENCY_AUDIT_WORKFLOWS"
else
  WORKFLOWS="$HERE/workflows"; WORKFLOWS_FROM="the default, the plugin's own layout"
fi
CALLEE="$WORKFLOWS/dependency-audit.yml"
CALLER="$WORKFLOWS/dependency-ref-matrix.yml"
NOTICES="$WORKFLOWS/dependency-advisory-notices.yml"
FIX="$HERE/dependency-audit-fixtures"
FAILURES=0
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

# MEMBERSHIP IS A `case` — NOT A PIPE INTO `grep -q`, AND NOT A HERESTRING EITHER (card#8080).
# `grep -q` exits on its FIRST match and closes the pipe, so the writer takes SIGPIPE and dies
# 141; under `pipefail` the PIPELINE is 141, and a TRUE membership comes back FALSE. Every
# assertion below is a membership question about `$out`, an audit report that grows with the
# population it describes, so the size gate is a matter of fixtures rather than of principle.
# The herestring these lines USED to be (`grep -q PAT <<< "$out"`) removes the SIGPIPE and buys
# the worse defect: a HUNG test instead of a silent wrong answer on the fleet's Windows seat
# (card#12619 — `_ci_py` in ci-verdict.sh owns the measurement).
# `has` and `has_line` are byte-identical to `plugins/coord/hooks/bin/_selftest_match.sh`, which
# owns the argument and the measurement; IN-FILE rather than sourced because this file SHIPS into
# an adopter's repo, where that path is not one it can name. Two FRAMEWORK-REPO guards keep them
# honest — neither ships, so nothing in your install runs either and nothing here depends on them
# being present: `.githooks/pipe-grep-membership.selftest.py` reds if any membership test goes
# back to the pipe (or to the refused herestring), and
# `.githooks/membership-primitive-drift.selftest.py` reds if either copy stops being
# byte-identical to the authority.
has() { case "$1" in *"$2"*) return 0 ;; *) return 1 ;; esac; }
# has_line <haystack> <needle> — TRUE when one whole line of the haystack is the needle.
has_line() { [ -n "$2" ] || return 1; case $'\n'"$1"$'\n' in *$'\n'"$2"$'\n'*) return 0 ;; *) return 1 ;; esac; }

fail() { echo "  FAIL: $1"; FAILURES=$((FAILURES + 1)); }
ok()   { echo "  ok:   $1"; }
finish() {
  if [ "$FAILURES" -gt 0 ]; then echo "$SUITE selftest: FAIL ($FAILURES)"; exit 1; fi
  echo "$SUITE selftest: all checks pass"; exit 0
}

for tool in jq sed awk find diff; do
  # A missing prerequisite is NAMED, never skipped: a silent skip reports a green suite that
  # ran none of these legs. jq is already a CI prerequisite for this repo's other guards.
  command -v "$tool" >/dev/null 2>&1 || { fail "PREREQUISITE MISSING: $tool is not on PATH; this suite does not skip legs"; finish; }
done

need_workflows() {  # each workflow file the suite reads; ends the suite at one it cannot read
  local f
  for f in "$@"; do
    [ -r "$f" ] || { fail "cannot read $f (from $WORKFLOWS_FROM): pass the directory your workflows are in as --workflows <dir>, or set DEPENDENCY_AUDIT_WORKFLOWS=<dir>"; finish; }
  done
}

# check_workflows_input <basename>: the sourcing suite, run again over a directory that holds every
# other workflow and not <basename>, ends red before any leg, naming the file and the input that
# chose it.
check_workflows_input() {
  local d="$TMP/without-$1" f from out rc
  [ -z "${DEPENDENCY_SELFTEST_NESTED-}" ] || return 0
  mkdir "$d"
  for f in "$CALLEE" "$CALLER" "$NOTICES"; do
    # A suite runs without a workflow it does not read, so one may not be there to copy.
    [ "${f##*/}" = "$1" ] || [ ! -r "$f" ] || cp "$f" "$d/"
  done
  for from in --workflows DEPENDENCY_AUDIT_WORKFLOWS; do
    if [ "$from" = --workflows ]; then
      out="$(DEPENDENCY_SELFTEST_NESTED=1 DEPENDENCY_AUDIT_WORKFLOWS="$TMP" bash "$0" --workflows "$d" 2>&1)"; rc=$?
    else
      out="$(DEPENDENCY_SELFTEST_NESTED=1 DEPENDENCY_AUDIT_WORKFLOWS="$d" bash "$0" 2>&1)"; rc=$?
    fi
    [ "$rc" -eq 1 ] && has "$out" "cannot read $d/$1 (from $from)" && ! has "$out" '  ok:' \
      && ok "a workflows directory without $1, named by $from, fails this suite before any leg" \
      || fail "a workflows directory without $1, named by $from, did not fail this suite before any leg (rc=$rc): $(head -c 400 <<< "$out")"
  done
}

extract() {  # $1=file $2=block-name -> the block body, de-indented by 10
  sed -n "/--- $2\$/,/--- end $2\$/p" "$1" \
    | grep -v -- "--- $2\$" \
    | grep -v -- "--- end $2\$" \
    | sed 's/^          //'
}

# extract_parsed <file> <block-name> <what> -> BODY; ends the suite when the block is empty or does
# not parse, since every leg that runs it would then test nothing.
extract_parsed() {
  BODY="$(extract "$1" "$2")"
  [ -n "$BODY" ] || { fail "extraction of '$2' returned EMPTY — the $3's markers moved; this selftest is testing nothing"; finish; }
  bash -n <(printf 'set -euo pipefail\n%s\n' "$BODY") 2>/dev/null \
    || { fail "the extracted '$2' block of the $3 does not PARSE — every leg that runs it would red on a syntax error"; finish; }
}

# The callee's blocks, in the order its step runs them.
BLOCK_NAMES="dep-audit-lib dep-audit-accept dep-audit-discovery dep-audit-source1 dep-audit-source2
            dep-audit-report dep-audit-main"
BLOCKS=""
need_workflows "$CALLEE"
for name in $BLOCK_NAMES; do
  extract_parsed "$CALLEE" "$name" callee
  BLOCKS="$BLOCKS
$BODY"
done

# ── the harness: a fixture tree + fixture seams + the extracted blocks ────────────────────
: > "$TMP/swallowed"

run_audit() {
  # $1 = repo dir, $2 = seam-prelude file, $3 = control repo ("" = the fixture default),
  # $4 = block text ("" = the shipped blocks)
  local repo="$1" prelude="$2" ctrl="${3:-thephpleague/commonmark}" blocks="${4:-$BLOCKS}"
  local rc=0
  : > "$TMP/gh_output" ; : > "$TMP/step_summary"; rm -f "$TMP/findings.json"
  (
    cd "$repo" || exit 97
    GITHUB_OUTPUT="$TMP/gh_output" GITHUB_STEP_SUMMARY="$TMP/step_summary" FINDINGS_FILE="$TMP/findings.json" \
    AUDIT_REF="${AUDIT_REF_UNDER_TEST:-fixture-ref}" \
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
ADV_FIXTURE='cat "$FIX/advisories/$(printf %s "$1" | tr / @).json";'
REG_NONE='return 1;'
# The reviewed global records (fixtures/reviewed/<GHSA>.json). A GHSA with no fixture exits
# non-zero, as `gh api` does on a 404, so a leg that needs a record nobody vendored reds.
REV_FIXTURE='cat "$FIX/reviewed/$1.json";'

mkrepo() {  # $1=name, then pairs of "src:dest"
  local d="$TMP/$1"; shift; mkdir -p "$d"
  local pair
  for pair in "$@"; do mkdir -p "$d/$(dirname "${pair#*:}")"; cp "$FIX/${pair%%:*}" "$d/${pair#*:}"; done
  printf '%s' "$d"
}

pop() { jq -r --arg k "$1" '(.[$k] // "MISSING") | tostring' <<< "$(sed -n 's/^population=//p' "$TMP/gh_output")"; }

block_mutant() {  # $1=label $2=sed program $3=sites expected [$4=text to mutate, default the callee blocks] -> MUT; 0 when it isolates its guard and parses
  local n base="${4:-$BLOCKS}"
  MUT="$(printf '%s\n' "$base" | sed "$2")"
  n="$(diff <(printf '%s\n' "$base") <(printf '%s\n' "$MUT") | grep -c '^>' || true)"
  if [ "$n" -ne "$3" ]; then
    fail "block mutant '$1' matched $n site(s), not $3 — it no longer isolates the guard it names"; return 1
  fi
  if ! bash -n <(printf 'set -euo pipefail\n%s\n' "$MUT") 2>/dev/null; then
    fail "block mutant '$1' does not parse — its verdict would prove nothing"; return 1
  fi
}

# GRANTS_AWK prints one line per job of a workflow, "<job><TAB><its grants, in file order>": `{}` or
# any other inline value as written, the block mapping's entries joined by ", ", or NONE when the job
# carries no `permissions:` of its own. A top-level `permissions:` is a line of its own. Quotes and
# trailing comments are stripped, so a quoted `"write"` reads as write.
GRANTS_AWK='
  function plain(s) { gsub(/["'"'"']/, "", s); sub(/[[:space:]]+#.*$/, "", s); sub(/^[[:space:]]+/, "", s); sub(/[[:space:]]+$/, "", s); gsub(/[[:space:]]+/, " ", s); return s }
  function flush() { if (job != "") print job "\t" (grants == "" ? "NONE" : grants); job = ""; grants = ""; p = 0 }
  /^[[:space:]]*(#|$)/ { next }
  /^["'"'"']?permissions["'"'"']?[[:space:]]*:/ { print "(top level)\t" plain($0); next }
  /^[^ ]/ { flush(); injobs = ($0 ~ /^["'"'"']?jobs["'"'"']?[[:space:]]*:/); next }
  !injobs { next }
  /^  [^ ]/ { flush(); job = plain($0); sub(/:.*$/, "", job); next }
  /^    ["'"'"']?permissions["'"'"']?[[:space:]]*:/ { grants = plain($0); sub(/^permissions ?: ?/, "", grants); p = (grants == ""); next }
  /^    [^ ]/ { p = 0; next }
  p { grants = grants (grants == "" ? "" : ", ") plain($0) }
  END { flush() }'

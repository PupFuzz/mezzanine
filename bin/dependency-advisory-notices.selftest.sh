#!/usr/bin/env bash
# ── VENDORED from coord 0.79.0 — the selftest of the advisory-notices workflow's shell. A body edit reds bin/vendor-pin-check.sh until declared below. ──
#
#   upstream repo     PupFuzz/agent-board-framework  (PRIVATE — a public CI runner cannot clone it)
#   upstream path     plugins/coord/templates/security/dependency-advisory-notices.selftest.sh
#   vendored from     31191341a2176a81f5e15b6d72e80282a2df168c   (marketplace, the v0.79.0 release merge)
#   plugin version    coord 0.79.0
#   upstream sha256   1c903ac68909c3e9cbef304752d2c7e49fdca9d73f9b25e9ccb4db7ac637890c
#
# That sha256 is the WHOLE upstream file; `bin/vendor-pin-check.sh` pins the BODY (from the first
# line that is neither blank nor a comment), which is a different figure. Re-derive this one
# against a marketplace clone:   git show 31191341:plugins/coord/templates/security/dependency-advisory-notices.selftest.sh | sha256sum
#
# DECLARED LOCAL EDITS — none. The text below this block was upstream's byte for byte when vendored
# (checked by diff against the commit above); the body pin holds it there.
#
# RUN IT WITH THIS REPOSITORY'S WORKFLOW DIRECTORY, which is not where upstream's default looks:
#     bash bin/dependency-advisory-notices.selftest.sh --workflows .github/workflows
# That is what `.github/workflows/dependency-audit-selftest.yml` runs. With no argument the suite
# looks for `bin/workflows/`, finds nothing, and FAILS naming the path it tried.
# ShellCheck's findings on this file are recorded in bin/shell-lint.baseline.tsv rather than
# annotated in the body, so the body stays upstream's.
#
# TO RE-VENDOR: this file moves with `bin/dependency-audit.selftest.sh`, whose header names the
# whole set and the procedure.
# ── END MEZZANINE VENDOR HEADER ────────────────────────────────────────────────────────────────
# dependency-advisory-notices.selftest.sh — runs the notices workflow's shipped shell. What it checks
# is the labels of its `nleg` lines; a rule of the coord plugin's `docs/DEPENDENCY-AUDIT.md`
# § Advisory notices with no label here is not held here. The workflow's YAML around that shell is
# `dependency-advisory-notices-shape.selftest.sh`'s.
#
# A behaviour leg runs the `dep-notices` block, extracted verbatim from workflows/dependency-advisory-notices.yml, against findings files and
# NOTICE_FAKE, a `notice_api` that keeps the issues in one JSON file, answers with a status and logs
# every call (and every request body). The legs assert on the logged calls and the resulting issues;
# where a leg's subject IS what reaches the run page or the job's red, it reads the summary file or
# the run's output. Each leg is a function of the block text, returning 0 when its assertion holds,
# so its mutant runs the SAME assertion over a block with one guard changed.
#
# ZERO NETWORK. The shipped `notice_api` body (`gh api --include`) is not run here, only its
# contract: the block treats a status other than the one a call needs, or none, as red. The first
# live run is what exercises it.
#
# Every leg and mutant is called through `nleg` or `nmut` at the start of a line, and the last check
# compares how many ran with how many this file declares.
set -uo pipefail

SUITE=dependency-advisory-notices
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$HERE/dependency-selftest-lib.sh" || { echo "FAIL: dependency-selftest-lib.sh is not beside this selftest, so no leg ran"; exit 1; }

need_workflows "$NOTICES"
check_workflows_input dependency-audit.yml
check_workflows_input dependency-advisory-notices.yml
extract_parsed "$NOTICES" "dep-notices" "notices workflow"
NOTICES_BLOCK="$BODY"
ok "notices block extracted from the shipped notices workflow, and it parses ($(printf '%s' "$NOTICES_BLOCK" | wc -l) lines)"
case "$(sed -n '/CUSTOMIZE-BEGIN notice-seams/,/CUSTOMIZE-END notice-seams/p' "$NOTICES")" in
  *'notice_api()'*) ok "the notices workflow's CUSTOMIZE zone defines notice_api, the one seam its block calls (its body is NOT run here)" ;;
  *) fail "the notices workflow's CUSTOMIZE zone no longer defines notice_api() — the block calls it, so the template would fail at run time while this suite's fake kept it green" ;;
esac
AFFECTED="$(mkrepo affected composer.lock.affected:composer.lock)"

NT="$TMP/nt"
N_RAN=0
NOTICE_FAKE='
sleep() { echo "$1" >> "$NT/sleeps"; }
notice_api() {
  local m="$1" p="$2" out="$4" n
  printf "%s %s\n" "$m" "$p" >> "$NT/calls"
  [ "$3" = - ] || cp "$3" "$NT/req.$(wc -l < "$NT/calls")"
  if [ -n "${NT_FAULT_CALL:-}" ]; then
    case "$m $p" in
      $NT_FAULT_CALL)
        if [ -z "${NT_FAULT_IF_STATE:-}" ] || jq -e "has(\"state\")" "$3" > /dev/null; then
          [ "$NT_FAULT_STATUS" != none ] || return 1
          echo "{\"message\":\"fault\"}" > "$out"; echo "$NT_FAULT_STATUS"; return 0
        fi ;;
    esac
  fi
  case "$m $p" in
    "GET repos/o/r/actions/workflows/7/runs?"*) cp "$NT/runs.json" "$out"; echo 200 ;;
    "GET repos/o/r/issues?"*)
      jq --argjson pg "${p##*page=}" --arg open "$(case "$p" in *state=open*) echo 1;; esac)" \
        "map(select(\$open == \"\" or .state == \"open\")) | sort_by(-.number) | .[(\$pg - 1) * 100 : \$pg * 100]" \
        "$NT/issues.json" > "$out"; echo 200 ;;
    "GET repos/o/r/labels/dependency-advisory")
      echo "{}" > "$out"; if [ -f "$NT/label" ]; then echo 200; else echo 404; fi ;;
    "POST repos/o/r/labels") touch "$NT/label"; echo "{}" > "$out"; echo 201 ;;
    "POST repos/o/r/issues")
      n=$(( $(jq length "$NT/issues.json") + 1 ))
      jq --argjson n "$n" --slurpfile r "$3" --arg drop "${NT_DROP_LABELS:-}" \
        ". + [{number: \$n, state: \"open\", title: \$r[0].title, body: \$r[0].body,
               labels: (if \$drop == \"\" then [\$r[0].labels[] | {name: .}] else [] end)}]" \
        "$NT/issues.json" > "$NT/issues.tmp" && mv "$NT/issues.tmp" "$NT/issues.json"
      jq ".[] | select(.number == $n)" "$NT/issues.json" > "$out"; echo 201 ;;
    "POST repos/o/r/issues/"*"/comments")
      n="${p%/comments}"; n="${n##*/}"
      jq -c --argjson n "$n" "{n: \$n} + ." "$3" >> "$NT/comments"; echo "{}" > "$out"; echo 201 ;;
    "PATCH repos/o/r/issues/"*)
      n="${p##*/}"
      jq --argjson n "$n" --slurpfile r "$3" "map(if .number == \$n then . + \$r[0] else . end)" \
        "$NT/issues.json" > "$NT/issues.tmp" && mv "$NT/issues.tmp" "$NT/issues.json"
      echo "{}" > "$out"; echo 200 ;;
    *) echo "{}" > "$out"; echo 599 ;;
  esac
}'
# The triggering audit run is 500; the run list holds it alone unless a leg says otherwise.
nt_reset() {
  rm -rf "$NT"; mkdir -p "$NT/find"; printf '[]' > "$NT/issues.json"; : > "$NT/calls"; : > "$NT/comments"; : > "$NT/sleeps"
  printf '{"workflow_runs":[{"id":500,"status":"completed","conclusion":"failure","event":"schedule","head_branch":"main"}]}' > "$NT/runs.json"
}
nt_files() { rm -rf "$NT/find"; mkdir -p "$NT/find"; }   # the next run's findings files start empty
nt_file() {  # $1=ref $2=status $3=affected rows (JSON array) [$4=unresolved] [$5=accepted] [$6=withdrawn]
  local d="$NT/find/dependency-audit-findings-$1"; mkdir -p "$d"
  jq -n --arg ref "$1" --arg st "$2" --argjson a "$3" --argjson u "${4:-[]}" --argjson acc "${5:-[]}" --argjson w "${6:-[]}" \
    '{schema: 1, ref: $ref, status: $st, affected: $a, unresolved: $u, accepted: $acc, withdrawn: $w}' > "$d/dependency-audit-findings.json"
}
nt_row() {  # $1=source $2=package $3=version $4=advisory ID [$5=ecosystem] [$6=range] -> one affected row
  jq -nc --arg s "$1" --arg p "$2" --arg v "$3" --arg g "$4" --arg e "${5:-npm}" --arg r "${6:->= 1.0.0, < 2.0.0}" \
    '{source: $s, ecosystem: $e, package: $p, version: $v, repo: "", ghsa: $g, severity: "high", range: $r, patched: "patched in \"2.0.0\""}'
}
rows() { jq -sc '.'; }   # nt_row lines on stdin -> one JSON array
run_notices() {  # $1 = block text -> the block's exit status; $NT/out holds what it printed
  : > "$NT/summary"
  ( cd "$NT" && GITHUB_REPOSITORY=o/r GITHUB_REF_NAME=main GITHUB_SERVER_URL=https://github.example \
      GITHUB_STEP_SUMMARY="$NT/summary" AUDIT_RUN_ID=500 AUDIT_WORKFLOW_ID=7 FINDINGS_DIR="$NT/find" \
      NOTICES_SWITCH="${NT_SWITCH:-}" NT="$NT" \
      bash -c "set -euo pipefail
$NOTICE_FAKE
$1" ) > "$NT/out" 2>&1
}
calls_since() { : > "$NT/calls"; }
writes() { grep -cE '^(POST|PATCH) ' "$NT/calls" || true; }
creates() { grep -c '^POST repos/o/r/issues$' "$NT/calls" || true; }
iq() { jq -e "$1" "$NT/issues.json" > /dev/null 2>&1; }   # a jq predicate over the fake's issues
edit_issues() { jq "$1" "$NT/issues.json" > "$NT/issues.tmp" && mv "$NT/issues.tmp" "$NT/issues.json"; }   # a jq edit, as a person would make
state_of() {  # $1 = issue number -> the refs its state line records, compact JSON
  jq -r --argjson n "$1" '.[] | select(.number == $n) | .body | split("\n")[0]' "$NT/issues.json" \
    | sed -n 's/^<!-- dependency-audit-notice \(.*\) -->$/\1/p' | jq -c '.refs'
}
req_of_last() {  # $1 = call pattern (grep -E) -> the request body of the last such call
  local n; n="$(grep -nE "$1" "$NT/calls" | tail -1 | cut -d: -f1)"
  [ -n "$n" ] && cat "$NT/req.$n"
}
nleg() {  # $1=label $2=leg function [$3=block text, default the notices block]: the leg must hold on the SHIPPED block
  N_RAN=$((N_RAN + 1))
  if "$2" "${3:-$NOTICES_BLOCK}"; then ok "notices: $1"; else fail "notices: $1 ($(tail -c 600 "$NT/out" 2>/dev/null | tr '\n' ' '))"; fi
}
nmut() {  # $1=label $2=leg function $3=sed program $4=sites it must change [$5=block text]: the leg must FAIL on the mutant
  N_RAN=$((N_RAN + 1))
  block_mutant "$1" "$3" "$4" "${5:-$NOTICES_BLOCK}" || return 0
  if "$2" "$MUT"; then fail "notices mutant '$1': the leg still held, so it is not held by that guard"
  elif grep -qE '^jq: ' "$NT/out" 2>/dev/null; then fail "notices mutant '$1': its jq program broke, so the leg failed for that and not for the guard: $(grep -m1 '^jq: ' "$NT/out")"
  else ok "mutant control: $1 — its leg fails, so it binds the shipped block"; fi
}

ID_A=GHSA-aaaa-aaaa-aaaa; ID_B=GHSA-bbbb-bbbb-bbbb; ID_C=GHSA-cccc-cccc-cccc

# A new advisory inside an already-red audit: run N finds A; run N+1 finds A and B; then N+1 again.
leg_new_inside_red() {
  nt_reset; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"
  run_notices "$1" && [ "$(creates)" -eq 1 ] || return 1
  nt_files; nt_file main audited "$( { nt_row 2 pkg-a 1.0.0 $ID_A; nt_row 2 pkg-b 1.0.0 $ID_B; } | rows)"; calls_since
  run_notices "$1" && [ "$(creates)" -eq 1 ] && ! grep -q 'issues/1' "$NT/calls" \
    && iq 'length == 2 and (.[1].title | contains("GHSA-bbbb-bbbb-bbbb"))' || return 1
  calls_since; run_notices "$1" && [ "$(writes)" -eq 0 ]
}
nleg "a new advisory inside an already-red audit is one create, and nothing else is written" leg_new_inside_red
nmut "the issue list read as empty" leg_new_inside_red \
  's/{number, state, body}'"'"' "\$WORK\/page\.json" >> "\$WORK\/issues\.ndjson"/{number, state, body}'"'"' "$WORK\/page.json" > \/dev\/null/' 1

# Two audited refs record A; then one of them stops reporting it.
two_ref_issue() {  # $1 = block: one issue for A recorded on main and dev
  nt_reset; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"; nt_file dev audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"
  run_notices "$1"
}
leg_fixed_on_one_ref() {
  two_ref_issue "$1" || return 1
  nt_files; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"; nt_file dev audited '[]'; calls_since
  run_notices "$1" && [ "$(grep -c '^POST repos/o/r/issues/1/comments$' "$NT/calls")" -eq 1 ] \
    && [ "$(grep -c '^PATCH repos/o/r/issues/1$' "$NT/calls")" -eq 1 ] && [ "$(writes)" -eq 2 ] \
    && iq '.[0].state == "open"' && [ "$(state_of 1)" = '{"main":{"versions":["1.0.0"],"sources":["2"]}}' ]
}
nleg "fixed on one ref: one comment and one PATCH, the issue stays open and records the other ref" leg_fixed_on_one_ref
nmut "a key cleared when any audited ref lacks it" leg_fixed_on_one_ref \
  's/else \.\[\$ref\] = \$now end) ) as \$n$/else .[$ref] = $now end) ) as $n0 | (if any($byref[]; .status == "audited") and ($n0 | length) < ($byref | length) then {} else $n0 end) as $n/' 1

leg_fixed_everywhere() {
  two_ref_issue "$1" || return 1
  nt_files; nt_file main audited '[]'; nt_file dev audited '[]'; calls_since
  run_notices "$1" && grep -q '^POST repos/o/r/issues/1/comments$' "$NT/calls" \
    && [ "$(grep -c '^PATCH repos/o/r/issues/1$' "$NT/calls")" -eq 1 ] \
    && [ "$(grep -n . "$NT/calls" | grep -m1 comments | cut -d: -f1)" -lt "$(grep -n . "$NT/calls" | grep -m1 PATCH | cut -d: -f1)" ] \
    && req_of_last '^PATCH ' | jq -e '.state == "closed" and .state_reason == "completed" and (.body | startswith("<!-- dependency-audit-notice"))' > /dev/null \
    && iq '.[0].state == "closed"' && [ "$(state_of 1)" = '{}' ]
}
nleg "fixed on every ref: the comment, then ONE PATCH carrying body, state closed and state_reason completed" leg_fixed_everywhere
nmut "the close sent as a second request" leg_fixed_everywhere \
  's/^\( *\)jq '"'"'{body} + (if \.state == "closed" then {state, state_reason: "completed"}$/\1jq '"'"'{body} + (if false then {}/;s/^\(  *\)write PATCH "repos\/\$REPO\/issues\/\$n" "\$WORK\/req\.json" 200$/&; jq '"'"'if .state == "closed" then {state, state_reason: "completed"} else {} end'"'"' <<< "$action" > "$WORK\/req.json"; write PATCH "repos\/$REPO\/issues\/$n" "$WORK\/req.json" 200/' 2

leg_patch_refused() {
  nt_reset; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"; run_notices "$1" || return 1
  nt_files; nt_file main audited '[]'; calls_since
  ! NT_FAULT_CALL='PATCH repos/o/r/issues/1' NT_FAULT_IF_STATE=1 NT_FAULT_STATUS=403 run_notices "$1" || return 1
  iq '.[0].state == "open"' && [ "$(state_of 1)" = '{"main":{"versions":["1.0.0"],"sources":["2"]}}' ] || return 1
  calls_since; run_notices "$1" && grep -q '^POST repos/o/r/issues/1/comments$' "$NT/calls" && iq '.[0].state == "closed"'
}
nleg "a refused close leaves the old recorded state and the open issue, and the next run says it again" leg_patch_refused
nmut "the body sent first, in its own request" leg_patch_refused \
  's/^\(  *\)write POST "repos\/\$REPO\/issues\/\$n\/comments" "\$WORK\/req\.json" 201$/\1jq '"'"'{body}'"'"' <<< "$action" > "$WORK\/req.json"; write PATCH "repos\/$REPO\/issues\/$n" "$WORK\/req.json" 200; jq '"'"'{body: .comment}'"'"' <<< "$action" > "$WORK\/req.json"; write POST "repos\/$REPO\/issues\/$n\/comments" "$WORK\/req.json" 201/;s/^\( *\)jq '"'"'{body} + (if \.state == "closed"/\1jq '"'"'{} + (if .state == "closed"/' 2

leg_unfinished_ref_holds() {
  two_ref_issue "$1" || return 1
  nt_files; nt_file main error '[]'; calls_since   # main errored, dev sent no file
  run_notices "$1" && [ "$(writes)" -eq 0 ] && [ "$(state_of 1 | jq -c 'keys')" = '["dev","main"]' ]
}
nleg "a ref with status error, and a ref with no file, clear nothing" leg_unfinished_ref_holds
nmut "an error file read as audited" leg_unfinished_ref_holds \
  's/^\( *\)\$f\.status != "audited"$/\1($f.status != "audited" and $f.status != "error")/' 1
nmut "a missing file read as an audited one" leg_unfinished_ref_holds \
  's/^\( *\)\$f\.status != "audited"$/\1($f != null and $f.status != "audited")/' 1

leg_unresolved_hold() {
  nt_reset; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"; run_notices "$1" || return 1
  nt_files; nt_file main audited '[]' '["pkg-a"]'; calls_since
  run_notices "$1" && [ "$(writes)" -eq 0 ] || return 1
  nt_files; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"; calls_since
  run_notices "$1" && [ "$(writes)" -eq 0 ]
}
nleg "a source-2 notice is held while its package did not resolve, and nothing is written either way" leg_unresolved_hold
nmut "the unresolved hold removed" leg_unresolved_hold \
  's/ and (\$f\.unresolved | has_ci(\$package)));$/ and false);/' 1

leg_unresolved_source1_clears() {
  nt_reset; nt_file main audited "$(nt_row 1 pkg-a 1.0.0 $ID_A | rows)"; run_notices "$1" || return 1
  nt_files; nt_file main audited '[]' '["pkg-a"]'; calls_since
  run_notices "$1" && iq '.[0].state == "closed"'
}
nleg "a source-1-only notice clears normally even while its package did not resolve" leg_unresolved_source1_clears
nmut "the hold applied whatever the recorded sources" leg_unresolved_source1_clears \
  's/or (((\$recorded\.sources \/\/ \[\]) | index(\["2"\])) != null and /or (/' 1

# A ref that did not prove absence keeps what is recorded beside what it lists.
leg_npm_flake() {  # one lookup of two nested affected versions fails for a night, after a hand close
  local two; two="$( { nt_row 2 pkg-a 1.0.0 $ID_A; nt_row 2 pkg-a 1.5.0 $ID_A; } | rows)"
  nt_reset; nt_file main audited "$two"; run_notices "$1" && edit_issues 'map(.state = "closed")' || return 1
  nt_files; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)" '["pkg-a"]'; calls_since
  run_notices "$1" && [ "$(writes)" -eq 0 ] || return 1
  nt_files; nt_file main audited "$two"; calls_since
  run_notices "$1" && [ "$(writes)" -eq 0 ] && iq '.[0].state == "closed"'
}
nleg "a lookup that fails for some of a package's versions writes nothing that night or the next, and reopens nothing" leg_npm_flake
nmut "an unresolved package's listing replaces the record" leg_npm_flake \
  's/ and (\$f\.unresolved | has_ci(\$package)));$/ and false);/' 1
nmut "the recorded versions dropped from the union" leg_npm_flake 's/{versions: (\[\$a, \$b | \.versions/{versions: ([$b | .versions/' 1

# Both sources record A; a source that fails a night, or stops reporting it, is not an event.
both_sources() {  # $1 = block
  nt_reset; nt_file main audited "$( { nt_row 1 pkg-a 1.0.0 $ID_A; nt_row 2 pkg-a 1.0.0 $ID_A; } | rows)"; run_notices "$1"
}
leg_source2_fails_a_night() {
  local i; both_sources "$1" || return 1; calls_since
  for i in 1 2; do
    nt_files; nt_file main error "$(nt_row 1 pkg-a 1.0.0 $ID_A | rows)"
    run_notices "$1" && [ "$(writes)" -eq 0 ] || return 1
    nt_files; nt_file main audited "$( { nt_row 1 pkg-a 1.0.0 $ID_A; nt_row 2 pkg-a 1.0.0 $ID_A; } | rows)"
    run_notices "$1" && [ "$(writes)" -eq 0 ] || return 1
  done
}
nleg "a source that fails for a night writes nothing that night or the next" leg_source2_fails_a_night
nmut "a listing always replaces the record and a source is an event" leg_source2_fails_a_night \
  's/^\( *\)| (if unproven(.*) then union(/\1| (if false then union(/;s/def told: map_values(\.versions);/def told: .;/' 2

leg_sources_alone() {
  both_sources "$1" || return 1
  nt_files; nt_file main audited "$(nt_row 1 pkg-a 1.0.0 $ID_A | rows)"; calls_since
  run_notices "$1" && [ "$(writes)" -eq 0 ]
}
nleg "an audited ref whose sources changed and whose versions did not writes nothing" leg_sources_alone
nmut "a change in sources is an event" leg_sources_alone 's/def told: map_values(\.versions);/def told: .;/' 1

leg_no_early_close() {
  both_sources "$1" || return 1
  nt_files; nt_file main audited "$( { nt_row 1 pkg-a 1.0.0 $ID_A; nt_row 1 pkg-a 1.0.1 $ID_A; } | rows)" '["pkg-a"]'
  run_notices "$1" && [ "$(state_of 1)" = '{"main":{"versions":["1.0.0","1.0.1"],"sources":["1","2"]}}' ] || return 1
  nt_files; nt_file main audited '[]' '["pkg-a"]'; calls_since
  run_notices "$1" && [ "$(writes)" -eq 0 ] && iq '.[0].state == "open"'
}
nleg "a night that rewrites the record while source 2 could not look keeps source 2 recorded, so the next silent night holds" leg_no_early_close
nmut "the recorded sources dropped from the union" leg_no_early_close 's/sources: (\[\$a, \$b | \.sources/sources: ([$b | .sources/' 1

leg_error_partial() {
  nt_reset; nt_file main audited "$( { nt_row 2 pkg-a 1.0.0 $ID_A; nt_row 2 pkg-a 1.0.1 $ID_A; } | rows)"; run_notices "$1" || return 1
  nt_files; nt_file main error "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"; calls_since
  run_notices "$1" && [ "$(writes)" -eq 0 ] || return 1
  nt_files; nt_file main error "$(nt_row 2 pkg-a 1.0.2 $ID_A | rows)"; calls_since
  run_notices "$1" && [ "$(writes)" -eq 2 ] && [ "$(state_of 1 | jq -c '.main.versions')" = '["1.0.0","1.0.1","1.0.2"]' ]
}
nleg "an error night that lists some versions clears none and adds a new one" leg_error_partial
nmut "an error file's listing replaces the record" leg_error_partial 's/^\( *\)\$f\.status != "audited"$/\1false/' 1

leg_complete_replaces() {
  nt_reset; nt_file main audited "$( { nt_row 2 pkg-a 1.0.0 $ID_A; nt_row 2 pkg-a 1.0.1 $ID_A; } | rows)"; run_notices "$1" || return 1
  nt_files; nt_file main audited "$(nt_row 2 pkg-a 1.0.1 $ID_A | rows)"
  run_notices "$1" && [ "$(state_of 1 | jq -c '.main.versions')" = '["1.0.1"]' ]
}
nleg "an audited ref that looked everything up replaces the record" leg_complete_replaces
nmut "every listing joined to the record" leg_complete_replaces 's/^\( *\)| (if unproven(.*) then union(/\1| (if true then union(/' 1

leg_hold_is_per_ref() {  # main was raised by source 2, dev by source 1 alone; then neither lists it and it does not resolve
  nt_reset; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"; nt_file dev audited "$(nt_row 1 pkg-a 1.0.0 $ID_A | rows)"
  run_notices "$1" || return 1
  nt_files; nt_file main audited '[]' '["pkg-a"]'; nt_file dev audited '[]' '["pkg-a"]'
  run_notices "$1" && [ "$(state_of 1 | jq -c 'keys')" = '["main"]' ] && iq '.[0].state == "open"'
}
nleg "the unresolved hold reads the sources recorded for its own ref: the source-2 ref is held, the source-1 ref clears" leg_hold_is_per_ref
nmut "the hold fed the sources of every recorded ref" leg_hold_is_per_ref \
  's/unproven(\$byref\[\$ref\]; \$s\[\$ref\]; /unproven($byref[$ref]; {sources: [$s[].sources[]]}; /' 1

leg_clear_reasons() {
  nt_reset; nt_file main audited "$( { nt_row 2 pkg-a 1.0.0 $ID_A; nt_row 2 pkg-b 1.0.0 $ID_B; nt_row 2 pkg-c 1.0.0 $ID_C; } | rows)"
  run_notices "$1" || return 1
  nt_files; nt_file main audited '[]' '[]' '["GHSA-BBBB-bbbb-bbbb"]' '["ghsa-aaaa-aaaa-aaaa"]'
  run_notices "$1" || return 1
  jq -e -s 'map({(.n | tostring): .body}) | add
            | (.["1"] | contains("withdrew")) and (.["2"] | contains("acceptance file"))
              and (.["3"] | contains("no longer reported"))' "$NT/comments" > /dev/null
}
nleg "the clearing comment names withdrawn, accepted, or no longer reported" leg_clear_reasons
nmut "the clearing reason fixed to no longer reported" leg_clear_reasons \
  's/if (\$f\.withdrawn | has_ci(\$id)) then/if false then/;s/elif (\$f\.accepted | has_ci(\$id)) then/elif false then/' 2

leg_regression_reopens() {
  nt_reset; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"; run_notices "$1" || return 1
  nt_files; nt_file main audited '[]'; run_notices "$1" && iq '.[0].state == "closed"' || return 1
  nt_files; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"; calls_since
  run_notices "$1" && [ "$(creates)" -eq 0 ] && iq 'length == 1 and .[0].state == "open"' \
    && req_of_last '^PATCH ' | jq -e '.state_reason == "reopened"' > /dev/null
}
nleg "a regression after a close reopens the same issue, with no create" leg_regression_reopens
nmut "only open issues scanned" leg_regression_reopens 's/&state=all&per_page=100/\&state=open\&per_page=100/' 1

hand_closed() {  # $1 = block: one issue for A recorded on $2 (refs, space-separated), then closed by hand
  local r; nt_reset
  for r in $2; do nt_file "$r" audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"; done
  run_notices "$1" && edit_issues 'map(.state = "closed")'
}
leg_hand_closed_unchanged() {
  hand_closed "$1" main || return 1
  calls_since; run_notices "$1" && [ "$(writes)" -eq 0 ] && iq '.[0].state == "closed"'
}
nleg "a hand-closed notice whose state is unchanged is not written to" leg_hand_closed_unchanged
nmut "reopened whenever the key is affected" leg_hand_closed_unchanged \
  's/updates: \[ \$keyed\[\] | select(\.issue != null and (\.n | told) != (\.s | told))$/updates: [ $keyed[] | select(.issue != null and ((.n | told) != (.s | told) or (.issue.state == "closed" and .n != {})))/;s/elif \.n != {} and \.issue\.state == "closed" and bad_news(\.s; \.n) then "open"/elif .n != {} and .issue.state == "closed" then "open"/' 2

leg_hand_closed_partial_clear() {
  hand_closed "$1" "main dev" || return 1
  nt_files; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"; nt_file dev audited '[]'; calls_since
  run_notices "$1" && [ "$(writes)" -eq 2 ] && req_of_last '^PATCH ' | jq -e 'has("state") | not' > /dev/null \
    && iq '.[0].state == "closed"'
}
nleg "a hand-closed notice that clears on one ref is commented and stays closed" leg_hand_closed_partial_clear
nmut "reopened on any change" leg_hand_closed_partial_clear \
  's/ and \.issue\.state == "closed" and bad_news(\.s; \.n) then "open"/ and .issue.state == "closed" then "open"/' 1

leg_hand_closed_new_ref() {
  hand_closed "$1" main || return 1
  nt_files; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"; nt_file dev audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"; calls_since
  run_notices "$1" && grep -q '^POST repos/o/r/issues/1/comments$' "$NT/calls" \
    && req_of_last '^PATCH ' | jq -e '.state == "open"' > /dev/null && iq '.[0].state == "open"'
}
nleg "a hand-closed notice reaching a ref it did not record is reopened" leg_hand_closed_new_ref
nmut "never reopened" leg_hand_closed_new_ref 's/bad_news(\.s; \.n) then "open"/bad_news(.s; .n) then null/' 1

leg_hand_closed_version_moved() {
  hand_closed "$1" main || return 1
  nt_files; nt_file main audited "$(nt_row 2 pkg-a 1.0.1 $ID_A | rows)"
  run_notices "$1" && iq '.[0].state == "open"'
}
nleg "a hand-closed notice whose version moves is reopened" leg_hand_closed_version_moved
nmut "a new version not read as bad news" leg_hand_closed_version_moved \
  's/ or any(\.value\.versions\[\]; \. as \$v | (\$s\[\$ref\]\.versions | index(\[\$v\])) == null));$/);/' 1

leg_hand_closed_cleared() {
  hand_closed "$1" main || return 1
  nt_files; nt_file main audited '[]'; calls_since
  run_notices "$1" && [ "$(writes)" -eq 2 ] && req_of_last '^PATCH ' | jq -e 'has("state") | not' > /dev/null && [ "$(state_of 1)" = '{}' ]
}
nleg "a hand-closed notice that clears everywhere records it and sends no state" leg_hand_closed_cleared
nmut "the close sent for an issue already closed" leg_hand_closed_cleared 's/if \.n == {} and \.issue\.state == "open" then "closed"/if .n == {} then "closed"/' 1

# Two issues carry the key: the older records a state the findings no longer hold.
leg_newest_issue() {
  nt_reset; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"; run_notices "$1" || return 1
  edit_issues '. + [.[0] | .number = 2] | .[0].body |= sub("1\\.0\\.0"; "0.9.0")'; calls_since
  run_notices "$1" && [ "$(writes)" -eq 0 ]
}
nleg "of two issues for one key, the newest is the record" leg_newest_issue
nmut "the oldest issue read as the record" leg_newest_issue 's/| max_by(\.number) as \$issue$/| min_by(.number) as $issue/' 1

leg_state_is_parsed() {  # the same state with its keys in another order and spaces added
  two_ref_issue "$1" || return 1
  edit_issues '.[0].body |= sub("^[^\n]*"; "<!-- dependency-audit-notice {\"refs\": {\"main\": {\"sources\": [\"2\"], \"versions\": [\"1.0.0\"]}, \"dev\": {\"versions\": [\"1.0.0\"], \"sources\": [\"2\"]}}, \"package\": \"pkg-a\", \"id\": \"GHSA-aaaa-aaaa-aaaa\"} -->")'
  calls_since; run_notices "$1" && [ "$(writes)" -eq 0 ]
}
nleg "a state line with other spacing and key order is the same state" leg_state_is_parsed
nmut "the states compared as text" leg_state_is_parsed 's/(\.n | told) != (\.s | told)/(.n | told | tojson) != (.s | told | tojson)/' 1

leg_stray() {  # labelled, with a state line that is not its first line
  nt_reset; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"; run_notices "$1" || return 1
  edit_issues '.[0].body |= "edited by a person\n" + .'; calls_since
  run_notices "$1" && [ "$(creates)" -eq 1 ] && ! grep -q 'issues/1' "$NT/calls" && iq 'length == 2' \
    && grep -qx '#1 carries the label but no notice state line: left alone' "$NT/summary"
}
nleg "a labelled issue whose first line is not the state is listed, left alone, and a new notice is opened" leg_stray
nmut "a state line read from below the first line" leg_stray 's/((\.body \/\/ "") | split("\\n")\[0\]/((.body \/\/ "") | split("\\n") | (map(select(startswith("<!--"))) | first \/\/ "")/' 1
nmut "the strays not listed" leg_stray 's/^\( *\)strays: \[ \$labelled\[\] | select(\.st == null)/\1strays: [ $labelled[] | select(false)/' 1

leg_both_sources_one_issue() {
  nt_reset
  nt_file main audited "$( { nt_row 1 Foo/Bar 1.0.0 GHSA-dddd-dddd-dddd Packagist; nt_row 2 foo/bar 1.0.0 ghsa-DDDD-dddd-dddd composer; } | rows)"
  run_notices "$1" && [ "$(creates)" -eq 1 ] && [ "$(state_of 1 | jq -c '.main.sources')" = '["1","2"]' ]
}
nleg "one advisory from both sources, ecosystems spelled apart and IDs in different case, is one issue" leg_both_sources_one_issue
nmut "ecosystem added to the key" leg_both_sources_one_issue \
  's/( \[ \$rows\[\] | {id: \.ghsa, package} \]/( [ $rows[] | {id: .ghsa, package, eco: .ecosystem} ]/;s/unique_by(\[(\.id | lc), (\.package | lc)\])/unique_by([(.id | lc), (.package | lc), .eco])/' 2
nmut "the case fold removed" leg_both_sources_one_issue 's/def lc: ascii_downcase;/def lc: .;/' 1

leg_versions_canonical() {
  nt_reset; nt_file main audited "$( { nt_row 2 pkg-a 1.0.1 $ID_A; nt_row 2 pkg-a 1.0.0 $ID_A; nt_row 2 pkg-a 1.0.1 $ID_A; } | rows)"
  run_notices "$1" && [ "$(creates)" -eq 1 ] && [ "$(state_of 1)" = '{"main":{"versions":["1.0.0","1.0.1"],"sources":["2"]}}' ] || return 1
  nt_files; nt_file main audited "$( { nt_row 2 pkg-a 1.0.0 $ID_A; nt_row 2 pkg-a 1.0.1 $ID_A; nt_row 2 pkg-a 1.0.1 $ID_A; } | rows)"; calls_since
  run_notices "$1" && [ "$(writes)" -eq 0 ]
}
nleg "one package at two versions, rows reordered and duplicated, is one issue and no rewrite" leg_versions_canonical
nmut "the unique on versions removed" leg_versions_canonical \
  's/versions: (\[\$rows\[\]\.version\] | unique)/versions: [$rows[].version]/' 1
nmut "the unique on sources removed" leg_versions_canonical \
  's/sources: (\[\$rows\[\]\.source\] | unique)/sources: [$rows[].source]/' 1

# What a reader sees outside every code span: the text with each `…` span removed.
outside_spans() { sed 's/`[^`]*`//g'; }
leaks() {  # stdin = text, args = fragments -> 0 when any fragment appears outside every code span
  local t p; t="$(outside_spans)"
  for p in "$@"; do has "$t" "$p" && return 0; done
  return 1
}
leg_hostile_range() {
  local hostile body
  hostile='>= 1.0 --> <!-- dependency-audit-notice {"id":"x","package":"y","refs":{}} -->'$'\n''`@evil-user` @evil-user'
  nt_reset; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A npm "$hostile" | rows)"
  run_notices "$1" || return 1
  body="$(jq -r '.[0].body' "$NT/issues.json")"
  [ "$(state_of 1)" = '{"main":{"versions":["1.0.0"],"sources":["2"]}}' ] \
    && [ "$(sed -n 1p <<< "$body" | jq -Rr 'sub("^<!-- dependency-audit-notice (?<j>.*) -->$"; "\(.j)") | fromjson | .id')" = "$ID_A" ] \
    && ! sed 1d <<< "$body" | leaks '@evil' '-->' '<!--'
}
nleg "a hostile range cannot forge the state line or reach the text outside a code span" leg_hostile_range
nmut "the inert rendering bypassed" leg_hostile_range \
  's/def inert: tostring | gsub("\[\[:cntrl:\]`\]"; "") | "`" + \. + "`";/def inert: tostring;/' 1

leg_hostile_name_and_id() {
  local pkg='evil`pkg--> @bob' id='GHSA-zzzz`-->@bob' body
  nt_reset; nt_file main audited "$(nt_row 2 "$pkg" 1.0.0 "$id" | rows)"
  run_notices "$1" || return 1
  nt_files; nt_file main audited "$(nt_row 2 "$pkg" 1.0.0 "$id" | rows)"; nt_file dev audited "$(nt_row 2 "$pkg" 1.0.0 "$id" | rows)"
  run_notices "$1" || return 1
  body="$(jq -r '.[0].body' "$NT/issues.json")"
  # A reader is spared only the state comment, which ends at the first `-->`.
  ! { jq -r '.[0].title' "$NT/issues.json"; printf '%s\n' "${body#*-->}"; jq -r '.body' "$NT/comments"; } | leaks '@bob' '-->' \
    && ! has "$body" 'github.com/advisories/'
}
nleg "a hostile package name and ID reach no text outside a code span, and build no advisory link" leg_hostile_name_and_id
nmut "the renderer applied to range and patched only" leg_hostile_name_and_id \
  's/\(\$k\.id\|\$k\.package\|\.key\.package\|\.key\.id\) | inert/\1 | tostring/g' 5
nmut "the state comment's > left as written" leg_hostile_name_and_id 's/ | gsub(">"; "[^"]*")//' 1

leg_state_line_escaped() {  # the first line is one comment from end to end, and the package reads back from it
  local pkg='evil--> <!-- @bob' state
  nt_reset; nt_file main audited "$(nt_row 2 "$pkg" 1.0.0 $ID_A | rows)"; run_notices "$1" || return 1
  state="$(jq -r '.[0].body | split("\n")[0]' "$NT/issues.json")"
  state="${state#<!-- dependency-audit-notice }"; state="${state% -->}"
  ! has "$state" '<' && ! has "$state" '>' && [ "$(jq -r '.package' <<< "$state")" = "$pkg" ]
}
nleg "a hostile package name cannot end or open a comment in the state line, and reads back from it" leg_state_line_escaped
nmut "the state line's < left as written" leg_state_line_escaped 's/ | gsub("<"; "[^"]*")//' 1
nmut "the state line's > left as written" leg_state_line_escaped 's/ | gsub(">"; "[^"]*")//' 1

HOSTILE='ok`-->@bob'
leg_hostile_texts() {  # $1 = block: severity, patched text, ref name and version each carry HOSTILE, in the body and in every kind of change line
  local a; a="$(nt_row 2 pkg-a "1$HOSTILE" $ID_A | jq -c --arg h "$HOSTILE" '.severity = $h | .patched = $h' | rows)"
  nt_reset; nt_file "ref$HOSTILE" audited "$a"; run_notices "$1" || return 1
  nt_files; nt_file "ref$HOSTILE" audited "$(nt_row 2 pkg-a "2$HOSTILE" $ID_A | rows)"; nt_file "new$HOSTILE" audited "$a"; run_notices "$1" || return 1
  jq -r '.[0].body | split("\n")[1:] | join("\n")' "$NT/issues.json" > "$NT/bodies"
  nt_files; nt_file "ref$HOSTILE" audited "$a"; nt_file "new$HOSTILE" audited '[]'; run_notices "$1" || return 1
  jq -r '.[0].body | split("\n")[1:] | join("\n")' "$NT/issues.json" >> "$NT/bodies"
  grep -q 'now affected on' "$NT/comments" && grep -q 'cleared on' "$NT/comments" && grep -q '(was ' "$NT/comments" \
    && ! { cat "$NT/bodies"; jq -r '.body' "$NT/comments"; } | leaks '@bob' '-->'
}
nleg "a hostile severity, patched text, ref name and version reach no text outside a code span, in body or comment" leg_hostile_texts
nmut "the severity shown raw" leg_hostile_texts 's/Severity: \\(\$r\.severity | inert)/Severity: \\($r.severity)/' 1
nmut "the patched text shown raw" leg_hostile_texts 's/Patched: \\(\$r\.patched | inert)/Patched: \\($r.patched)/' 1
nmut "each version shown raw" leg_hostile_texts 's/def versions(\$e): \$e\.versions | map(inert)/def versions($e): $e.versions/' 1
nmut "the body's ref shown raw" leg_hostile_texts 's/"- \\(\.key | inert): /"- \\(.key): /' 1
nmut "a newly affected ref shown raw in the comment" leg_hostile_texts 's/now affected on \\(\$ref | inert)/now affected on \\($ref)/' 1
nmut "a cleared ref shown raw in the comment" leg_hostile_texts 's/cleared on \\(\$ref | inert)/cleared on \\($ref)/' 1
nmut "a moved ref shown raw in the comment" leg_hostile_texts 's/"- on \\(\$ref | inert): now/"- on \\($ref): now/' 1

leg_body_says() {
  local body; two_ref_issue "$1" || return 1
  body="$(jq -r '.[0].body' "$NT/issues.json")"
  has "$body" "\`$ID_A\` ([advisory](https://github.com/advisories/$ID_A)) affects \`pkg-a\`." \
    && has_line "$body" '- Severity: `high`' && has_line "$body" '- Affected range: `>= 1.0.0, < 2.0.0`' \
    && has_line "$body" '- Patched: `patched in "2.0.0"`' && has_line "$body" '- `main`: `1.0.0`' && has_line "$body" '- `dev`: `1.0.0`' \
    && has_line "$body" 'Recorded by audit run https://github.example/o/r/actions/runs/500.' && has "$body" 'This issue closes itself'
}
nleg "the issue names the package, the linked advisory, severity, range, patched text, versions per ref, the run, and that it closes itself" leg_body_says
nmut "the advisory not linked" leg_body_says 's/(if (\$k\.id | ghsa) then/(if false then/' 1
nmut "the severity, range and patched text dropped" leg_body_says 's/(if \$r == null then "Severity/(if true then "Severity/' 1
nmut "the versions per ref dropped" leg_body_says 's/(if \$s == {} then "No audited/(if true then "No audited/' 1
nmut "the recording run dropped" leg_body_says 's/"Recorded by audit run \\(\$run)\."/""/' 1
nmut "the closing sentence dropped" leg_body_says 's/"This issue closes itself[^"]*"/""/' 1

leg_comment_says() {
  two_ref_issue "$1" || return 1
  nt_files; nt_file main audited "$(nt_row 2 pkg-a 1.0.1 $ID_A | rows)"; nt_file dev audited '[]'; nt_file rel audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"
  run_notices "$1" || return 1
  [ "$(jq -r '.body' "$NT/comments" | sed 1d)" = '- cleared on `dev`: no longer reported: the locked version is outside the affected range
- on `main`: now `1.0.1` (was `1.0.0`)
- now affected on `rel` at `1.0.0`' ]
}
nleg "the comment names each ref that is newly affected, cleared or moved" leg_comment_says
nmut "a newly affected ref left out of the comment" leg_comment_says 's/if \$s\[\$ref\] == null then "- now affected/if $s[$ref] == null then empty | "- now affected/' 1
nmut "a moved version left out of the comment" leg_comment_says 's/then "- on \\(\$ref | inert): now/then empty | "- on \\($ref | inert): now/' 1

leg_plan_in_summary() {
  local l s; nt_reset
  nt_file main audited "$( { nt_row 2 pkg-a 1.0.0 $ID_A; nt_row 2 pkg-b 1.0.0 $ID_B; nt_row 2 pkg-c 1.0.0 $ID_C; } | rows)"
  nt_file gone audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"; run_notices "$1" || return 1
  nt_files; nt_file main audited "$( { nt_row 2 pkg-a 1.0.0 $ID_A; nt_row 2 pkg-b 1.0.1 $ID_B; nt_row 2 pkg-e 1.0.0 GHSA-eeee-eeee-eeee; } | rows)"
  nt_file dev error '[]'; run_notices "$1" || return 1
  s="$(cat "$NT/summary")"
  for l in 'refs read: `dev`, `main`' 'NEW: `pkg-e` `GHSA-eeee-eeee-eeee`' "CHANGED: #2 \`pkg-b\` \`$ID_B\`" "CLEARED: #3 \`pkg-c\` \`$ID_C\`" 'unchanged: 1 notice(s)' \
           'ref `dev` did not complete (status `error`): nothing was cleared from it' 'ref `gone` sent no findings file: nothing was cleared from it'; do
    has_line "$s" "$l" || return 1
  done
}
nleg "the plan reaches the summary: refs read, new, changed, cleared, unchanged, and the refs that cleared nothing" leg_plan_in_summary
nmut "the refs read not listed" leg_plan_in_summary 's/read: \[ \$files\[\]\.ref | inert \] | unique,/read: [],/' 1
nmut "the plan not printed" leg_plan_in_summary 's/| while IFS= read -r line; do summary "\$line"; done$/> \/dev\/null/' 1
nmut "an errored ref not reported" leg_plan_in_summary 's/held: \[ (\$files\[\] | select(\.status != "audited")/held: [ ($files[] | select(false)/' 1
nmut "a ref with no file not reported" leg_plan_in_summary 's/| unique - (\$byref | keys))\[\]$/| unique | map(select(false)))[]/' 1

leg_writes_spaced() {
  nt_reset; nt_file main audited "$( { nt_row 2 pkg-a 1.0.0 $ID_A; nt_row 2 pkg-b 1.0.0 $ID_B; } | rows)"
  run_notices "$1" && [ "$(writes)" -eq 3 ] && [ "$(tr '\n' ' ' < "$NT/sleeps")" = '1 1 ' ]
}
nleg "a second passes between one write and the next" leg_writes_spaced
nmut "the gap between writes removed" leg_writes_spaced 's/\[ "\$WRITES" -eq 0 \] || sleep 1/true/' 1

leg_create_refused() {
  local st
  for st in 403 410; do
    nt_reset; nt_file main audited "$( { nt_row 2 pkg-a 1.0.0 $ID_A; nt_row 2 pkg-b 1.0.0 $ID_B; } | rows)"
    ! NT_FAULT_CALL='POST repos/o/r/issues' NT_FAULT_STATUS=$st run_notices "$1" || return 1
    grep -q "NOTICE-WRITE-FAILED.*HTTP $st" "$NT/out" && grep -q '^NEW: ' "$NT/summary" \
      && [ "$(tail -1 "$NT/calls")" = 'POST repos/o/r/issues' ] && [ "$(creates)" -eq 1 ] || return 1
  done
  nt_reset; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"
  ! NT_FAULT_CALL='POST repos/o/r/issues' NT_FAULT_STATUS=none run_notices "$1" \
    && grep -q 'NOTICE-WRITE-FAILED.*no HTTP status' "$NT/out" && [ "$(tail -1 "$NT/calls")" = 'POST repos/o/r/issues' ]
}
nleg "a refused create (403, 410, no status) stops red naming it, after the plan reached the summary" leg_create_refused
nmut "the status ignored" leg_create_refused 's/\[ "\$st" = "\$4" \] || stop/true || stop/' 1

leg_remedy() {
  local st
  for st in 403 410 none 502; do
    nt_reset; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"
    ! NT_FAULT_CALL='POST repos/o/r/issues' NT_FAULT_STATUS=$st run_notices "$1" || return 1
    if [ "$st" = 502 ]; then ! grep -q 'Enable Issues' "$NT/out" || return 1
    else grep -q 'Enable Issues on this repository, or set the Actions variable DEPENDENCY_AUDIT_NOTICES to off' "$NT/out" || return 1; fi
  done
}
nleg "a 403, 410 or no status names the remedy, and another status does not" leg_remedy
nmut "the remedy never named" leg_remedy 's/remedy() { case "\$1" in 403|410|"") /remedy() { case "$1" in x) /' 1
nmut "the remedy always named" leg_remedy 's/remedy() { case "\$1" in 403|410|"") /remedy() { case "$1" in *) /' 1

leg_label_check_fails() {
  nt_reset; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"
  ! NT_FAULT_CALL='GET repos/o/r/labels/*' NT_FAULT_STATUS=500 run_notices "$1" \
    && grep -q 'NOTICE-WRITE-FAILED\] the label check answered HTTP 500' "$NT/out" && [ "$(writes)" -eq 0 ]
}
nleg "a label check that answers neither 200 nor 404 stops the run with zero writes" leg_label_check_fails
nmut "any answer to the label check read as present" leg_label_check_fails 's/^\( *\)\*) stop NOTICE-WRITE-FAILED "the label check answered.*$/\1*) ;;/' 1

leg_label() {
  nt_reset; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"
  run_notices "$1" && [ "$(grep -E ' repos/o/r/(labels|issues$)' "$NT/calls" | tr '\n' '|')" \
    = 'GET repos/o/r/labels/dependency-advisory|POST repos/o/r/labels|POST repos/o/r/issues|' ] || return 1
  nt_reset; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"
  ! NT_FAULT_CALL='POST repos/o/r/labels' NT_FAULT_STATUS=422 run_notices "$1" \
    && grep -q 'NOTICE-WRITE-FAILED.*HTTP 422' "$NT/out" && [ "$(creates)" -eq 0 ]
}
nleg "a missing label is created before the first create, and a 422 on it stops the run" leg_label
nmut "422 accepted as success" leg_label \
  's/^\(  *\)write POST "repos\/\$REPO\/labels" "\$WORK\/req\.json" 201 ;;$/\1st="$(notice_api POST "repos\/$REPO\/labels" "$WORK\/req.json" "$WORK\/resp.json")"; case "$st" in 201|422) ;; *) exit 1 ;; esac ;;/' 1

leg_label_dropped() {
  nt_reset; nt_file main audited "$( { nt_row 2 pkg-a 1.0.0 $ID_A; nt_row 2 pkg-b 1.0.0 $ID_B; } | rows)"
  ! NT_DROP_LABELS=1 run_notices "$1" && grep -q 'NOTICE-LABEL-DROPPED.*#1 ' "$NT/out" && [ "$(creates)" -eq 1 ]
}
nleg "a create whose label was silently dropped stops red, naming the issue" leg_label_dropped
nmut "the label check on the create's response removed" leg_label_dropped \
  's/^\(  *\)jq -e --arg l "\$NOTICE_LABEL" '"'"'any(\.labels\[\]?; \.name == \$l)'"'"' "\$WORK\/resp\.json" > \/dev\/null \\$/\1true \\/' 1

leg_list_page_fails() {
  nt_reset
  jq -n '[range(1; 106) | {number: ., state: "open", body: "not a notice", labels: [{name: "dependency-advisory"}]}]' > "$NT/issues.json"
  nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"
  ! NT_FAULT_CALL='GET repos/o/r/issues?*page=2' NT_FAULT_STATUS=502 run_notices "$1" \
    && grep -q 'NOTICE-LIST-FAILED' "$NT/out" && [ "$(writes)" -eq 0 ]
}
nleg "a list that fails on its second page makes zero writes" leg_list_page_fails
nmut "a failed page read as the end of the list" leg_list_page_fails \
  's/|| stop NOTICE-LIST-FAILED "the issues labelled \$NOTICE_LABEL, page \$page, answered/|| break; : "/' 1

leg_run_list_fails() {
  local st
  for st in 502 none; do
    nt_reset; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"
    ! NT_FAULT_CALL='GET repos/o/r/actions/*' NT_FAULT_STATUS=$st run_notices "$1" \
      && grep -q "NOTICE-LIST-FAILED\] the audit workflow's run list" "$NT/out" && [ "$(writes)" -eq 0 ] || return 1
  done
}
nleg "an unreadable audit run list makes zero writes" leg_run_list_fails
nmut "any status read as a run list" leg_run_list_fails "s/\[ \"\\\$st\" = 200 \] || stop NOTICE-LIST-FAILED \"the audit workflow's run list answered/true || stop NOTICE-LIST-FAILED \"the audit workflow's run list answered/" 1
nmut "no status read as a run list" leg_run_list_fails "s/|| stop NOTICE-LIST-FAILED \"the audit workflow's run list got no/|| echo '{}' > \"\$WORK\/runs.json\"; st=200; : \"/" 1

leg_second_page() {  # the notice is the oldest of more than one page of labelled issues
  nt_reset; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"; run_notices "$1" || return 1
  edit_issues '. + [range(2; 107) | {number: ., state: "open", body: "not a notice", labels: []}]'; calls_since
  run_notices "$1" && [ "$(writes)" -eq 0 ] && grep -q 'page=2$' "$NT/calls"
}
nleg "a notice on the list's second page is found" leg_second_page
nmut "the list read one page deep" leg_second_page "s/^\( *\)\[ \"\\\$(jq 'length' \"\\\$WORK\/page\.json\")\" -eq 100 \] || break$/\1break/" 1

leg_no_input() {
  nt_reset; rm -rf "$NT/find"
  ! run_notices "$1" && grep -q 'NOTICE-NO-INPUT' "$NT/out" && [ "$(writes)" -eq 0 ]
}
nleg "no findings file, and no download directory, is NOTICE-NO-INPUT with zero writes" leg_no_input
nmut "the count taken from the download step's outcome" leg_no_input \
  's/\[ "\$n_files" -gt 0 \] \\$/[ "${DOWNLOAD_OUTCOME:-success}" = success ] \\/' 1

leg_schema() {
  nt_reset; nt_file dev audited "$(nt_row 2 pkg-b 1.0.0 $ID_B | rows)"; run_notices "$1" || return 1
  nt_files; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"; nt_file dev audited '[]'
  jq 'del(.schema)' "$NT/find/dependency-audit-findings-dev/dependency-audit-findings.json" > "$NT/x.json" \
    && mv "$NT/x.json" "$NT/find/dependency-audit-findings-dev/dependency-audit-findings.json"
  calls_since
  ! run_notices "$1" && grep -q 'NOTICE-SCHEMA' "$NT/summary" && [ "$(creates)" -eq 1 ] \
    && ! grep -q 'issues/1' "$NT/calls" && iq '.[0].state == "open"'
}
nleg "a file of another schema clears nothing, the valid ref is reconciled, and the run reds" leg_schema
nmut "the schema check removed" leg_schema "s/if jq -e '\.schema == 1' \"\$f\"/if jq -e 'true' \"\$f\"/" 1

leg_unkeyable_row() {
  nt_reset; nt_file main audited "$( { nt_row 2 pkg-a 1.0.0 $ID_A; nt_row 2 pkg-x 1.0.0 ''; } | rows)"
  ! run_notices "$1" && [ "$(creates)" -eq 1 ] && grep -q 'NOTICE-ROW-UNKEYABLE.*pkg-x' "$NT/summary"
}
nleg "a row with no advisory ID reds the run, and the rest of its ref is still reconciled" leg_unkeyable_row
nmut "the whole ref dropped for one unkeyable row" leg_unkeyable_row \
  's/| \[ \$files\[\] as \$f | (\$f\.affected/| [ $files[] as $f | select(all(($f.affected \/\/ [])[]; (.ghsa \/\/ "") != "")) | ($f.affected/' 1
nmut "an unkeyable row skipped silently" leg_unkeyable_row \
  "s/\[ \"\\\$(jq '\.unkeyable | length' \"\\\$WORK\/plan\.json\")\" -eq 0 \] || RED_AFTER=1/true/" 1

leg_switch_values() {
  local v
  for v in ON true offf; do
    nt_reset; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"
    NT_SWITCH=$v run_notices "$1" && [ "$(creates)" -eq 1 ] || return 1
    if [ "$v" = ON ]; then ! grep -q WARNING "$NT/summary" || return 1
    else grep -q "WARNING: DEPENDENCY_AUDIT_NOTICES is '$v'" "$NT/summary" || return 1; fi
  done
}
nleg "the switch at ON, true or a misspelling runs, warning for the last two" leg_switch_values
nmut "an unknown switch value reds the block" leg_switch_values 's/^\( *\)\*) summary "WARNING: /\1*) exit 1; summary "WARNING: /' 1

leg_newer_audit() {
  nt_reset; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"
  jq '.workflow_runs += [{id: 600, status: "completed", conclusion: "success", event: "workflow_dispatch", head_branch: "main"}]' \
    "$NT/runs.json" > "$NT/x.json" && mv "$NT/x.json" "$NT/runs.json"
  run_notices "$1" && [ "$(writes)" -eq 0 ] && grep -q 'newer audit run, 600' "$NT/summary"
}
nleg "a newer completed audit run means zero writes, green, naming it" leg_newer_audit
nmut "the freshness read removed" leg_newer_audit 's/^if \[ -n "\$newer" \]; then$/if false; then/' 1

# trusted_latest_audit over a run list whose newer runs are each untrusted, IDs out of list order.
leg_trusted_latest() {
  local def got
  def="$(sed -n "/^TRUSTED_LATEST_AUDIT_JQ='/,/'\$/p" <<< "$1")"
  got="$(eval "$def"; jq -r --arg b main "$TRUSTED_LATEST_AUDIT_JQ"' trusted_latest_audit($b) | .id' <<< '{"workflow_runs":[
    {"id":505,"status":"completed","conclusion":"success","event":"workflow_dispatch","head_branch":"main"},
    {"id":520,"status":"completed","conclusion":"cancelled","event":"schedule","head_branch":"main"},
    {"id":510,"status":"completed","conclusion":"failure","event":"schedule","head_branch":"main"},
    {"id":530,"status":"completed","conclusion":"failure","event":"workflow_dispatch","head_branch":"dev"},
    {"id":540,"status":"completed","conclusion":"failure","event":"pull_request","head_branch":"main"},
    {"id":550,"status":"in_progress","conclusion":null,"event":"schedule","head_branch":"main"}]}' 2>/dev/null)"
  [ "$got" = 510 ]
}
nleg "trusted_latest_audit takes the greatest ID among completed, non-cancelled, scheduled or dispatched default-branch runs" leg_trusted_latest
nmut "the cancelled exclusion removed" leg_trusted_latest 's/| select(\.status == "completed" and \.conclusion != "cancelled")/| select(.status == "completed")/' 1
nmut "the completed requirement removed" leg_trusted_latest 's/| select(\.status == "completed" and \.conclusion != "cancelled")/| select(.conclusion != "cancelled")/' 1
nmut "the event test removed" leg_trusted_latest 's/| select(\.event == "schedule" or \.event == "workflow_dispatch")/| select(true)/' 1
nmut "the branch test removed" leg_trusted_latest 's/| select(\.head_branch == \$branch) \]/| select(true) ]/' 1
nmut "max_by(.id) replaced by the first element" leg_trusted_latest 's/| max_by(\.id);/| first;/' 1

leg_creates_before_updates() {
  nt_reset; nt_file main audited "$(nt_row 2 pkg-a 1.0.0 $ID_A | rows)"; run_notices "$1" || return 1
  nt_files; nt_file main audited "$( { nt_row 2 pkg-a 1.0.1 $ID_A; nt_row 2 pkg-b 1.0.0 $ID_B; nt_row 2 pkg-c 1.0.0 $ID_C; } | rows)"; calls_since
  ! NT_FAULT_CALL='POST repos/o/r/issues/1/comments' NT_FAULT_STATUS=403 run_notices "$1" \
    && [ "$(creates)" -eq 2 ] && [ "$(tail -1 "$NT/calls")" = 'POST repos/o/r/issues/1/comments' ]
}
nleg "every create is applied before a refused update" leg_creates_before_updates
nmut "updates applied first" leg_creates_before_updates "s/jq -c '(\.creates + \.updates)\[\]'/jq -c '(.updates + .creates)[]'/" 1

# The issues list returns pull requests too; a labelled PR carrying a notice's state line is not a
# notice, so its recorded refs must not be reconciled against.
leg_pull_request_ignored() {
  nt_reset
  jq -n --arg b '<!-- dependency-audit-notice {"id":"GHSA-aaaa-aaaa-aaaa","package":"pkg-a","refs":{"main":{"versions":["1.0.0"],"sources":["2"]}}} -->' \
    '[{number: 1, state: "open", body: $b, pull_request: {url: "x"}, labels: [{name: "dependency-advisory"}]}]' > "$NT/issues.json"
  nt_file main audited '[]'
  run_notices "$1" && [ "$(writes)" -eq 0 ]
}
nleg "a labelled pull request carrying a state line is not read as a notice" leg_pull_request_ignored
nmut "pull requests kept in the issue list" leg_pull_request_ignored 's/| select(has("pull_request") | not) |/| select(true) |/' 1

# THE HOP the notices workflow depends on: the file the SHIPPED callee writes is the file the
# shipped notices block reads. The callee runs on the affected fixture (four source-2 findings, one
# package that does not resolve), and its file is handed to the notices block as the download would.
leg_callee_to_notices() {  # $1 = the callee's blocks
  local d="$NT/find/dependency-audit-findings-fixture-ref"
  nt_reset
  run_audit "$AFFECTED" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE")" "" "$1" > /dev/null || true
  [ -s "$TMP/findings.json" ] && jq -e '.schema == 1 and .ref == "fixture-ref" and .status == "audited"
      and (.affected | length) == 4 and .unresolved == ["vendor/no-source-url"]' "$TMP/findings.json" > /dev/null \
    && grep -qx 'findings_artifact=dependency-audit-findings-fixture-ref' "$TMP/gh_output" || return 1
  mkdir -p "$d"; cp "$TMP/findings.json" "$d/dependency-audit-findings.json"
  run_notices "$NOTICES_BLOCK" && [ "$(creates)" -eq 4 ] || return 1
  # A run that dies before emit_outputs (the scanner seam exits the shell) leaves no file.
  run_audit "$AFFECTED" "$(seam 'exit 9;' "$ADV_FIXTURE" "$REG_NONE")" "" "$1" > /dev/null || true
  [ ! -e "$TMP/findings.json" ]
}
nleg "the callee's findings file is read by the notices block, and a run stopped before its verdict leaves none" leg_callee_to_notices "$BLOCKS"
nmut "the findings file written before the sources run" leg_callee_to_notices \
  's/^discover_manifests$/export_findings audited "[]" "[]"; discover_manifests/' 1 "$BLOCKS"
nmut "the packages source 2 could not resolve left out of the file" leg_callee_to_notices \
  's/^\( *\)if \[ -f "\$WORK\/unresolved\.tsv" \]; then unresolved=.*$/\1:/' 1 "$BLOCKS"

leg_export_error_and_lists() {  # $1 = the callee's blocks: a failed source, and one accepted and one withdrawn ID
  run_audit "$AFFECTED" "$(seam 'return 127;' "$ADV_FIXTURE" "$REG_NONE")" "" \
    "$(sed "s/^emit_outputs$/ACCEPTED_IDS=$ID_A; WITHDRAWN_IDS=$ID_B; emit_outputs/" <<< "$1")" > /dev/null || true
  jq -e --arg a $ID_A --arg w $ID_B '.status == "error" and .accepted == [$a] and .withdrawn == [$w]' "$TMP/findings.json" > /dev/null
}
nleg "the findings file carries the error status and the accepted and withdrawn IDs" leg_export_error_and_lists "$BLOCKS"
nmut "the status written as audited" leg_export_error_and_lists \
  's/export_findings "\$status" "\$withdrawn" "\$accepted"/export_findings audited "$withdrawn" "$accepted"/' 1 "$BLOCKS"
nmut "the accepted and withdrawn lists exchanged" leg_export_error_and_lists \
  's/export_findings "\$status" "\$withdrawn" "\$accepted"/export_findings "$status" "$accepted" "$withdrawn"/' 1 "$BLOCKS"

leg_artifact_name() {
  AUDIT_REF_UNDER_TEST='rel/1.x+a_b-c' run_audit "$AFFECTED" "$(seam "$OSV_CLEAN" "$ADV_FIXTURE" "$REG_NONE")" "" "$1" > /dev/null || true
  grep -qx 'findings_artifact=dependency-audit-findings-rel_1.x_a_b-c' "$TMP/gh_output"
}
nleg "the artifact name carries the ref with every other character written as _" leg_artifact_name "$BLOCKS"
nmut "the ref put in the artifact name as written" leg_artifact_name "s/ | tr -c 'A-Za-z0-9._-' '_')\"$/)\"/" 1 "$BLOCKS"

extract_parsed "$CALLEE" "dep-audit-upload-failed" callee
UPLOAD_FAILED_BLOCK="$BODY"
leg_upload_failed() {  # $1 = the block
  : > "$TMP/step_summary"
  AUDIT_REF=main AUDIT_OUTCOME=success GITHUB_STEP_SUMMARY="$TMP/step_summary" bash -c "set -euo pipefail
$1" > "$TMP/upload_out" || return 1
  grep -q '^::warning .*FINDINGS-UPLOAD-FAILED: ref main: ' "$TMP/upload_out" \
    && grep -q "FINDINGS-UPLOAD-FAILED: ref main: .*audit itself concluded 'success'" "$TMP/step_summary"
}
nleg "a failed findings upload is named in the summary and as a warning, with what the audit concluded" leg_upload_failed "$UPLOAD_FAILED_BLOCK"
nmut "the failed upload not in the summary" leg_upload_failed 's/>> "\$GITHUB_STEP_SUMMARY"/> \/dev\/null/' 1 "$UPLOAD_FAILED_BLOCK"
[ ! -s "$TMP/swallowed" ] \
  && ok "every callee run above that printed an AUDIT-ERROR also exported it" \
  || fail "a callee run above printed an AUDIT-ERROR its population did not record: $(tr '\n' ';' < "$TMP/swallowed")"

# Every leg and mutant declared above ran: one this file declares and nobody ran is a check that
# cannot fail.
declared="$(grep -cE '^(nleg|nmut) ' "${BASH_SOURCE[0]}")"
[ "$N_RAN" -eq "$declared" ] \
  && ok "notices: every declared leg and mutant ran ($N_RAN, re-counted from this file)" \
  || fail "notices: $N_RAN of the $declared declared legs and mutants ran"

finish

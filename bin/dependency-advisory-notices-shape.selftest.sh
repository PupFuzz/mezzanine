#!/usr/bin/env bash
# ── VENDORED from coord 0.79.0 — the selftest of the advisory-notices workflow's YAML shape. A body edit reds bin/vendor-pin-check.sh until declared below. ──
#
#   upstream repo     PupFuzz/agent-board-framework  (PRIVATE — a public CI runner cannot clone it)
#   upstream path     plugins/coord/templates/security/dependency-advisory-notices-shape.selftest.sh
#   vendored from     31191341a2176a81f5e15b6d72e80282a2df168c   (marketplace, the v0.79.0 release merge)
#   plugin version    coord 0.79.0
#   upstream sha256   2e2ba50b489ff10be8c477e3f136c9cfd0deb75640ff70108751763f11a09e68
#
# That sha256 is the WHOLE upstream file; `bin/vendor-pin-check.sh` pins the BODY (from the first
# line that is neither blank nor a comment), which is a different figure. Re-derive this one
# against a marketplace clone:   git show 31191341:plugins/coord/templates/security/dependency-advisory-notices-shape.selftest.sh | sha256sum
#
# DECLARED LOCAL EDITS — none. The text below this block was upstream's byte for byte when vendored
# (checked by diff against the commit above); the body pin holds it there.
#
# RUN IT WITH THIS REPOSITORY'S WORKFLOW DIRECTORY, which is not where upstream's default looks:
#     bash bin/dependency-advisory-notices-shape.selftest.sh --workflows .github/workflows
# That is what `.github/workflows/dependency-audit-selftest.yml` runs. With no argument the suite
# looks for `bin/workflows/`, finds nothing, and FAILS naming the path it tried.
# ShellCheck's findings on this file are recorded in bin/shell-lint.baseline.tsv rather than
# annotated in the body, so the body stays upstream's.
#
# TO RE-VENDOR: this file moves with `bin/dependency-audit.selftest.sh`, whose header names the
# whole set and the procedure.
# ── END MEZZANINE VENDOR HEADER ────────────────────────────────────────────────────────────────
# dependency-advisory-notices-shape.selftest.sh — reads the shipped notices YAML, and what the callee
# and caller carry for it. What it checks is the labels of its `nshape` lines: each is a check the
# shipped files pass and a named rewrite of one of them that the check must refuse. The workflow's
# shell is `dependency-advisory-notices.selftest.sh`'s.
set -uo pipefail

SUITE=dependency-advisory-notices-shape
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$HERE/dependency-selftest-lib.sh" || { echo "FAIL: dependency-selftest-lib.sh is not beside this selftest, so no row ran"; exit 1; }
need_workflows "$NOTICES" "$CALLER"
check_workflows_input dependency-audit.yml
check_workflows_input dependency-ref-matrix.yml
check_workflows_input dependency-advisory-notices.yml
N_RAN=0

# Each check takes the notices, callee and caller texts and prints one line per violation.
SHIPPED_JOB_IF="(github.event.workflow_run.event == 'schedule' || github.event.workflow_run.event == 'workflow_dispatch') && github.event.workflow_run.head_branch == github.ref_name && github.event.workflow_run.conclusion != 'cancelled' && vars.DEPENDENCY_AUDIT_NOTICES != 'off'"
job_ifs() {  # $1 = notices text -> one line per job-level `if:` of the notices job, its value collapsed
  awk '/^  notices:/ { j = 1; next }
       j && /^  [^ #]/ { j = 0 }
       f && /^      / { v = v " " $0; next }
       f { print v; f = 0 }
       j && /^    if:/ { f = 1; v = $0; sub(/^    if:[[:space:]]*(>-)?/, "", v) }
       END { if (f) print v }' <<< "$1" | tr -s ' ' | sed 's/^ //; s/ $//'
}
chk_perms() {
  local got; got="$(awk "$GRANTS_AWK" <<< "$1")"
  [ "$got" = $'notices\tactions: read, issues: write' ] \
    || echo "the notices workflow grants '$(tr '\t\n' ' ;' <<< "$got")', not exactly actions: read and issues: write on its one job"
}
chk_name_coupling() {
  local want got
  want="$(sed -n 's/^name: //p' <<< "$3")"; got="$(sed -n 's/^    workflows: \["\(.*\)"\].*/\1/p' <<< "$1")"
  [ -n "$want" ] && [ "$want" = "$got" ] || echo "the notices workflow listens for '$got', but the caller is named '$want'"
}
chk_trust_twice() {
  local if_text filter a b
  if_text="$(job_ifs "$1")"; filter="$(sed -n "/^ *TRUSTED_LATEST_AUDIT_JQ='/,/'\$/p" <<< "$1")"
  a="$(grep -o "workflow_run\.event == '[a-z_]*'" <<< "$if_text" | sed "s/.*'\(.*\)'/\1/" | sort | tr '\n' ' ')"
  b="$(grep -o '\.event == "[a-z_]*"' <<< "$filter" | sed 's/.*"\(.*\)"/\1/' | sort | tr '\n' ' ')"
  [ -n "$a" ] && [ "$a" = "$b" ] || echo "the job's if: trusts events '$a', trusted_latest_audit trusts '$b'"
  has "$if_text" 'workflow_run.head_branch == github.ref_name' && has "$filter" '.head_branch == $branch' \
    || echo "the default-branch test is not in both the if: and trusted_latest_audit"
  has "$if_text" "workflow_run.conclusion != 'cancelled'" && has "$filter" '.conclusion != "cancelled"' \
    || echo "the cancelled exclusion is not in both the if: and trusted_latest_audit"
  return 0
}
chk_no_wrapper() { has "$(job_ifs "$1")" '${{' && echo "the notices job's if: carries the \${{ }} wrapper"; return 0; }
chk_if_literal() {
  local ifs; ifs="$(job_ifs "$1")"
  [ "$(grep -c . <<< "$ifs")" -eq 1 ] && [ "$ifs" = "$SHIPPED_JOB_IF" ] \
    || echo "the notices job's if: is not the one shipped expression: '$(tr '\n' '|' <<< "$ifs")'"
}
chk_run_name() {
  case "$(sed -n 's/^run-name: //p' <<< "$1")" in
    *'${{ github.event.workflow_run.id }}'*) ;;
    *) echo "run-name does not carry the triggering run's ID" ;;
  esac
}
step_text() {  # $1 = workflow text, $2 = step name -> that step's lines, comments above the next step included
  awk -v want="      - name: $2" '$0 == want { s = 1; print; next } s && /^      - / { s = 0 } s' <<< "$1"
}
SHIPPED_UPLOAD_IF="!cancelled() && steps.audit.outputs.findings_artifact != '' && vars.DEPENDENCY_AUDIT_NOTICES != 'off' && (github.server_url == 'https://github.com' || endsWith(github.server_url, '.ghe.com'))"
chk_upload() {
  local step ifs; step="$(step_text "$2" 'Upload the findings file for the notices workflow')"
  has "$step" 'retention-days: 1' || echo "the findings upload has no retention-days: 1"
  has "$step" '# upload-reason:' || echo "the findings upload has no # upload-reason:"
  has "$step" 'overwrite: true' || echo "the findings upload has no overwrite: true"
  ifs="$(sed -n '/^        if: >-$/,/^        uses:/p' <<< "$step" | sed '1d;$d' | tr -s ' \n' ' ' | sed 's/^ //; s/ $//')"
  [ "$ifs" = "$SHIPPED_UPLOAD_IF" ] || echo "the findings upload's if: is not the one shipped expression: '$ifs'"
}
chk_upload_report() {
  local step; step="$(step_text "$2" 'Report a failed findings upload')"
  has_line "$step" "        if: \${{ !cancelled() && steps.upload-findings.outcome == 'failure' }}" \
    && has_line "$2" '        id: upload-findings' || echo "a failed findings upload is not reported"
  has "$step" 'AUDIT_OUTCOME: ${{ steps.audit.outcome }}' || echo "the failed-upload report is not given the audit's own outcome"
}
chk_concurrency() {
  local c; c="$(awk '/^concurrency:/ { c = 1; next } c && /^[^ ]/ { c = 0 } c' <<< "$1")"
  local group; group="$(sed -n 's/^  group: //p' <<< "$c")"
  [ -n "$group" ] && ! has "$group" '$' || echo "the concurrency group is missing or not a fixed key"
  c=$'\n'"$c"$'\n'
  has "$c" $'\n  cancel-in-progress: false\n' || echo "the concurrency group can cancel"
  has "$c" $'\n  queue: max\n' || echo "the concurrency group has no queue: max"
  has "$c" $'\n  # supersession-exempt: ' || echo "the supersession-exempt marker is not on a line of its own inside the mapping"
  return 0
}
chk_label_once() {
  local defs inside
  defs="$(grep -c 'NOTICE_LABEL="' <<< "$1")"
  inside="$(sed -n '/CUSTOMIZE-BEGIN notice-seams/,/CUSTOMIZE-END notice-seams/p' <<< "$1" | grep -c 'NOTICE_LABEL="')"
  [ "$defs" -eq 1 ] && [ "$inside" -eq 0 ] || echo "the label is defined $defs time(s), $inside inside the CUSTOMIZE markers"
}
nshape() {  # $1=label $2=check $3=NOTICES|CALLEE|CALLER $4=sed rewrite: the shipped files pass, the rewrite is refused
  local n c e v
  N_RAN=$((N_RAN + 1))
  n="$(cat "$NOTICES")"; c="$(cat "$CALLEE")"; e="$(cat "$CALLER")"
  v="$("$2" "$n" "$c" "$e")"
  if [ -n "$v" ]; then fail "shape: $1 — the SHIPPED files fail it: $v"; return 0; fi
  case "$3" in
    NOTICES) v="$(sed "$4" <<< "$n")"; [ "$v" != "$n" ] && n="$v" ;;
    CALLEE)  v="$(sed "$4" <<< "$c")"; [ "$v" != "$c" ] && c="$v" ;;
    CALLER)  v="$(sed "$4" <<< "$e")"; [ "$v" != "$e" ] && e="$v" ;;
  esac || { fail "shape: $1 — the rewrite changed nothing, so it tests nothing"; return 0; }
  v="$("$2" "$n" "$c" "$e")"
  [ -n "$v" ] && ok "shape: $1 (rewrite refused: $(head -1 <<< "$v"))" || fail "shape: $1 — the rewrite was NOT refused"
}
nshape "the notices job grants exactly actions: read and issues: write (rewrite: contents: write added)" chk_perms NOTICES \
  's/^      issues: write$/&\n      contents: write/'
nshape "the notices job grants exactly actions: read and issues: write (rewrite: the job given write-all)" chk_perms NOTICES \
  '/^      actions: read$/d; /^      issues: write$/d; s/^    permissions:$/    permissions: write-all/'
nshape "no top-level permissions: in the notices workflow (rewrite: a top-level block added)" chk_perms NOTICES \
  's/^jobs:$/permissions:\n  issues: write\n&/'
nshape "the notices workflow listens for the caller's name (rewrite: the caller renamed)" chk_name_coupling CALLER \
  's/^name: .*$/& renamed/'
nshape "the trust rule is the same in the if: and trusted_latest_audit (rewrite: an event added to the if: only)" chk_trust_twice NOTICES \
  "s/^       || github.event.workflow_run.event == 'workflow_dispatch')$/       || github.event.workflow_run.event == 'workflow_dispatch' || github.event.workflow_run.event == 'push')/"
nshape "the trust rule is the same in the if: and trusted_latest_audit (rewrite: the cancelled clause dropped from the filter only)" chk_trust_twice NOTICES \
  's/| select(\.status == "completed" and \.conclusion != "cancelled")/| select(.status == "completed")/'
nshape "the job's if: carries no \${{ wrapper (rewrite: the wrapper put back)" chk_no_wrapper NOTICES \
  's/^      (github\.event\.workflow_run\.event == .schedule.$/      ${{ &/; s/^      && vars\.DEPENDENCY_AUDIT_NOTICES != .off.$/& }}/'
nshape "the job has one if:, the shipped expression (rewrite: the parentheses stripped)" chk_if_literal NOTICES \
  "s/^      (github.event.workflow_run.event == 'schedule'$/      github.event.workflow_run.event == 'schedule'/; s/^       || github.event.workflow_run.event == 'workflow_dispatch')$/       || github.event.workflow_run.event == 'workflow_dispatch'/"
nshape "the job has one if:, the shipped expression (rewrite: the event clause removed)" chk_if_literal NOTICES \
  "/^      (github.event.workflow_run.event == 'schedule'$/,/^       || github.event.workflow_run.event == 'workflow_dispatch')$/d; s/^      && github.event.workflow_run.head_branch/      github.event.workflow_run.head_branch/"
nshape "the job has one if:, the shipped expression (rewrite: the default-branch clause removed)" chk_if_literal NOTICES \
  '/^      && github\.event\.workflow_run\.head_branch == github\.ref_name$/d'
nshape "the job has one if:, the shipped expression (rewrite: the cancelled clause removed)" chk_if_literal NOTICES \
  "/^      && github.event.workflow_run.conclusion != 'cancelled'$/d"
nshape "the job has one if:, the shipped expression (rewrite: the off test moved into a step)" chk_if_literal NOTICES \
  "/^      && vars.DEPENDENCY_AUDIT_NOTICES != 'off'$/d; s/^      - name: Reconcile one issue per advisory$/&\n        if: \${{ vars.DEPENDENCY_AUDIT_NOTICES != 'off' }}/"
nshape "run-name carries the triggering run's ID (rewrite: the line removed)" chk_run_name NOTICES '/^run-name:/d'
nshape "the findings upload keeps retention-days: 1 (rewrite: removed)" chk_upload CALLEE '/^          retention-days: 1$/d'
nshape "the findings upload keeps its # upload-reason: (rewrite: removed)" chk_upload CALLEE '/^        # upload-reason: dependency-advisory-notices/d'
nshape "the findings upload keeps overwrite: true (rewrite: removed)" chk_upload CALLEE '/^          overwrite: true$/d'
nshape "the findings upload's if: is the shipped expression (rewrite: !cancelled() removed)" chk_upload CALLEE \
  '/^          !cancelled()$/d; s/^          && steps\.audit\.outputs/          steps.audit.outputs/'
nshape "the findings upload's if: is the shipped expression (rewrite: the off switch removed)" chk_upload CALLEE \
  "/^          && vars.DEPENDENCY_AUDIT_NOTICES != 'off'$/d"
nshape "the findings upload's if: is the shipped expression (rewrite: the host test removed)" chk_upload CALLEE \
  '/^          && (github\.server_url == /d'
nshape "a failed findings upload is reported (rewrite: the upload step's id removed)" chk_upload_report CALLEE '/^        id: upload-findings$/d'
nshape "a failed findings upload is reported with the audit's outcome (rewrite: removed)" chk_upload_report CALLEE '/^          AUDIT_OUTCOME: /d'
nshape "the concurrency group keeps its marker on a line of its own (rewrite: moved to a trailing comment)" chk_concurrency NOTICES \
  '/^  # supersession-exempt: /d; s/^  group: dependency-advisory-notices$/&  # supersession-exempt: one reconcile at a time/'
nshape "the concurrency group keeps queue: max (rewrite: removed)" chk_concurrency NOTICES '/^  queue: max$/d'
nshape "the label is defined once, outside the CUSTOMIZE markers (rewrite: moved inside them)" chk_label_once NOTICES \
  '/^          NOTICE_LABEL="dependency-advisory"$/d; s/^          # CUSTOMIZE-END notice-seams$/          NOTICE_LABEL="dependency-advisory"\n&/'

# Every row declared above ran: one this file declares and nobody ran is a check that cannot fail.
declared="$(grep -cE '^nshape ' "${BASH_SOURCE[0]}")"
[ "$N_RAN" -eq "$declared" ] \
  && ok "shape: every declared row ran ($N_RAN, re-counted from this file)" \
  || fail "shape: $N_RAN of the $declared declared rows ran"

finish

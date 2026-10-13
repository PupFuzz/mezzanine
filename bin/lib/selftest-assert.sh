#!/usr/bin/env bash
# selftest-assert.sh — the assertion basics this repo's bash selftests share (card#11562). SOURCED, never
# run: bin/deploy.selftest.sh and bin/deploy-gate-inputs.selftest.sh each source it before their first case.
#
# WHY IT IS ONE FILE. Each suite used to define its own `ok`, `bad`, `eq`, `has` … and the copies drifted:
# card#11557 gave bin/deploy.selftest.sh `exits`, which prints a failed run's output, and the other suite kept
# a bare `eq … "$RC"` whose red named no gate — so its one flaky exit 1 could only be rerun, never read
# (card#11562). The next suite sources this file and starts with what both have.
#
# WHAT THE CALLER OWNS. The summary line and the exit code (each suite names itself in it), and every
# assertion that reads that suite's own state (deploy.selftest.sh's call log, for one). The CI lanes read
# two things these suites print: a `^  FAIL ` line per failed assertion (`bad`, below) and the caller's
# `N assertions, M FAILED` summary. `exits` prints a failed run's output with every line prefixed
# (`         ┆ `), so no line of it can be read as a `^  FAIL ` line. The summary match in
# deploy-selftest.yml is anchored only at the line's END, so a run that itself printed a line ending
# `assertions, N FAILED` would satisfy it from inside that output; none of the scripts those suites run
# (deploy.sh, deploy-gate-inputs.sh, supervision.sh) prints one.
#
# ⛔ SOURCE IT FAIL-CLOSED. A suite that could not load this file has no assertions; run anyway, it prints a
# summary of zero cases. Each caller sources it with `|| exit 1` and then checks the functions below are
# defined, so a missing, unreadable or half-written copy stops the suite before its first case.

fails=0; cases=0; unverified=0
ok()  { printf '  ok   %s\n' "$1"; }
bad() { printf '  FAIL %s\n' "$1" >&2; fails=$((fails + 1)); }
# notverified <headline> [detail line…] — a CONDITION THIS RUNNER COULD NOT PRODUCE. Not a pass and not a
# failure: the case did not run, and saying so BY NAME is the result (card#9646, card#10368). Each caller
# names what it needed and what this runner answered instead; its summary repeats the count.
notverified() {
  unverified=$((unverified + 1))
  printf '  ⚠ NOT VERIFIED HERE  %s\n' "$1" >&2
  local l; for l in "${@:2}"; do printf '                       %s\n' "$l" >&2; done
}
# The assertions branch rather than chaining `A && ok || bad`: in that chain the reporter's OWN exit status
# is a second way to reach `bad`, so a printf that fails (a closed or full stdout under a CI lane's
# redirection) turns a case that PASSED into a FAIL, for a reason that is not about the check.
eq()  { cases=$((cases+1)); if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 — expected '$2', got '$3'"; fi; }
# exits <label> <expected> [status] [output] — THE exit-code assertion: <status> (default $RC) is <expected>, and on
# a mismatch everything <output> holds (default $OUT — what the same run printed, stdout and stderr together) is
# printed under the FAIL line (card#11557). A status alone cannot say which gate returned it: deploy.sh's 2 is every
# in-window failure there is, and deploy-gate-inputs.sh's 1 is every gate that refused.
# ⚠ THE WHOLE OUTPUT, NOT ITS TAIL: the in-window banner (in_window_failure) is itself longer than a short tail, and
# the line naming the gate that failed is printed just above it — measured, a 20-line tail showed the banner and
# not the cause. A red is the only time this prints, and it is the time the whole of it is wanted.
# Every site that captures a status captures the run's output beside it, so the output is always the run the
# status came from.
# ⛔ IT PRINTS WHATEVER IT IS HANDED. A caller hands it only the output of runs over fixtures it built, and its
# own header says why nothing a credential's value can reach is in that output.
exits() {
  local label="$1" want="$2" got="${3-$RC}" out="${4-$OUT}"
  cases=$((cases+1))
  if [ "$want" = "$got" ]; then ok "$label"; return 0; fi
  bad "$label — expected '$want', got '$got'"
  printf '         ┆ what it printed:\n' >&2
  printf '%s\n' "$out" | sed 's/^/         ┆ /' >&2
}
has() { cases=$((cases+1)); case "$3" in *"$2"*) ok "$1" ;; *) bad "$1 — output did not contain '$2'" ;; esac; }
hasnt() { cases=$((cases+1)); case "$3" in *"$2"*) bad "$1 — output unexpectedly contained '$2'" ;; *) ok "$1" ;; esac; }
section() { printf '\n── %s\n' "$1"; }

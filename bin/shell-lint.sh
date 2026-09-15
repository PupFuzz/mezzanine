#!/usr/bin/env bash
#
# shell-lint.sh — run ShellCheck over this repo's shell scripts and red on findings the
# baseline does not already carry. Local sweep and CI lane run THIS file, so there is one
# definition of what "the shell lint" means here (card#9635).
#
# WHY IT EXISTS — both halves of the defect it closes.
#
#   1. DISCOVERABILITY. A pinned ShellCheck has been on this box the whole time, installed as
#      `~/.local/bin/_shellcheck-pinned` (a symlink into `agent-board-toolkit`, version pinned by
#      that repo's `.shellcheck-version`). `which shellcheck` finds NOTHING, and the underscore
#      prefix reads as private, so three separate rounds concluded the tool was absent and skipped
#      the lint. A tool that only answers to a name nobody tries is not installed in any sense that
#      matters. This script tries that name, so no round has to know it.
#   2. NO LANE. `shellcheck` appeared nowhere under `.github/`, so nothing ran it on a PR either.
#      `.github/workflows/shell-lint.yml` runs this script; fixing only half 1 would help the rounds
#      that remember to look, which is the same failure with better odds.
#
#   The cost while both halves were open: `bin/deploy.sh` — the script that deploys production —
#   grew by roughly 400 lines in one cycle with no analyser ever run over it.
#
# USAGE
#
#   bin/shell-lint.sh                  lint the population, compare against the baseline,
#                                      exit 1 if any (file, code) class exceeds its baseline count
#   bin/shell-lint.sh --all            print every current finding, baselined ones included; exit 0
#   bin/shell-lint.sh --list           print the file population and exit
#   bin/shell-lint.sh --update-baseline  rewrite bin/shell-lint.baseline.tsv from the current tree
#
#   Exit codes: 0 clean · 1 new findings · 2 the lint could not be run (missing or wrong analyser,
#   bad usage). A 2 is never a pass.
#
# THE FILE POPULATION IS DERIVED, NEVER LISTED. Every tracked file that is named `*.sh` OR whose
# first line is a `sh`/`bash`/`dash`/`ksh` shebang. The shebang leg is load-bearing: `bin/promote-
# cards-by-token` is a 1.4k-line bash script with no extension, and a `bin/*.sh` glob would have
# missed it silently. A written count would be stale the next time a script lands, so this file
# carries none — run `--list` to print today's set.
#
# WHAT THE BASELINE IS, AND IS NOT. `bin/shell-lint.baseline.tsv` records, per (file, ShellCheck
# code), how many findings that pair had when the lane landed. It is a DEBT LEDGER, not an approval:
# nothing in it has been judged correct, and the lane's whole job is that the NEXT one reds. Keyed by
# count rather than by line number deliberately — line numbers move under every edit to the file
# above them, and a line-keyed baseline would red on PRs that changed nothing about the finding.
# The tradeoff is stated plainly: removing one finding and adding another of the same code in the
# same file nets to zero and passes. Fixing the baselined findings is separate, reviewed work — a
# lint fix inside `bin/deploy.sh` is a behaviour change on the production deploy path.
#
# A count that DROPS is reported but does not red. Cleanup PRs should not have to argue with the
# gate; the report says which pair improved and asks for `--update-baseline`, which is how the
# ledger ratchets down.
#
# THE ANALYSER VERSION IS ASSERTED, NOT ACCEPTED. Two ShellCheck versions genuinely disagree about
# the same file (the reasoning and a worked example are in agent-board-toolkit's
# `.shellcheck-version`), so a baseline is meaningful only against a named version. This script
# refuses to run under any other one rather than emit a verdict from an unnamed program. When the
# runner image moves and the assert reds, the fix is to move the pin DELIBERATELY — run both
# versions over the population and diff the findings first — not to relax the assert.
#
set -euo pipefail

# The version this repo's baseline was measured under. `agent-board-toolkit/.shellcheck-version` is
# the upstream pin; that file lives outside this repo and CI cannot read it, so the value is
# restated here and CROSS-CHECKED against it whenever it IS readable (see assert_version below) —
# a guard rather than a silent copy.
EXPECTED_VERSION='0.9.0'
TOOLKIT_PIN="${SHELL_LINT_TOOLKIT_PIN:-${HOME}/agent-board-toolkit/.shellcheck-version}"

die() { printf 'shell-lint: %s\n' "$*" >&2; exit 2; }

# Resolve the analyser. $SHELLCHECK wins; then the pinned build this box actually has; then PATH
# (which is what a CI runner offers). Whatever is chosen is PRINTED, so a log never has to guess
# which program produced the verdict.
resolve_shellcheck() {
  if [ -n "${SHELLCHECK:-}" ]; then
    command -v "${SHELLCHECK}" >/dev/null 2>&1 || die "\$SHELLCHECK is set to '${SHELLCHECK}', which is not executable"
    command -v "${SHELLCHECK}"
    return 0
  fi
  if [ -x "${HOME}/.local/bin/_shellcheck-pinned" ]; then
    printf '%s\n' "${HOME}/.local/bin/_shellcheck-pinned"
    return 0
  fi
  if command -v shellcheck >/dev/null 2>&1; then
    command -v shellcheck
    return 0
  fi
  die "no ShellCheck found. Tried \$SHELLCHECK, ${HOME}/.local/bin/_shellcheck-pinned (the pinned build on an agent box), and 'shellcheck' on PATH."
}

assert_version() {
  local sc="$1" got
  got="$("${sc}" --version | awk '/^version:/ { print $2; exit }')"
  [ -n "${got}" ] || die "could not read a version out of '${sc} --version'"
  if [ "${got}" != "${EXPECTED_VERSION}" ]; then
    die "analyser is ShellCheck ${got}, the baseline was measured under ${EXPECTED_VERSION}. Findings differ between versions, so this run would compare a new verdict against an old ledger. Move the pin deliberately (diff both versions over the population first) or point \$SHELLCHECK at ${EXPECTED_VERSION}."
  fi
  # Cross-check the restated version against the upstream pin where that file is reachable; say so
  # by name where it is not, rather than implying a check that did not happen.
  if [ -r "${TOOLKIT_PIN}" ]; then
    local pinned
    pinned="$(awk '/^version[[:space:]]/ { print $2; exit }' "${TOOLKIT_PIN}")"
    if [ "${pinned}" != "${EXPECTED_VERSION}" ]; then
      die "upstream pin ${TOOLKIT_PIN} says ShellCheck ${pinned}, this repo's baseline says ${EXPECTED_VERSION}. One of the two moved without the other."
    fi
    printf 'shell-lint: analyser %s (ShellCheck %s, matches the pin in %s)\n' "${sc}" "${got}" "${TOOLKIT_PIN}" >&2
  else
    printf 'shell-lint: analyser %s (ShellCheck %s). NOT VERIFIED HERE: the upstream pin %s is unreadable from this machine, so the version was asserted against this repo only.\n' "${sc}" "${got}" "${TOOLKIT_PIN}" >&2
  fi
}

# Print the population, NUL-safe, one path per line. Paths with newlines would break every consumer
# downstream of here; there are none, and this refuses rather than lints a subset if that changes.
population() {
  local f
  while IFS= read -r -d '' f; do
    case "${f}" in
      *$'\n'*) die "tracked path contains a newline: ${f}" ;;
    esac
    [ -f "${f}" ] || continue
    case "${f}" in
      *.sh) printf '%s\n' "${f}"; continue ;;
    esac
    if head -n 1 -- "${f}" | grep -qE '^#!.*[ /](ba|da|k)?sh([[:space:]]|$)'; then
      printf '%s\n' "${f}"
    fi
  done < <(git ls-files -z)
}

# file<TAB>code<TAB>count, sorted — the same shape the baseline is stored in.
counts_from_findings() {
  sed -nE 's/^([^:]+):[0-9]+:[0-9]+: [a-z]+: .*\[(SC[0-9]+)\]$/\1\t\2/p' "$1" \
    | sort \
    | uniq -c \
    | awk '{ printf "%s\t%s\t%s\n", $2, $3, $1 }' \
    | sort
}

main() {
  local mode='check'
  case "${1:-}" in
    '')                ;;
    --all)             mode='all' ;;
    --list)            mode='list' ;;
    --update-baseline) mode='update' ;;
    -h|--help)         sed -n '2,50p' "$0"; exit 0 ;;
    *)                 die "unknown argument '$1' (try --help)" ;;
  esac
  [ "$#" -le 1 ] || die 'takes at most one argument (try --help)'

  local root
  root="$(git rev-parse --show-toplevel)" || die 'not inside a git work tree'
  cd "${root}"

  local baseline='bin/shell-lint.baseline.tsv'
  local tmp
  tmp="$(mktemp -d)"
  # shellcheck disable=SC2064  # expand tmp now: the trap must survive the variable going out of scope
  trap "rm -rf -- '${tmp}'" EXIT

  population > "${tmp}/files"
  [ -s "${tmp}/files" ] || die 'the derived population is empty — that is a measurement that never happened, not a clean tree'

  if [ "${mode}" = 'list' ]; then
    cat "${tmp}/files"
    exit 0
  fi

  local sc
  sc="$(resolve_shellcheck)"
  assert_version "${sc}"

  # One invocation over the whole population, never one per file: `xargs` would collapse ShellCheck's
  # own exit status into its 123 ("some invocation failed"), which cannot be told apart from
  # "findings exist". ShellCheck exits 1 merely because findings exist; that is not an error here,
  # and anything above 1 means it did not run.
  local -a files
  mapfile -t files < "${tmp}/files"
  set +e
  "${sc}" --format=gcc "${files[@]}" > "${tmp}/findings" 2> "${tmp}/scerr"
  local rc=$?
  set -e
  if [ "${rc}" -gt 1 ]; then
    cat "${tmp}/scerr" >&2
    die "ShellCheck exited ${rc} — it failed to run, which is not a clean result"
  fi

  if [ "${mode}" = 'all' ]; then
    cat "${tmp}/findings"
    printf 'shell-lint: %s finding(s) over %s file(s); the baseline is not consulted in --all.\n' \
      "$(wc -l < "${tmp}/findings")" "$(wc -l < "${tmp}/files")" >&2
    exit 0
  fi

  counts_from_findings "${tmp}/findings" > "${tmp}/current"

  if [ "${mode}" = 'update' ]; then
    {
      printf '# shell-lint baseline — file<TAB>ShellCheck code<TAB>count, regenerated by\n'
      printf '# "bin/shell-lint.sh --update-baseline". Hand edits are pointless: the next run of that\n'
      printf '# command overwrites them. WHAT THIS IS: a debt ledger of findings that existed when the\n'
      printf '# lane landed, so the lane starts green and reds on the NEXT one. Nothing here has been\n'
      printf '# judged correct. Read the header of bin/shell-lint.sh for why it is keyed by count and\n'
      printf '# not by line, and for why a drop in a count reports rather than reds.\n'
      cat "${tmp}/current"
    } > "${baseline}"
    printf 'shell-lint: wrote %s\n' "${baseline}" >&2
    exit 0
  fi

  [ -r "${baseline}" ] || die "baseline ${baseline} is missing — run bin/shell-lint.sh --update-baseline"

  awk -v basefile="${baseline}" -v curfile="${tmp}/current" '
    BEGIN {
      FS = "\t"
      while ((getline line < basefile) > 0) {
        if (line ~ /^[[:space:]]*(#|$)/) continue
        if (split(line, p, "\t") < 3) continue
        k = p[1] "\t" p[2]; base[k] = p[3] + 0; seen[k] = 1
      }
      while ((getline line < curfile) > 0) {
        if (split(line, p, "\t") < 3) continue
        k = p[1] "\t" p[2]; cur[k] = p[3] + 0; seen[k] = 1
      }
      for (k in seen) {
        b = (k in base) ? base[k] : 0
        c = (k in cur) ? cur[k] : 0
        if (c > b) print "NEW\t" k "\t" b "\t" c
        else if (c < b) print "GONE\t" k "\t" b "\t" c
      }
    }
  ' < /dev/null | sort > "${tmp}/delta"

  grep '^NEW' "${tmp}/delta" > "${tmp}/new" || true
  grep '^GONE' "${tmp}/delta" > "${tmp}/gone" || true

  if [ -s "${tmp}/gone" ]; then
    printf 'shell-lint: these classes improved — run "bin/shell-lint.sh --update-baseline" and commit the ledger so the gain is held:\n' >&2
    awk -F'\t' '{ printf "  %s  %s  %s -> %s\n", $2, $3, $4, $5 }' "${tmp}/gone" >&2
  fi

  if [ -s "${tmp}/new" ]; then
    printf 'shell-lint: NEW findings — these are not in the baseline:\n' >&2
    awk -F'\t' '{ printf "  %s  %s  baseline %s -> now %s\n", $2, $3, $4, $5 }' "${tmp}/new" >&2
    printf '\n' >&2
    while IFS=$'\t' read -r _ file code _ _; do
      grep -E "^${file}:[0-9]+:[0-9]+: .*\[${code}\]$" "${tmp}/findings" >&2 || true
    done < "${tmp}/new"
    printf '\nshell-lint: fix them, or — if the finding is understood and accepted — annotate the line\nwith a "# shellcheck disable=<code>" directive carrying the reason. Re-baselining to make a NEW\nfinding go away is the one thing this gate exists to prevent.\n' >&2
    exit 1
  fi

  printf 'shell-lint: clean — %s file(s), %s baselined finding(s), no new ones.\n' \
    "$(wc -l < "${tmp}/files")" "$(wc -l < "${tmp}/findings")" >&2
  exit 0
}

main "$@"

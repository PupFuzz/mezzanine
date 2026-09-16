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
#   bin/shell-lint.sh --update-baseline  rewrite bin/shell-lint.baseline.tsv from the current tree.
#                                      REFUSES while the tree carries findings the ledger does not.
#   bin/shell-lint.sh --update-baseline --accept-new   record those findings as debt anyway, as a
#                                      deliberate, typed decision.
#
#   Exit codes: 0 clean · 1 new findings · 2 the lint could not be run, or was refused (missing or
#   wrong analyser, a file the ledger names has left the population, the finding parser lost a line,
#   a re-baseline over new findings, bad usage). A 2 is never a pass.
#
# THE FILE POPULATION IS DERIVED, NEVER LISTED. Every tracked file that is named `*.sh`, OR whose
# first line is a `sh`/`bash`/`dash`/`ksh` shebang, OR which declares its dialect to ShellCheck with
# a `# shellcheck shell=` directive in its first five lines. The shebang leg is load-bearing:
# `bin/promote-cards-by-token` is a 1.4k-line bash script with no extension, and a `bin/*.sh` glob
# would have missed it silently. The directive leg matches nothing today and exists so that the
# first shebang-less sourced library to land is not invisible to the derivation — that directive is
# what ShellCheck itself needs in order to analyse such a file at all, so it is exactly the marker
# that says "this is shell". A written count would be stale the next time a script lands, so this
# file carries none — run `--list` to print today's set.
#
# WHAT THE BASELINE IS, AND IS NOT. `bin/shell-lint.baseline.tsv` records, per (file, ShellCheck
# code), how many findings that pair had when the lane landed. It is a DEBT LEDGER, not an approval:
# nothing in it has been judged correct, and the lane's whole job is that the NEXT one reds. Keyed by
# count rather than by line number deliberately — line numbers move under every edit to the file
# above them, and a line-keyed baseline would red on PRs that changed nothing about the finding.
# Fixing the baselined findings is separate, reviewed work — a lint fix inside `bin/deploy.sh` is a
# behaviour change on the production deploy path.
#
# A DECREASE IS AN EVENT TO EXPLAIN, NOT A REWARD. `c < b` has three possible causes and the counts
# alone cannot tell them apart: the finding was FIXED; a finding was removed while another was added
# under the same (file, code) pair, so a regression rides in under a falling number; or the FILE left
# the population entirely. The first round of this lane assumed the first cause every time and
# printed `--update-baseline` as an unconditional imperative — which, in a run that also carried a
# NEW finding, instructed the reader to baseline that new finding permanently while the headline
# count did not move. So:
#
#   * THE LEDGER CARRIES THE POPULATION, in its `#pop` lines, and they are CHECKED. A derived
#     population that has lost a file the ledger names is a hard error (exit 2), never an
#     improvement: coverage leaving is the one cause a count can never show, and it silently
#     narrows what this lane claims to analyse. Deleting or renaming a script is legitimate —
#     re-baseline in the same change that does it.
#   * A class that reaches ZERO in a run carrying no new findings is the one unambiguous gain, and
#     only that case asks for `--update-baseline`.
#   * Any other decrease — a partial one, or any decrease in a run that also carries NEW findings —
#     prints as UNEXPLAINED DECREASE together with the findings that survived in that class, and
#     carries no re-baseline instruction.
#   * `--update-baseline` REFUSES (exit 2) while any class sits above its baseline. `--accept-new`
#     is the override, so that recording a new finding as debt is a decision somebody typed rather
#     than advice this tool gave itself.
#
# THE RESIDUAL, named rather than implied. A swap INSIDE one (file, code) pair still passes: remove
# one finding and add one of the same code in the same file and the count nets to zero silently;
# remove two and add one and the count falls. That is the price of keying by count, paid
# deliberately for the reason above. What changed is that the falling case is no longer called an
# improvement and no longer asks to be baselined — it prints the findings still standing in the
# class and asks a human to read them, which is the only check that can close it.
#
# THE ANALYSER VERSION IS ASSERTED, NOT ACCEPTED. Two ShellCheck versions genuinely disagree about
# the same file (the reasoning and a worked example are in agent-board-toolkit's
# `.shellcheck-version`), so a baseline is meaningful only against a named version. This script
# refuses to run under any other one rather than emit a verdict from an unnamed program. CI installs
# the pinned version rather than taking whatever the runner image ships, so this assert fires on a
# project decision instead of on GitHub's release schedule; when the pin itself moves, move it
# DELIBERATELY — run both versions over the population and diff the findings first — never by
# relaxing the assert.
#
set -euo pipefail

# The version this repo's baseline was measured under. `agent-board-toolkit/.shellcheck-version` is
# the upstream pin; that file lives outside this repo and CI cannot read it, so the value is
# restated here and CROSS-CHECKED against it whenever it IS readable (see assert_version below) —
# a guard rather than a silent copy.
EXPECTED_VERSION='0.9.0'
TOOLKIT_PIN="${SHELL_LINT_TOOLKIT_PIN:-${HOME}/agent-board-toolkit/.shellcheck-version}"

die() { printf 'shell-lint: %s\n' "$*" >&2; exit 2; }

# --help prints this file's own header, from line 2 to the first non-comment line. Deliberately not
# a line range: the header grows, and a range would silently start cutting it mid-sentence.
usage() { awk 'NR > 1 { if ($0 !~ /^#/) exit; sub(/^#[[:space:]]?/, ""); print }' "$0"; }

# Resolve the analyser. $SHELLCHECK wins; then the pinned build this box actually has; then PATH.
# Whatever is chosen is PRINTED, so a log never has to guess which program produced the verdict.
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
      continue
    fi
    if head -n 5 -- "${f}" | grep -qE '^#[[:space:]]*shellcheck[[:space:]]+shell='; then
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

# EVERY finding line must land in exactly one count. The parser above cannot match a path containing
# a colon and would drop those lines in total silence — a narrower measurement reported as a clean
# one. This compares the two numbers the script already computed and never compared.
assert_every_finding_counted() {
  local findings="$1" counts="$2" parsed total
  parsed="$(awk -F'\t' '{ n += $3 } END { print n + 0 }' "${counts}")"
  total="$(awk 'END { print NR + 0 }' "${findings}")"
  [ "${parsed}" -eq "${total}" ] && return 0
  printf 'shell-lint: the finding parser accounted for %s of %s finding line(s). Unmatched, up to ten:\n' \
    "${parsed}" "${total}" >&2
  grep -vE '^[^:]+:[0-9]+:[0-9]+: [a-z]+: .*\[SC[0-9]+\]$' "${findings}" | head -n 10 >&2 || true
  die 'those lines would have vanished from every count, and this run would have reported a smaller measurement as a clean one. Fix counts_from_findings to match the shape ShellCheck actually emitted.'
}

# NEW/GONE per (file, code) class, one line each: verdict<TAB>file<TAB>code<TAB>baseline<TAB>current.
# One definition, read by both --update-baseline's refusal and the check report.
delta_against_baseline() {
  awk -v basefile="$1" -v curfile="$2" '
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
  ' < /dev/null | sort
}

main() {
  local mode='check' mode_arg='' accept_new=0
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --all|--list|--update-baseline)
        [ -z "${mode_arg}" ] || die "'${mode_arg}' and '$1' are two different modes — pass one (try --help)"
        mode_arg="$1"
        case "$1" in
          --all)             mode='all' ;;
          --list)            mode='list' ;;
          --update-baseline) mode='update' ;;
        esac
        ;;
      --accept-new)      accept_new=1 ;;
      -h|--help)         usage; exit 0 ;;
      *)                 die "unknown argument '$1' (try --help)" ;;
    esac
    shift
  done
  if [ "${accept_new}" -eq 1 ] && [ "${mode}" != 'update' ]; then
    die "--accept-new means nothing on its own: it overrides --update-baseline's refusal to record new findings as debt, and nothing else."
  fi

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
  assert_every_finding_counted "${tmp}/findings" "${tmp}/current"

  if [ "${mode}" = 'update' ]; then
    # Re-baselining over findings the ledger does not carry is how a new defect becomes permanent
    # debt while the headline count barely moves. It takes a typed override, never a default.
    if [ -r "${baseline}" ] && [ "${accept_new}" -eq 0 ]; then
      delta_against_baseline "${baseline}" "${tmp}/current" | grep '^NEW' > "${tmp}/new" || true
      if [ -s "${tmp}/new" ]; then
        printf 'shell-lint: REFUSING to re-baseline — this tree carries findings the ledger does not:\n' >&2
        awk -F'\t' '{ printf "  %s  %s  baseline %s -> now %s\n", $2, $3, $4, $5 }' "${tmp}/new" >&2
        die 'writing the ledger now would record every one of those as accepted debt, and the total would barely move. Fix them, or annotate the line with a "# shellcheck disable=<code>" directive carrying the reason. If they really are debt you mean to record, say so deliberately: bin/shell-lint.sh --update-baseline --accept-new.'
      fi
    fi
    {
      printf '# shell-lint baseline — file<TAB>ShellCheck code<TAB>count, regenerated by\n'
      printf '# "bin/shell-lint.sh --update-baseline". Hand edits are pointless: the next run of that\n'
      printf '# command overwrites them. WHAT THIS IS: a debt ledger of findings that existed when the\n'
      printf '# lane landed, so the lane starts green and reds on the NEXT one. Nothing here has been\n'
      printf '# judged correct. Read the header of bin/shell-lint.sh for why it is keyed by count and\n'
      printf '# not by line, and for what a FALLING count does and does not prove.\n'
      printf '#\n'
      printf '# THE "#pop" LINES ARE THE POPULATION this ledger was measured over, and they are CHECKED:\n'
      printf '# bin/shell-lint.sh re-derives the population on every run and refuses to report on a tree\n'
      printf '# that has lost one of them. A file leaving coverage drives its counts down exactly as a fix\n'
      printf '# does, and this list is the only record that tells the two apart.\n'
      awk '{ print "#pop\t" $0 }' "${tmp}/files"
      cat "${tmp}/current"
    } > "${baseline}"
    printf 'shell-lint: wrote %s (%s file(s), %s finding(s))\n' \
      "${baseline}" "$(wc -l < "${tmp}/files")" "$(wc -l < "${tmp}/findings")" >&2
    exit 0
  fi

  [ -r "${baseline}" ] || die "baseline ${baseline} is missing — run bin/shell-lint.sh --update-baseline"

  # THE POPULATION CHECK. The population is derived fresh on every run; without this it was compared
  # to nothing, so a file dropping out of the set (a lost shebang, a rename, a deletion) read as its
  # classes "improving", plus a smaller file count that nothing compared to anything.
  awk -F'\t' '$1 == "#pop" { print $2 }' "${baseline}" | sort > "${tmp}/ledgerpop"
  [ -s "${tmp}/ledgerpop" ] || die "baseline ${baseline} carries no #pop population block, so a file leaving coverage could not be detected at all — regenerate it with bin/shell-lint.sh --update-baseline"
  sort "${tmp}/files" > "${tmp}/curpop"
  comm -23 "${tmp}/ledgerpop" "${tmp}/curpop" > "${tmp}/lostpop"
  if [ -s "${tmp}/lostpop" ]; then
    printf 'shell-lint: %s names these files, and the population derived from this tree does not contain them:\n' "${baseline}" >&2
    sed 's/^/  /' "${tmp}/lostpop" >&2
    die 'a file leaving the population drives its counts to zero, which is indistinguishable from fixing every finding in it, and it narrows what this lane claims to analyse behind a green check. If the file was deliberately deleted or renamed, re-run bin/shell-lint.sh --update-baseline in the SAME change and commit the ledger with it.'
  fi
  comm -13 "${tmp}/ledgerpop" "${tmp}/curpop" > "${tmp}/newpop"
  if [ -s "${tmp}/newpop" ]; then
    printf 'shell-lint: new in the population since the ledger was measured — analysed on this run, and named in the ledger at the next --update-baseline:\n' >&2
    sed 's/^/  /' "${tmp}/newpop" >&2
  fi

  delta_against_baseline "${baseline}" "${tmp}/current" > "${tmp}/delta"
  grep '^NEW' "${tmp}/delta" > "${tmp}/new" || true
  grep '^GONE' "${tmp}/delta" > "${tmp}/gone" || true

  if [ -s "${tmp}/gone" ]; then
    # A class that reached zero in a run carrying no new findings is the one decrease that explains
    # itself. Everything else is reported as what it is: a number that fell for a reason this tool
    # cannot read off the counts.
    if [ -s "${tmp}/new" ]; then
      cp "${tmp}/gone" "${tmp}/unexplained"
      : > "${tmp}/cleared"
    else
      awk -F'\t' '$5 == 0' "${tmp}/gone" > "${tmp}/cleared"
      awk -F'\t' '$5 > 0'  "${tmp}/gone" > "${tmp}/unexplained"
    fi

    if [ -s "${tmp}/unexplained" ]; then
      printf 'shell-lint: UNEXPLAINED DECREASE — verify no finding was swapped in:\n' >&2
      awk -F'\t' '{ printf "  %s  %s  %s -> %s\n", $2, $3, $4, $5 }' "${tmp}/unexplained" >&2
      : > "${tmp}/survivors"
      while IFS=$'\t' read -r _ file code _ _; do
        grep -E "^${file}:[0-9]+:[0-9]+: .*\[${code}\]$" "${tmp}/findings" >> "${tmp}/survivors" || true
      done < "${tmp}/unexplained"
      # A class emptied by its own run has no survivors to read; printing the heading over nothing
      # would ask for a check that cannot be made.
      if [ -s "${tmp}/survivors" ]; then
        printf '\nthe finding(s) still standing in those classes:\n' >&2
        cat "${tmp}/survivors" >&2
      fi
      printf '\nshell-lint: this ledger is keyed by COUNT, so a class that falls without reaching zero — or\nany fall in a run that also carries NEW findings — reads exactly like a regression that rode in\nunder a co-located fix. Where findings are listed above, read them and confirm they are the ones\nthe ledger recorded. No re-baseline is asked for here.\n' >&2
    fi

    if [ -s "${tmp}/cleared" ]; then
      printf 'shell-lint: these classes are gone entirely — every finding the ledger carried for the pair\nis fixed. Run "bin/shell-lint.sh --update-baseline" and commit the ledger so the gain is held:\n' >&2
      awk -F'\t' '{ printf "  %s  %s  %s -> 0\n", $2, $3, $4 }' "${tmp}/cleared" >&2
    fi
  fi

  if [ -s "${tmp}/new" ]; then
    printf 'shell-lint: NEW findings — these are not in the baseline:\n' >&2
    awk -F'\t' '{ printf "  %s  %s  baseline %s -> now %s\n", $2, $3, $4, $5 }' "${tmp}/new" >&2
    printf '\n' >&2
    while IFS=$'\t' read -r _ file code _ _; do
      grep -E "^${file}:[0-9]+:[0-9]+: .*\[${code}\]$" "${tmp}/findings" >&2 || true
    done < "${tmp}/new"
    printf '\nshell-lint: fix them, or — if the finding is understood and accepted — annotate the line\nwith a "# shellcheck disable=<code>" directive carrying the reason, ON THE LINE, never at file\nscope: a file-level disable blinds the whole (file, code) class, and this gate then reports the\nblinding as an improvement. Re-baselining to make a NEW finding go away is the one thing this\ngate exists to prevent, and --update-baseline refuses to do it.\n' >&2
    exit 1
  fi

  printf 'shell-lint: clean — %s file(s), %s baselined finding(s), no new ones.\n' \
    "$(wc -l < "${tmp}/files")" "$(wc -l < "${tmp}/findings")" >&2
  exit 0
}

main "$@"

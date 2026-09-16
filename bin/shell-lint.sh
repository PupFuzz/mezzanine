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
#                                      REFUSES while the tree carries findings the ledger does not,
#                                      and REFUSES while the tree measures LESS than the ledger does.
#   bin/shell-lint.sh --update-baseline --accept-new     record new findings as debt anyway
#   bin/shell-lint.sh --update-baseline --accept-shrink  record a NARROWED measurement anyway
#                                      (both are deliberate, typed decisions — see the two gates
#                                      below; pass both when a change does both at once)
#
#   Exit codes: 0 clean · 1 new findings · 2 the lint could not be run, or was refused (missing or
#   wrong analyser, a conflicted index, a file the ledger names that has left the population, a
#   file-scope disable directive, a measurement configuration the ledger does not record, the
#   finding parser losing a line, a refused re-baseline, bad usage). A 2 is never a pass.
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
# ══ WHAT IS MEASURED IS A FUNCTION OF THREE INPUTS, AND THE LEDGER RECORDS ALL THREE. ══
#
# A finding count is evidence about a MEASUREMENT, never on its own about the code. The measurement
# takes three inputs:
#
#   1. the FILE POPULATION — derived above, recorded in the ledger's `#pop` lines;
#   2. the ANALYSER VERSION — pinned in `bin/shell-lint.analyser.pin`, asserted before every run;
#   3. the ANALYSER'S EFFECTIVE CONFIGURATION — a `.shellcheckrc` in any parent directory, a
#      `SHELLCHECK_OPTS` in the environment, a `# shellcheck disable=` directive inside a file and
#      the REGION that directive covers, the flags this script passes, a severity floor, and the
#      analyser BUILD itself, which can exclude a code while reporting the pinned version truthfully.
#
# Earlier rounds of this lane guarded input 1 by ENUMERATING THE CAUSES of a falling count and
# handling each. That enumeration is over the wrong dimension, and the evidence is that every review
# round found another member of the list. Input 3 is the cheapest of the three for anyone to change,
# and the ONLY one that narrows coverage without touching a single file the ledger names — so every
# guard aimed at input 1 passes while it happens. Measured against this repo before this change:
# a one-line `.shellcheckrc` (`disable=SC2086`) at the root, plus a real `rm -rf $MEZZ_DOCROOT`
# added to `bin/supervision.sh`, printed *"these classes are gone entirely — every finding the
# ledger carried for the pair is fixed"*, exited 0, and asked for the narrowed ledger to be
# committed. `disable=SC2317,SC2016` took the same tree from 114 findings to 31 the same way. A
# file-scope directive did it with no new file at all; `SHELLCHECK_OPTS` did it with no file at all.
#
# SO: THE LEDGER RECORDS THE MEASUREMENT CONFIGURATION, AND EVERY NARROWING IT CAN SEE TAKES ONE
# TYPED ACCEPTANCE. Until this change the asymmetry was exact and inverted — ADDING DEBT took a
# typed override (`--accept-new`), REMOVING COVERAGE took nothing at all. `--accept-shrink` is the
# symmetric gate, and it covers in one place: a file leaving the population, a directive appearing,
# the analyser pin moving, the flag vector changing, and the analyser going quiet about a code the
# probe carries. Two narrowings sit OUTSIDE that set — the analyser build, and an existing directive
# widening the region it covers — and both were measured rather than assumed away; THE RESIDUAL,
# below, is where they are named, with what a green run does and does not prove because of them.
#
# The ledger's `#cfg` lines are that record, and they are CHECKED on every run:
#
#   #cfg analyser    the ShellCheck version the counts were measured under.
#   #cfg invocation  the exact flag vector, with the machine-dependent analyser path elided. It
#                    carries `--norc`, which is what makes a `.shellcheckrc` inert here, and the
#                    emptied `SHELLCHECK_OPTS`, which is what makes that environment variable inert.
#                    Both are RECORDED rather than merely done, so removing one is a ledger diff.
#   #cfg probe       the set of ShellCheck codes the analyser actually emits over a FIXED script
#                    this run writes to a temp directory and throws away (`probe_source`, below),
#                    under the very same invocation. This is the control (canon #9): the other rows
#                    say what the configuration is SUPPOSED to be, and this one is the analyser's
#                    own answer — about the codes this probe CONTAINS, a spread across all four
#                    severities and not every code in the ledger. A severity floor, or an exclusion
#                    or a silently dropped check touching one of THOSE codes, shows up here as a
#                    code that stopped being reported; one touching any other code is invisible
#                    here, which is what THE RESIDUAL below is about.
#   #cfg directive   every `# shellcheck <key>=<value>` directive inside a population file, keyed by
#                    (file, directive) with a COUNT — the same count-keying, and for the same
#                    reason, as the finding rows: a directive that moves lines is not a change,
#                    which is also how a relocated one widens its scope unseen (THE RESIDUAL).
#
# AND A FILE-SCOPE `disable=` IS REFUSED OUTRIGHT (exit 2), not recorded. A `# shellcheck disable=`
# before the first non-comment, non-blank line of a file is ShellCheck's own file-scope form: it
# blinds every (file, code) class in that file at once, and this gate then reads the blinding as an
# improvement. On the line it annotates, one finding at a time, it is a legitimate and reviewable
# annotation — which is what this script has always told people to write.
#
# A DECREASE IS STILL AN EVENT TO EXPLAIN, NOT A REWARD. With the configuration pinned, a class that
# falls is no longer ambiguous about WHY the measurement changed — the measurement did not change —
# but it is still ambiguous about the code: a finding may have been fixed, or one may have been
# removed while another of the same (file, code) pair was added. So:
#
#   * A class that reaches ZERO in a run carrying no new findings is the one unambiguous gain, and
#     only that case asks for `--update-baseline`.
#   * Any other decrease — a partial one, or any decrease in a run that also carries NEW findings —
#     prints as UNEXPLAINED DECREASE together with the findings that survived in that class, and
#     carries no re-baseline instruction. Under CI it is also emitted as a `::warning` annotation
#     and appended to the job summary, because a step that exits 0 renders COLLAPSED and the text
#     was reaching nobody; and the run's last line then says so rather than saying "clean".
#   * `--update-baseline` REFUSES (exit 2) while any class sits above its baseline, and REFUSES
#     while the measurement has narrowed. `--accept-new` and `--accept-shrink` are the two
#     overrides, so that each is a decision somebody typed rather than advice this tool gave itself.
#
# THE RESIDUAL, named rather than implied — and a DECISION ON THE RECORD rather than an oversight:
# card#9635 carries the measurements below and the won't-do that left them open, and card#9645 owns
# the count-keying half. Read this before concluding that a green run here means no second guard is
# wanted on the analyser, because the claim stops short of that.
#
# WHAT A GREEN RUN PROVES. Over the population the ledger names, analysed by a program reporting the
# pinned version with `--norc` and an emptied `SHELLCHECK_OPTS`: no (file, code) class carries MORE
# findings than the ledger records, no file left the population, no `# shellcheck` directive
# appeared, the flag vector is the recorded one, and the analyser still reports every code the probe
# carries. Two configuration channels are genuinely CLOSED, and closed visibly: `--norc` makes every
# `.shellcheckrc` in every parent directory inert, the emptied `SHELLCHECK_OPTS` contributes no
# arguments, and both ride in the `#cfg invocation` row, so dropping either is a ledger narrowing
# that takes a typed `--accept-shrink`. UNDER CI a third thing is closed that a local run cannot
# close: the lane INSTALLS the analyser as a sha256-verified download of the release
# `bin/shell-lint.analyser.pin` names, so the program behind a CI verdict is identified by DIGEST
# instead of by its own self-report. That is what makes a CI green stronger than a local one, and it
# is why the lane refuses to fall back to the runner image's ShellCheck.
#
# WHAT IT DOES NOT PROVE — three shapes, each measured on this tree rather than supposed:
#   * A SWAP INSIDE one (file, code) pair. Remove one finding and add one of the same code in the
#     same file and the count nets to zero silently; remove two and add one and the count falls.
#     That is the price of keying by count, paid deliberately for the reason above.
#   * THE ANALYSER BINARY, a configuration channel none of these rows close and one the probe is
#     blind to BY CONSTRUCTION. Locally the analyser is whatever `$SHELLCHECK`, the pinned path or
#     PATH resolves to, and the `#cfg analyser` row records what that program SAYS about itself. A
#     build that reports the pinned version truthfully while excluding a code the probe does not
#     carry runs GREEN and reports that whole class as fixed: measured with a wrapper passing
#     `--exclude=SC2317`, which exits 0, prints the ledger's entire SC2317 class as "gone entirely",
#     and calls the run clean. A probe notices only a code it contains, so widening it moves that
#     frontier rather than closing it — the next exclusion is of a code the widened probe does not
#     carry either. What closes this is IDENTIFYING the binary instead of asking it, which is what
#     the CI download does and what a local run does not.
#   * DIRECTIVE SCOPE, which lives inside the files and is not recorded. The `#cfg directive` rows
#     are keyed by (file, directive) with a count, so a directive APPEARING is caught while an
#     EXISTING one RELOCATING is not. Measured: a new SC2016 inside `bin/deploy.sh`'s
#     `previous_stream_pids()` reds at exit 1 (`SC2016 baseline 3 -> now 4`); lift that file's own
#     `# shellcheck disable=SC2016` off the line it annotates, re-seat it over that function, and
#     the same tree is exit 0 clean with `--update-baseline` re-deriving this ledger BYTE-IDENTICAL.
#     The widest relocation — to file scope — is the one case refused outright (exit 2, above).
#
# THE ANALYSER VERSION IS ASSERTED, NOT ACCEPTED. Two ShellCheck versions genuinely disagree about
# the same file (the reasoning and a worked example are in agent-board-toolkit's
# `.shellcheck-version`), so a baseline is meaningful only against a named version. This script
# refuses to run under any other one rather than emit a verdict from an unnamed program. CI installs
# the pinned version rather than taking whatever the runner image ships, so this assert fires on a
# project decision instead of on GitHub's release schedule; when the pin itself moves, move it
# DELIBERATELY — run both versions over the population and diff the findings first — never by
# relaxing the assert. The version and the release digest are written in ONE repo file,
# `bin/shell-lint.analyser.pin`, which this script and the CI workflow both read, so neither can
# drift from the other by being edited alone.
#
set -euo pipefail

# The ONE repo-local record of which analyser this baseline was measured under: version + the
# sha256 of the upstream release tarball the CI lane downloads. `.github/workflows/shell-lint.yml`
# reads this same file for both values. `agent-board-toolkit/.shellcheck-version` is the upstream
# pin; it lives outside this repo and CI cannot read it, so this file is CROSS-CHECKED against it
# whenever it IS readable (see assert_version) — a guard rather than a silent copy.
ANALYSER_PIN='bin/shell-lint.analyser.pin'
TOOLKIT_PIN="${SHELL_LINT_TOOLKIT_PIN:-${HOME}/agent-board-toolkit/.shellcheck-version}"

# THE ANALYSER FLAG VECTOR — part of the measurement, recorded in the ledger's `#cfg invocation`
# row. `--norc` makes every `.shellcheckrc` in every parent directory inert; without it, a two-line
# file nothing else in this repo names decides what the gate can see.
SC_FLAGS=(--norc --format=gcc)

EXPECTED_VERSION=''
EXPECTED_SHA256=''

die() { printf 'shell-lint: %s\n' "$*" >&2; exit 2; }

# --help prints this file's own header, from line 2 to the first non-comment line. Deliberately not
# a line range: the header grows, and a range would silently start cutting it mid-sentence.
usage() { awk 'NR > 1 { if ($0 !~ /^#/) exit; sub(/^#[[:space:]]?/, ""); print }' "$0"; }

# Read the version + release digest out of a pin file. One parser for this repo's pin and for the
# toolkit's, because they are written in the same shape on purpose.
pin_field() { # pin_field <file> version|asset
  case "$2" in
    version) awk '/^version[[:space:]]/ { print $2; exit }' "$1" ;;
    asset)   awk '/^asset[[:space:]]+linux\.x86_64[[:space:]]/ { print $3; exit }' "$1" ;;
  esac
}

read_analyser_pin() {
  [ -r "${ANALYSER_PIN}" ] || die "${ANALYSER_PIN} is missing — it is the one place this repo names the analyser its baseline was measured under, and without it this run would produce a verdict from an unnamed program"
  EXPECTED_VERSION="$(pin_field "${ANALYSER_PIN}" version)"
  EXPECTED_SHA256="$(pin_field "${ANALYSER_PIN}" asset)"
  [ -n "${EXPECTED_VERSION}" ] || die "${ANALYSER_PIN} carries no 'version <x.y.z>' line"
  [ -n "${EXPECTED_SHA256}" ] || die "${ANALYSER_PIN} carries no 'asset linux.x86_64 <sha256>' line"
}

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
    die "analyser is ShellCheck ${got}, the baseline was measured under ${EXPECTED_VERSION} (${ANALYSER_PIN}). Findings differ between versions, so this run would compare a new verdict against an old ledger. Move the pin deliberately (diff both versions over the population first) or point \$SHELLCHECK at ${EXPECTED_VERSION}."
  fi
  # Cross-check BOTH restated values against the upstream pin where that file is reachable; say so
  # by name where it is not, rather than implying a check that did not happen.
  if [ -r "${TOOLKIT_PIN}" ]; then
    local pinned_version pinned_sha
    pinned_version="$(pin_field "${TOOLKIT_PIN}" version)"
    pinned_sha="$(pin_field "${TOOLKIT_PIN}" asset)"
    if [ "${pinned_version}" != "${EXPECTED_VERSION}" ]; then
      die "upstream pin ${TOOLKIT_PIN} says ShellCheck ${pinned_version}, ${ANALYSER_PIN} says ${EXPECTED_VERSION}. One of the two moved without the other."
    fi
    if [ -z "${pinned_sha}" ]; then
      # Saying "both match" here would be a claim about a comparison that did not happen.
      printf 'shell-lint: analyser %s (ShellCheck %s, version matches the pin in %s). NOT VERIFIED HERE: that pin carries no "asset linux.x86_64" line, so the release digest in %s was compared against nothing.\n' \
        "${sc}" "${got}" "${TOOLKIT_PIN}" "${ANALYSER_PIN}" >&2
      return 0
    fi
    if [ "${pinned_sha}" != "${EXPECTED_SHA256}" ]; then
      die "upstream pin ${TOOLKIT_PIN} records a different linux.x86_64 release digest than ${ANALYSER_PIN}. The CI lane installs the artifact ${ANALYSER_PIN} names, so these two disagreeing means CI would analyse with a build this box never saw."
    fi
    printf 'shell-lint: analyser %s (ShellCheck %s, version and linux.x86_64 digest both match the pin in %s)\n' "${sc}" "${got}" "${TOOLKIT_PIN}" >&2
  else
    printf 'shell-lint: analyser %s (ShellCheck %s). NOT VERIFIED HERE: the upstream pin %s is unreadable from this machine, so the version and digest in %s were asserted against this repo only.\n' "${sc}" "${got}" "${TOOLKIT_PIN}" "${ANALYSER_PIN}" >&2
  fi
}

# THE ONE PLACE THE ANALYSER IS INVOKED. The population run and the configuration probe go through
# here, so the invocation recorded in the ledger cannot drift from the invocation performed, and so
# neutralising a configuration channel neutralises it for both. `SHELLCHECK_OPTS` is ShellCheck's
# own environment channel: emptied here it contributes no arguments, which is what stops an exported
# `--exclude=...` from narrowing this gate from outside the repo entirely.
sc_exec() { # sc_exec <analyser> <file>...
  local sc="$1"; shift
  SHELLCHECK_OPTS='' "${sc}" "${SC_FLAGS[@]}" "$@"
}

# The invocation as the ledger records it — the analyser path is machine-dependent and elided.
invocation_record() { printf "SHELLCHECK_OPTS='' <analyser> %s" "${SC_FLAGS[*]}"; }

# The fixed input the configuration probe measures. Every line here exists to make one ShellCheck
# code fire, across all four severities, so that a floor, or an exclusion of one of THESE codes,
# shows up as a code that stopped being reported. An exclusion of a code this list omits is
# invisible to the probe — a limit of the control, stated in THE RESIDUAL in this file's header,
# rather than a list to keep extending. Changing this changes the recorded
# fingerprint and therefore needs a re-baseline, deliberately.
probe_source() {
  cat <<'PROBE'
#!/usr/bin/env bash
# bin/shell-lint.sh's measurement probe. Written to a temp directory, analysed with the same
# invocation as the population, and thrown away. It is never part of this repo's population.
later_definition() { echo one; }; later_definition; unset -f later_definition
later_definition() { echo two; }
probe_unquoted() { rm -rf $1; }
probe_backticks() { echo `date`; }
probe_useless_cat() { cat /etc/hostname | grep -c .; }
probe_unused() { local unused_assignment='x'; }
probe_single_quotes() { echo 'expanding $HOME here'; }
PROBE
}

# The analyser's own answer about its effective configuration: the sorted set of codes it reports
# over that fixed input, right now, under this invocation.
probe_codes() { # probe_codes <analyser> <tmpdir>
  local sc="$1" dir="$2/probe" rc=0
  mkdir -p "${dir}"
  probe_source > "${dir}/measurement-probe.sh"
  set +e
  sc_exec "${sc}" "${dir}/measurement-probe.sh" > "${dir}/out" 2> "${dir}/err"
  rc=$?
  set -e
  if [ "${rc}" -gt 1 ]; then
    cat "${dir}/err" >&2
    die "the analyser exited ${rc} over the measurement probe, so this run has no reading of its effective configuration at all"
  fi
  sed -nE 's/^.*: [a-z]+: .*\[(SC[0-9]+)\]$/\1/p' "${dir}/out" | sort -u > "${dir}/codes"
  [ -s "${dir}/codes" ] || die 'the measurement probe produced no findings at all. That script is written to fire on every severity, so a silent analyser means the configuration has been narrowed past the point where this gate measures anything.'
  cat "${dir}/codes"
}

# Print the population, NUL-safe, one path per line. Paths with newlines or tabs would break every
# consumer downstream of here — the finding parser, and the TSV the ledger is written in; there are
# none, and this refuses rather than lints a subset if that changes. `sort -zu` because `git
# ls-files` emits an unmerged path ONCE PER STAGE, which would lint the same file several times and
# multiply its counts; the die above it is the real answer — a conflicted tree is not a measurable
# tree — and the dedup is what keeps a duplicate out of the `#pop` set, where it would make `comm`
# report a present file as lost.
population() {
  local f unmerged
  unmerged="$(git ls-files -u)"
  if [ -n "${unmerged}" ]; then
    printf 'shell-lint: the index has unmerged paths, for example:\n' >&2
    printf '%s\n' "${unmerged}" | awk 'NR <= 5 { print "  " $0 }' >&2
    die 'a conflicted tree is not a measurable tree: git lists an unmerged path once per stage, so the population, the finding counts and the ledger comparison would all be computed over a file set that does not exist. Finish the merge, then run this again.'
  fi
  while IFS= read -r -d '' f; do
    case "${f}" in
      *$'\n'*) die "tracked path contains a newline: ${f}" ;;
      *$'\t'*) die "tracked path contains a tab, which is the field separator of the ledger this run would write: ${f}" ;;
      -*)      die "tracked path starts with a dash, which the analyser would read as an option rather than as a file to analyse: ${f}" ;;
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
  done < <(git ls-files -z | sort -zu)
}

# Every ShellCheck directive inside the population, one row per occurrence:
# file<TAB>directive<TAB>FILE|line<TAB>lineno. A directive is matched only in the form ShellCheck
# itself honours — a comment at the START of a line, the word `shellcheck`, then `key=value` tokens
# until a `#` starts a trailing human comment. Anchoring at line start is what keeps this script's
# OWN prose about directives (which quotes the syntax mid-sentence) out of the record.
directive_occurrences() { # directive_occurrences <file>...
  # Each path is handed to awk as `./path`: an argument of the form `name=value` is an ASSIGNMENT to
  # awk, not a file, so a tracked file whose name contains `=` would silently never be read — the
  # same shape of parser defeat as the space-in-a-path one above, and silent in the same way.
  local -a args=()
  local f
  for f in "$@"; do args+=("./${f}"); done
  awk '
    FNR == 1 { code_seen = 0 }
    {
      if ($0 !~ /^[[:space:]]*#/ && $0 !~ /^[[:space:]]*$/) code_seen = 1
      if ($0 !~ /^[[:space:]]*#[[:space:]]*shellcheck[[:space:]]/) next
      line = $0
      sub(/^[[:space:]]*#[[:space:]]*shellcheck[[:space:]]+/, "", line)
      n = split(line, tok, /[[:space:]]+/)
      fn = FILENAME
      sub(/^\.\//, "", fn)
      for (i = 1; i <= n; i++) {
        if (tok[i] == "") continue
        if (tok[i] !~ /^[a-z-]+=/) break
        print fn "\t" tok[i] "\t" (code_seen ? "line" : "FILE") "\t" FNR
      }
    }
  ' "${args[@]}"
}

# A file-scope `disable=` blinds every class in the file at once and this gate would read the
# blinding as an improvement. Refused, by name and by line, rather than recorded.
assert_no_file_scope_disable() { # assert_no_file_scope_disable <occurrences-file>
  local hits
  hits="$(awk -F'\t' '$3 == "FILE" && $2 ~ /^disable=/ { printf "  %s:%s  %s\n", $1, $4, $2 }' "$1")"
  [ -n "${hits}" ] || return 0
  printf 'shell-lint: these directives sit before the first non-comment, non-blank line of their file, which is ShellCheck'"'"'s file scope:\n' >&2
  printf '%s\n' "${hits}" >&2
  die 'a file-scope disable turns off that code for the WHOLE file, so every finding it covers disappears from this measurement at once and the drop reads exactly like a fix — a green run reporting less than the last one analysed. Move the directive onto the line it is about, with the reason on it; that form annotates one finding, is reviewable in a diff, and is what the NEW-findings advice in this script has always asked for. The line-scope directive is then recorded in the ledger by "bin/shell-lint.sh --update-baseline --accept-shrink", in the same change.'
}

# file<TAB>code<TAB>count, sorted — the same shape the baseline is stored in. Counted on the WHOLE
# parsed key in one pass: an earlier `sort | uniq -c | awk` re-parsed its own output with awk's
# default field splitting, so a path containing a space put the ShellCheck code in a field that was
# thrown away, collapsing every code for that path onto one key while the total still added up.
counts_from_findings() {
  sed -nE 's/^([^:]+):[0-9]+:[0-9]+: [a-z]+: .*\[(SC[0-9]+)\]$/\1\t\2/p' "$1" \
    | awk -F'\t' '{ c[$0]++ } END { for (k in c) print k "\t" c[k] }' \
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

# Print the finding lines of one (file, code) class. Matched literally — the path is data, not a
# pattern, and interpolating it into a regex made `.` and `+` in a filename match the wrong lines
# (or none, silently).
print_class_findings() { # print_class_findings <findings-file> <file> <code>
  local line
  while IFS= read -r line; do
    case "${line}" in
      "$2:"*) ;;
      *) continue ;;
    esac
    case "${line}" in
      *"[$3]") printf '%s\n' "${line}" ;;
    esac
  done < "$1"
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

# The measurement configuration, in the shape the ledger stores it.
config_block() { # config_block <version> <probe-codes-file> <directive-counts-file>
  printf '#cfg\tanalyser\tShellCheck %s\n' "$1"
  printf '#cfg\tinvocation\t%s\n' "$(invocation_record)"
  awk '{ print "#cfg\tprobe\t" $0 }' "$2"
  awk -F'\t' '{ print "#cfg\tdirective\t" $0 }' "$3"
}

# NARROW/WIDEN over two `#cfg` blocks: verdict<TAB>one readable line. NARROWING is the direction
# this gate exists to catch — less is measured than the ledger records. A change this tool cannot
# classify (the analyser version, the flag vector) counts as a narrowing: it is not able to say
# which way such a change moves the measurement, and guessing "wider" is how a gate goes quiet.
cfg_delta() { # cfg_delta <ledger-cfg-file> <current-cfg-file>
  awk -v ledfile="$1" -v curfile="$2" '
    function ingest(file, store,   line, n, p, kind, k, v, d) {
      while ((getline line < file) > 0) {
        n = split(line, p, "\t")
        if (n < 3 || p[1] != "#cfg") continue
        kind = p[2]
        if (kind == "probe")          { k = kind SUBSEP p[3];            v = "reported"; d = p[3] }
        else if (kind == "directive") { if (n < 5) continue
                                        k = kind SUBSEP p[3] SUBSEP p[4]; v = p[5];      d = p[3] "  " p[4] }
        else                          { k = kind SUBSEP kind;            v = p[3];       d = "" }
        store[k] = v; kindof[k] = kind; disp[k] = d; seen[k] = 1
      }
    }
    BEGIN {
      ingest(ledfile, led)
      ingest(curfile, cur)
      for (k in seen) {
        b = (k in led) ? led[k] : ""
        c = (k in cur) ? cur[k] : ""
        if (b == c) continue
        kind = kindof[k]
        if (kind == "probe") {
          if (c == "") print "NARROW\tprobe: the analyser no longer reports " disp[k] " over the measurement probe"
          else         print "WIDEN\tprobe: the analyser now also reports " disp[k] " over the measurement probe"
        } else if (kind == "directive") {
          bn = (b == "") ? 0 : b + 0
          cn = (c == "") ? 0 : c + 0
          if (cn > bn) print "NARROW\tdirective: " disp[k] "  " bn " -> " cn
          else         print "WIDEN\tdirective: " disp[k] "  " bn " -> " cn
        } else {
          print "NARROW\t" kind ": [" b "] -> [" c "]  (which way that moves the measurement is not something this tool can read off, so it counts as a narrowing)"
        }
      }
    }
  ' < /dev/null | sort
}

# The ledger's two halves are one record and must agree. A `#pop` line deleted by hand, with its
# count rows left behind, made the tool read the file as newly joined and report a routine addition.
assert_ledger_coherent() { # assert_ledger_coherent <baseline> <ledgerpop-file> <tmpdir>
  local baseline="$1" ledgerpop="$2" tmp="$3"
  awk -F'\t' '$1 !~ /^#/ && NF >= 3 { print $1 }' "${baseline}" | sort -u > "${tmp}/ledgercountpop"
  comm -23 "${tmp}/ledgercountpop" "${ledgerpop}" > "${tmp}/orphan"
  [ -s "${tmp}/orphan" ] || return 0
  printf 'shell-lint: %s carries counts for these paths and does not name them in its #pop population:\n' "${baseline}" >&2
  sed 's/^/  /' "${tmp}/orphan" >&2
  die 'the two halves of the ledger are one record of one measurement and they disagree, so nothing read out of it can be trusted — including which files this lane is supposed to cover. It is generated, never hand-written: delete it and run bin/shell-lint.sh --update-baseline to write it from the tree.'
}

main() {
  local mode='check' mode_arg='' accept_new=0 accept_shrink=0
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
      --accept-shrink)   accept_shrink=1 ;;
      -h|--help)         usage; exit 0 ;;
      *)                 die "unknown argument '$1' (try --help)" ;;
    esac
    shift
  done
  if [ "${accept_new}" -eq 1 ] && [ "${mode}" != 'update' ]; then
    die "--accept-new means nothing on its own: it overrides --update-baseline's refusal to record new findings as debt, and nothing else."
  fi
  if [ "${accept_shrink}" -eq 1 ] && [ "${mode}" != 'update' ]; then
    die "--accept-shrink means nothing on its own: it overrides --update-baseline's refusal to record a NARROWED measurement, and nothing else."
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

  read_analyser_pin
  local sc
  sc="$(resolve_shellcheck)"
  assert_version "${sc}"

  local -a files
  mapfile -t files < "${tmp}/files"

  # The configuration, measured before the verdict it conditions.
  directive_occurrences "${files[@]}" > "${tmp}/directives.raw"
  assert_no_file_scope_disable "${tmp}/directives.raw"
  awk -F'\t' '{ c[$1 "\t" $2]++ } END { for (k in c) print k "\t" c[k] }' "${tmp}/directives.raw" \
    | sort > "${tmp}/directives"
  probe_codes "${sc}" "${tmp}" > "${tmp}/probe-codes"
  config_block "${EXPECTED_VERSION}" "${tmp}/probe-codes" "${tmp}/directives" > "${tmp}/curcfg"

  # One invocation over the whole population, never one per file: `xargs` would collapse ShellCheck's
  # own exit status into its 123 ("some invocation failed"), which cannot be told apart from
  # "findings exist". ShellCheck exits 1 merely because findings exist; that is not an error here,
  # and anything above 1 means it did not run.
  set +e
  sc_exec "${sc}" "${files[@]}" > "${tmp}/findings" 2> "${tmp}/scerr"
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
  sort "${tmp}/files" > "${tmp}/curpop"

  if [ "${mode}" = 'update' ]; then
    if [ -r "${baseline}" ]; then
      awk -F'\t' '$1 == "#pop" { print $2 }' "${baseline}" | sort -u > "${tmp}/ledgerpop"
      assert_ledger_coherent "${baseline}" "${tmp}/ledgerpop" "${tmp}"
      awk -F'\t' '$1 == "#cfg"' "${baseline}" > "${tmp}/ledgercfg"
      local refused=0

      # Re-baselining over findings the ledger does not carry is how a new defect becomes permanent
      # debt while the headline count barely moves. It takes a typed override, never a default —
      # and when the override IS typed, what it accepted is printed, so the log of the run that
      # wrote the ledger names the decision instead of leaving it to the diff.
      delta_against_baseline "${baseline}" "${tmp}/current" | grep '^NEW' > "${tmp}/new" || true
      if [ -s "${tmp}/new" ]; then
        if [ "${accept_new}" -eq 0 ]; then
          refused=1
          printf 'shell-lint: REFUSING to re-baseline — this tree carries findings the ledger does not:\n' >&2
        else
          printf 'shell-lint: --accept-new: recording these findings as debt, deliberately:\n' >&2
        fi
        awk -F'\t' '{ printf "  %s  %s  baseline %s -> now %s\n", $2, $3, $4, $5 }' "${tmp}/new" >&2
        [ "${accept_new}" -eq 1 ] || printf 'shell-lint: writing the ledger now would record every one of those as accepted debt, and the total would barely move. Fix them, or annotate the LINE with a "# shellcheck disable=<code>" directive carrying the reason (which is a narrowing of the measurement, recorded here the same way, with --accept-shrink). If they really are debt you mean to record, say so deliberately: --accept-new.\n' >&2
      fi

      # And the symmetric half: re-baselining over a NARROWED measurement is how coverage leaves
      # behind a green check. Same shape of decision, same shape of override.
      comm -23 "${tmp}/ledgerpop" "${tmp}/curpop" > "${tmp}/lostpop"
      cfg_delta "${tmp}/ledgercfg" "${tmp}/curcfg" | grep '^NARROW' > "${tmp}/cfgnarrow" || true
      if [ -s "${tmp}/lostpop" ] || [ -s "${tmp}/cfgnarrow" ]; then
        if [ "${accept_shrink}" -eq 0 ]; then
          refused=1
          printf 'shell-lint: REFUSING to re-baseline — this tree measures LESS than the ledger records:\n' >&2
        else
          printf 'shell-lint: --accept-shrink: recording this narrowed measurement, deliberately:\n' >&2
        fi
        sed 's/^/  population: /' "${tmp}/lostpop" >&2
        awk -F'\t' '{ print "  " $2 }' "${tmp}/cfgnarrow" >&2
        [ "${accept_shrink}" -eq 1 ] || printf 'shell-lint: writing the ledger now would record the narrower measurement as the new normal, with nothing naming what stopped being analysed. If the narrowing is what you meant — a script deleted or renamed, a directive added on purpose, the analyser pin moved — say so deliberately: --accept-shrink.\n' >&2
      fi
      [ "${refused}" -eq 0 ] || die 'nothing was written. Re-run with the override(s) named above if these are decisions you mean to record.'
    fi
    {
      printf '# shell-lint baseline — the WHOLE measurement this repo gates on, regenerated by\n'
      printf '# "bin/shell-lint.sh --update-baseline". Hand edits are pointless: the next run of that\n'
      printf '# command overwrites them. WHAT THIS IS: a debt ledger of findings that existed when the\n'
      printf '# lane landed, so the lane starts green and reds on the NEXT one. Nothing here has been\n'
      printf '# judged correct. Read the header of bin/shell-lint.sh for why it is keyed by count and\n'
      printf '# not by line, and for what a FALLING count does and does not prove.\n'
      printf '#\n'
      printf '# A COUNT IS A FUNCTION OF THREE INPUTS, and all three are recorded here BECAUSE A CHANGE\n'
      printf '# TO ANY OF THEM MOVES EVERY COUNT while nothing in the code moved:\n'
      printf '#   "#cfg"  the analyser version, the exact invocation, the codes that analyser actually\n'
      printf '#           emits over a fixed probe script, and every "# shellcheck" directive inside a\n'
      printf '#           population file. A .shellcheckrc, a SHELLCHECK_OPTS, a file-scope disable or\n'
      printf '#           a severity floor each drive counts to zero exactly as a fix does, and this\n'
      printf '#           block is the only record that tells those apart.\n'
      printf '#   "#pop"  the file population the counts were measured over.\n'
      printf '#   rows    file<TAB>ShellCheck code<TAB>count.\n'
      printf '# bin/shell-lint.sh re-derives all of it on every run and REFUSES to report on a tree that\n'
      printf '# measures less than this file records. Narrowing it takes a typed --accept-shrink, the\n'
      printf '# same way adding debt to it takes a typed --accept-new.\n'
      cat "${tmp}/curcfg"
      awk '{ print "#pop\t" $0 }' "${tmp}/files"
      cat "${tmp}/current"
    } > "${baseline}"
    printf 'shell-lint: wrote %s (%s file(s), %s finding(s), %s configuration row(s))\n' \
      "${baseline}" "$(wc -l < "${tmp}/files")" "$(wc -l < "${tmp}/findings")" "$(wc -l < "${tmp}/curcfg")" >&2
    exit 0
  fi

  [ -r "${baseline}" ] || die "baseline ${baseline} is missing — run bin/shell-lint.sh --update-baseline"

  # THE COVERAGE CHECK. The population and the analyser's configuration are both derived fresh on
  # every run; without this they were compared to nothing, so a file dropping out of the set, a
  # directive appearing, or an analyser told to keep quiet about a code all read as classes
  # "improving", behind a smaller measurement that nothing compared to anything.
  awk -F'\t' '$1 == "#pop" { print $2 }' "${baseline}" | sort -u > "${tmp}/ledgerpop"
  [ -s "${tmp}/ledgerpop" ] || die "baseline ${baseline} carries no #pop population block, so a file leaving coverage could not be detected at all — regenerate it with bin/shell-lint.sh --update-baseline"
  awk -F'\t' '$1 == "#cfg"' "${baseline}" > "${tmp}/ledgercfg"
  [ -s "${tmp}/ledgercfg" ] || die "baseline ${baseline} carries no #cfg block, so the analyser's configuration — the one input to this measurement that can be narrowed without touching any file this ledger names — is recorded nowhere and compared to nothing. Regenerate it with bin/shell-lint.sh --update-baseline."
  assert_ledger_coherent "${baseline}" "${tmp}/ledgerpop" "${tmp}"

  comm -23 "${tmp}/ledgerpop" "${tmp}/curpop" > "${tmp}/lostpop"
  cfg_delta "${tmp}/ledgercfg" "${tmp}/curcfg" > "${tmp}/cfgdelta"
  grep '^NARROW' "${tmp}/cfgdelta" > "${tmp}/cfgnarrow" || true
  grep '^WIDEN'  "${tmp}/cfgdelta" > "${tmp}/cfgwiden"  || true
  if [ -s "${tmp}/lostpop" ] || [ -s "${tmp}/cfgnarrow" ]; then
    printf 'shell-lint: THIS TREE MEASURES LESS THAN %s RECORDS:\n' "${baseline}" >&2
    sed 's/^/  population: this file is named in the ledger and is not in the population derived here: /' "${tmp}/lostpop" >&2
    awk -F'\t' '{ print "  " $2 }' "${tmp}/cfgnarrow" >&2
    die 'coverage leaving is the one change a finding count can never show: every class it touches falls to zero exactly as a fix does, behind a green check, and the run that did it is the run that asks you to commit the smaller ledger. If this narrowing is deliberate — a script deleted or renamed, a directive added on purpose, the analyser pin moved — record it IN THE SAME CHANGE and deliberately: bin/shell-lint.sh --update-baseline --accept-shrink, then commit the ledger with it.'
  fi
  comm -13 "${tmp}/ledgerpop" "${tmp}/curpop" > "${tmp}/newpop"
  if [ -s "${tmp}/newpop" ] || [ -s "${tmp}/cfgwiden" ]; then
    printf 'shell-lint: this tree measures MORE than the ledger records — analysed on this run, and recorded in the ledger at the next --update-baseline:\n' >&2
    sed 's/^/  population: /' "${tmp}/newpop" >&2
    awk -F'\t' '{ print "  " $2 }' "${tmp}/cfgwiden" >&2
  fi

  delta_against_baseline "${baseline}" "${tmp}/current" > "${tmp}/delta"
  grep '^NEW' "${tmp}/delta" > "${tmp}/new" || true
  grep '^GONE' "${tmp}/delta" > "${tmp}/gone" || true

  local unexplained=0
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
      unexplained=1
      printf 'shell-lint: UNEXPLAINED DECREASE — verify no finding was swapped in:\n' >&2
      awk -F'\t' '{ printf "  %s  %s  %s -> %s\n", $2, $3, $4, $5 }' "${tmp}/unexplained" >&2
      : > "${tmp}/survivors"
      while IFS=$'\t' read -r _ file code _ _; do
        print_class_findings "${tmp}/findings" "${file}" "${code}" >> "${tmp}/survivors"
      done < "${tmp}/unexplained"
      # A class that this run emptied has no surviving finding to read, and the heading over an
      # empty list would ask for a check that cannot be made. That is now the ONLY way this list is
      # empty: the match above is literal, so a path is never silently missed by it.
      if [ -s "${tmp}/survivors" ]; then
        printf '\nthe finding(s) still standing in those classes:\n' >&2
        cat "${tmp}/survivors" >&2
      fi
      printf '\nshell-lint: this ledger is keyed by COUNT, so a class that falls without reaching zero — or\nany fall in a run that also carries NEW findings — reads exactly like a regression that rode in\nunder a co-located fix. Where findings are listed above, read them and confirm they are the ones\nthe ledger recorded. No re-baseline is asked for here.\n' >&2

      # SURFACE IT WHERE THE RUN IS ACTUALLY READ. This text costs the job no exit code, and a step
      # that exits 0 renders COLLAPSED in the GitHub UI — so on a green job it was reaching nobody.
      # An annotation and a job-summary block both render under a green check.
      if [ -n "${GITHUB_ACTIONS:-}" ]; then
        while IFS=$'\t' read -r _ file code base cur; do
          printf '::warning file=%s::shell-lint: %s fell from %s to %s with findings still standing. A count keyed by (file, code) cannot tell a fix from a swap — read the surviving findings in the job log.\n' \
            "${file}" "${code}" "${base}" "${cur}"
        done < "${tmp}/unexplained"
      fi
      if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
        {
          printf '### shell-lint: unexplained decrease\n\n'
          printf 'These (file, code) classes fell without reaching zero, or fell in a run that also carries new findings. A count keyed by (file, code) cannot tell a fix from a swap, so somebody has to read them.\n\n'
          printf '```\n'
          awk -F'\t' '{ printf "%s  %s  %s -> %s\n", $2, $3, $4, $5 }' "${tmp}/unexplained"
          if [ -s "${tmp}/survivors" ]; then
            printf '\nthe finding(s) still standing in those classes:\n'
            cat "${tmp}/survivors"
          fi
          printf '```\n'
        } >> "${GITHUB_STEP_SUMMARY}"
      fi
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
      print_class_findings "${tmp}/findings" "${file}" "${code}" >&2
    done < "${tmp}/new"
    printf '\nshell-lint: fix them, or — if the finding is understood and accepted — annotate the line\nwith a "# shellcheck disable=<code>" directive carrying the reason, ON THE LINE, never at file\nscope: a file-level disable blinds the whole (file, code) class, and this gate REFUSES one at\nexit 2 for exactly that reason. A line-scope directive IS recorded, in the ledger'"'"'s #cfg block —\nit is a piece of this measurement, so the same change runs "bin/shell-lint.sh --update-baseline\n--accept-shrink" and commits the ledger, which is what makes the annotation a decision somebody\ntyped. Re-baselining to make a NEW finding go away is the one thing this gate exists to prevent,\nand --update-baseline refuses to do it.\n' >&2
    exit 1
  fi

  if [ "${unexplained}" -eq 1 ]; then
    printf 'shell-lint: no NEW findings over %s file(s) — but the UNEXPLAINED DECREASE above is this run'"'"'s result, and it needs a human read. Not called clean.\n' \
      "$(wc -l < "${tmp}/files")" >&2
    exit 0
  fi

  printf 'shell-lint: clean — %s file(s), %s baselined finding(s), no new ones.\n' \
    "$(wc -l < "${tmp}/files")" "$(wc -l < "${tmp}/findings")" >&2
  exit 0
}

main "$@"

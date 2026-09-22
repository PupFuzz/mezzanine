#!/usr/bin/env bash
# deploy-gate-inputs.sh — does THIS REPOSITORY pass the gates bin/deploy.sh's phase A holds the release
# it is handed to? card#9637, card#9745
#
# WHY IT EXISTS. `bin/deploy.sh`'s A12 required `server/package-lock.json` — a file this repository
# had never contained — so EVERY real `bin/deploy.sh --ref <anything>` refused at phase A from the
# day that gate landed, and went on refusing through three fix rounds and four adversarial reviews
# of the script. Nothing caught it, and `bin/deploy.selftest.sh` structurally could not:
# it builds FIXTURE repositories and runs the gates against those, and its fixtures MINT a lockfile
# (`printf '{"lockfileVersion":3}'`). It therefore proves the gate behaves correctly given a
# well-formed release and says nothing about whether THIS repo is one. **The suite's fixtures were
# more complete than the repository.** That asymmetry is what this file closes — not the lockfile,
# which card#9631 committed.
#
# WHAT IT DOES (card#9745). It RUNS the deploy's own target-tree gates over the commit under test —
# the functions `bin/deploy.sh` at that commit declares host-free in GATE_TREE_READERS — with the
# deploy's READ LEDGER on, and reports what each one read and whether it passed. What these gates
# refuse depends on the commit alone, so a gate that refuses here means a real
# `bin/deploy.sh --ref <commit>` refuses at phase A whatever host runs it, and this exits 1 with the
# deploy's own words for it.
#   ⛔ SO THIS LANE ASSERTS THE GATES' CONTENT PREDICATES, NOT ONLY THE PRESENCE OF THEIR FILES — an
#   `ALTER` on `events` with no `ALGORITHM=`, a `trustProxies('*')`, a PHP constraint A6 cannot
#   evaluate, a lockfileVersion A12 cannot map. That is an operator ruling (card#9745 comment 5701):
#   each of those refuses the production deploy of the commit, and a PR is a cheaper place to meet it.
#
# THE POPULATION IS OBSERVED, NEVER PARSED. Until card#9745 this file answered "which paths does phase
# A read" by PARSING `bin/deploy.sh`'s source text, and three cards (card#9637, card#9693, card#9644)
# paid for a derivation that a line wrap or a carve could break. Now nothing reads lines: `git_at`, the
# one function through which the deploy starts git, appends each call to a ledger when
# MEZZ_GIT_READ_LEDGER names one — the function that asked, the rev and the path, taken from the
# arguments git actually received (§ git_read_ledger_note in bin/deploy.sh). A path held in a variable,
# built at run time or named by the tree itself (A10's migrations) is recorded like any other, because
# the ledger sees the call and not the line.
#
# ⛔ AND THE LEDGER IS ITSELF CHECKED, ON EVERY RUN. A git process that did not go through `git_at` would
# be absent from the ledger AND from this report — a green over a read nobody saw. So each gate runs with
# a `git` shim first on PATH that writes down each git process started through PATH, in the ledger's own words, and
# the two lists must be the same list: a git process the ledger does not hold, or a ledger call no git
# process answered, stops this check (exit 2) and is printed. The other two claims the declaration rests
# on are held by `bin/deploy.selftest.sh § card#9745`, where a full phase A runs against a stub host:
# that the declared functions are exactly the ones phase A reads through, and that each host-free one
# reads the same run alone as inside phase A.
#
# RESIDUAL ESCAPES ARE NAMED IN ONE PLACE, AND THIS COMMENT IS NOT IT (canon: name what you cannot
# verify — once). They are the `NOT PROVED BY A GREEN` block this script PRINTS on every run, so the CI
# log of the run being trusted carries them. The workflow header and the changelog point there.
#
# THE DEPLOY SCRIPT AT THE COMMIT UNDER TEST is the one run: the question is whether a release passes
# ITS OWN gates, so a release that changed a gate is judged by the changed gate.
#
# NO HOST, NO DATABASE, NO NETWORK, NO CHECKOUT, NO CREDENTIAL. The gates it runs read the object
# database and nothing else, which is what `host-free` promises in bin/deploy.sh; the halves that compare
# the release against a host are not run, and the run's own output names each of them.
#
# USAGE
#   bin/deploy-gate-inputs.sh [--ref <rev>]     # default: HEAD
#
#   ⚠ ON A `pull_request` EVENT, `HEAD` IS THE MERGE COMMIT GitHub builds — not the PR head — so
#   the short sha printed below will not be the one the PR page shows, and that is deliberate:
#   whether the MERGE RESULT is deployable is the stronger question. Pass `--ref <sha>` to ask
#   about a specific commit instead.
#
# EXIT CODES — "the repo is not deployable" and "this check could not speak" are different events:
#   0  every gate <rev>'s bin/deploy.sh declares host-free RAN over <rev> and passed, and each git
#      process those runs started through PATH was a git_at call in the ledger
#   1  a gate REFUSED <rev> — a real `bin/deploy.sh --ref <rev>` refuses at phase A, whatever host runs it
#   2  the check could not run: a command line it could not use (`--ref` with no value, an unknown
#      argument, a <rev> that names no commit), no bin/deploy.sh at <rev> or no declaration in it, a
#      declared function it does not define, a gate that crashed or whose read of git could not be
#      established, a gate that read nothing or read through a function not declared host-free, or a
#      git process the ledger does not account for

set -Eeuo pipefail

ME="$(basename "${BASH_SOURCE[0]}")"
REV="HEAD"

die() { printf '\n⛔ %s — %s\n' "$ME" "$1" >&2; shift; local l; for l in "$@"; do printf '   %s\n' "$l" >&2; done; exit 2; }

# The `E` of `set -E` is what carries this trap into the functions and command substitutions below.
# Without it the flag is inert, and an unexpected failure would leave the shell exiting on that
# command's OWN status. Status 1 is this check's word for "a gate REFUSED the release", a finding about
# the RELEASE: a crash would be read as a deploy-blocking verdict nobody measured. Every real finding
# leaves by `exit 1` or `die`, neither of which trips this.
trap 'rc=$?; printf "\n⛔ %s — this check FAILED at line %s (status %s) and established NOTHING about the tree.\n   This is not a finding about the release.\n" "$ME" "$LINENO" "$rc" >&2; exit 2' ERR

# A bad COMMAND LINE leaves by `die` too (card#9831): it is "this check could not speak", never a
# finding. An option's value is tested, not expanded with `${2:?}` — that fails as a parameter
# expansion, a shell error the ERR trap above never sees, and the shell then exits 1.
while [ $# -gt 0 ]; do
  case "$1" in
    --ref)
      { [ $# -ge 2 ] && [ -n "$2" ]; } || die "$1 needs a value" "run \`$ME --help\` for usage"
      REV="$2"; shift 2 ;;
    -h|--help) sed -n '/^# USAGE/,/^$/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "unknown argument: $1" "run \`$ME --help\` for usage" ;;
  esac
done

SHA="$(git rev-parse --verify --quiet "$REV^{commit}")" \
  || die "'$REV' does not resolve to a commit in this repository"
SHORT="$(git rev-parse --short "$SHA")"
TOP="$(git rev-parse --show-toplevel)"
REAL_GIT="$(command -v git)"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# ── bin/deploy.sh AT THE COMMIT, in a directory of its own ─────────────────────────────────────
# It sources bin/supervision.sh from beside itself, and in a deploy that copy is the SERVING release's.
# There is no serving release here, so the copy beside it is EMPTY, deliberately: no host-free function
# runs anything out of it, and one that started to would fail here, loudly, rather than pass on a copy
# of the file that is not the one a deploy would have in its place.
mkdir -p "$WORK/bin" "$WORK/shim" "$WORK/runs"
git show "$SHA:bin/deploy.sh" > "$WORK/bin/deploy.sh" \
  || die "bin/deploy.sh is not readable at $SHORT" \
         "This check runs the gates that file declares. Without it nothing was measured."
: > "$WORK/bin/supervision.sh"

# The shim. A git process a gate run starts through PATH passes through it — it is first there — and it writes
# the arguments that process received, `%q`-quoted and space-joined, which is exactly how the ledger
# writes a git_at call. Then it becomes the real git.
{
  printf '#!/usr/bin/env bash\nREAL_GIT=%q\n' "$REAL_GIT"
  # shellcheck disable=SC2016  # the shim's own source, expanded when the shim runs
  printf '%s\n' 'printf -v a "%q " "$@"; printf "%s\n" "${a% }" >> "$MEZZ_GATE_GIT_PROCS"' 'exec "$REAL_GIT" "$@"'
} > "$WORK/shim/git"
chmod +x "$WORK/shim/git"

# lib_run <out> <ledger> <procs> <function> [argument…] — bin/deploy.sh SOURCED in a bash process of its
# own, then <function> [argument…], with the ledger on and the shim first on PATH.
#   · A PROCESS OF ITS OWN, never a subshell of this one: a gate refuses by `exit`, and run as a
#     subshell inside `|| rc=$?` bash would ignore `set -e` in EVERY command of it, so the gate would not
#     be running the way phase A runs it.
#   · git_read_failed is the deploy's word for "git could not read this path of the release" — a read
#     that FAILED, as against a path that is not there. Phase A answers it with a refusal (through
#     not_established), which here would be read as a finding about the release. It is not one, so in
#     this process it exits 3, which this check reports as its own failure (exit 2). ⚠ NOT
#     not_established itself: that exit also carries git_read_at's refusal of an entry that is not a
#     regular file — a symlink committed where a file belongs — which IS a finding about the release,
#     and must stay a refusal. Nothing else of the deploy is replaced. If git_read_failed is renamed,
#     this replacement stops applying, and bin/deploy-gate-inputs.selftest.sh's failed-read case reds.
#   · The command is held in variables ACROSS the source: a file sourced with no arguments shares its
#     caller's positional parameters, and bin/deploy.sh's library mode clears them (`set --`).
lib_run() {
  local out="$1" ledger="$2" procs="$3"; shift 3
  : > "$ledger"; : > "$procs"
  # shellcheck disable=SC2016  # the inner script, expanded by the bash it is handed to
  env MEZZ_DEPLOY_ROOT="$TOP" MEZZ_GIT_READ_LEDGER="$ledger" MEZZ_GATE_GIT_PROCS="$procs" \
      PATH="$WORK/shim:$PATH" "$BASH" -c '
    __d="$1"; shift; __cmd=("$@")
    . "$__d"
    git_read_failed() {
      printf "\n⛔ NOT ESTABLISHED — git could not read %s at %s (\`git %s\` exited %s)\n" "$3" "$2" "$1" "$4" >&2
      exit 3
    }
    "${__cmd[@]}"
  ' gate-run "$WORK/bin/deploy.sh" "$@" > "$out" 2>&1
}

# ── the declaration ───────────────────────────────────────────────────────────────────────────
rc=0
# shellcheck disable=SC2016  # expanded by the bash lib_run starts
# One line per row: whether the function it names is DEFINED, a tab, then the row as written.
lib_run "$WORK/declared" "$WORK/ledger.decl" "$WORK/procs.decl" eval \
  'declare -p GATE_TREE_READERS >/dev/null 2>&1 || exit 4
   for __r in "${GATE_TREE_READERS[@]}"; do
     if declare -F "${__r%% *}" >/dev/null; then printf "defined\t%s\n" "$__r"; else printf "undefined\t%s\n" "$__r"; fi
   done' \
  || rc=$?
[ "$rc" -ne 4 ] || die "bin/deploy.sh at $SHORT declares no GATE_TREE_READERS" \
  "This check runs the gates that declaration names as host-free. A commit whose deploy script" \
  "predates it (card#9745) is not one this check can speak about."
[ "$rc" -eq 0 ] || die "bin/deploy.sh at $SHORT could not be sourced to read its declaration (exit $rc)" \
  "What it printed:" "$(sed 's/^/  | /' "$WORK/declared")"

HOSTFREE=(); NOTRUN=()
while IFS=$'\t' read -r defined row; do
  [ -n "$row" ] || continue
  fn="${row%% *}"; how="${row#* }"
  [ "$defined" = defined ] || die "bin/deploy.sh at $SHORT declares $fn in GATE_TREE_READERS and defines no such function" \
    "A declaration naming a function that is not there is a declaration nothing can hold true."
  if [ "$how" = host-free ]; then HOSTFREE+=("$fn"); else NOTRUN+=("$fn — $how"); fi
done < "$WORK/declared"
[ "${#HOSTFREE[@]}" -gt 0 ] || die "bin/deploy.sh at $SHORT declares no host-free reader in GATE_TREE_READERS" \
  "An empty population certifies an entire tree in one line, so it is refused rather than reported" \
  "as a pass."

# ── run every host-free gate ──────────────────────────────────────────────────────────────────
printf 'bin/deploy.sh phase A — its target-tree gates, RUN over %s\n' "$SHORT"
printf '  declared host-free by bin/deploy.sh at %s (GATE_TREE_READERS): %s\n\n' "$SHORT" "${HOSTFREE[*]}"

refused=(); n_procs=0
for fn in "${HOSTFREE[@]}"; do
  out="$WORK/runs/$fn.out" ledger="$WORK/runs/$fn.ledger" procs="$WORK/runs/$fn.procs"
  rc=0; lib_run "$out" "$ledger" "$procs" "$fn" "$SHA" || rc=$?
  body="$(sed '/^[[:space:]]*$/d; s/^/      | /' "$out")"

  # How it ended. A refusal is the deploy's whole refusal contract — exit 1, the `⛔ REFUSED —` banner
  # and the closing promise — and nothing less: an exit 1 without them is a crash wearing the code.
  case "$rc" in
    0) verdict=passed ;;
    1) if grep -q '^⛔ REFUSED — ' "$out" && grep -q 'Nothing was changed\.' "$out"; then
         verdict=REFUSED; refused+=("$fn")
       else
         die "$fn exited 1 over $SHORT without the deploy's refusal — it crashed, and established nothing" \
           "What it printed:" "$body"
       fi ;;
    3) die "$fn could not establish a read of $SHORT out of git" \
         "This is not a finding about the release: git could not answer. What it printed:" "$body" ;;
    *) die "$fn exited $rc over $SHORT — a crash, not a refusal, and it established nothing" \
         "What it printed:" "$body" ;;
  esac

  # ⛔ EVERY git PROCESS WAS A git_at CALL. Two lists of the same events, from two ends: the ledger's
  # `call` lines (written by git_at) and the shim's (written by each git process that started).
  cut -f3- < <(grep '^call' "$ledger" || true) | sort > "$WORK/calls"
  sort "$procs" > "$WORK/procs"
  if [ "$(cat "$WORK/calls")" != "$(cat "$WORK/procs")" ]; then
    detail=()
    while IFS= read -r l; do detail+=("git process with NO git_at call:   git $l"); done < <(comm -13 "$WORK/calls" "$WORK/procs")
    while IFS= read -r l; do detail+=("git_at call that NO git process answered through the shim:   git $l"); done < <(comm -23 "$WORK/calls" "$WORK/procs")
    die "$fn started $(wc -l < "$WORK/procs") git process(es) and the ledger holds $(wc -l < "$WORK/calls") git_at call(s)" \
      "${detail[@]}" "" \
      "A read of the release is made through git_at, which is what the ledger records; a git process" \
      "that is not is a read this check cannot see. Start it with git_at. A call no process answered" \
      "means the shim was bypassed, so the count above measured nothing."
  fi
  n_procs=$((n_procs + $(wc -l < "$WORK/procs")))

  # What it READ, and through whom. Every read of the commit, attributed to this function or to another
  # function the declaration makes host-free, and to nothing else.
  reads="$(awk -F'\t' '$1 == "read"' "$ledger")"
  [ -n "$reads" ] || die "$fn is declared a reader of the release by bin/deploy.sh at $SHORT, and read nothing here" \
    "A declared reader that reads nothing is a declaration that has gone stale, or a run that did not" \
    "happen. What it printed:" "$body"
  while IFS=$'\t' read -r _ who rev path; do
    [ "$rev" = "$SHA" ] || die "$fn read $rev:$path — a rev that is not the commit under test ($SHA)" \
      "A host-free gate reads the release it is handed and nothing else; a read of another rev is a read" \
      "of this host's checkout, which a runner does not have. Hand it the commit, or declare it not host-free."
    case " ${HOSTFREE[*]} " in
      *" $who "*) ;;
      *) die "$fn read $path through $who, which bin/deploy.sh at $SHORT does not declare host-free" \
           "Add $who to GATE_TREE_READERS, in the change that makes it read the release." ;;
    esac
  done <<< "$reads"

  printf '  %-8s %s\n' "$verdict" "$fn"
  awk -F'\t' -v f="$fn" '{ print "      read  " $4 (($2 != f) ? "   (through " $2 ")" : "") }' <<< "$reads" | sort -u
  [ ! -s "$out" ] || printf '%s\n' "$body"
done

printf '\n  git processes those runs started through PATH: %d, each one a git_at call in the ledger\n' "$n_procs"

# ── what is declared and not run here, in the deploy's own words ─────────────────────────────────
if [ "${#NOTRUN[@]}" -gt 0 ]; then
  printf '\n  declared readers of a tree NOT RUN here — bin/deploy.sh at %s says why:\n' "$SHORT"
  printf '    %s\n' "${NOTRUN[@]}"
fi

cat <<'EXCLUDED'

  NOT RUN HERE, and why — each needs something CI has no business having or faking:
    A0 A1 A1b A1c A2 A3     properties of the deploy HOST and its checkout (uid, installed
    A3b A3c A4 A9           binaries and their versions, timing knobs, the failure marker, git,
                            the remotes, a clean prod tree, the currently-deployed sha). A CI
                            runner is not that host.
    A5                      reads the live host's server/.env. There is none on a runner, and
                            fabricating one would assert a production posture that is not measured.
    A7 A8                   fetch, and "is this commit contained in origin/main". A branch under
                            test deliberately is not. This lane asserts DEPLOYABILITY, not
                            release-readiness; requiring the latter would red every dev PR.
    A6 A6b A12 (comparison) the host's php, bash and npm against the floors the release declares.
                            On a runner they are the runner's, so a green would be a claim about
                            the runner. The floors themselves ARE read and judged above.
    A10b (comparison)       the release's .env.example keys against the host's .env.
    A13 (install plan)      runs the release's own crontab install against THIS user's crontab
                            (`crontab -l`) and compares its lock files with the serving
                            release's. Its bin/supervision.sh IS read and judged above.
    A14                     PHP-FPM, a dedicated stream pool over cgi-fcgi, and the vhost docroot.
EXCLUDED

# ── the one home for what a green does NOT establish ───────────────────────────────────────────
# Printed on every run, green or red, because the log of the run being trusted is the surface a
# maintainer actually reads. Every other surface — this script's own header, the workflow header, the
# changelog — points HERE rather than keeping a copy: four copies of the earlier list existed and all
# four were incomplete.
cat <<'LIMITS'

  WHAT A GREEN HERE PROVES, AND WHAT IT DOES NOT — the one home for this list (card#9637, card#9745):

  PROVED. Every function the bin/deploy.sh above declares host-free was RUN over this commit, with
    the arguments phase A gives it, and passed — its content predicates included, not only the
    presence of its files. Every path it read is printed above, taken from the calls git received.
    Every git process those runs started through PATH was a git_at call: a git shim first on PATH
    wrote down each one, and its list and the ledger's were the same list.

  NOT PROVED:
    * that the declaration names EVERY function phase A reads the release through. This check runs
      what is declared. bin/deploy.selftest.sh § card#9745 holds the declaration to a full phase A
      run (LEG 1) — over its CONTROL FIXTURE, on the path that run takes: a read reached only on a
      branch that run does not take (a refusal's own path, a host state the fixture lacks) is seen
      by neither.
    * that a host-free function reads the same inside phase A as when run alone. The same file
      checks that (LEG 2), over its fixture's commit, not over this one.
    * anything a gate decides about a HOST — every row of the block above.
    * a git process started by an absolute path (`/usr/bin/git …`) or without PATH, and any read of
      the object database by a program that is not git: the shim is found on PATH, and the ledger
      records git_at's calls. A read of bin/deploy.sh's text for card#9745 found no git started
      any other way; nothing re-reads its text for that on each run.
    * a read of the release that does not go through git at all — a gate reading the checkout's
      working tree instead of the commit it was handed.
LIMITS

if [ "${#refused[@]}" -gt 0 ]; then
  printf '\n⛔ %s — bin/deploy.sh REFUSES %s at phase A: %s\n' "$ME" "$SHORT" "${refused[*]}" >&2
  cat >&2 <<END

   This is the deploy's OWN gate, run early — not a rule of this lane. What it refuses
   depends on the commit alone, so a real \`bin/deploy.sh --ref $SHORT\` refuses at phase A
   whatever host runs it — at this gate, if no host check before it stops it first — before
   anything is touched, and nothing of this commit deploys until it passes. The gate's own words are
   printed above under its name, including the line it prints on a host
   ("Nothing was changed…"), which describes the deploy it stopped.
   The gate is not the defect: fix what it names, or change the gate deliberately.
END
  exit 1
fi

printf '\n✅ %s — every target-tree gate bin/deploy.sh declares host-free passes at %s\n' "$ME" "$SHORT"
printf '   (what that does and does not establish is printed above, under NOT PROVED BY A GREEN)\n'

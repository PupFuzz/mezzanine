#!/usr/bin/env bash
# deploy-gate-inputs.sh — does THIS REPOSITORY carry what bin/deploy.sh's phase A demands of the
# release it is handed? card#9637
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
# WHAT IT CHECKS, AND WHAT IT DOES NOT. Phase A's target-tree gates read the release out of the
# object database. Each such read is a PATH the release must carry, and for several of them an
# absent path is an unconditional refusal. This checks that population — *the files phase A reads
# out of the target tree* — against the repository's own tree at the commit under test.
#
# It does NOT evaluate the gates' content predicates (A6's constraint shape, A10's `ALGORITHM=`
# declaration, A11's `trustProxies('*')`, A13's crontab render). Those live INLINE in `phase_a`,
# which is one straight-line function reachable only by running the whole script, and the whole
# script refuses at A5 without a production `server/.env`. Evaluating them here would mean either
# rebuilding the selftest's stub host (a second copy of it) or RESTATING the gates in this file —
# and a restated copy of a deploy precondition is precisely the defect card#9203 filed, which A6's
# own comment in `bin/deploy.sh` names. Neither is done. What is needed instead is a seam in
# `bin/deploy.sh` that exposes its target-tree gates as callable units; that is filed, not carved
# here. Until it exists this file covers the presence population and says so, loudly, below.
#
# THE POPULATION IS DERIVED, NEVER WRITTEN DOWN. A list of required paths typed into this file is a
# restatement that drifts the moment `bin/deploy.sh` adds a gate — the same shape as the bug above.
# So the reads are derived, every run, from the `bin/deploy.sh` **at the commit under test**, by
# finding its `git_read_at`/`git_ls_at` call sites against `"$SHA"`. What IS written here is the
# far smaller judgement the source text cannot answer: whether an ABSENT path makes that gate
# refuse, warn, or pass. That table is GUARDED — a derived read this file has not classified, or a
# classification whose read is gone, stops the check (exit 2) rather than letting it quietly cover
# less than it did yesterday.
#
# DERIVING FROM THE COMMIT UNDER TEST is deliberate: the question is whether a release satisfies
# ITS OWN deploy script, so a release that changed the gate is judged by the changed gate.
#
# NO HOST, NO DATABASE, NO NETWORK, NO CHECKOUT, NO CREDENTIAL. git and bash over the object
# database. It never reads `server/.env` — on a CI runner there is none, and on a host there is one
# this check has no business opening.
#
# USAGE
#   bin/deploy-gate-inputs.sh [--ref <rev>]     # default: HEAD
#
# EXIT CODES — "the repo is not deployable" and "this check could not speak" are different events:
#   0  every required input is present at <rev>
#   1  a required input is MISSING — a real `bin/deploy.sh --ref <rev>` refuses at phase A
#   2  the check could not run: no bin/deploy.sh at <rev>, no reads derived from it (an empty
#      derivation is a measurement that never happened, never a pass), or the classification table
#      no longer matches the reads deploy.sh makes

set -Eeuo pipefail

ME="$(basename "${BASH_SOURCE[0]}")"
REV="HEAD"

die() { printf '\n⛔ %s — %s\n' "$ME" "$1" >&2; shift; local l; for l in "$@"; do printf '   %s\n' "$l" >&2; done; exit 2; }

while [ $# -gt 0 ]; do
  case "$1" in
    --ref)     REV="${2:?--ref needs a value}"; shift 2 ;;
    -h|--help) sed -n '/^# USAGE/,/^$/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) printf '%s: unknown argument: %s (try --help)\n' "$ME" "$1" >&2; exit 2 ;;
  esac
done

SHA="$(git rev-parse --verify --quiet "$REV^{commit}")" \
  || die "'$REV' does not resolve to a commit in this repository"
SHORT="$(git rev-parse --short "$SHA")"

# ── the classification ────────────────────────────────────────────────────────────────────────
# One row per literal path phase A reads out of the target tree, answering the ONE question the
# derivation cannot: what does `bin/deploy.sh` DO when that path is absent? Each verdict is read
# off the gate's own lines, cited here so a maintainer can check the row against them rather than
# against this sentence.
#
#   required           absent ⇒ the gate refuses. Present is enough; the content is not read here.
#   required-nonempty  absent OR empty ⇒ the gate refuses (`[ -n "$content" ] || refuse`).
#   optional           absent ⇒ the gate warns or passes, by its own reasoning. Reported, never failed.
#
# path <TAB> gate <TAB> rule <TAB> what bin/deploy.sh does when it is absent
CLASSIFIED=(
  "server/composer.json	A6	required-nonempty	refuses: it is where the PHP floor is declared, and composer would meet it inside the window instead"
  "server/database/migrations	A10	optional	passes, saying the release ships no migrations — git_ls_at's empty is a real answer"
  "server/.env.example	A10b	optional	warns that no key of the release was compared against the host's .env"
  "server/bootstrap/app.php	A11	required	refuses: server/artisan requires it, so every artisan command of that release fails inside the window"
  "server/package-lock.json	A12	required	refuses: npm ci needs it and package.json floats, so the prod asset build would not be reproducible"
  "bin/supervision.sh	A13	required-nonempty	refuses: the window installs the deployed release's crontab block from it"
)

# ── derive what phase A reads out of the target tree, from the deploy script at $SHA ───────────
DEPLOY_SRC="$(git show "$SHA:bin/deploy.sh")" \
  || die "bin/deploy.sh is not readable at $SHORT" \
         "This check derives its population from that file. Without it nothing was measured."

# The target-tree readers, called against $SHA. The primitives' own bodies read "$__rev" and so are
# not matched; a comment naming the functions is not a call site and does not match either.
# ONE pattern, used to FIND the call sites and to take the path out of them — two copies would be
# two notions of what a call site is, and the day they disagreed the check would find a read it
# could not then parse.
READ_RE='git_(read|ls)_at[[:space:]]+[A-Za-z_][A-Za-z0-9_]*[[:space:]]+"\$SHA"[[:space:]]+("[^"]*"|[^[:space:];]+)'
READS="$(printf '%s\n' "$DEPLOY_SRC" | grep -nE "$READ_RE" || true)"

[ -n "$READS" ] || die "no target-tree read was derived from bin/deploy.sh at $SHORT" \
  "This check finds phase A's reads by their git_read_at/git_ls_at call sites against \"\$SHA\"." \
  "Finding none means the readers were renamed, or the derivation no longer matches how they are" \
  "called — not that the deploy reads nothing. An empty population certifies an entire tree in one" \
  "line, so it is refused rather than reported as a pass."

# Split the derived reads into literal paths (a path this check can look up) and paths the deploy
# builds at run time (which it cannot, and says so by name rather than passing over them).
declare -a LITERAL_PATHS=() LITERAL_LINES=() DYNAMIC=()
while IFS= read -r line; do
  [ -n "$line" ] || continue
  lineno="${line%%:*}"
  arg="$(printf '%s\n' "$line" | sed -E "s/.*${READ_RE}.*/\\2/")"
  arg="${arg%\"}"; arg="${arg#\"}"
  case "$arg" in
    *'$'*) DYNAMIC+=("$lineno	$arg") ;;
    *)     LITERAL_PATHS+=("$arg"); LITERAL_LINES+=("$lineno") ;;
  esac
done <<< "$READS"

# ── the guard: the table and the derivation must name the same paths ───────────────────────────
derived_sorted="$(printf '%s\n' "${LITERAL_PATHS[@]}" | sort -u)"
classified_sorted="$(printf '%s\n' "${CLASSIFIED[@]}" | cut -f1 | sort -u)"
if [ "$derived_sorted" != "$classified_sorted" ]; then
  unclassified="$(comm -23 <(printf '%s\n' "$derived_sorted") <(printf '%s\n' "$classified_sorted") | tr '\n' ' ')"
  vanished="$(comm -13 <(printf '%s\n' "$derived_sorted") <(printf '%s\n' "$classified_sorted") | tr '\n' ' ')"
  detail=()
  [ -z "${unclassified// }" ] || detail+=("read by the deploy and NOT classified here: ${unclassified% }")
  [ -z "${vanished// }" ]     || detail+=("classified here and no longer read by the deploy: ${vanished% }")
  die "bin/deploy.sh at $SHORT reads a different set of target-tree paths than this check classifies" \
    "${detail[@]}" \
    "" \
    "Whether an absent path refuses, warns or passes is read off the gate; it cannot be derived." \
    "Classify the new read in CLASSIFIED above (or drop the row that went away). This stops rather" \
    "than covering less than it did yesterday without saying so."
fi

# ── judge the repository's own tree ────────────────────────────────────────────────────────────
printf 'bin/deploy.sh phase A — target-tree inputs at %s\n' "$SHORT"
printf '  population derived from bin/deploy.sh at %s: %d literal path(s), %d built at run time\n\n' \
  "$SHORT" "${#LITERAL_PATHS[@]}" "${#DYNAMIC[@]}"

missing=()
for row in "${CLASSIFIED[@]}"; do
  IFS=$'\t' read -r path gate rule why <<< "$row"
  # ls-tree exactly as bin/deploy.sh's own readers ask it: a literal pathspec, quoting off — and
  # with the SAME status rule (card#9608). ls-tree exits 0 for a pathspec that matches nothing, an
  # honest "not at this commit", and non-zero when it could not READ the trees it had to walk. A
  # `|| true` here would fuse those two, and this check would report a path as MISSING — a finding
  # about the release — on a read that never happened. That is the defect card#9608 ended in
  # bin/deploy.sh; it is not reintroduced here.
  rc=0
  entry="$(git -c core.quotePath=false ls-tree "$SHA" -- ":(literal)$path")" || rc=$?
  [ "$rc" -eq 0 ] || die "git could not read $path at $SHORT (\`git ls-tree\` exited $rc)" \
    "git's own error is above. Nothing was read, so nothing about $path at that commit is known —" \
    "this is not a finding about the release."
  if [ -z "$entry" ]; then
    case "$rule" in
      optional) printf '  ok      %-28s %-5s absent — %s\n' "$path" "$gate" "$why" ;;
      *)        printf '  MISSING %-28s %-5s %s\n' "$path" "$gate" "$why"
                missing+=("$path ($gate)") ;;
    esac
    continue
  fi
  mode="${entry%% *}"; rest="${entry#* }"; objsha="${rest#* }"; objsha="${objsha%%	*}"
  if [ "$rule" = required-nonempty ]; then
    # Same rule: a cat-file that FAILED is not a zero-byte blob.
    rc=0; size="$(git cat-file -s "$objsha")" || rc=$?
    [ "$rc" -eq 0 ] || die "git could not size $path's blob $objsha at $SHORT (\`git cat-file -s\` exited $rc)" \
      "Whether it is empty was not established, so it is not reported either way."
    if [ "$size" -eq 0 ]; then
      printf '  MISSING %-28s %-5s present but EMPTY (0 bytes) — %s\n' "$path" "$gate" "$why"
      missing+=("$path ($gate, empty)")
      continue
    fi
    printf '  ok      %-28s %-5s present, %s bytes, mode %s\n' "$path" "$gate" "$size" "$mode"
    continue
  fi
  if [ "$mode" = 040000 ]; then
    rc=0; listing="$(git -c core.quotePath=false ls-tree -r --name-only "$SHA" -- ":(literal)$path")" || rc=$?
    [ "$rc" -eq 0 ] || die "git could not list $path at $SHORT (\`git ls-tree -r\` exited $rc)" \
      "The file count under it was not established."
    n="$(printf '%s' "$listing" | grep -c . || true)"
    printf '  ok      %-28s %-5s present, %s file(s)\n' "$path" "$gate" "$n"
  else
    printf '  ok      %-28s %-5s present, mode %s\n' "$path" "$gate" "$mode"
  fi
done

# Named, not skipped: a read whose path the deploy assembles at run time is one this check cannot
# look up, and saying nothing about it would read as coverage (canon: name what you cannot verify).
if [ "${#DYNAMIC[@]}" -gt 0 ]; then
  printf '\n  not derivable — the deploy builds these paths at run time, so no fixed path exists to check:\n'
  for d in "${DYNAMIC[@]}"; do
    printf '    bin/deploy.sh:%s reads %s\n' "${d%%	*}" "${d#*	}"
  done
fi

# ── what this lane does NOT run, by name ───────────────────────────────────────────────────────
cat <<'EXCLUDED'

  NOT RUN HERE, and why — each needs something CI has no business having or faking:
    A0 A1 A1b A2 A3 A4 A9   properties of the deploy HOST and its checkout (uid, installed
                            binaries, timing knobs, the failure marker, a clean prod tree, the
                            currently-deployed sha). A CI runner is not that host.
    A5                      reads the live host's server/.env. There is none on a runner, and
                            fabricating one would assert a production posture that is not measured.
    A7 A8                   fetch, and "is this commit contained in origin/main". A branch under
                            test deliberately is not. This lane asserts DEPLOYABILITY, not
                            release-readiness; requiring the latter would red every dev PR.
    A10b (comparison half)  needs the host's .env to compare .env.example's keys against. Only the
                            target-tree read of server/.env.example is in scope above.
    A13 (crontab half)      needs this user's crontab, and compares the target's supervision_lock
                            against the SERVING release's. In CI they are the same file, so the
                            comparison would be a tautology dressed as a check.
    A14                     PHP-FPM, a dedicated stream pool over cgi-fcgi, and the vhost docroot.
    A6 (version half)       compares the release's PHP floor against the HOST's php. On a runner
                            that is the runner's php, so a green would be a claim about the runner
                            rather than about this repository.

  CONTENT PREDICATES not covered, pending a seam in bin/deploy.sh (card#9637): A6's constraint
  shape, A10's ALGORITHM= declaration on migrations that alter `events`, A11's trustProxies('*'),
  A13's render of the target release's crontab block. They are inline in phase_a, which runs only
  as a whole and refuses at A5 first. Restating them here would drift from the gate — the shape of
  the defect card#9203 filed.
EXCLUDED

if [ "${#missing[@]}" -gt 0 ]; then
  printf '\n⛔ %s — this repository does NOT satisfy bin/deploy.sh at %s\n' "$ME" "$SHORT" >&2
  printf '   missing: %s\n' "${missing[@]}" >&2
  cat >&2 <<END

   A real \`bin/deploy.sh --ref $SHORT\` REFUSES at phase A. Nothing would be deployed, on any host.
   The gate is not the defect: commit what the release is missing, or change the gate deliberately.
END
  exit 1
fi

printf '\n✅ %s — every target-tree input bin/deploy.sh phase A reads is present at %s\n' "$ME" "$SHORT"

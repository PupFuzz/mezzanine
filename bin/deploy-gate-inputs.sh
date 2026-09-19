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
# out of the target tree*, as far as the derivation below can see them — against the repository's
# own tree at the commit under test. Where it cannot see them is printed on every run, and the
# claim a green makes is exactly that narrow.
#
# It does NOT evaluate the gates' content predicates (A6's constraint shape, A10's `ALGORITHM=`
# declaration, A11's `trustProxies('*')`, A13's crontab render). Evaluating them here would mean
# either rebuilding the selftest's stub host (a second copy of it) or RESTATING the gates in this
# file — and a restated copy of a deploy precondition is precisely the defect card#9203 filed, which
# A6's own comment in `bin/deploy.sh` names. Neither is done.
#
# THE SEAM THAT MAKES A THIRD WAY POSSIBLE HAS LANDED, AND THIS FILE DOES NOT YET USE IT
# (card#9644). Those predicates are no longer inline in one straight-line `phase_a`: each is a
# top-level function of `bin/deploy.sh` taking the commit — `gate_a6_php_floor`,
# `gate_a10_migration_algorithm`, `gate_a11_trusted_proxies`, `gate_a13_target_plan` — reading only
# out of the object database, and that file no longer runs a deploy when it is SOURCED. Calling one
# from here CHANGES WHAT THIS LANE ASSERTS, so it is its own round of work and not a rider on the
# carve. Until that round lands this file covers the presence population and says so, loudly, below.
#
# THE POPULATION IS DERIVED, NEVER WRITTEN DOWN. A list of required paths typed into this file is a
# restatement that drifts the moment `bin/deploy.sh` adds a gate — the same shape as the bug above.
# So the reads are derived, every run, from the `bin/deploy.sh` **at the commit under test**. What
# IS written here is the far smaller judgement the source text cannot answer: whether an ABSENT
# path makes that gate refuse, warn, or pass.
#
# ⛔ AND THE DERIVATION STOPS ON WHAT IT SEES, RATHER THAN ONLY FINDING WHAT IT MATCHES. A
# derivation that only ever FINDS reads cannot protect anything: a read written in a shape its
# pattern misses is absent from the derived set AND from the guard's comparison, the two sides stay
# equal, and the run goes GREEN over a gate nobody checked. Measured on the first version of this
# file, six of eight realistic ways to add a read escaped with exit 0. So the derivation has two
# halves:
#
#   1. A STRICT pattern (`READ_RE`) that both FINDS a call site and takes the path out of it —
#      one pattern, never two, because two notions of what a call site is would disagree one day.
#   2. A deliberately LOOSE SUPERSET of lines that COULD be a read: every line calling a member of
#      the reader family, and every line naming `$SHA`/`${SHA}` in any quoting. **Every superset
#      line that `READ_RE` does not match stops this check (exit 2).** A shape this file cannot
#      read is never a shape it passes over.
#
#   The reader family is itself DERIVED, three ways, all unioned: the names `bin/deploy.sh` lists in
#   `git_read_call_site`'s own `case` — that function exists to walk its own reader frames, so the
#   list is maintained there for the deploy's own reasons, and it is read here as the CASE LABEL it
#   is, joined across the `\` continuations bash allows it — plus every function whose body invokes
#   `git_at ls-tree|show|cat-file`, which catches a reader added without touching that case, plus
#   every function that DELEGATES to one of those, because `git_ls_at`'s entire body is a call to
#   `_git_ls_at` and names no path of its own.
#   A line naming `$SHA` that runs some OTHER git subcommand is allowed only for the handful of
#   NON-READING subcommands named below; anything else — `cat-file`, `archive`, `log`, a subcommand
#   nobody has thought of yet — stops the check rather than being assumed harmless.
#
#   ⛔ AND BEING IN THAT FAMILY IS NOT BEING A READER OF THE RELEASE TREE (card#9693). This gate is
#   about ONE thing: a PATH read out of the tree under test. `git_read_call_site`'s list is the
#   deploy's FRAME-WALKING family, which is a different population — it grew to carry a ref
#   resolver, a read of an object by bare id and two refusal helpers, and every MENTION of one of
#   those then became a candidate read this check could not parse, the case list that defines them
#   included. So every candidate is RULED on from its own body, and each ruling is printed on every
#   run beside the name it was made about:
#     * it reads a PATH out of a tree — a git invocation carrying a `--` pathspec separator or an
#       argument holding a `:`, which are the only two ways git's CLI names a path inside a tree.
#       That is a property of git and not of this file, so it does not drift with bin/deploy.sh.
#       Such a function is a reader, and its call sites are the population above.
#     * it reads an object BY ID (`cat-file -t "$oid"`): no path can come back, so no call site of
#       it is a gate input.
#     * it runs only NON-READING subcommands (`rev-parse` over a ref name): the same.
#     * it runs no git at all — a refusal helper, which reads nothing by construction.
#   AMBIGUITY RESOLVES TOWARD READER: an invocation whose argument this check cannot tell apart is
#   ruled a path read, which STOPS the check rather than passing over it. And a name the family
#   list carries that this file defines no function for stops it too — there is no body to rule on,
#   and assuming either answer would be inventing one.
#
#   ⚠ WHAT THAT BUYS IS BOUNDED BY THE SUPERSET, AND THE BOUND IS NOT NARROW. A read the superset
#   does not SEE is invisible to the stop as well as to the guard, so it is still a green over a
#   gate nobody checked — this half makes the derivation stop on shapes it cannot parse, not total.
#   Shapes that add a real target-tree read and still exit 0 have been measured; they are
#   enumerated ONCE, in the `NOT PROVED BY A GREEN` block this script PRINTS on every run.
#
# ⛔ AND THE TABLE PINS THE DISPOSITION, NOT JUST THE PATH — the other half of the same defect.
# Comparing path SETS leaves a table that is silently WRONG the day a gate's `warn` becomes a
# `refuse`: same path, same derivation, green run, and a row that now describes something the
# deploy no longer does. So each row also pins WHERE the read is (the enclosing function) and a
# DIGEST of the disposition lines that gate reaches from it (§ the digest, below). Either moving
# stops the check and prints the new lines, so a maintainer who changes a disposition has to
# re-read the row — which is the table's whole purpose.
#
# RESIDUAL ESCAPES ARE NAMED IN ONE PLACE, AND THIS COMMENT IS NOT IT (canon: name what you cannot
# verify — once). They are enumerated in the `NOT PROVED BY A GREEN` block this script PRINTS on
# every run, so the CI log of the run being trusted carries them, and a reader who wants them runs
# the check rather than trusting a comment. The workflow header, the changelog entry and the PR
# body point there instead of keeping a copy: this list was restated on four surfaces and was
# incomplete on all four at once — the same drift this file refuses to accept in the path table.
# `bin/deploy.selftest.sh` is where such a read would be caught behaviourally;
# `bin/deploy-gate-inputs.selftest.sh` holds every escape shape this file DOES claim to stop, each
# as a red.
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
#   ⚠ ON A `pull_request` EVENT, `HEAD` IS THE MERGE COMMIT GitHub builds — not the PR head — so
#   the short sha printed below will not be the one the PR page shows, and that is deliberate:
#   whether the MERGE RESULT is deployable is the stronger question. Pass `--ref <sha>` to ask
#   about a specific commit instead.
#
# EXIT CODES — "the repo is not deployable" and "this check could not speak" are different events:
#   0  every required input this check DERIVED is present at <rev> — which is not the same as every
#      input phase A reads; the run's own `NOT PROVED BY A GREEN` block is where the difference is
#   1  a required input is MISSING — a real `bin/deploy.sh --ref <rev>` refuses at phase A
#   2  the check could not run: a command line it could not use (`--ref` with no value, an unknown
#      argument, a <rev> that names no commit), no bin/deploy.sh at <rev>, no reads derived from
#      it (an empty derivation is a measurement that never happened, never a pass), a line that
#      could be a read written in a shape the derivation does not match, or a classification table
#      that no longer matches the reads — or their dispositions — in deploy.sh

set -Eeuo pipefail

ME="$(basename "${BASH_SOURCE[0]}")"
REV="HEAD"

die() { printf '\n⛔ %s — %s\n' "$ME" "$1" >&2; shift; local l; for l in "$@"; do printf '   %s\n' "$l" >&2; done; exit 2; }

# The `E` of `set -E` is what carries this trap into the functions and command substitutions below.
# Without it the flag is inert, and an unexpected failure — a missing `sha256sum`, an awk that
# cannot be run — would leave the shell exiting on that command's OWN status. Status 1 is this
# check's word for "a required input is MISSING", a finding about the RELEASE: a crash would be
# read as a deploy-blocking verdict nobody measured. Every real finding leaves by `exit 1` or
# `die`, neither of which trips this.
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

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# ── the classification ────────────────────────────────────────────────────────────────────────
# One row per read phase A makes out of the target tree, answering the ONE question the derivation
# cannot: what does `bin/deploy.sh` DO when that path is absent? Each verdict is read off the
# gate's own lines, cited here so a maintainer can check the row against them rather than against
# this sentence.
#
#   required           absent ⇒ the gate refuses. Present is enough; the content is not read here.
#   required-nonempty  absent OR empty ⇒ the gate refuses (`[ -n "$content" ] || refuse`).
#   optional           absent ⇒ the gate warns or passes, by its own reasoning. Reported, never failed.
#   run-time           the deploy builds this path while it runs, so no fixed path exists to look
#                      up. Named on every run rather than skipped — but still PINNED, so a new one
#                      cannot appear unremarked.
#
# ⚠ FOUR OF THE SEVEN FIELDS ARE DERIVED AND PINNED, not written from memory: `kind` (which reader
# makes it), `fn` (the function the read is in) and `digest` (§ the digest) all come out of
# `bin/deploy.sh` itself. When any of them moves this check stops and prints the row it derived,
# ready to paste — do not hand-compute one. TAB-separated, in this order:
#
#   path/expression  kind  gate  rule  fn  digest  what bin/deploy.sh does when it is absent
CLASSIFIED=()
while IFS= read -r row; do [ -z "$row" ] || CLASSIFIED+=("$row"); done <<'TABLE'
server/composer.json	read	A6	required-nonempty	gate_a6_php_floor	6cd7000c6ab9	refuses: it is where the PHP floor is declared, and composer would meet it inside the window instead
server/database/migrations	ls	A10	optional	gate_a10_migration_algorithm	69438f9e492a	passes, saying the release ships no migrations — git_ls_at's empty is a real answer
$mig	read	A10	run-time	gate_a10_migration_algorithm	69438f9e492a	each migration the listing above named: the names come from the tree, not from this file, so presence is not in question — the read follows the listing. Its content predicate (ALGORITHM=) is out of scope below.
server/.env.example	read	A10b	optional	gate_a10b_config_drift	c6e6540fcb03	warns that no key of the release was compared against the host's .env
server/bootstrap/app.php	read	A11	required	gate_a11_trusted_proxies	910d5f07fb9a	refuses: server/artisan requires it, so every artisan command of that release fails inside the window
server/package-lock.json	ls	A12	required	gate_a12_asset_lockfile	86bdec226cc1	refuses: npm ci needs it and package.json floats, so the prod asset build would not be reproducible
bin/supervision.sh	read	A13	required-nonempty	gate_a13_target_plan	7ec2114d0a10	refuses: the window installs the deployed release's crontab block from it
server/public/$uif	read	A14	run-time	fpm_code_reload_ready	e3b0c44298fc	the release's .user.ini, whose NAME comes from the host's phpinfo (user_ini.filename): no fixed path exists at this commit. A14 is not run here at all — it needs PHP-FPM.
TABLE

# ── the patterns ──────────────────────────────────────────────────────────────────────────────
# READ_RE is the STRICT one: it both FINDS a call site and takes the path out of it, so there is
# one notion of what a call site is. It is composed from its two halves only so that the prefix can
# be stripped to leave the argument — the same pattern, not a second copy of it.
READ_PREFIX_RE='git_(read|ls)_at[[:space:]]+[A-Za-z_][A-Za-z0-9_]*[[:space:]]+"[$]SHA"[[:space:]]+'
READ_ARG_RE='("[^"]*"|[^[:space:];]+)'
READ_RE="$READ_PREFIX_RE$READ_ARG_RE"

# A line naming $SHA is in the loose superset. It is allowed WITHOUT matching READ_RE only when
# every git subcommand on it is one of these — none of which can hand the deploy a file out of the
# target tree. The list is deliberately short: a new subcommand on a $SHA line stops this check
# until somebody adds it here, having first asked whether it reads the release.
NON_READING_SUBCOMMANDS='rev-parse merge-base checkout fetch status'

# A git invocation at a COMMAND POSITION, which is how the derivation reads WHICH subcommands a
# reader-family member's own body runs. The position matters because a refusal's message text is
# prose about git — `"git could not read $3 at $2"` — and a pattern that takes any `git <word>` for
# an invocation reads that as `git could` and stops this check on a helper that runs nothing at all.
# Reading subcommands are matched loosely instead (§ the derivation), because over-reading one costs
# a stop and under-reading one costs a green over an unchecked gate.
GIT_CMD_RE='((^|[({;&|`!])[[:space:]]*|(^|[[:space:]])(if|then|elif|else|do|while|until)[[:space:]]+)git(_at)?[[:space:]]+(-[cC][[:space:]]+[^[:space:]]+[[:space:]]+)*[a-z][a-z-]*'

# § the digest. A read's DISPOSITION is what the gate does with it, and it is written as `refuse`,
# `warn` or `say`. The digest covers exactly those lines, from the read down to where the next read
# AT THE SAME NESTING takes over (or the end of the enclosing function) — comments and logic in
# between are not digested, so an edit that explains a gate better does not red this lane, while
# `warn` becoming `refuse` always does. A read nested INSIDE another's region (A10's per-migration
# read inside A10's listing) does not end that region, which is why both carry the same digest.
DISPO_RE='(^|[^A-Za-z0-9_])(refuse|warn|say)([[:space:]]|$)'

# ── derive the population from the deploy script at $SHA ───────────────────────────────────────
git show "$SHA:bin/deploy.sh" > "$WORK/deploy.sh" \
  || die "bin/deploy.sh is not readable at $SHORT" \
         "This check derives its population from that file. Without it nothing was measured."

cat > "$WORK/derive.awk" <<'AWK'
# Reads bin/deploy.sh on stdin. Writes what it derived into -v out=<dir>:
#   reads.tsv  idx, kind, arg, line, fn, region-end   — one per derived read
#   d.<idx>    the disposition lines of that read's region (the digest's input)
#   stops.tsv  line, reason                           — superset lines the strict pattern missed
#   facts.tsv  reader / notreader / modes / modewhat / modefn / examined
#   fatal      the derivation's own input was not there
function bail(m) { print m > (out "/fatal"); exit }
function note(i, why) { print i "\t" why > (out "/stops.tsv"); nstop++ }

# git_ok — every git invocation on a $SHA line runs a NON-READING subcommand. The subcommand is
# taken positionally (skipping `-c k=v`/`-C dir` and any other option) rather than pattern-matched,
# so `git_at -c core.quotePath=false ls-tree` is read as `ls-tree` and not as an option soup.
function git_ok(t,   k, parts, a, j, sc) {
  k = split(t, parts, /[[:space:]]+/)
  for (a = 1; a <= k; a++) {
    if (parts[a] ~ /(^|[^A-Za-z0-9_])git(_at)?$/) {
      j = a + 1
      while (j <= k && parts[j] ~ /^-/) { if (parts[j] == "-c" || parts[j] == "-C") j += 2; else j += 1 }
      sc = parts[j]; gsub(/[^A-Za-z0-9_-]/, "", sc)
      if (!(sc in ALLOW)) { BADSUB = sc; return 0 }
    }
  }
  return 1
}

BEGIN {
  n_allow = split(allow, aa, /[[:space:]]+/)
  for (a = 1; a <= n_allow; a++) ALLOW[aa[a]] = 1
  nstop = 0; nread = 0; nexam = 0
}
{ L[NR] = $0 }
END {
  n = NR
  if (n == 0) bail("bin/deploy.sh is empty at this commit")

  # ── top-level function extents. Every function in bin/deploy.sh is defined at column 0 and
  # closed by a `}` at column 0; a one-liner closes on its own line.
  for (i = 1; i <= n; i++) {
    if (L[i] ~ /^[A-Za-z_][A-Za-z0-9_]*\(\)[[:space:]]*\{/) {
      name = L[i]; sub(/\(\).*$/, "", name)
      if (L[i] ~ /\}[[:space:]]*$/) e = i
      else { e = n; for (j = i + 1; j <= n; j++) if (L[j] ~ /^\}[[:space:]]*$/) { e = j; break } }
      FSTART[name] = i; FEND[name] = e
      for (j = i; j <= e; j++) FN[j] = name
    }
  }

  # ── case LABELS, joined across their continuations. A case alternative is a list of PATTERNS and
  # never a pipeline of calls, and bash lets one be written over several lines with `\`. Both halves
  # of that sentence are card#9693: bin/deploy.sh's reader family grew past one line, so leg 1 below
  # read only the names on the LAST line of the deploy's own list — and the superset read the list
  # ITSELF as a call to every reader it defines.
  for (i = 1; i <= n; i++) {
    if (L[i] ~ /^[[:space:]]*#/) continue
    t = L[i]; j = i
    while (t ~ /\\[[:space:]]*$/ && j < n) { sub(/\\[[:space:]]*$/, "", t); j++; t = t " " L[j] }
    if (t !~ /^[[:space:]]*[A-Za-z_][A-Za-z0-9_]*([[:space:]]*\|[[:space:]]*[A-Za-z_][A-Za-z0-9_]*)*[[:space:]]*\)[[:space:]]*$/) continue
    LABELTEXT[i] = t
    for (k = i; k <= j; k++) LABEL[k] = 1
  }

  # ── the family, leg 1: the names bin/deploy.sh itself lists in git_read_call_site's `case`. That
  # list exists for the deploy's own frame-walking, so it is maintained there — and what it names is
  # a CANDIDATE here, ruled on below rather than taken for a reader of the release tree.
  if (!("git_read_call_site" in FSTART))
    bail("bin/deploy.sh defines no git_read_call_site() at this commit — the reader family is derived from its case list, and without it nothing was derived")
  nprim = 0
  for (i = FSTART["git_read_call_site"]; i <= FEND["git_read_call_site"]; i++) {
    if (!(i in LABELTEXT)) continue
    t = LABELTEXT[i]
    sub(/^[[:space:]]+/, "", t); sub(/[[:space:]]*\)[[:space:]]*$/, "", t)
    k = split(t, pp, /[[:space:]]*\|[[:space:]]*/)
    for (a = 1; a <= k; a++) { CAND[pp[a]] = 1; nprim++ }
  }
  if (nprim == 0)
    bail("git_read_call_site() at this commit lists no reader family in a `a | b | c)` case — that list is one of the ways this check derives which functions read the target tree")

  # ── what each function's own body DOES with git. Every invocation is read for its subcommand
  # (positionally, past `-c k=v`/`-C dir`, as git_ok does) and for whether it NAMES A PATH: a `--`
  # pathspec separator, or an argument holding a `:`. Those are the only two ways git's CLI names a
  # path inside a tree, so this rule is a property of git rather than a restatement of bin/deploy.sh.
  #   ⚠ IT RESOLVES TOWARD READER. Anything else on the line — a `${x:-y}`, a trailing `2>:` — reads
  #   as a path and makes the function a reader, which STOPS this check at an unmatched call site.
  #   The error it can make is a stop, never a pass.
  #   ⚠ AND IT IS READ TWO WAYS, because the two answers fail in opposite directions. A READING
  #   subcommand is looked for LOOSELY — anywhere on a non-comment line, the shape leg 2 has always
  #   used — since over-reading one means a stop and under-reading one means a silent green. Every
  #   OTHER subcommand is taken only at a COMMAND POSITION (line start, `(`, `$(`, `` ` ``, `|`,
  #   `&`, `;`, `!`, or after if/then/elif/else/do/while/until), because that answer decides whether
  #   this check STOPS on a name it cannot rule on — and a refusal's own message text is full of
  #   prose like "git could not read $3", which read loosely is an invocation of `git could`.
  for (name in FSTART) {
    for (i = FSTART[name]; i <= FEND[name]; i++) {
      if (L[i] ~ /^[[:space:]]*#/) continue
      if (L[i] ~ /[$]\{?SHA\}?/) OWNSHA[name] = 1
      rest = L[i]
      while (match(rest, /git(_at)?[[:space:]]+(-[cC][[:space:]]+[^[:space:]]+[[:space:]]+)*(ls-tree|show|cat-file)([[:space:]]|$)/)) {
        rest = substr(rest, RSTART + RLENGTH)
        if (rest ~ /(^|[[:space:]])--([[:space:]]|$)/ || rest ~ /:/) PATHREAD[name] = 1
        else OBJREAD[name] = 1
      }
      rest = L[i]
      while (match(rest, cmdpos_re)) {
        inv = substr(rest, RSTART, RLENGTH); rest = substr(rest, RSTART + RLENGTH)
        sc = inv; sub(/^.*[[:space:]]/, "", sc)
        if (index(" " SUBS[name] " ", " " sc " ") == 0)
          SUBS[name] = SUBS[name] (SUBS[name] == "" ? "" : " ") sc
      }
    }
  }

  # ── the family, leg 2: any function whose own body reads a PATH out of a tree, on a rev it was
  # HANDED. This is what catches a reader added without touching the case list above.
  #   ⚠ "on a rev it was handed" is what keeps a GATE out of the family. A reader is parameterised
  #   — every one above reads `$__rev`/`$2` and never the global — while a gate that reads the
  #   release inline names `$SHA` itself. Without that clause, one raw `git_at show "$SHA:…"` typed
  #   into `phase_a` would make PHASE_A a reader, and every call to it a call site the strict
  #   pattern cannot match: a true stop, with a message about the wrong line. Such a line is
  #   already stopped, by name, as a $SHA line running a reading subcommand.
  for (name in FSTART)
    if (PATHREAD[name] && !OWNSHA[name]) { READER[name] = "its body reads a path out of a tree"; CAND[name] = 1 }

  # ── the family, leg 3: and a function that DELEGATES to a reader is one. git_ls_at is the worked
  # case — its whole body is `_git_ls_at "$1" "$2" "$3" --name-only -r`, so nothing IN it names a
  # path, and before card#9693 it was a reader only because the case list happened to name it. A
  # list that stopped naming it would have dropped it out of the superset silently, which is what
  # a continued case list did.
  changed = 1
  while (changed) {
    changed = 0
    nr = 0
    for (r in READER) RLIST[++nr] = r
    for (name in FSTART) {
      if ((name in READER) || OWNSHA[name]) continue
      got = ""
      for (i = FSTART[name]; i <= FEND[name] && got == ""; i++) {
        if (L[i] ~ /^[[:space:]]*#/ || LABEL[i]) continue
        for (q = 1; q <= nr && got == ""; q++)
          if (L[i] ~ "(^|[^A-Za-z0-9_])" RLIST[q] "([[:space:]]|$)") got = RLIST[q]
      }
      if (got != "") NEWR[name] = got
    }
    # Collected first and applied after: an awk that deletes the element it is iterating over is not
    # a portable one, and this file runs under whichever awk the runner ships.
    nn = 0
    for (name in NEWR) NLIST[++nn] = name
    for (q = 1; q <= nn; q++) { READER[NLIST[q]] = "it delegates to " NEWR[NLIST[q]]; CAND[NLIST[q]] = 1; changed = 1 }
    delete NEWR
  }

  # ── and the RULING on every candidate that is not a reader, read off that same body. It is
  # printed on every run: a name this check drops from the population is a name a maintainer has to
  # be able to check it was right to drop.
  for (name in CAND) {
    if (name in READER) continue
    if (!(name in FSTART))
      bail("bin/deploy.sh's reader family names " name ", and this commit defines no such function at the top level — whether it reads a path out of the release tree cannot be read off a body that is not there, and assuming either answer would be inventing one")
    if (OWNSHA[name]) {
      NOTREADER[name] = "it names $SHA itself, so it is a gate reading the release inline and not a reader handed a rev"
      continue
    }
    # Every subcommand its body runs, against the two vocabularies this check has: the READING ones
    # it takes a path out of, and the non-reading ones named in NON_READING_SUBCOMMANDS. A third
    # answer is not assumed — `archive` and `worktree` can both put a path on disk — so a candidate
    # running one stops the check, which is the rule this file already applies to a $SHA line.
    known = ""; unknown = ""
    k = split(SUBS[name], ss, /[[:space:]]+/)
    for (a = 1; a <= k; a++) {
      if (ss[a] == "") continue
      if (ss[a] == "ls-tree" || ss[a] == "show" || ss[a] == "cat-file" || (ss[a] in ALLOW))
        known = known " " ss[a]
      else unknown = unknown " " ss[a]
    }
    if (unknown != "")
      bail("bin/deploy.sh's reader family names " name ", whose body runs `git" unknown "` — a subcommand this check does not know to be a read of the release tree or not, so whether its call sites are gate inputs was not derived. Name it in NON_READING_SUBCOMMANDS, having first asked whether it can hand a caller a path out of the target tree")
    if (OBJREAD[name]) NOTREADER[name] = "it reads an object BY ID and names no path (`git" known "`), so no path of the release can come back through it"
    else if (known != "") NOTREADER[name] = "it runs only `git" known "`, which names no path inside a tree"
    else NOTREADER[name] = "it runs no git at all"
  }
  for (name in READER) print "reader\t" name "\t" READER[name] > (out "/facts.tsv")
  for (name in NOTREADER) print "notreader\t" name "\t" NOTREADER[name] > (out "/facts.tsv")

  # ── the accepted file MODES, read off the reader's own `case`: the one alternative it lets
  # through with an empty body. Restating `100644|100755` here would be the drift this file exists
  # to prevent, so it is taken from the source instead. `for (name in READER)` has no order, so
  # the answer is required to be UNIQUE rather than whichever reader awk happened to walk first.
  modes_seen = ""; modefn_seen = ""
  for (name in READER) {
    if (!(name in FSTART)) continue
    for (i = FSTART[name]; i <= FEND[name]; i++) {
      if (L[i] !~ /^[[:space:]]*[0-9]+([[:space:]]*\|[[:space:]]*[0-9]+)*\)[[:space:]]*;;[[:space:]]*$/) continue
      t = L[i]; gsub(/[^0-9|]/, "", t)
      if (modes_seen == "") { modes_seen = t; modefn_seen = name }
      else if (modes_seen != t)
        bail("two readers in bin/deploy.sh accept DIFFERENT file modes (" modefn_seen ": " modes_seen ", " name ": " t ") — which of them a given gate's read is judged by cannot be derived from the call site")
    }
  }
  if (modes_seen != "") {
    print "modes\t" modes_seen > (out "/facts.tsv")
    print "modefn\t" modefn_seen > (out "/facts.tsv")
    # …and the reader's own word for each mode it refuses, from that same function.
    for (i = FSTART[modefn_seen]; i <= FEND[modefn_seen]; i++) {
      if (L[i] !~ /^[[:space:]]*[0-9]+\)[[:space:]]*__what="[^"]*"/) continue
      md = L[i]; sub(/^[[:space:]]*/, "", md); sub(/\).*$/, "", md)
      wh = L[i]; sub(/^[^"]*"/, "", wh); sub(/".*$/, "", wh)
      print "modewhat\t" md "\t" wh > (out "/facts.tsv")
    }
  }

  # ── the superset, and the strict pattern's account of it ────────────────────────────────────
  for (i = 1; i <= n; i++) {
    t = L[i]
    if (t ~ /^[[:space:]]*$/) continue
    if (t ~ /^[[:space:]]*#/) continue            # a comment naming a reader is not a call site
    # The family's own DEFINITION is not a set of calls to it: a case alternative naming the readers
    # is a list of patterns, which is how the deploy's own family list came to be read as a page of
    # reads of the release nobody had written (card#9693).
    if (LABEL[i]) continue
    # The family's own plumbing is exempt because it reads the rev it was HANDED — `$__rev`, never
    # the global. A line inside one that names $SHA is not that, and is examined like any other.
    if (FN[i] != "" && (FN[i] in CAND) && t !~ /[$]\{?SHA\}?/) continue

    match(t, /^[[:space:]]*/); ind = RLENGTH

    ncalls = 0
    for (name in READER) {
      rest = t
      while (match(rest, "(^|[^A-Za-z0-9_])" name "([[:space:]]|$)")) {
        ncalls++; rest = substr(rest, RSTART + RLENGTH)
      }
    }

    nm = 0; rest = t
    while (match(rest, read_re)) {
      m = substr(rest, RSTART, RLENGTH); nm++
      MK[nm] = (m ~ /^git_read_at/) ? "read" : "ls"
      a = m; sub("^" prefix_re, "", a); MA[nm] = a
      rest = substr(rest, RSTART + RLENGTH)
    }

    if (ncalls > 0 || nm > 0) {
      if (!(i in SEEN)) { SEEN[i] = 1; nexam++ }   # a LINE of the superset, counted once
      if (ncalls > nm) {
        note(i, "a reader of the target tree is called here in a shape the derivation does not match, so this read would be in NEITHER the derived set nor the table — and the run would be green")
        continue
      }
      # The other way round, and it is a different fault with a different remedy: the strict pattern
      # took a read out of this line and the family derivation does not hold the reader it names. One
      # of the two is wrong about bin/deploy.sh, and neither answer may be assumed here.
      if (nm > ncalls) {
        note(i, "the strict pattern reads this line as a call to a reader, and the reader family derived from this same file does not contain the name it calls — the two halves of the derivation disagree about this line, so what it reads is not established")
        continue
      }
      split_call = 0
      for (a = 1; a <= nm; a++) if (MA[a] == "\\") split_call = 1
      if (split_call) {
        note(i, "the read is continued onto the NEXT line with `\\`, so the path this derivation would take out of it is the backslash")
        continue
      }
      for (a = 1; a <= nm; a++) {
        nread++
        arg = MA[a]
        if (arg ~ /^".*"$/) arg = substr(arg, 2, length(arg) - 2)
        RK[nread] = MK[a]; RA[nread] = arg; RL[nread] = i; RF[nread] = FN[i]; RI[nread] = ind
      }
    }

    if (t ~ /[$]\{?SHA\}?/) {
      if (!(i in SEEN)) { SEEN[i] = 1; nexam++ }
      if (!git_ok(t)) note(i, "this line names $SHA and runs `git " BADSUB "`, which is not one of the non-reading subcommands this check knows; if it reads the release, it is a gate input")
    }
  }

  print "examined\t" nexam > (out "/facts.tsv")

  # ── each read's disposition region (§ the digest in the caller) ──────────────────────────────
  for (q = 1; q <= nread; q++) {
    fn = RF[q]
    e = (fn == "" || !(fn in FEND)) ? n : FEND[fn]
    for (r = 1; r <= nread; r++)
      if (RF[r] == fn && RL[r] > RL[q] && RI[r] <= RI[q] && RL[r] - 1 < e) e = RL[r] - 1
    for (j = RL[q]; j <= e; j++) {
      if (L[j] ~ /^[[:space:]]*#/) continue
      if (L[j] ~ dispo_re) print L[j] > (out "/d." q)
    }
    print q "\t" RK[q] "\t" RA[q] "\t" RL[q] "\t" fn "\t" e > (out "/reads.tsv")
  }
}
AWK

: > "$WORK/facts.tsv"; : > "$WORK/stops.tsv"; : > "$WORK/reads.tsv"
awk -v out="$WORK" -v read_re="$READ_RE" -v prefix_re="$READ_PREFIX_RE" \
    -v dispo_re="$DISPO_RE" -v allow="$NON_READING_SUBCOMMANDS" -v cmdpos_re="$GIT_CMD_RE" \
    -f "$WORK/derive.awk" < "$WORK/deploy.sh"

[ ! -s "$WORK/fatal" ] || die "the derivation's own input is not there in bin/deploy.sh at $SHORT" \
  "$(cat "$WORK/fatal")" \
  "" \
  "Nothing was measured. This check refuses rather than reporting a tree it never derived a" \
  "population for."

# Sorted, because awk's `for (name in READER)` has no defined order and these lines are printed on
# every run: an unsorted list changes between awk implementations on the same tree, and a reader
# comparing two runs would be reading an ordering difference as a change in the deploy.
FAMILY="$(awk -F'\t' '$1=="reader"{print $2}' "$WORK/facts.tsv" | sort | tr '\n' ' ')"
FAMILY="${FAMILY% }"
# The candidates this check RULED OUT of that family, each with the reason it read off their own
# bodies. Printed beside the family on every run: a name dropped from the population silently is a
# name nobody can check the drop of, and card#9693 is what a silent drop cost.
RULEDOUT="$(awk -F'\t' '$1=="notreader"{printf "    %s — %s\n", $2, $3}' "$WORK/facts.tsv" | sort)"

# Printed HERE, before the stops below rather than in the report after them, because a run that
# stops is exactly the run whose reader needs it: what this derivation took for a reader of the
# release is what decides which lines it went on to demand a match for.
printf 'bin/deploy.sh phase A — target-tree inputs at %s\n' "$SHORT"
printf '  reader family derived from bin/deploy.sh at %s: %s\n' "$SHORT" "$FAMILY"
if [ -n "$RULEDOUT" ]; then
  printf '  named in that reader family and ruled NOT a reader of the release tree, each by what\n'
  printf '  its own body does (card#9693):\n'
  printf '%s\n' "$RULEDOUT"
fi
EXAMINED="$(awk -F'\t' '$1=="examined"{print $2}' "$WORK/facts.tsv")"
MODES="$(awk -F'\t' '$1=="modes"{print $2}' "$WORK/facts.tsv")"
MODEFN="$(awk -F'\t' '$1=="modefn"{print $2}' "$WORK/facts.tsv")"
[ -n "$MODES" ] || die "no reader in bin/deploy.sh at $SHORT declares which file modes it will read" \
  "The mode rule below is taken from the reader's own \`case\` — the alternative it lets through" \
  "with an empty body — rather than restated here. Without it this check would have to assume one."

# ── the total-derivation stop ──────────────────────────────────────────────────────────────────
# Before any comparison: every line that COULD be a read is accounted for by the strict pattern.
# A line that is not is a hole in the population, and a hole in the population is invisible to the
# guard below — both sides of it would simply be missing the same read.
if [ -s "$WORK/stops.tsv" ]; then
  detail=()
  while IFS=$'\t' read -r sline swhy; do
    detail+=("bin/deploy.sh:$sline — $swhy" "    $(sed -n "${sline}p" "$WORK/deploy.sh")" "")
  done < "$WORK/stops.tsv"
  die "bin/deploy.sh at $SHORT reads the target tree in a shape this check cannot derive" \
    "${detail[@]}" \
    "Write the read on ONE line, in one of the two shapes this check's own READ_RE matches — which" \
    "is how every other read of the release in that file is written:" \
    "" \
    "    git_read_at var \"\$SHA\" path        the CONTENT of one file" \
    "    git_ls_at var \"\$SHA\" pathspec      the paths under a pathspec" \
    "" \
    "READ_RE itself, so this advice cannot drift from what actually matches:" \
    "    $READ_RE" \
    "" \
    "Or widen READ_RE deliberately and add the case to bin/deploy-gate-inputs.selftest.sh." \
    "Passing over it would leave the gate unchecked with this lane still green — the defect this" \
    "stop exists to end. ⚠ A line this stop names that reads NO path out of the release tree —" \
    "a ref resolved, an object read by bare id, a refusal helper — is this check misreading" \
    "bin/deploy.sh rather than bin/deploy.sh being written wrong: the family rulings printed" \
    "ABOVE, at the top of this run, are where that misreading is legible, and card#9693 is" \
    "where it came from."
fi

[ -s "$WORK/reads.tsv" ] || die "no target-tree read was derived from bin/deploy.sh at $SHORT" \
  "This check finds phase A's reads by their git_read_at/git_ls_at call sites against \"\$SHA\"." \
  "Finding none means the readers were renamed, or the derivation no longer matches how they are" \
  "called — not that the deploy reads nothing. An empty population certifies an entire tree in one" \
  "line, so it is refused rather than reported as a pass."

# ── what was derived, as rows: kind, arg, fn, digest ───────────────────────────────────────────
: > "$WORK/derived.tsv"; : > "$WORK/derived.full"
# `rend` is never READ, and it is still load-bearing: reads.tsv carries SIX fields (the layout
# recorded above: idx, kind, arg, line, fn, region-end), and the last name in a `read` absorbs
# everything left on the line. Drop `rend` and `fn` becomes "phase_a<TAB>145" — and `fn` is one of
# the four fields the guard below compares against the table, so the corruption would surface as a
# permanent mismatch, not as a tidier line. ShellCheck models a name's VALUE being read; it cannot
# see a name whose whole job is to hold a field position.
# shellcheck disable=SC2034  # terminal field sink for reads.tsv's 6th column — see above
while IFS=$'\t' read -r idx kind arg lineno fn rend; do
  dg="$( (cat "$WORK/d.$idx" 2>/dev/null || true) | sha256sum | cut -c1-12)"
  printf '%s\t%s\t%s\t%s\n' "$kind" "$arg" "$fn" "$dg" >> "$WORK/derived.tsv"
  printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$idx" "$kind" "$arg" "$lineno" "$fn" "$dg" >> "$WORK/derived.full"
done < "$WORK/reads.tsv"

: > "$WORK/classified.tsv"
for row in "${CLASSIFIED[@]}"; do
  IFS=$'\t' read -r c_path c_kind c_gate c_rule c_fn c_dg c_why <<< "$row"
  printf '%s\t%s\t%s\t%s\n' "$c_kind" "$c_path" "$c_fn" "$c_dg" >> "$WORK/classified.tsv"
done

# ── the guard: the table and the derivation must name the same reads, in the same places, with
#    the same dispositions ───────────────────────────────────────────────────────────────────────
sort "$WORK/derived.tsv" > "$WORK/derived.sorted"
sort "$WORK/classified.tsv" > "$WORK/classified.sorted"
if ! cmp -s "$WORK/derived.sorted" "$WORK/classified.sorted"; then
  cut -f1,2 "$WORK/derived.sorted" | sort > "$WORK/dk"
  cut -f1,2 "$WORK/classified.sorted" | sort > "$WORK/ck"
  detail=()
  while IFS=$'\t' read -r k_kind k_arg; do
    [ -n "$k_arg" ] || continue
    dline="$(awk -F'\t' -v k="$k_kind" -v a="$k_arg" '$2==k && $3==a {print $4; exit}' "$WORK/derived.full")"
    detail+=("read by the deploy and NOT classified here: $k_arg (git_${k_kind}_at, bin/deploy.sh:${dline:-?})")
  done < <(comm -23 "$WORK/dk" "$WORK/ck")
  while IFS=$'\t' read -r k_kind k_arg; do
    [ -n "$k_arg" ] || continue
    detail+=("classified here and no longer read by the deploy: $k_arg (git_${k_kind}_at)")
  done < <(comm -13 "$WORK/dk" "$WORK/ck")
  while IFS=$'\t' read -r k_kind k_arg; do
    [ -n "$k_arg" ] || continue
    d_row="$(awk -F'\t' -v k="$k_kind" -v a="$k_arg" '$1==k && $2==a {print $3"\t"$4; exit}' "$WORK/derived.sorted")"
    c_row="$(awk -F'\t' -v k="$k_kind" -v a="$k_arg" '$1==k && $2==a {print $3"\t"$4; exit}' "$WORK/classified.sorted")"
    [ "$d_row" != "$c_row" ] || continue
    idx="$(awk -F'\t' -v k="$k_kind" -v a="$k_arg" '$2==k && $3==a {print $1; exit}' "$WORK/derived.full")"
    detail+=("$k_arg still read, but its pinned location/disposition MOVED:" \
             "    table:   fn=$(printf '%s' "$c_row" | cut -f1)  digest=$(printf '%s' "$c_row" | cut -f2)" \
             "    derived: fn=$(printf '%s' "$d_row" | cut -f1)  digest=$(printf '%s' "$d_row" | cut -f2)" \
             "    the lines the digest now covers:")
    while IFS= read -r dl; do detail+=("      | $dl"); done < <(head -14 "$WORK/d.$idx" 2>/dev/null || true)
  done < <(comm -12 "$WORK/dk" "$WORK/ck")
  detail+=("" "the rows this run derived, ready to paste (the last field is yours to write):")
  while IFS=$'\t' read -r idx kind arg lineno fn dg; do
    detail+=("  $(printf '%s\t%s\t<gate>\t<rule>\t%s\t%s\t<what bin/deploy.sh does when it is absent>' "$arg" "$kind" "$fn" "$dg")")
  done < "$WORK/derived.full"
  die "bin/deploy.sh at $SHORT no longer matches what this check classifies" \
    "${detail[@]}" \
    "" \
    "Whether an absent path refuses, warns or passes is read off the gate; it cannot be derived." \
    "Update CLASSIFIED above from the derived rows, having re-read the gate's own lines. This" \
    "stops rather than covering less — or describing something else — than it did yesterday."
fi

# ── judge the repository's own tree ────────────────────────────────────────────────────────────
n_fixed=0; n_runtime=0
for row in "${CLASSIFIED[@]}"; do
  # `c_gate` and `c_why` are never read anywhere, and both hold a CLASSIFIED row's shape open:
  # `c_gate` occupies column 3 so that `c_rule` lands on column 4 (without it `c_rule` reads "A3",
  # the gate name, and every row counts as fixed), and `c_why` is the terminal sink that keeps the
  # trailing prose off `c_dg` at the sibling destructure above, whose printf writes `c_dg` into the
  # table the guard compares. Nothing measured is dropped by their being unread: the `gate` and
  # `why` columns are what the per-path report below prints.
  # shellcheck disable=SC2034  # positional names holding the row shape — see above
  IFS=$'\t' read -r c_path c_kind c_gate c_rule c_fn c_dg c_why <<< "$row"
  if [ "$c_rule" = run-time ]; then n_runtime=$((n_runtime + 1)); else n_fixed=$((n_fixed + 1)); fi
done

printf '\n  population derived from bin/deploy.sh at %s: %d fixed path(s), %d built at run time\n' \
  "$SHORT" "$n_fixed" "$n_runtime"
printf '  every one of the %d line(s) this derivation SEES as a possible read was matched by it\n' "$EXAMINED"
printf '  (which lines it does not see is printed below, under NOT PROVED BY A GREEN)\n\n'

missing=()
for row in "${CLASSIFIED[@]}"; do
  IFS=$'\t' read -r path kind gate rule fn dg why <<< "$row"
  [ "$rule" != run-time ] || continue
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
  # The MODE, for the rows the deploy reads as a FILE. git_read_at refuses every mode but the ones
  # it names — a symlink's blob is the path it points at, not the text at the other end — so a
  # present-but-not-a-file entry passes a presence check and refuses a real deploy. The accepted
  # set and the reader's own word for this mode are both taken from bin/deploy.sh above.
  if [ "$kind" = read ] && [[ "|$MODES|" != *"|$mode|"* ]]; then
    what="$(awk -F'\t' -v m="$mode" '$1=="modewhat" && $2==m {print $3; exit}' "$WORK/facts.tsv")"
    printf '  MISSING %-28s %-5s present but mode %s — bin/deploy.sh'"'"'s %s calls that "%s", not a file, and refuses it by name (it reads %s)\n' \
      "$path" "$gate" "$mode" "${MODEFN:-git_read_at}" "${what:-a mode it does not read}" "$MODES"
    missing+=("$path ($gate, mode $mode)")
    continue
  fi
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
    # grep -c exits 1 for "no lines matched" — a real count of zero — and >1 when it FAILED. A
    # `|| true` here would fuse those, which is the card#9608 shape the comment above refuses to
    # reintroduce; display-only is not a reason to write it the wrong way.
    rc=0; n="$(printf '%s' "$listing" | grep -c .)" || rc=$?
    [ "$rc" -le 1 ] || die "git listed $path at $SHORT but counting its entries failed (\`grep -c\` exited $rc)" \
      "The file count under it was not established, so it is not reported."
    printf '  ok      %-28s %-5s present, %s file(s)\n' "$path" "$gate" "$n"
  else
    printf '  ok      %-28s %-5s present, mode %s\n' "$path" "$gate" "$mode"
  fi
done

# Named, not skipped: a read whose path the deploy assembles at run time is one this check cannot
# look up, and saying nothing about it would read as coverage (canon: name what you cannot verify).
# Unlike the first version of this file, these are CLASSIFIED rows like any other — a new one stops
# the check rather than appearing quietly in this list.
if [ "$n_runtime" -gt 0 ]; then
  printf '\n  not derivable — the deploy builds these paths at run time, so no fixed path exists to check:\n'
  for row in "${CLASSIFIED[@]}"; do
    IFS=$'\t' read -r path kind gate rule fn dg why <<< "$row"
    [ "$rule" = run-time ] || continue
    printf '    %-5s %s (in %s) — %s\n' "$gate" "$path" "$fn" "$why"
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

  CONTENT PREDICATES not covered here: A6's constraint shape, A10's ALGORITHM= declaration on
  migrations that alter `events`, A11's trustProxies('*'), A13's render of the target release's
  crontab block. The seam they needed has LANDED (card#9644) — each is a callable gate function of
  bin/deploy.sh taking the commit, reading only out of git — and THIS CHECK DOES NOT CALL ONE YET.
  Restating them here instead would drift from the gate — the shape of the defect card#9203 filed.
EXCLUDED

# ── the one home for what a green does NOT establish ───────────────────────────────────────────
# Printed on every run, green or red, because the log of the run being trusted is the surface a
# maintainer actually reads. Every other surface — this script's own header, the workflow header,
# its changelog entry (card#9637), the PR body — points HERE rather than keeping a copy; four
# copies of this list existed and all four were incomplete, which is the drift this file refuses to
# accept in its path table and had no business accepting in its own claim.
cat <<'LIMITS'

  WHAT A GREEN HERE PROVES, AND WHAT IT DOES NOT — the one home for this list (card#9637):

  PROVED. Every target-tree read this derivation FOUND in the bin/deploy.sh above is classified in
    the table, in the function it sits in, with a digest of the disposition lines its gate reaches
    from it — and every fixed path those reads name is present at that commit, non-empty where the
    gate requires it, in a file mode bin/deploy.sh's own reader accepts. Every line the derivation
    SEES as a possible read was matched by the strict pattern: a read the superset sees but the
    pattern cannot parse stops this check at exit 2 rather than being passed over.

  NOT PROVED: that the derived population is every read phase A makes. The superset is lines naming
    $SHA/${SHA} plus lines calling a derived reader-family member, over ONE file, and each shape
    below was MEASURED — added to a fixture copy of this repository as a real target-tree read, and
    this check run over it — to exit 0 with the read never classified:
      * a rev aliased into another variable first (r="$SHA" on one line, `git show "$r:path"` on the
        next): the reading line names neither $SHA nor a family member, so it is not in the superset.
      * a genuine reader excluded by the family's `!own_sha` clause. That clause is what keeps
        phase_a out of the family; it also drops a properly parameterised reader whose body names
        $SHA for some other reason (a message, a comparison), and its call sites then match nothing.
      * a reader name held in a QUOTED variable (fn="git_read_at", then `"$fn" v "$SHA" path`): the
        call site carries no literal family name for the superset to catch.
      * a read in bin/supervision.sh, which bin/deploy.sh sources UNCONDITIONALLY beside itself and
        this derivation never opens — as would one reached through `eval`, or made with a git binary
        held in a variable ("$GIT" show …).
      * a reader whose git invocation names its path through a variable that already holds one
        (`git_at cat-file -p "$spec"`, where $spec was assembled as "$rev:$path" earlier). Whether a
        family member reads a PATH or an object BY ID is read off the invocation — a `--` pathspec
        or a `:` in an argument — so an argument that carries the colon out of sight reads as an
        object id, and that function's call sites are then not in the superset. It is the sibling of
        the first shape above, at the reader instead of at the call site.

  THE GAP IS A RECORDED DECISION, NOT AN OVERSIGHT (card#9637). Closing it by widening this
    derivation was tried and declined: every widening is one more pattern over the same source text,
    and the shape nobody has thought of escapes the wider pattern exactly as it escaped the narrow
    one. What would make the population total is the reads being ENUMERATED by the deploy instead
    of pattern-matched out of its source. card#9644's seam is the half of that which has landed —
    the target-tree gates are callable units now — and THE ENUMERATION IS NOT BUILT: every read
    above is still derived from source text, so nothing in this block has narrowed. A second guard
    over the same question is welcome here: this check does not claim to make one unnecessary.
LIMITS

if [ "${#missing[@]}" -gt 0 ]; then
  printf '\n⛔ %s — this repository does NOT satisfy bin/deploy.sh at %s\n' "$ME" "$SHORT" >&2
  printf '   missing: %s\n' "${missing[@]}" >&2
  cat >&2 <<END

   A real \`bin/deploy.sh --ref $SHORT\` REFUSES at phase A. Nothing would be deployed, on any host.
   The gate is not the defect: commit what the release is missing, or change the gate deliberately.
END
  exit 1
fi

printf '\n✅ %s — every target-tree input DERIVED from bin/deploy.sh phase A is present at %s\n' "$ME" "$SHORT"
printf '   (what that does and does not establish is printed above, under NOT PROVED BY A GREEN)\n'

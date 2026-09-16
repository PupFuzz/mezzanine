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
# So the reads are derived, every run, from the `bin/deploy.sh` **at the commit under test**. What
# IS written here is the far smaller judgement the source text cannot answer: whether an ABSENT
# path makes that gate refuse, warn, or pass.
#
# ⛔ AND THE DERIVATION IS TOTAL, NOT OPTIMISTIC — the whole point of round 2. A derivation that
# only ever FINDS reads cannot protect anything: a read written in a shape its pattern misses is
# absent from the derived set AND from the guard's comparison, the two sides stay equal, and the
# run goes GREEN over a gate nobody checked. Measured on the first version of this file, six of
# eight realistic ways to add a read escaped with exit 0. So the derivation has two halves:
#
#   1. A STRICT pattern (`READ_RE`) that both FINDS a call site and takes the path out of it —
#      one pattern, never two, because two notions of what a call site is would disagree one day.
#   2. A deliberately LOOSE SUPERSET of lines that COULD be a read: every line calling a member of
#      the reader family, and every line naming `$SHA`/`${SHA}` in any quoting. **Every superset
#      line that `READ_RE` does not match stops this check (exit 2).** A shape this file cannot
#      read is never a shape it passes over.
#
#   The reader family is itself DERIVED, two ways, and both are unioned: the names `bin/deploy.sh`
#   lists in `git_read_call_site`'s own `case` — that function exists to walk its own reader frames,
#   so the list is maintained there for the deploy's own reasons — plus every function whose body
#   invokes `git_at ls-tree|show|cat-file`, which catches a reader added without touching that case.
#   A line naming `$SHA` that runs some OTHER git subcommand is allowed only for the handful of
#   NON-READING subcommands named below; anything else — `cat-file`, `archive`, `log`, a subcommand
#   nobody has thought of yet — stops the check rather than being assumed harmless.
#
# ⛔ AND THE TABLE PINS THE DISPOSITION, NOT JUST THE PATH — the other half of the same defect.
# Comparing path SETS leaves a table that is silently WRONG the day a gate's `warn` becomes a
# `refuse`: same path, same derivation, green run, and a row that now describes something the
# deploy no longer does. So each row also pins WHERE the read is (the enclosing function) and a
# DIGEST of the disposition lines that gate reaches from it (§ the digest, below). Either moving
# stops the check and prints the new lines, so a maintainer who changes a disposition has to
# re-read the row — which is the table's whole purpose.
#
# RESIDUAL ESCAPES, NAMED (canon: name what you cannot verify). This derivation reads ONE file:
# `bin/deploy.sh` at the commit under test. A target-tree read added in a file it sources
# (`bin/supervision.sh`), or reached through `eval`, or made with a git binary held in a variable
# (`"$GIT" show …`), is outside the superset and would not be found. `bin/deploy.selftest.sh` is
# where those would be caught behaviourally; `bin/deploy-gate-inputs.selftest.sh` holds every
# escape shape this file DOES claim, each as a red.
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
#   0  every required input is present at <rev>
#   1  a required input is MISSING — a real `bin/deploy.sh --ref <rev>` refuses at phase A
#   2  the check could not run: no bin/deploy.sh at <rev>, no reads derived from it (an empty
#      derivation is a measurement that never happened, never a pass), a line that could be a read
#      written in a shape the derivation does not match, or a classification table that no longer
#      matches the reads — or their dispositions — in deploy.sh

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
server/composer.json	read	A6	required-nonempty	phase_a	6cd7000c6ab9	refuses: it is where the PHP floor is declared, and composer would meet it inside the window instead
server/database/migrations	ls	A10	optional	phase_a	69438f9e492a	passes, saying the release ships no migrations — git_ls_at's empty is a real answer
$mig	read	A10	run-time	phase_a	69438f9e492a	each migration the listing above named: the names come from the tree, not from this file, so presence is not in question — the read follows the listing. Its content predicate (ALGORITHM=) is out of scope below.
server/.env.example	read	A10b	optional	phase_a	c6e6540fcb03	warns that no key of the release was compared against the host's .env
server/bootstrap/app.php	read	A11	required	phase_a	910d5f07fb9a	refuses: server/artisan requires it, so every artisan command of that release fails inside the window
server/package-lock.json	ls	A12	required	phase_a	86bdec226cc1	refuses: npm ci needs it and package.json floats, so the prod asset build would not be reproducible
bin/supervision.sh	read	A13	required-nonempty	phase_a	f2f2b022e57d	refuses: the window installs the deployed release's crontab block from it
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
#   facts.tsv  family / modes / modewhat / modefn / examined
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

  # ── the reader family, leg 1: the names bin/deploy.sh itself lists in git_read_call_site's
  # `case`. That list exists for the deploy's own frame-walking, so it is maintained there.
  if (!("git_read_call_site" in FSTART))
    bail("bin/deploy.sh defines no git_read_call_site() at this commit — the reader family is derived from its case list, and without it nothing was derived")
  nprim = 0
  for (i = FSTART["git_read_call_site"]; i <= FEND["git_read_call_site"]; i++) {
    t = L[i]
    if (t !~ /^[[:space:]]*[A-Za-z_][A-Za-z0-9_]*([[:space:]]*\|[[:space:]]*[A-Za-z_][A-Za-z0-9_]*)+\)[[:space:]]*$/) continue
    sub(/^[[:space:]]+/, "", t); sub(/[[:space:]]*\)[[:space:]]*$/, "", t)
    k = split(t, pp, /[[:space:]]*\|[[:space:]]*/)
    for (a = 1; a <= k; a++) { PRIM[pp[a]] = 1; FAM[pp[a]] = 1; nprim++ }
  }
  if (nprim == 0)
    bail("git_read_call_site() at this commit lists no reader family in a `a | b | c)` case — that list is one of the two ways this check derives which functions read the target tree")

  # ── the reader family, leg 2: any function that runs a READING git subcommand on a rev it was
  # HANDED. This is what catches a reader added without touching the case list above.
  #   ⚠ "on a rev it was handed" is what keeps a GATE out of the family. A reader is parameterised
  #   — every one above reads `$__rev`/`$2` and never the global — while a gate that reads the
  #   release inline names `$SHA` itself. Without that clause, one raw `git_at show "$SHA:…"` typed
  #   into `phase_a` would make PHASE_A a reader, and every call to it a call site the strict
  #   pattern cannot match: a true stop, with a message about the wrong line. Such a line is
  #   already stopped, by name, as a $SHA line running a reading subcommand.
  for (name in FSTART) {
    hit = 0; own_sha = 0
    for (i = FSTART[name]; i <= FEND[name]; i++) {
      if (L[i] ~ /^[[:space:]]*#/) continue
      if (L[i] ~ /git(_at)?[[:space:]]+(-[cC][[:space:]]+[^[:space:]]+[[:space:]]+)*(ls-tree|show|cat-file)([[:space:]]|$)/) hit = 1
      if (L[i] ~ /[$]\{?SHA\}?/) own_sha = 1
    }
    if (hit && !own_sha) FAM[name] = 1
  }
  famlist = ""
  for (name in FAM) famlist = famlist (famlist == "" ? "" : " ") name

  # ── the accepted file MODES, read off the reader's own `case`: the one alternative it lets
  # through with an empty body. Restating `100644|100755` here would be the drift this file exists
  # to prevent, so it is taken from the source instead. `for (name in FAM)` has no defined order, so
  # the answer is required to be UNIQUE rather than whichever reader awk happened to walk first.
  modes_seen = ""; modefn_seen = ""
  for (name in FAM) {
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
    # The readers' own plumbing is exempt because it reads the rev it was HANDED — `$__rev`, never
    # the global. A line inside one that names $SHA is not that, and is examined like any other.
    if (FN[i] != "" && (FN[i] in PRIM) && t !~ /[$]\{?SHA\}?/) continue

    match(t, /^[[:space:]]*/); ind = RLENGTH

    ncalls = 0
    for (name in FAM) {
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
      if (nm != ncalls) {
        note(i, "a reader of the target tree is called here in a shape the derivation does not match, so this read would be in NEITHER the derived set nor the table — and the run would be green")
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

  print "family\t" famlist > (out "/facts.tsv")
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
    -v dispo_re="$DISPO_RE" -v allow="$NON_READING_SUBCOMMANDS" \
    -f "$WORK/derive.awk" < "$WORK/deploy.sh"

[ ! -s "$WORK/fatal" ] || die "the derivation's own input is not there in bin/deploy.sh at $SHORT" \
  "$(cat "$WORK/fatal")" \
  "" \
  "Nothing was measured. This check refuses rather than reporting a tree it never derived a" \
  "population for."

FAMILY="$(awk -F'\t' '$1=="family"{print $2}' "$WORK/facts.tsv")"
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
    "Write the read as \`git_read_at <var> \"\$SHA\" <path>\` on ONE line (which is how every other" \
    "read in that file is written), or widen READ_RE deliberately and add the case to" \
    "bin/deploy-gate-inputs.selftest.sh. Passing over it would leave the gate unchecked with this" \
    "lane still green — the defect this stop exists to end."
fi

[ -s "$WORK/reads.tsv" ] || die "no target-tree read was derived from bin/deploy.sh at $SHORT" \
  "This check finds phase A's reads by their git_read_at/git_ls_at call sites against \"\$SHA\"." \
  "Finding none means the readers were renamed, or the derivation no longer matches how they are" \
  "called — not that the deploy reads nothing. An empty population certifies an entire tree in one" \
  "line, so it is refused rather than reported as a pass."

# ── what was derived, as rows: kind, arg, fn, digest ───────────────────────────────────────────
: > "$WORK/derived.tsv"; : > "$WORK/derived.full"
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
  IFS=$'\t' read -r c_path c_kind c_gate c_rule c_fn c_dg c_why <<< "$row"
  if [ "$c_rule" = run-time ]; then n_runtime=$((n_runtime + 1)); else n_fixed=$((n_fixed + 1)); fi
done

printf 'bin/deploy.sh phase A — target-tree inputs at %s\n' "$SHORT"
printf '  population derived from bin/deploy.sh at %s: %d fixed path(s), %d built at run time\n' \
  "$SHORT" "$n_fixed" "$n_runtime"
printf '  reader family derived from that same file: %s\n' "$FAMILY"
printf '  every one of the %d line(s) that could be a read was matched by the derivation\n\n' "$EXAMINED"

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

  CONTENT PREDICATES not covered, pending a seam in bin/deploy.sh (card#9644): A6's constraint
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

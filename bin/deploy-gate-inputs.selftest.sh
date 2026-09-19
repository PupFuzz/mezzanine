#!/usr/bin/env bash
# deploy-gate-inputs.selftest.sh — hermetic, network-free acceptance for bin/deploy-gate-inputs.sh.
# card#9637
#
# WHY IT EXISTS. `bin/deploy-gate-inputs.sh` exists because a gate's demands and this repository
# were never in the same room. Its FIRST version had that defect one level up: its derivation only
# ever FOUND reads, so a read written in a shape its pattern missed was absent from the derived set
# AND from the table it is compared against — the two sides stayed equal and the run went GREEN over
# a gate nobody had checked. Six of eight realistic ways to add a read escaped that way. The fix is
# a derivation that STOPS on what it sees (a loose superset of lines that could be a read; anything
# in it the strict pattern does not match stops the check) and a table that pins each read's
# DISPOSITION, not just its path. That fix is not totality, and the checker does not claim it is:
# shapes its superset does not see still run green, and they are enumerated in the one place —
# the `NOT PROVED BY A GREEN` block `bin/deploy-gate-inputs.sh` prints on every run. The cases
# below are the escapes it DOES claim to stop; each is a red here for that reason.
#
# ⛔ AND THAT FIX IS ONLY WORTH ANYTHING IF IT IS RE-RUN. The eight escapes were first measured
# against throwaway fixtures in a temp dir that nothing in this repository kept, so the pattern
# could have been tightened the next day with the lane staying green forever. Every one of them is
# a case below, against the REAL script, and this file runs in the same CI lane as the check it
# tests.
#
# WHAT IS REAL AND WHAT IS NOT.
#   REAL: bash, git, the whole of `bin/deploy-gate-inputs.sh`, and a fixture repository built from
#         THIS repository's own tracked tree at HEAD — with `bin/deploy.sh` taken from the working
#         copy, because that is the file the checker's CLASSIFIED table is pinned against.
#   NOT RUN: `bin/deploy.sh` itself. Nothing here deploys, reaches a host, opens a `.env`, needs a
#         credential or touches the network; every fixture lives under one temp dir.
#
# RED-FIRST, WITH ONE CONTROL. `control: the repository as it is` is the single variable every red
# below differs from: the same fixture, plus exactly one mutation. A pass is evidence only because
# that control passes and each mutation is watched to fail — and to fail for the RIGHT REASON,
# which is why every case asserts on the message and not on the exit code alone (a check with two
# reasons to exit 2 would otherwise look like it discriminates when it does not).
#
# ⚠ THE MUTATION POINT IS DERIVED, NOT WRITTEN DOWN. Cases are inserted after the LAST line of
# `bin/deploy.sh` that the checker's own `READ_PREFIX_RE`/`READ_ARG_RE` match — both extracted from
# the checker at run time, never restated here — so a `bin/deploy.sh` that moves its gates around
# does not silently turn these cases into no-ops against a line that is no longer a read.
#
# RUN: bin/deploy-gate-inputs.selftest.sh          (exit 0 = every case passed)

set -uo pipefail

HERE="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
CHECK="$HERE/deploy-gate-inputs.sh"
REPO="$(cd "$HERE/.." && pwd)"
[ -r "$CHECK" ] || { echo "selftest: $CHECK not found" >&2; exit 1; }
[ -r "$REPO/bin/deploy.sh" ] || { echo "selftest: $REPO/bin/deploy.sh not found" >&2; exit 1; }

T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT

fails=0; cases=0
ok()  { printf '  ok   %s\n' "$1"; }
bad() { printf '  FAIL %s\n' "$1" >&2; fails=$((fails + 1)); }
# `eq` branches rather than chaining `A && ok || bad`: in that chain the reporter's OWN exit status
# is a second way to reach `bad`, so a printf that fails (a closed or full stdout under a CI lane's
# redirection) turns a case that PASSED into a FAIL, for a reason that is not about the check. `has`
# below already branches; this is the same shape written the same way.
eq()  { cases=$((cases+1)); if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 — expected '$2', got '$3'"; fi; }
has() { cases=$((cases+1)); case "$3" in *"$2"*) ok "$1" ;; *) bad "$1 — output did not contain '$2'" ;; esac; }
section() { printf '\n── %s\n' "$1"; }

# git, with an identity and no signing: the fixtures are commits, and a machine whose global config
# signs or names nobody must not turn this suite red for a reason that is not about the check.
G() { local d="$1"; shift; git -c user.email=selftest@invalid -c user.name=selftest \
        -c commit.gpgsign=false -c init.defaultBranch=main -C "$d" "$@"; }

# ── the derivation's own patterns, taken FROM the checker ──────────────────────────────────────
# Extracted, never restated: these decide where every mutation below is inserted, so a copy here
# would be a second notion of what a read looks like — the exact defect this suite exists to pin.
PFX="$(sed -n "s/^READ_PREFIX_RE='\(.*\)'\$/\1/p" "$CHECK")"
ARG="$(sed -n "s/^READ_ARG_RE='\(.*\)'\$/\1/p" "$CHECK")"
# Both operands here are TESTS with no side effect, so this chain is exactly `if ! (A && B)`: the
# block runs when either pattern came back empty, which is the intent. The hazard SC2015 names —
# `C` reached because `B` was an action that failed — has no operand to arrive through.
# shellcheck disable=SC2015  # A and B are both tests; C is the "either was empty" branch
[ -n "$PFX" ] && [ -n "$ARG" ] || {
  echo "selftest: could not extract READ_PREFIX_RE/READ_ARG_RE from $CHECK — the insertion point of" >&2
  echo "          every case below is derived from them, so nothing here would be testing anything." >&2
  exit 1; }
RRE="$PFX$ARG"

# ── the base fixture: this repository's own tree at HEAD ───────────────────────────────────────
mkdir -p "$T/base"
git -C "$REPO" archive HEAD | tar -x -C "$T/base" || { echo "selftest: git archive HEAD failed" >&2; exit 1; }
# bin/deploy.sh from the WORKING copy: the checker's CLASSIFIED table pins digests of that file's
# disposition lines, so the fixture has to hold the same one for the control to mean anything.
cp "$REPO/bin/deploy.sh" "$T/base/bin/deploy.sh"
G "$T/base" init -q
G "$T/base" add -A
G "$T/base" commit -q -m "fixture: this repository at HEAD"

ANCHOR="$(grep -nE "$RRE" "$T/base/bin/deploy.sh" | tail -1 | cut -d: -f1)"
[ -n "$ANCHOR" ] || { echo "selftest: no line of bin/deploy.sh matches the checker's READ_RE — every" >&2
                      echo "          case below would insert into nothing." >&2; exit 1; }

BASE_SHA="$(G "$T/base" rev-parse HEAD)"
DIR="$T/base"; OUT=""; RC=0
# One fixture repository, restored to its base commit before each case, rather than a copy per
# case: the restore is `reset --hard <the base commit>` + `clean -fdx` against an ABSOLUTE sha —
# never `HEAD~1`, which would walk backwards through whatever the last case happened to commit.
mkcase() { # mkcase <name> — the fixture, back at the state the control passed on
  G "$DIR" reset -q --hard "$BASE_SHA"
  G "$DIR" clean -qfdx
}
insert() { # insert <line>… — after the last derived read of $DIR's bin/deploy.sh, inside whichever
           # function holds it (card#9644 carved phase A's target-tree gates into functions of their
           # own, so that is a gate_* function now and no longer phase_a itself)
  local at f="$DIR/ins.$$"
  printf '%s\n' "$@" > "$f"
  at="$(grep -nE "$RRE" "$DIR/bin/deploy.sh" | tail -1 | cut -d: -f1)"
  awk -v at="$at" -v f="$f" 'NR==at { print; while ((getline l < f) > 0) print l; next } { print }' \
    "$DIR/bin/deploy.sh" > "$DIR/bin/deploy.sh.new"
  mv "$DIR/bin/deploy.sh.new" "$DIR/bin/deploy.sh"
  rm -f "$f"
}
wrap_family() { # wrap_family — write bin/deploy.sh's reader-family case label over TWO lines with a
                # trailing `\`, which is what card#9611 did to it when the family outgrew one line.
                # The split point is DERIVED from the label's own last alternative, never written.
  awk '
    /^git_read_call_site\(\)[[:space:]]*\{/ { inf = 1 }
    inf && /^\}[[:space:]]*$/               { inf = 0 }
    inf && !done && /^[[:space:]]*[A-Za-z_][A-Za-z0-9_]*([[:space:]]*\|[[:space:]]*[A-Za-z_][A-Za-z0-9_]*)+\)[[:space:]]*$/ {
      n = split($0, p, / \| /)
      head = p[1]
      for (i = 2; i < n; i++) head = head " | " p[i]
      print head " | \\"
      print "        " p[n]
      done = 1; next
    }
    { print }' "$DIR/bin/deploy.sh" > "$DIR/bin/deploy.sh.new"
  mv "$DIR/bin/deploy.sh.new" "$DIR/bin/deploy.sh"
}
family_add() { # family_add <name> [body…] — name <name> in bin/deploy.sh's OWN reader-family case
               # list (the list card#9611 grew), and define it at the end of the file if a body is
               # given. Naming it without defining it is a case of its own below.
  local nm="$1"; shift
  awk -v nm="$nm" '
    /^git_read_call_site\(\)[[:space:]]*\{/ { inf = 1 }
    inf && /^\}[[:space:]]*$/               { inf = 0 }
    inf && !done && /^[[:space:]]*[A-Za-z_][A-Za-z0-9_]*([[:space:]]*\|[[:space:]]*[A-Za-z_][A-Za-z0-9_]*)+\)[[:space:]]*$/ {
      sub(/\)[[:space:]]*$/, " | " nm ")"); done = 1
    }
    { print }' "$DIR/bin/deploy.sh" > "$DIR/bin/deploy.sh.new"
  mv "$DIR/bin/deploy.sh.new" "$DIR/bin/deploy.sh"
  [ "$#" -eq 0 ] || printf '%s\n' "$@" >> "$DIR/bin/deploy.sh"
}
run() { # run — commit $DIR's mutation and ask the checker about it
  G "$DIR" add -A >/dev/null
  G "$DIR" diff --cached --quiet || G "$DIR" commit -q -m mutation
  OUT="$(cd "$DIR" && bash "$CHECK" --ref HEAD 2>&1)"; RC=$?
  # VERBOSE=1 prints what each mutation actually made the check SAY. A case that exits 2 for the
  # wrong reason looks identical to one that exits 2 for the right one from the assertions alone.
  [ -z "${VERBOSE:-}" ] || { printf '    → exit %s\n' "$RC"
                             printf '%s' "$OUT" | grep -E '⛔|✅|—' | head -3 | sed 's/^/      /'; }
}

# ── the control ────────────────────────────────────────────────────────────────────────────────
section "CONTROL — the repository as it is"
mkcase control; run
eq  "control: exit 0"                               0 "$RC"
has "control: says every input is present"          "every target-tree input" "$OUT"
has "control: the population is derived, not typed" "population derived from bin/deploy.sh" "$OUT"
has "control: the reader family is derived too"     "reader family derived" "$OUT"
has "control: says every line it SEES was matched"  "SEES as a possible read was matched by it" "$OUT"
# The claim about what a green does NOT establish has ONE home — the block the check prints — and
# three in-repo surfaces (its own header, the workflow header, its changelog entry for card#9637)
# point at it by name rather than keeping a copy. Deleting the block would leave those three
# pointing at nothing, which is how the four copies that preceded it all went stale; this is the
# assertion that reds instead.
has "control: prints what a green does NOT prove"   "WHAT A GREEN HERE PROVES, AND WHAT IT DOES NOT" "$OUT"
has "control: names the decision that left the gap" "card#9637" "$OUT"

# ── the eight escape shapes ────────────────────────────────────────────────────────────────────
# Each adds ONE read of a path this repository does not carry. Before round 2, six of the eight
# exited 0 with the check still reporting its full population — the read was in neither the derived
# set nor the table, so the two agreed and the lane was green over an unchecked gate.
section "ESCAPES — a new read the derivation must never miss"

# ⚠ THE SINGLE QUOTES BELOW ARE THE POINT, and each case says on its own line which shape it
# holds open. Two kinds run through this section. Shell SOURCE, which this suite injects into the
# fixture's `bin/deploy.sh`: there the `$` IS the escape shape under test, and letting it expand
# here would inject THIS file's value and leave the case measuring a line nobody wrote. And
# assertion NEEDLES, which quote the checker's own output, where those `$` and backquotes are
# printed literally and an expansion would match nothing.

mkcase esc_control
# shellcheck disable=SC2016  # injected source: the matched `$SHA`
insert '  git_read_at newv "$SHA" server/NEWPATH.json'
run
eq  "the matched shape (control for the seven below): exit 2" 2 "$RC"
has "the matched shape: names the unclassified path"          "NOT classified here: server/NEWPATH.json" "$OUT"

mkcase esc_a
# shellcheck disable=SC2016  # injected source: an unquoted `$SHA`
insert '  git_read_at newv $SHA server/NEWPATH.json'
run
eq  "A  \$SHA unquoted: exit 2"                     2 "$RC"
has "A  \$SHA unquoted: the derivation stops"       "shape the derivation does not match" "$OUT"
# The REMEDY that stop prints is advice about what READ_RE matches — a restatement of the pattern,
# so it is guarded here rather than trusted (card#9693: the advice told a maintainer to write the
# read the way the line they were sent to already was, and sent them in a circle). Every shape it
# prints is matched against the checker's own pattern, extracted above.
n_advice=0; bad_advice=0
# shellcheck disable=SC2016  # assertion needle: `$SHA` as the checker PRINTS it, not as a value
while IFS= read -r advice; do
  [ -n "$advice" ] || continue
  n_advice=$((n_advice + 1))
  printf '%s\n' "$advice" | grep -qE "$RRE" || bad_advice=$((bad_advice + 1))
done < <(printf '%s\n' "$OUT" | grep -E '^[[:space:]]+git_[a-z_]+ var "\$SHA" ')
eq  "A  the remedy's own example reads are matched by READ_RE (shapes/unmatched)" \
    "2 0" "$n_advice $bad_advice"

mkcase esc_b
# shellcheck disable=SC2016  # injected source: a braced `${SHA}`
insert '  git_read_at newv "${SHA}" server/NEWPATH.json'
run
eq  "B  \${SHA} braced: exit 2"                     2 "$RC"
has "B  \${SHA} braced: the derivation stops"       "shape the derivation does not match" "$OUT"

mkcase esc_c
# shellcheck disable=SC2016  # injected source: `$SHA` on a call split over two lines
# The backslash is the LITERAL trailing continuation this case injects; the `'`
# after it closes the argument, since a single-quoted string has no escapes.
# shellcheck disable=SC1003  # a literal trailing backslash — see above
insert '  git_read_at newv "$SHA" \' '    server/NEWPATH.json'
run
eq  "C  the call split over two lines: exit 2"      2 "$RC"
has "C  split call: named as a continuation, not derived as the path \`\\\`" \
    "continued onto the NEXT line" "$OUT"

mkcase esc_d
# shellcheck disable=SC2016  # injected source: a different rev variable, `$TARGET_SHA`
insert '  git_read_at newv "$TARGET_SHA" server/NEWPATH.json'
run
eq  "D  a different rev variable: exit 2"           2 "$RC"
has "D  a different rev variable: the derivation stops" "shape the derivation does not match" "$OUT"

mkcase esc_e
# shellcheck disable=SC2016  # injected source: `$SHA` read by a third reader function
insert '  git_show_at newv "$SHA" server/NEWPATH.json'
# shellcheck disable=SC2016  # injected source: the `"$2:$3"` inside an appended reader body
printf '%s\n' 'git_show_at() { git_at show "$2:$3"; }' >> "$DIR/bin/deploy.sh"
run
eq  "E  a THIRD reader function: exit 2"            2 "$RC"
has "E  a third reader: found by its body running a reading git subcommand" \
    "shape the derivation does not match" "$OUT"

mkcase esc_f
# shellcheck disable=SC2016  # injected source: the positionals and `$SHA` inside a wrapper
insert '  read_target() { git_read_at "$1" "$SHA" "$2"; }' '  read_target newv server/NEWPATH.json'
run
eq  "F  a wrapper around a reader: exit 2"          2 "$RC"
has "F  a wrapper: the reader call inside it is the call site" \
    "shape the derivation does not match" "$OUT"

mkcase esc_h
# shellcheck disable=SC2016  # injected source: `$SHA` and the `$SRV` the path is built from
insert '  SRV=server; git_read_at newv "$SHA" "$SRV/NEWPATH.json"'
run
eq  "H  a path assembled from a variable: exit 2"   2 "$RC"
# shellcheck disable=SC2016  # assertion needle: `$SRV/NEWPATH.json`
has "H  assembled path: classified as run-time or not at all, never passed over" \
    'NOT classified here: $SRV/NEWPATH.json' "$OUT"

# Not one of the eight, and the reason the superset also covers $SHA lines: a read made with no
# reader at all.
mkcase esc_raw
# shellcheck disable=SC2016  # injected source: the `$(...)` and `$SHA:` of a reader-less read
insert '  body="$(git_at show "$SHA:server/NEWPATH.json")"'
run
eq  "raw \`git show \$SHA:…\`, no reader involved: exit 2" 2 "$RC"
# shellcheck disable=SC2016  # assertion needle: the backquoted `git show`
has "raw git show: named as a subcommand that is not one of the non-reading ones" \
    'runs `git show`' "$OUT"
# …and ONE line is named, not two. A gate that reads the release inline must not be taken for a
# reader FUNCTION: that would make every call to the gate a call site the strict pattern cannot
# match, and point a maintainer at the gate's CALLER instead of at the line they just wrote.
eq  "raw git show: the gate holding it is not itself taken for a reader" \
    1 "$(printf '%s' "$OUT" | grep -c 'bin/deploy.sh:[0-9]* —')"

# ── the family, and what being IN it does and does not mean ────────────────────────────────────
# card#9693. `bin/deploy.sh`'s reader family is `git_read_call_site`'s own case list, which exists
# for the deploy's frame-walking and is a DIFFERENT population from "functions that read a path out
# of the release". When card#9611 grew that list past one line, the checker read the list ITSELF as
# reads it could not parse, and stopped — on a healthy deploy script, with advice that did not
# apply to the lines it named. Each case below is one shape of that, and each was watched to fail
# against the derivation as it stood before this section existed.
section "THE FAMILY — a name in it is a candidate, and its own body is what rules on it"

# The case list bash lets you write over several lines. Nothing about the deploy changes.
mkcase fam_wrapped
wrap_family
run
eq  "the family's case list continued with \`\\\`: exit 0"  0 "$RC"
has "continued case list: the family is still derived from it" "reader family derived" "$OUT"
eq  "continued case list: git_ls_at, named on its FIRST line, is still a derived reader" \
    1 "$(printf '%s\n' "$OUT" | grep -c '^  reader family derived .*[^_]git_ls_at')"

# ⭐ And a read written EXACTLY as the stop's own advice prescribes is derived, not stopped on. This
# is the case the round-4 report was wrong about: `READ_RE` matches `git_ls_at` and always did, and
# these lines failed because the LOOSE half no longer held the name the STRICT half had just matched.
mkcase fam_wrapped_read
wrap_family
# shellcheck disable=SC2016  # injected source: the matched `$SHA`
insert '  git_ls_at newv "$SHA" server/NEWPATH.json'
run
eq  "a git_ls_at read under a continued case list: exit 2" 2 "$RC"
has "git_ls_at read: DERIVED and reported unclassified, not stopped on as unparseable" \
    "NOT classified here: server/NEWPATH.json" "$OUT"

# A refusal helper in the family reads nothing at all, and its call sites are not gate inputs.
mkcase fam_nonreader
family_add git_bail_now 'git_bail_now() { refuse "$@"; }'
insert '  git_bail_now "nothing was read"'
run
eq  "a refusal helper named in the family: exit 0"  0 "$RC"
has "refusal helper: ruled out of the family by its own body" "git_bail_now — it runs no git at all" "$OUT"

# A ref resolver in the family reads a NAME, and no path of the release can come back through it.
mkcase fam_ref
# shellcheck disable=SC2016  # injected source: the `$2` a parameterised resolver reads
family_add git_name_oid 'git_name_oid() { git_at rev-parse --verify --quiet "$2"; }'
# shellcheck disable=SC2016  # injected source: the `$REMOTE` a ref name is built from
insert '  git_name_oid oid "refs/remotes/$REMOTE/main"'
run
eq  "a ref resolver named in the family: exit 0"    0 "$RC"
# shellcheck disable=SC2016  # assertion needle: the checker prints those backquotes literally
has "ref resolver: ruled out by the subcommand it runs" \
    'git_name_oid — it runs only `git rev-parse`' "$OUT"

# A read of an object BY BARE ID is a read, and it is not a read of a path out of the tree.
mkcase fam_bareid
# shellcheck disable=SC2016  # injected source: the `$2` an object id arrives in
family_add git_type_at 'git_type_at() { git_at cat-file -t "$2"; }'
# shellcheck disable=SC2016  # injected source: `$SHA` handed to it as an OBJECT, not as a tree
insert '  git_type_at objtype "$SHA"'
run
eq  "an object read by bare id, named in the family: exit 0" 0 "$RC"
has "bare-id read: ruled out as naming no path"     "git_type_at — it reads an object BY ID and names no path" "$OUT"

# ⛔ AND THE GATE IS STILL A GATE. Being named in the family EXEMPTS NOTHING: a function that really
# does read a path out of the tree is a reader whoever lists it, and a call to it the strict pattern
# cannot parse still stops this check. This case passes before and after card#9693 — that is its
# point — so the evidence that it discriminates is elsewhere: against a derivation deliberately
# broken to exempt every name the family list carries (the mistake the four cases above could have
# been "fixed" with), its MESSAGE assertion reds while the exit status stays 2 for an unrelated
# reason. That is the hazard this suite states at the top, met here rather than argued about.
mkcase fam_listed_reader
# shellcheck disable=SC2016  # injected source: the `"$2:$3"` of a real reader body
family_add git_slurp_at 'git_slurp_at() { git_at show "$2:$3"; }'
# shellcheck disable=SC2016  # injected source: `$SHA`, read by a name the strict pattern will not match
insert '  git_slurp_at newv "$SHA" server/NEWPATH.json'
run
eq  "a REAL reader named in the family, called unparseably: exit 2" 2 "$RC"
has "a listed reader is still a reader: the call site stops the check" \
    "shape the derivation does not match" "$OUT"

# A name in the family this file defines nothing for cannot be ruled on, and is not assumed either way.
mkcase fam_undefined
family_add git_nowhere
run
eq  "a family name with no function behind it: exit 2" 2 "$RC"
has "undefined family name: says the body it would rule on is not there" \
    "defines no such function at the top level" "$OUT"

# …and neither is a subcommand this check has no reading for. `archive` puts paths on disk.
mkcase fam_unknown_sub
# shellcheck disable=SC2016  # injected source: the `$2`/`$3` of an archive extraction
family_add git_arch_at 'git_arch_at() { git_at archive "$2" -- "$3"; }'
run
eq  "a family member running an unknown git subcommand: exit 2" 2 "$RC"
has "unknown subcommand: named, and not assumed harmless" \
    "a subcommand this check does not know to be a read of the release tree" "$OUT"

# ── the disposition pin ────────────────────────────────────────────────────────────────────────
section "DISPOSITIONS — the table describes what the gate DOES, and is pinned to it"

mkcase dispo
# The first disposition at or after the last derived read — inside that read's region by
# construction, so the pin must move. Derived, so this stays a real mutation if the gates move.
at="$(awk -v s="$ANCHOR" 'NR>=s && /(^|[^A-Za-z0-9_])refuse([[:space:]]|$)/ { print NR; exit }' \
        "$DIR/bin/deploy.sh")"
[ -n "$at" ] && sed -i "${at}s/refuse/warn/" "$DIR/bin/deploy.sh"
run
eq  "a gate's refuse becomes a warn: exit 2"        2 "$RC"
has "refuse→warn: the row's pinned disposition MOVED" "pinned location/disposition MOVED" "$OUT"
has "refuse→warn: the check prints the lines the digest now covers" "the lines the digest now covers" "$OUT"

mkcase vanish
sed -i "${ANCHOR}d" "$DIR/bin/deploy.sh"
run
eq  "a classified read deleted from the deploy: exit 2" 2 "$RC"
has "a read that vanished is named, not quietly dropped" "no longer read by the deploy" "$OUT"

# ── the derivation's own inputs ────────────────────────────────────────────────────────────────
section "THE DERIVATION'S OWN INPUTS — an empty measurement is never a pass"

mkcase noreads
grep -vE "$RRE" "$DIR/bin/deploy.sh" > "$DIR/bin/deploy.sh.new"
mv "$DIR/bin/deploy.sh.new" "$DIR/bin/deploy.sh"
run
eq  "no read derivable at all: exit 2"              2 "$RC"
has "an empty derivation is refused, not reported as a pass" "no target-tree read was derived" "$OUT"

mkcase nofamily
awk '/^git_read_call_site\(\)[[:space:]]*\{/ { skip = 1 } skip && /^\}[[:space:]]*$/ { skip = 0; next }
     !skip { print }' "$DIR/bin/deploy.sh" > "$DIR/bin/deploy.sh.new"
mv "$DIR/bin/deploy.sh.new" "$DIR/bin/deploy.sh"
run
eq  "the reader family's own source removed: exit 2" 2 "$RC"
has "no family derivable: says so rather than deriving an empty one" "git_read_call_site" "$OUT"

mkcase nodeploy
rm -f "$DIR/bin/deploy.sh"
run
eq  "no bin/deploy.sh at the commit: exit 2"        2 "$RC"
has "no deploy script: says nothing was measured"   "is not readable at" "$OUT"

# The check CRASHING must not be readable as a finding. Exit 1 is its word for "a required input is
# MISSING" — a verdict about the release — and an unexpected failure under `set -e` leaves the shell
# exiting on that command's own status, which is very often 1. `set -E` + the ERR trap is what keeps
# the two apart, and `-E` without a trap is an inert flag, so the trap is watched to fire here.
mkcase crash
mkdir -p "$DIR/stub"
printf '#!/bin/sh\nexit 3\n' > "$DIR/stub/sha256sum"; chmod +x "$DIR/stub/sha256sum"
OUT="$(cd "$DIR" && PATH="$DIR/stub:$PATH" bash "$CHECK" --ref HEAD 2>&1)"; RC=$?
eq  "a tool the check needs fails: exit 2, NOT 1"   2 "$RC"
has "a crash says it established nothing, and is not a finding" "established NOTHING" "$OUT"

# ── the invocation ─────────────────────────────────────────────────────────────────────────────
# A mistyped COMMAND LINE must not be readable as a finding either (card#9831). `${2:?}` on an
# option's value fails as a parameter expansion, which is a shell error and never trips the ERR trap
# above: the shell prints its own line and exits 1 — this check's word for "a required input is
# MISSING". Each case asserts the `⛔` banner as well as the code, because the shell's own message
# carries the same words as `die`'s and exit 2 alone is reachable by more than one path.
section "THE INVOCATION — a mistyped command line is never a verdict about the release"

mkcase argv
OUT="$(cd "$DIR" && bash "$CHECK" --ref 2>&1)"; RC=$?
eq  "--ref with no value: exit 2, NOT 1"            2 "$RC"
has "--ref with no value: stops with the banner"    "⛔ deploy-gate-inputs.sh — --ref needs a value" "$OUT"

OUT="$(cd "$DIR" && bash "$CHECK" --ref '' 2>&1)"; RC=$?
eq  "--ref with an empty value: exit 2, NOT 1"      2 "$RC"
has "--ref with an empty value: stops with the banner" "⛔ deploy-gate-inputs.sh — --ref needs a value" "$OUT"

OUT="$(cd "$DIR" && bash "$CHECK" --reff HEAD 2>&1)"; RC=$?
eq  "an unknown argument: exit 2"                   2 "$RC"
has "an unknown argument: stops with the banner, naming it" "⛔ deploy-gate-inputs.sh — unknown argument: --reff" "$OUT"

# ── findings about the RELEASE — exit 1, and never confused with exit 2 ────────────────────────
section "FINDINGS — this repository does not satisfy the gate"

mkcase absent
rm -f "$DIR/server/package-lock.json"
run
eq  "a required input absent: exit 1 (a finding, not a failure)" 1 "$RC"
has "absent input: names the path and its gate"     "server/package-lock.json (A12)" "$OUT"
has "absent input: says a real deploy refuses at phase A" "REFUSES at phase A" "$OUT"

mkcase empty
: > "$DIR/server/composer.json"
run
eq  "a required-nonempty input present but empty: exit 1" 1 "$RC"
has "empty input: reported as empty, not as absent"  "present but EMPTY" "$OUT"

mkcase symlink
rm -f "$DIR/server/bootstrap/app.php"
ln -s ../top.txt "$DIR/server/bootstrap/app.php"
run
eq  "a required input committed as a SYMLINK: exit 1" 1 "$RC"
has "symlink: the mode is the finding"               "mode 120000" "$OUT"
has "symlink: quotes the reader's own word for it"   "a symbolic link" "$OUT"

printf '\n──────────────────────────────────────────────\n'
if [ "$fails" -eq 0 ]; then
  printf 'deploy-gate-inputs.selftest.sh: %d assertions, all passed\n' "$cases"; exit 0
fi
printf 'deploy-gate-inputs.selftest.sh: %d assertions, %d FAILED\n' "$cases" "$fails" >&2; exit 1

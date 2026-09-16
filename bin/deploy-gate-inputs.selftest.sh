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
eq()  { cases=$((cases+1)); [ "$2" = "$3" ] && ok "$1" || bad "$1 — expected '$2', got '$3'"; }
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
insert() { # insert <line>… — after the last derived read of $DIR's bin/deploy.sh, inside phase_a
  local at f="$DIR/ins.$$"
  printf '%s\n' "$@" > "$f"
  at="$(grep -nE "$RRE" "$DIR/bin/deploy.sh" | tail -1 | cut -d: -f1)"
  awk -v at="$at" -v f="$f" 'NR==at { print; while ((getline l < f) > 0) print l; next } { print }' \
    "$DIR/bin/deploy.sh" > "$DIR/bin/deploy.sh.new"
  mv "$DIR/bin/deploy.sh.new" "$DIR/bin/deploy.sh"
  rm -f "$f"
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
# three in-repo surfaces (its own header, the workflow header, docs/CHANGELOG.md) point at it by name
# rather than keeping a copy. Deleting the block would leave those three pointing at nothing, which
# is how the four copies that preceded it all went stale; this is the assertion that reds instead.
has "control: prints what a green does NOT prove"   "WHAT A GREEN HERE PROVES, AND WHAT IT DOES NOT" "$OUT"
has "control: names the decision that left the gap" "card#9637" "$OUT"

# ── the eight escape shapes ────────────────────────────────────────────────────────────────────
# Each adds ONE read of a path this repository does not carry. Before round 2, six of the eight
# exited 0 with the check still reporting its full population — the read was in neither the derived
# set nor the table, so the two agreed and the lane was green over an unchecked gate.
section "ESCAPES — a new read the derivation must never miss"

mkcase esc_control
insert '  git_read_at newv "$SHA" server/NEWPATH.json'
run
eq  "the matched shape (control for the seven below): exit 2" 2 "$RC"
has "the matched shape: names the unclassified path"          "NOT classified here: server/NEWPATH.json" "$OUT"

mkcase esc_a
insert '  git_read_at newv $SHA server/NEWPATH.json'
run
eq  "A  \$SHA unquoted: exit 2"                     2 "$RC"
has "A  \$SHA unquoted: the derivation stops"       "shape the derivation does not match" "$OUT"

mkcase esc_b
insert '  git_read_at newv "${SHA}" server/NEWPATH.json'
run
eq  "B  \${SHA} braced: exit 2"                     2 "$RC"
has "B  \${SHA} braced: the derivation stops"       "shape the derivation does not match" "$OUT"

mkcase esc_c
insert '  git_read_at newv "$SHA" \' '    server/NEWPATH.json'
run
eq  "C  the call split over two lines: exit 2"      2 "$RC"
has "C  split call: named as a continuation, not derived as the path \`\\\`" \
    "continued onto the NEXT line" "$OUT"

mkcase esc_d
insert '  git_read_at newv "$TARGET_SHA" server/NEWPATH.json'
run
eq  "D  a different rev variable: exit 2"           2 "$RC"
has "D  a different rev variable: the derivation stops" "shape the derivation does not match" "$OUT"

mkcase esc_e
insert '  git_show_at newv "$SHA" server/NEWPATH.json'
printf '%s\n' 'git_show_at() { git_at show "$2:$3"; }' >> "$DIR/bin/deploy.sh"
run
eq  "E  a THIRD reader function: exit 2"            2 "$RC"
has "E  a third reader: found by its body running a reading git subcommand" \
    "shape the derivation does not match" "$OUT"

mkcase esc_f
insert '  read_target() { git_read_at "$1" "$SHA" "$2"; }' '  read_target newv server/NEWPATH.json'
run
eq  "F  a wrapper around a reader: exit 2"          2 "$RC"
has "F  a wrapper: the reader call inside it is the call site" \
    "shape the derivation does not match" "$OUT"

mkcase esc_h
insert '  SRV=server; git_read_at newv "$SHA" "$SRV/NEWPATH.json"'
run
eq  "H  a path assembled from a variable: exit 2"   2 "$RC"
has "H  assembled path: classified as run-time or not at all, never passed over" \
    'NOT classified here: $SRV/NEWPATH.json' "$OUT"

# Not one of the eight, and the reason the superset also covers $SHA lines: a read made with no
# reader at all.
mkcase esc_raw
insert '  body="$(git_at show "$SHA:server/NEWPATH.json")"'
run
eq  "raw \`git show \$SHA:…\`, no reader involved: exit 2" 2 "$RC"
has "raw git show: named as a subcommand that is not one of the non-reading ones" \
    'runs `git show`' "$OUT"
# …and ONE line is named, not two. A gate that reads the release inline must not be taken for a
# reader FUNCTION: that would make every call to the gate a call site the strict pattern cannot
# match, and point a maintainer at `phase_a`'s caller instead of at the line they just wrote.
eq  "raw git show: the gate holding it is not itself taken for a reader" \
    1 "$(printf '%s' "$OUT" | grep -c 'bin/deploy.sh:[0-9]* —')"

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

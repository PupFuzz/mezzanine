#!/usr/bin/env bash
# vendor-pin-check.sh — the two VENDORED files' headers declare what their bodies are; this
# pins that declaration to a sha256 so it cannot quietly stop being true.
#
# WHY IT EXISTS. `bin/promote-cards-by-token` declared its whole body a BYTE-FOR-BYTE copy of
# upstream at pin e2f131f and called that "a one-diff check". Nothing ran the diff. PR #51 had
# edited the body at two sites eleven days earlier and the declaration went on reading as true
# for everyone downstream — found by PupFuzz/agent-roundtable#463 (2026-09-11). A declaration
# with no check is not a contract, it is a comment, and it is worse than silence: the next
# reader audits their work against a stated provenance that is false and gets confidence
# instead of a question. The sibling `.selftest.sh` carried the same shape of declaration and
# happened to be TRUE — it is pinned here too, because "true today" is not a check.
#
# WHAT IT PINS. The BODY only, never the header. Body = from the first line after line 1 (the
# shebang) that is neither blank nor a `#` comment, to EOF — DERIVED at run time by the awk
# below and never written down as a line number, so the headers above it are free to grow (and
# they have) without touching the pin. Blank lines count as header for that same reason: a
# comment block that gains a paragraph break must not read as a body change. A header edit is
# expected and passes; a body edit reds. That split is the whole design: the header is
# mezzanine's to write, the body is not.
#
# WHAT IT ALSO CHECKS, AND WHAT IT CANNOT. Each DECLARED local edit — the mover's two sites
# from #51 — is named in the FRAGMENTS table below by an unbroken fragment of its own text, the
# same fragment that file's header quotes, and the check requires every one of them to still be
# present IN THE BODY. That is the leg that stops the pin being bumped past a list that has
# gone stale, which is how the old declaration went false in the first place. It CANNOT detect
# a THIRD, UNDECLARED divergence from upstream: that comparison needs upstream, upstream is
# private, and a public runner cannot clone it. What stands in for it is the sha — any body
# edit reds and a human reads the diff. So a green here means "the body is what this repo last
# DECLARED", never "the body matches upstream". Said plainly rather than left to be inferred.
#
# WHAT A RED MEANS. Not "revert it" — the two local edits in the mover are deliberate and
# correct. It means the body changed, and everything that describes the body must be brought
# true in the SAME commit: the file header's DECLARED LOCAL EDITS list, the FRAGMENTS table
# here, and the sha in the manifest. The failure message spells out that list and prints the
# command that re-derives the sha, so nobody has to read this script to get back to green.
#
# WHY NOT `git diff` AGAINST UPSTREAM. Upstream is a private repo; a public CI runner cannot
# clone it. The sha is the offline stand-in — it cannot tell you WHAT changed, only that
# something did, which is the signal that was missing. The pin commit in each manifest row is
# provenance for a human, not something this script fetches. No network, no git, stdlib only.
#
# Usage: bash bin/vendor-pin-check.sh              # check every manifest row (exit 1 on drift)
#        bash bin/vendor-pin-check.sh --selftest   # prove the check CAN fail, then that a
#                                                  # header-only edit does NOT trip it
set -euo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"

# --- The manifest: <repo-relative path>|<pin commit it was vendored from>|<sha256 of its BODY>
# Re-pin with the command the failure message prints. Never re-pin without also updating the
# declared-local-edits list in that file's own header AND the FRAGMENTS table below — those
# two are the same list written twice, and this check is what keeps them from drifting apart.
MANIFEST=(
  "bin/promote-cards-by-token|e2f131f796baa93a5aa9cec620969bcaa21ac7fe|8ce23f47b6761e6f2f712e0fce52a66ab2fd4ed1bf97c86671ff26598ad63657"
  "bin/promote-cards-by-token.selftest.sh|e2f131f796baa93a5aa9cec620969bcaa21ac7fe|e9f6f87704f14541c2e194c926d0b0a44399f858b31cbdf607605648fcc53cf5"
)

# --- The declared local edits: <repo-relative path>|<what the site is>|<unbroken fragment>
# One row per site where a vendored body DELIBERATELY diverges from its pin. Naming them HERE
# as well as in the file's own header is what turns that header's list from a courtesy into
# something enforced: each fragment must still appear in the body or the check reds, so an edit
# cannot be dropped (or re-vendored away) while the header goes on claiming it. A file with NO
# row is declared identical to its pin — the selftest is, and that is why it has none.
# Fragments are matched with `grep -F` against the BODY ONLY: every header quotes its own
# fragments, so a whole-file match would pass on the declaration alone and check nothing.
FRAGMENTS=(
  "bin/promote-cards-by-token|#51 site (1), the false-all-clear comment on the ..HEAD refusal|nothing to do\" and exit 0 on a release whose cards"
  "bin/promote-cards-by-token|#51 site (2), the 'no range base' die string|Likeliest live causes: a shallow or tagless clone"
)

# The ONE spelling of the body rule. Both files use it; the failure message quotes it verbatim
# so a human re-derives the sha exactly the way this script did.
BODY_AWK='NR>1 && $0 ~ /[^[:space:]]/ && substr($0,1,1)!="#" {print NR; exit}'

# (`awk -- prog file` is not portable — the program text is already positional, so the path is
# passed plain. Every path this is called with is absolute, so it can never read as an option.)
body_start() { awk "$BODY_AWK" "$1"; }

# check_one <path> <expected-sha> [<label>] -> prints one line; 0 = ok, 1 = drift/unreadable.
check_one() {
  local f="$1" want="$2" label="${3:-$1}" n got body rc=0 row rf rwhat rfrag
  if [ ! -f "$f" ]; then
    printf 'MISMATCH %s — the manifest names a file that is not here\n' "$label"
    return 1
  fi
  n="$(body_start "$f")"
  if [ -z "$n" ]; then
    printf 'MISMATCH %s — no body found: every line after the shebang is blank or a comment\n' "$label"
    return 1
  fi
  got="$(tail -n +"$n" -- "$f" | sha256sum | cut -d' ' -f1)"
  if [ "$got" = "$want" ]; then
    printf 'ok %s body@%s sha256=%s\n' "$label" "$n" "${got:0:12}"
  else
    printf 'MISMATCH %s body@%s sha256=%s but the header declares %s\n' \
      "$label" "$n" "${got:0:12}" "${want:0:12}"
    rc=1
  fi

  # The declared-edits leg. Read into a variable and match with a here-string, never
  # `tail | grep -q`: grep -q exits at the first hit, tail takes EPIPE, and `pipefail` would
  # turn a FOUND fragment into a non-zero pipeline — a present edit reported as missing.
  body="$(tail -n +"$n" -- "$f")"
  for row in "${FRAGMENTS[@]}"; do
    IFS='|' read -r rf rwhat rfrag <<<"$row"
    [ "$rf" = "$label" ] || continue
    if grep -Fq -- "$rfrag" <<<"$body"; then
      printf 'ok %s declared edit present: %s\n' "$label" "$rwhat"
    else
      printf 'MISSING-EDIT %s — a declared local edit is NOT in the body: %s\n' "$label" "$rwhat"
      rc=1
    fi
  done
  return "$rc"
}

# How to get back to green, printed on any red so nobody has to read this script to fix it.
drift_advice() {
  local f="$1"
  cat >&2 <<EOF

The body of $f no longer matches the sha its header declares.
Nothing here says which of the two happened — you do:

  YOU FORKED IT (a deliberate local edit, like #51's two sites). Then ALL THREE:
    1. add the new edit to the DECLARED LOCAL EDITS list in $f's own header,
       locating it by a distinctive fragment of its text, never by a line number;
    2. add the same fragment to the FRAGMENTS table in bin/vendor-pin-check.sh; and
    3. re-pin the sha in bin/vendor-pin-check.sh
  — in the SAME commit. A pin bumped without the list is how the declaration went false
  the first time.

  YOU RE-VENDORED IT (took a fresh body from upstream). Then re-pin the sha the same way
  AND re-read the declared-local-edits list: each entry was either re-applied on top of
  the new body (it stays) or dropped on purpose (delete it from BOTH the header list and
  the FRAGMENTS table — a MISSING-EDIT red is exactly that case, not yet done).

Re-derive the sha with exactly:

  tail -n +\$(awk '$BODY_AWK' $f) $f | sha256sum

EOF
}

run_check() {
  local row f pin want rc=0
  for row in "${MANIFEST[@]}"; do
    IFS='|' read -r f pin want <<<"$row"
    if ! check_one "$ROOT/$f" "$want" "$f"; then
      rc=1
      drift_advice "$f"
    fi
  done
  return "$rc"
}

# --- --selftest: a pin nobody has seen fail is a decoration (canon #9). ------------------------
# Three arms, all on COPIES: a body mutation MUST red the sha leg; a header-comment mutation
# MUST NOT red anything; and deleting a declared edit MUST red the MISSING-EDIT leg. The middle
# arm is the control for both legs — without it a check that reds on everything would pass the
# other two and quietly make every header edit a merge blocker.
#
# Every copy is built with head/tail/printf/grep rather than an in-place rewrite, so every byte
# outside the mutated or deleted line is preserved exactly (including whether the file ends in
# a newline).
SELFTEST_TMP=""   # global on purpose: the EXIT trap fires after the function's locals are gone.
run_selftest() {
  local tmp row f pin want n total mid out rc fails=0 frow rf rwhat rfrag
  SELFTEST_TMP="$(mktemp -d)"; tmp="$SELFTEST_TMP"
  trap 'rm -rf "${SELFTEST_TMP:-}"' EXIT

  for row in "${MANIFEST[@]}"; do
    IFS='|' read -r f pin want <<<"$row"
    n="$(body_start "$ROOT/$f")"
    total="$(wc -l <"$ROOT/$f")"
    mid=$(( n + (total - n) / 2 ))   # a line in the MIDDLE of the body, not its edge

    # RED ARM — one line inside the body replaced.
    { head -n "$(( mid - 1 ))" -- "$ROOT/$f"
      printf '%s\n' "# vendor-pin-check selftest: this body line was mutated"
      tail -n +"$(( mid + 1 ))" -- "$ROOT/$f"
    } >"$tmp/body-mutated"
    out="$(check_one "$tmp/body-mutated" "$want" "$f")" && rc=0 || rc=$?
    if [ "$rc" -eq 1 ] && [ "${out#MISMATCH}" != "$out" ]; then
      printf 'ok   %s — a body mutation (line %s) reds: %s\n' "$f" "$mid" "$out"
    else
      printf 'FAIL %s — a body mutation (line %s) did NOT red (rc=%s): %s\n' "$f" "$mid" "$rc" "$out"
      fails=$(( fails + 1 ))
    fi

    # GREEN ARM (the control) — comment line 2, well above the body, replaced.
    if [ "$(sed -n '2p' -- "$ROOT/$f" | cut -c1)" != "#" ]; then
      printf 'FAIL %s — line 2 is not a comment; the header arm cannot be built\n' "$f"
      fails=$(( fails + 1 ))
      continue
    fi
    { head -n 1 -- "$ROOT/$f"
      printf '%s\n' "# vendor-pin-check selftest: this HEADER comment was mutated"
      tail -n +3 -- "$ROOT/$f"
    } >"$tmp/header-mutated"
    out="$(check_one "$tmp/header-mutated" "$want" "$f")" && rc=0 || rc=$?
    if [ "$rc" -eq 0 ]; then
      printf 'ok   %s — a header-comment mutation still passes: %s\n' "$f" "$out"
    else
      printf 'FAIL %s — a header-comment mutation reds the check (rc=%s): %s\n' "$f" "$rc" "$out"
      fails=$(( fails + 1 ))
    fi

    # MISSING-EDIT ARM — every line carrying a declared fragment deleted (its header quote
    # goes with it, which is the point: the two copies of the list vanish together). The sha
    # reds too; what this arm proves is that the MISSING-EDIT leg FIRES, since the green arm
    # above already showed it stays quiet on an untouched body.
    for frow in "${FRAGMENTS[@]}"; do
      IFS='|' read -r rf rwhat rfrag <<<"$frow"
      [ "$rf" = "$f" ] || continue
      grep -Fv -- "$rfrag" "$ROOT/$f" >"$tmp/frag-deleted" || true
      out="$(check_one "$tmp/frag-deleted" "$want" "$f")" && rc=0 || rc=$?
      if [ "$rc" -eq 1 ] && [ "${out#*MISSING-EDIT}" != "$out" ]; then
        printf 'ok   %s — deleting a declared edit reds MISSING-EDIT: %s\n' "$f" "$rwhat"
      else
        printf 'FAIL %s — deleting a declared edit did NOT red MISSING-EDIT (rc=%s): %s\n' "$f" "$rc" "$out"
        fails=$(( fails + 1 ))
      fi
    done
  done

  if [ "$fails" -ne 0 ]; then
    printf 'vendor-pin-check --selftest: %s arm(s) FAILED — the real check below proves nothing.\n' "$fails" >&2
    return 1
  fi
  printf 'vendor-pin-check --selftest: every arm behaved (bodies and dropped edits red, headers do not).\n'
  return 0
}

case "${1-}" in
  --selftest) run_selftest ;;
  "")         run_check ;;
  *)          printf 'usage: %s [--selftest]\n' "${0##*/}" >&2; exit 2 ;;
esac
